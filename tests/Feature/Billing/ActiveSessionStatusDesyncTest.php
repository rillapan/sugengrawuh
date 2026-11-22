<?php

namespace Tests\Feature\Billing;

use App\Models\AutomationIntent;
use App\Models\CustomPackage;
use App\Models\Device;
use App\Models\Fnb;
use App\Models\FnbOrder;
use App\Models\HourlyPrice;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\PhysicalDevice;
use App\Models\Playstation;
use App\Models\StationDeviceMapping;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BillingService;
use App\Services\Hardware\Simulation\HardwareSimulationManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ActiveSessionStatusDesyncTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $kasir;
    protected Playstation $playstation;
    protected Device $device;
    protected Location $location;
    protected Fnb $fnbKopi;
    protected BillingService $billingService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('kasir_ps_test', DB::connection()->getDatabaseName(), 'Tests must run on kasir_ps_test.');

        Config::set('hardware.driver', 'mock');
        Config::set('hardware.real_execution_enabled', false);
        Config::set('hardware.simulation_enabled', true);
        Config::set('hardware.automation_enabled', true);
        Config::set('hardware.automation.enabled', true);
        Config::set('hardware.allowed_environments', ['local', 'testing']);
        Config::set('hardware.allowed_databases', ['kasir_ps_test']);

        Carbon::setTestNow(Carbon::parse('2026-11-22 10:00:00', config('app.timezone', 'Asia/Jakarta')));

        $this->admin = User::factory()->create([
            'name' => 'Admin Desync',
            'email' => 'admin.desync@sugengrawuh.test',
            'role' => 'admin',
        ]);

        $this->kasir = User::factory()->create([
            'name' => 'Kasir Desync',
            'email' => 'kasir.desync@sugengrawuh.test',
            'role' => 'kasir',
            'is_fnb_staff' => false,
        ]);

        $this->playstation = Playstation::create([
            'nama' => 'PS5 Desync',
            'harga' => 15000,
        ]);
        HourlyPrice::create([
            'playstation_id' => $this->playstation->id,
            'hours' => 1,
            'price' => 15000,
        ]);
        HourlyPrice::create([
            'playstation_id' => $this->playstation->id,
            'hours' => 2,
            'price' => 30000,
        ]);

        $this->device = $this->createStation('Station Desync 01');
        $this->location = Location::where('device_id', $this->device->id)->firstOrFail();

        $this->fnbKopi = Fnb::create([
            'nama' => 'Kopi Susu',
            'harga_beli' => 4000,
            'harga_jual' => 8000,
            'stok' => 50,
        ]);

        $this->billingService = app(BillingService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function createStation(string $name, string $status = 'Tersedia'): Device
    {
        $device = Device::create([
            'nama' => $name,
            'playstation_id' => $this->playstation->id,
            'status' => $status,
            'session_generation' => 0,
        ]);
        $device->playstations()->attach($this->playstation->id);

        Location::create([
            'name' => 'Bilik ' . $name,
            'code' => 'STATION-DS-' . $device->id,
            'type' => 'station',
            'device_id' => $device->id,
            'is_active' => true,
        ]);

        return $device;
    }

    protected function extractStationCardHtml(string $html, int $deviceId): string
    {
        $pattern = '/<article\b[^>]*id="station-card-' . $deviceId . '"[^>]*>.*?<\/article>/s';
        $this->assertSame(1, preg_match($pattern, $html, $matches), "Station card #station-card-{$deviceId} not found.");

        return $matches[0];
    }

    protected function extractPosBootstrap(string $html): array
    {
        $pattern = '/<script[^>]*id="pos-bootstrap-data"[^>]*>(.*?)<\/script>/s';
        $this->assertSame(1, preg_match($pattern, $html, $matches), 'POS bootstrap JSON script not found.');

        $decoded = json_decode($matches[1], true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /** @test */
    public function test_ds_01_lost_time_running_with_stale_tersedia_device_displays_full_active_card(): void
    {
        $this->actingAs($this->kasir);

        $tx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'nama' => 'DS-01 Lost Time',
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        // Simulate daytime stale device status drift
        Carbon::setTestNow(Carbon::parse('2026-11-22 13:15:00', config('app.timezone', 'Asia/Jakarta')));
        DB::table('devices')->where('id', $this->device->id)->update(['status' => 'Tersedia']);

        $response = $this->get(route('transaction.index', ['tab' => 'stations']))->assertStatus(200);
        $card = $this->extractStationCardHtml($response->getContent(), $this->device->id);

        $this->assertMatchesRegularExpression('/class="[^"]*\bis-used\b[^"]*"/', $card);
        $this->assertStringContainsString('BERMAIN', $card);
        $this->assertStringContainsString('data-tx-id="' . $tx->id_transaksi . '"', $card);
        $this->assertStringContainsString('data-active-tx-id="' . $tx->id_transaksi . '"', $card);
        $this->assertStringContainsString('Detail', $card);
        $this->assertStringContainsString('Bayar', $card);
        $this->assertStringContainsString('Edit', $card);
        $this->assertStringContainsString('Cafe', $card);
        $this->assertStringContainsString('Berhenti', $card);
        $this->assertStringContainsString('Selesai', $card);

        $bootstrap = $this->extractPosBootstrap($response->getContent());
        $this->assertSame($tx->id_transaksi, $bootstrap['activeTransactions'][(string) $this->device->id]['id_transaksi']);
        $devBootstrap = collect($bootstrap['devices'])->firstWhere('id', $this->device->id);
        $this->assertSame('Digunakan', $devBootstrap['status']);
    }

    /** @test */
    public function test_ds_02_timer_and_custom_package_running_with_stale_tersedia_display_timer_and_buttons(): void
    {
        $this->actingAs($this->kasir);

        $customDevice = $this->createStation('Station Desync Custom');
        $customPkg = CustomPackage::create([
            'nama_paket' => 'Paket Siang 2 Jam',
            'harga_total' => 25000,
            'is_active' => true,
        ]);
        $customPkg->playstations()->attach($this->playstation->id, ['lama_main' => 120]);

        $timerTx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'nama' => 'DS-02 Timer',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 2,
        ], $this->kasir->id);

        $customTx = $this->billingService->startSession([
            'device_id' => $customDevice->id,
            'playstation_id' => $this->playstation->id,
            'nama' => 'DS-02 Custom',
            'tipe_transaksi' => 'custom_package',
            'custom_package_id' => $customPkg->id,
        ], $this->kasir->id);

        Carbon::setTestNow(Carbon::parse('2026-11-22 10:45:00', config('app.timezone', 'Asia/Jakarta')));
        DB::table('devices')->whereIn('id', [$this->device->id, $customDevice->id])->update(['status' => 'Tersedia']);

        $html = $this->get(route('transaction.index', ['tab' => 'stations']))->assertStatus(200)->getContent();

        $timerCard = $this->extractStationCardHtml($html, $this->device->id);
        $this->assertMatchesRegularExpression('/class="[^"]*\bis-used\b[^"]*"/', $timerCard);
        $this->assertStringContainsString('data-tx-id="' . $timerTx->id_transaksi . '"', $timerCard);
        $this->assertStringContainsString('data-expires-at="' . $timerTx->expires_at->toIso8601String() . '"', $timerCard);
        $this->assertStringContainsString('Berhenti', $timerCard);
        $this->assertStringContainsString('Bayar', $timerCard);

        $customCard = $this->extractStationCardHtml($html, $customDevice->id);
        $this->assertMatchesRegularExpression('/class="[^"]*\bis-used\b[^"]*"/', $customCard);
        $this->assertStringContainsString('data-tx-id="' . $customTx->id_transaksi . '"', $customCard);
        $this->assertStringContainsString('data-expires-at="' . $customTx->expires_at->toIso8601String() . '"', $customCard);
        $this->assertStringContainsString('Paket Siang 2 Jam', $customCard);
    }

    /** @test */
    public function test_ds_03_api_device_timers_resolves_running_session_when_device_is_stale_tersedia(): void
    {
        $this->actingAs($this->kasir);

        $tx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'nama' => 'DS-03 Timer Sync',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 2,
        ], $this->kasir->id);

        Carbon::setTestNow(Carbon::parse('2026-11-22 10:30:00', config('app.timezone', 'Asia/Jakarta')));
        DB::table('devices')->where('id', $this->device->id)->update(['status' => 'Tersedia']);

        $json = $this->getJson(route('device.timers'))->assertStatus(200)->json();
        $entry = collect($json['devices'])->firstWhere('id', $this->device->id);

        $this->assertNotNull($entry);
        $this->assertSame('Digunakan', $entry['status']);
        $this->assertSame('Tersedia', $entry['stored_device_status']);
        $this->assertTrue($entry['has_status_desync']);
        $this->assertNotNull($entry['transaction']);
        $this->assertSame($tx->id_transaksi, $entry['transaction']['id_transaksi']);
        $this->assertSame(Transaction::BILLING_RUNNING, $entry['transaction']['billing_status']);
        $this->assertSame(1800, $entry['transaction']['elapsed_seconds']);
        $this->assertSame(5400, $entry['transaction']['remaining_seconds']);
        $this->assertFalse($entry['transaction']['is_expired']);
    }

    /** @test */
    public function test_ds_04_paid_historical_or_newer_finished_transaction_never_releases_running_unpaid_device(): void
    {
        $this->actingAs($this->kasir);

        // Older paid finished transaction
        Transaction::create([
            'status' => 'user',
            'nama' => 'Old Paid Customer',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'prepaid',
            'harga' => 15000,
            'jam_main' => 1,
            'waktu_mulai' => '08:00',
            'waktu_Selesai' => '09:00',
            'started_at' => Carbon::parse('2026-11-22 08:00:00'),
            'expires_at' => Carbon::parse('2026-11-22 09:00:00'),
            'ended_at' => Carbon::parse('2026-11-22 09:00:00'),
            'status_transaksi' => 'selesai',
            'billing_status' => Transaction::BILLING_COMPLETED,
            'payment_status' => 'paid',
            'total' => 15000,
        ]);

        // Active RUNNING unpaid session
        $runningUnpaid = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'nama' => 'Current Running Unpaid',
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        // Even if a standalone finished/paid row exists with a higher ID on the same device
        Transaction::create([
            'status' => 'user',
            'nama' => 'Higher ID Paid Completed',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'prepaid',
            'harga' => 15000,
            'jam_main' => 1,
            'waktu_mulai' => '09:00',
            'waktu_Selesai' => '09:30',
            'started_at' => Carbon::parse('2026-11-22 09:00:00'),
            'expires_at' => Carbon::parse('2026-11-22 10:00:00'),
            'ended_at' => Carbon::parse('2026-11-22 09:30:00'),
            'status_transaksi' => 'selesai',
            'billing_status' => Transaction::BILLING_COMPLETED,
            'payment_status' => 'paid',
            'total' => 15000,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-11-22 12:30:00', config('app.timezone', 'Asia/Jakarta')));

        $this->postJson(route('device.updateAllStatuses'))->assertStatus(200);
        $this->postJson(route('device.updateStatus', $this->device->id))->assertStatus(409);
        $this->billingService->reconcileSessions(Carbon::now());

        $this->assertSame('Digunakan', $this->device->fresh()->status);
        $this->assertSame(Transaction::BILLING_RUNNING, $runningUnpaid->fresh()->billing_status);
        $this->assertSame('unpaid', $runningUnpaid->fresh()->payment_status);

        $active = $this->billingService->resolveActiveSessionForDevice($this->device->id, Carbon::now());
        $this->assertNotNull($active);
        $this->assertSame($runningUnpaid->id_transaksi, $active->id_transaksi);
    }

    /** @test */
    public function test_ds_05_get_device_and_transaction_pages_do_not_mutate_running_sessions_or_device_status(): void
    {
        $this->actingAs($this->admin);

        $tx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'nama' => 'DS-05 ReadOnly Check',
            'tipe_transaksi' => 'postpaid',
        ], $this->admin->id);

        // Simulate stale Tersedia status before GET visits
        DB::table('devices')->where('id', $this->device->id)->update(['status' => 'Tersedia']);

        Carbon::setTestNow(Carbon::parse('2026-11-22 14:00:00', config('app.timezone', 'Asia/Jakarta')));

        $devResponse = $this->get(route('device.index'))->assertStatus(200);
        $this->assertArrayHasKey($this->device->id, $devResponse->viewData('postpaidTransactions'));
        $this->assertSame(1, $devResponse->viewData('countInUse'));

        $txResponse = $this->get(route('transaction.index'))->assertStatus(200);
        $card = $this->extractStationCardHtml($txResponse->getContent(), $this->device->id);
        $this->assertMatchesRegularExpression('/class="[^"]*\bis-used\b[^"]*"/', $card);

        // GET pages must NOT perform mass DB mutations or alter running session timestamps
        $this->assertSame('Tersedia', $this->device->fresh()->status);
        $freshTx = $tx->fresh();
        $this->assertSame(Transaction::BILLING_RUNNING, $freshTx->billing_status);
        $this->assertNull($freshTx->ended_at);
        $this->assertSame('2026-11-22 10:00:00', $freshTx->started_at->toDateTimeString());
    }

    /** @test */
    public function test_ds_06_auto_status_update_triggers_never_overwrite_running_and_heal_stale_tersedia(): void
    {
        $this->actingAs($this->kasir);

        $tx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'nama' => 'DS-06 Auto Status Trigger',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 3,
        ], $this->kasir->id);

        DB::table('devices')->where('id', $this->device->id)->update(['status' => 'Tersedia']);
        Carbon::setTestNow(Carbon::parse('2026-11-22 11:00:00', config('app.timezone', 'Asia/Jakarta')));

        $this->postJson(route('device.updateAllStatuses'))
            ->assertStatus(200)
            ->assertJson(['success' => true, 'updated_count' => 1]);

        $this->assertSame('Digunakan', $this->device->fresh()->status);
        $this->assertSame(Transaction::BILLING_RUNNING, $tx->fresh()->billing_status);

        // Legacy single-device status release must reject with 409
        $this->postJson(route('device.updateStatus', $this->device->id))
            ->assertStatus(409)
            ->assertJson(['success' => false, 'transaction_id' => $tx->id_transaksi]);

        $this->assertSame('Digunakan', $this->device->fresh()->status);
        $this->assertSame(Transaction::BILLING_RUNNING, $tx->fresh()->billing_status);
    }

    /** @test */
    public function test_ds_07_repeated_reconcile_sessions_calls_are_idempotent(): void
    {
        $tx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'nama' => 'DS-07 Idempotent Reconcile',
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        $staleOccupied = $this->createStation('Station Stale Digunakan', 'Digunakan');
        DB::table('devices')->where('id', $this->device->id)->update(['status' => 'Tersedia']);

        Carbon::setTestNow(Carbon::parse('2026-11-22 12:00:00', config('app.timezone', 'Asia/Jakarta')));

        $first = $this->billingService->reconcileSessions(Carbon::now());
        $this->assertSame(2, $first['devices_healed']);
        $this->assertSame(1, $first['postpaid_survived']);
        $this->assertSame('Digunakan', $this->device->fresh()->status);
        $this->assertSame('Tersedia', $staleOccupied->fresh()->status);

        $second = $this->billingService->reconcileSessions(Carbon::now());
        $this->assertSame(0, $second['devices_healed']);
        $this->assertSame(0, $second['expired_processed']);
        $this->assertSame(1, $second['postpaid_survived']);

        $third = $this->billingService->reconcileSessions(Carbon::now());
        $this->assertSame(0, $third['devices_healed']);

        $freshTx = $tx->fresh();
        $this->assertSame('unpaid', $freshTx->payment_status);
        $this->assertSame('2026-11-22 10:00:00', $freshTx->started_at->toDateTimeString());
        $this->assertSame(Transaction::BILLING_RUNNING, $freshTx->billing_status);
    }

    /** @test */
    public function test_ds_08_repeated_polling_never_drops_running_session_and_stays_consistent(): void
    {
        $this->actingAs($this->kasir);

        $tx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'nama' => 'DS-08 Poll Loop Safety',
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        // Even with stale DB device status, repeated /device/timers and /transaction polls agree
        DB::table('devices')->where('id', $this->device->id)->update(['status' => 'Tersedia']);

        for ($i = 0; $i < 5; $i++) {
            Carbon::setTestNow(Carbon::parse('2026-11-22 10:05:00')->addSeconds($i * 5));

            $timers = $this->getJson(route('device.timers'))->assertStatus(200)->json();
            $dev = collect($timers['devices'])->firstWhere('id', $this->device->id);

            $this->assertSame('Digunakan', $dev['status']);
            $this->assertSame($tx->id_transaksi, $dev['transaction']['id_transaksi']);
            $this->assertSame(Transaction::BILLING_RUNNING, $dev['transaction']['billing_status']);

            $dashHtml = $this->get(route('transaction.index', ['tab' => 'stations']))->assertStatus(200)->getContent();
            $card = $this->extractStationCardHtml($dashHtml, $this->device->id);
            $this->assertMatchesRegularExpression('/class="[^"]*\bis-used\b[^"]*"/', $card);
            $this->assertStringContainsString('data-tx-id="' . $tx->id_transaksi . '"', $card);
        }
    }

    /** @test */
    public function test_ds_09_paying_old_outstanding_bill_does_not_disrupt_new_running_session(): void
    {
        $this->actingAs($this->kasir);

        $oldTx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'nama' => 'DS-09 Old Unpaid',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
        ], $this->kasir->id);

        Carbon::setTestNow(Carbon::parse('2026-11-22 11:05:00', config('app.timezone', 'Asia/Jakarta')));
        $this->billingService->evaluateExpiration(Carbon::now());
        $this->assertSame(Transaction::BILLING_EXPIRED_PENDING_ACTION, $oldTx->fresh()->billing_status);
        $this->assertSame('Tersedia', $this->device->fresh()->status);

        $newTx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'nama' => 'DS-09 New Running',
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        Carbon::setTestNow(Carbon::parse('2026-11-22 11:20:00', config('app.timezone', 'Asia/Jakarta')));

        $this->post(route('transaction.processPayment', $oldTx->id_transaksi), [
            'payment_method' => 'tunai',
            'amount_paid' => 15000,
            'discount' => 0,
        ])->assertRedirect();

        $this->assertSame('paid', $oldTx->fresh()->payment_status);
        $this->assertSame(1, Invoice::where('transaction_id', $oldTx->id_transaksi)->count());
        $this->assertSame(Transaction::BILLING_RUNNING, $newTx->fresh()->billing_status);
        $this->assertSame('unpaid', $newTx->fresh()->payment_status);
        $this->assertSame('Digunakan', $this->device->fresh()->status);
    }

    /** @test */
    public function test_ds_10_legitimate_timer_expiration_releases_device_to_tersedia(): void
    {
        $this->actingAs($this->kasir);

        $tx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'nama' => 'DS-10 Expiring Timer',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
        ], $this->kasir->id);

        Carbon::setTestNow(Carbon::parse('2026-11-22 11:00:00', config('app.timezone', 'Asia/Jakarta')));
        $eval = $this->billingService->evaluateExpiration(Carbon::now());

        $this->assertSame(1, $eval['count']);
        $this->assertSame(Transaction::BILLING_EXPIRED_PENDING_ACTION, $tx->fresh()->billing_status);
        $this->assertSame('selesai', $tx->fresh()->status_transaksi);
        $this->assertSame('Tersedia', $this->device->fresh()->status);

        $timers = $this->getJson(route('device.timers'))->assertStatus(200)->json();
        $dev = collect($timers['devices'])->firstWhere('id', $this->device->id);
        $this->assertSame('Tersedia', $dev['status']);
        $this->assertNull($dev['transaction']);
    }

    /** @test */
    public function test_ds_11_lost_time_active_for_many_daytime_hours_never_auto_stops(): void
    {
        $this->actingAs($this->kasir);

        $tx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'nama' => 'DS-11 Marathon Lost Time',
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        foreach ([2, 4, 6, 8] as $hoursLater) {
            Carbon::setTestNow(Carbon::parse('2026-11-22 10:00:00')->addHours($hoursLater));

            $this->billingService->evaluateExpiration(Carbon::now());
            $this->billingService->reconcileSessions(Carbon::now());
            $this->postJson(route('device.updateAllStatuses'))->assertStatus(200);
            $timers = $this->getJson(route('device.timers'))->assertStatus(200)->json();

            $dev = collect($timers['devices'])->firstWhere('id', $this->device->id);
            $this->assertSame('Digunakan', $dev['status']);
            $this->assertSame($tx->id_transaksi, $dev['transaction']['id_transaksi']);
            $this->assertSame($hoursLater * 3600, $dev['transaction']['elapsed_seconds']);
            $this->assertSame(Transaction::BILLING_RUNNING, $tx->fresh()->billing_status);
            $this->assertNull($tx->fresh()->ended_at);
        }
    }

    /** @test */
    public function test_ds_12_paying_while_timer_session_is_running_does_not_end_session(): void
    {
        $this->actingAs($this->kasir);

        $tx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'nama' => 'DS-12 Pay Mid Session',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 2,
        ], $this->kasir->id);

        Carbon::setTestNow(Carbon::parse('2026-11-22 10:30:00', config('app.timezone', 'Asia/Jakarta')));

        $this->post(route('transaction.processPayment', $tx->id_transaksi), [
            'payment_method' => 'tunai',
            'amount_paid' => 30000,
            'discount' => 0,
        ])->assertRedirect();

        $fresh = $tx->fresh();
        $this->assertSame('paid', $fresh->payment_status);
        $this->assertSame(Transaction::BILLING_RUNNING, $fresh->billing_status);
        $this->assertNull($fresh->ended_at);
        $this->assertSame('Digunakan', $this->device->fresh()->status);

        $dash = $this->get(route('transaction.index', ['tab' => 'stations']))->assertStatus(200);
        $card = $this->extractStationCardHtml($dash->getContent(), $this->device->id);
        $this->assertMatchesRegularExpression('/class="[^"]*\bis-used\b[^"]*"/', $card);
        $this->assertStringContainsString('LUNAS', $card);
        $this->assertStringContainsString('data-tx-id="' . $tx->id_transaksi . '"', $card);
    }

    /** @test */
    public function test_ds_13_adding_fnb_while_session_running_preserves_running_billing_status(): void
    {
        $this->actingAs($this->kasir);

        $tx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'nama' => 'DS-13 Active With FnB',
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        $this->postJson(route('transaction.fnb.add', $tx->id_transaksi), [
            'fnb_id' => $this->fnbKopi->id,
            'qty' => 2,
        ])->assertStatus(200)->assertJson(['success' => true]);

        $this->postJson(route('fnb.orders.store'), [
            'transaction_id' => $tx->id_transaksi,
            'action' => 'save',
            'items' => [
                ['fnb_id' => $this->fnbKopi->id, 'qty' => 1],
            ],
        ])->assertStatus(201)->assertJson(['success' => true]);

        $fresh = $tx->fresh();
        $this->assertSame(Transaction::BILLING_RUNNING, $fresh->billing_status);
        $this->assertSame('berjalan', $fresh->status_transaksi);
        $this->assertSame('Digunakan', $this->device->fresh()->status);

        $summary = $this->billingService->summarizeTransactionBilling($fresh);
        $this->assertSame(24000.0, $summary['fnb_unpaid_total']);

        $dash = $this->get(route('transaction.index', ['tab' => 'stations']))->assertStatus(200);
        $card = $this->extractStationCardHtml($dash->getContent(), $this->device->id);
        $this->assertStringContainsString('24.000', $card);
    }

    /** @test */
    public function test_ds_14_competing_start_requests_never_double_book_and_heal_stale_status(): void
    {
        $this->actingAs($this->kasir);

        // First request starts legitimately
        $firstResp = $this->postJson(route('transaction.store'), [
            'nama' => 'DS-14 Player 1',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'postpaid',
        ]);
        $firstResp->assertStatus(201)->assertJson(['success' => true]);

        // Even if devices.status drifted to Tersedia right before second concurrent request arrives
        DB::table('devices')->where('id', $this->device->id)->update(['status' => 'Tersedia']);

        $secondResp = $this->postJson(route('transaction.store'), [
            'nama' => 'DS-14 Player 2',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'postpaid',
        ]);
        $secondResp->assertStatus(422)->assertJson(['success' => false]);

        $this->assertSame(
            1,
            Transaction::where('device_id', $this->device->id)
                ->where('billing_status', Transaction::BILLING_RUNNING)
                ->count()
        );
        $this->assertSame('Digunakan', $this->device->fresh()->status);
    }

    /** @test */
    public function test_ds_15_expiration_and_new_start_competing_at_exact_expires_at_resolve_cleanly(): void
    {
        $this->actingAs($this->kasir);

        $firstTx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'nama' => 'DS-15 First Session',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
        ], $this->kasir->id);

        // Advance to exact expires_at (11:00:00) without prior daemon run
        Carbon::setTestNow(Carbon::parse('2026-11-22 11:00:00', config('app.timezone', 'Asia/Jakarta')));

        $storeResp = $this->postJson(route('transaction.store'), [
            'nama' => 'DS-15 Next Session',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'postpaid',
        ]);
        $storeResp->assertStatus(201)->assertJson(['success' => true]);

        $this->assertSame(Transaction::BILLING_EXPIRED_PENDING_ACTION, $firstTx->fresh()->billing_status);
        $running = Transaction::where('device_id', $this->device->id)
            ->where('billing_status', Transaction::BILLING_RUNNING)
            ->get();
        $this->assertCount(1, $running);
        $this->assertSame($storeResp->json('transaction_id'), $running->first()->id_transaksi);
        $this->assertSame('Digunakan', $this->device->fresh()->status);
    }

    /** @test */
    public function test_ds_16_offline_hardware_does_not_terminate_active_billing_session(): void
    {
        $this->actingAs($this->kasir);

        $physicalDevice = PhysicalDevice::create([
            'name' => 'Offline Relay DS-16',
            'category' => PhysicalDevice::CATEGORY_SMART_RELAY,
            'brand' => PhysicalDevice::BRAND_SHELLY,
            'model' => 'Shelly 1PM Mini Gen3',
            'mac_address' => 'AA:BB:CC:DD:EE:66',
            'ip_address' => '192.168.1.166',
            'is_active' => true,
            'connection_status' => 'OFFLINE',
            'verified_capabilities' => ['SWITCH_ON', 'SWITCH_OFF', 'GET_STATUS'],
        ]);

        StationDeviceMapping::create([
            'station_id' => $this->device->id,
            'physical_device_id' => $physicalDevice->id,
            'mapping_role' => StationDeviceMapping::ROLE_LIGHT_RELAY,
            'mapping_status' => StationDeviceMapping::STATUS_ACTIVE,
            'attached_by_user_id' => $this->admin->id,
            'attached_at' => now(),
        ]);

        app(HardwareSimulationManager::class)->resetAll();

        $tx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'nama' => 'DS-16 Offline Hardware',
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        Carbon::setTestNow(Carbon::parse('2026-11-22 11:30:00', config('app.timezone', 'Asia/Jakarta')));

        $this->billingService->reconcileSessions(Carbon::now());
        $this->billingService->evaluateExpiration(Carbon::now());

        $this->assertSame(Transaction::BILLING_RUNNING, $tx->fresh()->billing_status);
        $this->assertSame('Digunakan', $this->device->fresh()->status);

        $dash = $this->get(route('transaction.index', ['tab' => 'stations']))->assertStatus(200);
        $card = $this->extractStationCardHtml($dash->getContent(), $this->device->id);
        $this->assertMatchesRegularExpression('/class="[^"]*\bis-used\b[^"]*"/', $card);
        $this->assertStringContainsString('Hardware Offline', $card);
        $this->assertStringContainsString('data-tx-id="' . $tx->id_transaksi . '"', $card);
    }

    /** @test */
    public function test_ds_17_repeated_dashboard_refreshes_keep_transaction_id_and_timer_invariant(): void
    {
        $this->actingAs($this->kasir);

        $tx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'nama' => 'DS-17 Refresh Invariant',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 2,
        ], $this->kasir->id);

        $expectedStartIso = $tx->started_at->toIso8601String();
        $expectedExpIso = $tx->expires_at->toIso8601String();

        for ($r = 0; $r < 4; $r++) {
            Carbon::setTestNow(Carbon::parse('2026-11-22 10:10:00')->addMinutes($r * 10));
            $html = $this->get(route('transaction.index', ['tab' => 'stations']))->assertStatus(200)->getContent();
            $card = $this->extractStationCardHtml($html, $this->device->id);

            $this->assertStringContainsString('data-tx-id="' . $tx->id_transaksi . '"', $card);
            $this->assertStringContainsString('data-started-at="' . $expectedStartIso . '"', $card);
            $this->assertStringContainsString('data-expires-at="' . $expectedExpIso . '"', $card);

            $bootstrap = $this->extractPosBootstrap($html);
            $activeEntry = $bootstrap['activeTransactions'][(string) $this->device->id] ?? null;
            $this->assertNotNull($activeEntry);
            $this->assertSame($tx->id_transaksi, $activeEntry['id_transaksi']);
            $this->assertSame($expectedStartIso, $activeEntry['started_at']);
            $this->assertSame($expectedExpIso, $activeEntry['expires_at']);
        }
    }

    /** @test */
    public function test_ds_18_cross_midnight_session_with_stale_device_remains_active_and_consistent(): void
    {
        $this->actingAs($this->kasir);

        Carbon::setTestNow(Carbon::parse('2026-11-22 23:15:00', config('app.timezone', 'Asia/Jakarta')));

        $tx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'nama' => 'DS-18 Midnight Desync',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 3,
        ], $this->kasir->id);

        Carbon::setTestNow(Carbon::parse('2026-11-23 00:45:00', config('app.timezone', 'Asia/Jakarta')));
        DB::table('devices')->where('id', $this->device->id)->update(['status' => 'Tersedia']);

        $html = $this->get(route('transaction.index', ['tab' => 'stations']))->assertStatus(200)->getContent();
        $card = $this->extractStationCardHtml($html, $this->device->id);
        $this->assertMatchesRegularExpression('/class="[^"]*\bis-used\b[^"]*"/', $card);
        $this->assertStringContainsString('data-tx-id="' . $tx->id_transaksi . '"', $card);

        $timers = $this->getJson(route('device.timers'))->assertStatus(200)->json();
        $dev = collect($timers['devices'])->firstWhere('id', $this->device->id);
        $this->assertSame('Digunakan', $dev['status']);
        $this->assertSame($tx->id_transaksi, $dev['transaction']['id_transaksi']);
        $this->assertSame(5400, $dev['transaction']['elapsed_seconds']);
        $this->assertSame(5400, $dev['transaction']['remaining_seconds']);
    }

    /** @test */
    public function test_ds_19_multiple_active_stations_and_unpaid_deletion_never_cause_mass_reset(): void
    {
        $this->actingAs($this->admin);

        $station2 = $this->createStation('Station Desync 02');
        $station3 = $this->createStation('Station Desync 03');

        // Old unpaid draft/completed bill on Station 1 that admin later deletes
        $deletableUnpaid = Transaction::create([
            'status' => 'user',
            'nama' => 'Deletable Unpaid',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'prepaid',
            'harga' => 15000,
            'jam_main' => 1,
            'waktu_mulai' => '08:00',
            'waktu_Selesai' => '09:00',
            'status_transaksi' => 'draft',
            'billing_status' => Transaction::BILLING_IDLE,
            'payment_status' => 'unpaid',
            'total' => 15000,
        ]);

        $tx1 = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'nama' => 'Active Station 1',
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        $tx2 = $this->billingService->startSession([
            'device_id' => $station2->id,
            'playstation_id' => $this->playstation->id,
            'nama' => 'Active Station 2',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
        ], $this->kasir->id);

        $tx3 = $this->billingService->startSession([
            'device_id' => $station3->id,
            'playstation_id' => $this->playstation->id,
            'nama' => 'Active Station 3',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 3,
        ], $this->kasir->id);

        // Delete old draft on Station 1 -> Station 1 MUST remain Digunakan because $tx1 is RUNNING!
        $this->delete(route('transaction.destroy', $deletableUnpaid->id_transaksi))->assertRedirect();
        $this->assertSame('Digunakan', $this->device->fresh()->status);
        $this->assertSame(Transaction::BILLING_RUNNING, $tx1->fresh()->billing_status);

        // Expire Station 2 -> Station 1 & Station 3 MUST remain Digunakan
        Carbon::setTestNow(Carbon::parse('2026-11-22 11:05:00', config('app.timezone', 'Asia/Jakarta')));
        $this->billingService->evaluateExpiration(Carbon::now());

        $this->assertSame('Digunakan', $this->device->fresh()->status);
        $this->assertSame('Tersedia', $station2->fresh()->status);
        $this->assertSame('Digunakan', $station3->fresh()->status);
        $this->assertSame(Transaction::BILLING_RUNNING, $tx1->fresh()->billing_status);
        $this->assertSame(Transaction::BILLING_EXPIRED_PENDING_ACTION, $tx2->fresh()->billing_status);
        $this->assertSame(Transaction::BILLING_RUNNING, $tx3->fresh()->billing_status);
    }

    /** @test */
    public function test_ds_20_status_desync_recovery_emits_structured_warning_audit_log(): void
    {
        $tx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'nama' => 'DS-20 Audit Log',
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        DB::table('devices')->where('id', $this->device->id)->update(['status' => 'Tersedia']);

        Log::spy();

        $report = $this->billingService->reconcileSessions(Carbon::now());
        $this->assertSame(1, $report['devices_healed']);
        $this->assertSame('Digunakan', $this->device->fresh()->status);

        Log::shouldHaveReceived('warning')
            ->withArgs(function ($message, $context = []) use ($tx) {
                if (!str_contains((string) $message, 'STATUS_DESYNC')) {
                    return false;
                }

                return isset(
                    $context['timestamp'],
                    $context['device_id'],
                    $context['old_status'],
                    $context['new_status'],
                    $context['active_transaction_id'],
                    $context['billing_status'],
                    $context['source_method'],
                    $context['reason']
                )
                    && $context['device_id'] === $this->device->id
                    && $context['old_status'] === 'Tersedia'
                    && $context['new_status'] === 'Digunakan'
                    && $context['active_transaction_id'] === $tx->id_transaksi
                    && $context['billing_status'] === Transaction::BILLING_RUNNING
                    && $context['source_method'] === 'BillingService::reconcileSessions';
            })
            ->atLeast()
            ->once();
    }
}
