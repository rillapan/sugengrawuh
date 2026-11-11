<?php

namespace App\Services\Hardware;

use App\Models\PhysicalDevice;
use App\Services\Hardware\Contracts\HardwareDriverInterface;
use App\Services\Hardware\Drivers\MockConsoleDriver;
use App\Services\Hardware\Drivers\Ps3WebmanDriver;
use App\Services\Hardware\Drivers\Ps4GoldHenDriver;
use App\Services\Hardware\DTO\HardwareCommandResult;
use InvalidArgumentException;

class ConsoleNotificationDispatcher
{
    protected HardwareDriverManager $driverManager;
    protected ?MockConsoleDriver $mockDriver = null;

    public function __construct(HardwareDriverManager $driverManager, ?MockConsoleDriver $mockDriver = null)
    {
        $this->driverManager = $driverManager;
        $this->mockDriver = $mockDriver;
    }

    /**
     * Resolve the console notification driver for a physical device.
     *
     * @param PhysicalDevice $device
     * @return HardwareDriverInterface
     */
    public function resolveDriver(PhysicalDevice $device): HardwareDriverInterface
    {
        if ($device->category !== PhysicalDevice::CATEGORY_GAME_CONSOLE) {
            throw new InvalidArgumentException("Perangkat {$device->name} bukan merupakan Game Console ({$device->category}).");
        }

        $transportMode = config('hardware.console_notifications_transport', env('CONSOLE_NOTIFICATIONS_TRANSPORT', 'mock'));
        $hardwareDriver = config('hardware.driver', 'none');

        if ($transportMode === 'mock' || $hardwareDriver === 'mock') {
            return $this->mockDriver ?? new MockConsoleDriver();
        }

        // Real driver resolution via HardwareDriverManager
        $driver = $this->driverManager->getDriverForDevice($device);

        if ($driver instanceof HardwareDriverInterface) {
            return $driver;
        }

        // Fallback by driver_type or model
        $typeUpper = strtoupper((string) ($device->driver_type ?: $device->model));
        if (strpos($typeUpper, 'PS3') !== false || strpos($typeUpper, 'WEBMAN') !== false) {
            return app(Ps3WebmanDriver::class);
        }

        return app(Ps4GoldHenDriver::class);
    }

    /**
     * Dispatch notification popup to console.
     *
     * @param PhysicalDevice $device
     * @param string $message
     * @param array $options
     * @return HardwareCommandResult
     */
    public function dispatch(PhysicalDevice $device, string $message, array $options = []): HardwareCommandResult
    {
        $driver = $this->resolveDriver($device);

        if (method_exists($driver, 'sendNotification')) {
            return $driver->sendNotification($device, $message);
        }

        return $driver->sendCommand($device, 'SHOW_NOTIFICATION', array_merge(['message' => $message], $options));
    }

    /**
     * Check network connectivity to console.
     *
     * @param PhysicalDevice $device
     * @return array
     */
    public function checkConnectivity(PhysicalDevice $device): array
    {
        $driver = $this->resolveDriver($device);

        if (method_exists($driver, 'checkConnectivity')) {
            return $driver->checkConnectivity($device);
        }

        $probe = $driver->probe($device);
        return [
            'success' => $probe->isSuccess(),
            'status' => $probe->isSuccess() ? 'ONLINE' : 'OFFLINE',
            'message' => $probe->message,
        ];
    }

    /**
     * Get transport status of console.
     *
     * @param PhysicalDevice $device
     * @return array
     */
    public function getTransportStatus(PhysicalDevice $device): array
    {
        $driver = $this->resolveDriver($device);

        if (method_exists($driver, 'getTransportStatus')) {
            return $driver->getTransportStatus($device);
        }

        return [
            'driver_type' => get_class($driver),
            'device_id' => $device->id,
            'ip_address' => $device->ip_address,
            'connection_status' => $device->connection_status,
        ];
    }
}
