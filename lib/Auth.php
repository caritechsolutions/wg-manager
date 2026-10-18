<?php
declare(strict_types=1);

/** Admin users, sessions, CSRF, login lockout, TOTP 2FA and the UI IP allow-list. */
final class Auth
{
    // ------------------------------------------------------------------ users

    /** @return array<string,array{hash:string,totp:?string,createdAt:string,lastLogin:?string}> */
    public static function users(): array
    {
        return Util::readJson(WGM_USERS_FILE, []) ?? [];
    }

    private static function saveUsers(array $u): void
    {
        Util::writeJson(WGM_USERS_FILE, $u, 0600);
    }

    public static function validUsername(string $u): bool
    {
        return (bool)preg_match('/^[A-Za-z0-9._@-]{2,64}$/', $u);
    }

    public static function checkPassword(string $p): ?string
    {
        if (strlen($p) < 10) {
            return 'Password must be at least 10 characters.';
        }
        return null;
    }

    public static function addUser(string $name, string $password): void
    {
        Util::withLock(WGM_USERS_FILE, function () use ($name, $password) {
            $u = self::users();
            if (!self::validUsername($name)) {
                throw new InvalidArgumentException('Username may contain letters, digits and . _ @ - (2–64 chars).');
            }
            if (isset($u[$name])) {
                throw new InvalidArgumentException("User $name already exists.");
            }
            if ($m = self::checkPassword($password)) {
                throw new InvalidArgumentException($m);
            }
            $u[$name] = ['hash' => password_hash($password, PASSWORD_DEFAULT), 'totp' => null, 'createdAt' => Util::now(), 'lastLogin' => null];
            self::saveUsers($u);
        });
    }

    public static function setPassword(string $name, string $password): void
    {
        Util::withLock(WGM_USERS_FILE, function () use ($name, $password) {
            $u = self::users();
            if (!isset($u[$name])) {
                throw new InvalidArgumentException("No user $name.");
            }
            if ($m = self::checkPassword($password)) {
                throw new InvalidArgumentException($m);
            }
            $u[$name]['hash'] = password_hash($password, PASSWORD_DEFAULT);
            self::saveUsers($u);
        });
    }

    public static function setTotp(string $name, ?string $secret): void
    {
        Util::withLock(WGM_USERS_FILE, function () use ($name, $secret) {
            $u = self::users();
            if (!isset($u[$name])) {
                throw new InvalidArgumentException("No user $name.");
            }
            $u[$name]['totp'] = $secret;
            self::saveUsers($u);
        });
    }

    public static function deleteUser(string $name): void
    {
        Util::withLock(WGM_USERS_FILE, function () use ($name) {
            $u = self::users();
            if (!isset($u[$name])) {
                throw new InvalidArgumentException("No user $name.");
            }
            if (count($u) === 1) {
                throw new InvalidArgumentException('Cannot delete the last user.');
            }
            unset($u[$name]);
            self::saveUsers($u);
        });
    }

    private static function touchLogin(string $name): void
    {
        Util::withLock(WGM_USERS_FILE, function () use ($name) {
            $u = self::users();
            if (isset($u[$name])) {
                $u[$name]['lastLogin'] = Util::now();
                self::saveUsers($u);
            }
        });
    }

    // ------------------------------------------------------------------ sessions

