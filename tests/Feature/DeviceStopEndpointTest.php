<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Playstation;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DeviceStopEndpointTest extends TestCase
{
    use DatabaseTransactions;

    protected $admin;
    protected $kasir;
    protected $playstation;
    protected $device;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertEquals('kasir_ps_test', \DB::connection()->getDatabaseName(), 'Must run on test database');

        $this->admin = User::firstOrCreate(
            ['email' => 'admin_stop@test.local'],
            ['name' => 'Admin Stop Test', 'role' => 'admin', 'password' => bcrypt('secret123')]
        );

        $this->kasir = User::firstOrCreate(
            ['email' => 'kasir_stop@test.local'],
            ['name' => 'Kasir Stop Test', 'role' => 'kasir', 'password' => bcrypt('secret123')]
        );

        $this->playstation = Playstation::firstOrCreate(
            ['nama' => 'PS5 Stop Test'],
            ['harga' => 15000]
        );

        $this->device = Device::firstOrCreate(
            ['nama' => 'Device Stop Test Unit'],
            ['status' => 'Tersedia', 'playstation_id' => $this->playstation->id]
        );
    }

    public function test_stop_device_on_active_prepaid_session_with_g2_schema()
    {
        $this->actingAs($this->kasir);

        $this->device->update(['status' => 'Digunakan']);

        $now = Carbon::now();
        $tx = Transaction::create([
            'id_transaksi' => 'TRX-STOP-001',
            'status' => 'user',
            'device_id' => $this->device->id,
            'nama' => 'Gamer Stop 1',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 2,
            'harga' => 15000,
            'total' => 30000,
            'status_transaksi' => 'berjalan',
            'payment_status' => 'unpaid',
            'waktu_mulai' => $now->copy()->subMinutes(30)->format('H:i'),
            'waktu_Selesai' => $now->copy()->addMinutes(90)->format('H:i'),
            'started_at' => $now->copy()->subMinutes(30),
            'expires_at' => $now->copy()->addMinutes(90),
            'billing_status' => Transaction::BILLING_RUNNING,
        ]);

        // Explicitly refresh G2 schema check
        Transaction::hasG2Schema(true);

        $response = $this->postJson("/device/{$this->device->id}/stop");

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'new_status' => 'Tersedia',
            'transaction_id' => $tx->id_transaksi,
        ]);

        $this->assertEquals('Tersedia', $this->device->fresh()->status);

        $txFresh = $tx->fresh();
        $this->assertEquals('selesai', $txFresh->status_transaksi);
        $this->assertEquals(Transaction::BILLING_STOPPED_EARLY, $txFresh->billing_status);
        $this->assertNotNull($txFresh->ended_at);
    }

    public function test_stop_device_graceful_fallback_without_g2_schema()
    {
        $this->actingAs($this->admin);

        $this->device->update(['status' => 'Digunakan']);

        $now = Carbon::now();
        $tx = Transaction::create([
            'id_transaksi' => 'TRX-STOP-002',
            'status' => 'user',
            'device_id' => $this->device->id,
            'nama' => 'Gamer Stop Fallback',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
            'harga' => 15000,
            'total' => 15000,
            'status_transaksi' => 'berjalan',
            'payment_status' => 'unpaid',
            'waktu_mulai' => $now->format('H:i'),
            'waktu_Selesai' => $now->copy()->addHour()->format('H:i'),
        ]);

        // Force hasG2Schema to false to simulate unmigrated environment
        $reflection = new \ReflectionClass(Transaction::class);
        $prop = $reflection->getProperty('hasG2Schema');
        $prop->setAccessible(true);
        $prop->setValue(null, false);

        try {
            $response = $this->postJson("/device/{$this->device->id}/stop");

            $response->assertStatus(200);
            $response->assertJson([
                'success' => true,
                'new_status' => 'Tersedia',
            ]);

            $this->assertEquals('Tersedia', $this->device->fresh()->status);
            $this->assertEquals('selesai', $tx->fresh()->status_transaksi);
        } finally {
            // Restore actual G2 schema cached state
            Transaction::hasG2Schema(true);
        }
    }

    public function test_stop_device_returns_400_when_no_active_transaction()
    {
        $this->actingAs($this->kasir);
        $this->device->update(['status' => 'Tersedia']);

        $response = $this->postJson("/device/{$this->device->id}/stop");
        $response->assertStatus(400);
        $response->assertJson([
            'success' => false,
            'message' => 'Tidak ada transaksi aktif untuk perangkat ini',
        ]);
    }
}
