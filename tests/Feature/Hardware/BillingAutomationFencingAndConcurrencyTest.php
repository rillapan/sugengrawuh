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
use App\Services\Hardware\Automation\AutomationFencingGuard;
use App\Services\Hardware\Automation\AutomationIntentService;
use App\Services\Hardware\Simulation\HardwareSimulationManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BillingAutomationFencingAndConcurrencyTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;
    protected User $kasir;
    protected Playstation $billiardService;
    protected Device $station;
    protected PhysicalDevice $shellyDevice;
    protected StationDeviceMapping $mapping;
    protected BillingService $billingService;
    protected AutomationIntentService $intentService;
    protected AutomationDispatcherService $dispatcherService;
    protected AutomationFencingGuard $fencingGuard;
    protected HardwareSimulationManager $simManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertEquals('kasir_ps_test', DB::connection()->getDatabaseName(), 'Must run on kasir_ps_test');
        Config::set('hardware.driver', 'mock');
        Config::set('hardware.simulation_enabled', true);
        Config::set('hardware.allowed_environments', ['local', 'testing']);
        Config::set('hardware.allowed_databases', ['kasir_ps_test']);

        $this->admin = User::firstOrCreate(
            ['email' => 'admin_fence@test.local'],
            ['name' => 'Admin Fence', 'role' => 'admin', 'password' => bcrypt('secret123')]
        );

        $this->kasir = User::firstOrCreate(
            ['email' => 'kasir_fence@test.local'],
            ['name' => 'Kasir Fence', 'role' => 'kasir', 'password' => bcrypt('secret123')]
        );

        $this->billiardService = Playstation::firstOrCreate(
            ['nama' => 'Billiard 9-Foot Tournament Table'],
            ['harga' => 35000]
        );

        $this->station = Device::firstOrCreate(
            ['nama' => 'Meja Biliar VIP Fencing'],
            ['status' => 'Tersedia', 'playstation_id' => $this->billiardService->id]
        );
        $this->station->update(['status' => 'Tersedia', 'session_generation' => 1]);

        $this->shellyDevice = PhysicalDevice::firstOrCreate(
            ['mac_address' => 'AA:BB:CC:DD:EE:02'],
            [
                'name' => 'Shelly 1PM Meja Fencing',
                'category' => PhysicalDevice::CATEGORY_SMART_RELAY,
                'brand' => PhysicalDevice::BRAND_SHELLY,
                'model' => 'Shelly 1PM Mini Gen3',
                'ip_address' => '192.168.1.182',
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
        $this->intentService = app(AutomationIntentService::class);
        $this->dispatcherService = app(AutomationDispatcherService::class);
        $this->fencingGuard = app(AutomationFencingGuard::class);
        $this->simManager = app(HardwareSimulationManager::class);
        $this->simManager->resetAll();
    }

    /**
     * TC-11: Race between STOP and EXPIRE.
     * When stop occurs near expiry, session generation is bumped and pre-scheduled OFF is cancelled.
     * The new immediate STOP intent dispatches cleanly with newer generation.
     */
    public function test_11_stop_vs_expire_race_cancels_old_scheduled_off_and_executes_immediate_stop()
    {
        $startTime = Carbon::parse('2026-11-05 14:00:00');
        Carbon::setTestNow($startTime);

        $tx = $this->billingService->startSession([
            'device_id' => $this->station->id,
            'nama' => 'Cust Race Stop Expire',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
        ], $this->kasir->id);

        // Dispatch ON intent
        $this->dispatcherService->dispatchBatch(10, $startTime);

        $scheduledOff = AutomationIntent::where('transaction_id', $tx->id_transaksi)
            ->where('event_type', AutomationIntent::EVENT_BILLING_EXPIRED)
            ->first();
        $this->assertEquals(AutomationIntent::STATUS_PENDING, $scheduledOff->status);

        // Cashier stops early at 14:59:58 (2 seconds before scheduled expiry)
        $stopTime = Carbon::parse('2026-11-05 14:59:58');
        Carbon::setTestNow($stopTime);

        $this->billingService->stopSession($tx->id_transaksi, 'cashier_stop');

        // Verify pre-scheduled OFF is now CANCELLED
        $scheduledOff->refresh();
        $this->assertEquals(AutomationIntent::STATUS_CANCELLED, $scheduledOff->status);

        // Verify an immediate STOP intent was recorded with higher generation
        $stopIntent = AutomationIntent::where('transaction_id', $tx->id_transaksi)
            ->where('event_type', AutomationIntent::EVENT_BILLING_STOPPED)
            ->first();
        $this->assertNotNull($stopIntent);
        $this->assertGreaterThan($scheduledOff->session_generation, $stopIntent->session_generation);

        // Dispatch at 15:00:00 - only the stop intent executes
        Carbon::setTestNow(Carbon::parse('2026-11-05 15:00:00'));
        $dispatched = $this->dispatcherService->dispatchBatch(10, Carbon::now());

        $this->assertEquals(1, $dispatched);
        $stopIntent->refresh();
        $this->assertEquals(AutomationIntent::STATUS_COMPLETED, $stopIntent->status);
    }

    /**
     * TC-12: Extend vs Expiry Dispatch race.
     * If cashier extends right before expiry, old pre-scheduled OFF is cancelled and
     * becomes STALE if claimed, preventing light shutdown.
     */
    public function test_12_extend_vs_expiry_dispatch_race_aborts_stale_cutoff()
    {
        $startTime = Carbon::parse('2026-11-05 14:00:00');
        Carbon::setTestNow($startTime);

        $tx = $this->billingService->startSession([
            'device_id' => $this->station->id,
            'nama' => 'Cust Extend Race',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
        ], $this->kasir->id);

        $this->dispatcherService->dispatchBatch(10, $startTime);

        $oldOff = AutomationIntent::where('transaction_id', $tx->id_transaksi)
            ->where('event_type', AutomationIntent::EVENT_BILLING_EXPIRED)
            ->first();

        // 1 second before expiry, cashier extends by 1 hour
        $extendTime = Carbon::parse('2026-11-05 14:59:59');
        Carbon::setTestNow($extendTime);

        $this->billingService->extendSession($tx->id_transaksi, 1);

        $oldOff->refresh();
        $this->assertEquals(AutomationIntent::STATUS_CANCELLED, $oldOff->status);

        // At 15:00:00 (old expiry time), no intent should dispatch
        Carbon::setTestNow(Carbon::parse('2026-11-05 15:00:00'));
        $dispatched = $this->dispatcherService->dispatchBatch(10, Carbon::now());
        $this->assertEquals(0, $dispatched);

        // Meja remains SIMULATED_ON
        $this->assertEquals('SIMULATED_ON', $this->station->fresh()->last_hardware_state);

        // Verify new pre-scheduled OFF exists for 16:00:00
        $newOff = AutomationIntent::where('transaction_id', $tx->id_transaksi)
            ->where('event_type', AutomationIntent::EVENT_BILLING_EXPIRED)
            ->where('session_generation', $this->station->fresh()->session_generation)
            ->first();
        $this->assertNotNull($newOff);
        $this->assertEquals('2026-11-05 16:00:00', $newOff->scheduled_at->toDateTimeString());
    }

    /**
     * TC-13: Pending ON after session ended.
     * If a SWITCH_ON intent was delayed/stuck in PENDING while the session ended/stopped,
     * fencing guard rejects it as STALE, preventing lamp from turning ON.
     */
    public function test_13_pending_on_after_session_ended_is_rejected_as_stale_by_fencing()
    {
        $startTime = Carbon::parse('2026-11-05 14:00:00');
        Carbon::setTestNow($startTime);

        $tx = $this->billingService->startSession([
            'device_id' => $this->station->id,
            'nama' => 'Cust Delayed Start',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
        ], $this->kasir->id);

        $startIntent = AutomationIntent::where('transaction_id', $tx->id_transaksi)
            ->where('event_type', AutomationIntent::EVENT_BILLING_STARTED)
            ->first();

        // Customer immediately cancels/stops session before worker picks up the start intent!
        $stopTime = Carbon::parse('2026-11-05 14:00:05');
        Carbon::setTestNow($stopTime);
        $this->billingService->stopSession($tx->id_transaksi, 'cashier_stop');

        // Now worker runs and claims the startIntent
        $log = $this->dispatcherService->processIntent($startIntent, $stopTime);

        $startIntent->refresh();
        $this->assertEquals(AutomationIntent::STATUS_STALE, $startIntent->status);
        $this->assertStringContainsString('generation mismatch', $startIntent->last_error_message);
        $this->assertEquals('FENCING_REJECTED', $log->result_code);
    }

    /**
     * TC-14: New Customer vs Old Customer OFF retry crossover.
     * When Customer A's session ends and its OFF intent is retrying, but Customer B starts a new session,
     * Customer A's retrying OFF intent must be marked STALE due to generation bump, NOT turning off Customer B's lamp!
     */
    public function test_14_new_customer_session_crossover_discards_old_customer_off_retry()
    {
        $time1 = Carbon::parse('2026-11-05 14:00:00');
        Carbon::setTestNow($time1);

        $txA = $this->billingService->startSession([
            'device_id' => $this->station->id,
            'nama' => 'Customer A',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
        ], $this->kasir->id);
        $this->dispatcherService->dispatchBatch(10, $time1);

        // Customer A stops at 15:00:00
        $time2 = Carbon::parse('2026-11-05 15:00:00');
        Carbon::setTestNow($time2);

        $this->billingService->stopSession($txA->id_transaksi, 'cashier_stop');

        $stopIntentA = AutomationIntent::where('transaction_id', $txA->id_transaksi)
            ->where('event_type', AutomationIntent::EVENT_BILLING_STOPPED)
            ->first();

        // Simulate that stopIntentA failed on attempt 1 and is now RETRY_PENDING
        $stopIntentA->update([
            'status' => AutomationIntent::STATUS_RETRY_PENDING,
            'attempts' => 1,
            'next_retry_at' => $time2->copy()->addSeconds(3),
        ]);

        // Meanwhile, Customer B checks in and starts a new session on the same station at 15:00:02
        $time3 = Carbon::parse('2026-11-05 15:00:02');
        Carbon::setTestNow($time3);

        $txB = $this->billingService->startSession([
            'device_id' => $this->station->id,
            'nama' => 'Customer B',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 2,
        ], $this->kasir->id);

        // Start intent for Customer B dispatches ON
        $this->dispatcherService->dispatchBatch(10, $time3);
        $this->assertEquals('SIMULATED_ON', $this->station->fresh()->last_hardware_state);

        // Now next_retry_at arrives for Customer A's old OFF intent at 15:00:05
        $time4 = Carbon::parse('2026-11-05 15:00:05');
        Carbon::setTestNow($time4);

        $log = $this->dispatcherService->processIntent($stopIntentA, $time4);

        $stopIntentA->refresh();
        $this->assertEquals(AutomationIntent::STATUS_STALE, $stopIntentA->status);
        $this->assertEquals('FENCING_REJECTED', $log->result_code);

        // Crucial: Customer B's lamp remains ON!
        $this->assertEquals('SIMULATED_ON', $this->station->fresh()->last_hardware_state);
    }

    /**
     * TC-15: Station mapping changed during in-flight intent.
     * When station-device mapping is deactivated, capability policy rejects intent with SKIPPED / NO_MAPPING.
     */
    public function test_15_station_mapping_deactivated_rejects_intent_via_fencing()
    {
        $startTime = Carbon::parse('2026-11-05 14:00:00');
        Carbon::setTestNow($startTime);

        $tx = $this->billingService->startSession([
            'device_id' => $this->station->id,
            'nama' => 'Cust Mapping Changed',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
        ], $this->kasir->id);

        $startIntent = AutomationIntent::where('transaction_id', $tx->id_transaksi)
            ->where('event_type', AutomationIntent::EVENT_BILLING_STARTED)
            ->first();

        // Admin deactivates mapping
        $this->mapping->update(['mapping_status' => StationDeviceMapping::STATUS_INACTIVE]);

        // Worker processes intent
        $log = $this->dispatcherService->processIntent($startIntent, $startTime);

        $startIntent->refresh();
        $this->assertEquals(AutomationIntent::STATUS_STALE, $startIntent->status);
        $this->assertStringContainsString('mapping', strtolower($startIntent->last_error_message));
        $this->assertEquals('FENCING_REJECTED', $log->result_code);
    }

    /**
     * TC-16: Worker Concurrency & Claim Ownership.
     * Using FOR UPDATE SKIP LOCKED, if intent is claimed by Worker 1 (status=PROCESSING),
     * Worker 2 claim query skips it, preventing duplicate execution.
     */
    public function test_16_worker_concurrency_and_claim_ownership_prevents_duplicate_processing()
    {
        $startTime = Carbon::parse('2026-11-05 14:00:00');
        Carbon::setTestNow($startTime);

        $tx = $this->billingService->startSession([
            'device_id' => $this->station->id,
            'nama' => 'Cust Concurrent Workers',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
        ], $this->kasir->id);

        // Worker 1 claims intent
        $claimedIntents1 = $this->dispatcherService->claimEligibleIntents(10, $startTime);
        $this->assertCount(1, $claimedIntents1);
        $intent = $claimedIntents1->first();
        $this->assertEquals(AutomationIntent::STATUS_PROCESSING, $intent->status);

        // Worker 2 attempts to claim eligible intents at the exact same moment
        $claimedIntents2 = $this->dispatcherService->claimEligibleIntents(10, $startTime);
        $this->assertCount(0, $claimedIntents2, 'Worker 2 must skip intents currently in PROCESSING lease');
    }

    /**
     * TC-17: Stale Worker Lease Recovery.
     * If a worker crashed after claiming an intent (lease expired > 60s),
     * recoverStaleLockedIntents resets status back to PENDING or RETRY_PENDING.
     */
    public function test_17_stale_worker_lease_recovery_resets_dead_processing_locks()
    {
        $startTime = Carbon::parse('2026-11-05 14:00:00');
        Carbon::setTestNow($startTime);

        $tx = $this->billingService->startSession([
            'device_id' => $this->station->id,
            'nama' => 'Cust Worker Crash',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
        ], $this->kasir->id);

        // Worker 1 claims intent and crashes
        $claimed = $this->dispatcherService->claimEligibleIntents(10, $startTime);
        $intent = $claimed->first();
        $this->assertEquals(AutomationIntent::STATUS_PROCESSING, $intent->status);

        // Simulate 65 seconds passed without settlement
        $recoveryTime = $startTime->copy()->addSeconds(65);
        Carbon::setTestNow($recoveryTime);

        // Next worker run executes lease recovery
        $recovered = $this->dispatcherService->recoverStaleLockedIntents(60, $recoveryTime);
        $this->assertEquals(1, $recovered);

        $intent->refresh();
        $this->assertEquals(AutomationIntent::STATUS_RETRY_PENDING, $intent->status);
        $this->assertEquals('STALE_LEASE_RECOVERED', $intent->last_error_category);
    }
}
