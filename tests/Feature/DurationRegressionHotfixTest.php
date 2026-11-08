<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\HourlyPrice;
use App\Models\Playstation;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DurationRegressionHotfixTest extends TestCase
{
    use DatabaseTransactions;

    protected User $kasir;
    protected Device $billiardDevice;
    protected Playstation $billiardPagi;
    protected Playstation $billiardSore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kasir = User::firstOrCreate(
            ['email' => 'kasir_hotfix_test@sugengrawuh.com'],
            ['name' => 'Kasir Hotfix Test', 'password' => bcrypt('password'), 'role' => 'kasir']
        );

        // Replicate Meja 1 - Billiard setup exactly
        $this->billiardPagi = Playstation::create([
            'nama' => 'BILLIARD pagi TEST',
            'harga' => '25000',
        ]);

        $this->billiardSore = Playstation::create([
            'nama' => 'BILLIARD sore TEST',
            'harga' => '30000',
        ]);

        // Preset 5 menit = Rp2.000 on BILLIARD pagi
        HourlyPrice::create([
            'playstation_id' => $this->billiardPagi->id,
            'duration_seconds' => 300,
            'price' => 2000,
        ]);

        // Preset 1 jam = Rp10.000 on BILLIARD pagi
        HourlyPrice::create([
            'playstation_id' => $this->billiardPagi->id,
            'duration_seconds' => 3600,
            'price' => 10000,
        ]);

        $this->billiardDevice = Device::create([
            'nama' => 'Meja Test - Billiard',
            'status' => 'Tersedia',
            'playstation_id' => $this->billiardPagi->id,
            'session_generation' => 1,
        ]);

        $this->billiardDevice->playstations()->attach([$this->billiardPagi->id, $this->billiardSore->id]);
    }

    /**
     * Test BUG-05 & Multi-PlayStation: /api/get-hourly-prices returns humanized labels and duration_seconds.
     */
    public function test_bug_05_api_returns_clean_human_labels_and_exact_durations()
    {
        $response = $this->actingAs($this->kasir)->getJson(
            "/api/get-hourly-prices?device={$this->billiardDevice->id}&playstation_id={$this->billiardPagi->id}"
        );

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'prices',
            'durations' => [
                '*' => ['id', 'duration_seconds', 'formatted', 'human', 'price']
            ],
            'base_price'
        ]);

        $durations = $response->json('durations');
        $this->assertCount(2, $durations);

        // Check 5 minute preset
        $preset5m = collect($durations)->firstWhere('duration_seconds', 300);
        $this->assertNotNull($preset5m);
        $this->assertEquals('5 menit', $preset5m['human']);
        $this->assertEquals('00:05:00', $preset5m['formatted']);
        $this->assertEquals(2000, $preset5m['price']);

        // Check 1 hour preset
        $preset1h = collect($durations)->firstWhere('duration_seconds', 3600);
        $this->assertNotNull($preset1h);
        $this->assertEquals('1 jam', $preset1h['human']);
        $this->assertEquals('01:00:00', $preset1h['formatted']);
        $this->assertEquals(10000, $preset1h['price']);
    }

    /**
     * Test BUG-01, BUG-02, BUG-03: Storing transaction with 5-minute preset saves Rp2.000 and calculates proper end time.
     */
    public function test_bug_01_02_03_transaction_stores_preset_price_and_correct_waktu_selesai()
    {
        Carbon::setTestNow(Carbon::parse('2026-11-08 20:46:00'));

        $postData = [
            'tipe_transaksi' => 'prepaid',
            'nama' => 'Pelanggan Billiard 5 Menit',
            'device_id' => $this->billiardDevice->id,
            'playstation_id' => $this->billiardPagi->id,
            'jam_main_select' => '300',
            'duration_seconds' => '300',
            'jam_main' => '00:05:00',
            'harga' => 25000,
            'total' => 2000,
        ];

        $response = $this->actingAs($this->kasir)->post(route('transaction.store'), $postData);
        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('transaction.index'));

        $transaction = Transaction::where('nama', 'Pelanggan Billiard 5 Menit')->latest()->first();
        $this->assertNotNull($transaction);

        // BUG-02: Total PS / total price is Rp2.000 (preset ADMIN)
        $this->assertEquals(2000, (int) $transaction->total);

        // BUG-01: Waktu selesai starts with 20:51, duration_seconds is 300, expires_at is 20:51:00
        $this->assertStringStartsWith('20:51', $transaction->waktu_Selesai);
        $this->assertEquals(300, $transaction->duration_seconds);
        $this->assertNotNull($transaction->expires_at);
        $this->assertEquals('2026-11-08 20:51:00', $transaction->expires_at->format('Y-m-d H:i:s'));

        // Device status must be Digunakan
        $this->billiardDevice->refresh();
        $this->assertEquals('Digunakan', $this->billiardDevice->status);

        Carbon::setTestNow();
    }

    /**
     * Test BUG-04: Timer behavior on /device and /device/timers.
     */
    public function test_bug_04_device_timer_when_tersedia_and_when_digunakan()
    {
        // 1. When status is Tersedia, timer sync returns null transaction and no active running session
        $responseAvailable = $this->actingAs($this->kasir)->getJson(route('device.timers'));
        $responseAvailable->assertStatus(200);
        $deviceData = collect($responseAvailable->json('devices'))->firstWhere('id', $this->billiardDevice->id);
        $this->assertEquals('Tersedia', $deviceData['status']);
        $this->assertNull($deviceData['transaction']);

        // 2. Start a 5-minute prepaid session
        Carbon::setTestNow(Carbon::parse('2026-11-08 20:46:00'));

        $tx = Transaction::create([
            'nama' => 'Active Session 5 Min',
            'status' => 'user',
            'tipe_transaksi' => 'prepaid',
            'device_id' => $this->billiardDevice->id,
            'playstation_id' => $this->billiardPagi->id,
            'waktu_mulai' => '20:46',
            'waktu_Selesai' => '20:51',
            'duration_seconds' => 300,
            'jam_main' => '00:05:00',
            'harga' => 25000,
            'total' => 2000,
            'status_transaksi' => 'sukses',
            'payment_status' => 'paid',
            'billing_status' => Transaction::BILLING_RUNNING,
            'started_at' => Carbon::parse('2026-11-08 20:46:00'),
            'expires_at' => Carbon::parse('2026-11-08 20:51:00'),
        ]);

        $this->billiardDevice->update(['status' => 'Digunakan']);

        // Test /device/timers API
        $responseInUse = $this->actingAs($this->kasir)->getJson(route('device.timers'));
        $responseInUse->assertStatus(200);
        $deviceInUseData = collect($responseInUse->json('devices'))->firstWhere('id', $this->billiardDevice->id);
        $this->assertEquals('Digunakan', $deviceInUseData['status']);
        $this->assertNotNull($deviceInUseData['transaction']);
        $this->assertEquals(300, $deviceInUseData['transaction']['remaining_seconds']);
        $this->assertFalse($deviceInUseData['transaction']['is_expired']);

        // Test /device blade view has timer data populated
        $responseView = $this->actingAs($this->kasir)->get(route('device.index'));
        $responseView->assertStatus(200);
        $responseView->assertViewHas('timers', function ($timers) {
            return isset($timers[$this->billiardDevice->id])
                && str_contains($timers[$this->billiardDevice->id]['expires_at'], '20:51:00');
        });

        Carbon::setTestNow();
    }
}
