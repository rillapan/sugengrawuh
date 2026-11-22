<?php

namespace Tests\Feature\Billing;

use App\Models\Device;
use App\Models\HourlyPrice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Playstation;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BillingService;
use App\Services\Billing\LostTimeBillingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LostTimeBillingParityTest extends TestCase
{
    use RefreshDatabase;

    protected User $kasir;
    protected Playstation $ps4;
    protected Device $tv1;
    protected BillingService $billingService;
    protected LostTimeBillingService $lostTimeService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kasir = User::factory()->create([
            'name' => 'Kasir Billing TV1',
            'email' => 'kasir.tv1@sugengrawuh.test',
            'role' => 'kasir',
            'is_fnb_staff' => false,
        ]);

        // Setup PS4 TV1 with standard tariff Rp 15.000 / hour
        $this->ps4 = Playstation::create([
            'nama' => 'PS4 TV1',
            'harga' => 15000,
        ]);

        HourlyPrice::create([
            'playstation_id' => $this->ps4->id,
            'hour' => 1,
            'price' => 15000,
        ]);
        HourlyPrice::create([
            'playstation_id' => $this->ps4->id,
            'hour' => 2,
            'price' => 30000,
        ]);
        HourlyPrice::create([
            'playstation_id' => $this->ps4->id,
            'hour' => 3,
            'price' => 45000,
        ]);

        $this->tv1 = Device::create([
            'nama' => 'TV1',
            'playstation_id' => $this->ps4->id,
            'status' => 'Tersedia',
        ]);

        $this->billingService = app(BillingService::class);
        $this->lostTimeService = app(LostTimeBillingService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Helper to simulate the exact JS formula in public/js/transaction-dashboard.js
     */
    protected function computeJsEstimate(float $hourlyRate, int $elapsedSeconds, ?array $packages = null): float
    {
        $rate = (float) $hourlyRate;
        if ($rate <= 0 || $elapsedSeconds <= 0) {
            return 0.0;
        }

        $elapsedMinutes = (int) floor($elapsedSeconds / 60);
        if ($elapsedMinutes <= 0) {
            return 0.0;
        }

        $fullHours = (int) floor($elapsedMinutes / 60);
        $remainingMinutes = (int) ($elapsedMinutes % 60);
        $raw = 0.0;

        if ($fullHours > 0) {
            if ($packages && isset($packages[$fullHours])) {
                $raw += (float) $packages[$fullHours];
            } else {
                $raw += $fullHours * $rate;
            }
        }

        if ($remainingMinutes > 0) {
            $raw += ($remainingMinutes * $rate) / 60;
        }

        $lowerThousand = floor($raw / 1000) * 1000;
        $diff = $raw - $lowerThousand;

        if ($diff == 0) {
            return $raw;
        }
        if ($diff <= 500) {
            return $lowerThousand + 500;
        }
        return $lowerThousand + 1000;
    }

    /**
     * Test Case 1: Duration 3 jam 12 menit 0 detik
     * Rate: Rp 15.000 / jam
     * Calculation: 3 * 15.000 + 12 * 250 = 48.000 (diff 0 -> 48.000)
     */
    public function test_duration_3h_12m_0s_dashboard_and_payment_parity(): void
    {
        $start = Carbon::parse('2026-11-21 16:35:00');
        $end = Carbon::parse('2026-11-21 19:47:00'); // Exactly 3h 12m 0s = 11520s
        Carbon::setTestNow($end);

        $tx = Transaction::create([
            'user_id' => $this->kasir->id,
            'device_id' => $this->tv1->id,
            'playstation_id' => $this->ps4->id,
            'tipe_transaksi' => 'postpaid',
            'harga' => 15000,
            'waktu_mulai' => '16:35:00',
            'started_at' => $start,
            'lost_time_start' => $start,
            'total' => 0,
            'status' => 'aktif',
            'payment_status' => 'unpaid',
            'status_transaksi' => 'berjalan',
            'billing_status' => Transaction::BILLING_RUNNING,
            'session_generation' => 1,
            'created_at' => $start,
        ]);

        $elapsedSeconds = $start->diffInSeconds($end);
        $this->assertEquals(11520, $elapsedSeconds);

        // 1. Dashboard JS estimation
        $jsEst = $this->computeJsEstimate(15000, $elapsedSeconds, $this->ps4->getAvailableHoursWithPrices());
        $this->assertEquals(48000, $jsEst);

        // 2. Payment Page (showPayment) while running
        $res = $this->actingAs($this->kasir)->get(route('transaction.showPayment', $tx->id_transaksi));
        $res->assertStatus(200);
        $this->assertEquals(48000, (float) $res->viewData('psCostDisplay'));
        $this->assertEquals(48000, (float) $res->viewData('payableSubtotal'));

        // 3. End transaction (endTransaction)
        $this->actingAs($this->kasir)->post(route('transaction.end', $tx->id_transaksi));
        $freshTx = $tx->fresh();
        $this->assertEquals(48000, (float) $freshTx->total);
        $this->assertEquals('3 jam 12 menit', $freshTx->jam_main);

        // 4. Parity check: Dashboard JS == Payment View == Final Settlement
        $this->assertEquals($jsEst, (float) $freshTx->total);
    }

    /**
     * Test Case 2: Duration 3 jam 12 menit 27 detik
     * Rate: Rp 15.000 / jam
     * Seconds elapsed: 11547s -> floor(11547/60) = 192 minutes
     * Calculation: 3 * 15.000 + 12 * 250 = 48.000 (diff 0 -> 48.000)
     */
    public function test_duration_3h_12m_27s_dashboard_and_payment_parity(): void
    {
        $start = Carbon::parse('2026-11-21 16:35:00');
        $end = Carbon::parse('2026-11-21 19:47:27'); // 3h 12m 27s = 11547s
        Carbon::setTestNow($end);

        $tx = Transaction::create([
            'user_id' => $this->kasir->id,
            'device_id' => $this->tv1->id,
            'playstation_id' => $this->ps4->id,
            'tipe_transaksi' => 'postpaid',
            'harga' => 15000,
            'waktu_mulai' => '16:35:00',
            'started_at' => $start,
            'lost_time_start' => $start,
            'total' => 0,
            'status' => 'aktif',
            'payment_status' => 'unpaid',
            'status_transaksi' => 'berjalan',
            'billing_status' => Transaction::BILLING_RUNNING,
            'session_generation' => 1,
            'created_at' => $start,
        ]);

        $elapsedSeconds = $start->diffInSeconds($end);
        $this->assertEquals(11547, $elapsedSeconds);

        // 1. Dashboard JS estimation
        $jsEst = $this->computeJsEstimate(15000, $elapsedSeconds, $this->ps4->getAvailableHoursWithPrices());
        $this->assertEquals(48000, $jsEst);

        // 2. Payment Page (showPayment)
        $res = $this->actingAs($this->kasir)->get(route('transaction.showPayment', $tx->id_transaksi));
        $res->assertStatus(200);
        $this->assertEquals(48000, (float) $res->viewData('psCostDisplay'));

        // 3. End transaction
        $this->actingAs($this->kasir)->post(route('transaction.end', $tx->id_transaksi));
        $freshTx = $tx->fresh();
        $this->assertEquals(48000, (float) $freshTx->total);
        $this->assertEquals('3 jam 12 menit', $freshTx->jam_main);

        // 4. Parity
        $this->assertEquals($jsEst, (float) $freshTx->total);
    }

    /**
     * Test Case 3: Duration 3 jam 13 menit 0 detik
     * Rate: Rp 15.000 / jam
     * Calculation: 3 * 15.000 + 13 * 250 = 48.250 (diff 250 <= 500 -> 48.500)
     */
    public function test_duration_3h_13m_0s_dashboard_and_payment_parity(): void
    {
        $start = Carbon::parse('2026-11-21 16:35:00');
        $end = Carbon::parse('2026-11-21 19:48:00'); // Exactly 3h 13m 0s = 11580s
        Carbon::setTestNow($end);

        $tx = Transaction::create([
            'user_id' => $this->kasir->id,
            'device_id' => $this->tv1->id,
            'playstation_id' => $this->ps4->id,
            'tipe_transaksi' => 'postpaid',
            'harga' => 15000,
            'waktu_mulai' => '16:35:00',
            'started_at' => $start,
            'lost_time_start' => $start,
            'total' => 0,
            'status' => 'aktif',
            'payment_status' => 'unpaid',
            'status_transaksi' => 'berjalan',
            'billing_status' => Transaction::BILLING_RUNNING,
            'session_generation' => 1,
            'created_at' => $start,
        ]);

        $elapsedSeconds = $start->diffInSeconds($end);
        $this->assertEquals(11580, $elapsedSeconds);

        // 1. Dashboard JS estimation
        $jsEst = $this->computeJsEstimate(15000, $elapsedSeconds, $this->ps4->getAvailableHoursWithPrices());
        $this->assertEquals(48500, $jsEst);

        // 2. Payment Page
        $res = $this->actingAs($this->kasir)->get(route('transaction.showPayment', $tx->id_transaksi));
        $res->assertStatus(200);
        $this->assertEquals(48500, (float) $res->viewData('psCostDisplay'));

        // 3. End transaction
        $this->actingAs($this->kasir)->post(route('transaction.end', $tx->id_transaksi));
        $freshTx = $tx->fresh();
        $this->assertEquals(48500, (float) $freshTx->total);
        $this->assertEquals('3 jam 13 menit', $freshTx->jam_main);

        // 4. Parity
        $this->assertEquals($jsEst, (float) $freshTx->total);
    }

    /**
     * Test Case 4: Duration 3 jam 14 menit 0 detik
     * Rate: Rp 15.000 / jam
     * Calculation: 3 * 15.000 + 14 * 250 = 48.500 (diff 500 <= 500 -> 48.500)
     */
    public function test_duration_3h_14m_0s_dashboard_and_payment_parity(): void
    {
        $start = Carbon::parse('2026-11-21 16:35:00');
        $end = Carbon::parse('2026-11-21 19:49:00'); // Exactly 3h 14m 0s = 11640s
        Carbon::setTestNow($end);

        $tx = Transaction::create([
            'user_id' => $this->kasir->id,
            'device_id' => $this->tv1->id,
            'playstation_id' => $this->ps4->id,
            'tipe_transaksi' => 'postpaid',
            'harga' => 15000,
            'waktu_mulai' => '16:35:00',
            'started_at' => $start,
            'lost_time_start' => $start,
            'total' => 0,
            'status' => 'aktif',
            'payment_status' => 'unpaid',
            'status_transaksi' => 'berjalan',
            'billing_status' => Transaction::BILLING_RUNNING,
            'session_generation' => 1,
            'created_at' => $start,
        ]);

        $elapsedSeconds = $start->diffInSeconds($end);
        $this->assertEquals(11640, $elapsedSeconds);

        // 1. Dashboard JS estimation
        $jsEst = $this->computeJsEstimate(15000, $elapsedSeconds, $this->ps4->getAvailableHoursWithPrices());
        $this->assertEquals(48500, $jsEst);

        // 2. Payment Page
        $res = $this->actingAs($this->kasir)->get(route('transaction.showPayment', $tx->id_transaksi));
        $res->assertStatus(200);
        $this->assertEquals(48500, (float) $res->viewData('psCostDisplay'));

        // 3. End transaction
        $this->actingAs($this->kasir)->post(route('transaction.end', $tx->id_transaksi));
        $freshTx = $tx->fresh();
        $this->assertEquals(48500, (float) $freshTx->total);
        $this->assertEquals('3 jam 14 menit', $freshTx->jam_main);

        // 4. Parity
        $this->assertEquals($jsEst, (float) $freshTx->total);
    }

    /**
     * Test Case 5: Minute and Hour boundary transitions (59m59s, 1h00m, 1h00m30s, 1h01m, 1h02m, 1h03m, 1h04m)
     */
    public function test_minute_and_hour_boundary_transitions_parity(): void
    {
        $start = Carbon::parse('2026-11-21 10:00:00');
        $packages = $this->ps4->getAvailableHoursWithPrices();

        $transitions = [
            ['name' => '59m 59s',  'sec' => 59 * 60 + 59,  'min' => 59,  'expected' => 15000],
            ['name' => '1h 00m 00s', 'sec' => 60 * 60,      'min' => 60,  'expected' => 15000],
            ['name' => '1h 00m 30s', 'sec' => 60 * 60 + 30, 'min' => 60,  'expected' => 15000],
            ['name' => '1h 01m 00s', 'sec' => 61 * 60,      'min' => 61,  'expected' => 15500],
            ['name' => '1h 02m 00s', 'sec' => 62 * 60,      'min' => 62,  'expected' => 15500],
            ['name' => '1h 03m 00s', 'sec' => 63 * 60,      'min' => 63,  'expected' => 16000],
            ['name' => '1h 04m 00s', 'sec' => 64 * 60,      'min' => 64,  'expected' => 16000],
        ];

        foreach ($transitions as $case) {
            $jsEst = $this->computeJsEstimate(15000, $case['sec'], $packages);
            $rawBackend = $this->billingService->calculateLostTimePrice($this->ps4, $case['min']);
            $backendCost = $this->billingService->applyLostTimeRounding($rawBackend);

            $this->assertEquals(
                $case['expected'],
                $backendCost,
                "Backend price for {$case['name']} must match expected {$case['expected']}"
            );

            $this->assertEquals(
                $backendCost,
                $jsEst,
                "Dashboard JS estimate must identically match backend cost for {$case['name']}"
            );
        }
    }

    /**
     * Test Case 6: Exact Reenactment of TV1 Station 4 (Tx 81 Scenario)
     * - Started at 16:35:08
     * - Checked out at 19:48:06 (elapsed 11578s = 192m 58s)
     * - Both Dashboard and Payment must yield Rp 48.000
     */
    public function test_tv1_station_4_scenario_at_exact_timestamps(): void
    {
        $start = Carbon::parse('2026-11-21 16:35:08');
        $end = Carbon::parse('2026-11-21 19:48:06'); // Elapsed = 11578s
        Carbon::setTestNow($end);

        $tx = Transaction::create([
            'user_id' => $this->kasir->id,
            'device_id' => $this->tv1->id,
            'playstation_id' => $this->ps4->id,
            'tipe_transaksi' => 'postpaid',
            'harga' => 15000,
            'waktu_mulai' => '16:35:00', // Legacy waktu_mulai string
            'started_at' => $start,
            'lost_time_start' => $start,
            'total' => 0,
            'status' => 'aktif',
            'payment_status' => 'unpaid',
            'status_transaksi' => 'berjalan',
            'billing_status' => Transaction::BILLING_RUNNING,
            'session_generation' => 1,
            'created_at' => $start,
        ]);

        $elapsedSeconds = $start->diffInSeconds($end);
        $this->assertEquals(11578, $elapsedSeconds);

        // 1. Dashboard JS estimation at 19:48:06
        $jsEst = $this->computeJsEstimate(15000, $elapsedSeconds, $this->ps4->getAvailableHoursWithPrices());
        $this->assertEquals(48000, $jsEst);

        // 2. End transaction (cashier clicks Selesai)
        $this->actingAs($this->kasir)->post(route('transaction.end', $tx->id_transaksi));
        $freshTx = $tx->fresh();

        $this->assertEquals(48000, (float) $freshTx->total, 'Final transaction total must be Rp 48.000, not Rp 48.500');
        $this->assertEquals('3 jam 12 menit', $freshTx->jam_main, 'Duration must be 3 jam 12 menit based on 192 full minutes');

        // 3. Payment page (showPayment)
        $res = $this->actingAs($this->kasir)->get(route('transaction.showPayment', $tx->id_transaksi));
        $res->assertStatus(200);
        $this->assertEquals(48000, (float) $res->viewData('psCostDisplay'));
        $this->assertEquals(48000, (float) $res->viewData('payableSubtotal'));

        // 4. Checkout via LostTimeBillingService
        $invoice = $this->lostTimeService->checkoutLostTime($freshTx->id_transaksi, [
            'payment_method' => 'tunai',
            'amount_paid' => 50000,
            'cashier_user_id' => $this->kasir->id,
        ]);

        $this->assertInstanceOf(Invoice::class, $invoice);
        $this->assertEquals(48000, (float) $invoice->final_total);
        $this->assertEquals(2000, (float) $invoice->change_amount);
        $this->assertEquals('paid', $freshTx->fresh()->payment_status);
    }
}
