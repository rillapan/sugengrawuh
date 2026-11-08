<?php

namespace Tests\Feature\Hardware;

use App\Models\AutomationExecutionLog;
use App\Models\AutomationIntent;
use App\Models\Device;
use App\Models\PhysicalDevice;
use App\Models\Playstation;
use App\Models\StationDeviceMapping;
use App\Models\User;
use App\Services\BillingService;
use App\Services\Hardware\Simulation\HardwareSimulationManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BillingAutomationCommandsTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;
    protected User $kasir;
    protected Playstation $billiardService;
    protected Device $station;
    protected PhysicalDevice $shellyDevice;
    protected StationDeviceMapping $mapping;
    protected BillingService $billingService;
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
            ['email' => 'admin_cmd@test.local'],
            ['name' => 'Admin Cmd', 'role' => 'admin', 'password' => bcrypt('secret123')]
        );

        $this->kasir = User::firstOrCreate(
            ['email' => 'kasir_cmd@test.local'],
            ['name' => 'Kasir Cmd', 'role' => 'kasir', 'password' => bcrypt('secret123')]
        );

        $this->billiardService = Playstation::firstOrCreate(
            ['nama' => 'Billiard 9-Foot Tournament Table'],
            ['harga' => 35000]
        );

        $this->station = Device::firstOrCreate(
            ['nama' => 'Meja Biliar VIP Commands'],
            ['status' => 'Tersedia', 'playstation_id' => $this->billiardService->id]
        );
        $this->station->update(['status' => 'Tersedia', 'session_generation' => 1]);

        $this->shellyDevice = PhysicalDevice::firstOrCreate(
            ['mac_address' => 'AA:BB:CC:DD:EE:04'],
            [
                'name' => 'Shelly 1PM Meja Commands',
                'category' => PhysicalDevice::CATEGORY_SMART_RELAY,
                'brand' => PhysicalDevice::BRAND_SHELLY,
                'model' => 'Shelly 1PM Mini Gen3',
                'ip_address' => '192.168.1.185',
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
        $this->simManager = app(HardwareSimulationManager::class);
        $this->simManager->resetAll();
    }

    /**
     * Test billing:automation-worker with --once flag processes eligible intents and exits.
     */
    public function test_artisan_automation_worker_once_executes_and_exits()
    {
        $startTime = Carbon::parse('2026-11-05 14:00:00');
        Carbon::setTestNow($startTime);

        $tx = $this->billingService->startSession([
            'device_id' => $this->station->id,
            'nama' => 'Cust Worker Command',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
        ], $this->kasir->id);

        $startIntent = AutomationIntent::where('transaction_id', $tx->id_transaksi)
            ->where('event_type', AutomationIntent::EVENT_BILLING_STARTED)
            ->first();
        $this->assertEquals(AutomationIntent::STATUS_PENDING, $startIntent->status);

        // Run artisan command
        $this->artisan('billing:automation-worker', ['--once' => true, '--batch' => 10])
            ->assertExitCode(0);

        $startIntent->refresh();
        $this->assertEquals(AutomationIntent::STATUS_COMPLETED, $startIntent->status);
        $this->assertEquals('SIMULATED_ON', $this->station->fresh()->last_hardware_state);
    }

    /**
     * Test billing:automation-prune prunes completed intents older than 30 days and preserves logs.
     */
    public function test_artisan_automation_prune_removes_old_terminal_intents_and_keeps_audit_logs()
    {
        $oldDate = Carbon::now()->subDays(35);

        $intent = AutomationIntent::create([
            'station_id' => $this->station->id,
            'physical_device_id' => $this->shellyDevice->id,
            'event_type' => 'BILLING_STARTED',
            'desired_state' => AutomationIntent::STATE_ON,
            'target_channel' => 0,
            'session_generation' => 1,
            'idempotency_key' => 'PRUNE-CMD-TEST-001',
            'status' => AutomationIntent::STATUS_COMPLETED,
            'scheduled_at' => $oldDate,
            'completed_at' => $oldDate,
            'attempts' => 1,
            'max_attempts' => 4,
        ]);
        DB::table('automation_intents')->where('id', $intent->id)->update([
            'created_at' => $oldDate,
            'updated_at' => $oldDate,
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
            'executed_at' => Carbon::now()->subDays(20), // Only 20 days old -> Must be kept!
            'created_at' => Carbon::now()->subDays(20),
            'updated_at' => Carbon::now()->subDays(20),
        ]);

        $this->artisan('billing:automation-prune')
            ->assertExitCode(0);

        // 35-day-old completed intent pruned
        $this->assertNull(AutomationIntent::find($intent->id));

        // Execution log preserved with null intent_id (ON DELETE SET NULL)
        $log->refresh();
        $this->assertNull($log->intent_id);
        $this->assertEquals($this->station->id, $log->station_id);
    }
}
