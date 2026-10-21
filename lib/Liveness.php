<?php
declare(strict_types=1);

/**
 * Peer liveness from traffic, not just handshakes.
 *
 * WireGuard only re-handshakes about every 2 minutes, but every keepalive or
 * data packet a peer sends raises its received-bytes counter on the hub. We
 * remember the last time each peer's rx counter went up ("last seen"), so a
 * peer with PersistentKeepalive = 25 s can be flagged within ~a minute of
 * going quiet.
 *
 * seen.json: { "<publicKey>": { "rx": int, "seen": unix-ts } }
 */
final class Liveness
{
    private static function file(): string
    {
        return WGM_STATE_DIR . '/seen.json';
    }

    /**
     * Merge a helper status snapshot into seen.json and return the map.
     * @return array<string,array{rx:int,seen:int}>
     */
    public static function update(array $status): array
    {
        if (empty($status['up'])) {
            return self::read();
        }
        $now = (int)($status['time'] ?? time());
        return Util::withLock(self::file(), function () use ($status, $now) {
            $map = self::read();
            $peers = (array)($status['peers'] ?? []);
            foreach ($peers as $pub => $p) {
                $p = (array)$p;
                $rx = (int)($p['rx'] ?? 0);
                $hs = (int)($p['handshake'] ?? 0);
                $prev = $map[$pub] ?? null;
                $seen = (int)($prev['seen'] ?? 0);
                if ($prev !== null && $rx > (int)$prev['rx']) {
                    $seen = $now;                       // new packets arrived
                }
                // rx < prev means the interface was restarted (counters reset): no new info.
                $map[$pub] = ['rx' => $rx, 'seen' => max($seen, $hs)];
            }
            foreach (array_keys($map) as $pub) {
                if (!isset($peers[$pub])) {
                    unset($map[$pub]);                  // peer removed / disabled
                }
            }
            Util::writeJson(self::file(), $map, 0600);
            return $map;
        });
    }

    /** @return array<string,array{rx:int,seen:int}> */
    public static function read(): array
    {
        try {
            return Util::readJson(self::file(), []) ?? [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Last time we know the peer was alive (unix ts, 0 = never). */
    public static function lastSeen(array $client, ?array $status, array $map): int
    {
        $p = (array)($status['peers'][$client['publicKey']] ?? []);
        return max((int)($p['handshake'] ?? 0), (int)($map[$client['publicKey']]['seen'] ?? 0));
    }

    /** 'off' | 'never' | 'on' | 'stale' */
    public static function state(array $client, ?array $status, array $map, int $staleSeconds, ?int $now = null): string
    {
        if (!State::isActive($client)) {
            return 'off';
        }
        $seen = self::lastSeen($client, $status, $map);
        if ($seen === 0) {
            return 'never';
        }
        return (($now ?? (int)($status['time'] ?? time())) - $seen) <= $staleSeconds ? 'on' : 'stale';
    }
}
