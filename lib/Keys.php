<?php
declare(strict_types=1);

/**
 * WireGuard key handling (Curve25519). Uses PHP's bundled libsodium; falls
 * back to the `wg` binary when sodium is unavailable.
 */
final class Keys
{
    public static function isKey(?string $k): bool
    {
        if (!is_string($k) || strlen($k) !== 44 || !preg_match('#^[A-Za-z0-9+/]{43}=$#', $k)) {
            return false;
        }
        $raw = base64_decode($k, true);
        return $raw !== false && strlen($raw) === 32;
    }

    public static function generatePrivate(): string
    {
        if (function_exists('sodium_crypto_scalarmult_base')) {
            $b = random_bytes(32);
            // Clamp exactly as `wg genkey` does.
            $b[0] = chr(ord($b[0]) & 248);
            $b[31] = chr((ord($b[31]) & 127) | 64);
            return base64_encode($b);
        }
        return trim(self::run(['wg', 'genkey'], ''));
    }

    public static function publicFromPrivate(string $private): string
    {
        if (!self::isKey($private)) {
            throw new InvalidArgumentException('Invalid private key');
        }
        if (function_exists('sodium_crypto_scalarmult_base')) {
            return base64_encode(sodium_crypto_scalarmult_base(base64_decode($private, true)));
        }
        return trim(self::run(['wg', 'pubkey'], $private . "\n"));
    }

    public static function generatePreshared(): string
    {
        return base64_encode(random_bytes(32));
    }

    /** @return array{privateKey:string,publicKey:string} */
    public static function pair(): array
    {
        $priv = self::generatePrivate();
        return ['privateKey' => $priv, 'publicKey' => self::publicFromPrivate($priv)];
    }

    private static function run(array $cmd, string $stdin): string
    {
        $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($p)) {
            throw new RuntimeException('Cannot run ' . $cmd[0]);
        }
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($p) !== 0) {
            throw new RuntimeException($cmd[0] . ' failed');
        }
        return (string)$out;
    }
}
