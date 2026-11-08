<?php

namespace App\Services\Hardware\Drivers;

use App\Exceptions\HardwareSimulationException;
use App\Models\PhysicalDevice;
use App\Services\Hardware\Contracts\HardwareDriverInterface;
use App\Services\Hardware\DTO\HardwareCommandResult;
use App\Services\Hardware\Simulation\HardwareSimulationManager;

class MockSamsungTvDriver implements HardwareDriverInterface
{
    public const DRIVER_TYPE = 'MOCK_SAMSUNG_TV';

    public const CMD_POWER_ON = 'POWER_ON';
    public const CMD_STANDBY = 'STANDBY';
    public const CMD_MUTE = 'MUTE';
    public const CMD_UNMUTE = 'UNMUTE';
    public const CMD_MUTE_TOGGLE = 'MUTE_TOGGLE';
    public const CMD_VOLUME_UP = 'VOLUME_UP';
    public const CMD_VOLUME_DOWN = 'VOLUME_DOWN';
    public const CMD_SHOW_ALERT = 'SHOW_ALERT';
    public const CMD_GET_STATUS = 'GET_STATUS';

    public const SUPPORTED_COMMANDS = [
        self::CMD_POWER_ON,
        self::CMD_STANDBY,
        self::CMD_MUTE,
        self::CMD_UNMUTE,
        self::CMD_MUTE_TOGGLE,
        self::CMD_VOLUME_UP,
        self::CMD_VOLUME_DOWN,
        self::CMD_SHOW_ALERT,
        self::CMD_GET_STATUS,
    ];

    protected HardwareSimulationManager $simulationManager;

    public function __construct(HardwareSimulationManager $simulationManager)
    {
        $this->simulationManager = $simulationManager;
    }

    public function getDriverType(): string
    {
        return self::DRIVER_TYPE;
    }

    public function getSupportedCommands(): array
    {
        return self::SUPPORTED_COMMANDS;
    }

    /**
     * Virtual ping/probe simulation.
     */
    public function probe(PhysicalDevice $device): HardwareCommandResult
    {
        $this->validateDevice($device);
        $this->simulationManager->assertSimulationAllowed();

        $scenario = $this->simulationManager->getDeviceScenario($device->id);

        if ($scenario === HardwareSimulationManager::SCENARIO_OFFLINE) {
            $result = HardwareCommandResult::createOffline(
                $device->id,
                self::DRIVER_TYPE,
                'PROBE',
                'Virtual Probe: Samsung Smart TV tidak dapat dihubungi (Host unreachable / offline).'
            );
            $this->simulationManager->logEvent($device->id, $result);
            return $result;
        }

        if ($scenario === HardwareSimulationManager::SCENARIO_TIMEOUT) {
            $result = HardwareCommandResult::createTimeout(
                $device->id,
                self::DRIVER_TYPE,
                'PROBE',
                config('hardware.timeout_ms', 3000)
            );
            $this->simulationManager->logEvent($device->id, $result);
            return $result;
        }

        $result = HardwareCommandResult::createSuccess(
            $device->id,
            self::DRIVER_TYPE,
            'PROBE',
            'Virtual Probe: Samsung Smart TV merespons virtual probe (Latency simulasi: 2ms).',
            $this->getCurrentSimulatedState($device),
            ['virtual_ping' => 'PONG', 'latency_ms' => 2]
        );
        $this->simulationManager->logEvent($device->id, $result);
        return $result;
    }

