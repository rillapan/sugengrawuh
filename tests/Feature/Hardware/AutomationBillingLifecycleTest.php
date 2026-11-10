<?php

namespace Tests\Feature\Hardware;

use App\Models\AutomationIntent;
use App\Models\CustomPackage;
use App\Models\Device;
use App\Models\PhysicalDevice;
use App\Models\Playstation;
use App\Models\StationDeviceMapping;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BillingService;
use App\Services\Hardware\Automation\AutomationDispatcherService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AutomationBillingLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    protected User $kasir;
    protected Device $station;
    protected PhysicalDevice $physicalDevice;
    protected StationDeviceMapping $mapping;
    protected Playstation $ps;
    protected BillingService $billing;

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

        $admin = User::firstOrCreate(
            ['email' => 'admin_lifecycle@test.local'],
            ['name' => 'Admin Lifecycle', 'role' => 'admin', 'password' => bcrypt('secret')]
        );
        $this->kasir = User::firstOrCreate(
            ['email' => 'kasir_lifecycle@test.local'],
            ['name' => 'Kasir Lifecycle', 'role' => 'kasir', 'password' => bcrypt('secret')]
        );

        $this->ps = Playstation::firstOrCreate(
            ['nama' => 'PS Lifecycle Test'],
            ['harga' => 10000]
        );

        $this->station = Device::firstOrCreate(
            ['nama' => 'Station Lifecycle Test'],
            ['status' => 'Tersedia', 'session_generation' => 0, 'playstation_id' => $this->ps->id]
        );
        $this->station->update(['status' => 'Tersedia', 'session_generation' => 0]);

        $this->physicalDevice = PhysicalDevice::firstOrCreate(
            ['mac_address' => 'AA:BB:CC:DD:EE:F1'],
            [
                'name' => 'Shelly Lifecycle Test',
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
                'attached_by_user_id' => $admin->id,
                'attached_at' => now(),
            ]
        );
        $this->mapping->update(['mapping_status' => StationDeviceMapping::STATUS_ACTIVE]);

        $this->billing = app(BillingService::class);

        Carbon::setTestNow(Carbon::parse('2026-11-10 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // 1. Paket start → ON intent created
    public function test_paket_session_start_creates_on_intent(): void
    {
        $tx = $this->billing->startSession([
            'device_id' => $this->station->id,
            'nama' => 'Pelanggan Paket',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
        ], $this->kasir->id);

        $intent = AutomationIntent::where('transaction_id', $tx->id_transaksi)
            ->where('event_type', AutomationIntent::EVENT_BILLING_STARTED)
            ->first();

        $this->assertNotNull($intent, 'ON intent must be created on Paket start');
        $this->assertEquals(AutomationIntent::STATE_ON, $intent->desired_state);
        $this->assertEquals(AutomationIntent::STATUS_PENDING, $intent->status);
        $this->assertEquals($this->station->id, $intent->station_id);
    }

    // 2. Paket expiry → OFF intent created
    public function test_paket_expiry_creates_off_intent(): void
    {
        $tx = $this->billing->startSession([
            'device_id' => $this->station->id,
            'nama' => 'Pelanggan Paket Expiry',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
        ], $this->kasir->id);

        $tx->refresh();
        Carbon::setTestNow($tx->expires_at->addMinute());
        $this->billing->evaluateExpiration(Carbon::now());

        $intent = AutomationIntent::where('station_id', $this->station->id)
            ->where('event_type', AutomationIntent::EVENT_BILLING_EXPIRED)
            ->where('desired_state', AutomationIntent::STATE_OFF)
            ->first();

        $this->assertNotNull($intent, 'OFF intent must be created on Paket expiry');
        $this->assertEquals(AutomationIntent::STATE_OFF, $intent->desired_state);
    }

    // 3. Paket manual stop → OFF intent created
    public function test_paket_manual_stop_creates_off_intent(): void
    {
        $tx = $this->billing->startSession([
            'device_id' => $this->station->id,
            'nama' => 'Pelanggan Paket Stop',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
        ], $this->kasir->id);

        $this->billing->stopSession($tx->id_transaksi);

        $intent = AutomationIntent::where('station_id', $this->station->id)
            ->where('event_type', AutomationIntent::EVENT_BILLING_STOPPED)
            ->where('desired_state', AutomationIntent::STATE_OFF)
            ->first();

        $this->assertNotNull($intent, 'OFF intent must be created on Paket manual stop');
        $this->assertEquals(AutomationIntent::STATE_OFF, $intent->desired_state);
    }

    // 4. Lost Time start → ON intent created, expires_at = NULL
    public function test_lost_time_start_creates_on_intent(): void
    {
        $tx = $this->billing->startSession([
            'device_id' => $this->station->id,
            'nama' => 'Pelanggan Lost Time',
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        $this->assertNull($tx->expires_at, 'Lost Time must have no expires_at');

        $intent = AutomationIntent::where('transaction_id', $tx->id_transaksi)
            ->where('event_type', AutomationIntent::EVENT_BILLING_STARTED)
            ->first();

        $this->assertNotNull($intent, 'ON intent must be created on Lost Time start');
        $this->assertEquals(AutomationIntent::STATE_ON, $intent->desired_state);
        $this->assertEquals(AutomationIntent::STATUS_PENDING, $intent->status);
    }

    // 5. Lost Time → no auto OFF on expiry (expires_at = NULL excluded from evaluateExpiration)
    public function test_lost_time_no_off_on_expiry(): void
    {
        $this->billing->startSession([
            'device_id' => $this->station->id,
            'nama' => 'Pelanggan Lost Time No Expiry',
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        Carbon::setTestNow(Carbon::now()->addHours(10));
        $this->billing->evaluateExpiration(Carbon::now());

        $offCount = AutomationIntent::where('station_id', $this->station->id)
            ->where('event_type', AutomationIntent::EVENT_BILLING_EXPIRED)
            ->count();

        $this->assertEquals(0, $offCount, 'Lost Time must NOT receive an auto-expiry OFF intent');
    }

    // 6. Lost Time manual stop → OFF intent created
    public function test_lost_time_manual_stop_creates_off_intent(): void
    {
        $tx = $this->billing->startSession([
            'device_id' => $this->station->id,
            'nama' => 'Pelanggan Lost Time Stop',
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        Carbon::setTestNow(Carbon::now()->addHour());
        $this->billing->stopSession($tx->id_transaksi);

        $intent = AutomationIntent::where('station_id', $this->station->id)
            ->where('event_type', AutomationIntent::EVENT_BILLING_STOPPED)
            ->where('desired_state', AutomationIntent::STATE_OFF)
            ->first();

        $this->assertNotNull($intent, 'OFF intent must be created on Lost Time manual stop');
    }

    // 7. Custom Paket start → ON intent created
    public function test_custom_paket_start_creates_on_intent(): void
    {
        $pkg = CustomPackage::create(['nama_paket' => 'Paket Lifecycle Test', 'harga_total' => 50000]);
        $pkg->playstations()->attach($this->ps->id, ['lama_main' => 60]);

        $tx = $this->billing->startSession([
            'device_id' => $this->station->id,
            'nama' => 'Pelanggan Custom Paket',
            'tipe_transaksi' => 'custom_package',
            'custom_package_id' => $pkg->id,
        ], $this->kasir->id);

        $this->assertNotNull($tx->expires_at, 'Custom Paket must have expires_at');

        $intent = AutomationIntent::where('transaction_id', $tx->id_transaksi)
            ->where('event_type', AutomationIntent::EVENT_BILLING_STARTED)
            ->first();

        $this->assertNotNull($intent, 'ON intent must be created on Custom Paket start');
        $this->assertEquals(AutomationIntent::STATE_ON, $intent->desired_state);
    }

    // 8. Custom Paket expiry → OFF intent created
    public function test_custom_paket_expiry_creates_off_intent(): void
    {
        $pkg = CustomPackage::create(['nama_paket' => 'Paket Expiry Test', 'harga_total' => 30000]);
        $pkg->playstations()->attach($this->ps->id, ['lama_main' => 60]);

        $tx = $this->billing->startSession([
            'device_id' => $this->station->id,
            'nama' => 'Pelanggan Custom Expiry',
            'tipe_transaksi' => 'custom_package',
            'custom_package_id' => $pkg->id,
        ], $this->kasir->id);

        $tx->refresh();
        Carbon::setTestNow($tx->expires_at->addMinute());
        $this->billing->evaluateExpiration(Carbon::now());

        $intent = AutomationIntent::where('station_id', $this->station->id)
            ->where('event_type', AutomationIntent::EVENT_BILLING_EXPIRED)
            ->where('desired_state', AutomationIntent::STATE_OFF)
            ->first();

        $this->assertNotNull($intent, 'OFF intent must be created on Custom Paket expiry');
    }

    // 9. Extend does NOT create a new ON intent
    public function test_extend_does_not_create_intent(): void
    {
        $tx = $this->billing->startSession([
            'device_id' => $this->station->id,
            'nama' => 'Pelanggan Extend',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
        ], $this->kasir->id);

        $onCountBefore = AutomationIntent::where('transaction_id', $tx->id_transaksi)
            ->where('event_type', AutomationIntent::EVENT_BILLING_STARTED)
            ->count();

        $this->billing->extendSession($tx->id_transaksi, 30, true);

        $onCountAfter = AutomationIntent::where('transaction_id', $tx->id_transaksi)
            ->where('event_type', AutomationIntent::EVENT_BILLING_STARTED)
            ->count();

        $this->assertEquals($onCountBefore, $onCountAfter, 'Extend must NOT create a new ON intent');
    }

    // 10. Failed transaction (device in use) → no intent created
    public function test_failed_transaction_does_not_create_intent(): void
    {
        $this->station->update(['status' => 'Digunakan']);

        $threw = false;
        try {
            $this->billing->startSession([
                'device_id' => $this->station->id,
                'nama' => 'Pelanggan Gagal',
                'tipe_transaksi' => 'prepaid',
                'jam_main' => 1,
            ], $this->kasir->id);
        } catch (\RuntimeException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Must throw on unavailable device');
        $this->assertEquals(0, AutomationIntent::where('station_id', $this->station->id)->count(),
            'No intent must be created for a failed transaction');
    }

    // 11. No active mapping → billing succeeds, no PENDING intent
    public function test_station_without_mapping_billing_succeeds(): void
    {
        $this->mapping->update(['mapping_status' => StationDeviceMapping::STATUS_INACTIVE]);

        $tx = $this->billing->startSession([
            'device_id' => $this->station->id,
            'nama' => 'Pelanggan No Mapping',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
        ], $this->kasir->id);

        $this->assertNotNull($tx, 'Billing must succeed without hardware mapping');
        $this->assertEquals(Transaction::BILLING_RUNNING, $tx->billing_status);

        $pendingCount = AutomationIntent::where('transaction_id', $tx->id_transaksi)
            ->where('status', AutomationIntent::STATUS_PENDING)
            ->count();
        $this->assertEquals(0, $pendingCount, 'No PENDING intent when no active mapping');
    }

    // 12. Manual power dispatch works during active session
    public function test_manual_power_control_still_works_during_active_session(): void
    {
        $this->billing->startSession([
            'device_id' => $this->station->id,
            'nama' => 'Pelanggan Dispatch',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
        ], $this->kasir->id);

        $dispatcher = app(AutomationDispatcherService::class);
        $dispatched = $dispatcher->dispatchBatch(1, Carbon::now());

        $this->assertGreaterThanOrEqual(1, $dispatched,
            'dispatchBatch must process at least 1 pending intent during active session');

        $completed = AutomationIntent::where('station_id', $this->station->id)
            ->where('status', AutomationIntent::STATUS_COMPLETED)
            ->exists();
        $this->assertTrue($completed, 'At least one intent must reach COMPLETED status');
    }
}
