<?php

namespace App\Services\Hardware\Transports;

use App\Exceptions\HardwareSafetyException;
use App\Services\Hardware\Security\HardwareNetworkSecurity;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Throwable;

class ShellyHttpRpcTransport implements ShellyTransportInterface
{
    public const FORMAT_STANDARD = 'A'; // POST /rpc with full JSON-RPC frame (Recommended)
    public const FORMAT_SHORT = 'B';    // POST /rpc/<method> with params only

    protected const MAX_RESPONSE_BYTES = 65536; // 64 KB
    protected Client $client;
    protected string $rpcFormat = self::FORMAT_STANDARD;

    public function __construct(?Client $client = null, string $rpcFormat = self::FORMAT_STANDARD)
    {
        $this->client = $client ?? new Client([
            'http_errors' => false,
            'allow_redirects' => false, // Prevent SSRF redirection
        ]);
        $this->setRpcFormat($rpcFormat);
    }

    public function setRpcFormat(string $format): self
    {
        $this->rpcFormat = in_array(strtoupper($format), [self::FORMAT_STANDARD, self::FORMAT_SHORT], true)
            ? strtoupper($format)
            : self::FORMAT_STANDARD;
        return $this;
    }

    public function getRpcFormat(): string
    {
        return $this->rpcFormat;
    }

    public function getDeviceInfo(string $ip, int $port = 80, ?string $authPassword = null, int $timeoutMs = 3000): array
    {
        return $this->sendRpc($ip, $port, 'Shelly.GetDeviceInfo', [], $authPassword, $timeoutMs);
    }

