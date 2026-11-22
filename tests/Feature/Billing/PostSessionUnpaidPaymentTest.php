<?php

namespace Tests\Feature\Billing;

use App\Models\AutomationExecutionLog;
use App\Models\AutomationIntent;
use App\Models\CustomPackage;
use App\Models\Device;
use App\Models\Fnb;
use App\Models\FnbOrder;
use App\Models\FnbOrderItem;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\PhysicalDevice;
use App\Models\Playstation;
use App\Models\StationDeviceMapping;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Billing\PaketBillingService;
use App\Services\BillingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostSessionUnpaidPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $kasir;
    protected Playstation $playstation;
    protected Device $device;
    protected Location $location;
    protected Fnb $fnbKopi;
    protected Fnb $fnbSnack;
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
            'name' => 'Admin PostSession',
            'role' => 'admin',
            'email' => 'admin.e2e.postsess@sugengrawuh.test',
        ]);

        $this->kasir = User::factory()->create([
            'name' => 'Kasir PostSession',
            'role' => 'kasir',
            'email' => 'kasir.e2e.postsess@sugengrawuh.test',
            'is_fnb_staff' => false,
        ]);

        $this->playstation = Playstation::create([
            'nama' => 'PS5 PostSession E2E',
            'harga' => 10000,
        ]);

        $this->device = $this->createStationWithHardware('PS5 Station 1', 'STATION-PS-01', 'AA:BB:CC:DD:EE:21');
        $this->location = Location::where('device_id', $this->device->id)->firstOrFail();

        $this->fnbKopi = Fnb::create([
            'nama' => 'Kopi Hitam',
            'harga_beli' => 3000,
            'harga_jual' => 5000,
            'stok' => 50,
        ]);

        $this->fnbSnack = Fnb::create([
            'nama' => 'Kentang Goreng',
            'harga_beli' => 6000,
            'harga_jual' => 12000,
            'stok' => 50,
        ]);

        $this->billingService = app(BillingService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function createStationWithHardware(string $name, string $locationCode, string $macAddress): Device
    {
        $device = Device::create([
            'nama' => $name,
            'playstation_id' => $this->playstation->id,
            'status' => 'Tersedia',
            'session_generation' => 0,
        ]);
        $device->playstations()->attach($this->playstation->id);

        Location::create([
            'name' => 'Bilik ' . $name,
            'code' => $locationCode,
            'type' => 'station',
            'device_id' => $device->id,
            'is_active' => true,
        ]);

        $physicalDevice = PhysicalDevice::create([
            'name' => 'Relay ' . $name,
            'category' => PhysicalDevice::CATEGORY_SMART_RELAY,
            'brand' => PhysicalDevice::BRAND_SHELLY,
            'model' => 'Shelly 1PM Mini Gen3',
            'mac_address' => $macAddress,
            'ip_address' => '192.168.1.' . (150 + $device->id),
            'is_active' => true,
            'connection_status' => 'ONLINE',
            'verified_capabilities' => ['SWITCH_ON', 'SWITCH_OFF', 'GET_STATUS'],
        ]);

        StationDeviceMapping::create([
            'station_id' => $device->id,
            'physical_device_id' => $physicalDevice->id,
            'mapping_role' => StationDeviceMapping::ROLE_LIGHT_RELAY,
            'mapping_status' => StationDeviceMapping::STATUS_ACTIVE,
            'attached_by_user_id' => $this->admin->id,
            'attached_at' => now(),
        ]);

        return $device;
    }

    protected function createCustomPackage(string $name = 'Paket Hemat 2 Jam', int $minutes = 120, float $price = 18000): CustomPackage
    {
        $pkg = CustomPackage::create([
            'nama_paket' => $name,
            'harga_total' => $price,
            'is_active' => true,
        ]);
        $pkg->playstations()->attach($this->playstation->id, ['lama_main' => $minutes]);
        $pkg->fnbs()->attach($this->fnbKopi->id, ['quantity' => 1]);

        return $pkg;
    }

    protected function extractStationCardHtml(string $html, int $deviceId): string
    {
        $pattern = '/<article\b[^>]*id="station-card-' . $deviceId . '"[^>]*>.*?<\/article>/s';
        $this->assertSame(1, preg_match($pattern, $html, $matches), "Station card #station-card-{$deviceId} not found.");

        return $matches[0];
    }

    protected function extractCardDataAttribute(string $cardHtml, string $attr): ?string
    {
        $pattern = '/\b' . preg_quote($attr, '/') . '="([^"]*)"/';
        if (preg_match($pattern, $cardHtml, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    protected function extractPosBootstrap(string $html): array
    {
        $pattern = '/<script[^>]*id="pos-bootstrap-data"[^>]*>(.*?)<\/script>/s';
        $this->assertSame(1, preg_match($pattern, $html, $matches), 'Expected #pos-bootstrap-data JSON block.');

        $decoded = json_decode($matches[1], true);
        $this->assertIsArray($decoded, 'Expected valid JSON inside #pos-bootstrap-data.');

        return $decoded;
    }

    protected function normalizeIdCsv(string|array $raw): string
    {
        $items = is_array($raw)
            ? $raw
            : (trim($raw) === '' ? [] : explode(',', $raw));

        $ids = [];
        foreach ($items as $item) {
            $trimmed = trim((string) $item);
            if ($trimmed !== '') {
                $ids[] = (int) $trimmed;
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        return implode(',', $ids);
    }

    protected function assertOutstandingBillPresentInCard(string $cardHtml, int $txId, string $customerName): void
    {
        $paymentUrl = route('transaction.showPayment', $txId);

        $this->assertStringContainsString('class="pos-unpaid-box"', $cardHtml);
        $this->assertStringContainsString('Tagihan Belum Lunas', $cardHtml);
        $this->assertStringContainsString('data-outstanding-tx-id="' . $txId . '"', $cardHtml);
        $this->assertStringContainsString('#' . $txId, $cardHtml);
        $this->assertStringContainsString($customerName, $cardHtml);
        $this->assertMatchesRegularExpression(
            '/<a\b[^>]*href="' . preg_quote($paymentUrl, '/') . '"[^>]*class="[^"]*\bjs-pay-outstanding\b[^"]*"[^>]*data-outstanding-tx-id="' . $txId . '"[^>]*>\s*Bayar\s*<\/a>/s',
            $cardHtml
        );
    }

    protected function assertNoOutstandingBillsInCard(string $cardHtml): void
    {
        $this->assertSame('', $this->extractCardDataAttribute($cardHtml, 'data-unpaid-tx-ids'));
        $this->assertStringNotContainsString('Tagihan Belum Lunas', $cardHtml);
        $this->assertStringNotContainsString('pos-unpaid-box', $cardHtml);
        $this->assertStringNotContainsString('js-pay-outstanding', $cardHtml);
    }

    /** @test */
    public function test_01_timer_running_unpaid_shows_bayar_button_on_active_station_card(): void
    {
        $this->actingAs($this->kasir);

        $storeResp = $this->post(route('transaction.store'), [
            'nama' => 'Pelanggan Timer Aktif',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'duration_seconds' => 3600,
            'harga' => 10000,
            'total' => 10000,
            'action' => 'simpan',
        ]);
        $storeResp->assertRedirect(route('transaction.index', ['tab' => 'stations']));

        $tx = Transaction::where('nama', 'Pelanggan Timer Aktif')->firstOrFail();
        $this->assertSame(Transaction::BILLING_RUNNING, $tx->billing_status);
        $this->assertSame('unpaid', $tx->payment_status);
        $this->assertSame('Digunakan', $this->device->fresh()->status);

        $dashResp = $this->get(route('transaction.index', ['tab' => 'stations']))->assertStatus(200);
        $cardHtml = $this->extractStationCardHtml($dashResp->getContent(), $this->device->id);

        $this->assertMatchesRegularExpression('/class="[^"]*\bis-used\b[^"]*"/', $cardHtml);
        $this->assertStringContainsString('data-tx-id="' . $tx->id_transaksi . '"', $cardHtml);
        $this->assertStringContainsString('BELUM BAYAR', $cardHtml);
        $this->assertNoOutstandingBillsInCard($cardHtml);

        $paymentUrl = route('transaction.showPayment', $tx->id_transaksi);
        $this->assertMatchesRegularExpression(
            '/<a\b[^>]*href="' . preg_quote($paymentUrl, '/') . '"[^>]*>.*?<span>Bayar<\/span>.*?<\/a>/s',
            $cardHtml
        );

        $this->get($paymentUrl)
            ->assertStatus(200)
            ->assertSee('Pelanggan Timer Aktif')
            ->assertSee('10.000');
    }

    /** @test */
    public function test_02_expired_unpaid_timer_transitions_station_dispatches_off_and_remains_payable(): void
    {
        $this->actingAs($this->kasir);

        $this->post(route('transaction.store'), [
            'nama' => 'Pelanggan Timer Habis',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'duration_seconds' => 3600,
            'harga' => 10000,
            'total' => 10000,
            'action' => 'simpan',
        ])->assertRedirect(route('transaction.index', ['tab' => 'stations']));

        $tx = Transaction::where('nama', 'Pelanggan Timer Habis')->firstOrFail();

        $this->assertDatabaseHas('automation_intents', [
            'transaction_id' => $tx->id_transaksi,
            'event_type' => AutomationIntent::EVENT_BILLING_STARTED,
            'desired_state' => AutomationIntent::STATE_ON,
            'status' => AutomationIntent::STATUS_COMPLETED,
        ]);

        // Advance past expiration (10:00 -> 11:05)
        Carbon::setTestNow(Carbon::parse('2026-11-22 11:05:00', config('app.timezone', 'Asia/Jakarta')));

        $evalResult = $this->billingService->evaluateExpiration(Carbon::now());
        $this->assertSame(1, $evalResult['count']);

        $tx->refresh();
        $this->assertSame('selesai', $tx->status_transaksi);
        $this->assertSame(Transaction::BILLING_EXPIRED_PENDING_ACTION, $tx->billing_status);
        $this->assertSame('unpaid', $tx->payment_status);
        $this->assertSame('Tersedia', $this->device->fresh()->status);

        $offIntent = AutomationIntent::where('transaction_id', $tx->id_transaksi)
            ->where('event_type', AutomationIntent::EVENT_BILLING_EXPIRED)
            ->where('desired_state', AutomationIntent::STATE_OFF)
            ->first();
        $this->assertNotNull($offIntent);

        // Loading dashboard dispatches eligible OFF intent and displays unpaid bill on Tersedia card
        $dashResp = $this->get(route('transaction.index', ['tab' => 'stations']))->assertStatus(200);

        $offIntent->refresh();
        $this->assertSame(AutomationIntent::STATUS_COMPLETED, $offIntent->status);
        $this->assertSame('SIMULATED_OFF', $this->device->fresh()->last_hardware_state);
        $this->assertDatabaseHas('automation_execution_logs', [
            'intent_id' => $offIntent->id,
            'transaction_id' => $tx->id_transaksi,
            'command_sent' => 'SWITCH_OFF',
            'execution_status' => AutomationExecutionLog::STATUS_SUCCESS,
        ]);

        $cardHtml = $this->extractStationCardHtml($dashResp->getContent(), $this->device->id);
        $this->assertMatchesRegularExpression('/class="[^"]*\bis-available\b[^"]*"/', $cardHtml);
        $this->assertStringContainsString('TERSEDIA', $cardHtml);
        $this->assertSame((string) $tx->id_transaksi, $this->extractCardDataAttribute($cardHtml, 'data-unpaid-tx-ids'));
        $this->assertOutstandingBillPresentInCard($cardHtml, $tx->id_transaksi, 'Pelanggan Timer Habis');

        $bootstrap = $this->extractPosBootstrap($dashResp->getContent());
        $this->assertArrayHasKey((string) $this->device->id, $bootstrap['outstandingUnpaidTransactions']);
        $this->assertSame($tx->id_transaksi, $bootstrap['outstandingUnpaidTransactions'][(string) $this->device->id][0]['id_transaksi']);

        // Cashier completes payment via showPayment + processPayment
        $this->get(route('transaction.showPayment', $tx->id_transaksi))
            ->assertStatus(200)
            ->assertSee('Pelanggan Timer Habis')
            ->assertSee('10.000');

        $payResp = $this->post(route('transaction.processPayment', $tx->id_transaksi), [
            'payment_method' => 'tunai',
            'amount_paid' => 10000,
            'discount' => 0,
        ]);
        $payResp->assertRedirect(route('transaction.print', ['id' => $tx->id_transaksi, 'action' => 'payment']));

        $tx->refresh();
        $this->assertSame('paid', $tx->payment_status);
        $this->assertSame('selesai', $tx->status_transaksi);
        $this->assertSame(Transaction::BILLING_EXPIRED_PENDING_ACTION, $tx->billing_status);
        $this->assertSame(1, Invoice::where('transaction_id', $tx->id_transaksi)->count());

        // Unpaid bill disappears from card after payment
        $afterDash = $this->get(route('transaction.index', ['tab' => 'stations']))->assertStatus(200);
        $afterCardHtml = $this->extractStationCardHtml($afterDash->getContent(), $this->device->id);
        $this->assertNoOutstandingBillsInCard($afterCardHtml);
        $afterBootstrap = $this->extractPosBootstrap($afterDash->getContent());
        $this->assertArrayNotHasKey((string) $this->device->id, $afterBootstrap['outstandingUnpaidTransactions']);
    }

    /** @test */
    public function test_03_expired_paid_timer_shows_no_unpaid_bill_on_tersedia_station_card(): void
    {
        $this->actingAs($this->kasir);

        $this->post(route('transaction.store'), [
            'nama' => 'Pelanggan Sudah Bayar',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'duration_seconds' => 3600,
            'harga' => 10000,
            'total' => 10000,
            'action' => 'bayar',
        ])->assertRedirect();

        $tx = Transaction::where('nama', 'Pelanggan Sudah Bayar')->firstOrFail();

        $this->post(route('transaction.processPayment', $tx->id_transaksi), [
            'payment_method' => 'tunai',
            'amount_paid' => 10000,
            'discount' => 0,
        ])->assertRedirect(route('transaction.print', ['id' => $tx->id_transaksi, 'action' => 'payment']));

        $tx->refresh();
        $this->assertSame('paid', $tx->payment_status);
        $this->assertSame(Transaction::BILLING_RUNNING, $tx->billing_status);

        // Advance past expiration
        Carbon::setTestNow(Carbon::parse('2026-11-22 11:05:00', config('app.timezone', 'Asia/Jakarta')));
        $this->billingService->evaluateExpiration(Carbon::now());

        $tx->refresh();
        $this->assertSame(Transaction::BILLING_EXPIRED_PENDING_ACTION, $tx->billing_status);
        $this->assertSame('selesai', $tx->status_transaksi);
        $this->assertSame('paid', $tx->payment_status);
        $this->assertSame('Tersedia', $this->device->fresh()->status);

        $dashResp = $this->get(route('transaction.index', ['tab' => 'stations']))->assertStatus(200);
        $cardHtml = $this->extractStationCardHtml($dashResp->getContent(), $this->device->id);

        $this->assertMatchesRegularExpression('/class="[^"]*\bis-available\b[^"]*"/', $cardHtml);
        $this->assertStringContainsString('TERSEDIA', $cardHtml);
        $this->assertNoOutstandingBillsInCard($cardHtml);

        $bootstrap = $this->extractPosBootstrap($dashResp->getContent());
        $this->assertArrayNotHasKey((string) $this->device->id, $bootstrap['outstandingUnpaidTransactions']);

        $timers = $this->getJson(route('device.timers'))->assertStatus(200)->json();
        $devPayload = collect($timers['devices'])->firstWhere('id', $this->device->id);
        $this->assertSame('Tersedia', $devPayload['status']);
        $this->assertNull($devPayload['transaction']);
        $this->assertSame([], $devPayload['unpaid_transaction_ids']);
    }

    /** @test */
    public function test_04_tersedia_station_with_old_unpaid_timer_custom_package_and_lost_time_settles_cleanly(): void
    {
        $this->actingAs($this->kasir);

        $station1 = $this->device;
        $station2 = $this->createStationWithHardware('PS5 Station 2', 'STATION-PS-02', 'AA:BB:CC:DD:EE:22');
        $station3 = $this->createStationWithHardware('PS5 Station 3', 'STATION-PS-03', 'AA:BB:CC:DD:EE:23');
        $customPackage = $this->createCustomPackage();

        // 1. Prepaid Timer on Station 1
        $this->post(route('transaction.store'), [
            'nama' => 'Old Unpaid Timer',
            'device_id' => $station1->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'duration_seconds' => 3600,
            'harga' => 10000,
            'total' => 10000,
            'action' => 'simpan',
        ])->assertRedirect();

        // 2. Custom Paket on Station 2
        $this->post(route('transaction.store'), [
            'nama' => 'Old Unpaid Paket',
            'device_id' => $station2->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'custom_package',
            'custom_package_id' => $customPackage->id,
            'harga' => $customPackage->harga_total,
            'total' => $customPackage->harga_total,
            'action' => 'simpan',
        ])->assertRedirect();

        // 3. Lost Time on Station 3
        $this->post(route('transaction.store'), [
            'nama' => 'Old Unpaid LostTime',
            'device_id' => $station3->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'postpaid',
        ])->assertRedirect();

        $txTimer = Transaction::where('nama', 'Old Unpaid Timer')->firstOrFail();
        $txCustom = Transaction::where('nama', 'Old Unpaid Paket')->firstOrFail();
        $txLostTime = Transaction::where('nama', 'Old Unpaid LostTime')->firstOrFail();

        // Stop Lost Time after 90 mins (15000) and expire Timer (1h) + Custom Paket (2h)
        Carbon::setTestNow(Carbon::parse('2026-11-22 11:30:00', config('app.timezone', 'Asia/Jakarta')));
        $this->postJson(route('device.stop', $station3->id))
            ->assertStatus(200)
            ->assertJson(['success' => true, 'new_status' => 'Tersedia']);

        Carbon::setTestNow(Carbon::parse('2026-11-22 12:05:00', config('app.timezone', 'Asia/Jakarta')));
        $this->billingService->evaluateExpiration(Carbon::now());

        $this->assertSame('Tersedia', $station1->fresh()->status);
        $this->assertSame('Tersedia', $station2->fresh()->status);
        $this->assertSame('Tersedia', $station3->fresh()->status);

        // Verify all 3 Tersedia cards show Bayar button pointing to original id_transaksi
        $dashResp = $this->get(route('transaction.index', ['tab' => 'stations']))->assertStatus(200);
        $html = $dashResp->getContent();

        $card1 = $this->extractStationCardHtml($html, $station1->id);
        $card2 = $this->extractStationCardHtml($html, $station2->id);
        $card3 = $this->extractStationCardHtml($html, $station3->id);

        $this->assertOutstandingBillPresentInCard($card1, $txTimer->id_transaksi, 'Old Unpaid Timer');
        $this->assertStringContainsString('Timer', $card1);

        $this->assertOutstandingBillPresentInCard($card2, $txCustom->id_transaksi, 'Old Unpaid Paket');
        $this->assertStringContainsString('Custom Paket', $card2);

        $this->assertOutstandingBillPresentInCard($card3, $txLostTime->id_transaksi, 'Old Unpaid LostTime');
        $this->assertStringContainsString('Lost Time', $card3);

        // Settle all 3 via showPayment + processPayment
        foreach ([
            [$txTimer, 10000],
            [$txCustom, 18000],
            [$txLostTime, 15000],
        ] as [$tx, $amount]) {
            $this->get(route('transaction.showPayment', $tx->id_transaksi))->assertStatus(200);
            $this->post(route('transaction.processPayment', $tx->id_transaksi), [
                'payment_method' => 'tunai',
                'amount_paid' => $amount,
                'discount' => 0,
            ])->assertRedirect(route('transaction.print', ['id' => $tx->id_transaksi, 'action' => 'payment']));

            $this->assertSame('paid', $tx->fresh()->payment_status);
            $this->assertSame(1, Invoice::where('transaction_id', $tx->id_transaksi)->count());
        }

        $afterHtml = $this->get(route('transaction.index', ['tab' => 'stations']))->assertStatus(200)->getContent();
        $this->assertNoOutstandingBillsInCard($this->extractStationCardHtml($afterHtml, $station1->id));
        $this->assertNoOutstandingBillsInCard($this->extractStationCardHtml($afterHtml, $station2->id));
        $this->assertNoOutstandingBillsInCard($this->extractStationCardHtml($afterHtml, $station3->id));
    }

    /** @test */
    public function test_05_new_running_session_on_station_keeps_old_unpaid_bill_accessible_with_distinct_id(): void
    {
        $this->actingAs($this->kasir);

        // First session expires unpaid
        $this->post(route('transaction.store'), [
            'nama' => 'Pelanggan Sesi Pertama',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'duration_seconds' => 3600,
            'harga' => 10000,
            'total' => 10000,
            'action' => 'simpan',
        ])->assertRedirect();

        $oldTx = Transaction::where('nama', 'Pelanggan Sesi Pertama')->firstOrFail();

        Carbon::setTestNow(Carbon::parse('2026-11-22 11:05:00', config('app.timezone', 'Asia/Jakarta')));
        $this->billingService->evaluateExpiration(Carbon::now());
        $this->assertSame('Tersedia', $this->device->fresh()->status);

        // Start a NEW RUNNING Lost Time session on the same station
        $this->post(route('transaction.store'), [
            'nama' => 'Pelanggan Sesi Baru',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'postpaid',
        ])->assertRedirect();

        $newTx = Transaction::where('nama', 'Pelanggan Sesi Baru')->firstOrFail();
        $this->assertNotSame($oldTx->id_transaksi, $newTx->id_transaksi);
        $this->assertSame(Transaction::BILLING_RUNNING, $newTx->billing_status);
        $this->assertSame('Digunakan', $this->device->fresh()->status);

        $dashResp = $this->get(route('transaction.index', ['tab' => 'stations']))->assertStatus(200);
        $cardHtml = $this->extractStationCardHtml($dashResp->getContent(), $this->device->id);

        // Active session controls present for newTx
        $this->assertMatchesRegularExpression('/class="[^"]*\bis-used\b[^"]*"/', $cardHtml);
        $this->assertStringContainsString('data-tx-id="' . $newTx->id_transaksi . '"', $cardHtml);
        $this->assertStringContainsString('js-open-detail', $cardHtml);
        $this->assertStringContainsString('js-open-berhenti', $cardHtml);
        $this->assertStringContainsString(route('transaction.showPayment', $newTx->id_transaksi), $cardHtml);

        // Old unpaid bill ALSO rendered in card with distinct id_transaksi and Bayar link
        $this->assertSame((string) $oldTx->id_transaksi, $this->extractCardDataAttribute($cardHtml, 'data-unpaid-tx-ids'));
        $this->assertOutstandingBillPresentInCard($cardHtml, $oldTx->id_transaksi, 'Pelanggan Sesi Pertama');

        $bootstrap = $this->extractPosBootstrap($dashResp->getContent());
        $this->assertSame($newTx->id_transaksi, $bootstrap['activeTransactions'][(string) $this->device->id]['id_transaksi']);
        $this->assertSame($oldTx->id_transaksi, $bootstrap['outstandingUnpaidTransactions'][(string) $this->device->id][0]['id_transaksi']);

        $this->get(route('transaction.showPayment', $oldTx->id_transaksi))
            ->assertStatus(200)
            ->assertSee('Pelanggan Sesi Pertama');
    }

    /** @test */
    public function test_06_paying_old_unpaid_bill_during_running_session_is_isolated_and_preserves_fnb_integrity(): void
    {
        $this->actingAs($this->kasir);

        // Start old timer with 2x Kopi Hitam (10000) + 1h rental (10000)
        $this->post(route('transaction.store'), [
            'nama' => 'Old With FnB',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'duration_seconds' => 3600,
            'harga' => 10000,
            'total' => 20000,
            'action' => 'simpan',
            'fnb_ids' => [$this->fnbKopi->id],
            'fnbs_qty' => [2],
            'fnbs_harga' => [5000],
        ])->assertRedirect();

        $oldTx = Transaction::where('nama', 'Old With FnB')->firstOrFail();

        // Add an accepted unpaid FnbOrder (Kentang Goreng 12000) before expiration
        $qrOrder = FnbOrder::create([
            'order_number' => 'ORD-POSTSESS-06',
            'source' => FnbOrder::SOURCE_QR,
            'location_id' => $this->location->id,
            'transaction_id' => $oldTx->id_transaksi,
            'customer_name' => $oldTx->nama,
            'status' => FnbOrder::STATUS_ACCEPTED,
            'payment_status' => 'unpaid',
        ]);
        FnbOrderItem::create([
            'fnb_order_id' => $qrOrder->id,
            'fnb_id' => $this->fnbSnack->id,
            'qty' => 1,
            'unit_price_beli' => 6000,
            'unit_price_jual' => 12000,
            'subtotal' => 12000,
            'payment_status' => 'unpaid',
        ]);

        // Expire old session and dispatch its OFF intent
        Carbon::setTestNow(Carbon::parse('2026-11-22 11:05:00', config('app.timezone', 'Asia/Jakarta')));
        $this->get(route('transaction.index', ['tab' => 'stations']))->assertStatus(200);

        $oldTx->refresh();
        $this->assertSame(Transaction::BILLING_EXPIRED_PENDING_ACTION, $oldTx->billing_status);
        $this->assertSame('selesai', $oldTx->status_transaksi);
        $this->assertSame('Tersedia', $this->device->fresh()->status);

        // Start a NEW RUNNING Timer session on the SAME station at 11:10:00
        Carbon::setTestNow(Carbon::parse('2026-11-22 11:10:00', config('app.timezone', 'Asia/Jakarta')));
        $this->post(route('transaction.store'), [
            'nama' => 'New Active Timer',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'duration_seconds' => 3600,
            'harga' => 10000,
            'total' => 10000,
            'action' => 'simpan',
        ])->assertRedirect();

        $newTx = Transaction::where('nama', 'New Active Timer')->firstOrFail();
        $this->device->refresh();
        $this->assertSame('Digunakan', $this->device->status);
        $this->assertSame('SIMULATED_ON', $this->device->last_hardware_state);

        // Snapshot pre-payment timestamps, device generation, and automation rows
        Carbon::setTestNow(Carbon::parse('2026-11-22 11:20:00', config('app.timezone', 'Asia/Jakarta')));
        $oldStartedAt = $oldTx->started_at->toDateTimeString();
        $oldExpiresAt = $oldTx->expires_at->toDateTimeString();
        $oldEndedAt = $oldTx->ended_at->toDateTimeString();
        $newStartedAt = $newTx->started_at->toDateTimeString();
        $newExpiresAt = $newTx->expires_at->toDateTimeString();
        $deviceGenBefore = (int) $this->device->session_generation;
        $intentsBefore = AutomationIntent::count();
        $offIntentsBefore = AutomationIntent::where('desired_state', AutomationIntent::STATE_OFF)->count();
        $logsBefore = AutomationExecutionLog::count();

        // Settle oldTx via showPayment + processPayment (10000 rental + 10000 Kopi + 12000 Kentang = 32000)
        $this->get(route('transaction.showPayment', $oldTx->id_transaksi))
            ->assertStatus(200)
            ->assertSee('32.000');

        $this->post(route('transaction.processPayment', $oldTx->id_transaksi), [
            'payment_method' => 'tunai',
            'amount_paid' => 35000,
            'discount' => 0,
        ])->assertRedirect(route('transaction.print', ['id' => $oldTx->id_transaksi, 'action' => 'payment']));

        // 1. Old expired timer was NOT reactivated and preserved timestamps
        $oldTx->refresh();
        $this->assertSame('paid', $oldTx->payment_status);
        $this->assertSame('selesai', $oldTx->status_transaksi);
        $this->assertSame(Transaction::BILLING_EXPIRED_PENDING_ACTION, $oldTx->billing_status);
        $this->assertSame($oldStartedAt, $oldTx->started_at->toDateTimeString());
        $this->assertSame($oldExpiresAt, $oldTx->expires_at->toDateTimeString());
        $this->assertSame($oldEndedAt, $oldTx->ended_at->toDateTimeString());

        // 2. New RUNNING session was NOT altered
        $newTx->refresh();
        $this->assertSame(Transaction::BILLING_RUNNING, $newTx->billing_status);
        $this->assertSame('unpaid', $newTx->payment_status);
        $this->assertSame('sukses', $newTx->status_transaksi);
        $this->assertSame($newStartedAt, $newTx->started_at->toDateTimeString());
        $this->assertSame($newExpiresAt, $newTx->expires_at->toDateTimeString());

        // 3. Device remained Digunakan with hardware still ON and no new OFF intent recorded or dispatched
        $this->device->refresh();
        $this->assertSame('Digunakan', $this->device->status);
        $this->assertSame($deviceGenBefore, (int) $this->device->session_generation);
        $this->assertSame('SIMULATED_ON', $this->device->last_hardware_state);
        $this->assertSame($intentsBefore, AutomationIntent::count());
        $this->assertSame($offIntentsBefore, AutomationIntent::where('desired_state', AutomationIntent::STATE_OFF)->count());
        $this->assertSame($logsBefore, AutomationExecutionLog::count());

        // 4. Combined FnB + rental invoice integrity
        $invoice = Invoice::with('items')->where('transaction_id', $oldTx->id_transaksi)->firstOrFail();
        $this->assertSame(1, Invoice::where('transaction_id', $oldTx->id_transaksi)->count());
        $this->assertSame(Invoice::TYPE_GAME_AND_FNB, $invoice->invoice_type);
        $this->assertSame(32000.0, (float) $invoice->subtotal);
        $this->assertSame(32000.0, (float) $invoice->final_total);
        $this->assertSame(35000.0, (float) $invoice->amount_paid);
        $this->assertSame(3000.0, (float) $invoice->change_amount);
        $this->assertSame(32000.0, (float) $invoice->items->sum('subtotal'));
        $this->assertSame('paid', $qrOrder->fresh()->payment_status);

        // 5. Dashboard keeps newTx running and removes oldTx from unpaid bills
        $afterDash = $this->get(route('transaction.index', ['tab' => 'stations']))->assertStatus(200);
        $afterCard = $this->extractStationCardHtml($afterDash->getContent(), $this->device->id);
        $this->assertMatchesRegularExpression('/class="[^"]*\bis-used\b[^"]*"/', $afterCard);
        $this->assertStringContainsString('data-tx-id="' . $newTx->id_transaksi . '"', $afterCard);
        $this->assertNoOutstandingBillsInCard($afterCard);
    }

    /** @test */
    public function test_07_two_unpaid_bills_on_same_station_remain_accessible_and_paying_one_keeps_the_other(): void
    {
        $this->actingAs($this->kasir);

        // First unpaid bill: expired timer
        $this->post(route('transaction.store'), [
            'nama' => 'Tagihan Pertama',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'duration_seconds' => 3600,
            'harga' => 10000,
            'total' => 10000,
            'action' => 'simpan',
        ])->assertRedirect();
        $tx1 = Transaction::where('nama', 'Tagihan Pertama')->firstOrFail();

        Carbon::setTestNow(Carbon::parse('2026-11-22 11:05:00', config('app.timezone', 'Asia/Jakarta')));
        $this->billingService->evaluateExpiration(Carbon::now());

        // Second unpaid bill: stopped Lost Time (90 mins = 15000)
        $this->post(route('transaction.store'), [
            'nama' => 'Tagihan Kedua',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'postpaid',
        ])->assertRedirect();
        $tx2 = Transaction::where('nama', 'Tagihan Kedua')->firstOrFail();

        Carbon::setTestNow(Carbon::parse('2026-11-22 12:35:00', config('app.timezone', 'Asia/Jakarta')));
        $this->postJson(route('device.stop', $this->device->id))->assertStatus(200);

        $dash1 = $this->get(route('transaction.index', ['tab' => 'stations']))->assertStatus(200);
        $card1 = $this->extractStationCardHtml($dash1->getContent(), $this->device->id);

        $this->assertSame(
            $this->normalizeIdCsv([$tx1->id_transaksi, $tx2->id_transaksi]),
            $this->normalizeIdCsv((string) $this->extractCardDataAttribute($card1, 'data-unpaid-tx-ids'))
        );
        $this->assertOutstandingBillPresentInCard($card1, $tx1->id_transaksi, 'Tagihan Pertama');
        $this->assertOutstandingBillPresentInCard($card1, $tx2->id_transaksi, 'Tagihan Kedua');

        // Pay only the first bill
        $this->post(route('transaction.processPayment', $tx1->id_transaksi), [
            'payment_method' => 'tunai',
            'amount_paid' => 10000,
            'discount' => 0,
        ])->assertRedirect(route('transaction.print', ['id' => $tx1->id_transaksi, 'action' => 'payment']));

        $this->assertSame('paid', $tx1->fresh()->payment_status);
        $this->assertSame('unpaid', $tx2->fresh()->payment_status);

        // Reload dashboard: tx1 removed, tx2 remains accessible
        $dash2 = $this->get(route('transaction.index', ['tab' => 'stations']))->assertStatus(200);
        $card2 = $this->extractStationCardHtml($dash2->getContent(), $this->device->id);

        $this->assertSame((string) $tx2->id_transaksi, $this->extractCardDataAttribute($card2, 'data-unpaid-tx-ids'));
        $this->assertStringNotContainsString(route('transaction.showPayment', $tx1->id_transaksi), $card2);
        $this->assertOutstandingBillPresentInCard($card2, $tx2->id_transaksi, 'Tagihan Kedua');

        // Second bill can still be opened and paid
        $this->get(route('transaction.showPayment', $tx2->id_transaksi))
            ->assertStatus(200)
            ->assertSee('Tagihan Kedua')
            ->assertSee('15.000');

        $this->post(route('transaction.processPayment', $tx2->id_transaksi), [
            'payment_method' => 'tunai',
            'amount_paid' => 15000,
            'discount' => 0,
        ])->assertRedirect(route('transaction.print', ['id' => $tx2->id_transaksi, 'action' => 'payment']));

        $dash3 = $this->get(route('transaction.index', ['tab' => 'stations']))->assertStatus(200);
        $this->assertNoOutstandingBillsInCard($this->extractStationCardHtml($dash3->getContent(), $this->device->id));
    }

    /** @test */
    public function test_08_repeated_payment_calls_do_not_duplicate_invoice_and_redirect_already_paid(): void
    {
        $this->actingAs($this->kasir);

        // 1. Expired unpaid prepaid bill
        $this->post(route('transaction.store'), [
            'nama' => 'Idempotent Prepaid',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'duration_seconds' => 3600,
            'harga' => 10000,
            'total' => 10000,
            'action' => 'simpan',
        ])->assertRedirect();
        $prepaidTx = Transaction::where('nama', 'Idempotent Prepaid')->firstOrFail();

        Carbon::setTestNow(Carbon::parse('2026-11-22 11:05:00', config('app.timezone', 'Asia/Jakarta')));
        $this->billingService->evaluateExpiration(Carbon::now());

        $firstPay = $this->post(route('transaction.processPayment', $prepaidTx->id_transaksi), [
            'payment_method' => 'tunai',
            'amount_paid' => 10000,
            'discount' => 0,
        ]);
        $firstPay->assertRedirect(route('transaction.print', ['id' => $prepaidTx->id_transaksi, 'action' => 'payment']));
        $this->assertSame(1, Invoice::where('transaction_id', $prepaidTx->id_transaksi)->count());
        $originalInvoiceId = Invoice::where('transaction_id', $prepaidTx->id_transaksi)->value('id');

        // Repeated HTTP processPayment redirects cleanly without duplicating invoice
        $secondPay = $this->post(route('transaction.processPayment', $prepaidTx->id_transaksi), [
            'payment_method' => 'tunai',
            'amount_paid' => 10000,
            'discount' => 0,
        ]);
        $secondPay->assertRedirect(route('transaction.print', $prepaidTx->id_transaksi));
        $secondPay->assertSessionHas('gagal', 'Transaksi ini sudah lunas.');
        $this->assertSame(1, Invoice::where('transaction_id', $prepaidTx->id_transaksi)->count());

        // Repeated showPayment also redirects with 'Transaksi ini sudah lunas.'
        $showAgain = $this->get(route('transaction.showPayment', $prepaidTx->id_transaksi));
        $showAgain->assertRedirect(route('transaction.print', $prepaidTx->id_transaksi));
        $showAgain->assertSessionHas('gagal', 'Transaksi ini sudah lunas.');

        // Direct service call idempotency check
        $serviceInvoice = app(PaketBillingService::class)->activatePaidPaket($prepaidTx->fresh(), [
            'payment_method' => 'tunai',
            'amount_paid' => 10000,
            'cashier_user_id' => $this->kasir->id,
        ]);
        $this->assertSame($originalInvoiceId, $serviceInvoice->id);
        $this->assertSame(1, Invoice::where('transaction_id', $prepaidTx->id_transaksi)->count());

        // 2. Finished unpaid postpaid bill repeated payment
        $this->post(route('transaction.store'), [
            'nama' => 'Idempotent Postpaid',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'postpaid',
        ])->assertRedirect();
        $postpaidTx = Transaction::where('nama', 'Idempotent Postpaid')->firstOrFail();

        Carbon::setTestNow(Carbon::parse('2026-11-22 12:05:00', config('app.timezone', 'Asia/Jakarta')));
        $this->postJson(route('device.stop', $this->device->id))->assertStatus(200);

        $this->post(route('transaction.processPayment', $postpaidTx->id_transaksi), [
            'payment_method' => 'tunai',
            'amount_paid' => 10000,
            'discount' => 0,
        ])->assertRedirect(route('transaction.print', ['id' => $postpaidTx->id_transaksi, 'action' => 'payment']));

        $secondPostpaidPay = $this->post(route('transaction.processPayment', $postpaidTx->id_transaksi), [
            'payment_method' => 'tunai',
            'amount_paid' => 10000,
            'discount' => 0,
        ]);
        $secondPostpaidPay->assertRedirect(route('transaction.print', $postpaidTx->id_transaksi));
        $secondPostpaidPay->assertSessionHas('gagal', 'Transaksi ini sudah lunas.');
        $this->assertSame(1, Invoice::where('transaction_id', $postpaidTx->id_transaksi)->count());
    }

    /** @test */
    public function test_09_cross_midnight_session_expiring_after_midnight_remains_visible_and_payable(): void
    {
        $this->actingAs($this->kasir);

        // Start 10-minute session at 23:55 -> expires at 00:05 next day
        Carbon::setTestNow(Carbon::parse('2026-11-22 23:55:00', config('app.timezone', 'Asia/Jakarta')));

        $this->post(route('transaction.store'), [
            'nama' => 'Pelanggan Tengah Malam',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'duration_seconds' => 600,
            'harga' => 12000,
            'total' => 2000,
            'action' => 'simpan',
        ])->assertRedirect(route('transaction.index', ['tab' => 'stations']));

        $tx = Transaction::where('nama', 'Pelanggan Tengah Malam')->firstOrFail();
        $this->assertSame('2026-11-22 23:55:00', $tx->started_at->toDateTimeString());
        $this->assertSame('2026-11-23 00:05:00', $tx->expires_at->toDateTimeString());
        $this->assertSame('23:55:00', $tx->waktu_mulai);
        $this->assertSame('00:05:00', $tx->waktu_Selesai);

        // Before midnight expiration (23:59:00), session is still RUNNING
        Carbon::setTestNow(Carbon::parse('2026-11-22 23:59:00', config('app.timezone', 'Asia/Jakarta')));
        $preMidTimers = $this->getJson(route('device.timers'))->assertStatus(200)->json();
        $preMidDev = collect($preMidTimers['devices'])->firstWhere('id', $this->device->id);
        $this->assertSame('Digunakan', $preMidDev['status']);
        $this->assertSame($tx->id_transaksi, $preMidDev['transaction']['id_transaksi']);

        // Cross midnight past 00:05:00 (2026-11-23 00:06:00)
        Carbon::setTestNow(Carbon::parse('2026-11-23 00:06:00', config('app.timezone', 'Asia/Jakarta')));
        $eval = $this->billingService->evaluateExpiration(Carbon::now());
        $this->assertSame(1, $eval['count']);

        $tx->refresh();
        $this->assertSame('selesai', $tx->status_transaksi);
        $this->assertSame(Transaction::BILLING_EXPIRED_PENDING_ACTION, $tx->billing_status);
        $this->assertSame('unpaid', $tx->payment_status);
        $this->assertSame('Tersedia', $this->device->fresh()->status);

        // Unpaid bill remains visible on /transaction?tab=stations and /device/timers on the new calendar day
        $dashResp = $this->get(route('transaction.index', ['tab' => 'stations']))->assertStatus(200);
        $cardHtml = $this->extractStationCardHtml($dashResp->getContent(), $this->device->id);
        $this->assertSame((string) $tx->id_transaksi, $this->extractCardDataAttribute($cardHtml, 'data-unpaid-tx-ids'));
        $this->assertOutstandingBillPresentInCard($cardHtml, $tx->id_transaksi, 'Pelanggan Tengah Malam');

        $postMidTimers = $this->getJson(route('device.timers'))->assertStatus(200)->json();
        $postMidDev = collect($postMidTimers['devices'])->firstWhere('id', $this->device->id);
        $this->assertSame('Tersedia', $postMidDev['status']);
        $this->assertNull($postMidDev['transaction']);
        $this->assertSame([$tx->id_transaksi], $postMidDev['unpaid_transaction_ids']);

        // Payable after midnight
        $this->get(route('transaction.showPayment', $tx->id_transaksi))
            ->assertStatus(200)
            ->assertSee('Pelanggan Tengah Malam');

        $this->post(route('transaction.processPayment', $tx->id_transaksi), [
            'payment_method' => 'tunai',
            'amount_paid' => 2000,
            'discount' => 0,
        ])->assertRedirect(route('transaction.print', ['id' => $tx->id_transaksi, 'action' => 'payment']));

        $tx->refresh();
        $this->assertSame('paid', $tx->payment_status);
        $this->assertSame('2026-11-23 00:06:00', $tx->paid_at->toDateTimeString());
        $this->assertSame(1, Invoice::where('transaction_id', $tx->id_transaksi)->count());

        $afterDash = $this->get(route('transaction.index', ['tab' => 'stations']))->assertStatus(200);
        $this->assertNoOutstandingBillsInCard($this->extractStationCardHtml($afterDash->getContent(), $this->device->id));
    }

    /** @test */
    public function test_10_dashboard_polling_agrees_with_dom_unpaid_ids_and_clears_after_payment(): void
    {
        $this->actingAs($this->kasir);

        $station2 = $this->createStationWithHardware('PS5 Station 2', 'STATION-PS-02', 'AA:BB:CC:DD:EE:22');

        // Station 1: Start unpaid timer
        $this->post(route('transaction.store'), [
            'nama' => 'Polling Sync Station 1',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'duration_seconds' => 3600,
            'harga' => 10000,
            'total' => 10000,
            'action' => 'simpan',
        ])->assertRedirect();
        $tx1 = Transaction::where('nama', 'Polling Sync Station 1')->firstOrFail();

        // While running, DOM and /device/timers both report empty unpaid IDs and active tx1
        $runningDash = $this->get(route('transaction.index', ['tab' => 'stations']))->assertStatus(200);
        $runningCard = $this->extractStationCardHtml($runningDash->getContent(), $this->device->id);
        $runningDomUnpaid = $this->normalizeIdCsv((string) $this->extractCardDataAttribute($runningCard, 'data-unpaid-tx-ids'));
        $runningTimers = $this->getJson(route('device.timers'))->assertStatus(200)->json();
        $runningDevPayload = collect($runningTimers['devices'])->firstWhere('id', $this->device->id);

        $this->assertSame('', $runningDomUnpaid);
        $this->assertSame('Digunakan', $runningDevPayload['status']);
        $this->assertSame($tx1->id_transaksi, $runningDevPayload['transaction']['id_transaksi']);
        $this->assertSame([], $runningDevPayload['unpaid_transaction_ids']);

        // Advance past expiration and let /device/timers heartbeat expire tx1
        Carbon::setTestNow(Carbon::parse('2026-11-22 11:05:00', config('app.timezone', 'Asia/Jakarta')));

        $expiredTimers1 = $this->getJson(route('device.timers'))->assertStatus(200)->json();
        $expiredDev1 = collect($expiredTimers1['devices'])->firstWhere('id', $this->device->id);
        $this->assertSame('Tersedia', $expiredDev1['status']);
        $this->assertNull($expiredDev1['transaction']);
        $this->assertSame([$tx1->id_transaksi], $expiredDev1['unpaid_transaction_ids']);

        // Station 2 has an older unpaid bill AND a new running session to verify mixed state polling
        $this->post(route('transaction.store'), [
            'nama' => 'Polling Old Station 2',
            'device_id' => $station2->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'duration_seconds' => 600,
            'harga' => 10000,
            'total' => 2000,
            'action' => 'simpan',
        ])->assertRedirect();
        $tx2Old = Transaction::where('nama', 'Polling Old Station 2')->firstOrFail();

        Carbon::setTestNow(Carbon::parse('2026-11-22 11:20:00', config('app.timezone', 'Asia/Jakarta')));
        $this->billingService->evaluateExpiration(Carbon::now());

        $this->post(route('transaction.store'), [
            'nama' => 'Polling Active Station 2',
            'device_id' => $station2->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'postpaid',
        ])->assertRedirect();
        $tx2Active = Transaction::where('nama', 'Polling Active Station 2')->firstOrFail();

        // Render dashboard DOM and compare against repeated /device/timers polls
        $dashHtml = $this->get(route('transaction.index', ['tab' => 'stations']))->assertStatus(200)->getContent();
        $card1Html = $this->extractStationCardHtml($dashHtml, $this->device->id);
        $card2Html = $this->extractStationCardHtml($dashHtml, $station2->id);

        $domUnpaid1 = $this->normalizeIdCsv((string) $this->extractCardDataAttribute($card1Html, 'data-unpaid-tx-ids'));
        $domUnpaid2 = $this->normalizeIdCsv((string) $this->extractCardDataAttribute($card2Html, 'data-unpaid-tx-ids'));

        for ($poll = 0; $poll < 2; $poll++) {
            $pollTimers = $this->getJson(route('device.timers'))->assertStatus(200)->json();
            $pollDev1 = collect($pollTimers['devices'])->firstWhere('id', $this->device->id);
            $pollDev2 = collect($pollTimers['devices'])->firstWhere('id', $station2->id);

            $this->assertSame('Tersedia', $pollDev1['status']);
            $this->assertNull($pollDev1['transaction']);
            $this->assertSame($domUnpaid1, $this->normalizeIdCsv($pollDev1['unpaid_transaction_ids']));
            $this->assertSame([$tx1->id_transaksi], $pollDev1['unpaid_transaction_ids']);

            $this->assertSame('Digunakan', $pollDev2['status']);
            $this->assertSame($tx2Active->id_transaksi, $pollDev2['transaction']['id_transaksi']);
            $this->assertSame($domUnpaid2, $this->normalizeIdCsv($pollDev2['unpaid_transaction_ids']));
            $this->assertSame([$tx2Old->id_transaksi], $pollDev2['unpaid_transaction_ids']);
        }

        // Re-fetching dashboard after polling still shows the unpaid bills
        $reloadedDash = $this->get(route('transaction.index', ['tab' => 'stations']))->assertStatus(200)->getContent();
        $this->assertOutstandingBillPresentInCard($this->extractStationCardHtml($reloadedDash, $this->device->id), $tx1->id_transaksi, 'Polling Sync Station 1');
        $this->assertOutstandingBillPresentInCard($this->extractStationCardHtml($reloadedDash, $station2->id), $tx2Old->id_transaksi, 'Polling Old Station 2');

        // After payment of tx1 and tx2Old, /device/timers returns unpaid_transaction_ids = [] and DOM clears bills
        $this->post(route('transaction.processPayment', $tx1->id_transaksi), [
            'payment_method' => 'tunai',
            'amount_paid' => 10000,
            'discount' => 0,
        ])->assertRedirect();

        $this->post(route('transaction.processPayment', $tx2Old->id_transaksi), [
            'payment_method' => 'tunai',
            'amount_paid' => 2000,
            'discount' => 0,
        ])->assertRedirect();

        $paidTimers = $this->getJson(route('device.timers'))->assertStatus(200)->json();
        $paidDev1 = collect($paidTimers['devices'])->firstWhere('id', $this->device->id);
        $paidDev2 = collect($paidTimers['devices'])->firstWhere('id', $station2->id);

        $this->assertSame('Tersedia', $paidDev1['status']);
        $this->assertNull($paidDev1['transaction']);
        $this->assertSame([], $paidDev1['unpaid_transaction_ids']);

        $this->assertSame('Digunakan', $paidDev2['status']);
        $this->assertSame($tx2Active->id_transaksi, $paidDev2['transaction']['id_transaksi']);
        $this->assertSame([], $paidDev2['unpaid_transaction_ids']);

        $finalHtml = $this->get(route('transaction.index', ['tab' => 'stations']))->assertStatus(200)->getContent();
        $this->assertNoOutstandingBillsInCard($this->extractStationCardHtml($finalHtml, $this->device->id));
        $finalCard2 = $this->extractStationCardHtml($finalHtml, $station2->id);
        $this->assertMatchesRegularExpression('/class="[^"]*\bis-used\b[^"]*"/', $finalCard2);
        $this->assertNoOutstandingBillsInCard($finalCard2);
    }

    /** @test */
    public function test_11_owner_read_only_view_renders_unpaid_bill_without_payment_action(): void
    {
        $owner = User::factory()->create([
            'name' => 'Owner PostSession',
            'role' => 'owner',
            'email' => 'owner.e2e.postsess@sugengrawuh.test',
        ]);

        // Create and expire an unpaid prepaid timer session so the station is Tersedia with an unpaid bill
        $this->actingAs($this->kasir)->post(route('transaction.store'), [
            'nama' => 'Pelanggan ReadOnly Owner',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'duration_seconds' => 3600,
            'harga' => 10000,
            'total' => 10000,
            'action' => 'simpan',
        ])->assertRedirect();

        $tx = Transaction::where('nama', 'Pelanggan ReadOnly Owner')->firstOrFail();

        Carbon::setTestNow(Carbon::parse('2026-11-22 11:05:00', config('app.timezone', 'Asia/Jakarta')));
        $this->billingService->evaluateExpiration(Carbon::now());
        $this->assertSame('Tersedia', $this->device->fresh()->status);

        // Owner views /transaction?tab=stations
        $ownerResp = $this->actingAs($owner)->get(route('transaction.index', ['tab' => 'stations']))->assertStatus(200);
        $ownerHtml = $ownerResp->getContent();
        $cardHtml = $this->extractStationCardHtml($ownerHtml, $this->device->id);

        $this->assertMatchesRegularExpression('/class="[^"]*\bis-available\b[^"]*"/', $cardHtml);
        $this->assertStringContainsString('Tagihan Belum Lunas', $cardHtml);
        $this->assertStringContainsString('#' . $tx->id_transaksi, $cardHtml);
        $this->assertStringContainsString('BELUM BAYAR', $cardHtml);
        $this->assertStringNotContainsString('js-pay-outstanding', $cardHtml);
        $this->assertStringNotContainsString('js-pay-outstanding', $ownerHtml);
        $this->assertStringNotContainsString(route('transaction.showPayment', $tx->id_transaksi), $cardHtml);
        $this->assertStringNotContainsString(route('transaction.showPayment', $tx->id_transaksi), $ownerHtml);

        $bootstrap = $this->extractPosBootstrap($ownerHtml);
        $this->assertFalse($bootstrap['canOperate']);
        $this->assertArrayHasKey((string) $this->device->id, $bootstrap['outstandingUnpaidTransactions']);
        $this->assertSame($tx->id_transaksi, $bootstrap['outstandingUnpaidTransactions'][(string) $this->device->id][0]['id_transaksi']);
        $this->assertNull($bootstrap['outstandingUnpaidTransactions'][(string) $this->device->id][0]['payment_url']);
    }
}
