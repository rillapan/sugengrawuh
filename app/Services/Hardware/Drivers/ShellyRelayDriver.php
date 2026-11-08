<?php

namespace App\Services\Hardware\Drivers;

use App\Exceptions\HardwareSafetyException;
use App\Exceptions\HardwareSimulationException;
use App\Models\Device;
use App\Models\PhysicalDevice;
use App\Models\StationDeviceMapping;
use App\Services\Hardware\Contracts\HardwareDriverInterface;
use App\Services\Hardware\DeviceStatusManagementService;
use App\Services\Hardware\DTO\HardwareCommandResult;
use App\Services\Hardware\Transports\ShellyTransportInterface;

class ShellyRelayDriver implements HardwareDriverInterface
{
    public const DRIVER_TYPE = 'REAL_SHELLY_RELAY';

    public const CMD_SWITCH_ON = 'SWITCH_ON';
    public const CMD_SWITCH_OFF = 'SWITCH_OFF';
    public const CMD_GET_STATUS = 'GET_STATUS';

    public const SUPPORTED_COMMANDS = [
        self::CMD_SWITCH_ON,
        self::CMD_SWITCH_OFF,
        self::CMD_GET_STATUS,
    ];

    protected ShellyTransportInterface $transport;
    protected ?DeviceStatusManagementService $statusService;
    protected bool $isRealVerifiedMode = false;

    public function __construct(
        ShellyTransportInterface $transport,
        ?DeviceStatusManagementService $statusService = null,
        bool $isRealVerifiedMode = false
    ) {
        $this->transport = $transport;
        $this->statusService = $statusService ?? new DeviceStatusManagementService();
        $this->isRealVerifiedMode = $isRealVerifiedMode;
    }

    public function getDriverType(): string
    {
        return self::DRIVER_TYPE;
    }

    public function getSupportedCommands(): array
    {
        return self::SUPPORTED_COMMANDS;
    }

    public function getTransport(): ShellyTransportInterface
    {
        return $this->transport;
    }

    /**
     * Probe Shelly relay reachability and device identity via Shelly.GetDeviceInfo.
     */
    public function probe(PhysicalDevice $device): HardwareCommandResult
    {
        $this->validateDevice($device);

        $port = config('hardware.default_ports.SHELLY_HTTP', 80);
        $timeoutMs = config('hardware.connect_timeout_ms', 3000);
        $authPassword = $device->pairing_payload; // Stored encrypted password if configured

        $response = $this->transport->getDeviceInfo($device->ip_address, $port, $authPassword, $timeoutMs);

        if ($response['status'] === 'OFFLINE') {
            return HardwareCommandResult::createOffline(
                $device->id,
                self::DRIVER_TYPE,
                'PROBE',
                "Shelly Smart Relay unreachable on {$device->ip_address}:{$port} (Offline)"
            );
        }

        if ($response['status'] === 'TIMEOUT') {
            return HardwareCommandResult::createTimeout(
                $device->id,
                self::DRIVER_TYPE,
                'PROBE',
                $timeoutMs
            );
        }

        if ($response['status'] === 'AUTH_REQUIRED') {
            return HardwareCommandResult::createAuthRequired(
                $device->id,
                self::DRIVER_TYPE,
                'PROBE',
                "Autentikasi sandi perangkat Shelly diperlukan"
            );
        }

        if (!$response['success']) {
            return HardwareCommandResult::createError(
                $device->id,
                self::DRIVER_TYPE,
                'PROBE',
                $response['message'] ?? 'Probe gagal',
                $response['status'] ?? 'PROBE_FAILED',
                [],
                false
            );
        }

        $info = $response['device_info'] ?? [];
        $this->statusService->updateConnectionStatus($device, PhysicalDevice::CONNECTION_ONLINE, $this->isRealVerifiedMode);

        return HardwareCommandResult::createRealResult(
            $device->id,
            self::DRIVER_TYPE,
            'PROBE',
            true,
            'SUCCESS',
            "Shelly Smart Relay teridentifikasi: {$info['app']} (Gen {$info['gen']})",
            ['model' => $info['model'] ?? 'Shelly 1PM Mini Gen3', 'mac' => $info['mac'] ?? $device->mac_address],
            $info
        );
    }

