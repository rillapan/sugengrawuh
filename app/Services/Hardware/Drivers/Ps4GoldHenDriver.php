<?php

namespace App\Services\Hardware\Drivers;

use App\Models\PhysicalDevice;
use App\Models\TvAlertEvent;
use App\Services\Hardware\Contracts\HardwareDriverInterface;
use App\Services\Hardware\DeviceStatusManagementService;
use App\Services\Hardware\DTO\HardwareCommandResult;
use App\Services\Hardware\Transports\Ps4TransportInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class Ps4GoldHenDriver implements HardwareDriverInterface
{
    public const DRIVER_TYPE = 'PS4_GOLDHEN';

    public const CMD_SHOW_NOTIFICATION = 'SHOW_NOTIFICATION';
    public const CMD_SHOW_ALERT = 'SHOW_ALERT';
    public const CMD_PROBE = 'PROBE';
    public const CMD_GET_STATUS = 'GET_STATUS';

    public const SUPPORTED_COMMANDS = [
        self::CMD_SHOW_NOTIFICATION,
        self::CMD_SHOW_ALERT,
        self::CMD_PROBE,
        self::CMD_GET_STATUS,
    ];

    protected ?Ps4TransportInterface $transport;
    protected DeviceStatusManagementService $statusService;
    protected bool $isRealVerifiedMode;

    public function __construct(
        ?Ps4TransportInterface $transport = null,
        ?DeviceStatusManagementService $statusService = null,
        bool $isRealVerifiedMode = false
    ) {
        $this->transport = $transport;
        $this->statusService = $statusService ?? new DeviceStatusManagementService();
        $this->isRealVerifiedMode = $isRealVerifiedMode;
    }

    public function getDriverType(): string
    {
        return self::DRIVER_TYPE;
    }

    public function getSupportedCommands(): array
    {
        return self::SUPPORTED_COMMANDS;
    }

    public function probe(PhysicalDevice $device): HardwareCommandResult
    {
        $this->validateDevice($device);

        // If mock transport provided or testing, use transport probe
        if ($this->transport) {
            $port = config('hardware.ps_console.ps4.default_port', 8080);
            $timeoutMs = config('hardware.ps_console.ps4.timeout_ms', 2000);
            $result = $this->transport->checkConnection($device->ip_address, $port, $timeoutMs);

            if ($result['status'] === 'UNSUPPORTED_PROTOCOL' || $result['status'] === 'NOT_CONFIGURED') {
                return HardwareCommandResult::createUnsupportedCapability(
                    $device->id,
                    self::DRIVER_TYPE,
                    self::CMD_PROBE,
                    $result['message']
                );
            }

            if ($result['status'] === 'OFFLINE') {
                return HardwareCommandResult::createOffline(
                    $device->id,
                    self::DRIVER_TYPE,
                    self::CMD_PROBE,
                    "PS4 unreachable on {$device->ip_address}:{$port} (Offline)"
                );
            }

            if ($result['status'] === 'TIMEOUT') {
                return HardwareCommandResult::createTimeout(
                    $device->id,
                    self::DRIVER_TYPE,
                    self::CMD_PROBE,
                    $timeoutMs
                );
            }

            if (!$result['success']) {
                return HardwareCommandResult::createError(
                    $device->id,
                    self::DRIVER_TYPE,
                    self::CMD_PROBE,
                    $result['message'] ?? 'Probe failed',
                    $result['status'] ?? 'PROBE_FAILED'
                );
            }

            $this->statusService->updateConnectionStatus($device, PhysicalDevice::CONNECTION_ONLINE, $this->isRealVerifiedMode);

            return HardwareCommandResult::createRealResult(
                $device->id,
                self::DRIVER_TYPE,
                self::CMD_PROBE,
                true,
                'SUCCESS',
                $result['message'] ?? 'PS4 GoldHEN Outbound Agent probe berhasil',
                ['ip' => $device->ip_address, 'status' => 'ONLINE'],
                $result['data'] ?? []
            );
        }

        // Outbound polling connectivity check based on heartbeat
        $connectivity = $this->checkConnectivity($device);
        if ($connectivity['status'] === 'ONLINE') {
            $this->statusService->updateConnectionStatus($device, PhysicalDevice::CONNECTION_ONLINE, $this->isRealVerifiedMode);
            return HardwareCommandResult::createRealResult(
                $device->id,
                self::DRIVER_TYPE,
                self::CMD_PROBE,
                true,
                'SUCCESS',
                'PS4 GoldHEN Agent aktif (Heartbeat terdeteksi)',
                $connectivity
            );
        }

        return HardwareCommandResult::createRealResult(
            $device->id,
            self::DRIVER_TYPE,
            self::CMD_PROBE,
            true,
            'WAITING_AGENT',
            'PS4 GoldHEN menggunakan arsitektur outbound polling. Menunggu heartbeat dari plugin sugengrawuh_notify.prx.',
            $connectivity
        );
    }

    public function pair(PhysicalDevice $device, array $options = []): HardwareCommandResult
    {
        $this->validateDevice($device);

        // GoldHEN agent authenticates via device-scoped token
        if (empty($device->device_token_hash)) {
            $token = $device->generateDeviceToken();
            return HardwareCommandResult::createRealResult(
                $device->id,
                self::DRIVER_TYPE,
                'PAIR',
                true,
                'TOKEN_GENERATED',
                'Device token untuk PS4 GoldHEN Agent berhasil dibuat.',
                ['token' => $token, 'pairing_status' => 'PAIRED']
            );
        }

        return HardwareCommandResult::createRealResult(
            $device->id,
            self::DRIVER_TYPE,
            'PAIR',
            true,
            'ALREADY_PAIRED',
            'PS4 GoldHEN Agent telah terautentikasi dengan device token yang ada.',
            ['pairing_status' => 'PAIRED']
        );
    }

    public function sendCommand(PhysicalDevice $device, string $command, array $payload = []): HardwareCommandResult
    {
        $this->validateDevice($device);

        $upperCommand = strtoupper(trim($command));
        if (!in_array($upperCommand, self::SUPPORTED_COMMANDS, true)) {
            return HardwareCommandResult::createError(
                $device->id,
                self::DRIVER_TYPE,
                $command,
                "Perintah '{$command}' tidak didukung oleh driver PS4 GoldHEN.",
                'INVALID_COMMAND'
            );
        }

        if ($upperCommand === self::CMD_PROBE) {
            return $this->probe($device);
        }

        if ($upperCommand === self::CMD_GET_STATUS) {
            return $this->getStatus($device);
        }

        $rawMessage = (string) ($payload['message'] ?? $payload['text'] ?? $payload['body'] ?? '');
        $title = (string) ($payload['title'] ?? '');
        $combinedMessage = !empty($title) ? "{$title}: {$rawMessage}" : $rawMessage;

        return $this->sendNotification($device, $combinedMessage);
    }

    /**
     * Send notification to PS4. In outbound polling architecture, the notification is queued
     * as PENDING for the PS4 agent to fetch on its next polling cycle.
     *
     * @param PhysicalDevice $device
     * @param string $message
     * @return HardwareCommandResult
     */
    public function sendNotification(PhysicalDevice $device, string $message): HardwareCommandResult
    {
        $this->validateDevice($device);

        $cleanMessage = $this->sanitizeMessage($message);
        if (empty($cleanMessage)) {
            return HardwareCommandResult::createError(
                $device->id,
                self::DRIVER_TYPE,
                self::CMD_SHOW_NOTIFICATION,
                'Pesan notifikasi tidak boleh kosong',
                'EMPTY_MESSAGE'
            );
        }

        if (mb_strlen($cleanMessage) > 120) {
            $cleanMessage = mb_substr($cleanMessage, 0, 120);
        }

        // If mock transport is explicitly configured (for unit/integration tests), dispatch via mock transport
        if ($this->transport) {
            $port = config('hardware.ps_console.ps4.default_port', 8080);
            $timeoutMs = config('hardware.ps_console.ps4.timeout_ms', 2000);
            $transportResult = $this->transport->sendNotification($device->ip_address, $port, $cleanMessage, $timeoutMs);

            if (!$transportResult['success']) {
                return HardwareCommandResult::createError(
                    $device->id,
                    self::DRIVER_TYPE,
                    self::CMD_SHOW_NOTIFICATION,
                    $transportResult['message'],
                    $transportResult['status'] ?? 'NOTIFICATION_FAILED'
                );
            }

            return HardwareCommandResult::createRealResult(
                $device->id,
                self::DRIVER_TYPE,
                self::CMD_SHOW_NOTIFICATION,
                true,
                'PENDING',
                'Notifikasi siap diambil oleh PS4 GoldHEN Agent (Outbound Polling).',
                [
                    'command' => self::CMD_SHOW_NOTIFICATION,
                    'message_length' => mb_strlen($cleanMessage),
                    'transport' => 'OUTBOUND_POLLING',
                    'status_type' => 'PENDING',
                    'physical_display_verified' => false,
                ],
                ['raw_response' => $transportResult['raw_response'] ?? null]
            );
        }

        Log::info('[PS4_GOLDHEN] Notifikasi diantrekan untuk polling agen PS4', [
            'device_id' => $device->id,
            'ip' => $device->ip_address,
            'message_length' => mb_strlen($cleanMessage),
        ]);

        return HardwareCommandResult::createRealResult(
            $device->id,
            self::DRIVER_TYPE,
            self::CMD_SHOW_NOTIFICATION,
            true,
            'PENDING',
            'Notifikasi diantrekan (PENDING). Plugin sugengrawuh_notify.prx pada PS4 akan mengambil pesan ini via HTTP polling.',
            [
                'command' => self::CMD_SHOW_NOTIFICATION,
                'message_length' => mb_strlen($cleanMessage),
                'transport' => 'OUTBOUND_POLLING',
                'status_type' => 'PENDING',
                'physical_display_verified' => false,
            ]
        );
    }

    /**
     * Check connectivity to PS4 based on recent agent heartbeat.
     *
     * @param PhysicalDevice $device
     * @return array
     */
    public function checkConnectivity(PhysicalDevice $device): array
    {
        $this->validateDevice($device);

        $now = Carbon::now();
        $lastSeen = $device->last_seen_at;

        // Consider online if heartbeat was received within the last 45 seconds
        $isOnline = $lastSeen && $now->diffInSeconds($lastSeen) <= 45;

        return [
            'success' => true,
            'status' => $isOnline ? 'ONLINE' : 'WAITING_AGENT',
            'is_online' => $isOnline,
            'last_seen_at' => $lastSeen ? $lastSeen->toIso8601String() : null,
            'seconds_since_last_seen' => $lastSeen ? $now->diffInSeconds($lastSeen) : null,
            'polling_endpoint' => '/api/console-agent/v1/events',
        ];
    }

    /**
     * Get transport status of the PS4 device.
     *
     * @param PhysicalDevice $device
     * @return array
     */
    public function getTransportStatus(PhysicalDevice $device): array
    {
        return [
            'driver_type' => self::DRIVER_TYPE,
            'device_id' => $device->id,
            'ip_address' => $device->ip_address,
            'connection_status' => $device->connection_status,
            'last_seen_at' => $device->last_seen_at ? $device->last_seen_at->toIso8601String() : null,
            'transport_mode' => 'OUTBOUND_POLLING',
            'poll_interval_seconds' => 3,
            'endpoint' => '/api/console-agent/v1/events',
            'has_token' => !empty($device->device_token_hash),
        ];
    }

    public function getStatus(PhysicalDevice $device): HardwareCommandResult
    {
        $this->validateDevice($device);

        $connectivity = $this->checkConnectivity($device);
        return HardwareCommandResult::createRealResult(
            $device->id,
            self::DRIVER_TYPE,
            self::CMD_GET_STATUS,
            true,
            'STATUS_OK',
            'Status PS4 GoldHEN Agent diambil',
            $connectivity
        );
    }

    protected function validateDevice(PhysicalDevice $device): void
    {
        if ($device->category !== PhysicalDevice::CATEGORY_GAME_CONSOLE) {
            throw new InvalidArgumentException(
                "Driver PS4 GoldHEN hanya mendukung kategori GAME_CONSOLE, bukan {$device->category}."
            );
        }

        if (!$device->is_active) {
            throw new InvalidArgumentException(
                "Perangkat {$device->name} sedang dinonaktifkan."
            );
        }
    }

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

