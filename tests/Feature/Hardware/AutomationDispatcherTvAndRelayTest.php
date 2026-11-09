<?php

namespace Tests\Feature\Hardware;

use App\Models\AutomationExecutionLog;
use App\Models\AutomationIntent;
use App\Models\Device;
use App\Models\PhysicalDevice;
use App\Models\Playstation;
use App\Models\StationDeviceMapping;
use App\Models\User;
use App\Services\Hardware\Automation\AutomationDispatcherService;
use App\Services\Hardware\Automation\AutomationIntentService;
use App\Services\Hardware\Simulation\HardwareSimulationManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AutomationDispatcherTvAndRelayTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;
    protected Device $tvStation;
    protected Device $relayStation;
    protected PhysicalDevice $tvDevice;
    protected PhysicalDevice $relayDevice;
    protected StationDeviceMapping $tvMapping;
    protected StationDeviceMapping $relayMapping;
    protected AutomationDispatcherService $dispatcher;
    protected AutomationIntentService $intentService;
    protected HardwareSimulationManager $simManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertEquals('kasir_ps_test', DB::connection()->getDatabaseName(), 'Must run on kasir_ps_test');

        Config::set('hardware.driver', 'mock');
        Config::set('hardware.real_execution_enabled', false);
        Config::set('hardware.simulation_enabled', true);
        Config::set('hardware.automation_enabled', true);
        Config::set('hardware.allowed_environments', ['local', 'testing']);
        Config::set('hardware.allowed_databases', ['kasir_ps_test']);

        $this->admin = User::firstOrCreate(
            ['email' => 'admin_dispatcher_tvrelay@test.local'],
            ['name' => 'Admin Dispatcher', 'role' => 'admin', 'password' => bcrypt('secret')]
        );

        $psCategory = Playstation::firstOrCreate(
            ['nama' => 'PS4 Dispatcher Test'],
            ['harga' => 10000]
        );

        // Samsung TV station
        $this->tvStation = Device::firstOrCreate(
            ['nama' => 'PS Dispatcher TV Test'],
            ['status' => 'Tersedia', 'session_generation' => 1, 'playstation_id' => $psCategory->id]
        );
        $this->tvStation->update(['status' => 'Tersedia', 'session_generation' => 1]);

        $this->tvDevice = PhysicalDevice::firstOrCreate(
            ['mac_address' => 'AA:BB:CC:DD:EF:01'],
            [
                'name' => 'Samsung TV Dispatcher Test',
                'category' => PhysicalDevice::CATEGORY_SMART_TV,
                'brand' => PhysicalDevice::BRAND_SAMSUNG,
                'model' => '43FE60',
                'ip_address' => '192.168.1.201',
                'is_active' => true,
                'connection_status' => 'ONLINE',
                'verified_capabilities' => ['POWER_ON', 'STANDBY', 'GET_STATUS'],
            ]
        );
        $this->tvDevice->update(['is_active' => true]);

        $this->tvMapping = StationDeviceMapping::firstOrCreate(
            ['station_id' => $this->tvStation->id, 'physical_device_id' => $this->tvDevice->id],
            [
                'mapping_role' => StationDeviceMapping::ROLE_DISPLAY_TV,
                'mapping_status' => StationDeviceMapping::STATUS_ACTIVE,
                'attached_by_user_id' => $this->admin->id,
                'attached_at' => now(),
            ]
        );
        $this->tvMapping->update(['mapping_status' => StationDeviceMapping::STATUS_ACTIVE]);

        // Shelly relay station
        $this->relayStation = Device::firstOrCreate(
            ['nama' => 'Billiard Dispatcher Relay Test'],
            ['status' => 'Tersedia', 'session_generation' => 1, 'playstation_id' => $psCategory->id]
        );
        $this->relayStation->update(['status' => 'Tersedia', 'session_generation' => 1]);

        $this->relayDevice = PhysicalDevice::firstOrCreate(
            ['mac_address' => 'AA:BB:CC:DD:EF:02'],
            [
                'name' => 'Shelly Relay Dispatcher Test',
                'category' => PhysicalDevice::CATEGORY_SMART_RELAY,
                'brand' => PhysicalDevice::BRAND_SHELLY,
                'model' => 'Shelly 1PM Mini Gen3',
                'ip_address' => '192.168.1.202',
                'is_active' => true,
                'connection_status' => 'ONLINE',
                'verified_capabilities' => ['SWITCH_ON', 'SWITCH_OFF', 'GET_STATUS'],
            ]
        );
        $this->relayDevice->update(['is_active' => true]);

        $this->relayMapping = StationDeviceMapping::firstOrCreate(
            ['station_id' => $this->relayStation->id, 'physical_device_id' => $this->relayDevice->id],
            [
                'mapping_role' => StationDeviceMapping::ROLE_LIGHT_RELAY,
                'mapping_status' => StationDeviceMapping::STATUS_ACTIVE,
                'attached_by_user_id' => $this->admin->id,
                'attached_at' => now(),
            ]
        );
        $this->relayMapping->update(['mapping_status' => StationDeviceMapping::STATUS_ACTIVE]);

        $this->dispatcher = app(AutomationDispatcherService::class);
        $this->intentService = app(AutomationIntentService::class);
        $this->simManager = app(HardwareSimulationManager::class);
        $this->simManager->resetAll();
    }

    // Helper: create a PENDING intent ready for dispatch
    private function makeIntent(Device $station, StationDeviceMapping $mapping, string $desired, int $gen = 1): AutomationIntent
    {
        $intent = AutomationIntent::create([
            'station_id' => $station->id,
            'station_name' => $station->nama,
            'physical_device_id' => $mapping->physical_device_id,
            'station_device_mapping_id' => $mapping->id,
            'session_generation' => $gen,
            'event_type' => $desired === AutomationIntent::STATE_ON
                ? AutomationIntent::EVENT_BILLING_STARTED
                : AutomationIntent::EVENT_BILLING_STOPPED,
            'desired_state' => $desired,
            'target_channel' => 0,
            'status' => AutomationIntent::STATUS_PENDING,
            'attempts' => 0,
            'max_attempts' => 4,
            'scheduled_at' => Carbon::now(),
            'idempotency_key' => 'test-' . uniqid('', true),
        ]);
        return $intent;
    }

    // Case 1 & 4 & 6 & 7: TV ON → POWER_ON, power field readback, SIMULATED_ON, command_sent correct
    public function test_tv_on_intent_sends_power_on_command(): void
    {
        $intent = $this->makeIntent($this->tvStation, $this->tvMapping, AutomationIntent::STATE_ON);

        $dispatched = $this->dispatcher->dispatchBatch(1, Carbon::now());
        $this->assertEquals(1, $dispatched);

        $intent->refresh();
        // Intent must be COMPLETED — POWER_ON is a valid TV command; SWITCH_ON would cause INVALID_COMMAND → RETRY_PENDING
        $this->assertEquals(AutomationIntent::STATUS_COMPLETED, $intent->status,
            'TV ON intent must complete: POWER_ON is accepted by TV driver; SWITCH_ON is not.');

        // Case 7: command_sent stores actual command, not "Switch.Set on=true"
        $log = AutomationExecutionLog::where('intent_id', $intent->id)->first();
        $this->assertNotNull($log);
        $this->assertEquals('POWER_ON', $log->command_sent);

        // Case 6: FAKE_TRANSPORT / simulation → SIMULATED_ON, never STATE_ON
        $this->assertEquals('SIMULATED_ON', $this->tvStation->fresh()->last_hardware_state);
    }

    // Case 2: TV OFF → STANDBY
    public function test_tv_off_intent_sends_standby_command(): void
    {
        $intent = $this->makeIntent($this->tvStation, $this->tvMapping, AutomationIntent::STATE_OFF);

        $dispatched = $this->dispatcher->dispatchBatch(1, Carbon::now());
        $this->assertEquals(1, $dispatched);

        $intent->refresh();
        $this->assertEquals(AutomationIntent::STATUS_COMPLETED, $intent->status,
            'TV OFF intent must complete: STANDBY is accepted by TV driver.');

        $log = AutomationExecutionLog::where('intent_id', $intent->id)->first();
        $this->assertNotNull($log);
        $this->assertEquals('STANDBY', $log->command_sent);
        $this->assertEquals('SIMULATED_OFF', $this->tvStation->fresh()->last_hardware_state);
    }

    // Case 3 (ON): Relay ON → SWITCH_ON
    public function test_relay_on_intent_sends_switch_on_command(): void
    {
        $intent = $this->makeIntent($this->relayStation, $this->relayMapping, AutomationIntent::STATE_ON);

        $dispatched = $this->dispatcher->dispatchBatch(1, Carbon::now());
        $this->assertEquals(1, $dispatched);

        $intent->refresh();
        $this->assertEquals(AutomationIntent::STATUS_COMPLETED, $intent->status);

        $log = AutomationExecutionLog::where('intent_id', $intent->id)->first();
        $this->assertNotNull($log);
        $this->assertEquals('SWITCH_ON', $log->command_sent);
        $this->assertEquals('SIMULATED_ON', $this->relayStation->fresh()->last_hardware_state);
    }

    // Case 3 (OFF) & 5: Relay OFF → SWITCH_OFF, readback uses output field
    public function test_relay_off_intent_sends_switch_off_command(): void
    {
        $intent = $this->makeIntent($this->relayStation, $this->relayMapping, AutomationIntent::STATE_OFF);

        $dispatched = $this->dispatcher->dispatchBatch(1, Carbon::now());
        $this->assertEquals(1, $dispatched);

        $intent->refresh();
        $this->assertEquals(AutomationIntent::STATUS_COMPLETED, $intent->status,
            'Relay OFF intent must complete with output field readback.');

        $log = AutomationExecutionLog::where('intent_id', $intent->id)->first();
        $this->assertNotNull($log);
        $this->assertEquals('SWITCH_OFF', $log->command_sent);
        $this->assertEquals('SIMULATED_OFF', $this->relayStation->fresh()->last_hardware_state);
    }
}
