<?php

namespace App\Services\Hardware\Drivers;

use App\Exceptions\HardwareSafetyException;
use App\Models\PhysicalDevice;
use App\Services\Hardware\Contracts\HardwareDriverInterface;
use App\Services\Hardware\DeviceStatusManagementService;
use App\Services\Hardware\DTO\HardwareCommandResult;
use App\Services\Hardware\Transports\Ps3TransportInterface;
use InvalidArgumentException;

class Ps3HenNotificationDriver implements HardwareDriverInterface
{
    public const DRIVER_TYPE = 'PS3_HEN_HTTP';

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

    protected Ps3TransportInterface $transport;
    protected DeviceStatusManagementService $statusService;
    protected bool $isRealVerifiedMode;

    public function __construct(
        Ps3TransportInterface $transport,
        ?DeviceStatusManagementService $statusService = null,
        bool $isRealVerifiedMode = false
    ) {
        $this->transport = $transport;
        $this->statusService = $statusService ?? new DeviceStatusManagementService();
        $this->isRealVerifiedMode = $isRealVerifiedMode;
    }

    public function probe(PhysicalDevice $device): HardwareCommandResult
    {
        $this->validateDevice($device);

        $port = config('hardware.ps_console.ps3.default_port', 80);
        $timeoutMs = config('hardware.ps_console.ps3.connect_timeout_ms', 2000);

        $result = $this->transport->checkConnection($device->ip_address, $port, $timeoutMs);

        if ($result['status'] === 'OFFLINE') {
            return HardwareCommandResult::createOffline(
                $device->id,
                $this->getDriverType(),
                self::CMD_PROBE,
                "PS3 unreachable on {$device->ip_address}:{$port} (Offline)"
            );
        }

        if ($result['status'] === 'TIMEOUT') {
            return HardwareCommandResult::createTimeout(
                $device->id,
                $this->getDriverType(),
                self::CMD_PROBE,
                $timeoutMs
            );
        }

        if (!$result['success']) {
            return HardwareCommandResult::createError(
                $device->id,
                $this->getDriverType(),
                self::CMD_PROBE,
                $result['message'] ?? 'Probe failed',
                $result['status'] ?? 'PROBE_FAILED'
            );
        }

        $this->statusService->updateConnectionStatus($device, PhysicalDevice::CONNECTION_ONLINE, $this->isRealVerifiedMode);

        return HardwareCommandResult::createRealResult(
            $device->id,
            $this->getDriverType(),
            self::CMD_PROBE,
            true,
            'SUCCESS',
            $result['message'] ?? 'PS3 webMAN MOD probe berhasil',
            ['ip' => $device->ip_address, 'status' => 'ONLINE'],
            $result['data'] ?? []
        );
    }

    public function pair(PhysicalDevice $device, array $options = []): HardwareCommandResult
    {
        $this->validateDevice($device);

        // webMAN MOD HTTP does not require PIN pairing handshake
        return HardwareCommandResult::createRealResult(
            $device->id,
            $this->getDriverType(),
            'PAIR',
            true,
            'NOT_REQUIRED',
            'PS3 webMAN MOD tidak memerlukan otorisasi PIN (Pairing not required)',
            ['pairing_status' => 'NOT_REQUIRED']
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
                "Perintah '{$command}' tidak didukung oleh driver PS3 HEN.",
                'INVALID_COMMAND'
            );
        }

        if ($upperCommand === self::CMD_PROBE) {
            return $this->probe($device);
        }

        if ($upperCommand === self::CMD_GET_STATUS) {
            return $this->getStatus($device);
        }

        // SHOW_NOTIFICATION or SHOW_ALERT
        $rawMessage = (string) ($payload['message'] ?? $payload['text'] ?? $payload['body'] ?? '');
        $title = (string) ($payload['title'] ?? '');

        $combinedMessage = !empty($title) ? "{$title}: {$rawMessage}" : $rawMessage;
        $cleanMessage = $this->sanitizeMessage($combinedMessage);

