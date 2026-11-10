<?php

namespace App\Services\Hardware\Automation;

use App\Models\AutomationExecutionLog;
use App\Models\AutomationIntent;
use App\Models\Device;
use App\Models\PhysicalDevice;
use App\Services\Hardware\Contracts\HardwareDriverInterface;
use App\Services\Hardware\HardwareDriverManager;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class AutomationDispatcherService
{
    protected AutomationFencingGuard $fencingGuard;
    protected AutomationCapabilityPolicy $capabilityPolicy;
    protected HardwareDriverManager $driverManager;

    public function __construct(
        AutomationFencingGuard $fencingGuard,
        AutomationCapabilityPolicy $capabilityPolicy,
        HardwareDriverManager $driverManager
    ) {
        $this->fencingGuard = $fencingGuard;
        $this->capabilityPolicy = $capabilityPolicy;
        $this->driverManager = $driverManager;
    }

    /**
     * Dispatch the next batch of eligible intents using rapid claim transaction (< 5ms).
     *
     * @param int $limit
     * @param Carbon|null $asOf
     * @param string|null $workerId
     * @return int Count of dispatched intents
     */
    public function dispatchBatch(int $limit = 10, ?Carbon $asOf = null, ?string $workerId = null): int
    {
        $now = $asOf ?? Carbon::now();
        $claimedIntents = $this->claimEligibleIntents($limit, $now, $workerId);

        if ($claimedIntents->isEmpty()) {
            return 0;
        }

        // Phase 2 & 3: Process outside DB transaction
        $processedCount = 0;
        foreach ($claimedIntents as $intent) {
            $this->processIntent($intent, $now);
            $processedCount++;
        }

        return $processedCount;
    }

    /**
     * Phase 1: Rapid DB Claim (< 5ms)
     *
     * @param int $limit
     * @param Carbon|null $asOf
     * @param string|null $workerId
     * @return \Illuminate\Support\Collection
     */
    public function claimEligibleIntents(int $limit = 10, ?Carbon $asOf = null, ?string $workerId = null): \Illuminate\Support\Collection
    {
        $now = $asOf ?? Carbon::now();
        $worker = $workerId ?? (getmypid() . '@' . gethostname());

        return DB::transaction(function () use ($limit, $now, $worker) {
            $intents = AutomationIntent::eligible($now)
                ->orderBy('scheduled_at', 'asc')
                ->orderBy('id', 'asc')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            if ($intents->isEmpty()) {
                return collect();
            }

            $ids = $intents->pluck('id')->all();
            AutomationIntent::whereIn('id', $ids)->update([
                'status' => AutomationIntent::STATUS_PROCESSING,
                'locked_at' => $now,
                'locked_by' => $worker,
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => $now,
            ]);

            return AutomationIntent::with(['station', 'transaction', 'physicalDevice', 'stationDeviceMapping'])
                ->whereIn('id', $ids)
                ->get();
        });
    }

    /**
     * Process a single claimed intent.
     *
     * @param AutomationIntent $intent
     * @param Carbon|null $asOf
     * @return AutomationExecutionLog
     */
    public function processIntent(AutomationIntent $intent, ?Carbon $asOf = null): AutomationExecutionLog
    {
        $now = $asOf ?? Carbon::now();
        $station = $intent->station ?? Device::find($intent->station_id);

        // 1. Pre-execution Fencing Validation
        $fencing = $this->fencingGuard->validateFencing($intent, $now);
        if (!$fencing['valid']) {
            return $this->settleTerminal(
                $intent,
                $fencing['terminal_status'] ?? AutomationIntent::STATUS_STALE,
                $fencing['reason'] ?? 'Fencing validation failed',
                'FENCING_REJECTED'
            );
        }

        // 2. Capability Policy Validation
        $physicalDevice = $intent->physicalDevice ?? ($station ? ($station->activeLightRelay()->first()?->physicalDevice ?? $station->activeDisplayTv()->first()?->physicalDevice) : null);
        $capability = $this->capabilityPolicy->isCommandAllowed($physicalDevice, $intent->desired_state);

        if (!$capability['allowed']) {
            return $this->settleTerminal(
                $intent,
                AutomationIntent::STATUS_SKIPPED,
                $capability['reason'] ?? 'Command not allowed by capability policy',
                $capability['result_code'] ?? 'SKIPPED_UNVERIFIED_CAPABILITY'
            );
        }

        // 3. Category-aware command translation
        $command = match([$physicalDevice->category, $intent->desired_state]) {
            [PhysicalDevice::CATEGORY_SMART_TV, AutomationIntent::STATE_ON]  => 'POWER_ON',
            [PhysicalDevice::CATEGORY_SMART_TV, AutomationIntent::STATE_OFF] => 'STANDBY',
            [PhysicalDevice::CATEGORY_SMART_RELAY, AutomationIntent::STATE_ON] => 'SWITCH_ON',
            default => 'SWITCH_OFF',
        };

        // 4. Resolve Driver
        try {
            $driver = $this->driverManager->getDriverForDevice($physicalDevice);
        } catch (Throwable $e) {
            return $this->handleDispatchFailure($intent, $physicalDevice, 'CONFIG_ERROR', $e->getMessage(), 0, $now, $command);
        }

        // 5. Mandatory Read-Back Before Retry (for Shelly Relay)
        if ($intent->attempts > 1 && $physicalDevice->category === PhysicalDevice::CATEGORY_SMART_RELAY) {
            try {
                $statusResult = $driver->getStatus($physicalDevice);
                if ($statusResult->success) {
                    $state = $statusResult->simulatedState ?: $statusResult->rawResponse;
                    $isCurrentlyOn = isset($state['output']) ? (bool) $state['output'] : (($state['switch'] ?? '') === 'ON');
                    $expectedOn = ($intent->desired_state === AutomationIntent::STATE_ON);

                    if ($isCurrentlyOn === $expectedOn) {
                        // Previous attempt succeeded on device! Mark complete without sending redundant packet.
                        return $this->settleSuccess(
                            $intent,
                            $physicalDevice,
                            $driver,
                            $isCurrentlyOn,
                            $state['power_watts'] ?? $state['apower'] ?? 0.0,
                            0,
                            $command,
                            'VERIFIED_OK_READBACK',
                            $now
                        );
                    }
                }
            } catch (Throwable $readbackError) {
                Log::debug("[AUTOMATION_DISPATCHER] Pre-retry read-back probe failed: {$readbackError->getMessage()}");
            }
        }

        // 6. Driver Dispatch
        $startTime = microtime(true);

        try {
            $cmdResult = $driver->sendCommand($physicalDevice, $command, ['channel' => $intent->target_channel]);

            if (!$cmdResult->success) {
                $errorCat = $cmdResult->resultCode ?: 'TIMEOUT';
                return $this->handleDispatchFailure(
                    $intent,
                    $physicalDevice,
                    $errorCat,
                    $cmdResult->message ?: "Driver command {$command} failed",
                    (int) round((microtime(true) - $startTime) * 1000),
                    $now,
                    $command
                );
            }

            // Post-dispatch read-back verification (category-aware)
            $statusResult = $driver->getStatus($physicalDevice);
            $state = $statusResult->simulatedState ?: $statusResult->rawResponse;
            if ($physicalDevice->category === PhysicalDevice::CATEGORY_SMART_TV) {
                if ($intent->desired_state === AutomationIntent::STATE_ON && ($cmdResult->resultCode === 'ACK_WAKEUP' || ($state['power_state'] ?? '') === 'waking')) {
                    $observedOutput = true;
                } else {
                    $observedOutput = ($state['power_state'] ?? '') === 'on' || ($state['power'] ?? '') === 'ON';
                }
            } else {
                $observedOutput = isset($state['output']) ? (bool) $state['output'] : (($state['switch'] ?? '') === 'ON');
            }
            $expectedOutput = ($intent->desired_state === AutomationIntent::STATE_ON);

            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);

            if ($observedOutput !== $expectedOutput) {
                return $this->handleDispatchFailure(
                    $intent,
                    $physicalDevice,
                    'READBACK_MISMATCH',
                    "READBACK_MISMATCH: Expected output=" . ($expectedOutput ? 'true' : 'false') . ", observed=" . ($observedOutput ? 'true' : 'false'),
                    $latencyMs,
                    $now,
                    $command
                );
            }

            return $this->settleSuccess(
                $intent,
                $physicalDevice,
                $driver,
                $observedOutput,
                $state['power_watts'] ?? $state['apower'] ?? 0.0,
                $latencyMs,
                $command,
                'VERIFIED_OK',
                $now
            );
        } catch (Throwable $e) {
            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
            $errorCategory = 'TIMEOUT';
            if (str_contains($e->getMessage(), 'READBACK_MISMATCH')) {
                $errorCategory = 'READBACK_MISMATCH';
            } elseif (str_contains($e->getMessage(), 'Connection refused') || str_contains($e->getMessage(), 'EHOSTUNREACH')) {
                $errorCategory = 'OFFLINE';
            }

            return $this->handleDispatchFailure($intent, $physicalDevice, $errorCategory, $e->getMessage(), $latencyMs, $now, $command);
        }
    }

    /**
     * Settle an intent as successful.
     */
    protected function settleSuccess(
        AutomationIntent $intent,
        PhysicalDevice $physicalDevice,
        HardwareDriverInterface $driver,
        bool $observedOutput,
        ?float $powerWatts,
        int $latencyMs,
        string $command,
        string $resultCode,
        Carbon $now
    ): AutomationExecutionLog {
        DB::transaction(function () use ($intent, $now) {
            $intent->update([
                'status' => AutomationIntent::STATUS_COMPLETED,
                'completed_at' => $now,
                'last_error_category' => null,
                'last_error_message' => null,
                'updated_at' => $now,
            ]);
        });

        // Update station state cache
        $badge = $observedOutput ? 'SIMULATED_ON' : 'SIMULATED_OFF';
        Device::where('id', $intent->station_id)->update([
            'last_hardware_state' => $badge,
            'last_hardware_verified_at' => $now,
        ]);

        $log = AutomationExecutionLog::create([
            'intent_id' => $intent->id,
            'transaction_id' => $intent->transaction_id,
            'station_id' => $intent->station_id,
            'station_name' => $intent->station_name ?? "Station #{$intent->station_id}",
            'physical_device_id' => $physicalDevice->id,
            'driver_type' => $driver->getDriverType(),
            'command_sent' => $command,
            'attempt_number' => $intent->attempts,
            'execution_status' => AutomationExecutionLog::STATUS_SUCCESS,
            'result_code' => $resultCode,
            'error_category' => null,
            'error_details' => null,
            'observed_output_state' => $observedOutput ? 'true' : 'false',
            'observed_power_watts' => $powerWatts,
            'latency_ms' => $latencyMs,
            'executed_at' => $now,
        ]);

        return $log;
    }

    /**
     * Settle an intent as terminal (STALE, CANCELLED, or SKIPPED).
     */
    protected function settleTerminal(
        AutomationIntent $intent,
        string $status,
        string $reason,
        string $resultCode
    ): AutomationExecutionLog {
        $now = Carbon::now();

        DB::transaction(function () use ($intent, $status, $reason, $now) {
            $intent->update([
                'status' => $status,
                'last_error_category' => $status,
                'last_error_message' => $reason,
                'updated_at' => $now,
            ]);
        });

        return AutomationExecutionLog::create([
            'intent_id' => $intent->id,
            'transaction_id' => $intent->transaction_id,
            'station_id' => $intent->station_id,
            'station_name' => $intent->station_name ?? "Station #{$intent->station_id}",
            'physical_device_id' => $intent->physical_device_id,
            'driver_type' => 'none',
            'command_sent' => $intent->desired_state,
            'attempt_number' => $intent->attempts,
            'execution_status' => AutomationExecutionLog::STATUS_SKIPPED,
            'result_code' => $resultCode,
            'error_category' => $status,
            'error_details' => $reason,
            'observed_output_state' => null,
            'observed_power_watts' => null,
            'latency_ms' => 0,
            'executed_at' => $now,
        ]);
    }

    /**
     * Handle dispatch failure, schedule bounded backoff retry, or mark FAILED after attempt 4.
     */
    protected function handleDispatchFailure(
        AutomationIntent $intent,
        ?PhysicalDevice $physicalDevice,
        string $errorCategory,
        string $errorMessage,
        int $latencyMs,
        Carbon $now,
        ?string $command = null
    ): AutomationExecutionLog {
        // Backoff schedule: Attempt 1 -> +3s, Attempt 2 -> +10s, Attempt 3 -> +30s
        $backoffs = [1 => 3, 2 => 10, 3 => 30];
        $currentAttempt = (int) $intent->attempts;

        if ($currentAttempt >= (int) $intent->max_attempts) {
            // Permanently FAILED after attempt 4
            $newStatus = AutomationIntent::STATUS_FAILED;
            $nextRetry = null;
            $execStatus = AutomationExecutionLog::STATUS_FAILED;
            $resultCode = 'MAX_RETRIES_EXCEEDED';

            // Mark station state badge as COMMAND_FAILED
            Device::where('id', $intent->station_id)->update([
                'last_hardware_state' => 'COMMAND_FAILED',
                'last_hardware_verified_at' => $now,
            ]);
        } else {
            // Schedule RETRY_PENDING
            $newStatus = AutomationIntent::STATUS_RETRY_PENDING;
            $delay = $backoffs[$currentAttempt] ?? 30;
            $nextRetry = (clone $now)->addSeconds($delay);
            $execStatus = AutomationExecutionLog::STATUS_RETRY_SCHEDULED;
            $resultCode = "RETRY_SCHEDULED_{$delay}S";
        }

        DB::transaction(function () use ($intent, $newStatus, $nextRetry, $errorCategory, $errorMessage, $now) {
            $intent->update([
                'status' => $newStatus,
                'next_retry_at' => $nextRetry,
                'last_error_category' => $errorCategory,
                'last_error_message' => substr($errorMessage, 0, 250),
                'updated_at' => $now,
            ]);
        });

        return AutomationExecutionLog::create([
            'intent_id' => $intent->id,
            'transaction_id' => $intent->transaction_id,
            'station_id' => $intent->station_id,
            'station_name' => $intent->station_name ?? "Station #{$intent->station_id}",
            'physical_device_id' => $physicalDevice ? $physicalDevice->id : $intent->physical_device_id,
            'driver_type' => $physicalDevice ? ($physicalDevice->category === PhysicalDevice::CATEGORY_SMART_TV ? 'samsung_tv' : 'shelly_relay') : 'unknown',
            'command_sent' => $command ?? ($physicalDevice?->category === PhysicalDevice::CATEGORY_SMART_TV
                ? ($intent->desired_state === AutomationIntent::STATE_ON ? 'POWER_ON' : 'STANDBY')
                : ($intent->desired_state === AutomationIntent::STATE_ON ? 'SWITCH_ON' : 'SWITCH_OFF')),
            'attempt_number' => $currentAttempt,
            'execution_status' => $execStatus,
            'result_code' => $resultCode,
            'error_category' => $errorCategory,
            'error_details' => substr($errorMessage, 0, 500),
            'observed_output_state' => null,
            'observed_power_watts' => null,
            'latency_ms' => $latencyMs,
            'executed_at' => $now,
        ]);
    }

    /**
     * Stale lock recovery sweep (called on worker boot or period).
     *
     * @param int $leaseSeconds
     * @param Carbon|null $asOf
     * @return int
     */
    public function recoverStaleLockedIntents(int $leaseSeconds = 60, ?Carbon $asOf = null): int
    {
        $now = $asOf ?? Carbon::now();
        $staleIntents = AutomationIntent::staleLocked($leaseSeconds, $now)->get();

        if ($staleIntents->isEmpty()) {
            return 0;
        }

        $recovered = 0;
        foreach ($staleIntents as $stale) {
            if ($stale->attempts >= $stale->max_attempts) {
                $stale->update([
                    'status' => AutomationIntent::STATUS_FAILED,
                    'last_error_category' => 'STALE_LEASE_EXPIRED',
                    'last_error_message' => 'Worker lease expired after reaching max attempts.',
                    'updated_at' => $now,
                ]);
            } else {
                $stale->update([
                    'status' => AutomationIntent::STATUS_RETRY_PENDING,
                    'next_retry_at' => $now,
                    'last_error_category' => 'STALE_LEASE_RECOVERED',
                    'last_error_message' => 'Reset to RETRY_PENDING after worker lease expired.',
                    'updated_at' => $now,
                ]);
            }
            $recovered++;
        }

        return $recovered;
    }
}
