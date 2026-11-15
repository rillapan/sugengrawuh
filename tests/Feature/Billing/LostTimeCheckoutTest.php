<?php

namespace Tests\Feature\Billing;

use Tests\TestCase;
use App\Models\User;
use App\Models\Device;
use App\Models\Playstation;
use App\Models\HourlyPrice;
use App\Models\Transaction;
use App\Models\TransactionFnb;
use App\Models\Location;
use App\Models\Fnb;
use App\Models\FnbOrder;
use App\Models\FnbOrderItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\BillingService;
use App\Services\Billing\LostTimeBillingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

class LostTimeCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected $kasir;
    protected $playstation;
    protected $device;
    protected $location;
    protected $fnbKopi;
    protected $fnbRoti;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kasir = User::factory()->create([
            'role' => 'kasir',
            'email' => 'kasir@sugengrawuh.test',
        ]);

        $this->playstation = Playstation::create([
            'nama' => 'PS5 Lost Time',
            'harga' => 10000, // 10.000 per jam
        ]);

        HourlyPrice::create([
            'playstation_id' => $this->playstation->id,
            'hours' => 1,
            'price' => 10000,
        ]);
        HourlyPrice::create([
            'playstation_id' => $this->playstation->id,
            'hours' => 2,
            'price' => 20000,
        ]);

        $this->device = Device::create([
            'nama' => 'PS-LT-01',
            'playstation_id' => $this->playstation->id,
            'status' => 'Tersedia',
        ]);
        $this->device->playstations()->attach($this->playstation->id);

        $this->location = Location::create([
            'name' => 'Bilik Lost Time 1',
            'code' => 'STATION-LT-01',
            'type' => 'STATION',
            'device_id' => $this->device->id,
            'is_active' => true,
        ]);

        $this->fnbKopi = Fnb::create([
            'nama' => 'Kopi Tubruk',
            'harga_beli' => 3000,
            'harga_jual' => 5000,
            'stok' => 20,
        ]);

        $this->fnbRoti = Fnb::create([
            'nama' => 'Roti Bakar',
            'harga_beli' => 7000,
            'harga_jual' => 12000,
            'stok' => 20,
        ]);
    }

    /**
     * AC-04 / BR-02 / BR-03: Lost Time Checkout excludes early paid F&B and bills only unpaid F&B.
     */
    public function test_ac_04_lost_time_checkout_excludes_early_paid_fnb_and_bills_only_unpaid(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-15 10:00:00'));

        /** @var BillingService $billingService */
        $billingService = app(BillingService::class);

        // Start Lost Time billing
        $transaction = $billingService->startSession([
            'nama' => 'Pelanggan Lost Time',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        $this->assertEquals(Transaction::BILLING_RUNNING, $transaction->billing_status);
        $this->assertNotNull($transaction->session_token);
        $this->assertEquals('Digunakan', $this->device->fresh()->status);

        // Order 1: Kopi Tubruk (5000)
        $order1 = FnbOrder::create([
            'order_number' => 'ORD-TEST-001',
            'session_token' => $transaction->session_token,
            'source' => FnbOrder::SOURCE_QR,
            'location_id' => $this->location->id,
            'transaction_id' => $transaction->id_transaksi,
            'customer_name' => $transaction->nama,
            'billing_name' => $transaction->nama,
            'status' => FnbOrder::STATUS_ACCEPTED,
            'payment_status' => 'unpaid',
            'requires_upfront_payment' => false,
        ]);

        $item1 = FnbOrderItem::create([
            'fnb_order_id' => $order1->id,
            'fnb_id' => $this->fnbKopi->id,
            'qty' => 1,
            'unit_price_beli' => 3000,
            'unit_price_jual' => 5000,
            'subtotal' => 5000,
            'payment_status' => 'unpaid',
        ]);

        // Order 2: Roti Bakar (12000)
        $order2 = FnbOrder::create([
            'order_number' => 'ORD-TEST-002',
            'session_token' => $transaction->session_token,
            'source' => FnbOrder::SOURCE_QR,
            'location_id' => $this->location->id,
            'transaction_id' => $transaction->id_transaksi,
            'customer_name' => $transaction->nama,
            'billing_name' => $transaction->nama,
            'status' => FnbOrder::STATUS_ACCEPTED,
            'payment_status' => 'unpaid',
            'requires_upfront_payment' => false,
        ]);

        $item2 = FnbOrderItem::create([
            'fnb_order_id' => $order2->id,
            'fnb_id' => $this->fnbRoti->id,
            'qty' => 1,
            'unit_price_beli' => 7000,
            'unit_price_jual' => 12000,
            'subtotal' => 12000,
            'payment_status' => 'unpaid',
        ]);

        // Settle Order 1 early via LostTimeBillingService::settleFnbOrder
        /** @var LostTimeBillingService $lostTimeService */
        $lostTimeService = app(LostTimeBillingService::class);
        $earlyInvoice = $lostTimeService->settleFnbOrder($order1->id, [
            'payment_method' => 'tunai',
            'amount_paid' => 5000,
            'cashier_user_id' => $this->kasir->id,
        ]);

        $this->assertInstanceOf(Invoice::class, $earlyInvoice);
        $this->assertEquals(Invoice::TYPE_FNB_STANDALONE, $earlyInvoice->invoice_type);
        $this->assertEquals(5000, $earlyInvoice->final_total);
        $this->assertEquals('paid', $order1->fresh()->payment_status);
        $this->assertEquals('paid', $item1->fresh()->payment_status);
        $this->assertEquals('unpaid', $order2->fresh()->payment_status);

        // Advance time by 90 minutes (10:00 to 11:30)
        Carbon::setTestNow(Carbon::parse('2026-11-15 11:30:00'));

        // End transaction via TransactionController@endTransaction
        $this->actingAs($this->kasir)
            ->post(route('transaction.end', $transaction->id_transaksi));

        $transaction->refresh();
        $this->assertEquals('selesai', $transaction->status_transaksi);
        $this->assertNull($transaction->session_token);

        // Pricing check: 90 minutes PS5 at 10k/hr:
        // calculateLostTimePrice(90 min) = 1 hour (10000) + 30 min (5000) = 15000
        // applyLostTimeRounding(15000) = 15000
        // Expected PS rental fee = 15000
        // Order 1 was already paid, so only Order 2 (12000) is included
        // Expected total = 15000 + 12000 = 27000
        $this->assertEquals(27000, $transaction->total);

        // Process payment for the transaction
        $response = $this->actingAs($this->kasir)
            ->post(route('transaction.processPayment', $transaction->id_transaksi), [
                'payment_method' => 'tunai',
                'amount_paid' => 30000,
                'discount' => 0,
            ]);

        $response->assertRedirect(route('transaction.print', ['id' => $transaction->id_transaksi, 'action' => 'payment']));

        $transaction->refresh();
        $this->assertEquals('paid', $transaction->payment_status);

        // Verify final checkout invoice
        $finalInvoice = Invoice::where('transaction_id', $transaction->id_transaksi)
            ->where('invoice_type', '!=', Invoice::TYPE_FNB_STANDALONE)
            ->first();

        $this->assertNotNull($finalInvoice);
        $this->assertEquals(Invoice::TYPE_GAME_AND_FNB, $finalInvoice->invoice_type);
        $this->assertEquals(27000, $finalInvoice->subtotal);
        $this->assertEquals(27000, $finalInvoice->final_total);
        $this->assertEquals(3000, $finalInvoice->change_amount);

        // Verify invoice items: 1 game rental + 1 F&B item (Order 2 only!)
        $this->assertCount(2, $finalInvoice->items);
        $rentalItem = $finalInvoice->items->where('item_type', InvoiceItem::ITEM_TYPE_GAME_RENTAL)->first();
        $this->assertNotNull($rentalItem);
        $this->assertEquals(15000, $rentalItem->subtotal);

        $fnbItems = $finalInvoice->items->where('item_type', InvoiceItem::ITEM_TYPE_FNB_ITEM);
        $this->assertCount(1, $fnbItems);
        $this->assertEquals($item2->id, $fnbItems->first()->fnb_order_item_id);
        $this->assertEquals(12000, $fnbItems->first()->subtotal);

        // Order 1 item must NOT be in final invoice
        $this->assertFalse($finalInvoice->items->contains('fnb_order_item_id', $item1->id));

        // Both orders must now be paid
        $this->assertEquals('paid', $order1->fresh()->payment_status);
        $this->assertEquals('paid', $order2->fresh()->payment_status);
        $this->assertEquals('paid', $item2->fresh()->payment_status);
    }

    /**
     * OPEN-04: Closing Lost Time session preserves in-progress kitchen orders.
     */
    public function test_open_04_closing_lost_time_session_preserves_in_progress_kitchen_orders(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-15 14:00:00'));

        /** @var BillingService $billingService */
        $billingService = app(BillingService::class);

        $transaction = $billingService->startSession([
            'nama' => 'Budi Santoso',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        $order = FnbOrder::create([
            'order_number' => 'ORD-KITCHEN-01',
            'session_token' => $transaction->session_token,
            'source' => FnbOrder::SOURCE_QR,
            'location_id' => $this->location->id,
            'transaction_id' => $transaction->id_transaksi,
            'customer_name' => $transaction->nama,
            'billing_name' => $transaction->nama,
            'status' => FnbOrder::STATUS_PREPARING, // In-progress in kitchen!
            'payment_status' => 'unpaid',
            'requires_upfront_payment' => false,
        ]);

        $item = FnbOrderItem::create([
            'fnb_order_id' => $order->id,
            'fnb_id' => $this->fnbRoti->id,
            'qty' => 1,
            'unit_price_beli' => 7000,
            'unit_price_jual' => 12000,
            'subtotal' => 12000,
            'payment_status' => 'unpaid',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-11-15 15:00:00'));

        // Checkout lost time directly via LostTimeBillingService
        /** @var LostTimeBillingService $lostTimeService */
        $lostTimeService = app(LostTimeBillingService::class);
        $invoice = $lostTimeService->checkoutLostTime($transaction->id_transaksi, [
            'payment_method' => 'tunai',
            'amount_paid' => 30000,
            'cashier_user_id' => $this->kasir->id,
        ]);

        $this->assertInstanceOf(Invoice::class, $invoice);

        // Station is released to Tersedia
        $this->assertEquals('Tersedia', $this->device->fresh()->status);

        // OPEN-04 Requirement: Order in kitchen MUST remain PREPARING, not forced to DELIVERED
        $freshOrder = $order->fresh();
        $this->assertEquals(FnbOrder::STATUS_PREPARING, $freshOrder->status, 'Kitchen status must remain PREPARING');
        $this->assertEquals('paid', $freshOrder->payment_status);
        $this->assertEquals($transaction->id_transaksi, $freshOrder->transaction_id, 'transaction_id must remain linked');
        $this->assertEquals('Budi Santoso', $freshOrder->billing_name, 'billing_name must be intact for staff delivery');
    }

    /**
     * BR-10: Cannot settle already paid F&B order twice.
     */
    public function test_br_10_cannot_settle_already_paid_fnb_order_twice(): void
    {
        $order = FnbOrder::create([
            'order_number' => 'ORD-DOUBLE-SETTLE',
            'session_token' => 'dummy-token',
            'source' => FnbOrder::SOURCE_CASHIER,
            'location_id' => $this->location->id,
            'customer_name' => 'Pelanggan Double Settle',
            'billing_name' => 'Pelanggan Double Settle',
            'status' => FnbOrder::STATUS_ACCEPTED,
            'payment_status' => 'unpaid',
            'requires_upfront_payment' => false,
        ]);

        FnbOrderItem::create([
            'fnb_order_id' => $order->id,
            'fnb_id' => $this->fnbKopi->id,
            'qty' => 1,
            'unit_price_beli' => 3000,
            'unit_price_jual' => 5000,
            'subtotal' => 5000,
            'payment_status' => 'unpaid',
        ]);

        /** @var LostTimeBillingService $lostTimeService */
        $lostTimeService = app(LostTimeBillingService::class);

        // First settlement succeeds
        $invoice = $lostTimeService->settleFnbOrder($order->id, [
            'payment_method' => 'tunai',
            'amount_paid' => 5000,
        ]);
        $this->assertNotNull($invoice);
        $this->assertEquals('paid', $order->fresh()->payment_status);

        // Second settlement must throw InvalidArgumentException
        $this->expectException(\InvalidArgumentException::class);
        $lostTimeService->settleFnbOrder($order->id, [
            'payment_method' => 'tunai',
            'amount_paid' => 5000,
        ]);
    }

    /**
     * View test: End transaction and payment view show unpaid vs early-paid F&B accurately.
     */
    public function test_end_transaction_and_payment_view_show_unpaid_vs_early_paid_fnb(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-15 10:00:00'));

        /** @var BillingService $billingService */
        $billingService = app(BillingService::class);

        $transaction = $billingService->startSession([
            'nama' => 'Pelanggan View Test',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        // Order 1 (Kopi 5000)
        $order1 = FnbOrder::create([
            'order_number' => 'ORD-VIEW-1',
            'session_token' => $transaction->session_token,
            'source' => FnbOrder::SOURCE_QR,
            'location_id' => $this->location->id,
            'transaction_id' => $transaction->id_transaksi,
            'customer_name' => $transaction->nama,
            'billing_name' => $transaction->nama,
            'status' => FnbOrder::STATUS_ACCEPTED,
            'payment_status' => 'unpaid',
        ]);
        FnbOrderItem::create([
            'fnb_order_id' => $order1->id,
            'fnb_id' => $this->fnbKopi->id,
            'qty' => 1,
            'unit_price_beli' => 3000,
            'unit_price_jual' => 5000,
            'subtotal' => 5000,
            'payment_status' => 'unpaid',
        ]);

        // Order 2 (Roti 12000)
        $order2 = FnbOrder::create([
            'order_number' => 'ORD-VIEW-2',
            'session_token' => $transaction->session_token,
            'source' => FnbOrder::SOURCE_QR,
            'location_id' => $this->location->id,
            'transaction_id' => $transaction->id_transaksi,
            'customer_name' => $transaction->nama,
            'billing_name' => $transaction->nama,
            'status' => FnbOrder::STATUS_ACCEPTED,
            'payment_status' => 'unpaid',
        ]);
        FnbOrderItem::create([
            'fnb_order_id' => $order2->id,
            'fnb_id' => $this->fnbRoti->id,
            'qty' => 1,
            'unit_price_beli' => 7000,
            'unit_price_jual' => 12000,
            'subtotal' => 12000,
            'payment_status' => 'unpaid',
        ]);

        // Early settle order 1
        /** @var LostTimeBillingService $lostTimeService */
        $lostTimeService = app(LostTimeBillingService::class);
        $lostTimeService->settleFnbOrder($order1->id, [
            'payment_method' => 'tunai',
            'amount_paid' => 5000,
        ]);

        // Helper attribute verification on Model
        $transaction->refresh();
        $this->assertEquals(5000.0, $transaction->paid_fnb_order_total);
        $this->assertEquals(12000.0, $transaction->unpaid_fnb_order_total);

        // Advance 60 minutes
        Carbon::setTestNow(Carbon::parse('2026-11-15 11:00:00'));

        // End transaction
        $this->actingAs($this->kasir)
            ->post(route('transaction.end', $transaction->id_transaksi));

        $transaction->refresh();
        // 60 min PS5 = 10.000 + 12.000 (Order 2 only) = 22.000
        $this->assertEquals(22000, $transaction->total);

        // Visit payment page
        $response = $this->actingAs($this->kasir)
            ->get(route('transaction.showPayment', $transaction->id_transaksi));

        $response->assertStatus(200);
        $response->assertSee('22.000'); // Total to pay
        $response->assertSee('Roti Bakar'); // Unpaid item
        $response->assertSee('Kopi Tubruk'); // Early paid item should be visible with status
    }

    /**
     * Regression test: Lost time checkout succeeds when F&B item has null harga_beli.
     */
    public function test_lost_time_checkout_succeeds_when_fnb_item_has_null_harga_beli(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-15 10:00:00'));

        $fnbNullHargaBeli = Fnb::create([
            'nama' => 'Teh Manis Tanpa Modal',
            'harga_beli' => null,
            'harga_jual' => 4000,
            'stok' => 20,
        ]);

        $billingService = app(BillingService::class);
        $transaction = $billingService->startSession([
            'nama' => 'Pelanggan Lost Time Null Modal',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        // Add legacy TransactionFnb with null harga_beli
        TransactionFnb::create([
            'transaction_id' => $transaction->id_transaksi,
            'fnb_id' => $fnbNullHargaBeli->id,
            'qty' => 2,
            'harga_jual' => 4000,
            'harga_beli' => null,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-11-15 11:00:00'));

        // Checkout via processPayment route
        $response = $this->actingAs($this->kasir)
            ->post(route('transaction.processPayment', $transaction->id_transaksi), [
                'payment_method' => 'tunai',
                'amount_paid' => 50000,
                'discount' => 0,
            ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('transaction.print', ['id' => $transaction->id_transaksi, 'action' => 'payment']));

        $transaction->refresh();
        $this->assertEquals('paid', $transaction->payment_status);

        $invoiceItem = InvoiceItem::where('fnb_id', $fnbNullHargaBeli->id)->first();
        $this->assertNotNull($invoiceItem);
        $this->assertEquals(0.0, $invoiceItem->unit_price_beli);
    }
}
