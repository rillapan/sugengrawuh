<?php

namespace App\Services\Hardware\DTO;

use JsonSerializable;

class HardwareCommandResult implements JsonSerializable
{
    public int $deviceId;
    public string $driverType;
    public bool $simulationMode;
    public string $command;
    public bool $success;
    public string $resultCode;
    public string $message;
    public array $simulatedState;
    public array $telemetry;
    public string $executedAt;
    public array $rawResponse;

    public function __construct(
        int $deviceId,
        string $driverType,
        bool $simulationMode,
        string $command,
        bool $success,
        string $resultCode,
        string $message,
        array $simulatedState = [],
        ?string $executedAt = null,
        array $rawResponse = []
    ) {
        $this->deviceId = $deviceId;
        $this->driverType = $driverType;
        $this->simulationMode = $simulationMode;
        $this->command = $command;
        $this->success = $success;
        $this->resultCode = $resultCode;
        $this->message = $message;
        $this->simulatedState = $simulatedState;
        $this->telemetry = $simulatedState;
        $this->executedAt = $executedAt ?? now()->toIso8601String();
        $this->rawResponse = $rawResponse;
    }

    public static function createSuccess(
        int $deviceId,
        string $driverType,
        string $command,
        string $message,
        array $simulatedState = [],
        array $rawResponse = []
    ): self {
        return new self(
            $deviceId,
            $driverType,
            true,
            $command,
            true,
            'SUCCESS',
            $message,
            $simulatedState,
            now()->toIso8601String(),
            $rawResponse
        );
    }

    public static function createOffline(
        int $deviceId,
        string $driverType,
        string $command,
        string $message = 'Perangkat tidak merespons (Offline / Unreachable)',
        array $simulatedState = []
    ): self {
        return new self(
            $deviceId,
            $driverType,
            true,
            $command,
            false,
            'OFFLINE',
            $message,
            $simulatedState,
            now()->toIso8601String(),
            ['status' => 'OFFLINE', 'reason' => 'HOST_UNREACHABLE']
        );
    }

    public static function createTimeout(
        int $deviceId,
        string $driverType,
        string $command,
        int $timeoutMs = 3000,
        array $simulatedState = []
    ): self {
        return new self(
            $deviceId,
            $driverType,
            true,
            $command,
            false,
            'TIMEOUT',
            "Waktu tunggu koneksi perangkat habis ({$timeoutMs}ms)",
            $simulatedState,
            now()->toIso8601String(),
            ['status' => 'TIMEOUT', 'timeout_ms' => $timeoutMs]
        );
    }

    public static function createRejected(
        int $deviceId,
        string $driverType,
        string $command,
        string $message = 'Perintah ditolak oleh perangkat virtual',
        array $simulatedState = []
    ): self {
        return new self(
            $deviceId,
            $driverType,
            true,
            $command,
            false,
            'COMMAND_REJECTED',
            $message,
            $simulatedState,
            now()->toIso8601String(),
            ['status' => 'REJECTED', 'code' => 403]
        );
    }

    public static function createError(
        int $deviceId,
        string $driverType,
        string $command,
        string $message,
        string $resultCode = 'ERROR',
        array $simulatedState = [],
        bool $simulationMode = true
    ): self {
        return new self(
            $deviceId,
            $driverType,
            $simulationMode,
            $command,
            false,
            $resultCode,
            $message,
            $simulatedState,
            now()->toIso8601String(),
            ['status' => 'ERROR', 'error' => $message]
        );
    }

    public static function createRealResult(
        int $deviceId,
        string $driverType,
        string $command,
        bool $success,
        string $resultCode,
        string $message,
        array $deviceState = [],
        array $rawResponse = []
    ): self {
        return new self(
            $deviceId,
            $driverType,
            false, // Real execution mode (not simulation)
            $command,
            $success,
            $resultCode,
            $message,
            $deviceState,
            now()->toIso8601String(),
            $rawResponse
        );
    }

    public static function createUnsupportedCapability(
        int $deviceId,
        string $driverType,
        string $command,
        string $message = 'Perintah belum didukung atau belum terverifikasi untuk perangkat ini'
    ): self {
        return new self(
            $deviceId,
            $driverType,
            false,
            $command,
            false,
            'CAPABILITY_UNSUPPORTED',
            $message,
            [],
            now()->toIso8601String(),
            ['status' => 'UNSUPPORTED', 'capability' => $command]
        );
    }

    public static function createAuthRequired(
        int $deviceId,
        string $driverType,
        string $command,
        string $message = 'Autentikasi perangkat diperlukan'
    ): self {
        return new self(
            $deviceId,
            $driverType,
            false,
            $command,
            false,
            'AUTH_REQUIRED',
            $message,
            [],
            now()->toIso8601String(),
            ['status' => 'AUTH_REQUIRED', 'http_code' => 401]
        );
    }

    public function toArray(): array
    {
        return [
            'device_id' => $this->deviceId,
            'driver_type' => $this->driverType,
            'simulation_mode' => $this->simulationMode,
            'command' => $this->command,
            'success' => $this->success,
            'result_code' => $this->resultCode,
            'message' => $this->message,
            'simulated_state' => $this->simulatedState,
            'telemetry' => $this->telemetry,
            'executed_at' => $this->executedAt,
            'raw_response' => $this->rawResponse,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
