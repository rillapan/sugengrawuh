<?php

namespace App\Services\Hardware;

use App\Exceptions\HardwareSafetyException;
use App\Exceptions\HardwareSimulationException;
use App\Models\AutomationIntent;
use App\Models\Device;
use App\Models\HardwarePowerCommandLog;
use App\Models\PhysicalDevice;
use App\Models\StationDeviceMapping;
use App\Models\User;
use App\Services\Hardware\Drivers\SamsungTvDriver;
use App\Services\Hardware\Drivers\ShellyRelayDriver;
use App\Services\Hardware\Security\HardwareSafetyGuard;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class StationPowerControlService
{
    protected HardwareDriverManager $driverManager;

    public function __construct(HardwareDriverManager $driverManager)
    {
        $this->driverManager = $driverManager;
    }

    /**
     * Execute manual power control command on a mapped physical device for a station.
     *
     * @param Device $station
     * @param PhysicalDevice $physicalDevice
     * @param string $desiredState 'ON' or 'OFF'
     * @param User $user
     * @param string|null $idempotencyKey
     * @return array
     */
    public function controlPower(
        Device $station,
        PhysicalDevice $physicalDevice,
        string $desiredState,
        User $user,
        ?string $idempotencyKey = null
    ): array {
        $desiredState = strtoupper(trim($desiredState));
        if (!in_array($desiredState, ['ON', 'OFF'], true)) {
            return [
                'success' => false,
                'desired_state' => $desiredState,
                'reported_state' => 'UNKNOWN',
                'execution_state' => 'REJECTED',
                'result_code' => 'INVALID_STATE',
                'message' => "Desired state harus berupa 'ON' atau 'OFF'.",
            ];
        }

        // 1. Validate active mapping between station and physical device
        $mapping = StationDeviceMapping::where('station_id', $station->id)
            ->where('physical_device_id', $physicalDevice->id)
            ->where('mapping_status', StationDeviceMapping::STATUS_ACTIVE)
            ->first();

        if (!$mapping) {
            return [
                'success' => false,
                'desired_state' => $desiredState,
                'reported_state' => 'UNKNOWN',
                'execution_state' => 'REJECTED',
                'result_code' => 'INVALID_MAPPING',
                'message' => "Perangkat fisik '{$physicalDevice->name}' tidak memiliki mapping aktif dengan stasiun '{$station->nama}'.",
            ];
        }

        // 2. Validate device is active
        if (!$physicalDevice->is_active) {
            return [
                'success' => false,
                'desired_state' => $desiredState,
                'reported_state' => 'UNKNOWN',
                'execution_state' => 'REJECTED',
                'result_code' => 'DEVICE_INACTIVE',
                'message' => "Perangkat fisik '{$physicalDevice->name}' sedang nonaktif.",
            ];
        }

        // 3. User authorization check (Admin or Owner)
        if (!$user->isAdmin() && !$user->isOwner()) {
            return [
                'success' => false,
                'desired_state' => $desiredState,
                'reported_state' => 'UNKNOWN',
                'execution_state' => 'REJECTED',
                'result_code' => 'UNAUTHORIZED_ROLE',
                'message' => "Pengguna tidak memiliki izin untuk mengontrol power hardware (Khusus Admin & Owner).",
            ];
        }

        // 4. Idempotency check: Avoid executing duplicate command within 10 seconds
        if ($idempotencyKey) {
            $existingLog = HardwarePowerCommandLog::where('idempotency_key', $idempotencyKey)
                ->where('created_at', '>=', Carbon::now()->subSeconds(10))
                ->latest()
                ->first();

            if ($existingLog) {
                return [
                    'success' => $existingLog->execution_status === 'SUCCESS',
                    'desired_state' => $existingLog->desired_state,
                    'reported_state' => $existingLog->reported_state,
                    'execution_state' => $existingLog->execution_status,
                    'result_code' => $existingLog->result_code,
                    'message' => $existingLog->message . ' (Idempotent response)',
                    'telemetry' => $existingLog->telemetry ?? [],
                    'latency_ms' => $existingLog->latency_ms ?? 0,
                ];
            }
        }

        // 5. Hardware Lock: Prevent concurrent driver execution on the same physical device
        $lockKey = "hardware_power_lock_{$physicalDevice->id}";
        $lock = Cache::lock($lockKey, 10);

        if (!$lock->get()) {
            return [
                'success' => false,
                'desired_state' => $desiredState,
                'reported_state' => 'PROCESSING',
                'execution_state' => 'PROCESSING',
                'result_code' => 'CONCURRENT_LOCK',
                'message' => "Perintah power sedang diproses untuk perangkat ini. Harap tunggu sesaat.",
            ];
        }

        $startTime = microtime(true);
        $command = '';
        $reportedState = 'UNKNOWN';
        $executionState = 'FAILED';

        try {
            // 6. Map desired state to driver command
            if ($physicalDevice->category === PhysicalDevice::CATEGORY_SMART_RELAY) {
                $command = ($desiredState === 'ON') ? ShellyRelayDriver::CMD_SWITCH_ON : ShellyRelayDriver::CMD_SWITCH_OFF;
            } elseif ($physicalDevice->category === PhysicalDevice::CATEGORY_SMART_TV) {
                if ($desiredState === 'ON') {
                    $verified = $physicalDevice->verified_capabilities ?? [];
                    if (!in_array(SamsungTvDriver::CMD_POWER_ON, array_map('strtoupper', (array) $verified), true)) {
                        $lock->release();
                        return [
                            'success' => false,
                            'desired_state' => 'ON',
                            'reported_state' => 'UNSUPPORTED',
                            'execution_state' => 'UNSUPPORTED',
                            'result_code' => 'UNSUPPORTED_CAPABILITY',
                            'message' => "Perintah 'POWER_ON' belum diverifikasi untuk model TV ini (Samsung Smart TV tidak mendukung aktivasi dari mode standby melalui WebSocket tanpa WOL fisik).",
                            'latency_ms' => (int) round((microtime(true) - $startTime) * 1000),
                        ];
                    }
                    $command = SamsungTvDriver::CMD_POWER_ON;
                } else {
                    $command = SamsungTvDriver::CMD_STANDBY;
                }
            } else {
                $lock->release();
                return [
                    'success' => false,
                    'desired_state' => $desiredState,
                    'reported_state' => 'UNSUPPORTED',
                    'execution_state' => 'REJECTED',
                    'result_code' => 'UNSUPPORTED_CATEGORY',
                    'message' => "Kategori hardware '{$physicalDevice->category}' tidak mendukung kontrol power.",
                ];
            }

            // 7. Safety Guard Assert for real mode
            $activeDriverMode = config('hardware.driver', 'none');
            if ($activeDriverMode === 'real') {
                HardwareSafetyGuard::assertRealExecutionAllowed($physicalDevice, $command, $user, false);
            }

            // 8. Resolve driver and execute command
            $driver = $this->driverManager->getDriverForDevice($physicalDevice);
            $result = $driver->sendCommand($physicalDevice, $command);
            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);

            // 9. Evaluate execution and reported state
            if ($result->success) {
                $executionState = 'SUCCESS';

                if ($physicalDevice->category === PhysicalDevice::CATEGORY_SMART_RELAY) {
                    $telemetry = $result->simulatedState ?? $result->telemetry ?? [];
                    if (isset($telemetry['output'])) {
                        $reportedState = $telemetry['output'] ? 'ON' : 'OFF';
                    } else {
                        $reportedState = $desiredState;
                    }
                } elseif ($physicalDevice->category === PhysicalDevice::CATEGORY_SMART_TV) {
                    $reportedState = ($command === SamsungTvDriver::CMD_STANDBY) ? 'OFF' : 'ON';
                } else {
                    $reportedState = $desiredState;
                }

                // Update Station state without modifying session_generation
                $station->update([
                    'last_hardware_state' => ($reportedState === 'ON') ? 'STATE_ON' : 'STATE_OFF',
                    'last_hardware_verified_at' => Carbon::now(),
                    'last_manual_power_at' => Carbon::now(),
                ]);

                // Update PhysicalDevice state
                $physicalDevice->update([
                    'connection_status' => PhysicalDevice::CONNECTION_ONLINE,
                    'last_seen_at' => Carbon::now(),
                ]);

                // Invalidate / cancel obsolete pending automated intents of the opposite state
                AutomationIntent::where('station_id', $station->id)
                    ->where('physical_device_id', $physicalDevice->id)
                    ->whereIn('status', [AutomationIntent::STATUS_PENDING, AutomationIntent::STATUS_RETRY_PENDING])
                    ->where('desired_state', '!=', $desiredState)
                    ->update([
                        'status' => AutomationIntent::STATUS_CANCELLED,
                        'last_error_message' => "Dibatalkan oleh manual power override ({$desiredState}) pada " . Carbon::now()->toDateTimeString(),
                        'updated_at' => Carbon::now(),
                    ]);
            } else {
                $latencyMs = (int) round((microtime(true) - $startTime) * 1000);

                if ($result->resultCode === 'UNSUPPORTED_CAPABILITY') {
                    $reportedState = 'UNSUPPORTED';
                    $executionState = 'UNSUPPORTED';
                } elseif ($result->resultCode === 'OFFLINE') {
                    $reportedState = 'OFFLINE';
                    $executionState = 'FAILED';
                    $physicalDevice->update(['connection_status' => PhysicalDevice::CONNECTION_OFFLINE]);
                } elseif ($result->resultCode === 'TIMEOUT') {
                    $reportedState = 'UNKNOWN';
                    $executionState = 'TIMEOUT';
                } else {
                    $reportedState = 'UNKNOWN';
                    $executionState = 'FAILED';
                }
            }

            // 10. Audit Log in dedicated table
            HardwarePowerCommandLog::create([
                'station_id' => $station->id,
                'physical_device_id' => $physicalDevice->id,
                'user_id' => $user->id,
                'desired_state' => $desiredState,
                'reported_state' => $reportedState,
                'execution_status' => $executionState,
                'command_sent' => $command,
                'driver_type' => $driver->getDriverType(),
                'idempotency_key' => $idempotencyKey,
                'result_code' => $result->resultCode,
                'message' => $result->message,
                'telemetry' => $result->telemetry ?? [],
                'latency_ms' => $latencyMs,
            ]);

            Log::info("[HARDWARE_POWER_CONTROL] Command executed", [
                'station_id' => $station->id,
                'physical_device_id' => $physicalDevice->id,
                'user_id' => $user->id,
                'desired_state' => $desiredState,
                'reported_state' => $reportedState,
                'execution_state' => $executionState,
                'result_code' => $result->resultCode,
                'latency_ms' => $latencyMs,
            ]);

            return [
                'success' => $result->success,
                'desired_state' => $desiredState,
                'reported_state' => $reportedState,
                'execution_state' => $executionState,
                'result_code' => $result->resultCode,
                'message' => $result->message,
                'telemetry' => $result->telemetry ?? [],
                'latency_ms' => $latencyMs,
            ];
        } catch (HardwareSafetyException $e) {
            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
            Log::warning("[HARDWARE_POWER_CONTROL] Safety Guard blocked execution: " . $e->getMessage());

            HardwarePowerCommandLog::create([
                'station_id' => $station->id,
                'physical_device_id' => $physicalDevice->id,
                'user_id' => $user->id,
                'desired_state' => $desiredState,
                'reported_state' => 'UNKNOWN',
                'execution_status' => 'REJECTED',
                'command_sent' => $command ?: 'BLOCKED',
                'driver_type' => 'NONE',
                'idempotency_key' => $idempotencyKey,
                'result_code' => 'SAFETY_GUARD_BLOCKED',
                'message' => $e->getMessage(),
                'latency_ms' => $latencyMs,
            ]);

            return [
                'success' => false,
                'desired_state' => $desiredState,
                'reported_state' => 'UNKNOWN',
                'execution_state' => 'REJECTED',
                'result_code' => 'SAFETY_GUARD_BLOCKED',
                'message' => $e->getMessage(),
            ];
        } catch (HardwareSimulationException $e) {
            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
            return [
                'success' => false,
                'desired_state' => $desiredState,
                'reported_state' => 'UNKNOWN',
                'execution_state' => 'REJECTED',
                'result_code' => 'SIMULATION_ERROR',
                'message' => $e->getMessage(),
            ];
        } catch (Throwable $e) {
            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
            Log::error("[HARDWARE_POWER_CONTROL] Unexpected execution error: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return [
                'success' => false,
                'desired_state' => $desiredState,
                'reported_state' => 'UNKNOWN',
                'execution_state' => 'FAILED',
                'result_code' => 'UNEXPECTED_ERROR',
                'message' => 'Terjadi kesalahan sistem saat menghubungi perangkat: ' . $e->getMessage(),
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * Retrieve reported power status of a mapped physical device on a station.
     *
     * @param Device $station
     * @param PhysicalDevice $physicalDevice
     * @return array
     */
    public function getPowerStatus(Device $station, PhysicalDevice $physicalDevice): array
    {
        // 1. Verify active mapping
        $mapping = StationDeviceMapping::where('station_id', $station->id)
            ->where('physical_device_id', $physicalDevice->id)
            ->where('mapping_status', StationDeviceMapping::STATUS_ACTIVE)
            ->first();

        if (!$mapping) {
            return [
                'status' => 'error',
                'reported_state' => 'UNMAPPED',
                'message' => 'Perangkat tidak terhubung ke stasiun ini.',
            ];
        }

        if ($physicalDevice->connection_status === PhysicalDevice::CONNECTION_OFFLINE) {
            return [
                'status' => 'success',
                'reported_state' => 'OFFLINE',
                'connection_status' => 'OFFLINE',
                'device_name' => $physicalDevice->name,
                'brand' => $physicalDevice->brand,
                'category' => $physicalDevice->category,
            ];
        }

        // Check last command log or driver state
        $lastLog = HardwarePowerCommandLog::where('station_id', $station->id)
            ->where('physical_device_id', $physicalDevice->id)
            ->latest()
            ->first();

        $reportedState = $lastLog ? $lastLog->reported_state : 'UNKNOWN';

        if ($reportedState === 'UNKNOWN') {
            if (str_contains((string) $station->last_hardware_state, 'STATE_ON') || str_contains((string) $station->last_hardware_state, 'SIMULATED_ON')) {
                $reportedState = 'ON';
            } elseif (str_contains((string) $station->last_hardware_state, 'STATE_OFF') || str_contains((string) $station->last_hardware_state, 'SIMULATED_OFF')) {
                $reportedState = 'OFF';
            }
        }

        return [
            'status' => 'success',
            'reported_state' => $reportedState,
            'connection_status' => $physicalDevice->connection_status,
            'device_name' => $physicalDevice->name,
            'brand' => $physicalDevice->brand,
            'category' => $physicalDevice->category,
            'last_verified_at' => $station->last_hardware_verified_at ? $station->last_hardware_verified_at->toDateTimeString() : null,
            'last_manual_power_at' => $station->last_manual_power_at ? $station->last_manual_power_at->toDateTimeString() : null,
            'telemetry' => $lastLog?->telemetry ?? [],
        ];
    }
}
