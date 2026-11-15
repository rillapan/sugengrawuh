<?php

namespace Tests\Feature\Concurrency;

use App\Models\AutomationIntent;
use App\Models\Device;
use App\Models\Fnb;
use App\Models\FnbOrder;
use App\Models\HourlyPrice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Location;
use App\Models\Playstation;
use App\Models\StockMutation;
use App\Models\Transaction;
use App\Models\TransactionFnb;
use App\Models\User;
use App\Services\Billing\LostTimeBillingService;
use App\Services\BillingService;
use App\Services\Fnb\FnbStockService;
use App\Services\Fnb\QrOrderService;
use App\Services\Qr\QrCodeSvgService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class CheckoutAndOrderingRaceConditionTest extends TestCase
{
    use RefreshDatabase;

    protected User $kasir;
    protected User $kitchenUser;
    protected Playstation $playstation;
    protected Device $device;
    protected Location $location;
    protected Fnb $fnbLimited;
    protected Fnb $fnbUnlimited;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kasir = User::factory()->create([
            'role' => 'kasir',
            'email' => 'kasir.concurrency@sugengrawuh.test',
        ]);

        $this->kitchenUser = User::factory()->create([
            'role' => 'kasir',
            'is_fnb_staff' => true,
            'email' => 'kitchen.concurrency@sugengrawuh.test',
        ]);

        $this->playstation = Playstation::create([
            'nama' => 'PS5 Concurrency',
            'harga' => 10000,
        ]);

        HourlyPrice::create([
            'playstation_id' => $this->playstation->id,
            'hour' => 1,
            'duration_seconds' => 3600,
            'price' => 10000,
        ]);

        $this->device = Device::create([
            'nama' => 'PS-CONC-01',
            'playstation_id' => $this->playstation->id,
            'status' => 'Tersedia',
        ]);
        $this->device->playstations()->attach($this->playstation->id);

        $this->location = Location::create([
            'name' => 'Bilik Concurrency 1',
            'code' => 'PS-01',
            'type' => 'station',
            'device_id' => $this->device->id,
            'is_active' => true,
        ]);

        $this->fnbLimited = Fnb::create([
            'nama' => 'Indomie Spesial',
            'harga_beli' => 5000,
            'harga_jual' => 10000,
            'stok' => 3,
        ]);

        $this->fnbUnlimited = Fnb::create([
            'nama' => 'Es Teh Manis',
            'harga_beli' => 1500,
            'harga_jual' => 4000,
            'stok' => -1,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * AC-07: Checkout completes & clears session_token right before stale QR tab submits.
     * Stale QR submission must fail deterministically (403 / AuthorizationException) with zero orphan orders or invoice items.
     */
    public function test_ac_07_checkout_clears_session_token_and_rejects_stale_qr_submission(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-15 10:00:00'));

        /** @var BillingService $billingService */
        $billingService = app(BillingService::class);
        /** @var LostTimeBillingService $lostTimeBillingService */
        $lostTimeBillingService = app(LostTimeBillingService::class);
        /** @var QrOrderService $qrOrderService */
        $qrOrderService = app(QrOrderService::class);

        $transaction = $billingService->startSession([
            'nama' => 'Andi Gamer',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        $capturedToken = $transaction->session_token;
        $this->assertNotEmpty($capturedToken);

        // Customer has the menu open before checkout
        $menuResponse = $this->get(route('public.qr.station', $this->location->code));
        $menuResponse->assertStatus(200);
        $menuResponse->assertViewIs('qr_order.menu');

        // Advance time 1 hour and complete cashier checkout
        Carbon::setTestNow(Carbon::parse('2026-11-15 11:00:00'));

        $invoice = $lostTimeBillingService->checkoutLostTime($transaction->id_transaksi, [
            'payment_method' => 'tunai',
            'amount_paid' => 10000,
            'cashier_user_id' => $this->kasir->id,
        ]);

        $transaction->refresh();
        $this->assertNull($transaction->session_token);
        $this->assertEquals('paid', $transaction->payment_status);
        $this->assertEquals(Transaction::BILLING_COMPLETED, $transaction->billing_status);
        $this->assertEquals('Tersedia', $this->device->fresh()->status);

        // Hardware billing-stopped intent was recorded cleanly
        $this->assertTrue(
            AutomationIntent::where('transaction_id', $transaction->id_transaksi)
                ->where('event_type', AutomationIntent::EVENT_BILLING_STOPPED)
                ->exists()
        );

        // 1. Stale QR tab submits via HTTP POST -> deterministic 403
        $stalePayload = [
            'session_token' => $capturedToken,
            'customer_name' => 'Andi Late Tap',
            'items' => [
                ['fnb_id' => $this->fnbUnlimited->id, 'qty' => 1],
            ],
        ];

        $httpResponse = $this->postJson(
            route('public.qr.station.submit', $this->location->code),
            $stalePayload
        );
        $httpResponse->assertStatus(403);

        // 2. Direct service call with stale session_token -> AuthorizationException
        $exceptionThrown = false;
        try {
            $qrOrderService->submitStationOrder($this->location->code, $stalePayload);
        } catch (AuthorizationException $e) {
            $exceptionThrown = true;
        }
        $this->assertTrue($exceptionThrown, 'Expected AuthorizationException when submitting with cleared session_token.');

        // Zero orphan orders or extra invoice items created
        $this->assertEquals(0, FnbOrder::count());
        $this->assertEquals(1, Invoice::count());
        $this->assertEquals(1, InvoiceItem::where('invoice_id', $invoice->id)->count());
    }

    /**
     * OPEN-03 / AC-06: Customer A finishes and Customer B starts on the same physical station QR.
     * Customer A's stale tab with token_A must be rejected (403) with zero charge to Customer B,
     * while Customer B with token_B succeeds (201).
     */
    public function test_open_03_ac_06_session_rollover_rejects_customer_a_stale_token_and_isolates_customer_b(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-15 12:00:00'));

        /** @var BillingService $billingService */
        $billingService = app(BillingService::class);
        /** @var LostTimeBillingService $lostTimeBillingService */
        $lostTimeBillingService = app(LostTimeBillingService::class);

        // Customer A starts session
        $txA = $billingService->startSession([
            'nama' => 'Customer A',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        $tokenA = $txA->session_token;
        $this->assertNotEmpty($tokenA);

        // Customer A stops & checks out
        Carbon::setTestNow(Carbon::parse('2026-11-15 13:00:00'));
        $billingService->stopSession($txA->id_transaksi, 'customer_a_done');
        $invoiceA = $lostTimeBillingService->checkoutLostTime($txA->id_transaksi, [
            'payment_method' => 'tunai',
            'amount_paid' => 10000,
            'cashier_user_id' => $this->kasir->id,
        ]);

        $this->assertNull($txA->fresh()->session_token);
        $this->assertEquals('Tersedia', $this->device->fresh()->status);

        // Customer B starts a new session on the same station
        Carbon::setTestNow(Carbon::parse('2026-11-15 13:05:00'));
        $txB = $billingService->startSession([
            'nama' => 'Customer B',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        $tokenB = $txB->session_token;
        $this->assertNotEmpty($tokenB);
        $this->assertNotEquals($tokenA, $tokenB);

        // Customer A's stale tab attempts to submit using tokenA
        $staleResponse = $this->postJson(route('public.qr.station.submit', $this->location->code), [
            'session_token' => $tokenA,
            'customer_name' => 'Teman A',
            'items' => [
                ['fnb_id' => $this->fnbUnlimited->id, 'qty' => 2],
            ],
        ]);
        $staleResponse->assertStatus(403);

        // Zero orders created for Customer B (or Customer A)
        $this->assertEquals(0, FnbOrder::where('transaction_id', $txB->id_transaksi)->count());
        $this->assertEquals(0, FnbOrder::count());

        // Customer B submits using tokenB -> succeeds 201 and attaches exclusively to Customer B
        $validResponse = $this->postJson(route('public.qr.station.submit', $this->location->code), [
            'session_token' => $tokenB,
            'customer_name' => 'Teman B',
            'items' => [
                ['fnb_id' => $this->fnbUnlimited->id, 'qty' => 1],
            ],
        ]);
        $validResponse->assertStatus(201)->assertJson(['success' => true]);

        $ordersB = FnbOrder::where('transaction_id', $txB->id_transaksi)->get();
        $this->assertCount(1, $ordersB);
        $this->assertEquals('Customer B', $ordersB->first()->billing_name);
        $this->assertEquals('Teman B', $ordersB->first()->customer_name);

        // Customer A's invoice remains unaffected
        $this->assertEquals(1, InvoiceItem::where('invoice_id', $invoiceA->id)->count());
        $this->assertEquals(0, FnbOrder::where('transaction_id', $txA->id_transaksi)->count());
    }

    /**
     * AC-08: Limited stock oversell prevention, rollback on insufficient stock,
     * acceptAndDeductStock idempotency, and QR double-tap idempotency_key deduplication.
     */
    public function test_ac_08_limited_stock_oversell_prevention_and_idempotency(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-15 14:00:00'));

        /** @var BillingService $billingService */
        $billingService = app(BillingService::class);
        /** @var QrOrderService $qrOrderService */
        $qrOrderService = app(QrOrderService::class);
        /** @var FnbStockService $fnbStockService */
        $fnbStockService = app(FnbStockService::class);

        $transaction = $billingService->startSession([
            'nama' => 'Budi Stock Test',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        // Submit two QR orders for qty = 2 each when finite stock is 3
        $order1 = $qrOrderService->submitStationOrder($this->location->code, [
            'session_token' => $transaction->session_token,
            'customer_name' => 'Pemesan 1',
            'items' => [
                ['fnb_id' => $this->fnbLimited->id, 'qty' => 2],
            ],
        ]);

        $order2 = $qrOrderService->submitStationOrder($this->location->code, [
            'session_token' => $transaction->session_token,
            'customer_name' => 'Pemesan 2',
            'items' => [
                ['fnb_id' => $this->fnbLimited->id, 'qty' => 2],
            ],
        ]);

        $this->assertEquals(FnbOrder::STATUS_PENDING, $order1->status);
        $this->assertEquals(FnbOrder::STATUS_PENDING, $order2->status);
        $this->assertEquals(3, $this->fnbLimited->fresh()->stok);

        // Kitchen accepts Order 1 -> stock drops from 3 to 1, 1 out StockMutation created
        $accepted1 = $fnbStockService->acceptAndDeductStock($order1->id, $this->kitchenUser->id);
        $this->assertEquals(FnbOrder::STATUS_ACCEPTED, $accepted1->status);
        $this->assertEquals(1, $this->fnbLimited->fresh()->stok);
        $this->assertEquals(1, StockMutation::where('fnb_id', $this->fnbLimited->id)->where('type', 'out')->count());
        $this->assertEquals(2, StockMutation::where('fnb_id', $this->fnbLimited->id)->where('type', 'out')->first()->qty);

        // Kitchen attempts to accept Order 2 (qty = 2, remaining stock = 1) -> throws InvalidArgumentException & rolls back
        $oversellRejected = false;
        try {
            $fnbStockService->acceptAndDeductStock($order2->id, $this->kitchenUser->id);
        } catch (InvalidArgumentException $e) {
            $oversellRejected = true;
            $this->assertStringContainsString('tidak mencukupi', $e->getMessage());
        }
        $this->assertTrue($oversellRejected, 'Expected InvalidArgumentException when accepting order with insufficient stock.');

        // Stock stays 1 (no negative stock), Order 2 stays PENDING, no second StockMutation
        $this->assertEquals(1, $this->fnbLimited->fresh()->stok);
        $this->assertEquals(FnbOrder::STATUS_PENDING, $order2->fresh()->status);
        $this->assertNull($order2->fresh()->accepted_at);
        $this->assertEquals(1, StockMutation::where('fnb_id', $this->fnbLimited->id)->count());

        // Repeated call to acceptAndDeductStock on Order 1 is idempotent (no double deduction)
        $reaccepted1 = $fnbStockService->acceptAndDeductStock($order1->id, $this->kitchenUser->id);
        $this->assertEquals(FnbOrder::STATUS_ACCEPTED, $reaccepted1->status);
        $this->assertEquals(1, $this->fnbLimited->fresh()->stok);
        $this->assertEquals(1, StockMutation::where('fnb_id', $this->fnbLimited->id)->count());

        // QR double-tap deduplication via idempotency_key
        $idempotencyKey = 'idem-' . Str::uuid();
        $doubleTapPayload = [
            'session_token' => $transaction->session_token,
            'customer_name' => 'Pemesan Double Tap',
            'idempotency_key' => $idempotencyKey,
            'items' => [
                ['fnb_id' => $this->fnbUnlimited->id, 'qty' => 1],
            ],
        ];

        $firstTap = $qrOrderService->submitStationOrder($this->location->code, $doubleTapPayload);
        $secondTap = $qrOrderService->submitStationOrder($this->location->code, $doubleTapPayload);

        $this->assertEquals($firstTap->id, $secondTap->id);
        $this->assertEquals(3, FnbOrder::count()); // order1, order2, and single firstTap
    }

    /**
     * C.1 / AC-04: Double settlement via settleFnbOrder() / checkoutLostTime() is rejected cleanly,
     * and direct duplicate InvoiceItem insertion on fnb_order_item_id or transaction_fnb_id fails at DB level.
     */
    public function test_c1_ac_04_double_settlement_and_unique_invoice_item_db_constraint_guard(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-15 15:00:00'));

        /** @var BillingService $billingService */
        $billingService = app(BillingService::class);
        /** @var QrOrderService $qrOrderService */
        $qrOrderService = app(QrOrderService::class);
        /** @var LostTimeBillingService $lostTimeBillingService */
        $lostTimeBillingService = app(LostTimeBillingService::class);

        $transaction = $billingService->startSession([
            'nama' => 'Citra Settlement',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        $order = $qrOrderService->submitStationOrder($this->location->code, [
            'session_token' => $transaction->session_token,
            'customer_name' => 'Citra',
            'items' => [
                ['fnb_id' => $this->fnbUnlimited->id, 'qty' => 2],
            ],
        ]);
        $orderItem = $order->items()->firstOrFail();

        // First settleFnbOrder succeeds
        $fnbInvoice = $lostTimeBillingService->settleFnbOrder($order->id, [
            'payment_method' => 'tunai',
            'amount_paid' => 8000,
            'cashier_user_id' => $this->kasir->id,
        ]);

        $this->assertEquals('paid', $order->fresh()->payment_status);
        $this->assertEquals(1, Invoice::count());
        $this->assertEquals(1, InvoiceItem::count());

        // Second settleFnbOrder throws InvalidArgumentException and creates nothing
        $doubleSettleRejected = false;
        try {
            $lostTimeBillingService->settleFnbOrder($order->id, [
                'payment_method' => 'tunai',
                'amount_paid' => 8000,
                'cashier_user_id' => $this->kasir->id,
            ]);
        } catch (InvalidArgumentException $e) {
            $doubleSettleRejected = true;
        }
        $this->assertTrue($doubleSettleRejected, 'Expected InvalidArgumentException on duplicate settleFnbOrder().');
        $this->assertEquals(1, Invoice::count());
        $this->assertEquals(1, InvoiceItem::count());

        // Direct duplicate InvoiceItem with same fnb_order_item_id blocked by DB unique constraint
        $duplicateOrderItemBlocked = false;
        try {
            InvoiceItem::create([
                'invoice_id' => $fnbInvoice->id,
                'item_type' => InvoiceItem::ITEM_TYPE_FNB_ITEM,
                'fnb_id' => $this->fnbUnlimited->id,
                'fnb_order_item_id' => $orderItem->id,
                'transaction_fnb_id' => null,
                'name' => $this->fnbUnlimited->nama,
                'qty' => 2,
                'unit_price_beli' => 1500,
                'unit_price_jual' => 4000,
                'subtotal' => 8000,
            ]);
        } catch (QueryException $e) {
            $duplicateOrderItemBlocked = true;
        }
        $this->assertTrue($duplicateOrderItemBlocked, 'Expected QueryException on duplicate fnb_order_item_id.');

        // Direct duplicate InvoiceItem with same transaction_fnb_id blocked by DB unique constraint
        $legacyFnb = TransactionFnb::create([
            'transaction_id' => $transaction->id_transaksi,
            'fnb_id' => $this->fnbUnlimited->id,
            'qty' => 1,
            'harga_jual' => 4000,
            'harga_beli' => 1500,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-11-15 16:00:00'));
        $checkoutInvoice = $lostTimeBillingService->checkoutLostTime($transaction->id_transaksi, [
            'payment_method' => 'tunai',
            'amount_paid' => 14000,
            'cashier_user_id' => $this->kasir->id,
        ]);

        // Double checkoutLostTime rejected
        $doubleCheckoutRejected = false;
        try {
            $lostTimeBillingService->checkoutLostTime($transaction->id_transaksi, [
                'payment_method' => 'tunai',
                'amount_paid' => 14000,
                'cashier_user_id' => $this->kasir->id,
            ]);
        } catch (InvalidArgumentException $e) {
            $doubleCheckoutRejected = true;
        }
        $this->assertTrue($doubleCheckoutRejected, 'Expected InvalidArgumentException on duplicate checkoutLostTime().');

        $duplicateLegacyFnbBlocked = false;
        try {
            InvoiceItem::create([
                'invoice_id' => $checkoutInvoice->id,
                'item_type' => InvoiceItem::ITEM_TYPE_FNB_ITEM,
                'fnb_id' => $this->fnbUnlimited->id,
                'fnb_order_item_id' => null,
                'transaction_fnb_id' => $legacyFnb->id,
                'name' => $this->fnbUnlimited->nama,
                'qty' => 1,
                'unit_price_beli' => 1500,
                'unit_price_jual' => 4000,
                'subtotal' => 4000,
            ]);
        } catch (QueryException $e) {
            $duplicateLegacyFnbBlocked = true;
        }
        $this->assertTrue($duplicateLegacyFnbBlocked, 'Expected QueryException on duplicate transaction_fnb_id.');
    }

    /**
     * OPEN-04: Post-checkout kitchen delivery preserves PREPARING status, transaction_id, and billing_name,
     * allowing kitchen staff to advance to READY -> DELIVERED while the old QR tab cannot submit new orders.
     */
    public function test_open_04_post_checkout_kitchen_delivery_continues_while_qr_station_is_locked(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-15 17:00:00'));

        /** @var BillingService $billingService */
        $billingService = app(BillingService::class);
        /** @var QrOrderService $qrOrderService */
        $qrOrderService = app(QrOrderService::class);
        /** @var FnbStockService $fnbStockService */
        $fnbStockService = app(FnbStockService::class);
        /** @var LostTimeBillingService $lostTimeBillingService */
        $lostTimeBillingService = app(LostTimeBillingService::class);

        $transaction = $billingService->startSession([
            'nama' => 'Dedi Renter',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        $oldToken = $transaction->session_token;

        $order = $qrOrderService->submitStationOrder($this->location->code, [
            'session_token' => $oldToken,
            'customer_name' => 'Dedi Guest',
            'items' => [
                ['fnb_id' => $this->fnbLimited->id, 'qty' => 1],
            ],
        ]);

        // Kitchen accepts and moves order to PREPARING before checkout
        $fnbStockService->acceptAndDeductStock($order->id, $this->kitchenUser->id);
        $fnbStockService->advanceStatus($order->id, FnbOrder::STATUS_PREPARING, $this->kitchenUser->id);

        // Cashier completes checkout while order is still PREPARING
        Carbon::setTestNow(Carbon::parse('2026-11-15 18:00:00'));
        $lostTimeBillingService->checkoutLostTime($transaction->id_transaksi, [
            'payment_method' => 'tunai',
            'amount_paid' => 20000,
            'cashier_user_id' => $this->kasir->id,
        ]);

        $order->refresh();
        $this->assertEquals(FnbOrder::STATUS_PREPARING, $order->status);
        $this->assertEquals('paid', $order->payment_status);
        $this->assertEquals($transaction->id_transaksi, $order->transaction_id);
        $this->assertEquals('Dedi Renter', $order->billing_name);

        // Old QR tab cannot submit new orders and menu shows no_session
        $this->get(route('public.qr.station', $this->location->code))
            ->assertStatus(200)
            ->assertViewIs('qr_order.no_session');

        $this->postJson(route('public.qr.station.submit', $this->location->code), [
            'session_token' => $oldToken,
            'customer_name' => 'Dedi Guest',
            'items' => [
                ['fnb_id' => $this->fnbUnlimited->id, 'qty' => 1],
            ],
        ])->assertStatus(403);

        // Kitchen smoothly finishes READY -> DELIVERED post-checkout
        $readyOrder = $fnbStockService->advanceStatus($order->id, FnbOrder::STATUS_READY, $this->kitchenUser->id);
        $this->assertEquals(FnbOrder::STATUS_READY, $readyOrder->status);

        $deliveredOrder = $fnbStockService->advanceStatus($order->id, FnbOrder::STATUS_DELIVERED, $this->kitchenUser->id);
        $this->assertEquals(FnbOrder::STATUS_DELIVERED, $deliveredOrder->status);
        $this->assertEquals($transaction->id_transaksi, $deliveredOrder->transaction_id);
        $this->assertEquals('Dedi Renter', $deliveredOrder->billing_name);
    }

    /**
     * AC-12: QrCodeSvgService renders pure offline deterministic inline SVG without external scripts or CDNs.
     */
    public function test_ac_12_qr_code_svg_service_renders_pure_offline_deterministic_svg(): void
    {
        /** @var QrCodeSvgService $qrService */
        $qrService = app(QrCodeSvgService::class);
        $targetUrl = 'http://192.168.1.50:8008/station/PS-01';

        $svgFirst = $qrService->generateSvg($targetUrl);
        $svgSecond = $qrService->generateSvg($targetUrl);

        $this->assertSame($svgFirst, $svgSecond);
        $this->assertStringStartsWith('<svg', $svgFirst);
        $this->assertStringContainsString('xmlns="http://www.w3.org/2000/svg"', $svgFirst);
        $this->assertStringContainsString('<path d="', $svgFirst);
        $this->assertStringEndsWith('</svg>', $svgFirst);

        $svgWithoutXmlns = str_replace('xmlns="http://www.w3.org/2000/svg"', '', $svgFirst);
        $this->assertStringNotContainsString('<script', strtolower($svgWithoutXmlns));
        $this->assertStringNotContainsString('http://', $svgWithoutXmlns);
        $this->assertStringNotContainsString('https://', $svgWithoutXmlns);
        $this->assertStringNotContainsString('fonts.googleapis.com', $svgWithoutXmlns);
        $this->assertStringNotContainsString('cdnjs.cloudflare.com', $svgWithoutXmlns);
    }
}
