<?php

namespace Tests\Feature\QrOrdering;

use App\Models\Device;
use App\Models\Fnb;
use App\Models\FnbOrder;
use App\Models\FnbOrderItem;
use App\Models\Location;
use App\Models\Playstation;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Fnb\QrOrderService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ActiveStationOrdersTest extends TestCase
{
    use RefreshDatabase;

    protected $kasir;
    protected $playstation;
    protected $device;
    protected $location;
    protected $fnbItem1;
    protected $fnbItem2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kasir = User::factory()->create([
            'role' => 'kasir',
            'email' => 'kasir@sugengrawuh.test',
        ]);

        $this->playstation = Playstation::create([
            'nama' => 'PS5 Station',
            'harga' => 15000,
        ]);

        $this->device = Device::create([
            'nama' => 'PS-01',
            'playstation_id' => $this->playstation->id,
            'status' => 'Tersedia',
        ]);
        $this->device->playstations()->attach($this->playstation->id);

        $this->location = Location::create([
            'name' => 'Bilik 1 PS5',
            'code' => 'STATION-01',
            'type' => 'station',
            'device_id' => $this->device->id,
            'is_active' => true,
        ]);

        $this->fnbItem1 = Fnb::create([
            'nama' => 'Es Teh Manis',
            'harga_beli' => 2000,
            'harga_jual' => 5000,
            'stok' => 50,
        ]);

        $this->fnbItem2 = Fnb::create([
            'nama' => 'Indomie Goreng',
            'harga_beli' => 4000,
            'harga_jual' => 10000,
            'stok' => 30,
        ]);
    }

    /** @test */
    public function test_active_session_returns_active_orders_with_items_and_player_name()
    {
        $token = Str::random(32);

        $session = Transaction::create([
            'nama' => 'Budi Gamer',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'postpaid',
            'billing_status' => Transaction::BILLING_RUNNING,
            'status' => 'Berjalan',
            'harga' => 15000,
            'session_token' => $token,
            'waktu_mulai' => now(),
            'started_at' => now(),
            'total' => 0,
        ]);

        $order = FnbOrder::create([
            'order_number' => 'ORD-TEST-001',
            'session_token' => $token,
            'source' => FnbOrder::SOURCE_QR,
            'location_id' => $this->location->id,
            'transaction_id' => $session->id_transaksi,
            'customer_name' => 'Budi',
            'billing_name' => $session->nama,
            'status' => FnbOrder::STATUS_PREPARING,
            'payment_status' => 'unpaid',
            'requires_upfront_payment' => false,
            'notes' => 'Gula sedikit',
        ]);

        FnbOrderItem::create([
            'fnb_order_id' => $order->id,
            'fnb_id' => $this->fnbItem1->id,
            'qty' => 2,
            'unit_price_beli' => 2000,
            'unit_price_jual' => 5000,
            'subtotal' => 10000,
            'payment_status' => 'unpaid',
            'notes' => 'Dingin banget',
        ]);

        $response = $this->getJson(route('public.qr.station.orders', [
            'code' => $this->location->code,
            'token' => $token,
        ]));

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'playerName' => 'Budi Gamer',
        ]);

        $orders = $response->json('orders');
        $this->assertCount(1, $orders);
        $this->assertEquals('ORD-TEST-001', $orders[0]['order_number']);
        $this->assertEquals(FnbOrder::STATUS_PREPARING, $orders[0]['status']);
        $this->assertEquals(10000, $orders[0]['total_amount']);
        $this->assertCount(1, $orders[0]['items']);
        $this->assertEquals('Es Teh Manis', $orders[0]['items'][0]['name']);
        $this->assertEquals(2, $orders[0]['items'][0]['qty']);
        $this->assertEquals(10000, $orders[0]['items'][0]['subtotal']);
    }

    /** @test */
    public function test_multiple_customers_in_same_session_see_all_orders_for_station()
    {
        $token = Str::random(32);

        $session = Transaction::create([
            'nama' => 'Grup Mabar',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'postpaid',
            'billing_status' => Transaction::BILLING_RUNNING,
            'status' => 'Berjalan',
            'harga' => 15000,
            'session_token' => $token,
            'waktu_mulai' => now(),
            'started_at' => now(),
            'total' => 0,
        ]);

        // Order 1 from Phone A (Customer "Andi")
        $order1 = FnbOrder::create([
            'order_number' => 'ORD-TEST-ANDI',
            'session_token' => $token,
            'source' => FnbOrder::SOURCE_QR,
            'location_id' => $this->location->id,
            'transaction_id' => $session->id_transaksi,
            'customer_name' => 'Andi',
            'billing_name' => $session->nama,
            'status' => FnbOrder::STATUS_PENDING,
            'payment_status' => 'unpaid',
            'requires_upfront_payment' => false,
        ]);
        FnbOrderItem::create([
            'fnb_order_id' => $order1->id,
            'fnb_id' => $this->fnbItem1->id,
            'qty' => 1,
            'unit_price_beli' => 2000,
            'unit_price_jual' => 5000,
            'subtotal' => 5000,
        ]);

        // Order 2 from Phone B (Customer "Rudi")
        $order2 = FnbOrder::create([
            'order_number' => 'ORD-TEST-RUDI',
            'session_token' => $token,
            'source' => FnbOrder::SOURCE_QR,
            'location_id' => $this->location->id,
            'transaction_id' => $session->id_transaksi,
            'customer_name' => 'Rudi',
            'billing_name' => $session->nama,
            'status' => FnbOrder::STATUS_ACCEPTED,
            'payment_status' => 'unpaid',
            'requires_upfront_payment' => false,
        ]);
        FnbOrderItem::create([
            'fnb_order_id' => $order2->id,
            'fnb_id' => $this->fnbItem2->id,
            'qty' => 2,
            'unit_price_beli' => 4000,
            'unit_price_jual' => 10000,
            'subtotal' => 20000,
        ]);

        // Both phone queries with the valid session token see both orders
        $response = $this->getJson(route('public.qr.station.orders', [
            'code' => $this->location->code,
            'token' => $token,
        ]));

        $response->assertStatus(200);
        $orders = $response->json('orders');
        $this->assertCount(2, $orders);

        $orderNumbers = collect($orders)->pluck('order_number')->all();
        $this->assertContains('ORD-TEST-ANDI', $orderNumbers);
        $this->assertContains('ORD-TEST-RUDI', $orderNumbers);
    }

    /** @test */
    public function test_cancelled_and_expired_orders_are_excluded()
    {
        $token = Str::random(32);

        $session = Transaction::create([
            'nama' => 'Player Filter',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'postpaid',
            'billing_status' => Transaction::BILLING_RUNNING,
            'status' => 'Berjalan',
            'harga' => 15000,
            'session_token' => $token,
            'waktu_mulai' => now(),
            'started_at' => now(),
            'total' => 0,
        ]);

        // Active: READY
        $activeOrder = FnbOrder::create([
            'order_number' => 'ORD-ACTIVE',
            'session_token' => $token,
            'source' => FnbOrder::SOURCE_QR,
            'location_id' => $this->location->id,
            'transaction_id' => $session->id_transaksi,
            'customer_name' => 'Active',
            'billing_name' => $session->nama,
            'status' => FnbOrder::STATUS_READY,
            'payment_status' => 'unpaid',
            'requires_upfront_payment' => false,
        ]);

        // Inactive: CANCELLED
        FnbOrder::create([
            'order_number' => 'ORD-CANCELLED',
            'session_token' => $token,
            'source' => FnbOrder::SOURCE_QR,
            'location_id' => $this->location->id,
            'transaction_id' => $session->id_transaksi,
            'customer_name' => 'Cancelled',
            'billing_name' => $session->nama,
            'status' => FnbOrder::STATUS_CANCELLED,
            'payment_status' => 'unpaid',
            'requires_upfront_payment' => false,
        ]);

        // Inactive: EXPIRED
        FnbOrder::create([
            'order_number' => 'ORD-EXPIRED',
            'session_token' => $token,
            'source' => FnbOrder::SOURCE_QR,
            'location_id' => $this->location->id,
            'transaction_id' => $session->id_transaksi,
            'customer_name' => 'Expired',
            'billing_name' => $session->nama,
            'status' => FnbOrder::STATUS_EXPIRED,
            'payment_status' => 'unpaid',
            'requires_upfront_payment' => false,
        ]);

        $response = $this->getJson(route('public.qr.station.orders', [
            'code' => $this->location->code,
            'token' => $token,
        ]));

        $response->assertStatus(200);
        $orders = $response->json('orders');
        $this->assertCount(1, $orders);
        $this->assertEquals('ORD-ACTIVE', $orders[0]['order_number']);
    }

    /** @test */
    public function test_session_rollover_prevents_leaking_previous_customer_orders()
    {
        $tokenA = Str::random(32);
        $sessionA = Transaction::create([
            'nama' => 'Customer A',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'postpaid',
            'billing_status' => Transaction::BILLING_COMPLETED,
            'status' => 'Selesai',
            'harga' => 15000,
            'session_token' => $tokenA,
            'waktu_mulai' => now()->subHours(2),
            'waktu_selesai' => now()->subHour(),
            'total' => 15000,
        ]);

        FnbOrder::create([
            'order_number' => 'ORD-CUSTOMER-A',
            'session_token' => $tokenA,
            'source' => FnbOrder::SOURCE_QR,
            'location_id' => $this->location->id,
            'transaction_id' => $sessionA->id_transaksi,
            'customer_name' => 'Customer A',
            'billing_name' => $sessionA->nama,
            'status' => FnbOrder::STATUS_DELIVERED,
            'payment_status' => 'paid',
            'requires_upfront_payment' => false,
        ]);

        // Customer B starts a new session on the same device
        $tokenB = Str::random(32);
        $sessionB = Transaction::create([
            'nama' => 'Customer B',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'postpaid',
            'billing_status' => Transaction::BILLING_RUNNING,
            'status' => 'Berjalan',
            'harga' => 15000,
            'session_token' => $tokenB,
            'waktu_mulai' => now(),
            'started_at' => now(),
            'total' => 0,
        ]);

        FnbOrder::create([
            'order_number' => 'ORD-CUSTOMER-B',
            'session_token' => $tokenB,
            'source' => FnbOrder::SOURCE_QR,
            'location_id' => $this->location->id,
            'transaction_id' => $sessionB->id_transaksi,
            'customer_name' => 'Customer B',
            'billing_name' => $sessionB->nama,
            'status' => FnbOrder::STATUS_PENDING,
            'payment_status' => 'unpaid',
            'requires_upfront_payment' => false,
        ]);

        // Customer B queries active orders
        $response = $this->getJson(route('public.qr.station.orders', [
            'code' => $this->location->code,
            'token' => $tokenB,
        ]));

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'playerName' => 'Customer B',
        ]);
        $orders = $response->json('orders');
        $this->assertCount(1, $orders);
        $this->assertEquals('ORD-CUSTOMER-B', $orders[0]['order_number']);
        $this->assertNotEquals('ORD-CUSTOMER-A', $orders[0]['order_number']);

        // Querying with previous tokenA returns 403
        $responseOld = $this->getJson(route('public.qr.station.orders', [
            'code' => $this->location->code,
            'token' => $tokenA,
        ]));
        $responseOld->assertStatus(403);
    }

    /** @test */
    public function test_invalid_or_expired_token_returns_403()
    {
        $token = Str::random(32);

        Transaction::create([
            'nama' => 'Budi Gamer',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'postpaid',
            'billing_status' => Transaction::BILLING_RUNNING,
            'status' => 'Berjalan',
            'harga' => 15000,
            'session_token' => $token,
            'waktu_mulai' => now(),
            'started_at' => now(),
            'total' => 0,
        ]);

        // Wrong token
        $response = $this->getJson(route('public.qr.station.orders', [
            'code' => $this->location->code,
            'token' => 'invalid-token-12345',
        ]));
        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
        ]);

        // Missing token
        $responseMissing = $this->getJson(route('public.qr.station.orders', [
            'code' => $this->location->code,
        ]));
        $responseMissing->assertStatus(422);
    }

    /** @test */
    public function test_service_get_station_menu_data_includes_active_orders_and_session_type()
    {
        $token = Str::random(32);

        $session = Transaction::create([
            'nama' => 'Paket Player',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'prepaid',
            'billing_status' => Transaction::BILLING_RUNNING,
            'status' => 'Berjalan',
            'harga' => 25000,
            'session_token' => $token,
            'waktu_mulai' => now(),
            'started_at' => now(),
            'total' => 25000,
        ]);

        $order = FnbOrder::create([
            'order_number' => 'ORD-PAKET-01',
            'session_token' => $token,
            'source' => FnbOrder::SOURCE_QR,
            'location_id' => $this->location->id,
            'transaction_id' => $session->id_transaksi,
            'customer_name' => 'Paket User',
            'billing_name' => $session->nama,
            'status' => FnbOrder::STATUS_ACCEPTED,
            'payment_status' => 'paid',
            'requires_upfront_payment' => true,
        ]);

        /** @var QrOrderService $service */
        $service = app(QrOrderService::class);
        $data = $service->getStationMenuData($this->location->code);

        $this->assertTrue($data['hasActiveSession']);
        $this->assertEquals('prepaid', $data['sessionType']);
        $this->assertCount(1, $data['activeOrders']);
        $this->assertEquals('ORD-PAKET-01', $data['activeOrders']->first()->order_number);
    }

    /** @test */
    public function test_idle_station_menu_data_returns_empty_active_orders_and_null_session_type()
    {
        /** @var QrOrderService $service */
        $service = app(QrOrderService::class);
        $data = $service->getStationMenuData($this->location->code);

        $this->assertFalse($data['hasActiveSession']);
        $this->assertNull($data['sessionType']);
        $this->assertCount(0, $data['activeOrders']);
    }

    /** @test */
    public function test_orders_are_sorted_by_priority_ready_preparing_accepted_pending_delivered()
    {
        $token = Str::random(32);

        $session = Transaction::create([
            'nama' => 'Priority Player',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'postpaid',
            'billing_status' => Transaction::BILLING_RUNNING,
            'status' => 'Berjalan',
            'harga' => 15000,
            'session_token' => $token,
            'waktu_mulai' => now(),
            'started_at' => now(),
            'total' => 0,
        ]);

        // Create orders in mixed order
        foreach ([
            FnbOrder::STATUS_DELIVERED => 'ORD-DELIVERED',
            FnbOrder::STATUS_PENDING => 'ORD-PENDING',
            FnbOrder::STATUS_READY => 'ORD-READY',
            FnbOrder::STATUS_ACCEPTED => 'ORD-ACCEPTED',
            FnbOrder::STATUS_PREPARING => 'ORD-PREPARING',
        ] as $status => $orderNumber) {
            FnbOrder::create([
                'order_number' => $orderNumber,
                'session_token' => $token,
                'source' => FnbOrder::SOURCE_QR,
                'location_id' => $this->location->id,
                'transaction_id' => $session->id_transaksi,
                'customer_name' => 'Priority User',
                'billing_name' => $session->nama,
                'status' => $status,
                'payment_status' => 'unpaid',
                'requires_upfront_payment' => false,
            ]);
        }

        $response = $this->getJson(route('public.qr.station.orders', [
            'code' => $this->location->code,
            'token' => $token,
        ]));

        $response->assertStatus(200);
        $orders = $response->json('orders');
        $this->assertCount(5, $orders);

        $statuses = collect($orders)->pluck('status')->all();
        $this->assertEquals([
            FnbOrder::STATUS_READY,
            FnbOrder::STATUS_PREPARING,
            FnbOrder::STATUS_ACCEPTED,
            FnbOrder::STATUS_PENDING,
            FnbOrder::STATUS_DELIVERED,
        ], $statuses);
    }
}
