<?php

namespace Tests\Feature\Hardware;

use App\Models\AutomationIntent;
use App\Models\Device;
use App\Models\PhysicalDevice;
use App\Models\Playstation;
use App\Models\StationDeviceMapping;
use App\Models\User;
use App\Services\Hardware\Automation\AutomationDispatcherService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AutomationSchedulerTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;
    protected Device $station;
    protected PhysicalDevice $physicalDevice;
    protected StationDeviceMapping $mapping;

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
            ['email' => 'admin_scheduler@test.local'],
            ['name' => 'Admin Scheduler', 'role' => 'admin', 'password' => bcrypt('secret')]
        );

        $ps = Playstation::firstOrCreate(
            ['nama' => 'PS Scheduler Test'],
            ['harga' => 10000]
        );

        $this->station = Device::firstOrCreate(
            ['nama' => 'Station Scheduler Test'],
            ['status' => 'Tersedia', 'session_generation' => 1, 'playstation_id' => $ps->id]
        );
        $this->station->update(['status' => 'Tersedia', 'session_generation' => 1]);

        $this->physicalDevice = PhysicalDevice::firstOrCreate(
            ['mac_address' => 'AA:BB:CC:DD:EF:F1'],
            [
                'name' => 'Shelly Scheduler Test',
                'category' => PhysicalDevice::CATEGORY_SMART_RELAY,
                'brand' => PhysicalDevice::BRAND_SHELLY,
                'model' => 'Shelly 1PM Mini Gen3',
                'ip_address' => '192.168.1.191',
                'is_active' => true,
                'connection_status' => 'ONLINE',
                'verified_capabilities' => ['SWITCH_ON', 'SWITCH_OFF', 'GET_STATUS'],
            ]
        );
        $this->physicalDevice->update(['is_active' => true]);

        $this->mapping = StationDeviceMapping::firstOrCreate(
            ['station_id' => $this->station->id, 'physical_device_id' => $this->physicalDevice->id],
            [
                'mapping_role' => StationDeviceMapping::ROLE_LIGHT_RELAY,
                'mapping_status' => StationDeviceMapping::STATUS_ACTIVE,
                'attached_by_user_id' => $this->admin->id,
                'attached_at' => now(),
            ]
        );
        $this->mapping->update(['mapping_status' => StationDeviceMapping::STATUS_ACTIVE]);
    }

    public function test_automation_worker_command_is_scheduled(): void
    {
        $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
        $events = $schedule->events();
        $commandStrings = array_map(fn($e) => $e->command ?? '', $events);
        $found = collect($commandStrings)->contains(fn($cmd) => str_contains($cmd, 'billing:automation-worker'));
        $this->assertTrue($found, 'billing:automation-worker must be scheduled in Kernel.php');
    }

    public function test_dispatch_batch_processes_pending_intent(): void
    {
        $intent = AutomationIntent::create([
            'station_id' => $this->station->id,
            'station_name' => $this->station->nama,
            'physical_device_id' => $this->physicalDevice->id,
            'station_device_mapping_id' => $this->mapping->id,
            'session_generation' => 1,
            'event_type' => AutomationIntent::EVENT_BILLING_STARTED,
            'desired_state' => AutomationIntent::STATE_ON,
            'target_channel' => 0,
            'status' => AutomationIntent::STATUS_PENDING,
            'attempts' => 0,
            'max_attempts' => 4,
            'scheduled_at' => Carbon::now(),
            'idempotency_key' => 'scheduler-test-' . uniqid('', true),
        ]);

        $dispatched = app(AutomationDispatcherService::class)->dispatchBatch(1, Carbon::now());

        $this->assertEquals(1, $dispatched, 'dispatchBatch must process the pending intent');
        $intent->refresh();
        $this->assertEquals(AutomationIntent::STATUS_COMPLETED, $intent->status,
            'Intent must be COMPLETED after dispatchBatch');
        $this->assertEquals('SIMULATED_ON', $this->station->fresh()->last_hardware_state);
    }
}