    /**
     * Virtual pairing simulation (Does NOT write to physical_devices table).
     */
    public function pair(PhysicalDevice $device, array $options = []): HardwareCommandResult
    {
        $this->validateDevice($device);
        $this->simulationManager->assertSimulationAllowed();

        $scenario = $this->simulationManager->getDeviceScenario($device->id);

        if ($scenario === HardwareSimulationManager::SCENARIO_OFFLINE) {
            $result = HardwareCommandResult::createOffline($device->id, self::DRIVER_TYPE, 'PAIR');
            $this->simulationManager->logEvent($device->id, $result);
            return $result;
        }

        if ($scenario === HardwareSimulationManager::SCENARIO_REJECTED) {
            $result = HardwareCommandResult::createRejected(
                $device->id,
                self::DRIVER_TYPE,
                'PAIR',
                'Virtual Pairing: Otorisasi ditolak oleh Samsung Smart TV (Simulated invalid token).'
            );
            $this->simulationManager->logEvent($device->id, $result);
            return $result;
        }

        $result = HardwareCommandResult::createSuccess(
            $device->id,
            self::DRIVER_TYPE,
            'PAIR',
            'Virtual Pairing: Berhasil melakukan handshake simulasi dengan Samsung Smart TV.',
            $this->getCurrentSimulatedState($device),
            ['simulated_token' => 'mock_token_samsung_' . md5($device->mac_address)]
        );
        $this->simulationManager->logEvent($device->id, $result);
        return $result;
    }

