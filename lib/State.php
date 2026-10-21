<?php
declare(strict_types=1);

/**
 * The state document: settings, server public key and clients.
 * Everything that ends up in a WireGuard config passes through validate(),
 * which the root helper runs before rendering — the web side can never
 * smuggle extra config lines in.
 */
final class State
{
    public const IFACE_RE = '/^[a-zA-Z0-9_=+.-]{1,15}$/';
    public const ID_RE = '/^[A-Za-z0-9-]{1,64}$/';

    public static function defaults(string $iface = 'wg0', int $port = 51820, string $address = '10.8.0.1/24', string $endpoint = ''): array
    {
        $net = Ip::ifaceV4($address);
        return [
            'version'  => 1,
            'settings' => [
                'interface'    => $iface,
                'listenPort'   => $port,
                'address'      => $address,
                'endpointHost' => $endpoint,
                'endpointPort' => $port,
                'mtu'          => null,
                'firewall'     => [
                    'ipForward'         => true,
                    'peerToPeer'        => true,
                    'nat'               => true,
                    'natInterface'      => '',
                    'enforceAllowedIps' => false,
                ],
                'defaults' => [
                    'allowedIps'   => $net ? $net['cidr'] : '10.8.0.0/24',
                    'dns'          => '',
                    'keepalive'    => 25,
                    'mtu'          => null,
                    'presharedKey' => true,
                ],
                'ui' => [
                    'allowFrom'       => [],
                    'sessionMinutes'  => 720,
                    'lockoutAttempts' => 5,
                    'lockoutMinutes'  => 15,
                ],
                'alerts' => [
                    'staleSeconds' => 90,
                    'monitorAll' => true,
                    'telegram' => ['enabled' => false, 'botToken' => '', 'chatId' => ''],
                    'webhook'  => ['enabled' => false, 'url' => ''],
                    'email'    => ['enabled' => false, 'host' => '', 'port' => 587, 'security' => 'starttls',
                                   'username' => '', 'password' => '', 'from' => '', 'to' => ''],
                ],
                'backups' => ['keep' => 30, 'nightlyHour' => 2],
            ],
            'server'  => ['publicKey' => ''],
            'clients' => [],
        ];
    }

    /** Fill in any keys missing from an older/partial state using defaults. */
    public static function upgrade(array $state): array
    {
        $d = self::defaults();
        $merge = function (array $def, array $cur) use (&$merge): array {
            foreach ($def as $k => $v) {
                if (!array_key_exists($k, $cur)) {
                    $cur[$k] = $v;
                } elseif (is_array($v) && is_array($cur[$k]) && $v !== [] && array_keys($v) !== range(0, count($v) - 1)) {
                    $cur[$k] = $merge($v, $cur[$k]);
                }
            }
            return $cur;
        };
        $state['settings'] = $merge($d['settings'], $state['settings'] ?? []);
        $state['server'] = ($state['server'] ?? []) + $d['server'];
        $state['clients'] = $state['clients'] ?? [];
        $state['version'] = 1;
        foreach ($state['clients'] as $id => $c) {
            $state['clients'][$id] = array_merge(self::clientDefaults($state['settings']), $c);
        }
        return $state;
    }

    public static function clientDefaults(array $settings): array
    {
        $d = $settings['defaults'];
        return [
            'id' => '', 'name' => '', 'address' => '', 'publicKey' => '', 'privateKey' => null,
            'presharedKey' => null, 'enabled' => true, 'group' => '', 'notes' => '', 'expiresAt' => null,
            'serverRoutes' => [], 'advertise' => false, 'includeAdvertised' => false,
            'allowedIps' => $d['allowedIps'], 'dns' => $d['dns'], 'keepalive' => (int)$d['keepalive'],
            'mtu' => $d['mtu'], 'monitor' => false, 'mute' => false, 'createdAt' => Util::now(), 'updatedAt' => Util::now(),
        ];
    }

    /** True when the client should be present on the hub right now. */
    public static function isActive(array $c, ?int $now = null): bool
    {
        if (empty($c['enabled'])) {
            return false;
        }
        if (!empty($c['expiresAt'])) {
            $t = strtotime($c['expiresAt'] . ' 23:59:59 UTC');
            if ($t !== false && $t < ($now ?? time())) {
                return false;
            }
        }
        return true;
    }