    /**
     * Pair Shelly relay. Local Shelly RPC uses password auth if enabled.
     */
    public function pair(PhysicalDevice $device, array $options = []): HardwareCommandResult
    {
        $this->validateDevice($device);

        $password = $options['password'] ?? null;
        $port = (int) ($options['port'] ?? config('hardware.default_ports.SHELLY_HTTP', 80));
        $timeoutMs = (int) ($options['timeout_ms'] ?? config('hardware.timeout_ms', 3000));

        // Test RPC call with password
        $response = $this->transport->getDeviceInfo($device->ip_address, $port, $password, $timeoutMs);

        if ($response['status'] === 'AUTH_REQUIRED' || $response['status'] === 'AUTH_FAILED') {
            $this->statusService->recordPairingFailure($device, $this->isRealVerifiedMode);
            return HardwareCommandResult::createRejected(
                $device->id,
                self::DRIVER_TYPE,
                'PAIR',
                "Autentikasi Shelly gagal: Sandi tidak cocok atau otorisasi ditolak"
            );
        }

        if (!$response['success']) {
            return HardwareCommandResult::createError(
                $device->id,
                self::DRIVER_TYPE,
                'PAIR',
                $response['message'] ?? 'Pairing gagal',
                $response['status'] ?? 'PAIRING_FAILED',
                [],
                false
            );
        }

        if (!empty($password)) {
            $this->statusService->recordSuccessfulPairing($device, $password, $this->isRealVerifiedMode);
        }

        return HardwareCommandResult::createRealResult(
            $device->id,
            self::DRIVER_TYPE,
            'PAIR',
            true,
            'PAIRED',
            'Konfigurasi otorisasi Shelly Smart Relay berhasil diverifikasi',
            ['pairing_status' => 'PAIRED'],
            $response['device_info'] ?? []
        );
    }

    /**
     * Send operational relay command (SWITCH_ON, SWITCH_OFF, GET_STATUS).
     */
    public function sendCommand(PhysicalDevice $device, string $command, array $payload = []): HardwareCommandResult
    {
        $this->validateDevice($device);

        $upperCommand = strtoupper(trim($command));
        if (!in_array($upperCommand, self::SUPPORTED_COMMANDS, true)) {
            return HardwareCommandResult::createError(
                $device->id,
                self::DRIVER_TYPE,
                $command,
                "Perintah '{$command}' tidak didukung oleh Shelly Smart Relay.",
                'INVALID_COMMAND',
                [],
                false
            );
        }

        if ($upperCommand === self::CMD_GET_STATUS) {
            return $this->getStatus($device);
        }

        $port = config('hardware.default_ports.SHELLY_HTTP', 80);
        $timeoutMs = config('hardware.timeout_ms', 3000);
        $authPassword = $device->pairing_payload;

        $targetOn = ($upperCommand === self::CMD_SWITCH_ON);
        $rpcParams = [
            'id' => 0, // Shelly 1PM Mini single relay output is channel 0
            'on' => $targetOn,
        ];

        // 1. Send Switch.Set
        $response = $this->transport->sendRpc($device->ip_address, $port, 'Switch.Set', $rpcParams, $authPassword, $timeoutMs);

        if ($response['status'] === 'OFFLINE') {
            return HardwareCommandResult::createOffline(
                $device->id,
                self::DRIVER_TYPE,
                $upperCommand,
                "Shelly Smart Relay offline pada {$device->ip_address}:{$port}"
            );
        }

        if ($response['status'] === 'TIMEOUT') {
            return HardwareCommandResult::createTimeout(
                $device->id,
                self::DRIVER_TYPE,
                $upperCommand,
                $timeoutMs
            );
        }

        if ($response['status'] === 'AUTH_REQUIRED') {
            return HardwareCommandResult::createAuthRequired(
                $device->id,
                self::DRIVER_TYPE,
                $upperCommand,
                "Autentikasi diperlukan untuk mengubah status relay"
            );
        }

        if (!$response['success']) {
            return HardwareCommandResult::createError(
                $device->id,
                self::DRIVER_TYPE,
                $upperCommand,
                $response['message'] ?? 'Eksekusi Switch.Set gagal',
                $response['status'] ?? 'RPC_ERROR',
                [],
                false
            );
        }

        // 2. Read-back verification via Switch.GetStatus
        $readBack = $this->transport->sendRpc($device->ip_address, $port, 'Switch.GetStatus', ['id' => 0], $authPassword, $timeoutMs);
        $verifiedState = false;
        $telemetry = [];

        if ($readBack['success']) {
            $statusResult = $readBack['result'] ?? [];
            $currentOutput = (bool) ($statusResult['output'] ?? false);
            $verifiedState = ($currentOutput === $targetOn);
            $telemetry = [
                'output' => $currentOutput,
                'apower' => $statusResult['apower'] ?? 0.0,
                'voltage' => $statusResult['voltage'] ?? 0.0,
                'current' => $statusResult['current'] ?? 0.0,
                'temperature' => $statusResult['temperature']['tC'] ?? null,
            ];

            if (!$verifiedState) {
                return HardwareCommandResult::createRealResult(
                    $device->id,
                    self::DRIVER_TYPE,
                    $upperCommand,
                    false,
                    'STATE_MISMATCH',
                    "Perintah Switch.Set diterima, namun verifikasi read-back output tidak sesuai (Output tetap: " . ($currentOutput ? 'ON' : 'OFF') . ")",
                    $telemetry,
                    $readBack['result'] ?? []
                );
            }
        }

        $actionDesc = $targetOn ? 'dinyalakan (Output ON)' : 'dimatikan (Output OFF)';
        $message = "Relay lampu meja biliar berhasil {$actionDesc} dan diverifikasi melalui read-back Switch.GetStatus.";

        return HardwareCommandResult::createRealResult(
            $device->id,
            self::DRIVER_TYPE,
            $upperCommand,
            true,
            'SUCCESS',
            $message,
            $telemetry,
            $response['result'] ?? []
        );
    }

