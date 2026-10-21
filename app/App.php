<?php
declare(strict_types=1);

/**
 * Web front controller. Every request: allow-list → session → (login) → route.
 * All state changes are POST + CSRF-checked, saved through Store::update()
 * (validated, backed up) and then applied through the root helper.
 */
final class App
{
    private static array $state = [];
    private static ?array $status = null;
    private static ?string $statusError = null;

    public static function run(): void
    {
        self::headers();
        if (!Store::exists()) {
            http_response_code(503);
            self::bare('Not initialised', '<p>wg-manager has no state yet. On the server run <code>sudo wgm init</code> (the installer normally does this).</p>');
            return;
        }
        self::$state = Store::load();
        $settings = self::$state['settings'];
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if (!Auth::ipAllowed($settings, $ip)) {
            http_response_code(403);
            self::bare('Forbidden', '<p>Your address is not allowed to use this interface.</p>');
            return;
        }
        Auth::startSession($settings);

        $r = (string)($_GET['r'] ?? 'clients');
        $post = $_SERVER['REQUEST_METHOD'] === 'POST';
        if ($post && !Auth::checkCsrf($_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null))) {
            http_response_code(400);
            if (str_starts_with($r, 'api/')) {
                self::json(['ok' => false, 'error' => 'Session expired — reload the page.'], 400);
            }
            self::flash('error', 'Your session expired. Please try again.');
            self::redirect(Auth::user() ? $r : 'login');
        }

        // Public routes
        if ($r === 'login') {
            $post ? self::loginPost() : self::loginPage();
            return;
        }
        if ($r === '2fa') {
            $post ? self::totpPost() : self::totpPage();
            return;
        }
        if (Auth::user() === null || !isset(Auth::users()[Auth::user()])) {
            if (str_starts_with($r, 'api/')) {
                self::json(['ok' => false, 'error' => 'Not logged in.'], 401);
            }
            Auth::logout();
            Auth::startSession($settings);
            self::redirect('login');
        }

