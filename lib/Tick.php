<?php
declare(strict_types=1);

/**
 * Background work, run every minute by wg-manager-tick.timer (as www-data):
 *  - removes expired clients from the live interface
 *  - stale-peer alerts
 *  - nightly backup
 */
final class Tick
{
    /** @return string[] log lines */
    public static function run(): array
    {
        $log = [];
        $state = Store::load();
        $s = $state['settings'];

        try {
            $status = Wg::status();
        } catch (Throwable $e) {
            return ['status failed: ' . $e->getMessage()];
        }

        // 1. Expiry: an expired client still present on the live interface → apply.
        if ($status['up'] && $status['activeInterface'] === $s['interface']) {
            foreach ($state['clients'] as $c) {
                if (State::isExpired($c) && isset($status['peers'][$c['publicKey']])) {
                    try {
                        Wg::apply();
                        Audit::log('client.expired', 'Removed expired client(s) from ' . $s['interface'], 'system', 'local');
                        $log[] = 'applied (expired clients)';
                    } catch (Throwable $e) {
                        $log[] = 'apply failed: ' . $e->getMessage();
                    }
                    break;
                }
            }
        }

        // 2. Stale-peer alerts.
        $alertState = Util::readJson(WGM_ALERTS_FILE, []) ?? [];
        $limit = 60 * (int)$s['alerts']['staleMinutes'];
        $now = (int)($status['time'] ?? time());
        $changed = false;
        foreach ($state['clients'] as $id => $c) {
            if (empty($c['monitor']) || !State::isActive($c) || !$status['up']) {
                if (isset($alertState[$id])) {
                    unset($alertState[$id]);
                    $changed = true;
                }
                continue;
            }
            $hs = (int)($status['peers'][$c['publicKey']]['handshake'] ?? 0);
            $cur = ($hs > 0 && $now - $hs <= $limit) ? 'ok' : 'stale';
            $prev = $alertState[$id] ?? null;
            if ($prev === $cur) {
                continue;
            }
            $alertState[$id] = $cur;
            $changed = true;
            if ($cur === 'stale' || $prev === 'stale') {
                $subject = $cur === 'stale'
                    ? "WireGuard: {$c['name']} is down"
                    : "WireGuard: {$c['name']} is back";
                $text = $cur === 'stale'
                    ? "{$c['name']} ({$c['address']}) — last handshake " . Util::ago($hs, $now) . '.'
                    : "{$c['name']} ({$c['address']}) — handshake " . Util::ago($hs, $now) . '.';
                $res = Alerts::anyEnabled($s) ? Alerts::send($s, $subject, $text . "\nHub: " . ($s['endpointHost'] ?: gethostname())) : [];
                Audit::log($cur === 'stale' ? 'alert.stale' : 'alert.recovered', $text . ($res ? ' [' . implode(', ', array_map(fn($k, $v) => "$k: $v", array_keys($res), $res)) . ']' : ''), 'system', 'local');
                $log[] = $subject;
            }
        }
        if ($changed) {
            Util::writeJson(WGM_ALERTS_FILE, $alertState, 0600);
        }

        // 3. Nightly backup.
        if ((int)date('G') === (int)$s['backups']['nightlyHour']) {
            $today = date('Ymd');
            $done = false;
            foreach (Store::backups() as $b) {
                if ($b['reason'] === 'nightly' && date('Ymd', $b['time']) === $today) {
                    $done = true;
                    break;
                }
            }
            if (!$done) {
                $log[] = 'backup ' . Store::backup('nightly');
            }
        }
        return $log;
    }

    /** Alert state per client id ('ok' | 'stale'), for the UI. */
    public static function alertState(): array
    {
        return Util::readJson(WGM_ALERTS_FILE, []) ?? [];
    }
}
