<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Playstation;
use App\Models\Transaction;
use App\Models\CustomPackage;
use App\Models\Fnb;
use App\Models\User;
use App\Services\BillingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ServerSideBillingEngineTest extends TestCase
{
    use DatabaseTransactions;

    protected $adminUser;
    protected $kasirUser;
    protected $playstation;
    protected $device;
    protected $billingService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::create([
            'name' => 'Admin Test G2',
            'username' => 'admin_g2_' . uniqid(),
            'email' => 'admin_g2_' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);

        $this->kasirUser = User::create([
            'name' => 'Kasir Test G2',
            'username' => 'kasir_g2_' . uniqid(),
            'email' => 'kasir_g2_' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'role' => 'kasir',
        ]);

        $this->playstation = Playstation::create([
            'nama' => 'PS5 Pro Station',
            'harga' => 10000,
        ]);

        $this->device = Device::create([
            'nama' => 'Station G2-01',
            'status' => 'Tersedia',
            'playstation_id' => $this->playstation->id,
        ]);
        $this->device->playstations()->attach($this->playstation->id);

        $this->billingService = app(BillingService::class);
    }

    public function test_prepaid_session_starts_with_absolute_server_timestamps_and_occupies_station()
    {
        $startTime = Carbon::create(2026, 11, 4, 14, 0, 0);
        Carbon::setTestNow($startTime);

        $tx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 2,
            'nama' => 'Customer Alpha',
        ], $this->kasirUser->id);

        $this->assertEquals(Transaction::BILLING_RUNNING, $tx->billing_status);
        $this->assertEquals('14:00', $tx->waktu_mulai);
        $this->assertEquals('16:00', $tx->waktu_Selesai);
        $this->assertEquals('2026-11-04 14:00:00', $tx->started_at->toDateTimeString());
        $this->assertEquals('2026-11-04 16:00:00', $tx->expires_at->toDateTimeString());
        $this->assertNull($tx->ended_at);
        $this->assertEquals('unpaid', $tx->payment_status);

        // Station is marked as Digunakan
        $this->device->refresh();
        $this->assertEquals('Digunakan', $this->device->status);

        Carbon::setTestNow();
    }

    public function test_prepaid_session_expires_via_worker_and_releases_station()
    {
        $startTime = Carbon::create(2026, 11, 4, 14, 0, 0);
        Carbon::setTestNow($startTime);

        $tx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
            'nama' => 'Customer Expirable',
        ], $this->kasirUser->id);

        // Before expiration: worker evaluates at 14:30 => session stays RUNNING
        Carbon::setTestNow($startTime->copy()->addMinutes(30));
        $evalBefore = $this->billingService->evaluateExpiration();
        $this->assertEquals(0, $evalBefore['count']);
        $tx->refresh();
        $this->assertEquals(Transaction::BILLING_RUNNING, $tx->billing_status);
        $this->device->refresh();
        $this->assertEquals('Digunakan', $this->device->status);

        // At expiration: worker evaluates at 15:01 => session transitioned and station freed
        Carbon::setTestNow($startTime->copy()->addMinutes(61));
        $evalAfter = $this->billingService->evaluateExpiration();
        $this->assertEquals(1, $evalAfter['count']);

        $tx->refresh();
        $this->assertEquals(Transaction::BILLING_EXPIRED_PENDING_ACTION, $tx->billing_status);
        $this->assertEquals('selesai', $tx->status_transaksi);
        $this->assertNotNull($tx->ended_at);

        // Device is freed to Tersedia
        $this->device->refresh();
        $this->assertEquals('Tersedia', $this->device->status);

        Carbon::setTestNow();
    }

    public function test_prepaid_session_can_be_extended_atomically()
    {
        $startTime = Carbon::create(2026, 11, 4, 14, 0, 0);
        Carbon::setTestNow($startTime);

        $tx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
            'nama' => 'Customer Extended',
        ], $this->kasirUser->id);

        $this->assertEquals('2026-11-04 15:00:00', $tx->expires_at->toDateTimeString());
        $this->assertEquals(10000, $tx->total);

        // Extend by 2 hours
        $extendedTx = $this->billingService->extendSession($tx->id_transaksi, 2);

        $this->assertEquals('2026-11-04 17:00:00', $extendedTx->expires_at->toDateTimeString());
        $this->assertEquals('17:00', $extendedTx->waktu_Selesai);
        $this->assertEquals(3, $extendedTx->jam_main);
        $this->assertEquals(30000, $extendedTx->total);

        Carbon::setTestNow();
    }

    public function test_prepaid_session_stopped_early_records_status_and_frees_station()
    {
        $startTime = Carbon::create(2026, 11, 4, 14, 0, 0);
        Carbon::setTestNow($startTime);

        $tx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 2,
            'nama' => 'Customer Early Stop',
        ], $this->kasirUser->id);

        // Stop after 30 minutes
        Carbon::setTestNow($startTime->copy()->addMinutes(30));
        $stoppedTx = $this->billingService->stopSession($tx->id_transaksi, 'customer_requested');

        $this->assertEquals(Transaction::BILLING_STOPPED_EARLY, $stoppedTx->billing_status);
        $this->assertEquals('selesai', $stoppedTx->status_transaksi);
        $this->assertEquals('14:30', $stoppedTx->waktu_Selesai);
        $this->assertEquals('2026-11-04 14:30:00', $stoppedTx->ended_at->toDateTimeString());

        $this->device->refresh();
        $this->assertEquals('Tersedia', $this->device->status);

        Carbon::setTestNow();
    }

    public function test_concurrent_starts_on_same_station_prevented_by_pessimistic_lock()
    {
        // First start succeeds
        $tx1 = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
            'nama' => 'Customer 1',
        ], $this->kasirUser->id);

        $this->assertNotNull($tx1);
        $this->device->refresh();
        $this->assertEquals('Digunakan', $this->device->status);

        // Second start attempt on the same station MUST throw RuntimeException
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Perangkat "Station G2-01" sedang tidak tersedia');

        $this->billingService->startSession([
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
            'nama' => 'Customer 2',
        ], $this->kasirUser->id);
    }

    public function test_postpaid_lost_time_lifecycle_and_duration_calculation()
    {
        $startTime = Carbon::create(2026, 11, 4, 14, 0, 0);
        Carbon::setTestNow($startTime);

        $tx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'postpaid',
            'nama' => 'Customer Postpaid',
        ], $this->kasirUser->id);

        $this->assertEquals(Transaction::BILLING_RUNNING, $tx->billing_status);
        $this->assertNull($tx->expires_at);
        $this->assertNull($tx->waktu_Selesai);
        $this->assertEquals('berjalan', $tx->status_transaksi);

        // Worker should NOT expire postpaid sessions!
        Carbon::setTestNow($startTime->copy()->addHours(5));
        $workerResult = $this->billingService->evaluateExpiration();
        $this->assertEquals(0, $workerResult['count']);
        $tx->refresh();
        $this->assertEquals(Transaction::BILLING_RUNNING, $tx->billing_status);

        // Cashier stops postpaid after 2 hours 15 minutes (135 minutes)
        // Rate: 10,000/hr = 2 hours (20,000) + 15 mins (2,500) = 22,500 => rounding to 22,500
        Carbon::setTestNow($startTime->copy()->addMinutes(135));
        $stoppedTx = $this->billingService->stopSession($tx->id_transaksi);

        $this->assertEquals(Transaction::BILLING_COMPLETED, $stoppedTx->billing_status);
        $this->assertEquals('selesai', $stoppedTx->status_transaksi);
        $this->assertEquals('2 jam 15 menit', $stoppedTx->jam_main);
        $this->assertEquals(22500, $stoppedTx->total);

        $this->device->refresh();
        $this->assertEquals('Tersedia', $this->device->status);

        Carbon::setTestNow();
    }

    public function test_postpaid_across_midnight_calculates_correct_duration()
    {
        // Started at 23:15 on Nov 4
        $startTime = Carbon::create(2026, 11, 4, 23, 15, 0);
        Carbon::setTestNow($startTime);

        $tx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'postpaid',
            'nama' => 'Midnight Gamer',
        ], $this->kasirUser->id);

        // Ended at 01:45 on Nov 5 (2 hours 30 mins = 150 minutes across midnight)
        $endTime = Carbon::create(2026, 11, 5, 1, 45, 0);
        Carbon::setTestNow($endTime);

        $stoppedTx = $this->billingService->stopSession($tx->id_transaksi);

        $this->assertEquals('2 jam 30 menit', $stoppedTx->jam_main);
        // 2 hrs (20,000) + 30 mins (5,000) = 25,000
        $this->assertEquals(25000, $stoppedTx->total);
        $this->assertEquals(Transaction::BILLING_COMPLETED, $stoppedTx->billing_status);

        Carbon::setTestNow();
    }

    public function test_custom_package_session_lifecycle()
    {
        $package = CustomPackage::create([
            'nama_paket' => 'Paket Begadang G2',
            'harga_total' => 45000,
            'is_active' => true,
        ]);
        $package->playstations()->attach($this->playstation->id, ['lama_main' => 180]); // 3 hours

        $startTime = Carbon::create(2026, 11, 4, 18, 0, 0);
        Carbon::setTestNow($startTime);

        $tx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'custom_package',
            'custom_package_id' => $package->id,
            'nama' => 'Package Customer',
        ], $this->kasirUser->id);

        $this->assertEquals(Transaction::BILLING_RUNNING, $tx->billing_status);
        $this->assertEquals('2026-11-04 21:00:00', $tx->expires_at->toDateTimeString());
        $this->assertEquals(45000, $tx->total);
        $this->assertEquals(180, $tx->jam_main);

        // Fast forward past expiration
        Carbon::setTestNow($startTime->copy()->addMinutes(185));
        $evalResult = $this->billingService->evaluateExpiration();
        $this->assertEquals(1, $evalResult['count']);

        $tx->refresh();
        $this->assertEquals(Transaction::BILLING_EXPIRED_PENDING_ACTION, $tx->billing_status);
        $this->device->refresh();
        $this->assertEquals('Tersedia', $this->device->status);

        Carbon::setTestNow();
    }

    public function test_idempotent_worker_and_concurrency_protection()
    {
        $startTime = Carbon::create(2026, 11, 4, 10, 0, 0);
        Carbon::setTestNow($startTime);

        $tx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
            'nama' => 'Idempotency Customer',
        ], $this->kasirUser->id);

        // Move past expiration
        Carbon::setTestNow($startTime->copy()->addMinutes(65));

        // First worker evaluation processes the session
        $run1 = $this->billingService->evaluateExpiration();
        $this->assertEquals(1, $run1['count']);

        // Second immediate worker evaluation processes 0 (idempotency verified!)
        $run2 = $this->billingService->evaluateExpiration();
        $this->assertEquals(0, $run2['count']);

        Carbon::setTestNow();
    }

    public function test_crash_recovery_reconciliation_command()
    {
        $startTime = Carbon::create(2026, 11, 4, 10, 0, 0);
        Carbon::setTestNow($startTime);

        // Station 1: Prepaid that expired while system was shut down
        $txExpired = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
            'nama' => 'Overdue Customer',
        ], $this->kasirUser->id);

        // Create Station 2 for Postpaid
        $device2 = Device::create([
            'nama' => 'Station G2-02',
            'status' => 'Tersedia',
            'playstation_id' => $this->playstation->id,
        ]);
        $device2->playstations()->attach($this->playstation->id);

        $txPostpaid = $this->billingService->startSession([
            'device_id' => $device2->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'postpaid',
            'nama' => 'Postpaid Survived',
        ], $this->kasirUser->id);

        // System was turned off and boots up 3 hours later at 13:00
        $recoveryTime = Carbon::create(2026, 11, 4, 13, 0, 0);
        Carbon::setTestNow($recoveryTime);

        // Run artisan billing:reconcile
        $this->artisan('billing:reconcile')
            ->expectsOutput('Starting Billing & Station Reconciliation...')
            ->expectsOutput('Reconciliation completed successfully.')
            ->assertExitCode(0);

        // Check Station 1: expired prepaid reconciled, device freed
        $txExpired->refresh();
        $this->assertEquals(Transaction::BILLING_EXPIRED_PENDING_ACTION, $txExpired->billing_status);
        $this->device->refresh();
        $this->assertEquals('Tersedia', $this->device->status);

        // Check Station 2: postpaid survived restart without premature cancellation
        $txPostpaid->refresh();
        $this->assertEquals(Transaction::BILLING_RUNNING, $txPostpaid->billing_status);
        $device2->refresh();
        $this->assertEquals('Digunakan', $device2->status);

        Carbon::setTestNow();
    }

    public function test_payment_does_not_prematurely_free_station_while_session_is_running()
    {
        $startTime = Carbon::create(2026, 11, 4, 14, 0, 0);
        Carbon::setTestNow($startTime);

        // Create prepaid transaction with upfront payment via HTTP request
        $response = $this->actingAs($this->kasirUser)->post(route('transaction.store'), [
            'nama' => 'Upfront Payer',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 2,
            'harga' => 10000,
            'total' => 20000,
            'action' => 'bayar',
        ]);

        $tx = Transaction::latest('id_transaksi')->first();
        $this->assertNotNull($tx);
        $this->assertEquals(Transaction::BILLING_RUNNING, $tx->billing_status);
        $this->assertEquals('Digunakan', $this->device->fresh()->status);

        // Cashier completes payment immediately at 14:05 (session expires at 16:00)
        Carbon::setTestNow($startTime->copy()->addMinutes(5));
        $payResponse = $this->actingAs($this->kasirUser)->post(route('transaction.processPayment', $tx->id_transaksi), [
            'payment_method' => 'tunai',
            'amount_paid' => $tx->total,
        ]);

        $tx->refresh();
        $this->assertEquals('paid', $tx->payment_status);
        $this->assertEquals(Transaction::BILLING_RUNNING, $tx->billing_status);

        // Station MUST REMAIN Digunakan because the 2-hour rental is still in progress!
        $this->device->refresh();
        $this->assertEquals('Digunakan', $this->device->status);

        // Now fast forward to 16:05 (after expiration)
        Carbon::setTestNow($startTime->copy()->addMinutes(125));
        $this->billingService->evaluateExpiration();

        $tx->refresh();
        $this->assertEquals(Transaction::BILLING_EXPIRED_PENDING_ACTION, $tx->billing_status);
        $this->device->refresh();
        $this->assertEquals('Tersedia', $this->device->status);

        Carbon::setTestNow();
    }

    public function test_frontend_timer_synchronization_endpoint()
    {
        $startTime = Carbon::create(2026, 11, 4, 14, 0, 0);
        Carbon::setTestNow($startTime);

        $tx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
            'nama' => 'API Timer Test',
        ], $this->kasirUser->id);

        $response = $this->actingAs($this->kasirUser)
            ->getJson(route('device.timers'));

        $response->assertStatus(200)
            ->assertJsonStructure([
                'server_now',
                'server_timestamp',
                'devices' => [
                    '*' => [
                        'id',
                        'nama',
                        'status',
                        'transaction' => [
                            'id_transaksi',
                            'nama',
                            'tipe_transaksi',
                            'billing_status',
                            'started_at',
                            'expires_at',
                            'remaining_seconds',
                            'elapsed_seconds',
                            'is_expired',
                        ]
                    ]
                ]
            ]);

        $data = $response->json();
        $this->assertEquals(3600, $data['devices'][0]['transaction']['remaining_seconds']);
        $this->assertFalse($data['devices'][0]['transaction']['is_expired']);

        Carbon::setTestNow();
    }

    public function test_historical_transactions_are_not_treated_as_active_sessions()
    {
        // Create an old finished transaction from yesterday with null or COMPLETED billing_status
        $oldTx = Transaction::create([
            'status' => 'user',
            'nama' => 'Past Customer',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'user_id' => $this->kasirUser->id,
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 2,
            'waktu_mulai' => '10:00',
            'waktu_Selesai' => '12:00',
            'started_at' => Carbon::yesterday()->setTime(10, 0),
            'expires_at' => Carbon::yesterday()->setTime(12, 0),
            'ended_at' => Carbon::yesterday()->setTime(12, 0),
            'status_transaksi' => 'selesai',
            'payment_status' => 'paid',
            'billing_status' => Transaction::BILLING_COMPLETED,
            'harga' => 10000,
            'total' => 20000,
        ]);

        // Device should be available
        $this->device->update(['status' => 'Tersedia']);
        $this->device->refresh();
        $this->assertEquals('Tersedia', $this->device->status);

        // Active billing count must be 0
        $this->assertEquals(0, Transaction::where('device_id', $this->device->id)->activeBilling()->count());

        // Worker evaluation must not touch old completed transaction
        $evalResult = $this->billingService->evaluateExpiration(Carbon::now());
        $this->assertEquals(0, $evalResult['count']);

        // Cashier can start a new session on this station without being blocked
        $newTx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
            'nama' => 'Current Customer',
        ], $this->kasirUser->id);

        $this->assertNotNull($newTx);
        $this->assertEquals(Transaction::BILLING_RUNNING, $newTx->billing_status);
        $this->assertEquals(1, Transaction::where('device_id', $this->device->id)->activeBilling()->count());
    }

    public function test_worker_and_scheduler_safe_from_double_processing()
    {
        $startTime = Carbon::create(2026, 11, 4, 10, 0, 0);
        Carbon::setTestNow($startTime);

        $tx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
            'nama' => 'Concurrent Worker Test',
        ], $this->kasirUser->id);

        // Move time past expiration
        $evalTime = $startTime->copy()->addMinutes(65);
        Carbon::setTestNow($evalTime);

        // Simulate Worker 1 and Worker 2 running concurrently
        $worker1Result = $this->billingService->evaluateExpiration($evalTime);
        $worker2Result = $this->billingService->evaluateExpiration($evalTime);

        // Exactly one worker processed the expiration
        $this->assertEquals(1, $worker1Result['count']);
        $this->assertEquals(0, $worker2Result['count']);

        $tx->refresh();
        $this->assertEquals(Transaction::BILLING_EXPIRED_PENDING_ACTION, $tx->billing_status);
        $this->assertEquals('selesai', $tx->status_transaksi);
        $this->assertEquals('Tersedia', $this->device->fresh()->status);

        Carbon::setTestNow();
    }

    public function test_billing_worker_and_reconcile_commands_exit_zero_on_success()
    {
        $this->artisan('billing:worker', ['--once' => true])
            ->assertExitCode(0);

        $this->artisan('billing:reconcile')
            ->assertExitCode(0);
    }

    public function test_billing_worker_and_reconcile_fail_fast_when_schema_incomplete()
    {
        Transaction::$mockMissingColumns = ['billing_status'];
        Transaction::hasG2Schema(true);

        try {
            $this->artisan('billing:worker', ['--once' => true])
                ->expectsOutput('Skema G2 tidak lengkap pada database. Kolom berikut tidak ditemukan: billing_status')
                ->assertExitCode(1);

            $this->artisan('billing:reconcile')
                ->expectsOutput('Skema G2 tidak lengkap pada database. Kolom berikut tidak ditemukan: billing_status')
                ->assertExitCode(1);
        } finally {
            Transaction::$mockMissingColumns = null;
            Transaction::hasG2Schema(true);
        }
    }

    /**
     * Helper to execute the exact G2 migration backfill logic on the test database.
     */
    protected function executeMigrationBackfill(): void
    {
        // A. Transaksi yang secara eksplisit sudah selesai -> COMPLETED
        \DB::statement("
            UPDATE transactions 
            SET billing_status = 'COMPLETED'
            WHERE billing_status IS NULL 
              AND status_transaksi = 'selesai'
        ");

        // B. Rekonsiliasi transaksi legacy yang berpotensi masih berjalan pada station 'Digunakan'
        $occupiedDevices = \DB::table('devices')->where('status', 'Digunakan')->get();

        foreach ($occupiedDevices as $device) {
            $latestTx = \DB::table('transactions')
                ->where('device_id', $device->id)
                ->where('status_transaksi', 'berjalan')
                ->whereNull('billing_status')
                ->orderByDesc('id_transaksi')
                ->first();

            if (!$latestTx) {
                continue;
            }

            $startedAt = null;
            if (!empty($latestTx->started_at)) {
                $startedAt = Carbon::parse($latestTx->started_at);
            } elseif (!empty($latestTx->lost_time_start)) {
                $startedAt = Carbon::parse($latestTx->lost_time_start);
            } elseif (!empty($latestTx->waktu_mulai) && !empty($latestTx->created_at)) {
                try {
                    $createdDate = Carbon::parse($latestTx->created_at)->toDateString();
                    $startedAt = Carbon::parse($createdDate . ' ' . $latestTx->waktu_mulai);
                } catch (\Throwable $e) {
                    $startedAt = null;
                }
            } elseif (!empty($latestTx->created_at)) {
                $startedAt = Carbon::parse($latestTx->created_at);
            }

            if (!$startedAt) {
                continue;
            }

            $tipeTransaksi = $latestTx->tipe_transaksi;
            $updatePayload = [];

            if ($tipeTransaksi === 'postpaid') {
                $updatePayload = [
                    'started_at' => $startedAt,
                    'expires_at' => null,
                    'billing_status' => 'RUNNING',
                ];
            } else {
                $expiresAt = null;

                if (!empty($latestTx->expires_at)) {
                    $expiresAt = Carbon::parse($latestTx->expires_at);
                } elseif (is_numeric($latestTx->jam_main) && (float) $latestTx->jam_main > 0) {
                    $jamMain = (float) $latestTx->jam_main;
                    $minutes = ($jamMain <= 24) ? ($jamMain * 60) : $jamMain;
                    $expiresAt = $startedAt->copy()->addMinutes(round($minutes));
                } elseif (!empty($latestTx->waktu_Selesai)) {
                    try {
                        $createdDate = $startedAt->toDateString();
                        $tentativeExpires = Carbon::parse($createdDate . ' ' . $latestTx->waktu_Selesai);
                        if ($tentativeExpires->lt($startedAt)) {
                            $tentativeExpires->addDay();
                        }
                        $expiresAt = $tentativeExpires;
                    } catch (\Throwable $e) {
                        $expiresAt = null;
                    }
                }

                if (!$expiresAt) {
                    continue;
                }

                $updatePayload['started_at'] = $startedAt;
                $updatePayload['expires_at'] = $expiresAt;

                if ($expiresAt->isPast()) {
                    $updatePayload['billing_status'] = 'EXPIRED_PENDING_ACTION';
                    $updatePayload['ended_at'] = $expiresAt;
                } else {
                    $updatePayload['billing_status'] = 'RUNNING';
                }
            }

            if (!empty($updatePayload)) {
                \DB::table('transactions')
                    ->where('id_transaksi', $latestTx->id_transaksi)
                    ->update($updatePayload);
            }
        }
    }

    public function test_migration_backfill_lost_time_across_midnight()
    {
        $yesterday = Carbon::now()->subDay()->setTime(23, 30);
        $this->device->update(['status' => 'Digunakan']);

        $tx = Transaction::create([
            'status' => 'user',
            'device_id' => $this->device->id,
            'nama' => 'Midnight Postpaid Gamer',
            'tipe_transaksi' => 'postpaid',
            'jam_main' => null,
            'harga' => 10000,
            'total' => 0,
            'status_transaksi' => 'berjalan',
            'payment_status' => 'unpaid',
            'waktu_mulai' => '23:30',
            'lost_time_start' => $yesterday,
            'billing_status' => null,
        ]);
        \DB::table('transactions')->where('id_transaksi', $tx->id_transaksi)->update(['created_at' => $yesterday]);

        $this->executeMigrationBackfill();

        $txFresh = $tx->fresh();
        $this->assertEquals(Transaction::BILLING_RUNNING, $txFresh->billing_status);
        $this->assertNull($txFresh->expires_at);
        $this->assertNotNull($txFresh->started_at);
        $this->assertEquals('unpaid', $txFresh->payment_status);
        $this->assertEquals('Digunakan', $this->device->fresh()->status);
    }

    public function test_migration_backfill_prepaid_across_midnight()
    {
        $baseDate = Carbon::create(2026, 11, 4, 23, 30, 0);
        $evalTime = Carbon::create(2026, 11, 5, 0, 30, 0);
        Carbon::setTestNow($evalTime);

        $station = Device::create([
            'nama' => 'Midnight Prepaid Station',
            'status' => 'Digunakan',
            'playstation_id' => $this->playstation->id,
        ]);

        $tx = Transaction::create([
            'status' => 'user',
            'device_id' => $station->id,
            'nama' => 'Midnight Prepaid Gamer',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 2,
            'harga' => 10000,
            'total' => 20000,
            'status_transaksi' => 'berjalan',
            'payment_status' => 'paid',
            'waktu_mulai' => '23:30',
            'waktu_Selesai' => '01:30',
            'started_at' => $baseDate,
            'expires_at' => null,
            'billing_status' => null,
            'created_at' => $baseDate,
        ]);

        $this->executeMigrationBackfill();

        $txFresh = $tx->fresh();
        $this->assertEquals(Transaction::BILLING_RUNNING, $txFresh->billing_status);
        $this->assertNotNull($txFresh->expires_at);
        $this->assertTrue($txFresh->expires_at->gt($evalTime));
        $this->assertEquals('paid', $txFresh->payment_status);
        $this->assertEquals('Digunakan', $station->fresh()->status);

        Carbon::setTestNow();
    }

    public function test_migration_backfill_prepaid_legacy_without_expires_at()
    {
        $now = Carbon::now();
        $station = Device::create([
            'nama' => 'Legacy Station Without Expires',
            'status' => 'Digunakan',
            'playstation_id' => $this->playstation->id,
        ]);

        $tx = Transaction::create([
            'status' => 'user',
            'device_id' => $station->id,
            'nama' => 'Legacy Gamer',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 2,
            'harga' => 10000,
            'total' => 20000,
            'status_transaksi' => 'berjalan',
            'payment_status' => 'unpaid',
            'waktu_mulai' => $now->copy()->subMinutes(30)->format('H:i'),
            'started_at' => $now->copy()->subMinutes(30),
            'expires_at' => null,
            'billing_status' => null,
            'created_at' => $now->copy()->subMinutes(30),
        ]);

        $this->executeMigrationBackfill();

        $txFresh = $tx->fresh();
        $this->assertEquals(Transaction::BILLING_RUNNING, $txFresh->billing_status);
        $this->assertNotNull($txFresh->expires_at);
        $this->assertTrue($txFresh->expires_at->isFuture());
    }

    public function test_migration_backfill_ambiguous_historical_transaction_remains_null()
    {
        $threeDaysAgo = Carbon::now()->subDays(3);
        $freeStation = Device::create([
            'nama' => 'Ambiguous Station',
            'status' => 'Tersedia',
            'playstation_id' => $this->playstation->id,
        ]);

        $txAmbiguous = Transaction::create([
            'status' => 'user',
            'device_id' => $freeStation->id,
            'nama' => 'Old Forgotten Customer',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => null,
            'harga' => 10000,
            'total' => 10000,
            'status_transaksi' => 'berjalan',
            'payment_status' => 'unpaid',
            'waktu_mulai' => '10:00',
            'billing_status' => null,
        ]);
        \DB::table('transactions')->where('id_transaksi', $txAmbiguous->id_transaksi)->update(['created_at' => $threeDaysAgo]);

        $this->executeMigrationBackfill();

        $txFresh = $txAmbiguous->fresh();
        $this->assertNull($txFresh->billing_status);
    }

    public function test_migration_backfill_valid_completed_transaction()
    {
        $freeStation = Device::create([
            'nama' => 'Completed Station',
            'status' => 'Tersedia',
            'playstation_id' => $this->playstation->id,
        ]);

        $txFinished = Transaction::create([
            'status' => 'user',
            'device_id' => $freeStation->id,
            'nama' => 'Legit Finished Customer',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
            'harga' => 10000,
            'total' => 10000,
            'status_transaksi' => 'selesai',
            'payment_status' => 'paid',
            'waktu_mulai' => '10:00',
            'waktu_Selesai' => '11:00',
            'billing_status' => null,
        ]);

        $this->executeMigrationBackfill();

        $this->assertEquals(Transaction::BILLING_COMPLETED, $txFinished->fresh()->billing_status);
    }

    public function test_migration_backfill_multiple_legacy_transactions_on_single_station()
    {
        $sharedStation = Device::create([
            'nama' => 'Contested Station',
            'status' => 'Digunakan',
            'playstation_id' => $this->playstation->id,
        ]);

        $yesterday = Carbon::now()->subDay();

        $txOlder = Transaction::create([
            'status' => 'user',
            'device_id' => $sharedStation->id,
            'nama' => 'Older Unclosed Session',
            'tipe_transaksi' => 'postpaid',
            'jam_main' => null,
            'harga' => 10000,
            'total' => 0,
            'status_transaksi' => 'berjalan',
            'payment_status' => 'unpaid',
            'waktu_mulai' => '10:00',
            'billing_status' => null,
        ]);
        \DB::table('transactions')->where('id_transaksi', $txOlder->id_transaksi)->update(['created_at' => $yesterday]);

        $txLatest = Transaction::create([
            'status' => 'user',
            'device_id' => $sharedStation->id,
            'nama' => 'Current Active Session',
            'tipe_transaksi' => 'postpaid',
            'jam_main' => null,
            'harga' => 10000,
            'total' => 0,
            'status_transaksi' => 'berjalan',
            'payment_status' => 'unpaid',
            'waktu_mulai' => Carbon::now()->subMinutes(15)->format('H:i'),
            'lost_time_start' => Carbon::now()->subMinutes(15),
            'billing_status' => null,
            'created_at' => Carbon::now()->subMinutes(15),
        ]);

        $this->executeMigrationBackfill();

        $this->assertEquals(Transaction::BILLING_RUNNING, $txLatest->fresh()->billing_status);
        $this->assertNull($txOlder->fresh()->billing_status);
    }

    public function test_billing_status_lifecycle_constants_integrity()
    {
        $this->assertEquals('IDLE', Transaction::BILLING_IDLE);
        $this->assertEquals('RUNNING', Transaction::BILLING_RUNNING);
        $this->assertEquals('EXPIRED_PENDING_ACTION', Transaction::BILLING_EXPIRED_PENDING_ACTION);
        $this->assertEquals('STOPPED_EARLY', Transaction::BILLING_STOPPED_EARLY);
        $this->assertEquals('COMPLETED', Transaction::BILLING_COMPLETED);

        // 1. Start session -> RUNNING
        $tx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 2,
            'nama' => 'Lifecycle Tester',
        ], $this->kasirUser->id);
        $this->assertEquals(Transaction::BILLING_RUNNING, $tx->billing_status);

        // 2. Stop early before expiration -> STOPPED_EARLY
        $stoppedTx = $this->billingService->stopSession($tx->id_transaksi, 'early_stop');
        $this->assertEquals(Transaction::BILLING_STOPPED_EARLY, $stoppedTx->billing_status);

        // 3. Postpaid session stopped -> COMPLETED
        $postpaidTx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'postpaid',
            'nama' => 'Postpaid Lifecycle',
        ], $this->kasirUser->id);
        $stoppedPostpaid = $this->billingService->stopSession($postpaidTx->id_transaksi, 'cashier_stop');
        $this->assertEquals(Transaction::BILLING_COMPLETED, $stoppedPostpaid->billing_status);

        // 4. Prepaid expired naturally -> EXPIRED_PENDING_ACTION
        $prepaidTx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
            'nama' => 'Expire Lifecycle',
        ], $this->kasirUser->id);
        Carbon::setTestNow(Carbon::now()->addMinutes(70));
        $this->billingService->evaluateExpiration(Carbon::now());
        $this->assertEquals(Transaction::BILLING_EXPIRED_PENDING_ACTION, $prepaidTx->fresh()->billing_status);
        Carbon::setTestNow();
    }
}
