<?php

namespace Tests\Feature;

use App\Exceptions\HardwareSafetyException;
use App\Exceptions\HardwareSimulationException;
use App\Models\PhysicalDevice;
use App\Services\Hardware\DeviceStatusManagementService;
use App\Services\Hardware\Drivers\MockSamsungTvDriver;
use App\Services\Hardware\Drivers\MockShellyRelayDriver;
use App\Services\Hardware\Drivers\SamsungTvDriver;
use App\Services\Hardware\Drivers\ShellyRelayDriver;
use App\Services\Hardware\HardwareDriverManager;
use App\Services\Hardware\Simulation\HardwareSimulationManager;
use App\Services\Hardware\Transports\FakeSamsungWebSocketTransport;
use App\Services\Hardware\Transports\FakeShellyHttpRpcTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class HardwareRealDriverSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected PhysicalDevice $samsungTv;
    protected PhysicalDevice $shellyRelay;
    protected HardwareDriverManager $driverManager;
    protected HardwareSimulationManager $simulationManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->samsungTv = PhysicalDevice::create([
            'name' => 'Samsung TV Safety Test',
            'category' => PhysicalDevice::CATEGORY_SMART_TV,
            'brand' => PhysicalDevice::BRAND_SAMSUNG,
            'model' => '50U8500',
            'mac_address' => '00:11:22:33:77:01',
            'ip_address' => '192.168.1.171',
            'connection_status' => PhysicalDevice::CONNECTION_UNVERIFIED,
            'pairing_status' => PhysicalDevice::PAIRING_UNVERIFIED,
            'is_active' => true,
        ]);

        $this->shellyRelay = PhysicalDevice::create([
            'name' => 'Shelly Relay Safety Test',
            'category' => PhysicalDevice::CATEGORY_SMART_RELAY,
            'brand' => PhysicalDevice::BRAND_SHELLY,
            'model' => 'Shelly 1PM Mini Gen3',
            'mac_address' => '00:11:22:33:77:02',
            'ip_address' => '192.168.1.172',
            'connection_status' => PhysicalDevice::CONNECTION_UNVERIFIED,
            'pairing_status' => PhysicalDevice::PAIRING_UNVERIFIED,
            'is_active' => true,
        ]);

        $this->simulationManager = new HardwareSimulationManager();
        $this->driverManager = new HardwareDriverManager($this->simulationManager);
    }

    /** @test */
    public function mode_none_throws_simulation_disabled_exception()
    {
        Config::set('hardware.driver', 'none');

        $this->expectException(HardwareSimulationException::class);
        $this->expectExceptionMessage('Hardware Simulation dinonaktifkan');

        $this->driverManager->getDriverForDevice($this->samsungTv);
    }

    /** @test */
    public function mode_mock_returns_g4_a_mock_drivers()
    {
        Config::set('hardware.driver', 'mock');

        $samsungDriver = $this->driverManager->getDriverForDevice($this->samsungTv);
        $this->assertInstanceOf(MockSamsungTvDriver::class, $samsungDriver);
        $this->assertEquals('MOCK_SAMSUNG_TV', $samsungDriver->getDriverType());

        $shellyDriver = $this->driverManager->getDriverForDevice($this->shellyRelay);
        $this->assertInstanceOf(MockShellyRelayDriver::class, $shellyDriver);
        $this->assertEquals('MOCK_SHELLY_RELAY', $shellyDriver->getDriverType());
    }

    /** @test */
    public function mode_real_fails_safe_when_real_execution_disabled()
    {
        Config::set('hardware.driver', 'real');
        Config::set('hardware.real_execution_enabled', false); // Default G4-B1

        $this->expectException(HardwareSafetyException::class);
        $this->expectExceptionMessage('SAFETY GUARD ACTIVE: Eksekusi jaringan ke hardware fisik dinonaktifkan');

        $this->driverManager->getDriverForDevice($this->samsungTv);
    }

    /** @test */
    public function mode_real_resolves_real_drivers_when_safety_guard_is_satisfied()
    {
        Config::set('hardware.driver', 'real');
        Config::set('hardware.real_execution_enabled', true);

        $fakeSamsung = new FakeSamsungWebSocketTransport();
        $fakeShelly = new FakeShellyHttpRpcTransport();

        $this->driverManager->setSamsungTransport($fakeSamsung);
        $this->driverManager->setShellyTransport($fakeShelly);

        $samsungDriver = $this->driverManager->getDriverForDevice($this->samsungTv);
        $this->assertInstanceOf(SamsungTvDriver::class, $samsungDriver);
        $this->assertEquals('REAL_SAMSUNG_TV', $samsungDriver->getDriverType());

        $shellyDriver = $this->driverManager->getDriverForDevice($this->shellyRelay);
        $this->assertInstanceOf(ShellyRelayDriver::class, $shellyDriver);
        $this->assertEquals('REAL_SHELLY_RELAY', $shellyDriver->getDriverType());
    }

    /** @test */
    public function device_status_management_service_prevents_fake_responses_from_mutating_db()
    {
        Config::set('hardware.driver', 'real');
        Config::set('hardware.real_execution_enabled', false);

        $service = new DeviceStatusManagementService();

        // 1. Connection status update with isRealVerified = false
        $updatedConn = $service->updateConnectionStatus($this->samsungTv, PhysicalDevice::CONNECTION_ONLINE, false);
        $this->assertFalse($updatedConn);
        $this->assertEquals(PhysicalDevice::CONNECTION_UNVERIFIED, $this->samsungTv->fresh()->connection_status);

        // 2. Pairing token persistence with isRealVerified = false
        $updatedPair = $service->recordSuccessfulPairing($this->samsungTv, 'fake_token_abc', false);
        $this->assertFalse($updatedPair);
        $this->assertEquals(PhysicalDevice::PAIRING_UNVERIFIED, $this->samsungTv->fresh()->pairing_status);
        $this->assertNull($this->samsungTv->fresh()->pairing_payload);

        // 3. Capability addition with isRealVerified = false
        $updatedCap = $service->addVerifiedCapability($this->samsungTv, 'POWER_ON', false);
        $this->assertFalse($updatedCap);
        $this->assertEmpty($this->samsungTv->fresh()->verified_capabilities ?? []);
    }
}
