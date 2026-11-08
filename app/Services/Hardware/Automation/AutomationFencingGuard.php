<?php

namespace App\Services\Hardware\Automation;

use App\Models\AutomationIntent;
use App\Models\Device;
use App\Models\StationDeviceMapping;
use App\Models\Transaction;
use Carbon\Carbon;

class AutomationFencingGuard
{
    /**
     * Re-verify station, session generation, active transaction, and mapping
     * immediately prior to physical driver dispatch.
     *
     * @param AutomationIntent $intent
     * @param Carbon|null $asOf
     * @return array [bool $valid, ?string $reason, ?string $terminal_status]
     */
    public function validateFencing(AutomationIntent $intent, ?Carbon $asOf = null): array
    {
        $now = $asOf ?? Carbon::now();

        // 1. Verify Station Exists
        $station = Device::find($intent->station_id);
        if (!$station) {
            return [
                'valid' => false,
                'reason' => "Station #{$intent->station_id} does not exist.",
                'terminal_status' => AutomationIntent::STATUS_STALE,
            ];
        }

        // 2. Generation Fencing Check
        if ((int) $station->session_generation !== (int) $intent->session_generation) {
            return [
                'valid' => false,
                'reason' => "Session generation mismatch: station={$station->session_generation}, intent={$intent->session_generation}",
                'terminal_status' => AutomationIntent::STATUS_STALE,
            ];
        }

        // 3. Mapping Verification
        if ($intent->station_device_mapping_id) {
            $mapping = StationDeviceMapping::find($intent->station_device_mapping_id);
            if (!$mapping || (int) $mapping->station_id !== (int) $station->id || $mapping->mapping_status !== StationDeviceMapping::STATUS_ACTIVE) {
                return [
                    'valid' => false,
                    'reason' => "Station hardware mapping #{$intent->station_device_mapping_id} is no longer active on station #{$station->id}.",
                    'terminal_status' => AutomationIntent::STATUS_STALE,
                ];
            }
        } else {
            $currentMapping = $station->activeLightRelay()->first() ?? $station->activeDisplayTv()->first();
            if (!$currentMapping) {
                return [
                    'valid' => false,
                    'reason' => "Station #{$station->id} has no active hardware mapping.",
                    'terminal_status' => AutomationIntent::STATUS_SKIPPED,
                ];
            }
        }

        // 3B. Manual Override Precedence Check:
        // Prevent older automated intents from overwriting a newer manual power override.
        if ($station->last_manual_power_at && $intent->scheduled_at) {
            $manualAt = Carbon::parse($station->last_manual_power_at);
            if ($intent->scheduled_at->lt($manualAt)) {
                $isTerminalSafety = in_array($intent->event_type, [
                    AutomationIntent::EVENT_BILLING_STOPPED,
                    AutomationIntent::EVENT_BILLING_EXPIRED
                ], true);

                if (!$isTerminalSafety) {
                    return [
                        'valid' => false,
                        'reason' => "Intent superseded by newer manual power override executed at {$manualAt->toDateTimeString()}.",
                        'terminal_status' => AutomationIntent::STATUS_STALE,
                    ];
                }
            }
        }

        // 4. Session & Transaction State Checks
        if ($intent->transaction_id) {
            $transaction = Transaction::find($intent->transaction_id);

            // 4A. For OFF Desired State
            if ($intent->desired_state === AutomationIntent::STATE_OFF) {
                // If station is already occupied by a DIFFERENT running transaction, abort old OFF!
                $activeTx = $station->activeTransaction();
                if ($activeTx && (int) $activeTx->id_transaksi !== (int) $intent->transaction_id && $activeTx->billing_status === Transaction::BILLING_RUNNING) {
                    return [
                        'valid' => false,
                        'reason' => "Station occupied by newer transaction #{$activeTx->id_transaksi}.",
                        'terminal_status' => AutomationIntent::STATUS_STALE,
                    ];
                }

                if ($transaction) {
                    // If the transaction was extended: expires_at is now beyond this intent's scheduled_at
                    if ($transaction->billing_status === Transaction::BILLING_RUNNING && $transaction->expires_at && $transaction->expires_at->gt($intent->scheduled_at)) {
                        return [
                            'valid' => false,
                            'reason' => "Transaction was extended (new expiry: {$transaction->expires_at->toDateTimeString()}).",
                            'terminal_status' => AutomationIntent::STATUS_CANCELLED,
                        ];
                    }

                    // Special rule for BILLING_EXPIRED:
                    // At exact expires_at, G2 billing_status might still be 'RUNNING' prior to G2 worker reconciliation.
                    // This is completely valid and MUST NOT be aborted!
                    if ($intent->event_type === AutomationIntent::EVENT_BILLING_EXPIRED) {
                        if ($transaction->expires_at && $now->lt($transaction->expires_at)) {
                            // If clock is somehow before expires_at, intent fired prematurely
                            return [
                                'valid' => false,
                                'reason' => "Intent fired before transaction expires_at ({$transaction->expires_at->toDateTimeString()}).",
                                'terminal_status' => AutomationIntent::STATUS_RETRY_PENDING,
                            ];
                        }
                    }
                }
            }

            // 4B. For ON Desired State
            if ($intent->desired_state === AutomationIntent::STATE_ON) {
                if ($transaction && in_array($transaction->billing_status, [
                    Transaction::BILLING_COMPLETED,
                    Transaction::BILLING_STOPPED_EARLY,
                    Transaction::BILLING_EXPIRED_PENDING_ACTION,
                ], true)) {
                    return [
                        'valid' => false,
                        'reason' => "Cannot execute ON command: transaction #{$transaction->id_transaksi} is already {$transaction->billing_status}.",
                        'terminal_status' => AutomationIntent::STATUS_STALE,
                    ];
                }
            }
        }

        return [
            'valid' => true,
            'reason' => null,
            'terminal_status' => null,
        ];
    }
}
