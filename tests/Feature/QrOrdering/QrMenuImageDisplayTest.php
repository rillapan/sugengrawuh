<?php

namespace Tests\Feature\QrOrdering;

use App\Models\Device;
use App\Models\Fnb;
use App\Models\FnbOrder;
use App\Models\Location;
use App\Models\Playstation;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class QrMenuImageDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected $kasir;
    protected $playstation;
    protected $device;
    protected $location;
    protected $transaction;
    protected $sessionToken;

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

        $this->sessionToken = Str::random(32);

        $this->transaction = Transaction::create([
            'nama' => 'Pemain Stasiun 1',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'postpaid',
            'billing_status' => Transaction::BILLING_RUNNING,
            'status' => 'Berjalan',
            'harga' => 15000,
            'session_token' => $this->sessionToken,
            'waktu_mulai' => now(),
            'started_at' => now(),
            'total' => 0,
        ]);
    }

    /** @test */
    public function qr_menu_displays_product_uploaded_image_when_present()
    {
        Storage::fake('public');
        Storage::disk('public')->put('fnb/snack.jpg', 'fake-image-content');

        $fnb = Fnb::create([
            'nama' => 'Kentang Goreng Krispi',
            'harga_beli' => 8000,
            'harga_jual' => 15000,
            'stok' => 20,
            'image' => 'fnb/snack.jpg',
        ]);

        $response = $this->get(route('public.qr.station', $this->location->code));

        $response->assertStatus(200);
        $response->assertViewIs('qr_order.menu');
        $response->assertSee('storage/fnb/snack.jpg');
        $response->assertSee('fnb-img-wrapper');
    }

    /** @test */
    public function qr_menu_displays_placeholder_when_product_has_no_image()
    {
        $fnb = Fnb::create([
            'nama' => 'Teh Hangat Manis',
            'harga_beli' => 2000,
            'harga_jual' => 5000,
            'stok' => 50,
            'image' => null,
        ]);

        $response = $this->get(route('public.qr.station', $this->location->code));

        $response->assertStatus(200);
        $response->assertViewIs('qr_order.menu');
        $response->assertSee('img/fnb-placeholder.svg');
    }

    /** @test */
    public function order_status_view_displays_product_thumbnail()
    {
        Storage::fake('public');
        Storage::disk('public')->put('fnb/snack.jpg', 'fake-image-content');

        $fnbWithImg = Fnb::create([
            'nama' => 'Kentang Goreng Krispi',
            'harga_beli' => 8000,
            'harga_jual' => 15000,
            'stok' => 20,
            'image' => 'fnb/snack.jpg',
        ]);

        $fnbWithoutImg = Fnb::create([
            'nama' => 'Teh Hangat Manis',
            'harga_beli' => 2000,
            'harga_jual' => 5000,
            'stok' => 50,
            'image' => null,
        ]);

        $payload = [
            'session_token' => $this->sessionToken,
            'customer_name' => 'Budi Santoso',
            'items' => [
                ['fnb_id' => $fnbWithImg->id, 'qty' => 1],
                ['fnb_id' => $fnbWithoutImg->id, 'qty' => 2],
            ],
        ];

        $submitResp = $this->postJson(route('public.qr.station.submit', $this->location->code), $payload);
        $submitResp->assertStatus(201);
        $orderNumber = $submitResp->json('order.order_number');

        $statusResp = $this->get(route('public.qr.status', $orderNumber));
        $statusResp->assertStatus(200);
        $statusResp->assertViewIs('qr_order.status');
        $statusResp->assertSee('storage/fnb/snack.jpg');
        $statusResp->assertSee('img/fnb-placeholder.svg');
    }

    /** @test */
    public function fnb_kitchen_queue_displays_product_thumbnail()
    {
        Storage::fake('public');
        Storage::disk('public')->put('fnb/cola.jpg', 'fake-image-content');

        $fnb = Fnb::create([
            'nama' => 'Es Cola Dingin',
            'harga_beli' => 4000,
            'harga_jual' => 8000,
            'stok' => 10,
            'image' => 'fnb/cola.jpg',
        ]);

        $payload = [
            'session_token' => $this->sessionToken,
            'customer_name' => 'Doni Cola',
            'items' => [
                ['fnb_id' => $fnb->id, 'qty' => 1],
            ],
        ];

        $submitResp = $this->postJson(route('public.qr.station.submit', $this->location->code), $payload);
        $submitResp->assertStatus(201);

        $queueResp = $this->actingAs($this->kasir)->get(route('fnb.queue.index'));
        $queueResp->assertStatus(200);
        $queueResp->assertViewIs('fnb.orders_queue');
        $queueResp->assertSee('storage/fnb/cola.jpg');
    }
}
