<?php

namespace App\Services\Hardware\Simulation;

use App\Exceptions\HardwareSimulationException;
use App\Models\PhysicalDevice;
use App\Services\Hardware\DTO\HardwareCommandResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class HardwareSimulationManager
{
    public const SCENARIO_SUCCESS = 'SUCCESS';
    public const SCENARIO_OFFLINE = 'OFFLINE';
    public const SCENARIO_TIMEOUT = 'TIMEOUT';
    public const SCENARIO_REJECTED = 'REJECTED';
    public const SCENARIO_RECOVERED = 'RECOVERED';

    public const ALLOWED_SCENARIOS = [
        self::SCENARIO_SUCCESS,
        self::SCENARIO_OFFLINE,
        self::SCENARIO_TIMEOUT,
        self::SCENARIO_REJECTED,
        self::SCENARIO_RECOVERED,
    ];

    protected const CACHE_PREFIX = 'hw_sim:';
    protected const TTL_SECONDS = 3600; // 1 hour TTL
    protected const MAX_EVENTS = 50;

    /**
     * Validate all simulation safety guards:
     * 1. Simulation enabled in config
     * 2. Driver set to 'mock'
     * 3. Environment in local/testing
     * 4. Active database connection is explicitly kasir_ps_test (NEVER production billing-sugeng-rawuh)
     *
     * @return void
     * @throws HardwareSimulationException
     */
    public function assertSimulationAllowed(): void
    {
        if (!config('hardware.simulation_enabled', false)) {
            throw HardwareSimulationException::simulationDisabled();
        }

        if (config('hardware.driver', 'none') !== 'mock') {
            throw HardwareSimulationException::simulationDisabled();
        }

        $env = app()->environment();
        $allowedEnvs = config('hardware.allowed_environments', ['local', 'testing']);
        if (!in_array($env, $allowedEnvs, true)) {
            throw HardwareSimulationException::environmentGuard($env);
        }

        $activeDb = DB::connection()->getDatabaseName();
        $allowedDbs = config('hardware.allowed_databases', ['kasir_ps_test']);
        if (!in_array($activeDb, $allowedDbs, true)) {
            throw HardwareSimulationException::productionDatabaseGuard($activeDb);
        }
    }

    /**
     * Get the active simulation scenario for a specific device.
     * Defaults to SCENARIO_SUCCESS if not set.
     *
     * @param int $deviceId
     * @return string
     */
    public function getDeviceScenario(int $deviceId): string
    {
        return Cache::get($this->scenarioKey($deviceId), self::SCENARIO_SUCCESS);
    }

    /**
     * Set deterministic simulation scenario for a device.
     *
     * @param int $deviceId
     * @param string $scenario
     * @return void
     * @throws HardwareSimulationException
     */
    public function setDeviceScenario(int $deviceId, string $scenario): void
    {
        $this->assertSimulationAllowed();

        $upperScenario = strtoupper(trim($scenario));
        if (!in_array($upperScenario, self::ALLOWED_SCENARIOS, true)) {
            throw HardwareSimulationException::invalidScenario($scenario);
        }

        Cache::put($this->scenarioKey($deviceId), $upperScenario, self::TTL_SECONDS);
    }

    /**
     * Get current simulated hardware state for a device.
     *
     * @param int $deviceId
     * @param array $defaultState
     * @return array
     */
    public function getSimulatedState(int $deviceId, array $defaultState = []): array
    {
        return Cache::get($this->stateKey($deviceId), $defaultState);
    }

    /**
     * Update current simulated hardware state for a device.
     *
     * @param int $deviceId
     * @param array $state
     * @return void
     */
    public function updateSimulatedState(int $deviceId, array $state): void
    {
        $this->assertSimulationAllowed();

        $current = $this->getSimulatedState($deviceId);
        $merged = array_merge($current, $state);
        Cache::put($this->stateKey($deviceId), $merged, self::TTL_SECONDS);
    }

    /**
     * Record a simulation event log entry in isolated buffer (FIFO, max 50).
     *
     * @param int $deviceId
     * @param HardwareCommandResult $result
     * @return void
     */
    public function logEvent(int $deviceId, HardwareCommandResult $result): void
    {
        $events = $this->getEvents($deviceId, self::MAX_EVENTS);

        // Prepend newest event
        array_unshift($events, [
            'id' => uniqid('evt_', true),
            'device_id' => $deviceId,
            'driver_type' => $result->driverType,
            'command' => $result->command,
            'success' => $result->success,
            'result_code' => $result->resultCode,
            'message' => $result->message,
            'simulated_state' => $result->simulatedState,
            'executed_at' => $result->executedAt,
        ]);

        if (count($events) > self::MAX_EVENTS) {
            $events = array_slice($events, 0, self::MAX_EVENTS);
        }

        Cache::put($this->eventsKey($deviceId), $events, self::TTL_SECONDS);
    }

    /**
     * Retrieve simulation event logs for a device.
     *
     * @param int $deviceId
     * @param int $limit
     * @return array
     */
    public function getEvents(int $deviceId, int $limit = 20): array
    {
        $events = Cache::get($this->eventsKey($deviceId), []);
        return array_slice($events, 0, $limit);
    }

    /**
     * Reset simulation state, scenario, and events for a single device.
     *
     * @param int $deviceId
     * @return void
     */
    public function resetDevice(int $deviceId): void
    {
        Cache::forget($this->stateKey($deviceId));
        Cache::forget($this->scenarioKey($deviceId));
        Cache::forget($this->eventsKey($deviceId));
    }

    /**
     * Reset simulation state across all devices.
     *
     * @return void
     */
    public function resetAll(): void
    {
        // Fetch all physical device IDs
        $deviceIds = PhysicalDevice::pluck('id');
        foreach ($deviceIds as $id) {
            $this->resetDevice($id);
        }
    }

    protected function scenarioKey(int $deviceId): string
    {
        return self::CACHE_PREFIX . 'scenario:' . $deviceId;
    }

    protected function stateKey(int $deviceId): string
    {
        return self::CACHE_PREFIX . 'state:' . $deviceId;
    }

    protected function eventsKey(int $deviceId): string
    {
        return self::CACHE_PREFIX . 'events:' . $deviceId;
    }
}
