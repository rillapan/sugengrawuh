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

class QrOrderConfirmationAndIdempotencyTest extends TestCase
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
    public function test_json_submit_with_idempotency_key_returns_201_with_order_json()
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

        $idempotencyKey = 'idem_' . now()->timestamp . '_' . Str::random(8);

        $payload = [
            'session_token' => $token,
            'customer_name' => 'Rendi',
            'notes' => 'Tanpa es batu',
            'idempotency_key' => $idempotencyKey,
            'items' => [
                [
                    'fnb_id' => $this->fnbItem1->id,
                    'qty' => 2,
                    'expected_price' => 5000,
                ],
                [
                    'fnb_id' => $this->fnbItem2->id,
                    'qty' => 1,
                    'expected_price' => 10000,
                ],
            ],
        ];

        $response = $this->post(
            route('public.qr.station.submit', $this->location->code),
            $payload,
            [
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ]
        );

        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
        ]);
        $response->assertJsonStructure([
            'success',
            'order' => [
                'id',
                'order_number',
                'customer_name',
                'status',
                'payment_status',
                'requires_upfront_payment',
            ],
            'redirect',
        ]);

        $orderData = $response->json('order');
        $this->assertEquals('Rendi', $orderData['customer_name']);

        $this->assertDatabaseHas('fnb_orders', [
            'order_number' => $orderData['order_number'],
            'customer_name' => 'Rendi',
            'transaction_id' => $session->id_transaksi,
            'notes' => 'Tanpa es batu',
        ]);
    }

    /** @test */
    public function test_duplicate_submit_with_same_idempotency_key_returns_existing_order_without_duplicate_record()
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

        $idempotencyKey = 'idem_test_duplicate_12345';

        $payload = [
            'session_token' => $token,
            'customer_name' => 'Doni Sub-user',
            'notes' => 'Porsi banyak',
            'idempotency_key' => $idempotencyKey,
            'items' => [
                [
                    'fnb_id' => $this->fnbItem1->id,
                    'qty' => 1,
                    'expected_price' => 5000,
                ],
            ],
        ];

        // First submit
        $response1 = $this->post(
            route('public.qr.station.submit', $this->location->code),
            $payload,
            [
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ]
        );
        $response1->assertStatus(201);
        $orderNumber1 = $response1->json('order.order_number');
        $this->assertNotEmpty($orderNumber1);

        // Second submit with same idempotency key
        $response2 = $this->post(
            route('public.qr.station.submit', $this->location->code),
            $payload,
            [
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ]
        );
        $response2->assertStatus(201);
        $orderNumber2 = $response2->json('order.order_number');

        $this->assertEquals($orderNumber1, $orderNumber2);

        // Verify only 1 record created in database
        $this->assertEquals(1, FnbOrder::where('transaction_id', $session->id_transaksi)->count());
    }

    /** @test */
    public function test_order_submission_with_invalid_token_returns_403()
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

        $payload = [
            'session_token' => 'invalid-token-here',
            'customer_name' => 'Rendi',
            'items' => [
                [
                    'fnb_id' => $this->fnbItem1->id,
                    'qty' => 1,
                ],
            ],
        ];

        $response = $this->post(
            route('public.qr.station.submit', $this->location->code),
            $payload,
            [
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ]
        );

        $response->assertStatus(403);
    }

    /** @test */
    public function test_order_submission_with_missing_customer_name_returns_422()
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

        $payload = [
            'session_token' => $token,
            'customer_name' => '', // Missing customer name
            'items' => [
                [
                    'fnb_id' => $this->fnbItem1->id,
                    'qty' => 1,
                ],
            ],
        ];

        $response = $this->post(
            route('public.qr.station.submit', $this->location->code),
            $payload,
            [
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ]
        );

        $response->assertStatus(422);
    }

    /** @test */
    public function test_order_submission_with_empty_items_returns_422()
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

        $payload = [
            'session_token' => $token,
            'customer_name' => 'Rendi',
            'items' => [],
        ];

        $response = $this->post(
            route('public.qr.station.submit', $this->location->code),
            $payload,
            [
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ]
        );

        $response->assertStatus(422);
    }

    /** @test */
    public function test_station_menu_view_renders_confirmation_modal_and_session_notice_for_postpaid_lost_time()
    {
        $token = Str::random(32);

        Transaction::create([
            'nama' => 'Agus Postpaid Player',
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

        $response = $this->get(route('public.qr.station', $this->location->code));

        $response->assertStatus(200);
        $response->assertViewIs('qr_order.menu');

        // Check confirmation modal elements
        $response->assertSee('confirmOrderModal', false);
        $response->assertSee('Konfirmasi Pesanan', false);
        $response->assertSee('Kembali / Edit Pesanan', false);
        $response->assertSee('Ya, Konfirmasi Pesanan', false);

        // Check station & player identity in modal or header
        $response->assertSee($this->location->name, false);
        $response->assertSee($this->location->code, false);
        $response->assertSee('Agus Postpaid Player', false);

        // Check postpaid payment notice
        $response->assertSee('Informasi Pembayaran:', false);
        $response->assertSee('Anda memesan pada sesi Lost Time. Tagihan F&B akan dicatat ke tagihan meja Anda dan dibayarkan saat checkout permainan.', false);
    }

    /** @test */
    public function test_station_menu_view_renders_session_notice_for_prepaid_paket()
    {
        $token = Str::random(32);

        Transaction::create([
            'nama' => 'Siti Prepaid Player',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'prepaid',
            'billing_status' => Transaction::BILLING_RUNNING,
            'status' => 'Berjalan',
            'harga' => 30000,
            'session_token' => $token,
            'waktu_mulai' => now(),
            'started_at' => now(),
            'total' => 30000,
        ]);

        $response = $this->get(route('public.qr.station', $this->location->code));

        $response->assertStatus(200);
        $response->assertViewIs('qr_order.menu');

        // Check confirmation modal elements
        $response->assertSee('confirmOrderModal', false);

        // Check prepaid payment notice
        $response->assertSee('<strong>Informasi Pembayaran:</strong> Anda memesan pada sesi Paket. Pembayaran dapat dilakukan kepada staf saat pesanan diantarkan atau setelah sesi bermain selesai.', false);
        $response->assertDontSee('Lost Time', false);
    }
}
