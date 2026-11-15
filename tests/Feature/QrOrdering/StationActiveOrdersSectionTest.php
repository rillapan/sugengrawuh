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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StationActiveOrdersSectionTest extends TestCase
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
            'nama' => 'Indomie Goreng',
            'harga_beli' => 4000,
            'harga_jual' => 10000,
            'stok' => 30,
        ]);

        $this->fnbItem2 = Fnb::create([
            'nama' => 'Es Teh Manis',
            'harga_beli' => 2000,
            'harga_jual' => 5000,
            'stok' => 50,
        ]);
    }

    /** @test */
    public function test_station_menu_renders_pesanan_saya_section_with_active_orders_details()
    {
        $token = Str::random(32);

        $session = Transaction::create([
            'nama' => 'Andi Player',
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

        $order1 = FnbOrder::create([
            'order_number' => 'ORD-SEC-001',
            'session_token' => $token,
            'source' => FnbOrder::SOURCE_QR,
            'location_id' => $this->location->id,
            'transaction_id' => $session->id_transaksi,
            'customer_name' => 'Budi Santoso',
            'status' => FnbOrder::STATUS_PENDING,
            'payment_status' => FnbOrder::PAYMENT_UNPAID,
            'total_amount' => 25000,
            'notes' => 'Jangan pedas',
        ]);

        FnbOrderItem::create([
            'fnb_order_id' => $order1->id,
            'fnb_id' => $this->fnbItem1->id,
            'qty' => 2,
            'unit_price_jual' => 10000,
            'subtotal' => 20000,
        ]);

        FnbOrderItem::create([
            'fnb_order_id' => $order1->id,
            'fnb_id' => $this->fnbItem2->id,
            'qty' => 1,
            'unit_price_jual' => 5000,
            'subtotal' => 5000,
        ]);

        $order2 = FnbOrder::create([
            'order_number' => 'ORD-SEC-002',
            'session_token' => $token,
            'source' => FnbOrder::SOURCE_QR,
            'location_id' => $this->location->id,
            'transaction_id' => $session->id_transaksi,
            'customer_name' => 'Siti Aminah',
            'status' => FnbOrder::STATUS_PREPARING,
            'payment_status' => FnbOrder::PAYMENT_PAID,
            'total_amount' => 10000,
        ]);

        FnbOrderItem::create([
            'fnb_order_id' => $order2->id,
            'fnb_id' => $this->fnbItem1->id,
            'qty' => 1,
            'unit_price_jual' => 10000,
            'subtotal' => 10000,
        ]);

        $response = $this->get(route('public.qr.station', [
            'code' => $this->location->code,
            'token' => $token,
        ]));

        $response->assertStatus(200);
        $response->assertSee('Pesanan Saya');
        $response->assertSee('ORD-SEC-001');
        $response->assertSee('Budi Santoso');
        $response->assertSee('Indomie Goreng');
        $response->assertSee('Es Teh Manis');
        $response->assertSee('Menunggu Dapur');
        $response->assertSee('Belum Bayar');
        $response->assertSee('Rp 25.000');

        $response->assertSee('ORD-SEC-002');
        $response->assertSee('Siti Aminah');
        $response->assertSee('Sedang Disiapkan');
        $response->assertSee('Lunas');
        $response->assertSee('Rp 10.000');
    }

    /** @test */
    public function test_station_menu_renders_delivered_orders_but_excludes_cancelled_and_expired()
    {
        $token = Str::random(32);

        $session = Transaction::create([
            'nama' => 'Andi Player',
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

        $deliveredOrder = FnbOrder::create([
            'order_number' => 'ORD-DELIVERED-01',
            'session_token' => $token,
            'source' => FnbOrder::SOURCE_QR,
            'location_id' => $this->location->id,
            'transaction_id' => $session->id_transaksi,
            'customer_name' => 'Delivered Customer',
            'status' => FnbOrder::STATUS_DELIVERED,
            'payment_status' => FnbOrder::PAYMENT_PAID,
            'total_amount' => 10000,
        ]);

        FnbOrderItem::create([
            'fnb_order_id' => $deliveredOrder->id,
            'fnb_id' => $this->fnbItem1->id,
            'qty' => 1,
            'unit_price_jual' => 10000,
            'subtotal' => 10000,
        ]);

        $cancelledOrder = FnbOrder::create([
            'order_number' => 'ORD-CANCELLED-01',
            'session_token' => $token,
            'source' => FnbOrder::SOURCE_QR,
            'location_id' => $this->location->id,
            'transaction_id' => $session->id_transaksi,
            'customer_name' => 'Cancelled Customer',
            'status' => FnbOrder::STATUS_CANCELLED,
            'payment_status' => FnbOrder::PAYMENT_UNPAID,
            'total_amount' => 10000,
        ]);

        $expiredOrder = FnbOrder::create([
            'order_number' => 'ORD-EXPIRED-01',
            'session_token' => $token,
            'source' => FnbOrder::SOURCE_QR,
            'location_id' => $this->location->id,
            'transaction_id' => $session->id_transaksi,
            'customer_name' => 'Expired Customer',
            'status' => FnbOrder::STATUS_EXPIRED,
            'payment_status' => FnbOrder::PAYMENT_UNPAID,
            'total_amount' => 10000,
        ]);

        $response = $this->get(route('public.qr.station', [
            'code' => $this->location->code,
            'token' => $token,
        ]));

        $response->assertStatus(200);
        $response->assertSee('Pesanan Saya');
        $response->assertSee('ORD-DELIVERED-01');
        $response->assertSee('Delivered Customer');
        $response->assertSee('Selesai Diantar');

        $response->assertDontSee('ORD-CANCELLED-01');
        $response->assertDontSee('Cancelled Customer');
        $response->assertDontSee('ORD-EXPIRED-01');
        $response->assertDontSee('Expired Customer');
    }

    /** @test */
    public function test_station_menu_renders_empty_state_when_no_active_orders_exist()
    {
        $token = Str::random(32);

        Transaction::create([
            'nama' => 'Andi Player',
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

        $response = $this->get(route('public.qr.station', [
            'code' => $this->location->code,
            'token' => $token,
        ]));

        $response->assertStatus(200);
        $response->assertSee('Pesanan Saya');
        $response->assertSee('Belum ada pesanan aktif untuk sesi ini.');
    }

    /** @test */
    public function test_station_menu_renders_polling_script_and_endpoint()
    {
        $token = Str::random(32);

        Transaction::create([
            'nama' => 'Andi Player',
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

        $response = $this->get(route('public.qr.station', [
            'code' => $this->location->code,
            'token' => $token,
        ]));

        $response->assertStatus(200);
        $response->assertSee('loadActiveOrders');
        $response->assertSee('/station/' . $this->location->code . '/orders');
        $response->assertSee('8000');
    }
}