    /** Whether stale/recovered alerts are sent for this client. */
    public static function alertsOn(array $settings, array $c): bool
    {
        if (!empty($c['mute'])) {
            return false;
        }
        return !empty($settings['alerts']['monitorAll']) || !empty($c['monitor']);
    }

    public static function staleSeconds(array $settings): int
    {
        return max(30, (int)($settings['alerts']['staleSeconds'] ?? 90));
    }

    public static function isExpired(array $c, ?int $now = null): bool
    {
        return !empty($c['enabled']) && !self::isActive($c, $now);
    }

    // ------------------------------------------------------------------ settings

    /** @return array{0:array,1:string[]} */
    public static function settingsFromInput(array $cur, array $in, string $section): array
    {
        $s = $cur;
        $e = [];
        $int = static function ($v, int $min, int $max, string $label) use (&$e): ?int {
            if ($v === '' || $v === null) {
                return null;
            }
            if (!preg_match('/^\d+$/', (string)$v) || (int)$v < $min || (int)$v > $max) {
                $e[] = "$label must be a number between $min and $max.";
                return null;
            }
            return (int)$v;
        };
        $bool = static fn($k) => !empty($in[$k]);

        switch ($section) {
            case 'interface':
                $s['interface'] = trim((string)($in['interface'] ?? ''));
                $s['listenPort'] = $int($in['listenPort'] ?? '', 1, 65535, 'Listen port') ?? $cur['listenPort'];
                $s['address'] = trim((string)($in['address'] ?? ''));
                $s['endpointHost'] = trim((string)($in['endpointHost'] ?? ''));
                $s['endpointPort'] = $int($in['endpointPort'] ?? '', 1, 65535, 'Endpoint port') ?? $s['listenPort'];
                $s['mtu'] = $int($in['mtu'] ?? '', 1280, 9000, 'MTU');
                break;
            case 'firewall':
                $s['firewall']['ipForward'] = $bool('ipForward');
                $s['firewall']['peerToPeer'] = $bool('peerToPeer');
                $s['firewall']['nat'] = $bool('nat');
                $s['firewall']['enforceAllowedIps'] = $bool('enforceAllowedIps');
                $s['firewall']['natInterface'] = trim((string)($in['natInterface'] ?? ''));
                break;
            case 'defaults':
                $s['defaults']['allowedIps'] = implode(', ', Ip::splitList((string)($in['allowedIps'] ?? '')));
                $s['defaults']['dns'] = implode(', ', Ip::splitList((string)($in['dns'] ?? '')));
                $s['defaults']['keepalive'] = $int($in['keepalive'] ?? '0', 0, 65535, 'Keepalive') ?? 0;
                $s['defaults']['mtu'] = $int($in['mtu'] ?? '', 1280, 9000, 'MTU');
                $s['defaults']['presharedKey'] = $bool('presharedKey');
                break;
            case 'ui':
                $s['ui']['allowFrom'] = Ip::splitList((string)($in['allowFrom'] ?? ''));
                $s['ui']['sessionMinutes'] = $int($in['sessionMinutes'] ?? '', 5, 10080, 'Session length') ?? 720;
                $s['ui']['lockoutAttempts'] = $int($in['lockoutAttempts'] ?? '', 1, 100, 'Lockout attempts') ?? 5;
                $s['ui']['lockoutMinutes'] = $int($in['lockoutMinutes'] ?? '', 1, 1440, 'Lockout minutes') ?? 15;
                break;
            case 'alerts':
                $a = &$s['alerts'];
                $a['staleSeconds'] = $int($in['staleSeconds'] ?? '', 30, 86400, 'Stale threshold') ?? 90;
                $a['monitorAll'] = $bool('monitorAll');
                $a['telegram']['enabled'] = $bool('tgEnabled');
                $a['telegram']['botToken'] = trim((string)($in['tgToken'] ?? '')) ?: $cur['alerts']['telegram']['botToken'];
                $a['telegram']['chatId'] = trim((string)($in['tgChat'] ?? ''));
                $a['webhook']['enabled'] = $bool('whEnabled');
                $a['webhook']['url'] = trim((string)($in['whUrl'] ?? ''));
                $a['email']['enabled'] = $bool('emEnabled');
                $a['email']['host'] = trim((string)($in['emHost'] ?? ''));
                $a['email']['port'] = $int($in['emPort'] ?? '', 1, 65535, 'SMTP port') ?? 587;
                $a['email']['security'] = in_array($in['emSecurity'] ?? '', ['starttls', 'ssl', 'none'], true) ? $in['emSecurity'] : 'starttls';
                $a['email']['username'] = trim((string)($in['emUser'] ?? ''));
                $pw = (string)($in['emPass'] ?? '');
                $a['email']['password'] = $pw !== '' ? $pw : $cur['alerts']['email']['password'];
                $a['email']['from'] = trim((string)($in['emFrom'] ?? ''));
                $a['email']['to'] = trim((string)($in['emTo'] ?? ''));
                unset($a);
                break;
            case 'backups':
                $s['backups']['keep'] = $int($in['keep'] ?? '', 1, 1000, 'Backups to keep') ?? 30;
                $s['backups']['nightlyHour'] = $int($in['nightlyHour'] ?? '', 0, 23, 'Nightly hour') ?? 2;
                break;
            default:
                $e[] = 'Unknown settings section.';
        }
        $e = array_merge($e, self::checkSettings($s));
        return [$s, $e];
    }