    /**
     * Retrieve status and telemetry of Shelly relay via Switch.GetStatus.
     */
    public function getStatus(PhysicalDevice $device): HardwareCommandResult
    {
        $this->validateDevice($device);

        $port = config('hardware.default_ports.SHELLY_HTTP', 80);
        $timeoutMs = config('hardware.timeout_ms', 3000);
        $authPassword = $device->pairing_payload;

        $response = $this->transport->sendRpc($device->ip_address, $port, 'Switch.GetStatus', ['id' => 0], $authPassword, $timeoutMs);

        if (!$response['success']) {
            if ($response['status'] === 'OFFLINE') {
                return HardwareCommandResult::createOffline(
                    $device->id,
                    self::DRIVER_TYPE,
                    self::CMD_GET_STATUS,
                    "Shelly relay offline pada {$device->ip_address}:{$port}"
                );
            }

            return HardwareCommandResult::createError(
                $device->id,
                self::DRIVER_TYPE,
                self::CMD_GET_STATUS,
                $response['message'] ?? 'Gagal mengambil status Switch',
                $response['status'] ?? 'STATUS_FAILED',
                [],
                false
            );
        }

        $res = $response['result'] ?? [];
        $output = (bool) ($res['output'] ?? false);
        $apower = (float) ($res['apower'] ?? 0.0);
        $outputLabel = $output ? 'ON' : 'OFF';

        return HardwareCommandResult::createRealResult(
            $device->id,
            self::DRIVER_TYPE,
            self::CMD_GET_STATUS,
            true,
            'SUCCESS',
            "Status Shelly Smart Relay: Output {$outputLabel}, Daya {$apower}W",
            [
                'output' => $output,
                'apower' => $apower,
                'voltage' => $res['voltage'] ?? 0.0,
                'current' => $res['current'] ?? 0.0,
                'temperature' => $res['temperature']['tC'] ?? null,
            ],
            $res
        );
    }

    /**
     * Validate device eligibility and ensure strict safety rules.
     * Prevents Shelly from ever being used to cut power to a Samsung TV!
     */
    protected function validateDevice(PhysicalDevice $device): void
    {
        if ($device->category !== PhysicalDevice::CATEGORY_SMART_RELAY || $device->brand !== PhysicalDevice::BRAND_SHELLY) {
            throw HardwareSimulationException::incompatibleDevice(
                'SMART_RELAY (SHELLY)',
                "{$device->category} ({$device->brand})"
            );
        }

        if (!$device->is_active) {
            throw HardwareSimulationException::deviceInactive($device->name);
        }

        // Section 5.C & 10: HARDWARE SAFETY ENFORCEMENT
        // Check active station mapping: Shelly must only be mapped to LIGHT_RELAY on billiard stations!
        // DILARANG menggunakan Shelly untuk memutus aliran listrik utama Samsung TV!
        $activeMapping = $device->activeMapping;
        if ($activeMapping) {
            if ($activeMapping->mapping_role !== StationDeviceMapping::ROLE_LIGHT_RELAY) {
                throw HardwareSafetyException::dangerousRelayAction(
                    "PELANGGARAN KESELAMATAN HARDWARE: Shelly Smart Relay dipetakan dengan peran '{$activeMapping->mapping_role}'. Shelly hanya diizinkan untuk 'LIGHT_RELAY'."
                );
            }

            if ($activeMapping->station) {
                $stationCategory = $activeMapping->station->getStationCategory();
                if ($stationCategory !== \App\Models\Playstation::CATEGORY_BILLIARD) {
                    throw HardwareSafetyException::dangerousRelayAction(
                        "PELANGGARAN KESELAMATAN HARDWARE: Shelly Smart Relay dipetakan ke station '{$activeMapping->station->nama}' berkategori '{$stationCategory}'. DILARANG menggunakan relay untuk memutus daya TV/PlayStation! Shelly hanya boleh digunakan untuk lampu meja biliar."
                    );
                }
            }
        }
    }
}