    public static function startSession(array $settings): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        session_name('wgm_session');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
        session_start();
        $max = 60 * (int)($settings['ui']['sessionMinutes'] ?? 720);
        if (isset($_SESSION['user'], $_SESSION['seen']) && time() - (int)$_SESSION['seen'] > $max) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['seen'] = time();
    }

    public static function user(): ?string
    {
        return (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user'])) ? (string)$_SESSION['user'] : null;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            session_destroy();
        }
    }

    public static function csrf(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function checkCsrf(?string $token): bool
    {
        return is_string($token) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
    }

    // ------------------------------------------------------------------ login + lockout

    /** @return 'ok'|'totp'|'fail'|'locked' */
    public static function attempt(string $name, string $password, string $ip, array $settings): string
    {
        if (self::isLocked($ip, $settings)) {
            return 'locked';
        }
        $u = self::users()[$name] ?? null;
        // Verify against a dummy hash when the user doesn't exist (constant-ish timing).
        $hash = $u['hash'] ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
        $okPw = password_verify($password, $hash) && $u !== null;
        if (!$okPw) {
            self::recordFail($ip);
            Audit::log('login.failed', "user=$name", $name, $ip);
            return self::isLocked($ip, $settings) ? 'locked' : 'fail';
        }
        if (!empty($u['totp'])) {
            session_regenerate_id(true);
            $_SESSION['pending'] = $name;
            $_SESSION['pendingAt'] = time();
            return 'totp';
        }
        self::finishLogin($name, $ip);
        return 'ok';
    }

    /** @return 'ok'|'fail'|'locked'|'expired' */
    public static function attemptTotp(string $code, string $ip, array $settings): string
    {
        if (self::isLocked($ip, $settings)) {
            return 'locked';
        }
        $name = $_SESSION['pending'] ?? null;
        if (!$name || time() - (int)($_SESSION['pendingAt'] ?? 0) > 300) {
            unset($_SESSION['pending'], $_SESSION['pendingAt']);
            return 'expired';
        }
        $secret = self::users()[$name]['totp'] ?? null;
        if (!$secret || !Totp::verify($secret, $code)) {
            self::recordFail($ip);
            Audit::log('login.2fa-failed', "user=$name", $name, $ip);
            return self::isLocked($ip, $settings) ? 'locked' : 'fail';
        }
        unset($_SESSION['pending'], $_SESSION['pendingAt']);
        self::finishLogin($name, $ip);
        return 'ok';
    }

    private static function finishLogin(string $name, string $ip): void
    {
        session_regenerate_id(true);
        $_SESSION['user'] = $name;
        $_SESSION['seen'] = time();
        unset($_SESSION['csrf']);
        self::clearFails($ip);
        self::touchLogin($name);
        Audit::log('login', '', $name, $ip);
    }

    private static function logins(): array
    {
        return Util::readJson(WGM_LOGINS_FILE, []) ?? [];
    }

    public static function isLocked(string $ip, array $settings): bool
    {
        $r = self::logins()[$ip] ?? null;
        return $r && (int)($r['lockedUntil'] ?? 0) > time();
    }

    private static function recordFail(string $ip): void
    {
        $settings = Store::exists() ? Store::load()['settings'] : State::defaults()['settings'];
        $max = (int)$settings['ui']['lockoutAttempts'];
        $mins = (int)$settings['ui']['lockoutMinutes'];
        Util::withLock(WGM_LOGINS_FILE, function () use ($ip, $max, $mins) {
            $all = self::logins();
            $now = time();
            // forget stale entries
            foreach ($all as $k => $r) {
                if ((int)($r['lockedUntil'] ?? 0) < $now && (int)($r['last'] ?? 0) < $now - 86400) {
                    unset($all[$k]);
                }
            }
            $r = $all[$ip] ?? ['fails' => [], 'lockedUntil' => 0];
            $r['fails'] = array_values(array_filter($r['fails'], fn($t) => $t > $now - $mins * 60));
            $r['fails'][] = $now;
            $r['last'] = $now;
            if (count($r['fails']) >= $max) {
                $r['lockedUntil'] = $now + $mins * 60;
                $r['fails'] = [];
                Audit::log('login.locked', "ip=$ip for $mins min", 'system', $ip);
            }
            $all[$ip] = $r;
            Util::writeJson(WGM_LOGINS_FILE, $all, 0600);
        });
    }

    private static function clearFails(string $ip): void
    {
        Util::withLock(WGM_LOGINS_FILE, function () use ($ip) {
            $all = self::logins();
            unset($all[$ip]);
            Util::writeJson(WGM_LOGINS_FILE, $all, 0600);
        });
    }

    // ------------------------------------------------------------------ allow-list

    public static function ipAllowed(array $settings, string $ip): bool
    {
        $list = $settings['ui']['allowFrom'] ?? [];
        if (!$list || $ip === '127.0.0.1' || $ip === '::1') {
            return true;
        }
        foreach ($list as $cidr) {
            $p = Ip::parseCidr((string)$cidr);
            if ($p && Ip::isIp($ip) && Ip::overlaps($ip, $p['text']) && (strlen((string)inet_pton($ip)) === 4 ? 32 : 128) >= $p['prefix']) {
                return true;
            }
        }
        return false;
    }
}
