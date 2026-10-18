<?php
declare(strict_types=1);

/**
 * IP / CIDR helpers. Works on packed binary addresses so IPv4 and IPv6
 * share the same logic.
 */
final class Ip
{
    public static function isIp(string $s): bool
    {
        return @inet_pton($s) !== false;
    }

    public static function isIpv4(string $s): bool
    {
        return filter_var($s, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    /**
     * Parse "a.b.c.d/nn" (or a bare IP, treated as /32 or /128).
     * Returns null when invalid. 'text' is the normalised network form.
     *
     * @return array{packed:string,prefix:int,family:int,text:string,addr:string}|null
     */
    public static function parseCidr(string $s): ?array
    {
        $s = trim($s);
        if ($s === '' || strlen($s) > 64) {
            return null;
        }
        $parts = explode('/', $s);
        if (count($parts) > 2) {
            return null;
        }
        $packed = @inet_pton($parts[0]);
        if ($packed === false) {
            return null;
        }
        $family = strlen($packed) === 4 ? 4 : 6;
        $max = $family === 4 ? 32 : 128;
        if (count($parts) === 2) {
            if (!preg_match('/^\d{1,3}$/', $parts[1])) {
                return null;
            }
            $prefix = (int)$parts[1];
            if ($prefix > $max) {
                return null;
            }
        } else {
            $prefix = $max;
        }
        $net = self::mask($packed, $prefix);
        return [
            'packed' => $net,
            'prefix' => $prefix,
            'family' => $family,
            'text'   => inet_ntop($net) . '/' . $prefix,
            'addr'   => inet_ntop($packed),
        ];
    }

    /** Zero the host bits of a packed address. */
    public static function mask(string $packed, int $prefix): string
    {
        $out = '';
        $len = strlen($packed);
        for ($i = 0; $i < $len; $i++) {
            $bits = max(0, min(8, $prefix - $i * 8));
            $m = $bits === 0 ? 0 : (0xFF << (8 - $bits)) & 0xFF;
            $out .= chr(ord($packed[$i]) & $m);
        }
        return $out;
    }

    /** True when the two CIDRs share any address. */
    public static function overlaps(string $a, string $b): bool
    {
        $x = self::parseCidr($a);
        $y = self::parseCidr($b);
        if (!$x || !$y || $x['family'] !== $y['family']) {
            return false;
        }
        $p = min($x['prefix'], $y['prefix']);
        return self::mask($x['packed'], $p) === self::mask($y['packed'], $p);
    }

    /** True when $ip lies inside $cidr. */
    public static function inCidr(string $ip, string $cidr): bool
    {
        return self::isIp($ip) && self::overlaps($ip, $cidr) && self::parseCidr($ip)['prefix'] >= self::parseCidr($cidr)['prefix'];
    }

    /**
     * Split a free-text list ("a, b\nc") into trimmed non-empty items.
     * @return string[]
     */
    public static function splitList(string $s): array
    {
        $items = preg_split('/[\s,;]+/', $s) ?: [];
        return array_values(array_filter(array_map('trim', $items), fn($v) => $v !== ''));
    }

    /**
     * IPv4 interface address "10.8.0.1/24" → details used for the pool.
     * @return array{server:string,prefix:int,network:int,broadcast:int,cidr:string}|null
     */
    public static function ifaceV4(string $address): ?array
    {
        $c = self::parseCidr($address);
        if (!$c || $c['family'] !== 4 || $c['prefix'] > 30 || $c['prefix'] < 8) {
            return null;
        }
        $network = (int)ip2long(inet_ntop($c['packed']));
        $size = 1 << (32 - $c['prefix']);
        return [
            'server'    => $c['addr'],
            'prefix'    => $c['prefix'],
            'network'   => $network,
            'broadcast' => $network + $size - 1,
            'cidr'      => $c['text'],
        ];
    }

    /** True when $ip is a usable host address in the interface subnet (not network, broadcast or server). */
    public static function usableHost(string $ip, string $ifaceAddress): bool
    {
        $i = self::ifaceV4($ifaceAddress);
        if (!$i || !self::isIpv4($ip)) {
            return false;
        }
        $n = (int)ip2long($ip);
        return $n > $i['network'] && $n < $i['broadcast'] && $ip !== $i['server'];
    }

    /** Next free host address in the interface subnet, or null if the pool is full. */
    public static function nextFree(string $ifaceAddress, array $used): ?string
    {
        $i = self::ifaceV4($ifaceAddress);
        if (!$i) {
            return null;
        }
        $taken = array_flip($used);
        $taken[$i['server']] = true;
        for ($n = $i['network'] + 1; $n < $i['broadcast']; $n++) {
            $ip = long2ip($n);
            if (!isset($taken[$ip])) {
                return $ip;
            }
        }
        return null;
    }
}
