<?php

namespace App\Services\Hardware\Drivers;

use App\Models\PhysicalDevice;
use App\Services\Hardware\Contracts\HardwareDriverInterface;
use App\Services\Hardware\DTO\HardwareCommandResult;
use InvalidArgumentException;

class MockConsoleDriver implements HardwareDriverInterface
{
    public const DRIVER_TYPE = 'MOCK_CONSOLE';

    public const CMD_SHOW_NOTIFICATION = 'SHOW_NOTIFICATION';
    public const CMD_SHOW_ALERT = 'SHOW_ALERT';
    public const CMD_PROBE = 'PROBE';
    public const CMD_GET_STATUS = 'GET_STATUS';

    protected array $dispatchedMessages = [];

    public function getDriverType(): string
    {
        return self::DRIVER_TYPE;
    }

    public function getSupportedCommands(): array
    {
        return [
            self::CMD_SHOW_NOTIFICATION,
            self::CMD_SHOW_ALERT,
            self::CMD_PROBE,
            self::CMD_GET_STATUS,
        ];
    }

    public function probe(PhysicalDevice $device): HardwareCommandResult
    {
        return HardwareCommandResult::createRealResult(
            $device->id,
            self::DRIVER_TYPE,
            self::CMD_PROBE,
            true,
            'SUCCESS',
            "Mock Console probe berhasil untuk {$device->name}",
            ['ip' => $device->ip_address, 'status' => 'ONLINE']
        );
    }

    public function pair(PhysicalDevice $device, array $options = []): HardwareCommandResult
    {
        return HardwareCommandResult::createRealResult(
            $device->id,
            self::DRIVER_TYPE,
            'PAIR',
            true,
            'PAIRED',
            "Mock Console pairing berhasil untuk {$device->name}",
            ['pairing_status' => 'PAIRED']
        );
    }

    public function sendCommand(PhysicalDevice $device, string $command, array $payload = []): HardwareCommandResult
    {
        $rawMessage = (string) ($payload['message'] ?? $payload['text'] ?? $payload['body'] ?? '');
        $title = (string) ($payload['title'] ?? '');
        $combinedMessage = !empty($title) ? "{$title}: {$rawMessage}" : $rawMessage;

        return $this->sendNotification($device, $combinedMessage);
    }

    public function sendNotification(PhysicalDevice $device, string $message): HardwareCommandResult
    {
        $cleanMessage = trim(strip_tags($message));
        if (mb_strlen($cleanMessage) > 120) {
            $cleanMessage = mb_substr($cleanMessage, 0, 120);
        }

        $this->dispatchedMessages[] = [
            'device_id' => $device->id,
            'message' => $cleanMessage,
            'dispatched_at' => now()->toIso8601String(),
        ];

        return HardwareCommandResult::createRealResult(
            $device->id,
            self::DRIVER_TYPE,
            self::CMD_SHOW_NOTIFICATION,
            true,
            'HTTP_ACCEPTED',
            'Notifikasi simulasi konsol berhasil diterima (MOCK_CONSOLE).',
            [
                'command' => self::CMD_SHOW_NOTIFICATION,
                'message' => $cleanMessage,
                'message_length' => mb_strlen($cleanMessage),
                'simulated' => true,
            ]
        );
    }

    public function checkConnectivity(PhysicalDevice $device): array
    {
        return [
            'success' => true,
            'status' => 'ONLINE',
            'is_online' => true,
            'mode' => 'MOCK',
        ];
    }

    public function getTransportStatus(PhysicalDevice $device): array
    {
        return [
            'driver_type' => self::DRIVER_TYPE,
            'device_id' => $device->id,
            'ip_address' => $device->ip_address,
            'connection_status' => 'ONLINE',
            'transport_mode' => 'MOCK',
        ];
    }

    public function getStatus(PhysicalDevice $device): HardwareCommandResult
    {
        return HardwareCommandResult::createRealResult(
            $device->id,
            self::DRIVER_TYPE,
            self::CMD_GET_STATUS,
            true,
            'STATUS_OK',
            'Status Mock Console OK',
            ['status' => 'ONLINE']
        );
    }

    public function getDispatchedMessages(): array
    {
        return $this->dispatchedMessages;
    }
}