    /** @return string[] */
    public static function checkSettings(array $s): array
    {
        $e = [];
        if (!preg_match(self::IFACE_RE, (string)($s['interface'] ?? ''))) {
            $e[] = 'Interface name must be 1–15 characters: letters, digits, _ = + . -';
        }
        if (!is_int($s['listenPort'] ?? null) || $s['listenPort'] < 1 || $s['listenPort'] > 65535) {
            $e[] = 'Listen port is invalid.';
        }
        if (!Ip::ifaceV4((string)($s['address'] ?? ''))) {
            $e[] = 'Server address must be an IPv4 address with prefix /8–/30, e.g. 10.8.0.1/24.';
        } elseif (strpos((string)$s['address'], '/') === false) {
            $e[] = 'Server address needs a prefix, e.g. 10.8.0.1/24.';
        }
        $host = (string)($s['endpointHost'] ?? '');
        if ($host !== '' && !Ip::isIp($host) && !preg_match('/^(?=.{1,253}$)[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?)*$/', $host)) {
            $e[] = 'Public endpoint must be a hostname or IP address.';
        }
        if (!is_int($s['endpointPort'] ?? null)) {
            $e[] = 'Endpoint port is invalid.';
        }
        if ($s['mtu'] !== null && (!is_int($s['mtu']) || $s['mtu'] < 1280 || $s['mtu'] > 9000)) {
            $e[] = 'MTU must be 1280–9000.';
        }
        $ni = (string)($s['firewall']['natInterface'] ?? '');
        if ($ni !== '' && !preg_match(self::IFACE_RE, $ni)) {
            $e[] = 'NAT interface name is invalid.';
        }
        foreach (self::checkAllowedIps((string)($s['defaults']['allowedIps'] ?? '')) as $m) {
            $e[] = 'Default ' . $m;
        }
        foreach (Ip::splitList((string)($s['defaults']['dns'] ?? '')) as $d) {
            if (!Ip::isIp($d)) {
                $e[] = "Default DNS \"$d\" is not an IP address.";
            }
        }
        foreach ($s['ui']['allowFrom'] ?? [] as $c) {
            if (!Ip::parseCidr((string)$c)) {
                $e[] = "UI allow-list entry \"$c\" is not an IP or CIDR.";
            }
        }
        $wh = (string)($s['alerts']['webhook']['url'] ?? '');
        if ($wh !== '' && !preg_match('#^https?://#i', $wh)) {
            $e[] = 'Webhook URL must start with http:// or https://';
        }
        return $e;
    }

    /** @return string[] */
    public static function checkAllowedIps(string $list): array
    {
        $items = Ip::splitList($list);
        if (!$items) {
            return ['AllowedIPs needs at least one CIDR.'];
        }
        $e = [];
        foreach ($items as $i) {
            if (!Ip::parseCidr($i) || strpos($i, '/') === false) {
                $e[] = "AllowedIPs entry \"$i\" is not a CIDR (e.g. 10.8.0.0/24).";
            }
        }
        return $e;
    }

    // ------------------------------------------------------------------ clients

