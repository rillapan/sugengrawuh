<?php

namespace Tests\Feature\Fnb;

use App\Models\Device;
use App\Models\Fnb;
use App\Models\FnbOrder;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Location;
use App\Models\Playstation;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WorkShift;
use App\Services\Billing\LostTimeBillingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DynamicPaymentFlowFnbTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $kasir;
    protected User $fnbStaff;
    protected Playstation $playstation;
    protected Device $device;
    protected Location $stationLocation;
    protected WorkShift $workShift;
    protected Fnb $fnbItem1;
    protected Fnb $fnbItem2;
    protected Transaction $paketTx;
    protected Transaction $lostTimeTx;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workShift = WorkShift::create([
            'nama_shift' => 'Shift Pagi',
            'jam_mulai' => '08:00:00',
            'jam_selesai' => '16:00:00',
        ]);

        $this->admin = User::factory()->create([
            'name' => 'Admin Test',
            'role' => 'admin',
            'email' => 'admin_dynamic@test.local',
        ]);

        $this->kasir = User::factory()->create([
            'name' => 'Kasir Utama',
            'role' => 'kasir',
            'is_fnb_staff' => false,
            'shift' => 'Pagi',
            'work_shift_id' => $this->workShift->id,
            'email' => 'kasir_dynamic@test.local',
        ]);

        $this->fnbStaff = User::factory()->create([
            'name' => 'Staf Dapur F&B',
            'role' => 'kasir',
            'is_fnb_staff' => true,
            'shift' => 'Pagi',
            'work_shift_id' => $this->workShift->id,
            'email' => 'fnb_dynamic@test.local',
        ]);

        $this->playstation = Playstation::create([
            'nama' => 'PS5 Regular',
            'harga' => 15000,
        ]);

        $this->device = Device::create([
            'nama' => 'PS-01',
            'playstation_id' => $this->playstation->id,
            'status' => 'Digunakan',
        ]);
        $this->device->playstations()->attach($this->playstation->id);

        $this->stationLocation = Location::create([
            'name' => 'Station Bilik 1',
            'code' => 'ST-01',
            'type' => 'station',
            'device_id' => $this->device->id,
            'is_active' => true,
        ]);

        $this->fnbItem1 = Fnb::create([
            'nama' => 'Mie Goreng Telur',
            'harga_beli' => 5000,
            'harga_jual' => 10000,
            'stok' => 20,
        ]);

        $this->fnbItem2 = Fnb::create([
            'nama' => 'Es Jeruk Manis',
            'harga_beli' => 2000,
            'harga_jual' => 5000,
            'stok' => 30,
        ]);

        // Active prepaid paket session (total paid at start: 20.000)
        $this->paketTx = Transaction::create([
            'nama' => 'Pelanggan Paket',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'prepaid',
            'billing_status' => Transaction::BILLING_RUNNING,
            'status' => 'Berjalan',
            'status_transaksi' => 'berjalan',
            'payment_status' => 'paid',
            'harga' => 20000,
            'total' => 20000,
            'waktu_mulai' => '10:00:00',
            'started_at' => Carbon::now()->subMinutes(30),
            'session_token' => Str::random(32),
        ]);

        // Active postpaid lost-time session
        $this->lostTimeTx = Transaction::create([
            'nama' => 'Pelanggan Lost Time',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'postpaid',
            'billing_status' => Transaction::BILLING_RUNNING,
            'status' => 'Berjalan',
            'status_transaksi' => 'berjalan',
            'payment_status' => 'unpaid',
            'harga' => 15000,
            'total' => 0,
            'waktu_mulai' => '11:00:00',
            'started_at' => Carbon::now()->subMinutes(60),
            'session_token' => Str::random(32),
        ]);
    }

    /**
     * Scenario 1:
     * Paket session primary flow creates standalone F&B invoice without altering game bill.
     * Order can immediately be accepted by kitchen.
     *
     * @test
     */
    public function test_paket_session_primary_flow_creates_standalone_fnb_invoice_without_altering_game_bill()
    {
        $payload = [
            'customer_type' => 'playing',
            'transaction_id' => $this->paketTx->id_transaksi,
            'action' => 'pay_now',
            'payment_method' => 'tunai',
            'amount_paid' => 20000,
            'items' => [
                ['fnb_id' => $this->fnbItem1->id, 'qty' => 2], // 2 x 10.000 = 20.000
            ],
        ];

        $response = $this->actingAs($this->kasir)
            ->post(route('fnb.orders.store'), $payload);

        $order = FnbOrder::where('transaction_id', $this->paketTx->id_transaksi)->latest('id')->first();
        $this->assertNotNull($order);
        $this->assertTrue((bool) $order->requires_upfront_payment);
        $this->assertEquals('paid', $order->payment_status);

        // Standalone F&B invoice generated
        $invoice = Invoice::where('transaction_id', $this->paketTx->id_transaksi)
            ->where('invoice_type', Invoice::TYPE_FNB_STANDALONE)
            ->latest('id')
            ->first();

        $this->assertNotNull($invoice);
        $this->assertEquals(20000, (float) $invoice->subtotal);
        $this->assertEquals(20000, (float) $invoice->final_total);
        $this->assertEquals(20000, (float) $invoice->amount_paid);
        $response->assertRedirect(route('fnb.invoice.show', $invoice->id));

        // Game transaction total is NOT altered or rebilled
        $this->assertEquals(20000, (float) $this->paketTx->fresh()->total);
        $this->assertEquals('paid', $this->paketTx->fresh()->payment_status);

        // Order can immediately be accepted by kitchen (ACCEPTED)
        $acceptRes = $this->actingAs($this->fnbStaff)->post(route('fnb.queue.status', $order->id), [
            'status' => FnbOrder::STATUS_ACCEPTED,
        ]);
        $acceptRes->assertRedirect(route('fnb.queue.index'));
        $this->assertEquals(FnbOrder::STATUS_ACCEPTED, $order->fresh()->status);
    }

    /**
     * Scenario 2:
     * Lost-time session primary flow saves order unpaid and allows kitchen processing.
     *
     * @test
     */
    public function test_lost_time_session_primary_flow_saves_order_unpaid_and_allows_kitchen_processing()
    {
        $payload = [
            'customer_type' => 'playing',
            'transaction_id' => $this->lostTimeTx->id_transaksi,
            'action' => 'save',
            'items' => [
                ['fnb_id' => $this->fnbItem1->id, 'qty' => 1],
            ],
        ];

        $response = $this->actingAs($this->kasir)
            ->post(route('fnb.orders.store'), $payload);

        $response->assertRedirect(route('fnb.queue.index'));

        $order = FnbOrder::where('transaction_id', $this->lostTimeTx->id_transaksi)->latest('id')->first();
        $this->assertNotNull($order);
        $this->assertFalse((bool) $order->requires_upfront_payment);
        $this->assertEquals('unpaid', $order->payment_status);

        // Assert no invoice is created at this time
        $this->assertDatabaseMissing('invoices', [
            'transaction_id' => $this->lostTimeTx->id_transaksi,
        ]);

        // Order can be accepted by kitchen (ACCEPTED) despite being unpaid
        $acceptRes = $this->actingAs($this->fnbStaff)->post(route('fnb.queue.status', $order->id), [
            'status' => FnbOrder::STATUS_ACCEPTED,
        ]);
        $acceptRes->assertRedirect(route('fnb.queue.index'));
        $this->assertEquals(FnbOrder::STATUS_ACCEPTED, $order->fresh()->status);
    }

    /**
     * Scenario 3:
     * Lost-time early settlement creates separate invoice and is not rebilled at final checkout.
     *
     * @test
     */
    public function test_lost_time_early_settlement_creates_separate_invoice_and_is_not_rebilled_at_final_checkout()
    {
        // 1. Submit F&B order with early settlement (action = 'pay_now')
        $payload = [
            'customer_type' => 'playing',
            'transaction_id' => $this->lostTimeTx->id_transaksi,
            'action' => 'pay_now',
            'payment_method' => 'tunai',
            'amount_paid' => 10000,
            'items' => [
                ['fnb_id' => $this->fnbItem1->id, 'qty' => 1], // 10.000
            ],
        ];

        $response = $this->actingAs($this->kasir)
            ->post(route('fnb.orders.store'), $payload);

        $order = FnbOrder::where('transaction_id', $this->lostTimeTx->id_transaksi)->latest('id')->first();
        $this->assertNotNull($order);
        $this->assertEquals('paid', $order->payment_status);

        // Assert standalone F&B invoice is generated
        $fnbInvoice = Invoice::where('transaction_id', $this->lostTimeTx->id_transaksi)
            ->where('invoice_type', Invoice::TYPE_FNB_STANDALONE)
            ->first();

        $this->assertNotNull($fnbInvoice);
        $this->assertEquals(10000, (float) $fnbInvoice->final_total);
        $response->assertRedirect(route('fnb.invoice.show', $fnbInvoice->id));

        // 2. End session / settle via LostTimeBillingService::settleLostTimeTransaction
        /** @var LostTimeBillingService $lostTimeService */
        $lostTimeService = app(LostTimeBillingService::class);
        $checkoutInvoice = $lostTimeService->settleLostTimeTransaction($this->lostTimeTx->id_transaksi, [
            'payment_method' => 'tunai',
            'amount_paid' => 100000,
        ]);

        $this->assertNotNull($checkoutInvoice);
        $this->assertNotEquals($fnbInvoice->id, $checkoutInvoice->id);

        // Assert final checkout does NOT include the already-settled F&B items on the session invoice
        $fnbItemsOnCheckoutInvoice = $checkoutInvoice->items()
            ->where('item_type', InvoiceItem::ITEM_TYPE_FNB_ITEM)
            ->count();

        $this->assertEquals(0, $fnbItemsOnCheckoutInvoice);
        $this->assertEquals(Invoice::TYPE_GAME_ONLY, $checkoutInvoice->invoice_type);
    }

    /**
     * Scenario 4:
     * Non-playing customer primary flow creates standalone invoice.
     *
     * @test
     */
    public function test_non_playing_customer_primary_flow_creates_standalone_invoice()
    {
        $payload = [
            'customer_type' => 'non_playing',
            'customer_name' => 'Pelanggan Meja 5 Walkin',
            'order_type' => 'takeaway',
            'action' => 'pay_now',
            'payment_method' => 'tunai',
            'amount_paid' => 15000,
            'items' => [
                ['fnb_id' => $this->fnbItem2->id, 'qty' => 3], // 3 x 5.000 = 15.000
            ],
        ];

        $response = $this->actingAs($this->kasir)
            ->post(route('fnb.orders.store'), $payload);

        $order = FnbOrder::where('customer_name', 'Pelanggan Meja 5 Walkin')->first();
        $this->assertNotNull($order);
        $this->assertNull($order->transaction_id);
        $this->assertTrue((bool) $order->requires_upfront_payment);
        $this->assertEquals('paid', $order->payment_status);

        $invoice = Invoice::where('invoice_type', Invoice::TYPE_FNB_STANDALONE)
            ->whereNull('transaction_id')
            ->latest('id')
            ->first();

        $this->assertNotNull($invoice);
        $this->assertEquals(15000, (float) $invoice->final_total);
        $response->assertRedirect(route('fnb.invoice.show', $invoice->id));

        // Order can be accepted by kitchen (ACCEPTED)
        $acceptRes = $this->actingAs($this->fnbStaff)->post(route('fnb.queue.status', $order->id), [
            'status' => FnbOrder::STATUS_ACCEPTED,
        ]);
        $acceptRes->assertRedirect(route('fnb.queue.index'));
        $this->assertEquals(FnbOrder::STATUS_ACCEPTED, $order->fresh()->status);
    }

    /**
     * Scenario 5:
     * Non-playing saved as pending blocks kitchen until paid.
     *
     * @test
     */
    public function test_non_playing_saved_as_pending_blocks_kitchen_until_paid()
    {
        $payload = [
            'customer_type' => 'non_playing',
            'customer_name' => 'Pelanggan Non-Main Pending',
            'order_type' => 'dine_in',
            'action' => 'save',
            'items' => [
                ['fnb_id' => $this->fnbItem1->id, 'qty' => 1], // 10.000
            ],
        ];

        $response = $this->actingAs($this->kasir)
            ->post(route('fnb.orders.store'), $payload);

        $response->assertRedirect(route('fnb.queue.index'));

        $order = FnbOrder::where('customer_name', 'Pelanggan Non-Main Pending')->first();
        $this->assertNotNull($order);
        $this->assertEquals('unpaid', $order->payment_status);
        $this->assertTrue((bool) $order->requires_upfront_payment);

        // 1. Kitchen cannot advance to ACCEPTED while unpaid
        $blockedAttempt = $this->actingAs($this->fnbStaff)->post(route('fnb.queue.status', $order->id), [
            'status' => FnbOrder::STATUS_ACCEPTED,
        ]);
        $blockedAttempt->assertSessionHas('gagal');
        $this->assertEquals(FnbOrder::STATUS_PENDING, $order->fresh()->status);

        // 2. Cashier settles payment via fnb.queue.pay
        $payRes = $this->actingAs($this->kasir)->post(route('fnb.queue.pay', $order->id), [
            'payment_method' => 'tunai',
            'amount_paid' => 10000,
        ]);
        $payRes->assertSessionHas('success');
        $this->assertEquals('paid', $order->fresh()->payment_status);

        // 3. Kitchen can now advance to ACCEPTED
        $acceptRes = $this->actingAs($this->fnbStaff)->post(route('fnb.queue.status', $order->id), [
            'status' => FnbOrder::STATUS_ACCEPTED,
        ]);
        $acceptRes->assertSessionHas('success');
        $this->assertEquals(FnbOrder::STATUS_ACCEPTED, $order->fresh()->status);
    }

    /**
     * Scenario 6:
     * F&B staff (kasir F&B) can submit pay_now for non-playing walkin and advance order to ACCEPTED.
     * Owner is strictly forbidden with 403.
     *
     * @test
     */
    public function test_fnb_staff_submitting_pay_now_for_non_playing_creates_invoice_and_advances_to_accepted()
    {
        $payload = [
            'customer_type' => 'non_playing',
            'customer_name' => 'Tamu Non-Bermain Kasir FnB',
            'order_type' => 'dine_in',
            'action' => 'pay_now',
            'payment_method' => 'tunai',
            'amount_paid' => 15000,
            'items' => [
                ['fnb_id' => $this->fnbItem2->id, 'qty' => 3], // 3 x 5.000 = 15.000
            ],
        ];

        // Kasir F&B processes payment and kitchen receives order as ACCEPTED
        $response = $this->actingAs($this->fnbStaff)
            ->post(route('fnb.orders.store'), $payload);

        $order = FnbOrder::where('customer_name', 'Tamu Non-Bermain Kasir FnB')->firstOrFail();
        $this->assertEquals('paid', $order->payment_status);
        $this->assertEquals(FnbOrder::STATUS_ACCEPTED, $order->status);

        $invoice = Invoice::where('transaction_id', null)
            ->where('invoice_type', Invoice::TYPE_FNB_STANDALONE)
            ->latest('id')
            ->firstOrFail();

        $this->assertEquals(15000, (float) $invoice->final_total);
        $response->assertRedirect(route('fnb.invoice.show', $invoice->id));

        // Kasir F&B can view and print the invoice receipt (not forbidden with 403)
        $this->actingAs($this->fnbStaff)
            ->get(route('fnb.invoice.show', $invoice->id))
            ->assertStatus(200)
            ->assertSee($invoice->invoice_number);

        // Owner role is read-only and strictly forbidden from pay_now (403)
        $owner = User::factory()->create(['role' => 'owner']);
        $ownerResponse = $this->actingAs($owner)
            ->post(route('fnb.orders.store'), $payload);
        $ownerResponse->assertStatus(403);
    }

    /**
     * Scenario 7:
     * View action buttons render correct labels for paket, lost time, and non-playing.
     * Cashier and Kasir F&B see action buttons and payment modal; Owner sees monitoring alert.
     *
     * @test
     */
    public function test_view_action_buttons_render_correct_labels_for_paket_lost_time_and_non_playing()
    {
        // 1. Kasir & Kasir F&B view verification
        foreach ([$this->kasir, $this->fnbStaff] as $user) {
            $res = $this->actingAs($user)->get(route('fnb.orders.create'));
            $res->assertStatus(200);

            // Assert button text definitions and guidance
            $res->assertSee('Simpan Transaksi');
            $res->assertSee('Bayar dan Proses');
            $res->assertSee('Bayar Sekarang');
            $res->assertSee('Bayar Sekarang Lebih Awal');
            $res->assertSee('Simpan Pending (Bayar Nanti)');
            $res->assertSee('Tagihan F&B akan dicatat ke meja dan dilunasi bersama billing main saat checkout.', false);
            $res->assertSee('Sesi Paket dapat dilayani langsung. Pembayaran dapat dilakukan saat staf mengantar makanan atau setelah sesi bermain selesai.', false);
            $res->assertSee('Pelanggan Non-Bermain dapat langsung bayar di kasir F&B dan pesanan langsung diproses dapur.', false);

            // Assert payment modal elements rendered
            $res->assertSee('id="modalPaymentSummary"', false);
            $res->assertSee('Konfirmasi Pembayaran & Cetak Struk');
            $res->assertSee('id="modalSummaryCustomerType"', false);
            $res->assertSee('id="modalSummaryLocation"', false);
            $res->assertSee('id="modalSummaryCustomerName"', false);
            $res->assertSee('id="modalOrderItemsBody"', false);
        }

        // 2. Owner view verification
        $owner = User::factory()->create(['role' => 'owner']);
        $ownerRes = $this->actingAs($owner)->get(route('fnb.orders.create'));
        $ownerRes->assertStatus(200);
        $ownerRes->assertSee('Akun Owner hanya dapat memantau menu transaksi F&amp;B.', false);
        $ownerRes->assertDontSee('id="modalPaymentSummary"', false);
    }
}
