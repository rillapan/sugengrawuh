<?php

namespace App\Services\Hardware\Contracts;

use App\Models\PhysicalDevice;
use App\Services\Hardware\DTO\HardwareCommandResult;

interface HardwareDriverInterface
{
    /**
     * Check if the device is responsive on the virtual/physical network.
     *
     * @param PhysicalDevice $device
     * @return HardwareCommandResult
     */
    public function probe(PhysicalDevice $device): HardwareCommandResult;

    /**
     * Perform pairing or authentication handshake with the device.
     *
     * @param PhysicalDevice $device
     * @param array $options
     * @return HardwareCommandResult
     */
    public function pair(PhysicalDevice $device, array $options = []): HardwareCommandResult;

    /**
     * Send an operational command to the device.
     *
     * @param PhysicalDevice $device
     * @param string $command
     * @param array $payload
     * @return HardwareCommandResult
     */
    public function sendCommand(PhysicalDevice $device, string $command, array $payload = []): HardwareCommandResult;

    /**
     * Retrieve the current state of the device.
     *
     * @param PhysicalDevice $device
     * @return HardwareCommandResult
     */
    public function getStatus(PhysicalDevice $device): HardwareCommandResult;

    /**
     * Get the list of supported command strings for this driver.
     *
     * @return array
     */
    public function getSupportedCommands(): array;

    /**
     * Get the driver type identifier (e.g. 'MOCK_SAMSUNG_TV', 'MOCK_SHELLY_RELAY').
     *
     * @return string
     */
    public function getDriverType(): string;
}
