<?php

namespace Tests\Feature\QrOrdering;

use App\Models\Device;
use App\Models\Location;
use App\Models\Playstation;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StationPlayerIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected $kasir;
    protected $playstation;
    protected $device;
    protected $location;

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
    }

    /** @test */
    public function idle_station_without_session_displays_belum_ada_pemain()
    {
        $response = $this->get(route('public.qr.station', $this->location->code));

        $response->assertStatus(200);
        $response->assertViewIs('qr_order.no_session');
        $response->assertSee('Nama Pemain: Belum Ada Pemain');
    }

    /** @test */
    public function station_with_active_lost_time_session_displays_player_name()
    {
        Transaction::create([
            'nama' => 'Budi LostTime Player',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'postpaid',
            'billing_status' => Transaction::BILLING_RUNNING,
            'status' => 'Berjalan',
            'harga' => 15000,
            'session_token' => Str::random(32),
            'waktu_mulai' => now(),
            'started_at' => now(),
            'total' => 0,
        ]);

        $response = $this->get(route('public.qr.station', $this->location->code));

        $response->assertStatus(200);
        $response->assertViewIs('qr_order.menu');
        $response->assertSee('Nama Pemain:');
        $response->assertSee('Budi LostTime Player');
    }

    /** @test */
    public function station_with_active_paket_session_displays_player_name()
    {
        Transaction::create([
            'nama' => 'Siti Paket Player',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'prepaid',
            'billing_status' => Transaction::BILLING_RUNNING,
            'status' => 'Berjalan',
            'harga' => 30000,
            'session_token' => Str::random(32),
            'waktu_mulai' => now(),
            'started_at' => now(),
            'total' => 30000,
        ]);

        $response = $this->get(route('public.qr.station', $this->location->code));

        $response->assertStatus(200);
        $response->assertViewIs('qr_order.menu');
        $response->assertSee('Nama Pemain:');
        $response->assertSee('Siti Paket Player');
    }

    /** @test */
    public function ended_session_immediately_reverts_to_belum_ada_pemain()
    {
        $session = Transaction::create([
            'nama' => 'Agus Finished Player',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'postpaid',
            'billing_status' => Transaction::BILLING_RUNNING,
            'status' => 'Berjalan',
            'harga' => 15000,
            'session_token' => Str::random(32),
            'waktu_mulai' => now()->subHour(),
            'started_at' => now()->subHour(),
            'total' => 15000,
        ]);

        $session->update([
            'billing_status' => Transaction::BILLING_COMPLETED,
            'status' => 'Selesai',
            'ended_at' => now(),
        ]);

        $response = $this->get(route('public.qr.station', $this->location->code));

        $response->assertStatus(200);
        $response->assertViewIs('qr_order.no_session');
        $response->assertSee('Nama Pemain: Belum Ada Pemain');
        $response->assertDontSee('Agus Finished Player');
    }

    /** @test */
    public function session_rollover_displays_new_player_name_and_excludes_previous()
    {
        $sessionA = Transaction::create([
            'nama' => 'Customer A Old',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'postpaid',
            'billing_status' => Transaction::BILLING_RUNNING,
            'status' => 'Berjalan',
            'harga' => 15000,
            'session_token' => Str::random(32),
            'waktu_mulai' => now()->subHours(2),
            'started_at' => now()->subHours(2),
            'total' => 30000,
        ]);

        $sessionA->update([
            'billing_status' => Transaction::BILLING_COMPLETED,
            'status' => 'Selesai',
            'ended_at' => now()->subMinutes(10),
        ]);

        $sessionB = Transaction::create([
            'nama' => 'Customer B New',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'tipe_transaksi' => 'postpaid',
            'billing_status' => Transaction::BILLING_RUNNING,
            'status' => 'Berjalan',
            'harga' => 15000,
            'session_token' => Str::random(32),
            'waktu_mulai' => now()->subMinutes(5),
            'started_at' => now()->subMinutes(5),
            'total' => 0,
        ]);

        $response = $this->get(route('public.qr.station', $this->location->code));

        $response->assertStatus(200);
        $response->assertViewIs('qr_order.menu');
        $response->assertSee('Nama Pemain:');
        $response->assertSee('Customer B New');
        $response->assertDontSee('Customer A Old');
    }
}
