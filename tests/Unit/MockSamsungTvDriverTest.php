<?php

namespace Tests\Unit;

use App\Exceptions\HardwareSimulationException;
use App\Models\PhysicalDevice;
use App\Services\Hardware\Drivers\MockSamsungTvDriver;
use App\Services\Hardware\Simulation\HardwareSimulationManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class MockSamsungTvDriverTest extends TestCase
{
    use RefreshDatabase;

    protected HardwareSimulationManager $simulationManager;
    protected MockSamsungTvDriver $driver;
    protected PhysicalDevice $samsungTv;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('hardware.driver', 'mock');
        Config::set('hardware.simulation_enabled', true);
        Config::set('hardware.allowed_environments', ['local', 'testing']);
        Config::set('hardware.allowed_databases', ['kasir_ps_test']);

        $this->simulationManager = new HardwareSimulationManager();
        $this->driver = new MockSamsungTvDriver($this->simulationManager);

        $this->samsungTv = PhysicalDevice::create([
            'name' => 'Samsung 50 QLED Test',
            'category' => PhysicalDevice::CATEGORY_SMART_TV,
            'brand' => PhysicalDevice::BRAND_SAMSUNG,
            'model' => 'QA50Q60B',
            'mac_address' => '00:11:22:33:44:99',
            'ip_address' => '192.168.1.199',
            'connection_status' => PhysicalDevice::CONNECTION_UNVERIFIED,
            'pairing_status' => PhysicalDevice::PAIRING_UNVERIFIED,
            'is_active' => true,
        ]);

        $this->simulationManager->resetDevice($this->samsungTv->id);
    }

    /** @test */
    public function driver_identifies_as_mock_samsung_tv()
    {
        $this->assertEquals('MOCK_SAMSUNG_TV', $this->driver->getDriverType());
        $this->assertContains('POWER_ON', $this->driver->getSupportedCommands());
        $this->assertContains('STANDBY', $this->driver->getSupportedCommands());
        $this->assertContains('MUTE', $this->driver->getSupportedCommands());
        $this->assertContains('UNMUTE', $this->driver->getSupportedCommands());
        $this->assertContains('GET_STATUS', $this->driver->getSupportedCommands());
    }

    /** @test */
    public function power_on_command_transitions_simulated_state_to_on()
    {
        $result = $this->driver->sendCommand($this->samsungTv, 'POWER_ON');

        $this->assertTrue($result->success);
        $this->assertEquals('SUCCESS', $result->resultCode);
        $this->assertEquals('ON', $result->simulatedState['power']);
        $this->assertStringContainsString('dinyalakan', $result->message);

        // Verify simulated state persists in manager
        $persisted = $this->simulationManager->getSimulatedState($this->samsungTv->id);
        $this->assertEquals('ON', $persisted['power']);
    }

    /** @test */
    public function standby_command_transitions_simulated_state_to_standby()
    {
        // First turn on
        $this->driver->sendCommand($this->samsungTv, 'POWER_ON');

        // Then standby
        $result = $this->driver->sendCommand($this->samsungTv, 'STANDBY');

        $this->assertTrue($result->success);
        $this->assertEquals('SUCCESS', $result->resultCode);
        $this->assertEquals('STANDBY', $result->simulatedState['power']);
    }

    /** @test */
    public function mute_and_unmute_commands_toggle_simulated_audio()
    {
        $resMute = $this->driver->sendCommand($this->samsungTv, 'MUTE');
        $this->assertTrue($resMute->success);
        $this->assertTrue($resMute->simulatedState['mute']);

        $resUnmute = $this->driver->sendCommand($this->samsungTv, 'UNMUTE');
        $this->assertTrue($resUnmute->success);
        $this->assertFalse($resUnmute->simulatedState['mute']);
    }

    /** @test */
    public function get_status_returns_current_simulated_state()
    {
        $this->driver->sendCommand($this->samsungTv, 'POWER_ON');
        $this->driver->sendCommand($this->samsungTv, 'MUTE');

        $status = $this->driver->getStatus($this->samsungTv);

        $this->assertTrue($status->success);
        $this->assertEquals('ON', $status->simulatedState['power']);
        $this->assertTrue($status->simulatedState['mute']);
    }

    /** @test */
    public function offline_scenario_returns_offline_result_code()
    {
        $this->simulationManager->setDeviceScenario($this->samsungTv->id, HardwareSimulationManager::SCENARIO_OFFLINE);

        $result = $this->driver->sendCommand($this->samsungTv, 'POWER_ON');

        $this->assertFalse($result->success);
        $this->assertEquals('OFFLINE', $result->resultCode);
        $this->assertStringContainsString('offline', strtolower($result->message));
    }

    /** @test */
    public function timeout_scenario_returns_timeout_result_code()
    {
        $this->simulationManager->setDeviceScenario($this->samsungTv->id, HardwareSimulationManager::SCENARIO_TIMEOUT);

        $result = $this->driver->sendCommand($this->samsungTv, 'POWER_ON');

        $this->assertFalse($result->success);
        $this->assertEquals('TIMEOUT', $result->resultCode);
        $this->assertStringContainsString('habis', $result->message);
    }

    /** @test */
    public function rejected_scenario_returns_command_rejected_result_code()
    {
        $this->simulationManager->setDeviceScenario($this->samsungTv->id, HardwareSimulationManager::SCENARIO_REJECTED);

        $result = $this->driver->sendCommand($this->samsungTv, 'POWER_ON');

        $this->assertFalse($result->success);
        $this->assertEquals('COMMAND_REJECTED', $result->resultCode);
    }

    /** @test */
    public function invalid_command_returns_invalid_command_result()
    {
        $result = $this->driver->sendCommand($this->samsungTv, 'BLOW_UP');

        $this->assertFalse($result->success);
        $this->assertEquals('INVALID_COMMAND', $result->resultCode);
    }

    /** @test */
    public function incompatible_device_throws_422()
    {
        $shelly = PhysicalDevice::create([
            'name' => 'Shelly 1PM Test',
            'category' => PhysicalDevice::CATEGORY_SMART_RELAY,
            'brand' => PhysicalDevice::BRAND_SHELLY,
            'model' => 'Gen3 Mini',
            'mac_address' => 'AA:BB:CC:11:22:33',
            'ip_address' => '192.168.1.188',
            'connection_status' => PhysicalDevice::CONNECTION_UNVERIFIED,
            'is_active' => true,
        ]);

        $this->expectException(HardwareSimulationException::class);
        $this->expectExceptionCode(422);

        $this->driver->sendCommand($shelly, 'POWER_ON');
    }

    /** @test */
    public function inactive_device_throws_409()
    {
        $this->samsungTv->update(['is_active' => false]);

        $this->expectException(HardwareSimulationException::class);
        $this->expectExceptionCode(409);

        $this->driver->sendCommand($this->samsungTv, 'POWER_ON');
    }

    /** @test */
    public function mock_driver_never_modifies_real_physical_device_database_fields()
    {
        $this->driver->probe($this->samsungTv);
        $this->driver->pair($this->samsungTv);
        $this->driver->sendCommand($this->samsungTv, 'POWER_ON');

        $fresh = $this->samsungTv->fresh();

        // Must remain UNVERIFIED and null in database
        $this->assertEquals(PhysicalDevice::CONNECTION_UNVERIFIED, $fresh->connection_status);
        $this->assertEquals(PhysicalDevice::PAIRING_UNVERIFIED, $fresh->pairing_status);
        $this->assertNull($fresh->pairing_payload);
        $this->assertNull($fresh->last_seen_at);
    }
}
