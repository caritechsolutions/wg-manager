<?php
declare(strict_types=1);

final class Util
{
    /** Read a JSON file; returns $default when missing. Throws on corrupt JSON. */
    public static function readJson(string $path, $default = null)
    {
        if (!is_file($path)) {
            return $default;
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Cannot read $path");
        }
        if (trim($raw) === '') {
            return $default;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException("Corrupt JSON in $path");
        }
        return $data;
    }

    /** Atomic write (temp file + rename) with the given mode. */
    public static function writeFile(string $path, string $content, int $mode = 0600): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create $dir");
        }
        $tmp = $dir . '/.' . basename($path) . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $content) === false) {
            throw new RuntimeException("Cannot write $tmp");
        }
        chmod($tmp, $mode);
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Cannot replace $path");
        }
    }

    public static function writeJson(string $path, $data, int $mode = 0600): void
    {
        self::writeFile($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", $mode);
    }

    /**
     * Run $fn while holding an exclusive lock on $path.lock.
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public static function withLock(string $path, callable $fn)
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $fh = fopen($path . '.lock', 'c');
        if (!$fh) {
            throw new RuntimeException("Cannot lock $path");
        }
        try {
            flock($fh, LOCK_EX);
            return $fn();
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    public static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    public static function bytes(int|float $n): string
    {
        $u = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $i = 0;
        while ($n >= 1024 && $i < count($u) - 1) {
            $n /= 1024;
            $i++;
        }
        return ($i === 0 ? (string)(int)$n : number_format($n, $n >= 100 ? 0 : ($n >= 10 ? 1 : 2))) . ' ' . $u[$i];
    }

    public static function ago(int $ts, ?int $now = null): string
    {
        if ($ts <= 0) {
            return 'never';
        }
        $d = max(0, ($now ?? time()) - $ts);
        if ($d < 60) {
            return $d . ' s ago';
        }
        if ($d < 3600) {
            return intdiv($d, 60) . ' min ago';
        }
        if ($d < 86400) {
            return intdiv($d, 3600) . ' h ' . intdiv($d % 3600, 60) . ' m ago';
        }
        return intdiv($d, 86400) . ' d ago';
    }

    /** Strip control characters and trim; cap the length. */
    public static function cleanText(?string $s, int $max = 200): string
    {
        $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$s) ?? '';
        $s = trim($s);
        return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
    }

    public static function isRoot(): bool
    {
        return function_exists('posix_geteuid') ? posix_geteuid() === 0 : trim((string)shell_exec('id -u')) === '0';
    }
}

/** HTML escape. */
function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
