<?php

namespace Tests\Feature\Billing;

use Tests\TestCase;
use App\Models\User;
use App\Models\Device;
use App\Models\Playstation;
use App\Models\Transaction;
use App\Models\CustomPackage;
use App\Models\Fnb;
use App\Models\TransactionFnb;
use App\Models\StockMutation;
use App\Models\Location;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\FnbOrder;
use App\Models\FnbOrderItem;
use App\Models\AutomationIntent;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PaketUpfrontPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected $kasir;
    protected $playstation;
    protected $device;
    protected $location;
    protected $fnbItem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kasir = User::factory()->create([
            'role' => 'kasir',
            'email' => 'kasir@sugengrawuh.test',
        ]);

        $this->playstation = Playstation::create([
            'nama' => 'PS5 Station',
            'harga' => 10000,
        ]);

        $this->device = Device::create([
            'nama' => 'PS-01',
            'playstation_id' => $this->playstation->id,
            'status' => 'Tersedia',
        ]);
        $this->device->playstations()->attach($this->playstation->id);

        $this->location = Location::create([
            'name' => 'Bilik 1',
            'code' => 'STATION-01',
            'type' => 'STATION',
            'device_id' => $this->device->id,
            'is_active' => true,
        ]);

        $this->fnbItem = Fnb::create([
            'nama' => 'Kopi Tubruk',
            'harga_beli' => 3000,
            'harga_jual' => 5000,
            'stok' => 10,
        ]);
    }

    /**
     * AC-01: Storing a Paket (prepaid) without payment creates a draft session:
     * - BILLING_IDLE
     * - device.status remains 'Tersedia'
     * - session_token is null
     * - AutomationIntent count is 0 (hardware not turned ON)
     * - F&B stock is unchanged
     * - Redirects to payment page
     */
    public function test_ac_01_unpaid_paket_does_not_activate_session_device_stock_or_hardware(): void
    {
        $response = $this->actingAs($this->kasir)->post(route('transaction.store'), [
            'nama' => 'Pelanggan Paket Unpaid',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
            'harga' => 10000,
            'total' => 20000,
            'fnb_ids' => [$this->fnbItem->id],
            'fnbs_qty' => [2],
            'fnbs_harga' => [5000],
            'action' => 'bayar',
        ]);

        $transaction = Transaction::where('nama', 'Pelanggan Paket Unpaid')->first();
        $this->assertNotNull($transaction, 'Transaction must be created');

        $response->assertRedirect(route('transaction.showPayment', $transaction->id_transaksi));

        // Must remain in draft / IDLE state until paid
        $this->assertEquals('draft', $transaction->status_transaksi);
        $this->assertEquals('unpaid', $transaction->payment_status);
        $this->assertEquals(Transaction::BILLING_IDLE, $transaction->billing_status);
        $this->assertNull($transaction->started_at);
        $this->assertNull($transaction->expires_at);
        $this->assertNull($transaction->session_token);

        // Device must remain Tersedia
        $this->assertEquals('Tersedia', $this->device->fresh()->status);

        // F&B stock must remain unchanged (not deducted yet)
        $this->assertEquals(10, $this->fnbItem->fresh()->stok);
        $this->assertEquals(0, StockMutation::where('fnb_id', $this->fnbItem->id)->count());

        // Zero hardware automation intents recorded
        $this->assertEquals(0, AutomationIntent::where('transaction_id', $transaction->id_transaksi)->count());
    }

    /**
     * AC-02: Payment of Paket activates session:
     * - BILLING_RUNNING
     * - device.status becomes 'Digunakan'
     * - session_token is generated (Str::random(40))
     * - AutomationIntent ON is recorded and dispatched
     * - F&B stock is deducted once with StockMutation (type = 'out')
     * - Exactly 1 Invoice is created with accurate GAME_RENTAL + FNB_ITEM subtotals
     * - FnbOrder is queued as ACCEPTED if location exists
     */
    public function test_ac_02_paid_paket_activates_session_hardware_on_deducts_stock_once_and_creates_single_invoice(): void
    {
        // 1. Store draft transaction
        $this->actingAs($this->kasir)->post(route('transaction.store'), [
            'nama' => 'Pelanggan Paket Lunas',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
            'duration_seconds' => 3600,
            'harga' => 10000,
            'total' => 20000,
            'fnb_ids' => [$this->fnbItem->id],
            'fnbs_qty' => [2],
            'fnbs_harga' => [5000],
        ]);

        $transaction = Transaction::where('nama', 'Pelanggan Paket Lunas')->firstOrFail();

        // 2. Process upfront payment
        $paymentResponse = $this->actingAs($this->kasir)->post(route('transaction.processPayment', $transaction->id_transaksi), [
            'payment_method' => 'tunai',
            'amount_paid' => 25000,
            'discount' => 0,
        ]);

        $paymentResponse->assertRedirect(route('transaction.print', ['id' => $transaction->id_transaksi, 'action' => 'payment']));

        $transaction->refresh();
        $this->assertEquals('paid', $transaction->payment_status);
        $this->assertEquals('sukses', $transaction->status_transaksi);
        $this->assertEquals(Transaction::BILLING_RUNNING, $transaction->billing_status);
        $this->assertNotNull($transaction->started_at);
        $this->assertNotNull($transaction->expires_at);
        $this->assertNotNull($transaction->session_token);
        $this->assertEquals(40, strlen($transaction->session_token));

        // Device status must be Digunakan
        $this->assertEquals('Digunakan', $this->device->fresh()->status);

        // F&B stock decremented exactly once (10 - 2 = 8)
        $this->assertEquals(8, $this->fnbItem->fresh()->stok);
        $this->assertEquals(1, StockMutation::where('fnb_id', $this->fnbItem->id)->where('type', 'out')->count());

        // Single immutable invoice created
        $invoices = Invoice::where('transaction_id', $transaction->id_transaksi)->get();
        $this->assertCount(1, $invoices);
        $invoice = $invoices->first();
        $this->assertEquals(20000, (float) $invoice->subtotal);
        $this->assertEquals(20000, (float) $invoice->final_total);
        $this->assertEquals(25000, (float) $invoice->amount_paid);
        $this->assertEquals(5000, (float) $invoice->change_amount);
        $this->assertEquals('tunai', $invoice->payment_method);
        $this->assertEquals(Invoice::TYPE_GAME_AND_FNB, $invoice->invoice_type);

        // Check invoice items: GAME_RENTAL (10000) and FNB_ITEM (10000)
        $rentalItem = InvoiceItem::where('invoice_id', $invoice->id)->where('item_type', InvoiceItem::ITEM_TYPE_GAME_RENTAL)->first();
        $this->assertNotNull($rentalItem);
        $this->assertEquals(10000, (float) $rentalItem->subtotal);

        $fnbInvoiceItem = InvoiceItem::where('invoice_id', $invoice->id)->where('item_type', InvoiceItem::ITEM_TYPE_FNB_ITEM)->first();
        $this->assertNotNull($fnbInvoiceItem);
        $this->assertEquals(10000, (float) $fnbInvoiceItem->subtotal);
        $this->assertEquals(2, $fnbInvoiceItem->qty);

        // FnbOrder created in ACCEPTED state
        $fnbOrder = FnbOrder::where('transaction_id', $transaction->id_transaksi)->first();
        $this->assertNotNull($fnbOrder);
        $this->assertEquals(FnbOrder::STATUS_ACCEPTED, $fnbOrder->status);
        $this->assertEquals('paid', $fnbOrder->payment_status);
        $this->assertEquals(FnbOrder::SOURCE_CASHIER, $fnbOrder->source);
        $this->assertEquals($this->location->id, $fnbOrder->location_id);
        $this->assertEquals($transaction->session_token, $fnbOrder->session_token);

        // FnbOrderItem has correct prices
        $orderItem = FnbOrderItem::where('fnb_order_id', $fnbOrder->id)->first();
        $this->assertNotNull($orderItem);
        $this->assertEquals(3000, (float) $orderItem->unit_price_beli);
        $this->assertEquals(5000, (float) $orderItem->unit_price_jual);
        $this->assertEquals(10000, (float) $orderItem->subtotal);

        // Hardware automation intent recorded
        $intent = AutomationIntent::where('transaction_id', $transaction->id_transaksi)
            ->where('event_type', AutomationIntent::EVENT_BILLING_STARTED)
            ->first();
        $this->assertNotNull($intent);
        $this->assertEquals(AutomationIntent::STATE_ON, $intent->desired_state);

        // Idempotency check: Repeated payment call returns already paid without duplicate invoice/stock deduction
        $secondPay = $this->actingAs($this->kasir)->post(route('transaction.processPayment', $transaction->id_transaksi), [
            'payment_method' => 'tunai',
            'amount_paid' => 20000,
        ]);
        $secondPay->assertRedirect(route('transaction.print', $transaction->id_transaksi));
        $this->assertCount(1, Invoice::where('transaction_id', $transaction->id_transaksi)->get());
        $this->assertEquals(8, $this->fnbItem->fresh()->stok);
    }

    /**
     * AC-03: Paid Paket invoice is immutable and rejects adding items to the same transaction.
     */
    public function test_ac_03_paid_paket_invoice_is_immutable_and_rejects_adding_items_to_same_transaction(): void
    {
        // Setup paid paket transaction
        $this->actingAs($this->kasir)->post(route('transaction.store'), [
            'nama' => 'Pelanggan Immutable',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
            'harga' => 10000,
            'total' => 10000,
        ]);

        $transaction = Transaction::where('nama', 'Pelanggan Immutable')->firstOrFail();

        $this->actingAs($this->kasir)->post(route('transaction.processPayment', $transaction->id_transaksi), [
            'payment_method' => 'tunai',
            'amount_paid' => 10000,
            'discount' => 0,
        ]);

        $invoice = Invoice::where('transaction_id', $transaction->id_transaksi)->firstOrFail();
        $originalTotal = $invoice->final_total;

        // Attempting to add FNB item to a paid transaction via fnb.add must be rejected
        $addFnbRes = $this->actingAs($this->kasir)->postJson(route('transaction.fnb.add', $transaction->id_transaksi), [
            'fnb_id' => $this->fnbItem->id,
            'qty' => 1,
        ]);
        $addFnbRes->assertStatus(403);

        // Attempting to add order via store-order must also be rejected
        $storeOrderRes = $this->actingAs($this->kasir)->post(route('transaction.store-order', $transaction->id_transaksi), [
            'items' => [
                ['fnb_id' => $this->fnbItem->id, 'qty' => 1],
            ],
        ]);
        $storeOrderRes->assertRedirect(route('transaction.index'));

        // Invoice remains immutable
        $this->assertEquals($originalTotal, $invoice->fresh()->final_total);
    }

    /**
     * Custom package also requires upfront payment before activation.
     */
    public function test_custom_package_requires_upfront_payment_before_activation(): void
    {
        $customPackage = CustomPackage::create([
            'nama_paket' => 'Paket Mabar 2 Jam + Kopi',
            'harga_total' => 25000,
            'is_active' => true,
        ]);
        $customPackage->playstations()->attach($this->playstation->id, ['lama_main' => 120]);

        // 1. Store custom package
        $response = $this->actingAs($this->kasir)->post(route('transaction.store'), [
            'nama' => 'Pelanggan Custom Paket Upfront',
            'device_id' => $this->device->id,
            'tipe_transaksi' => 'custom_package',
            'custom_package_id' => $customPackage->id,
            'harga' => 25000,
            'total' => 25000,
        ]);

        $transaction = Transaction::where('nama', 'Pelanggan Custom Paket Upfront')->firstOrFail();
        $response->assertRedirect(route('transaction.showPayment', $transaction->id_transaksi));

        $this->assertEquals('draft', $transaction->status_transaksi);
        $this->assertEquals('unpaid', $transaction->payment_status);
        $this->assertEquals(Transaction::BILLING_IDLE, $transaction->billing_status);
        $this->assertEquals('Tersedia', $this->device->fresh()->status);

        // 2. Pay custom package
        $this->actingAs($this->kasir)->post(route('transaction.processPayment', $transaction->id_transaksi), [
            'payment_method' => 'tunai',
            'amount_paid' => 30000,
            'discount' => 0,
        ]);

        $transaction->refresh();
        $this->assertEquals('paid', $transaction->payment_status);
        $this->assertEquals('sukses', $transaction->status_transaksi);
        $this->assertEquals(Transaction::BILLING_RUNNING, $transaction->billing_status);
        $this->assertEquals('Digunakan', $this->device->fresh()->status);
        $this->assertNotNull($transaction->session_token);

        $this->assertCount(1, Invoice::where('transaction_id', $transaction->id_transaksi)->get());
    }

    /**
     * Regression test: Payment processing must succeed even when Fnb item has null harga_beli.
     */
    public function test_paket_payment_succeeds_when_fnb_item_has_null_harga_beli(): void
    {
        $fnbNullHargaBeli = Fnb::create([
            'nama' => 'Snack Tanpa Modal',
            'harga_beli' => null,
            'harga_jual' => 5000,
            'stok' => 10,
        ]);

        $this->actingAs($this->kasir)->post(route('transaction.store'), [
            'nama' => 'Pelanggan Paket Null Harga Beli',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
            'harga' => 10000,
            'total' => 15000,
            'fnb_ids' => [$fnbNullHargaBeli->id],
            'fnbs_qty' => [1],
            'fnbs_harga' => [5000],
            'action' => 'bayar',
        ]);

        $transaction = Transaction::where('nama', 'Pelanggan Paket Null Harga Beli')->firstOrFail();

        // Process payment
        $response = $this->actingAs($this->kasir)->post(route('transaction.processPayment', $transaction->id_transaksi), [
            'payment_method' => 'tunai',
            'amount_paid' => 20000,
            'discount' => 0,
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('transaction.print', ['id' => $transaction->id_transaksi, 'action' => 'payment']));

        $transaction->refresh();
        $this->assertEquals('paid', $transaction->payment_status);

        $orderItem = FnbOrderItem::where('fnb_id', $fnbNullHargaBeli->id)->first();
        $this->assertNotNull($orderItem);
        $this->assertEquals(0.0, $orderItem->unit_price_beli);

        $invoiceItem = InvoiceItem::where('fnb_id', $fnbNullHargaBeli->id)->first();
        $this->assertNotNull($invoiceItem);
        $this->assertEquals(0.0, $invoiceItem->unit_price_beli);
    }
}

