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
use App\Services\Hardware\Contracts\HardwareDriverInterface;
use App\Services\Hardware\Drivers\MockSamsungTvDriver;
use App\Services\Hardware\DTO\HardwareCommandResult;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class StationNotificationDispatcher
{
    public const CHANNEL_AUTO = 'AUTO';
    public const CHANNEL_CONSOLE = 'CONSOLE';
    public const CHANNEL_TV = 'TV';
    public const CHANNEL_BOTH = 'BOTH';

    protected HardwareDriverManager $driverManager;
    protected TvAlertService $tvAlertService;

    public function __construct(
        HardwareDriverManager $driverManager,
        TvAlertService $tvAlertService
    ) {
        $this->driverManager = $driverManager;
        $this->tvAlertService = $tvAlertService;
    }

    /**
     * Dispatch notification to a station (Smart TV, Console, or both).
     *
     * @param Device $station
     * @param User $actor
     * @param array $payload
     * @return array
     * @throws TvControlException
     */
    public function dispatchNotification(Device $station, User $actor, array $payload): array
    {
        $userRole = strtolower($actor->role ?? '');
        if (!in_array($userRole, ['admin', 'owner'], true)) {
            throw TvControlException::forbidden('Hanya Admin atau Owner yang berwenang mengirim notifikasi.');
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

        $generation = (int) ($station->session_generation ?? $activeTx->session_generation ?? 0);
        $channelPreference = strtoupper(trim((string) ($payload['channel'] ?? self::CHANNEL_AUTO)));

        $preset = strtoupper(trim((string) ($payload['preset'] ?? $payload['message_preset'] ?? TvAlertEvent::PRESET_REMAINING_10_MIN)));
        if ($preset === '10_MIN_LEFT') {
            $preset = TvAlertEvent::PRESET_REMAINING_10_MIN;
        }

        $presets = TvAlertEvent::getPresetMessages();
        if ($preset === TvAlertEvent::PRESET_CUSTOM) {
            $rawBody = (string) ($payload['custom_message'] ?? $payload['message_body'] ?? $payload['message'] ?? '');
            if (empty(trim($rawBody))) {
                throw TvControlException::invalidInput('Pesan custom tidak boleh kosong.');
            }
            if (mb_strlen(trim($rawBody)) > 200) {
                throw TvControlException::invalidInput('Pesan custom maksimal 200 karakter.');
            }
            $body = trim(strip_tags($rawBody));
            $title = !empty($payload['custom_title'])
                ? trim(strip_tags((string) $payload['custom_title']))
                : 'PESAN DARI KASIR / ADMIN';
        } else {
            if (!isset($presets[$preset])) {
                throw TvControlException::invalidInput("Preset pesan '{$preset}' tidak valid.");
            }
            $title = $presets[$preset]['title'];
            $body = $presets[$preset]['body'];
        }

        $duration = isset($payload['duration_seconds']) ? (int) $payload['duration_seconds'] : 8;
        $duration = max(5, min(15, $duration));

        // Resolve active mappings
        $consoleMapping = $station->activeGameConsole()->with('physicalDevice')->first();
        $consoleDevice = $consoleMapping ? $consoleMapping->physicalDevice : null;

        $tvMapping = $station->activeDisplayTv()->with('physicalDevice')->first();
        $tvDevice = $tvMapping ? $tvMapping->physicalDevice : null;

        $results = [];

        // Route selection
        $sendToConsole = false;
        $sendToTv = false;

        switch ($channelPreference) {
            case self::CHANNEL_CONSOLE:
                if (!$consoleDevice || !$consoleDevice->is_active) {
                    throw TvControlException::invalidInput("Stasiun '{$station->nama}' tidak memiliki Game Console aktif yang terhubung.");
                }
                $sendToConsole = true;
                break;

            case self::CHANNEL_TV:
                if (!$tvDevice || !$tvDevice->is_active) {
                    throw TvControlException::noActiveTvMapping($station->nama);
                }
                $sendToTv = true;
                break;

            case self::CHANNEL_BOTH:
                if ($consoleDevice && $consoleDevice->is_active) {
                    $sendToConsole = true;
                }
                if ($tvDevice && $tvDevice->is_active) {
                    $sendToTv = true;
                }
                if (!$sendToConsole && !$sendToTv) {
                    throw TvControlException::invalidInput("Stasiun '{$station->nama}' tidak memiliki perangkat hardware (TV/Console) aktif yang terhubung.");
                }
                break;

            case self::CHANNEL_AUTO:
            default:
                // Single-channel priority: If console is mapped and active, prioritize console; otherwise fallback to TV
                if ($consoleDevice && $consoleDevice->is_active) {
                    $sendToConsole = true;
                } elseif ($tvDevice && $tvDevice->is_active) {
                    $sendToTv = true;
                } else {
                    throw TvControlException::invalidInput("Stasiun '{$station->nama}' tidak memiliki Game Console atau Smart TV aktif yang terpetakan.");
                }
                break;
        }

        // 1. Dispatch to Console
        if ($sendToConsole && $consoleDevice) {
            $results['console'] = $this->dispatchToConsole(
                $station,
                $activeTx,
                $consoleDevice,
                $consoleMapping,
                $generation,
                $actor,
                $preset,
                $title,
                $body,
                $duration,
                $payload
            );
        }

        // 2. Dispatch to TV
        if ($sendToTv && $tvDevice) {
            $results['tv'] = $this->dispatchToTv(
                $station,
                $activeTx,
                $tvDevice,
                $tvMapping,
                $generation,
                $actor,
                $preset,
                $title,
                $body,
                $duration,
                $payload
            );
        }

        $primaryResult = $results['console'] ?? $results['tv'] ?? null;

        return [
            'success' => $primaryResult ? $primaryResult['success'] : false,
            'channel_used' => $sendToConsole && $sendToTv ? 'BOTH' : ($sendToConsole ? 'CONSOLE' : 'TV'),
            'dispatches' => $results,
            'primary_event' => $primaryResult['event'] ?? null,
            'message' => 'Notifikasi berhasil diproses ke perangkat tujuan.',
        ];
    }

    /**
     * Dispatch notification to Game Console (PS3 HEN or PS4 HEN).
     */
    protected function dispatchToConsole(
        Device $station,
        Transaction $activeTx,
        PhysicalDevice $console,
        StationDeviceMapping $mapping,
        int $generation,
        User $actor,
        string $preset,
        string $title,
        string $body,
        int $duration,
        array $payload
    ): array {
        $driver = $this->driverManager->getDriverForDevice($console);

        $cmdResult = $driver->sendCommand($console, 'SHOW_ALERT', [
            'title' => $title,
            'message' => $body,
            'duration_seconds' => $duration,
        ]);

        $idempotencyKey = !empty($payload['idempotency_key'])
            ? (string) $payload['idempotency_key'] . '_console'
            : 'console_notif_st_' . $station->id . '_tx_' . $activeTx->id_transaksi . '_' . uniqid('', true);

        $status = $cmdResult->success ? TvAlertEvent::STATUS_SENT_TO_SIMULATOR : TvAlertEvent::STATUS_FAILED;
        if ($cmdResult->resultCode === 'UNSUPPORTED_CAPABILITY' || $cmdResult->resultCode === 'NOT_CONFIGURED') {
            $status = TvAlertEvent::STATUS_UNSUPPORTED;
        }

        $event = TvAlertEvent::create([
            'station_id' => $station->id,
            'station_name' => $station->nama,
            'transaction_id' => $activeTx->id_transaksi,
            'physical_device_id' => $console->id,
            'station_device_mapping_id' => $mapping->id,
            'session_generation' => $generation,
            'actor_user_id' => $actor->id,
            'actor_role' => strtoupper($actor->role ?? 'ADMIN'),
            'event_type' => TvAlertEvent::TYPE_MANUAL_ALERT,
            'command' => TvAlertEvent::CMD_SHOW_ALERT,
            'message_preset' => $preset,
            'message_title' => $title,
            'message_body' => $body,
            'display_duration_seconds' => $duration,
            'control_mode' => $cmdResult->simulationMode ? 'MOCK' : 'REAL',
            'driver_type' => $cmdResult->driverType,
            'target_channel' => 'CONSOLE',
            'idempotency_key' => $idempotencyKey,
            'status' => $status,
            'execution_status' => $cmdResult->success ? TvAlertEvent::STATUS_COMMAND_EXECUTED : TvAlertEvent::STATUS_FAILED,
            'result_code' => $cmdResult->resultCode,
            'error_message' => $cmdResult->success ? null : $cmdResult->message,
            'scheduled_at' => Carbon::now(),
            'delivered_at' => $cmdResult->success ? Carbon::now() : null,
            'acked_at' => $cmdResult->success ? Carbon::now() : null,
        ]);

        return [
            'success' => $cmdResult->success,
            'event' => $event,
            'result_code' => $cmdResult->resultCode,
            'driver_type' => $cmdResult->driverType,
            'target_device' => "{$console->name} ({$console->model})",
            'message' => $cmdResult->message,
        ];
    }

    /**
     * Dispatch notification to Samsung Smart TV.
     */
    protected function dispatchToTv(
        Device $station,
        Transaction $activeTx,
        PhysicalDevice $tv,
        StationDeviceMapping $mapping,
        int $generation,
        User $actor,
        string $preset,
        string $title,
        string $body,
        int $duration,
        array $payload
    ): array {
        $event = $this->tvAlertService->sendManualAlert($station, $actor, [
            'preset' => $preset,
            'message_preset' => $preset,
            'custom_message' => $body,
            'custom_title' => $title,
            'duration_seconds' => $duration,
            'idempotency_key' => $payload['idempotency_key'] ?? null,
        ]);

        if (Schema::hasColumn('tv_control_events', 'target_channel')) {
            $event->update(['target_channel' => 'TV']);
        }

        return [
            'success' => ($event->status !== TvAlertEvent::STATUS_FAILED),
            'event' => $event,
            'result_code' => $event->result_code,
            'driver_type' => $event->driver_type,
            'target_device' => "{$tv->name} ({$tv->model})",
            'message' => 'Peringatan TV berhasil dicatat/dikirim.',
        ];
    }
}
