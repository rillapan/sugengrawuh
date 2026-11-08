<?php

namespace Tests\Feature\Hardware;

use App\Models\AutomationExecutionLog;
use App\Models\AutomationIntent;
use App\Models\Device;
use App\Models\PhysicalDevice;
use App\Models\Playstation;
use App\Models\StationDeviceMapping;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BillingService;
use App\Services\Hardware\Automation\AutomationDispatcherService;
use App\Services\Hardware\Simulation\HardwareSimulationManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class BillingAutomationLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;
    protected User $kasir;
    protected Playstation $billiardService;
    protected Device $station;
    protected PhysicalDevice $shellyDevice;
    protected StationDeviceMapping $mapping;
    protected BillingService $billingService;
    protected AutomationDispatcherService $dispatcherService;
    protected HardwareSimulationManager $simManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertEquals('kasir_ps_test', \DB::connection()->getDatabaseName(), 'Must run on kasir_ps_test');
        \Illuminate\Support\Facades\Config::set('hardware.driver', 'mock');
        \Illuminate\Support\Facades\Config::set('hardware.simulation_enabled', true);
        \Illuminate\Support\Facades\Config::set('hardware.allowed_environments', ['local', 'testing']);
        \Illuminate\Support\Facades\Config::set('hardware.allowed_databases', ['kasir_ps_test']);

        $this->admin = User::firstOrCreate(
            ['email' => 'admin_auto_life@test.local'],
            ['name' => 'Admin Auto Life', 'role' => 'admin', 'password' => bcrypt('secret123')]
        );

        $this->kasir = User::firstOrCreate(
            ['email' => 'kasir_auto_life@test.local'],
            ['name' => 'Kasir Auto Life', 'role' => 'kasir', 'password' => bcrypt('secret123')]
        );

        $this->billiardService = Playstation::firstOrCreate(
            ['nama' => 'Billiard 9-Foot Tournament Table'],
            ['harga' => 35000]
        );

        $this->station = Device::firstOrCreate(
            ['nama' => 'Meja Biliar VIP 01'],
            ['status' => 'Tersedia', 'playstation_id' => $this->billiardService->id]
        );
        $this->station->update(['status' => 'Tersedia', 'session_generation' => 1]);

        $this->shellyDevice = PhysicalDevice::firstOrCreate(
            ['mac_address' => 'AA:BB:CC:DD:EE:01'],
            [
                'name' => 'Shelly 1PM Meja Biliar 01',
                'category' => PhysicalDevice::CATEGORY_SMART_RELAY,
                'brand' => PhysicalDevice::BRAND_SHELLY,
                'model' => 'Shelly 1PM Mini Gen3',
                'ip_address' => '192.168.1.181',
                'is_active' => true,
                'connection_status' => 'ONLINE',
                'verified_capabilities' => ['SWITCH_ON', 'SWITCH_OFF', 'GET_STATUS'],
            ]
        );

        $this->mapping = StationDeviceMapping::firstOrCreate(
            [
                'station_id' => $this->station->id,
                'physical_device_id' => $this->shellyDevice->id,
            ],
            [
                'mapping_role' => StationDeviceMapping::ROLE_LIGHT_RELAY,
                'mapping_status' => StationDeviceMapping::STATUS_ACTIVE,
                'created_by' => $this->admin->id,
            ]
        );
        $this->mapping->update(['mapping_status' => StationDeviceMapping::STATUS_ACTIVE]);

        $this->billingService = app(BillingService::class);
        $this->dispatcherService = app(AutomationDispatcherService::class);
        $this->simManager = app(HardwareSimulationManager::class);
        $this->simManager->resetAll();
    }

    /**
     * TC-01: Prepaid Start -> Commits SWITCH_ON intent and pre-scheduled SWITCH_OFF intent atomically.
     */
    public function test_01_prepaid_start_creates_switch_on_and_prescheduled_switch_off_intents()
    {
        $now = Carbon::parse('2026-11-05 14:00:00');
        Carbon::setTestNow($now);

        $tx = $this->billingService->startSession([
            'device_id' => $this->station->id,
            'nama' => 'Customer Biliar 1',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 2,
        ], $this->kasir->id);

        $this->assertNotNull($tx);
        $this->assertEquals(Transaction::BILLING_RUNNING, $tx->billing_status);
        $this->assertEquals('2026-11-05 16:00:00', $tx->expires_at->toDateTimeString());

        // Verify two automation intents were created atomically
        $startIntent = AutomationIntent::where('transaction_id', $tx->id_transaksi)
            ->where('event_type', AutomationIntent::EVENT_BILLING_STARTED)
            ->first();

        $expireIntent = AutomationIntent::where('transaction_id', $tx->id_transaksi)
            ->where('event_type', AutomationIntent::EVENT_BILLING_EXPIRED)
            ->first();

        $this->assertNotNull($startIntent);
        $this->assertEquals(AutomationIntent::STATE_ON, $startIntent->desired_state);
        $this->assertEquals(AutomationIntent::STATUS_PENDING, $startIntent->status);
        $this->assertEquals('2026-11-05 14:00:00', $startIntent->scheduled_at->toDateTimeString());

        $this->assertNotNull($expireIntent);
        $this->assertEquals(AutomationIntent::STATE_OFF, $expireIntent->desired_state);
        $this->assertEquals(AutomationIntent::STATUS_PENDING, $expireIntent->status);
        $this->assertEquals('2026-11-05 16:00:00', $expireIntent->scheduled_at->toDateTimeString());

        // Dispatch start intent
        $dispatched = $this->dispatcherService->dispatchBatch(10, $now);
        $this->assertEquals(1, $dispatched);

        $startIntent->refresh();
        $this->assertEquals(AutomationIntent::STATUS_COMPLETED, $startIntent->status);
        $this->assertEquals('SIMULATED_ON', $this->station->fresh()->last_hardware_state);

        // Pre-scheduled expire intent remains PENDING until 16:00
        $expireIntent->refresh();
        $this->assertEquals(AutomationIntent::STATUS_PENDING, $expireIntent->status);
    }

    /**
     * TC-02 & TC-03 & TC-04: Scheduled expiry dispatches SWITCH_OFF immediately at expires_at
     * without cashier confirmation and with zero grace period (SR-G5-BR-001).
     */
    public function test_02_prescheduled_expiry_intent_dispatches_switch_off_at_expires_at()
    {
        $startTime = Carbon::parse('2026-11-05 14:00:00');
        Carbon::setTestNow($startTime);

        $tx = $this->billingService->startSession([
            'device_id' => $this->station->id,
            'nama' => 'Player Tournament',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
        ], $this->kasir->id);

        $this->dispatcherService->dispatchBatch(10, $startTime);

        // Advance clock to exact expires_at second (15:00:00) with zero grace period
        $expiryTime = Carbon::parse('2026-11-05 15:00:00');
        Carbon::setTestNow($expiryTime);

        // Automation worker claims and dispatches the pre-scheduled intent immediately
        $dispatched = $this->dispatcherService->dispatchBatch(10, $expiryTime);
        $this->assertEquals(1, $dispatched);

        $expireIntent = AutomationIntent::where('transaction_id', $tx->id_transaksi)
            ->where('event_type', AutomationIntent::EVENT_BILLING_EXPIRED)
            ->first();

        $this->assertEquals(AutomationIntent::STATUS_COMPLETED, $expireIntent->status);
        $this->assertEquals('SIMULATED_OFF', $this->station->fresh()->last_hardware_state);

        // Check audit log recorded SUCCESS and verified output
        $log = AutomationExecutionLog::where('intent_id', $expireIntent->id)->first();
        $this->assertNotNull($log);
        $this->assertEquals(AutomationExecutionLog::STATUS_SUCCESS, $log->execution_status);
        $this->assertEquals('false', $log->observed_output_state);
        $this->assertEquals('VERIFIED_OK', $log->result_code);
    }

    /**
     * TC-05: Prepaid Extend -> Invalidate old scheduled OFF and pre-schedule new OFF for new_expires_at.
     */
    public function test_05_prepaid_extend_bumps_generation_cancels_old_off_and_schedules_new_off()
    {
        $startTime = Carbon::parse('2026-11-05 14:00:00');
        Carbon::setTestNow($startTime);

        $tx = $this->billingService->startSession([
            'device_id' => $this->station->id,
            'nama' => 'Player Extend',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
        ], $this->kasir->id);

        $initialGen = (int) $this->station->fresh()->session_generation;

        $oldExpireIntent = AutomationIntent::where('transaction_id', $tx->id_transaksi)
            ->where('event_type', AutomationIntent::EVENT_BILLING_EXPIRED)
            ->first();

        // Extend by 1 hour with 5 minutes left
        $extendTime = Carbon::parse('2026-11-05 14:55:00');
        Carbon::setTestNow($extendTime);

        $extendedTx = $this->billingService->extendSession($tx->id_transaksi, 1);

        $newGen = (int) $this->station->fresh()->session_generation;
        $this->assertGreaterThan($initialGen, $newGen);

        // Verify old pre-scheduled intent is marked CANCELLED
        $oldExpireIntent->refresh();
        $this->assertEquals(AutomationIntent::STATUS_CANCELLED, $oldExpireIntent->status);

        // Verify new pre-scheduled intent exists for 16:00:00
        $newExpireIntent = AutomationIntent::where('transaction_id', $tx->id_transaksi)
            ->where('event_type', AutomationIntent::EVENT_BILLING_EXPIRED)
            ->where('status', AutomationIntent::STATUS_PENDING)
            ->first();

        $this->assertNotNull($newExpireIntent);
        $this->assertEquals('2026-11-05 16:00:00', $newExpireIntent->scheduled_at->toDateTimeString());
        $this->assertEquals($newGen, $newExpireIntent->session_generation);
    }

    /**
     * TC-06: Prepaid Stop Early -> Cancels scheduled OFF and triggers immediate SWITCH_OFF.
     */
    public function test_06_prepaid_stop_early_cancels_prescheduled_off_and_triggers_immediate_cutoff()
    {
        $startTime = Carbon::parse('2026-11-05 14:00:00');
        Carbon::setTestNow($startTime);

        $tx = $this->billingService->startSession([
            'device_id' => $this->station->id,
            'nama' => 'Player Stop Early',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 2,
        ], $this->kasir->id);

        $oldExpireIntent = AutomationIntent::where('transaction_id', $tx->id_transaksi)
            ->where('event_type', AutomationIntent::EVENT_BILLING_EXPIRED)
            ->first();

        // Customer stops after 30 minutes
        $stopTime = Carbon::parse('2026-11-05 14:30:00');
        Carbon::setTestNow($stopTime);

        $stoppedTx = $this->billingService->stopSession($tx->id_transaksi, 'customer_leave');

        $this->assertEquals(Transaction::BILLING_STOPPED_EARLY, $stoppedTx->billing_status);
        $this->assertEquals('Tersedia', $this->station->fresh()->status);

        // Old scheduled expire intent cancelled
        $oldExpireIntent->refresh();
        $this->assertEquals(AutomationIntent::STATUS_CANCELLED, $oldExpireIntent->status);

        // Immediate stop intent created with scheduled_at = stopTime
        $stopIntent = AutomationIntent::where('transaction_id', $tx->id_transaksi)
            ->where('event_type', AutomationIntent::EVENT_BILLING_STOPPED)
            ->first();

        $this->assertNotNull($stopIntent);
        $this->assertEquals(AutomationIntent::STATUS_PENDING, $stopIntent->status);
        $this->assertEquals('2026-11-05 14:30:00', $stopIntent->scheduled_at->toDateTimeString());

        // Dispatch stop intent
        $this->dispatcherService->dispatchBatch(10, $stopTime);
        $stopIntent->refresh();
        $this->assertEquals(AutomationIntent::STATUS_COMPLETED, $stopIntent->status);
        $this->assertEquals('SIMULATED_OFF', $this->station->fresh()->last_hardware_state);
    }

    /**
     * TC-07: Direct DeviceController::stopDevice() delegation triggers immediate cutoff intent
     * and preserves exact response contract.
     */
    public function test_07_device_controller_stop_device_triggers_immediate_cutoff_intent()
    {
        $this->actingAs($this->kasir);
        $now = Carbon::parse('2026-11-05 14:00:00');
        Carbon::setTestNow($now);

        $tx = $this->billingService->startSession([
            'device_id' => $this->station->id,
            'nama' => 'Player Controller Stop',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 2,
        ], $this->kasir->id);

        $stopTime = Carbon::parse('2026-11-05 14:45:00');
        Carbon::setTestNow($stopTime);

        $response = $this->postJson("/device/{$this->station->id}/stop");

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'device_id' => $this->station->id,
            'new_status' => 'Tersedia',
            'transaction_id' => $tx->id_transaksi,
        ]);

        $this->assertEquals('Tersedia', $this->station->fresh()->status);

        // Verify immediate STOP intent was committed
        $stopIntent = AutomationIntent::where('transaction_id', $tx->id_transaksi)
            ->where('event_type', AutomationIntent::EVENT_BILLING_STOPPED)
            ->first();

        $this->assertNotNull($stopIntent);
        $this->assertEquals(AutomationIntent::STATUS_PENDING, $stopIntent->status);
    }

    /**
     * TC-08: Lost Time Start -> SWITCH_ON created with NO pre-scheduled expiry.
     */
    public function test_08_lost_time_start_commits_switch_on_with_no_prescheduled_expiry()
    {
        $now = Carbon::parse('2026-11-05 21:00:00');
        Carbon::setTestNow($now);

        $tx = $this->billingService->startSession([
            'device_id' => $this->station->id,
            'nama' => 'Lost Time Customer',
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        $this->assertNull($tx->expires_at);

        // Verify start intent exists
        $startIntent = AutomationIntent::where('transaction_id', $tx->id_transaksi)
            ->where('event_type', AutomationIntent::EVENT_BILLING_STARTED)
            ->first();
        $this->assertNotNull($startIntent);

        // Verify NO pre-scheduled expire intent exists
        $expireIntent = AutomationIntent::where('transaction_id', $tx->id_transaksi)
            ->where('event_type', AutomationIntent::EVENT_BILLING_EXPIRED)
            ->first();
        $this->assertNull($expireIntent);
    }

    /**
     * TC-09: Lost Time Midnight Crossing -> Remains running without any cutoff intent.
     */
    public function test_09_lost_time_midnight_crossing_remains_running_without_cutoff()
    {
        $start = Carbon::parse('2026-11-05 23:00:00');
        Carbon::setTestNow($start);

        $tx = $this->billingService->startSession([
            'device_id' => $this->station->id,
            'nama' => 'Night Owl Player',
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        // Advance to 01:30 next morning
        $nextMorning = Carbon::parse('2026-11-06 01:30:00');
        Carbon::setTestNow($nextMorning);

        // Run worker
        $dispatched = $this->dispatcherService->dispatchBatch(10, $nextMorning);
        $this->assertEquals(1, $dispatched); // Only start intent was pending

        // Session remains RUNNING, device remains Digunakan
        $this->assertEquals(Transaction::BILLING_RUNNING, $tx->fresh()->billing_status);
        $this->assertEquals('Digunakan', $this->station->fresh()->status);

        // No OFF intents exist
        $offIntents = AutomationIntent::where('transaction_id', $tx->id_transaksi)
            ->where('desired_state', AutomationIntent::STATE_OFF)
            ->count();
        $this->assertEquals(0, $offIntents);
    }

    /**
     * TC-10: Lost Time Stop -> Commits immediate SWITCH_OFF.
     */
    public function test_10_lost_time_stop_triggers_immediate_switch_off()
    {
        $start = Carbon::parse('2026-11-05 20:00:00');
        Carbon::setTestNow($start);

        $tx = $this->billingService->startSession([
            'device_id' => $this->station->id,
            'nama' => 'Postpaid Player',
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        $stopTime = Carbon::parse('2026-11-05 22:30:00');
        Carbon::setTestNow($stopTime);

        $this->billingService->stopSession($tx->id_transaksi, 'lost_time_finish');

        $stopIntent = AutomationIntent::where('transaction_id', $tx->id_transaksi)
            ->where('event_type', AutomationIntent::EVENT_BILLING_STOPPED)
            ->first();

        $this->assertNotNull($stopIntent);
        $this->assertEquals(AutomationIntent::STATUS_PENDING, $stopIntent->status);

        $this->dispatcherService->dispatchBatch(10, $stopTime);
        $stopIntent->refresh();
        $this->assertEquals(AutomationIntent::STATUS_COMPLETED, $stopIntent->status);
    }
}
