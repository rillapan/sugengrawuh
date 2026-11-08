<?php

namespace App\Services\Hardware\Transports;

class FakeShellyHttpRpcTransport implements ShellyTransportInterface
{
    public const SCENARIO_SUCCESS = 'SUCCESS';
    public const SCENARIO_OFFLINE = 'OFFLINE';
    public const SCENARIO_TIMEOUT = 'TIMEOUT';
    public const SCENARIO_AUTH_REQUIRED = 'AUTH_REQUIRED';
    public const SCENARIO_INVALID_CREDENTIALS = 'INVALID_CREDENTIALS';
    public const SCENARIO_INVALID_JSON = 'INVALID_JSON';
    public const SCENARIO_ERROR_RESPONSE = 'ERROR_RESPONSE';

    protected string $scenario = self::SCENARIO_SUCCESS;
    protected bool $outputState = false;
    protected float $powerWatts = 0.0;
    protected array $rpcLog = [];

    public function __construct(string $scenario = self::SCENARIO_SUCCESS)
    {
        $this->scenario = $scenario;
    }

    public function setScenario(string $scenario): self
    {
        $this->scenario = $scenario;
        return $this;
    }

    public function setOutputState(bool $state, float $powerWatts = 45.0): self
    {
        $this->outputState = $state;
        $this->powerWatts = $state ? $powerWatts : 0.0;
        return $this;
    }

    public function getDeviceInfo(string $ip, int $port = 80, ?string $authPassword = null, int $timeoutMs = 3000): array
    {
        $this->rpcLog[] = [
            'method' => 'Shelly.GetDeviceInfo',
            'ip' => $ip,
            'port' => $port,
            'auth' => $authPassword ? '[PROVIDED]' : null,
        ];

        if ($this->scenario === self::SCENARIO_OFFLINE) {
            return [
                'success' => false,
                'status' => 'OFFLINE',
                'device_info' => [],
                'message' => "Fake Shelly: Host {$ip}:{$port} unreachable (Connection Refused)",
            ];
        }

        if ($this->scenario === self::SCENARIO_TIMEOUT) {
            return [
                'success' => false,
                'status' => 'TIMEOUT',
                'device_info' => [],
                'message' => "Fake Shelly: Request to {$ip}:{$port} timed out ({$timeoutMs}ms)",
            ];
        }

        if ($this->scenario === self::SCENARIO_AUTH_REQUIRED && empty($authPassword)) {
            return [
                'success' => false,
                'status' => 'AUTH_REQUIRED',
                'device_info' => [],
                'message' => "Fake Shelly: 401 Unauthorized — Authentication required for this Shelly device",
            ];
        }

        if ($this->scenario === self::SCENARIO_INVALID_CREDENTIALS) {
            return [
                'success' => false,
                'status' => 'AUTH_FAILED',
                'device_info' => [],
                'message' => "Fake Shelly: 401 Unauthorized — Invalid authentication password",
            ];
        }

        if ($this->scenario === self::SCENARIO_INVALID_JSON) {
            return [
                'success' => false,
                'status' => 'INVALID_JSON',
                'device_info' => [],
                'message' => "Fake Shelly: Malformed response received from {$ip}:{$port}",
            ];
        }

        return [
            'success' => true,
            'status' => 'SUCCESS',
            'device_info' => [
                'id' => 'shelly1pmmini3-aabbccddeeff',
                'name' => 'Billiard Light Relay 1',
                'app' => 'Plus1PMMini',
                'model' => 'SNG3-001P16EU',
                'gen' => 3,
                'ver' => '1.4.4',
                'mac' => 'AA:BB:CC:DD:EE:FF',
                'auth_en' => ($this->scenario === self::SCENARIO_AUTH_REQUIRED),
            ],
            'message' => "Fake Shelly: Shelly.GetDeviceInfo returned Gen3 hardware details",
        ];
    }

