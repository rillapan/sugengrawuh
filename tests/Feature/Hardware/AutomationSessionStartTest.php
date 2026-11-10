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
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AutomationSessionStartTest extends TestCase
{
    use DatabaseTransactions;

    protected User $kasir;
    protected Playstation $ps;
    protected Device $station;
    protected PhysicalDevice $physicalDevice;
    protected StationDeviceMapping $mapping;
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
            ['email' => 'admin_session_start@test.local'],
            ['name' => 'Admin Session Start', 'role' => 'admin', 'password' => bcrypt('secret')]
        );
        $this->kasir = User::firstOrCreate(
            ['email' => 'kasir_session_start@test.local'],
            ['name' => 'Kasir Session Start', 'role' => 'kasir', 'password' => bcrypt('secret')]
        );

        $this->ps = Playstation::firstOrCreate(
            ['nama' => 'PS Session Start Test'],
            ['harga' => 10000]
        );

        $this->station = Device::firstOrCreate(
            ['nama' => 'Station Session Start Test'],
            ['status' => 'Tersedia', 'session_generation' => 0, 'playstation_id' => $this->ps->id]
        );
        $this->station->update(['status' => 'Tersedia', 'session_generation' => 0]);

        $this->physicalDevice = PhysicalDevice::firstOrCreate(
            ['mac_address' => 'AA:BB:CC:DD:EE:F0'],
            [
                'name' => 'Shelly Session Start Test',
                'category' => PhysicalDevice::CATEGORY_SMART_RELAY,
                'brand' => PhysicalDevice::BRAND_SHELLY,
                'model' => 'Shelly 1PM Mini Gen3',
                'ip_address' => '192.168.1.190',
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

        Carbon::setTestNow(Carbon::parse('2026-11-10 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // Case 1: Paket (prepaid) start → ON intent + pre-scheduled OFF intent
    public function test_paket_start_creates_on_intent(): void
    {
        $tx = $this->billing->startSession([
            'device_id' => $this->station->id,
            'nama' => 'Pelanggan Paket',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
        ], $this->kasir->id);

        $this->assertEquals(Transaction::BILLING_RUNNING, $tx->billing_status);

        $intent = AutomationIntent::where('transaction_id', $tx->id_transaksi)
            ->where('event_type', AutomationIntent::EVENT_BILLING_STARTED)
            ->first();

        $this->assertNotNull($intent, 'ON intent must be created for Paket start');
        $this->assertEquals(AutomationIntent::STATE_ON, $intent->desired_state);
        $this->assertEquals(AutomationIntent::STATUS_PENDING, $intent->status);
        $this->assertEquals($this->station->id, $intent->station_id);

        // Pre-scheduled OFF also exists
        $offIntent = AutomationIntent::where('transaction_id', $tx->id_transaksi)
            ->where('event_type', AutomationIntent::EVENT_BILLING_EXPIRED)
            ->first();
        $this->assertNotNull($offIntent, 'Pre-scheduled OFF intent must exist for prepaid');
        $this->assertEquals(AutomationIntent::STATE_OFF, $offIntent->desired_state);
    }

    // Case 2: Lost Time (postpaid) start → ON intent, NO pre-scheduled OFF
    public function test_lost_time_start_creates_on_intent(): void
    {
        $tx = $this->billing->startSession([
            'device_id' => $this->station->id,
            'nama' => 'Pelanggan Lost Time',
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        $this->assertEquals(Transaction::BILLING_RUNNING, $tx->billing_status);
        $this->assertNull($tx->expires_at, 'Lost Time must have no expires_at');

        $intent = AutomationIntent::where('transaction_id', $tx->id_transaksi)
            ->where('event_type', AutomationIntent::EVENT_BILLING_STARTED)
            ->first();

        $this->assertNotNull($intent, 'ON intent must be created for Lost Time start');
        $this->assertEquals(AutomationIntent::STATE_ON, $intent->desired_state);
        $this->assertEquals(AutomationIntent::STATUS_PENDING, $intent->status);

        // No pre-scheduled OFF for Lost Time
        $offCount = AutomationIntent::where('transaction_id', $tx->id_transaksi)
            ->where('event_type', AutomationIntent::EVENT_BILLING_EXPIRED)
            ->count();
        $this->assertEquals(0, $offCount, 'Lost Time must NOT have a pre-scheduled OFF intent');
    }

    // Case 3: Custom Paket (custom_package) start → ON intent + pre-scheduled OFF
    public function test_custom_paket_start_creates_on_intent(): void
    {
        $customPackage = CustomPackage::create(['nama_paket' => 'Paket Hemat Test', 'harga_total' => 50000]);
        $customPackage->playstations()->attach($this->ps->id, ['lama_main' => 90]); // 90 minutes

        $tx = $this->billing->startSession([
            'device_id' => $this->station->id,
            'nama' => 'Pelanggan Custom Paket',
            'tipe_transaksi' => 'custom_package',
            'custom_package_id' => $customPackage->id,
        ], $this->kasir->id);

        $this->assertEquals(Transaction::BILLING_RUNNING, $tx->billing_status);
        $this->assertNotNull($tx->expires_at, 'Custom Paket must have expires_at');

        $intent = AutomationIntent::where('transaction_id', $tx->id_transaksi)
            ->where('event_type', AutomationIntent::EVENT_BILLING_STARTED)
            ->first();

        $this->assertNotNull($intent, 'ON intent must be created for Custom Paket start');
        $this->assertEquals(AutomationIntent::STATE_ON, $intent->desired_state);
        $this->assertEquals(AutomationIntent::STATUS_PENDING, $intent->status);

        // Pre-scheduled OFF also exists
        $offIntent = AutomationIntent::where('transaction_id', $tx->id_transaksi)
            ->where('event_type', AutomationIntent::EVENT_BILLING_EXPIRED)
            ->first();
        $this->assertNotNull($offIntent, 'Pre-scheduled OFF intent must exist for Custom Paket');
        $this->assertEquals(AutomationIntent::STATE_OFF, $offIntent->desired_state);
    }

    // Case 4: Extend does NOT create a new ON intent
    public function test_extend_does_not_create_new_on_intent(): void
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

        $this->billing->extendSession($tx->id_transaksi, 30, true); // extend by 30 minutes

        $onCountAfter = AutomationIntent::where('transaction_id', $tx->id_transaksi)
            ->where('event_type', AutomationIntent::EVENT_BILLING_STARTED)
            ->count();

        $this->assertEquals($onCountBefore, $onCountAfter, 'Extend must NOT create a new ON intent');
    }

    // Case 5: Failed transaction (device not available) → no intent created
    public function test_failed_transaction_does_not_create_intent(): void
    {
        // Mark device as already in use
        $this->station->update(['status' => 'Digunakan']);

        $exceptionThrown = false;
        try {
            $this->billing->startSession([
                'device_id' => $this->station->id,
                'nama' => 'Pelanggan Gagal',
                'tipe_transaksi' => 'prepaid',
                'jam_main' => 1,
            ], $this->kasir->id);
        } catch (\RuntimeException $e) {
            $exceptionThrown = true;
        }

        $this->assertTrue($exceptionThrown, 'Billing on unavailable device must throw');

        $intentCount = AutomationIntent::where('station_id', $this->station->id)->count();
        $this->assertEquals(0, $intentCount, 'No intent must be created for a failed transaction');
    }

    // Case 6: Station without active hardware mapping → billing succeeds, no intent created
    public function test_station_without_mapping_billing_succeeds(): void
    {
        // Deactivate the mapping
        $this->mapping->update(['mapping_status' => StationDeviceMapping::STATUS_INACTIVE]);

        $tx = $this->billing->startSession([
            'device_id' => $this->station->id,
            'nama' => 'Pelanggan No Mapping',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
        ], $this->kasir->id);

        $this->assertNotNull($tx, 'Billing must succeed even without hardware mapping');
        $this->assertEquals(Transaction::BILLING_RUNNING, $tx->billing_status);

        // recordIntent() creates SKIPPED intents when no active mapping — no PENDING ones should exist
        $pendingCount = AutomationIntent::where('transaction_id', $tx->id_transaksi)
            ->where('status', AutomationIntent::STATUS_PENDING)
            ->count();
        $this->assertEquals(0, $pendingCount, 'No PENDING intent must be created when no active mapping exists');
    }
}
