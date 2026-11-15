<?php

namespace Tests\Feature\Fnb;

use App\Models\Device;
use App\Models\Fnb;
use App\Models\FnbOrder;
use App\Models\FnbOrderItem;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\Playstation;
use App\Models\PriceGroup;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WorkShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FnbTransactionSeparationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $kasir;
    protected User $fnbStaff;
    protected User $owner;
    protected Playstation $playstation;
    protected Device $device;
    protected Location $stationLocation;
    protected WorkShift $workShift;
    protected Fnb $fnbSnack;
    protected Fnb $fnbDrink;
    protected Fnb $fnbUnlimited;
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
            'email' => 'admin@test.local',
        ]);

        $this->kasir = User::factory()->create([
            'name' => 'Kasir Utama',
            'role' => 'kasir',
            'is_fnb_staff' => false,
            'shift' => 'Pagi',
            'work_shift_id' => $this->workShift->id,
            'email' => 'kasir@test.local',
        ]);

        $this->fnbStaff = User::factory()->create([
            'name' => 'Staf Dapur',
            'role' => 'kasir',
            'is_fnb_staff' => true,
            'shift' => 'Pagi',
            'work_shift_id' => $this->workShift->id,
            'email' => 'fnbstaff@test.local',
        ]);

        $this->owner = User::factory()->create([
            'name' => 'Owner Test',
            'role' => 'owner',
            'email' => 'owner@test.local',
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
            'name' => 'Bilik 1',
            'code' => 'BILIK-01',
            'type' => 'station',
            'device_id' => $this->device->id,
            'is_active' => true,
        ]);

        $priceGroup = PriceGroup::create([
            'nama' => 'Minuman Dingin',
            'harga' => 8000,
        ]);

        $this->fnbSnack = Fnb::create([
            'nama' => 'Indomie Goreng',
            'harga_beli' => 5000,
            'harga_jual' => 10000,
            'stok' => 10,
        ]);

        $this->fnbDrink = Fnb::create([
            'nama' => 'Es Teh Manis',
            'harga_beli' => 2000,
            'harga_jual' => 5000,
            'price_group_id' => $priceGroup->id,
            'stok' => 15,
        ]);

        $this->fnbUnlimited = Fnb::create([
            'nama' => 'Air Mineral Refill',
            'harga_beli' => 0,
            'harga_jual' => 3000,
            'stok' => -1,
        ]);

        $this->paketTx = Transaction::create([
            'nama' => 'Budi Paket',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'prepaid',
            'billing_status' => Transaction::BILLING_RUNNING,
            'status' => 'Berjalan',
            'status_transaksi' => 'berjalan',
            'payment_status' => 'paid',
            'harga' => 15000,
            'total' => 15000,
            'waktu_mulai' => '10:00:00',
            'started_at' => now(),
            'session_token' => Str::random(32),
        ]);

        $this->lostTimeTx = Transaction::create([
            'nama' => 'Siti Lost Time',
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
            'started_at' => now(),
            'session_token' => Str::random(32),
        ]);
    }

    /** @test */
    public function test_access_create_fnb_transaction_route()
    {
        // Unauthenticated redirected to login
        $response = $this->get(route('fnb.orders.create'));
        $response->assertRedirect('/login');

        // Kasir can access
        $kasirRes = $this->actingAs($this->kasir)->get(route('fnb.orders.create'));
        $kasirRes->assertStatus(200);
        $kasirRes->assertViewIs('fnb.transaction_create');
        $kasirRes->assertViewHas('title', 'Transaksi F&B');

        // Admin can access
        $adminRes = $this->actingAs($this->admin)->get(route('fnb.orders.create'));
        $adminRes->assertStatus(200);

        // F&B Staff can access
        $staffRes = $this->actingAs($this->fnbStaff)->get(route('fnb.orders.create'));
        $staffRes->assertStatus(200);
    }

    /** @test */
    public function test_action_save_creates_unpaid_order_in_queue()
    {
        $payload = [
            'action' => 'save',
            'customer_name' => 'Pelanggan Meja 1',
            'location_id' => $this->stationLocation->id,
            'items' => [
                ['fnb_id' => $this->fnbSnack->id, 'qty' => 2, 'notes' => 'Pedas'],
            ],
        ];

        // Form post redirects to queue with flash message
        $response = $this->actingAs($this->kasir)
            ->post(route('fnb.orders.store'), $payload);

        $response->assertRedirect(route('fnb.queue.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('fnb_orders', [
            'customer_name' => 'Pelanggan Meja 1',
            'status' => FnbOrder::STATUS_PENDING,
            'payment_status' => 'unpaid',
            'requires_upfront_payment' => true,
        ]);

        $order = FnbOrder::where('customer_name', 'Pelanggan Meja 1')->first();
        $this->assertNotNull($order);
        $this->assertCount(1, $order->items);
        $this->assertEquals(2, $order->items->first()->qty);
        $this->assertEquals('unpaid', $order->items->first()->payment_status);

        // JSON post returns 201 with order and redirect_url
        $jsonPayload = [
            'action' => 'save',
            'customer_name' => 'Pelanggan Meja 2',
            'items' => [
                ['fnb_id' => $this->fnbDrink->id, 'qty' => 1],
            ],
        ];

        $jsonRes = $this->actingAs($this->kasir)
            ->postJson(route('fnb.orders.store'), $jsonPayload);

        $jsonRes->assertStatus(201);
        $jsonRes->assertJson([
            'success' => true,
            'redirect_url' => route('fnb.queue.index'),
        ]);
    }

    /** @test */
    public function test_action_pay_now_by_kasir_creates_order_and_settles_payment()
    {
        $payload = [
            'action' => 'pay_now',
            'customer_name' => 'Pelanggan Langsung Bayar',
            'payment_method' => 'tunai',
            'discount' => 10, // 10% discount
            'amount_paid' => 20000,
            'items' => [
                ['fnb_id' => $this->fnbSnack->id, 'qty' => 2], // 2 * 10.000 = 20.000, 10% discount = 18.000
            ],
        ];

        $response = $this->actingAs($this->kasir)
            ->post(route('fnb.orders.store'), $payload);

        $order = FnbOrder::where('customer_name', 'Pelanggan Langsung Bayar')->first();
        $this->assertNotNull($order);
        $this->assertEquals('paid', $order->payment_status);

        $invoice = Invoice::where('invoice_type', Invoice::TYPE_FNB_STANDALONE)
            ->where('cashier_user_id', $this->kasir->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($invoice);
        $this->assertEquals(20000, (float) $invoice->subtotal);
        $this->assertEquals(2000, (float) $invoice->discount);
        $this->assertEquals(18000, (float) $invoice->final_total);
        $this->assertEquals(20000, (float) $invoice->amount_paid);
        $this->assertEquals(2000, (float) $invoice->change_amount);
        $this->assertNotNull($invoice->paid_at);

        $response->assertRedirect(route('fnb.invoice.show', $invoice->id));

        // JSON post returns redirect_url to invoice
        $jsonPayload = [
            'action' => 'pay_now',
            'customer_name' => 'Pelanggan JSON Bayar',
            'payment_method' => 'tunai',
            'amount_paid' => 10000,
            'items' => [
                ['fnb_id' => $this->fnbSnack->id, 'qty' => 1],
            ],
        ];

        $jsonRes = $this->actingAs($this->kasir)
            ->postJson(route('fnb.orders.store'), $jsonPayload);

        $jsonRes->assertStatus(201);
        $jsonRes->assertJson([
            'success' => true,
        ]);
        $this->assertStringContainsString('/fnb/invoices/', $jsonRes->json('redirect_url'));
    }

    /** @test */
    public function test_action_pay_now_by_fnb_staff_succeeds_and_owner_is_blocked_with_403()
    {
        $payload = [
            'action' => 'pay_now',
            'customer_name' => 'Order Kasir FnB Walkin',
            'payment_method' => 'tunai',
            'amount_paid' => 10000,
            'items' => [
                ['fnb_id' => $this->fnbSnack->id, 'qty' => 1],
            ],
        ];

        // Kasir F&B is authorized to make bills and process payment
        $response = $this->actingAs($this->fnbStaff)
            ->post(route('fnb.orders.store'), $payload);

        $order = FnbOrder::where('customer_name', 'Order Kasir FnB Walkin')->firstOrFail();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame(FnbOrder::STATUS_ACCEPTED, $order->status);

        // Owner is strictly forbidden with 403
        $owner = User::factory()->create(['role' => 'owner']);
        $ownerResponse = $this->actingAs($owner)
            ->post(route('fnb.orders.store'), $payload);
        $ownerResponse->assertStatus(403);
    }

    /** @test */
    public function test_paket_session_sets_requires_upfront_payment_true()
    {
        $payload = [
            'transaction_id' => $this->paketTx->id_transaksi,
            'action' => 'save',
            'items' => [
                ['fnb_id' => $this->fnbSnack->id, 'qty' => 1],
            ],
        ];

        $this->actingAs($this->kasir)->post(route('fnb.orders.store'), $payload);

        $order = FnbOrder::where('transaction_id', $this->paketTx->id_transaksi)->first();
        $this->assertNotNull($order);
        $this->assertTrue((bool) $order->requires_upfront_payment);
    }

    /** @test */
    public function test_lost_time_session_sets_requires_upfront_payment_false()
    {
        $payload = [
            'transaction_id' => $this->lostTimeTx->id_transaksi,
            'action' => 'save',
            'items' => [
                ['fnb_id' => $this->fnbSnack->id, 'qty' => 1],
            ],
        ];

        $this->actingAs($this->kasir)->post(route('fnb.orders.store'), $payload);

        $order = FnbOrder::where('transaction_id', $this->lostTimeTx->id_transaksi)->first();
        $this->assertNotNull($order);
        $this->assertFalse((bool) $order->requires_upfront_payment);
    }

    /** @test */
    public function test_non_playing_walkin_sets_requires_upfront_payment_true()
    {
        $payload = [
            'transaction_id' => null,
            'customer_name' => 'Walkin Non-Bermain',
            'action' => 'save',
            'items' => [
                ['fnb_id' => $this->fnbSnack->id, 'qty' => 1],
            ],
        ];

        $this->actingAs($this->kasir)->post(route('fnb.orders.store'), $payload);

        $order = FnbOrder::where('customer_name', 'Walkin Non-Bermain')->first();
        $this->assertNotNull($order);
        $this->assertNull($order->transaction_id);
        $this->assertTrue((bool) $order->requires_upfront_payment);
    }

    /** @test */
    public function test_infinite_stock_items_work_without_deduction_error()
    {
        $payload = [
            'action' => 'save',
            'customer_name' => 'Order Refill Unlimited',
            'items' => [
                ['fnb_id' => $this->fnbUnlimited->id, 'qty' => 50],
            ],
        ];

        $response = $this->actingAs($this->kasir)->post(route('fnb.orders.store'), $payload);
        $response->assertRedirect(route('fnb.queue.index'));

        $this->assertDatabaseHas('fnb_orders', [
            'customer_name' => 'Order Refill Unlimited',
        ]);

        $this->fnbUnlimited->refresh();
        $this->assertEquals(-1, $this->fnbUnlimited->stok);
    }

    /** @test */
    public function test_insufficient_stock_is_rejected_atomically_with_422()
    {
        $payload = [
            'action' => 'save',
            'customer_name' => 'Order Stok Kurang',
            'items' => [
                ['fnb_id' => $this->fnbSnack->id, 'qty' => 999], // Snack only has 10
            ],
        ];

        $response = $this->actingAs($this->kasir)->postJson(route('fnb.orders.store'), $payload);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('fnb_orders', [
            'customer_name' => 'Order Stok Kurang',
        ]);
    }

    /** @test */
    public function test_view_renders_customer_type_toggle_sessions_locations_and_products()
    {
        $response = $this->actingAs($this->kasir)->get(route('fnb.orders.create'));

        $response->assertStatus(200);
        $response->assertSee('value="playing"', false);
        $response->assertSee('value="non_playing"', false);
        $response->assertSee($this->paketTx->nama);
        $response->assertSee($this->device->nama);
        $response->assertSee($this->stationLocation->name);
        $response->assertSee($this->fnbSnack->nama);
        $response->assertSee(number_format($this->fnbSnack->harga_jual, 0, ',', '.'));
    }

    /** @test */
    public function test_cashier_and_admin_see_both_save_order_and_pay_now_with_payment_modal()
    {
        foreach ([$this->kasir, $this->admin] as $user) {
            $response = $this->actingAs($user)->get(route('fnb.orders.create'));

            $response->assertStatus(200);
            $response->assertSee('Simpan Transaksi');
            $response->assertSee('Bayar Sekarang');
            $response->assertSee('Bayar Sekarang Lebih Awal');
            $response->assertSee('Simpan Pending');
            $response->assertSee('id="modalPaymentSummary"', false);
            $response->assertSee('id="modalSummaryCustomerType"', false);
            $response->assertSee('id="modalSummaryLocation"', false);
            $response->assertSee('id="modalSummaryCustomerName"', false);
            $response->assertSee('id="modalOrderItemsBody"', false);
            $response->assertSee('updateDynamicActionButtons');
            $response->assertSee('value="pay_now"', false);
            $response->assertSee('value="save"', false);
        }
    }

    /** @test */
    public function test_fnb_staff_sees_save_order_and_pay_now_with_payment_modal()
    {
        $response = $this->actingAs($this->fnbStaff)->get(route('fnb.orders.create'));

        $response->assertStatus(200);
        $response->assertSee('Simpan Transaksi');
        $response->assertSee('Bayar dan Proses');
        $response->assertSee('value="save"', false);
        $response->assertSee('value="pay_now"', false);
        $response->assertSee('id="modalPaymentSummary"', false);
    }

    /** @test */
    public function test_sidebar_renders_transaksi_fnb_and_antrean_fnb_links_for_authorized_roles()
    {
        foreach ([$this->kasir, $this->admin, $this->fnbStaff] as $user) {
            $view = $this->actingAs($user)->view('layouts.sidebar', ['active' => 'fnb-transaction']);

            $view->assertSee(route('fnb.orders.create'));
            $view->assertSee('Transaksi F&amp;B', false);
            $view->assertSee('fa-cash-register');
            $view->assertSee(route('fnb.queue.index'));
            $view->assertSee('Antrean Order F&amp;B', false);
            $view->assertSee('fa-concierge-bell');
        }
    }

    /** @test */
    public function test_fnb_queue_view_does_not_contain_create_order_modal_and_links_to_transaction_create()
    {
        foreach ([$this->kasir, $this->admin, $this->fnbStaff] as $user) {
            $response = $this->actingAs($user)->get(route('fnb.queue.index'));

            $response->assertStatus(200);
            $response->assertDontSee('id="modalCreateFnbOrder"', false);
            $response->assertDontSee('data-target="#modalCreateFnbOrder"', false);
            $response->assertDontSee('Buat Order Baru');
            $response->assertSee(route('fnb.orders.create'));
            $response->assertSee('Transaksi F&amp;B Baru', false);
        }

        // Owner can monitor queue but does not see Transaksi F&B Baru action button
        $ownerResponse = $this->actingAs($this->owner)->get(route('fnb.queue.index'));
        $ownerResponse->assertStatus(200);
        $ownerResponse->assertDontSee('Transaksi F&amp;B Baru', false);
        $ownerResponse->assertDontSee('id="modalCreateFnbOrder"', false);
    }

    /** @test */
    public function test_order_created_in_fnb_transaction_appears_in_queue()
    {
        $payload = [
            'action' => 'save',
            'customer_name' => 'Pelanggan Meja Queue Test',
            'location_id' => $this->stationLocation->id,
            'notes' => 'Tolong pedas banget',
            'items' => [
                ['fnb_id' => $this->fnbSnack->id, 'qty' => 2, 'notes' => 'Cabai 5'],
                ['fnb_id' => $this->fnbDrink->id, 'qty' => 1, 'notes' => 'Gula sedikit'],
            ],
        ];

        $createRes = $this->actingAs($this->kasir)->post(route('fnb.orders.store'), $payload);
        $createRes->assertRedirect(route('fnb.queue.index'));

        $order = FnbOrder::where('customer_name', 'Pelanggan Meja Queue Test')->first();
        $this->assertNotNull($order);

        $queueRes = $this->actingAs($this->kasir)->get(route('fnb.queue.index'));
        $queueRes->assertStatus(200);
        $queueRes->assertSee($order->order_number);
        $queueRes->assertSee('Pelanggan Meja Queue Test');
        $queueRes->assertSee($this->stationLocation->name);
        $queueRes->assertSee('Indomie Goreng');
        $queueRes->assertSee('Cabai 5');
        $queueRes->assertSee('Es Teh Manis');
        $queueRes->assertSee('Gula sedikit');
        $queueRes->assertSee('Tolong pedas banget');
        $queueRes->assertSee(number_format($order->total_amount, 0, ',', '.'));
        $queueRes->assertSee('Non-Bermain');

        // Create Lost Time order and Paket order to verify badges in queue
        $lostTimePayload = [
            'action' => 'save',
            'transaction_id' => $this->lostTimeTx->id_transaksi,
            'customer_name' => 'Player Lost Time Queue',
            'items' => [['fnb_id' => $this->fnbDrink->id, 'qty' => 1]],
        ];
        $this->actingAs($this->kasir)->post(route('fnb.orders.store'), $lostTimePayload);

        $paketPayload = [
            'action' => 'save',
            'transaction_id' => $this->paketTx->id_transaksi,
            'customer_name' => 'Player Paket Queue',
            'items' => [['fnb_id' => $this->fnbSnack->id, 'qty' => 1]],
        ];
        $this->actingAs($this->kasir)->post(route('fnb.orders.store'), $paketPayload);

        $queueRes2 = $this->actingAs($this->kasir)->get(route('fnb.queue.index'));
        $queueRes2->assertStatus(200);
        $queueRes2->assertSee('Lost Time');
        $queueRes2->assertSee('Paket');
        $queueRes2->assertSee('Bayar Saat Selesai Main');
        $queueRes2->assertSee('Bayar Saat Antar / Selesai');
    }

    /** @test */
    public function test_unpaid_paket_order_can_be_accepted_while_non_playing_order_is_blocked_until_paid()
    {
        // 1. Unpaid Paket order (can be served directly, paid when delivered or at session end)
        $paketPayload = [
            'transaction_id' => $this->paketTx->id_transaksi,
            'customer_name' => 'Budi Paket Unpaid',
            'action' => 'save',
            'items' => [
                ['fnb_id' => $this->fnbSnack->id, 'qty' => 1],
            ],
        ];
        $this->actingAs($this->kasir)->post(route('fnb.orders.store'), $paketPayload);
        $paketOrder = FnbOrder::where('customer_name', 'Budi Paket Unpaid')->firstOrFail();
        $this->assertSame('unpaid', $paketOrder->payment_status);

        // ACCEPTED status transition succeeds directly for Paket
        $acceptPaketRes = $this->actingAs($this->fnbStaff)
            ->postJson(route('fnb.queue.status', $paketOrder->id), ['status' => 'ACCEPTED']);
        $acceptPaketRes->assertStatus(200);
        $this->assertSame(FnbOrder::STATUS_ACCEPTED, $paketOrder->fresh()->status);

        // 2. Unpaid Non-Bermain order (blocked from kitchen until paid)
        $nonPlayingPayload = [
            'action' => 'save',
            'customer_name' => 'Tamu Non-Bermain Unpaid',
            'items' => [
                ['fnb_id' => $this->fnbDrink->id, 'qty' => 2],
            ],
        ];
        $this->actingAs($this->kasir)->post(route('fnb.orders.store'), $nonPlayingPayload);
        $nonPlayingOrder = FnbOrder::where('customer_name', 'Tamu Non-Bermain Unpaid')->firstOrFail();
        $this->assertSame('unpaid', $nonPlayingOrder->payment_status);

        // Attempt ACCEPTED status transition while unpaid -> must fail with 422
        $acceptNonPlayingRes = $this->actingAs($this->fnbStaff)
            ->postJson(route('fnb.queue.status', $nonPlayingOrder->id), ['status' => 'ACCEPTED']);
        $acceptNonPlayingRes->assertStatus(422);
        $this->assertSame(FnbOrder::STATUS_PENDING, $nonPlayingOrder->fresh()->status);

        // Verify HTML queue renders disabled button with tooltip for unpaid non-playing order
        $queueRes = $this->actingAs($this->kasir)->get(route('fnb.queue.index'));
        $queueRes->assertStatus(200);
        $queueRes->assertSee('disabled title=Wajib_lunas_terlebih_dahulu', false);
    }

    /** @test */
    public function test_paid_order_or_lost_time_order_can_advance_through_full_kitchen_lifecycle()
    {
        $initialSnackStock = $this->fnbSnack->fresh()->stok;

        // 1. Paid order via pay_now (automatically ACCEPTED and stock deducted)
        $paidPayload = [
            'action' => 'pay_now',
            'customer_name' => 'Pelanggan Lunas Lifecycle',
            'payment_method' => 'tunai',
            'amount_paid' => 20000,
            'items' => [
                ['fnb_id' => $this->fnbSnack->id, 'qty' => 2], // 2 * 10.000 = 20.000
            ],
        ];
        $this->actingAs($this->kasir)->post(route('fnb.orders.store'), $paidPayload);
        $paidOrder = FnbOrder::where('customer_name', 'Pelanggan Lunas Lifecycle')->firstOrFail();
        $this->assertSame('paid', $paidOrder->payment_status);
        $this->assertSame(FnbOrder::STATUS_ACCEPTED, $paidOrder->status);
        $this->assertSame($initialSnackStock - 2, $this->fnbSnack->fresh()->stok);

        // ACCEPTED -> PREPARING
        $this->actingAs($this->fnbStaff)
            ->post(route('fnb.queue.status', $paidOrder->id), ['status' => 'PREPARING'])
            ->assertRedirect(route('fnb.queue.index'));
        $this->assertSame(FnbOrder::STATUS_PREPARING, $paidOrder->fresh()->status);

        // PREPARING -> READY
        $this->actingAs($this->fnbStaff)
            ->post(route('fnb.queue.status', $paidOrder->id), ['status' => 'READY'])
            ->assertRedirect(route('fnb.queue.index'));
        $this->assertSame(FnbOrder::STATUS_READY, $paidOrder->fresh()->status);

        // READY -> DELIVERED
        $this->actingAs($this->fnbStaff)
            ->post(route('fnb.queue.status', $paidOrder->id), ['status' => 'DELIVERED'])
            ->assertRedirect(route('fnb.queue.index'));
        $this->assertSame(FnbOrder::STATUS_DELIVERED, $paidOrder->fresh()->status);

        // 2. Lost Time order (unpaid) can also advance through kitchen lifecycle
        $ltPayload = [
            'transaction_id' => $this->lostTimeTx->id_transaksi,
            'action' => 'save',
            'items' => [
                ['fnb_id' => $this->fnbSnack->id, 'qty' => 1],
            ],
        ];
        $this->actingAs($this->kasir)->post(route('fnb.orders.store'), $ltPayload);
        $ltOrder = FnbOrder::where('transaction_id', $this->lostTimeTx->id_transaksi)->latest('id')->firstOrFail();
        $this->assertFalse((bool) $ltOrder->requires_upfront_payment);
        $this->assertSame('unpaid', $ltOrder->payment_status);

        $snackStockBeforeLt = $this->fnbSnack->fresh()->stok;

        // PENDING -> ACCEPTED allowed while unpaid
        $this->actingAs($this->fnbStaff)
            ->post(route('fnb.queue.status', $ltOrder->id), ['status' => 'ACCEPTED'])
            ->assertRedirect(route('fnb.queue.index'));
        $this->assertSame(FnbOrder::STATUS_ACCEPTED, $ltOrder->fresh()->status);
        $this->assertSame($snackStockBeforeLt - 1, $this->fnbSnack->fresh()->stok);

        // ACCEPTED -> PREPARING -> READY -> DELIVERED
        $this->actingAs($this->fnbStaff)
            ->post(route('fnb.queue.status', $ltOrder->id), ['status' => 'PREPARING']);
        $this->assertSame(FnbOrder::STATUS_PREPARING, $ltOrder->fresh()->status);

        $this->actingAs($this->fnbStaff)
            ->post(route('fnb.queue.status', $ltOrder->id), ['status' => 'READY']);
        $this->assertSame(FnbOrder::STATUS_READY, $ltOrder->fresh()->status);

        $this->actingAs($this->fnbStaff)
            ->post(route('fnb.queue.status', $ltOrder->id), ['status' => 'DELIVERED']);
        $this->assertSame(FnbOrder::STATUS_DELIVERED, $ltOrder->fresh()->status);
    }
}
