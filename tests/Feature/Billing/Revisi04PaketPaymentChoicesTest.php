<?php

namespace Tests\Feature\Billing;

use App\Models\AutomationIntent;
use App\Models\CustomPackage;
use App\Models\Device;
use App\Models\Fnb;
use App\Models\FnbOrder;
use App\Models\HourlyPrice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Location;
use App\Models\PhysicalDevice;
use App\Models\Playstation;
use App\Models\StationDeviceMapping;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BillingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Revisi04PaketPaymentChoicesTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $kasir;
    protected User $owner;
    protected Playstation $playstation;
    protected Device $device;
    protected Location $location;
    protected Fnb $fnbKopi;
    protected Fnb $fnbSnack;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('kasir_ps_test', DB::connection()->getDatabaseName(), 'Must run on kasir_ps_test');

        Config::set('hardware.driver', 'mock');
        Config::set('hardware.real_execution_enabled', false);
        Config::set('hardware.simulation_enabled', true);
        Config::set('hardware.automation_enabled', true);
        Config::set('hardware.allowed_environments', ['local', 'testing']);
        Config::set('hardware.allowed_databases', ['kasir_ps_test']);

        Carbon::setTestNow(Carbon::parse('2026-11-16 10:00:00'));

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'email' => 'admin.rev04@sugengrawuh.test',
        ]);

        $this->kasir = User::factory()->create([
            'role' => 'kasir',
            'email' => 'kasir.rev04@sugengrawuh.test',
        ]);

        $this->owner = User::factory()->create([
            'role' => 'owner',
            'email' => 'owner.rev04@sugengrawuh.test',
        ]);

        $this->playstation = Playstation::create([
            'nama' => 'PS5 Station',
            'harga' => 11000,
        ]);

        HourlyPrice::create([
            'playstation_id' => $this->playstation->id,
            'hour' => 1,
            'duration_seconds' => 3600,
            'price' => 10000,
        ]);

        HourlyPrice::create([
            'playstation_id' => $this->playstation->id,
            'hour' => 3,
            'duration_seconds' => 10800,
            'price' => 27000,
        ]);

        $this->device = $this->createStationWithHardware('PS5 1', $this->playstation, 'STATION-01', 'AA:BB:CC:DD:EE:01');

        $this->location = Location::where('device_id', $this->device->id)->firstOrFail();

        $this->fnbKopi = Fnb::create([
            'nama' => 'Kopi Susu',
            'harga_beli' => 3000,
            'harga_jual' => 5000,
            'stok' => 20,
        ]);

        $this->fnbSnack = Fnb::create([
            'nama' => 'Kentang Goreng',
            'harga_beli' => 5000,
            'harga_jual' => 8000,
            'stok' => 20,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function createStationWithHardware(
        string $name,
        Playstation $playstation,
        string $locationCode,
        string $macAddress
    ): Device {
        $device = Device::create([
            'nama' => $name,
            'playstation_id' => $playstation->id,
            'status' => 'Tersedia',
            'session_generation' => 0,
        ]);
        $device->playstations()->attach($playstation->id);

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
            'ip_address' => '192.168.1.' . (100 + $device->id),
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

    private function createCustomPackage(): CustomPackage
    {
        $pkg = CustomPackage::create([
            'nama_paket' => 'Paket Mabar 2 Jam',
            'harga_total' => 18000,
            'is_active' => true,
        ]);
        $pkg->playstations()->attach($this->playstation->id, ['lama_main' => 120]);
        $pkg->fnbs()->attach($this->fnbKopi->id, ['quantity' => 1]);

        return $pkg;
    }

    public function test_dashboard_paket_modal_shows_both_bayar_sekarang_and_simpan_transaksi_buttons(): void
    {
        $response = $this->actingAs($this->kasir)->get(route('transaction.index'));

        $response->assertStatus(200);
        $html = $response->getContent();

        $this->assertStringContainsString('id="paketDialog"', $html);
        $this->assertMatchesRegularExpression(
            '/<button[^>]*id="btn-submit-paket-simpan"[^>]*data-paket-action="simpan"[^>]*>\s*Simpan Transaksi\s*<\/button>/s',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/<button[^>]*id="btn-submit-paket-bayar"[^>]*data-paket-action="bayar"[^>]*>\s*Bayar Sekarang\s*<\/button>/s',
            $html
        );
    }

    public function test_bayar_sekarang_prepaid_and_custom_package_keep_draft_until_paid_and_print_custom_receipt(): void
    {
        // 1. Prepaid with action=bayar
        $prepaidStore = $this->actingAs($this->kasir)->post(route('transaction.store'), [
            'nama' => 'Pelanggan Bayar Prepaid',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'duration_seconds' => 3600,
            'harga' => 11000,
            'total' => 10000,
            'action' => 'bayar',
        ]);

        $prepaidTx = Transaction::where('nama', 'Pelanggan Bayar Prepaid')->firstOrFail();
        $prepaidStore->assertRedirect(route('transaction.showPayment', $prepaidTx->id_transaksi));

        $this->assertSame('draft', $prepaidTx->status_transaksi);
        $this->assertSame(Transaction::BILLING_IDLE, $prepaidTx->billing_status);
        $this->assertSame('unpaid', $prepaidTx->payment_status);
        $this->assertNull($prepaidTx->started_at);
        $this->assertNull($prepaidTx->expires_at);
        $this->assertNull($prepaidTx->session_token);
        $this->assertSame('Tersedia', $this->device->fresh()->status);
        $this->assertSame(0, Invoice::where('transaction_id', $prepaidTx->id_transaksi)->count());
        $this->assertSame(0, AutomationIntent::where('transaction_id', $prepaidTx->id_transaksi)->count());

        $prepaidPay = $this->actingAs($this->kasir)->post(route('transaction.processPayment', $prepaidTx->id_transaksi), [
            'payment_method' => 'tunai',
            'amount_paid' => 10000,
            'discount' => 0,
        ]);
        $prepaidPay->assertRedirect(route('transaction.print', ['id' => $prepaidTx->id_transaksi, 'action' => 'payment']));

        $prepaidTx->refresh();
        $this->assertSame('sukses', $prepaidTx->status_transaksi);
        $this->assertSame('paid', $prepaidTx->payment_status);
        $this->assertSame(Transaction::BILLING_RUNNING, $prepaidTx->billing_status);
        $this->assertNotNull($prepaidTx->started_at);
        $this->assertNotNull($prepaidTx->expires_at);
        $this->assertNotNull($prepaidTx->session_token);
        $this->assertSame('Digunakan', $this->device->fresh()->status);
        $this->assertSame(1, Invoice::where('transaction_id', $prepaidTx->id_transaksi)->count());
        $this->assertDatabaseHas('automation_intents', [
            'transaction_id' => $prepaidTx->id_transaksi,
            'event_type' => AutomationIntent::EVENT_BILLING_STARTED,
            'desired_state' => AutomationIntent::STATE_ON,
        ]);
        $this->assertDatabaseHas('automation_intents', [
            'transaction_id' => $prepaidTx->id_transaksi,
            'event_type' => AutomationIntent::EVENT_BILLING_EXPIRED,
            'desired_state' => AutomationIntent::STATE_OFF,
        ]);

        // 2. Custom package with action=bayar on a second station
        $device2 = $this->createStationWithHardware('PS5 2', $this->playstation, 'STATION-02', 'AA:BB:CC:DD:EE:02');
        $customPackage = $this->createCustomPackage();

        $customStore = $this->actingAs($this->kasir)->post(route('transaction.store'), [
            'nama' => 'Pelanggan Bayar Custom',
            'device_id' => $device2->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'custom_package',
            'custom_package_id' => $customPackage->id,
            'harga' => $customPackage->harga_total,
            'total' => $customPackage->harga_total,
            'action' => 'bayar',
        ]);

        $customTx = Transaction::where('nama', 'Pelanggan Bayar Custom')->firstOrFail();
        $customStore->assertRedirect(route('transaction.showPayment', $customTx->id_transaksi));

        $this->assertSame('draft', $customTx->status_transaksi);
        $this->assertSame(Transaction::BILLING_IDLE, $customTx->billing_status);
        $this->assertSame('unpaid', $customTx->payment_status);
        $this->assertSame('Tersedia', $device2->fresh()->status);
        $this->assertSame(0, Invoice::where('transaction_id', $customTx->id_transaksi)->count());
        $this->assertSame(0, AutomationIntent::where('transaction_id', $customTx->id_transaksi)->count());

        $customPay = $this->actingAs($this->kasir)->post(route('transaction.processPayment', $customTx->id_transaksi), [
            'payment_method' => 'tunai',
            'amount_paid' => 20000,
            'discount' => 0,
        ]);
        $customPay->assertRedirect(route('transaction.print', ['id' => $customTx->id_transaksi, 'action' => 'payment']));

        $customTx->refresh();
        $this->assertSame('sukses', $customTx->status_transaksi);
        $this->assertSame('paid', $customTx->payment_status);
        $this->assertSame(Transaction::BILLING_RUNNING, $customTx->billing_status);
        $this->assertSame('Digunakan', $device2->fresh()->status);
        $this->assertSame(1, Invoice::where('transaction_id', $customTx->id_transaksi)->count());
        $this->assertDatabaseHas('automation_intents', [
            'transaction_id' => $customTx->id_transaksi,
            'event_type' => AutomationIntent::EVENT_BILLING_STARTED,
            'desired_state' => AutomationIntent::STATE_ON,
        ]);
        $this->assertDatabaseHas('automation_intents', [
            'transaction_id' => $customTx->id_transaksi,
            'event_type' => AutomationIntent::EVENT_BILLING_EXPIRED,
            'desired_state' => AutomationIntent::STATE_OFF,
        ]);

        // Custom package receipt print renders cleanly with HTTP 200
        $receiptResponse = $this->actingAs($this->kasir)->get(route('transaction.print', $customTx->id_transaksi));
        $receiptResponse->assertStatus(200);
        $receiptResponse->assertSee('Paket Mabar 2 Jam');
        $receiptResponse->assertSee('Harga Paket');
    }

    public function test_simpan_transaksi_prepaid_preset_sub_hour_and_custom_package_behaviour_and_double_booking_guard(): void
    {
        $devicePreset = $this->device;
        $deviceSubHour = $this->createStationWithHardware('PS5 2', $this->playstation, 'STATION-02', 'AA:BB:CC:DD:EE:02');
        $deviceCustom = $this->createStationWithHardware('PS5 3', $this->playstation, 'STATION-03', 'AA:BB:CC:DD:EE:03');
        $deviceBayarRef = $this->createStationWithHardware('PS5 4', $this->playstation, 'STATION-04', 'AA:BB:CC:DD:EE:04');

        // Reference 'bayar' drafts to prove identical tariff calculation
        $this->actingAs($this->kasir)->post(route('transaction.store'), [
            'nama' => 'Ref Bayar Preset',
            'device_id' => $deviceBayarRef->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'duration_seconds' => 3600,
            'harga' => 11000,
            'total' => 10000,
            'action' => 'bayar',
        ])->assertRedirect();
        $bayarPresetRef = Transaction::where('nama', 'Ref Bayar Preset')->firstOrFail();

        $this->actingAs($this->kasir)->post(route('transaction.store'), [
            'nama' => 'Ref Bayar SubHour',
            'device_id' => $deviceBayarRef->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'jam_main_select' => 'custom',
            'custom_hours' => 0,
            'custom_minutes' => 30,
            'custom_seconds' => 0,
            'duration_seconds' => 1800,
            'harga' => 11000,
            'total' => 5500,
            'action' => 'bayar',
        ])->assertRedirect();
        $bayarSubHourRef = Transaction::where('nama', 'Ref Bayar SubHour')->firstOrFail();

        // A. Simpan Transaksi - Prepaid Preset (1 hour = Rp 10.000), anonymous nama -> 'Pelanggan'
        $simpanPresetResp = $this->actingAs($this->kasir)->post(route('transaction.store'), [
            'nama' => '',
            'device_id' => $devicePreset->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'duration_seconds' => 3600,
            'harga' => 11000,
            'total' => 10000,
            'action' => 'simpan',
        ]);

        $simpanPresetResp->assertRedirect(route('transaction.index', ['tab' => 'stations']));
        $presetTx = Transaction::where('device_id', $devicePreset->id)->firstOrFail();

        $this->assertSame('Pelanggan', $presetTx->nama);
        $this->assertSame((float) $bayarPresetRef->total, (float) $presetTx->total);
        $this->assertSame($bayarPresetRef->resolvePaketRentalTotal(), $presetTx->resolvePaketRentalTotal());
        $this->assertSame(10000.0, $presetTx->resolvePaketRentalTotal());
        $this->assertSame('sukses', $presetTx->status_transaksi);
        $this->assertSame('unpaid', $presetTx->payment_status);
        $this->assertSame(Transaction::BILLING_RUNNING, $presetTx->billing_status);
        $this->assertNotNull($presetTx->started_at);
        $this->assertNotNull($presetTx->expires_at);
        $this->assertSame(3600, $presetTx->started_at->diffInSeconds($presetTx->expires_at));
        $this->assertNotNull($presetTx->session_token);
        $this->assertSame(40, strlen($presetTx->session_token));
        $this->assertSame('Digunakan', $devicePreset->fresh()->status);
        $this->assertSame(0, Invoice::where('transaction_id', $presetTx->id_transaksi)->count());

        $this->assertDatabaseHas('automation_intents', [
            'transaction_id' => $presetTx->id_transaksi,
            'event_type' => AutomationIntent::EVENT_BILLING_STARTED,
            'desired_state' => AutomationIntent::STATE_ON,
        ]);
        $this->assertDatabaseHas('automation_intents', [
            'transaction_id' => $presetTx->id_transaksi,
            'event_type' => AutomationIntent::EVENT_BILLING_EXPIRED,
            'desired_state' => AutomationIntent::STATE_OFF,
        ]);

        // Double-booking prevention on now-occupied station (JSON & form POST)
        $dupJsonResp = $this->actingAs($this->kasir)->postJson(route('transaction.store'), [
            'nama' => 'Pelanggan Duplikat',
            'device_id' => $devicePreset->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'duration_seconds' => 3600,
            'harga' => 11000,
            'total' => 10000,
            'action' => 'simpan',
        ]);
        $dupJsonResp->assertStatus(422)->assertJson(['success' => false]);

        $dupWebResp = $this->actingAs($this->kasir)->post(route('transaction.store'), [
            'nama' => 'Pelanggan Duplikat Web',
            'device_id' => $devicePreset->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'duration_seconds' => 3600,
            'harga' => 11000,
            'total' => 10000,
            'action' => 'bayar',
        ]);
        $dupWebResp->assertSessionHas('gagal');
        $this->assertSame(1, Transaction::where('device_id', $devicePreset->id)->count());

        // B. Simpan Transaksi - Prepaid Sub-hour Custom Duration 00:30:00 (1800s -> Rp 5.500)
        $expectedSubHourPrice = $this->playstation->calculateCustomPrice(1800);
        $this->assertSame(5500, $expectedSubHourPrice);

        $simpanSubHourResp = $this->actingAs($this->kasir)->postJson(route('transaction.store'), [
            'nama' => '',
            'device_id' => $deviceSubHour->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'jam_main_select' => 'custom',
            'custom_hours' => 0,
            'custom_minutes' => 30,
            'custom_seconds' => 0,
            'duration_seconds' => 1800,
            'harga' => 11000,
            'total' => $expectedSubHourPrice,
            'action' => 'simpan',
        ]);
        $simpanSubHourResp->assertStatus(201)->assertJson([
            'success' => true,
            'redirect_url' => route('transaction.index', ['tab' => 'stations']),
        ]);

        $subHourTx = Transaction::where('device_id', $deviceSubHour->id)->firstOrFail();
        $this->assertSame('Pelanggan', $subHourTx->nama);
        $this->assertSame('00:30:00', $subHourTx->jam_main);
        $this->assertSame(1800, $subHourTx->duration_seconds);
        $this->assertSame((float) $bayarSubHourRef->total, (float) $subHourTx->total);
        $this->assertSame(5500.0, $subHourTx->resolvePaketRentalTotal());
        $this->assertSame('sukses', $subHourTx->status_transaksi);
        $this->assertSame('unpaid', $subHourTx->payment_status);
        $this->assertSame(Transaction::BILLING_RUNNING, $subHourTx->billing_status);
        $this->assertSame('Digunakan', $deviceSubHour->fresh()->status);
        $this->assertSame(0, Invoice::where('transaction_id', $subHourTx->id_transaksi)->count());

        // C. Simpan Transaksi - Custom Package
        $customPackage = $this->createCustomPackage();
        $simpanCustomResp = $this->actingAs($this->kasir)->post(route('transaction.store'), [
            'nama' => '',
            'device_id' => $deviceCustom->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'custom_package',
            'custom_package_id' => $customPackage->id,
            'harga' => $customPackage->harga_total,
            'total' => $customPackage->harga_total,
            'action' => 'simpan',
        ]);
        $simpanCustomResp->assertRedirect(route('transaction.index', ['tab' => 'stations']));

        $customTx = Transaction::where('device_id', $deviceCustom->id)->firstOrFail();
        $this->assertSame('Pelanggan', $customTx->nama);
        $this->assertSame(18000.0, $customTx->resolvePaketRentalTotal());
        $this->assertSame('sukses', $customTx->status_transaksi);
        $this->assertSame('unpaid', $customTx->payment_status);
        $this->assertSame(Transaction::BILLING_RUNNING, $customTx->billing_status);
        $this->assertSame('Digunakan', $deviceCustom->fresh()->status);
        $this->assertSame(0, Invoice::where('transaction_id', $customTx->id_transaksi)->count());
        $this->assertDatabaseHas('automation_intents', [
            'transaction_id' => $customTx->id_transaksi,
            'event_type' => AutomationIntent::EVENT_BILLING_STARTED,
            'desired_state' => AutomationIntent::STATE_ON,
        ]);
        $this->assertDatabaseHas('automation_intents', [
            'transaction_id' => $customTx->id_transaksi,
            'event_type' => AutomationIntent::EVENT_BILLING_EXPIRED,
            'desired_state' => AutomationIntent::STATE_OFF,
        ]);

        // D. Station cards on /transaction render Paket, BELUM BAYAR, live timer, accurate Sewa (not Rp 0!), and action buttons
        $dashboardResp = $this->actingAs($this->kasir)->get(route('transaction.index'));
        $dashboardResp->assertStatus(200);
        $html = $dashboardResp->getContent();

        foreach ([
            [$devicePreset->id, $presetTx->id_transaksi, 'Paket', 'Rp 10.000'],
            [$deviceSubHour->id, $subHourTx->id_transaksi, 'Paket', 'Rp 5.500'],
            [$deviceCustom->id, $customTx->id_transaksi, 'Custom Paket', 'Rp 18.000'],
        ] as [$devId, $txId, $typeLabel, $expectedSewa]) {
            $cardHtml = $this->extractStationCardHtml($html, $devId);
            $this->assertStringContainsString($typeLabel, $cardHtml);
            $this->assertStringContainsString('BELUM BAYAR', $cardHtml);
            $this->assertStringContainsString('js-pos-timer', $cardHtml);
            $this->assertStringContainsString($expectedSewa, $cardHtml);
            $this->assertStringNotContainsString('Sewa:</span>' . "\n" . '                        <strong>Rp 0</strong>', $cardHtml);
            $this->assertStringContainsString('js-open-detail', $cardHtml);
            $this->assertStringContainsString(route('transaction.showPayment', $txId), $cardHtml);
            $this->assertStringContainsString(route('transaction.edit', $txId), $cardHtml);
            $this->assertStringContainsString('js-open-cafe', $cardHtml);
            $this->assertStringContainsString('js-open-berhenti', $cardHtml);
            $this->assertStringNotContainsString('js-open-selesai', $cardHtml);
        }
    }

    public function test_mid_session_cafe_orders_and_early_payment_while_running_on_simpan_paket(): void
    {
        // Start Simpan Transaksi Paket (1 hour = Rp 10.000)
        $this->actingAs($this->kasir)->post(route('transaction.store'), [
            'nama' => 'Pelanggan Early Pay',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'duration_seconds' => 3600,
            'harga' => 11000,
            'total' => 10000,
            'action' => 'simpan',
        ])->assertRedirect(route('transaction.index', ['tab' => 'stations']));

        $tx = Transaction::where('nama', 'Pelanggan Early Pay')->firstOrFail();
        $origStartedAt = $tx->started_at->copy();
        $origExpiresAt = $tx->expires_at->copy();
        $origToken = $tx->session_token;

        $this->assertSame(
            1,
            AutomationIntent::where('transaction_id', $tx->id_transaksi)
                ->where('event_type', AutomationIntent::EVENT_BILLING_STARTED)
                ->count()
        );

        // Add unpaid Cafe order (action=save): 2 x Rp 5.000 = Rp 10.000
        $unpaidOrderResp = $this->actingAs($this->kasir)->postJson(route('fnb.orders.store'), [
            'transaction_id' => $tx->id_transaksi,
            'action' => 'save',
            'items' => [
                ['fnb_id' => $this->fnbKopi->id, 'qty' => 2],
            ],
        ]);
        $unpaidOrderResp->assertStatus(201)->assertJson(['success' => true]);
        $unpaidOrderId = $unpaidOrderResp->json('order.id');

        // Add already-paid Cafe order (action=pay_now): 1 x Rp 8.000 = Rp 8.000
        $paidOrderResp = $this->actingAs($this->kasir)->postJson(route('fnb.orders.store'), [
            'transaction_id' => $tx->id_transaksi,
            'action' => 'pay_now',
            'payment_method' => 'tunai',
            'amount_paid' => 8000,
            'items' => [
                ['fnb_id' => $this->fnbSnack->id, 'qty' => 1],
            ],
        ]);
        $paidOrderResp->assertStatus(201)->assertJson(['success' => true]);
        $paidOrderId = $paidOrderResp->json('order.id');

        // GET /transaction/{id}/payment shows rental (10k) + unpaid Cafe (10k) = 20k, plus FnB (Sudah Lunas) 8k badge
        $paymentPage = $this->actingAs($this->kasir)->get(route('transaction.showPayment', $tx->id_transaksi));
        $paymentPage->assertStatus(200);
        $paymentHtml = $paymentPage->getContent();
        $this->assertStringContainsString('FnB (Sudah Lunas):', $paymentHtml);
        $this->assertStringContainsString('Rp 8.000 (Lunas)', $paymentHtml);
        $this->assertStringContainsString('id="total-display">20.000</span>', $paymentHtml);

        // Pay early while session is still RUNNING
        $payResp = $this->actingAs($this->kasir)->post(route('transaction.processPayment', $tx->id_transaksi), [
            'payment_method' => 'tunai',
            'amount_paid' => 25000,
            'discount' => 0,
        ]);
        $payResp->assertRedirect(route('transaction.print', ['id' => $tx->id_transaksi, 'action' => 'payment']));

        $tx->refresh();
        $this->assertSame('paid', $tx->payment_status);
        $this->assertSame('sukses', $tx->status_transaksi);
        $this->assertSame(Transaction::BILLING_RUNNING, $tx->billing_status);
        $this->assertTrue($origStartedAt->equalTo($tx->started_at));
        $this->assertTrue($origExpiresAt->equalTo($tx->expires_at));
        $this->assertSame($origToken, $tx->session_token);
        $this->assertSame('Digunakan', $this->device->fresh()->status);

        // Unpaid FnbOrder is now paid
        $this->assertSame('paid', FnbOrder::findOrFail($unpaidOrderId)->payment_status);
        $this->assertSame('paid', FnbOrder::findOrFail($paidOrderId)->payment_status);

        // Exactly 1 Game+FnB invoice covering rental (10k) + unpaid order (10k) = 20k (never re-charging 8k paid order)
        $gameInvoices = Invoice::where('transaction_id', $tx->id_transaksi)
            ->where('invoice_type', '!=', Invoice::TYPE_FNB_STANDALONE)
            ->get();
        $this->assertCount(1, $gameInvoices);
        $gameInvoice = $gameInvoices->first();
        $this->assertSame(Invoice::TYPE_GAME_AND_FNB, $gameInvoice->invoice_type);
        $this->assertSame(20000.0, (float) $gameInvoice->subtotal);
        $this->assertSame(20000.0, (float) $gameInvoice->final_total);
        $this->assertSame(25000.0, (float) $gameInvoice->amount_paid);
        $this->assertSame(5000.0, (float) $gameInvoice->change_amount);

        $invoiceItems = InvoiceItem::where('invoice_id', $gameInvoice->id)->get();
        $this->assertCount(2, $invoiceItems);
        $this->assertSame(10000.0, (float) $invoiceItems->where('item_type', InvoiceItem::ITEM_TYPE_GAME_RENTAL)->sum('subtotal'));
        $this->assertSame(10000.0, (float) $invoiceItems->where('item_type', InvoiceItem::ITEM_TYPE_FNB_ITEM)->sum('subtotal'));

        // No duplicate BILLING_STARTED ON intent
        $this->assertSame(
            1,
            AutomationIntent::where('transaction_id', $tx->id_transaksi)
                ->where('event_type', AutomationIntent::EVENT_BILLING_STARTED)
                ->count()
        );

        // Repeat payment attempt is rejected/redirected without creating a second Game+FnB Invoice
        $repeatPay = $this->actingAs($this->kasir)->post(route('transaction.processPayment', $tx->id_transaksi), [
            'payment_method' => 'tunai',
            'amount_paid' => 20000,
            'discount' => 0,
        ]);
        $repeatPay->assertRedirect(route('transaction.print', $tx->id_transaksi));
        $repeatPay->assertSessionHas('gagal');
        $this->assertSame(
            1,
            Invoice::where('transaction_id', $tx->id_transaksi)
                ->where('invoice_type', '!=', Invoice::TYPE_FNB_STANDALONE)
                ->count()
        );
    }

    public function test_end_of_session_payment_after_berhenti_and_after_expiration(): void
    {
        // Case A: Stopped early via POST /device/{id}/stop -> STOPPED_EARLY, device Tersedia, even occupied by new customer
        $this->actingAs($this->kasir)->post(route('transaction.store'), [
            'nama' => 'Pelanggan Berhenti',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'duration_seconds' => 3600,
            'harga' => 11000,
            'total' => 10000,
            'action' => 'simpan',
        ])->assertRedirect(route('transaction.index', ['tab' => 'stations']));

        $stoppedTx = Transaction::where('nama', 'Pelanggan Berhenti')->firstOrFail();

        // Mid-session unpaid order (10k) + already-paid order (8k)
        $this->actingAs($this->kasir)->postJson(route('fnb.orders.store'), [
            'transaction_id' => $stoppedTx->id_transaksi,
            'action' => 'save',
            'items' => [['fnb_id' => $this->fnbKopi->id, 'qty' => 2]],
        ])->assertStatus(201);

        $this->actingAs($this->kasir)->postJson(route('fnb.orders.store'), [
            'transaction_id' => $stoppedTx->id_transaksi,
            'action' => 'pay_now',
            'payment_method' => 'tunai',
            'amount_paid' => 8000,
            'items' => [['fnb_id' => $this->fnbSnack->id, 'qty' => 1]],
        ])->assertStatus(201);

        Carbon::setTestNow(Carbon::parse('2026-11-16 10:25:00'));

        $stopResp = $this->actingAs($this->kasir)->postJson(route('device.stop', $this->device->id));
        $stopResp->assertStatus(200)->assertJson(['success' => true, 'new_status' => 'Tersedia']);

        $stoppedTx->refresh();
        $stoppedEndedAt = $stoppedTx->ended_at->copy();
        $this->assertSame(Transaction::BILLING_STOPPED_EARLY, $stoppedTx->billing_status);
        $this->assertSame('unpaid', $stoppedTx->payment_status);
        $this->assertSame('Tersedia', $this->device->fresh()->status);

        // Occupy the same station with a new customer's session before settling the stopped transaction
        $this->actingAs($this->kasir)->post(route('transaction.store'), [
            'nama' => 'Pelanggan Baru Station',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'postpaid',
        ])->assertRedirect(route('transaction.index'));
        $this->assertSame('Digunakan', $this->device->fresh()->status);

        $intentCountBeforeSettle = AutomationIntent::where('transaction_id', $stoppedTx->id_transaksi)->count();

        // Settle the stopped transaction while device is occupied by another customer
        $this->actingAs($this->kasir)->post(route('transaction.processPayment', $stoppedTx->id_transaksi), [
            'payment_method' => 'e-wallet',
            'amount_paid' => 20000,
            'discount' => 0,
        ])->assertRedirect(route('transaction.print', ['id' => $stoppedTx->id_transaksi, 'action' => 'payment']));

        $stoppedTx->refresh();
        $this->assertSame('paid', $stoppedTx->payment_status);
        $this->assertSame(Transaction::BILLING_STOPPED_EARLY, $stoppedTx->billing_status);
        $this->assertTrue($stoppedEndedAt->equalTo($stoppedTx->ended_at));
        $this->assertSame('Digunakan', $this->device->fresh()->status);
        $this->assertSame($intentCountBeforeSettle, AutomationIntent::where('transaction_id', $stoppedTx->id_transaksi)->count());

        $stoppedGameInv = Invoice::where('transaction_id', $stoppedTx->id_transaksi)
            ->where('invoice_type', '!=', Invoice::TYPE_FNB_STANDALONE)
            ->get();
        $this->assertCount(1, $stoppedGameInv);
        $this->assertSame(20000.0, (float) $stoppedGameInv->first()->final_total);

        // Case B: Expired via BillingService::evaluateExpiration -> EXPIRED_PENDING_ACTION, device Tersedia
        Carbon::setTestNow(Carbon::parse('2026-11-16 11:00:00'));
        $device2 = $this->createStationWithHardware('PS5 2', $this->playstation, 'STATION-02', 'AA:BB:CC:DD:EE:02');

        $this->actingAs($this->kasir)->post(route('transaction.store'), [
            'nama' => 'Pelanggan Expired',
            'device_id' => $device2->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'duration_seconds' => 3600,
            'harga' => 11000,
            'total' => 10000,
            'action' => 'simpan',
        ])->assertRedirect(route('transaction.index', ['tab' => 'stations']));

        $expiredTx = Transaction::where('nama', 'Pelanggan Expired')->firstOrFail();

        // Mid-session unpaid order (5k) + already-paid order (8k)
        $this->actingAs($this->kasir)->postJson(route('fnb.orders.store'), [
            'transaction_id' => $expiredTx->id_transaksi,
            'action' => 'save',
            'items' => [['fnb_id' => $this->fnbKopi->id, 'qty' => 1]],
        ])->assertStatus(201);

        $this->actingAs($this->kasir)->postJson(route('fnb.orders.store'), [
            'transaction_id' => $expiredTx->id_transaksi,
            'action' => 'pay_now',
            'payment_method' => 'tunai',
            'amount_paid' => 8000,
            'items' => [['fnb_id' => $this->fnbSnack->id, 'qty' => 1]],
        ])->assertStatus(201);

        // Advance time past expiration (12:05:00) and evaluate expiration
        Carbon::setTestNow(Carbon::parse('2026-11-16 12:05:00'));
        $evalResult = app(BillingService::class)->evaluateExpiration(Carbon::now());
        $this->assertSame(1, $evalResult['count']);

        $expiredTx->refresh();
        $expiredEndedAt = $expiredTx->ended_at->copy();
        $this->assertSame(Transaction::BILLING_EXPIRED_PENDING_ACTION, $expiredTx->billing_status);
        $this->assertSame('unpaid', $expiredTx->payment_status);
        $this->assertSame('Tersedia', $device2->fresh()->status);

        $intentCountBeforeExpPay = AutomationIntent::where('transaction_id', $expiredTx->id_transaksi)->count();

        // Settle expired transaction while device2 remains Tersedia
        $this->actingAs($this->kasir)->post(route('transaction.processPayment', $expiredTx->id_transaksi), [
            'payment_method' => 'transfer_bank',
            'amount_paid' => 15000,
            'discount' => 0,
        ])->assertRedirect(route('transaction.print', ['id' => $expiredTx->id_transaksi, 'action' => 'payment']));

        $expiredTx->refresh();
        $this->assertSame('paid', $expiredTx->payment_status);
        $this->assertSame(Transaction::BILLING_EXPIRED_PENDING_ACTION, $expiredTx->billing_status);
        $this->assertTrue($expiredEndedAt->equalTo($expiredTx->ended_at));
        $this->assertSame('Tersedia', $device2->fresh()->status);
        $this->assertSame($intentCountBeforeExpPay, AutomationIntent::where('transaction_id', $expiredTx->id_transaksi)->count());

        $expiredGameInv = Invoice::where('transaction_id', $expiredTx->id_transaksi)
            ->where('invoice_type', '!=', Invoice::TYPE_FNB_STANDALONE)
            ->get();
        $this->assertCount(1, $expiredGameInv);
        $this->assertSame(15000.0, (float) $expiredGameInv->first()->final_total);
    }

    public function test_edit_duration_while_running_unpaid_updates_total_expires_at_and_final_settlement(): void
    {
        $this->actingAs($this->kasir)->post(route('transaction.store'), [
            'nama' => 'Pelanggan Extend',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'duration_seconds' => 3600,
            'harga' => 11000,
            'total' => 10000,
            'action' => 'simpan',
        ])->assertRedirect(route('transaction.index', ['tab' => 'stations']));

        $tx = Transaction::where('nama', 'Pelanggan Extend')->firstOrFail();

        // Open edit form cleanly
        $this->actingAs($this->kasir)
            ->get(route('transaction.edit', $tx->id_transaksi))
            ->assertStatus(200);

        // Advance 20 minutes and extend to 3 hours (HourlyPrice preset: 27.000)
        Carbon::setTestNow(Carbon::parse('2026-11-16 10:20:00'));

        $updateResp = $this->actingAs($this->kasir)->put(route('transaction.update', $tx->id_transaksi), [
            'tipe_transaksi' => 'prepaid',
            'device_id' => $this->device->id,
            'jam_main' => 3,
        ]);
        $updateResp->assertRedirect(route('transaction.index'));

        $tx->refresh();
        $this->assertSame('unpaid', $tx->payment_status);
        $this->assertSame(Transaction::BILLING_RUNNING, $tx->billing_status);
        $this->assertSame(10800, $tx->duration_seconds);
        $this->assertSame(27000.0, (float) $tx->total);
        $this->assertSame(27000.0, $tx->resolvePaketRentalTotal());
        // Started 10:00, extended to 3h at 10:20 -> remaining 2h40m -> expires at 13:00:00
        $this->assertSame('2026-11-16 13:00:00', $tx->expires_at->format('Y-m-d H:i:s'));

        // Final payment settles at updated 27.000
        $this->actingAs($this->kasir)->post(route('transaction.processPayment', $tx->id_transaksi), [
            'payment_method' => 'tunai',
            'amount_paid' => 30000,
            'discount' => 0,
        ])->assertRedirect(route('transaction.print', ['id' => $tx->id_transaksi, 'action' => 'payment']));

        $invoice = Invoice::where('transaction_id', $tx->id_transaksi)->firstOrFail();
        $this->assertSame(27000.0, (float) $invoice->final_total);
        $this->assertSame(3000.0, (float) $invoice->change_amount);
    }

    public function test_berhenti_vs_selesai_distinction_and_owner_rbac_read_only_guard(): void
    {
        $deviceLostTime = $this->createStationWithHardware('PS5 2', $this->playstation, 'STATION-02', 'AA:BB:CC:DD:EE:02');
        $deviceAvailable = $this->createStationWithHardware('PS5 3', $this->playstation, 'STATION-03', 'AA:BB:CC:DD:EE:03');

        // Start running Simpan Paket on station 1
        $this->actingAs($this->kasir)->post(route('transaction.store'), [
            'nama' => 'Pelanggan Paket',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'duration_seconds' => 3600,
            'harga' => 11000,
            'total' => 10000,
            'action' => 'simpan',
        ])->assertRedirect();
        $paketTx = Transaction::where('nama', 'Pelanggan Paket')->firstOrFail();

        // Start running Lost Time on station 2
        $this->actingAs($this->kasir)->post(route('transaction.store'), [
            'nama' => 'Pelanggan Lost Time',
            'device_id' => $deviceLostTime->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'postpaid',
        ])->assertRedirect();
        $lostTimeTx = Transaction::where('nama', 'Pelanggan Lost Time')->firstOrFail();

        // Kasir station cards: Paket has Berhenti ONLY (no Selesai); Lost Time has BOTH Berhenti and Selesai
        $kasirDash = $this->actingAs($this->kasir)->get(route('transaction.index'));
        $kasirDash->assertStatus(200);
        $kasirHtml = $kasirDash->getContent();

        $paketCard = $this->extractStationCardHtml($kasirHtml, $this->device->id);
        $this->assertStringContainsString('js-open-berhenti', $paketCard);
        $this->assertStringNotContainsString('js-open-selesai', $paketCard);

        $lostTimeCard = $this->extractStationCardHtml($kasirHtml, $deviceLostTime->id);
        $this->assertStringContainsString('js-open-berhenti', $lostTimeCard);
        $this->assertStringContainsString('js-open-selesai', $lostTimeCard);
        $this->assertStringContainsString(route('transaction.end', $lostTimeTx->id_transaksi), $lostTimeCard);

        // Owner RBAC read-only guard on GET /transaction and all operational mutations
        $ownerDash = $this->actingAs($this->owner)->get(route('transaction.index'));
        $ownerDash->assertStatus(200);
        $ownerHtml = $ownerDash->getContent();
        $this->assertStringContainsString('Mode Pantau (Read-Only)', $ownerHtml);
        $this->assertStringNotContainsString('js-open-paket', $ownerHtml);
        $this->assertStringNotContainsString('js-start-lost-time', $ownerHtml);
        $this->assertStringNotContainsString('js-open-berhenti', $ownerHtml);
        $this->assertStringNotContainsString('js-open-selesai', $ownerHtml);
        $this->assertStringNotContainsString(route('transaction.showPayment', $paketTx->id_transaksi), $ownerHtml);
        $this->assertStringNotContainsString(route('transaction.edit', $paketTx->id_transaksi), $ownerHtml);

        $this->actingAs($this->owner)->postJson(route('transaction.store'), [
            'device_id' => $deviceAvailable->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'duration_seconds' => 3600,
            'harga' => 11000,
            'total' => 10000,
            'action' => 'simpan',
        ])->assertStatus(403);

        $this->actingAs($this->owner)->postJson(route('transaction.store'), [
            'device_id' => $deviceAvailable->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'duration_seconds' => 3600,
            'harga' => 11000,
            'total' => 10000,
            'action' => 'bayar',
        ])->assertStatus(403);

        $this->actingAs($this->owner)
            ->get(route('transaction.edit', $paketTx->id_transaksi))
            ->assertStatus(403);

        $this->actingAs($this->owner)
            ->put(route('transaction.update', $paketTx->id_transaksi), [
                'tipe_transaksi' => 'prepaid',
                'device_id' => $this->device->id,
                'jam_main' => 2,
            ])
            ->assertStatus(403);

        $this->actingAs($this->owner)
            ->post(route('transaction.processPayment', $paketTx->id_transaksi), [
                'payment_method' => 'tunai',
                'amount_paid' => 10000,
            ])
            ->assertStatus(403);

        $this->actingAs($this->owner)
            ->postJson(route('device.stop', $this->device->id))
            ->assertStatus(403);

        $this->actingAs($this->owner)
            ->postJson(route('transaction.end', $lostTimeTx->id_transaksi))
            ->assertStatus(403);

        $this->actingAs($this->owner)
            ->postJson(route('fnb.orders.store'), [
                'transaction_id' => $paketTx->id_transaksi,
                'action' => 'pay_now',
                'items' => [['fnb_id' => $this->fnbKopi->id, 'qty' => 1]],
            ])
            ->assertStatus(403);

        // Verify Berhenti (device.stop) stops Paket early without auto-paying, whereas Selesai (transaction.end) ends Lost Time & redirects to payment
        Carbon::setTestNow(Carbon::parse('2026-11-16 10:30:00'));

        $this->actingAs($this->kasir)
            ->postJson(route('device.stop', $this->device->id))
            ->assertStatus(200)
            ->assertJson(['success' => true, 'new_status' => 'Tersedia']);

        $paketTx->refresh();
        $this->assertSame(Transaction::BILLING_STOPPED_EARLY, $paketTx->billing_status);
        $this->assertSame('unpaid', $paketTx->payment_status);

        $endResp = $this->actingAs($this->kasir)
            ->post(route('transaction.end', $lostTimeTx->id_transaksi));
        $endResp->assertRedirect(route('transaction.showPayment', $lostTimeTx->id_transaksi));

        $lostTimeTx->refresh();
        $this->assertSame(Transaction::BILLING_COMPLETED, $lostTimeTx->billing_status);
        $this->assertSame('unpaid', $lostTimeTx->payment_status);
        $this->assertSame('Tersedia', $deviceLostTime->fresh()->status);
    }

    private function extractStationCardHtml(string $html, int $deviceId): string
    {
        $pattern = '/<article\b[^>]*id="station-card-' . $deviceId . '"[^>]*>.*?<\/article>/s';
        $matched = preg_match($pattern, $html, $matches);
        $this->assertSame(1, $matched, "Expected station card #station-card-{$deviceId} in HTML.");

        return $matches[0];
    }
}
