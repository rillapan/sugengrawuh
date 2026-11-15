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
use App\Models\StockMutation;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WorkShift;
use App\Services\Fnb\FnbStockService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StockAndRbacIntegrationTest extends TestCase
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
    }

    /** @test */
    public function open_01_and_open_02_pay_first_orders_cannot_be_accepted_until_paid_then_deduct_stock_once()
    {
        // 1. Paket extra order (requires_upfront_payment = true)
        $paketTx = Transaction::create([
            'nama' => 'Andi Paket',
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

        $paketOrderResponse = $this->actingAs($this->kasir)
            ->postJson(route('fnb.orders.store'), [
                'transaction_id' => $paketTx->id_transaksi,
                'customer_name' => 'Andi Paket',
                'items' => [
                    ['fnb_id' => $this->fnbSnack->id, 'qty' => 2],
                ],
            ]);

        $paketOrderResponse->assertStatus(201);
        $paketOrderId = $paketOrderResponse->json('order.id');
        $paketOrder = FnbOrder::findOrFail($paketOrderId);
        $this->assertTrue($paketOrder->requires_upfront_payment);
        $this->assertSame('unpaid', $paketOrder->payment_status);

        // 2. Non-playing walk-in order (transaction_id = null, requires_upfront_payment = true)
        $walkinResponse = $this->actingAs($this->fnbStaff)
            ->postJson(route('fnb.orders.store'), [
                'customer_name' => 'Pengunjung Walk-In',
                'items' => [
                    ['fnb_id' => $this->fnbDrink->id, 'qty' => 3],
                ],
            ]);

        $walkinResponse->assertStatus(201);
        $walkinOrderId = $walkinResponse->json('order.id');
        $walkinOrder = FnbOrder::findOrFail($walkinOrderId);
        $this->assertNull($walkinOrder->transaction_id);
        $this->assertTrue($walkinOrder->requires_upfront_payment);
        $this->assertSame('unpaid', $walkinOrder->payment_status);
        $this->assertSame(FnbOrder::SOURCE_FNB_STAFF, $walkinOrder->source);
        // PriceGroup harga (8000) used instead of base harga_jual (5000)
        $this->assertEquals(24000, (float) $walkinOrder->items->first()->subtotal);

        // Active playing session (Paket) can be accepted immediately without waiting for payment
        $this->actingAs($this->fnbStaff)
            ->postJson(route('fnb.queue.status', $paketOrder->id), ['status' => FnbOrder::STATUS_ACCEPTED])
            ->assertStatus(200);

        // Unpaid Non-Bermain walk-in order must be rejected (422) until paid
        $this->actingAs($this->fnbStaff)
            ->postJson(route('fnb.queue.status', $walkinOrder->id), ['status' => FnbOrder::STATUS_ACCEPTED])
            ->assertStatus(422);

        $this->assertSame(8, $this->fnbSnack->fresh()->stok); // 10 - 2 = 8
        $this->assertSame(15, $this->fnbDrink->fresh()->stok);
        $this->assertSame(1, StockMutation::where('fnb_id', $this->fnbSnack->id)->count());

        // Non-F&B Kasir pays walk-in order via /fnb/queue/{id}/pay
        $payResponse = $this->actingAs($this->kasir)
            ->postJson(route('fnb.queue.pay', $walkinOrder->id), [
                'payment_method' => 'tunai',
                'amount_paid' => 30000,
            ]);

        $payResponse->assertStatus(200)
            ->assertJsonStructure(['invoice_id', 'invoice_number', 'redirect_url']);

        $invoiceId = $payResponse->json('invoice_id');
        $invoice = Invoice::findOrFail($invoiceId);
        $this->assertSame(Invoice::TYPE_FNB_STANDALONE, $invoice->invoice_type);
        $this->assertEquals(24000, (float) $invoice->final_total);
        $this->assertEquals(6000, (float) $invoice->change_amount);
        $this->assertSame('paid', $walkinOrder->fresh()->payment_status);

        // Non-F&B Kasir can view standalone invoice receipt
        $this->actingAs($this->kasir)
            ->get(route('fnb.invoice.show', $invoiceId))
            ->assertStatus(200)
            ->assertSee($invoice->invoice_number)
            ->assertSee('Es Teh Manis');

        // Now kitchen / F&B staff CAN accept the paid walk-in order -> stock deducted once
        $this->actingAs($this->fnbStaff)
            ->postJson(route('fnb.queue.status', $walkinOrder->id), ['status' => FnbOrder::STATUS_ACCEPTED])
            ->assertStatus(200);

        $this->assertSame(FnbOrder::STATUS_ACCEPTED, $walkinOrder->fresh()->status);
        $this->assertNotNull($walkinOrder->fresh()->accepted_at);
        $this->assertSame(12, $this->fnbDrink->fresh()->stok);
        $this->assertSame(1, StockMutation::where('fnb_id', $this->fnbDrink->id)->where('type', 'out')->count());
    }

    /** @test */
    public function open_06_lost_time_order_can_be_accepted_unpaid_and_stock_deduction_is_idempotent()
    {
        $lostTimeTx = Transaction::create([
            'nama' => 'Budi Lost Time',
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

        $orderRes = $this->actingAs($this->kasir)
            ->postJson(route('fnb.orders.store'), [
                'transaction_id' => $lostTimeTx->id_transaksi,
                'customer_name' => 'Teman Budi',
                'items' => [
                    ['fnb_id' => $this->fnbSnack->id, 'qty' => 2],
                    ['fnb_id' => $this->fnbUnlimited->id, 'qty' => 1],
                ],
            ]);

        $orderRes->assertStatus(201);
        $order = FnbOrder::findOrFail($orderRes->json('order.id'));
        $this->assertFalse($order->requires_upfront_payment);
        $this->assertSame('unpaid', $order->payment_status);

        // Cannot skip ACCEPTED directly to READY
        $this->actingAs($this->fnbStaff)
            ->postJson(route('fnb.queue.status', $order->id), ['status' => FnbOrder::STATUS_READY])
            ->assertStatus(422);

        // Accept while unpaid -> allowed for Lost Time
        $this->actingAs($this->fnbStaff)
            ->postJson(route('fnb.queue.status', $order->id), ['status' => FnbOrder::STATUS_ACCEPTED])
            ->assertStatus(200);

        $this->assertSame(8, $this->fnbSnack->fresh()->stok);
        $this->assertSame(-1, $this->fnbUnlimited->fresh()->stok);
        $this->assertSame(1, StockMutation::where('fnb_id', $this->fnbSnack->id)->where('type', 'out')->count());
        $this->assertSame(0, StockMutation::where('fnb_id', $this->fnbUnlimited->id)->count());

        // Idempotent duplicate ACCEPT call does not deduct stock again
        app(FnbStockService::class)->acceptAndDeductStock($order->id, $this->fnbStaff->id);
        $this->assertSame(8, $this->fnbSnack->fresh()->stok);
        $this->assertSame(1, StockMutation::where('fnb_id', $this->fnbSnack->id)->where('type', 'out')->count());

        // Forward transitions ACCEPTED -> PREPARING -> READY -> DELIVERED do not deduct stock again
        foreach ([FnbOrder::STATUS_PREPARING, FnbOrder::STATUS_READY, FnbOrder::STATUS_DELIVERED] as $nextStatus) {
            $this->actingAs($this->fnbStaff)
                ->postJson(route('fnb.queue.status', $order->id), ['status' => $nextStatus])
                ->assertStatus(200);
        }

        $this->assertSame(FnbOrder::STATUS_DELIVERED, $order->fresh()->status);
        $this->assertSame(8, $this->fnbSnack->fresh()->stok);
        $this->assertSame(1, StockMutation::where('fnb_id', $this->fnbSnack->id)->where('type', 'out')->count());
    }

    /** @test */
    public function open_06_expire_command_only_expires_pending_orders_older_than_30_minutes_without_touching_stock()
    {
        Carbon::setTestNow(Carbon::parse('2026-11-15 12:00:00'));

        $oldPending = FnbOrder::create([
            'order_number' => 'ORD-OLD-PEND',
            'source' => FnbOrder::SOURCE_QR,
            'location_id' => $this->stationLocation->id,
            'customer_name' => 'Old Pending',
            'status' => FnbOrder::STATUS_PENDING,
            'payment_status' => 'unpaid',
            'requires_upfront_payment' => false,
        ]);
        FnbOrder::where('id', $oldPending->id)->update(['created_at' => Carbon::now()->subMinutes(31)]);

        $freshPending = FnbOrder::create([
            'order_number' => 'ORD-FRESH-PEND',
            'source' => FnbOrder::SOURCE_QR,
            'location_id' => $this->stationLocation->id,
            'customer_name' => 'Fresh Pending',
            'status' => FnbOrder::STATUS_PENDING,
            'payment_status' => 'unpaid',
            'requires_upfront_payment' => false,
        ]);
        FnbOrder::where('id', $freshPending->id)->update(['created_at' => Carbon::now()->subMinutes(15)]);

        $oldAccepted = FnbOrder::create([
            'order_number' => 'ORD-OLD-ACC',
            'source' => FnbOrder::SOURCE_QR,
            'location_id' => $this->stationLocation->id,
            'customer_name' => 'Old Accepted',
            'status' => FnbOrder::STATUS_ACCEPTED,
            'accepted_at' => Carbon::now()->subMinutes(115),
            'payment_status' => 'unpaid',
            'requires_upfront_payment' => false,
        ]);
        FnbOrder::where('id', $oldAccepted->id)->update(['created_at' => Carbon::now()->subMinutes(120)]);

        $oldPreparing = FnbOrder::create([
            'order_number' => 'ORD-OLD-PREP',
            'source' => FnbOrder::SOURCE_QR,
            'location_id' => $this->stationLocation->id,
            'customer_name' => 'Old Preparing',
            'status' => FnbOrder::STATUS_PREPARING,
            'accepted_at' => Carbon::now()->subMinutes(55),
            'payment_status' => 'unpaid',
            'requires_upfront_payment' => false,
        ]);
        FnbOrder::where('id', $oldPreparing->id)->update(['created_at' => Carbon::now()->subMinutes(60)]);

        FnbOrderItem::create([
            'fnb_order_id' => $oldPending->id,
            'fnb_id' => $this->fnbSnack->id,
            'qty' => 2,
            'unit_price_beli' => 5000,
            'unit_price_jual' => 10000,
            'subtotal' => 20000,
            'payment_status' => 'unpaid',
        ]);

        $this->artisan('fnb:expire-pending')->assertExitCode(0);

        $this->assertSame(FnbOrder::STATUS_EXPIRED, $oldPending->fresh()->status);
        $this->assertSame(FnbOrder::STATUS_PENDING, $freshPending->fresh()->status);
        $this->assertSame(FnbOrder::STATUS_ACCEPTED, $oldAccepted->fresh()->status);
        $this->assertSame(FnbOrder::STATUS_PREPARING, $oldPreparing->fresh()->status);

        // Stock untouched
        $this->assertSame(10, $this->fnbSnack->fresh()->stok);
        $this->assertSame(0, StockMutation::count());

        // Verify schedule registration
        $schedule = app(Schedule::class);
        $event = collect($schedule->events())->first(fn ($e) => str_contains($e->command ?? '', 'fnb:expire-pending'));
        $this->assertNotNull($event, 'fnb:expire-pending must be registered in Console Kernel schedule');
        $this->assertSame('*/5 * * * *', $event->expression);

        Carbon::setTestNow();
    }

    /** @test */
    public function open_06_cancelling_pending_does_not_touch_stock_while_cancelling_accepted_restores_stock_once()
    {
        // 1. Cancel PENDING order -> no stock change
        $pendingOrder = FnbOrder::create([
            'order_number' => 'ORD-PEND-CANCEL',
            'source' => FnbOrder::SOURCE_CASHIER,
            'location_id' => $this->stationLocation->id,
            'customer_name' => 'Cancel Pending',
            'status' => FnbOrder::STATUS_PENDING,
            'payment_status' => 'unpaid',
            'requires_upfront_payment' => false,
        ]);
        FnbOrderItem::create([
            'fnb_order_id' => $pendingOrder->id,
            'fnb_id' => $this->fnbSnack->id,
            'qty' => 4,
            'unit_price_beli' => 5000,
            'unit_price_jual' => 10000,
            'subtotal' => 40000,
            'payment_status' => 'unpaid',
        ]);

        $this->actingAs($this->fnbStaff)
            ->postJson(route('fnb.queue.cancel', $pendingOrder->id), ['reason' => 'Pelanggan batal'])
            ->assertStatus(200);

        $this->assertSame(FnbOrder::STATUS_CANCELLED, $pendingOrder->fresh()->status);
        $this->assertSame($this->fnbStaff->id, $pendingOrder->fresh()->cancelled_by_user_id);
        $this->assertSame('Pelanggan batal', $pendingOrder->fresh()->cancelled_reason);
        $this->assertSame(10, $this->fnbSnack->fresh()->stok);
        $this->assertSame(0, StockMutation::count());

        // 2. Accept an order then cancel it -> stock restored once, StockMutation 'in' logged, no refund invoice created
        $acceptedOrder = FnbOrder::create([
            'order_number' => 'ORD-ACC-CANCEL',
            'source' => FnbOrder::SOURCE_QR,
            'location_id' => $this->stationLocation->id,
            'customer_name' => 'Cancel Accepted',
            'status' => FnbOrder::STATUS_PENDING,
            'payment_status' => 'paid',
            'requires_upfront_payment' => true,
        ]);
        FnbOrderItem::create([
            'fnb_order_id' => $acceptedOrder->id,
            'fnb_id' => $this->fnbSnack->id,
            'qty' => 3,
            'unit_price_beli' => 5000,
            'unit_price_jual' => 10000,
            'subtotal' => 30000,
            'payment_status' => 'paid',
        ]);

        $stockService = app(FnbStockService::class);
        $stockService->acceptAndDeductStock($acceptedOrder->id, $this->fnbStaff->id);
        $this->assertSame(7, $this->fnbSnack->fresh()->stok);

        $this->actingAs($this->kasir)
            ->postJson(route('fnb.queue.cancel', $acceptedOrder->id), ['reason' => 'Stok dapur habis'])
            ->assertStatus(200);

        $this->assertSame(FnbOrder::STATUS_CANCELLED, $acceptedOrder->fresh()->status);
        $this->assertSame(10, $this->fnbSnack->fresh()->stok);
        $this->assertSame(1, StockMutation::where('fnb_id', $this->fnbSnack->id)->where('type', 'out')->count());
        $this->assertSame(1, StockMutation::where('fnb_id', $this->fnbSnack->id)->where('type', 'in')->where('qty', 3)->count());
        $this->assertSame(0, Invoice::count());

        // Double cancel rejected
        $this->actingAs($this->kasir)
            ->postJson(route('fnb.queue.cancel', $acceptedOrder->id), ['reason' => 'Ulang'])
            ->assertStatus(422);
    }

    /** @test */
    public function open_05_fnb_staff_rbac_and_admin_user_management_toggle()
    {
        // Home redirects F&B staff away from dashboard revenue
        $this->actingAs($this->fnbStaff)
            ->get('/home')
            ->assertRedirect(route('fnb.queue.index'));

        // F&B staff can access queue and sidebar hides non-F&B links
        $queuePage = $this->actingAs($this->fnbStaff)->get(route('fnb.queue.index'));
        $queuePage->assertStatus(200);
        $queuePage->assertSee('Antrean Order F&amp;B', false);
        $queuePage->assertDontSee(route('transaction.index'));
        $queuePage->assertDontSee(route('device.index'));
        $queuePage->assertDontSee(route('report'));

        // F&B staff can create walk-in order and advance status of unpaid Lost Time order
        $lostTimeTx = Transaction::create([
            'nama' => 'Session LT',
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
            'waktu_mulai' => '13:00:00',
            'started_at' => now(),
            'session_token' => Str::random(32),
        ]);

        $orderRes = $this->actingAs($this->fnbStaff)->postJson(route('fnb.orders.store'), [
            'transaction_id' => $lostTimeTx->id_transaksi,
            'customer_name' => 'Tamu FNB Staff',
            'items' => [['fnb_id' => $this->fnbSnack->id, 'qty' => 1]],
        ]);
        $orderRes->assertStatus(201);
        $orderId = $orderRes->json('order.id');

        $this->actingAs($this->fnbStaff)
            ->postJson(route('fnb.queue.status', $orderId), ['status' => FnbOrder::STATUS_ACCEPTED])
            ->assertStatus(200);
        $this->actingAs($this->fnbStaff)
            ->postJson(route('fnb.queue.status', $orderId), ['status' => FnbOrder::STATUS_PREPARING])
            ->assertStatus(200);

        // F&B staff (kasir F&B) CAN process payment and view F&B invoices
        $payRes = $this->actingAs($this->fnbStaff)->post(route('fnb.queue.pay', $orderId), ['payment_method' => 'tunai']);
        $invoiceCreated = Invoice::latest('id')->first();
        $this->assertNotNull($invoiceCreated);
        $payRes->assertRedirect(route('fnb.invoice.show', $invoiceCreated->id));
        $this->actingAs($this->fnbStaff)->get(route('fnb.invoice.show', $invoiceCreated->id))->assertStatus(200);

        // F&B staff gets 403 on billing transaction, reports, revenue charts, and hardware routes
        $this->actingAs($this->fnbStaff)->get('/chart-pie-data')->assertStatus(403);
        $this->actingAs($this->fnbStaff)->get('/chart-area-data')->assertStatus(403);
        $this->actingAs($this->fnbStaff)->get('/hourly-revenue-data')->assertStatus(403);
        $this->actingAs($this->fnbStaff)->get('/transaction/create')->assertStatus(403);
        $this->actingAs($this->fnbStaff)->get('/transaction')->assertStatus(403);
        $this->actingAs($this->fnbStaff)->get('/report')->assertStatus(403);
        $this->actingAs($this->fnbStaff)->get('/fnb/laporan-penjualan')->assertStatus(403);
        $this->actingAs($this->fnbStaff)->get('/admin/hardware/registry')->assertStatus(403);
        $this->actingAs($this->fnbStaff)->get('/expense')->assertStatus(403);

        // Admin creates a kasir with is_fnb_staff = true
        $this->actingAs($this->admin)->post(route('users.store'), [
            'name' => 'Staf F&B Baru',
            'email' => 'newfnb@test.local',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'role' => 'kasir',
            'shift' => 'Siang',
            'work_shift_id' => $this->workShift->id,
            'is_fnb_staff' => '1',
        ])->assertRedirect(route('users.index'));

        $createdUser = User::where('email', 'newfnb@test.local')->firstOrFail();
        $this->assertTrue($createdUser->isFnbStaff());

        // Admin updates role to owner -> is_fnb_staff reset to false
        $this->actingAs($this->admin)->put(route('users.update', $createdUser->id), [
            'name' => 'Owner Baru',
            'email' => 'newfnb@test.local',
            'role' => 'owner',
            'is_fnb_staff' => '1',
        ])->assertRedirect(route('users.index'));

        $this->assertFalse((bool) $createdUser->fresh()->is_fnb_staff);
        $this->assertFalse($createdUser->fresh()->isFnbStaff());
    }

    /** @test */
    public function ac_10_and_ac_11_unified_queue_renders_qr_cashier_and_fnb_staff_orders()
    {
        $qrOrder = FnbOrder::create([
            'order_number' => 'ORD-SRC-QR',
            'source' => FnbOrder::SOURCE_QR,
            'location_id' => $this->stationLocation->id,
            'customer_name' => 'Rendi QR',
            'billing_name' => 'Budi Utama',
            'status' => FnbOrder::STATUS_PENDING,
            'payment_status' => 'unpaid',
            'requires_upfront_payment' => false,
        ]);

        $cashierOrder = FnbOrder::create([
            'order_number' => 'ORD-SRC-CSH',
            'source' => FnbOrder::SOURCE_CASHIER,
            'location_id' => $this->stationLocation->id,
            'customer_name' => 'Siti Kasir',
            'billing_name' => 'Siti Kasir',
            'status' => FnbOrder::STATUS_ACCEPTED,
            'accepted_at' => now(),
            'payment_status' => 'paid',
            'requires_upfront_payment' => true,
        ]);

        $staffRes = $this->actingAs($this->fnbStaff)->postJson(route('fnb.orders.store'), [
            'customer_name' => 'Walkin Non-Bermain',
            'items' => [['fnb_id' => $this->fnbSnack->id, 'qty' => 1]],
        ]);
        $staffRes->assertStatus(201);
        $staffOrderNumber = $staffRes->json('order.order_number');

        $queueRes = $this->actingAs($this->kasir)->get(route('fnb.queue.index'));
        $queueRes->assertStatus(200);
        $queueRes->assertSee('ORD-SRC-QR');
        $queueRes->assertSee('Rendi QR');
        $queueRes->assertSee('Budi Utama');
        $queueRes->assertSee('ORD-SRC-CSH');
        $queueRes->assertSee('Siti Kasir');
        $queueRes->assertSee($staffOrderNumber);
        $queueRes->assertSee('Walkin Non-Bermain');
        $queueRes->assertSee('Non-Bermain / Takeaway');
    }
}
