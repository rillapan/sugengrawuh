<?php

namespace Tests\Feature\Billing;

use App\Models\AutomationIntent;
use App\Models\CustomPackage;
use App\Models\Device;
use App\Models\Fnb;
use App\Models\FnbOrder;
use App\Models\FnbOrderItem;
use App\Models\HourlyPrice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Location;
use App\Models\PhysicalDevice;
use App\Models\Playstation;
use App\Models\StationDeviceMapping;
use App\Models\Transaction;
use App\Models\TransactionFnb;
use App\Models\TransactionPricingSegment;
use App\Models\TvAlertEvent;
use App\Models\User;
use App\Services\Billing\LostTimeBillingService;
use App\Services\Billing\PaketBillingService;
use App\Services\BillingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ActiveTransactionCategorySwitchTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $kasir;
    protected BillingService $billingService;
    protected LostTimeBillingService $lostTimeBillingService;
    protected PaketBillingService $paketBillingService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('kasir_ps_test', DB::connection()->getDatabaseName(), 'Must run on kasir_ps_test');

        Config::set('hardware.driver', 'mock');
        Config::set('hardware.real_execution_enabled', false);
        Config::set('hardware.simulation_enabled', true);
        Config::set('hardware.automation_enabled', true);
        Config::set('hardware.automation.enabled', true);
        Config::set('hardware.allowed_environments', ['local', 'testing']);
        Config::set('hardware.allowed_databases', ['kasir_ps_test']);

        Carbon::setTestNow(Carbon::parse('2026-11-22 10:00:00', config('app.timezone', 'Asia/Jakarta')));

        $this->admin = User::factory()->create([
            'name' => 'Admin Switch Test',
            'role' => 'admin',
            'email' => 'admin.catswitch@sugengrawuh.test',
        ]);

        $this->kasir = User::factory()->create([
            'name' => 'Kasir Switch Test',
            'role' => 'kasir',
            'email' => 'kasir.catswitch@sugengrawuh.test',
        ]);

        $this->billingService = app(BillingService::class);
        $this->lostTimeBillingService = app(LostTimeBillingService::class);
        $this->paketBillingService = app(PaketBillingService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Helper to create a multi-category station with active Shelly Relay and Samsung Display TV hardware mappings.
     *
     * @param string $name
     * @param array<int, Playstation> $categories
     * @param string $macSuffix
     * @param int $ipLastOctet
     * @return array{device: Device, relay: PhysicalDevice, tv: PhysicalDevice, relay_mapping: StationDeviceMapping, tv_mapping: StationDeviceMapping}
     */
    protected function createStationWithHardware(
        string $name,
        array $categories,
        string $macSuffix = '10',
        int $ipLastOctet = 110
    ): array {
        $primaryPs = $categories[0] ?? null;

        $device = Device::create([
            'nama' => $name,
            'playstation_id' => $primaryPs?->id,
            'status' => 'Tersedia',
            'session_generation' => 0,
        ]);

        if (!empty($categories)) {
            $device->playstations()->sync(collect($categories)->pluck('id')->all());
        }

        $relay = PhysicalDevice::create([
            'name' => "Relay {$name}",
            'category' => PhysicalDevice::CATEGORY_SMART_RELAY,
            'brand' => PhysicalDevice::BRAND_SHELLY,
            'model' => 'Shelly 1PM Mini Gen3',
            'mac_address' => "AA:BB:CC:DD:11:{$macSuffix}",
            'ip_address' => "192.168.1.{$ipLastOctet}",
            'is_active' => true,
            'connection_status' => PhysicalDevice::CONNECTION_ONLINE,
            'verified_capabilities' => ['SWITCH_ON', 'SWITCH_OFF', 'GET_STATUS'],
        ]);

        $relayMapping = StationDeviceMapping::create([
            'station_id' => $device->id,
            'physical_device_id' => $relay->id,
            'mapping_role' => StationDeviceMapping::ROLE_LIGHT_RELAY,
            'mapping_status' => StationDeviceMapping::STATUS_ACTIVE,
            'attached_by_user_id' => $this->admin->id,
            'attached_at' => now(),
        ]);

        $tvIpOctet = $ipLastOctet + 50;
        $tv = PhysicalDevice::create([
            'name' => "Samsung TV {$name}",
            'category' => PhysicalDevice::CATEGORY_SMART_TV,
            'brand' => PhysicalDevice::BRAND_SAMSUNG,
            'model' => 'Samsung Crystal UHD 43',
            'mac_address' => "AA:BB:CC:DD:22:{$macSuffix}",
            'ip_address' => "192.168.1.{$tvIpOctet}",
            'is_active' => true,
            'connection_status' => PhysicalDevice::CONNECTION_ONLINE,
            'pairing_status' => PhysicalDevice::PAIRING_PAIRED,
            'verified_capabilities' => ['SHOW_ALERT', 'VOLUME_CONTROL'],
        ]);

        $tvMapping = StationDeviceMapping::create([
            'station_id' => $device->id,
            'physical_device_id' => $tv->id,
            'mapping_role' => StationDeviceMapping::ROLE_DISPLAY_TV,
            'mapping_status' => StationDeviceMapping::STATUS_ACTIVE,
            'attached_by_user_id' => $this->admin->id,
            'attached_at' => now(),
        ]);

        return [
            'device' => $device->fresh(['playstations', 'playstation']),
            'relay' => $relay,
            'tv' => $tv,
            'relay_mapping' => $relayMapping,
            'tv_mapping' => $tvMapping,
        ];
    }

    /**
     * CS-01: Multi-category device [Billiard Pagi (25000), Billiard Sore (30000)].
     * Switch active transaction from Billiard Pagi to Billiard Sore.
     * Assert playstation_id, harga, and dynamic pricing match Billiard Sore.
     */
    public function test_cs_01_billiard_pagi_to_sore_updates_category_and_tariff(): void
    {
        $billiardPagi = Playstation::create(['nama' => 'Billiard Pagi', 'harga' => 25000]);
        $billiardSore = Playstation::create(['nama' => 'Billiard Sore', 'harga' => 30000]);

        ['device' => $device] = $this->createStationWithHardware('Meja Billiard 1', [$billiardPagi, $billiardSore], '01', 101);

        $tx = $this->billingService->startSession([
            'device_id' => $device->id,
            'playstation_id' => $billiardPagi->id,
            'nama' => 'Pelanggan Billiard',
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        $this->assertSame($billiardPagi->id, (int) $tx->playstation_id);
        $this->assertSame(25000.0, (float) $tx->harga);

        // Advance 30 minutes and switch to Billiard Sore (correction mode so full tariff follows Billiard Sore)
        Carbon::setTestNow(Carbon::parse('2026-11-22 10:30:00'));

        $response = $this->actingAs($this->kasir)->putJson(route('transaction.update', $tx->id_transaksi), [
            'device_id' => $device->id,
            'playstation_id' => $billiardSore->id,
            'tipe_transaksi' => 'postpaid',
            'pricing_mode' => 'correction',
        ]);

        $response->assertOk()->assertJson(['success' => true]);

        $tx->refresh();
        $this->assertSame($billiardSore->id, (int) $tx->playstation_id);
        $this->assertSame(30000.0, (float) $tx->harga);
        $this->assertSame('Billiard Sore', $tx->resolveCurrentPlaystation()?->nama);

        // At 10:30 (30 min @ 30,000/hr), dynamic summary must be 15,000 (not 12,500)
        $summary = $this->billingService->summarizeTransactionBilling($tx, Carbon::now());
        $this->assertSame(15000.0, $summary['rental_subtotal']);
        $this->assertSame(15000.0, $summary['payable_total']);
    }

    /**
     * CS-02: Multi-category VIP device [Karaoke (30000), PS VIP (25000)].
     * Switch active transaction from Karaoke to PS VIP and verify price calculation follows PS VIP.
     */
    public function test_cs_02_karaoke_to_ps_vip_updates_price_to_target_category(): void
    {
        $karaoke = Playstation::create(['nama' => 'Karaoke', 'harga' => 30000]);
        $psVip = Playstation::create(['nama' => 'PS VIP', 'harga' => 25000]);

        ['device' => $device] = $this->createStationWithHardware('VIP Room 1', [$karaoke, $psVip], '02', 102);

        $tx = $this->billingService->startSession([
            'device_id' => $device->id,
            'playstation_id' => $karaoke->id,
            'nama' => 'Pelanggan VIP',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 2,
            'duration_seconds' => 7200,
        ], $this->kasir->id);

        $this->assertSame($karaoke->id, (int) $tx->playstation_id);
        $this->assertSame(30000.0, (float) $tx->harga);
        $this->assertSame(60000.0, (float) $tx->total);

        // Switch from Karaoke (30k) to PS VIP (25k) in correction mode
        $this->actingAs($this->kasir)->put(route('transaction.update', $tx->id_transaksi), [
            'device_id' => $device->id,
            'playstation_id' => $psVip->id,
            'tipe_transaksi' => 'prepaid',
            'pricing_mode' => 'correction',
            'duration_seconds' => 7200,
            'jam_main' => 2,
        ])->assertRedirect(route('transaction.index'));

        $tx->refresh();
        $this->assertSame($psVip->id, (int) $tx->playstation_id);
        $this->assertSame(25000.0, (float) $tx->harga);
        $this->assertSame(50000.0, (float) $tx->total);

        $summary = $this->billingService->summarizeTransactionBilling($tx, Carbon::now());
        $this->assertSame(50000.0, $summary['rental_subtotal']);
        $this->assertSame(50000.0, $summary['payable_total']);
    }

    /**
     * CS-03: Multi-category console station [PS3 (10000), PS4 (15000)].
     * Start 2-hour Timer at 10:00:00 (expires_at = 12:00:00).
     * Advance clock to 10:45:00, switch PS3 -> PS4.
     * Assert started_at stays 10:00:00, expires_at stays 12:00:00 (45m elapsed, 1h15m remaining — countdown never reset!).
     */
    public function test_cs_03_ps3_to_ps4_switches_category_without_resetting_timer(): void
    {
        $ps3 = Playstation::create(['nama' => 'PS3', 'harga' => 10000]);
        $ps4 = Playstation::create(['nama' => 'PS4', 'harga' => 15000]);

        ['device' => $device] = $this->createStationWithHardware('Console Station 3', [$ps3, $ps4], '03', 103);

        $tx = $this->billingService->startSession([
            'device_id' => $device->id,
            'playstation_id' => $ps3->id,
            'nama' => 'Pelanggan Console',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 2,
            'duration_seconds' => 7200,
        ], $this->kasir->id);

        $this->assertSame('2026-11-22 10:00:00', $tx->started_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-22 12:00:00', $tx->expires_at->format('Y-m-d H:i:s'));

        // Advance clock to 10:45:00 (45m elapsed, 1h15m = 4500s remaining)
        Carbon::setTestNow(Carbon::parse('2026-11-22 10:45:00'));

        $previewRes = $this->actingAs($this->kasir)->postJson(route('transaction.editPreview', $tx->id_transaksi), [
            'device_id' => $device->id,
            'playstation_id' => $ps4->id,
            'tipe_transaksi' => 'prepaid',
            'pricing_mode' => 'from_now',
            'duration_seconds' => 7200,
        ]);

        $previewRes->assertOk()
            ->assertJson([
                'valid' => true,
                'category_changed' => true,
                'duration_changed' => false,
                'elapsed_seconds' => 2700,
                'remaining_seconds' => 4500,
                'total_duration_seconds' => 7200,
            ]);

        $this->actingAs($this->kasir)->put(route('transaction.update', $tx->id_transaksi), [
            'device_id' => $device->id,
            'playstation_id' => $ps4->id,
            'tipe_transaksi' => 'prepaid',
            'pricing_mode' => 'from_now',
            'duration_seconds' => 7200,
            'jam_main' => 2,
        ])->assertRedirect(route('transaction.index'));

        $tx->refresh();
        $this->assertSame($ps4->id, (int) $tx->playstation_id);
        $this->assertSame('2026-11-22 10:00:00', $tx->started_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-22 12:00:00', $tx->expires_at->format('Y-m-d H:i:s'));
        $this->assertSame(7200, (int) $tx->duration_seconds);
        $this->assertSame(2700, Carbon::now()->diffInSeconds($tx->started_at));
        $this->assertSame(4500, Carbon::now()->diffInSeconds($tx->expires_at, false));
    }

    /**
     * CS-04: Start Lost Time at 10:00:00 on Billiard Pagi (25000/jam).
     * At 10:30:00, switch to Billiard Sore (30000/jam) with pricing_mode = 'correction' (Mode A).
     * End/checkout at 11:00:00 (60 min total).
     * Assert 0 segments remain and total rental is 30000 (entire 60 min at Billiard Sore rate).
     */
    public function test_cs_04_lost_time_mode_a_correction_recalculates_entire_duration_with_new_tariff(): void
    {
        $billiardPagi = Playstation::create(['nama' => 'Billiard Pagi', 'harga' => 25000]);
        $billiardSore = Playstation::create(['nama' => 'Billiard Sore', 'harga' => 30000]);

        ['device' => $device] = $this->createStationWithHardware('Meja Billiard 2', [$billiardPagi, $billiardSore], '04', 104);

        $tx = $this->billingService->startSession([
            'device_id' => $device->id,
            'playstation_id' => $billiardPagi->id,
            'nama' => 'Pelanggan Mode A Lost Time',
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        Carbon::setTestNow(Carbon::parse('2026-11-22 10:30:00'));

        $this->actingAs($this->kasir)->put(route('transaction.update', $tx->id_transaksi), [
            'device_id' => $device->id,
            'playstation_id' => $billiardSore->id,
            'tipe_transaksi' => 'postpaid',
            'pricing_mode' => 'correction',
        ])->assertRedirect(route('transaction.index'));

        $this->assertSame(0, TransactionPricingSegment::where('transaction_id', $tx->id_transaksi)->count());

        // Advance to 11:00:00 (60 min total) and checkout
        Carbon::setTestNow(Carbon::parse('2026-11-22 11:00:00'));

        $invoice = $this->lostTimeBillingService->checkoutLostTime($tx->id_transaksi, [
            'payment_method' => 'tunai',
            'amount_paid' => 30000,
            'cashier_user_id' => $this->kasir->id,
        ]);

        $tx->refresh();
        $this->assertSame(0, TransactionPricingSegment::where('transaction_id', $tx->id_transaksi)->count());
        $this->assertSame($billiardSore->id, (int) $tx->playstation_id);
        $this->assertSame(30000.0, (float) $tx->total);
        $this->assertSame(30000.0, (float) $invoice->subtotal);
        $this->assertSame(30000.0, (float) $invoice->final_total);
    }

    /**
     * CS-05: Lost Time Mode B (from_now) calculates old and new segments separately without repeated rounding.
     * - Part A: Start Lost Time at 10:00:00 on Karaoke (30000/jam). At 10:30:00 (30 min), switch to PS VIP (25000/jam)
     *   with pricing_mode = 'from_now'. At 11:00:00 (another 30 min), end/checkout.
     *   Assert Segment 1 = 30m @ 30k (15000), Segment 2 = 30m @ 25k (12500), total rental = 27500.
     * - Part B (Anti-repeated-rounding check): 20 min @ 20k/jam (6666.6667) + 40 min @ 20k/jam (13333.3333)
     *   across two categories with 20k rate -> raw sum 20000.00 -> single final rounding gives 20000 (NOT 7000 + 13500 = 20500!).
     */
    public function test_cs_05_lost_time_mode_b_from_now_calculates_old_and_new_segments_separately_without_repeated_rounding(): void
    {
        // Part A: 30m @ 30k + 30m @ 25k = 15,000 + 12,500 = 27,500
        $karaoke = Playstation::create(['nama' => 'Karaoke', 'harga' => 30000]);
        $psVip = Playstation::create(['nama' => 'PS VIP', 'harga' => 25000]);

        ['device' => $deviceA] = $this->createStationWithHardware('VIP Room A', [$karaoke, $psVip], '05', 105);

        $txA = $this->billingService->startSession([
            'device_id' => $deviceA->id,
            'playstation_id' => $karaoke->id,
            'nama' => 'Pelanggan Mode B Lost Time',
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        Carbon::setTestNow(Carbon::parse('2026-11-22 10:30:00'));

        $this->actingAs($this->kasir)->put(route('transaction.update', $txA->id_transaksi), [
            'device_id' => $deviceA->id,
            'playstation_id' => $psVip->id,
            'tipe_transaksi' => 'postpaid',
            'pricing_mode' => 'from_now',
        ])->assertRedirect(route('transaction.index'));

        $segmentsMid = TransactionPricingSegment::where('transaction_id', $txA->id_transaksi)
            ->orderBy('segment_order')
            ->get();
        $this->assertCount(2, $segmentsMid);
        $this->assertSame(1800, (int) $segmentsMid[0]->duration_seconds);
        $this->assertSame(15000.0, (float) $segmentsMid[0]->raw_amount);
        $this->assertNull($segmentsMid[1]->ended_at);

        // Advance to 11:00:00 (another 30m) and end session
        Carbon::setTestNow(Carbon::parse('2026-11-22 11:00:00'));

        $this->actingAs($this->kasir)->post(route('transaction.end', $txA->id_transaksi));

        $txA->refresh();
        $this->assertSame(27500.0, (float) $txA->total);

        $segmentsFinal = TransactionPricingSegment::where('transaction_id', $txA->id_transaksi)
            ->orderBy('segment_order')
            ->get();
        $this->assertCount(2, $segmentsFinal);
        $this->assertSame(1800, (int) $segmentsFinal[0]->duration_seconds);
        $this->assertSame(15000.0, (float) $segmentsFinal[0]->billed_amount);
        $this->assertSame(1800, (int) $segmentsFinal[1]->duration_seconds);
        $this->assertSame(12500.0, (float) $segmentsFinal[1]->billed_amount);

        // Part B: Anti-repeated-rounding check (20 min @ 20k + 40 min @ 20k = 6666.6667 + 13333.3333 = 20000.00 -> 20000)
        Carbon::setTestNow(Carbon::parse('2026-11-22 12:00:00'));
        $cat20A = Playstation::create(['nama' => 'Kategori 20k A', 'harga' => 20000]);
        $cat20B = Playstation::create(['nama' => 'Kategori 20k B', 'harga' => 20000]);
        ['device' => $deviceB] = $this->createStationWithHardware('VIP Room B', [$cat20A, $cat20B], '06', 106);

        $txB = $this->billingService->startSession([
            'device_id' => $deviceB->id,
            'playstation_id' => $cat20A->id,
            'nama' => 'Pelanggan Anti Rounding',
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        // At 12:20:00 (20 min elapsed), switch to Cat 20k B (Mode B)
        Carbon::setTestNow(Carbon::parse('2026-11-22 12:20:00'));
        $this->actingAs($this->kasir)->put(route('transaction.update', $txB->id_transaksi), [
            'device_id' => $deviceB->id,
            'playstation_id' => $cat20B->id,
            'tipe_transaksi' => 'postpaid',
            'pricing_mode' => 'from_now',
        ])->assertRedirect(route('transaction.index'));

        // At 13:00:00 (40 min on Cat 20k B, total 60 min), checkout
        Carbon::setTestNow(Carbon::parse('2026-11-22 13:00:00'));
        $breakdownB = $this->billingService->finalizeLostTimeSegments($txB->fresh(), Carbon::now());

        $this->assertEqualsWithDelta(20000.0, $breakdownB['raw_total'], 0.01);
        $this->assertSame(20000, (int) $breakdownB['rounded_total'], 'Single final rounding must yield 20000, not 7000 + 13500 = 20500');

        $allocatedSum = collect($breakdownB['segments'])->sum('allocated_billed_amount');
        $this->assertEqualsWithDelta(20000.0, $allocatedSum, 0.01);
    }

    /**
     * CS-06: Category A (1h=15k, 2h=30k) -> Category B (base 25000/jam, 2h HourlyPrice preset = 45000).
     * Start 2-hour Timer on Category A (30000).
     * Switch to Category B with pricing_mode = 'correction'.
     * Assert rental total is 45000 (preset), NOT 50000 (25000 * 2).
     */
    public function test_cs_06_timer_mode_a_correction_uses_new_category_hourly_preset(): void
    {
        $catA = Playstation::create(['nama' => 'PS4 Reguler', 'harga' => 15000]);
        $catB = Playstation::create(['nama' => 'PS5 VIP Preset', 'harga' => 25000]);

        HourlyPrice::create([
            'playstation_id' => $catB->id,
            'hour' => 2,
            'duration_seconds' => 7200,
            'price' => 45000,
        ]);

        ['device' => $device] = $this->createStationWithHardware('Station Preset 1', [$catA, $catB], '07', 107);

        $tx = $this->billingService->startSession([
            'device_id' => $device->id,
            'playstation_id' => $catA->id,
            'nama' => 'Pelanggan Preset Mode A',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 2,
            'duration_seconds' => 7200,
        ], $this->kasir->id);

        $this->assertSame(30000.0, (float) $tx->total);

        Carbon::setTestNow(Carbon::parse('2026-11-22 10:20:00'));

        $this->actingAs($this->kasir)->put(route('transaction.update', $tx->id_transaksi), [
            'device_id' => $device->id,
            'playstation_id' => $catB->id,
            'tipe_transaksi' => 'prepaid',
            'pricing_mode' => 'correction',
            'duration_seconds' => 7200,
            'jam_main' => 2,
        ])->assertRedirect(route('transaction.index'));

        $tx->refresh();
        $this->assertSame($catB->id, (int) $tx->playstation_id);
        $this->assertSame(45000.0, (float) $tx->total, 'Must use 2h HourlyPrice preset 45000, not 25000 * 2 = 50000');
        $this->assertSame(45000.0, $tx->resolvePaketRentalTotal());
    }

    /**
     * CS-07: Start 2-hour Timer (7200s, 10:00–12:00) on Karaoke (30000/jam).
     * At 10:30 (1800s elapsed, 5400s remaining), switch to PS VIP (25000/jam) with pricing_mode = 'from_now'.
     * Assert Seg 1 = 1800s (15000), Seg 2 = 5400s (37500), sum of segment durations = 7200s (exact 2h, no double charge), total rental = 52500.
     * Also test a second switch at 11:00 (1800s + 1800s + 3600s = 7200s).
     */
    public function test_cs_07_timer_mode_b_from_now_partitions_duration_without_double_charge(): void
    {
        $karaoke = Playstation::create(['nama' => 'Karaoke', 'harga' => 30000]);
        $psVip = Playstation::create(['nama' => 'PS VIP', 'harga' => 25000]);
        $ps4Vip = Playstation::create(['nama' => 'PS4 VIP', 'harga' => 20000]);

        ['device' => $device] = $this->createStationWithHardware('VIP Room Partition', [$karaoke, $psVip, $ps4Vip], '08', 108);

        $tx = $this->billingService->startSession([
            'device_id' => $device->id,
            'playstation_id' => $karaoke->id,
            'nama' => 'Pelanggan Timer Partition',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 2,
            'duration_seconds' => 7200,
        ], $this->kasir->id);

        // First switch at 10:30 (1800s elapsed, 5400s remaining)
        Carbon::setTestNow(Carbon::parse('2026-11-22 10:30:00'));

        $this->actingAs($this->kasir)->put(route('transaction.update', $tx->id_transaksi), [
            'device_id' => $device->id,
            'playstation_id' => $psVip->id,
            'tipe_transaksi' => 'prepaid',
            'pricing_mode' => 'from_now',
            'duration_seconds' => 7200,
            'jam_main' => 2,
        ])->assertRedirect(route('transaction.index'));

        $tx->refresh();
        $segmentsAfterFirst = TransactionPricingSegment::where('transaction_id', $tx->id_transaksi)
            ->orderBy('segment_order')
            ->get();

        $this->assertCount(2, $segmentsAfterFirst);
        $this->assertSame(1800, (int) $segmentsAfterFirst[0]->duration_seconds);
        $this->assertSame(15000.0, (float) $segmentsAfterFirst[0]->billed_amount);
        $this->assertSame(5400, (int) $segmentsAfterFirst[1]->duration_seconds);
        $this->assertSame(37500.0, (float) $segmentsAfterFirst[1]->billed_amount);
        $this->assertSame(7200, (int) $segmentsAfterFirst->sum('duration_seconds'));
        $this->assertSame(52500.0, (float) $tx->total);
        $this->assertSame(52500.0, $tx->resolvePaketRentalTotal());

        // Second switch at 11:00 to PS4 VIP (20,000/jam) -> 1800s @ 30k (15000) + 1800s @ 25k (12500) + 3600s @ 20k (20000) = 47500
        Carbon::setTestNow(Carbon::parse('2026-11-22 11:00:00'));

        $this->actingAs($this->kasir)->put(route('transaction.update', $tx->id_transaksi), [
            'device_id' => $device->id,
            'playstation_id' => $ps4Vip->id,
            'tipe_transaksi' => 'prepaid',
            'pricing_mode' => 'from_now',
            'duration_seconds' => 7200,
            'jam_main' => 2,
        ])->assertRedirect(route('transaction.index'));

        $tx->refresh();
        $segmentsAfterSecond = TransactionPricingSegment::where('transaction_id', $tx->id_transaksi)
            ->orderBy('segment_order')
            ->get();

        $this->assertCount(3, $segmentsAfterSecond);
        $this->assertSame([1800, 1800, 3600], $segmentsAfterSecond->pluck('duration_seconds')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(7200, (int) $segmentsAfterSecond->sum('duration_seconds'));
        $this->assertSame([15000.0, 12500.0, 20000.0], $segmentsAfterSecond->pluck('billed_amount')->map(fn ($v) => (float) $v)->all());
        $this->assertSame(47500.0, (float) $tx->total);
        $this->assertSame('2026-11-22 12:00:00', $tx->expires_at->format('Y-m-d H:i:s'));
    }

    /**
     * CS-08: Station with active Shelly relay & Display TV mapping, plus pending scheduled BILLING_EXPIRED OFF intent
     * and pending TvAlertEvent. Record session_generation and AutomationIntent count/statuses before switch.
     * Perform category-only switch (both Mode A and Mode B).
     * Assert device->session_generation and tx->session_generation are unchanged, no new AutomationIntent rows created,
     * existing BILLING_EXPIRED OFF intent remains PENDING (not cancelled/recreated), and pending TvAlertEvent is not cancelled.
     */
    public function test_cs_08_same_device_category_switch_does_not_trigger_hardware_on_off_or_bump_generation(): void
    {
        $ps3 = Playstation::create(['nama' => 'PS3', 'harga' => 10000]);
        $ps4 = Playstation::create(['nama' => 'PS4', 'harga' => 15000]);

        ['device' => $device, 'tv' => $tv, 'tv_mapping' => $tvMapping] = $this->createStationWithHardware(
            'Hardware Safe Station',
            [$ps3, $ps4],
            '09',
            109
        );

        $tx = $this->billingService->startSession([
            'device_id' => $device->id,
            'playstation_id' => $ps3->id,
            'nama' => 'Pelanggan Hardware Safe',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 2,
            'duration_seconds' => 7200,
        ], $this->kasir->id);

        $device->refresh();
        $tx->refresh();

        $pendingExpiredIntent = AutomationIntent::where('transaction_id', $tx->id_transaksi)
            ->where('event_type', AutomationIntent::EVENT_BILLING_EXPIRED)
            ->where('desired_state', AutomationIntent::STATE_OFF)
            ->firstOrFail();

        $this->assertSame(AutomationIntent::STATUS_PENDING, $pendingExpiredIntent->status);

        $tvAlert = TvAlertEvent::create([
            'station_id' => $device->id,
            'station_name' => $device->nama,
            'transaction_id' => $tx->id_transaksi,
            'physical_device_id' => $tv->id,
            'station_device_mapping_id' => $tvMapping->id,
            'session_generation' => $tx->session_generation,
            'actor_user_id' => $this->admin->id,
            'actor_role' => 'admin',
            'event_type' => TvAlertEvent::TYPE_BILLING_ENDING_SOON,
            'command' => TvAlertEvent::CMD_SHOW_ALERT,
            'message_preset' => TvAlertEvent::PRESET_REMAINING_10_MIN,
            'message_title' => 'WAKTU BERMAIN TERSISA 10 MENIT!',
            'message_body' => 'Silakan hubungi kasir.',
            'display_duration_seconds' => 15,
            'status' => TvAlertEvent::STATUS_PENDING,
            'scheduled_at' => $tx->expires_at->copy()->subMinutes(10),
        ]);

        $genBefore = (int) $device->session_generation;
        $txGenBefore = (int) $tx->session_generation;
        $intentCountBefore = AutomationIntent::where('transaction_id', $tx->id_transaksi)->count();

        // Advance 30 minutes and switch PS3 -> PS4 using Mode B (from_now)
        Carbon::setTestNow(Carbon::parse('2026-11-22 10:30:00'));

        $this->actingAs($this->kasir)->put(route('transaction.update', $tx->id_transaksi), [
            'device_id' => $device->id,
            'playstation_id' => $ps4->id,
            'tipe_transaksi' => 'prepaid',
            'pricing_mode' => 'from_now',
            'duration_seconds' => 7200,
            'jam_main' => 2,
        ])->assertRedirect(route('transaction.index'));

        $device->refresh();
        $tx->refresh();
        $pendingExpiredIntent->refresh();
        $tvAlert->refresh();

        $this->assertSame($genBefore, (int) $device->session_generation);
        $this->assertSame($txGenBefore, (int) $tx->session_generation);
        $this->assertSame($intentCountBefore, AutomationIntent::where('transaction_id', $tx->id_transaksi)->count());
        $this->assertSame(AutomationIntent::STATUS_PENDING, $pendingExpiredIntent->status);
        $this->assertSame(TvAlertEvent::STATUS_PENDING, $tvAlert->status);

        // Switch back PS4 -> PS3 using Mode A (correction)
        Carbon::setTestNow(Carbon::parse('2026-11-22 10:45:00'));

        $this->actingAs($this->kasir)->put(route('transaction.update', $tx->id_transaksi), [
            'device_id' => $device->id,
            'playstation_id' => $ps3->id,
            'tipe_transaksi' => 'prepaid',
            'pricing_mode' => 'correction',
            'duration_seconds' => 7200,
            'jam_main' => 2,
        ])->assertRedirect(route('transaction.index'));

        $device->refresh();
        $tx->refresh();
        $pendingExpiredIntent->refresh();
        $tvAlert->refresh();

        $this->assertSame($genBefore, (int) $device->session_generation);
        $this->assertSame($txGenBefore, (int) $tx->session_generation);
        $this->assertSame($intentCountBefore, AutomationIntent::where('transaction_id', $tx->id_transaksi)->count());
        $this->assertSame(AutomationIntent::STATUS_PENDING, $pendingExpiredIntent->status);
        $this->assertSame(TvAlertEvent::STATUS_PENDING, $tvAlert->status);
    }

    /**
     * CS-09: Assert id_transaksi, device_id, started_at, lost_time_start, waktu_mulai, expires_at, waktu_Selesai,
     * billing_status (RUNNING), payment_status (unpaid), and session_token remain identical before and after
     * category-only switch, and Transaction::count() is unchanged (no new transaction created).
     */
    public function test_cs_09_category_only_switch_preserves_session_identity_timestamps_and_status(): void
    {
        $cat1 = Playstation::create(['nama' => 'Billiard Pagi', 'harga' => 25000]);
        $cat2 = Playstation::create(['nama' => 'Billiard Sore', 'harga' => 30000]);

        ['device' => $device] = $this->createStationWithHardware('Identity Station', [$cat1, $cat2], '10', 110);

        $tx = $this->billingService->startSession([
            'device_id' => $device->id,
            'playstation_id' => $cat1->id,
            'nama' => 'Pelanggan Identity',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 2,
            'duration_seconds' => 7200,
        ], $this->kasir->id);

        $tx->update(['lost_time_start' => $tx->started_at]);
        $tx->refresh();

        $txCountBefore = Transaction::count();
        $snapshotBefore = [
            'id_transaksi' => (int) $tx->id_transaksi,
            'device_id' => (int) $tx->device_id,
            'started_at' => $tx->started_at?->toIso8601String(),
            'lost_time_start' => $tx->lost_time_start?->toIso8601String(),
            'waktu_mulai' => $tx->waktu_mulai,
            'expires_at' => $tx->expires_at?->toIso8601String(),
            'waktu_Selesai' => $tx->waktu_Selesai,
            'billing_status' => $tx->billing_status,
            'payment_status' => $tx->payment_status,
            'session_token' => $tx->session_token,
        ];

        Carbon::setTestNow(Carbon::parse('2026-11-22 10:25:00'));

        $this->actingAs($this->kasir)->put(route('transaction.update', $tx->id_transaksi), [
            'device_id' => $device->id,
            'playstation_id' => $cat2->id,
            'tipe_transaksi' => 'prepaid',
            'pricing_mode' => 'from_now',
            'duration_seconds' => 7200,
            'jam_main' => 2,
        ])->assertRedirect(route('transaction.index'));

        $tx->refresh();

        $this->assertSame($txCountBefore, Transaction::count());
        $this->assertSame($snapshotBefore['id_transaksi'], (int) $tx->id_transaksi);
        $this->assertSame($snapshotBefore['device_id'], (int) $tx->device_id);
        $this->assertSame($snapshotBefore['started_at'], $tx->started_at?->toIso8601String());
        $this->assertSame($snapshotBefore['lost_time_start'], $tx->lost_time_start?->toIso8601String());
        $this->assertSame($snapshotBefore['waktu_mulai'], $tx->waktu_mulai);
        $this->assertSame($snapshotBefore['expires_at'], $tx->expires_at?->toIso8601String());
        $this->assertSame($snapshotBefore['waktu_Selesai'], $tx->waktu_Selesai);
        $this->assertSame(Transaction::BILLING_RUNNING, $tx->billing_status);
        $this->assertSame('unpaid', $tx->payment_status);
        $this->assertSame($snapshotBefore['session_token'], $tx->session_token);
        $this->assertNotEmpty($tx->session_token);
    }

    /**
     * CS-10: Unrelated category is rejected and detached category shows validation warning.
     * - Attempt PUT /transaction/{id} with a playstation_id not attached to the device -> rejected
     *   (422 JSON / redirect with gagal and validation error on playstation_id), DB transaction unchanged.
     * - Also detach current category from device_playstation pivot and request GET /transaction/{id}/edit ->
     *   assert warning banner is rendered and current category is NOT silently replaced by another category (BR-02).
     */
    public function test_cs_10_unrelated_category_is_rejected_and_detached_category_shows_validation_warning(): void
    {
        $ps3 = Playstation::create(['nama' => 'PS3', 'harga' => 10000]);
        $ps4 = Playstation::create(['nama' => 'PS4', 'harga' => 15000]);
        $billiardUnrelated = Playstation::create(['nama' => 'Billiard VIP Unrelated', 'harga' => 40000]);

        ['device' => $device] = $this->createStationWithHardware('Station Validasi', [$ps3, $ps4], '11', 111);

        $tx = $this->billingService->startSession([
            'device_id' => $device->id,
            'playstation_id' => $ps3->id,
            'nama' => 'Pelanggan Validasi',
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        // 1. JSON PUT with unrelated category -> 422
        $jsonRes = $this->actingAs($this->kasir)->putJson(route('transaction.update', $tx->id_transaksi), [
            'device_id' => $device->id,
            'playstation_id' => $billiardUnrelated->id,
            'tipe_transaksi' => 'postpaid',
            'pricing_mode' => 'from_now',
        ]);
        $jsonRes->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error_code' => 'invalid_category',
            ]);

        // 2. Web PUT with unrelated category -> redirect with gagal flash & errors on playstation_id
        $webRes = $this->actingAs($this->kasir)
            ->from(route('transaction.edit', $tx->id_transaksi))
            ->put(route('transaction.update', $tx->id_transaksi), [
                'device_id' => $device->id,
                'playstation_id' => $billiardUnrelated->id,
                'tipe_transaksi' => 'postpaid',
                'pricing_mode' => 'from_now',
            ]);
        $webRes->assertRedirect(route('transaction.edit', $tx->id_transaksi));
        $webRes->assertSessionHas('gagal');
        $webRes->assertSessionHasErrors(['playstation_id']);

        $tx->refresh();
        $this->assertSame($ps3->id, (int) $tx->playstation_id);
        $this->assertSame(10000.0, (float) $tx->harga);

        // 3. Detach PS3 from device_playstation pivot and update device.playstation_id to PS4
        $device->playstations()->detach($ps3->id);
        $device->update(['playstation_id' => $ps4->id]);

        $editRes = $this->actingAs($this->kasir)->get(route('transaction.edit', $tx->id_transaksi));
        $editRes->assertOk();
        $editRes->assertSee('id="category_unavailable_alert"', false);
        $editRes->assertSee('Kategori permainan saat ini tidak lagi tersedia pada konfigurasi perangkat ini', false);
        $editRes->assertSee('-- Pilih Kategori Permainan Valid --', false);

        // Ensure current category in DB was NOT silently replaced by PS4
        $tx->refresh();
        $this->assertSame($ps3->id, (int) $tx->playstation_id);
    }

    /**
     * CS-11: Paid or settled transaction rejects category switch and financial mutation.
     * Settled paid transaction (with Invoice & InvoiceItem): both GET /transaction/{id}/edit and
     * PUT /transaction/{id} (and POST /transaction/{id}/edit-preview) reject the edit.
     * Assert playstation_id, harga, total, Invoice::count(), and Invoice->final_total remain completely untouched.
     */
    public function test_cs_11_paid_or_settled_transaction_rejects_category_switch_and_financial_mutation(): void
    {
        $ps3 = Playstation::create(['nama' => 'PS3', 'harga' => 10000]);
        $ps4 = Playstation::create(['nama' => 'PS4', 'harga' => 15000]);

        ['device' => $device] = $this->createStationWithHardware('Station Settled', [$ps3, $ps4], '12', 112);

        $tx = $this->billingService->startSession([
            'device_id' => $device->id,
            'playstation_id' => $ps3->id,
            'nama' => 'Pelanggan Paid',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 2,
            'duration_seconds' => 7200,
        ], $this->kasir->id);

        $invoice = $this->paketBillingService->activatePaidPaket($tx, [
            'payment_method' => 'tunai',
            'amount_paid' => 20000,
            'cashier_user_id' => $this->kasir->id,
        ]);

        $tx->refresh();
        $this->assertSame('paid', $tx->payment_status);
        $invoiceCountBefore = Invoice::count();

        // 1. GET /transaction/{id}/edit redirects with gagal
        $this->actingAs($this->kasir)
            ->get(route('transaction.edit', $tx->id_transaksi))
            ->assertRedirect(route('transaction.index'))
            ->assertSessionHas('gagal');

        // 2. POST /transaction/{id}/edit-preview returns 422 payment_locked
        $this->actingAs($this->kasir)
            ->postJson(route('transaction.editPreview', $tx->id_transaksi), [
                'device_id' => $device->id,
                'playstation_id' => $ps4->id,
                'tipe_transaksi' => 'prepaid',
                'pricing_mode' => 'from_now',
            ])
            ->assertStatus(422)
            ->assertJson([
                'valid' => false,
                'error_code' => 'payment_locked',
            ]);

        // 3. PUT /transaction/{id} rejects edit
        $this->actingAs($this->kasir)
            ->putJson(route('transaction.update', $tx->id_transaksi), [
                'device_id' => $device->id,
                'playstation_id' => $ps4->id,
                'tipe_transaksi' => 'prepaid',
                'pricing_mode' => 'from_now',
                'duration_seconds' => 7200,
            ])
            ->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error_code' => 'payment_locked',
            ]);

        $tx->refresh();
        $invoice->refresh();
        $this->assertSame($ps3->id, (int) $tx->playstation_id);
        $this->assertSame(10000.0, (float) $tx->harga);
        $this->assertSame(20000.0, (float) $tx->total);
        $this->assertSame($invoiceCountBefore, Invoice::count());
        $this->assertSame(20000.0, (float) $invoice->final_total);
    }

    /**
     * CS-12: Existing FnB items and orders are preserved without loss or double counting.
     * Attach a legacy TransactionFnb item (2 x 8000 = 16000), an unpaid FnbOrder (1 x 12000 = 12000),
     * and an already-paid FnbOrder (1 x 10000 = 10000, settled via TYPE_FNB_STANDALONE).
     * Switch category on the running transaction.
     * Assert FnB rows are intact, paid FnB is not re-billed, unpaid FnB (28000) is included once in payable total
     * (rental + 28000), and checkout invoice has exact rental + unpaid FnB items.
     */
    public function test_cs_12_existing_fnb_items_and_orders_are_preserved_without_loss_or_double_counting(): void
    {
        $karaoke = Playstation::create(['nama' => 'Karaoke', 'harga' => 30000]);
        $psVip = Playstation::create(['nama' => 'PS VIP', 'harga' => 25000]);

        ['device' => $device] = $this->createStationWithHardware('VIP FnB Room', [$karaoke, $psVip], '13', 113);

        $location = Location::create([
            'name' => 'VIP FnB Room Loc',
            'code' => 'LOC-VIP-FNB',
            'type' => 'station',
            'device_id' => $device->id,
            'is_active' => true,
        ]);

        $fnbMie = Fnb::create(['nama' => 'Mie Goreng', 'harga_beli' => 4000, 'harga_jual' => 8000, 'stok' => 50]);
        $fnbKentang = Fnb::create(['nama' => 'Kentang Goreng', 'harga_beli' => 6000, 'harga_jual' => 12000, 'stok' => 50]);
        $fnbEsTeh = Fnb::create(['nama' => 'Es Teh Jumbo', 'harga_beli' => 3000, 'harga_jual' => 10000, 'stok' => 50]);

        $tx = $this->billingService->startSession([
            'device_id' => $device->id,
            'playstation_id' => $karaoke->id,
            'nama' => 'Pelanggan FnB Mix',
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        // 1. Legacy TransactionFnb: 2 x 8000 = 16000 (unpaid)
        TransactionFnb::create([
            'transaction_id' => $tx->id_transaksi,
            'fnb_id' => $fnbMie->id,
            'qty' => 2,
            'harga_beli' => 4000,
            'harga_jual' => 8000,
        ]);

        // 2. Unpaid FnbOrder: 1 x 12000 = 12000
        $unpaidOrder = FnbOrder::create([
            'order_number' => 'ORD-CS12-UNPAID',
            'source' => FnbOrder::SOURCE_QR,
            'location_id' => $location->id,
            'transaction_id' => $tx->id_transaksi,
            'customer_name' => $tx->nama,
            'status' => FnbOrder::STATUS_ACCEPTED,
            'payment_status' => 'unpaid',
        ]);
        FnbOrderItem::create([
            'fnb_order_id' => $unpaidOrder->id,
            'fnb_id' => $fnbKentang->id,
            'qty' => 1,
            'unit_price_beli' => 6000,
            'unit_price_jual' => 12000,
            'subtotal' => 12000,
            'payment_status' => 'unpaid',
        ]);

        // 3. Paid FnbOrder settled via TYPE_FNB_STANDALONE: 1 x 10000 = 10000
        $paidOrder = FnbOrder::create([
            'order_number' => 'ORD-CS12-PAID',
            'source' => FnbOrder::SOURCE_QR,
            'location_id' => $location->id,
            'transaction_id' => $tx->id_transaksi,
            'customer_name' => $tx->nama,
            'status' => FnbOrder::STATUS_ACCEPTED,
            'payment_status' => 'unpaid',
            'created_by_user_id' => $this->kasir->id,
        ]);
        FnbOrderItem::create([
            'fnb_order_id' => $paidOrder->id,
            'fnb_id' => $fnbEsTeh->id,
            'qty' => 1,
            'unit_price_beli' => 3000,
            'unit_price_jual' => 10000,
            'subtotal' => 10000,
            'payment_status' => 'unpaid',
        ]);
        $standaloneInv = $this->lostTimeBillingService->settleFnbOrder($paidOrder->id, [
            'payment_method' => 'tunai',
            'amount_paid' => 10000,
            'cashier_user_id' => $this->kasir->id,
        ]);
        $this->assertSame(Invoice::TYPE_FNB_STANDALONE, $standaloneInv->invoice_type);

        // Advance to 10:30:00 (30m @ 30k = 15,000) and switch to PS VIP (25k) in Mode B
        Carbon::setTestNow(Carbon::parse('2026-11-22 10:30:00'));

        $this->actingAs($this->kasir)->put(route('transaction.update', $tx->id_transaksi), [
            'device_id' => $device->id,
            'playstation_id' => $psVip->id,
            'tipe_transaksi' => 'postpaid',
            'pricing_mode' => 'from_now',
        ])->assertRedirect(route('transaction.index'));

        // Advance to 11:00:00 (another 30m @ 25k = 12,500 -> total rental = 27,500)
        Carbon::setTestNow(Carbon::parse('2026-11-22 11:00:00'));

        $summary = $this->billingService->summarizeTransactionBilling($tx->fresh(), Carbon::now());
        $this->assertSame(27500.0, $summary['rental_subtotal']);
        $this->assertSame(10000.0, $summary['fnb_paid_total']);
        $this->assertSame(28000.0, $summary['fnb_unpaid_total']); // 16000 + 12000
        $this->assertSame(38000.0, $summary['total_fnb_display']);
        $this->assertSame(55500.0, $summary['payable_total']); // 27500 + 28000

        // Checkout via processPayment
        $this->actingAs($this->kasir)->post(route('transaction.processPayment', $tx->id_transaksi), [
            'payment_method' => 'tunai',
            'amount_paid' => 60000,
            'discount' => 0,
        ])->assertRedirect(route('transaction.print', ['id' => $tx->id_transaksi, 'action' => 'payment']));

        $checkoutInv = Invoice::where('transaction_id', $tx->id_transaksi)
            ->where('invoice_type', '!=', Invoice::TYPE_FNB_STANDALONE)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(55500.0, (float) $checkoutInv->subtotal);
        $this->assertSame(55500.0, (float) $checkoutInv->final_total);
        $this->assertSame(4500.0, (float) $checkoutInv->change_amount);

        $rentalItems = $checkoutInv->items()->where('item_type', InvoiceItem::ITEM_TYPE_GAME_RENTAL)->get();
        $fnbItems = $checkoutInv->items()->where('item_type', InvoiceItem::ITEM_TYPE_FNB_ITEM)->get();

        $this->assertCount(2, $rentalItems);
        $this->assertSame(27500.0, (float) $rentalItems->sum('subtotal'));
        $this->assertCount(2, $fnbItems);
        $this->assertSame(28000.0, (float) $fnbItems->sum('subtotal'));
    }

    /**
     * CS-13: Concurrent or duplicate edit requests do not corrupt transaction or segments.
     * Simulate two rapid/back-to-back edit requests at the exact same timestamp (10:30:00):
     * Request 1 switches Cat A -> Cat B (from_now), Request 2 immediately repeats Cat A -> Cat B or switches Cat B -> Cat C at 10:30:00.
     * Assert no duplicate/overlapping segments, no 0-second orphan billing distortion, total segment duration equals session duration,
     * and transaction state is valid.
     */
    public function test_cs_13_concurrent_or_duplicate_edit_requests_do_not_corrupt_transaction_or_segments(): void
    {
        $catA = Playstation::create(['nama' => 'Cat A', 'harga' => 20000]);
        $catB = Playstation::create(['nama' => 'Cat B', 'harga' => 25000]);
        $catC = Playstation::create(['nama' => 'Cat C', 'harga' => 30000]);

        ['device' => $device] = $this->createStationWithHardware('Station Concurrency', [$catA, $catB, $catC], '14', 114);

        $tx = $this->billingService->startSession([
            'device_id' => $device->id,
            'playstation_id' => $catA->id,
            'nama' => 'Pelanggan Rapid Edit',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 2,
            'duration_seconds' => 7200,
        ], $this->kasir->id);

        Carbon::setTestNow(Carbon::parse('2026-11-22 10:30:00'));

        // Request 1: Switch Cat A -> Cat B at 10:30:00
        $this->actingAs($this->kasir)->putJson(route('transaction.update', $tx->id_transaksi), [
            'device_id' => $device->id,
            'playstation_id' => $catB->id,
            'tipe_transaksi' => 'prepaid',
            'pricing_mode' => 'from_now',
            'duration_seconds' => 7200,
        ])->assertOk();

        // Request 2 (duplicate at exact same timestamp 10:30:00): Repeat Cat B
        $this->actingAs($this->kasir)->putJson(route('transaction.update', $tx->id_transaksi), [
            'device_id' => $device->id,
            'playstation_id' => $catB->id,
            'tipe_transaksi' => 'prepaid',
            'pricing_mode' => 'from_now',
            'duration_seconds' => 7200,
        ])->assertOk();

        $segmentsAfterDup = TransactionPricingSegment::where('transaction_id', $tx->id_transaksi)
            ->orderBy('segment_order')
            ->get();
        $this->assertCount(2, $segmentsAfterDup, 'Duplicate request at same timestamp must not create extra segment');
        $this->assertSame(7200, (int) $segmentsAfterDup->sum('duration_seconds'));

        // Request 3 (rapid switch Cat B -> Cat C at the exact same timestamp 10:30:00):
        // Since Segment 2 started at 10:30:00 and now <= lastSegStart, it must update Segment 2 in-place to Cat C without creating a 0-second Segment 2!
        $this->actingAs($this->kasir)->putJson(route('transaction.update', $tx->id_transaksi), [
            'device_id' => $device->id,
            'playstation_id' => $catC->id,
            'tipe_transaksi' => 'prepaid',
            'pricing_mode' => 'from_now',
            'duration_seconds' => 7200,
        ])->assertOk();

        $tx->refresh();
        $segmentsAfterSwitchC = TransactionPricingSegment::where('transaction_id', $tx->id_transaksi)
            ->orderBy('segment_order')
            ->get();

        $this->assertCount(2, $segmentsAfterSwitchC, 'Same-second switch must replace the open/current segment rather than creating a 0s orphan segment');
        $this->assertSame($catA->id, (int) $segmentsAfterSwitchC[0]->playstation_id);
        $this->assertSame(1800, (int) $segmentsAfterSwitchC[0]->duration_seconds);
        $this->assertSame($catC->id, (int) $segmentsAfterSwitchC[1]->playstation_id);
        $this->assertSame(5400, (int) $segmentsAfterSwitchC[1]->duration_seconds);
        $this->assertSame(7200, (int) $segmentsAfterSwitchC->sum('duration_seconds'));
        $this->assertSame($catC->id, (int) $tx->playstation_id);
        // 30m @ 20k (10,000) + 90m @ 30k (45,000) = 55,000
        $this->assertSame(55000.0, (float) $tx->total);
    }

    /**
     * CS-14: Dashboard refresh displays new category, price, running timer, and action buttons.
     * After category switch on running session, fetch GET /transaction?tab=stations.
     * Extract station card HTML and #pos-bootstrap-data JSON: assert new category name chip is displayed,
     * data-hourly-rate and rental_subtotal / pos-est-rental-{id} reflect new category/segments,
     * timer attributes (data-started-at, data-expires-at) are intact,
     * buttons Detail, Bayar, Edit, Cafe, Berhenti (and Selesai for Lost Time) are present,
     * and GET /device/timers matches id_transaksi and unpaid_transaction_ids (no reload loop!).
     */
    public function test_cs_14_dashboard_refresh_displays_new_category_price_running_timer_and_action_buttons(): void
    {
        $billiardPagi = Playstation::create(['nama' => 'Billiard Pagi', 'harga' => 25000]);
        $billiardSore = Playstation::create(['nama' => 'Billiard Sore', 'harga' => 30000]);

        ['device' => $device] = $this->createStationWithHardware('Meja Billiard Dashboard', [$billiardPagi, $billiardSore], '15', 115);

        $tx = $this->billingService->startSession([
            'device_id' => $device->id,
            'playstation_id' => $billiardPagi->id,
            'nama' => 'Pelanggan Dashboard',
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        Carbon::setTestNow(Carbon::parse('2026-11-22 10:30:00'));

        $this->actingAs($this->kasir)->put(route('transaction.update', $tx->id_transaksi), [
            'device_id' => $device->id,
            'playstation_id' => $billiardSore->id,
            'tipe_transaksi' => 'postpaid',
            'pricing_mode' => 'from_now',
        ])->assertRedirect(route('transaction.index'));

        // Advance another 30 min -> 11:00:00 (30m @ 25k = 12,500 + 30m @ 30k = 15,000 => 27,500)
        Carbon::setTestNow(Carbon::parse('2026-11-22 11:00:00'));

        $dashRes = $this->actingAs($this->kasir)->get(route('transaction.index', ['tab' => 'stations']));
        $dashRes->assertOk();

        $html = $dashRes->getContent();
        $this->assertStringContainsString('<span class="pos-chip">Billiard Sore</span>', $html);
        $this->assertStringContainsString('data-hourly-rate="30000"', $html);
        $this->assertStringContainsString('data-has-segments="1"', $html);
        $this->assertStringContainsString('id="pos-est-rental-' . $device->id . '">Rp 27.500</strong>', $html);
        $this->assertStringContainsString('data-started-at="' . $tx->fresh()->getEffectiveStartTime()->toIso8601String() . '"', $html);

        // Verify action buttons on active postpaid card
        $this->assertStringContainsString('>Detail</button>', $html);
        $this->assertStringContainsString('<span>Bayar</span>', $html);
        $this->assertStringContainsString('<span>Edit</span>', $html);
        $this->assertStringContainsString('<span>Cafe</span>', $html);
        $this->assertStringContainsString('<span>Berhenti</span>', $html);
        $this->assertStringContainsString('<span>Selesai</span>', $html);

        // Extract #pos-bootstrap-data JSON
        preg_match('/<script type="application\/json" id="pos-bootstrap-data">(.*?)<\/script>/s', $html, $matches);
        $this->assertNotEmpty($matches[1] ?? null, '#pos-bootstrap-data script tag must exist');
        $bootstrap = json_decode(htmlspecialchars_decode($matches[1]), true);
        if (!is_array($bootstrap)) {
            $bootstrap = json_decode($matches[1], true);
        }
        $this->assertIsArray($bootstrap);

        $activePayload = $bootstrap['activeTransactions'][(string) $device->id] ?? null;
        $this->assertNotNull($activePayload);
        $this->assertSame($tx->id_transaksi, $activePayload['id_transaksi']);
        $this->assertSame($billiardSore->id, $activePayload['playstation_id']);
        $this->assertSame('Billiard Sore', $activePayload['playstation_nama']);
        $this->assertSame(27500.0, (float) $activePayload['rental_subtotal']);
        $this->assertCount(2, $activePayload['pricing_segments']);

        // Verify GET /device/timers parity (prevents infinite reload loop)
        $timersRes = $this->actingAs($this->kasir)->getJson(route('device.timers'));
        $timersRes->assertOk();
        $timerDev = collect($timersRes->json('devices'))->firstWhere('id', $device->id);
        $this->assertNotNull($timerDev);
        $this->assertSame('Digunakan', $timerDev['status']);
        $this->assertFalse($timerDev['has_status_desync']);
        $this->assertSame($tx->id_transaksi, $timerDev['transaction']['id_transaksi']);
        $this->assertSame([], $timerDev['unpaid_transaction_ids']);
    }

    /**
     * CS-15: Cross-midnight timer and lost time category switch preserves session.
     * Start 2-hour Timer at 2026-11-22 23:00:00 (expires_at = 2026-11-23 01:00:00).
     * Advance to 2026-11-23 00:15:00 (after midnight!). Switch category (test both Mode A and Mode B).
     * Assert session stays RUNNING, started_at = 2026-11-22 23:00:00, expires_at = 2026-11-23 01:00:00,
     * elapsed 75m and remaining 45m are calculated accurately across midnight!
     */
    public function test_cs_15_cross_midnight_timer_and_lost_time_category_switch_preserves_session(): void
    {
        $ps3 = Playstation::create(['nama' => 'PS3', 'harga' => 12000]);
        $ps4 = Playstation::create(['nama' => 'PS4', 'harga' => 16000]);

        ['device' => $deviceTimer] = $this->createStationWithHardware('Midnight Timer Station', [$ps3, $ps4], '16', 116);
        ['device' => $deviceLt] = $this->createStationWithHardware('Midnight LT Station', [$ps3, $ps4], '17', 117);

        Carbon::setTestNow(Carbon::parse('2026-11-22 23:00:00'));

        $timerTx = $this->billingService->startSession([
            'device_id' => $deviceTimer->id,
            'playstation_id' => $ps3->id,
            'nama' => 'Pelanggan Midnight Timer',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 2,
            'duration_seconds' => 7200,
        ], $this->kasir->id);

        $ltTx = $this->billingService->startSession([
            'device_id' => $deviceLt->id,
            'playstation_id' => $ps3->id,
            'nama' => 'Pelanggan Midnight LT',
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        // Advance past midnight to 2026-11-23 00:15:00 (75m = 4500s elapsed, 45m = 2700s remaining)
        Carbon::setTestNow(Carbon::parse('2026-11-23 00:15:00'));

        // Preview Timer across midnight
        $previewTimer = $this->actingAs($this->kasir)->postJson(route('transaction.editPreview', $timerTx->id_transaksi), [
            'device_id' => $deviceTimer->id,
            'playstation_id' => $ps4->id,
            'tipe_transaksi' => 'prepaid',
            'pricing_mode' => 'from_now',
            'duration_seconds' => 7200,
        ]);
        $previewTimer->assertOk()->assertJson([
            'valid' => true,
            'elapsed_seconds' => 4500,
            'remaining_seconds' => 2700,
            'total_duration_seconds' => 7200,
            // 75m (1.25h) @ 12k = 15,000 + 45m (0.75h) @ 16k = 12,000 => 27,000
            'new_rental_estimate' => 27000,
        ]);

        // Apply Mode B on Timer across midnight
        $this->actingAs($this->kasir)->put(route('transaction.update', $timerTx->id_transaksi), [
            'device_id' => $deviceTimer->id,
            'playstation_id' => $ps4->id,
            'tipe_transaksi' => 'prepaid',
            'pricing_mode' => 'from_now',
            'duration_seconds' => 7200,
            'jam_main' => 2,
        ])->assertRedirect(route('transaction.index'));

        $timerTx->refresh();
        $this->assertSame(Transaction::BILLING_RUNNING, $timerTx->billing_status);
        $this->assertSame('2026-11-22 23:00:00', $timerTx->started_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-23 01:00:00', $timerTx->expires_at->format('Y-m-d H:i:s'));
        $this->assertSame(27000.0, (float) $timerTx->total);

        // Also test Mode A on Timer across midnight
        $this->actingAs($this->kasir)->put(route('transaction.update', $timerTx->id_transaksi), [
            'device_id' => $deviceTimer->id,
            'playstation_id' => $ps4->id,
            'tipe_transaksi' => 'prepaid',
            'pricing_mode' => 'correction',
            'duration_seconds' => 7200,
            'jam_main' => 2,
        ])->assertRedirect(route('transaction.index'));

        $timerTx->refresh();
        $this->assertSame(Transaction::BILLING_RUNNING, $timerTx->billing_status);
        $this->assertSame('2026-11-22 23:00:00', $timerTx->started_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-23 01:00:00', $timerTx->expires_at->format('Y-m-d H:i:s'));
        $this->assertSame(32000.0, (float) $timerTx->total);

        // Apply Mode B on Lost Time across midnight at 00:15:00 (75m @ 12k = 15,000), then finish at 01:00:00 (45m @ 16k = 12,000)
        $this->actingAs($this->kasir)->put(route('transaction.update', $ltTx->id_transaksi), [
            'device_id' => $deviceLt->id,
            'playstation_id' => $ps4->id,
            'tipe_transaksi' => 'postpaid',
            'pricing_mode' => 'from_now',
        ])->assertRedirect(route('transaction.index'));

        Carbon::setTestNow(Carbon::parse('2026-11-23 01:00:00'));
        $ltSummary = $this->billingService->summarizeTransactionBilling($ltTx->fresh(), Carbon::now());
        $this->assertSame(27000.0, $ltSummary['rental_subtotal']);
    }

    /**
     * CS-16: Settlement after category switch generates accurate invoice and receipt.
     * Settle both a Mode B Lost Time session and a Mode B Timer session via POST /transaction/{id}/process-payment.
     * Assert Invoice subtotal and final_total match the exact segment breakdown, InvoiceItem rows (ITEM_TYPE_GAME_RENTAL)
     * contain each segment's category name and allocated subtotal summing to rental_subtotal,
     * and GET /transaction/{id}/print renders the segment items and totals accurately.
     */
    public function test_cs_16_settlement_after_category_switch_generates_accurate_invoice_and_receipt(): void
    {
        $karaoke = Playstation::create(['nama' => 'Karaoke', 'harga' => 30000]);
        $psVip = Playstation::create(['nama' => 'PS VIP', 'harga' => 25000]);

        ['device' => $deviceLt] = $this->createStationWithHardware('Station Receipt LT', [$karaoke, $psVip], '18', 118);
        ['device' => $deviceTimer] = $this->createStationWithHardware('Station Receipt Timer', [$karaoke, $psVip], '19', 119);

        // 1. Start Lost Time & Timer at 10:00:00
        $ltTx = $this->billingService->startSession([
            'device_id' => $deviceLt->id,
            'playstation_id' => $karaoke->id,
            'nama' => 'Pelanggan Struk LT',
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        $timerTx = $this->billingService->startSession([
            'device_id' => $deviceTimer->id,
            'playstation_id' => $karaoke->id,
            'nama' => 'Pelanggan Struk Timer',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 2,
            'duration_seconds' => 7200,
        ], $this->kasir->id);

        // 2. Switch both to PS VIP (Mode B) at 10:30:00
        Carbon::setTestNow(Carbon::parse('2026-11-22 10:30:00'));

        $this->actingAs($this->kasir)->put(route('transaction.update', $ltTx->id_transaksi), [
            'device_id' => $deviceLt->id,
            'playstation_id' => $psVip->id,
            'tipe_transaksi' => 'postpaid',
            'pricing_mode' => 'from_now',
        ])->assertRedirect(route('transaction.index'));

        $this->actingAs($this->kasir)->put(route('transaction.update', $timerTx->id_transaksi), [
            'device_id' => $deviceTimer->id,
            'playstation_id' => $psVip->id,
            'tipe_transaksi' => 'prepaid',
            'pricing_mode' => 'from_now',
            'duration_seconds' => 7200,
            'jam_main' => 2,
        ])->assertRedirect(route('transaction.index'));

        // 3. Advance to 11:00:00 and settle both via POST /transaction/{id}/process-payment
        Carbon::setTestNow(Carbon::parse('2026-11-22 11:00:00'));

        // Lost Time: 30m @ 30k (15,000) + 30m @ 25k (12,500) = 27,500
        $this->actingAs($this->kasir)->post(route('transaction.processPayment', $ltTx->id_transaksi), [
            'payment_method' => 'tunai',
            'amount_paid' => 30000,
            'discount' => 0,
        ])->assertRedirect(route('transaction.print', ['id' => $ltTx->id_transaksi, 'action' => 'payment']));

        $ltInvoice = Invoice::where('transaction_id', $ltTx->id_transaksi)->latest('id')->firstOrFail();
        $this->assertSame(27500.0, (float) $ltInvoice->subtotal);
        $this->assertSame(27500.0, (float) $ltInvoice->final_total);
        $ltItems = $ltInvoice->items()->where('item_type', InvoiceItem::ITEM_TYPE_GAME_RENTAL)->get();
        $this->assertCount(2, $ltItems);
        $this->assertStringContainsString('Karaoke', $ltItems[0]->name);
        $this->assertSame(15000.0, (float) $ltItems[0]->subtotal);
        $this->assertStringContainsString('PS VIP', $ltItems[1]->name);
        $this->assertSame(12500.0, (float) $ltItems[1]->subtotal);

        $ltReceiptRes = $this->actingAs($this->kasir)->get(route('transaction.print', $ltTx->id_transaksi));
        $ltReceiptRes->assertOk();
        $ltReceiptRes->assertSee('Karaoke', false);
        $ltReceiptRes->assertSee('PS VIP', false);
        $ltReceiptRes->assertSee('Segmen #1', false);
        $ltReceiptRes->assertSee('Segmen #2', false);
        $ltReceiptRes->assertSee('Rp 15.000', false);
        $ltReceiptRes->assertSee('Rp 12.500', false);
        $ltReceiptRes->assertSee('Rp 27.500', false);

        // Timer: 30m @ 30k (15,000) + 90m @ 25k (37,500) = 52,500
        $this->actingAs($this->kasir)->post(route('transaction.processPayment', $timerTx->id_transaksi), [
            'payment_method' => 'e-wallet',
            'amount_paid' => 52500,
            'discount' => 0,
        ])->assertRedirect(route('transaction.print', ['id' => $timerTx->id_transaksi, 'action' => 'payment']));

        $timerInvoice = Invoice::where('transaction_id', $timerTx->id_transaksi)->latest('id')->firstOrFail();
        $this->assertSame(52500.0, (float) $timerInvoice->subtotal);
        $this->assertSame(52500.0, (float) $timerInvoice->final_total);
        $timerItems = $timerInvoice->items()->where('item_type', InvoiceItem::ITEM_TYPE_GAME_RENTAL)->get();
        $this->assertCount(2, $timerItems);
        $this->assertStringContainsString('Karaoke', $timerItems[0]->name);
        $this->assertSame(15000.0, (float) $timerItems[0]->subtotal);
        $this->assertStringContainsString('PS VIP', $timerItems[1]->name);
        $this->assertSame(37500.0, (float) $timerItems[1]->subtotal);

        $timerReceiptRes = $this->actingAs($this->kasir)->get(route('transaction.print', $timerTx->id_transaksi));
        $timerReceiptRes->assertOk();
        $timerReceiptRes->assertSee('Karaoke', false);
        $timerReceiptRes->assertSee('PS VIP', false);
        $timerReceiptRes->assertSee('Rp 15.000', false);
        $timerReceiptRes->assertSee('Rp 37.500', false);
        $timerReceiptRes->assertSee('Rp 52.500', false);
    }

    /**
     * CS-17: Expired session cannot be reactivated by category switch.
     * Test both an already-evaluated EXPIRED_PENDING_ACTION session AND a stale RUNNING session
     * whose expires_at has passed (now > expires_at).
     * Attempt PUT /transaction/{id} to switch category.
     * Assert request is rejected, billing_status is EXPIRED_PENDING_ACTION (never RUNNING),
     * expires_at is unchanged, device->status is Tersedia, and 0 new hardware ON/OFF intents are created.
     */
    public function test_cs_17_expired_session_cannot_be_reactivated_by_category_switch(): void
    {
        $ps3 = Playstation::create(['nama' => 'PS3', 'harga' => 10000]);
        $ps4 = Playstation::create(['nama' => 'PS4', 'harga' => 15000]);

        ['device' => $device1] = $this->createStationWithHardware('Expired Station 1', [$ps3, $ps4], '20', 120);
        ['device' => $device2] = $this->createStationWithHardware('Expired Station 2', [$ps3, $ps4], '21', 121);

        // Case 1: Already evaluated EXPIRED_PENDING_ACTION session
        $txEvaluated = $this->billingService->startSession([
            'device_id' => $device1->id,
            'playstation_id' => $ps3->id,
            'nama' => 'Pelanggan Expired 1',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
            'duration_seconds' => 3600,
        ], $this->kasir->id);

        // Case 2: Stale RUNNING session that hasn't been evaluated yet when clock passes expires_at
        $txStaleRunning = $this->billingService->startSession([
            'device_id' => $device2->id,
            'playstation_id' => $ps3->id,
            'nama' => 'Pelanggan Expired 2',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
            'duration_seconds' => 3600,
        ], $this->kasir->id);

        // Advance past 11:00:00 expiration to 11:05:00
        Carbon::setTestNow(Carbon::parse('2026-11-22 11:05:00'));

        // Evaluate only device 1 first
        $this->billingService->evaluateExpiration(Carbon::now(), $device1->id);
        $txEvaluated->refresh();
        $device1->refresh();
        $this->assertSame(Transaction::BILLING_EXPIRED_PENDING_ACTION, $txEvaluated->billing_status);
        $this->assertSame('Tersedia', $device1->status);

        $intentsDevice1Before = AutomationIntent::where('station_id', $device1->id)->count();

        // Attempt to edit/switch category on already-evaluated EXPIRED_PENDING_ACTION session
        $res1 = $this->actingAs($this->kasir)->putJson(route('transaction.update', $txEvaluated->id_transaksi), [
            'device_id' => $device1->id,
            'playstation_id' => $ps4->id,
            'tipe_transaksi' => 'prepaid',
            'pricing_mode' => 'from_now',
            'duration_seconds' => 3600,
        ]);
        $res1->assertStatus(422)->assertJson([
            'success' => false,
            'error_code' => 'session_ended',
        ]);

        $txEvaluated->refresh();
        $device1->refresh();
        $this->assertSame(Transaction::BILLING_EXPIRED_PENDING_ACTION, $txEvaluated->billing_status);
        $this->assertSame($ps3->id, (int) $txEvaluated->playstation_id);
        $this->assertSame('2026-11-22 11:00:00', $txEvaluated->expires_at->format('Y-m-d H:i:s'));
        $this->assertSame('Tersedia', $device1->status);
        $this->assertSame($intentsDevice1Before, AutomationIntent::where('station_id', $device1->id)->count());

        // Attempt to edit/switch category on stale RUNNING session (pre-update evaluateExpiration transitions it to EXPIRED_PENDING_ACTION and rejects edit)
        $res2 = $this->actingAs($this->kasir)->putJson(route('transaction.update', $txStaleRunning->id_transaksi), [
            'device_id' => $device2->id,
            'playstation_id' => $ps4->id,
            'tipe_transaksi' => 'prepaid',
            'pricing_mode' => 'from_now',
            'duration_seconds' => 7200,
        ]);
        $res2->assertStatus(422)->assertJson([
            'success' => false,
            'error_code' => 'session_ended',
        ]);

        $txStaleRunning->refresh();
        $device2->refresh();
        $this->assertSame(Transaction::BILLING_EXPIRED_PENDING_ACTION, $txStaleRunning->billing_status);
        $this->assertSame($ps3->id, (int) $txStaleRunning->playstation_id);
        $this->assertSame('2026-11-22 11:00:00', $txStaleRunning->expires_at->format('Y-m-d H:i:s'));
        $this->assertSame('Tersedia', $device2->status);
        $this->assertSame(
            1,
            AutomationIntent::where('transaction_id', $txStaleRunning->id_transaksi)
                ->where('event_type', AutomationIntent::EVENT_BILLING_STARTED)
                ->count(),
            'Must never emit a second BILLING_STARTED ON intent'
        );
    }

    /**
     * CS-18: Single-category device with pivot or legacy playstation_id remains compatible.
     * Test a device with 1 pivot category AND a legacy device with 0 pivot rows and only devices.playstation_id.
     * Verify GET /transaction/{id}/edit and PUT /transaction/{id} (both with and without playstation_id in payload)
     * work seamlessly.
     */
    public function test_cs_18_single_category_device_with_pivot_or_legacy_playstation_id_remains_compatible(): void
    {
        $psSingle = Playstation::create(['nama' => 'PS5 Single', 'harga' => 20000]);

        // 1. Device with 1 pivot category
        ['device' => $pivotDevice] = $this->createStationWithHardware('Single Pivot Station', [$psSingle], '22', 122);

        $txPivot = $this->billingService->startSession([
            'device_id' => $pivotDevice->id,
            'playstation_id' => $psSingle->id,
            'nama' => 'Pelanggan Single Pivot',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
            'duration_seconds' => 3600,
        ], $this->kasir->id);

        $this->actingAs($this->kasir)
            ->get(route('transaction.edit', $txPivot->id_transaksi))
            ->assertOk()
            ->assertSee('PS5 Single', false);

        // PUT without playstation_id in payload (auto-resolved for single-category device) and extend to 2 hours
        $this->actingAs($this->kasir)->put(route('transaction.update', $txPivot->id_transaksi), [
            'device_id' => $pivotDevice->id,
            'tipe_transaksi' => 'prepaid',
            'pricing_mode' => 'from_now',
            'duration_seconds' => 7200,
            'jam_main' => 2,
        ])->assertRedirect(route('transaction.index'));

        $txPivot->refresh();
        $this->assertSame($psSingle->id, (int) $txPivot->playstation_id);
        $this->assertSame(40000.0, (float) $txPivot->total);

        // 2. Legacy device with 0 pivot rows and only devices.playstation_id
        $legacyDevice = Device::create([
            'nama' => 'Legacy No Pivot Station',
            'playstation_id' => $psSingle->id,
            'status' => 'Tersedia',
            'session_generation' => 0,
        ]);
        $this->assertSame(0, $legacyDevice->playstations()->count());

        $txLegacy = $this->billingService->startSession([
            'device_id' => $legacyDevice->id,
            'nama' => 'Pelanggan Legacy No Pivot',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
            'duration_seconds' => 3600,
        ], $this->kasir->id);

        $this->actingAs($this->kasir)
            ->get(route('transaction.edit', $txLegacy->id_transaksi))
            ->assertOk()
            ->assertSee('PS5 Single', false)
            ->assertDontSee('id="category_unavailable_alert"', false);

        // PUT with and without playstation_id
        $this->actingAs($this->kasir)->putJson(route('transaction.update', $txLegacy->id_transaksi), [
            'device_id' => $legacyDevice->id,
            'tipe_transaksi' => 'prepaid',
            'pricing_mode' => 'correction',
            'duration_seconds' => 7200,
        ])->assertOk()->assertJson(['success' => true]);

        $txLegacy->refresh();
        $this->assertSame($psSingle->id, (int) $txLegacy->playstation_id);
        $this->assertSame(40000.0, (float) $txLegacy->total);
    }

    /**
     * CS-19: Multi-category device edit dropdown loads only valid configured options.
     * Create 3 categories in DB (Cat 1, Cat 2, Cat 3 Unrelated), attach only Cat 1 and Cat 2 to Device A.
     * Fetch GET /transaction/{id}/edit: assert deviceCategoriesMap[Device A] and the #playstation_id dropdown
     * contain Cat 1 and Cat 2 with their rates, and do NOT offer Cat 3 Unrelated for Device A.
     */
    public function test_cs_19_multi_category_device_edit_dropdown_loads_only_valid_configured_options(): void
    {
        $cat1 = Playstation::create(['nama' => 'Cat 1 Reguler', 'harga' => 15000]);
        $cat2 = Playstation::create(['nama' => 'Cat 2 Sore', 'harga' => 22000]);
        $cat3Unrelated = Playstation::create(['nama' => 'Cat 3 Unrelated VIP', 'harga' => 50000]);

        ['device' => $deviceA] = $this->createStationWithHardware('Device A Multi', [$cat1, $cat2], '23', 123);

        $tx = $this->billingService->startSession([
            'device_id' => $deviceA->id,
            'playstation_id' => $cat1->id,
            'nama' => 'Pelanggan Dropdown',
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        $res = $this->actingAs($this->kasir)->get(route('transaction.edit', $tx->id_transaksi));
        $res->assertOk();

        $viewMap = $res->viewData('deviceCategoriesMap');
        $this->assertIsArray($viewMap);
        $this->assertArrayHasKey((string) $deviceA->id, $viewMap);

        $deviceACats = collect($viewMap[(string) $deviceA->id]);
        $this->assertSame([$cat1->id, $cat2->id], $deviceACats->pluck('id')->all());
        $this->assertFalse($deviceACats->pluck('id')->contains($cat3Unrelated->id));

        $html = $res->getContent();
        $this->assertStringContainsString('Cat 1 Reguler — Rp 15.000/jam', $html);
        $this->assertStringContainsString('Cat 2 Sore — Rp 22.000/jam', $html);
        $this->assertStringNotContainsString('Cat 3 Unrelated VIP', $html);
    }

    /**
     * CS-20: Updated master price or preset and tampered browser price use authoritative server pricing.
     * Update Playstation->harga and HourlyPrice preset in DB before switching category.
     * Call POST /transaction/{id}/edit-preview and PUT /transaction/{id} while injecting tampered
     * harga => 1 and total => 1 in the request payload.
     * Assert tampered client values are completely ignored, preview endpoint and persisted transaction total
     * match 100%, and calculation uses the authoritative updated DB price/preset.
     */
    public function test_cs_20_updated_master_price_or_preset_and_tampered_browser_price_use_authoritative_server_pricing(): void
    {
        $catA = Playstation::create(['nama' => 'PS4 Base', 'harga' => 15000]);
        $catB = Playstation::create(['nama' => 'PS5 Master', 'harga' => 25000]);

        $preset2h = HourlyPrice::create([
            'playstation_id' => $catB->id,
            'hour' => 2,
            'duration_seconds' => 7200,
            'price' => 45000,
        ]);

        ['device' => $device] = $this->createStationWithHardware('Station Tamper Check', [$catA, $catB], '24', 124);

        $tx = $this->billingService->startSession([
            'device_id' => $device->id,
            'playstation_id' => $catA->id,
            'nama' => 'Pelanggan Tamper',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 2,
            'duration_seconds' => 7200,
        ], $this->kasir->id);

        // Update master price and 2h preset in DB before switching category
        $catB->update(['harga' => 28000]);
        $preset2h->update(['price' => 52000]);

        Carbon::setTestNow(Carbon::parse('2026-11-22 10:15:00'));

        // 1. Mode A (correction) with tampered harga=1 and total=1 -> must use updated 2h preset 52000
        $previewModeA = $this->actingAs($this->kasir)->postJson(route('transaction.editPreview', $tx->id_transaksi), [
            'device_id' => $device->id,
            'playstation_id' => $catB->id,
            'tipe_transaksi' => 'prepaid',
            'pricing_mode' => 'correction',
            'duration_seconds' => 7200,
            'harga' => 1,
            'total' => 1,
        ]);
        $previewModeA->assertOk()->assertJson([
            'valid' => true,
            'new_rental_estimate' => 52000,
            'new_payable_total' => 52000,
        ]);

        $this->actingAs($this->kasir)->putJson(route('transaction.update', $tx->id_transaksi), [
            'device_id' => $device->id,
            'playstation_id' => $catB->id,
            'tipe_transaksi' => 'prepaid',
            'pricing_mode' => 'correction',
            'duration_seconds' => 7200,
            'harga' => 1,
            'total' => 1,
        ])->assertOk();

        $tx->refresh();
        $this->assertSame(28000.0, (float) $tx->harga, 'Tampered harga=1 must be ignored; authoritative DB harga=28000 used');
        $this->assertSame(52000.0, (float) $tx->total, 'Tampered total=1 must be ignored; authoritative DB preset=52000 used');
        $this->assertSame((float) $previewModeA->json('new_payable_total'), (float) $tx->total);
    }

    /**
     * CS-21 (Finding 1): summarizeTransactionBilling() populates rental_segments for segmented sessions
     * and both payment.blade.php and show.blade.php render "Rincian Segmen Tarif Permainan".
     */
    public function test_cs_21_payment_and_show_pages_render_rental_segments_breakdown_for_segmented_sessions(): void
    {
        $catA = Playstation::create(['nama' => 'Karaoke Seg', 'harga' => 30000]);
        $catB = Playstation::create(['nama' => 'PS VIP Seg', 'harga' => 25000]);

        ['device' => $deviceLt] = $this->createStationWithHardware('Station Seg LT', [$catA, $catB], '25', 125);
        ['device' => $deviceTimer] = $this->createStationWithHardware('Station Seg Timer', [$catA, $catB], '26', 126);

        $ltTx = $this->billingService->startSession([
            'device_id' => $deviceLt->id,
            'playstation_id' => $catA->id,
            'nama' => 'Pelanggan Seg LT',
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        $timerTx = $this->billingService->startSession([
            'device_id' => $deviceTimer->id,
            'playstation_id' => $catA->id,
            'nama' => 'Pelanggan Seg Timer',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 2,
            'duration_seconds' => 7200,
        ], $this->kasir->id);

        // Unsegmented transactions return empty rental_segments
        $unsegSummary = $this->billingService->summarizeTransactionBilling($ltTx->fresh(), Carbon::now());
        $this->assertSame([], $unsegSummary['rental_segments']);

        // Switch both at 10:30:00 (Mode B)
        Carbon::setTestNow(Carbon::parse('2026-11-22 10:30:00'));

        $this->actingAs($this->kasir)->putJson(route('transaction.update', $ltTx->id_transaksi), [
            'device_id' => $deviceLt->id,
            'playstation_id' => $catB->id,
            'tipe_transaksi' => 'postpaid',
            'pricing_mode' => 'from_now',
        ])->assertOk();

        $this->actingAs($this->kasir)->putJson(route('transaction.update', $timerTx->id_transaksi), [
            'device_id' => $deviceTimer->id,
            'playstation_id' => $catB->id,
            'tipe_transaksi' => 'prepaid',
            'pricing_mode' => 'from_now',
            'duration_seconds' => 7200,
        ])->assertOk();

        // Advance to 11:00:00 -> LT: 30m @ 30k (15k) + 30m @ 25k (12.5k) = 27.5k; Timer: 15k + 37.5k = 52.5k
        Carbon::setTestNow(Carbon::parse('2026-11-22 11:00:00'));

        $ltSummary = $this->billingService->summarizeTransactionBilling($ltTx->fresh(), Carbon::now());
        $this->assertCount(2, $ltSummary['rental_segments']);
        $this->assertSame('Karaoke Seg', $ltSummary['rental_segments'][0]['playstation_name']);
        $this->assertSame('PS VIP Seg', $ltSummary['rental_segments'][1]['playstation_name']);
        $this->assertSame(15000.0, (float) $ltSummary['rental_segments'][0]['allocated_billed_amount']);
        $this->assertSame(12500.0, (float) $ltSummary['rental_segments'][1]['allocated_billed_amount']);

        $timerSummary = $this->billingService->summarizeTransactionBilling($timerTx->fresh(), Carbon::now());
        $this->assertCount(2, $timerSummary['rental_segments']);
        $this->assertSame(15000.0, (float) $timerSummary['rental_segments'][0]['allocated_billed_amount']);
        $this->assertSame(37500.0, (float) $timerSummary['rental_segments'][1]['allocated_billed_amount']);

        // Payment page renders segment breakdown for both
        $this->actingAs($this->kasir)
            ->get(route('transaction.showPayment', $ltTx->id_transaksi))
            ->assertOk()
            ->assertSee('Rincian Segmen Tarif Permainan', false)
            ->assertSee('Karaoke Seg', false)
            ->assertSee('PS VIP Seg', false)
            ->assertSee('Rp 15.000', false)
            ->assertSee('Rp 12.500', false);

        $this->actingAs($this->kasir)
            ->get(route('transaction.showPayment', $timerTx->id_transaksi))
            ->assertOk()
            ->assertSee('Rincian Segmen Tarif Permainan', false)
            ->assertSee('Karaoke Seg', false)
            ->assertSee('PS VIP Seg', false)
            ->assertSee('Rp 15.000', false)
            ->assertSee('Rp 37.500', false);

        // Show page renders segment breakdown for both
        $this->actingAs($this->kasir)
            ->get(route('transaction.show', $ltTx->id_transaksi))
            ->assertOk()
            ->assertSee('Rincian Segmen Tarif Permainan', false)
            ->assertSee('Karaoke Seg', false)
            ->assertSee('PS VIP Seg', false)
            ->assertSee('Rp 15.000', false)
            ->assertSee('Rp 12.500', false);

        $this->actingAs($this->kasir)
            ->get(route('transaction.show', $timerTx->id_transaksi))
            ->assertOk()
            ->assertSee('Rincian Segmen Tarif Permainan', false)
            ->assertSee('Karaoke Seg', false)
            ->assertSee('PS VIP Seg', false)
            ->assertSee('Rp 15.000', false)
            ->assertSee('Rp 37.500', false);
    }

    /**
     * CS-22 (Finding 2): custom_package edits preserve expires_at when package is unchanged (zero drift),
     * compute with second precision when package changes, and set expires_at_changed = true whenever expires_at changes.
     */
    public function test_cs_22_custom_package_edit_preserves_expires_at_when_unchanged_and_updates_with_second_precision_when_changed(): void
    {
        $ps = Playstation::create(['nama' => 'PS5 Paket', 'harga' => 20000]);
        ['device' => $dev1] = $this->createStationWithHardware('Station Paket 1', [$ps], '27', 127);
        ['device' => $dev2] = $this->createStationWithHardware('Station Paket 2', [$ps], '28', 128);

        $pkg60 = CustomPackage::create([
            'nama_paket' => 'Paket 60 Menit',
            'harga_total' => 30000,
            'is_active' => true,
        ]);
        $pkg60->playstations()->attach($ps->id, ['lama_main' => 60]);

        $pkg90 = CustomPackage::create([
            'nama_paket' => 'Paket 90 Menit',
            'harga_total' => 45000,
            'is_active' => true,
        ]);
        $pkg90->playstations()->attach($ps->id, ['lama_main' => 90]);

        $tx = $this->billingService->startSession([
            'device_id' => $dev1->id,
            'playstation_id' => $ps->id,
            'nama' => 'Pelanggan Paket Drift',
            'tipe_transaksi' => 'custom_package',
            'custom_package_id' => $pkg60->id,
        ], $this->kasir->id);

        $tx->refresh();
        $this->assertSame('2026-11-22 11:00:00', $tx->expires_at->format('Y-m-d H:i:s'));
        $waktuSelesaiBefore = $tx->waktu_Selesai;
        $waktuSelesaiBefore = $tx->waktu_Selesai;

        // Advance to 10:15:37 (non-zero seconds) and move to Station 2 with SAME custom package
        Carbon::setTestNow(Carbon::parse('2026-11-22 10:15:37'));

        $previewSamePkg = $this->actingAs($this->kasir)->postJson(route('transaction.editPreview', $tx->id_transaksi), [
            'device_id' => $dev2->id,
            'tipe_transaksi' => 'custom_package',
            'custom_package_id' => $pkg60->id,
        ]);
        $previewSamePkg->assertOk()->assertJson([
            'valid' => true,
            'device_changed' => true,
            'duration_changed' => false,
            'expires_at' => Carbon::parse('2026-11-22 11:00:00')->toIso8601String(),
        ]);

        $this->actingAs($this->kasir)->putJson(route('transaction.update', $tx->id_transaksi), [
            'device_id' => $dev2->id,
            'tipe_transaksi' => 'custom_package',
            'custom_package_id' => $pkg60->id,
        ])->assertOk();

        $tx->refresh();
        $this->assertSame($dev2->id, (int) $tx->device_id);
        $this->assertSame('2026-11-22 11:00:00', $tx->expires_at->format('Y-m-d H:i:s'), 'Unchanged custom_package must preserve exact expires_at without 59s drift');
        $this->assertSame($waktuSelesaiBefore, $tx->waktu_Selesai);

        // Now change custom package from 60m to 90m at 10:20:25 (elapsed 1225s, remaining 4175s -> 11:30:00)
        Carbon::setTestNow(Carbon::parse('2026-11-22 10:20:25'));

        $expectedNewExpiresAt = Carbon::parse('2026-11-22 11:30:00');
        $previewChangePkg = $this->actingAs($this->kasir)->postJson(route('transaction.editPreview', $tx->id_transaksi), [
            'device_id' => $dev2->id,
            'tipe_transaksi' => 'custom_package',
            'custom_package_id' => $pkg90->id,
        ]);
        $previewChangePkg->assertOk()->assertJson([
            'valid' => true,
            'duration_changed' => true,
            'remaining_seconds' => 4175,
            'expires_at' => $expectedNewExpiresAt->toIso8601String(),
        ]);

        $genBefore = (int) $dev2->fresh()->session_generation;

        $this->actingAs($this->kasir)->putJson(route('transaction.update', $tx->id_transaksi), [
            'device_id' => $dev2->id,
            'tipe_transaksi' => 'custom_package',
            'custom_package_id' => $pkg90->id,
        ])->assertOk();

        $tx->refresh();
        $dev2->refresh();
        $this->assertSame($pkg90->id, (int) $tx->custom_package_id);
        $this->assertSame('2026-11-22 11:30:00', $tx->expires_at->format('Y-m-d H:i:s'));
        $this->assertSame(45000.0, (float) $tx->total);
        $this->assertSame($genBefore + 1, (int) $dev2->session_generation, 'Changing expires_at must bump generation and reschedule OFF intent');

        $activeOffIntent = AutomationIntent::where('transaction_id', $tx->id_transaksi)
            ->where('event_type', AutomationIntent::EVENT_BILLING_EXPIRED)
            ->where('status', AutomationIntent::STATUS_PENDING)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('2026-11-22 11:30:00', $activeOffIntent->scheduled_at->format('Y-m-d H:i:s'));
    }

    /**
     * CS-23 (Finding 3): Shortening a running prepaid or custom_package session to <= elapsed time
     * or <= closed segments is rejected consistently in preview and apply without corrupting session or segments.
     */
    public function test_cs_23_shortening_running_prepaid_or_custom_package_below_elapsed_or_closed_segments_is_rejected(): void
    {
        $catA = Playstation::create(['nama' => 'PS3 Short', 'harga' => 10000]);
        $catB = Playstation::create(['nama' => 'PS4 Short', 'harga' => 15000]);

        ['device' => $device1] = $this->createStationWithHardware('Station Short 1', [$catA, $catB], '29', 129);
        ['device' => $device2] = $this->createStationWithHardware('Station Short 2', [$catA, $catB], '30', 130);

        // 1. Running 2h prepaid started at 10:00:00
        $txPrepaid = $this->billingService->startSession([
            'device_id' => $device1->id,
            'playstation_id' => $catA->id,
            'nama' => 'Pelanggan Short Prepaid',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 2,
            'duration_seconds' => 7200,
        ], $this->kasir->id);

        // Switch Cat A -> Cat B at 10:30:00 (Seg 1 closed = 1800s)
        Carbon::setTestNow(Carbon::parse('2026-11-22 10:30:00'));
        $this->actingAs($this->kasir)->putJson(route('transaction.update', $txPrepaid->id_transaksi), [
            'device_id' => $device1->id,
            'playstation_id' => $catB->id,
            'tipe_transaksi' => 'prepaid',
            'pricing_mode' => 'from_now',
            'duration_seconds' => 7200,
        ])->assertOk();

        // Advance to 10:45:00 (2700s elapsed). Attempt shortening to 2700s (== elapsed) and 1800s (<= closed segments)
        Carbon::setTestNow(Carbon::parse('2026-11-22 10:45:00'));
        $intentsBefore = AutomationIntent::where('transaction_id', $txPrepaid->id_transaksi)->count();

        foreach ([2700, 1800] as $badDuration) {
            $this->actingAs($this->kasir)->postJson(route('transaction.editPreview', $txPrepaid->id_transaksi), [
                'device_id' => $device2->id,
                'playstation_id' => $catB->id,
                'tipe_transaksi' => 'prepaid',
                'pricing_mode' => 'from_now',
                'duration_seconds' => $badDuration,
            ])->assertStatus(422)->assertJson(['valid' => false]);

            $this->actingAs($this->kasir)->putJson(route('transaction.update', $txPrepaid->id_transaksi), [
                'device_id' => $device2->id,
                'playstation_id' => $catB->id,
                'tipe_transaksi' => 'prepaid',
                'pricing_mode' => 'from_now',
                'duration_seconds' => $badDuration,
            ])->assertStatus(422)->assertJson(['success' => false]);
        }

        // Web PUT redirect back with errors
        $webRes = $this->actingAs($this->kasir)
            ->from(route('transaction.edit', $txPrepaid->id_transaksi))
            ->put(route('transaction.update', $txPrepaid->id_transaksi), [
                'device_id' => $device1->id,
                'playstation_id' => $catB->id,
                'tipe_transaksi' => 'prepaid',
                'pricing_mode' => 'from_now',
                'duration_seconds' => 1800,
            ]);
        $webRes->assertRedirect(route('transaction.edit', $txPrepaid->id_transaksi));
        $webRes->assertSessionHasErrors(['duration_seconds']);

        $txPrepaid->refresh();
        $this->assertSame(Transaction::BILLING_RUNNING, $txPrepaid->billing_status);
        $this->assertSame($device1->id, (int) $txPrepaid->device_id);
        $this->assertSame(7200, (int) $txPrepaid->duration_seconds);
        $this->assertSame('2026-11-22 12:00:00', $txPrepaid->expires_at->format('Y-m-d H:i:s'));
        $this->assertSame($intentsBefore, AutomationIntent::where('transaction_id', $txPrepaid->id_transaksi)->count());
        $this->assertSame(7200, (int) TransactionPricingSegment::where('transaction_id', $txPrepaid->id_transaksi)->sum('duration_seconds'));

        // 2. Running custom_package (90m) cannot be switched to a 30m custom_package after 45m elapsed
        $pkg90 = CustomPackage::create(['nama_paket' => 'Paket 90m', 'harga_total' => 45000, 'is_active' => true]);
        $pkg90->playstations()->attach($catA->id, ['lama_main' => 90]);
        $pkg30 = CustomPackage::create(['nama_paket' => 'Paket 30m', 'harga_total' => 15000, 'is_active' => true]);
        $pkg30->playstations()->attach($catA->id, ['lama_main' => 30]);

        Carbon::setTestNow(Carbon::parse('2026-11-22 12:00:00'));
        $txPkg = $this->billingService->startSession([
            'device_id' => $device2->id,
            'playstation_id' => $catA->id,
            'nama' => 'Pelanggan Short Paket',
            'tipe_transaksi' => 'custom_package',
            'custom_package_id' => $pkg90->id,
        ], $this->kasir->id);

        Carbon::setTestNow(Carbon::parse('2026-11-22 12:45:00'));
        $this->actingAs($this->kasir)->postJson(route('transaction.editPreview', $txPkg->id_transaksi), [
            'device_id' => $device2->id,
            'tipe_transaksi' => 'custom_package',
            'custom_package_id' => $pkg30->id,
        ])->assertStatus(422)->assertJson([
            'valid' => false,
            'error_code' => 'duration_below_elapsed',
        ]);

        $this->actingAs($this->kasir)->putJson(route('transaction.update', $txPkg->id_transaksi), [
            'device_id' => $device2->id,
            'tipe_transaksi' => 'custom_package',
            'custom_package_id' => $pkg30->id,
        ])->assertStatus(422)->assertJson([
            'success' => false,
            'error_code' => 'duration_below_elapsed',
        ]);

        $txPkg->refresh();
        $this->assertSame(Transaction::BILLING_RUNNING, $txPkg->billing_status);
        $this->assertSame($pkg90->id, (int) $txPkg->custom_package_id);
        $this->assertSame('2026-11-22 13:30:00', $txPkg->expires_at->format('Y-m-d H:i:s'));
    }

    /**
     * CS-24 (Finding 4): edit.blade.php uses old('device_id') for category options & availability check,
     * honours old('playstation_id') & old('pricing_mode'), and triggers preview fetch on DOMContentLoaded when old input differs.
     */
    public function test_cs_24_edit_form_validation_redirect_honours_old_device_category_pricing_mode_and_refreshes_preview(): void
    {
        $cat1A = Playstation::create(['nama' => 'Cat Dev1 Alpha', 'harga' => 12000]);
        $cat1B = Playstation::create(['nama' => 'Cat Dev1 Beta', 'harga' => 14000]);
        $cat2A = Playstation::create(['nama' => 'Cat Dev2 Gamma', 'harga' => 18000]);
        $cat2B = Playstation::create(['nama' => 'Cat Dev2 Delta', 'harga' => 22000]);

        ['device' => $dev1] = $this->createStationWithHardware('Station OldInput 1', [$cat1A, $cat1B], '31', 131);
        ['device' => $dev2] = $this->createStationWithHardware('Station OldInput 2', [$cat2A, $cat2B], '32', 132);

        $tx = $this->billingService->startSession([
            'device_id' => $dev1->id,
            'playstation_id' => $cat1A->id,
            'nama' => 'Pelanggan OldInput',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 2,
            'duration_seconds' => 7200,
        ], $this->kasir->id);

        Carbon::setTestNow(Carbon::parse('2026-11-22 10:45:00'));

        // Submit invalid shortened duration while switching to Device 2, Cat2B, and Mode A (correction)
        $response = $this->actingAs($this->kasir)
            ->from(route('transaction.edit', $tx->id_transaksi))
            ->followingRedirects()
            ->put(route('transaction.update', $tx->id_transaksi), [
                'device_id' => $dev2->id,
                'playstation_id' => $cat2B->id,
                'tipe_transaksi' => 'prepaid',
                'pricing_mode' => 'correction',
                'duration_seconds' => 1800,
                'jam_main' => '00:30:00',
            ]);

        $response->assertOk();
        $html = $response->getContent();

        // Device 2 options must be rendered inside #playstation_id (Cat Dev2 Gamma / Delta, NOT Cat Dev1 Alpha / Beta)
        preg_match('/<select[^>]*id="playstation_id"[^>]*>(.*?)<\/select>/s', $html, $psSelectMatch);
        $this->assertNotEmpty($psSelectMatch[1] ?? null);
        $psOptionsHtml = $psSelectMatch[1];

        $this->assertStringContainsString('Cat Dev2 Gamma', $psOptionsHtml);
        $this->assertStringContainsString('Cat Dev2 Delta', $psOptionsHtml);
        $this->assertStringNotContainsString('Cat Dev1 Alpha', $psOptionsHtml);
        $this->assertStringNotContainsString('Cat Dev1 Beta', $psOptionsHtml);
        $this->assertMatchesRegularExpression('/<option\s+value="' . $cat2B->id . '"[^>]*selected/s', $psOptionsHtml);
        $this->assertMatchesRegularExpression('/id="pricing_mode_correction"[^>]*checked/s', $html);
        $this->assertStringContainsString('const shouldRefreshPreviewOnLoad = Boolean(true);', $html);
        $this->assertStringContainsString('fetchServerEditPreview();', $html);

        // Also verify unavailable check uses old('device_id'): detach cat1A on dev1 so dev1 has unavailable category,
        // then redirect back with old('device_id') = dev2 and valid cat2A -> #playstation_id must NOT be marked aria-invalid
        $dev1->playstations()->detach($cat1A->id);
        $resUnavailableRedirect = $this->actingAs($this->kasir)
            ->from(route('transaction.edit', $tx->id_transaksi))
            ->followingRedirects()
            ->put(route('transaction.update', $tx->id_transaksi), [
                'device_id' => $dev2->id,
                'playstation_id' => $cat2A->id,
                'tipe_transaksi' => 'prepaid',
                'pricing_mode' => 'from_now',
                'duration_seconds' => 1800,
            ]);
        $resUnavailableRedirect->assertOk();
        $html2 = $resUnavailableRedirect->getContent();
        preg_match('/<select[^>]*id="playstation_id"[^>]*>/s', $html2, $psTagMatch);
        $this->assertNotEmpty($psTagMatch[0] ?? null);
        $this->assertStringNotContainsString('aria-invalid="true"', $psTagMatch[0]);
    }

    /**
     * CS-25 (Minor M1-M6): Eager loading & relationLoaded preset lookup, segment snapshot/device_id sync,
     * show.blade.php cross-midnight & summary figures, extendSession unpaid FnB, store pivot fallback & invoice guards,
     * and ended edit form disabling.
     */
    public function test_cs_25_minor_fixes_eager_loading_snapshots_show_fnb_guards_and_ended_form_disabled(): void
    {
        // M1: Playstation::getPriceForDuration uses loaded hourlyPrices without extra DB queries
        $ps1 = Playstation::create(['nama' => 'PS5 M1', 'harga' => 20000]);
        $ps2 = Playstation::create(['nama' => 'PS5 Pro M1', 'harga' => 30000]);
        HourlyPrice::create([
            'playstation_id' => $ps1->id,
            'hour' => 2,
            'duration_seconds' => 7200,
            'price' => 36000,
        ]);
        $loadedPs = Playstation::with('hourlyPrices')->findOrFail($ps1->id);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->assertSame(36000, $loadedPs->getPriceForDuration(7200));
        $this->assertSame(36000, $loadedPs->getPriceForDuration(7200));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(0, $queries, 'getPriceForDuration must use loaded hourlyPrices without executing SQL queries');

        // M5 store(): device with legacy playstation_id pointing to $ps2 while pivot has [$ps1] only -> store() without playstation_id uses getAvailableCategories()->first() ($ps1)
        $pivotOnlyDev = Device::create([
            'nama' => 'Pivot Priority Station',
            'playstation_id' => $ps2->id,
            'status' => 'Tersedia',
            'session_generation' => 0,
        ]);
        $pivotOnlyDev->playstations()->sync([$ps1->id]);

        $this->actingAs($this->kasir)->post(route('transaction.store'), [
            'nama' => 'Pelanggan Pivot Fallback',
            'device_id' => $pivotOnlyDev->id,
            'tipe_transaksi' => 'postpaid',
        ])->assertRedirect();
        $pivotTx = Transaction::where('nama', 'Pelanggan Pivot Fallback')->firstOrFail();
        $this->assertSame($ps1->id, (int) $pivotTx->playstation_id);
        $this->assertSame(20000.0, (float) $pivotTx->harga);

        // M2 part 2: postpaid device-only move with segments updates open segment's device_id
        ['device' => $ltDev1] = $this->createStationWithHardware('M2 LT 1', [$ps1, $ps2], '33', 133);
        ['device' => $ltDev2] = $this->createStationWithHardware('M2 LT 2', [$ps1, $ps2], '34', 134);
        $ltTx = $this->billingService->startSession([
            'device_id' => $ltDev1->id,
            'playstation_id' => $ps1->id,
            'nama' => 'Pelanggan M2 LT',
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        Carbon::setTestNow(Carbon::parse('2026-11-22 10:30:00'));
        $this->actingAs($this->kasir)->putJson(route('transaction.update', $ltTx->id_transaksi), [
            'device_id' => $ltDev1->id,
            'playstation_id' => $ps2->id,
            'tipe_transaksi' => 'postpaid',
            'pricing_mode' => 'from_now',
        ])->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-11-22 10:40:00'));
        $this->actingAs($this->kasir)->putJson(route('transaction.update', $ltTx->id_transaksi), [
            'device_id' => $ltDev2->id,
            'playstation_id' => $ps2->id,
            'tipe_transaksi' => 'postpaid',
            'pricing_mode' => 'from_now',
        ])->assertOk();

        $openLtSeg = TransactionPricingSegment::where('transaction_id', $ltTx->id_transaksi)
            ->whereNull('ended_at')
            ->firstOrFail();
        $this->assertSame($ltDev2->id, (int) $openLtSeg->device_id);

        // M2 part 1 & M4: re-editing segmented prepaid without category change syncs hourly_rate_snapshot;
        // extendSession uses uninvoiced_legacy_fnb_total + unpaid_fnb_order_total
        Carbon::setTestNow(Carbon::parse('2026-11-22 11:00:00'));
        ['device' => $timerDev] = $this->createStationWithHardware('M2 Timer Dev', [$ps1, $ps2], '35', 135);
        $locTimer = Location::create([
            'name' => 'Loc M4',
            'code' => 'LOC-M4',
            'type' => 'station',
            'device_id' => $timerDev->id,
            'is_active' => true,
        ]);
        $timerTx = $this->billingService->startSession([
            'device_id' => $timerDev->id,
            'playstation_id' => $ps1->id,
            'nama' => 'Pelanggan M2 M4',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 2,
            'duration_seconds' => 7200,
        ], $this->kasir->id);

        Carbon::setTestNow(Carbon::parse('2026-11-22 11:30:00'));
        $this->actingAs($this->kasir)->putJson(route('transaction.update', $timerTx->id_transaksi), [
            'device_id' => $timerDev->id,
            'playstation_id' => $ps2->id,
            'tipe_transaksi' => 'prepaid',
            'pricing_mode' => 'from_now',
            'duration_seconds' => 7200,
        ])->assertOk();

        // Update master price of ps2 (30k -> 34k) and re-edit duration (3h) without category change
        $ps2->update(['harga' => 34000]);
        $this->actingAs($this->kasir)->putJson(route('transaction.update', $timerTx->id_transaksi), [
            'device_id' => $timerDev->id,
            'playstation_id' => $ps2->id,
            'tipe_transaksi' => 'prepaid',
            'pricing_mode' => 'from_now',
            'duration_seconds' => 10800,
        ])->assertOk();

        $lastSeg = TransactionPricingSegment::where('transaction_id', $timerTx->id_transaksi)
            ->orderByDesc('segment_order')
            ->firstOrFail();
        $this->assertSame(34000.0, (float) $lastSeg->hourly_rate_snapshot);

        // Attach an unpaid FnbOrder (7,000) and call extendSession (+1h)
        $fnbSnack = Fnb::create(['nama' => 'Kacang M4', 'harga_beli' => 3000, 'harga_jual' => 7000, 'stok' => 10]);
        $fnbOrd = FnbOrder::create([
            'order_number' => 'ORD-M4-1',
            'source' => FnbOrder::SOURCE_QR,
            'location_id' => $locTimer->id,
            'transaction_id' => $timerTx->id_transaksi,
            'customer_name' => $timerTx->nama,
            'status' => FnbOrder::STATUS_ACCEPTED,
            'payment_status' => 'unpaid',
        ]);
        FnbOrderItem::create([
            'fnb_order_id' => $fnbOrd->id,
            'fnb_id' => $fnbSnack->id,
            'qty' => 1,
            'unit_price_beli' => 3000,
            'unit_price_jual' => 7000,
            'subtotal' => 7000,
            'payment_status' => 'unpaid',
        ]);

        $extended = $this->billingService->extendSession($timerTx->id_transaksi, 1);
        // Seg1: 0.5h @ 20k = 10,000; Seg2: 3.5h @ 34k = 119,000 -> rental 129,000 + 7,000 unpaid FnB = 136,000
        $this->assertSame(136000.0, (float) $extended->total);

        // M3: show.blade.php cross-midnight duration & BillingService summary figures
        Carbon::setTestNow(Carbon::parse('2026-11-22 23:00:00'));
        ['device' => $showDev] = $this->createStationWithHardware('M3 Show Dev', [$ps1], '36', 136);
        $showLoc = Location::create([
            'name' => 'Loc M3',
            'code' => 'LOC-M3',
            'type' => 'station',
            'device_id' => $showDev->id,
            'is_active' => true,
        ]);
        $showTx = $this->billingService->startSession([
            'device_id' => $showDev->id,
            'playstation_id' => $ps1->id,
            'nama' => 'Pelanggan M3 CrossMidnight',
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        $showOrd = FnbOrder::create([
            'order_number' => 'ORD-M3-1',
            'source' => FnbOrder::SOURCE_QR,
            'location_id' => $showLoc->id,
            'transaction_id' => $showTx->id_transaksi,
            'customer_name' => $showTx->nama,
            'status' => FnbOrder::STATUS_ACCEPTED,
            'payment_status' => 'unpaid',
        ]);
        FnbOrderItem::create([
            'fnb_order_id' => $showOrd->id,
            'fnb_id' => $fnbSnack->id,
            'qty' => 1,
            'unit_price_beli' => 3000,
            'unit_price_jual' => 7000,
            'subtotal' => 7000,
            'payment_status' => 'unpaid',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-11-23 01:30:00'));
        $this->actingAs($this->kasir)->post(route('transaction.end', $showTx->id_transaksi));

        // 2h30m on $ps1 (2h preset 36,000 + 30m @ 20k = 46,000 rental) + 7,000 FnB
        $showRes = $this->actingAs($this->kasir)->get(route('transaction.show', $showTx->id_transaksi));
        $showRes->assertOk()
            ->assertSee('2 jam 30 menit 0 detik', false)
            ->assertSee('Rp 46.000', false)
            ->assertSee('Rp 7.000', false);

        // M5: hasGameSettlementInvoice guards FnB mutations & destroy even if payment_status somehow unpaid
        $invLockedTx = Transaction::create([
            'status' => 'user',
            'nama' => 'Pelanggan Settlement Guard',
            'device_id' => $showDev->id,
            'playstation_id' => $ps1->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'prepaid',
            'harga' => 20000,
            'jam_main' => 1,
            'duration_seconds' => 3600,
            'waktu_mulai' => '10:00',
            'waktu_Selesai' => '11:00',
            'status_transaksi' => 'sukses',
            'payment_status' => 'unpaid',
            'total' => 20000,
        ]);
        TransactionFnb::create([
            'transaction_id' => $invLockedTx->id_transaksi,
            'fnb_id' => $fnbSnack->id,
            'qty' => 1,
            'harga_beli' => 3000,
            'harga_jual' => 7000,
        ]);
        Invoice::create([
            'invoice_number' => 'INV-GUARD-TEST-01',
            'transaction_id' => $invLockedTx->id_transaksi,
            'invoice_type' => Invoice::TYPE_GAME_ONLY,
            'subtotal' => 20000,
            'discount' => 0,
            'final_total' => 20000,
            'amount_paid' => 20000,
            'change_amount' => 0,
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->kasir->id,
            'paid_at' => Carbon::now(),
        ]);

        $this->actingAs($this->kasir)->get(route('transaction.add-order', $invLockedTx->id_transaksi))
            ->assertRedirect(route('transaction.index'));
        $this->actingAs($this->kasir)->post(route('transaction.store-order', $invLockedTx->id_transaksi), [
            'items' => [['fnb_id' => $fnbSnack->id, 'qty' => 1]],
        ])->assertRedirect(route('transaction.index'));
        $this->actingAs($this->kasir)->postJson(route('transaction.fnb.add', $invLockedTx->id_transaksi), [
            'fnb_id' => $fnbSnack->id,
            'qty' => 1,
        ])->assertStatus(403);
        $this->actingAs($this->kasir)->putJson(route('transaction.fnb.update', [
            'id' => $invLockedTx->id_transaksi,
            'fnbId' => $fnbSnack->id,
        ]), ['qty' => 2])->assertStatus(403);
        $this->actingAs($this->kasir)->deleteJson(route('transaction.fnb.delete', [
            'id' => $invLockedTx->id_transaksi,
            'fnbId' => $fnbSnack->id,
        ]))->assertStatus(403);
        $this->actingAs($this->kasir)->delete(route('transaction.destroy', $invLockedTx->id_transaksi))
            ->assertRedirect('transaction')
            ->assertSessionHas('gagal');
        $this->assertDatabaseHas('transactions', ['id_transaksi' => $invLockedTx->id_transaksi]);

        // M6: edit.blade.php disables submit button & repricing inputs when $isSessionEnded
        $endedEditRes = $this->actingAs($this->kasir)->get(route('transaction.edit', $showTx->id_transaksi));
        $endedEditRes->assertOk();
        $endedHtml = $endedEditRes->getContent();
        $this->assertMatchesRegularExpression('/<select[^>]*id="tipe_transaksi"[^>]*disabled/s', $endedHtml);
        $this->assertMatchesRegularExpression('/<select[^>]*id="device_id"[^>]*disabled/s', $endedHtml);
        $this->assertMatchesRegularExpression('/<select[^>]*id="playstation_id"[^>]*disabled/s', $endedHtml);
        $this->assertMatchesRegularExpression('/<input[^>]*id="pricing_mode_from_now"[^>]*disabled/s', $endedHtml);
        $this->assertMatchesRegularExpression('/<input[^>]*id="pricing_mode_correction"[^>]*disabled/s', $endedHtml);
        $this->assertMatchesRegularExpression('/<select[^>]*id="custom_package_id"[^>]*disabled/s', $endedHtml);
        $this->assertMatchesRegularExpression('/<select[^>]*id="jam_main"[^>]*disabled/s', $endedHtml);
        $this->assertMatchesRegularExpression('/<button[^>]*type="submit"[^>]*disabled/s', $endedHtml);
    }
}
