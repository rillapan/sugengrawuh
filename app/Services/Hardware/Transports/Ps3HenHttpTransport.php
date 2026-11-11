<?php

namespace App\Services\Hardware\Transports;

use App\Exceptions\HardwareSafetyException;
use App\Services\Hardware\Security\HardwareNetworkSecurity;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

class Ps3HenHttpTransport implements Ps3TransportInterface
{
    protected Client $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client();
    }

    public function getClient(): Client
    {
        return $this->client;
    }

    /**
     * Build the webMAN MOD popup endpoint path using rawurlencode.
     *
     * @param string $message
     * @return string
     */
    public function buildNotificationPath(string $message): string
    {
        return '/popup.ps3/' . rawurlencode($message);
    }

    /**
     * Build full HTTP URL for webMAN MOD popup notification.
     *
     * @param string $ip
     * @param int $port
     * @param string $message
     * @return string
     */
    public function buildNotificationUrl(string $ip, int $port, string $message): string
    {
        return "http://{$ip}:{$port}" . $this->buildNotificationPath($message);
    }

    /**
     * Check if PS3 console webMAN MOD HTTP server is responsive.
     *
     * @param string $ip
     * @param int $port
     * @param int $timeoutMs
     * @return array
     */
    public function checkConnection(string $ip, int $port, int $timeoutMs): array
    {
        try {
            HardwareNetworkSecurity::validateTargetIp($ip);
            HardwareNetworkSecurity::validateTargetPort('SONY', $port);

            $url = "http://{$ip}:{$port}/cpursx.ps3";
            $connectTimeoutSec = (float) (config('hardware.ps_console.ps3.connect_timeout_ms', 2000) / 1000);
            $requestTimeoutSec = (float) ($timeoutMs / 1000);

            $response = $this->client->get($url, [
                'timeout' => $requestTimeoutSec,
                'connect_timeout' => $connectTimeoutSec,
                'headers' => [
                    'User-Agent' => 'SugengRawuhPOS-PS3/1.0',
                ],
            ]);

            $body = (string) $response->getBody();

            return [
                'success' => true,
                'status' => 'ONLINE',
                'message' => 'PS3 webMAN MOD HTTP server aktif dan merespons',
                'data' => [
                    'ip' => $ip,
                    'port' => $port,
                    'status_code' => $response->getStatusCode(),
                    'raw_snippet' => mb_substr($body, 0, 100),
                ],
            ];
        } catch (HardwareSafetyException $e) {
            return [
                'success' => false,
                'status' => 'SECURITY_REJECTED',
                'message' => $e->getMessage(),
                'data' => [],
            ];
        } catch (ConnectException $e) {
            $isTimeout = strpos(strtolower($e->getMessage()), 'timed out') !== false ||
                         strpos(strtolower($e->getMessage()), 'timeout') !== false;
            return [
                'success' => false,
                'status' => $isTimeout ? 'TIMEOUT' : 'OFFLINE',
                'message' => $isTimeout ? "Timeout memeriksa koneksi PS3 ({$timeoutMs}ms)" : "PS3 offline atau unreachable pada {$ip}:{$port}",
                'data' => [],
            ];
        } catch (RequestException $e) {
            $isTimeout = strpos(strtolower($e->getMessage()), 'timed out') !== false ||
                         strpos(strtolower($e->getMessage()), 'timeout') !== false;
            return [
                'success' => false,
                'status' => $isTimeout ? 'TIMEOUT' : 'REQUEST_ERROR',
                'message' => $isTimeout ? "Timeout memeriksa koneksi PS3 ({$timeoutMs}ms)" : "HTTP request error: " . $e->getMessage(),
                'data' => [],
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'status' => 'ERROR',
                'message' => "Kesalahan koneksi PS3: " . $e->getMessage(),
                'data' => [],
            ];
        }
    }

    /**
     * Send popup notification to PS3 via webMAN MOD HTTP.
     *
     * @param string $ip
     * @param int $port
     * @param string $message
     * @param int $timeoutMs
     * @return array
     */
    public function sendNotification(string $ip, int $port, string $message, int $timeoutMs): array
    {
        try {
            HardwareNetworkSecurity::validateTargetIp($ip);
            HardwareNetworkSecurity::validateTargetPort('SONY', $port);

            // webMAN MOD format: GET /popup.ps3/<url_encoded_message>
            $url = $this->buildNotificationUrl($ip, $port, $message);

            $connectTimeoutSec = (float) (config('hardware.ps_console.ps3.connect_timeout_ms', 2000) / 1000);
            $requestTimeoutSec = $timeoutMs > 0 ? (float) ($timeoutMs / 1000) : (float) (config('hardware.ps_console.ps3.timeout_ms', 5000) / 1000);

            $response = $this->client->get($url, [
                'timeout' => $requestTimeoutSec,
                'connect_timeout' => $connectTimeoutSec,
                'headers' => [
                    'User-Agent' => 'SugengRawuhPOS-PS3/1.0',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $body = (string) $response->getBody();

            $success = ($statusCode >= 200 && $statusCode < 300);

            return [
                'success' => $success,
                'status' => $success ? 'ACK_HTTP' : 'HTTP_ERROR',
                'message' => $success
                    ? 'Frame notifikasi HTTP diterima oleh webMAN MOD (ACK_HTTP)'
                    : "webMAN MOD mengembalikan kode status {$statusCode}",
                'raw_response' => mb_substr($body, 0, 200),
            ];
        } catch (HardwareSafetyException $e) {
            return [
                'success' => false,
                'status' => 'SECURITY_REJECTED',
                'message' => $e->getMessage(),
                'raw_response' => null,
            ];
        } catch (ConnectException $e) {
            $isTimeout = strpos(strtolower($e->getMessage()), 'timed out') !== false ||
                         strpos(strtolower($e->getMessage()), 'timeout') !== false;
            return [
                'success' => false,
                'status' => $isTimeout ? 'TIMEOUT' : 'OFFLINE',
                'message' => $isTimeout
                    ? "Pengiriman notifikasi timeout setelah {$timeoutMs}ms"
                    : "PS3 offline pada {$ip}:{$port}",
                'raw_response' => null,
            ];
        } catch (RequestException $e) {
            $isTimeout = strpos(strtolower($e->getMessage()), 'timed out') !== false ||
                         strpos(strtolower($e->getMessage()), 'timeout') !== false;
            if ($isTimeout) {
                return [
                    'success' => false,
                    'status' => 'TIMEOUT',
                    'message' => "Pengiriman notifikasi timeout setelah {$timeoutMs}ms",
                    'raw_response' => null,
                ];
            }

            return [
                'success' => false,
                'status' => 'REQUEST_ERROR',
                'message' => "HTTP request error: " . $e->getMessage(),
                'raw_response' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'status' => 'TRANSMIT_FAILED',
                'message' => "Gagal mengirim notifikasi ke PS3: " . $e->getMessage(),
                'raw_response' => null,
            ];
        }
    }
}
