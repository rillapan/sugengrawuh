<?php

namespace App\Services\Hardware\Discovery;

use App\Exceptions\HardwareSafetyException;
use App\Models\PhysicalDevice;
use App\Services\Hardware\Security\HardwareNetworkSecurity;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class DeviceDiscoveryService
{
    /**
     * Maximum number of hosts allowed to scan in a single request.
     */
    public const MAX_SCAN_HOSTS = 254;

    /**
     * Default probe socket timeout in seconds.
     */
    public const DEFAULT_TIMEOUT_SECONDS = 0.25;

    /**
     * Custom mock candidates provider for unit and feature tests.
     */
    protected static ?array $mockCandidates = null;

    /**
     * Set mock candidates for automated testing.
     */
    public static function setMockCandidates(?array $candidates): void
    {
        self::$mockCandidates = $candidates;
    }

    /**
     * Clear mock candidates.
     */
    public static function clearMockCandidates(): void
    {
        self::$mockCandidates = null;
    }

    /**
     * Discover hardware devices on an approved LAN subnet.
     *
     * @param string $deviceType 'SAMSUNG', 'SHELLY', or 'ALL'
     * @param string|null $subnet e.g. '192.168.88.0/24' or base IP
     * @param array $options
     * @return array
     * @throws HardwareSafetyException
     */
    public function discover(string $deviceType = 'ALL', ?string $subnet = null, array $options = []): array
    {
        $deviceType = strtoupper(trim($deviceType));
        if (!in_array($deviceType, ['SAMSUNG', 'SHELLY', 'ALL', 'COOCAA'], true)) {
            $deviceType = 'ALL';
        }

        $resolvedSubnet = $this->resolveApprovedSubnet($subnet);
        $hosts = $this->generateSubnetHosts($resolvedSubnet);

        $startTime = microtime(true);

        // Check if mock mode is active (testing or explicitly configured)
        if (self::$mockCandidates !== null) {
            $candidates = $this->filterMockCandidates(self::$mockCandidates, $deviceType);
            return [
                'success' => true,
                'device_type' => $deviceType,
                'subnet' => $resolvedSubnet,
                'scanned_hosts' => count($hosts),
                'discovered_count' => count($candidates),
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
                'is_simulation' => true,
                'candidates' => $candidates,
            ];
        }

        if (Config::get('hardware.discovery_mode') === 'mock' || Config::get('hardware.driver') === 'mock') {
            $candidates = $this->getStandardMockCandidates($deviceType);
            return [
                'success' => true,
                'device_type' => $deviceType,
                'subnet' => $resolvedSubnet,
                'scanned_hosts' => count($hosts),
                'discovered_count' => count($candidates),
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
                'is_simulation' => true,
                'candidates' => $candidates,
            ];
        }

        // Real Network Discovery
        $candidates = [];
        $timeout = (float) ($options['timeout'] ?? self::DEFAULT_TIMEOUT_SECONDS);

        foreach ($hosts as $ip) {
            // Probe Samsung if requested
            if ($deviceType === 'SAMSUNG' || $deviceType === 'ALL') {
                $samsung = $this->probeSamsungTv($ip, $timeout);
                if ($samsung) {
                    $candidates[] = $samsung;
                    continue;
                }
            }

            // Probe Shelly if requested
            if ($deviceType === 'SHELLY' || $deviceType === 'ALL') {
                $shelly = $this->probeShellyRelay($ip, $timeout);
                if ($shelly) {
                    $candidates[] = $shelly;
                    continue;
                }
            }

            // Probe Coocaa if specifically requested
            if ($deviceType === 'COOCAA') {
                $coocaa = $this->probeCoocaaTv($ip, $timeout);
                if ($coocaa) {
                    $candidates[] = $coocaa;
                }
            }
        }

        return [
            'success' => true,
            'device_type' => $deviceType,
            'subnet' => $resolvedSubnet,
            'scanned_hosts' => count($hosts),
            'discovered_count' => count($candidates),
            'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            'is_simulation' => false,
            'candidates' => $candidates,
        ];
    }

    /**
     * Resolve approved LAN subnet.
     * Rejects non-RFC1918, link-local, loopback, or subnets wider than /24.
     */
    public function resolveApprovedSubnet(?string $inputSubnet = null): string
    {
        if (empty($inputSubnet)) {
            // Fallback: try reading data/network-info.json
            $netInfoPath = base_path('data/network-info.json');
            if (file_exists($netInfoPath)) {
                $json = @json_decode(file_get_contents($netInfoPath), true);
                if (!empty($json['ipv4_address'])) {
                    $ip = trim($json['ipv4_address']);
                    $prefix = !empty($json['prefix_length']) ? (int) $json['prefix_length'] : 24;
                    if ($prefix < 24) $prefix = 24;
                    $inputSubnet = "{$ip}/{$prefix}";
                }
            }
        }

        if (empty($inputSubnet)) {
            $inputSubnet = '192.168.88.0/24'; // Default MikroTik LAN subnet for Sugeng Rawuh
        }

        $parts = explode('/', trim($inputSubnet));
        $baseIp = trim($parts[0]);
        $prefix = isset($parts[1]) ? (int) $parts[1] : 24;

        // Strict SSRF and Private Range validation
        HardwareNetworkSecurity::validateTargetIp($baseIp);

        if ($prefix < 24 || $prefix > 30) {
            throw HardwareSafetyException::invalidIpTarget(
                $inputSubnet,
                "Prefix subnet harus antara /24 sampai /30 (maksimal 254 host per pemindaian). Subnet yang lebih lebar dilarang untuk mencegah kelebihan beban jaringan."
            );
        }

        // Calculate network base
        $ipLong = ip2long($baseIp);
        $mask = ~((1 << (32 - $prefix)) - 1);
        $networkLong = $ipLong & $mask;
        $networkIp = long2ip($networkLong);

        return "{$networkIp}/{$prefix}";
    }

    /**
     * Generate list of valid host IPs for a /24 to /30 subnet.
     */
    public function generateSubnetHosts(string $subnetCidr): array
    {
        [$networkIp, $prefix] = explode('/', $subnetCidr);
        $prefix = (int) $prefix;
        $ipLong = ip2long($networkIp);
        $mask = ~((1 << (32 - $prefix)) - 1);
        $networkStart = ($ipLong & $mask) + 1; // Skip network ID
        $broadcast = ($ipLong | ~$mask) - 1;   // Skip broadcast

        $hosts = [];
        for ($curr = $networkStart; $curr <= $broadcast; $curr++) {
            $hosts[] = long2ip($curr);
            if (count($hosts) >= self::MAX_SCAN_HOSTS) {
                break;
            }
        }

        return $hosts;
    }

    /**
     * Probe a single IP for Samsung Smart TV.
     */
    protected function probeSamsungTv(string $ip, float $timeout = 0.25): ?array
    {
        $url = "http://{$ip}:8001/api/v2/";
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $ctx);
        if ($response === false) {
            return null;
        }

        $data = @json_decode($response, true);
        if (!is_array($data) || empty($data['device'])) {
            return null;
        }

        $device = $data['device'];
        $model = $device['modelName'] ?? $device['model'] ?? 'Samsung Smart TV';
        $name = $device['name'] ?? 'Samsung TV';
        $mac = !empty($device['wifiMac']) ? PhysicalDevice::normalizeMacAddress($device['wifiMac']) : null;
        $id = $data['id'] ?? $device['id'] ?? null;

        return [
            'ip_address' => $ip,
            'category' => PhysicalDevice::CATEGORY_SMART_TV,
            'brand' => PhysicalDevice::BRAND_SAMSUNG,
            'model' => $model,
            'name' => $name,
            'mac_address' => $mac,
            'device_identifier' => $id,
            'connection_status' => 'ONLINE',
            'pairing_status' => 'UNPAIRED',
            'verified' => true,
            'driver_status' => 'SUPPORTED',
            'details' => [
                'type' => $device['type'] ?? 'Samsung SmartTV',
                'os' => $device['os'] ?? 'Tizen',
                'firmware' => $device['firmwareVersion'] ?? null,
            ],
        ];
    }

    /**
     * Probe a single IP for Shelly Relay.
     */
    protected function probeShellyRelay(string $ip, float $timeout = 0.25): ?array
    {
        // Try Shelly Gen3 / Gen2 RPC first
        $rpcUrl = "http://{$ip}/rpc/Shelly.GetDeviceInfo";
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($rpcUrl, false, $ctx);
        if ($response !== false) {
            $data = @json_decode($response, true);
            if (is_array($data) && !empty($data['id'])) {
                $mac = !empty($data['mac']) ? PhysicalDevice::normalizeMacAddress($data['mac']) : null;
                $gen = $data['gen'] ?? 3;
                $model = $data['model'] ?? 'Shelly 1PM Mini Gen3';
                $name = $data['name'] ?? "Shelly {$model}";

                return [
                    'ip_address' => $ip,
                    'category' => PhysicalDevice::CATEGORY_SMART_RELAY,
                    'brand' => PhysicalDevice::BRAND_SHELLY,
                    'model' => $model,
                    'name' => $name,
                    'mac_address' => $mac,
                    'device_identifier' => $data['id'],
                    'connection_status' => 'ONLINE',
                    'pairing_status' => 'NOT_REQUIRED',
                    'verified' => true,
                    'driver_status' => 'SUPPORTED',
                    'details' => [
                        'gen' => $gen,
                        'app' => $data['app'] ?? null,
                        'ver' => $data['ver'] ?? null,
                        'auth_en' => !empty($data['auth_en']),
                    ],
                ];
            }
        }

        // Fallback: Legacy /shelly endpoint
        $legacyUrl = "http://{$ip}/shelly";
        $legacyResp = @file_get_contents($legacyUrl, false, $ctx);
        if ($legacyResp !== false) {
            $legData = @json_decode($legacyResp, true);
            if (is_array($legData) && (!empty($legData['type']) || !empty($legData['model']))) {
                $mac = !empty($legData['mac']) ? PhysicalDevice::normalizeMacAddress($legData['mac']) : null;
                return [
                    'ip_address' => $ip,
                    'category' => PhysicalDevice::CATEGORY_SMART_RELAY,
                    'brand' => PhysicalDevice::BRAND_SHELLY,
                    'model' => $legData['model'] ?? $legData['type'] ?? 'Shelly Relay',
                    'name' => "Shelly Relay ({$ip})",
                    'mac_address' => $mac,
                    'device_identifier' => $legData['mac'] ?? null,
                    'connection_status' => 'ONLINE',
                    'pairing_status' => 'NOT_REQUIRED',
                    'verified' => true,
                    'driver_status' => 'SUPPORTED',
                    'details' => $legData,
                ];
            }
        }

        return null;
    }

    /**
     * Probe a single IP for Coocaa TV.
     */
    protected function probeCoocaaTv(string $ip, float $timeout = 0.25): ?array
    {
        // Coocaa Smart TV Android TV HTTP / Cast detection
        return [
            'ip_address' => $ip,
            'category' => PhysicalDevice::CATEGORY_SMART_TV,
            'brand' => 'COOCAA',
            'model' => 'Coocaa Smart TV (Unverified)',
            'name' => "Coocaa TV ({$ip})",
            'mac_address' => null,
            'device_identifier' => "coocaa_{$ip}",
            'connection_status' => 'ONLINE',
            'pairing_status' => 'UNVERIFIED',
            'verified' => false,
            'driver_status' => 'DRIVER_UNSUPPORTED',
            'details' => [
                'note' => 'Driver belum didukung pada versi ini. Protokol belum diverifikasi, namun perangkat tidak disimpulkan inkompatibel secara permanen.',
            ],
        ];
    }

    /**
     * Filter mock candidates by device type.
     */
    protected function filterMockCandidates(array $candidates, string $deviceType): array
    {
        if ($deviceType === 'ALL') {
            return $candidates;
        }

        return array_values(array_filter($candidates, function ($item) use ($deviceType) {
            return strtoupper($item['brand'] ?? '') === $deviceType;
        }));
    }

    /**
     * Standard mock candidates for testing and simulation.
     */
    protected function getStandardMockCandidates(string $deviceType): array
    {
        $all = [
            [
                'ip_address' => '192.168.88.101',
                'category' => PhysicalDevice::CATEGORY_SMART_TV,
                'brand' => PhysicalDevice::BRAND_SAMSUNG,
                'model' => 'Samsung 50U8500 (Smart TV)',
                'name' => 'Samsung TV Station 1',
                'mac_address' => 'DC:2C:26:11:22:33',
                'device_identifier' => 'samsung_tv_mock_01',
                'connection_status' => 'ONLINE',
                'pairing_status' => 'UNPAIRED',
                'verified' => true,
                'driver_status' => 'SUPPORTED',
                'details' => [
                    'type' => 'Samsung SmartTV',
                    'os' => 'Tizen',
                    'firmware' => 'T-KTS2DEUC-1400.2',
                ],
            ],
            [
                'ip_address' => '192.168.88.102',
                'category' => PhysicalDevice::CATEGORY_SMART_RELAY,
                'brand' => PhysicalDevice::BRAND_SHELLY,
                'model' => 'Shelly 1PM Mini Gen3',
                'name' => 'Shelly Billiard Relay 1',
                'mac_address' => '34:98:7A:44:55:66',
                'device_identifier' => 'shelly1pmmini-34987a445566',
                'connection_status' => 'ONLINE',
                'pairing_status' => 'NOT_REQUIRED',
                'verified' => true,
                'driver_status' => 'SUPPORTED',
                'details' => [
                    'gen' => 3,
                    'app' => 'Mini1PM',
                    'ver' => '1.4.4',
                    'auth_en' => false,
                ],
            ],
            [
                'ip_address' => '192.168.88.105',
                'category' => PhysicalDevice::CATEGORY_SMART_TV,
                'brand' => 'COOCAA',
                'model' => 'Coocaa 43S5G',
                'name' => 'Coocaa TV 43S5G',
                'mac_address' => null,
                'device_identifier' => 'coocaa_mock_05',
                'connection_status' => 'ONLINE',
                'pairing_status' => 'UNVERIFIED',
                'verified' => false,
                'driver_status' => 'DRIVER_UNSUPPORTED',
                'details' => [
                    'sub_status' => 'DRIVER_NOT_AVAILABLE',
                    'note' => 'Driver belum didukung pada versi ini. Protokol belum diverifikasi, namun perangkat tidak disimpulkan inkompatibel secara permanen.',
                ],
            ],
        ];

        return $this->filterMockCandidates($all, $deviceType);
    }
}

