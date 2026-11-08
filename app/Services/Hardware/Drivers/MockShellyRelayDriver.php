<?php

namespace App\Services\Hardware\Drivers;

use App\Exceptions\HardwareSimulationException;
use App\Models\PhysicalDevice;
use App\Services\Hardware\Contracts\HardwareDriverInterface;
use App\Services\Hardware\DTO\HardwareCommandResult;
use App\Services\Hardware\Simulation\HardwareSimulationManager;

class MockShellyRelayDriver implements HardwareDriverInterface
{
    public const DRIVER_TYPE = 'MOCK_SHELLY_RELAY';

    public const CMD_SWITCH_ON = 'SWITCH_ON';
    public const CMD_SWITCH_OFF = 'SWITCH_OFF';
    public const CMD_GET_STATUS = 'GET_STATUS';

    public const SUPPORTED_COMMANDS = [
        self::CMD_SWITCH_ON,
        self::CMD_SWITCH_OFF,
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
                'Virtual Probe: Shelly Smart Relay tidak dapat dihubungi (Host unreachable / offline).'
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
            'Virtual Probe: Shelly 1PM Smart Relay merespons virtual probe (Latency simulasi: 1ms).',
            $this->getCurrentSimulatedState($device),
            ['virtual_ping' => 'PONG', 'device' => 'Shelly1PMGen3', 'latency_ms' => 1]
        );
        $this->simulationManager->logEvent($device->id, $result);
        return $result;
    }

    /**
     * Virtual pairing simulation (Shelly does not require complex pairing in local RPC).
     */
    public function pair(PhysicalDevice $device, array $options = []): HardwareCommandResult
    {
        $this->validateDevice($device);
        $this->simulationManager->assertSimulationAllowed();

        $result = HardwareCommandResult::createSuccess(
            $device->id,
            self::DRIVER_TYPE,
            'PAIR',
            'Virtual Pairing: Shelly 1PM Gen3 Mini siap digunakan (No pairing handshake required).',
            $this->getCurrentSimulatedState($device),
            ['auth' => 'NOT_REQUIRED']
        );
        $this->simulationManager->logEvent($device->id, $result);
        return $result;
    }

    /**
     * Send operational simulated command to Shelly Relay.
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
                "Perintah '{$command}' tidak didukung oleh Shelly Smart Relay.",
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
                "Perintah '{$upperCommand}' gagal: Shelly Smart Relay offline / unreachable.",
                ['switch' => 'OFFLINE', 'output' => false, 'power_watts' => 0.0]
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
                "Perintah '{$upperCommand}' ditolak oleh Shelly Smart Relay (Simulated Relay Error / Overheat Protect).",
                $this->getCurrentSimulatedState($device)
            );
            $this->simulationManager->logEvent($device->id, $result);
            return $result;
        }

        // Apply command effect on simulated state
        $state = $this->getCurrentSimulatedState($device);
        $message = '';
        $rawRpc = [];

        switch ($upperCommand) {
            case self::CMD_SWITCH_ON:
                $state['switch'] = 'ON';
                $state['output'] = true;
                $state['power_watts'] = 45.2; // Typical billiard table lamp wattage
                $message = 'Relay lampu meja biliar berhasil dinyalakan (Simulasi Shelly 1PM - Output ON).';
                $rawRpc = ['id' => 1, 'src' => 'kasir_sim', 'result' => ['was_on' => false]];
                break;
            case self::CMD_SWITCH_OFF:
                $state['switch'] = 'OFF';
                $state['output'] = false;
                $state['power_watts'] = 0.0;
                $message = 'Relay lampu meja biliar berhasil dimatikan (Simulasi Shelly 1PM - Output OFF).';
                $rawRpc = ['id' => 1, 'src' => 'kasir_sim', 'result' => ['was_on' => true]];
                break;
            case self::CMD_GET_STATUS:
                $message = "Status virtual Shelly Smart Relay berhasil diambil (Output: {$state['switch']}, Daya: {$state['power_watts']}W).";
                $rawRpc = [
                    'id' => 1,
                    'result' => [
                        'output' => $state['output'],
                        'apower' => $state['power_watts'],
                        'voltage' => $state['voltage'],
                        'temperature' => ['tC' => $state['temperature']],
                    ]
                ];
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
            $rawRpc
        );

        $this->simulationManager->logEvent($device->id, $result);
        return $result;
    }

    /**
     * Retrieve status of Shelly Relay.
     */
    public function getStatus(PhysicalDevice $device): HardwareCommandResult
    {
        return $this->sendCommand($device, self::CMD_GET_STATUS);
    }

    /**
     * Validate device eligibility for Shelly driver.
     */
    protected function validateDevice(PhysicalDevice $device): void
    {
        if ($device->category !== PhysicalDevice::CATEGORY_SMART_RELAY || $device->brand !== PhysicalDevice::BRAND_SHELLY) {
            throw HardwareSimulationException::incompatibleDevice('SMART_RELAY (SHELLY)', "{$device->category} ({$device->brand})");
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
            'switch' => 'OFF',
            'output' => false,
            'power_watts' => 0.0,
            'voltage' => 228.4,
            'temperature' => 38.5,
            'model' => $device->model,
            'ip' => $device->ip_address,
            'simulated' => true,
        ];

        return $this->simulationManager->getSimulatedState($device->id, $default);
    }
}
