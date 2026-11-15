<?php

namespace Tests\Feature\Fnb;

use App\Exports\FnbSalesExport;
use App\Models\Device;
use App\Models\Fnb;
use App\Models\FnbOrder;
use App\Models\FnbOrderItem;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\Playstation;
use App\Models\PriceGroup;
use App\Models\Transaction;
use App\Models\TransactionFnb;
use App\Models\User;
use App\Services\Billing\LostTimeBillingService;
use App\Services\Billing\PaketBillingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class FnbSalesReportingTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $kasir;
    protected Playstation $ps5;
    protected Playstation $ps4;
    protected Device $devicePs5;
    protected Device $devicePs4;
    protected Location $locationPs5;
    protected Fnb $fnbSnack;
    protected Fnb $fnbDrink;
    protected PriceGroup $priceGroup;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-15 14:00:00'));

        $this->admin = User::factory()->create([
            'name' => 'Admin Report',
            'role' => 'admin',
            'email' => 'admin-report@test.local',
        ]);

        $this->kasir = User::factory()->create([
            'name' => 'Kasir Report',
            'role' => 'kasir',
            'is_fnb_staff' => false,
            'email' => 'kasir-report@test.local',
        ]);

        $this->ps5 = Playstation::create([
            'nama' => 'PS5',
            'harga' => 15000,
        ]);

        $this->ps4 = Playstation::create([
            'nama' => 'PS4',
            'harga' => 10000,
        ]);

        $this->devicePs5 = Device::create([
            'nama' => 'PS5-01',
            'playstation_id' => $this->ps5->id,
            'status' => 'Tersedia',
        ]);
        $this->devicePs5->playstations()->attach($this->ps5->id);

        $this->devicePs4 = Device::create([
            'nama' => 'PS4-01',
            'playstation_id' => $this->ps4->id,
            'status' => 'Tersedia',
        ]);
        $this->devicePs4->playstations()->attach($this->ps4->id);

        // PS5 station has a Location; PS4 station intentionally has no Location
        $this->locationPs5 = Location::create([
            'name' => 'Bilik PS5 01',
            'code' => 'BILIK-PS5-01',
            'type' => 'station',
            'device_id' => $this->devicePs5->id,
            'is_active' => true,
        ]);

        $this->priceGroup = PriceGroup::create([
            'nama' => 'Minuman',
            'harga' => 8000,
        ]);

        $this->fnbSnack = Fnb::create([
            'nama' => 'Indomie Goreng',
            'harga_beli' => 4000,
            'harga_jual' => 10000,
            'stok' => 50,
        ]);

        $this->fnbDrink = Fnb::create([
            'nama' => 'Es Teh Manis',
            'harga_beli' => 2000,
            'harga_jual' => 8000,
            'price_group_id' => $this->priceGroup->id,
            'stok' => 50,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @test */
    public function all_fnb_sales_channels_are_reported_without_double_counting()
    {
        // 1. Paket upfront WITH Location (creates TransactionFnb + FnbOrderItem + InvoiceItem)
        //    Snack qty 2 @ 10.000 (beli 4.000) => 20.000
        $paketTx = Transaction::create([
            'nama' => 'Customer Paket',
            'device_id' => $this->devicePs5->id,
            'playstation_id' => $this->ps5->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'prepaid',
            'status' => 'Berjalan',
            'status_transaksi' => 'draft',
            'payment_status' => 'unpaid',
            'harga' => 15000,
            'jam_main' => '2',
            'total' => 50000, // 30.000 PS + 20.000 FnB
            'waktu_mulai' => '12:00',
        ]);
        TransactionFnb::create([
            'transaction_id' => $paketTx->id_transaksi,
            'fnb_id' => $this->fnbSnack->id,
            'qty' => 2,
            'harga_jual' => 10000,
            'harga_beli' => 4000,
        ]);

        app(PaketBillingService::class)->activatePaidPaket($paketTx, [
            'payment_method' => 'tunai',
            'amount_paid' => 50000,
            'cashier_user_id' => $this->kasir->id,
        ]);

        // 2. Paket extra F&B (TYPE_FNB_STANDALONE linked to $paketTx)
        //    Drink qty 1 @ 8.000 (beli 2.000) => 8.000
        $paketExtraOrder = FnbOrder::create([
            'order_number' => 'ORD-PKT-EXT-01',
            'source' => FnbOrder::SOURCE_CASHIER,
            'location_id' => $this->locationPs5->id,
            'transaction_id' => $paketTx->id_transaksi,
            'customer_name' => 'Customer Paket',
            'status' => FnbOrder::STATUS_PENDING,
            'payment_status' => 'unpaid',
            'requires_upfront_payment' => true,
            'created_by_user_id' => $this->kasir->id,
        ]);
        FnbOrderItem::create([
            'fnb_order_id' => $paketExtraOrder->id,
            'fnb_id' => $this->fnbDrink->id,
            'qty' => 1,
            'unit_price_beli' => 2000,
            'unit_price_jual' => 8000,
            'subtotal' => 8000,
            'payment_status' => 'unpaid',
        ]);
        app(LostTimeBillingService::class)->settleFnbOrder($paketExtraOrder->id, [
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->kasir->id,
        ]);

        // 3. Lost Time early F&B (TYPE_FNB_STANDALONE linked to $lostTimeTx)
        //    Snack qty 1 @ 10.000 (beli 4.000) => 10.000
        $lostTimeTx = Transaction::create([
            'nama' => 'Customer LostTime',
            'device_id' => $this->devicePs4->id,
            'playstation_id' => $this->ps4->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'postpaid',
            'billing_status' => Transaction::BILLING_RUNNING,
            'status' => 'Berjalan',
            'status_transaksi' => 'berjalan',
            'payment_status' => 'unpaid',
            'harga' => 10000,
            'total' => 0,
            'waktu_mulai' => '13:00',
            'started_at' => Carbon::now()->subHour(),
            'session_token' => Str::random(32),
        ]);

        $earlyOrder = FnbOrder::create([
            'order_number' => 'ORD-LT-EARLY-01',
            'source' => FnbOrder::SOURCE_QR,
            'transaction_id' => $lostTimeTx->id_transaksi,
            'customer_name' => 'Customer LostTime',
            'status' => FnbOrder::STATUS_ACCEPTED,
            'payment_status' => 'unpaid',
            'requires_upfront_payment' => false,
            'created_by_user_id' => $this->kasir->id,
        ]);
        FnbOrderItem::create([
            'fnb_order_id' => $earlyOrder->id,
            'fnb_id' => $this->fnbSnack->id,
            'qty' => 1,
            'unit_price_beli' => 4000,
            'unit_price_jual' => 10000,
            'subtotal' => 10000,
            'payment_status' => 'unpaid',
        ]);
        app(LostTimeBillingService::class)->settleFnbOrder($earlyOrder->id, [
            'payment_method' => 'qris' === 'qris' ? 'e-wallet' : 'tunai',
            'cashier_user_id' => $this->kasir->id,
        ]);

        // 4. Lost Time final checkout (unpaid FnbOrderItem => InvoiceItem in TYPE_GAME_AND_FNB)
        //    Drink qty 2 @ 8.000 (beli 2.000) => 16.000
        $finalOrder = FnbOrder::create([
            'order_number' => 'ORD-LT-FINAL-01',
            'source' => FnbOrder::SOURCE_QR,
            'transaction_id' => $lostTimeTx->id_transaksi,
            'customer_name' => 'Customer LostTime',
            'status' => FnbOrder::STATUS_DELIVERED,
            'payment_status' => 'unpaid',
            'requires_upfront_payment' => false,
            'created_by_user_id' => $this->kasir->id,
        ]);
        FnbOrderItem::create([
            'fnb_order_id' => $finalOrder->id,
            'fnb_id' => $this->fnbDrink->id,
            'qty' => 2,
            'unit_price_beli' => 2000,
            'unit_price_jual' => 8000,
            'subtotal' => 16000,
            'payment_status' => 'unpaid',
        ]);
        app(LostTimeBillingService::class)->checkoutLostTime($lostTimeTx->id_transaksi, [
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->kasir->id,
        ]);

        // 5. Non-playing walk-in standalone F&B (transaction_id = null)
        //    Snack qty 3 @ 10.000 (beli 4.000) => 30.000
        $walkinOrder = FnbOrder::create([
            'order_number' => 'ORD-WALKIN-01',
            'source' => FnbOrder::SOURCE_CASHIER,
            'transaction_id' => null,
            'customer_name' => 'Walk-in Buyer',
            'status' => FnbOrder::STATUS_PENDING,
            'payment_status' => 'unpaid',
            'requires_upfront_payment' => true,
            'created_by_user_id' => $this->kasir->id,
        ]);
        FnbOrderItem::create([
            'fnb_order_id' => $walkinOrder->id,
            'fnb_id' => $this->fnbSnack->id,
            'qty' => 3,
            'unit_price_beli' => 4000,
            'unit_price_jual' => 10000,
            'subtotal' => 30000,
            'payment_status' => 'unpaid',
        ]);
        app(LostTimeBillingService::class)->settleFnbOrder($walkinOrder->id, [
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->kasir->id,
        ]);

        // Expected totals:
        // Indomie Goreng: 2 (paket) + 1 (LT early) + 3 (walk-in) = 6 qty
        //   modal = 6 * 4.000 = 24.000; penjualan = 6 * 10.000 = 60.000; laba = 36.000
        // Es Teh Manis: 1 (paket extra) + 2 (LT final) = 3 qty
        //   modal = 3 * 2.000 = 6.000; penjualan = 3 * 8.000 = 24.000; laba = 18.000

        $response = $this->actingAs($this->admin)
            ->get(route('fnb.laporan', ['period' => 'today']));

        $response->assertStatus(200);
        $salesData = collect($response->viewData('salesData'))->keyBy('nama');

        $this->assertCount(2, $salesData);

        $snack = $salesData->get('Indomie Goreng');
        $this->assertNotNull($snack);
        $this->assertEquals(6, (int) $snack->jumlah_terjual);
        $this->assertEquals(24000, (float) $snack->total_modal);
        $this->assertEquals(60000, (float) $snack->total_penjualan);
        $this->assertEquals(36000, (float) $snack->laba_rugi);

        $drink = $salesData->get('Es Teh Manis');
        $this->assertNotNull($drink);
        $this->assertEquals(3, (int) $drink->jumlah_terjual);
        $this->assertEquals(6000, (float) $drink->total_modal);
        $this->assertEquals(24000, (float) $drink->total_penjualan);
        $this->assertEquals(18000, (float) $drink->laba_rugi);

        $chartData = $response->viewData('chartData');
        $this->assertEquals(['2026-11-15' => 9], $chartData['sales_over_time']);

        // PDF export succeeds (fixes broken transactions.id join)
        $pdfResponse = $this->actingAs($this->admin)
            ->get(route('fnb.laporan.pdf', ['period' => 'today']));
        $pdfResponse->assertStatus(200);

        // Excel export passes identical deduplicated rows
        Excel::fake();
        $excelResponse = $this->actingAs($this->admin)
            ->get(route('fnb.laporan.excel', ['period' => 'today']));
        $excelResponse->assertStatus(200);
        Excel::assertDownloaded('laporan_penjualan_fnb.xlsx', function (FnbSalesExport $export) {
            $reflection = new \ReflectionClass($export);
            $prop = $reflection->getProperty('salesData');
            $prop->setAccessible(true);
            $rows = collect($prop->getValue($export))->keyBy('nama');

            return $rows->count() === 2
                && (int) $rows['Indomie Goreng']->jumlah_terjual === 6
                && (float) $rows['Indomie Goreng']->total_penjualan === 60000.0
                && (int) $rows['Es Teh Manis']->jumlah_terjual === 3
                && (float) $rows['Es Teh Manis']->total_penjualan === 24000.0;
        });
    }

    /** @test */
    public function legacy_paid_transaction_fnbs_without_invoice_items_are_still_reported_and_unpaid_are_excluded()
    {
        // Legacy paid transaction WITHOUT Invoice / InvoiceItem
        $legacyPaidTx = Transaction::create([
            'nama' => 'Legacy Paid',
            'device_id' => $this->devicePs4->id,
            'playstation_id' => $this->ps4->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'prepaid',
            'status' => 'Selesai',
            'status_transaksi' => 'selesai',
            'payment_status' => 'paid',
            'paid_at' => Carbon::now(),
            'harga' => 10000,
            'jam_main' => '1',
            'total' => 25000,
            'waktu_mulai' => '10:00',
        ]);
        TransactionFnb::create([
            'transaction_id' => $legacyPaidTx->id_transaksi,
            'fnb_id' => $this->fnbSnack->id,
            'qty' => 2,
            'harga_jual' => 7500, // historical price
            'harga_beli' => 3500, // historical cost on transaction_fnbs
        ]);

        // Unpaid transaction with TransactionFnb must NOT be counted
        $unpaidTx = Transaction::create([
            'nama' => 'Unpaid Session',
            'device_id' => $this->devicePs4->id,
            'playstation_id' => $this->ps4->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'postpaid',
            'status' => 'Berjalan',
            'status_transaksi' => 'berjalan',
            'payment_status' => 'unpaid',
            'harga' => 10000,
            'total' => 0,
            'waktu_mulai' => '13:00',
        ]);
        TransactionFnb::create([
            'transaction_id' => $unpaidTx->id_transaksi,
            'fnb_id' => $this->fnbSnack->id,
            'qty' => 5,
            'harga_jual' => 10000,
            'harga_beli' => 4000,
        ]);

        // Also Paket upfront WITHOUT Location (InvoiceItem linked via transaction_fnb_id)
        $paketNoLocTx = Transaction::create([
            'nama' => 'Paket No Loc',
            'device_id' => $this->devicePs4->id,
            'playstation_id' => $this->ps4->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'prepaid',
            'status' => 'Berjalan',
            'status_transaksi' => 'draft',
            'payment_status' => 'unpaid',
            'harga' => 10000,
            'jam_main' => '1',
            'total' => 20000,
            'waktu_mulai' => '11:00',
        ]);
        TransactionFnb::create([
            'transaction_id' => $paketNoLocTx->id_transaksi,
            'fnb_id' => $this->fnbSnack->id,
            'qty' => 1,
            'harga_jual' => 10000,
            'harga_beli' => 4000,
        ]);
        app(PaketBillingService::class)->activatePaidPaket($paketNoLocTx, [
            'payment_method' => 'tunai',
            'amount_paid' => 20000,
            'cashier_user_id' => $this->kasir->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('fnb.laporan', ['period' => 'today']));

        $response->assertStatus(200);
        $salesData = collect($response->viewData('salesData'))->keyBy('nama');
        $snack = $salesData->get('Indomie Goreng');

        $this->assertNotNull($snack);
        // 2 (legacy paid) + 1 (paketNoLoc via InvoiceItem, NOT duplicated from TransactionFnb) = 3 qty
        $this->assertEquals(3, (int) $snack->jumlah_terjual);
        // modal = (2 * 3500) + (1 * 4000) = 11000
        $this->assertEquals(11000, (float) $snack->total_modal);
        // penjualan = (2 * 7500) + (1 * 10000) = 25000
        $this->assertEquals(25000, (float) $snack->total_penjualan);
        // laba = 25000 - 11000 = 14000
        $this->assertEquals(14000, (float) $snack->laba_rugi);

        // Ensure exportExcel & exportPdf work on legacy + invoice items (verifies transactions.id_transaksi fix)
        $this->actingAs($this->admin)->get(route('fnb.laporan.pdf', ['period' => 'today']))->assertStatus(200);
        $this->actingAs($this->admin)->get(route('fnb.laporan.excel', ['period' => 'today']))->assertStatus(200);
    }

    /** @test */
    public function historical_fnb_reports_preserve_price_snapshots_when_master_prices_change()
    {
        $walkinOrder = FnbOrder::create([
            'order_number' => 'ORD-SNAP-01',
            'source' => FnbOrder::SOURCE_CASHIER,
            'transaction_id' => null,
            'customer_name' => 'Snapshot Buyer',
            'status' => FnbOrder::STATUS_PENDING,
            'payment_status' => 'unpaid',
            'requires_upfront_payment' => true,
            'created_by_user_id' => $this->kasir->id,
        ]);
        FnbOrderItem::create([
            'fnb_order_id' => $walkinOrder->id,
            'fnb_id' => $this->fnbDrink->id,
            'qty' => 2,
            'unit_price_beli' => 2000,
            'unit_price_jual' => 8000,
            'subtotal' => 16000,
            'payment_status' => 'unpaid',
        ]);
        app(LostTimeBillingService::class)->settleFnbOrder($walkinOrder->id, [
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->kasir->id,
        ]);

        // Mutate master prices AFTER checkout
        $this->priceGroup->update(['harga' => 25000]);
        $this->fnbDrink->update([
            'harga_beli' => 9000,
            'harga_jual' => 25000,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('fnb.laporan', ['period' => 'today']));

        $response->assertStatus(200);
        $drink = collect($response->viewData('salesData'))->keyBy('nama')->get('Es Teh Manis');

        $this->assertNotNull($drink);
        $this->assertEquals(2, (int) $drink->jumlah_terjual);
        $this->assertEquals(4000, (float) $drink->total_modal);
        $this->assertEquals(16000, (float) $drink->total_penjualan);
        $this->assertEquals(12000, (float) $drink->laba_rugi);
    }

    /** @test */
    public function report_controller_includes_standalone_fnb_without_double_counting_game_invoices_and_respects_ps_filter()
    {
        // 1. PS5 Paket upfront: 50.000 (30k rental + 20k FnB) -> Transaction.total = 50.000 + Invoice TYPE_GAME_AND_FNB = 50.000
        $paketTx = Transaction::create([
            'nama' => 'Paket PS5',
            'device_id' => $this->devicePs5->id,
            'playstation_id' => $this->ps5->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'prepaid',
            'status' => 'Berjalan',
            'status_transaksi' => 'draft',
            'payment_status' => 'unpaid',
            'harga' => 15000,
            'jam_main' => '2',
            'total' => 50000,
            'waktu_mulai' => '12:00',
        ]);
        TransactionFnb::create([
            'transaction_id' => $paketTx->id_transaksi,
            'fnb_id' => $this->fnbSnack->id,
            'qty' => 2,
            'harga_jual' => 10000,
            'harga_beli' => 4000,
        ]);
        app(PaketBillingService::class)->activatePaidPaket($paketTx, [
            'payment_method' => 'tunai',
            'amount_paid' => 50000,
            'cashier_user_id' => $this->kasir->id,
        ]);

        // 2. PS5 Paket extra F&B standalone: 8.000
        $paketExtra = FnbOrder::create([
            'order_number' => 'ORD-REP-EXT',
            'source' => FnbOrder::SOURCE_CASHIER,
            'location_id' => $this->locationPs5->id,
            'transaction_id' => $paketTx->id_transaksi,
            'customer_name' => 'Paket PS5',
            'status' => FnbOrder::STATUS_PENDING,
            'payment_status' => 'unpaid',
            'requires_upfront_payment' => true,
            'created_by_user_id' => $this->kasir->id,
        ]);
        FnbOrderItem::create([
            'fnb_order_id' => $paketExtra->id,
            'fnb_id' => $this->fnbDrink->id,
            'qty' => 1,
            'unit_price_beli' => 2000,
            'unit_price_jual' => 8000,
            'subtotal' => 8000,
            'payment_status' => 'unpaid',
        ]);
        app(LostTimeBillingService::class)->settleFnbOrder($paketExtra->id, [
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->kasir->id,
        ]);

        // 3. PS4 Lost Time: 1 hour (10.000) + early F&B standalone (10.000) + final unpaid F&B (16.000)
        //    Transaction.total at checkout = 26.000 (10.000 rental + 16.000 unpaid F&B)
        //    Early F&B standalone Invoice = 10.000
        $lostTimeTx = Transaction::create([
            'nama' => 'LostTime PS4',
            'device_id' => $this->devicePs4->id,
            'playstation_id' => $this->ps4->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'postpaid',
            'billing_status' => Transaction::BILLING_RUNNING,
            'status' => 'Berjalan',
            'status_transaksi' => 'berjalan',
            'payment_status' => 'unpaid',
            'harga' => 10000,
            'total' => 0,
            'waktu_mulai' => '13:00',
            'started_at' => Carbon::now()->subHour(),
            'session_token' => Str::random(32),
        ]);
        $ltEarly = FnbOrder::create([
            'order_number' => 'ORD-REP-LT-E',
            'source' => FnbOrder::SOURCE_QR,
            'transaction_id' => $lostTimeTx->id_transaksi,
            'customer_name' => 'LostTime PS4',
            'status' => FnbOrder::STATUS_ACCEPTED,
            'payment_status' => 'unpaid',
            'requires_upfront_payment' => false,
            'created_by_user_id' => $this->kasir->id,
        ]);
        FnbOrderItem::create([
            'fnb_order_id' => $ltEarly->id,
            'fnb_id' => $this->fnbSnack->id,
            'qty' => 1,
            'unit_price_beli' => 4000,
            'unit_price_jual' => 10000,
            'subtotal' => 10000,
            'payment_status' => 'unpaid',
        ]);
        app(LostTimeBillingService::class)->settleFnbOrder($ltEarly->id, [
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->kasir->id,
        ]);

        $ltFinal = FnbOrder::create([
            'order_number' => 'ORD-REP-LT-F',
            'source' => FnbOrder::SOURCE_QR,
            'transaction_id' => $lostTimeTx->id_transaksi,
            'customer_name' => 'LostTime PS4',
            'status' => FnbOrder::STATUS_DELIVERED,
            'payment_status' => 'unpaid',
            'requires_upfront_payment' => false,
            'created_by_user_id' => $this->kasir->id,
        ]);
        FnbOrderItem::create([
            'fnb_order_id' => $ltFinal->id,
            'fnb_id' => $this->fnbDrink->id,
            'qty' => 2,
            'unit_price_beli' => 2000,
            'unit_price_jual' => 8000,
            'subtotal' => 16000,
            'payment_status' => 'unpaid',
        ]);
        app(LostTimeBillingService::class)->checkoutLostTime($lostTimeTx->id_transaksi, [
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->kasir->id,
        ]);

        // 4. Non-playing walk-in standalone F&B: 30.000
        $walkin = FnbOrder::create([
            'order_number' => 'ORD-REP-WALKIN',
            'source' => FnbOrder::SOURCE_CASHIER,
            'transaction_id' => null,
            'customer_name' => 'Walk-in Guest',
            'status' => FnbOrder::STATUS_PENDING,
            'payment_status' => 'unpaid',
            'requires_upfront_payment' => true,
            'created_by_user_id' => $this->kasir->id,
        ]);
        FnbOrderItem::create([
            'fnb_order_id' => $walkin->id,
            'fnb_id' => $this->fnbSnack->id,
            'qty' => 3,
            'unit_price_beli' => 4000,
            'unit_price_jual' => 10000,
            'subtotal' => 30000,
            'payment_status' => 'unpaid',
        ]);
        app(LostTimeBillingService::class)->settleFnbOrder($walkin->id, [
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->kasir->id,
        ]);

        // 5. Unpaid PS5 transaction (must NOT be counted)
        Transaction::create([
            'nama' => 'Unpaid PS5',
            'device_id' => $this->devicePs5->id,
            'playstation_id' => $this->ps5->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'postpaid',
            'billing_status' => Transaction::BILLING_RUNNING,
            'status' => 'Berjalan',
            'status_transaksi' => 'berjalan',
            'payment_status' => 'unpaid',
            'harga' => 15000,
            'total' => 99000,
            'waktu_mulai' => '13:30',
        ]);

        // Expected total without PS filter:
        // Transactions: 50.000 (Paket PS5) + 26.000 (LT PS4) = 76.000
        // Standalone F&B Invoices: 8.000 (Paket extra) + 10.000 (LT early) + 30.000 (Walk-in) = 48.000
        // Grand Total = 124.000
        $resAll = $this->actingAs($this->admin)
            ->get(route('report', ['period' => 'today']));

        $resAll->assertStatus(200);
        $this->assertEquals(124000, (float) $resAll->viewData('dailyIncome'));
        $this->assertEquals(124000, (float) $resAll->viewData('monthlyIncome'));
        $this->assertEquals(124000, (float) $resAll->viewData('yearlyIncome'));
        $this->assertEquals(124000, (float) $resAll->viewData('periodIncome'));
        $this->assertEquals(124000, (float) array_sum($resAll->viewData('chartData')));

        // Filtered to PS5 only:
        // Transactions: 50.000 (Paket PS5)
        // Standalone F&B linked to PS5: 8.000 (Paket extra)
        // Walk-in (transaction_id=null) and PS4 excluded from periodIncome & chartDatasets
        $resPs5 = $this->actingAs($this->admin)
            ->get(route('report', [
                'period' => 'today',
                'playstation_types' => [$this->ps5->id],
            ]));

        $resPs5->assertStatus(200);
        $this->assertEquals(58000, (float) $resPs5->viewData('periodIncome'));
        $datasets = $resPs5->viewData('chartDatasets');
        $this->assertCount(1, $datasets);
        $this->assertEquals(58000, (float) array_sum($datasets[0]['data']));

        // Also check PDF, Excel, and transactionReport endpoints return 200 and include standalone F&B
        $this->actingAs($this->admin)
            ->get('/generate-pdf?period=today')
            ->assertStatus(200);

        $this->actingAs($this->admin)
            ->get(route('laporan.excel', ['period' => 'today']))
            ->assertStatus(200);

        $txReportRes = $this->actingAs($this->admin)
            ->get(route('transaction.report', [
                'start_datetime' => '2026-11-15T00:00',
                'end_datetime' => '2026-11-15T23:59',
            ]));
        $txReportRes->assertStatus(200);
        $txReportRes->assertSee('124.000');
    }
}
