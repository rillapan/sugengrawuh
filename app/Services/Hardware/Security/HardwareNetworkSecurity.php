<?php

namespace App\Services\Hardware\Security;

use App\Exceptions\HardwareSafetyException;

class HardwareNetworkSecurity
{
    /**
     * Validate target IP address against strict private IPv4 rules.
     * Rejects loopback, link-local, multicast, broadcast, and public addresses.
     *
     * @param string $ip
     * @return string Validated IP
     * @throws HardwareSafetyException
     */
    public static function validateTargetIp(string $ip): string
    {
        $ip = trim($ip);

        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw HardwareSafetyException::invalidIpTarget($ip, "Bukan format IPv4 yang valid");
        }

        $long = ip2long($ip);
        if ($long === false) {
            throw HardwareSafetyException::invalidIpTarget($ip, "Gagal mengonversi alamat IP");
        }

        // 0.0.0.0/8 (Current network)
        if (($long & 0xFF000000) === 0x00000000) {
            throw HardwareSafetyException::invalidIpTarget($ip, "Alamat wildcard / current network (0.0.0.0/8) dilarang");
        }

        // 127.0.0.0/8 (Loopback)
        if (($long & 0xFF000000) === 0x7F000000) {
            throw HardwareSafetyException::invalidIpTarget($ip, "Alamat loopback (127.0.0.0/8) dilarang untuk mencegah SSRF");
        }

        // 169.254.0.0/16 (Link-Local / APIPA / Cloud Metadata 169.254.169.254)
        if (($long & 0xFFFF0000) === (int) 0xA9FE0000) {
            throw HardwareSafetyException::invalidIpTarget($ip, "Alamat link-local / metadata (169.254.0.0/16) dilarang");
        }

        // 224.0.0.0/4 (Multicast 224.0.0.0 - 239.255.255.255)
        if (($long & 0xF0000000) === (int) 0xE0000000) {
            throw HardwareSafetyException::invalidIpTarget($ip, "Alamat multicast (224.0.0.0/4) dilarang");
        }

        // 255.255.255.255 (Broadcast)
        if ($long === -1 || $long === (int) 0xFFFFFFFF) {
            throw HardwareSafetyException::invalidIpTarget($ip, "Alamat broadcast dilarang");
        }

        // Verify RFC 1918 Private Ranges:
        // 10.0.0.0/8:      0x0A000000 - 0x0AFFFFFF
        // 172.16.0.0/12:   0xAC100000 - 0xAC1FFFFF
        // 192.168.0.0/16:  0xC0A80000 - 0xC0A8FFFF
        $is10 = ($long & 0xFF000000) === 0x0A000000;
        $is172 = ($long & 0xFFF00000) === (int) 0xAC100000;
        $is192 = ($long & 0xFFFF0000) === (int) 0xC0A80000;

        if (!$is10 && !$is172 && !$is192) {
            throw HardwareSafetyException::invalidIpTarget($ip, "Hanya alamat IPv4 privat lokal RFC 1918 (10.x.x.x, 172.16-31.x.x, 192.168.x.x) yang diizinkan");
        }

        return $ip;
    }

    /**
     * Validate target port against brand allowlist.
     *
     * @param string $brand
     * @param int $port
     * @return int Validated port
     * @throws HardwareSafetyException
     */
    public static function validateTargetPort(string $brand, int $port): int
    {
        $brandUpper = strtoupper(trim($brand));
        $allowedPorts = config("hardware.allowed_ports.{$brandUpper}", []);

        if (!in_array($port, $allowedPorts, true)) {
            throw HardwareSafetyException::portNotAllowed($brandUpper, $port);
        }

        return $port;
    }

    /**
     * Sanitize sensitive credentials and tokens from audit logs and responses.
     *
     * @param mixed $data
     * @return mixed
     */
    public static function sanitizeLog($data)
    {
        if (!is_array($data)) {
            return $data;
        }

        $sensitiveKeys = [
            'token',
            'pairing_payload',
            'password',
            'auth',
            'authorization',
            'secret',
            'client_key',
            'key',
            'simulated_token',
        ];

        $cleaned = [];
        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string) $key);
            $isSensitive = false;

            foreach ($sensitiveKeys as $pattern) {
                if ($lowerKey === $pattern || str_contains($lowerKey, $pattern)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $cleaned[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $cleaned[$key] = self::sanitizeLog($value);
            } else {
                $cleaned[$key] = $value;
            }
        }

        return $cleaned;
    }
}
