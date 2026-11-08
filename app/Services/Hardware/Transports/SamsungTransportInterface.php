<?php

namespace App\Services\Hardware\Transports;

interface SamsungTransportInterface
{
    /**
     * Check device reachability / REST endpoint.
     *
     * @param string $ip
     * @param int $port
     * @param int $timeoutMs
     * @return array ['success' => bool, 'status' => string, 'latency_ms' => int, 'data' => array, 'message' => string]
     */
    public function checkConnection(string $ip, int $port = 8001, int $timeoutMs = 3000): array;

    /**
     * Perform WebSocket connection and pairing handshake.
     *
     * @param string $ip
     * @param int $port
     * @param string $appName
     * @param string|null $token
     * @param int $timeoutMs
     * @return array ['success' => bool, 'status' => string, 'token' => ?string, 'message' => string, 'event' => ?string]
     */
    public function openWebSocket(string $ip, int $port = 8001, string $appName = 'Sugeng Rawuh POS', ?string $token = null, int $timeoutMs = 3000): array;

    /**
     * Send remote key code frame over WebSocket.
     *
     * @param string $key (e.g. KEY_MUTE, KEY_POWER)
     * @return array ['success' => bool, 'status' => string, 'message' => string, 'raw_response' => array]
     */
    public function sendKey(string $key): array;

    /**
     * Retrieve REST device info payload (http://{ip}:{port}/api/v2/).
     *
     * @param string $ip
     * @param int $port
     * @param int $timeoutMs
     * @return array ['success' => bool, 'status' => string, 'info' => array, 'message' => string]
     */
    public function getDeviceInfo(string $ip, int $port = 8001, int $timeoutMs = 3000): array;

    /**
     * Close active WebSocket connection.
     */
    public function close(): void;

    /**
     * Check if socket is open and ready.
     *
     * @return bool
     */
    public function isConnected(): bool;

    /**
     * Get the pairing token obtained during handshake (if any).
     *
     * @return string|null
     */
    public function getLastToken(): ?string;
}
