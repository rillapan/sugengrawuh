<?php

namespace Tests\Unit;

use App\Exceptions\HardwareSimulationException;
use App\Models\PhysicalDevice;
use App\Services\Hardware\Drivers\MockShellyRelayDriver;
use App\Services\Hardware\Simulation\HardwareSimulationManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class MockShellyRelayDriverTest extends TestCase
{
    use RefreshDatabase;

    protected HardwareSimulationManager $simulationManager;
    protected MockShellyRelayDriver $driver;
    protected PhysicalDevice $shellyRelay;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('hardware.driver', 'mock');
        Config::set('hardware.simulation_enabled', true);
        Config::set('hardware.allowed_environments', ['local', 'testing']);
        Config::set('hardware.allowed_databases', ['kasir_ps_test']);

        $this->simulationManager = new HardwareSimulationManager();
        $this->driver = new MockShellyRelayDriver($this->simulationManager);

        $this->shellyRelay = PhysicalDevice::create([
            'name' => 'Shelly 1PM Billiard Light',
            'category' => PhysicalDevice::CATEGORY_SMART_RELAY,
            'brand' => PhysicalDevice::BRAND_SHELLY,
            'model' => 'Gen3 Mini',
            'mac_address' => 'AA:BB:CC:55:66:77',
            'ip_address' => '192.168.1.177',
            'connection_status' => PhysicalDevice::CONNECTION_UNVERIFIED,
            'pairing_status' => PhysicalDevice::PAIRING_UNVERIFIED,
            'is_active' => true,
        ]);

        $this->simulationManager->resetDevice($this->shellyRelay->id);
    }

    /** @test */
    public function driver_identifies_as_mock_shelly_relay()
    {
        $this->assertEquals('MOCK_SHELLY_RELAY', $this->driver->getDriverType());
        $this->assertContains('SWITCH_ON', $this->driver->getSupportedCommands());
        $this->assertContains('SWITCH_OFF', $this->driver->getSupportedCommands());
        $this->assertContains('GET_STATUS', $this->driver->getSupportedCommands());
    }

    /** @test */
    public function switch_on_command_turns_relay_on_and_simulates_power_consumption()
    {
        $result = $this->driver->sendCommand($this->shellyRelay, 'SWITCH_ON');

        $this->assertTrue($result->success);
        $this->assertEquals('SUCCESS', $result->resultCode);
        $this->assertEquals('ON', $result->simulatedState['switch']);
        $this->assertTrue($result->simulatedState['output']);
        $this->assertGreaterThan(0.0, $result->simulatedState['power_watts']);
        $this->assertStringContainsString('dinyalakan', $result->message);

        // Verify simulated state persists in manager
        $persisted = $this->simulationManager->getSimulatedState($this->shellyRelay->id);
        $this->assertEquals('ON', $persisted['switch']);
        $this->assertTrue($persisted['output']);
    }

    /** @test */
    public function switch_off_command_turns_relay_off_and_zeroes_power()
    {
        // First turn on
        $this->driver->sendCommand($this->shellyRelay, 'SWITCH_ON');

        // Then turn off
        $result = $this->driver->sendCommand($this->shellyRelay, 'SWITCH_OFF');

        $this->assertTrue($result->success);
        $this->assertEquals('SUCCESS', $result->resultCode);
        $this->assertEquals('OFF', $result->simulatedState['switch']);
        $this->assertFalse($result->simulatedState['output']);
        $this->assertEquals(0.0, $result->simulatedState['power_watts']);
    }

    /** @test */
    public function get_status_returns_current_simulated_relay_metrics()
    {
        $this->driver->sendCommand($this->shellyRelay, 'SWITCH_ON');

        $status = $this->driver->getStatus($this->shellyRelay);

        $this->assertTrue($status->success);
        $this->assertEquals('ON', $status->simulatedState['switch']);
        $this->assertArrayHasKey('voltage', $status->simulatedState);
        $this->assertArrayHasKey('temperature', $status->simulatedState);
    }

    /** @test */
    public function offline_scenario_returns_offline_result_code()
    {
        $this->simulationManager->setDeviceScenario($this->shellyRelay->id, HardwareSimulationManager::SCENARIO_OFFLINE);

        $result = $this->driver->sendCommand($this->shellyRelay, 'SWITCH_ON');

        $this->assertFalse($result->success);
        $this->assertEquals('OFFLINE', $result->resultCode);
        $this->assertStringContainsString('offline', strtolower($result->message));
    }

    /** @test */
    public function timeout_scenario_returns_timeout_result_code()
    {
        $this->simulationManager->setDeviceScenario($this->shellyRelay->id, HardwareSimulationManager::SCENARIO_TIMEOUT);

        $result = $this->driver->sendCommand($this->shellyRelay, 'SWITCH_ON');

        $this->assertFalse($result->success);
        $this->assertEquals('TIMEOUT', $result->resultCode);
    }

    /** @test */
    public function rejected_scenario_returns_command_rejected_result_code()
    {
        $this->simulationManager->setDeviceScenario($this->shellyRelay->id, HardwareSimulationManager::SCENARIO_REJECTED);

        $result = $this->driver->sendCommand($this->shellyRelay, 'SWITCH_ON');

        $this->assertFalse($result->success);
        $this->assertEquals('COMMAND_REJECTED', $result->resultCode);
    }

    /** @test */
    public function incompatible_device_throws_422()
    {
        $samsungTv = PhysicalDevice::create([
            'name' => 'Samsung TV 43',
            'category' => PhysicalDevice::CATEGORY_SMART_TV,
            'brand' => PhysicalDevice::BRAND_SAMSUNG,
            'model' => 'QA43Q60B',
            'mac_address' => '00:11:22:88:88:88',
            'ip_address' => '192.168.1.187',
            'connection_status' => PhysicalDevice::CONNECTION_UNVERIFIED,
            'is_active' => true,
        ]);

        $this->expectException(HardwareSimulationException::class);
        $this->expectExceptionCode(422);

        $this->driver->sendCommand($samsungTv, 'SWITCH_ON');
    }

    /** @test */
    public function mock_driver_never_modifies_real_physical_device_database_fields()
    {
        $this->driver->probe($this->shellyRelay);
        $this->driver->pair($this->shellyRelay);
        $this->driver->sendCommand($this->shellyRelay, 'SWITCH_ON');

        $fresh = $this->shellyRelay->fresh();

        $this->assertEquals(PhysicalDevice::CONNECTION_UNVERIFIED, $fresh->connection_status);
        $this->assertNull($fresh->pairing_payload);
        $this->assertNull($fresh->last_seen_at);
    }
}