        try {
            match (true) {
                $r === 'logout' && $post   => self::logout(),
                $r === 'clients'           => self::clientsPage(),
                $r === 'client' && !$post  => self::clientPage(),
                $r === 'client' && $post   => self::clientSave(),
                $r === 'client-action' && $post => self::clientAction(),
                $r === 'client-conf'       => self::clientConf(),
                $r === 'client-qr'         => self::clientQr(),
                $r === 'settings' && !$post => self::settingsPage(),
                $r === 'settings' && $post => self::settingsSave(),
                $r === 'apply' && $post    => self::applyNow(),
                $r === 'users' && !$post   => self::usersPage(),
                $r === 'users' && $post    => self::usersPost(),
                $r === 'account' && !$post => self::accountPage(),
                $r === 'account' && $post  => self::accountPost(),
                $r === 'activity'          => self::activityPage(),
                $r === 'audit-csv'         => self::auditCsv(),
                $r === 'backup' && $post   => self::backupPost(),
                $r === 'backup-download'   => self::backupDownload(),
                $r === 'alerts-test' && $post => self::alertsTest(),
                $r === 'api/status'        => self::apiStatus(),
                $r === 'api/toggle' && $post => self::apiToggle(),
                default                    => self::notFound(),
            };
        } catch (ValidationError $e) {
            self::flash('error', implode("\n", $e->errors));
            if ($r === 'client') {
                self::redirect('client', isset($_GET['id']) ? ['id' => (string)$_GET['id']] : ['new' => 1]);
            }
            self::redirect(in_array($r, ['settings', 'users', 'activity', 'account'], true) ? $r : 'clients');
        }
    }

    // ================================================================== helpers

    private static function headers(): void
    {
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'none'; form-action 'self'; base-uri 'none'");
        header('Cache-Control: no-store');
    }

    public static function url(string $r, array $q = []): string
    {
        return '?' . http_build_query(['r' => $r] + $q);
    }

    private static function redirect(string $r, array $q = []): never
    {
        header('Location: ' . (str_starts_with($r, '?') ? $r : self::url($r, $q)));
        exit;
    }

    private static function json(array $data, int $code = 200): never
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function flash(string $type, string $msg): void
    {
        $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
    }

    private static function takeFlash(): array
    {
        $f = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $f;
    }

    private static function notFound(): void
    {
        http_response_code(404);
        self::page('Not found', 'notfound', []);
    }

    /** Live status from the helper (once per request). */
    private static function status(): ?array
    {
        if (self::$status === null && self::$statusError === null) {
            try {
                self::$status = Wg::status();
            } catch (Throwable $e) {
                self::$statusError = $e->getMessage();
            }
        }
        return self::$status;
    }

    /** Save through Store::update, then apply. Sets flash messages. */
    private static function saveAndApply(callable $fn, string $reason, string $auditAction, string $auditDetail, string $okMsg): void
    {
        Store::update($fn, $reason);
        Audit::log($auditAction, $auditDetail);
        self::$state = Store::load();
        self::applyWithFlash($okMsg);
    }

    private static function applyWithFlash(string $okMsg): void
    {
        try {
            $r = Wg::apply();
            $extra = [];
            if (!empty($r['routes']['added'])) {
                $extra[] = 'routes added: ' . implode(', ', $r['routes']['added']);
            }
            if (!empty($r['routes']['removed'])) {
                $extra[] = 'routes removed: ' . implode(', ', $r['routes']['removed']);
            }
            $act = implode(', ', $r['actions'] ?? []);
            self::flash('ok', $okMsg . ' Applied (' . $act . ')' . ($extra ? ' — ' . implode('; ', $extra) : '') . '.');
            Audit::log('apply', $act);
        } catch (Throwable $e) {
            self::flash('error', $okMsg . ' But applying to the interface failed: ' . $e->getMessage());
            Audit::log('apply.failed', $e->getMessage());
        }
    }

    private static function input(string $k, string $def = ''): string
    {
        return is_string($_POST[$k] ?? null) ? $_POST[$k] : $def;
    }

    // ================================================================== rendering

    private static function page(string $title, string $view, array $vars, string $nav = ''): void
    {
        $status = self::status();
        $vars += [
            'state' => self::$state, 's' => self::$state['settings'], 'status' => $status,
            'statusError' => self::$statusError, 'user' => Auth::user(), 'csrf' => Auth::csrf(),
        ];
        $flash = self::takeFlash();
        extract($vars, EXTR_SKIP);
        header('Content-Type: text/html; charset=utf-8');
        require WGM_APP_DIR . '/app/views/layout.php';
    }

    public static function view(string $name, array $vars): void
    {
        extract($vars, EXTR_SKIP);
        require WGM_APP_DIR . '/app/views/' . $name . '.php';
    }

    private static function bare(string $title, string $html): void
    {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . h($title) . ' · WG Manager</title><link rel="stylesheet" href="assets/app.css"></head><body class="bare"><main class="auth"><h1>' . h($title) . '</h1>' . $html . '</main></body></html>';
    }

    private static function authPage(string $title, string $view, array $vars = []): void
    {
        $flash = self::takeFlash();
        $csrf = Auth::csrf();
        extract($vars, EXTR_SKIP);
        header('Content-Type: text/html; charset=utf-8');
        require WGM_APP_DIR . '/app/views/auth.php';
    }

    // ================================================================== auth

    private static function loginPage(): void
    {
        if (Auth::user()) {
            self::redirect('clients');
        }
        self::authPage('Sign in', 'login');
    }

    private static function loginPost(): void
    {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $res = Auth::attempt(trim(self::input('username')), self::input('password'), $ip, self::$state['settings']);
        match ($res) {
            'ok'     => self::redirect('clients'),
            'totp'   => self::redirect('2fa'),
            'locked' => self::flash('error', 'Too many failed attempts. Try again in ' . self::$state['settings']['ui']['lockoutMinutes'] . ' minutes.'),
            default  => self::flash('error', 'Wrong username or password.'),
        };
        self::redirect('login');
    }

    private static function totpPage(): void
    {
        if (empty($_SESSION['pending'])) {
            self::redirect('login');
        }
        self::authPage('Two-factor code', 'totp');
    }

    private static function totpPost(): void
    {
        $res = Auth::attemptTotp(self::input('code'), (string)($_SERVER['REMOTE_ADDR'] ?? ''), self::$state['settings']);
        match ($res) {
            'ok'      => self::redirect('clients'),
            'expired' => self::redirect('login'),
            'locked'  => self::flash('error', 'Too many failed attempts. Try again later.'),
            default   => self::flash('error', 'That code is not valid.'),
        };
        self::redirect($res === 'locked' ? 'login' : '2fa');
    }

    private static function logout(): void
    {
        Audit::log('logout');
        Auth::logout();
        self::redirect('login');
    }

    // ================================================================== clients

    private static function clientsPage(): void
    {
        $clients = self::$state['clients'];
        uasort($clients, fn($a, $b) => ip2long($a['address']) <=> ip2long($b['address']));
        self::page('Clients', 'clients', ['clients' => $clients, 'alertState' => Tick::alertState()], 'clients');
    }

    private static function getClient(): array
    {
        $id = (string)($_GET['id'] ?? $_POST['id'] ?? '');
        $c = self::$state['clients'][$id] ?? null;
        if (!$c) {
            self::flash('error', 'That client no longer exists.');
            self::redirect('clients');
        }
        return $c;
    }

    private static function clientPage(): void
    {
        $new = !isset($_GET['id']);
        $c = $new ? State::clientDefaults(self::$state['settings']) : self::getClient();
        if ($new) {
            $used = array_map(fn($x) => $x['address'], self::$state['clients']);
            $c['address'] = (string)Ip::nextFree(self::$state['settings']['address'], array_values($used));
            $c['presharedKey'] = self::$state['settings']['defaults']['presharedKey'] ? 'yes' : null;
        }
        // Re-populate the form after a validation error.
        if (!empty($_SESSION['form'])) {
            $c = array_merge($c, $_SESSION['form']);
            unset($_SESSION['form']);
        }
        self::page($new ? 'New client' : $c['name'], 'client', ['c' => $c, 'new' => $new, 'alertState' => Tick::alertState()], 'clients');
    }

    private static function clientSave(): void
    {
        $id = isset($_GET['id']) ? (string)$_GET['id'] : null;
        if ($id !== null && !isset(self::$state['clients'][$id])) {
            self::redirect('clients');
        }
        [$c, $errors] = State::clientFromInput(self::$state, $id, $_POST);
        if ($errors) {
            $form = $c;
            $form['serverRoutes'] = Ip::splitList(self::input('serverRoutes'));
            unset($form['privateKey'], $form['presharedKey'], $form['id']);
            $_SESSION['form'] = $form;
            self::flash('error', implode("\n", array_unique($errors)));
            self::redirect('client', $id !== null ? ['id' => $id] : ['new' => 1]);
        }
        $before = $id !== null ? self::$state['clients'][$id] : null;
        try {
            self::saveAndApply(function (array &$st) use ($c) {
                $st['clients'][$c['id']] = $c;
            }, $id === null ? 'client-create' : 'client-update', $id === null ? 'client.create' : 'client.update',
                $c['name'] . ' (' . $c['address'] . ')' . self::diffSummary($before, $c),
                $id === null ? "Client {$c['name']} created." : "Client {$c['name']} saved.");
        } catch (ValidationError $e) {
            $_SESSION['form'] = array_diff_key($c, ['privateKey' => 1, 'presharedKey' => 1, 'id' => 1]);
            throw $e;
        }
        self::redirect('client', ['id' => $c['id']]);
    }

    private static function diffSummary(?array $a, array $b): string
    {
        if ($a === null) {
            return $b['serverRoutes'] ? ' routes ' . implode(', ', $b['serverRoutes']) : '';
        }
        $ch = [];
        foreach (['name', 'address', 'enabled', 'allowedIps', 'dns', 'keepalive', 'mtu', 'expiresAt', 'advertise', 'includeAdvertised', 'monitor', 'group'] as $k) {
            if (($a[$k] ?? null) !== ($b[$k] ?? null)) {
                $ch[] = $k . ': ' . json_encode($a[$k] ?? null) . ' → ' . json_encode($b[$k] ?? null);
            }
        }
        $add = array_diff($b['serverRoutes'], $a['serverRoutes']);
        $del = array_diff($a['serverRoutes'], $b['serverRoutes']);
        if ($add) {
            $ch[] = 'routes added ' . implode(', ', $add);
        }
        if ($del) {
            $ch[] = 'routes removed ' . implode(', ', $del);
        }
        return $ch ? ' — ' . implode('; ', $ch) : '';
    }

    private static function clientAction(): void
    {
        $c = self::getClient();
        $id = $c['id'];
        $a = self::input('action');
        if ($a === 'delete') {
            self::saveAndApply(function (array &$st) use ($id) {
                unset($st['clients'][$id]);
            }, 'client-delete', 'client.delete', "{$c['name']} ({$c['address']})", "Client {$c['name']} deleted.");
            self::redirect('clients');
        }
        if ($a === 'toggle') {
            $on = empty($c['enabled']);
            self::saveAndApply(function (array &$st) use ($id, $on) {
                $st['clients'][$id]['enabled'] = $on;
                $st['clients'][$id]['updatedAt'] = Util::now();
            }, 'client-toggle', $on ? 'client.enable' : 'client.disable', $c['name'], "Client {$c['name']} " . ($on ? 'enabled.' : 'disabled.'));
        } elseif ($a === 'rotate') {
            $own = $c['privateKey'] === null;
            self::saveAndApply(function (array &$st) use ($id, $own) {
                $cl = &$st['clients'][$id];
                if (!$own) {
                    $cl = array_merge($cl, Keys::pair());
                }
                $cl['presharedKey'] = $cl['presharedKey'] !== null || self::$state['settings']['defaults']['presharedKey'] ? Keys::generatePreshared() : null;
                $cl['updatedAt'] = Util::now();
            }, 'client-rotate', 'client.rotate-keys', $c['name'], "New keys generated for {$c['name']} — the device needs the new config.");
        } elseif ($a === 'set-public-key') {
            $pk = trim(self::input('publicKey'));
            self::saveAndApply(function (array &$st) use ($id, $pk) {
                $st['clients'][$id]['publicKey'] = $pk;
                $st['clients'][$id]['privateKey'] = null;
                $st['clients'][$id]['updatedAt'] = Util::now();
            }, 'client-pubkey', 'client.set-public-key', $c['name'], "Public key updated for {$c['name']}.");
        }
        self::redirect('client', ['id' => $id]);
    }

    private static function clientConf(): void
    {
        $c = self::getClient();
        Audit::log('client.download', $c['name']);
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . Render::clientFilename($c) . '.conf"');
        echo Render::client(self::$state, $c);
        exit;
    }

    private static function clientQr(): void
    {
        $c = self::getClient();
        header('Content-Type: image/svg+xml');
        try {
            echo Qr::svg(Render::client(self::$state, $c));
        } catch (Throwable $e) {
            echo '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="40"><text x="4" y="24" font-size="12">' . h($e->getMessage()) . '</text></svg>';
        }
        exit;
    }

    // ================================================================== settings

    private static function settingsPage(): void
    {
        $preview = (string)preg_replace('/^PresharedKey = .*$/m', 'PresharedKey = (hidden)', Render::server(self::$state, '(hidden)', true));
        self::page('Settings', 'settings', ['preview' => $preview], 'settings');
    }

    private static function settingsSave(): void
    {
        $section = self::input('section');
        $old = self::$state['settings'];
        [$new, $errors] = State::settingsFromInput($old, $_POST, $section);
        if ($errors) {
            self::flash('error', implode("\n", array_unique($errors)));
            self::redirect('settings');
        }
        $changes = [];
        foreach (self::flatten($old) as $k => $v) {
            $nv = self::flatten($new)[$k] ?? null;
            if ($nv !== $v) {
                $changes[] = str_contains($k, 'password') || str_contains($k, 'botToken') ? "$k changed" : "$k: " . json_encode($v) . ' → ' . json_encode($nv);
            }
        }
        if (!$changes) {
            self::flash('ok', 'Nothing changed.');
            self::redirect('settings');
        }
        $affectsWg = in_array($section, ['interface', 'firewall'], true);
        Store::update(function (array &$st) use ($new) {
            $st['settings'] = $new;
        }, 'settings');
        Audit::log('settings.' . $section, implode('; ', $changes));
        self::$state = Store::load();
        $affectsWg ? self::applyWithFlash('Settings saved.') : self::flash('ok', 'Settings saved.');
        self::redirect('settings');
    }

    private static function flatten(array $a, string $p = ''): array
    {
        $o = [];
        foreach ($a as $k => $v) {
            if (is_array($v) && $v !== [] && array_keys($v) !== range(0, count($v) - 1)) {
                $o += self::flatten($v, $p . $k . '.');
            } else {
                $o[$p . $k] = $v;
            }
        }
        return $o;
    }

    private static function applyNow(): void
    {
        self::applyWithFlash('Configuration re-applied.');
        self::redirect((string)($_POST['back'] ?? '') === 'activity' ? 'activity' : 'clients');
    }

    private static function alertsTest(): void
    {
        $s = self::$state['settings'];
        if (!Alerts::anyEnabled($s)) {
            self::flash('error', 'No alert channel is enabled.');
        } else {
            $res = Alerts::send($s, 'WireGuard: test alert', 'Test message from wg-manager on ' . ($s['endpointHost'] ?: gethostname()) . '.');
            foreach ($res as $ch => $r) {
                self::flash($r === 'sent' ? 'ok' : 'error', ucfirst($ch) . ': ' . $r);
            }
        }
        self::redirect('settings');
    }

    // ================================================================== users & account

    private static function usersPage(): void
    {
        self::page('Admin users', 'users', ['users' => Auth::users()], 'settings');
    }

    private static function usersPost(): void
    {
        $a = self::input('action');
        $name = trim(self::input('username'));
        try {
            if ($a === 'add') {
                Auth::addUser($name, self::input('password'));
                Audit::log('user.create', $name);
                self::flash('ok', "User $name created.");
            } elseif ($a === 'delete') {
                if ($name === Auth::user()) {
                    throw new InvalidArgumentException('You cannot delete yourself.');
                }
                Auth::deleteUser($name);
                Audit::log('user.delete', $name);
                self::flash('ok', "User $name deleted.");
            } elseif ($a === 'reset-2fa') {
                Auth::setTotp($name, null);
                Audit::log('user.2fa-reset', $name);
                self::flash('ok', "2FA removed for $name.");
            } elseif ($a === 'password') {
                Auth::setPassword($name, self::input('password'));
                Audit::log('user.password', $name);
                self::flash('ok', "Password changed for $name.");
            }
        } catch (InvalidArgumentException $e) {
            self::flash('error', $e->getMessage());
        }
        self::redirect('users');
    }

    private static function accountPage(): void
    {
        $u = Auth::users()[Auth::user()];
        $pending = $_SESSION['totpSetup'] ?? null;
        $qr = null;
        if ($pending) {
            try {
                $qr = Qr::svg(Totp::uri($pending, (string)Auth::user(), 'wg-manager ' . (self::$state['settings']['endpointHost'] ?: gethostname())));
            } catch (Throwable $e) {
                $qr = null;
            }
        }
        self::page('Account', 'account', ['u' => $u, 'pending' => $pending, 'qr' => $qr], 'settings');
    }

    private static function accountPost(): void
    {
        $me = (string)Auth::user();
        $a = self::input('action');
        $u = Auth::users()[$me];
        try {
            if ($a === 'password') {
                if (!password_verify(self::input('current'), $u['hash'])) {
                    throw new InvalidArgumentException('Current password is wrong.');
                }
                if (self::input('password') !== self::input('confirm')) {
                    throw new InvalidArgumentException('The new passwords do not match.');
                }
                Auth::setPassword($me, self::input('password'));
                Audit::log('user.password', $me);
                self::flash('ok', 'Password changed.');
            } elseif ($a === '2fa-start') {
                $_SESSION['totpSetup'] = Totp::newSecret();
            } elseif ($a === '2fa-confirm') {
                $secret = $_SESSION['totpSetup'] ?? '';
                if ($secret === '' || !Totp::verify($secret, self::input('code'))) {
                    throw new InvalidArgumentException('That code is not valid — check the time on your phone and try again.');
                }
                Auth::setTotp($me, $secret);
                unset($_SESSION['totpSetup']);
                Audit::log('user.2fa-enable', $me);
                self::flash('ok', 'Two-factor authentication is on.');
            } elseif ($a === '2fa-cancel') {
                unset($_SESSION['totpSetup']);
            } elseif ($a === '2fa-disable') {
                if (!password_verify(self::input('current'), $u['hash'])) {
                    throw new InvalidArgumentException('Current password is wrong.');
                }
                Auth::setTotp($me, null);
                Audit::log('user.2fa-disable', $me);
                self::flash('ok', 'Two-factor authentication is off.');
            }
        } catch (InvalidArgumentException $e) {
            self::flash('error', $e->getMessage());
        }
        self::redirect('account');
    }

    // ================================================================== activity, backups

    private static function activityPage(): void
    {
        $q = Util::cleanText((string)($_GET['q'] ?? ''), 100);
        $health = null;
        $healthError = null;
        try {
            $health = Wg::health()['checks'];
        } catch (Throwable $e) {
            $healthError = $e->getMessage();
        }
        self::page('Activity', 'activity', [
            'log' => Audit::read(300, $q), 'q' => $q, 'health' => $health, 'healthError' => $healthError,
            'backups' => Store::backups(),
        ], 'activity');
    }

    private static function auditCsv(): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="wg-manager-audit-' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['time', 'user', 'ip', 'action', 'detail']);
        foreach (Audit::read(0) as $r) {
            // neutralise spreadsheet formulas
            $row = array_map(fn($v) => preg_match('/^[=+\-@]/', (string)$v) ? "'" . $v : (string)$v, [$r['ts'], $r['user'], $r['ip'], $r['action'], $r['detail']]);
            fputcsv($out, $row);
        }
        exit;
    }

    private static function backupDownload(): void
    {
        $p = Store::backupPath((string)($_GET['name'] ?? ''));
        if ($p === null) {
            self::notFound();
            return;
        }
        Audit::log('backup.download', basename($p));
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . basename($p) . '"');
        readfile($p);
        exit;
    }

    private static function backupPost(): void
    {
        $a = self::input('action');
        if ($a === 'now') {
            $n = Store::backup('manual');
            Audit::log('backup.create', $n);
            self::flash('ok', "Backup $n written.");
        } elseif ($a === 'restore') {
            $p = Store::backupPath(self::input('name'));
            if ($p === null) {
                self::flash('error', 'No such backup.');
            } else {
                $data = Util::readJson($p);
                if (($data['server']['publicKey'] ?? '') !== self::$state['server']['publicKey']) {
                    self::flash('error', 'That backup belongs to a different server key and cannot be restored from the web UI. Use `wgm restore` together with the matching key.');
                } else {
                    Store::replace($data, 'restore');
                    Audit::log('backup.restore', basename($p));
                    self::$state = Store::load();
                    self::applyWithFlash('Restored ' . basename($p) . '.');
                }
            }
        } elseif ($a === 'upload') {
            self::uploadImport();
        }
        self::redirect('activity');
    }

    private static function uploadImport(): void
    {
        $f = $_FILES['file'] ?? null;
        if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || $f['size'] > 5 * 1024 * 1024) {
            self::flash('error', 'Upload failed.');
            return;
        }
        $data = json_decode((string)file_get_contents($f['tmp_name']), true);
        if (!is_array($data)) {
            self::flash('error', 'That file is not JSON.');
            return;
        }
        if (isset($data['server']['privateKey'])) {
            // wg-easy wg0.json — replaces server key + clients, keeps our settings.
            if (empty($_POST['confirmReplace'])) {
                self::flash('error', 'Tick the confirmation box: importing a wg-easy file replaces the server key and all clients.');
                return;
            }
            $s = self::$state['settings'];
            [$new, $priv, $warn] = State::fromWgEasy($data, [
                'interface' => $s['interface'], 'listenPort' => $s['listenPort'], 'endpointHost' => $s['endpointHost'],
                'endpointPort' => $s['endpointPort'], 'allowedIps' => $s['defaults']['allowedIps'],
                'prefix' => (int)(explode('/', $s['address'])[1] ?? 24),
            ], self::$state);
            $errors = State::validate($new);
            if ($errors) {
                throw new ValidationError($errors);
            }
            Wg::setServerKey($priv);
            Store::replace($new, 'import');
            Audit::log('import.wgeasy', count($new['clients']) . ' clients');
            foreach ($warn as $w) {
                self::flash('error', $w);
            }
            self::$state = Store::load();
            self::applyWithFlash('Imported ' . count($new['clients']) . ' clients from wg-easy.');
            return;
        }
        if (isset($data['settings'], $data['clients'])) {
            if (($data['server']['publicKey'] ?? '') !== self::$state['server']['publicKey']) {
                self::flash('error', 'That backup belongs to a different server key. Restore it with `wgm restore` on the server.');
                return;
            }
            Store::replace($data, 'restore-upload');
            Audit::log('backup.restore', 'uploaded ' . Util::cleanText($f['name'] ?? '', 80));
            self::$state = Store::load();
            self::applyWithFlash('Backup restored.');
            return;
        }
        self::flash('error', 'Unrecognised file. Upload a wg-manager backup or a wg-easy wg0.json.');
    }

    // ================================================================== api

    private static function apiStatus(): void
    {
        $st = self::status();
        if ($st === null) {
            self::json(['ok' => false, 'error' => self::$statusError], 502);
        }
        $out = [];
        $now = (int)$st['time'];
        $limit = State::staleSeconds(self::$state['settings']);
        $seenMap = Liveness::update($st);
        foreach (self::$state['clients'] as $id => $c) {
            $p = $st['peers'][$c['publicKey']] ?? null;
            $hs = (int)($p['handshake'] ?? 0);
            $out[$id] = [
                'state'     => Liveness::state($c, $st, $seenMap, $limit, $now),
                'seen'      => Util::ago(Liveness::lastSeen($c, $st, $seenMap), $now),
                'handshake' => Util::ago($hs, $now),
                'endpoint'  => $p['endpoint'] ?? '',
                'rx'        => Util::bytes((int)($p['rx'] ?? 0)),
                'tx'        => Util::bytes((int)($p['tx'] ?? 0)),
                'rxBytes'   => (int)($p['rx'] ?? 0),
                'txBytes'   => (int)($p['tx'] ?? 0),
            ];
        }
        self::json(['ok' => true, 'up' => $st['up'], 'inSync' => $st['inSync'], 'interface' => $st['interface'], 'time' => $now, 'clients' => $out]);
    }

    private static function apiToggle(): void
    {
        $id = (string)($_POST['id'] ?? '');
        $c = self::$state['clients'][$id] ?? null;
        if (!$c) {
            self::json(['ok' => false, 'error' => 'No such client.'], 404);
        }
        $on = !empty($_POST['enabled']);
        Store::update(function (array &$st) use ($id, $on) {
            $st['clients'][$id]['enabled'] = $on;
            $st['clients'][$id]['updatedAt'] = Util::now();
        }, 'client-toggle');
        Audit::log($on ? 'client.enable' : 'client.disable', $c['name']);
        try {
            Wg::apply();
            self::json(['ok' => true, 'enabled' => $on]);
        } catch (Throwable $e) {
            self::json(['ok' => false, 'error' => 'Saved, but apply failed: ' . $e->getMessage(), 'enabled' => $on], 500);
        }
    }
}
