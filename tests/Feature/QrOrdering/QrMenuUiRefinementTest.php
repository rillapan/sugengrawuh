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

class QrMenuUiRefinementTest extends TestCase
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
            'nama' => 'Kentang Crispy',
            'harga_beli' => 6000,
            'harga_jual' => 12000,
            'stok' => 25,
            'image' => 'fnb/kentang.jpg',
        ]);

        $this->fnbItem2 = Fnb::create([
            'nama' => 'Es Teh Manis',
            'harga_beli' => 2000,
            'harga_jual' => 5000,
            'stok' => 40,
            'image' => null,
        ]);
    }

    /** @test */
    public function test_station_menu_renders_header_with_active_orders_button_and_badge()
    {
        $token = Str::random(32);

        $session = Transaction::create([
            'nama' => 'Randi Player',
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
            'order_number' => 'ORD-UI-001',
            'session_token' => $token,
            'source' => FnbOrder::SOURCE_QR,
            'location_id' => $this->location->id,
            'transaction_id' => $session->id_transaksi,
            'customer_name' => 'Randi',
            'status' => FnbOrder::STATUS_PREPARING,
            'payment_status' => FnbOrder::PAYMENT_UNPAID,
            'total_amount' => 12000,
        ]);

        FnbOrderItem::create([
            'fnb_order_id' => $order->id,
            'fnb_id' => $this->fnbItem1->id,
            'qty' => 1,
            'unit_price_jual' => 12000,
            'subtotal' => 12000,
        ]);

        $response = $this->get(route('public.qr.station', [
            'code' => $this->location->code,
            'token' => $token,
        ]));

        $response->assertStatus(200);

        // Header station and player
        $response->assertSee('📍 Bilik 1 PS5 (STATION-01)');
        $response->assertSee('Nama Pemain:');
        $response->assertSee('Randi Player');

        // Header "Pesanan Saya" button and badge
        $response->assertSee('openActiveOrdersModal');
        $response->assertSee('active-orders-count-badge');
        $response->assertSee('Pesanan Saya');
    }

    /** @test */
    public function test_station_menu_renders_active_orders_modal_and_preserves_order_details()
    {
        $token = Str::random(32);

        $session = Transaction::create([
            'nama' => 'Randi Player',
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
            'order_number' => 'ORD-UI-002',
            'session_token' => $token,
            'source' => FnbOrder::SOURCE_QR,
            'location_id' => $this->location->id,
            'transaction_id' => $session->id_transaksi,
            'customer_name' => 'Doni',
            'status' => FnbOrder::STATUS_READY,
            'payment_status' => FnbOrder::PAYMENT_PAID,
            'total_amount' => 17000,
            'notes' => 'Porsi banyak',
        ]);

        FnbOrderItem::create([
            'fnb_order_id' => $order->id,
            'fnb_id' => $this->fnbItem1->id,
            'qty' => 1,
            'unit_price_jual' => 12000,
            'subtotal' => 12000,
        ]);

        FnbOrderItem::create([
            'fnb_order_id' => $order->id,
            'fnb_id' => $this->fnbItem2->id,
            'qty' => 1,
            'unit_price_jual' => 5000,
            'subtotal' => 5000,
        ]);

        $response = $this->get(route('public.qr.station', [
            'code' => $this->location->code,
            'token' => $token,
        ]));

        $response->assertStatus(200);

        // Modal container exists
        $response->assertSee('activeOrdersModal');
        $response->assertSee('closeActiveOrdersModal');

        // Order details present inside modal
        $response->assertSee('ORD-UI-002');
        $response->assertSee('Doni');
        $response->assertSee('Kentang Crispy');
        $response->assertSee('Es Teh Manis');
        $response->assertSee('Siap Diantar');
        $response->assertSee('Lunas');
        $response->assertSee('Porsi banyak');
        $response->assertSee('Rp 17.000');
    }

    /** @test */
    public function test_station_menu_renders_image_lightbox_elements_and_clickable_thumbnails()
    {
        $token = Str::random(32);

        Transaction::create([
            'nama' => 'Randi Player',
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

        // Lightbox modal container and handlers
        $response->assertSee('imageLightboxModal');
        $response->assertSee('openImageLightbox');
        $response->assertSee('closeImageLightbox');
        $response->assertSee('lightbox-img');
    }

    /** @test */
    public function test_station_menu_layout_order_customer_form_before_catalog_and_floating_footer()
    {
        $token = Str::random(32);

        Transaction::create([
            'nama' => 'Randi Player',
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

        $content = $response->getContent();

        $customerNamePos = strpos($content, 'Nama Pemesan');
        $catalogPos = strpos($content, 'Daftar Pilihan Menu');
        $footerPos = strpos($content, 'class="floating-footer"');

        $this->assertNotFalse($customerNamePos);
        $this->assertNotFalse($catalogPos);
        $this->assertNotFalse($footerPos);

        // Customer form must come before catalog
        $this->assertTrue($customerNamePos < $catalogPos, 'Form Pemesan should come before Katalog Menu');
        // Catalog must come before footer
        $this->assertTrue($catalogPos < $footerPos, 'Katalog Menu should come before Floating Footer');
    }
}
