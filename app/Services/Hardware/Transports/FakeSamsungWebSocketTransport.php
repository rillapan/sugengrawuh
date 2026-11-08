<?php

namespace App\Services\Hardware\Transports;

class FakeSamsungWebSocketTransport implements SamsungTransportInterface
{
    public const SCENARIO_SUCCESS = 'SUCCESS';
    public const SCENARIO_OFFLINE = 'OFFLINE';
    public const SCENARIO_TIMEOUT = 'TIMEOUT';
    public const SCENARIO_AUTH_REJECTED = 'AUTH_REJECTED';
    public const SCENARIO_MALFORMED = 'MALFORMED';

    protected string $scenario = self::SCENARIO_SUCCESS;
    protected bool $connected = false;
    protected ?string $lastToken = null;
    protected array $sentKeys = [];
    protected array $connectionAttempts = [];
    protected ?string $customToken = null;

    public function __construct(string $scenario = self::SCENARIO_SUCCESS)
    {
        $this->scenario = $scenario;
    }

    public function setScenario(string $scenario): self
    {
        $this->scenario = $scenario;
        return $this;
    }

    public function setCustomToken(?string $token): self
    {
        $this->customToken = $token;
        return $this;
    }

    public function checkConnection(string $ip, int $port = 8001, int $timeoutMs = 3000): array
    {
        $this->connectionAttempts[] = ['ip' => $ip, 'port' => $port, 'method' => 'checkConnection'];

        if ($this->scenario === self::SCENARIO_OFFLINE) {
            return [
                'success' => false,
                'status' => 'OFFLINE',
                'latency_ms' => 0,
                'data' => [],
                'message' => "Fake Transport: Target {$ip}:{$port} unreachable (Device Offline)",
            ];
        }

        if ($this->scenario === self::SCENARIO_TIMEOUT) {
            return [
                'success' => false,
                'status' => 'TIMEOUT',
                'latency_ms' => $timeoutMs,
                'data' => [],
                'message' => "Fake Transport: Connection to {$ip}:{$port} timed out after {$timeoutMs}ms",
            ];
        }

        return [
            'success' => true,
            'status' => 'ONLINE',
            'latency_ms' => 4,
            'data' => [
                'device' => [
                    'modelName' => 'Samsung 50U8500 (Simulated)',
                    'networkType' => 'wireless',
                    'PowerState' => 'on',
                    'type' => 'Samsung SmartTV',
                ],
            ],
            'message' => "Fake Transport: REST ping success on {$ip}:{$port} (4ms)",
        ];
    }

    public function openWebSocket(string $ip, int $port = 8001, string $appName = 'Sugeng Rawuh POS', ?string $token = null, int $timeoutMs = 3000): array
    {
        $this->connectionAttempts[] = [
            'ip' => $ip,
            'port' => $port,
            'appName' => $appName,
            'token' => $token,
            'method' => 'openWebSocket',
        ];

        if ($this->scenario === self::SCENARIO_OFFLINE) {
            $this->connected = false;
            return [
                'success' => false,
                'status' => 'OFFLINE',
                'token' => null,
                'message' => "Fake WebSocket: Connection refused by {$ip}:{$port}",
                'event' => null,
            ];
        }

        if ($this->scenario === self::SCENARIO_TIMEOUT) {
            $this->connected = false;
            return [
                'success' => false,
                'status' => 'TIMEOUT',
                'token' => null,
                'message' => "Fake WebSocket: Handshake timeout after {$timeoutMs}ms",
                'event' => null,
            ];
        }

        if ($this->scenario === self::SCENARIO_AUTH_REJECTED) {
            $this->connected = false;
            return [
                'success' => false,
                'status' => 'AUTH_REJECTED',
                'token' => null,
                'message' => "Fake WebSocket: Pairing/Authorization rejected by user on TV screen",
                'event' => 'ms.channel.unauthorized',
            ];
        }

        if ($this->scenario === self::SCENARIO_MALFORMED) {
            $this->connected = false;
            return [
                'success' => false,
                'status' => 'MALFORMED_RESPONSE',
                'token' => null,
                'message' => "Fake WebSocket: Received invalid non-JSON handshake response",
                'event' => null,
            ];
        }

        $this->connected = true;
        $this->lastToken = $this->customToken ?? ('fake_token_' . substr(md5($ip . $appName), 0, 12));

        return [
            'success' => true,
            'status' => 'PAIRED',
            'token' => $this->lastToken,
            'message' => "Fake WebSocket: Handshake accepted, token issued for {$appName}",
            'event' => 'ms.channel.connect',
        ];
    }

    public function sendKey(string $key): array
    {
        if (!$this->connected && $this->scenario !== self::SCENARIO_SUCCESS) {
            return [
                'success' => false,
                'status' => 'DISCONNECTED',
                'message' => "Fake WebSocket: Socket not connected",
                'raw_response' => [],
            ];
        }

        $this->sentKeys[] = $key;

        if ($this->scenario === self::SCENARIO_OFFLINE) {
            return [
                'success' => false,
                'status' => 'OFFLINE',
                'message' => "Fake WebSocket: Connection dropped while sending {$key}",
                'raw_response' => [],
            ];
        }

        if ($this->scenario === self::SCENARIO_TIMEOUT) {
            return [
                'success' => false,
                'status' => 'TIMEOUT',
                'message' => "Fake WebSocket: No frame acknowledgement for {$key}",
                'raw_response' => [],
            ];
        }

        return [
            'success' => true,
            'status' => 'ACK_TRANSPORT',
            'message' => "Fake WebSocket: Key frame '{$key}' transmitted and acknowledged by transport layer",
            'raw_response' => [
                'event' => 'ms.remote.control',
                'data' => [
                    'clients' => [
                        ['attributes' => ['name' => 'Sugeng Rawuh POS'], 'id' => 'fake-client-1'],
                    ],
                ],
            ],
        ];
    }

    public function getDeviceInfo(string $ip, int $port = 8001, int $timeoutMs = 3000): array
    {
        $check = $this->checkConnection($ip, $port, $timeoutMs);
        if (!$check['success']) {
            return $check;
        }

        return [
            'success' => true,
            'status' => 'SUCCESS',
            'info' => [
                'id' => 'uuid:fake-samsung-50u8500',
                'name' => '[TV] Samsung 50U8500',
                'model' => '50U8500',
                'modelName' => 'UN50U8500FXZA',
                'networkType' => 'wireless',
                'PowerState' => 'on',
                'type' => 'Samsung SmartTV',
                'uri' => "http://{$ip}:{$port}/api/v2/",
                'version' => '2.0.26',
            ],
            'message' => 'Fake Transport: REST Device Info retrieved successfully',
        ];
    }

    public function close(): void
    {
        $this->connected = false;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function getLastToken(): ?string
    {
        return $this->lastToken;
    }

    public function getSentKeys(): array
    {
        return $this->sentKeys;
    }

    public function getConnectionAttempts(): array
    {
        return $this->connectionAttempts;
    }
}