        if (empty($cleanMessage)) {
            return HardwareCommandResult::createError(
                $device->id,
                self::DRIVER_TYPE,
                $upperCommand,
                'Pesan notifikasi tidak boleh kosong',
                'EMPTY_MESSAGE'
            );
        }

        $port = config('hardware.ps_console.ps3.default_port', 80);
        $timeoutMs = config('hardware.ps_console.ps3.timeout_ms', 2000);

        $transportResult = $this->transport->sendNotification($device->ip_address, $port, $cleanMessage, $timeoutMs);

        if (!$transportResult['success']) {
            if ($transportResult['status'] === 'OFFLINE') {
                return HardwareCommandResult::createOffline(
                    $device->id,
                    self::DRIVER_TYPE,
                    $upperCommand,
                    $transportResult['message']
                );
            }

            if ($transportResult['status'] === 'TIMEOUT') {
                return HardwareCommandResult::createTimeout(
                    $device->id,
                    self::DRIVER_TYPE,
                    $upperCommand,
                    $timeoutMs
                );
            }

            return HardwareCommandResult::createError(
                $device->id,
                self::DRIVER_TYPE,
                $upperCommand,
                $transportResult['message'],
                $transportResult['status'] ?? 'NOTIFICATION_FAILED'
            );
        }

        return HardwareCommandResult::createRealResult(
            $device->id,
            self::DRIVER_TYPE,
            $upperCommand,
            true,
            'ACK_HTTP',
            "Notifikasi terkirim dan diterima oleh webMAN MOD PS3 (ACK_HTTP). Tampilan pop-up pada layar fisik memerlukan verifikasi fisik.",
            [
                'command' => $upperCommand,
                'message_length' => mb_strlen($cleanMessage),
                'transport' => 'HTTP_WEBMAN',
                'physical_display_verified' => false,
            ],
            ['raw_response' => $transportResult['raw_response'] ?? null]
        );
    }

    public function getStatus(PhysicalDevice $device): HardwareCommandResult
    {
        return $this->probe($device);
    }

    public function getSupportedCommands(): array
    {
        return self::SUPPORTED_COMMANDS;
    }

    public function getDriverType(): string
    {
        return self::DRIVER_TYPE;
    }

    /**
     * Sanitize message for webMAN MOD on-screen popup display.
     * Strips HTML, scripts, normalizes whitespace, control chars, and limits to <= 120 chars.
     *
     * @param string $message
     * @param int|null $maxLength
     * @return string
     */
    public function sanitizeMessage(string $message, ?int $maxLength = null): string
    {
        $maxLen = $maxLength ?? (int) config('hardware.ps_console.ps3.max_message_length', 120);

        // Strip script tags and contents completely
        $clean = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $message);
        // Strip other HTML tags
        $clean = strip_tags($clean);
        // Replace newlines and tabs with single space
        $clean = preg_replace('/[\r\n\t]+/', ' ', $clean);
        // Remove non-printable control characters
        $clean = preg_replace('/[\x00-\x1F\x7F]/', '', $clean);
        // Normalize multiple spaces into single space
        $clean = trim(preg_replace('/\s+/', ' ', $clean));

        if (mb_strlen($clean) > $maxLen) {
            $clean = $maxLen > 3 ? mb_substr($clean, 0, $maxLen - 3) . '...' : mb_substr($clean, 0, $maxLen);
        }

        return $clean;
    }

    protected function validateDevice(PhysicalDevice $device): void
    {
        if ($device->category !== PhysicalDevice::CATEGORY_GAME_CONSOLE) {
            throw new InvalidArgumentException("Driver PS3 HEN hanya mendukung kategori GAME_CONSOLE, diberikan: {$device->category}");
        }

        if (!in_array($device->brand, [PhysicalDevice::BRAND_SONY, PhysicalDevice::BRAND_PLAYSTATION], true)) {
            throw new InvalidArgumentException("Driver PS3 HEN hanya mendukung merek SONY atau PLAYSTATION, diberikan: {$device->brand}");
        }
    }
}

