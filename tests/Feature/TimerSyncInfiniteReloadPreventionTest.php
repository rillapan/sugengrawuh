<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Playstation;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class TimerSyncInfiniteReloadPreventionTest extends TestCase
{
    use DatabaseTransactions;

    protected User $kasir;
    protected Playstation $playstation;
    protected Device $device;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kasir = User::firstOrCreate(
            ['email' => 'kasir_timer_test@billing.test'],
            ['name' => 'Kasir Timer Test', 'role' => 'kasir', 'password' => bcrypt('password')]
        );

        $this->playstation = Playstation::firstOrCreate(
            ['nama' => 'PS Test Timer'],
            ['harga' => 10000]
        );

        $this->device = Device::create([
            'nama' => 'Station Timer Test',
            'playstation_id' => $this->playstation->id,
            'status' => 'Tersedia',
        ]);
        $this->device->playstations()->attach($this->playstation->id);
    }

    /** @test */
    public function test_timer_sync_data_returns_null_transaction_for_tersedia_device_with_orphaned_running_tx(): void
    {
        $this->actingAs($this->kasir);

        // Device is Tersedia, but an orphan RUNNING transaction exists in DB
        $orphanTx = Transaction::create([
            'status' => 'user',
            'nama' => 'Orphan Customer',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'harga' => 10000,
            'waktu_mulai' => '10:00:00',
            'status_transaksi' => 'berjalan',
            'payment_status' => 'unpaid',
            'tipe_transaksi' => 'postpaid',
            'billing_status' => Transaction::BILLING_RUNNING,
            'total' => 0,
        ]);

        $response = $this->getJson(route('device.timers'));
        $response->assertStatus(200);

        $devices = collect($response->json('devices'));
        $targetDevice = $devices->firstWhere('id', $this->device->id);

        $this->assertNotNull($targetDevice, 'Target device should be present in timer sync response.');
        $this->assertEquals('Tersedia', $targetDevice['status']);
        // For a Tersedia device, transaction MUST be null to prevent infinite client reload loops
        $this->assertNull($targetDevice['transaction'], 'Tersedia device must not return active transaction.');
    }

    /** @test */
    public function test_timer_sync_data_returns_active_transaction_when_device_is_digunakan(): void
    {
        $this->actingAs($this->kasir);

        $this->device->update(['status' => 'Digunakan']);

        $activeTx = Transaction::create([
            'status' => 'user',
            'nama' => 'Active Customer',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'harga' => 10000,
            'waktu_mulai' => '10:00:00',
            'status_transaksi' => 'berjalan',
            'payment_status' => 'unpaid',
            'tipe_transaksi' => 'postpaid',
            'billing_status' => Transaction::BILLING_RUNNING,
            'total' => 0,
        ]);

        $response = $this->getJson(route('device.timers'));
        $response->assertStatus(200);

        $devices = collect($response->json('devices'));
        $targetDevice = $devices->firstWhere('id', $this->device->id);

        $this->assertNotNull($targetDevice);
        $this->assertEquals('Digunakan', $targetDevice['status']);
        $this->assertNotNull($targetDevice['transaction']);
        $this->assertEquals($activeTx->id_transaksi, $targetDevice['transaction']['id_transaksi']);
    }

    /** @test */
    public function test_stop_device_can_stop_running_session_started_on_previous_day(): void
    {
        $this->actingAs($this->kasir);

        $this->device->update(['status' => 'Digunakan']);

        $yesterday = Carbon::now()->subDays(2);
        $pastTx = Transaction::create([
            'status' => 'user',
            'nama' => 'Overnight Customer',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasir->id,
            'harga' => 10000,
            'waktu_mulai' => '23:00:00',
            'status_transaksi' => 'berjalan',
            'payment_status' => 'unpaid',
            'tipe_transaksi' => 'postpaid',
            'billing_status' => Transaction::BILLING_RUNNING,
            'total' => 0,
        ]);
        \DB::table('transactions')->where('id_transaksi', $pastTx->id_transaksi)->update([
            'created_at' => $yesterday,
            'updated_at' => $yesterday,
        ]);

        $response = $this->postJson(route('device.stop', $this->device->id));
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'new_status' => 'Tersedia',
            'transaction_id' => $pastTx->id_transaksi,
        ]);

        $this->assertEquals('Tersedia', $this->device->fresh()->status);
        $this->assertEquals('selesai', $pastTx->fresh()->status_transaksi);
    }
}
