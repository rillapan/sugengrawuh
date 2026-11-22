<?php

namespace Tests\Feature\Finance;

use App\Models\Device;
use App\Models\Fnb;
use App\Models\FnbOrder;
use App\Models\FnbOrderItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Playstation;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\CashierRevenueService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialReportMultiCashierTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $kasirBilling;
    protected User $kasirFnb;
    protected Playstation $ps5;
    protected Playstation $billiard;
    protected Device $devicePs5;
    protected Device $deviceBilliard;
    protected Fnb $fnbItem;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-21 14:00:00');

        $this->admin = User::factory()->create([
            'name' => 'Owner Admin',
            'email' => 'owner.admin@test.com',
            'role' => 'admin',
            'is_fnb_staff' => false,
        ]);

        $this->kasirBilling = User::factory()->create([
            'name' => 'Budi Billing',
            'email' => 'budi.billing@test.com',
            'role' => 'kasir',
            'is_fnb_staff' => false,
        ]);

        $this->kasirFnb = User::factory()->create([
            'name' => 'Siti Cafe',
            'email' => 'siti.cafe@test.com',
            'role' => 'kasir',
            'is_fnb_staff' => true,
        ]);

        $this->ps5 = Playstation::create([
            'nama' => 'PlayStation 5 VIP',
            'harga' => 20000,
        ]);

        $this->billiard = Playstation::create([
            'nama' => 'Meja Billiard 01',
            'harga' => 35000,
        ]);

        $this->devicePs5 = Device::create([
            'nama' => 'PS5-01',
            'playstation_id' => $this->ps5->id,
            'status' => 'tersedia',
        ]);

        $this->deviceBilliard = Device::create([
            'nama' => 'MEJA-01',
            'playstation_id' => $this->billiard->id,
            'status' => 'tersedia',
        ]);

        $this->fnbItem = Fnb::create([
            'nama' => 'Es Kopi Susu',
            'harga_beli' => 8000,
            'harga_jual' => 15000,
            'stok' => 50,
            'status' => 'tersedia',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * AC-01, AC-02, AC-03, AC-04, AC-08, AC-09, AC-10, AC-12, AC-14:
     * Report index provides 3 perspectives: Cashier Receipts, Category Sales, and Business Consolidation.
     */
    public function test_report_displays_three_perspectives_cashier_category_and_consolidation(): void
    {
        $today = Carbon::now(CashierRevenueService::TIMEZONE);

        // 1. Transaction Rental PS5: Rp 40.000 settled by Kasir Billing
        $txPs = Transaction::create([
            'id_transaksi' => 'TRX-PS-001',
            'device_id' => $this->devicePs5->id,
            'nama' => 'Customer PS',
            'harga' => 20000,
            'total' => 40000,
            'diskon' => 0,
            'status' => 'selesai',
            'payment_status' => 'paid',
            'billing_status' => Transaction::BILLING_COMPLETED,
            'tipe_transaksi' => 'prepaid',
            'waktu_mulai' => $today->toDateTimeString(),
            'waktu_Selesai' => $today->copy()->addHours(2)->toDateTimeString(),
            'user_id' => $this->kasirBilling->id,
            'paid_at' => $today,
        ]);

        $invPs = Invoice::create([
            'invoice_number' => 'INV-PS-001',
            'transaction_id' => $txPs->id_transaksi,
            'cashier_user_id' => $this->kasirBilling->id,
            'subtotal' => 40000,
            'discount' => 0,
            'final_total' => 40000,
            'amount_paid' => 40000,
            'change_amount' => 0,
            'payment_method' => 'tunai',
            'payment_status' => 'paid',
            'paid_at' => $today,
            'invoice_type' => Invoice::TYPE_GAME_ONLY,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invPs->id,
            'item_type' => InvoiceItem::ITEM_TYPE_GAME_RENTAL,
            'name' => 'Rental PS5 2 Jam',
            'qty' => 1,
            'unit_price_jual' => 40000,
            'subtotal' => 40000,
        ]);

        // 2. Standalone F&B Order: Rp 30.000 created and settled directly by Kasir F&B
        $fnbOrder = FnbOrder::create([
            'order_number' => 'ORD-FNB-001',
            'customer_name' => 'Walk-in Guest',
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'status' => FnbOrder::STATUS_DELIVERED,
            'paid_at' => $today,
            'created_by_user_id' => $this->kasirFnb->id,
        ]);

        FnbOrderItem::create([
            'fnb_order_id' => $fnbOrder->id,
            'fnb_id' => $this->fnbItem->id,
            'qty' => 2,
            'unit_price_beli' => 8000,
            'unit_price_jual' => 15000,
            'subtotal' => 30000,
        ]);

        $invFnb = Invoice::create([
            'invoice_number' => 'INV-FNB-001',
            'cashier_user_id' => $this->kasirFnb->id,
            'subtotal' => 30000,
            'discount' => 0,
            'final_total' => 30000,
            'amount_paid' => 30000,
            'change_amount' => 0,
            'payment_method' => 'e-wallet',
            'payment_status' => 'paid',
            'paid_at' => $today,
            'invoice_type' => Invoice::TYPE_FNB_STANDALONE,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invFnb->id,
            'item_type' => InvoiceItem::ITEM_TYPE_FNB_ITEM,
            'fnb_id' => $this->fnbItem->id,
            'name' => 'Es Kopi Susu x 2',
            'qty' => 2,
            'unit_price_jual' => 15000,
            'subtotal' => 30000,
        ]);

        // 3. Combined Transaction (Billiard Rp 70.000 + F&B Rp 30.000 with Rp 10.000 discount = Rp 90.000 net)
        // Order creator: Kasir F&B; Payment receiver: Kasir Billing
        $txBilliard = Transaction::create([
            'id_transaksi' => 'TRX-BIL-001',
            'device_id' => $this->deviceBilliard->id,
            'nama' => 'Customer Billiard',
            'harga' => 35000,
            'total' => 90000,
            'diskon' => 10,
            'status' => 'selesai',
            'payment_status' => 'paid',
            'billing_status' => Transaction::BILLING_COMPLETED,
            'tipe_transaksi' => 'postpaid',
            'waktu_mulai' => $today->toDateTimeString(),
            'waktu_Selesai' => $today->copy()->addHours(2)->toDateTimeString(),
            'user_id' => $this->kasirFnb->id, // Created by F&B staff
            'paid_at' => $today,
        ]);

        $invCombined = Invoice::create([
            'invoice_number' => 'INV-BIL-001',
            'transaction_id' => $txBilliard->id_transaksi,
            'cashier_user_id' => $this->kasirBilling->id, // Settled by Billing Cashier
            'subtotal' => 100000,
            'discount' => 10000,
            'final_total' => 90000,
            'amount_paid' => 100000,
            'change_amount' => 10000,
            'payment_method' => 'transfer_bank',
            'payment_status' => 'paid',
            'paid_at' => $today,
            'invoice_type' => Invoice::TYPE_GAME_AND_FNB,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invCombined->id,
            'item_type' => InvoiceItem::ITEM_TYPE_GAME_RENTAL,
            'name' => 'Rental Meja Billiard',
            'qty' => 1,
            'unit_price_jual' => 70000,
            'subtotal' => 70000,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invCombined->id,
            'item_type' => InvoiceItem::ITEM_TYPE_FNB_ITEM,
            'fnb_id' => $this->fnbItem->id,
            'name' => 'Es Kopi Susu x 2',
            'qty' => 2,
            'unit_price_jual' => 15000,
            'subtotal' => 30000,
        ]);

        // Request report as Admin
        $response = $this->actingAs($this->admin)->get(route('report', ['period' => 'today']));

        $response->assertStatus(200);
        $response->assertViewHas([
            'consolidatedSummary',
            'categorySummary',
            'cashierRevenueSummary',
            'invoices',
            'cashierList',
        ]);

        // Check Consolidated Summary (AC-10)
        $cons = $response->viewData('consolidatedSummary');
        // Kasir Billing = 40.000 (PS) + 90.000 (Combined) = 130.000
        $this->assertEquals(130000, $cons['totals']['total_billing']);
        // Kasir F&B = 30.000 (Direct F&B)
        $this->assertEquals(30000, $cons['totals']['total_fnb']);
        // Admin = 0
        $this->assertEquals(0, $cons['totals']['total_admin']);
        // Grand Total = 160.000
        $this->assertEquals(160000, $cons['totals']['grand_total']);
        $this->assertTrue($cons['is_reconciled']);

        // Check Category Summary (AC-08, AC-09, AC-12)
        $cat = $response->viewData('categorySummary');
        $this->assertEquals(160000, $cat['total_net']);
        $this->assertTrue($cat['is_balanced']);

        // Check PS Category: 40.000
        $this->assertEquals(40000, $cat['categories'][CashierRevenueService::CATEGORY_PLAYSTATION]['net']);
        // Check Billiard Category: 70.000 - 7.000 (70% discount) = 63.000
        $this->assertEquals(63000, $cat['categories'][CashierRevenueService::CATEGORY_BILLIARD]['net']);
        // Check Cafe Category: 30.000 (standalone) + (30.000 - 3.000 discount) = 57.000
        $this->assertEquals(57000, $cat['categories'][CashierRevenueService::CATEGORY_CAFE]['net']);

        // Sum of categories == Consolidated Grand Total (AC-12)
        $this->assertEquals($cons['totals']['grand_total'], $cat['categories_net_sum']);
    }

    /**
     * AC-01, AC-02: Report filtering by specific cashier correctly isolates receipts.
     */
    public function test_report_cashier_filter_scopes_receipts_to_selected_cashier(): void
    {
        $today = Carbon::now(CashierRevenueService::TIMEZONE);

        // Billing Cashier invoice: 50.000
        Invoice::create([
            'invoice_number' => 'INV-BILL-FILTER',
            'cashier_user_id' => $this->kasirBilling->id,
            'subtotal' => 50000,
            'discount' => 0,
            'final_total' => 50000,
            'amount_paid' => 50000,
            'change_amount' => 0,
            'payment_method' => 'tunai',
            'payment_status' => 'paid',
            'paid_at' => $today,
            'invoice_type' => Invoice::TYPE_GAME_ONLY,
        ]);

        // F&B Cashier invoice: 25.000
        Invoice::create([
            'invoice_number' => 'INV-FNB-FILTER',
            'cashier_user_id' => $this->kasirFnb->id,
            'subtotal' => 25000,
            'discount' => 0,
            'final_total' => 25000,
            'amount_paid' => 25000,
            'change_amount' => 0,
            'payment_method' => 'e-wallet',
            'payment_status' => 'paid',
            'paid_at' => $today,
            'invoice_type' => Invoice::TYPE_FNB_STANDALONE,
        ]);

        // Filter to Kasir F&B only
        $response = $this->actingAs($this->admin)->get(route('report', [
            'period' => 'today',
            'cashier_user_id' => $this->kasirFnb->id,
        ]));

        $response->assertStatus(200);
        $summary = $response->viewData('cashierRevenueSummary');
        $this->assertEquals(25000, $summary['total_net']);
        $this->assertEquals(1, $summary['invoice_count']);
        $this->assertEquals($this->kasirFnb->id, $summary['cashier_user_id']);

        $invoices = $response->viewData('invoices');
        $this->assertCount(1, $invoices);
        $this->assertEquals('INV-FNB-FILTER', $invoices->first()->invoice_number);
    }

    /**
     * AC-11: Legacy paid transactions without invoice are attributed to legacy unattributed bucket.
     */
    public function test_report_handles_legacy_unattributed_transactions_in_consolidation(): void
    {
        $today = Carbon::now(CashierRevenueService::TIMEZONE);

        // Modern transaction with invoice: 40.000
        $txModern = Transaction::create([
            'id_transaksi' => 'TRX-MODERN',
            'device_id' => $this->devicePs5->id,
            'nama' => 'Modern Customer',
            'harga' => 20000,
            'total' => 40000,
            'diskon' => 0,
            'status' => 'selesai',
            'payment_status' => 'paid',
            'billing_status' => Transaction::BILLING_COMPLETED,
            'tipe_transaksi' => 'prepaid',
            'waktu_mulai' => $today->toDateTimeString(),
            'waktu_Selesai' => $today->copy()->addHours(2)->toDateTimeString(),
            'user_id' => $this->kasirBilling->id,
            'paid_at' => $today,
        ]);

        Invoice::create([
            'invoice_number' => 'INV-MODERN',
            'transaction_id' => $txModern->id_transaksi,
            'cashier_user_id' => $this->kasirBilling->id,
            'subtotal' => 40000,
            'discount' => 0,
            'final_total' => 40000,
            'amount_paid' => 40000,
            'change_amount' => 0,
            'payment_method' => 'tunai',
            'payment_status' => 'paid',
            'paid_at' => $today,
            'invoice_type' => Invoice::TYPE_GAME_ONLY,
        ]);

        // Legacy transaction WITHOUT invoice: 35.000
        Transaction::create([
            'id_transaksi' => 'TRX-LEGACY-01',
            'device_id' => $this->devicePs5->id,
            'nama' => 'Legacy Customer',
            'harga' => 20000,
            'total' => 35000,
            'diskon' => 0,
            'status' => 'selesai',
            'payment_status' => 'paid',
            'billing_status' => Transaction::BILLING_COMPLETED,
            'tipe_transaksi' => 'prepaid',
            'waktu_mulai' => $today->toDateTimeString(),
            'waktu_Selesai' => $today->copy()->addHours(2)->toDateTimeString(),
            'user_id' => $this->kasirBilling->id,
            'paid_at' => $today,
            'created_at' => $today,
        ]);

        $response = $this->actingAs($this->admin)->get(route('report', ['period' => 'today']));
        $response->assertStatus(200);

        $cons = $response->viewData('consolidatedSummary');
        $this->assertEquals(40000, $cons['totals']['total_billing']);
        $this->assertEquals(35000, $cons['totals']['total_legacy_unattributed']);
        $this->assertEquals(1, $cons['totals']['legacy_unattributed_count']);
        $this->assertEquals(75000, $cons['totals']['grand_total']);
        $this->assertTrue($cons['is_reconciled']);
    }

    /**
     * AC-15: PDF and Excel export return HTTP 200 and reflect multi-cashier summaries.
     */
    public function test_pdf_and_excel_exports_succeed(): void
    {
        $today = Carbon::now(CashierRevenueService::TIMEZONE);

        $tx = Transaction::create([
            'id_transaksi' => 'TRX-EXP-001',
            'device_id' => $this->devicePs5->id,
            'nama' => 'Export Customer',
            'harga' => 20000,
            'total' => 50000,
            'diskon' => 0,
            'status' => 'selesai',
            'payment_status' => 'paid',
            'billing_status' => Transaction::BILLING_COMPLETED,
            'tipe_transaksi' => 'prepaid',
            'waktu_mulai' => $today->toDateTimeString(),
            'waktu_Selesai' => $today->copy()->addHours(2)->toDateTimeString(),
            'user_id' => $this->kasirBilling->id,
            'paid_at' => $today,
        ]);

        Invoice::create([
            'invoice_number' => 'INV-EXP-001',
            'transaction_id' => $tx->id_transaksi,
            'cashier_user_id' => $this->kasirBilling->id,
            'subtotal' => 50000,
            'discount' => 0,
            'final_total' => 50000,
            'amount_paid' => 50000,
            'change_amount' => 0,
            'payment_method' => 'tunai',
            'payment_status' => 'paid',
            'paid_at' => $today,
            'invoice_type' => Invoice::TYPE_GAME_ONLY,
        ]);

        // PDF export
        $pdfRes = $this->actingAs($this->admin)->get('/generate-pdf?period=today');
        $pdfRes->assertStatus(200);

        // Excel export
        $excelRes = $this->actingAs($this->admin)->get('/generate-excel?period=today');
        $excelRes->assertStatus(200);
    }
}
