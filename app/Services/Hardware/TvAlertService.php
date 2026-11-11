<?php

namespace App\Services\Hardware;

use App\Exceptions\TvControlException;
use App\Models\Device;
use App\Models\PhysicalDevice;
use App\Models\Playstation;
use App\Models\StationDeviceMapping;
use App\Models\Transaction;
use App\Models\TvAlertEvent;
use App\Models\TvVolumeEnforcementLog;
use App\Models\User;
use App\Services\Hardware\Automation\AutomationIntentService;
use App\Services\Hardware\Drivers\MockSamsungTvDriver;
use App\Services\Hardware\Drivers\SamsungTvDriver;
use App\Services\Hardware\Simulation\HardwareSimulationManager;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class TvAlertService
{
    public const HEARTBEAT_CACHE_PREFIX = 'tv_sim_heartbeat:';
    public const HEARTBEAT_TTL_SECONDS = 30;
    public const AUTO_ALERT_THRESHOLD_SECONDS = 600; // 10 minutes

    protected HardwareDriverManager $driverManager;
    protected HardwareSimulationManager $simulationManager;

    public function __construct(
        ?HardwareDriverManager $driverManager = null,
        ?HardwareSimulationManager $simulationManager = null
    ) {
        $this->simulationManager = $simulationManager ?? app(HardwareSimulationManager::class);
        $this->driverManager = $driverManager ?? app(HardwareDriverManager::class);
    }

    /**
     * Check if tv_control_events schema exists on the active database.
     */
    public static function hasTvControlSchema(): bool
    {
        if (AutomationIntentService::$mockMissingTables !== null || AutomationIntentService::$mockMissingColumns !== null) {
            return false;
        }

        try {
            return Schema::hasTable('tv_control_events');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Generate a deterministic, device-scoped HMAC token per station + mapped physical device.
     */
    public function generateConsumerToken(int $stationId, ?int $physicalDeviceId = null): string
    {
        if ($physicalDeviceId === null) {
            $mapping = StationDeviceMapping::where('station_id', $stationId)
                ->where('mapping_status', StationDeviceMapping::STATUS_ACTIVE)
                ->where('mapping_role', StationDeviceMapping::ROLE_DISPLAY_TV)
                ->first();
            $physicalDeviceId = $mapping ? (int) $mapping->physical_device_id : 0;
        }

        $secret = (string) config('app.key', 'sugeng-rawuh-tv-secret-key');
        return substr(hash_hmac('sha256', "tv_agent:{$stationId}:{$physicalDeviceId}", $secret), 0, 32);
    }

    /**
     * Verify consumer token is valid and strictly bound to the specified station.
     */
    public function verifyConsumerToken(int $stationId, ?string $token): bool
    {
        if ($token === null || trim($token) === '') {
            return false;
        }

        $cleanToken = trim($token);

        // Allow station-scoped dev token ONLY in local/testing when simulation is enabled
        if (
            app()->environment(['local', 'testing'])
            && config('hardware.simulation_enabled', false) === true
        ) {
            if (hash_equals("dev_tv_station_{$stationId}", $cleanToken)) {
                return true;
            }
        }

        // Verify against the station's active Samsung TV mapping
        $mapping = StationDeviceMapping::where('station_id', $stationId)
            ->where('mapping_status', StationDeviceMapping::STATUS_ACTIVE)
            ->where('mapping_role', StationDeviceMapping::ROLE_DISPLAY_TV)
            ->first();

        if ($mapping) {
            $expectedMappedToken = $this->generateConsumerToken($stationId, (int) $mapping->physical_device_id);
            if (hash_equals($expectedMappedToken, $cleanToken)) {
                return true;
            }
        }

        // Fallback check if token was generated with physicalDeviceId = 0 for this exact stationId
        $expectedStationOnlyToken = $this->generateConsumerToken($stationId, 0);
        if (hash_equals($expectedStationOnlyToken, $cleanToken)) {
            return true;
        }

        return false;
    }

    /**
     * Alias for verifyConsumerToken.
     */
    public function verifyConsumerAuth(int $stationId, ?string $token): bool
    {
        return $this->verifyConsumerToken($stationId, $token);
    }

    /**
     * Validate station eligibility, active billing session, and Samsung Smart TV mapping.
     *
     * @param Device $station
     * @return array{transaction: Transaction, mapping: StationDeviceMapping, tv: PhysicalDevice, generation: int}
     * @throws TvControlException
     */
    public function validateStationForTvControl(Device $station): array
    {
        $station->loadMissing(['playstations', 'playstation']);

        // 1. Must be PLAYSTATION category (never BILLIARD)
        if ($station->getStationCategory() !== Playstation::CATEGORY_PLAYSTATION) {
            throw TvControlException::invalidCategory($station->nama);
        }

        // 2. Must be 'Digunakan'
        if ($station->status !== 'Digunakan') {
            throw TvControlException::stationAvailable($station->nama);
        }

        // 3. Must have a valid RUNNING billing session that is not expired
        $activeTx = Transaction::where('device_id', $station->id)
            ->where('billing_status', Transaction::BILLING_RUNNING)
            ->latest('id_transaksi')
            ->first();

        if (!$activeTx) {
            // Fallback for legacy transactions created today without billing_status
            $activeTx = Transaction::where('device_id', $station->id)
                ->whereDate('created_at', Carbon::today())
                ->whereIn('status_transaksi', ['sukses', 'berjalan'])
                ->where(function ($q) {
                    $q->whereNull('billing_status')
                        ->orWhere('billing_status', Transaction::BILLING_RUNNING);
                })
                ->latest('id_transaksi')
                ->first();
        }

        if (!$activeTx) {
            throw TvControlException::noActiveSession($station->nama);
        }

        if ($activeTx->expires_at && Carbon::now()->greaterThanOrEqualTo($activeTx->expires_at)) {
            throw TvControlException::noActiveSession($station->nama);
        }

        // 4. Must have an active DISPLAY_TV mapping with an active Samsung Smart TV
        $mapping = $station->activeDisplayTv()->with('physicalDevice')->first();
        $tv = $mapping ? $mapping->physicalDevice : null;

        if (
            !$mapping
            || !$tv
            || !$tv->is_active
            || $tv->category !== PhysicalDevice::CATEGORY_SMART_TV
            || $tv->brand !== PhysicalDevice::BRAND_SAMSUNG
        ) {
            throw TvControlException::noActiveTvMapping($station->nama);
        }

        $generation = (int) ($station->session_generation ?: ($activeTx->session_generation ?: 1));

        return [
            'transaction' => $activeTx,
            'mapping' => $mapping,
            'tv' => $tv,
            'generation' => $generation,
        ];
    }

    /**
     * Sanitize custom message text safely against XSS, script tags, and control characters.
     *
     * @param string $text
     * @param int $maxLength
     * @return string
     * @throws TvControlException
     */
    public function sanitizeMessageText(string $text, int $maxLength = 200): string
    {
        // Remove <script>...</script> and <style>...</style> blocks including their inner text
        $clean = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', ' ', $text) ?? '';
        $clean = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', ' ', $clean) ?? '';

        // Strip any remaining HTML/XML tags
        $clean = strip_tags($clean);

        // Remove control characters
        $clean = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $clean) ?? '';

        // Collapse excessive whitespace
        $clean = preg_replace('/\s+/u', ' ', $clean) ?? '';
        $clean = trim($clean);

        if ($clean === '') {
            throw TvControlException::invalidInput(
                'Pesan peringatan tidak boleh kosong atau hanya berisi tag HTML/karakter tidak valid.'
            );
        }

        if (mb_strlen($clean) > $maxLength) {
            $clean = mb_substr($clean, 0, $maxLength);
        }

        return $clean;
    }

    /**
     * Send a manual alert to a PlayStation station's Samsung TV.
     *
     * @param Device $station
     * @param User $actor
     * @param array $payload
     * @return TvAlertEvent
     * @throws TvControlException
     */
    public function sendManualAlert(Device $station, User $actor, array $payload): TvAlertEvent
    {
        if (!$actor->isAdmin()) {
            throw TvControlException::forbidden();
        }

        $context = $this->validateStationForTvControl($station);
        /** @var Transaction $activeTx */
        $activeTx = $context['transaction'];
        /** @var StationDeviceMapping $mapping */
        $mapping = $context['mapping'];
        /** @var PhysicalDevice $tv */
        $tv = $context['tv'];
        $generation = $context['generation'];

        $preset = strtoupper(trim((string) ($payload['message_preset'] ?? $payload['preset'] ?? TvAlertEvent::PRESET_REMAINING_10_MIN)));
        if ($preset === '10_MIN_LEFT') {
            $preset = TvAlertEvent::PRESET_REMAINING_10_MIN;
        }
        $presets = TvAlertEvent::getPresetMessages();

        if (!isset($presets[$preset])) {
            throw TvControlException::invalidInput("Preset pesan '{$preset}' tidak valid.");
        }

        if ($preset === TvAlertEvent::PRESET_CUSTOM) {
            $rawCustom = (string) ($payload['custom_message'] ?? $payload['message_body'] ?? '');
            if (mb_strlen(trim($rawCustom)) > 200) {
                throw TvControlException::invalidInput('Pesan custom maksimal 200 karakter.');
            }
            $body = $this->sanitizeMessageText($rawCustom, 200);
            $title = !empty($payload['custom_title'])
                ? $this->sanitizeMessageText((string) $payload['custom_title'], 100)
                : $presets[$preset]['title'];
        } else {
            $title = $presets[$preset]['title'];
            $body = $presets[$preset]['body'];
        }

        $duration = isset($payload['duration_seconds']) ? (int) $payload['duration_seconds'] : 8;
        if ($duration < 5 || $duration > 15) {
            throw TvControlException::invalidInput('Durasi tampilan peringatan harus antara 5 hingga 15 detik.');
        }

        $driver = $this->resolveMockOrConfiguredDriver($tv);
        $cmdResult = $driver->sendCommand($tv, MockSamsungTvDriver::CMD_SHOW_ALERT, [
            'title' => $title,
            'message' => $body,
            'duration_seconds' => $duration,
        ]);

        $state = $cmdResult->simulatedState;
        $idempotencyKey = !empty($payload['idempotency_key'])
            ? (string) $payload['idempotency_key']
            : 'manual_alert_st_' . $station->id . '_tx_' . $activeTx->id_transaksi . '_' . uniqid('', true);

        // IMPORTANT: When mock driver succeeds, status is PENDING until consumer (Tizen/Lab) polls and ACKs!
        $status = $cmdResult->success ? TvAlertEvent::STATUS_PENDING : TvAlertEvent::STATUS_FAILED;
        $resultCode = $cmdResult->success ? 'QUEUED_FOR_CONSUMER' : $cmdResult->resultCode;
        $errorMessage = $cmdResult->success ? null : $cmdResult->message;

        return TvAlertEvent::create([
            'station_id' => $station->id,
            'station_name' => $station->nama,
            'transaction_id' => $activeTx->id_transaksi,
            'physical_device_id' => $tv->id,
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
            'simulated_volume' => isset($state['volume']) ? (int) $state['volume'] : 30,
            'simulated_muted' => isset($state['mute']) ? (bool) $state['mute'] : false,
            'control_mode' => $cmdResult->simulationMode ? 'MOCK' : 'REAL',
            'driver_type' => $cmdResult->driverType,
            'target_channel' => 'TV',
            'idempotency_key' => $idempotencyKey,
            'status' => $status,
            'result_code' => $resultCode,
            'error_message' => $errorMessage,
            'scheduled_at' => Carbon::now(),
        ]);
    }

    /**
     * Send volume/mute control command (VOLUME_DOWN, VOLUME_UP, MUTE_TOGGLE).
     *
     * @param Device $station
     * @param User $actor
     * @param string $command
     * @param array $options
     * @return array
     * @throws TvControlException
     */
    public function sendVolumeCommand(Device $station, User $actor, string $command, array $options = []): array
    {
        if (!$actor->isAdmin()) {
            throw TvControlException::forbidden();
        }

        $context = $this->validateStationForTvControl($station);
        /** @var Transaction $activeTx */
        $activeTx = $context['transaction'];
        /** @var StationDeviceMapping $mapping */
        $mapping = $context['mapping'];
        /** @var PhysicalDevice $tv */
        $tv = $context['tv'];
        $generation = $context['generation'];

        $upperCommand = strtoupper(trim($command));
        $allowedCommands = [
            TvAlertEvent::CMD_VOLUME_DOWN,
            TvAlertEvent::CMD_VOLUME_UP,
            TvAlertEvent::CMD_MUTE_TOGGLE,
            TvAlertEvent::CMD_MUTE,
            TvAlertEvent::CMD_UNMUTE,
            TvAlertEvent::CMD_SET_VOLUME,
        ];

        if (!in_array($upperCommand, $allowedCommands, true)) {
            throw TvControlException::invalidInput(
                "Perintah volume '{$command}' tidak valid. Pilihan: VOLUME_DOWN, VOLUME_UP, MUTE_TOGGLE, MUTE, UNMUTE, SET_VOLUME."
            );
        }

        // Enforce rate-limiting protection per station
        $this->checkVolumeRateLimit($station);

        // Atomic mutual exclusion lock to serialize concurrent hardware commands on the same station
        $lock = Cache::lock("tv_vol_mutex:{$station->id}", 5);
        if (!$lock->get()) {
            throw TvControlException::rateLimited($station->nama);
        }

        try {
            // Enforce soft volume limit if enabled on physical device
            if ($tv && $tv->auto_volume_limit_enabled) {
                $maxLimit = (int) ($tv->max_volume_limit ?? 50);

                if ($upperCommand === TvAlertEvent::CMD_SET_VOLUME) {
                    $requestedTarget = isset($options['target_volume']) ? (int) $options['target_volume'] : (isset($options['volume']) ? (int) $options['volume'] : 30);
                    if ($requestedTarget > $maxLimit) {
                        throw TvControlException::invalidInput("Perintah ditolak: target volume ({$requestedTarget}%) melampaui batas volume maksimum stasiun ({$maxLimit}%).");
                    }
                }

                if ($upperCommand === TvAlertEvent::CMD_VOLUME_UP) {
                    if ($tv->current_volume !== null && $tv->current_volume >= $maxLimit) {
                        throw TvControlException::invalidInput("Perintah ditolak: batas volume maksimum ({$maxLimit}%) telah tercapai pada stasiun ini.");
                    }
                }
            }

            $driverPayload = [];
            if ($upperCommand === TvAlertEvent::CMD_SET_VOLUME) {
                $targetVol = isset($options['target_volume']) ? (int) $options['target_volume'] : (isset($options['volume']) ? (int) $options['volume'] : 30);
                $targetVol = max(0, min(100, $targetVol));
                $driverPayload['target_volume'] = $targetVol;
                $driverPayload['volume'] = $targetVol;
            } else {
                $step = isset($options['step']) ? (int) $options['step'] : (int) config('hardware.tv_control.volume_step', 5);
                $driverPayload['step'] = $step;
            }

            $driver = $this->resolveMockOrConfiguredDriver($tv);
            $cmdResult = $driver->sendCommand($tv, $upperCommand, $driverPayload);

            $isRealMode = ($cmdResult->driverType === SamsungTvDriver::DRIVER_TYPE)
                || (config('hardware.driver') === 'real' && !$cmdResult->simulationMode);

            $state = $cmdResult->simulatedState ?? [];
            $simulatedVolume = isset($state['volume']) ? (int) $state['volume'] : 30;
            $simulatedMuted = isset($state['mute']) ? (bool) $state['mute'] : false;

            $status = $cmdResult->success
                ? ($isRealMode ? TvAlertEvent::STATUS_COMMAND_SENT : TvAlertEvent::STATUS_PENDING)
                : TvAlertEvent::STATUS_FAILED;
            $executionStatus = $cmdResult->success
                ? ($isRealMode ? TvAlertEvent::STATUS_COMMAND_SENT : null)
                : ($cmdResult->resultCode === 'CAPABILITY_UNSUPPORTED' ? TvAlertEvent::STATUS_UNSUPPORTED : TvAlertEvent::STATUS_FAILED);
            $resultCode = $cmdResult->resultCode;
            $errorMessage = $cmdResult->success ? null : $cmdResult->message;

            $messageTitle = $isRealMode
                ? "Kontrol Audio TV ({$upperCommand})"
                : "Simulasi Audio TV ({$upperCommand})";

            $event = TvAlertEvent::create([
                'station_id' => $station->id,
                'station_name' => $station->nama,
                'transaction_id' => $activeTx->id_transaksi,
                'physical_device_id' => $tv->id,
                'station_device_mapping_id' => $mapping->id,
                'session_generation' => $generation,
                'actor_user_id' => $actor->id,
                'actor_role' => strtoupper($actor->role ?? 'ADMIN'),
                'event_type' => TvAlertEvent::TYPE_VOLUME_CONTROL,
                'command' => $upperCommand,
                'message_preset' => null,
                'message_title' => $messageTitle,
                'message_body' => $cmdResult->message,
                'display_duration_seconds' => 3,
                'simulated_volume' => $simulatedVolume,
                'simulated_muted' => $simulatedMuted,
                'control_mode' => $isRealMode ? 'REAL' : 'MOCK',
                'driver_type' => $cmdResult->driverType,
                'idempotency_key' => 'vol_cmd_st_' . $station->id . '_' . strtolower($upperCommand) . '_' . uniqid('', true),
                'status' => $status,
                'execution_status' => $executionStatus,
                'result_code' => $resultCode,
                'error_message' => $errorMessage,
                'scheduled_at' => Carbon::now(),
                'delivered_at' => $isRealMode && $cmdResult->success ? Carbon::now() : null,
                'acked_at' => $isRealMode && $cmdResult->success ? Carbon::now() : null,
            ]);

            if ($cmdResult->success && $tv) {
                if (!$isRealMode) {
                    $tv->update([
                        'current_volume' => $simulatedVolume,
                        'is_muted' => $simulatedMuted,
                        'last_volume_verified_at' => Carbon::now(),
                    ]);
                } else {
                    $telemetry = $cmdResult->telemetry ?? [];
                    if (!empty($telemetry['physical_state_verified'])) {
                        $tv->update([
                            'current_volume' => $telemetry['volume'] ?? $tv->current_volume,
                            'is_muted' => $telemetry['mute'] ?? $tv->is_muted,
                            'last_volume_verified_at' => Carbon::now(),
                        ]);
                    }
                }
            }

            $actualVolume = $isRealMode ? ($tv ? $tv->current_volume : null) : $simulatedVolume;

            return [
                'success' => $cmdResult->success,
                'event' => $event,
                'command_result' => $cmdResult,
                'control_mode' => $isRealMode ? 'REAL' : 'MOCK',
                'execution_status' => $cmdResult->success ? 'COMMAND_SENT' : 'FAILED',
                'volume' => $actualVolume,
                'actual_volume' => $actualVolume,
                'actual_volume_display' => ($actualVolume !== null) ? "{$actualVolume}/100" : 'Tidak tersedia',
                'mute' => $isRealMode ? (bool) ($tv ? $tv->is_muted : false) : $simulatedMuted,
                'simulated_state' => $isRealMode ? null : [
                    'volume' => $simulatedVolume,
                    'mute' => $simulatedMuted,
                    'power' => (string) ($state['power'] ?? 'ON'),
                    'simulated' => true,
                ],
                'message' => $cmdResult->message,
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * Alias for sendVolumeCommand.
     */
    public function controlVolume(Device $station, User $actor, string $command, array $options = []): array
    {
        return $this->sendVolumeCommand($station, $actor, $command, $options);
    }

    /**
     * Update Maximum Volume Settings for a station's mapped Samsung Smart TV.
     * Accessible by Admin or Owner.
     *
     * @param Device $station
     * @param User $actor
     * @param array $payload
     * @return array
     * @throws TvControlException
     */
    public function updateVolumeLimitSettings(Device $station, User $actor, array $payload): array
    {
        $userRole = strtolower($actor->role ?? '');
        if (!in_array($userRole, ['admin', 'owner'], true)) {
            throw TvControlException::forbidden('Hanya Admin atau Owner yang berwenang mengubah batas volume TV.');
        }

        $station->loadMissing(['playstations', 'playstation']);
        if ($station->getStationCategory() !== Playstation::CATEGORY_PLAYSTATION) {
            throw TvControlException::invalidCategory($station->nama);
        }

        $mapping = $station->activeDisplayTv()->with('physicalDevice')->first();
        $tv = $mapping ? $mapping->physicalDevice : null;

        if (
            !$mapping
            || !$tv
            || !$tv->is_active
            || $tv->category !== PhysicalDevice::CATEGORY_SMART_TV
            || $tv->brand !== PhysicalDevice::BRAND_SAMSUNG
        ) {
            throw TvControlException::noActiveTvMapping($station->nama);
        }

        $enabled = isset($payload['enabled'])
            ? filter_var($payload['enabled'], FILTER_VALIDATE_BOOLEAN)
            : (isset($payload['auto_volume_limit_enabled']) ? filter_var($payload['auto_volume_limit_enabled'], FILTER_VALIDATE_BOOLEAN) : false);

        $maxVolume = isset($payload['max_volume'])
            ? (int) $payload['max_volume']
            : (isset($payload['max_volume_limit']) ? (int) $payload['max_volume_limit'] : 50);
        $maxVolume = max(0, min(100, $maxVolume));

        $tv->update([
            'auto_volume_limit_enabled' => $enabled,
            'max_volume_limit' => $maxVolume,
            'auto_volume_limit_updated_by_user_id' => $actor->id,
        ]);

        $enforceNow = !empty($payload['enforce_now']);
        $enforcedTriggered = false;

        if ($enforceNow) {
            $currentVol = $tv->current_volume;
            if ($currentVol === null) {
                $tvState = $this->getStationSimulatedTvState($station);
                $currentVol = isset($tvState['volume']) ? (int) $tvState['volume'] : null;
            }

            if ($currentVol !== null && $currentVol > $maxVolume) {
                // If station is currently in use, dispatch real/simulated SET_VOLUME command
                if ($station->status === 'Digunakan') {
                    try {
                        $this->sendVolumeCommand($station, $actor, TvAlertEvent::CMD_SET_VOLUME, ['target_volume' => $maxVolume]);
                    } catch (\Throwable $e) {
                        // Non-blocking fallback
                    }
                } else {
                    $tv->update([
                        'current_volume' => $maxVolume,
                        'volume_enforcement_status' => 'ENFORCED',
                        'last_volume_verified_at' => Carbon::now(),
                    ]);
                }

                $this->recordVolumeEnforcement($station, [
                    'previous_volume' => $currentVol,
                    'enforced_volume' => $maxVolume,
                    'max_limit' => $maxVolume,
                    'enforcement_source' => TvVolumeEnforcementLog::SOURCE_ADMIN_ENFORCE,
                    'status' => TvVolumeEnforcementLog::STATUS_ENFORCED,
                    'details' => "Volume otomatis diturunkan dari {$currentVol} ke {$maxVolume} via Enforce Now (Admin/Owner).",
                ]);

                $enforcedTriggered = true;
            }
        }

        $tv->refresh();

        return [
            'station_id' => $station->id,
            'station_name' => $station->nama,
            'physical_device_id' => $tv->id,
            'auto_volume_limit_enabled' => (bool) $tv->auto_volume_limit_enabled,
            'max_volume_limit' => (int) $tv->max_volume_limit,
            'current_volume' => $tv->current_volume !== null ? (int) $tv->current_volume : null,
            'is_muted' => (bool) $tv->is_muted,
            'volume_enforcement_status' => (string) ($tv->volume_enforcement_status ?? 'WITHIN_LIMIT'),
            'enforced' => $enforcedTriggered,
            'message' => "Batas volume TV untuk {$station->nama} berhasil diatur ke {$maxVolume}% (" . ($enabled ? 'Aktif' : 'Nonaktif') . ').',
        ];
    }

    /**
     * Record a volume enforcement log event and update physical device status.
     *
     * @param Device $station
     * @param array $payload
     * @return TvVolumeEnforcementLog
     */
    public function recordVolumeEnforcement(Device $station, array $payload): TvVolumeEnforcementLog
    {
        $mapping = $station->activeDisplayTv()->with('physicalDevice')->first();
        $tv = $mapping ? $mapping->physicalDevice : null;
        $physicalDeviceId = $tv ? (int) $tv->id : (int) ($payload['physical_device_id'] ?? 0);

        $previousVolume = (int) ($payload['previous_volume'] ?? 0);
        $enforcedVolume = (int) ($payload['enforced_volume'] ?? 0);
        $maxLimit = (int) ($payload['max_limit'] ?? ($tv ? $tv->max_volume_limit : 50));
        $enforcementSource = (string) ($payload['enforcement_source'] ?? TvVolumeEnforcementLog::SOURCE_TIZEN_AGENT);
        $status = (string) ($payload['status'] ?? TvVolumeEnforcementLog::STATUS_ENFORCED);
        $details = isset($payload['details']) ? (string) $payload['details'] : null;

        $log = TvVolumeEnforcementLog::create([
            'station_id' => $station->id,
            'physical_device_id' => $physicalDeviceId,
            'previous_volume' => $previousVolume,
            'enforced_volume' => $enforcedVolume,
            'max_limit' => $maxLimit,
            'enforcement_source' => $enforcementSource,
            'status' => $status,
            'details' => $details,
        ]);

        if ($tv) {
            $tv->update([
                'volume_enforcement_status' => $status === TvVolumeEnforcementLog::STATUS_ENFORCED ? 'ENFORCED' : 'WITHIN_LIMIT',
                'current_volume' => $enforcedVolume,
                'last_volume_verified_at' => Carbon::now(),
            ]);
        }

        return $log;
    }

    /**
     * Evaluate active PlayStation prepaid/custom_package sessions for the automatic 10-minute alert,
     * and sweep any stale/expired/cancelled pending alerts.
     */
    public function evaluateAutoAlerts(?Carbon $now = null): array
    {
        $now = $now ? $now->copy() : Carbon::now();

        if (!self::hasTvControlSchema()) {
            return [
                'evaluated_at' => $now->toIso8601String(),
                'created_count' => 0,
                'created_events' => [],
                'expired_count' => 0,
                'cancelled_count' => 0,
                'stale_count' => 0,
            ];
        }

        $expiredCount = 0;
        $cancelledCount = 0;
        $staleCount = 0;

        // 1. Sweep existing PENDING events whose session/station state is no longer valid
        $pendingEvents = TvAlertEvent::where('status', TvAlertEvent::STATUS_PENDING)
            ->with(['station', 'transaction'])
            ->get();

        foreach ($pendingEvents as $pendingEvent) {
            $station = $pendingEvent->station;
            $tx = $pendingEvent->transaction;

            if ($station && (int) $pendingEvent->session_generation !== (int) ($station->session_generation ?: 1)) {
                $pendingEvent->update([
                    'status' => TvAlertEvent::STATUS_STALE,
                    'error_message' => 'Session generation mismatch',
                    'cancelled_at' => $now,
                ]);
                $staleCount++;
                continue;
            }

            if ($tx) {
                if (
                    $pendingEvent->event_type !== TvAlertEvent::TYPE_BILLING_EXPIRED
                    && (
                        $tx->billing_status === Transaction::BILLING_EXPIRED_PENDING_ACTION
                        || ($tx->expires_at && $now->greaterThanOrEqualTo($tx->expires_at))
                    )
                ) {
                    $pendingEvent->update([
                        'status' => TvAlertEvent::STATUS_EXPIRED,
                        'error_message' => 'Session expired',
                        'cancelled_at' => $now,
                    ]);
                    $expiredCount++;
                    continue;
                }

                if (
                    $pendingEvent->event_type === TvAlertEvent::TYPE_BILLING_EXPIRED
                    && $tx->expires_at
                    && $now->lessThan($tx->expires_at)
                ) {
                    $pendingEvent->update([
                        'status' => TvAlertEvent::STATUS_CANCELLED,
                        'error_message' => 'Session extended after expiration',
                        'cancelled_at' => $now,
                    ]);
                    $cancelledCount++;
                    continue;
                }

                if (
                    in_array($tx->billing_status, [Transaction::BILLING_STOPPED_EARLY, Transaction::BILLING_COMPLETED], true)
                    || $tx->status_transaksi === 'selesai'
                ) {
                    $pendingEvent->update([
                        'status' => TvAlertEvent::STATUS_CANCELLED,
                        'error_message' => 'Session ended',
                        'cancelled_at' => $now,
                    ]);
                    $cancelledCount++;
                    continue;
                }

                if (
                    $pendingEvent->event_type === TvAlertEvent::TYPE_BILLING_ENDING_SOON
                    && $tx->expires_at
                    && $now->diffInSeconds($tx->expires_at, false) > self::AUTO_ALERT_THRESHOLD_SECONDS
                ) {
                    $pendingEvent->update([
                        'status' => TvAlertEvent::STATUS_CANCELLED,
                        'error_message' => 'Session extended beyond 10m threshold',
                        'cancelled_at' => $now,
                    ]);
                    $cancelledCount++;
                    continue;
                }
            }

            if ($station && $station->status !== 'Digunakan') {
                $pendingEvent->update([
                    'status' => TvAlertEvent::STATUS_CANCELLED,
                    'error_message' => 'Station no longer in use',
                    'cancelled_at' => $now,
                ]);
                $cancelledCount++;
            }
        }

        // 2. Evaluate active prepaid / custom_package sessions for 10-minute remaining alert
        $runningSessions = Transaction::where('billing_status', Transaction::BILLING_RUNNING)
            ->whereIn('tipe_transaksi', ['prepaid', 'custom_package'])
            ->whereNotNull('expires_at')
            ->with(['device.playstations', 'device.playstation'])
            ->get();

        $createdEvents = [];

        foreach ($runningSessions as $tx) {
            $station = $tx->device;
            if (!$station || $station->status !== 'Digunakan') {
                continue;
            }

            if ($station->getStationCategory() !== Playstation::CATEGORY_PLAYSTATION) {
                continue;
            }

            // 1. Check if station has active Game Console mapping (PS3/PS4 HEN)
            $consoleMapping = $station->activeGameConsole()->with('physicalDevice')->first();
            $consoleDevice = ($consoleMapping && $consoleMapping->physicalDevice && $consoleMapping->physicalDevice->is_active)
                ? $consoleMapping->physicalDevice
                : null;

            // 2. Check if station has active Samsung Smart TV mapping
            $mapping = $station->activeDisplayTv()->with('physicalDevice')->first();
            $tv = ($mapping && $mapping->physicalDevice && $mapping->physicalDevice->is_active)
                ? $mapping->physicalDevice
                : null;

            if (!$consoleDevice && (!$tv || $tv->category !== PhysicalDevice::CATEGORY_SMART_TV || $tv->brand !== PhysicalDevice::BRAND_SAMSUNG)) {
                continue;
            }

            $remainingSeconds = $now->diffInSeconds($tx->expires_at, false);

            if ($remainingSeconds <= 0) {
                // Already expired — mark any pending alerts for this transaction (except TYPE_BILLING_EXPIRED) as EXPIRED
                $expiredCount += $this->cancelPendingAlertsForTransaction($tx, TvAlertEvent::STATUS_EXPIRED, 'Session expired');

                $generation = (int) ($station->session_generation ?: ($tx->session_generation ?: 1));
                $idempotencyKey = "auto_alert_expired_tx_{$tx->id_transaksi}_gen_{$generation}";

                if (TvAlertEvent::where('idempotency_key', $idempotencyKey)->exists()) {
                    continue;
                }

                $presets = TvAlertEvent::getPresetMessages();
                $expiredPreset = $presets[TvAlertEvent::PRESET_TIME_EXPIRED] ?? [
                    'title' => 'WAKTU BERMAIN TELAH HABIS!',
                    'body' => 'Waktu bermain telah habis. Silakan hubungi kasir.',
                ];
                $title = $expiredPreset['title'];
                $body = $expiredPreset['body'];

                try {
                    // Single-channel rule: prioritize Console if mapped, otherwise TV
                    if ($consoleDevice) {
                        $driver = $this->driverManager->getDriverForDevice($consoleDevice);
                        $cmdResult = $driver->sendCommand($consoleDevice, 'SHOW_ALERT', [
                            'title' => $title,
                            'message' => $body,
                            'duration_seconds' => 8,
                        ]);

                        $isPs4Outbound = (strpos(strtoupper((string) $consoleDevice->model), 'PS4') !== false || strpos(strtoupper((string) $consoleDevice->driver_type), 'PS4') !== false);

                        $status = TvAlertEvent::STATUS_FAILED;
                        if ($cmdResult->success) {
                            if ($isPs4Outbound) {
                                $status = TvAlertEvent::STATUS_PENDING;
                            } elseif ($cmdResult->simulationMode && $cmdResult->resultCode !== 'HTTP_ACCEPTED' && $cmdResult->resultCode !== 'ACCEPTED') {
                                $status = TvAlertEvent::STATUS_SENT_TO_SIMULATOR;
                            } else {
                                $status = TvAlertEvent::STATUS_ACCEPTED;
                            }
                        }

                        $event = TvAlertEvent::create([
                            'station_id' => $station->id,
                            'station_name' => $station->nama,
                            'transaction_id' => $tx->id_transaksi,
                            'physical_device_id' => $consoleDevice->id,
                            'station_device_mapping_id' => $consoleMapping->id,
                            'session_generation' => $generation,
                            'actor_user_id' => null,
                            'actor_role' => 'SYSTEM',
                            'event_type' => TvAlertEvent::TYPE_BILLING_EXPIRED,
                            'command' => TvAlertEvent::CMD_SHOW_ALERT,
                            'message_preset' => TvAlertEvent::PRESET_TIME_EXPIRED,
                            'message_title' => $title,
                            'message_body' => $body,
                            'display_duration_seconds' => 8,
                            'simulated_volume' => 30,
                            'simulated_muted' => false,
                            'control_mode' => $cmdResult->simulationMode ? 'MOCK' : 'REAL',
                            'driver_type' => $cmdResult->driverType,
                            'target_channel' => 'CONSOLE',
                            'idempotency_key' => $idempotencyKey,
                            'status' => $status,
                            'execution_status' => $cmdResult->success ? TvAlertEvent::STATUS_COMMAND_EXECUTED : TvAlertEvent::STATUS_FAILED,
                            'result_code' => $cmdResult->resultCode,
                            'error_message' => $cmdResult->success ? null : $cmdResult->message,
                            'scheduled_at' => $now,
                            'delivered_at' => ($status === TvAlertEvent::STATUS_ACCEPTED || $cmdResult->success) ? $now : null,
                            'acked_at' => ($status === TvAlertEvent::STATUS_ACCEPTED || $cmdResult->success) ? $now : null,
                        ]);
                    } else {
                        $state = $this->simulationManager->getSimulatedState($tv->id, [
                            'volume' => (int) config('hardware.tv_control.default_volume', 30),
                            'mute' => false,
                            'power' => 'ON',
                        ]);

                        $event = TvAlertEvent::create([
                            'station_id' => $station->id,
                            'station_name' => $station->nama,
                            'transaction_id' => $tx->id_transaksi,
                            'physical_device_id' => $tv->id,
                            'station_device_mapping_id' => $mapping->id,
                            'session_generation' => $generation,
                            'actor_user_id' => null,
                            'actor_role' => 'SYSTEM',
                            'event_type' => TvAlertEvent::TYPE_BILLING_EXPIRED,
                            'command' => TvAlertEvent::CMD_SHOW_ALERT,
                            'message_preset' => TvAlertEvent::PRESET_TIME_EXPIRED,
                            'message_title' => $title,
                            'message_body' => $body,
                            'display_duration_seconds' => 8,
                            'simulated_volume' => isset($state['volume']) ? (int) $state['volume'] : 30,
                            'simulated_muted' => isset($state['mute']) ? (bool) $state['mute'] : false,
                            'control_mode' => 'MOCK',
                            'driver_type' => MockSamsungTvDriver::DRIVER_TYPE,
                            'target_channel' => 'TV',
                            'idempotency_key' => $idempotencyKey,
                            'status' => TvAlertEvent::STATUS_PENDING,
                            'result_code' => 'QUEUED_FOR_CONSUMER',
                            'error_message' => null,
                            'scheduled_at' => $now,
                        ]);
                    }

                    $createdEvents[] = $event;
                } catch (\Illuminate\Database\QueryException $e) {
                    // Concurrent duplicate idempotency_key race condition: skip cleanly
                }
                continue;
            }

            if ($remainingSeconds <= self::AUTO_ALERT_THRESHOLD_SECONDS) {
                $generation = (int) ($station->session_generation ?: ($tx->session_generation ?: 1));
                $idempotencyKey = "auto_alert_10m_tx_{$tx->id_transaksi}_gen_{$generation}";

                if (TvAlertEvent::where('idempotency_key', $idempotencyKey)->exists()) {
                    continue;
                }

                $remainingMinutes = max(1, (int) ceil($remainingSeconds / 60));
                $title = 'WAKTU BERMAIN TERSISA 10 MENIT!';
                $body = "Sisa waktu bermain sekitar {$remainingMinutes} menit. Silakan hubungi kasir untuk memperpanjang waktu bermain.";

                try {
                    // Single-channel rule: prioritize Console if mapped, otherwise TV
                    if ($consoleDevice) {
                        $driver = $this->driverManager->getDriverForDevice($consoleDevice);
                        $cmdResult = $driver->sendCommand($consoleDevice, 'SHOW_ALERT', [
                            'title' => $title,
                            'message' => $body,
                            'duration_seconds' => 8,
                        ]);

                        $event = TvAlertEvent::create([
                            'station_id' => $station->id,
                            'station_name' => $station->nama,
                            'transaction_id' => $tx->id_transaksi,
                            'physical_device_id' => $consoleDevice->id,
                            'station_device_mapping_id' => $consoleMapping->id,
                            'session_generation' => $generation,
                            'actor_user_id' => null,
                            'actor_role' => 'SYSTEM',
                            'event_type' => TvAlertEvent::TYPE_BILLING_ENDING_SOON,
                            'command' => TvAlertEvent::CMD_SHOW_ALERT,
                            'message_preset' => TvAlertEvent::PRESET_REMAINING_10_MIN,
                            'message_title' => $title,
                            'message_body' => $body,
                            'display_duration_seconds' => 8,
                            'simulated_volume' => 30,
                            'simulated_muted' => false,
                            'control_mode' => $cmdResult->simulationMode ? 'MOCK' : 'REAL',
                            'driver_type' => $cmdResult->driverType,
                            'target_channel' => 'CONSOLE',
                            'idempotency_key' => $idempotencyKey,
                            'status' => $cmdResult->success ? TvAlertEvent::STATUS_SENT_TO_SIMULATOR : TvAlertEvent::STATUS_FAILED,
                            'execution_status' => $cmdResult->success ? TvAlertEvent::STATUS_COMMAND_EXECUTED : TvAlertEvent::STATUS_FAILED,
                            'result_code' => $cmdResult->resultCode,
                            'error_message' => $cmdResult->success ? null : $cmdResult->message,
                            'scheduled_at' => $now,
                            'delivered_at' => $cmdResult->success ? $now : null,
                            'acked_at' => $cmdResult->success ? $now : null,
                        ]);
                    } else {
                        $state = $this->simulationManager->getSimulatedState($tv->id, [
                            'volume' => (int) config('hardware.tv_control.default_volume', 30),
                            'mute' => false,
                            'power' => 'ON',
                        ]);

                        $event = TvAlertEvent::create([
                            'station_id' => $station->id,
                            'station_name' => $station->nama,
                            'transaction_id' => $tx->id_transaksi,
                            'physical_device_id' => $tv->id,
                            'station_device_mapping_id' => $mapping->id,
                            'session_generation' => $generation,
                            'actor_user_id' => null,
                            'actor_role' => 'SYSTEM',
                            'event_type' => TvAlertEvent::TYPE_BILLING_ENDING_SOON,
                            'command' => TvAlertEvent::CMD_SHOW_ALERT,
                            'message_preset' => TvAlertEvent::PRESET_REMAINING_10_MIN,
                            'message_title' => $title,
                            'message_body' => $body,
                            'display_duration_seconds' => 8,
                            'simulated_volume' => isset($state['volume']) ? (int) $state['volume'] : 30,
                            'simulated_muted' => isset($state['mute']) ? (bool) $state['mute'] : false,
                            'control_mode' => 'MOCK',
                            'driver_type' => MockSamsungTvDriver::DRIVER_TYPE,
                            'target_channel' => 'TV',
                            'idempotency_key' => $idempotencyKey,
                            'status' => TvAlertEvent::STATUS_PENDING,
                            'result_code' => 'QUEUED_FOR_CONSUMER',
                            'error_message' => null,
                            'scheduled_at' => $now,
                        ]);
                    }

                    $createdEvents[] = $event;
                } catch (\Illuminate\Database\QueryException $e) {
                    // Concurrent duplicate idempotency_key race condition: skip cleanly
                }
            }
        }

        return [
            'evaluated_at' => $now->toIso8601String(),
            'created_count' => count($createdEvents),
            'created_events' => $createdEvents,
            'expired_count' => $expiredCount,
            'cancelled_count' => $cancelledCount,
            'stale_count' => $staleCount,
        ];
    }

    /**
     * Alias for evaluateAutoAlerts.
     */
    public function evaluateAutoTenMinuteAlerts(?Carbon $now = null): array
    {
        return $this->evaluateAutoAlerts($now);
    }

    /**
     * Cancel or expire all PENDING TV alert events for a transaction.
     */
    public function cancelPendingAlertsForTransaction(
        Transaction $tx,
        string $newStatus = TvAlertEvent::STATUS_CANCELLED,
        ?string $reason = null
    ): int {
        if (!self::hasTvControlSchema()) {
            return 0;
        }

        $query = TvAlertEvent::where('transaction_id', $tx->id_transaksi)
            ->where('status', TvAlertEvent::STATUS_PENDING);

        if ($newStatus === TvAlertEvent::STATUS_EXPIRED) {
            $query->where('event_type', '!=', TvAlertEvent::TYPE_BILLING_EXPIRED);
        }

        return $query->update([
            'status' => $newStatus,
            'error_message' => $reason ?? "Transitioned to {$newStatus}",
            'cancelled_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    /**
     * Mark any PENDING alerts on a station with older session_generation as STALE.
     */
    public function markStalePendingAlertsForStation(Device $station, string $reason = 'Session generation mismatch'): int
    {
        if (!self::hasTvControlSchema()) {
            return 0;
        }

        $currentGen = (int) ($station->session_generation ?: 1);

        return TvAlertEvent::where('station_id', $station->id)
            ->where('status', TvAlertEvent::STATUS_PENDING)
            ->where('session_generation', '!=', $currentGen)
            ->update([
                'status' => TvAlertEvent::STATUS_STALE,
                'error_message' => $reason,
                'cancelled_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
    }

    /**
     * Poll valid PENDING events for a consumer (Tizen app or /admin/tv-lab simulator).
     * Reconciles stale/expired/cancelled events before returning.
     * Does NOT mark events as DELIVERED_TO_APP yet — only sets delivered_at timestamp!
     */
    public function pollEventsForConsumer(Device $station, ?Carbon $now = null): array
    {
        $now = $now ? $now->copy() : Carbon::now();
        $station->refresh();

        // Record consumer heartbeat
        Cache::put(self::HEARTBEAT_CACHE_PREFIX . $station->id, $now->toIso8601String(), self::HEARTBEAT_TTL_SECONDS);

        $mapping = StationDeviceMapping::where('station_id', $station->id)
            ->where('mapping_status', StationDeviceMapping::STATUS_ACTIVE)
            ->where('mapping_role', StationDeviceMapping::ROLE_DISPLAY_TV)
            ->with('physicalDevice')
            ->first();
        $tv = $mapping ? $mapping->physicalDevice : null;

        $volumeLimitConfig = [
            'enabled' => $tv ? (bool) $tv->auto_volume_limit_enabled : false,
            'max_limit' => $tv ? (int) ($tv->max_volume_limit ?? 50) : 50,
            'current_volume' => $tv ? ($tv->current_volume !== null ? (int) $tv->current_volume : null) : null,
            'is_muted' => $tv ? (bool) $tv->is_muted : false,
            'last_verified_at' => $tv && $tv->last_volume_verified_at ? $tv->last_volume_verified_at->toIso8601String() : null,
            'enforcement_status' => $tv ? (string) ($tv->volume_enforcement_status ?? 'WITHIN_LIMIT') : 'WITHIN_LIMIT',
        ];

        if (!self::hasTvControlSchema()) {
            return [
                'station_id' => $station->id,
                'station_name' => $station->nama,
                'station_status' => $station->status,
                'session_generation' => (int) ($station->session_generation ?: 1),
                'events' => collect(),
                'tv_state' => $this->getStationSimulatedTvState($station),
                'volume_limit_config' => $volumeLimitConfig,
                'polled_at' => $now->toIso8601String(),
            ];
        }

        $pendingEvents = TvAlertEvent::where('station_id', $station->id)
            ->where('status', TvAlertEvent::STATUS_PENDING)
            ->orderBy('id', 'asc')
            ->get();

        $currentStationGen = (int) ($station->session_generation ?: 1);

        foreach ($pendingEvents as $event) {
            // 1. Check session_generation fencing first
            if ((int) $event->session_generation !== $currentStationGen) {
                $event->update([
                    'status' => TvAlertEvent::STATUS_STALE,
                    'error_message' => 'Session generation mismatch',
                    'cancelled_at' => $now,
                ]);
                continue;
            }

            // 2. Check transaction validity if linked
            if ($event->transaction_id) {
                $tx = Transaction::find($event->transaction_id);
                if (!$tx) {
                    $event->update([
                        'status' => TvAlertEvent::STATUS_CANCELLED,
                        'error_message' => 'Transaction not found',
                        'cancelled_at' => $now,
                    ]);
                    continue;
                }

                if (
                    $tx->billing_status === Transaction::BILLING_EXPIRED_PENDING_ACTION
                    || ($tx->expires_at && $now->greaterThanOrEqualTo($tx->expires_at))
                ) {
                    $event->update([
                        'status' => TvAlertEvent::STATUS_EXPIRED,
                        'error_message' => 'Session expired',
                        'cancelled_at' => $now,
                    ]);
                    continue;
                }

                if ($tx->billing_status !== Transaction::BILLING_RUNNING) {
                    $event->update([
                        'status' => TvAlertEvent::STATUS_CANCELLED,
                        'error_message' => 'Session is no longer running',
                        'cancelled_at' => $now,
                    ]);
                    continue;
                }

                if (
                    $event->event_type === TvAlertEvent::TYPE_BILLING_ENDING_SOON
                    && $tx->expires_at
                    && $now->diffInSeconds($tx->expires_at, false) > self::AUTO_ALERT_THRESHOLD_SECONDS
                ) {
                    $event->update([
                        'status' => TvAlertEvent::STATUS_CANCELLED,
                        'error_message' => 'Session extended beyond 10m threshold',
                        'cancelled_at' => $now,
                    ]);
                    continue;
                }
            }

            // 3. Check station status
            if ($station->status !== 'Digunakan') {
                $event->update([
                    'status' => TvAlertEvent::STATUS_CANCELLED,
                    'error_message' => 'Station is not in use',
                    'cancelled_at' => $now,
                ]);
                continue;
            }
        }

        // Fetch remaining valid PENDING events
        $validEvents = TvAlertEvent::where('station_id', $station->id)
            ->where('status', TvAlertEvent::STATUS_PENDING)
            ->orderBy('id', 'asc')
            ->get();

        foreach ($validEvents as $validEvent) {
            if ($validEvent->delivered_at === null) {
                $validEvent->update(['delivered_at' => $now]);
            }
        }

        return [
            'station_id' => $station->id,
            'station_name' => $station->nama,
            'station_status' => $station->status,
            'session_generation' => $currentStationGen,
            'events' => $validEvents,
            'tv_state' => $this->getStationSimulatedTvState($station),
            'volume_limit_config' => $volumeLimitConfig,
            'polled_at' => $now->toIso8601String(),
        ];
    }

    /**
     * Alias for pollEventsForConsumer.
     */
    public function pollPendingEventsForStation(Device $station, ?Carbon $now = null): array
    {
        return $this->pollEventsForConsumer($station, $now);
    }

    /**
     * Acknowledge an event from the consumer (Tizen app or simulator) after rendering.
     * Transitions status to DELIVERED_TO_APP with result_code ACKED_BY_SIMULATOR.
     *
     * @param TvAlertEvent $event
     * @param Device $consumerStation
     * @param array $ackPayload
     * @param Carbon|null $now
     * @return TvAlertEvent
     * @throws TvControlException
     */
    public function acknowledgeEvent(
        TvAlertEvent $event,
        Device $consumerStation,
        array $ackPayload = [],
        ?Carbon $now = null
    ): TvAlertEvent {
        $now = $now ? $now->copy() : Carbon::now();

        if ((int) $event->station_id !== (int) $consumerStation->id) {
            throw TvControlException::forbidden('Consumer tidak memiliki otorisasi untuk event station lain.');
        }

        $event->refresh();
        $consumerStation->refresh();

        // Record consumer heartbeat
        Cache::put(self::HEARTBEAT_CACHE_PREFIX . $consumerStation->id, $now->toIso8601String(), self::HEARTBEAT_TTL_SECONDS);

        $rawExecutionStatus = $ackPayload['execution_status'] ?? null;
        $validExecutionStatuses = [
            TvAlertEvent::STATUS_COMMAND_RECEIVED,
            TvAlertEvent::STATUS_COMMAND_EXECUTED,
            TvAlertEvent::STATUS_STATE_VERIFIED,
            TvAlertEvent::STATUS_UNSUPPORTED,
            TvAlertEvent::STATUS_FAILED,
        ];

        if ($rawExecutionStatus !== null && in_array(strtoupper(trim((string) $rawExecutionStatus)), $validExecutionStatuses, true)) {
            $executionStatus = strtoupper(trim((string) $rawExecutionStatus));
        } else {
            $executionStatus = TvAlertEvent::STATUS_COMMAND_EXECUTED;
        }

        $realVolume = (isset($ackPayload['real_volume']) && is_numeric($ackPayload['real_volume']))
            ? max(0, min(100, (int) $ackPayload['real_volume']))
            : null;

        $realMuted = isset($ackPayload['real_muted'])
            ? filter_var($ackPayload['real_muted'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;

        $hardwareErrorCode = !empty($ackPayload['hardware_error_code'])
            ? substr((string) $ackPayload['hardware_error_code'], 0, 64)
            : null;

        $hardwareErrorMessage = !empty($ackPayload['hardware_error_message'])
            ? (string) $ackPayload['hardware_error_message']
            : null;

        // Idempotent success or progressive state update if already delivered/acked
        if (in_array($event->status, [TvAlertEvent::STATUS_DELIVERED_TO_APP, TvAlertEvent::STATUS_ACKED_BY_SIMULATOR], true)) {
            $updateData = [];
            if ($rawExecutionStatus !== null) {
                $updateData['execution_status'] = $executionStatus;
                if ($executionStatus === TvAlertEvent::STATUS_FAILED) {
                    $updateData['status'] = TvAlertEvent::STATUS_FAILED;
                    $updateData['result_code'] = $hardwareErrorCode ?? 'FAILED';
                } elseif ($executionStatus === TvAlertEvent::STATUS_UNSUPPORTED) {
                    $updateData['result_code'] = 'UNSUPPORTED_ON_TARGET';
                }
            }
            if ($realVolume !== null) {
                $updateData['real_volume'] = $realVolume;
            }
            if ($realMuted !== null) {
                $updateData['real_muted'] = $realMuted;
            }
            if ($hardwareErrorCode !== null) {
                $updateData['hardware_error_code'] = $hardwareErrorCode;
            }
            if ($hardwareErrorMessage !== null) {
                $updateData['hardware_error_message'] = $hardwareErrorMessage;
            }
            if (!empty($updateData)) {
                $event->update($updateData);
            }
            return $event->fresh();
        }

        // Reject if already terminal
        if (in_array($event->status, [
            TvAlertEvent::STATUS_CANCELLED,
            TvAlertEvent::STATUS_STALE,
            TvAlertEvent::STATUS_EXPIRED,
            TvAlertEvent::STATUS_FAILED,
            TvAlertEvent::STATUS_REJECTED,
        ], true)) {
            throw TvControlException::conflict("Event sudah tidak berlaku ({$event->status}).");
        }

        // Check session_generation fencing
        $currentStationGen = (int) ($consumerStation->session_generation ?: 1);
        if ((int) $event->session_generation !== $currentStationGen) {
            $event->update([
                'status' => TvAlertEvent::STATUS_STALE,
                'error_message' => 'Session generation mismatch',
                'cancelled_at' => $now,
            ]);
            throw TvControlException::conflict('Sesi telah berganti (generation mismatch). ACK ditolak.');
        }

        // Check transaction validity
        if ($event->transaction_id) {
            $tx = Transaction::find($event->transaction_id);
            if (
                $tx
                && (
                    $tx->billing_status === Transaction::BILLING_EXPIRED_PENDING_ACTION
                    || ($tx->expires_at && $now->greaterThanOrEqualTo($tx->expires_at))
                )
            ) {
                $event->update([
                    'status' => TvAlertEvent::STATUS_EXPIRED,
                    'error_message' => 'Session expired',
                    'cancelled_at' => $now,
                ]);
                throw TvControlException::conflict('Sesi billing telah kedaluwarsa. ACK ditolak.');
            }

            if (!$tx || $tx->billing_status !== Transaction::BILLING_RUNNING) {
                $event->update([
                    'status' => TvAlertEvent::STATUS_CANCELLED,
                    'error_message' => 'Session is no longer running',
                    'cancelled_at' => $now,
                ]);
                throw TvControlException::conflict('Sesi billing sudah berakhir. ACK ditolak.');
            }
        }

        if ($consumerStation->status !== 'Digunakan') {
            $event->update([
                'status' => TvAlertEvent::STATUS_CANCELLED,
                'error_message' => 'Station is not in use',
                'cancelled_at' => $now,
            ]);
            throw TvControlException::conflict('Stasiun sudah tidak digunakan. ACK ditolak.');
        }

        $status = TvAlertEvent::STATUS_DELIVERED_TO_APP;
        $resultCode = $ackPayload['result_code'] ?? 'ACKED_BY_SIMULATOR';

        if ($executionStatus === TvAlertEvent::STATUS_FAILED) {
            $status = TvAlertEvent::STATUS_FAILED;
            $resultCode = $hardwareErrorCode ?? 'FAILED';
        } elseif ($executionStatus === TvAlertEvent::STATUS_UNSUPPORTED) {
            $resultCode = 'UNSUPPORTED_ON_TARGET';
        }

        $event->update([
            'status' => $status,
            'execution_status' => $executionStatus,
            'result_code' => $resultCode,
            'real_volume' => $realVolume,
            'real_muted' => $realMuted,
            'hardware_error_code' => $hardwareErrorCode,
            'hardware_error_message' => $hardwareErrorMessage,
            'delivered_at' => $event->delivered_at ?? $now,
            'acked_at' => $now,
        ]);

        return $event->fresh();
    }

    /**
     * Check whether a consumer/simulator has polled recently for this station.
     */
    public function isSimulatorOnline(int $stationId): bool
    {
        return Cache::has(self::HEARTBEAT_CACHE_PREFIX . $stationId);
    }

    /**
     * Get current simulated TV state for a station's mapped Samsung TV.
     */
    public function getStationSimulatedTvState(Device $station): array
    {
        $mapping = $station->activeDisplayTv()->with('physicalDevice')->first();
        $tv = $mapping ? $mapping->physicalDevice : null;

        $defaultState = [
            'power' => 'ON',
            'volume' => (int) config('hardware.tv_control.default_volume', 30),
            'mute' => false,
            'simulated' => true,
        ];

        if (!$tv) {
            return $defaultState;
        }

        $state = $this->simulationManager->getSimulatedState($tv->id, $defaultState);

        return [
            'device_id' => $tv->id,
            'device_name' => $tv->name,
            'power' => (string) ($state['power'] ?? 'ON'),
            'volume' => (int) ($state['volume'] ?? 30),
            'mute' => (bool) ($state['mute'] ?? false),
            'last_alert' => $state['last_alert'] ?? null,
            'scenario' => $this->simulationManager->getDeviceScenario($tv->id),
            'simulated' => true,
        ];
    }

    /**
     * Get complete TV control status & audit history for a station (used by UI / status endpoints).
     */
    public function getStationControlStatus(Device $station, int $historyLimit = 10): array
    {
        $station->loadMissing(['playstations', 'playstation']);
        $mapping = $station->activeDisplayTv()->with('physicalDevice')->first();
        $tv = $mapping ? $mapping->physicalDevice : null;
        $activeTx = $station->activeTransaction();

        $remainingSeconds = null;
        if ($activeTx && $activeTx->expires_at) {
            $remainingSeconds = max(0, Carbon::now()->diffInSeconds($activeTx->expires_at, false));
        }

        $events = self::hasTvControlSchema()
            ? TvAlertEvent::where('station_id', $station->id)
                ->latest('id')
                ->limit($historyLimit)
                ->get()
            : collect();

        $latestEvent = $events->first();

        $lanHostIp = $this->getLanHostIp();
        $lanBaseUrl = $this->getLanBaseUrl();
        $devToken = "dev_tv_station_{$station->id}";
        $lanApiUrl = "{$lanBaseUrl}/api/tv-agent/events?station_id={$station->id}&token={$devToken}";

        $isRealDriver = config('hardware.driver') === 'real';

        return [
            'station_id' => $station->id,
            'station_name' => $station->nama,
            'station_status' => $station->status,
            'station_category' => $station->getStationCategory(),
            'session_generation' => (int) ($station->session_generation ?: 1),
            'has_active_session' => $activeTx !== null && !$activeTx->isExpired(),
            'transaction' => $activeTx ? [
                'id_transaksi' => $activeTx->id_transaksi,
                'nama' => $activeTx->nama,
                'tipe_transaksi' => $activeTx->tipe_transaksi,
                'billing_status' => $activeTx->billing_status,
                'expires_at' => $activeTx->expires_at ? $activeTx->expires_at->toIso8601String() : null,
                'remaining_seconds' => $remainingSeconds,
            ] : null,
            'tv_device' => $tv ? [
                'id' => $tv->id,
                'name' => $tv->name,
                'model' => $tv->model,
                'ip_address' => $tv->ip_address,
                'connection_status' => $tv->connection_status,
                'pairing_status' => $tv->pairing_status,
                'is_active' => $tv->is_active,
            ] : null,
            'control_mode' => $isRealDriver ? 'REAL' : 'MOCK',
            'simulator_online' => $isRealDriver ? ($tv && $tv->connection_status === 'ONLINE') : $this->isSimulatorOnline($station->id),
            'direct_transport' => $isRealDriver,
            'consumer_token' => $this->generateConsumerToken($station->id, $tv ? $tv->id : 0),
            'dev_token' => $devToken,
            'lan_host_ip' => $lanHostIp,
            'lan_base_url' => $lanBaseUrl,
            'lan_api_url' => $lanApiUrl,
            'tv_state' => $isRealDriver ? [
                'device_id' => $tv ? $tv->id : null,
                'device_name' => $tv ? $tv->name : null,
                'power' => ($tv && $tv->connection_status === 'ONLINE') ? 'ON' : 'OFF',
                'volume' => $tv ? $tv->current_volume : null,
                'mute' => $tv ? (bool) $tv->is_muted : false,
                'simulated' => false,
                'physical_state_verified' => false,
            ] : $this->getStationSimulatedTvState($station),
            'latest_event' => $latestEvent,
            'history' => $events,
        ];
    }

    /**
     * Get LAN host IP for direct TV agent/consumer access.
     * Configurable via TV_AGENT_HOST_IP or derived from APP_URL / SERVER_ADDR.
     */
    public function getLanHostIp(): string
    {
        $envIp = env('TV_AGENT_HOST_IP');
        if (!empty($envIp)) {
            return (string) $envIp;
        }

        // In automated testing environment, retain default deterministic IP unless overridden
        if (app()->environment('testing')) {
            return '192.168.200.240';
        }

        $appUrl = (string) config('app.url');
        if (!empty($appUrl)) {
            $host = parse_url($appUrl, PHP_URL_HOST);
            if (!empty($host) && filter_var($host, FILTER_VALIDATE_IP) && $host !== '127.0.0.1' && $host !== '0.0.0.0') {
                return (string) $host;
            }
        }

        if (request() && request()->server('SERVER_ADDR')) {
            $serverAddr = (string) request()->server('SERVER_ADDR');
            if ($serverAddr !== '127.0.0.1' && $serverAddr !== '::1') {
                return $serverAddr;
            }
        }

        // Dynamically detect local network IPv4 interface (non-loopback, non-link-local)
        if (function_exists('net_get_interfaces')) {
            $interfaces = @net_get_interfaces();
            if (is_array($interfaces)) {
                foreach ($interfaces as $name => $info) {
                    if (!empty($info['unicast'])) {
                        foreach ($info['unicast'] as $entry) {
                            $addr = $entry['address'] ?? '';
                            if (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                                if ($addr !== '127.0.0.1' && !str_starts_with($addr, '169.254.')) {
                                    return (string) $addr;
                                }
                            }
                        }
                    }
                }
            }
        }

        return '192.168.200.240';
    }

    /**
     * Get LAN port for TV agent access.
     */
    public function getLanPort(): int
    {
        $envPort = env('TV_AGENT_PORT');
        if (!empty($envPort)) {
            return (int) $envPort;
        }

        if (request() && request()->server('SERVER_PORT')) {
            return (int) request()->server('SERVER_PORT');
        }

        return 8000;
    }

    /**
     * Get full LAN base URL (e.g., http://192.168.200.240:8000).
     */
    public function getLanBaseUrl(): string
    {
        $ip = $this->getLanHostIp();
        $port = $this->getLanPort();
        $scheme = (request() && request()->isSecure()) ? 'https' : 'http';

        if (($port === 80 && $scheme === 'http') || ($port === 443 && $scheme === 'https')) {
            return "{$scheme}://{$ip}";
        }

        return "{$scheme}://{$ip}:{$port}";
    }

    /**
     * Resolve MockSamsungTvDriver safely in mock/test environments.
     */
    protected function resolveMockOrConfiguredDriver(PhysicalDevice $tv)
    {
        if (config('hardware.driver', 'none') === 'none' && app()->environment(['local', 'testing'])) {
            return new MockSamsungTvDriver($this->simulationManager);
        }

        return $this->driverManager->getDriverForDevice($tv);
    }

    /**
     * Rate-limiting check for rapid volume command bursts on the same station.
     */
    protected function checkVolumeRateLimit(Device $station): void
    {
        $lockKey = "tv_vol_lock:{$station->id}";
        if (Cache::has($lockKey)) {
            throw TvControlException::rateLimited($station->nama);
        }

        $burstKey = "tv_vol_burst:{$station->id}";
        $maxBurst = (int) config('hardware.tv_control.max_burst_per_sec', 8);
        $currentBurst = (int) Cache::get($burstKey, 0);

        if ($currentBurst >= $maxBurst) {
            throw TvControlException::rateLimited($station->nama);
        }

        Cache::put($burstKey, $currentBurst + 1, 1);
    }
}

