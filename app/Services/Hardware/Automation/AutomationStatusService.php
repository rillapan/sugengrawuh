<?php

namespace App\Services\Hardware\Automation;

use App\Models\AutomationIntent;
use App\Models\Device;
use Carbon\Carbon;

class AutomationStatusService
{
    /**
     * Get masked cashier hardware status for a station.
     * Strictly hides IP, MAC, passwords, and tokens.
     *
     * @param Device $station
     * @return array
     */
    public function getCashierStatus(Device $station): array
    {
        $activeRelay = $station->activeLightRelay()->first();
        $activeTv = $station->activeDisplayTv()->first();
        $activeMapping = $activeRelay ?? $activeTv;

        if (!$activeMapping) {
            return [
                'station_id' => $station->id,
                'station_name' => $station->nama,
                'has_hardware' => false,
                'hardware_state' => 'NO_HARDWARE',
                'hardware_badge' => [
                    'code' => 'NO_HARDWARE',
                    'label' => 'Tanpa Otomasi',
                    'color_class' => 'badge-light text-muted',
                    'icon' => 'fas fa-minus',
                ],
                'device_role' => null,
                'last_verified_at' => null,
                'pending_intent' => null,
                'alert' => null,
            ];
        }

        $pendingIntent = AutomationIntent::where('station_id', $station->id)
            ->whereIn('status', [AutomationIntent::STATUS_PENDING, AutomationIntent::STATUS_PROCESSING, AutomationIntent::STATUS_RETRY_PENDING])
            ->latest('id')
            ->first();

        $failedIntent = AutomationIntent::where('station_id', $station->id)
            ->where('status', AutomationIntent::STATUS_FAILED)
            ->where('updated_at', '>=', Carbon::now()->subMinutes(15))
            ->latest('id')
            ->first();

        $currentState = $station->last_hardware_state ?? 'STATE_UNKNOWN';

        if ($failedIntent) {
            $currentState = 'COMMAND_FAILED';
        } elseif ($pendingIntent && $pendingIntent->status === AutomationIntent::STATUS_PROCESSING) {
            $currentState = 'COMMAND_PENDING';
        }

        $badge = $this->formatBadge($currentState);

        $alert = null;
        if ($failedIntent) {
            $alert = [
                'level' => 'danger',
                'station_name' => $station->nama,
                'message' => "Lampu {$station->nama} gagal dimatikan otomatis setelah waktu habis! Periksa koneksi Shelly atau matikan saklar fisik manual.",
                'allow_manual_retry' => true,
                'intent_id' => $failedIntent->id,
            ];
        }

        return [
            'station_id' => $station->id,
            'station_name' => $station->nama,
            'has_hardware' => true,
            'hardware_state' => $currentState,
            'hardware_badge' => $badge,
            'device_role' => $activeMapping->mapping_role,
            'last_verified_at' => $station->last_hardware_verified_at ? $station->last_hardware_verified_at->toDateTimeString() : null,
            'pending_intent' => $pendingIntent ? [
                'id' => $pendingIntent->id,
                'event_type' => $pendingIntent->event_type,
                'status' => $pendingIntent->status,
                'attempts' => $pendingIntent->attempts,
            ] : null,
            'alert' => $alert,
        ];
    }

    /**
     * Format badge code to UI representation.
     */
    public function formatBadge(string $stateCode): array
    {
        return match ($stateCode) {
            'SIMULATED_ON', 'RELAY_ON_VERIFIED' => [
                'code' => $stateCode,
                'label' => $stateCode === 'SIMULATED_ON' ? 'Simulasi Relay ON' : 'Relay ON (Terverifikasi)',
                'color_class' => 'badge-success',
                'icon' => 'fas fa-bolt',
            ],
            'SIMULATED_OFF', 'RELAY_OFF_VERIFIED' => [
                'code' => $stateCode,
                'label' => $stateCode === 'SIMULATED_OFF' ? 'Simulasi Relay OFF' : 'Relay OFF (Terverifikasi)',
                'color_class' => 'badge-secondary',
                'icon' => 'far fa-circle',
            ],
            'COMMAND_PENDING' => [
                'code' => 'COMMAND_PENDING',
                'label' => 'Mengirim Perintah...',
                'color_class' => 'badge-warning',
                'icon' => 'fas fa-spinner fa-spin',
            ],
            'COMMAND_FAILED' => [
                'code' => 'COMMAND_FAILED',
                'label' => 'Perintah Gagal',
                'color_class' => 'badge-danger',
                'icon' => 'fas fa-exclamation-triangle',
            ],
            'DEVICE_OFFLINE' => [
                'code' => 'DEVICE_OFFLINE',
                'label' => 'Perangkat Offline',
                'color_class' => 'badge-dark',
                'icon' => 'fas fa-wifi-slash',
            ],
            default => [
                'code' => 'STATE_UNKNOWN',
                'label' => 'Status Belum Dicek',
                'color_class' => 'badge-light text-muted',
                'icon' => 'fas fa-question-circle',
            ],
        };
    }
}
