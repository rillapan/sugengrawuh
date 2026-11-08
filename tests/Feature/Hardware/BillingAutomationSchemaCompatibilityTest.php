<?php

namespace Tests\Feature\Hardware;

use App\Exceptions\AutomationSchemaNotReadyException;
use App\Models\AutomationIntent;
use App\Models\Device;
use App\Models\Playstation;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BillingService;
use App\Services\Hardware\Automation\AutomationIntentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BillingAutomationSchemaCompatibilityTest extends TestCase
{
    use DatabaseTransactions;

    protected User $kasir;
    protected Playstation $playstation;
    protected Device $station;
    protected BillingService $billingService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertEquals('kasir_ps_test', DB::connection()->getDatabaseName(), 'Must run on kasir_ps_test');

        $this->kasir = User::firstOrCreate(
            ['email' => 'kasir_compat@test.local'],
            ['name' => 'Kasir Compat', 'role' => 'kasir', 'password' => bcrypt('secret123')]
        );

        $this->playstation = Playstation::firstOrCreate(
            ['nama' => 'Billiard Standard Table'],
            ['harga' => 30000]
        );

        $this->station = Device::firstOrCreate(
            ['nama' => 'Meja Biliar Compat 01'],
            ['playstation_id' => $this->playstation->id, 'status' => 'Tersedia']
        );
        $this->station->update(['status' => 'Tersedia']);
        $this->station->refresh();

        if (!$this->station->playstations()->where('playstations.id', $this->playstation->id)->exists()) {
            $this->station->playstations()->attach($this->playstation->id);
        }

        $this->billingService = app(BillingService::class);
    }

    protected function tearDown(): void
    {
        AutomationIntentService::$mockMissingTables = null;
        AutomationIntentService::$mockMissingColumns = null;
        parent::tearDown();
    }

    public function test_legacy_billing_starts_session_safely_when_automation_disabled_without_touching_g5()
    {
        Config::set('hardware.automation_enabled', false);

        $initialIntentCount = AutomationIntent::count();
        $initialGen = (int) ($this->station->session_generation ?? 0);

        $tx = $this->billingService->startSession([
            'device_id' => $this->station->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
            'nama' => 'Legacy Customer',
        ], $this->kasir->id);

        $this->assertInstanceOf(Transaction::class, $tx);
        $this->assertEquals(Transaction::BILLING_RUNNING, $tx->billing_status);
        $this->assertEquals('Digunakan', $this->station->fresh()->status);

        // Transactional outbox must NOT create intents when automation is disabled
        $this->assertEquals($initialIntentCount, AutomationIntent::count(), 'No automation intent should be created when automation is disabled');
        // Generation must not be incremented
        $this->assertEquals($initialGen, (int) ($this->station->fresh()->session_generation ?? 0));
    }

    public function test_legacy_billing_extends_and_stops_session_safely_when_automation_disabled()
    {
        Config::set('hardware.automation_enabled', false);

        $tx = $this->billingService->startSession([
            'device_id' => $this->station->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
            'nama' => 'Legacy Extend Customer',
        ], $this->kasir->id);

        $initialIntentCount = AutomationIntent::count();

        // Extend
        $extendedTx = $this->billingService->extendSession($tx->id_transaksi, 1);
        $this->assertEquals(2, $extendedTx->jam_main);
        $this->assertEquals($initialIntentCount, AutomationIntent::count());

        // Stop
        $stoppedTx = $this->billingService->stopSession($tx->id_transaksi, 'manual_stop');
        $this->assertContains($stoppedTx->billing_status, [Transaction::BILLING_COMPLETED, Transaction::BILLING_STOPPED_EARLY]);
        $this->assertEquals('Tersedia', $this->station->fresh()->status);
        $this->assertEquals($initialIntentCount, AutomationIntent::count());
    }

    public function test_legacy_billing_evaluates_expiration_safely_when_automation_disabled()
    {
        Config::set('hardware.automation_enabled', false);

        $tx = $this->billingService->startSession([
            'device_id' => $this->station->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
            'nama' => 'Legacy Expiry Customer',
        ], $this->kasir->id);

        $initialIntentCount = AutomationIntent::count();

        $future = Carbon::parse($tx->expires_at)->addSecond();
        $result = $this->billingService->evaluateExpiration($future);

        $this->assertGreaterThanOrEqual(1, $result['count']);
        $this->assertEquals(Transaction::BILLING_EXPIRED_PENDING_ACTION, $tx->fresh()->billing_status);
        $this->assertEquals('Tersedia', $this->station->fresh()->status);
        $this->assertEquals($initialIntentCount, AutomationIntent::count(), 'No intent should be recorded upon expiry when automation is disabled');
    }

    public function test_preflight_fails_fast_when_automation_enabled_but_schema_is_incomplete()
    {
        Config::set('hardware.automation_enabled', true);

        // Simulate that automation_intents table is missing
        AutomationIntentService::$mockMissingTables = ['automation_intents'];

        $this->expectException(AutomationSchemaNotReadyException::class);
        $this->expectExceptionMessage('automation_intents');

        $this->billingService->startSession([
            'device_id' => $this->station->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
            'nama' => 'Preflight Test Customer',
        ], $this->kasir->id);
    }

    public function test_preflight_fails_fast_when_automation_enabled_but_session_generation_column_is_missing()
    {
        Config::set('hardware.automation_enabled', true);

        // Simulate missing session_generation column
        AutomationIntentService::$mockMissingColumns = ['devices.session_generation'];

        $this->expectException(AutomationSchemaNotReadyException::class);
        $this->expectExceptionMessage('devices.session_generation');

        $this->billingService->startSession([
            'device_id' => $this->station->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
            'nama' => 'Preflight Column Customer',
        ], $this->kasir->id);
    }

    public function test_transactional_outbox_executes_normally_when_automation_enabled_and_schema_ready()
    {
        Config::set('hardware.automation_enabled', true);
        AutomationIntentService::$mockMissingTables = null;
        AutomationIntentService::$mockMissingColumns = null;

        $initialIntentCount = AutomationIntent::count();
        $initialGen = (int) ($this->station->fresh()->session_generation ?? 0);

        $tx = $this->billingService->startSession([
            'device_id' => $this->station->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
            'nama' => 'Full Outbox Customer',
        ], $this->kasir->id);

        $this->assertEquals(Transaction::BILLING_RUNNING, $tx->billing_status);
        $this->assertGreaterThan($initialGen, (int) $this->station->fresh()->session_generation);
        // Pre-order ON + pre-scheduled OFF
        $this->assertEquals($initialIntentCount + 2, AutomationIntent::count());
    }

    public function test_worker_fails_fast_with_clear_error_when_schema_incomplete()
    {
        AutomationIntentService::$mockMissingTables = ['automation_intents'];

        $this->artisan('billing:automation-worker', ['--once' => true])
            ->expectsOutput('Skema otomatisasi (automation_intents / automation_execution_logs) belum diterapkan.')
            ->assertExitCode(1);
    }

    public function test_prune_command_exits_gracefully_when_schema_incomplete()
    {
        AutomationIntentService::$mockMissingTables = ['automation_intents'];

        $this->artisan('billing:automation-prune')
            ->expectsOutput('Skema otomatisasi (automation_intents) belum diterapkan. Pruning dilewati.')
            ->assertExitCode(0);
    }
}
