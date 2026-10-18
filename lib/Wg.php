<?php
declare(strict_types=1);

/**
 * Talks to the root helper. The web app runs as www-data and may only call
 * this one program through sudo (see /etc/sudoers.d/wg-manager).
 */
final class Wg
{
    public static function apply(): array
    {
        return self::call(['apply']);
    }

    public static function status(): array
    {
        return self::call(['status']);
    }

    public static function health(): array
    {
        return self::call(['health']);
    }

    public static function generateServerKey(bool $force = false): array
    {
        return self::call($force ? ['server-key', 'generate', '--force'] : ['server-key', 'generate']);
    }

    public static function setServerKey(string $privateKey): array
    {
        return self::call(['server-key', 'set'], $privateKey . "\n");
    }

    /** @return array decoded JSON from the helper; throws on failure */
    public static function call(array $args, string $stdin = ''): array
    {
        $direct = Util::isRoot() || getenv('WGM_TEST') === '1';
        $cmd = $direct ? array_merge([WGM_HELPER], $args) : array_merge(['sudo', '-n', WGM_HELPER], $args);
        $p = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($p)) {
            throw new RuntimeException('Cannot start the wg-manager helper.');
        }
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($p);
        $data = json_decode($out, true);
        if (!is_array($data)) {
            $msg = trim($err) !== '' ? trim($err) : trim($out);
            throw new RuntimeException('Helper failed (exit ' . $code . '): ' . ($msg !== '' ? substr($msg, 0, 500) : 'no output'));
        }
        if (empty($data['ok'])) {
            throw new RuntimeException((string)($data['error'] ?? 'Helper reported an error.'));
        }
        return $data;
    }
}
