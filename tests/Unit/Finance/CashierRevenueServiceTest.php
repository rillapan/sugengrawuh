<?php

namespace Tests\Unit\Finance;

use App\Models\Device;
use App\Models\Fnb;
use App\Models\FnbOrder;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Playstation;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\CashierRevenueService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashierRevenueServiceTest extends TestCase
{
    use RefreshDatabase;

    protected CashierRevenueService $service;
    protected User $billingCashier;
    protected User $fnbCashier;
    protected User $adminUser;

    protected Playstation $psConsole;
    protected Playstation $billiardTable;
    protected Device $psDevice;
    protected Device $billiardDevice;
    protected Fnb $snack;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new CashierRevenueService();

        $this->billingCashier = User::create([
            'name' => 'Budi Billing',
            'email' => 'budi@test.com',
            'password' => bcrypt('password123'),
            'role' => 'kasir',
            'is_fnb_staff' => false,
        ]);

        $this->fnbCashier = User::create([
            'name' => 'Siti FnB',
            'email' => 'siti@test.com',
            'password' => bcrypt('password123'),
            'role' => 'kasir',
            'is_fnb_staff' => true,
        ]);

        $this->adminUser = User::create([
            'name' => 'Owner Sugeng',
            'email' => 'owner@test.com',
            'password' => bcrypt('password123'),
            'role' => 'owner',
        ]);

        $this->psConsole = Playstation::create([
            'nama' => 'PS5 Pro Station 1',
            'harga' => 15000,
        ]);

        $this->billiardTable = Playstation::create([
            'nama' => 'Meja Billiard 01',
            'harga' => 30000,
        ]);

        $this->psDevice = Device::create([
            'nama' => 'PS5-01',
            'status' => 'Tersedia',
            'playstation_id' => $this->psConsole->id,
        ]);

        $this->billiardDevice = Device::create([
            'nama' => 'Billiard-01',
            'status' => 'Tersedia',
            'playstation_id' => $this->billiardTable->id,
        ]);

        $this->snack = Fnb::create([
            'nama' => 'Kentang Goreng',
            'harga_jual' => 15000,
            'harga_beli' => 8000,
            'stok' => 50,
            'status' => 'Tersedia',
        ]);
    }

    /**
     * AC-01: Pembayaran rental PS diterima Kasir Billing
     */
    public function test_individual_billing_cashier_summary(): void
    {
        $now = Carbon::parse('2026-11-21 14:00:00');

        Invoice::create([
            'invoice_number' => 'INV-PS-001',
            'subtotal' => 30000,
            'discount' => 0,
            'final_total' => 30000,
            'amount_paid' => 30000,
            'change_amount' => 0,
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->billingCashier->id,
            'paid_at' => $now,
        ]);

        Invoice::create([
            'invoice_number' => 'INV-PS-002',
            'subtotal' => 20000,
            'discount' => 0,
            'final_total' => 20000,
            'amount_paid' => 20000,
            'change_amount' => 0,
            'payment_method' => 'e-wallet',
            'cashier_user_id' => $this->billingCashier->id,
            'paid_at' => $now,
        ]);

        $summary = $this->service->getCashierRevenueSummary($now, $now, $this->billingCashier->id);

        $this->assertEquals(50000, $summary['total_net']);
        $this->assertEquals(2, $summary['invoice_count']);
        $this->assertEquals(30000, $summary['payment_methods']['tunai']);
        $this->assertEquals(20000, $summary['payment_methods']['e-wallet']);
        $this->assertEquals(0, $summary['payment_methods']['transfer_bank']);
        $this->assertEquals(CashierRevenueService::CHANNEL_BILLING, $summary['channel']);
    }

    /**
     * AC-02 & AC-04: Transaksi Cafe dibayar langsung ke Kasir F&B
     */
    public function test_fnb_cashier_revenue_is_isolated(): void
    {
        $now = Carbon::parse('2026-11-21 15:30:00');

        Invoice::create([
            'invoice_number' => 'INV-FNB-001',
            'subtotal' => 45000,
            'discount' => 5000,
            'final_total' => 40000,
            'amount_paid' => 50000,
            'change_amount' => 10000,
            'payment_method' => 'transfer_bank',
            'cashier_user_id' => $this->fnbCashier->id,
            'paid_at' => $now,
        ]);

        $fnbSummary = $this->service->getCashierRevenueSummary($now, $now, $this->fnbCashier->id);
        $billingSummary = $this->service->getCashierRevenueSummary($now, $now, $this->billingCashier->id);

        $this->assertEquals(40000, $fnbSummary['total_net']);
        $this->assertEquals(45000, $fnbSummary['total_gross']);
        $this->assertEquals(5000, $fnbSummary['total_discount']);
        $this->assertEquals(40000, $fnbSummary['payment_methods']['transfer_bank']);
        $this->assertEquals(CashierRevenueService::CHANNEL_FNB, $fnbSummary['channel']);

        // Billing cashier receives 0 from this F&B transaction
        $this->assertEquals(0, $billingSummary['total_net']);
        $this->assertEquals(0, $billingSummary['invoice_count']);
    }

    /**
     * AC-03 & AC-22: Combined Payment (Rental + Cafe dibayar di Kasir Billing)
     * Tidak terjadi double counting: Kasir Billing = 100%, Kasir F&B = 0%, Total Usaha = 100%.
     */
    public function test_combined_payment_anti_double_counting(): void
    {
        $now = Carbon::parse('2026-11-21 16:00:00');

        $tx = Transaction::create([
            'nama' => 'Pak Joko',
            'nama_pelanggan' => 'Pak Joko',
            'device_id' => $this->psDevice->id,
            'playstation_id' => $this->psConsole->id,
            'user_id' => $this->billingCashier->id,
            'tipe_transaksi' => 'postpaid',
            'status' => 'Selesai',
            'status_transaksi' => 'selesai',
            'billing_status' => Transaction::BILLING_STOPPED_EARLY,
            'payment_status' => 'paid',
            'waktu_mulai' => '14:00',
            'waktu_selesai' => '16:00',
            'harga' => 15000,
            'total' => 65000,
            'paid_at' => $now,
        ]);

        $invoice = Invoice::create([
            'invoice_number' => 'INV-COMBINED-001',
            'transaction_id' => $tx->id_transaksi,
            'invoice_type' => Invoice::TYPE_GAME_AND_FNB,
            'subtotal' => 65000, // 40.000 rental + 25.000 cafe
            'discount' => 0,
            'final_total' => 65000,
            'amount_paid' => 70000,
            'change_amount' => 5000,
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->billingCashier->id,
            'paid_at' => $now,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'item_type' => InvoiceItem::ITEM_TYPE_GAME_RENTAL,
            'name' => 'Rental PS5',
            'qty' => 1,
            'unit_price_jual' => 40000,
            'subtotal' => 40000,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'item_type' => InvoiceItem::ITEM_TYPE_FNB_ITEM,
            'name' => 'Kentang Goreng',
            'qty' => 1,
            'unit_price_jual' => 25000,
            'subtotal' => 25000,
        ]);

        $consolidated = $this->service->getChannelConsolidatedSummary($now, $now);
        $categories = $this->service->getCategoryRevenueSummary($now, $now);

        // Kasir Billing = Rp65.000, Kasir F&B = Rp0
        $this->assertEquals(65000, $consolidated['totals']['total_billing']);
        $this->assertEquals(0, $consolidated['totals']['total_fnb']);
        $this->assertEquals(65000, $consolidated['totals']['grand_total']);
        $this->assertTrue($consolidated['is_reconciled']);

        // Kategori penjualan: Rental PS = Rp40.000, Cafe = Rp25.000, Total = Rp65.000
        $this->assertEquals(40000, $categories['categories'][CashierRevenueService::CATEGORY_PLAYSTATION]['net']);
        $this->assertEquals(25000, $categories['categories'][CashierRevenueService::CATEGORY_CAFE]['net']);
        $this->assertEquals(0, $categories['categories'][CashierRevenueService::CATEGORY_BILLIARD]['net']);
        $this->assertEquals(65000, $categories['categories_net_sum']);
        $this->assertTrue($categories['is_balanced']);
    }

    /**
     * AC-08: Alokasi Diskon Proporsional pada tagihan gabungan
     * Rental Rp50.000 + Cafe Rp50.000 diskon Rp10.000 (total Rp90.000).
     * Masing-masing kategori neto tepat Rp45.000; total neto klop Rp90.000.
     */
    public function test_proportional_discount_allocation_exactness(): void
    {
        $now = Carbon::parse('2026-11-21 17:00:00');

        $tx = Transaction::create([
            'nama' => 'Diskon Test',
            'nama_pelanggan' => 'Diskon Test',
            'device_id' => $this->psDevice->id,
            'playstation_id' => $this->psConsole->id,
            'user_id' => $this->billingCashier->id,
            'tipe_transaksi' => 'postpaid',
            'status' => 'Selesai',
            'status_transaksi' => 'selesai',
            'billing_status' => Transaction::BILLING_STOPPED_EARLY,
            'payment_status' => 'paid',
            'waktu_mulai' => '15:00',
            'waktu_selesai' => '17:00',
            'harga' => 25000,
            'total' => 90000,
            'paid_at' => $now,
        ]);

        $invoice = Invoice::create([
            'invoice_number' => 'INV-DISC-001',
            'transaction_id' => $tx->id_transaksi,
            'invoice_type' => Invoice::TYPE_GAME_AND_FNB,
            'subtotal' => 100000,
            'discount' => 10000,
            'final_total' => 90000,
            'amount_paid' => 90000,
            'change_amount' => 0,
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->billingCashier->id,
            'paid_at' => $now,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'item_type' => InvoiceItem::ITEM_TYPE_GAME_RENTAL,
            'name' => 'Rental PS5 Pro',
            'qty' => 1,
            'unit_price_jual' => 50000,
            'subtotal' => 50000,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'item_type' => InvoiceItem::ITEM_TYPE_FNB_ITEM,
            'name' => 'Cafe Bundle',
            'qty' => 1,
            'unit_price_jual' => 50000,
            'subtotal' => 50000,
        ]);

        $categories = $this->service->getCategoryRevenueSummary($now, $now);

        $this->assertEquals(45000, $categories['categories'][CashierRevenueService::CATEGORY_PLAYSTATION]['net']);
        $this->assertEquals(45000, $categories['categories'][CashierRevenueService::CATEGORY_CAFE]['net']);
        $this->assertEquals(90000, $categories['categories_net_sum']);
        $this->assertEquals(90000, $categories['total_net']);
        $this->assertTrue($categories['is_balanced']);
    }

    /**
     * AC-09: Kategori Billiard vs PlayStation terpisah akurat via Playstation::getCategory()
     */
    public function test_billiard_category_separation(): void
    {
        $now = Carbon::parse('2026-11-21 18:00:00');

        $tx = Transaction::create([
            'nama' => 'Billiard Pelanggan',
            'nama_pelanggan' => 'Billiard Pelanggan',
            'device_id' => $this->billiardDevice->id,
            'playstation_id' => $this->billiardTable->id,
            'user_id' => $this->billingCashier->id,
            'tipe_transaksi' => 'postpaid',
            'status' => 'Selesai',
            'status_transaksi' => 'selesai',
            'billing_status' => Transaction::BILLING_STOPPED_EARLY,
            'payment_status' => 'paid',
            'waktu_mulai' => '16:00',
            'waktu_selesai' => '18:00',
            'harga' => 30000,
            'total' => 60000,
            'paid_at' => $now,
        ]);

        $invoice = Invoice::create([
            'invoice_number' => 'INV-BIL-001',
            'transaction_id' => $tx->id_transaksi,
            'invoice_type' => Invoice::TYPE_GAME_ONLY,
            'subtotal' => 60000,
            'discount' => 0,
            'final_total' => 60000,
            'amount_paid' => 60000,
            'change_amount' => 0,
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->billingCashier->id,
            'paid_at' => $now,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'item_type' => InvoiceItem::ITEM_TYPE_GAME_RENTAL,
            'name' => 'Rental Meja Billiard 01',
            'qty' => 1,
            'unit_price_jual' => 60000,
            'subtotal' => 60000,
        ]);

        $categories = $this->service->getCategoryRevenueSummary($now, $now);

        $this->assertEquals(60000, $categories['categories'][CashierRevenueService::CATEGORY_BILLIARD]['net']);
        $this->assertEquals(0, $categories['categories'][CashierRevenueService::CATEGORY_PLAYSTATION]['net']);
        $this->assertEquals(0, $categories['categories'][CashierRevenueService::CATEGORY_CAFE]['net']);
    }

    /**
     * AC-10 & AC-11: Transaksi historis lawas tanpa invoice masuk ke bucket UNATTRIBUTED
     * dan total konsolidasi akurat: Total Billing + Total F&B + Total Admin + Legacy = Grand Total.
     */
    public function test_channel_consolidation_with_legacy_unattributed(): void
    {
        $now = Carbon::parse('2026-11-21 19:00:00');

        // Invoice Billing = 30.000
        Invoice::create([
            'invoice_number' => 'INV-B1',
            'subtotal' => 30000,
            'discount' => 0,
            'final_total' => 30000,
            'amount_paid' => 30000,
            'change_amount' => 0,
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->billingCashier->id,
            'paid_at' => $now,
        ]);

        // Invoice F&B = 20.000
        Invoice::create([
            'invoice_number' => 'INV-F1',
            'subtotal' => 20000,
            'discount' => 0,
            'final_total' => 20000,
            'amount_paid' => 20000,
            'change_amount' => 0,
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->fnbCashier->id,
            'paid_at' => $now,
        ]);

        // Invoice Admin = 50.000
        Invoice::create([
            'invoice_number' => 'INV-A1',
            'subtotal' => 50000,
            'discount' => 0,
            'final_total' => 50000,
            'amount_paid' => 50000,
            'change_amount' => 0,
            'payment_method' => 'transfer_bank',
            'cashier_user_id' => $this->adminUser->id,
            'paid_at' => $now,
        ]);

        // Legacy paid transaction WITHOUT invoice = 25.000
        Transaction::create([
            'nama' => 'Legacy Customer',
            'nama_pelanggan' => 'Legacy Customer',
            'device_id' => $this->psDevice->id,
            'playstation_id' => $this->psConsole->id,
            'user_id' => $this->billingCashier->id,
            'tipe_transaksi' => 'prepaid',
            'status' => 'Selesai',
            'status_transaksi' => 'selesai',
            'billing_status' => Transaction::BILLING_COMPLETED,
            'payment_status' => 'paid',
            'waktu_mulai' => '10:00',
            'waktu_selesai' => '12:00',
            'harga' => 15000,
            'total' => 25000,
            'paid_at' => $now,
        ]);

        $summary = $this->service->getChannelConsolidatedSummary($now, $now);

        $this->assertEquals(30000, $summary['totals']['total_billing']);
        $this->assertEquals(20000, $summary['totals']['total_fnb']);
        $this->assertEquals(50000, $summary['totals']['total_admin']);
        $this->assertEquals(100000, $summary['totals']['total_attributed']);
        $this->assertEquals(25000, $summary['totals']['total_legacy_unattributed']);
        $this->assertEquals(125000, $summary['totals']['grand_total']);
        $this->assertTrue($summary['is_reconciled']);
    }

    /**
     * AC-05: Transaksi belum dibayar (unpaid / running) diisolasi dan TIDAK masuk ke omzet kas
     */
    public function test_unpaid_transactions_are_excluded_from_revenue(): void
    {
        $now = Carbon::parse('2026-11-21 20:00:00');

        // Running unpaid rental
        Transaction::create([
            'nama' => 'Running Player',
            'nama_pelanggan' => 'Running Player',
            'device_id' => $this->psDevice->id,
            'playstation_id' => $this->psConsole->id,
            'user_id' => $this->billingCashier->id,
            'tipe_transaksi' => 'prepaid',
            'status' => 'Berjalan',
            'status_transaksi' => 'draft',
            'billing_status' => Transaction::BILLING_RUNNING,
            'payment_status' => 'unpaid',
            'waktu_mulai' => '20:00',
            'waktu_selesai' => '22:00',
            'harga' => 30000,
            'total' => 30000,
            'paket_payable_total' => 30000,
        ]);

        // Pending unpaid F&B order
        $unpaidOrder = FnbOrder::create([
            'order_number' => 'ORD-UNPAID-01',
            'customer_name' => 'Lapar',
            'status' => FnbOrder::STATUS_ACCEPTED,
            'payment_status' => 'unpaid',
            'created_by_user_id' => $this->fnbCashier->id,
        ]);

        \App\Models\FnbOrderItem::create([
            'fnb_order_id' => $unpaidOrder->id,
            'fnb_id' => $this->snack->id,
            'qty' => 1,
            'unit_price_beli' => 8000,
            'unit_price_jual' => 20000,
            'subtotal' => 20000,
            'payment_status' => 'unpaid',
        ]);

        $revenueSummary = $this->service->getCashierRevenueSummary($now, $now);
        $receivables = $this->service->getUnpaidReceivablesSummary();

        // Revenue is exactly 0
        $this->assertEquals(0, $revenueSummary['total_net']);
        $this->assertEquals(0, $revenueSummary['invoice_count']);

        // Receivables track the uncollected amounts
        $this->assertFalse($receivables['is_revenue']);
        $this->assertEquals(30000, $receivables['unpaid_rentals']['total']);
        $this->assertEquals(20000, $receivables['unpaid_fnb']['total']);
        $this->assertEquals(50000, $receivables['total_receivables']);
    }

    /**
     * Hourly Series (00:00 - 23:00) returns 24 data points matching paid_at hour
     */
    public function test_hourly_revenue_series(): void
    {
        $now = Carbon::parse('2026-11-21 10:15:00');
        $afternoon = Carbon::parse('2026-11-21 14:45:00');

        Invoice::create([
            'invoice_number' => 'INV-H1',
            'subtotal' => 15000,
            'discount' => 0,
            'final_total' => 15000,
            'amount_paid' => 15000,
            'change_amount' => 0,
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->billingCashier->id,
            'paid_at' => $now,
        ]);

        Invoice::create([
            'invoice_number' => 'INV-H2',
            'subtotal' => 25000,
            'discount' => 0,
            'final_total' => 25000,
            'amount_paid' => 25000,
            'change_amount' => 0,
            'payment_method' => 'e-wallet',
            'cashier_user_id' => $this->billingCashier->id,
            'paid_at' => $afternoon,
        ]);

        $series = $this->service->getHourlyRevenueSeries($now, $this->billingCashier->id);

        $this->assertEquals(24, count($series['series']));
        $this->assertEquals(40000, $series['total_revenue']);
        $this->assertEquals(2, $series['total_invoices']);

        // Hour 10: 15.000
        $this->assertEquals(15000, $series['series'][10]['total']);
        $this->assertEquals(1, $series['series'][10]['count']);

        // Hour 14: 25.000
        $this->assertEquals(25000, $series['series'][14]['total']);
        $this->assertEquals(1, $series['series'][14]['count']);

        // Hour 0: 0
        $this->assertEquals(0, $series['series'][0]['total']);
    }
}
