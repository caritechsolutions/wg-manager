<?php
declare(strict_types=1);

/*
 * wg-manager — shared bootstrap for the web app, the CLI (wgm) and the root
 * helper. Paths can be overridden with environment variables (used by tests);
 * the helper ignores overrides because it runs as root via sudo.
 */

define('WGM_VERSION', '1.0.0');
define('WGM_APP_DIR', dirname(__DIR__));

$isHelper = defined('WGM_IS_HELPER');
$env = static fn(string $k, string $def): string => (!$isHelper && getenv($k)) ? (string)getenv($k) : $def;

define('WGM_STATE_DIR', $env('WGM_STATE_DIR', '/var/lib/wg-manager'));        // owned by www-data
define('WGM_ETC_DIR', $env('WGM_ETC_DIR', '/etc/wg-manager'));                // root-owned, install info
define('WGM_KEY_DIR', $env('WGM_KEY_DIR', '/etc/wireguard/wg-manager'));       // root-only, server private key
define('WGM_WG_DIR', $env('WGM_WG_DIR', '/etc/wireguard'));
define('WGM_HELPER', $env('WGM_HELPER', '/usr/local/sbin/wg-manager-helper'));
define('WGM_WEB_USER', 'www-data');

define('WGM_STATE_FILE', WGM_STATE_DIR . '/state.json');
define('WGM_USERS_FILE', WGM_STATE_DIR . '/users.json');
define('WGM_AUDIT_FILE', WGM_STATE_DIR . '/audit.jsonl');
define('WGM_LOGINS_FILE', WGM_STATE_DIR . '/logins.json');
define('WGM_ALERTS_FILE', WGM_STATE_DIR . '/alerts-state.json');
define('WGM_BACKUP_DIR', WGM_STATE_DIR . '/backups');
define('WGM_INSTALL_FILE', WGM_ETC_DIR . '/install.json');

require_once __DIR__ . '/Ip.php';
require_once __DIR__ . '/Keys.php';
require_once __DIR__ . '/Util.php';
require_once __DIR__ . '/State.php';
require_once __DIR__ . '/Store.php';
require_once __DIR__ . '/Render.php';
require_once __DIR__ . '/Wg.php';
require_once __DIR__ . '/Audit.php';
require_once __DIR__ . '/Totp.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Alerts.php';
require_once __DIR__ . '/Qr.php';
require_once __DIR__ . '/Tick.php';

// Use the server's local time zone for display and the nightly-backup hour.
(static function (): void {
    $tz = getenv('TZ') ?: trim((string)@file_get_contents('/etc/timezone'));
    if ($tz === '' && is_link('/etc/localtime')) {
        $tz = (string)preg_replace('#^.*/zoneinfo/#', '', (string)readlink('/etc/localtime'));
    }
    if ($tz === '' || !@date_default_timezone_set($tz)) {
        date_default_timezone_set('UTC');
    }
})();
