<?php

namespace App\Services\Hardware\Transports;

use App\Exceptions\HardwareSafetyException;
use App\Services\Hardware\Security\HardwareNetworkSecurity;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

class GoldHenHttpNotificationTransport implements Ps4TransportInterface
{
    protected Client $client;
    protected bool $protocolVerified;

    public function __construct(?Client $client = null, ?bool $protocolVerified = null)
    {
        $this->client = $client ?? new Client();
        $this->protocolVerified = $protocolVerified ?? (bool) config('hardware.ps_console.ps4.real_protocol_enabled', false);
    }

    public function isProtocolVerified(): bool
    {
        return $this->protocolVerified;
    }

    public function checkConnection(string $ip, int $port, int $timeoutMs): array
    {
        // STRICT SAFETY CHECK: Port 9090 is GoldHEN BinLoader, NOT a notification string endpoint!
        if ($port === 9090) {
            return [
                'success' => false,
                'status' => 'UNSUPPORTED_PROTOCOL',
                'message' => 'Port 9090 adalah GoldHEN BinLoader (khusus binary/payload inject), bukan endpoint string notifikasi. Permintaan dibatalkan.',
                'data' => ['blocked_reason' => 'BINLOADER_PORT_MISMATCH'],
            ];
        }

        if (!$this->protocolVerified) {
            return [
                'success' => false,
                'status' => 'NOT_CONFIGURED',
                'message' => 'Protokol fisik notifikasi PS4 belum diverifikasi pada firmware fisik (status: NOT_CONFIGURED)',
                'data' => [],
            ];
        }

        try {
            HardwareNetworkSecurity::validateTargetIp($ip);
            HardwareNetworkSecurity::validateTargetPort('SONY', $port);

            $endpoint = config('hardware.ps_console.ps4.endpoint', '/notify');
            $url = "http://{$ip}:{$port}{$endpoint}";

            $response = $this->client->get($url, [
                'timeout' => $timeoutMs / 1000,
                'connect_timeout' => $timeoutMs / 1000,
                'headers' => [
                    'User-Agent' => 'SugengRawuhPOS-PS4/1.0',
                ],
            ]);

            return [
                'success' => true,
                'status' => 'ONLINE',
                'message' => 'PS4 GoldHEN Notification endpoint aktif',
                'data' => [
                    'ip' => $ip,
                    'port' => $port,
                    'status_code' => $response->getStatusCode(),
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
            $isTimeout = strpos(strtolower($e->getMessage()), 'timed out') !== false;
            return [
                'success' => false,
                'status' => $isTimeout ? 'TIMEOUT' : 'OFFLINE',
                'message' => $isTimeout ? "Timeout memeriksa koneksi PS4 ({$timeoutMs}ms)" : "PS4 offline atau unreachable pada {$ip}:{$port}",
                'data' => [],
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'status' => 'ERROR',
                'message' => "Kesalahan koneksi PS4: " . $e->getMessage(),
                'data' => [],
            ];
        }
    }

    public function sendNotification(string $ip, int $port, string $message, int $timeoutMs): array
    {
        // STRICT SAFETY CHECK: Port 9090 is GoldHEN BinLoader, NOT a notification string endpoint!
        if ($port === 9090) {
            return [
                'success' => false,
                'status' => 'UNSUPPORTED_PROTOCOL',
                'message' => 'Port 9090 adalah GoldHEN BinLoader (khusus binary/payload inject), bukan endpoint string notifikasi. Pengiriman teks dibatalkan demi keamanan konsol.',
                'raw_response' => null,
                'data' => ['blocked_reason' => 'BINLOADER_PORT_MISMATCH'],
            ];
        }

        if (!$this->protocolVerified) {
            return [
                'success' => false,
                'status' => 'NOT_CONFIGURED',
                'message' => 'Protokol fisik notifikasi PS4 belum dikonfigurasi/diverifikasi secara fisik pada perangkat ini (NOT_CONFIGURED)',
                'raw_response' => null,
            ];
        }

        try {
            HardwareNetworkSecurity::validateTargetIp($ip);
            HardwareNetworkSecurity::validateTargetPort('SONY', $port);

            $endpoint = config('hardware.ps_console.ps4.endpoint', '/notify');
            $encodedMessage = rawurlencode($message);
            $url = "http://{$ip}:{$port}{$endpoint}?message={$encodedMessage}";

            $response = $this->client->get($url, [
                'timeout' => $timeoutMs / 1000,
                'connect_timeout' => $timeoutMs / 1000,
                'headers' => [
                    'User-Agent' => 'SugengRawuhPOS-PS4/1.0',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $body = (string) $response->getBody();
            $success = ($statusCode >= 200 && $statusCode < 300);

            return [
                'success' => $success,
                'status' => $success ? 'ACK_HTTP' : 'HTTP_ERROR',
                'message' => $success
                    ? 'Frame notifikasi HTTP diterima oleh PS4 GoldHEN daemon (ACK_HTTP)'
                    : "PS4 GoldHEN mengembalikan status HTTP {$statusCode}",
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
            $isTimeout = strpos(strtolower($e->getMessage()), 'timed out') !== false;
            return [
                'success' => false,
                'status' => $isTimeout ? 'TIMEOUT' : 'OFFLINE',
                'message' => $isTimeout
                    ? "Pengiriman notifikasi timeout setelah {$timeoutMs}ms"
                    : "PS4 offline pada {$ip}:{$port}",
                'raw_response' => null,
            ];
        } catch (RequestException $e) {
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
                'message' => "Gagal mengirim notifikasi ke PS4: " . $e->getMessage(),
                'raw_response' => null,
            ];
        }
    }
}