    /**
     * Build a client from form input. $id null = new client.
     * @return array{0:array,1:string[]}
     */
    public static function clientFromInput(array $state, ?string $id, array $in): array
    {
        $s = $state['settings'];
        $c = $id !== null ? $state['clients'][$id] : self::clientDefaults($s);
        $e = [];

        $c['name'] = Util::cleanText($in['name'] ?? '', 64);
        if ($c['name'] === '') {
            $e[] = 'Name is required.';
        }
        $c['group'] = Util::cleanText($in['group'] ?? '', 64);
        $c['notes'] = Util::cleanText($in['notes'] ?? '', 500);
        $c['enabled'] = !empty($in['enabled']);
        $c['monitor'] = !empty($in['monitor']);
        $c['mute'] = !empty($in['mute']);
        $c['advertise'] = !empty($in['advertise']);
        $c['includeAdvertised'] = !empty($in['includeAdvertised']);

        $exp = trim((string)($in['expiresAt'] ?? ''));
        if ($exp === '') {
            $c['expiresAt'] = null;
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp) && strtotime($exp) !== false) {
            $c['expiresAt'] = $exp;
        } else {
            $e[] = 'Expiry must be a date (YYYY-MM-DD) or empty.';
        }

        $addr = trim((string)($in['address'] ?? ''));
        if ($addr === '') {
            $used = array_map(fn($x) => $x['address'], array_filter($state['clients'], fn($x, $k) => $k !== $id, ARRAY_FILTER_USE_BOTH));
            $addr = (string)Ip::nextFree($s['address'], array_values($used));
            if ($addr === '') {
                $e[] = 'The address pool is full.';
            }
        }
        $addr = preg_replace('#/32$#', '', $addr);
        $c['address'] = $addr;

        $routes = [];
        foreach (Ip::splitList((string)($in['serverRoutes'] ?? '')) as $r) {
            $p = Ip::parseCidr($r);
            $routes[] = $p ? $p['text'] : $r;
        }
        $c['serverRoutes'] = array_values(array_unique($routes));

        $c['allowedIps'] = implode(', ', Ip::splitList((string)($in['allowedIps'] ?? '')));
        $c['dns'] = implode(', ', Ip::splitList((string)($in['dns'] ?? '')));
        $ka = trim((string)($in['keepalive'] ?? ''));
        $c['keepalive'] = $ka === '' ? 0 : (preg_match('/^\d+$/', $ka) ? (int)$ka : -1);
        $mtu = trim((string)($in['mtu'] ?? ''));
        $c['mtu'] = $mtu === '' ? null : (preg_match('/^\d+$/', $mtu) ? (int)$mtu : -1);

        if ($id === null) {
            $c['id'] = Util::uuid();
            $c['createdAt'] = Util::now();
            if (($in['keyMode'] ?? 'generate') === 'own') {
                $c['publicKey'] = trim((string)($in['publicKey'] ?? ''));
                $c['privateKey'] = null;
            } else {
                $c = array_merge($c, Keys::pair());
            }
            $c['presharedKey'] = !empty($in['presharedKey']) ? Keys::generatePreshared() : null;
        }
        $c['updatedAt'] = Util::now();

