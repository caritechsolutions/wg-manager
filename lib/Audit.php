<?php
declare(strict_types=1);

/** Append-only audit log (JSON lines). */
final class Audit
{
    public static function log(string $action, string $detail = '', ?string $user = null, ?string $ip = null): void
    {
        $row = [
            'ts'     => Util::now(),
            'user'   => $user ?? (Auth::user() ?? (PHP_SAPI === 'cli' ? 'cli' : 'anonymous')),
            'ip'     => $ip ?? ($_SERVER['REMOTE_ADDR'] ?? (PHP_SAPI === 'cli' ? 'local' : '')),
            'action' => $action,
            'detail' => Util::cleanText($detail, 1000),
        ];
        $dir = dirname(WGM_AUDIT_FILE);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $fh = @fopen(WGM_AUDIT_FILE, 'a');
        if ($fh) {
            flock($fh, LOCK_EX);
            fwrite($fh, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
            flock($fh, LOCK_UN);
            fclose($fh);
            @chmod(WGM_AUDIT_FILE, 0600);
        }
        self::rotate();
    }

    /** Newest first. @return array<int,array> */
    public static function read(int $limit = 200, string $filter = ''): array
    {
        $files = array_filter([WGM_AUDIT_FILE, WGM_AUDIT_FILE . '.1'], 'is_file');
        $rows = [];
        foreach ($files as $f) {
            $lines = file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            for ($i = count($lines) - 1; $i >= 0; $i--) {
                $r = json_decode($lines[$i], true);
                if (!is_array($r)) {
                    continue;
                }
                if ($filter !== '' && stripos(implode(' ', array_map('strval', $r)), $filter) === false) {
                    continue;
                }
                $rows[] = $r;
                if ($limit > 0 && count($rows) >= $limit) {
                    return $rows;
                }
            }
        }
        return $rows;
    }

    private static function rotate(): void
    {
        if (is_file(WGM_AUDIT_FILE) && filesize(WGM_AUDIT_FILE) > 5 * 1024 * 1024) {
            @rename(WGM_AUDIT_FILE, WGM_AUDIT_FILE . '.1');
        }
    }
}
