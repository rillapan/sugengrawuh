<?php

namespace Tests\Feature;

use App\Models\AutomationIntent;
use App\Models\Device;
use App\Models\PhysicalDevice;
use App\Models\Playstation;
use App\Models\StationDeviceMapping;
use App\Models\User;
use App\Services\Hardware\Automation\AutomationStatusService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DevicePageLiveStatusSyncTest extends TestCase
{
    use DatabaseTransactions;

    protected AutomationStatusService $service;
    protected Device $station;
    protected PhysicalDevice $physicalDevice;
    protected StationDeviceMapping $mapping;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertEquals('kasir_ps_test', DB::connection()->getDatabaseName(), 'Must run on kasir_ps_test');

        $this->service = app(AutomationStatusService::class);

        $ps = Playstation::firstOrCreate(
            ['nama' => 'PS4 StatusSync Test'],
            ['harga' => 10000]
        );

        $admin = User::firstOrCreate(
            ['email' => 'admin_statussync@test.local'],
            ['name' => 'Admin StatusSync', 'role' => 'admin', 'password' => bcrypt('secret')]
        );

        $this->station = Device::firstOrCreate(
            ['nama' => 'Station StatusSync Test'],
            ['status' => 'Tersedia', 'playstation_id' => $ps->id, 'session_generation' => 1]
        );
        $this->station->update(['status' => 'Tersedia', 'last_hardware_state' => 'STATE_UNKNOWN', 'last_hardware_verified_at' => null]);

        $this->physicalDevice = PhysicalDevice::firstOrCreate(
            ['mac_address' => 'AA:BB:CC:DD:EF:99'],
            [
                'name' => 'Shelly StatusSync Test',
                'category' => PhysicalDevice::CATEGORY_SMART_RELAY,
                'brand' => PhysicalDevice::BRAND_SHELLY,
                'model' => 'Shelly 1PM',
                'ip_address' => '192.168.1.250',
                'is_active' => true,
                'connection_status' => 'ONLINE',
            ]
        );
        $this->physicalDevice->update(['is_active' => true]);

        $this->mapping = StationDeviceMapping::firstOrCreate(
            ['station_id' => $this->station->id, 'physical_device_id' => $this->physicalDevice->id],
            [
                'mapping_role' => StationDeviceMapping::ROLE_LIGHT_RELAY,
                'mapping_status' => StationDeviceMapping::STATUS_ACTIVE,
                'attached_by_user_id' => $admin->id,
                'attached_at' => now(),
            ]
        );
        $this->mapping->update(['mapping_status' => StationDeviceMapping::STATUS_ACTIVE]);
    }

    // Case 1: station_status = Tersedia when no active session
    public function test_cashier_status_includes_station_status_tersedia(): void
    {
        $this->station->update(['status' => 'Tersedia']);
        $result = $this->service->getCashierStatus($this->station->fresh());
        $this->assertArrayHasKey('station_status', $result);
        $this->assertEquals('Tersedia', $result['station_status']);
    }

    // Case 2: station_status = Digunakan when active billing session
    public function test_cashier_status_includes_station_status_digunakan(): void
    {
        $this->station->update(['status' => 'Digunakan']);
        $result = $this->service->getCashierStatus($this->station->fresh());
        $this->assertEquals('Digunakan', $result['station_status']);
    }

    // Case 3: hardware_badge.code = SIMULATED_ON when last_hardware_state = SIMULATED_ON
    public function test_hardware_badge_simulated_on_when_last_state_is_simulated_on(): void
    {
        $this->station->update(['last_hardware_state' => 'SIMULATED_ON']);
        $result = $this->service->getCashierStatus($this->station->fresh());
        $this->assertEquals('SIMULATED_ON', $result['hardware_badge']['code']);
        $this->assertNotEquals('STATE_ON', $result['hardware_badge']['code']);
    }

    // Case 4: hardware_badge.code = COMMAND_PENDING when PENDING intent exists
    public function test_hardware_badge_command_pending_when_pending_intent(): void
    {
        AutomationIntent::create([
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
            'idempotency_key' => 'test-pending-' . uniqid(),
        ]);

        $result = $this->service->getCashierStatus($this->station->fresh());
        $this->assertEquals('COMMAND_PENDING', $result['hardware_badge']['code']);
    }

    // Case 5: hardware_badge.code = COMMAND_FAILED when recent FAILED intent
    public function test_hardware_badge_command_failed_when_recent_failed_intent(): void
    {
        AutomationIntent::create([
            'station_id' => $this->station->id,
            'station_name' => $this->station->nama,
            'physical_device_id' => $this->physicalDevice->id,
            'station_device_mapping_id' => $this->mapping->id,
            'session_generation' => 1,
            'event_type' => AutomationIntent::EVENT_BILLING_STOPPED,
            'desired_state' => AutomationIntent::STATE_OFF,
            'target_channel' => 0,
            'status' => AutomationIntent::STATUS_FAILED,
            'attempts' => 4,
            'max_attempts' => 4,
            'scheduled_at' => Carbon::now()->subMinutes(5),
            'updated_at' => Carbon::now()->subMinutes(5),
            'idempotency_key' => 'test-failed-' . uniqid(),
        ]);

        $result = $this->service->getCashierStatus($this->station->fresh());
        $this->assertEquals('COMMAND_FAILED', $result['hardware_badge']['code']);
    }

    // Case 6: Station Digunakan with no hardware → hardware_state = NO_HARDWARE, station_status = Digunakan
    public function test_no_hardware_station_digunakan_returns_both_correctly(): void
    {
        $ps = Playstation::firstOrCreate(['nama' => 'PS4 NoHW Test'], ['harga' => 10000]);
        $stationNoHw = Device::firstOrCreate(
            ['nama' => 'Station NoHW StatusSync'],
            ['status' => 'Tersedia', 'playstation_id' => $ps->id, 'session_generation' => 1]
        );
        $stationNoHw->update(['status' => 'Digunakan']);

        $result = $this->service->getCashierStatus($stationNoHw->fresh());
        $this->assertEquals('NO_HARDWARE', $result['hardware_state']);
        $this->assertEquals('Digunakan', $result['station_status']);
        $this->assertFalse($result['has_hardware']);
    }

    // Case 7: Station Tersedia with SIMULATED_ON → hardware_state and station_status are independent
    public function test_tersedia_station_with_simulated_on_returns_both_independently(): void
    {
        $this->station->update(['status' => 'Tersedia', 'last_hardware_state' => 'SIMULATED_ON']);
        $result = $this->service->getCashierStatus($this->station->fresh());
        $this->assertEquals('Tersedia', $result['station_status']);
        $this->assertEquals('SIMULATED_ON', $result['hardware_state']);
        $this->assertEquals('SIMULATED_ON', $result['hardware_badge']['code']);
    }
}
