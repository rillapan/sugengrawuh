<?php

namespace App\Services\Hardware\Drivers;

use App\Models\PhysicalDevice;
use App\Services\Hardware\Contracts\HardwareDriverInterface;
use App\Services\Hardware\DeviceStatusManagementService;
use App\Services\Hardware\DTO\HardwareCommandResult;
use App\Services\Hardware\Transports\Ps3TransportInterface;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class Ps3WebmanDriver extends Ps3HenNotificationDriver implements HardwareDriverInterface
{
    public const DRIVER_TYPE = 'PS3_WEBMAN';

    public const STATUS_HTTP_ACCEPTED = 'HTTP_ACCEPTED';
    public const STATUS_ACCEPTED = 'ACCEPTED';
    public const STATUS_OFFLINE = 'OFFLINE';
    public const STATUS_TIMEOUT = 'TIMEOUT';
    public const STATUS_ERROR = 'ERROR';
    public const STATUS_SECURITY_REJECTED = 'SECURITY_REJECTED';

    public function __construct(
        Ps3TransportInterface $transport,
        ?DeviceStatusManagementService $statusService = null,
        bool $isRealVerifiedMode = false
    ) {
        parent::__construct($transport, $statusService, $isRealVerifiedMode);
    }

    public function getDriverType(): string
    {
        return self::DRIVER_TYPE;
    }

    /**
     * Build the webMAN MOD popup endpoint path using rawurlencode.
     *
     * @param string $message
     * @return string
     */
    public function buildNotificationPath(string $message): string
    {
        return '/popup.ps3/' . rawurlencode($this->sanitizeMessage($message));
    }

    /**
     * Build full URL for webMAN MOD popup notification.
     *
     * @param PhysicalDevice $device
     * @param string $message
     * @return string
     */
    public function buildNotificationUrl(PhysicalDevice $device, string $message): string
    {
        $port = config('hardware.ps_console.ps3.default_port', 80);
        return "http://{$device->ip_address}:{$port}" . $this->buildNotificationPath($message);
    }

    /**
     * Send command to PS3 console (delegates to sendNotification for alert / notification commands).
     *
     * @param PhysicalDevice $device
     * @param string $command
     * @param array $payload
     * @return HardwareCommandResult
     */
    public function sendCommand(PhysicalDevice $device, string $command, array $payload = []): HardwareCommandResult
    {
        $this->validateDevice($device);

        $upperCommand = strtoupper(trim($command));
        if (!in_array($upperCommand, self::SUPPORTED_COMMANDS, true)) {
            return HardwareCommandResult::createError(
                $device->id,
                self::DRIVER_TYPE,
                $command,
                "Perintah '{$command}' tidak didukung oleh driver PS3 webMAN.",
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
     * Send notification popup to PS3 console via webMAN MOD.
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
                'Pesan notifikasi tidak boleh kosong setelah sanitasi.',
                'EMPTY_MESSAGE'
            );
        }

        // Limit strictly to <= 120 chars
        if (mb_strlen($cleanMessage) > 120) {
            $cleanMessage = mb_substr($cleanMessage, 0, 120);
        }

        $port = config('hardware.ps_console.ps3.default_port', 80);
        $timeoutMs = config('hardware.ps_console.ps3.timeout_ms', 5000);

        Log::info('[PS3_WEBMAN] Sending notification to PS3', [
            'device_id' => $device->id,
            'ip' => $device->ip_address,
            'port' => $port,
            'msg_len' => mb_strlen($cleanMessage),
        ]);

        try {
            $transportResult = $this->transport->sendNotification($device->ip_address, $port, $cleanMessage, $timeoutMs);
        } catch (\Throwable $e) {
            return HardwareCommandResult::createError(
                $device->id,
                self::DRIVER_TYPE,
                self::CMD_SHOW_NOTIFICATION,
                "Gagal komunikasi dengan konsol PS3: " . $e->getMessage(),
                self::STATUS_ERROR
            );
        }

        if (!$transportResult['success']) {
            if ($transportResult['status'] === 'OFFLINE') {
                return HardwareCommandResult::createOffline(
                    $device->id,
                    self::DRIVER_TYPE,
                    self::CMD_SHOW_NOTIFICATION,
                    $transportResult['message']
                );
            }

            if ($transportResult['status'] === 'TIMEOUT') {
                return HardwareCommandResult::createTimeout(
                    $device->id,
                    self::DRIVER_TYPE,
                    self::CMD_SHOW_NOTIFICATION,
                    $timeoutMs
                );
            }

            if ($transportResult['status'] === 'SECURITY_REJECTED') {
                return HardwareCommandResult::createError(
                    $device->id,
                    self::DRIVER_TYPE,
                    self::CMD_SHOW_NOTIFICATION,
                    $transportResult['message'],
                    self::STATUS_SECURITY_REJECTED
                );
            }

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
            self::STATUS_HTTP_ACCEPTED,
            "Notifikasi diterima oleh webMAN MOD (HTTP_ACCEPTED). Tampilan popup pada layar fisik memerlukan verifikasi fisik.",
            [
                'command' => self::CMD_SHOW_NOTIFICATION,
                'message_length' => mb_strlen($cleanMessage),
                'transport' => 'HTTP_WEBMAN',
                'status_type' => self::STATUS_HTTP_ACCEPTED,
                'normalized_status' => self::STATUS_ACCEPTED,
                'physical_display_verified' => false,
            ],
            ['raw_response' => $transportResult['raw_response'] ?? null]
        );
    }

    /**
     * Check network connectivity to PS3 webMAN MOD HTTP server.
     *
     * @param PhysicalDevice $device
     * @return array
     */
    public function checkConnectivity(PhysicalDevice $device): array
    {
        $this->validateDevice($device);

        $port = config('hardware.ps_console.ps3.default_port', 80);
        $timeoutMs = config('hardware.ps_console.ps3.connect_timeout_ms', 2000);

        return $this->transport->checkConnection($device->ip_address, $port, $timeoutMs);
    }

    /**
     * Get transport status of the PS3 device.
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
            'transport_mode' => 'DIRECT_HTTP',
            'endpoint' => '/popup.ps3',
            'timeout_ms' => config('hardware.ps_console.ps3.timeout_ms', 5000),
            'connect_timeout_ms' => config('hardware.ps_console.ps3.connect_timeout_ms', 2000),
            'physical_display_verified' => false,
        ];
    }

    protected function validateDevice(PhysicalDevice $device): void
    {
        if ($device->category !== PhysicalDevice::CATEGORY_GAME_CONSOLE) {
            throw new InvalidArgumentException("Driver PS3 webMAN hanya mendukung kategori GAME_CONSOLE, diberikan: {$device->category}");
        }

        if (!in_array($device->brand, [PhysicalDevice::BRAND_SONY, PhysicalDevice::BRAND_PLAYSTATION], true)) {
            throw new InvalidArgumentException("Driver PS3 webMAN hanya mendukung merek SONY atau PLAYSTATION, diberikan: {$device->brand}");
        }
    }
}
