<?php

namespace Tests\Feature;

use App\Models\AutomationIntent;
use App\Models\Device;
use App\Models\PhysicalDevice;
use App\Models\Playstation;
use App\Models\StationDeviceMapping;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BillingService;
use App\Services\Hardware\Automation\AutomationDispatcherService;
use App\Services\Hardware\Automation\AutomationFencingGuard;
use App\Services\Hardware\Automation\AutomationIntentService;
use App\Services\Hardware\Simulation\HardwareSimulationManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BillingAutoDeviceIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    protected BillingService $billing;
    protected AutomationDispatcherService $dispatcher;
    protected AutomationIntentService $intentService;
    protected HardwareSimulationManager $simManager;

    protected Device $tvStation;
    protected Device $relayStation;
    protected PhysicalDevice $tvDevice;
    protected PhysicalDevice $relayDevice;
    protected Playstation $ps;
    protected User $admin;

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

        $this->admin = User::firstOrCreate(
            ['email' => 'admin_billing_int@test.local'],
            ['name' => 'Admin Integration', 'role' => 'admin', 'password' => bcrypt('secret')]
        );

        $this->ps = Playstation::firstOrCreate(
            ['nama' => 'PS4 Integration Test'],
            ['harga' => 10000]
        );

        // PS station with Samsung TV
        $this->tvStation = Device::firstOrCreate(
            ['nama' => 'PS Station Integration TV'],
            ['status' => 'Tersedia', 'session_generation' => 0, 'playstation_id' => $this->ps->id]
        );
        $this->tvStation->update(['status' => 'Tersedia', 'session_generation' => 0]);

        $this->tvDevice = PhysicalDevice::firstOrCreate(
            ['mac_address' => 'BB:CC:DD:EE:FF:01'],
            [
                'name' => 'Samsung TV Integration',
                'category' => PhysicalDevice::CATEGORY_SMART_TV,
                'brand' => PhysicalDevice::BRAND_SAMSUNG,
                'model' => '43FE60',
                'ip_address' => '192.168.1.111',
                'is_active' => true,
                'connection_status' => 'ONLINE',
                'verified_capabilities' => ['POWER_ON', 'STANDBY', 'GET_STATUS'],
            ]
        );
        $this->tvDevice->update(['is_active' => true]);

        StationDeviceMapping::firstOrCreate(
            ['station_id' => $this->tvStation->id, 'physical_device_id' => $this->tvDevice->id],
            [
                'mapping_role' => StationDeviceMapping::ROLE_DISPLAY_TV,
                'mapping_status' => StationDeviceMapping::STATUS_ACTIVE,
                'attached_by_user_id' => $this->admin->id,
                'attached_at' => now(),
            ]
        )->update(['mapping_status' => StationDeviceMapping::STATUS_ACTIVE]);

        // Billiard station with Shelly relay
        $this->relayStation = Device::firstOrCreate(
            ['nama' => 'Billiard Station Integration Relay'],
            ['status' => 'Tersedia', 'session_generation' => 0, 'playstation_id' => $this->ps->id]
        );
        $this->relayStation->update(['status' => 'Tersedia', 'session_generation' => 0]);

        $this->relayDevice = PhysicalDevice::firstOrCreate(
            ['mac_address' => 'BB:CC:DD:EE:FF:02'],
            [
                'name' => 'Shelly Relay Integration',
                'category' => PhysicalDevice::CATEGORY_SMART_RELAY,
                'brand' => PhysicalDevice::BRAND_SHELLY,
                'model' => 'Shelly 1PM Mini Gen3',
                'ip_address' => '192.168.1.112',
                'is_active' => true,
                'connection_status' => 'ONLINE',
                'verified_capabilities' => ['SWITCH_ON', 'SWITCH_OFF', 'GET_STATUS'],
            ]
        );
        $this->relayDevice->update(['is_active' => true]);

        StationDeviceMapping::firstOrCreate(
            ['station_id' => $this->relayStation->id, 'physical_device_id' => $this->relayDevice->id],
            [
                'mapping_role' => StationDeviceMapping::ROLE_LIGHT_RELAY,
                'mapping_status' => StationDeviceMapping::STATUS_ACTIVE,
                'attached_by_user_id' => $this->admin->id,
                'attached_at' => now(),
            ]
        )->update(['mapping_status' => StationDeviceMapping::STATUS_ACTIVE]);

        $this->billing = app(BillingService::class);
        $this->dispatcher = app(AutomationDispatcherService::class);
        $this->intentService = app(AutomationIntentService::class);
        $this->simManager = app(HardwareSimulationManager::class);
        $this->simManager->resetAll();
    }

    // Test A — PS station billing start: one intent ON, station DIGUNAKAN, dispatches to SIMULATED_ON
    public function test_A_ps_station_billing_start_creates_one_on_intent(): void
    {
        $tx = $this->billing->startSession([
            'device_id' => $this->tvStation->id,
            'playstation_id' => $this->ps->id,
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
            'nama' => 'Test A Customer',
            'user_id' => $this->admin->id,
        ]);

        // Station is DIGUNAKAN
        $this->assertEquals('Digunakan', $this->tvStation->fresh()->status);

        // Exactly ONE intent ON for this station
        $onIntents = AutomationIntent::where('station_id', $this->tvStation->id)
            ->where('desired_state', AutomationIntent::STATE_ON)
            ->where('event_type', AutomationIntent::EVENT_BILLING_STARTED)
            ->get();
        $this->assertCount(1, $onIntents, 'Expected exactly one ON intent for PS station.');
        $this->assertEquals(AutomationIntent::STATUS_PENDING, $onIntents->first()->status);

        // Worker dispatches → COMPLETED, hardware state SIMULATED_ON
        $this->dispatcher->dispatchBatch(1, Carbon::now());
        $onIntents->first()->refresh();
        $this->assertEquals(AutomationIntent::STATUS_COMPLETED, $onIntents->first()->status);
        $this->assertEquals('SIMULATED_ON', $this->tvStation->fresh()->last_hardware_state);
    }

    // Test B — Billiard station billing start: one intent ON via relay
    public function test_B_billiard_station_billing_start_creates_one_on_intent(): void
    {
        $tx = $this->billing->startSession([
            'device_id' => $this->relayStation->id,
            'playstation_id' => $this->ps->id,
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
            'nama' => 'Test B Customer',
            'user_id' => $this->admin->id,
        ]);

        $this->assertEquals('Digunakan', $this->relayStation->fresh()->status);

        $onIntents = AutomationIntent::where('station_id', $this->relayStation->id)
            ->where('desired_state', AutomationIntent::STATE_ON)
            ->get();
        $this->assertCount(1, $onIntents);
        $this->assertEquals(AutomationIntent::STATUS_PENDING, $onIntents->first()->status);

        $this->dispatcher->dispatchBatch(1, Carbon::now());
        $onIntents->first()->refresh();
        $this->assertEquals(AutomationIntent::STATUS_COMPLETED, $onIntents->first()->status);
        $this->assertEquals('SIMULATED_ON', $this->relayStation->fresh()->last_hardware_state);
    }

    // Test C — Stop session: station TERSEDIA, one OFF intent dispatches to SIMULATED_OFF
    public function test_C_stop_session_creates_off_intent_and_frees_station(): void
    {
        $tx = $this->billing->startSession([
            'device_id' => $this->relayStation->id,
            'playstation_id' => $this->ps->id,
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
            'nama' => 'Test C Customer',
            'user_id' => $this->admin->id,
        ]);

        // Dispatch ON intent
        $this->dispatcher->dispatchBatch(1, Carbon::now());

        // Stop session
        $this->billing->stopSession($tx->id_transaksi);

        $this->assertEquals('Tersedia', $this->relayStation->fresh()->status);

        $offIntent = AutomationIntent::where('station_id', $this->relayStation->id)
            ->where('desired_state', AutomationIntent::STATE_OFF)
            ->where('event_type', AutomationIntent::EVENT_BILLING_STOPPED)
            ->where('status', AutomationIntent::STATUS_PENDING)
            ->first();
        $this->assertNotNull($offIntent, 'Expected a PENDING OFF intent after stop.');

        $this->dispatcher->dispatchBatch(1, Carbon::now());
        $offIntent->refresh();
        $this->assertEquals(AutomationIntent::STATUS_COMPLETED, $offIntent->status);
        $this->assertEquals('SIMULATED_OFF', $this->relayStation->fresh()->last_hardware_state);
    }

    // Test D — Extend: no new ON intent, old OFF cancelled, new OFF scheduled
    public function test_D_extend_session_cancels_old_off_and_schedules_new(): void
    {
        $tx = $this->billing->startSession([
            'device_id' => $this->relayStation->id,
            'playstation_id' => $this->ps->id,
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
            'nama' => 'Test D Customer',
            'user_id' => $this->admin->id,
        ]);

        $firstOffIntent = AutomationIntent::where('station_id', $this->relayStation->id)
            ->where('desired_state', AutomationIntent::STATE_OFF)
            ->where('event_type', AutomationIntent::EVENT_BILLING_EXPIRED)
            ->first();
        $this->assertNotNull($firstOffIntent, 'Prepaid session should have a scheduled OFF intent.');

        $onCountBefore = AutomationIntent::where('station_id', $this->relayStation->id)
            ->where('desired_state', AutomationIntent::STATE_ON)
            ->count();

        // Extend by 30 minutes
        $this->billing->extendSession($tx->id_transaksi, 30, true);

        // Station stays DIGUNAKAN
        $this->assertEquals('Digunakan', $this->relayStation->fresh()->status);

        // No new ON intent created
        $onCountAfter = AutomationIntent::where('station_id', $this->relayStation->id)
            ->where('desired_state', AutomationIntent::STATE_ON)
            ->count();
        $this->assertEquals($onCountBefore, $onCountAfter, 'Extend must not create a new ON intent.');

        // Old OFF intent is CANCELLED
        $firstOffIntent->refresh();
        $this->assertEquals(AutomationIntent::STATUS_CANCELLED, $firstOffIntent->status);

        // New OFF intent is PENDING with a later scheduled_at
        $newOffIntent = AutomationIntent::where('station_id', $this->relayStation->id)
            ->where('desired_state', AutomationIntent::STATE_OFF)
            ->where('status', AutomationIntent::STATUS_PENDING)
            ->first();
        $this->assertNotNull($newOffIntent, 'A new PENDING OFF intent should exist after extend.');
        $this->assertTrue(
            Carbon::parse($newOffIntent->scheduled_at)->gt(Carbon::parse($firstOffIntent->scheduled_at)),
            'New OFF intent must be scheduled later than the cancelled one.'
        );
    }

    // Test E — Unavailable device does not create intent
    public function test_E_unavailable_device_does_not_create_intent(): void
    {
        // Mark station as unavailable
        $this->tvStation->update(['status' => 'Digunakan']);

        $intentsBefore = AutomationIntent::where('station_id', $this->tvStation->id)->count();

        $this->expectException(\RuntimeException::class);

        try {
            $this->billing->startSession([
                'device_id' => $this->tvStation->id,
                'playstation_id' => $this->ps->id,
                'tipe_transaksi' => 'prepaid',
                'jam_main' => 1,
                'nama' => 'Test E Customer',
                'user_id' => $this->admin->id,
            ]);
        } finally {
            $intentsAfter = AutomationIntent::where('station_id', $this->tvStation->id)->count();
            $this->assertEquals($intentsBefore, $intentsAfter, 'Failed session must not create any intent.');
            // Station status unchanged
            $this->assertEquals('Digunakan', $this->tvStation->fresh()->status);
        }
    }

    // Test F — Idempotency: calling recordIntent twice with same params yields one row
    public function test_F_recordIntent_is_idempotent(): void
    {
        $station = $this->relayStation;
        $station->update(['session_generation' => 5]);
        $station->refresh();

        $tx = Transaction::create([
            'status' => 'user',
            'device_id' => $station->id,
            'playstation_id' => $this->ps->id,
            'user_id' => $this->admin->id,
            'tipe_transaksi' => 'prepaid',
            'billing_status' => Transaction::BILLING_RUNNING,
            'started_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addHour(),
            'status_transaksi' => 'sukses',
            'waktu_mulai' => Carbon::now()->format('H:i'),
            'waktu_Selesai' => Carbon::now()->addHour()->format('H:i'),
            'session_generation' => 5,
            'nama' => 'Test F',
            'harga' => 0,
            'total' => 0,
            'payment_status' => 'unpaid',
        ]);

        $intentA = $this->intentService->recordIntent($station, $tx, AutomationIntent::EVENT_BILLING_STARTED, AutomationIntent::STATE_ON, Carbon::now());
        $intentB = $this->intentService->recordIntent($station, $tx, AutomationIntent::EVENT_BILLING_STARTED, AutomationIntent::STATE_ON, Carbon::now());

        $this->assertEquals($intentA->id, $intentB->id, 'Second recordIntent call must return existing intent.');
        $count = AutomationIntent::where('station_id', $station->id)
            ->where('desired_state', AutomationIntent::STATE_ON)
            ->where('event_type', AutomationIntent::EVENT_BILLING_STARTED)
            ->where('transaction_id', $tx->id_transaksi)
            ->count();
        $this->assertEquals(1, $count, 'Only one intent row should exist.');
    }

    // Test G — Session generation fence: old intent rejected as STALE when generation changes
    public function test_G_session_fence_rejects_old_intent_as_stale(): void
    {
        $tx = $this->billing->startSession([
            'device_id' => $this->relayStation->id,
            'playstation_id' => $this->ps->id,
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
            'nama' => 'Test G Customer',
            'user_id' => $this->admin->id,
        ]);

        $oldIntent = AutomationIntent::where('station_id', $this->relayStation->id)
            ->where('desired_state', AutomationIntent::STATE_ON)
            ->where('status', AutomationIntent::STATUS_PENDING)
            ->first();
        $this->assertNotNull($oldIntent);
        $oldGeneration = $oldIntent->session_generation;

        // Simulate new session by bumping station generation (new booking on same device)
        $this->relayStation->increment('session_generation');
        $this->relayStation->refresh();
        $this->assertGreaterThan($oldGeneration, (int) $this->relayStation->session_generation);

        // Worker dispatches: old intent should be fenced as STALE
        $this->dispatcher->dispatchBatch(1, Carbon::now());
        $oldIntent->refresh();
        $this->assertEquals(AutomationIntent::STATUS_STALE, $oldIntent->status,
            'Old intent must be STALE after session generation bumped.');
    }

    // Test H — FnB-only transaction (no device_id) creates no automation intent
    public function test_H_fnb_only_transaction_creates_no_intent(): void
    {
        $countBefore = AutomationIntent::count();

        // FnB-only transaction has no device_id
        Transaction::create([
            'status' => 'user',
            'device_id' => null,
            'playstation_id' => null,
            'user_id' => $this->admin->id,
            'tipe_transaksi' => 'postpaid',
            'billing_status' => null,
            'status_transaksi' => 'sukses',
            'waktu_mulai' => Carbon::now()->format('H:i'),
            'waktu_Selesai' => Carbon::now()->format('H:i'),
            'nama' => 'Test H FnB Only',
            'harga' => 0,
            'total' => 20000,
            'payment_status' => 'paid',
        ]);

        $countAfter = AutomationIntent::count();
        $this->assertEquals($countBefore, $countAfter, 'FnB-only transaction must create no automation intent.');
    }
}
