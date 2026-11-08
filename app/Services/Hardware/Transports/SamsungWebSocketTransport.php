<?php

namespace App\Services\Hardware\Transports;

use App\Exceptions\HardwareSafetyException;
use App\Services\Hardware\Security\HardwareNetworkSecurity;
use Exception;
use Throwable;

class SamsungWebSocketTransport implements SamsungTransportInterface
{
    protected const WS_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    protected const MAX_RESPONSE_BYTES = 16384; // 16 KB

    /** @var resource|null */
    protected $socket = null;
    protected bool $connected = false;
    protected ?string $lastToken = null;
    protected string $activeIp = '';
    protected int $activePort = 8001;

    public function checkConnection(string $ip, int $port = 8001, int $timeoutMs = 3000): array
    {
        try {
            HardwareNetworkSecurity::validateTargetIp($ip);
            HardwareNetworkSecurity::validateTargetPort('SAMSUNG', $port);

            $start = microtime(true);
            $errno = 0;
            $errstr = '';
            $timeoutSeconds = $timeoutMs / 1000.0;

            $stream = @fsockopen($ip, $port, $errno, $errstr, $timeoutSeconds);
            if (!$stream) {
                return [
                    'success' => false,
                    'status' => 'OFFLINE',
                    'latency_ms' => 0,
                    'data' => [],
                    'message' => "Samsung TV unreachable on {$ip}:{$port} ({$errstr})",
                ];
            }

            fclose($stream);
            $latencyMs = (int) round((microtime(true) - $start) * 1000);

            // Attempt REST info query
            $infoResult = $this->getDeviceInfo($ip, $port, $timeoutMs);

            return [
                'success' => true,
                'status' => 'ONLINE',
                'latency_ms' => $latencyMs,
                'data' => $infoResult['info'] ?? [],
                'message' => "Samsung TV probe succeeded ({$latencyMs}ms)",
            ];
        } catch (HardwareSafetyException $e) {
            return [
                'success' => false,
                'status' => 'SECURITY_VIOLATION',
                'latency_ms' => 0,
                'data' => [],
                'message' => $e->getMessage(),
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'status' => 'ERROR',
                'latency_ms' => 0,
                'data' => [],
                'message' => "Probe error: " . $e->getMessage(),
            ];
        }
    }

