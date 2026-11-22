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
    public function test_timer_sync_data_and_dashboard_agree_on_authoritative_running_tx_when_device_is_tersedia(): void
    {
        $this->actingAs($this->kasir);

        // Device DB status is Tersedia (stale), while an authoritative RUNNING transaction exists
        $runningTx = Transaction::create([
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

        $this->assertNotNull($targetDevice, 'Target device should be present in timer sync response.');
        $this->assertEquals('Digunakan', $targetDevice['status']);
        $this->assertNotNull($targetDevice['transaction'], 'Authoritative RUNNING transaction must be returned.');
        $this->assertEquals($runningTx->id_transaksi, $targetDevice['transaction']['id_transaksi']);

        // Dashboard DOM must agree with /device/timers to prevent infinite reload loops
        $dashboardResponse = $this->get(route('transaction.index'));
        $dashboardResponse->assertStatus(200);

        $html = $dashboardResponse->getContent();
        $pattern = '/<article\b[^>]*id="station-card-' . $this->device->id . '"[^>]*>.*?<\/article>/s';
        $this->assertSame(1, preg_match($pattern, $html, $matches), 'Station card must be rendered on dashboard.');
        $cardHtml = $matches[0];

        $this->assertMatchesRegularExpression('/class="[^"]*\bis-used\b[^"]*"/', $cardHtml);
        $this->assertStringContainsString('data-tx-id="' . $runningTx->id_transaksi . '"', $cardHtml);
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
