<?php

namespace Tests\Feature;

use App\Models\AutomationIntent;
use App\Models\Device;
use App\Models\PhysicalDevice;
use App\Models\Playstation;
use App\Models\StationDeviceMapping;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Hardware\Automation\AutomationStatusService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeviceTimerSyncExpirationTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;
    protected Device $station;
    protected PhysicalDevice $physicalDevice;
    protected StationDeviceMapping $mapping;
    protected Playstation $ps;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertEquals('kasir_ps_test', DB::connection()->getDatabaseName(), 'Must run on kasir_ps_test');

        Config::set('hardware.driver', 'mock');
        Config::set('hardware.real_execution_enabled', false);
        Config::set('hardware.simulation_enabled', true);
        Config::set('hardware.automation_enabled', true);
        Config::set('hardware.automation.enabled', true);
        Config::set('hardware.allowed_environments', ['local', 'testing']);
        Config::set('hardware.allowed_databases', ['kasir_ps_test']);

        $this->user = User::firstOrCreate(
            ['email' => 'kasir_timer_test@sugeng.local'],
            ['name' => 'Kasir Timer Test', 'role' => 'kasir', 'password' => bcrypt('password')]
        );

        $this->ps = Playstation::firstOrCreate(
            ['nama' => 'PS5 Timer Test'],
            ['harga' => 15000]
        );

        $this->station = Device::create([
            'nama' => 'Station Timer Test ' . uniqid(),
            'status' => 'Digunakan',
            'playstation_id' => $this->ps->id,
        ]);
        $this->station->playstations()->sync([$this->ps->id]);

        $this->physicalDevice = PhysicalDevice::create([
            'name' => 'Mock TV Timer Test ' . uniqid(),
            'category' => PhysicalDevice::CATEGORY_SMART_TV,
            'brand' => PhysicalDevice::BRAND_SAMSUNG,
            'model' => 'QE55Q70',
            'ip_address' => '192.168.1.199',
            'mac_address' => 'AA:BB:CC:11:22:' . rand(10, 99),
            'is_active' => true,
            'connection_status' => 'ONLINE',
            'verified_capabilities' => ['power_on', 'standby', 'alerts'],
        ]);

        $this->mapping = StationDeviceMapping::create([
            'station_id' => $this->station->id,
            'physical_device_id' => $this->physicalDevice->id,
            'mapping_role' => StationDeviceMapping::ROLE_DISPLAY_TV,
            'mapping_status' => StationDeviceMapping::STATUS_ACTIVE,
            'attached_by_user_id' => $this->user->id,
            'attached_at' => Carbon::now(),
        ]);
    }

    public function test_future_intent_does_not_show_command_pending_badge(): void
    {
        $statusService = app(AutomationStatusService::class);

        // Record a future intent (e.g. BILLING_EXPIRED scheduled in 1 hour)
        AutomationIntent::create([
            'station_id' => $this->station->id,
            'station_name' => $this->station->nama,
            'physical_device_id' => $this->physicalDevice->id,
            'station_device_mapping_id' => $this->mapping->id,
            'session_generation' => 1,
            'event_type' => AutomationIntent::EVENT_BILLING_EXPIRED,
            'desired_state' => AutomationIntent::STATE_OFF,
            'target_channel' => 0,
            'status' => AutomationIntent::STATUS_PENDING,
            'attempts' => 0,
            'max_attempts' => 4,
            'scheduled_at' => Carbon::now()->addHour(),
            'idempotency_key' => 'test-future-intent-' . uniqid(),
        ]);

        $this->station->update(['last_hardware_state' => 'STATE_ON']);

        $status = $statusService->getCashierStatus($this->station->fresh());

        // Should NOT be COMMAND_PENDING because scheduled_at is in the future
        $this->assertNotEquals('COMMAND_PENDING', $status['hardware_badge']['code']);
        $this->assertEquals('STATE_ON', $status['hardware_badge']['code']);
    }

    public function test_timer_sync_heartbeat_automatically_expires_session_and_turns_off_hardware(): void
    {
        // 1. Create a transaction that expired 2 minutes ago
        $now = Carbon::now();
        $tx = Transaction::create([
            'status' => 'user',
            'device_id' => $this->station->id,
            'nama' => 'Pelanggan Habis Waktu',
            'tipe_transaksi' => 'prepaid',
            'harga' => 15000,
            'jam_main' => '01:00:00',
            'waktu_mulai' => $now->copy()->subMinutes(62)->format('H:i:s'),
            'waktu_Selesai' => $now->copy()->subMinutes(2)->format('H:i:s'),
            'total' => 15000,
            'status_transaksi' => 'berjalan',
            'billing_status' => Transaction::BILLING_RUNNING,
            'started_at' => $now->copy()->subMinutes(62),
            'expires_at' => $now->copy()->subMinutes(2),
        ]);

        // Pre-create the BILLING_EXPIRED intent scheduled for expires_at
        $intent = AutomationIntent::create([
            'station_id' => $this->station->id,
            'station_name' => $this->station->nama,
            'physical_device_id' => $this->physicalDevice->id,
            'station_device_mapping_id' => $this->mapping->id,
            'transaction_id' => $tx->id_transaksi,
            'session_generation' => 1,
            'event_type' => AutomationIntent::EVENT_BILLING_EXPIRED,
            'desired_state' => AutomationIntent::STATE_OFF,
            'target_channel' => 0,
            'status' => AutomationIntent::STATUS_PENDING,
            'attempts' => 0,
            'max_attempts' => 4,
            'scheduled_at' => $tx->expires_at,
            'idempotency_key' => 'test-expired-intent-' . uniqid(),
        ]);

        // 2. Client browser fetches /device/timers
        $response = $this->actingAs($this->user)->getJson(route('device.timers'));

        $response->assertOk();

        // 3. Verify transaction was automatically transitioned
        $tx->refresh();
        $this->assertEquals(Transaction::BILLING_EXPIRED_PENDING_ACTION, $tx->billing_status);
        $this->assertEquals('selesai', $tx->status_transaksi);

        // 4. Verify station was set to Tersedia
        $this->station->refresh();
        $this->assertEquals('Tersedia', $this->station->status);

        // 5. Verify the automation intent was dispatched and completed
        $intent->refresh();
        $this->assertEquals(AutomationIntent::STATUS_COMPLETED, $intent->status);
    }
}
