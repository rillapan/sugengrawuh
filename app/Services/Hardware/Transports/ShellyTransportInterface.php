<?php

namespace App\Services\Hardware\Transports;

interface ShellyTransportInterface
{
    /**
     * Call Shelly.GetDeviceInfo via HTTP RPC.
     *
     * @param string $ip
     * @param int $port
     * @param string|null $authPassword
     * @param int $timeoutMs
     * @return array ['success' => bool, 'status' => string, 'device_info' => array, 'message' => string]
     */
    public function getDeviceInfo(string $ip, int $port = 80, ?string $authPassword = null, int $timeoutMs = 3000): array;

    /**
     * Send HTTP RPC command to Shelly Gen3 (e.g. Switch.Set, Switch.GetStatus).
     *
     * @param string $ip
     * @param int $port
     * @param string $method
     * @param array $params
     * @param string|null $authPassword
     * @param int $timeoutMs
     * @return array ['success' => bool, 'status' => string, 'result' => array, 'message' => string, 'http_code' => int]
     */
    public function sendRpc(
        string $ip,
        int $port = 80,
        string $method = 'Switch.GetStatus',
        array $params = [],
        ?string $authPassword = null,
        int $timeoutMs = 3000
    ): array;
}