    public function sendRpc(
        string $ip,
        int $port = 80,
        string $method = 'Switch.GetStatus',
        array $params = [],
        ?string $authPassword = null,
        int $timeoutMs = 3000,
        ?string $format = null
    ): array {
        try {
            HardwareNetworkSecurity::validateTargetIp($ip);
            HardwareNetworkSecurity::validateTargetPort('SHELLY', $port);

            $activeFormat = $format !== null
                ? (in_array(strtoupper($format), [self::FORMAT_STANDARD, self::FORMAT_SHORT], true) ? strtoupper($format) : self::FORMAT_STANDARD)
                : $this->rpcFormat;

            $connectTimeoutSec = min(3.0, $timeoutMs / 1000.0);
            $timeoutSec = max(1.0, $timeoutMs / 1000.0);

            // Determine endpoint path and request payload based on official Shelly documentation
            if ($activeFormat === self::FORMAT_SHORT) {
                // Method B: POST /rpc/<method> with params only
                $url = "http://{$ip}:{$port}/rpc/{$method}";
                $payload = empty($params) ? (object) [] : $params;
            } else {
                // Method A: POST /rpc with complete JSON-RPC 2.0 frame
                $url = "http://{$ip}:{$port}/rpc";
                $payload = [
                    'id' => 1,
                    'src' => 'sugeng_rawuh_pos',
                    'method' => $method,
                    'params' => empty($params) ? (object) [] : $params,
                ];
            }

            $options = [
                'http_errors' => false,
                'allow_redirects' => false,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'User-Agent' => 'SugengRawuhPOS-ShellyDriver/1.0',
                ],
                'connect_timeout' => $connectTimeoutSec,
                'timeout' => $timeoutSec,
                'json' => $payload,
            ];

            if (!empty($authPassword)) {
                $options['auth'] = ['admin', $authPassword, 'digest'];
            }

            $response = $this->client->post($url, $options);
            $statusCode = $response->getStatusCode();
            $body = (string) $response->getBody();

            if (strlen($body) > self::MAX_RESPONSE_BYTES) {
                $body = substr($body, 0, self::MAX_RESPONSE_BYTES);
            }

            if ($statusCode === 401) {
                return [
                    'success' => false,
                    'status' => 'AUTH_REQUIRED',
                    'result' => [],
                    'message' => "Shelly 401 Unauthorized: Autentikasi sandi diperlukan untuk perangkat di {$ip}:{$port}",
                    'http_code' => 401,
                    'rpc_format' => $activeFormat,
                ];
            }

            if ($statusCode === 403) {
                return [
                    'success' => false,
                    'status' => 'REJECTED',
                    'result' => [],
                    'message' => "Shelly 403 Forbidden: Akses RPC ditolak oleh perangkat",
                    'http_code' => 403,
                    'rpc_format' => $activeFormat,
                ];
            }

            $json = json_decode($body, true);
            if (!is_array($json)) {
                return [
                    'success' => false,
                    'status' => 'INVALID_JSON',
                    'result' => [],
                    'message' => "Respons Shelly bukan JSON yang valid (HTTP {$statusCode})",
                    'http_code' => $statusCode,
                    'rpc_format' => $activeFormat,
                ];
            }

            // Error parsing tailored for each format
            if ($activeFormat === self::FORMAT_STANDARD) {
                if (isset($json['error'])) {
                    $errorCode = $json['error']['code'] ?? $statusCode;
                    $errorMessage = $json['error']['message'] ?? 'Unknown Shelly error';
                    return [
                        'success' => false,
                        'status' => 'DEVICE_ERROR',
                        'result' => $json['error'],
                        'message' => "Shelly RPC error ({$errorCode}): {$errorMessage}",
                        'http_code' => $statusCode,
                        'rpc_format' => $activeFormat,
                    ];
                }
                $result = $json['result'] ?? [];
            } else {
                // Method B: response is contents of result or error directly
                if (isset($json['code']) && isset($json['message'])) {
                    $errorCode = $json['code'];
                    $errorMessage = $json['message'];
                    return [
                        'success' => false,
                        'status' => 'DEVICE_ERROR',
                        'result' => $json,
                        'message' => "Shelly RPC error ({$errorCode}): {$errorMessage}",
                        'http_code' => $statusCode,
                        'rpc_format' => $activeFormat,
                    ];
                }
                $result = $json;
            }

            return [
                'success' => true,
                'status' => 'SUCCESS',
                'result' => $result,
                'device_info' => ($method === 'Shelly.GetDeviceInfo') ? $result : [],
                'message' => "Perintah Shelly RPC '{$method}' berhasil dieksekusi",
                'http_code' => $statusCode,
                'rpc_format' => $activeFormat,
            ];
        } catch (HardwareSafetyException $e) {
            return [
                'success' => false,
                'status' => 'SECURITY_VIOLATION',
                'result' => [],
                'message' => $e->getMessage(),
                'http_code' => 422,
            ];
        } catch (ConnectException $e) {
            return [
                'success' => false,
                'status' => 'OFFLINE',
                'result' => [],
                'message' => "Shelly relay tidak dapat dihubungi (Offline / Host unreachable pada {$ip}:{$port})",
                'http_code' => 0,
            ];
        } catch (RequestException $e) {
            $isTimeout = str_contains(strtolower($e->getMessage()), 'timeout')
                || str_contains(strtolower($e->getMessage()), 'timed out');

            if ($e->hasResponse()) {
                $statusCode = $e->getResponse()->getStatusCode();
                $body = (string) $e->getResponse()->getBody();

                if ($statusCode === 401) {
                    return [
                        'success' => false,
                        'status' => 'AUTH_REQUIRED',
                        'result' => [],
                        'message' => "Shelly 401 Unauthorized: Autentikasi sandi diperlukan untuk perangkat di {$ip}:{$port}",
                        'http_code' => 401,
                    ];
                }

                if ($statusCode === 403) {
                    return [
                        'success' => false,
                        'status' => 'REJECTED',
                        'result' => [],
                        'message' => "Shelly 403 Forbidden: Akses RPC ditolak oleh perangkat",
                        'http_code' => 403,
                    ];
                }

                $json = json_decode($body, true);
                if (is_array($json)) {
                    if (isset($json['error'])) {
                        $code = $json['error']['code'] ?? $statusCode;
                        $msg = $json['error']['message'] ?? 'Unknown Shelly error';
                        return [
                            'success' => false,
                            'status' => 'DEVICE_ERROR',
                            'result' => $json['error'],
                            'message' => "Shelly RPC error ({$code}): {$msg}",
                            'http_code' => $statusCode,
                        ];
                    }
                    if (isset($json['code']) && isset($json['message'])) {
                        return [
                            'success' => false,
                            'status' => 'DEVICE_ERROR',
                            'result' => $json,
                            'message' => "Shelly RPC error ({$json['code']}): {$json['message']}",
                            'http_code' => $statusCode,
                        ];
                    }
                }
            }

            return [
                'success' => false,
                'status' => $isTimeout ? 'TIMEOUT' : 'COMMUNICATION_ERROR',
                'result' => [],
                'message' => $isTimeout
                    ? "Waktu tunggu Shelly RPC {$method} habis ({$timeoutMs}ms)"
                    : "Kesalahan komunikasi HTTP: " . $e->getMessage(),
                'http_code' => $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0,
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'status' => 'ERROR',
                'result' => [],
                'message' => "Kesalahan tidak terduga: " . $e->getMessage(),
                'http_code' => 500,
            ];
        }
    }
}
