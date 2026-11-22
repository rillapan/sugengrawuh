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

class MultiCashierEndToEndTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $kasirBilling;
    protected User $kasirFnb;
    protected Playstation $ps5;
    protected Playstation $billiard;
    protected Device $devicePs5;
    protected Device $deviceBilliard;
    protected Fnb $kopi;
    protected Fnb $snack;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-21 15:30:00');

        $this->admin = User::factory()->create([
            'name' => 'Owner Sugeng',
            'email' => 'owner.sugeng@test.com',
            'role' => 'admin',
            'is_fnb_staff' => false,
        ]);

        $this->kasirBilling = User::factory()->create([
            'name' => 'Ahmad Billing',
            'email' => 'ahmad.billing@test.com',
            'role' => 'kasir',
            'is_fnb_staff' => false,
        ]);

        $this->kasirFnb = User::factory()->create([
            'name' => 'Dewi Cafe',
            'email' => 'dewi.cafe@test.com',
            'role' => 'kasir',
            'is_fnb_staff' => true,
        ]);

        $this->ps5 = Playstation::create([
            'nama' => 'PlayStation 5 Suite',
            'harga' => 25000,
        ]);

        $this->billiard = Playstation::create([
            'nama' => 'Meja Billiard Pro',
            'harga' => 40000,
        ]);

        $this->devicePs5 = Device::create([
            'nama' => 'PS5-VIP',
            'playstation_id' => $this->ps5->id,
            'status' => 'tersedia',
        ]);

        $this->deviceBilliard = Device::create([
            'nama' => 'MEJA-VIP',
            'playstation_id' => $this->billiard->id,
            'status' => 'tersedia',
        ]);

        $this->kopi = Fnb::create([
            'nama' => 'Caramel Macchiato',
            'harga_beli' => 12000,
            'harga_jual' => 22000,
            'stok' => 100,
            'status' => 'tersedia',
        ]);

        $this->snack = Fnb::create([
            'nama' => 'Kentang Goreng',
            'harga_beli' => 8000,
            'harga_jual' => 15000,
            'stok' => 100,
            'status' => 'tersedia',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * AC-01 through AC-23 Comprehensive End-to-End Simulation.
     */
    public function test_full_lifecycle_multi_cashier_revenue_reconciliation_and_integrity(): void
    {
        $now = Carbon::now(CashierRevenueService::TIMEZONE);

        // -------------------------------------------------------------
        // Step 1: Channel Lock Verification (AC-16, AC-17, AC-18, AC-21)
        // -------------------------------------------------------------
        $lockRes = $this->actingAs($this->admin)->put(route('users.update', $this->kasirBilling->id), [
            'name' => 'Ahmad Renamed',
            'email' => $this->kasirBilling->email,
            'role' => 'kasir',
            'is_fnb_staff' => 1, // Illegal mutation attempt
        ]);
        $lockRes->assertSessionHasErrors('is_fnb_staff');
        $this->assertFalse((bool) $this->kasirBilling->fresh()->is_fnb_staff);

        // -------------------------------------------------------------
        // Step 2: F&B Direct Order Settled by Kasir F&B (AC-02, AC-08)
        // Customer orders 2 Caramel Macchiato (2 x 22.000 = 44.000) directly at cafe
        // -------------------------------------------------------------
        $orderDirect = FnbOrder::create([
            'order_number' => 'ORD-CAFE-DIRECT',
            'source' => FnbOrder::SOURCE_CASHIER,
            'customer_name' => 'Tamu Kafe',
            'payment_method' => 'cash',
            'payment_status' => FnbOrder::PAYMENT_PAID,
            'status' => FnbOrder::STATUS_DELIVERED,
            'paid_at' => $now,
            'created_by_user_id' => $this->kasirFnb->id,
        ]);

        FnbOrderItem::create([
            'fnb_order_id' => $orderDirect->id,
            'fnb_id' => $this->kopi->id,
            'qty' => 2,
            'unit_price_beli' => 12000,
            'unit_price_jual' => 22000,
            'subtotal' => 44000,
            'payment_status' => 'paid',
        ]);

        $invDirect = Invoice::create([
            'invoice_number' => 'INV-E2E-FNB-001',
            'cashier_user_id' => $this->kasirFnb->id, // Received by Kasir F&B
            'subtotal' => 44000,
            'discount' => 0,
            'final_total' => 44000,
            'amount_paid' => 50000,
            'change_amount' => 6000,
            'payment_method' => 'tunai',
            'paid_at' => $now,
            'invoice_type' => Invoice::TYPE_FNB_STANDALONE,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invDirect->id,
            'item_type' => InvoiceItem::ITEM_TYPE_FNB_ITEM,
            'fnb_id' => $this->kopi->id,
            'name' => 'Caramel Macchiato',
            'qty' => 2,
            'unit_price_jual' => 22000,
            'subtotal' => 44000,
        ]);

        // -------------------------------------------------------------
        // Step 3: F&B Order Created by F&B Staff, Settled by Kasir Billing (AC-03)
        // 1 Snack Kentang Goreng (15.000) created by Dewi Cafe, but customer pays at billing desk
        // -------------------------------------------------------------
        $orderViaBilling = FnbOrder::create([
            'order_number' => 'ORD-CAFE-TO-BILLING',
            'source' => FnbOrder::SOURCE_FNB_STAFF,
            'customer_name' => 'Player Console 1',
            'payment_method' => 'cash',
            'payment_status' => FnbOrder::PAYMENT_PAID,
            'status' => FnbOrder::STATUS_DELIVERED,
            'paid_at' => $now,
            'created_by_user_id' => $this->kasirFnb->id, // Created by F&B staff
        ]);

        FnbOrderItem::create([
            'fnb_order_id' => $orderViaBilling->id,
            'fnb_id' => $this->snack->id,
            'qty' => 1,
            'unit_price_beli' => 8000,
            'unit_price_jual' => 15000,
            'subtotal' => 15000,
            'payment_status' => 'paid',
        ]);

        $invFnbViaBilling = Invoice::create([
            'invoice_number' => 'INV-E2E-FNB-002',
            'cashier_user_id' => $this->kasirBilling->id, // Received by Kasir Billing!
            'subtotal' => 15000,
            'discount' => 0,
            'final_total' => 15000,
            'amount_paid' => 15000,
            'change_amount' => 0,
            'payment_method' => 'e-wallet',
            'paid_at' => $now,
            'invoice_type' => Invoice::TYPE_FNB_STANDALONE,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invFnbViaBilling->id,
            'item_type' => InvoiceItem::ITEM_TYPE_FNB_ITEM,
            'fnb_id' => $this->snack->id,
            'name' => 'Kentang Goreng',
            'qty' => 1,
            'unit_price_jual' => 15000,
            'subtotal' => 15000,
        ]);

        // -------------------------------------------------------------
        // Step 4: Standalone Rental PS5 (Prepaid Timer) Settled by Kasir Billing (AC-01, AC-09)
        // 2 Jam Rental PS5 = 50.000, settled by Kasir Billing
        // -------------------------------------------------------------
        $txPs = Transaction::create([
            'id_transaksi' => 'TRX-E2E-PS',
            'device_id' => $this->devicePs5->id,
            'nama' => 'Player Rian',
            'harga' => 25000,
            'total' => 50000,
            'diskon' => 0,
            'status' => 'selesai',
            'payment_status' => 'paid',
            'billing_status' => Transaction::BILLING_COMPLETED,
            'tipe_transaksi' => 'prepaid',
            'waktu_mulai' => $now->toDateTimeString(),
            'waktu_Selesai' => $now->copy()->addHours(2)->toDateTimeString(),
            'user_id' => $this->kasirBilling->id,
            'paid_at' => $now,
        ]);

        $invPs = Invoice::create([
            'invoice_number' => 'INV-E2E-PS-001',
            'transaction_id' => $txPs->id_transaksi,
            'cashier_user_id' => $this->kasirBilling->id,
            'subtotal' => 50000,
            'discount' => 0,
            'final_total' => 50000,
            'amount_paid' => 50000,
            'change_amount' => 0,
            'payment_method' => 'tunai',
            'paid_at' => $now,
            'invoice_type' => Invoice::TYPE_GAME_ONLY,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invPs->id,
            'item_type' => InvoiceItem::ITEM_TYPE_GAME_RENTAL,
            'name' => 'Rental PS5 2 Jam',
            'qty' => 1,
            'unit_price_jual' => 50000,
            'subtotal' => 50000,
        ]);

        // -------------------------------------------------------------
        // Step 5: Combined Billiard + F&B with Discount Settled by Kasir Billing (AC-04)
        // Billiard: 80.000 + 2 Kopi: 44.000 = Subtotal 124.000. Diskon: 24.000 -> Final: 100.000
        // Settled by Kasir Billing. F&B Cashier MUST RECEIVE Rp 0 from this transaction!
        // -------------------------------------------------------------
        $txBilliardCombined = Transaction::create([
            'id_transaksi' => 'TRX-E2E-BIL',
            'device_id' => $this->deviceBilliard->id,
            'nama' => 'Player Bagus',
            'harga' => 40000,
            'total' => 100000,
            'diskon' => 20,
            'status' => 'selesai',
            'payment_status' => 'paid',
            'billing_status' => Transaction::BILLING_COMPLETED,
            'tipe_transaksi' => 'postpaid',
            'waktu_mulai' => $now->toDateTimeString(),
            'waktu_Selesai' => $now->copy()->addHours(2)->toDateTimeString(),
            'user_id' => $this->kasirBilling->id,
            'paid_at' => $now,
        ]);

        $invCombined = Invoice::create([
            'invoice_number' => 'INV-E2E-BIL-001',
            'transaction_id' => $txBilliardCombined->id_transaksi,
            'cashier_user_id' => $this->kasirBilling->id,
            'subtotal' => 124000,
            'discount' => 24000,
            'final_total' => 100000,
            'amount_paid' => 100000,
            'change_amount' => 0,
            'payment_method' => 'transfer_bank',
            'paid_at' => $now,
            'invoice_type' => Invoice::TYPE_GAME_AND_FNB,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invCombined->id,
            'item_type' => InvoiceItem::ITEM_TYPE_GAME_RENTAL,
            'name' => 'Rental Meja Billiard',
            'qty' => 1,
            'unit_price_jual' => 80000,
            'subtotal' => 80000,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invCombined->id,
            'item_type' => InvoiceItem::ITEM_TYPE_FNB_ITEM,
            'fnb_id' => $this->kopi->id,
            'name' => 'Caramel Macchiato',
            'qty' => 2,
            'unit_price_jual' => 22000,
            'subtotal' => 44000,
        ]);

        // -------------------------------------------------------------
        // Step 6: Unpaid Operational Session (Must be Isolated from Cash Revenue)
        // -------------------------------------------------------------
        Transaction::create([
            'id_transaksi' => 'TRX-UNPAID-ACTIVE',
            'device_id' => $this->devicePs5->id,
            'nama' => 'Player Sedang Main',
            'harga' => 25000,
            'total' => 25000,
            'diskon' => 0,
            'status' => 'aktif',
            'payment_status' => 'unpaid',
            'billing_status' => Transaction::BILLING_RUNNING,
            'tipe_transaksi' => 'prepaid',
            'waktu_mulai' => $now->toDateTimeString(),
            'user_id' => $this->kasirBilling->id,
        ]);

        // -------------------------------------------------------------
        // Step 7: Dashboard Verification Scoped by RBAC (AC-01, AC-02, AC-03, AC-04, AC-13)
        // -------------------------------------------------------------
        // A. Kasir F&B Dashboard: Only sees Rp 44.000 (direct cafe order)
        // Does NOT receive Rp 15.000 (paid at billing) nor Rp 44.000 (from combined at billing)
        $dashFnb = $this->actingAs($this->kasirFnb)->get(route('home'));
        $dashFnb->assertStatus(200);
        $this->assertEquals(44000, $dashFnb->viewData('todayRevenue'));

        // B. Kasir Billing Dashboard: Sees Rp 165.000
        // (PS: 50.000 + FNB via billing: 15.000 + Combined: 100.000 = 165.000)
        $dashBilling = $this->actingAs($this->kasirBilling)->get(route('home'));
        $dashBilling->assertStatus(200);
        $this->assertEquals(165000, $dashBilling->viewData('todayRevenue'));

        // C. Admin / Owner Dashboard: Sees Grand Total Rp 209.000
        // (Kasir Billing 165.000 + Kasir F&B 44.000 = 209.000)
        $dashAdmin = $this->actingAs($this->admin)->get(route('home'));
        $dashAdmin->assertStatus(200);
        $this->assertEquals(209000, $dashAdmin->viewData('todayRevenue'));

        // -------------------------------------------------------------
        // Step 8: Financial Report & 3-Perspective Reconciliation (AC-10, AC-12, AC-14)
        // -------------------------------------------------------------
        $reportRes = $this->actingAs($this->admin)->get(route('report', ['period' => 'today']));
        $reportRes->assertStatus(200);

        $cons = $reportRes->viewData('consolidatedSummary');
        $cat = $reportRes->viewData('categorySummary');

        // Mathematical Assertions
        $this->assertEquals(165000, $cons['totals']['total_billing'], 'Billing cashier receipts match exact net sum');
        $this->assertEquals(44000, $cons['totals']['total_fnb'], 'F&B cashier receipts match exact net sum');
        $this->assertEquals(0, $cons['totals']['total_admin'], 'Admin direct receipts is zero');
        $this->assertEquals(209000, $cons['totals']['grand_total'], 'Consolidated grand total is 165.000 + 44.000');
        $this->assertTrue($cons['is_reconciled']);

        // Category Breakdown Assertions
        // Total Invoices Net == Category Net Sum == Grand Total (Anti-Double Counting & Conservation of Value)
        $this->assertEquals(209000, $cat['total_net']);
        $this->assertEquals(209000, $cat['categories_net_sum']);
        $this->assertTrue($cat['is_balanced']);

        // Check Individual Category Contributions:
        // PS Net = 50.000
        $this->assertEquals(50000, $cat['categories'][CashierRevenueService::CATEGORY_PLAYSTATION]['net']);
        // Billiard Gross 80.000 with proportional discount (80.000 / 124.000 * 24.000 = 15.483,87 -> 64.516,13)
        $this->assertGreaterThan(60000, $cat['categories'][CashierRevenueService::CATEGORY_BILLIARD]['net']);
        // Cafe Gross (44.000 direct + 15.000 via billing + 44.000 combined = 103.000)
        $this->assertGreaterThan(80000, $cat['categories'][CashierRevenueService::CATEGORY_CAFE]['net']);

        // Proportional Conservation Proof: sum of categories == sum of cashiers
        $categorySum = $cat['categories'][CashierRevenueService::CATEGORY_PLAYSTATION]['net']
            + $cat['categories'][CashierRevenueService::CATEGORY_BILLIARD]['net']
            + $cat['categories'][CashierRevenueService::CATEGORY_CAFE]['net'];
        $cashierSum = $cons['totals']['total_billing'] + $cons['totals']['total_fnb'] + $cons['totals']['total_admin'];
        $this->assertEquals($cashierSum, $categorySum, 'Total category net must precisely equal total cashier net');

        // -------------------------------------------------------------
        // Step 9: PDF & Excel Export Verification (AC-15)
        // -------------------------------------------------------------
        $pdfRes = $this->actingAs($this->admin)->get('/generate-pdf?period=today');
        $pdfRes->assertStatus(200);

        $excelRes = $this->actingAs($this->admin)->get('/generate-excel?period=today');
        $excelRes->assertStatus(200);
    }

    /**
     * Requirement 4: Cafe paid at F&B while rental running, then rental settled at Billing without double charging.
     */
    public function test_fnb_paid_at_fnb_while_rental_running_then_rental_settled_at_billing_no_double_charging(): void
    {
        $now = Carbon::now(CashierRevenueService::TIMEZONE);
        $lostTimeService = app(\App\Services\Billing\LostTimeBillingService::class);

        // 1. Customer starts rental session on Meja Billiard
        $tx = Transaction::create([
            'id_transaksi' => 'TX-RENTAL-FNB-PARALLEL',
            'user_id' => $this->kasirBilling->id,
            'device_id' => $this->deviceBilliard->id,
            'playstation_id' => $this->billiard->id,
            'tipe_transaksi' => 'postpaid',
            'harga' => 40000,
            'waktu_mulai' => $now->copy()->subHours(2),
            'lost_time_start' => $now->copy()->subHours(2),
            'total' => 0,
            'status' => 'aktif',
            'payment_status' => 'unpaid',
            'created_at' => $now->copy()->subHours(2),
        ]);

        $this->deviceBilliard->update(['status' => 'Digunakan']);

        // 2. Customer orders F&B linked to this active transaction
        $order = FnbOrder::create([
            'order_number' => 'ORD-CAFE-WHILE-PLAYING',
            'transaction_id' => $tx->id_transaksi,
            'source' => FnbOrder::SOURCE_CASHIER,
            'customer_name' => 'Billiard Player 1',
            'billing_name' => 'Meja Billiard 01',
            'payment_method' => 'tunai',
            'payment_status' => 'unpaid',
            'status' => FnbOrder::STATUS_PREPARING,
            'created_by_user_id' => $this->kasirFnb->id,
        ]);

        FnbOrderItem::create([
            'fnb_order_id' => $order->id,
            'fnb_id' => $this->kopi->id,
            'qty' => 1,
            'unit_price_beli' => 12000,
            'unit_price_jual' => 22000,
            'subtotal' => 22000,
            'payment_status' => 'unpaid',
        ]);

        // 3. Customer walks to Kasir F&B and pays for the coffee immediately
        $fnbInvoice = $lostTimeService->settleFnbOrder($order->id, [
            'payment_method' => 'tunai',
            'amount_paid' => 22000,
            'cashier_user_id' => $this->kasirFnb->id,
        ]);

        $this->assertInstanceOf(Invoice::class, $fnbInvoice);
        $this->assertEquals(22000, (float) $fnbInvoice->final_total);
        $this->assertEquals($this->kasirFnb->id, $fnbInvoice->cashier_user_id);
        $this->assertEquals('paid', $order->fresh()->payment_status);

        // 4. Session continues, then customer ends game at Kasir Billing
        Carbon::setTestNow($now);
        $rentalInvoice = $lostTimeService->checkoutLostTime($tx->id_transaksi, [
            'payment_method' => 'tunai',
            'amount_paid' => 100000,
            'cashier_user_id' => $this->kasirBilling->id,
        ]);

        $this->assertInstanceOf(Invoice::class, $rentalInvoice);
        $this->assertEquals($this->kasirBilling->id, $rentalInvoice->cashier_user_id);
        // The rental invoice must ONLY charge rental (no unpaid F&B attached)
        $this->assertCount(1, $rentalInvoice->items);
        $this->assertEquals(InvoiceItem::ITEM_TYPE_GAME_RENTAL, $rentalInvoice->items->first()->item_type);

        // 5. Verify Revenue Attribution and Zero Double Charging
        $revenueService = app(CashierRevenueService::class);
        $startDate = $now->copy()->startOfDay();
        $endDate = $now->copy()->endOfDay();

        $fnbSummary = $revenueService->getCashierRevenueSummary($startDate, $endDate, $this->kasirFnb->id);
        $billingSummary = $revenueService->getCashierRevenueSummary($startDate, $endDate, $this->kasirBilling->id);
        $consolidated = $revenueService->getChannelConsolidatedSummary($startDate, $endDate);
        $categories = $revenueService->getCategoryRevenueSummary($startDate, $endDate);

        $this->assertEquals(22000, $fnbSummary['total_net'], 'F&B cashier received exactly 22.000');
        $this->assertEquals((float) $rentalInvoice->final_total, $billingSummary['total_net'], 'Billing cashier received exactly rental fee');
        $this->assertEquals(22000 + (float) $rentalInvoice->final_total, $consolidated['totals']['grand_total']);
        $this->assertEquals(22000, $categories['categories'][CashierRevenueService::CATEGORY_CAFE]['net']);
        $this->assertEquals((float) $rentalInvoice->final_total, $categories['categories'][CashierRevenueService::CATEGORY_BILLIARD]['net']);
        $this->assertTrue($consolidated['is_reconciled']);
        $this->assertTrue($categories['is_balanced']);
    }

    /**
     * Requirement 5: Deactivated cashier & device category change preserves historical invoice attribution.
     */
    public function test_cashier_deactivation_and_device_category_change_preserves_invoice_history(): void
    {
        $now = Carbon::now(CashierRevenueService::TIMEZONE);
        $revenueService = app(CashierRevenueService::class);

        $shift = \App\Models\WorkShift::create([
            'nama_shift' => 'Shift Pagi',
            'jam_mulai' => '08:00:00',
            'jam_selesai' => '16:00:00',
        ]);

        // Create rental invoice under Kasir Billing on Meja Billiard
        $invoiceBilling = Invoice::create([
            'invoice_number' => 'INV-HIST-BILLIARD',
            'subtotal' => 50000,
            'discount' => 0,
            'final_total' => 50000,
            'amount_paid' => 50000,
            'change_amount' => 0,
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->kasirBilling->id,
            'paid_at' => $now,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoiceBilling->id,
            'item_type' => InvoiceItem::ITEM_TYPE_GAME_RENTAL,
            'name' => 'Rental Meja Billiard 01 (Lost Time)',
            'qty' => 1,
            'unit_price_beli' => 0,
            'unit_price_jual' => 50000,
            'subtotal' => 50000,
        ]);

        // Attempt to delete Kasir Billing user (Admin action) -> MUST be blocked
        $delRes = $this->actingAs($this->admin)->delete(route('users.destroy', $this->kasirBilling->id));
        $delRes->assertRedirect(route('users.index'));
        $this->assertDatabaseHas('users', ['id' => $this->kasirBilling->id]);

        // Admin deactivates / updates cashier credentials without changing role/channel
        $this->actingAs($this->admin)->put(route('users.update', $this->kasirBilling->id), [
            'name' => 'Ahmad Billing (Nonaktif)',
            'email' => 'ahmad.deactivated@test.com',
            'role' => 'kasir',
            'is_fnb_staff' => 0,
            'shift' => 'Pagi',
            'work_shift_id' => $shift->id,
        ])->assertSessionHasNoErrors();

        // Mutate PlayStation / device master data (e.g. category modified or renamed)
        $this->billiard->update(['nama' => 'PS5 Meja Diubah']);
        $this->deviceBilliard->update(['nama' => 'Device PS5 Modified']);

        // Historical reporting must still attribute correctly
        $startDate = $now->copy()->startOfDay();
        $endDate = $now->copy()->endOfDay();

        $billingSummary = $revenueService->getCashierRevenueSummary($startDate, $endDate, $this->kasirBilling->id);
        $this->assertEquals(50000, $billingSummary['total_net']);
        $this->assertEquals(CashierRevenueService::CHANNEL_BILLING, $billingSummary['channel']);

        $categories = $revenueService->getCategoryRevenueSummary($startDate, $endDate);
        $this->assertEquals(50000, $categories['categories'][CashierRevenueService::CATEGORY_BILLIARD]['net'], 'Historical snapshot name preserves Billiard category even after master data mutation');
    }

    /**
     * Requirement 6: Exhaustive channel lock pathways.
     */
    public function test_exhaustive_channel_lock_pathways(): void
    {
        $shift = \App\Models\WorkShift::create([
            'nama_shift' => 'Shift Pagi',
            'jam_mulai' => '08:00:00',
            'jam_selesai' => '16:00:00',
        ]);

        // 1. Kasir Billing cannot flip is_fnb_staff to true
        $res1 = $this->actingAs($this->admin)->put(route('users.update', $this->kasirBilling->id), [
            'name' => 'Ahmad Billing',
            'email' => $this->kasirBilling->email,
            'role' => 'kasir',
            'is_fnb_staff' => 1,
            'shift' => 'Pagi',
            'work_shift_id' => $shift->id,
        ]);
        $res1->assertSessionHasErrors('is_fnb_staff');

        // 2. Kasir Billing with transaction/invoice cannot change role to admin or owner
        Invoice::create([
            'invoice_number' => 'INV-LOCK-TEST',
            'subtotal' => 10000,
            'discount' => 0,
            'final_total' => 10000,
            'amount_paid' => 10000,
            'change_amount' => 0,
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->kasirBilling->id,
            'paid_at' => Carbon::now(),
        ]);

        $res2 = $this->actingAs($this->admin)->put(route('users.update', $this->kasirBilling->id), [
            'name' => 'Ahmad Billing',
            'email' => $this->kasirBilling->email,
            'role' => 'admin',
        ]);
        $res2->assertSessionHasErrors('role');

        // 3. Kasir F&B cannot flip is_fnb_staff to false (even if field omitted in form)
        $res3 = $this->actingAs($this->admin)->put(route('users.update', $this->kasirFnb->id), [
            'name' => 'Siti FnB',
            'email' => $this->kasirFnb->email,
            'role' => 'kasir',
            'shift' => 'Pagi',
            'work_shift_id' => $shift->id,
            // is_fnb_staff omitted -> evaluates to false
        ]);
        $res3->assertSessionHasErrors('is_fnb_staff');

        // 4. Non-kasir with invoices cannot be converted to kasir
        Invoice::create([
            'invoice_number' => 'INV-ADMIN-DIRECT',
            'subtotal' => 5000,
            'discount' => 0,
            'final_total' => 5000,
            'amount_paid' => 5000,
            'change_amount' => 0,
            'payment_method' => 'tunai',
            'cashier_user_id' => $this->admin->id,
            'paid_at' => Carbon::now(),
        ]);

        $res4 = $this->actingAs($this->admin)->put(route('users.update', $this->admin->id), [
            'name' => 'Owner Sugeng',
            'email' => $this->admin->email,
            'role' => 'kasir',
            'is_fnb_staff' => 0,
            'shift' => 'Pagi',
            'work_shift_id' => $shift->id,
        ]);
        $res4->assertSessionHasErrors('role');

        // 5. Valid profile update on cashier without channel/role change succeeds
        $res5 = $this->actingAs($this->admin)->put(route('users.update', $this->kasirBilling->id), [
            'name' => 'Ahmad Billing Shift Sore',
            'email' => $this->kasirBilling->email,
            'role' => 'kasir',
            'is_fnb_staff' => 0,
            'shift' => 'Sore',
            'work_shift_id' => $shift->id,
        ]);
        $res5->assertSessionHasNoErrors();
        $this->assertEquals('Ahmad Billing Shift Sore', $this->kasirBilling->fresh()->name);
    }
}
