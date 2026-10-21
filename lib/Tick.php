<?php
declare(strict_types=1);

/**
 * Background work, run every 15 s by wg-manager-tick.timer (as www-data):
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

        // 2. Stale-peer alerts, from traffic (see Liveness).
        //    Only real transitions alert: a peer seen for the first time is
        //    recorded silently, so long-dead peers don't all fire at once.
        //    Everything that changed in one run goes out as ONE message.
        $seenMap = Liveness::update($status);
        $alertState = Util::readJson(WGM_ALERTS_FILE, []) ?? [];
        $limit = State::staleSeconds($s);
        $now = (int)($status['time'] ?? time());
        $changed = false;
        $down = $back = [];
        foreach ($state['clients'] as $id => $c) {
            if (!State::alertsOn($s, $c) || !State::isActive($c) || !$status['up']) {
                if (isset($alertState[$id])) {
                    unset($alertState[$id]);
                    $changed = true;
                }
                continue;
            }
            $seen = Liveness::lastSeen($c, $status, $seenMap);
            $cur = ($seen > 0 && $now - $seen <= $limit) ? 'ok' : 'stale';
            $prev = $alertState[$id] ?? null;
            if ($prev === $cur) {
                continue;
            }
            $alertState[$id] = $cur;
            $changed = true;
            if ($prev === null) {
                continue;
            }
            $line = "{$c['name']} ({$c['address']}) — last seen " . Util::ago($seen, $now);
            if ($cur === 'stale') {
                $down[] = $line;
            } else {
                $back[] = $line;
            }
            Audit::log($cur === 'stale' ? 'alert.stale' : 'alert.recovered', $line, 'system', 'local');
        }
        if ($down || $back) {
            $n = count($down) + count($back);
            $subject = $n === 1
                ? 'WireGuard: ' . explode(' (', ($down ?: $back)[0])[0] . ($down ? ' is down' : ' is back')
                : 'WireGuard: ' . ($down ? count($down) . ' down' : '') . ($down && $back ? ', ' : '') . ($back ? count($back) . ' back' : '');
            $text = ($down ? "DOWN (no traffic for over {$limit} s):\n- " . implode("\n- ", $down) . "\n" : '')
                  . ($back ? "BACK:\n- " . implode("\n- ", $back) . "\n" : '')
                  . 'Hub: ' . ($s['endpointHost'] ?: gethostname());
            if (Alerts::anyEnabled($s)) {
                $res = Alerts::send($s, $subject, $text);
                $fails = array_filter($res, fn($r) => $r !== 'sent');
                if ($fails) {
                    Audit::log('alert.send-failed', implode('; ', array_map(fn($k, $v) => "$k: $v", array_keys($fails), $fails)), 'system', 'local');
                }
            }
            $log[] = $subject;
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
