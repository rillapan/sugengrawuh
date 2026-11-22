<?php

namespace Tests\Feature\Billing;

use App\Models\AutomationIntent;
use App\Models\CustomPackage;
use App\Models\Device;
use App\Models\PhysicalDevice;
use App\Models\Playstation;
use App\Models\StationDeviceMapping;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Billing\LostTimeBillingService;
use App\Services\Billing\PaketBillingService;
use App\Services\BillingService;
use App\Services\Hardware\Simulation\HardwareSimulationManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class MidnightActiveSessionContinuityTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $kasir;
    protected Playstation $playstation;
    protected Device $device;
    protected BillingService $billingService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertEquals('kasir_ps_test', DB::connection()->getDatabaseName(), 'Tests must run on kasir_ps_test.');

        Config::set('hardware.driver', 'mock');
        Config::set('hardware.real_execution_enabled', false);
        Config::set('hardware.simulation_enabled', true);
        Config::set('hardware.allowed_environments', ['local', 'testing']);
        Config::set('hardware.allowed_databases', ['kasir_ps_test']);

        $this->admin = User::factory()->create([
            'name' => 'Admin Midnight',
            'email' => 'admin.midnight@sugengrawuh.test',
            'role' => 'admin',
        ]);

        $this->kasir = User::factory()->create([
            'name' => 'Kasir Midnight',
            'email' => 'kasir.midnight@sugengrawuh.test',
            'role' => 'kasir',
            'is_fnb_staff' => false,
        ]);

        $this->playstation = Playstation::create([
            'nama' => 'PS5 Midnight',
            'harga' => 15000,
        ]);

        $this->device = $this->createStation('Station Midnight 01');
        $this->billingService = app(BillingService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function createStation(string $name, ?Playstation $ps = null, string $status = 'Tersedia'): Device
    {
        $ps = $ps ?? $this->playstation;
        $device = Device::create([
            'nama' => $name,
            'playstation_id' => $ps->id,
            'status' => $status,
        ]);
        $device->playstations()->attach($ps->id);

        return $device;
    }

    protected function extractStationCardHtml(string $html, int $deviceId): string
    {
        $pattern = '/<article\b[^>]*id="station-card-' . $deviceId . '"[^>]*>.*?<\/article>/s';
        $this->assertSame(1, preg_match($pattern, $html, $matches), "Station card #station-card-{$deviceId} not found in HTML.");

        return $matches[0];
    }

    protected function assertStationCardActive(string $html, int $deviceId, int $expectedTxId): void
    {
        $cardHtml = $this->extractStationCardHtml($html, $deviceId);
        $this->assertMatchesRegularExpression('/class="[^"]*\bis-used\b[^"]*"/', $cardHtml);
        $this->assertStringContainsString('data-tx-id="' . $expectedTxId . '"', $cardHtml);
    }

    protected function assertStationCardAvailable(string $html, int $deviceId, ?int $excludedTxId = null): void
    {
        $cardHtml = $this->extractStationCardHtml($html, $deviceId);
        $this->assertMatchesRegularExpression('/class="[^"]*\bis-available\b[^"]*"/', $cardHtml);
        if ($excludedTxId !== null) {
            $this->assertStringNotContainsString('data-tx-id="' . $excludedTxId . '"', $cardHtml);
        }
    }

    protected function extractPosBootstrap(string $html): array
    {
        $pattern = '/<script type="application\/json" id="pos-bootstrap-data">(.*?)<\/script>/s';
        $this->assertSame(1, preg_match($pattern, $html, $matches), 'POS bootstrap JSON script not found.');

        $decoded = json_decode($matches[1], true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    protected function assertTimerSyncMatchesDevice(
        array $timersResponse,
        int $deviceId,
        string $expectedStatus,
        ?int $expectedTxId
    ): array {
        $target = collect($timersResponse['devices'] ?? [])->firstWhere('id', $deviceId);
        $this->assertNotNull($target, "Device {$deviceId} missing from /device/timers payload.");
        $this->assertSame($expectedStatus, $target['status']);

        if ($expectedTxId === null) {
            $this->assertNull($target['transaction']);
        } else {
            $this->assertNotNull($target['transaction']);
            $this->assertSame($expectedTxId, $target['transaction']['id_transaksi']);
        }

        return $target;
    }

    /** @test */
    public function test_mt_01_lost_time_crossing_midnight_remains_running_and_blocks_double_booking(): void
    {
        $this->actingAs($this->kasir);

        Carbon::setTestNow(Carbon::parse('2026-11-22 23:00:00'));
        $tx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'nama' => 'MT-01 Lost Time',
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        Carbon::setTestNow(Carbon::parse('2026-11-23 00:05:00'));

        $this->get(route('device.index'))->assertStatus(200);
        $txResponse = $this->get(route('transaction.index'))->assertStatus(200);
        $this->assertStationCardActive($txResponse->getContent(), $this->device->id, $tx->id_transaksi);

        $createResponse = $this->get(route('transaction.create'))->assertStatus(200);
        $availableDevices = $createResponse->viewData('devices');
        $this->assertFalse($availableDevices->contains('id', $this->device->id));

        $doubleBookResponse = $this->postJson(route('transaction.store'), [
            'nama' => 'Double Book Attempt',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'postpaid',
        ]);
        $doubleBookResponse->assertStatus(422)->assertJson(['success' => false]);

        $this->assertSame(1, Transaction::where('device_id', $this->device->id)->count());
        $this->assertSame(Transaction::BILLING_RUNNING, $tx->fresh()->billing_status);
        $this->assertSame('Digunakan', $this->device->fresh()->status);
    }

    /** @test */
    public function test_mt_02_lost_time_still_running_at_0200_shows_on_dashboard_with_10800s_elapsed(): void
    {
        $this->actingAs($this->kasir);

        Carbon::setTestNow(Carbon::parse('2026-11-22 23:00:00'));
        $tx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'nama' => 'MT-02 Lost Time 02:00',
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        Carbon::setTestNow(Carbon::parse('2026-11-23 02:00:00'));

        $dashboard = $this->get(route('transaction.index'))->assertStatus(200);
        $this->assertStationCardActive($dashboard->getContent(), $this->device->id, $tx->id_transaksi);

        $timers = $this->getJson(route('device.timers'))->assertStatus(200)->json();
        $target = $this->assertTimerSyncMatchesDevice($timers, $this->device->id, 'Digunakan', $tx->id_transaksi);
        $this->assertSame(10800, $target['transaction']['elapsed_seconds']);
    }

    /** @test */
    public function test_mt_03_prepaid_timer_crossing_midnight_remains_running_at_0005_and_0200(): void
    {
        $this->actingAs($this->kasir);

        Carbon::setTestNow(Carbon::parse('2026-11-22 23:00:00'));
        $tx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'nama' => 'MT-03 Timer 4h',
            'tipe_transaksi' => 'prepaid',
            'waktu_mulai' => '23:00',
            'jam_main' => 4,
        ], $this->kasir->id);

        foreach (['2026-11-23 00:05:00', '2026-11-23 02:00:00'] as $checkTime) {
            Carbon::setTestNow(Carbon::parse($checkTime));

            $txPage = $this->get(route('transaction.index'))->assertStatus(200);
            $this->assertStationCardActive($txPage->getContent(), $this->device->id, $tx->id_transaksi);

            $devPage = $this->get(route('device.index'))->assertStatus(200);
            $timersView = $devPage->viewData('timers');
            $this->assertArrayHasKey($this->device->id, $timersView);
            $this->assertSame('2026-11-23', $timersView[$this->device->id]['end_date']);
            $this->assertSame('03:00:00', $timersView[$this->device->id]['end_time']);

            $timersJson = $this->getJson(route('device.timers'))->assertStatus(200)->json();
            $target = $this->assertTimerSyncMatchesDevice($timersJson, $this->device->id, 'Digunakan', $tx->id_transaksi);
            $this->assertSame(Transaction::BILLING_RUNNING, $target['transaction']['billing_status']);
            $this->assertFalse($target['transaction']['is_expired']);

            $this->assertSame(Transaction::BILLING_RUNNING, $tx->fresh()->billing_status);
            $this->assertSame('Digunakan', $this->device->fresh()->status);
        }
    }

    /** @test */
    public function test_mt_04_prepaid_timer_ending_at_0300_transitions_to_expired_pending_action(): void
    {
        $this->actingAs($this->kasir);

        Carbon::setTestNow(Carbon::parse('2026-11-22 23:00:00'));
        $tx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'nama' => 'MT-04 Timer Expire',
            'tipe_transaksi' => 'prepaid',
            'waktu_mulai' => '23:00',
            'jam_main' => 4,
        ], $this->kasir->id);

        Carbon::setTestNow(Carbon::parse('2026-11-23 03:00:00'));

        $eval = $this->billingService->evaluateExpiration(Carbon::now());
        $this->assertSame(1, $eval['count']);

        $tx->refresh();
        $this->assertSame(Transaction::BILLING_EXPIRED_PENDING_ACTION, $tx->billing_status);
        $this->assertSame('selesai', $tx->status_transaksi);
        $this->assertSame('Tersedia', $this->device->fresh()->status);

        $timersJson = $this->getJson(route('device.timers'))->assertStatus(200)->json();
        $this->assertTimerSyncMatchesDevice($timersJson, $this->device->id, 'Tersedia', null);
    }

    /** @test */
    public function test_mt_05_dashboard_refresh_at_0001_retains_lost_time_and_timer_sessions(): void
    {
        $this->actingAs($this->kasir);
        $secondDevice = $this->createStation('Station Midnight 02');

        Carbon::setTestNow(Carbon::parse('2026-11-22 23:00:00'));
        $lostTx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'nama' => 'MT-05 Lost Time',
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        $timerTx = $this->billingService->startSession([
            'device_id' => $secondDevice->id,
            'nama' => 'MT-05 Timer',
            'tipe_transaksi' => 'prepaid',
            'waktu_mulai' => '23:00',
            'jam_main' => 4,
        ], $this->kasir->id);

        Carbon::setTestNow(Carbon::parse('2026-11-23 00:01:00'));

        for ($refresh = 0; $refresh < 2; $refresh++) {
            $html = $this->get(route('transaction.index'))->assertStatus(200)->getContent();
            $this->assertStationCardActive($html, $this->device->id, $lostTx->id_transaksi);
            $this->assertStationCardActive($html, $secondDevice->id, $timerTx->id_transaksi);
        }
    }

    /** @test */
    public function test_mt_06_device_timers_returns_complete_payload_with_jakarta_offset_after_midnight(): void
    {
        $this->actingAs($this->kasir);
        $timerDevice = $this->createStation('Station Midnight 02');

        Carbon::setTestNow(Carbon::parse('2026-11-22 23:00:00'));
        $lostTx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'nama' => 'MT-06 Lost Time',
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        $timerTx = $this->billingService->startSession([
            'device_id' => $timerDevice->id,
            'nama' => 'MT-06 Timer',
            'tipe_transaksi' => 'prepaid',
            'waktu_mulai' => '23:00',
            'jam_main' => 4,
        ], $this->kasir->id);

        // Check at 00:01:00
        Carbon::setTestNow(Carbon::parse('2026-11-23 00:01:00'));
        $timers0001 = $this->getJson(route('device.timers'))->assertStatus(200)->json();

        $lost0001 = $this->assertTimerSyncMatchesDevice($timers0001, $this->device->id, 'Digunakan', $lostTx->id_transaksi);
        $this->assertSame(Transaction::BILLING_RUNNING, $lost0001['transaction']['billing_status']);
        $this->assertStringEndsWith('+07:00', $lost0001['transaction']['started_at']);
        $this->assertNull($lost0001['transaction']['expires_at']);
        $this->assertSame(3660, $lost0001['transaction']['elapsed_seconds']);
        $this->assertSame(0, $lost0001['transaction']['remaining_seconds']);

        $timer0001 = $this->assertTimerSyncMatchesDevice($timers0001, $timerDevice->id, 'Digunakan', $timerTx->id_transaksi);
        $this->assertSame(Transaction::BILLING_RUNNING, $timer0001['transaction']['billing_status']);
        $this->assertSame('2026-11-22T23:00:00+07:00', $timer0001['transaction']['started_at']);
        $this->assertSame('2026-11-23T03:00:00+07:00', $timer0001['transaction']['expires_at']);
        $this->assertSame(3660, $timer0001['transaction']['elapsed_seconds']);
        $this->assertSame(10740, $timer0001['transaction']['remaining_seconds']);

        // Check at 02:00:00
        Carbon::setTestNow(Carbon::parse('2026-11-23 02:00:00'));
        $timers0200 = $this->getJson(route('device.timers'))->assertStatus(200)->json();

        $lost0200 = $this->assertTimerSyncMatchesDevice($timers0200, $this->device->id, 'Digunakan', $lostTx->id_transaksi);
        $this->assertSame(10800, $lost0200['transaction']['elapsed_seconds']);
        $this->assertSame(0, $lost0200['transaction']['remaining_seconds']);

        $timer0200 = $this->assertTimerSyncMatchesDevice($timers0200, $timerDevice->id, 'Digunakan', $timerTx->id_transaksi);
        $this->assertSame(10800, $timer0200['transaction']['elapsed_seconds']);
        $this->assertSame(3600, $timer0200['transaction']['remaining_seconds']);
    }

    /** @test */
    public function test_mt_07_stale_tersedia_device_with_running_session_displays_active_and_heals_on_reconcile(): void
    {
        $this->actingAs($this->kasir);

        Carbon::setTestNow(Carbon::parse('2026-11-22 23:00:00'));
        $tx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'nama' => 'MT-07 Drift',
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        // Simulate stale DB status drift
        Device::where('id', $this->device->id)->update(['status' => 'Tersedia']);

        Carbon::setTestNow(Carbon::parse('2026-11-23 00:15:00'));

        $dashboard = $this->get(route('transaction.index'))->assertStatus(200);
        $this->assertStationCardActive($dashboard->getContent(), $this->device->id, $tx->id_transaksi);

        $timers = $this->getJson(route('device.timers'))->assertStatus(200)->json();
        $this->assertTimerSyncMatchesDevice($timers, $this->device->id, 'Digunakan', $tx->id_transaksi);

        $report = $this->billingService->reconcileSessions(Carbon::now());
        $this->assertGreaterThanOrEqual(1, $report['devices_healed']);
        $this->assertSame('Digunakan', $this->device->fresh()->status);
    }

    /** @test */
    public function test_mt_08_finished_transactions_are_not_shown_as_active_after_midnight(): void
    {
        $this->actingAs($this->kasir);
        $devStopped = $this->createStation('Station Stopped Early');
        $devExpired = $this->createStation('Station Expired Pending');

        Carbon::setTestNow(Carbon::parse('2026-11-22 22:00:00'));

        $completedTx = Transaction::create([
            'status' => 'user',
            'nama' => 'Completed Customer',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'postpaid',
            'waktu_mulai' => '21:00',
            'waktu_Selesai' => '22:00',
            'started_at' => Carbon::parse('2026-11-22 21:00:00'),
            'ended_at' => Carbon::parse('2026-11-22 22:00:00'),
            'status_transaksi' => 'selesai',
            'billing_status' => Transaction::BILLING_COMPLETED,
            'payment_status' => 'paid',
            'harga' => 15000,
            'total' => 15000,
        ]);

        $stoppedTx = Transaction::create([
            'status' => 'user',
            'nama' => 'Stopped Early Customer',
            'device_id' => $devStopped->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'prepaid',
            'waktu_mulai' => '21:00',
            'waktu_Selesai' => '22:00',
            'started_at' => Carbon::parse('2026-11-22 21:00:00'),
            'expires_at' => Carbon::parse('2026-11-22 23:00:00'),
            'ended_at' => Carbon::parse('2026-11-22 22:00:00'),
            'jam_main' => 2,
            'status_transaksi' => 'selesai',
            'billing_status' => Transaction::BILLING_STOPPED_EARLY,
            'payment_status' => 'unpaid',
            'harga' => 15000,
            'total' => 30000,
        ]);

        $expiredTx = Transaction::create([
            'status' => 'user',
            'nama' => 'Expired Pending Customer',
            'device_id' => $devExpired->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'prepaid',
            'waktu_mulai' => '20:00',
            'waktu_Selesai' => '22:00',
            'started_at' => Carbon::parse('2026-11-22 20:00:00'),
            'expires_at' => Carbon::parse('2026-11-22 22:00:00'),
            'ended_at' => Carbon::parse('2026-11-22 22:00:00'),
            'jam_main' => 2,
            'status_transaksi' => 'selesai',
            'billing_status' => Transaction::BILLING_EXPIRED_PENDING_ACTION,
            'payment_status' => 'unpaid',
            'harga' => 15000,
            'total' => 30000,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-11-23 00:15:00'));

        $html = $this->get(route('transaction.index'))->assertStatus(200)->getContent();
        $this->assertStationCardAvailable($html, $this->device->id, $completedTx->id_transaksi);
        $this->assertStationCardAvailable($html, $devStopped->id, $stoppedTx->id_transaksi);
        $this->assertStationCardAvailable($html, $devExpired->id, $expiredTx->id_transaksi);

        $timers = $this->getJson(route('device.timers'))->assertStatus(200)->json();
        $this->assertTimerSyncMatchesDevice($timers, $this->device->id, 'Tersedia', null);
        $this->assertTimerSyncMatchesDevice($timers, $devStopped->id, 'Tersedia', null);
        $this->assertTimerSyncMatchesDevice($timers, $devExpired->id, 'Tersedia', null);
    }

    /** @test */
    public function test_mt_09_running_unpaid_session_remains_unpaid_and_running_after_midnight(): void
    {
        $this->actingAs($this->kasir);
        $timerDevice = $this->createStation('Station Unpaid Timer');

        Carbon::setTestNow(Carbon::parse('2026-11-22 23:00:00'));
        $lostTx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'nama' => 'MT-09 Unpaid Lost',
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        $timerTx = $this->billingService->startSession([
            'device_id' => $timerDevice->id,
            'nama' => 'MT-09 Unpaid Timer',
            'tipe_transaksi' => 'prepaid',
            'waktu_mulai' => '23:00',
            'jam_main' => 4,
        ], $this->kasir->id);

        Carbon::setTestNow(Carbon::parse('2026-11-23 00:30:00'));

        $html = $this->get(route('transaction.index'))->assertStatus(200)->getContent();
        $this->assertStationCardActive($html, $this->device->id, $lostTx->id_transaksi);
        $this->assertStationCardActive($html, $timerDevice->id, $timerTx->id_transaksi);

        $timers = $this->getJson(route('device.timers'))->assertStatus(200)->json();
        $this->assertTimerSyncMatchesDevice($timers, $this->device->id, 'Digunakan', $lostTx->id_transaksi);
        $this->assertTimerSyncMatchesDevice($timers, $timerDevice->id, 'Digunakan', $timerTx->id_transaksi);

        $this->assertSame('unpaid', $lostTx->fresh()->payment_status);
        $this->assertSame(Transaction::BILLING_RUNNING, $lostTx->fresh()->billing_status);
        $this->assertSame('unpaid', $timerTx->fresh()->payment_status);
        $this->assertSame(Transaction::BILLING_RUNNING, $timerTx->fresh()->billing_status);
    }

    /** @test */
    public function test_mt_10_running_paid_session_remains_paid_and_running_until_expires_at(): void
    {
        $this->actingAs($this->kasir);

        Carbon::setTestNow(Carbon::parse('2026-11-22 23:00:00'));
        $draftTx = Transaction::create([
            'status' => 'user',
            'nama' => 'MT-10 Paid Timer',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'prepaid',
            'waktu_mulai' => '23:00',
            'jam_main' => 4,
            'duration_seconds' => 14400,
            'harga' => 15000,
            'total' => 60000,
            'status_transaksi' => 'draft',
            'payment_status' => 'unpaid',
            'billing_status' => Transaction::BILLING_IDLE,
        ]);

        app(PaketBillingService::class)->activatePaidPaket($draftTx, [
            'payment_method' => 'tunai',
            'amount_paid' => 60000,
            'cashier_user_id' => $this->kasir->id,
        ]);

        $draftTx->refresh();
        $this->assertSame('paid', $draftTx->payment_status);
        $this->assertSame(Transaction::BILLING_RUNNING, $draftTx->billing_status);
        $this->assertSame('2026-11-23 03:00:00', $draftTx->expires_at->toDateTimeString());

        Carbon::setTestNow(Carbon::parse('2026-11-23 01:30:00'));
        $html = $this->get(route('transaction.index'))->assertStatus(200)->getContent();
        $this->assertStationCardActive($html, $this->device->id, $draftTx->id_transaksi);

        $timers = $this->getJson(route('device.timers'))->assertStatus(200)->json();
        $this->assertTimerSyncMatchesDevice($timers, $this->device->id, 'Digunakan', $draftTx->id_transaksi);
        $this->assertSame('paid', $draftTx->fresh()->payment_status);
        $this->assertSame(Transaction::BILLING_RUNNING, $draftTx->fresh()->billing_status);

        Carbon::setTestNow(Carbon::parse('2026-11-23 03:00:00'));
        $this->billingService->evaluateExpiration(Carbon::now());

        $draftTx->refresh();
        $this->assertSame(Transaction::BILLING_EXPIRED_PENDING_ACTION, $draftTx->billing_status);
        $this->assertSame('paid', $draftTx->payment_status);
        $this->assertSame('Tersedia', $this->device->fresh()->status);
    }

    /** @test */
    public function test_mt_11_lost_time_2300_to_0230_calculates_exact_duration_and_cost_across_end_payment_and_checkout(): void
    {
        $this->actingAs($this->kasir);

        Carbon::setTestNow(Carbon::parse('2026-11-22 23:00:00'));
        $tx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'nama' => 'MT-11 Lost Time Checkout',
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        Carbon::setTestNow(Carbon::parse('2026-11-23 02:30:00'));

        $rawPrice = $this->billingService->calculateLostTimePrice($this->playstation, 210);
        $expectedCost = $this->billingService->applyLostTimeRounding($rawPrice);
        $this->assertSame(52500.0, $expectedCost);

        $endResponse = $this->post(route('transaction.end', $tx->id_transaksi));
        $endResponse->assertRedirect(route('transaction.showPayment', $tx->id_transaksi));

        $tx->refresh();
        $this->assertSame('3 jam 30 menit', $tx->jam_main);
        $this->assertEquals($expectedCost, (float) $tx->total);
        $this->assertSame('selesai', $tx->status_transaksi);
        $this->assertSame(Transaction::BILLING_COMPLETED, $tx->billing_status);

        $paymentPage = $this->get(route('transaction.showPayment', $tx->id_transaksi))->assertStatus(200);
        $this->assertEquals($expectedCost, (float) $paymentPage->viewData('payableSubtotal'));

        $invoice = app(LostTimeBillingService::class)->checkoutLostTime($tx->id_transaksi, [
            'payment_method' => 'tunai',
            'amount_paid' => $expectedCost,
            'cashier_user_id' => $this->kasir->id,
        ]);

        $this->assertEquals($expectedCost, (float) $invoice->final_total);
        $this->assertSame('paid', $tx->fresh()->payment_status);
    }

    /** @test */
    public function test_mt_12_timer_extended_after_midnight_uses_new_expires_at_and_stays_running(): void
    {
        $this->actingAs($this->kasir);

        Carbon::setTestNow(Carbon::parse('2026-11-22 23:00:00'));
        $tx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'nama' => 'MT-12 Extended Timer',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 2,
        ], $this->kasir->id);

        $this->assertSame('2026-11-23 01:00:00', $tx->expires_at->toDateTimeString());

        Carbon::setTestNow(Carbon::parse('2026-11-23 00:30:00'));
        $extended = $this->billingService->extendSession($tx->id_transaksi, 2);

        $this->assertSame('2026-11-23 03:00:00', $extended->expires_at->toDateTimeString());
        $this->assertSame(Transaction::BILLING_RUNNING, $extended->billing_status);

        // Advance past original 01:00:00 expiry
        Carbon::setTestNow(Carbon::parse('2026-11-23 01:15:00'));
        $eval = $this->billingService->evaluateExpiration(Carbon::now());
        $this->assertSame(0, $eval['count']);
        $this->assertSame(Transaction::BILLING_RUNNING, $tx->fresh()->billing_status);

        $timers = $this->getJson(route('device.timers'))->assertStatus(200)->json();
        $target = $this->assertTimerSyncMatchesDevice($timers, $this->device->id, 'Digunakan', $tx->id_transaksi);
        $this->assertSame('2026-11-23T03:00:00+07:00', $target['transaction']['expires_at']);
    }

    /** @test */
    public function test_mt_13_two_running_sessions_on_same_device_logs_warning_and_returns_latest(): void
    {
        $this->actingAs($this->kasir);

        Carbon::setTestNow(Carbon::parse('2026-11-22 23:00:00'));
        $this->device->update(['status' => 'Digunakan']);

        $olderTx = Transaction::create([
            'status' => 'user',
            'nama' => 'Older Running',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'postpaid',
            'waktu_mulai' => '23:00',
            'started_at' => Carbon::parse('2026-11-22 23:00:00'),
            'lost_time_start' => Carbon::parse('2026-11-22 23:00:00'),
            'status_transaksi' => 'berjalan',
            'billing_status' => Transaction::BILLING_RUNNING,
            'payment_status' => 'unpaid',
            'harga' => 15000,
            'total' => 0,
        ]);

        $newerTx = Transaction::create([
            'status' => 'user',
            'nama' => 'Newer Running',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'postpaid',
            'waktu_mulai' => '23:30',
            'started_at' => Carbon::parse('2026-11-22 23:30:00'),
            'lost_time_start' => Carbon::parse('2026-11-22 23:30:00'),
            'status_transaksi' => 'berjalan',
            'billing_status' => Transaction::BILLING_RUNNING,
            'payment_status' => 'unpaid',
            'harga' => 15000,
            'total' => 0,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-11-23 00:15:00'));

        Log::spy();

        $resolved = $this->billingService->resolveActiveSessionForDevice($this->device->id, Carbon::now());
        $this->assertNotNull($resolved);
        $this->assertSame($newerTx->id_transaksi, $resolved->id_transaksi);
        $this->assertNotSame($olderTx->id_transaksi, $resolved->id_transaksi);

        $timers = $this->getJson(route('device.timers'))->assertStatus(200)->json();
        $this->assertTimerSyncMatchesDevice($timers, $this->device->id, 'Digunakan', $newerTx->id_transaksi);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'Multiple RUNNING sessions detected'))
            ->atLeast()
            ->once();
    }

    /** @test */
    public function test_mt_14_reconcile_sessions_is_idempotent_across_repeated_calls(): void
    {
        $overdueDevice = $this->createStation('Station Overdue');

        Carbon::setTestNow(Carbon::parse('2026-11-22 23:00:00'));
        $lostTx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'nama' => 'MT-14 Lost Time',
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        $overdueTx = $this->billingService->startSession([
            'device_id' => $overdueDevice->id,
            'nama' => 'MT-14 Overdue Timer',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
        ], $this->kasir->id);

        // Drift device status before reconcile
        Device::where('id', $this->device->id)->update(['status' => 'Tersedia']);

        Carbon::setTestNow(Carbon::parse('2026-11-23 00:30:00'));

        $first = $this->billingService->reconcileSessions(Carbon::now());
        $this->assertSame(1, $first['expired_processed']);
        $this->assertSame(1, $first['postpaid_survived']);
        $this->assertSame(1, $first['devices_healed']);

        $second = $this->billingService->reconcileSessions(Carbon::now());
        $this->assertSame(0, $second['expired_processed']);
        $this->assertSame(1, $second['postpaid_survived']);
        $this->assertSame(0, $second['devices_healed']);

        $this->assertSame(2, Transaction::count());
        $this->assertSame('unpaid', $lostTx->fresh()->payment_status);
        $this->assertSame('unpaid', $overdueTx->fresh()->payment_status);
        $this->assertSame(Transaction::BILLING_RUNNING, $lostTx->fresh()->billing_status);
        $this->assertSame(Transaction::BILLING_EXPIRED_PENDING_ACTION, $overdueTx->fresh()->billing_status);
    }

    /** @test */
    public function test_mt_15_end_of_month_and_end_of_year_transitions_preserve_active_sessions_and_cost(): void
    {
        $this->actingAs($this->kasir);
        $timerDevice = $this->createStation('Station Boundary Timer');

        $boundaries = [
            ['start' => '2026-11-30 23:30:00', 'check' => '2026-12-01 01:30:00', 'exp' => '2026-12-01 02:30:00'],
            ['start' => '2026-12-31 23:30:00', 'check' => '2027-01-01 01:30:00', 'exp' => '2027-01-01 02:30:00'],
        ];

        foreach ($boundaries as $idx => $b) {
            Carbon::setTestNow(Carbon::parse($b['start']));

            $lostTx = $this->billingService->startSession([
                'device_id' => $this->device->id,
                'nama' => "MT-15 Lost {$idx}",
                'tipe_transaksi' => 'postpaid',
            ], $this->kasir->id);

            $timerTx = $this->billingService->startSession([
                'device_id' => $timerDevice->id,
                'nama' => "MT-15 Timer {$idx}",
                'tipe_transaksi' => 'prepaid',
                'jam_main' => 3,
            ], $this->kasir->id);

            Carbon::setTestNow(Carbon::parse($b['check']));

            $timers = $this->getJson(route('device.timers'))->assertStatus(200)->json();
            $lostSync = $this->assertTimerSyncMatchesDevice($timers, $this->device->id, 'Digunakan', $lostTx->id_transaksi);
            $this->assertSame(7200, $lostSync['transaction']['elapsed_seconds']);

            $timerSync = $this->assertTimerSyncMatchesDevice($timers, $timerDevice->id, 'Digunakan', $timerTx->id_transaksi);
            $this->assertSame(7200, $timerSync['transaction']['elapsed_seconds']);
            $this->assertSame(3600, $timerSync['transaction']['remaining_seconds']);
            $this->assertSame($b['exp'], $timerTx->fresh()->expires_at->toDateTimeString());

            $stoppedLost = $this->billingService->stopSession($lostTx->id_transaksi, 'boundary_check');
            $this->assertSame('2 jam 0 menit', $stoppedLost->jam_main);
            $this->assertEquals(30000.0, (float) $stoppedLost->total);

            $this->billingService->stopSession($timerTx->id_transaksi, 'boundary_cleanup');
        }
    }

    /** @test */
    public function test_mt_16_simulated_server_restart_reconciles_and_preserves_cross_midnight_sessions(): void
    {
        $this->actingAs($this->kasir);
        $prepaidActiveDev = $this->createStation('Station Restart Active');
        $prepaidExpiredDev = $this->createStation('Station Restart Expired');

        Carbon::setTestNow(Carbon::parse('2026-11-22 23:00:00'));
        $lostTx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'nama' => 'MT-16 Lost Time',
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        $activeTimerTx = $this->billingService->startSession([
            'device_id' => $prepaidActiveDev->id,
            'nama' => 'MT-16 Active Timer',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 3,
        ], $this->kasir->id);

        $expiredTimerTx = $this->billingService->startSession([
            'device_id' => $prepaidExpiredDev->id,
            'nama' => 'MT-16 Expired Timer',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
        ], $this->kasir->id);

        // Simulate DB drift before restart recovery
        Device::where('id', $this->device->id)->update(['status' => 'Tersedia']);

        Carbon::setTestNow(Carbon::parse('2026-11-23 00:30:00'));
        $report = $this->billingService->reconcileSessions(Carbon::now());

        $this->assertSame(1, $report['expired_processed']);
        $this->assertSame(1, $report['postpaid_survived']);
        $this->assertSame(1, $report['devices_healed']);

        $this->assertSame(Transaction::BILLING_EXPIRED_PENDING_ACTION, $expiredTimerTx->fresh()->billing_status);
        $this->assertSame('Tersedia', $prepaidExpiredDev->fresh()->status);

        $html = $this->get(route('transaction.index'))->assertStatus(200)->getContent();
        $this->assertStationCardActive($html, $this->device->id, $lostTx->id_transaksi);
        $this->assertStationCardActive($html, $prepaidActiveDev->id, $activeTimerTx->id_transaksi);
        $this->assertStationCardAvailable($html, $prepaidExpiredDev->id, $expiredTimerTx->id_transaksi);

        $timers = $this->getJson(route('device.timers'))->assertStatus(200)->json();
        $this->assertTimerSyncMatchesDevice($timers, $this->device->id, 'Digunakan', $lostTx->id_transaksi);
        $this->assertTimerSyncMatchesDevice($timers, $prepaidActiveDev->id, 'Digunakan', $activeTimerTx->id_transaksi);
        $this->assertTimerSyncMatchesDevice($timers, $prepaidExpiredDev->id, 'Tersedia', null);
    }

    /** @test */
    public function test_mt_17_legacy_session_without_g2_columns_crosses_midnight_and_finished_legacy_stays_inactive(): void
    {
        $this->actingAs($this->kasir);
        $finishedDevice = $this->createStation('Station Legacy Finished', null, 'Tersedia');

        Carbon::setTestNow(Carbon::parse('2026-11-22 23:00:00'));
        $this->device->update(['status' => 'Digunakan']);

        $legacyActive = Transaction::create([
            'status' => 'user',
            'nama' => 'MT-17 Legacy Active',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'prepaid',
            'waktu_mulai' => '23:00',
            'waktu_Selesai' => '01:30',
            'jam_main' => 2.5,
            'harga' => 15000,
            'total' => 37500,
            'status_transaksi' => 'sukses',
            'payment_status' => 'unpaid',
            'billing_status' => null,
            'expires_at' => null,
        ]);

        $legacyFinished = Transaction::create([
            'status' => 'user',
            'nama' => 'MT-17 Legacy Finished',
            'device_id' => $finishedDevice->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'prepaid',
            'waktu_mulai' => '21:00',
            'waktu_Selesai' => '23:00',
            'jam_main' => 2,
            'harga' => 15000,
            'total' => 30000,
            'status_transaksi' => 'selesai',
            'payment_status' => 'paid',
            'billing_status' => null,
            'expires_at' => null,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-11-23 00:15:00'));

        $html = $this->get(route('transaction.index'))->assertStatus(200)->getContent();
        $this->assertStationCardActive($html, $this->device->id, $legacyActive->id_transaksi);
        $this->assertStationCardAvailable($html, $finishedDevice->id, $legacyFinished->id_transaksi);

        $timers = $this->getJson(route('device.timers'))->assertStatus(200)->json();
        $this->assertTimerSyncMatchesDevice($timers, $this->device->id, 'Digunakan', $legacyActive->id_transaksi);
        $this->assertTimerSyncMatchesDevice($timers, $finishedDevice->id, 'Tersedia', null);
    }

    /** @test */
    public function test_mt_18_frontend_polling_consistency_dom_and_bootstrap_match_timer_sync_after_midnight(): void
    {
        $this->actingAs($this->kasir);
        $timerDevice = $this->createStation('Station Poll Timer');
        $idleDevice = $this->createStation('Station Poll Idle');

        Carbon::setTestNow(Carbon::parse('2026-11-22 23:00:00'));
        $lostTx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'nama' => 'MT-18 Lost Time',
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        $timerTx = $this->billingService->startSession([
            'device_id' => $timerDevice->id,
            'nama' => 'MT-18 Timer',
            'tipe_transaksi' => 'prepaid',
            'waktu_mulai' => '23:00',
            'jam_main' => 4,
        ], $this->kasir->id);

        // Drift device DB status to ensure DOM, bootstrap, and /device/timers still stay aligned
        Device::where('id', $this->device->id)->update(['status' => 'Tersedia']);

        foreach (['2026-11-23 00:01:00', '2026-11-23 02:00:00'] as $checkTime) {
            Carbon::setTestNow(Carbon::parse($checkTime));

            $html = $this->get(route('transaction.index'))->assertStatus(200)->getContent();
            $bootstrap = $this->extractPosBootstrap($html);
            $bootstrapDevices = collect($bootstrap['devices'])->keyBy('id');
            $timers = $this->getJson(route('device.timers'))->assertStatus(200)->json();

            $expectedMap = [
                $this->device->id => ['status' => 'Digunakan', 'tx_id' => $lostTx->id_transaksi],
                $timerDevice->id => ['status' => 'Digunakan', 'tx_id' => $timerTx->id_transaksi],
                $idleDevice->id => ['status' => 'Tersedia', 'tx_id' => null],
            ];

            foreach ($expectedMap as $devId => $expected) {
                $syncItem = $this->assertTimerSyncMatchesDevice($timers, $devId, $expected['status'], $expected['tx_id']);
                $this->assertSame($syncItem['status'], $bootstrapDevices[$devId]['status']);

                if ($expected['tx_id'] !== null) {
                    $this->assertStationCardActive($html, $devId, $expected['tx_id']);
                    $this->assertSame($expected['tx_id'], $bootstrap['activeTransactions'][(string) $devId]['id_transaksi']);
                } else {
                    $this->assertStationCardAvailable($html, $devId);
                    $this->assertArrayNotHasKey((string) $devId, $bootstrap['activeTransactions']);
                }
            }
        }
    }

    /** @test */
    public function test_mt_19_expiration_with_hardware_automation_does_not_duplicate_off_intents(): void
    {
        Config::set('hardware.automation_enabled', true);

        $shelly = PhysicalDevice::create([
            'name' => 'Shelly Midnight Relay',
            'mac_address' => 'AA:BB:CC:DD:EE:99',
            'category' => PhysicalDevice::CATEGORY_SMART_RELAY,
            'brand' => PhysicalDevice::BRAND_SHELLY,
            'model' => 'Shelly 1PM Mini Gen3',
            'ip_address' => '192.168.1.199',
            'is_active' => true,
            'connection_status' => 'ONLINE',
            'verified_capabilities' => ['SWITCH_ON', 'SWITCH_OFF', 'GET_STATUS'],
        ]);

        StationDeviceMapping::create([
            'station_id' => $this->device->id,
            'physical_device_id' => $shelly->id,
            'mapping_role' => StationDeviceMapping::ROLE_LIGHT_RELAY,
            'mapping_status' => StationDeviceMapping::STATUS_ACTIVE,
            'created_by' => $this->admin->id,
        ]);

        app(HardwareSimulationManager::class)->resetAll();

        Carbon::setTestNow(Carbon::parse('2026-11-22 23:00:00'));
        $tx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'nama' => 'MT-19 Automated Timer',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 2,
        ], $this->kasir->id);

        Carbon::setTestNow(Carbon::parse('2026-11-23 01:00:00'));

        $this->billingService->evaluateExpiration(Carbon::now());
        $this->billingService->evaluateExpiration(Carbon::now());
        $this->artisan('billing:daemon', ['--once' => true])->assertExitCode(0);
        $this->artisan('billing:daemon', ['--once' => true])->assertExitCode(0);

        $offIntents = AutomationIntent::where('transaction_id', $tx->id_transaksi)
            ->where('event_type', AutomationIntent::EVENT_BILLING_EXPIRED)
            ->get();

        $this->assertCount(1, $offIntents);
        $this->assertSame(AutomationIntent::STATE_OFF, $offIntents->first()->desired_state);
        $this->assertSame(AutomationIntent::STATUS_COMPLETED, $offIntents->first()->status);
        $this->assertSame('SIMULATED_OFF', $this->device->fresh()->last_hardware_state);
    }

    /** @test */
    public function test_mt_20_reconcile_sessions_heals_device_status_discrepancies_bidirectionally(): void
    {
        $devNeedsDigunakan = $this->device; // status Tersedia + RUNNING tx
        $devNeedsTersedia = $this->createStation('Station Stale Digunakan', null, 'Digunakan');
        $devControlTersedia = $this->createStation('Station Control Tersedia', null, 'Tersedia');
        $devControlDigunakan = $this->createStation('Station Control Digunakan', null, 'Digunakan');

        Carbon::setTestNow(Carbon::parse('2026-11-22 23:00:00'));

        Transaction::create([
            'status' => 'user',
            'nama' => 'MT-20 Running on Tersedia',
            'device_id' => $devNeedsDigunakan->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'postpaid',
            'waktu_mulai' => '23:00',
            'started_at' => Carbon::parse('2026-11-22 23:00:00'),
            'lost_time_start' => Carbon::parse('2026-11-22 23:00:00'),
            'status_transaksi' => 'berjalan',
            'billing_status' => Transaction::BILLING_RUNNING,
            'payment_status' => 'unpaid',
            'harga' => 15000,
            'total' => 0,
        ]);

        Transaction::create([
            'status' => 'user',
            'nama' => 'MT-20 Completed on Digunakan',
            'device_id' => $devNeedsTersedia->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'postpaid',
            'waktu_mulai' => '21:00',
            'waktu_Selesai' => '22:00',
            'started_at' => Carbon::parse('2026-11-22 21:00:00'),
            'ended_at' => Carbon::parse('2026-11-22 22:00:00'),
            'status_transaksi' => 'selesai',
            'billing_status' => Transaction::BILLING_COMPLETED,
            'payment_status' => 'paid',
            'harga' => 15000,
            'total' => 15000,
        ]);

        Transaction::create([
            'status' => 'user',
            'nama' => 'MT-20 Control Running',
            'device_id' => $devControlDigunakan->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'postpaid',
            'waktu_mulai' => '23:00',
            'started_at' => Carbon::parse('2026-11-22 23:00:00'),
            'lost_time_start' => Carbon::parse('2026-11-22 23:00:00'),
            'status_transaksi' => 'berjalan',
            'billing_status' => Transaction::BILLING_RUNNING,
            'payment_status' => 'unpaid',
            'harga' => 15000,
            'total' => 0,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-11-23 00:10:00'));

        $report = $this->billingService->reconcileSessions(Carbon::now());

        $this->assertSame(2, $report['devices_healed']);
        $this->assertSame('Digunakan', $devNeedsDigunakan->fresh()->status);
        $this->assertSame('Tersedia', $devNeedsTersedia->fresh()->status);
        $this->assertSame('Tersedia', $devControlTersedia->fresh()->status);
        $this->assertSame('Digunakan', $devControlDigunakan->fresh()->status);
    }

    /** @test */
    public function test_store_evaluates_expired_session_before_availability_and_blocks_double_booking(): void
    {
        $this->actingAs($this->kasir);

        $customPkg = CustomPackage::create([
            'nama_paket' => 'Paket Store Expiry Test',
            'harga_total' => 25000,
            'status' => 'active',
        ]);
        $customPkg->playstations()->attach($this->playstation->id, ['lama_main' => 60]);

        Carbon::setTestNow(Carbon::parse('2026-11-22 23:00:00'));

        // Start 2h prepaid session ending at 01:00
        $expiredCandidate = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'nama' => 'Sesi Sebelumnya',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 2,
        ], $this->kasir->id);

        // At 00:30 (still active): postpaid and custom_package store MUST be rejected without altering running session
        Carbon::setTestNow(Carbon::parse('2026-11-23 00:30:00'));

        $this->postJson(route('transaction.store'), [
            'tipe_transaksi' => 'postpaid',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'nama' => 'Penumpang Gelap 1',
        ])->assertStatus(422);

        $this->postJson(route('transaction.store'), [
            'tipe_transaksi' => 'custom_package',
            'device_id' => $this->device->id,
            'custom_package_id' => $customPkg->id,
            'action' => 'simpan',
            'nama' => 'Penumpang Gelap 2',
        ])->assertStatus(422);

        $this->assertSame(Transaction::BILLING_RUNNING, $expiredCandidate->fresh()->billing_status);
        $this->assertSame('Digunakan', $this->device->fresh()->status);

        // At 01:00:05 (session expired, daemon hasn't freed device yet): store evaluates expiration first, frees & books cleanly
        Carbon::setTestNow(Carbon::parse('2026-11-23 01:00:05'));

        $newResp = $this->postJson(route('transaction.store'), [
            'tipe_transaksi' => 'postpaid',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'nama' => 'Pemain Baru Setelah Expired',
        ]);

        $newResp->assertStatus(201)->assertJson(['success' => true]);
        $newTxId = $newResp->json('transaction_id');

        $this->assertSame(Transaction::BILLING_EXPIRED_PENDING_ACTION, $expiredCandidate->fresh()->billing_status);
        $this->assertSame('selesai', $expiredCandidate->fresh()->status_transaksi);

        $newTx = Transaction::findOrFail($newTxId);
        $this->assertSame(Transaction::BILLING_RUNNING, $newTx->billing_status);
        $this->assertSame('Digunakan', $this->device->fresh()->status);

        // Subsequent immediate store on same occupied station must still fail (no double booking)
        $this->postJson(route('transaction.store'), [
            'tipe_transaksi' => 'postpaid',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'nama' => 'Double Booking Reject',
        ])->assertStatus(422);
    }

    /** @test */
    public function test_debug_device_and_update_status_protect_cross_midnight_running_sessions(): void
    {
        $owner = User::factory()->create([
            'name' => 'Owner Legacy Guard',
            'email' => 'owner.legacy.guard@sugengrawuh.test',
            'role' => 'owner',
            'is_fnb_staff' => false,
        ]);

        $prepaidDev = $this->createStation('Station Legacy Guard Timer');
        $idleDev = $this->createStation('Station Idle Release', null, 'Digunakan');

        Carbon::setTestNow(Carbon::parse('2026-11-22 23:00:00'));

        $lostTx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'nama' => 'Cross Midnight Lost Time',
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        $timerTx = $this->billingService->startSession([
            'device_id' => $prepaidDev->id,
            'nama' => 'Cross Midnight Prepaid Timer',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 4,
        ], $this->kasir->id);

        // Simulate stale DB status on prepaid station while session is still RUNNING
        Device::where('id', $prepaidDev->id)->update(['status' => 'Tersedia']);

        // Completed historical session on idleDev (should allow safe release via updateStatus)
        Transaction::create([
            'status' => 'user',
            'nama' => 'Sudah Selesai',
            'device_id' => $idleDev->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'postpaid',
            'waktu_mulai' => '21:00',
            'waktu_Selesai' => '22:00',
            'started_at' => Carbon::parse('2026-11-22 21:00:00'),
            'ended_at' => Carbon::parse('2026-11-22 22:00:00'),
            'status_transaksi' => 'selesai',
            'billing_status' => Transaction::BILLING_COMPLETED,
            'payment_status' => 'paid',
            'harga' => 15000,
            'total' => 15000,
        ]);

        // Advance past midnight
        Carbon::setTestNow(Carbon::parse('2026-11-23 00:30:00'));

        // 1. debugDevice() is read-only and accurately detects both cross-midnight RUNNING sessions (including prepaid)
        $this->actingAs($this->kasir);
        $dbgPrepaid = $this->getJson(route('device.debug', $prepaidDev->id))->assertStatus(200)->json();
        $this->assertTrue($dbgPrepaid['success']);
        $this->assertSame('Digunakan', $dbgPrepaid['effective_status']);
        $this->assertTrue($dbgPrepaid['has_status_mismatch']);
        $this->assertSame($timerTx->id_transaksi, $dbgPrepaid['active_session_id']);
        $this->assertSame(1, $dbgPrepaid['active_transactions_count']);
        $this->assertTrue($dbgPrepaid['should_show_stop_button']);
        $this->assertSame('Tersedia', $prepaidDev->fresh()->status, 'debugDevice must not mutate DB status');

        $dbgLost = $this->getJson(route('device.debug', $this->device->id))->assertStatus(200)->json();
        $this->assertSame($lostTx->id_transaksi, $dbgLost['active_session_id']);
        $this->assertSame(1, $dbgLost['active_transactions_count']);

        // 2. Unauthorized role cannot call updateStatus()
        $this->actingAs($owner)
            ->postJson(route('device.updateStatus', $this->device->id))
            ->assertStatus(403);

        // 3. Cashier calling legacy updateStatus() on RUNNING Lost Time or Timer MUST be rejected (409) and never terminate session
        $this->actingAs($this->kasir);
        $this->postJson(route('device.updateStatus', $this->device->id))
            ->assertStatus(409)
            ->assertJson(['success' => false, 'transaction_id' => $lostTx->id_transaksi]);

        $this->postJson(route('device.updateStatus', $prepaidDev->id))
            ->assertStatus(409)
            ->assertJson(['success' => false, 'transaction_id' => $timerTx->id_transaksi]);

        $this->assertSame(Transaction::BILLING_RUNNING, $lostTx->fresh()->billing_status);
        $this->assertSame('berjalan', $lostTx->fresh()->status_transaksi);
        $this->assertSame('Digunakan', $this->device->fresh()->status);

        $this->assertSame(Transaction::BILLING_RUNNING, $timerTx->fresh()->billing_status);
        $this->assertSame('sukses', $timerTx->fresh()->status_transaksi);
        $this->assertSame('Digunakan', $prepaidDev->fresh()->status);

        // 4. Calling updateStatus() on an occupied station with NO active session safely frees it to Tersedia
        $this->postJson(route('device.updateStatus', $idleDev->id))
            ->assertStatus(200)
            ->assertJson(['success' => true, 'new_status' => 'Tersedia']);
        $this->assertSame('Tersedia', $idleDev->fresh()->status);
    }

    /**
     * SR-BILLING-MIDNIGHT-002 (TEST-01):
     * Timer yang sudah mencapai exact expires_at diproses expiration-nya sebelum validasi sesi baru,
     * meski devices.status masih tertinggal Digunakan, tanpa menyisakan dua sesi RUNNING.
     */
    public function test_01_expired_timer_at_exact_expires_at_allows_new_prepaid_without_duplicate_running(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-21 23:00:00'));

        $kasir = User::factory()->create(['role' => 'kasir']);
        $ps = Playstation::create(['nama' => 'PS5 Test-01', 'harga' => 15000]);
        \App\Models\HourlyPrice::create(['playstation_id' => $ps->id, 'hours' => 1, 'price' => 15000]);
        \App\Models\HourlyPrice::create(['playstation_id' => $ps->id, 'hours' => 2, 'price' => 30000]);
        $device = Device::create(['nama' => 'PS-MID-T01', 'playstation_id' => $ps->id, 'status' => 'Tersedia']);
        $device->playstations()->attach($ps->id);

        /** @var BillingService $billingService */
        $billingService = app(BillingService::class);

        $expiredTimer = $billingService->startSession([
            'device_id' => $device->id,
            'playstation_id' => $ps->id,
            'nama' => 'Tamu Timer T01',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 2,
        ], $kasir->id);

        // Tepat pada expires_at (01:00:00), simulasi device masih berstatus Digunakan
        Carbon::setTestNow(Carbon::parse('2026-11-22 01:00:00'));
        $this->assertSame('Digunakan', $device->fresh()->status);

        $response = $this->actingAs($kasir)->post(route('transaction.store'), [
            'nama' => 'Tamu Baru T01',
            'device_id' => $device->id,
            'playstation_id' => $ps->id,
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
            'harga' => 15000,
            'total' => 15000,
            'action' => 'simpan',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $expiredTimer->refresh();
        $this->assertSame(Transaction::BILLING_EXPIRED_PENDING_ACTION, $expiredTimer->billing_status);

        $runningSessions = Transaction::where('device_id', $device->id)
            ->where('billing_status', Transaction::BILLING_RUNNING)
            ->get();

        $this->assertCount(1, $runningSessions, 'Exactly one RUNNING session may exist on the station.');
        $newSession = $runningSessions->first();
        $this->assertNotSame($expiredTimer->id_transaksi, $newSession->id_transaksi);
        $this->assertSame('2026-11-22 01:00:00', $newSession->started_at->toDateTimeString());
        $this->assertSame('2026-11-22 02:00:00', $newSession->expires_at->toDateTimeString());
        $this->assertSame('Digunakan', $device->fresh()->status);
    }

    /**
     * SR-BILLING-MIDNIGHT-002 (TEST-02):
     * Transaksi RUNNING lintas tengah malam dengan devices.status stale='Tersedia'
     * harus menolak double booking (postpaid, prepaid simpan/bayar, custom_package)
     * dan mempertahankan sesi asli secara utuh.
     */
    public function test_02_prevent_double_booking_when_device_status_is_stale_tersedia(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-21 23:00:00'));

        $kasir = User::factory()->create(['role' => 'kasir']);
        $ps = Playstation::create(['nama' => 'PS5 Test-02', 'harga' => 15000]);
        \App\Models\HourlyPrice::create(['playstation_id' => $ps->id, 'hours' => 1, 'price' => 15000]);
        \App\Models\HourlyPrice::create(['playstation_id' => $ps->id, 'hours' => 3, 'price' => 45000]);
        $device = Device::create(['nama' => 'PS-MID-T02', 'playstation_id' => $ps->id, 'status' => 'Tersedia']);
        $device->playstations()->attach($ps->id);

        $customPkg = \App\Models\CustomPackage::create([
            'nama_paket' => 'Paket Begadang T02',
            'harga_total' => 25000,
            'is_active' => true,
        ]);
        $customPkg->playstations()->attach($ps->id, ['lama_main' => 120]);

        /** @var BillingService $billingService */
        $billingService = app(BillingService::class);
        $originalTx = $billingService->startSession([
            'device_id' => $device->id,
            'playstation_id' => $ps->id,
            'nama' => 'Pemain Asli T02',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 3,
        ], $kasir->id);

        // Lewati tengah malam dan simulasi stale device status = Tersedia
        Carbon::setTestNow(Carbon::parse('2026-11-22 00:15:00'));
        DB::table('devices')->where('id', $device->id)->update(['status' => 'Tersedia']);

        $attempts = [
            ['nama' => 'Penyerobot Postpaid', 'device_id' => $device->id, 'playstation_id' => $ps->id, 'tipe_transaksi' => 'postpaid'],
            ['nama' => 'Penyerobot Prepaid Simpan', 'device_id' => $device->id, 'playstation_id' => $ps->id, 'tipe_transaksi' => 'prepaid', 'jam_main' => 1, 'harga' => 15000, 'total' => 15000, 'action' => 'simpan'],
            ['nama' => 'Penyerobot Prepaid Bayar', 'device_id' => $device->id, 'playstation_id' => $ps->id, 'tipe_transaksi' => 'prepaid', 'jam_main' => 1, 'harga' => 15000, 'total' => 15000, 'action' => 'bayar'],
            ['nama' => 'Penyerobot Paket', 'device_id' => $device->id, 'custom_package_id' => $customPkg->id, 'tipe_transaksi' => 'custom_package', 'total' => 25000, 'action' => 'simpan'],
        ];

        foreach ($attempts as $payload) {
            $this->actingAs($kasir)
                ->postJson(route('transaction.store'), $payload)
                ->assertStatus(422);
        }

        $this->assertSame(1, Transaction::where('device_id', $device->id)->count());

        $snapshot = $originalTx->fresh();
        $this->assertSame($originalTx->id_transaksi, $snapshot->id_transaksi);
        $this->assertSame(Transaction::BILLING_RUNNING, $snapshot->billing_status);
        $this->assertSame($originalTx->status_transaksi, $snapshot->status_transaksi);
        $this->assertSame('2026-11-21 23:00:00', $snapshot->started_at->toDateTimeString());
        $this->assertSame('2026-11-22 02:00:00', $snapshot->expires_at->toDateTimeString());
        $this->assertEquals($originalTx->total, $snapshot->total);
    }

    /**
     * SR-BILLING-MIDNIGHT-002 (TEST-03):
     * Cross-midnight recovery Lost Time mulai 23:30 lalu pada 00:30 BillingService
     * diinisialisasi ulang dan menjalankan reconcileSessions().
     */
    public function test_03_cross_midnight_recovery_2330_to_0030_after_billing_reinitialization(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-21 23:30:00'));

        $kasir = User::factory()->create(['role' => 'kasir']);
        $ps = Playstation::create(['nama' => 'PS5 Test-03', 'harga' => 10000]);
        $device = Device::create(['nama' => 'PS-MID-T03', 'playstation_id' => $ps->id, 'status' => 'Tersedia']);
        $device->playstations()->attach($ps->id);

        $billingService = app(BillingService::class);
        $lostTx = $billingService->startSession([
            'device_id' => $device->id,
            'playstation_id' => $ps->id,
            'nama' => 'Lost Time 2330',
            'tipe_transaksi' => 'postpaid',
        ], $kasir->id);

        $originalId = $lostTx->id_transaksi;

        // Maju ke 00:30 dan simulasikan stale status + re-init container instance
        Carbon::setTestNow(Carbon::parse('2026-11-22 00:30:00'));
        DB::table('devices')->where('id', $device->id)->update(['status' => 'Tersedia']);

        app()->forgetInstance(BillingService::class);
        /** @var BillingService $reinitializedBilling */
        $reinitializedBilling = app(BillingService::class);
        $reconcileResult = $reinitializedBilling->reconcileSessions();

        $this->assertGreaterThanOrEqual(1, $reconcileResult['devices_healed']);

        $freshTx = Transaction::findOrFail($originalId);
        $this->assertSame($originalId, $freshTx->id_transaksi);
        $this->assertSame('23:30:00', $freshTx->waktu_mulai);
        $this->assertSame('2026-11-21 23:30:00', $freshTx->started_at->toDateTimeString());
        $this->assertSame(Transaction::BILLING_RUNNING, $freshTx->billing_status);
        $this->assertSame('berjalan', $freshTx->status_transaksi);
        $this->assertSame(3600, $freshTx->getEffectiveStartTime()->diffInSeconds(now()));
        $this->assertSame('Digunakan', $device->fresh()->status);

        $timersResponse = $this->actingAs($kasir)->getJson(route('device.timers'))->assertStatus(200);
        $deviceEntry = collect($timersResponse->json('devices'))->firstWhere('id', $device->id);
        $this->assertNotNull($deviceEntry);
        $this->assertSame('Digunakan', $deviceEntry['status']);
        $this->assertSame($originalId, $deviceEntry['transaction']['id_transaksi']);
        $this->assertSame(3600, $deviceEntry['transaction']['elapsed_seconds']);
    }

    /**
     * SR-BILLING-MIDNIGHT-002 (TEST-04):
     * Timer 23:00–02:00 tetap RUNNING pada 00:00 dan 01:59, lalu expire tepat sekali pada 02:00.
     */
    public function test_04_timer_2300_to_0200_stays_running_until_0159_and_expires_once_at_0200(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-21 23:00:00'));

        $kasir = User::factory()->create(['role' => 'kasir']);
        $ps = Playstation::create(['nama' => 'PS5 Test-04', 'harga' => 12000]);
        \App\Models\HourlyPrice::create(['playstation_id' => $ps->id, 'hours' => 3, 'price' => 36000]);
        $device = Device::create(['nama' => 'PS-MID-T04', 'playstation_id' => $ps->id, 'status' => 'Tersedia']);
        $device->playstations()->attach($ps->id);

        /** @var BillingService $billingService */
        $billingService = app(BillingService::class);
        $timerTx = $billingService->startSession([
            'device_id' => $device->id,
            'playstation_id' => $ps->id,
            'nama' => 'Timer 3 Jam T04',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 3,
        ], $kasir->id);

        // 00:00:00 — tetap RUNNING
        Carbon::setTestNow(Carbon::parse('2026-11-22 00:00:00'));
        $midnightEval = $billingService->evaluateExpiration();
        $this->assertSame(0, $midnightEval['count']);
        $this->assertSame(Transaction::BILLING_RUNNING, $timerTx->fresh()->billing_status);
        $this->assertSame('Digunakan', $device->fresh()->status);

        // 01:59:59 — masih tetap RUNNING
        Carbon::setTestNow(Carbon::parse('2026-11-22 01:59:59'));
        $beforeExpiryEval = $billingService->evaluateExpiration();
        $this->assertSame(0, $beforeExpiryEval['count']);
        $this->assertSame(Transaction::BILLING_RUNNING, $timerTx->fresh()->billing_status);
        $this->assertSame('Digunakan', $device->fresh()->status);

        // 02:00:00 — transisi selesai tepat satu kali
        Carbon::setTestNow(Carbon::parse('2026-11-22 02:00:00'));
        $atExpiryEval = $billingService->evaluateExpiration();
        $this->assertSame(1, $atExpiryEval['count']);

        $expiredSnapshot = $timerTx->fresh();
        $this->assertSame(Transaction::BILLING_EXPIRED_PENDING_ACTION, $expiredSnapshot->billing_status);
        $this->assertSame('2026-11-22 02:00:00', $expiredSnapshot->ended_at->toDateTimeString());
        $this->assertSame('Tersedia', $device->fresh()->status);

        // Eksekusi ulang tidak boleh memproses ulang atau mengubah ended_at
        Carbon::setTestNow(Carbon::parse('2026-11-22 02:01:00'));
        $secondEval = $billingService->evaluateExpiration();
        $this->assertSame(0, $secondEval['count']);
        $this->assertSame('2026-11-22 02:00:00', $timerTx->fresh()->ended_at->toDateTimeString());
    }

    /**
     * SR-BILLING-MIDNIGHT-002 (TEST-05):
     * Idempotency hardware automation mock: pergantian hari tidak memicu OFF palsu,
     * dan expiration sah pada 02:00 tidak memicu OFF intent/log duplikat.
     */
    public function test_05_hardware_automation_idempotency_across_midnight_and_expiration(): void
    {
        \Illuminate\Support\Facades\Config::set('hardware.driver', 'mock');
        \Illuminate\Support\Facades\Config::set('hardware.simulation_enabled', true);
        \Illuminate\Support\Facades\Config::set('hardware.real_execution_enabled', false);

        Carbon::setTestNow(Carbon::parse('2026-11-21 23:00:00'));

        $admin = User::factory()->create(['role' => 'admin', 'email' => 'admin_t05@test.local']);
        $kasir = User::factory()->create(['role' => 'kasir', 'email' => 'kasir_t05@test.local']);
        $ps = Playstation::create(['nama' => 'PS5 Test-05', 'harga' => 15000]);
        \App\Models\HourlyPrice::create(['playstation_id' => $ps->id, 'hours' => 3, 'price' => 45000]);

        $timerStation = Device::create(['nama' => 'PS-MID-T05-TIMER', 'playstation_id' => $ps->id, 'status' => 'Tersedia']);
        $timerStation->playstations()->attach($ps->id);

        $lostStation = Device::create(['nama' => 'PS-MID-T05-LOST', 'playstation_id' => $ps->id, 'status' => 'Tersedia']);
        $lostStation->playstations()->attach($ps->id);

        $shelly1 = \App\Models\PhysicalDevice::create([
            'mac_address' => 'AA:BB:CC:DD:05:01',
            'name' => 'Shelly Timer T05',
            'category' => \App\Models\PhysicalDevice::CATEGORY_SMART_RELAY,
            'brand' => \App\Models\PhysicalDevice::BRAND_SHELLY,
            'model' => 'Shelly 1PM',
            'ip_address' => '192.168.1.251',
            'is_active' => true,
            'connection_status' => 'ONLINE',
            'verified_capabilities' => ['SWITCH_ON', 'SWITCH_OFF', 'GET_STATUS'],
        ]);
        $shelly2 = \App\Models\PhysicalDevice::create([
            'mac_address' => 'AA:BB:CC:DD:05:02',
            'name' => 'Shelly Lost T05',
            'category' => \App\Models\PhysicalDevice::CATEGORY_SMART_RELAY,
            'brand' => \App\Models\PhysicalDevice::BRAND_SHELLY,
            'model' => 'Shelly 1PM',
            'ip_address' => '192.168.1.252',
            'is_active' => true,
            'connection_status' => 'ONLINE',
            'verified_capabilities' => ['SWITCH_ON', 'SWITCH_OFF', 'GET_STATUS'],
        ]);

        \App\Models\StationDeviceMapping::create([
            'station_id' => $timerStation->id,
            'physical_device_id' => $shelly1->id,
            'mapping_role' => \App\Models\StationDeviceMapping::ROLE_LIGHT_RELAY,
            'mapping_status' => \App\Models\StationDeviceMapping::STATUS_ACTIVE,
            'created_by' => $admin->id,
        ]);
        \App\Models\StationDeviceMapping::create([
            'station_id' => $lostStation->id,
            'physical_device_id' => $shelly2->id,
            'mapping_role' => \App\Models\StationDeviceMapping::ROLE_LIGHT_RELAY,
            'mapping_status' => \App\Models\StationDeviceMapping::STATUS_ACTIVE,
            'created_by' => $admin->id,
        ]);

        app(\App\Services\Hardware\Simulation\HardwareSimulationManager::class)->resetAll();
        /** @var BillingService $billingService */
        $billingService = app(BillingService::class);

        $timerTx = $billingService->startSession([
            'device_id' => $timerStation->id,
            'playstation_id' => $ps->id,
            'nama' => 'Timer Hardware T05',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 3,
        ], $kasir->id);

        $lostTx = $billingService->startSession([
            'device_id' => $lostStation->id,
            'playstation_id' => $ps->id,
            'nama' => 'Lost Hardware T05',
            'tipe_transaksi' => 'postpaid',
        ], $kasir->id);

        $this->artisan('billing:daemon', ['--once' => true])->assertExitCode(0);
        $this->assertSame('SIMULATED_ON', $timerStation->fresh()->last_hardware_state);
        $this->assertSame('SIMULATED_ON', $lostStation->fresh()->last_hardware_state);

        // Lewati tengah malam (00:00 dan 00:30): ulangi reconciliation, expiration, dan daemon
        foreach (['2026-11-22 00:00:00', '2026-11-22 00:30:00'] as $midnightCheckpoint) {
            Carbon::setTestNow(Carbon::parse($midnightCheckpoint));
            $billingService->reconcileSessions();
            $billingService->evaluateExpiration();
            $this->artisan('billing:daemon', ['--once' => true])->assertExitCode(0);
            $billingService->reconcileSessions();
            $this->artisan('billing:daemon', ['--once' => true])->assertExitCode(0);
        }

        // Pergantian tanggal tidak boleh memicu perintah OFF palsu
        $this->assertSame('SIMULATED_ON', $timerStation->fresh()->last_hardware_state);
        $this->assertSame('SIMULATED_ON', $lostStation->fresh()->last_hardware_state);
        $this->assertSame(0, \App\Models\AutomationIntent::where('transaction_id', $lostTx->id_transaksi)
            ->where('desired_state', \App\Models\AutomationIntent::STATE_OFF)
            ->count());

        $pendingTimerOff = \App\Models\AutomationIntent::where('transaction_id', $timerTx->id_transaksi)
            ->where('desired_state', \App\Models\AutomationIntent::STATE_OFF)
            ->get();
        $this->assertCount(1, $pendingTimerOff);
        $this->assertSame(\App\Models\AutomationIntent::STATUS_PENDING, $pendingTimerOff->first()->status);

        // 02:00:00 — expiration sah dijalankan berulang, hanya boleh ada 1 OFF intent & 1 execution log
        Carbon::setTestNow(Carbon::parse('2026-11-22 02:00:00'));
        for ($i = 0; $i < 3; $i++) {
            $billingService->reconcileSessions();
            $billingService->evaluateExpiration();
            $this->artisan('billing:daemon', ['--once' => true])->assertExitCode(0);
        }

        $offIntents = \App\Models\AutomationIntent::where('transaction_id', $timerTx->id_transaksi)
            ->where('desired_state', \App\Models\AutomationIntent::STATE_OFF)
            ->get();
        $this->assertCount(1, $offIntents, 'Legitimate expiration must not duplicate OFF intents.');
        $this->assertSame(\App\Models\AutomationIntent::STATUS_COMPLETED, $offIntents->first()->status);

        $offLogs = \App\Models\AutomationExecutionLog::where('intent_id', $offIntents->first()->id)->get();
        $this->assertCount(1, $offLogs, 'Hardware OFF must execute exactly once.');
        $this->assertSame(\App\Models\AutomationExecutionLog::STATUS_SUCCESS, $offLogs->first()->execution_status);
        $this->assertSame('SIMULATED_OFF', $timerStation->fresh()->last_hardware_state);
        $this->assertSame('SIMULATED_ON', $lostStation->fresh()->last_hardware_state);
    }

    /**
     * SR-BILLING-MIDNIGHT-002 (TEST-06):
     * Legacy diagnostic & sync endpoints (debugDevice, updateStatus, getTimerSyncData)
     * tidak mengubah sesi RUNNING lintas tengah malam dan tetap membaca sesi meski devices.status stale.
     */
    public function test_06_legacy_endpoints_preserve_cross_midnight_running_session_state(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-21 23:20:00'));

        $admin = User::factory()->create(['role' => 'admin', 'email' => 'admin_t05@test.local']);
        $kasir = User::factory()->create(['role' => 'kasir', 'email' => 'kasir_t05@test.local']);
        $ps = Playstation::create(['nama' => 'PS5 Test-06', 'harga' => 15000]);
        $device = Device::create(['nama' => 'PS-MID-T06', 'playstation_id' => $ps->id, 'status' => 'Tersedia']);
        $device->playstations()->attach($ps->id);

        /** @var BillingService $billingService */
        $billingService = app(BillingService::class);
        $tx = $billingService->startSession([
            'device_id' => $device->id,
            'playstation_id' => $ps->id,
            'nama' => 'Sesi Legacy Guard T06',
            'tipe_transaksi' => 'postpaid',
        ], $kasir->id);

        Carbon::setTestNow(Carbon::parse('2026-11-22 00:20:00'));
        DB::table('devices')->where('id', $device->id)->update(['status' => 'Tersedia']);

        $this->actingAs($admin)
            ->getJson(route('device.debug', $device->id))
            ->assertStatus(200);

        $this->actingAs($kasir)
            ->postJson(route('device.updateStatus', $device->id))
            ->assertStatus(409);

        $syncResp = $this->actingAs($kasir)
            ->getJson(route('device.timers'))
            ->assertStatus(200);

        $syncedDevice = collect($syncResp->json('devices'))->firstWhere('id', $device->id);
        $this->assertNotNull($syncedDevice);
        $this->assertSame('Digunakan', $syncedDevice['status']);
        $this->assertSame($tx->id_transaksi, $syncedDevice['transaction']['id_transaksi']);
        $this->assertSame(3600, $syncedDevice['transaction']['elapsed_seconds']);

        $freshTx = $tx->fresh();
        $this->assertSame(Transaction::BILLING_RUNNING, $freshTx->billing_status);
        $this->assertSame('berjalan', $freshTx->status_transaksi);
        $this->assertSame('2026-11-21 23:20:00', $freshTx->started_at->toDateTimeString());
        $this->assertNull($freshTx->ended_at);
        $this->assertSame('Digunakan', $device->fresh()->status);
    }

    /**
     * SR-BILLING-MIDNIGHT-002 (TEST-07):
     * Setelah pergantian tanggal dan reconciliation: payment_status tidak berubah,
     * biaya Lost Time akurat, FnB yang terhubung tidak hilang, zero duplicate invoice,
     * dan started_at tidak berubah.
     */
    public function test_07_payment_and_fnb_preservation_across_midnight_and_reconciliation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-21 23:00:00'));

        $kasir = User::factory()->create(['role' => 'kasir']);
        $ps = Playstation::create(['nama' => 'PS5 Test-07', 'harga' => 15000]);
        \App\Models\HourlyPrice::create(['playstation_id' => $ps->id, 'hours' => 1, 'price' => 15000]);
        \App\Models\HourlyPrice::create(['playstation_id' => $ps->id, 'hours' => 3, 'price' => 45000]);

        $lostDevice = Device::create(['nama' => 'PS-MID-T07-LT', 'playstation_id' => $ps->id, 'status' => 'Tersedia']);
        $lostDevice->playstations()->attach($ps->id);
        $timerDevice = Device::create(['nama' => 'PS-MID-T07-TM', 'playstation_id' => $ps->id, 'status' => 'Tersedia']);
        $timerDevice->playstations()->attach($ps->id);

        $location = \App\Models\Location::create([
            'name' => 'Bilik T07',
            'code' => 'STATION-MID-T07',
            'type' => 'STATION',
            'device_id' => $lostDevice->id,
            'is_active' => true,
        ]);

        $fnbSnack = \App\Models\Fnb::create([
            'nama' => 'Indomie Telor T07',
            'harga_beli' => 5000,
            'harga_jual' => 8000,
            'stok' => 30,
        ]);
        $fnbDrink = \App\Models\Fnb::create([
            'nama' => 'Es Teh T07',
            'harga_beli' => 2000,
            'harga_jual' => 5000,
            'stok' => 30,
        ]);

        /** @var BillingService $billingService */
        $billingService = app(BillingService::class);
        $lostTx = $billingService->startSession([
            'device_id' => $lostDevice->id,
            'playstation_id' => $ps->id,
            'nama' => 'Tamu FnB Midnight',
            'tipe_transaksi' => 'postpaid',
        ], $kasir->id);

        // Tambah FnB langsung ke transaksi
        $this->actingAs($kasir)->post(route('transaction.fnb.add', $lostTx->id_transaksi), [
            'fnb_id' => $fnbSnack->id,
            'qty' => 2,
        ])->assertStatus(200)->assertJson(['success' => true]);

        // Tambah FnbOrder belum bayar (12.000 via qty 2 Es Teh = 10.000, plus order early paid 5.000)
        $unpaidOrder = \App\Models\FnbOrder::create([
            'order_number' => 'ORD-T07-UNPAID',
            'session_token' => $lostTx->session_token,
            'source' => \App\Models\FnbOrder::SOURCE_QR,
            'location_id' => $location->id,
            'transaction_id' => $lostTx->id_transaksi,
            'customer_name' => $lostTx->nama,
            'billing_name' => $lostTx->nama,
            'status' => \App\Models\FnbOrder::STATUS_ACCEPTED,
            'payment_status' => 'unpaid',
            'requires_upfront_payment' => false,
        ]);
        \App\Models\FnbOrderItem::create([
            'fnb_order_id' => $unpaidOrder->id,
            'fnb_id' => $fnbDrink->id,
            'qty' => 2,
            'unit_price_beli' => 2000,
            'unit_price_jual' => 5000,
            'subtotal' => 10000,
            'payment_status' => 'unpaid',
        ]);

        $earlyPaidOrder = \App\Models\FnbOrder::create([
            'order_number' => 'ORD-T07-PAID',
            'session_token' => $lostTx->session_token,
            'source' => \App\Models\FnbOrder::SOURCE_QR,
            'location_id' => $location->id,
            'transaction_id' => $lostTx->id_transaksi,
            'customer_name' => $lostTx->nama,
            'billing_name' => $lostTx->nama,
            'status' => \App\Models\FnbOrder::STATUS_ACCEPTED,
            'payment_status' => 'unpaid',
            'requires_upfront_payment' => false,
        ]);
        \App\Models\FnbOrderItem::create([
            'fnb_order_id' => $earlyPaidOrder->id,
            'fnb_id' => $fnbDrink->id,
            'qty' => 1,
            'unit_price_beli' => 2000,
            'unit_price_jual' => 5000,
            'subtotal' => 5000,
            'payment_status' => 'unpaid',
        ]);

        /** @var \App\Services\Billing\LostTimeBillingService $lostService */
        $lostService = app(\App\Services\Billing\LostTimeBillingService::class);
        $lostService->settleFnbOrder($earlyPaidOrder->id, [
            'payment_method' => 'tunai',
            'amount_paid' => 5000,
            'cashier_user_id' => $kasir->id,
        ]);

        // Sesi kedua: Prepaid Timer yang sudah dibayar dimuka pada 23:00
        $paidTimerTx = $billingService->startSession([
            'device_id' => $timerDevice->id,
            'playstation_id' => $ps->id,
            'nama' => 'Timer Paid T07',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 3,
        ], $kasir->id);
        app(\App\Services\Billing\PaketBillingService::class)->activatePaidPaket($paidTimerTx->id_transaksi, [
            'payment_method' => 'tunai',
            'amount_paid' => 45000,
            'discount' => 0,
            'cashier_user_id' => $kasir->id,
        ]);

        // Lewati tengah malam dan jalankan reconciliation berulang
        Carbon::setTestNow(Carbon::parse('2026-11-22 01:30:00'));
        $billingService->reconcileSessions();
        $billingService->reconcileSessions();
        $this->actingAs($kasir)->get(route('transaction.index'))->assertStatus(200);

        $lostSnapshot = $lostTx->fresh(['transactionFnbs', 'fnbOrders.items']);
        $paidTimerSnapshot = $paidTimerTx->fresh();

        $this->assertSame('unpaid', $lostSnapshot->payment_status);
        $this->assertSame('2026-11-21 23:00:00', $lostSnapshot->started_at->toDateTimeString());
        $this->assertCount(1, $lostSnapshot->transactionFnbs);
        $this->assertSame(2, $lostSnapshot->transactionFnbs->first()->qty);
        $this->assertSame('unpaid', $unpaidOrder->fresh()->payment_status);
        $this->assertSame('paid', $earlyPaidOrder->fresh()->payment_status);
        $this->assertSame(1, \App\Models\Invoice::where('transaction_id', $lostTx->id_transaksi)->count());

        $this->assertSame('paid', $paidTimerSnapshot->payment_status);
        $this->assertSame(Transaction::BILLING_RUNNING, $paidTimerSnapshot->billing_status);
        $this->assertSame('2026-11-21 23:00:00', $paidTimerSnapshot->started_at->toDateTimeString());
        $this->assertSame(1, \App\Models\Invoice::where('transaction_id', $paidTimerTx->id_transaksi)->count());

        // End Lost Time pada 01:30 (2 jam 30 menit @ 15.000/jam = 37.500 + 16.000 direct FnB + 10.000 unpaid FnB = 63.500)
        $this->actingAs($kasir)->post(route('transaction.end', $lostTx->id_transaksi));

        $endedLost = $lostTx->fresh();
        $this->assertSame('selesai', $endedLost->status_transaksi);
        $this->assertEquals(63500, $endedLost->total);

        $this->actingAs($kasir)->post(route('transaction.processPayment', $lostTx->id_transaksi), [
            'payment_method' => 'tunai',
            'amount_paid' => 70000,
            'discount' => 0,
        ])->assertRedirect();

        $this->assertSame('paid', $lostTx->fresh()->payment_status);
        $this->assertSame(
            1,
            \App\Models\Invoice::where('transaction_id', $lostTx->id_transaksi)
                ->where('invoice_type', '!=', \App\Models\Invoice::TYPE_FNB_STANDALONE)
                ->count()
        );
        $this->assertSame(2, \App\Models\Invoice::where('transaction_id', $lostTx->id_transaksi)->count());
    }
}