    /**
     * Send operational simulated command to Samsung TV.
     */
    public function sendCommand(PhysicalDevice $device, string $command, array $payload = []): HardwareCommandResult
    {
        $this->validateDevice($device);
        $this->simulationManager->assertSimulationAllowed();

        $upperCommand = strtoupper(trim($command));
        if (!in_array($upperCommand, self::SUPPORTED_COMMANDS, true)) {
            $result = HardwareCommandResult::createError(
                $device->id,
                self::DRIVER_TYPE,
                $command,
                "Perintah '{$command}' tidak didukung oleh Samsung Smart TV.",
                'INVALID_COMMAND'
            );
            $this->simulationManager->logEvent($device->id, $result);
            return $result;
        }

        $scenario = $this->simulationManager->getDeviceScenario($device->id);

        // Handle deterministic scenarios
        if ($scenario === HardwareSimulationManager::SCENARIO_OFFLINE) {
            $result = HardwareCommandResult::createOffline(
                $device->id,
                self::DRIVER_TYPE,
                $upperCommand,
                "Perintah '{$upperCommand}' gagal: Samsung Smart TV offline / host unreachable.",
                ['power' => 'OFFLINE', 'mute' => false]
            );
            $this->simulationManager->logEvent($device->id, $result);
            return $result;
        }

        if ($scenario === HardwareSimulationManager::SCENARIO_TIMEOUT) {
            $timeoutMs = config('hardware.timeout_ms', 3000);
            $result = HardwareCommandResult::createTimeout(
                $device->id,
                self::DRIVER_TYPE,
                $upperCommand,
                $timeoutMs,
                $this->getCurrentSimulatedState($device)
            );
            $this->simulationManager->logEvent($device->id, $result);
            return $result;
        }

        if ($scenario === HardwareSimulationManager::SCENARIO_REJECTED) {
            $result = HardwareCommandResult::createRejected(
                $device->id,
                self::DRIVER_TYPE,
                $upperCommand,
                "Perintah '{$upperCommand}' ditolak oleh Samsung Smart TV (Simulated 403 / Access Denied).",
                $this->getCurrentSimulatedState($device)
            );
            $this->simulationManager->logEvent($device->id, $result);
            return $result;
        }

        // Apply command effect on simulated state
        $state = $this->getCurrentSimulatedState($device);
        $message = '';

        switch ($upperCommand) {
            case self::CMD_POWER_ON:
                $state['power'] = 'ON';
                $message = 'Samsung Smart TV berhasil dinyalakan (Simulasi - Virtual Display Active).';
                break;
            case self::CMD_STANDBY:
                $state['power'] = 'STANDBY';
                $message = 'Samsung Smart TV beralih ke mode Standby (Simulasi).';
                break;
            case self::CMD_MUTE:
                $state['mute'] = true;
                $message = 'Audio Samsung Smart TV dimatikan / Mute (Simulasi).';
                break;
            case self::CMD_UNMUTE:
                $state['mute'] = false;
                $message = 'Audio Samsung Smart TV diaktifkan / Unmute (Simulasi).';
                break;
            case self::CMD_MUTE_TOGGLE:
                $state['mute'] = !((bool) ($state['mute'] ?? false));
                $statusLabel = $state['mute'] ? 'MUTE (Bisu)' : 'UNMUTE (Aktif)';
                $message = "Audio Samsung Smart TV ditoggle ke {$statusLabel} (Simulasi — bukan status nyata TV fisik).";
                break;
            case self::CMD_VOLUME_DOWN:
                $step = (int) ($payload['step'] ?? config('hardware.tv_control.volume_step', 5));
                $currentVol = (int) ($state['volume'] ?? config('hardware.tv_control.default_volume', 30));
                $state['volume'] = max(0, $currentVol - $step);
                $message = "Volume simulasi Samsung Smart TV diturunkan menjadi {$state['volume']}/100 (Simulasi).";
                break;
            case self::CMD_VOLUME_UP:
                $step = (int) ($payload['step'] ?? config('hardware.tv_control.volume_step', 5));
                $currentVol = (int) ($state['volume'] ?? config('hardware.tv_control.default_volume', 30));
                $state['volume'] = min(100, $currentVol + $step);
                $message = "Volume simulasi Samsung Smart TV dinaikkan menjadi {$state['volume']}/100 (Simulasi).";
                break;
            case self::CMD_SHOW_ALERT:
                $alertTitle = (string) ($payload['title'] ?? 'PERINGATAN WAKTU BERMAIN');
                $alertBody = (string) ($payload['message'] ?? '');
                $duration = (int) ($payload['duration_seconds'] ?? 8);
                $state['last_alert'] = [
                    'title' => $alertTitle,
                    'message' => $alertBody,
                    'duration_seconds' => $duration,
                    'sent_at' => now()->toIso8601String(),
                ];
                $message = "Alert simulasi '{$alertTitle}' berhasil diproses oleh Mock Driver (Menunggu ACK Simulator).";
                break;
            case self::CMD_GET_STATUS:
                $message = "Status virtual Samsung Smart TV berhasil diambil (Power: {$state['power']}).";
                break;
        }

        // Save updated state
        $this->simulationManager->updateSimulatedState($device->id, $state);

        $result = HardwareCommandResult::createSuccess(
            $device->id,
            self::DRIVER_TYPE,
            $upperCommand,
            $message,
            $state,
            ['simulated_response' => 'ACK_OK', 'command' => $upperCommand]
        );

        $this->simulationManager->logEvent($device->id, $result);
        return $result;
    }

    /**
     * Retrieve status of Samsung TV.
     */
    public function getStatus(PhysicalDevice $device): HardwareCommandResult
    {
        return $this->sendCommand($device, self::CMD_GET_STATUS);
    }

    /**
     * Validate device eligibility for Samsung TV driver.
     */
    protected function validateDevice(PhysicalDevice $device): void
    {
        if ($device->category !== PhysicalDevice::CATEGORY_SMART_TV || $device->brand !== PhysicalDevice::BRAND_SAMSUNG) {
            throw HardwareSimulationException::incompatibleDevice('SMART_TV (SAMSUNG)', "{$device->category} ({$device->brand})");
        }

        if (!$device->is_active) {
            throw HardwareSimulationException::deviceInactive($device->name);
        }
    }

    /**
     * Get current or default simulated state for device.
     */
    protected function getCurrentSimulatedState(PhysicalDevice $device): array
    {
        $default = [
            'power' => 'STANDBY',
            'mute' => false,
            'volume' => (int) config('hardware.tv_control.default_volume', 30),
            'model' => $device->model,
            'ip' => $device->ip_address,
            'simulated' => true,
        ];

        return $this->simulationManager->getSimulatedState($device->id, $default);
    }
}