    public function sendRpc(
        string $ip,
        int $port = 80,
        string $method = 'Switch.GetStatus',
        array $params = [],
        ?string $authPassword = null,
        int $timeoutMs = 3000
    ): array {
        $this->rpcLog[] = [
            'method' => $method,
            'params' => $params,
            'ip' => $ip,
            'port' => $port,
        ];

        if ($this->scenario === self::SCENARIO_OFFLINE) {
            return [
                'success' => false,
                'status' => 'OFFLINE',
                'result' => [],
                'message' => "Fake Shelly: Host unreachable ({$ip}:{$port})",
                'http_code' => 0,
            ];
        }

        if ($this->scenario === self::SCENARIO_TIMEOUT) {
            return [
                'success' => false,
                'status' => 'TIMEOUT',
                'result' => [],
                'message' => "Fake Shelly: RPC {$method} timed out ({$timeoutMs}ms)",
                'http_code' => 504,
            ];
        }

        if ($this->scenario === self::SCENARIO_AUTH_REQUIRED && empty($authPassword)) {
            return [
                'success' => false,
                'status' => 'AUTH_REQUIRED',
                'result' => [],
                'message' => "Fake Shelly: 401 Unauthorized (Password required)",
                'http_code' => 401,
            ];
        }

        if ($this->scenario === self::SCENARIO_ERROR_RESPONSE) {
            return [
                'success' => false,
                'status' => 'DEVICE_ERROR',
                'result' => [
                    'code' => 400,
                    'message' => 'Shelly RPC Error: Overpower protection engaged',
                ],
                'message' => "Fake Shelly: Device returned error code 400",
                'http_code' => 400,
            ];
        }

        if ($this->scenario === self::SCENARIO_INVALID_JSON) {
            return [
                'success' => false,
                'status' => 'INVALID_JSON',
                'result' => [],
                'message' => "Fake Shelly: Unable to parse JSON-RPC response",
                'http_code' => 200,
            ];
        }

        // Process Method
        if ($method === 'Switch.Set') {
            $channel = $params['id'] ?? 0;
            if ($channel !== 0) {
                return [
                    'success' => false,
                    'status' => 'INVALID_CHANNEL',
                    'result' => ['code' => 404, 'message' => "Relay switch channel {$channel} not found on Shelly 1PM Mini"],
                    'message' => "Fake Shelly: Invalid channel {$channel}",
                    'http_code' => 404,
                ];
            }

            $wasOn = $this->outputState;
            $this->outputState = (bool) ($params['on'] ?? false);
            $this->powerWatts = $this->outputState ? 45.2 : 0.0;

            return [
                'success' => true,
                'status' => 'SUCCESS',
                'result' => [
                    'was_on' => $wasOn,
                ],
                'message' => "Fake Shelly: Switch.Set id:0 on:" . ($this->outputState ? 'true' : 'false') . " succeeded",
                'http_code' => 200,
            ];
        }

        if ($method === 'Switch.GetStatus') {
            return [
                'success' => true,
                'status' => 'SUCCESS',
                'result' => [
                    'id' => 0,
                    'source' => 'HTTP_RPC',
                    'output' => $this->outputState,
                    'apower' => $this->powerWatts,
                    'voltage' => 228.4,
                    'current' => $this->outputState ? 0.20 : 0.0,
                    'temperature' => [
                        'tC' => 38.5,
                        'tF' => 101.3,
                    ],
                ],
                'message' => "Fake Shelly: Switch.GetStatus output is " . ($this->outputState ? 'ON' : 'OFF'),
                'http_code' => 200,
            ];
        }

        return [
            'success' => true,
            'status' => 'SUCCESS',
            'result' => [],
            'message' => "Fake Shelly: Method {$method} acknowledged",
            'http_code' => 200,
        ];
    }

    public function getRpcLog(): array
    {
        return $this->rpcLog;
    }
}