        $e = array_merge($e, self::checkClient($state, $c, $c['id']));
        return [$c, $e];
    }

    /** @return string[] */
    public static function checkClient(array $state, array $c, string $id, array $skip = []): array
    {
        $s = $state['settings'];
        $e = [];
        $name = $c['name'] !== '' ? $c['name'] : $id;
        if (!preg_match(self::ID_RE, $id)) {
            $e[] = "Client id \"$id\" is invalid.";
        }
        if (!is_string($c['name'] ?? null) || preg_match('/[\x00-\x1F\x7F]/', $c['name'])) {
            $e[] = "Client \"$id\" has an invalid name.";
        }
        if (!Ip::usableHost((string)$c['address'], $s['address'])) {
            $e[] = "$name: address {$c['address']} is not a usable host in {$s['address']}.";
        }
        if (!Keys::isKey($c['publicKey'] ?? null)) {
            $e[] = "$name: public key is not a valid WireGuard key.";
        }
        if (($c['privateKey'] ?? null) !== null && !Keys::isKey($c['privateKey'])) {
            $e[] = "$name: private key is invalid.";
        }
        if (($c['presharedKey'] ?? null) !== null && !Keys::isKey($c['presharedKey'])) {
            $e[] = "$name: preshared key is invalid.";
        }
        foreach (self::checkAllowedIps((string)$c['allowedIps']) as $m) {
            $e[] = "$name: $m";
        }
        foreach (Ip::splitList((string)$c['dns']) as $d) {
            if (!Ip::isIp($d)) {
                $e[] = "$name: DNS \"$d\" is not an IP address.";
            }
        }
        if (!is_int($c['keepalive']) || $c['keepalive'] < 0 || $c['keepalive'] > 65535) {
            $e[] = "$name: keepalive must be 0–65535 seconds.";
        }
        if ($c['mtu'] !== null && (!is_int($c['mtu']) || $c['mtu'] < 1280 || $c['mtu'] > 9000)) {
            $e[] = "$name: MTU must be 1280–9000.";
        }
        if (!is_array($c['serverRoutes'])) {
            $e[] = "$name: server routes are invalid.";
            return $e;
        }
        $ifaceNet = Ip::ifaceV4($s['address'])['cidr'] ?? '';
        foreach ($c['serverRoutes'] as $r) {
            $p = Ip::parseCidr((string)$r);
            if (!$p || $p['family'] !== 4 || strpos((string)$r, '/') === false || $p['text'] !== $r) {
                $e[] = "$name: route \"$r\" must be an IPv4 network like 192.168.110.0/24.";
                continue;
            }
            if ($p['prefix'] < 8) {
                $e[] = "$name: route $r is too broad (minimum /8).";
            }
            if ($ifaceNet && Ip::overlaps($r, $ifaceNet)) {
                $e[] = "$name: route $r overlaps the tunnel subnet $ifaceNet.";
            }
        }

        // Uniqueness against other clients.
        foreach ($state['clients'] as $oid => $o) {
            if ($oid === $id || isset($skip[$oid])) {
                continue;
            }
            if (($o['address'] ?? '') === $c['address']) {
                $e[] = "$name: address {$c['address']} is already used by {$o['name']}.";
            }
            if (($o['publicKey'] ?? '') === $c['publicKey'] && $c['publicKey'] !== '') {
                $e[] = "$name: public key is already used by {$o['name']}.";
            }
            foreach ($c['serverRoutes'] as $r) {
                foreach ($o['serverRoutes'] ?? [] as $or) {
                    if (Ip::overlaps($r, $or)) {
                        $e[] = "$name: route $r overlaps $or on {$o['name']}.";
                    }
                }
            }
        }
        if (Keys::isKey($c['publicKey'] ?? null) && $c['publicKey'] === ($state['server']['publicKey'] ?? '')) {
            $e[] = "$name: public key is the server's own key.";
        }
        return $e;
    }

    /** Full validation of a state document. @return string[] */
    public static function validate(array $state): array
    {
        $e = [];
        if (!isset($state['settings'], $state['clients']) || !is_array($state['clients'])) {
            return ['State document is malformed.'];
        }
        $e = array_merge($e, self::checkSettings($state['settings']));
        if ($e) {
            return $e;
        }
        $pk = $state['server']['publicKey'] ?? '';
        if ($pk !== '' && !Keys::isKey($pk)) {
            $e[] = 'Server public key is invalid.';
        }
        $seen = [];
        foreach ($state['clients'] as $id => $c) {
            if (!is_array($c) || ($c['id'] ?? null) !== $id) {
                $e[] = "Client entry \"$id\" is malformed.";
                continue;
            }
            $e = array_merge($e, self::checkClient($state, $c, (string)$id, $seen));
            $seen[$id] = true;
        }
        return array_values(array_unique($e));
    }

    // ------------------------------------------------------------------ advertised routes

    /** AllowedIPs that go into this client's own config file. @return string[] */
    public static function clientAllowedIps(array $state, array $c): array
    {
        $list = Ip::splitList((string)$c['allowedIps']);
        if (!empty($c['includeAdvertised'])) {
            foreach ($state['clients'] as $o) {
                if ($o['id'] === $c['id'] || empty($o['advertise']) || !self::isActive($o)) {
                    continue;
                }
                foreach ($o['serverRoutes'] as $r) {
                    $covered = false;
                    foreach ($list as $x) {
                        $px = Ip::parseCidr($x);
                        $pr = Ip::parseCidr($r);
                        if ($px && $pr && $px['family'] === 4 && $px['prefix'] <= $pr['prefix'] && Ip::overlaps($x, $r)) {
                            $covered = true;
                            break;
                        }
                    }
                    if (!$covered) {
                        $list[] = $r;
                    }
                }
            }
        }
        return array_values(array_unique($list));
    }

    // ------------------------------------------------------------------ wg-easy import

    /**
     * Convert a wg-easy v14 wg0.json into a state document.
     * @param array $opts  interface, listenPort, endpointHost, endpointPort, prefix, allowedIps, dns, keepalive, mtu
     * @return array{0:array,1:string,2:string[]} state, server private key, warnings
     */
    public static function fromWgEasy(array $json, array $opts, ?array $base = null): array
    {
        $warn = [];
        $srv = $json['server'] ?? null;
        if (!is_array($srv) || !Keys::isKey($srv['privateKey'] ?? null) || !is_array($json['clients'] ?? null)) {
            throw new InvalidArgumentException('Not a wg-easy wg0.json (missing server.privateKey or clients).');
        }
        $serverIp = (string)($srv['address'] ?? '10.8.0.1');
        if (!Ip::isIpv4($serverIp)) {
            throw new InvalidArgumentException('wg-easy server.address is not an IPv4 address.');
        }
        $prefix = (int)($opts['prefix'] ?? 24);
        $iface = $opts['interface'] ?? 'wg0';
        $port = (int)($opts['listenPort'] ?? 51820);
        $state = $base ? self::upgrade($base) : self::defaults($iface, $port);
        $s = &$state['settings'];
        $s['interface'] = $iface;
        $s['listenPort'] = $port;
        $s['address'] = "$serverIp/$prefix";
        $net = Ip::ifaceV4($s['address']);
        if (isset($opts['endpointHost']) && $opts['endpointHost'] !== '') {
            $s['endpointHost'] = $opts['endpointHost'];
        }
        $s['endpointPort'] = (int)($opts['endpointPort'] ?? $port);
        $s['defaults']['allowedIps'] = $opts['allowedIps'] ?? ($net['cidr'] ?? '10.8.0.0/24');
        if (isset($opts['dns'])) {
            $s['defaults']['dns'] = $opts['dns'];
        }
        if (isset($opts['keepalive'])) {
            $s['defaults']['keepalive'] = (int)$opts['keepalive'];
        }
        if (isset($opts['mtu'])) {
            $s['mtu'] = $opts['mtu'] === '' ? null : (int)$opts['mtu'];
        }
        unset($s);

        $priv = $srv['privateKey'];
        $state['server']['publicKey'] = Keys::isKey($srv['publicKey'] ?? null) ? $srv['publicKey'] : Keys::publicFromPrivate($priv);
        if ($state['server']['publicKey'] !== Keys::publicFromPrivate($priv)) {
            $warn[] = 'server.publicKey does not match server.privateKey; using the key derived from the private key.';
            $state['server']['publicKey'] = Keys::publicFromPrivate($priv);
        }

        $state['clients'] = [];
        foreach ($json['clients'] as $key => $wc) {
            if (!is_array($wc)) {
                continue;
            }
            $id = (string)($wc['id'] ?? $key);
            if (!preg_match(self::ID_RE, $id)) {
                $id = Util::uuid();
            }
            $c = self::clientDefaults($state['settings']);
            $c['id'] = $id;
            $c['name'] = Util::cleanText($wc['name'] ?? '', 64) ?: ('client-' . substr($id, 0, 8));
            $c['address'] = (string)($wc['address'] ?? '');
            $c['publicKey'] = (string)($wc['publicKey'] ?? '');
            $c['privateKey'] = Keys::isKey($wc['privateKey'] ?? null) ? $wc['privateKey'] : null;
            $c['presharedKey'] = Keys::isKey($wc['preSharedKey'] ?? null) ? $wc['preSharedKey'] : null;
            $c['enabled'] = ($wc['enabled'] ?? true) !== false;
            if (!empty($wc['expiredAt'])) {
                $t = strtotime((string)$wc['expiredAt']);
                $c['expiresAt'] = $t ? gmdate('Y-m-d', $t) : null;
            }
            $c['createdAt'] = (string)($wc['createdAt'] ?? Util::now());
            $c['updatedAt'] = Util::now();
            $errs = self::checkClient($state, $c, $id);
            if ($errs) {
                $warn[] = 'Skipped "' . $c['name'] . '": ' . implode(' ', $errs);
                continue;
            }
            $state['clients'][$id] = $c;
        }
        return [$state, $priv, $warn];
    }
}
