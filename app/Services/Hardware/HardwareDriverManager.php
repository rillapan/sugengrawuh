<?php

namespace App\Services\Hardware;

use App\Exceptions\HardwareSafetyException;
use App\Exceptions\HardwareSimulationException;
use App\Models\PhysicalDevice;
use App\Services\Hardware\Contracts\HardwareDriverInterface;
use App\Services\Hardware\Drivers\MockSamsungTvDriver;
use App\Services\Hardware\Drivers\MockShellyRelayDriver;
use App\Services\Hardware\Drivers\SamsungTvDriver;
use App\Services\Hardware\Drivers\ShellyRelayDriver;
use App\Services\Hardware\Security\HardwareSafetyGuard;
use App\Services\Hardware\Simulation\HardwareSimulationManager;
use App\Services\Hardware\Transports\SamsungTransportInterface;
use App\Services\Hardware\Transports\SamsungWebSocketTransport;
use App\Services\Hardware\Transports\ShellyHttpRpcTransport;
use App\Services\Hardware\Transports\ShellyTransportInterface;

class HardwareDriverManager
{
    protected HardwareSimulationManager $simulationManager;
    protected ?SamsungTransportInterface $samsungTransport = null;
    protected ?ShellyTransportInterface $shellyTransport = null;
    protected ?DeviceStatusManagementService $statusService = null;
    protected bool $bypassSafetyGuardForTesting = false;

    public function __construct(
        HardwareSimulationManager $simulationManager,
        ?SamsungTransportInterface $samsungTransport = null,
        ?ShellyTransportInterface $shellyTransport = null,
        ?DeviceStatusManagementService $statusService = null
    ) {
        $this->simulationManager = $simulationManager;
        $this->samsungTransport = $samsungTransport;
        $this->shellyTransport = $shellyTransport;
        $this->statusService = $statusService ?? new DeviceStatusManagementService();
    }

    public function getSimulationManager(): HardwareSimulationManager
    {
        return $this->simulationManager;
    }

    public function setSamsungTransport(SamsungTransportInterface $transport): self
    {
        $this->samsungTransport = $transport;
        return $this;
    }

    public function setShellyTransport(ShellyTransportInterface $transport): self
    {
        $this->shellyTransport = $transport;
        return $this;
    }

    public function setStatusService(DeviceStatusManagementService $service): self
    {
        $this->statusService = $service;
        return $this;
    }

    public function setBypassSafetyGuardForTesting(bool $bypass): self
    {
        $this->bypassSafetyGuardForTesting = $bypass;
        return $this;
    }

    /**
     * Resolve the appropriate driver for a physical device.
     *
     * @param PhysicalDevice $device
     * @return HardwareDriverInterface
     * @throws HardwareSimulationException|HardwareSafetyException
     */
    public function getDriverForDevice(PhysicalDevice $device): HardwareDriverInterface
    {
        $driverConfig = config('hardware.driver', 'none');

        // Mode: MOCK (G4-A Simulator)
        if ($driverConfig === 'mock') {
            if ($device->category === PhysicalDevice::CATEGORY_SMART_TV && $device->brand === PhysicalDevice::BRAND_SAMSUNG) {
                return new MockSamsungTvDriver($this->simulationManager);
            }

            if ($device->category === PhysicalDevice::CATEGORY_SMART_RELAY && $device->brand === PhysicalDevice::BRAND_SHELLY) {
                return new MockShellyRelayDriver($this->simulationManager);
            }

            throw HardwareSimulationException::incompatibleDevice(
                'SMART_TV (SAMSUNG) atau SMART_RELAY (SHELLY)',
                "{$device->category} ({$device->brand})"
            );
        }

        // Mode: REAL (G4-B Physical / Protocol Drivers)
        if ($driverConfig === 'real') {
            // Safety guard check: unless testing bypass flag is set, real execution switch must be true
            if (!$this->bypassSafetyGuardForTesting && !HardwareSafetyGuard::isRealExecutionEnabled()) {
                throw HardwareSafetyException::realExecutionDisabled();
            }

            $isRealVerified = HardwareSafetyGuard::isRealExecutionEnabled();

            if ($device->category === PhysicalDevice::CATEGORY_SMART_TV && $device->brand === PhysicalDevice::BRAND_SAMSUNG) {
                $transport = $this->samsungTransport ?? new SamsungWebSocketTransport();
                return new SamsungTvDriver($transport, $this->statusService, $isRealVerified);
            }

            if ($device->category === PhysicalDevice::CATEGORY_SMART_RELAY && $device->brand === PhysicalDevice::BRAND_SHELLY) {
                $transport = $this->shellyTransport ?? new ShellyHttpRpcTransport();
                return new ShellyRelayDriver($transport, $this->statusService, $isRealVerified);
            }

            throw HardwareSimulationException::incompatibleDevice(
                'SMART_TV (SAMSUNG) atau SMART_RELAY (SHELLY)',
                "{$device->category} ({$device->brand})"
            );
        }

        // Mode: NONE or unsupported
        throw HardwareSimulationException::simulationDisabled();
    }
}
