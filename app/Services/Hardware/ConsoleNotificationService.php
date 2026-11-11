<?php

namespace App\Services\Hardware;

use App\Exceptions\TvControlException;
use App\Models\Device;
use App\Models\PhysicalDevice;
use App\Models\Playstation;
use App\Models\StationDeviceMapping;
use App\Models\Transaction;
use App\Models\TvAlertEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ConsoleNotificationService
{
    // Status Constants
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_ACCEPTED = 'ACCEPTED';
    public const STATUS_FAILED = 'FAILED';
    public const STATUS_UNVERIFIED = 'UNVERIFIED';

    public const PRESET_REMAINING_10_MIN = 'REMAINING_10_MIN';
    public const PRESET_TIME_REMAINING_10_MINUTES = 'TIME_REMAINING_10_MINUTES';
    public const PRESET_ALMOST_FINISHED = 'ALMOST_FINISHED';
    public const PRESET_TIME_EXPIRED = 'TIME_EXPIRED';
    public const PRESET_LOWER_VOLUME = 'LOWER_VOLUME';
    public const PRESET_CUSTOM = 'CUSTOM';

    protected ConsoleNotificationDispatcher $dispatcher;

    public function __construct(ConsoleNotificationDispatcher $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     * Normalize delivery or event status to one of: PENDING, ACCEPTED, FAILED, UNVERIFIED.
     *
     * @param string|null $status
     * @return string
     */
    public static function normalizeStatus(?string $status): string
    {
        if ($status === null || trim($status) === '') {
            return self::STATUS_UNVERIFIED;
        }

        $upper = strtoupper(trim($status));

        return match ($upper) {
            'ACCEPTED',
            'HTTP_ACCEPTED',
            'ACK_HTTP',
            'DELIVERED',
            'DELIVERED_TO_APP',
            'ACKED',
            'ACKED_BY_SIMULATOR',
            'COMPLETED',
            'STATE_VERIFIED',
            'COMMAND_EXECUTED',
            'SENT_TO_SIMULATOR' => self::STATUS_ACCEPTED,

            'PENDING',
            'COMMAND_SENT',
            'COMMAND_RECEIVED',
            'QUEUED_FOR_CONSUMER' => self::STATUS_PENDING,

            'FAILED',
            'REJECTED',
            'CANCELLED',
            'EXPIRED',
            'STALE',
            'TIMEOUT',
            'OFFLINE',
            'ERROR' => self::STATUS_FAILED,

            'UNVERIFIED' => self::STATUS_UNVERIFIED,

            default => self::STATUS_UNVERIFIED,
        };
    }

    public static function getPresets(): array
    {
        return [
            self::PRESET_REMAINING_10_MIN => [
                'title' => 'SISA WAKTU 10 MENIT',
                'body' => 'Waktu bermain tersisa 10 menit. Silakan hubungi kasir untuk memperpanjang waktu.',
            ],
            self::PRESET_TIME_REMAINING_10_MINUTES => [
                'title' => 'SISA WAKTU 10 MENIT',
                'body' => 'Waktu bermain tersisa 10 menit. Silakan hubungi kasir untuk memperpanjang waktu.',
            ],
            self::PRESET_ALMOST_FINISHED => [
                'title' => 'WAKTU HAMPIR HABIS',
                'body' => 'Waktu bermain hampir habis. Segera hubungi kasir.',
            ],
            self::PRESET_TIME_EXPIRED => [
                'title' => 'WAKTU HABIS',
                'body' => 'Waktu bermain telah habis. Silakan hubungi kasir.',
            ],
            self::PRESET_LOWER_VOLUME => [
                'title' => 'PERINGATAN VOLUME',
                'body' => 'Mohon kecilkan volume permainan. Terima kasih.',
            ],
        ];
    }

    /**
     * Send manual notification popup to a PlayStation console.
     *
     * @param Device $station
     * @param User $actor
     * @param array $payload
     * @return TvAlertEvent
     * @throws TvControlException
     */
    public function sendManualNotification(Device $station, User $actor, array $payload): TvAlertEvent
    {
        $userRole = strtolower($actor->role ?? '');
        if (!in_array($userRole, ['admin', 'owner'], true)) {
            throw TvControlException::forbidden('Hanya Admin atau Owner yang berwenang mengirim notifikasi konsol.');
        }

        $station->loadMissing(['playstations', 'playstation']);
        if ($station->getStationCategory() !== Playstation::CATEGORY_PLAYSTATION) {
            throw TvControlException::invalidCategory($station->nama);
        }

        $activeTx = Transaction::where('device_id', $station->id)
            ->where('billing_status', Transaction::BILLING_RUNNING)
            ->first();

        if (!$activeTx) {
            throw TvControlException::noActiveSession($station->nama);
        }

        $consoleMapping = $station->activeGameConsole()->with('physicalDevice')->first();
        $consoleDevice = $consoleMapping ? $consoleMapping->physicalDevice : null;

        if (!$consoleDevice || !$consoleDevice->is_active) {
            throw TvControlException::invalidInput("Stasiun '{$station->nama}' tidak memiliki Game Console aktif yang terhubung.");
        }

        $preset = strtoupper(trim((string) ($payload['preset'] ?? $payload['message_preset'] ?? self::PRESET_REMAINING_10_MIN)));
        if ($preset === '10_MIN_LEFT') {
            $preset = self::PRESET_REMAINING_10_MIN;
        }

        $presets = self::getPresets();
        if ($preset === self::PRESET_CUSTOM) {
            $rawBody = (string) ($payload['custom_message'] ?? $payload['message_body'] ?? $payload['message'] ?? '');
            if (empty(trim($rawBody))) {
                throw TvControlException::invalidInput('Pesan custom tidak boleh kosong.');
            }
            $cleanBody = $this->sanitizeMessage($rawBody, 120);
            $title = !empty($payload['custom_title'])
                ? $this->sanitizeMessage((string) $payload['custom_title'], 40)
                : 'PESAN DARI KASIR / ADMIN';
        } else {
            if (!isset($presets[$preset])) {
                throw TvControlException::invalidInput("Preset pesan '{$preset}' tidak valid.");
            }
            $title = $presets[$preset]['title'];
            $cleanBody = $presets[$preset]['body'];
        }

        $generation = (int) ($station->session_generation ?: ($activeTx->session_generation ?: 1));
        $durationSeconds = isset($payload['duration_seconds']) ? (int) $payload['duration_seconds'] : 8;
        $durationSeconds = max(5, min(15, $durationSeconds));

        // Create notification event record
        $event = TvAlertEvent::create([
            'station_id' => $station->id,
            'station_name' => $station->nama,
            'transaction_id' => $activeTx->id_transaksi,
            'station_device_mapping_id' => $consoleMapping->id,
            'physical_device_id' => $consoleDevice->id,
            'target_channel' => 'CONSOLE',
            'actor_user_id' => $actor->id,
            'actor_role' => strtoupper($actor->role ?? 'ADMIN'),
            'event_type' => TvAlertEvent::TYPE_MANUAL_ALERT,
            'command' => TvAlertEvent::CMD_SHOW_ALERT,
            'message_preset' => $preset,
            'message_title' => $title,
            'message_body' => $cleanBody,
            'display_duration_seconds' => $durationSeconds,
            'control_mode' => 'REAL',
            'driver_type' => $consoleDevice->driver_type ?: $consoleDevice->model,
            'session_generation' => $generation,
            'status' => TvAlertEvent::STATUS_PENDING,
            'scheduled_at' => Carbon::now(),
        ]);

        // Dispatch via ConsoleNotificationDispatcher
        $dispatchResult = $this->dispatcher->dispatch($consoleDevice, $cleanBody, [
            'title' => $title,
            'event_id' => $event->id,
            'duration_seconds' => $durationSeconds,
        ]);

        if ($dispatchResult->success) {
            $isDirectAccepted = ($dispatchResult->resultCode === 'HTTP_ACCEPTED' || $dispatchResult->resultCode === 'ACK_HTTP' || $dispatchResult->resultCode === 'ACCEPTED');
            $isPs4Outbound = (strpos(strtoupper((string) $consoleDevice->model), 'PS4') !== false || strpos(strtoupper((string) $consoleDevice->driver_type), 'PS4') !== false);

            $newStatus = ($isDirectAccepted && !$isPs4Outbound) ? TvAlertEvent::STATUS_ACCEPTED : TvAlertEvent::STATUS_PENDING;

            $event->update([
                'status' => $newStatus,
                'result_code' => $dispatchResult->resultCode,
                'delivered_at' => ($newStatus === TvAlertEvent::STATUS_ACCEPTED) ? Carbon::now() : null,
            ]);
        } else {
            $event->update([
                'status' => TvAlertEvent::STATUS_FAILED,
                'result_code' => $dispatchResult->resultCode,
                'error_message' => $dispatchResult->message,
            ]);
        }

        return $event->fresh();
    }

    /**
     * Get live console status for a station.
     *
     * @param Device $station
     * @return array
     */
    public function getConsoleStatus(Device $station): array
    {
        $station->loadMissing(['playstations', 'playstation']);
        $mapping = $station->activeGameConsole()->with('physicalDevice')->first();
        $device = $mapping ? $mapping->physicalDevice : null;

        if (!$device) {
            return [
                'has_console' => false,
                'mapped' => false,
                'station_id' => $station->id,
                'station_name' => $station->nama,
                'message' => 'Tidak ada Game Console yang terpetakan pada stasiun ini.',
            ];
        }

        $connectivity = $this->dispatcher->checkConnectivity($device);
        $transport = $this->dispatcher->getTransportStatus($device);

        $recentEvents = TvAlertEvent::where('physical_device_id', $device->id)
            ->latest('id')
            ->limit(5)
            ->get();

        return [
            'has_console' => true,
            'mapped' => true,
            'station_id' => $station->id,
            'station_name' => $station->nama,
            'device_id' => $device->id,
            'name' => $device->name,
            'brand' => $device->brand,
            'model' => $device->model,
            'ip_address' => $device->ip_address,
            'driver_type' => $device->driver_type ?: $device->model,
            'firmware_version' => $device->firmware_version,
            'homebrew_type' => $device->homebrew_type,
            'homebrew_version' => $device->homebrew_version,
            'is_active' => (bool) $device->is_active,
            'connection_status' => $connectivity['status'] ?? $device->connection_status,
            'last_seen_at' => $device->last_seen_at ? $device->last_seen_at->toIso8601String() : null,
            'connectivity' => $connectivity,
            'transport' => $transport,
            'recent_events' => $recentEvents,
        ];
    }

    /**
     * Cancel pending console alerts for a station upon extend, stop, or new session.
     *
     * @param Device $station
     * @param string $reason
     * @return int Number of cancelled events
     */
    public function cancelPendingAlertsForStation(Device $station, string $reason): int
    {
        $now = Carbon::now();
        return TvAlertEvent::where('station_id', $station->id)
            ->where('status', TvAlertEvent::STATUS_PENDING)
            ->whereIn('target_channel', ['CONSOLE', 'BOTH'])
            ->update([
                'status' => TvAlertEvent::STATUS_CANCELLED,
                'cancelled_at' => $now,
                'error_message' => $reason,
            ]);
    }

    /**
     * Sanitize message string for console display.
     *
     * @param string $text
     * @param int $maxLength
     * @return string
     */
    public function sanitizeMessage(string $text, int $maxLength = 120): string
    {
        $clean = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', ' ', $text) ?? '';
        $clean = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', ' ', $clean) ?? '';
        $clean = strip_tags($clean);
        $clean = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $clean) ?? '';
        $clean = preg_replace('/\s+/u', ' ', $clean) ?? '';
        $clean = trim($clean);

        if (mb_strlen($clean) > $maxLength) {
            $clean = mb_substr($clean, 0, $maxLength);
        }

        return $clean;
    }
}
