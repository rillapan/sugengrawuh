<?php

namespace App\Services\Hardware\Transports;

interface Ps3TransportInterface
{
    /**
     * Check if PS3 console webMAN MOD HTTP server is responsive.
     *
     * @param string $ip
     * @param int $port
     * @param int $timeoutMs
     * @return array ['success' => bool, 'status' => string, 'message' => string, 'data' => array]
     */
    public function checkConnection(string $ip, int $port, int $timeoutMs): array;

    /**
     * Send popup notification to PS3 via webMAN MOD HTTP.
     *
     * @param string $ip
     * @param int $port
     * @param string $message
     * @param int $timeoutMs
     * @return array ['success' => bool, 'status' => string, 'message' => string, 'raw_response' => mixed]
     */
    public function sendNotification(string $ip, int $port, string $message, int $timeoutMs): array;
}

