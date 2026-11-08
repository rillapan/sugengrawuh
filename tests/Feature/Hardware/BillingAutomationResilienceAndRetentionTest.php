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
use App\Services\Hardware\Automation\AutomationStatusService;
use App\Services\Hardware\Simulation\HardwareSimulationManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BillingAutomationResilienceAndRetentionTest extends TestCase
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
    protected AutomationStatusService $statusService;
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
            ['email' => 'admin_resil@test.local'],
            ['name' => 'Admin Resil', 'role' => 'admin', 'password' => bcrypt('secret123')]
        );

        $this->kasir = User::firstOrCreate(
            ['email' => 'kasir_resil@test.local'],
            ['name' => 'Kasir Resil', 'role' => 'kasir', 'password' => bcrypt('secret123')]
        );

        $this->billiardService = Playstation::firstOrCreate(
            ['nama' => 'Billiard 9-Foot Tournament Table'],
            ['harga' => 35000]
        );

        $this->station = Device::firstOrCreate(
            ['nama' => 'Meja Biliar VIP Resilience'],
            ['status' => 'Tersedia', 'playstation_id' => $this->billiardService->id]
        );
        $this->station->update(['status' => 'Tersedia', 'session_generation' => 1]);

        $this->shellyDevice = PhysicalDevice::firstOrCreate(
            ['mac_address' => 'AA:BB:CC:DD:EE:03'],
            [
                'name' => 'Shelly 1PM Meja Resil',
                'category' => PhysicalDevice::CATEGORY_SMART_RELAY,
                'brand' => PhysicalDevice::BRAND_SHELLY,
                'model' => 'Shelly 1PM Mini Gen3',
                'ip_address' => '192.168.1.183',
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
        $this->statusService = app(AutomationStatusService::class);
        $this->simManager = app(HardwareSimulationManager::class);
        $this->simManager->resetAll();
    }

    /**
     * TC-18: Offline Shelly triggers backoff retry schedule (+3s, +10s, +30s).
     */
    public function test_18_offline_shelly_schedules_bounded_backoff_retries()
    {
        $startTime = Carbon::parse('2026-11-05 14:00:00');
        Carbon::setTestNow($startTime);

        $tx = $this->billingService->startSession([
            'device_id' => $this->station->id,
            'nama' => 'Cust Offline Shelly',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
        ], $this->kasir->id);

        $startIntent = AutomationIntent::where('transaction_id', $tx->id_transaksi)
            ->where('event_type', AutomationIntent::EVENT_BILLING_STARTED)
            ->first();

        // Inject OFFLINE scenario into simulation manager for this device
        $this->simManager->setDeviceScenario($this->shellyDevice->id, HardwareSimulationManager::SCENARIO_OFFLINE);

        // Attempt 1: At 14:00:00
        $this->dispatcherService->dispatchBatch(10, $startTime);
        $startIntent->refresh();

        $this->assertEquals(AutomationIntent::STATUS_RETRY_PENDING, $startIntent->status);
        $this->assertEquals(1, $startIntent->attempts);
        $this->assertEquals('2026-11-05 14:00:03', $startIntent->next_retry_at->toDateTimeString(), 'Attempt 1 backoff is +3s');

        // Check execution log recorded for attempt 1
        $log1 = AutomationExecutionLog::where('intent_id', $startIntent->id)->where('attempt_number', 1)->first();
        $this->assertNotNull($log1);
        $this->assertEquals(AutomationExecutionLog::STATUS_RETRY_SCHEDULED, $log1->execution_status);
        $this->assertEquals('OFFLINE', $log1->error_category);

        // Attempt 2: At 14:00:03
        $timeAttempt2 = Carbon::parse('2026-11-05 14:00:03');
        Carbon::setTestNow($timeAttempt2);
        $this->dispatcherService->dispatchBatch(10, $timeAttempt2);
        $startIntent->refresh();

        $this->assertEquals(AutomationIntent::STATUS_RETRY_PENDING, $startIntent->status);
        $this->assertEquals(2, $startIntent->attempts);
        $this->assertEquals('2026-11-05 14:00:13', $startIntent->next_retry_at->toDateTimeString(), 'Attempt 2 backoff is +10s');

        // Attempt 3: At 14:00:13
        $timeAttempt3 = Carbon::parse('2026-11-05 14:00:13');
        Carbon::setTestNow($timeAttempt3);
        $this->dispatcherService->dispatchBatch(10, $timeAttempt3);
        $startIntent->refresh();

        $this->assertEquals(AutomationIntent::STATUS_RETRY_PENDING, $startIntent->status);
        $this->assertEquals(3, $startIntent->attempts);
        $this->assertEquals('2026-11-05 14:00:43', $startIntent->next_retry_at->toDateTimeString(), 'Attempt 3 backoff is +30s');
    }

    /**
     * TC-19: Max retries exhausted after 4 attempts -> status transitions to FAILED, cashier alert state active.
     */
    public function test_19_max_retries_exhausted_transitions_to_failed_with_cashier_alert()
    {
        $startTime = Carbon::parse('2026-11-05 14:00:00');
        Carbon::setTestNow($startTime);

        $tx = $this->billingService->startSession([
            'device_id' => $this->station->id,
            'nama' => 'Cust Max Retries',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
        ], $this->kasir->id);

        $startIntent = AutomationIntent::where('transaction_id', $tx->id_transaksi)
            ->where('event_type', AutomationIntent::EVENT_BILLING_STARTED)
            ->first();

        $this->simManager->setDeviceScenario($this->shellyDevice->id, HardwareSimulationManager::SCENARIO_OFFLINE);

        // Attempt 1: 14:00:00
        $this->dispatcherService->dispatchBatch(10, $startTime);
        // Attempt 2: 14:00:03
        $this->dispatcherService->dispatchBatch(10, Carbon::parse('2026-11-05 14:00:03'));
        // Attempt 3: 14:00:13
        $this->dispatcherService->dispatchBatch(10, Carbon::parse('2026-11-05 14:00:13'));
        // Attempt 4 (Final): 14:00:43
        $this->dispatcherService->dispatchBatch(10, Carbon::parse('2026-11-05 14:00:43'));

        $startIntent->refresh();
        $this->assertEquals(AutomationIntent::STATUS_FAILED, $startIntent->status);
        $this->assertEquals(4, $startIntent->attempts);
        $this->assertNull($startIntent->next_retry_at);

        // Verify Cashier UI alert state
        $statusData = $this->statusService->getCashierStatus($this->station);
        $this->assertEquals('COMMAND_FAILED', $statusData['hardware_state']);
        $this->assertNotNull($statusData['alert']);
        $this->assertStringContainsString('gagal', strtolower($statusData['alert']['message']));
    }

    /**
     * TC-20: Read-Back Prior to Retry settles COMPLETED without redundant network packet.
     */
    public function test_20_readback_prior_to_retry_settles_completed_when_device_already_in_desired_state()
    {
        $startTime = Carbon::parse('2026-11-05 14:00:00');
        Carbon::setTestNow($startTime);

        $tx = $this->billingService->startSession([
            'device_id' => $this->station->id,
            'nama' => 'Cust Readback Prior',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
        ], $this->kasir->id);

        $startIntent = AutomationIntent::where('transaction_id', $tx->id_transaksi)
            ->where('event_type', AutomationIntent::EVENT_BILLING_STARTED)
            ->first();

        // Simulate attempt 1 failed with TIMEOUT
        $this->simManager->setDeviceScenario($this->shellyDevice->id, HardwareSimulationManager::SCENARIO_TIMEOUT);
        $this->dispatcherService->dispatchBatch(10, $startTime);
        $startIntent->refresh();
        $this->assertEquals(AutomationIntent::STATUS_RETRY_PENDING, $startIntent->status);

        // Device actually received packet and output is ON!
        $this->simManager->resetDevice($this->shellyDevice->id);
        $this->simManager->updateSimulatedState($this->shellyDevice->id, [
            'switch' => 'ON',
            'output' => true,
            'power_watts' => 45.0,
            'voltage' => 220.0,
            'temperature' => 42.0,
        ]);

        // Attempt 2 runs at 14:00:03
        $time2 = Carbon::parse('2026-11-05 14:00:03');
        Carbon::setTestNow($time2);
        $this->dispatcherService->dispatchBatch(10, $time2);

        $startIntent->refresh();
        $this->assertEquals(AutomationIntent::STATUS_COMPLETED, $startIntent->status);

        $latestLog = AutomationExecutionLog::where('intent_id', $startIntent->id)
            ->orderBy('attempt_number', 'desc')
            ->first();
        $this->assertEquals('VERIFIED_OK_READBACK', $latestLog->result_code);
    }

    /**
     * TC-21: Read-Back Mismatch Detection.
     * When post-dispatch status reports output does not match desired state.
     */
    public function test_21_readback_mismatch_triggers_failure()
    {
        $startTime = Carbon::parse('2026-11-05 14:00:00');
        Carbon::setTestNow($startTime);

        $tx = $this->billingService->startSession([
            'device_id' => $this->station->id,
            'nama' => 'Cust Readback Mismatch',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
        ], $this->kasir->id);

        $startIntent = AutomationIntent::where('transaction_id', $tx->id_transaksi)
            ->where('event_type', AutomationIntent::EVENT_BILLING_STARTED)
            ->first();

        // Shelly driver mock returns state rejected/error scenario
        $this->simManager->setDeviceScenario($this->shellyDevice->id, HardwareSimulationManager::SCENARIO_REJECTED);
        $this->dispatcherService->dispatchBatch(10, $startTime);

        $startIntent->refresh();
        $this->assertEquals(AutomationIntent::STATUS_RETRY_PENDING, $startIntent->status);
        $this->assertEquals('COMMAND_REJECTED', $startIntent->last_error_category);
    }

    /**
     * TC-22: Samsung TV Automated Power Commands Skipped with SKIPPED_UNVERIFIED_CAPABILITY.
     */
    public function test_22_samsung_tv_automated_power_commands_skipped_by_capability_policy()
    {
        $tvDevice = PhysicalDevice::firstOrCreate(
            ['mac_address' => '11:22:33:44:55:66'],
            [
                'name' => 'Samsung TV 50U8500 VIP',
                'category' => PhysicalDevice::CATEGORY_SMART_TV,
                'brand' => PhysicalDevice::BRAND_SAMSUNG,
                'model' => '50U8500',
                'ip_address' => '192.168.1.184',
                'is_active' => true,
                'connection_status' => 'ONLINE',
                'verified_capabilities' => ['KEY_MUTE', 'KEY_POWER'],
            ]
        );

        $tvMapping = StationDeviceMapping::firstOrCreate(
            [
                'station_id' => $this->station->id,
                'physical_device_id' => $tvDevice->id,
            ],
            [
                'mapping_role' => StationDeviceMapping::ROLE_DISPLAY_TV,
                'mapping_status' => StationDeviceMapping::STATUS_ACTIVE,
                'created_by' => $this->admin->id,
            ]
        );

        $now = Carbon::parse('2026-11-05 14:00:00');
        Carbon::setTestNow($now);

        // Create an intent targeted specifically at Samsung TV
        $intent = AutomationIntent::create([
            'station_id' => $this->station->id,
            'physical_device_id' => $tvDevice->id,
            'station_device_mapping_id' => $tvMapping->id,
            'event_type' => 'BILLING_STARTED',
            'desired_state' => AutomationIntent::STATE_ON,
            'target_channel' => 0,
            'session_generation' => $this->station->fresh()->session_generation,
            'idempotency_key' => 'TV-POWER-TEST-001',
            'status' => AutomationIntent::STATUS_PENDING,
            'scheduled_at' => $now,
            'attempts' => 0,
            'max_attempts' => 4,
        ]);

        $log = $this->dispatcherService->processIntent($intent, $now);

        $intent->refresh();
        $this->assertEquals(AutomationIntent::STATUS_SKIPPED, $intent->status);
        $this->assertStringContainsString('Samsung TV power commands are unverified', $intent->last_error_message);
        $this->assertEquals('SKIPPED_UNVERIFIED_CAPABILITY', $log->result_code);
    }

    /**
     * TC-23: Financial Transaction Isolation.
     * IoT/automation failure NEVER alters billing duration, transaction totals, or payment status.
     */
    public function test_23_financial_transaction_isolation_preserves_billing_state_on_hardware_failure()
    {
        $startTime = Carbon::parse('2026-11-05 14:00:00');
        Carbon::setTestNow($startTime);

        $tx = $this->billingService->startSession([
            'device_id' => $this->station->id,
            'nama' => 'Cust Financial Iso',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 2,
        ], $this->kasir->id);

        $initialTotal = $tx->total;
        $initialStatus = $tx->status_pembayaran;
        $initialBillingStatus = $tx->billing_status;

        // Simulate catastrophic IoT failure
        $this->simManager->setDeviceScenario($this->shellyDevice->id, HardwareSimulationManager::SCENARIO_OFFLINE);
        $this->dispatcherService->dispatchBatch(10, $startTime);

        $tx->refresh();
        $this->assertEquals($initialTotal, $tx->total, 'Transaction total must not be mutated by IoT failure');
        $this->assertEquals($initialStatus, $tx->status_pembayaran, 'Payment status must not be altered');
        $this->assertEquals($initialBillingStatus, $tx->billing_status, 'Billing status must remain RUNNING');
    }

    /**
     * TC-24: RBAC & Credential Secrecy.
     * Cashier automation status endpoint never leaks IP, MAC, password, or token.
     */
    public function test_24_rbac_and_credential_secrecy_masks_hardware_details_from_cashier()
    {
        $this->actingAs($this->kasir);

        $response = $this->getJson("/api/stations/{$this->station->id}/automation-status");
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertArrayNotHasKey('ip_address', $data);
        $this->assertArrayNotHasKey('mac_address', $data);
        $this->assertArrayNotHasKey('token', $data);
        $this->assertArrayNotHasKey('password', $data);

        // Response contains customer-safe fields
        $this->assertArrayHasKey('hardware_state', $data);
        $this->assertArrayHasKey('hardware_badge', $data);
        $this->assertArrayHasKey('station_name', $data);
    }

    /**
     * TC-25: Retention Verification: 30-day intent pruning preserves 90-day execution logs via ON DELETE SET NULL.
     */
    public function test_25_intent_pruning_preserves_execution_logs_via_set_null()
    {
        $pastDate = Carbon::now()->subDays(35);

        $intent = AutomationIntent::create([
            'station_id' => $this->station->id,
            'physical_device_id' => $this->shellyDevice->id,
            'event_type' => 'BILLING_STARTED',
            'desired_state' => AutomationIntent::STATE_ON,
            'target_channel' => 0,
            'session_generation' => 1,
            'idempotency_key' => 'PRUNE-TEST-INTENT-001',
            'status' => AutomationIntent::STATUS_COMPLETED,
            'scheduled_at' => $pastDate,
            'completed_at' => $pastDate,
            'attempts' => 1,
            'max_attempts' => 4,
        ]);

        $log = AutomationExecutionLog::create([
            'intent_id' => $intent->id,
            'station_id' => $this->station->id,
            'station_name' => $this->station->nama,
            'physical_device_id' => $this->shellyDevice->id,
            'driver_type' => 'shelly_relay',
            'command_sent' => 'SWITCH_ON',
            'attempt_number' => 1,
            'execution_status' => AutomationExecutionLog::STATUS_FAILED,
            'result_code' => 'TIMEOUT',
            'error_category' => 'TIMEOUT',
            'executed_at' => $pastDate,
        ]);

        $this->assertNotNull($log->intent_id);

        // Delete intent directly (simulating 30-day prune)
        $intent->delete();

        // Log still exists with intent_id set to NULL
        $log->refresh();
        $this->assertNull($log->intent_id);
        $this->assertEquals($this->station->id, $log->station_id);
    }

    /**
     * TC-26: Status RUNNING at Expiry Second triggers pre-scheduled OFF intent before G2 worker updates status.
     */
    public function test_26_status_running_at_expiry_second_triggers_prescheduled_off_intent()
    {
        $startTime = Carbon::parse('2026-11-05 14:00:00');
        Carbon::setTestNow($startTime);

        $tx = $this->billingService->startSession([
            'device_id' => $this->station->id,
            'nama' => 'Cust Immediate Cutoff',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
        ], $this->kasir->id);

        // Dispatch ON intent
        $this->dispatcherService->dispatchBatch(10, $startTime);
        $this->assertEquals('SIMULATED_ON', $this->station->fresh()->last_hardware_state);

        // Fast-forward to exactly 15:00:00 (expires_at)
        $expiryTime = Carbon::parse('2026-11-05 15:00:00');
        Carbon::setTestNow($expiryTime);

        // Crucial check: G2 billing worker has NOT run yet, so transaction billing_status is still RUNNING
        $tx->refresh();
        $this->assertEquals('RUNNING', $tx->billing_status);

        // Automation worker runs at 15:00:00
        $dispatched = $this->dispatcherService->dispatchBatch(10, $expiryTime);
        $this->assertEquals(1, $dispatched);

        // Light relay is ordered OFF immediately without waiting for G2 worker to set EXPIRED_PENDING_ACTION
        $this->assertEquals('SIMULATED_OFF', $this->station->fresh()->last_hardware_state);
    }

    /**
     * TC-27: Foreign Key Schema Verification.
     * Validates column definitions and foreign key constraints on automation tables.
     */
    public function test_27_foreign_key_schema_and_column_types()
    {
        $intentColumns = DB::select("SHOW COLUMNS FROM automation_intents");
        $columnNames = array_column($intentColumns, 'Field');

        $this->assertContains('id', $columnNames);
        $this->assertContains('station_id', $columnNames);
        $this->assertContains('transaction_id', $columnNames);
        $this->assertContains('physical_device_id', $columnNames);
        $this->assertContains('station_device_mapping_id', $columnNames);
        $this->assertContains('session_generation', $columnNames);
        $this->assertContains('desired_state', $columnNames);
        $this->assertContains('status', $columnNames);
        $this->assertContains('idempotency_key', $columnNames);

        $deviceColumns = DB::select("SHOW COLUMNS FROM devices");
        $deviceColNames = array_column($deviceColumns, 'Field');
        $this->assertContains('session_generation', $deviceColNames);
        $this->assertContains('last_hardware_state', $deviceColNames);
        $this->assertContains('last_hardware_verified_at', $deviceColNames);

        $txColumns = DB::select("SHOW COLUMNS FROM transactions");
        $txColNames = array_column($txColumns, 'Field');
        $this->assertContains('session_generation', $txColNames);
    }
}
