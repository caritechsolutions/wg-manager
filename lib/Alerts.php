<?php
declare(strict_types=1);

/** Stale-peer alerts via Telegram, a JSON webhook and/or SMTP email. */
final class Alerts
{
    /** @return array<string,string> channel => 'sent' | error message */
    public static function send(array $settings, string $subject, string $text): array
    {
        $a = $settings['alerts'];
        $res = [];
        if (!empty($a['telegram']['enabled'])) {
            $res['telegram'] = self::try(fn() => self::telegram($a['telegram'], "*" . self::mdEscape($subject) . "*\n" . self::mdEscape($text)));
        }
        if (!empty($a['webhook']['enabled'])) {
            $res['webhook'] = self::try(fn() => self::post($a['webhook']['url'], json_encode(['subject' => $subject, 'text' => $text, 'ts' => Util::now()]), ['Content-Type: application/json']));
        }
        if (!empty($a['email']['enabled'])) {
            $res['email'] = self::try(fn() => Smtp::send($a['email'], $subject, $text));
        }
        return $res;
    }

    public static function anyEnabled(array $settings): bool
    {
        $a = $settings['alerts'];
        return !empty($a['telegram']['enabled']) || !empty($a['webhook']['enabled']) || !empty($a['email']['enabled']);
    }

    private static function try(callable $fn): string
    {
        try {
            $fn();
            return 'sent';
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    private static function mdEscape(string $s): string
    {
        return preg_replace('/([_*`\[])/', '\\\\$1', $s) ?? $s;
    }

    private static function telegram(array $t, string $text): void
    {
        if (!preg_match('/^\d+:[A-Za-z0-9_-]+$/', (string)$t['botToken']) || trim((string)$t['chatId']) === '') {
            throw new RuntimeException('Telegram bot token or chat id missing/invalid.');
        }
        $url = 'https://api.telegram.org/bot' . $t['botToken'] . '/sendMessage';
        self::post($url, http_build_query(['chat_id' => $t['chatId'], 'text' => $text, 'parse_mode' => 'Markdown']), ['Content-Type: application/x-www-form-urlencoded']);
    }

    private static function post(string $url, string $body, array $headers): void
    {
        if (!preg_match('#^https?://#i', $url)) {
            throw new RuntimeException('Invalid URL.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('php-curl is not installed.');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $out = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($out === false || $code < 200 || $code >= 300) {
            throw new RuntimeException('HTTP ' . $code . ($err ? " ($err)" : '') . ': ' . substr((string)$out, 0, 200));
        }
    }
}

/** Minimal SMTP client: STARTTLS / implicit TLS / plain, AUTH LOGIN. */
final class Smtp
{
    public static function send(array $cfg, string $subject, string $text): void
    {
        foreach (['host', 'from', 'to'] as $k) {
            if (trim((string)($cfg[$k] ?? '')) === '') {
                throw new RuntimeException("SMTP $k is not set.");
            }
        }
        $host = $cfg['host'];
        $port = (int)$cfg['port'];
        $sec = $cfg['security'] ?? 'starttls';
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host]]);
        $remote = ($sec === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            throw new RuntimeException("SMTP connect failed: $errstr");
        }
        stream_set_timeout($fp, 15);
        try {
            self::expect($fp, 220);
            $ehlo = gethostname() ?: 'wg-manager';
            self::cmd($fp, "EHLO $ehlo", 250);
            if ($sec === 'starttls') {
                self::cmd($fp, 'STARTTLS', 220);
                if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('STARTTLS negotiation failed.');
                }
                self::cmd($fp, "EHLO $ehlo", 250);
            }
            if (($cfg['username'] ?? '') !== '') {
                self::cmd($fp, 'AUTH LOGIN', 334);
                self::cmd($fp, base64_encode($cfg['username']), 334);
                self::cmd($fp, base64_encode((string)$cfg['password']), 235);
            }
            $from = self::addr($cfg['from']);
            self::cmd($fp, "MAIL FROM:<$from>", 250);
            $to = array_filter(array_map([self::class, 'addr'], explode(',', $cfg['to'])));
            foreach ($to as $t) {
                self::cmd($fp, "RCPT TO:<$t>", 250);
            }
            self::cmd($fp, 'DATA', 354);
            $msg = 'From: ' . $from . "\r\n"
                . 'To: ' . implode(', ', $to) . "\r\n"
                . 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n"
                . 'Date: ' . date(DATE_RFC2822) . "\r\n"
                . 'Message-ID: <' . bin2hex(random_bytes(8)) . '@' . $ehlo . ">\r\n"
                . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
                . preg_replace('/^\./m', '..', str_replace(["\r\n", "\n"], "\r\n", $text)) . "\r\n.";
            self::cmd($fp, $msg, 250);
            fwrite($fp, "QUIT\r\n");
        } finally {
            fclose($fp);
        }
    }

    private static function addr(string $s): string
    {
        $s = trim($s);
        if (preg_match('/<([^>]+)>/', $s, $m)) {
            $s = $m[1];
        }
        if ($s !== '' && !filter_var($s, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException("Invalid email address: $s");
        }
        return $s;
    }

    private static function cmd($fp, string $line, int $want): void
    {
        fwrite($fp, $line . "\r\n");
        self::expect($fp, $want);
    }

    private static function expect($fp, int $want): void
    {
        $resp = '';
        while (($l = fgets($fp, 1024)) !== false) {
            $resp .= $l;
            if (strlen($l) < 4 || $l[3] === ' ') {
                break;
            }
        }
        if ((int)substr($resp, 0, 3) !== $want) {
            throw new RuntimeException('SMTP: ' . trim($resp ?: 'no response'));
        }
    }
}
