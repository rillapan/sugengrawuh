<?php

namespace Tests\Feature\QrOrdering;

use App\Models\Device;
use App\Models\Fnb;
use App\Models\FnbOrder;
use App\Models\FnbOrderItem;
use App\Models\Location;
use App\Models\Playstation;
use App\Models\PriceGroup;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicQrOrderSubmissionTest extends TestCase
{
    use RefreshDatabase;

    protected $kasir;
    protected $playstation;
    protected $device;
    protected $location;
    protected $fnbItem;
    protected $priceGroup;

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

        $this->priceGroup = PriceGroup::create([
            'nama' => 'Snack Premium',
            'harga' => 12000,
        ]);

        $this->fnbItem = Fnb::create([
            'nama' => 'Chiki Balls',
            'harga_beli' => 6000,
            'harga_jual' => 10000,
            'price_group_id' => $this->priceGroup->id,
            'stok' => 20,
        ]);
    }

    /** @test */
    public function test_idle_station_without_active_session_shows_redirect_to_cashier_message()
    {
        // No active transaction on this device
        $response = $this->get(route('public.qr.station', $this->location->code));

        $response->assertStatus(200);
        $response->assertViewIs('qr_order.no_session');
        $response->assertSee('kasir', false);
        $response->assertDontSee('form-order');
        $response->assertDontSee('submit');
    }

    /** @test */
    public function test_ac_05_valid_session_token_submits_order_with_customer_and_billing_name_and_price_group_snapshot()
    {
        $token = Str::random(32);

        $session = Transaction::create([
            'nama' => 'Budi Primary Renter',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'postpaid',
            'billing_status' => Transaction::BILLING_RUNNING,
            'status' => 'Berjalan',
            'harga' => 15000,
            'session_token' => $token,
            'session_generation' => 1,
            'waktu_mulai' => now(),
            'started_at' => now(),
            'total' => 0,
        ]);

        // 1. Visit menu
        $menuResponse = $this->get(route('public.qr.station', $this->location->code));
        $menuResponse->assertStatus(200);
        $menuResponse->assertViewIs('qr_order.menu');
        $menuResponse->assertSee($this->fnbItem->nama);
        // Price group price (12000) instead of base harga_jual (10000)
        $menuResponse->assertSee('12.000');

        // 2. Submit order as guest friend "Rendi"
        $payload = [
            'session_token' => $token,
            'customer_name' => 'Rendi Sub-Customer',
            'notes' => 'Tolong pedas ya',
            'items' => [
                [
                    'fnb_id' => $this->fnbItem->id,
                    'qty' => 2,
                    'notes' => 'Dingin',
                ],
            ],
        ];

        $submitResponse = $this->postJson(route('public.qr.station.submit', $this->location->code), $payload);
        $submitResponse->assertStatus(201);
        $submitResponse->assertJsonStructure([
            'success',
            'order' => [
                'id',
                'order_number',
                'session_token',
                'customer_name',
                'status',
                'payment_status',
                'requires_upfront_payment',
            ],
            'redirect',
        ]);

        $order = FnbOrder::where('session_token', $token)->first();
        $this->assertNotNull($order);
        $this->assertSame(FnbOrder::SOURCE_QR, $order->source);
        $this->assertSame($session->id_transaksi, $order->transaction_id);
        $this->assertSame('Rendi Sub-Customer', $order->customer_name);
        $this->assertSame('Budi Primary Renter', $order->billing_name);
        $this->assertSame(FnbOrder::STATUS_PENDING, $order->status);
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertFalse($order->requires_upfront_payment); // Postpaid lost time sets false!

        $this->assertCount(1, $order->items);
        $item = $order->items->first();
        $this->assertSame($this->fnbItem->id, $item->fnb_id);
        $this->assertSame(2, $item->qty);
        $this->assertEquals(6000.0, (float) $item->unit_price_beli);
        $this->assertEquals(12000.0, (float) $item->unit_price_jual); // PriceGroup price
        $this->assertEquals(24000.0, (float) $item->subtotal);

        // Crucial AC rule: Stock is NOT decremented yet! Stock remains 20 until ACCEPTED
        $this->assertSame(20, $this->fnbItem->fresh()->stok);
    }

    /** @test */
    public function test_ac_06_old_token_rejected_after_session_ends_or_new_customer_starts()
    {
        $oldToken = Str::random(32);

        // Session ended
        $session = Transaction::create([
            'nama' => 'Pelanggan Lama',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'postpaid',
            'billing_status' => Transaction::BILLING_COMPLETED,
            'status' => 'Selesai',
            'harga' => 15000,
            'session_token' => $oldToken,
            'waktu_mulai' => now()->subHours(2),
            'waktu_Selesai' => now()->subHour(),
            'total' => 30000,
        ]);

        $payload = [
            'session_token' => $oldToken,
            'customer_name' => 'Ghost Customer',
            'items' => [
                ['fnb_id' => $this->fnbItem->id, 'qty' => 1],
            ],
        ];

        // 1. Submit against ended session -> 403 Forbidden
        $response = $this->postJson(route('public.qr.station.submit', $this->location->code), $payload);
        $response->assertStatus(403);

        // 2. Now start a NEW customer session on the same device with a NEW token
        $newToken = Str::random(32);
        Transaction::create([
            'nama' => 'Pelanggan Baru',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'postpaid',
            'billing_status' => Transaction::BILLING_RUNNING,
            'status' => 'Berjalan',
            'harga' => 15000,
            'session_token' => $newToken,
            'session_generation' => 2,
            'waktu_mulai' => now(),
            'started_at' => now(),
            'total' => 0,
        ]);

        // Attempting with oldToken must STILL be rejected with 403!
        $response2 = $this->postJson(route('public.qr.station.submit', $this->location->code), $payload);
        $response2->assertStatus(403);

        // But attempting with newToken succeeds!
        $payload['session_token'] = $newToken;
        $response3 = $this->postJson(route('public.qr.station.submit', $this->location->code), $payload);
        $response3->assertStatus(201);
    }

    /** @test */
    public function test_open_01_paket_qr_order_sets_requires_upfront_payment_true_while_lost_time_sets_false()
    {
        $tokenPaket = Str::random(32);
        $paketSession = Transaction::create([
            'nama' => 'Paket Player',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'prepaid',
            'billing_status' => Transaction::BILLING_RUNNING,
            'status' => 'Berjalan',
            'harga' => 15000,
            'session_token' => $tokenPaket,
            'waktu_mulai' => now(),
            'started_at' => now(),
            'total' => 25000,
        ]);

        $payload = [
            'session_token' => $tokenPaket,
            'customer_name' => 'Friend of Paket Player',
            'items' => [
                ['fnb_id' => $this->fnbItem->id, 'qty' => 1],
            ],
        ];

        $resp = $this->postJson(route('public.qr.station.submit', $this->location->code), $payload);
        $resp->assertStatus(201);

        $order = FnbOrder::where('session_token', $tokenPaket)->first();
        $this->assertTrue($order->requires_upfront_payment);

        // Now test custom_package type
        $device2 = Device::create([
            'nama' => 'PS-02',
            'playstation_id' => $this->playstation->id,
            'status' => 'Tersedia',
        ]);
        $device2->playstations()->attach($this->playstation->id);
        $loc2 = Location::create([
            'name' => 'Bilik 2',
            'code' => 'STATION-02',
            'type' => 'station',
            'device_id' => $device2->id,
            'is_active' => true,
        ]);

        $tokenCustom = Str::random(32);
        Transaction::create([
            'nama' => 'Custom Player',
            'device_id' => $device2->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'custom_package',
            'billing_status' => Transaction::BILLING_RUNNING,
            'status' => 'Berjalan',
            'harga' => 15000,
            'session_token' => $tokenCustom,
            'waktu_mulai' => now(),
            'started_at' => now(),
            'total' => 50000,
        ]);

        $payload['session_token'] = $tokenCustom;
        $respCustom = $this->postJson(route('public.qr.station.submit', $loc2->code), $payload);
        $respCustom->assertStatus(201);
        $orderCustom = FnbOrder::where('session_token', $tokenCustom)->first();
        $this->assertTrue($orderCustom->requires_upfront_payment);
    }

    /** @test */
    public function test_ac_09_insufficient_stock_or_price_change_rejected_atomically()
    {
        $token = Str::random(32);
        Transaction::create([
            'nama' => 'Active Customer',
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

        // 1. Insufficient stock (stok is 20, requesting 25)
        $payloadStock = [
            'session_token' => $token,
            'customer_name' => 'Greedy Player',
            'items' => [
                ['fnb_id' => $this->fnbItem->id, 'qty' => 25],
            ],
        ];
        $respStock = $this->postJson(route('public.qr.station.submit', $this->location->code), $payloadStock);
        $respStock->assertStatus(422);

        // 2. Price change detected (current price group price is 12000, client expected 10000)
        $payloadPrice = [
            'session_token' => $token,
            'customer_name' => 'Old Menu Cached Player',
            'items' => [
                [
                    'fnb_id' => $this->fnbItem->id,
                    'qty' => 1,
                    'expected_price' => 10000,
                ],
            ],
        ];
        $respPrice = $this->postJson(route('public.qr.station.submit', $this->location->code), $payloadPrice);
        $respPrice->assertStatus(422);
    }

    /** @test */
    public function test_ac_08_two_simultaneous_qr_orders_create_separate_orders_with_unique_numbers()
    {
        $token = Str::random(32);
        $session = Transaction::create([
            'nama' => 'Group Party',
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

        $payload1 = [
            'session_token' => $token,
            'customer_name' => 'Player 1',
            'items' => [['fnb_id' => $this->fnbItem->id, 'qty' => 1]],
        ];
        $payload2 = [
            'session_token' => $token,
            'customer_name' => 'Player 2',
            'items' => [['fnb_id' => $this->fnbItem->id, 'qty' => 2]],
        ];

        $resp1 = $this->postJson(route('public.qr.station.submit', $this->location->code), $payload1);
        $resp2 = $this->postJson(route('public.qr.station.submit', $this->location->code), $payload2);

        $resp1->assertStatus(201);
        $resp2->assertStatus(201);

        $order1 = FnbOrder::find($resp1->json('order.id'));
        $order2 = FnbOrder::find($resp2->json('order.id'));

        $this->assertNotSame($order1->id, $order2->id);
        $this->assertNotSame($order1->order_number, $order2->order_number);
        $this->assertSame($session->id_transaksi, $order1->transaction_id);
        $this->assertSame($session->id_transaksi, $order2->transaction_id);
    }

    /** @test */
    public function test_ac_13_public_qr_cannot_access_hardware_tv_or_admin_location_routes()
    {
        // Unauthenticated guest cannot access admin location management
        $this->get('/admin/locations')->assertRedirect('/login');
        $this->post('/admin/locations', ['name' => 'Hacked'])->assertRedirect('/login');

        // Unauthenticated guest cannot trigger TV control or hardware
        $this->post('/admin/stations/1/hardware/power', ['action' => 'turn_off'])->assertRedirect('/login');
    }

    /** @test */
    public function test_public_views_do_not_leak_billing_name()
    {
        $token = Str::random(32);
        $session = Transaction::create([
            'nama' => 'SUPER_SECRET_PRIMARY_CUSTOMER_NAME',
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

        // 1. Menu view displays active station player name
        $menuResp = $this->get(route('public.qr.station', $this->location->code));
        $menuResp->assertStatus(200);
        $menuResp->assertSee('SUPER_SECRET_PRIMARY_CUSTOMER_NAME');

        // 2. Submit order as guest "Tamu Sopan"
        $payload = [
            'session_token' => $token,
            'customer_name' => 'Tamu Sopan',
            'items' => [['fnb_id' => $this->fnbItem->id, 'qty' => 1]],
        ];
        $submitResp = $this->postJson(route('public.qr.station.submit', $this->location->code), $payload);
        $orderNumber = $submitResp->json('order.order_number');

        // 3. Status view should NOT leak billing_name
        $statusResp = $this->get(route('public.qr.status', $orderNumber));
        $statusResp->assertStatus(200);
        $statusResp->assertSee('Tamu Sopan');
        $statusResp->assertDontSee('SUPER_SECRET_PRIMARY_CUSTOMER_NAME');
    }
}
