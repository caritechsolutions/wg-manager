<?php
declare(strict_types=1);

/**
 * Time-limited setup links. A link lets a new device fetch its own config
 * (wrapped in an install script) without logging in. Only a SHA-256 of the
 * token is stored; one active link per client.
 */
final class SetupLinks
{
    public const TTL = 86400; // 24 hours

    /** @return array<string,array{clientId:string,expires:int,createdBy:string,createdAt:string}> */
    private static function load(): array
    {
        $d = Util::readJson(WGM_SETUP_FILE, []);
        return is_array($d) ? $d : [];
    }

    private static function prune(array $links, ?int $now = null): array
    {
        $now = $now ?? time();
        return array_filter($links, fn($l) => is_array($l) && (int)($l['expires'] ?? 0) > $now);
    }

    public static function validToken(?string $t): bool
    {
        return is_string($t) && preg_match('/^[a-f0-9]{48}$/', $t) === 1;
    }

    /** Create a link for the client, replacing any earlier one. @return array{token:string,expires:int} */
    public static function create(string $clientId, string $user, ?int $now = null): array
    {
        $now = $now ?? time();
        $token = bin2hex(random_bytes(24));
        $expires = $now + self::TTL;
        Util::withLock(WGM_SETUP_FILE, function () use ($clientId, $user, $token, $expires, $now) {
            $links = array_filter(self::prune(self::load(), $now), fn($l) => $l['clientId'] !== $clientId);
            $links[hash('sha256', $token)] = [
                'clientId' => $clientId, 'expires' => $expires, 'createdBy' => $user, 'createdAt' => Util::now(),
            ];
            Util::writeJson(WGM_SETUP_FILE, $links, 0600);
        });
        return ['token' => $token, 'expires' => $expires];
    }

    /** Client id for a live token, or null. */
    public static function clientFor(?string $token, ?int $now = null): ?string
    {
        if (!self::validToken($token)) {
            return null;
        }
        $l = self::prune(self::load(), $now)[hash('sha256', (string)$token)] ?? null;
        return $l ? (string)$l['clientId'] : null;
    }

    /** Expiry time of the client's live link, or null. */
    public static function activeFor(string $clientId, ?int $now = null): ?int
    {
        foreach (self::prune(self::load(), $now) as $l) {
            if ($l['clientId'] === $clientId) {
                return (int)$l['expires'];
            }
        }
        return null;
    }

    /** Cancel the client's link(s). Returns true when something was removed. */
    public static function revoke(string $clientId): bool
    {
        if (!is_file(WGM_SETUP_FILE)) {
            return false;
        }
        return (bool)Util::withLock(WGM_SETUP_FILE, function () use ($clientId) {
            $all = self::prune(self::load());
            $keep = array_filter($all, fn($l) => $l['clientId'] !== $clientId);
            Util::writeJson(WGM_SETUP_FILE, $keep, 0600);
            return count($keep) !== count($all);
        });
    }
}
