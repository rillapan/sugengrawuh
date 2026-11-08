<?php

namespace Tests\Unit;

use App\Exceptions\HardwareSafetyException;
use App\Services\Hardware\Security\HardwareNetworkSecurity;
use Tests\TestCase;

class HardwareNetworkSecurityTest extends TestCase
{
    /** @test */
    public function valid_rfc1918_private_ipv4_addresses_are_accepted()
    {
        $this->assertEquals('10.0.0.1', HardwareNetworkSecurity::validateTargetIp('10.0.0.1'));
        $this->assertEquals('10.254.1.50', HardwareNetworkSecurity::validateTargetIp('10.254.1.50'));
        $this->assertEquals('172.16.0.1', HardwareNetworkSecurity::validateTargetIp('172.16.0.1'));
        $this->assertEquals('172.31.255.254', HardwareNetworkSecurity::validateTargetIp('172.31.255.254'));
        $this->assertEquals('192.168.1.100', HardwareNetworkSecurity::validateTargetIp('192.168.1.100'));
        $this->assertEquals('192.168.0.1', HardwareNetworkSecurity::validateTargetIp('192.168.0.1'));
    }

    /** @test */
    public function loopback_ip_addresses_are_strictly_rejected_to_prevent_ssrf()
    {
        $this->expectException(HardwareSafetyException::class);
        $this->expectExceptionMessage('loopback (127.0.0.0/8) dilarang');

        HardwareNetworkSecurity::validateTargetIp('127.0.0.1');
    }

    /** @test */
    public function link_local_and_cloud_metadata_ip_addresses_are_strictly_rejected()
    {
        $this->expectException(HardwareSafetyException::class);
        $this->expectExceptionMessage('link-local / metadata (169.254.0.0/16) dilarang');

        HardwareNetworkSecurity::validateTargetIp('169.254.169.254');
    }

    /** @test */
    public function multicast_ip_addresses_are_strictly_rejected()
    {
        $this->expectException(HardwareSafetyException::class);
        $this->expectExceptionMessage('multicast (224.0.0.0/4) dilarang');

        HardwareNetworkSecurity::validateTargetIp('224.0.0.1');
    }

    /** @test */
    public function broadcast_and_wildcard_ip_addresses_are_strictly_rejected()
    {
        $this->expectException(HardwareSafetyException::class);
        HardwareNetworkSecurity::validateTargetIp('255.255.255.255');
    }

    /** @test */
    public function public_internet_ip_addresses_are_strictly_rejected()
    {
        $this->expectException(HardwareSafetyException::class);
        $this->expectExceptionMessage('RFC 1918');

        HardwareNetworkSecurity::validateTargetIp('8.8.8.8');
    }

    /** @test */
    public function target_port_allowlist_is_strictly_enforced()
    {
        // Samsung allows 8001, 8002
        $this->assertEquals(8001, HardwareNetworkSecurity::validateTargetPort('SAMSUNG', 8001));
        $this->assertEquals(8002, HardwareNetworkSecurity::validateTargetPort('SAMSUNG', 8002));

        // Shelly allows 80, 8080
        $this->assertEquals(80, HardwareNetworkSecurity::validateTargetPort('SHELLY', 80));
        $this->assertEquals(8080, HardwareNetworkSecurity::validateTargetPort('SHELLY', 8080));
    }

    /** @test */
    public function unauthorized_ports_are_rejected()
    {
        $this->expectException(HardwareSafetyException::class);
        $this->expectExceptionMessage('Port 22 tidak diizinkan');

        HardwareNetworkSecurity::validateTargetPort('SAMSUNG', 22);
    }

    /** @test */
    public function sanitize_log_removes_sensitive_credentials_and_tokens()
    {
        $data = [
            'device_id' => 1,
            'name' => 'Samsung TV 1',
            'token' => 'secret_pairing_token_12345',
            'pairing_payload' => 'sensitive_payload',
            'auth' => ['password' => 'super_secret'],
            'headers' => ['Authorization' => 'Bearer sensitive_jwt'],
            'status' => 'ONLINE',
        ];

        $sanitized = HardwareNetworkSecurity::sanitizeLog($data);

        $this->assertEquals(1, $sanitized['device_id']);
        $this->assertEquals('Samsung TV 1', $sanitized['name']);
        $this->assertEquals('[REDACTED]', $sanitized['token']);
        $this->assertEquals('[REDACTED]', $sanitized['pairing_payload']);
        $this->assertEquals('[REDACTED]', $sanitized['auth']);
        $this->assertEquals('[REDACTED]', $sanitized['headers']['Authorization']);
        $this->assertEquals('ONLINE', $sanitized['status']);
    }
}
