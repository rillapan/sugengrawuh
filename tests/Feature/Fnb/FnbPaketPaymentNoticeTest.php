<?php

namespace Tests\Feature\Fnb;

use App\Models\Device;
use App\Models\Fnb;
use App\Models\FnbOrder;
use App\Models\Location;
use App\Models\Playstation;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WorkShift;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FnbPaketPaymentNoticeTest extends TestCase
{
    use RefreshDatabase;

    protected User $kasir;
    protected Playstation $playstation;
    protected Device $device1;
    protected Device $device2;
    protected Location $location1;
    protected Location $location2;
    protected WorkShift $workShift;
    protected Fnb $fnbItem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workShift = WorkShift::create([
            'nama_shift' => 'Shift Pagi',
            'jam_mulai' => '08:00:00',
            'jam_selesai' => '16:00:00',
        ]);

        $this->kasir = User::factory()->create([
            'name' => 'Kasir Test',
            'role' => 'kasir',
            'email' => 'kasir_notice@test.local',
        ]);

        $this->playstation = Playstation::create([
            'nama' => 'PS5',
            'harga' => 15000,
        ]);

        $this->device1 = Device::create([
            'nama' => 'PS5 - Station 1',
            'status' => 'Digunakan',
            'playstation_id' => $this->playstation->id,
        ]);

        $this->device2 = Device::create([
            'nama' => 'PS5 - Station 2',
            'status' => 'Digunakan',
            'playstation_id' => $this->playstation->id,
        ]);

        $this->location1 = Location::create([
            'name' => 'Station 1 Meja',
            'code' => 'ST-01',
            'type' => 'station',
            'device_id' => $this->device1->id,
            'is_active' => true,
        ]);

        $this->location2 = Location::create([
            'name' => 'Station 2 Meja',
            'code' => 'ST-02',
            'type' => 'station',
            'device_id' => $this->device2->id,
            'is_active' => true,
        ]);

        $this->fnbItem = Fnb::create([
            'nama' => 'Kopi Robusta',
            'harga_beli' => 3000,
            'harga_jual' => 8000,
            'stok' => 50,
        ]);
    }

    /** @test */
    public function test_options_contain_correct_data_payment_status_for_unpaid_and_paid_paket()
    {
        $unpaidPaket = Transaction::create([
            'nama' => 'Pelanggan Unpaid',
            'status' => 'user',
            'device_id' => $this->device1->id,
            'playstation_id' => $this->playstation->id,
            'harga' => 25000,
            'total' => 25000,
            'waktu_mulai' => '10:00:00',
            'jam_main' => '01:00:00',
            'duration_seconds' => 3600,
            'started_at' => Carbon::now()->subMinutes(10),
            'expires_at' => Carbon::now()->addMinutes(50),
            'billing_status' => Transaction::BILLING_RUNNING,
            'status_transaksi' => 'belum selesai',
            'tipe_transaksi' => 'prepaid',
            'payment_status' => 'unpaid',
            'user_id' => $this->kasir->id,
        ]);

        $paidPaket = Transaction::create([
            'nama' => 'Pelanggan Paid',
            'status' => 'user',
            'device_id' => $this->device2->id,
            'playstation_id' => $this->playstation->id,
            'harga' => 25000,
            'total' => 25000,
            'waktu_mulai' => '10:00:00',
            'jam_main' => '01:00:00',
            'duration_seconds' => 3600,
            'started_at' => Carbon::now()->subMinutes(10),
            'expires_at' => Carbon::now()->addMinutes(50),
            'billing_status' => Transaction::BILLING_RUNNING,
            'status_transaksi' => 'belum selesai',
            'tipe_transaksi' => 'prepaid',
            'payment_status' => 'paid',
            'paid_at' => Carbon::now()->subMinutes(10),
            'payment_method' => 'cash',
            'user_id' => $this->kasir->id,
        ]);

        $response = $this->actingAs($this->kasir)->get(route('fnb.orders.create'));
        $response->assertStatus(200);

        // Verify options have data-payment-status
        $response->assertSee('value="' . $unpaidPaket->id_transaksi . '"', false);
        $response->assertSee('data-payment-status="unpaid"', false);
        $response->assertSee('value="' . $paidPaket->id_transaksi . '"', false);
        $response->assertSee('data-payment-status="paid"', false);

        // Verify client-side JavaScript handles both conditions dynamically
        $response->assertSee('Sesi Paket — Belum Lunas', false);
        $response->assertSee('Biaya bermain belum dibayar. Pesanan F&B dapat digabungkan dengan tagihan bermain untuk pembayaran di akhir sesi, atau dibayar secara terpisah sesuai pilihan pembayaran yang tersedia.', false);
        $response->assertSee('Sesi Paket — Sudah Lunas', false);
        $response->assertSee('Biaya bermain sudah lunas. Pesanan F&B menjadi tagihan tambahan yang terpisah dari biaya bermain dan dapat dibayar sesuai pilihan pembayaran yang tersedia.', false);

        // Verify old text is completely gone
        $response->assertDontSee('Biaya main sudah lunas. Pesanan F&B dapat dilayani langsung dan dibayarkan saat staf mengantar makanan atau setelah sesi bermain selesai.', false);
    }

    /** @test */
    public function test_selected_unpaid_paket_renders_belum_lunas_notice_in_blade()
    {
        $unpaidPaket = Transaction::create([
            'nama' => 'Pelanggan Unpaid',
            'status' => 'user',
            'device_id' => $this->device1->id,
            'playstation_id' => $this->playstation->id,
            'harga' => 25000,
            'total' => 25000,
            'waktu_mulai' => '10:00:00',
            'jam_main' => '01:00:00',
            'duration_seconds' => 3600,
            'started_at' => Carbon::now()->subMinutes(10),
            'expires_at' => Carbon::now()->addMinutes(50),
            'billing_status' => Transaction::BILLING_RUNNING,
            'status_transaksi' => 'belum selesai',
            'tipe_transaksi' => 'prepaid',
            'payment_status' => 'unpaid',
            'user_id' => $this->kasir->id,
        ]);

        // Simulate returning with old('transaction_id')
        session()->flashInput(['transaction_id' => $unpaidPaket->id_transaksi]);

        $response = $this->actingAs($this->kasir)->get(route('fnb.orders.create'));
        $response->assertStatus(200);

        $response->assertSee('<strong id="noticePaketTitle">Sesi Paket — Belum Lunas</strong>', false);
        $response->assertSee('Biaya bermain belum dibayar. Pesanan F&B dapat digabungkan dengan tagihan bermain untuk pembayaran di akhir sesi, atau dibayar secara terpisah sesuai pilihan pembayaran yang tersedia.', false);
    }

    /** @test */
    public function test_selected_paid_paket_renders_sudah_lunas_notice_in_blade()
    {
        $paidPaket = Transaction::create([
            'nama' => 'Pelanggan Paid',
            'status' => 'user',
            'device_id' => $this->device1->id,
            'playstation_id' => $this->playstation->id,
            'harga' => 25000,
            'total' => 25000,
            'waktu_mulai' => '10:00:00',
            'jam_main' => '01:00:00',
            'duration_seconds' => 3600,
            'started_at' => Carbon::now()->subMinutes(10),
            'expires_at' => Carbon::now()->addMinutes(50),
            'billing_status' => Transaction::BILLING_RUNNING,
            'status_transaksi' => 'belum selesai',
            'tipe_transaksi' => 'prepaid',
            'payment_status' => 'paid',
            'paid_at' => Carbon::now()->subMinutes(10),
            'payment_method' => 'cash',
            'user_id' => $this->kasir->id,
        ]);

        // Simulate returning with old('transaction_id')
        session()->flashInput(['transaction_id' => $paidPaket->id_transaksi]);

        $response = $this->actingAs($this->kasir)->get(route('fnb.orders.create'));
        $response->assertStatus(200);

        $response->assertSee('<strong id="noticePaketTitle">Sesi Paket — Sudah Lunas</strong>', false);
        $response->assertSee('Biaya bermain sudah lunas. Pesanan F&B menjadi tagihan tambahan yang terpisah dari biaya bermain dan dapat dibayar sesuai pilihan pembayaran yang tersedia.', false);
    }

    /** @test */
    public function test_fnb_order_created_on_paid_paket_is_not_automatically_paid_unless_explicitly_paid()
    {
        $paidPaket = Transaction::create([
            'nama' => 'Pelanggan Paket Paid',
            'status' => 'user',
            'device_id' => $this->device1->id,
            'playstation_id' => $this->playstation->id,
            'harga' => 25000,
            'total' => 25000,
            'waktu_mulai' => '10:00:00',
            'jam_main' => '01:00:00',
            'duration_seconds' => 3600,
            'started_at' => Carbon::now()->subMinutes(10),
            'expires_at' => Carbon::now()->addMinutes(50),
            'billing_status' => Transaction::BILLING_RUNNING,
            'status_transaksi' => 'belum selesai',
            'tipe_transaksi' => 'prepaid',
            'payment_status' => 'paid',
            'paid_at' => Carbon::now()->subMinutes(10),
            'payment_method' => 'cash',
            'user_id' => $this->kasir->id,
        ]);

        // Action: save (Simpan Transaksi)
        $response = $this->actingAs($this->kasir)->post(route('fnb.orders.store'), [
            'customer_type' => 'playing',
            'transaction_id' => $paidPaket->id_transaksi,
            'location_id' => $this->location1->id,
            'action' => 'save',
            'items' => [
                [
                    'fnb_id' => $this->fnbItem->id,
                    'qty' => 1,
                    'notes' => 'Panas',
                ],
            ],
        ]);

        $response->assertRedirect(route('fnb.queue.index'));

        $order = FnbOrder::where('transaction_id', $paidPaket->id_transaksi)->first();
        $this->assertNotNull($order);
        // The new F&B order must be unpaid, NOT marked as paid simply because game session is paid
        $this->assertEquals('unpaid', $order->payment_status);
    }
}