    public function openWebSocket(
        string $ip,
        int $port = 8001,
        string $appName = 'Sugeng Rawuh POS',
        ?string $token = null,
        int $timeoutMs = 3000
    ): array {
        $this->close();

        try {
            HardwareNetworkSecurity::validateTargetIp($ip);
            HardwareNetworkSecurity::validateTargetPort('SAMSUNG', $port);

            $this->activeIp = $ip;
            $this->activePort = $port;
            $isSecure = ($port === 8002);
            $protocol = $isSecure ? 'ssl' : 'tcp';
            $timeoutSeconds = $timeoutMs / 1000.0;

            $contextOptions = [
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false,
                    'SNI_enabled' => true,
                ],
            ];
            $context = stream_context_create($contextOptions);

            $errno = 0;
            $errstr = '';
            $remoteSocket = "{$protocol}://{$ip}:{$port}";

            $this->socket = @stream_socket_client(
                $remoteSocket,
                $errno,
                $errstr,
                $timeoutSeconds,
                STREAM_CLIENT_CONNECT,
                $context
            );

            if (!$this->socket) {
                return [
                    'success' => false,
                    'status' => 'OFFLINE',
                    'token' => null,
                    'message' => "Gagal membuka socket ke {$ip}:{$port}: {$errstr} ({$errno})",
                    'event' => null,
                ];
            }

            stream_set_timeout($this->socket, (int) ceil($timeoutSeconds));

            // Generate RFC-6455 Handshake
            $key = base64_encode(random_bytes(16));
            $encodedName = base64_encode($appName);
            $path = "/api/v2/channels/samsung.remote.control?name={$encodedName}";
            if (!empty($token)) {
                $path .= "&token={$token}";
            }

            $headers = "GET {$path} HTTP/1.1\r\n" .
                "Host: {$ip}:{$port}\r\n" .
                "Upgrade: websocket\r\n" .
                "Connection: Upgrade\r\n" .
                "Sec-WebSocket-Key: {$key}\r\n" .
                "Sec-WebSocket-Version: 13\r\n" .
                "Origin: http://localhost\r\n\r\n";

            fwrite($this->socket, $headers);

            // Read Handshake response
            $response = '';
            while (!feof($this->socket)) {
                $line = fgets($this->socket, 1024);
                if ($line === false || $line === "\r\n" || $line === "\n") {
                    break;
                }
                $response .= $line;
                if (strlen($response) > self::MAX_RESPONSE_BYTES) {
                    break;
                }
            }

            if (!str_contains($response, '101 Switching Protocols')) {
                $this->close();
                return [
                    'success' => false,
                    'status' => 'HANDSHAKE_FAILED',
                    'token' => null,
                    'message' => 'Samsung TV menolak WebSocket upgrade handshake',
                    'event' => null,
                ];
            }

            // Verify Sec-WebSocket-Accept header
            $expectedAccept = base64_encode(sha1($key . self::WS_GUID, true));
            if (!str_contains($response, $expectedAccept)) {
                $this->close();
                return [
                    'success' => false,
                    'status' => 'INVALID_WS_ACCEPT',
                    'token' => null,
                    'message' => 'Header Sec-WebSocket-Accept tidak valid',
                    'event' => null,
                ];
            }

            $this->connected = true;

            // Read initial message frame (e.g. ms.channel.connect event with pairing token)
            $frame = $this->readFrame();
            if ($frame !== null && isset($frame['event'])) {
                if ($frame['event'] === 'ms.channel.connect') {
                    $receivedToken = $frame['data']['token'] ?? null;
                    if ($receivedToken) {
                        $this->lastToken = (string) $receivedToken;
                    }
                    return [
                        'success' => true,
                        'status' => 'PAIRED',
                        'token' => $this->lastToken,
                        'message' => 'WebSocket terhubung dan channel pairing aktif',
                        'event' => $frame['event'],
                    ];
                }

                if ($frame['event'] === 'ms.channel.unauthorized') {
                    return [
                        'success' => false,
                        'status' => 'AUTH_REJECTED',
                        'token' => null,
                        'message' => 'Otorisasi pairing ditolak pada layar TV',
                        'event' => $frame['event'],
                    ];
                }
            }

            return [
                'success' => true,
                'status' => 'CONNECTED',
                'token' => $this->lastToken,
                'message' => 'WebSocket terhubung ke Samsung Remote Channel',
                'event' => $frame['event'] ?? 'connected',
            ];
        } catch (HardwareSafetyException $e) {
            $this->close();
            return [
                'success' => false,
                'status' => 'SECURITY_VIOLATION',
                'token' => null,
                'message' => $e->getMessage(),
                'event' => null,
            ];
        } catch (Throwable $e) {
            $this->close();
            return [
                'success' => false,
                'status' => 'ERROR',
                'token' => null,
                'message' => 'Koneksi WebSocket gagal: ' . $e->getMessage(),
                'event' => null,
            ];
        }
    }

    public function sendKey(string $key): array
    {
        if (!$this->connected || !$this->socket) {
            return [
                'success' => false,
                'status' => 'DISCONNECTED',
                'message' => 'Socket WebSocket tidak terhubung',
                'raw_response' => [],
            ];
        }

        try {
            $payload = [
                'method' => 'ms.remote.control',
                'params' => [
                    'Cmd' => 'Click',
                    'DataOfCmd' => $key,
                    'Option' => 'false',
                    'TypeOfRemote' => 'SendRemoteKey',
                ],
            ];

            $json = json_encode($payload, JSON_THROW_ON_ERROR);
            $this->writeMaskedFrame($json);

            // Read response acknowledgement frame if available
            $response = $this->readFrame();

            return [
                'success' => true,
                'status' => 'ACK_TRANSPORT',
                'message' => "Frame kunci '{$key}' terkirim dan diakui oleh transport layer",
                'raw_response' => $response ?? ['transport_ack' => true],
            ];
        } catch (Throwable $e) {
            $this->close();
            return [
                'success' => false,
                'status' => 'TRANSPORT_ERROR',
                'message' => "Gagal mengirim kunci '{$key}': " . $e->getMessage(),
                'raw_response' => [],
            ];
        }
    }

    public function getDeviceInfo(string $ip, int $port = 8001, int $timeoutMs = 3000): array
    {
        try {
            HardwareNetworkSecurity::validateTargetIp($ip);
            HardwareNetworkSecurity::validateTargetPort('SAMSUNG', $port);

            $url = "http://{$ip}:{$port}/api/v2/";
            $timeoutSeconds = $timeoutMs / 1000.0;

            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => $timeoutSeconds,
                    'ignore_errors' => true,
                    'header' => "Accept: application/json\r\nUser-Agent: SugengRawuhPOS/1.0\r\n",
                ],
            ]);

            $content = @file_get_contents($url, false, $context);
            if ($content === false || strlen($content) === 0) {
                return [
                    'success' => false,
                    'status' => 'OFFLINE',
                    'info' => [],
                    'message' => "Tidak dapat mengambil REST Device Info dari {$url}",
                ];
            }

            if (strlen($content) > self::MAX_RESPONSE_BYTES) {
                $content = substr($content, 0, self::MAX_RESPONSE_BYTES);
            }

            $json = json_decode($content, true);
            if (!is_array($json)) {
                return [
                    'success' => false,
                    'status' => 'INVALID_JSON',
                    'info' => [],
                    'message' => "Respons REST info bukan JSON yang valid",
                ];
            }

            return [
                'success' => true,
                'status' => 'SUCCESS',
                'info' => $json['device'] ?? $json,
                'message' => 'REST Device Info berhasil diambil',
            ];
        } catch (HardwareSafetyException $e) {
            return [
                'success' => false,
                'status' => 'SECURITY_VIOLATION',
                'info' => [],
                'message' => $e->getMessage(),
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'status' => 'ERROR',
                'info' => [],
                'message' => $e->getMessage(),
            ];
        }
    }

    public function close(): void
    {
        if ($this->socket && is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
        $this->connected = false;
    }

    public function isConnected(): bool
    {
        return $this->connected && $this->socket !== null && is_resource($this->socket);
    }

    public function getLastToken(): ?string
    {
        return $this->lastToken;
    }

    /**
     * Write masked text frame to WebSocket (RFC 6455).
     */
    protected function writeMaskedFrame(string $payload): void
    {
        $length = strlen($payload);
        $frame = chr(0x81); // FIN + Opcode 1 (Text)

        if ($length <= 125) {
            $frame .= chr(0x80 | $length);
        } elseif ($length <= 65535) {
            $frame .= chr(0x80 | 126) . pack('n', $length);
        } else {
            $frame .= chr(0x80 | 127) . pack('J', $length);
        }

        $maskKey = random_bytes(4);
        $frame .= $maskKey;

        // XOR payload with mask
        $maskedPayload = '';
        for ($i = 0; $i < $length; $i++) {
            $maskedPayload .= $payload[$i] ^ $maskKey[$i % 4];
        }

        $frame .= $maskedPayload;
        fwrite($this->socket, $frame);
    }

    /**
     * Read and decode unmasked or masked frame from TV.
     */
    protected function readFrame(): ?array
    {
        if (!$this->socket || !is_resource($this->socket)) {
            return null;
        }

        $header = fread($this->socket, 2);
        if ($header === false || strlen($header) < 2) {
            return null;
        }

        $byte1 = ord($header[0]);
        $byte2 = ord($header[1]);
        $isMasked = ($byte2 & 0x80) !== 0;
        $payloadLength = $byte2 & 0x7F;

        if ($payloadLength === 126) {
            $lenBytes = fread($this->socket, 2);
            if ($lenBytes === false || strlen($lenBytes) < 2) {
                return null;
            }
            $payloadLength = unpack('n', $lenBytes)[1];
        } elseif ($payloadLength === 127) {
            $lenBytes = fread($this->socket, 8);
            if ($lenBytes === false || strlen($lenBytes) < 8) {
                return null;
            }
            $payloadLength = unpack('J', $lenBytes)[1];
        }

        if ($payloadLength > self::MAX_RESPONSE_BYTES) {
            $payloadLength = self::MAX_RESPONSE_BYTES;
        }

        $maskKey = '';
        if ($isMasked) {
            $maskKey = fread($this->socket, 4);
        }

        $payload = '';
        $remaining = $payloadLength;
        while ($remaining > 0 && !feof($this->socket)) {
            $chunk = fread($this->socket, min(1024, $remaining));
            if ($chunk === false) {
                break;
            }
            $payload .= $chunk;
            $remaining -= strlen($chunk);
        }

        if ($isMasked && strlen($maskKey) === 4) {
            $unmasked = '';
            for ($i = 0; $i < strlen($payload); $i++) {
                $unmasked .= $payload[$i] ^ $maskKey[$i % 4];
            }
            $payload = $unmasked;
        }

        $decoded = json_decode($payload, true);
        return is_array($decoded) ? $decoded : ['raw' => $payload];
    }
}
