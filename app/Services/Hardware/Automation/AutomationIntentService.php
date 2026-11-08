<?php

namespace App\Services\Hardware\Automation;

use App\Exceptions\AutomationSchemaNotReadyException;
use App\Models\AutomationIntent;
use App\Models\Device;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AutomationIntentService
{
    public static ?array $mockMissingTables = null;
    public static ?array $mockMissingColumns = null;
    protected static ?bool $hasG5SchemaCached = null;

    /**
     * Check if IoT hardware automation is enabled in configuration.
     */
    public function isAutomationEnabled(): bool
    {
        return (bool) Config::get('hardware.automation_enabled', Config::get('hardware.automation.enabled', false));
    }

    /**
     * Required tables for G5 IoT Automation.
     */
    public static function getRequiredG5Tables(): array
    {
        return [
            'automation_intents',
            'automation_execution_logs',
            'physical_devices',
            'station_device_mappings',
        ];
    }

    /**
     * Required columns for G5 IoT Automation.
     */
    public static function getRequiredG5Columns(): array
    {
        return [
            'devices.session_generation',
            'transactions.session_generation',
        ];
    }

    /**
     * Detect missing G5 tables or columns.
     */
    public static function getMissingG5Schema(): array
    {
        $missing = [];

        if (static::$mockMissingTables !== null) {
            $missing = array_merge($missing, static::$mockMissingTables);
        } else {
            foreach (static::getRequiredG5Tables() as $table) {
                try {
                    if (!Schema::hasTable($table)) {
                        $missing[] = "table:{$table}";
                    }
                } catch (\Throwable $e) {
                    $missing[] = "table:{$table}";
                }
            }
        }

        if (static::$mockMissingColumns !== null) {
            $missing = array_merge($missing, static::$mockMissingColumns);
        } else {
            foreach (static::getRequiredG5Columns() as $colDef) {
                [$table, $col] = explode('.', $colDef);
                try {
                    if (!Schema::hasColumn($table, $col)) {
                        $missing[] = "column:{$colDef}";
                    }
                } catch (\Throwable $e) {
                    $missing[] = "column:{$colDef}";
                }
            }
        }

        return $missing;
    }

    /**
     * Check whether active database schema supports G5 automation.
     */
    public static function hasG5Schema(bool $refresh = false): bool
    {
        if (static::$hasG5SchemaCached === null || $refresh || static::$mockMissingTables !== null || static::$mockMissingColumns !== null) {
            $missing = static::getMissingG5Schema();
            static::$hasG5SchemaCached = empty($missing);
        }

        return (bool) static::$hasG5SchemaCached;
    }

    /**
     * Assert that schema is ready when automation is enabled.
     * Throws AutomationSchemaNotReadyException if enabled but incomplete.
     *
     * @throws AutomationSchemaNotReadyException
     */
    public function assertAutomationReady(): void
    {
        if (!$this->isAutomationEnabled()) {
            return;
        }

        $missing = static::getMissingG5Schema();
        if (!empty($missing)) {
            $missingStr = implode(', ', $missing);
            throw new AutomationSchemaNotReadyException(
                "Otomatisasi IoT diaktifkan (HARDWARE_AUTOMATION_ENABLED=true), namun skema database belum siap. Item berikut belum tersedia: [{$missingStr}]. Silakan jalankan migrasi database atau nonaktifkan otomatisasi.",
                $missing
            );
        }
    }

    /**
     * Record a new desired-state automation intent atomically.
     *
     * @param Device $station
     * @param Transaction|null $transaction
     * @param string $eventType
     * @param string $desiredState
     * @param Carbon|null $scheduledAt
     * @param string|null $idempotencyKey
     * @return AutomationIntent
     */
    public function recordIntent(
        Device $station,
        ?Transaction $transaction,
        string $eventType,
        string $desiredState,
        ?Carbon $scheduledAt = null,
        ?string $idempotencyKey = null
    ): AutomationIntent {
        $activeRelay = $station->activeLightRelay()->first();
        $activeTv = $station->activeDisplayTv()->first();
        $activeMapping = $activeRelay ?? $activeTv;

        $generation = (int) ($station->session_generation ?? 1);

        if ($idempotencyKey === null) {
            $idempotencyKey = $this->generateIdempotencyKey($station, $transaction, $eventType, $generation);
        }

        $scheduledTime = $scheduledAt ?? Carbon::now();

        // If station has no hardware mapping at all, create intent as SKIPPED
        $initialStatus = $activeMapping ? AutomationIntent::STATUS_PENDING : AutomationIntent::STATUS_SKIPPED;

        return AutomationIntent::firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'transaction_id' => $transaction ? $transaction->id_transaksi : null,
                'station_id' => $station->id,
                'station_name' => $station->nama,
                'physical_device_id' => $activeMapping ? $activeMapping->physical_device_id : null,
                'station_device_mapping_id' => $activeMapping ? $activeMapping->id : null,
                'session_generation' => $generation,
                'event_type' => $eventType,
                'desired_state' => $desiredState,
                'target_channel' => 0,
                'status' => $initialStatus,
                'attempts' => 0,
                'max_attempts' => 4,
                'scheduled_at' => $scheduledTime,
            ]
        );
    }

    /**
     * Cancel any scheduled or pending OFF intents for a transaction (e.g. upon extension or early stop).
     *
     * @param Transaction $transaction
     * @return int
     */
    public function cancelPendingOffIntents(Transaction $transaction): int
    {
        return AutomationIntent::where('transaction_id', $transaction->id_transaksi)
            ->where('desired_state', AutomationIntent::STATE_OFF)
            ->whereIn('status', [AutomationIntent::STATUS_PENDING, AutomationIntent::STATUS_RETRY_PENDING])
            ->update([
                'status' => AutomationIntent::STATUS_CANCELLED,
                'updated_at' => Carbon::now(),
            ]);
    }

    /**
     * Increment session generation on the station model.
     *
     * @param Device $station
     * @return int
     */
    public function bumpSessionGeneration(Device $station): int
    {
        $station->increment('session_generation');
        $station->refresh();

        return (int) $station->session_generation;
    }

    /**
     * Deterministic idempotency key generator.
     */
    protected function generateIdempotencyKey(Device $station, ?Transaction $transaction, string $eventType, int $generation): string
    {
        $suffix = strtolower($eventType);

        if ($transaction) {
            return "intent_tx_{$transaction->id_transaksi}_gen{$generation}_{$suffix}";
        }

        $ts = Carbon::now()->timestamp;
        return "intent_st_{$station->id}_gen{$generation}_{$suffix}_{$ts}";
    }
}
