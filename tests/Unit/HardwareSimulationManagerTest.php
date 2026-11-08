<?php

namespace Tests\Unit;

use App\Exceptions\HardwareSimulationException;
use App\Models\PhysicalDevice;
use App\Services\Hardware\DTO\HardwareCommandResult;
use App\Services\Hardware\Simulation\HardwareSimulationManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HardwareSimulationManagerTest extends TestCase
{
    protected HardwareSimulationManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new HardwareSimulationManager();

        // Enable mock simulation in testing
        Config::set('hardware.driver', 'mock');
        Config::set('hardware.simulation_enabled', true);
        Config::set('hardware.allowed_environments', ['local', 'testing']);
        Config::set('hardware.allowed_databases', ['kasir_ps_test']);
    }

    /** @test */
    public function guard_blocks_when_simulation_is_disabled()
    {
        Config::set('hardware.simulation_enabled', false);

        $this->expectException(HardwareSimulationException::class);
        $this->expectExceptionCode(403);
        $this->manager->assertSimulationAllowed();
    }

    /** @test */
    public function guard_blocks_when_driver_is_not_mock()
    {
        Config::set('hardware.driver', 'none');

        $this->expectException(HardwareSimulationException::class);
        $this->expectExceptionCode(403);
        $this->manager->assertSimulationAllowed();
    }

    /** @test */
    public function guard_blocks_when_active_database_is_production()
    {
        // Mock active database name to production DB
        DB::shouldReceive('connection->getDatabaseName')
            ->once()
            ->andReturn('billing-sugeng-rawuh');

        $this->expectException(HardwareSimulationException::class);
        $this->expectExceptionCode(403);
        $this->manager->assertSimulationAllowed();
    }

    /** @test */
    public function guard_passes_when_all_safety_conditions_are_met()
    {
        // Ensure active database matches testing allowed
        $this->assertEquals('kasir_ps_test', DB::connection()->getDatabaseName());

        $this->manager->assertSimulationAllowed();
        $this->assertTrue(true); // Reached without exception
    }

    /** @test */
    public function default_scenario_is_success()
    {
        $this->manager->resetDevice(101);
        $scenario = $this->manager->getDeviceScenario(101);

        $this->assertEquals(HardwareSimulationManager::SCENARIO_SUCCESS, $scenario);
    }

    /** @test */
    public function can_set_and_retrieve_deterministic_scenarios()
    {
        $this->manager->setDeviceScenario(101, HardwareSimulationManager::SCENARIO_OFFLINE);
        $this->assertEquals(HardwareSimulationManager::SCENARIO_OFFLINE, $this->manager->getDeviceScenario(101));

        $this->manager->setDeviceScenario(101, HardwareSimulationManager::SCENARIO_TIMEOUT);
        $this->assertEquals(HardwareSimulationManager::SCENARIO_TIMEOUT, $this->manager->getDeviceScenario(101));

        $this->manager->setDeviceScenario(101, HardwareSimulationManager::SCENARIO_REJECTED);
        $this->assertEquals(HardwareSimulationManager::SCENARIO_REJECTED, $this->manager->getDeviceScenario(101));

        $this->manager->setDeviceScenario(101, HardwareSimulationManager::SCENARIO_RECOVERED);
        $this->assertEquals(HardwareSimulationManager::SCENARIO_RECOVERED, $this->manager->getDeviceScenario(101));
    }

    /** @test */
    public function invalid_scenario_throws_422()
    {
        $this->expectException(HardwareSimulationException::class);
        $this->expectExceptionCode(422);

        $this->manager->setDeviceScenario(101, 'INVALID_SCENARIO_XYZ');
    }

    /** @test */
    public function simulated_state_can_be_stored_merged_and_retrieved()
    {
        $this->manager->resetDevice(202);

        $initial = $this->manager->getSimulatedState(202, ['power' => 'STANDBY']);
        $this->assertEquals(['power' => 'STANDBY'], $initial);

        $this->manager->updateSimulatedState(202, ['power' => 'ON', 'mute' => false]);
        $state = $this->manager->getSimulatedState(202);
        $this->assertEquals(['power' => 'ON', 'mute' => false], $state);

        // Merge new attribute
        $this->manager->updateSimulatedState(202, ['volume' => 25]);
        $updated = $this->manager->getSimulatedState(202);
        $this->assertEquals(['power' => 'ON', 'mute' => false, 'volume' => 25], $updated);
    }

    /** @test */
    public function simulation_events_are_buffered_and_retrieved_in_reverse_chronological_order()
    {
        $this->manager->resetDevice(303);

        $res1 = HardwareCommandResult::createSuccess(303, 'MOCK_TV', 'POWER_ON', 'OK', ['power' => 'ON']);
        $res2 = HardwareCommandResult::createOffline(303, 'MOCK_TV', 'MUTE');

        $this->manager->logEvent(303, $res1);
        $this->manager->logEvent(303, $res2);

        $events = $this->manager->getEvents(303, 10);

        $this->assertCount(2, $events);
        // Latest event first
        $this->assertEquals('MUTE', $events[0]['command']);
        $this->assertEquals('OFFLINE', $events[0]['result_code']);
        $this->assertEquals('POWER_ON', $events[1]['command']);
        $this->assertEquals('SUCCESS', $events[1]['result_code']);
    }

    /** @test */
    public function reset_clears_device_state_scenario_and_events()
    {
        $this->manager->setDeviceScenario(404, HardwareSimulationManager::SCENARIO_OFFLINE);
        $this->manager->updateSimulatedState(404, ['power' => 'ON']);
        $this->manager->logEvent(404, HardwareCommandResult::createSuccess(404, 'MOCK', 'TEST', 'OK'));

        $this->manager->resetDevice(404);

        $this->assertEquals(HardwareSimulationManager::SCENARIO_SUCCESS, $this->manager->getDeviceScenario(404));
        $this->assertEmpty($this->manager->getSimulatedState(404));
        $this->assertEmpty($this->manager->getEvents(404));
    }
}
