<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\HourlyPrice;
use App\Models\Playstation;
use App\Models\Transaction;
use App\Models\CustomPackage;
use App\Models\Fnb;
use App\Models\User;
use App\Models\AutomationIntent;
use App\Services\BillingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DurationPrecisionBillingFeatureTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;
    protected User $kasir;
    protected Playstation $playstation;
    protected Device $device;
    protected BillingService $billingService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::firstOrCreate(
            ['email' => 'admin_duration_test@sugengrawuh.com'],
            ['name' => 'Admin Duration Test', 'password' => bcrypt('password'), 'role' => 'admin']
        );

        $this->kasir = User::firstOrCreate(
            ['email' => 'kasir_duration_test@sugengrawuh.com'],
            ['name' => 'Kasir Duration Test', 'password' => bcrypt('password'), 'role' => 'kasir']
        );

        $this->playstation = Playstation::create([
            'nama' => 'PS5 PRO DURATION',
            'harga' => '30000',
        ]);

        $this->device = Device::create([
            'nama' => 'PS5-DUR-01',
            'status' => 'Tersedia',
            'playstation_id' => $this->playstation->id,
            'session_generation' => 1,
        ]);
        $this->device->playstations()->attach($this->playstation->id);

        $this->billingService = app(BillingService::class);
    }

    /**
     * 1. Test Admin can create HourlyPrice with Hours, Minutes, Seconds.
     */
    public function test_admin_can_create_hourly_price_with_hours_minutes_seconds()
    {
        $response = $this->actingAs($this->admin)->post(
            route('hourly-prices.hourly-prices.store', $this->playstation->id),
            [
                'hours' => 0,
                'minutes' => 30,
                'seconds' => 0,
                'price' => 12000,
            ]
        );

        $response->assertRedirect(route('hourly-prices.hourly-prices.index', $this->playstation->id));
        $this->assertDatabaseHas('hourly_prices', [
            'playstation_id' => $this->playstation->id,
            'duration_seconds' => 1800,
            'price' => 12000,
        ]);
    }

    /**
     * 2. Test Admin can update HourlyPrice with Hours, Minutes, Seconds.
     */
    public function test_admin_can_update_hourly_price_with_hours_minutes_seconds()
    {
        $preset = HourlyPrice::create([
            'playstation_id' => $this->playstation->id,
            'duration_seconds' => 1800,
            'price' => 12000,
        ]);

        $response = $this->actingAs($this->admin)->put(
            route('hourly-prices.hourly-prices.update', [$this->playstation->id, $preset->id]),
            [
                'hours' => 1,
                'minutes' => 15,
                'seconds' => 30,
                'price' => 35000,
            ]
        );

        $response->assertRedirect(route('hourly-prices.hourly-prices.index', $this->playstation->id));
        $this->assertDatabaseHas('hourly_prices', [
            'id' => $preset->id,
            'duration_seconds' => 4530,
            'price' => 35000,
        ]);
    }

    /**
     * 3. Test HourlyPrice input validation (zero, invalid minute, invalid second, negative, overflow).
     */
    public function test_hourly_price_validation_rules()
    {
        // 00:00:00 rejected
        $resZero = $this->actingAs($this->admin)->post(
            route('hourly-prices.hourly-prices.store', $this->playstation->id),
            ['hours' => 0, 'minutes' => 0, 'seconds' => 0, 'price' => 10000]
        );
        $resZero->assertSessionHasErrors('total_seconds');

        // Minute 60 rejected
        $resMin60 = $this->actingAs($this->admin)->post(
            route('hourly-prices.hourly-prices.store', $this->playstation->id),
            ['hours' => 1, 'minutes' => 60, 'seconds' => 0, 'price' => 10000]
        );
        $resMin60->assertSessionHasErrors('minutes');

        // Second 60 rejected
        $resSec60 = $this->actingAs($this->admin)->post(
            route('hourly-prices.hourly-prices.store', $this->playstation->id),
            ['hours' => 1, 'minutes' => 0, 'seconds' => 60, 'price' => 10000]
        );
        $resSec60->assertSessionHasErrors('seconds');

        // Negative rejected
        $resNeg = $this->actingAs($this->admin)->post(
            route('hourly-prices.hourly-prices.store', $this->playstation->id),
            ['hours' => -1, 'minutes' => 0, 'seconds' => 0, 'price' => 10000]
        );
        $resNeg->assertSessionHasErrors('hours');

        // 00:00:01 accepted
        $resOneSec = $this->actingAs($this->admin)->post(
            route('hourly-prices.hourly-prices.store', $this->playstation->id),
            ['hours' => 0, 'minutes' => 0, 'seconds' => 1, 'price' => 500]
        );
        $resOneSec->assertSessionHasNoErrors();
    }

    /**
     * 4. Req 1: Preset tepat menggunakan harga yang disimpan ADMIN (prioritas tertinggi).
     */
    public function test_req_1_preset_exact_match_prioritized_over_prorata()
    {
        // Admin creates preset 00:30:00 (1800s) = Rp 12.000 (though base 30k prorata would be 15k)
        HourlyPrice::create([
            'playstation_id' => $this->playstation->id,
            'duration_seconds' => 1800,
            'price' => 12000,
        ]);

        $tx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'tipe_transaksi' => 'prepaid',
            'duration_seconds' => 1800,
        ], $this->kasir->id);

        $this->assertEquals(12000, $tx->total);
        $this->assertEquals(1800, $tx->duration_seconds);
    }

    /**
     * 5. Req 2: Custom manual 5 menit -> Rp 2.500 untuk tarif Rp 30.000/jam.
     */
    public function test_req_2_custom_manual_5_minutes_produces_2500()
    {
        $tx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'tipe_transaksi' => 'prepaid',
            'duration_seconds' => 300, // 5 minutes
        ], $this->kasir->id);

        $this->assertEquals(2500, $tx->total);
        $this->assertEquals(300, $tx->duration_seconds);
    }

    /**
     * 6. Req 3: Custom manual 30 menit -> Rp 15.000.
     */
    public function test_req_3_custom_manual_30_minutes_produces_15000()
    {
        $tx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'tipe_transaksi' => 'prepaid',
            'duration_seconds' => 1800, // 30 minutes (no preset)
        ], $this->kasir->id);

        $this->assertEquals(15000, $tx->total);
        $this->assertEquals(1800, $tx->duration_seconds);
    }

    /**
     * 7. Req 4: Custom manual 1 jam 15 menit 30 detik (4530s) -> raw Rp 37.750 -> Rp 38.000.
     */
    public function test_req_4_custom_manual_1h_15m_30s_produces_38000()
    {
        $tx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'tipe_transaksi' => 'prepaid',
            'duration_seconds' => 4530, // 1h 15m 30s
        ], $this->kasir->id);

        $this->assertEquals(38000, $tx->total);
        $this->assertEquals(4530, $tx->duration_seconds);
    }

    /**
     * 8. Req 5: Custom manual 30 detik -> raw Rp 250 -> Rp 500.
     */
    public function test_req_5_custom_manual_30s_produces_500()
    {
        $tx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'tipe_transaksi' => 'prepaid',
            'duration_seconds' => 30, // 30 seconds
        ], $this->kasir->id);

        $this->assertEquals(500, $tx->total);
        $this->assertEquals(30, $tx->duration_seconds);
    }

    /**
     * 9. Req 6 to 9: Rounding boundary rules.
     */
    public function test_req_6_to_9_rounding_boundaries()
    {
        // Rp 30.000 / hr -> 30.000 / 3600 = 8.333333 / sec
        // 1800s = Rp 15.000 exact -> Rp 15.000
        $this->assertEquals(15000, $this->playstation->calculateCustomPrice(1800));

        // 1860s (31m) = 30000 * 1860 / 3600 = 15.500 exact -> Rp 15.500
        $this->assertEquals(15500, $this->playstation->calculateCustomPrice(1860));

        // 1861s = 30000 * 1861 / 3600 = 15508.33 -> round UP to Rp 16.000
        $this->assertEquals(16000, $this->playstation->calculateCustomPrice(1861));

        // 1801s = 30000 * 1801 / 3600 = 15008.33 -> round UP to Rp 15.500
        $this->assertEquals(15500, $this->playstation->calculateCustomPrice(1801));
    }

    /**
     * 10. Req 10: Lost Time tetap menggunakan formula existing.
     */
    public function test_req_10_lost_time_unaffected()
    {
        $startTime = Carbon::parse('2026-11-08 10:00:00');
        Carbon::setTestNow($startTime);

        $tx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'tipe_transaksi' => 'postpaid',
        ], $this->kasir->id);

        $this->assertEquals(0, $tx->total);
        $this->assertNull($tx->expires_at);

        // Stop 45 minutes later
        Carbon::setTestNow($startTime->copy()->addMinutes(45));
        $stoppedTx = $this->billingService->stopSession($tx->id_transaksi);

        // 45m on 30.000/hr = 22.500 -> Lost time rounding (diff 500) -> 22.500
        $this->assertEquals(22500, $stoppedTx->total);
        $this->assertEquals('0 jam 45 menit', $stoppedTx->jam_main);
    }

    /**
     * 11. Req 11: Custom Paket tetap menggunakan harga existing.
     */
    public function test_req_11_custom_package_unaffected()
    {
        $package = CustomPackage::create([
            'nama_paket' => 'Paket Hemat Durasi',
            'harga_total' => 45000,
        ]);
        $package->playstations()->attach($this->playstation->id, ['lama_main' => 90]);

        $tx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'tipe_transaksi' => 'custom_package',
            'custom_package_id' => $package->id,
        ], $this->kasir->id);

        $this->assertEquals(45000, $tx->total);
        $this->assertEquals(90, $tx->jam_main);
    }

    /**
     * 12. Req 12: F&B tidak dibulatkan dan tidak mengalami re-rounding.
     */
    public function test_req_12_fnb_not_rounded()
    {
        $fnb = Fnb::create([
            'nama' => 'Snack Keripik',
            'harga_beli' => 2000,
            'harga_jual' => 3250, // Unusual odd denomination
            'stok' => 10,
        ]);

        $tx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'tipe_transaksi' => 'prepaid',
            'duration_seconds' => 1800, // PS price: 15.000
            'fnb_ids' => [$fnb->id],
            'fnbs_qty' => [1],
        ], $this->kasir->id);

        // 15.000 (PS) + 3.250 (FnB) = 18.250. F&B must NOT be rounded up to 18.500!
        $this->assertEquals(18250, $tx->total);
    }

    /**
     * 13. Req 13: Extend billing tidak membuat harga ganda.
     */
    public function test_req_13_extend_billing_calculates_correct_total()
    {
        $start = Carbon::parse('2026-11-08 14:00:00');
        Carbon::setTestNow($start);

        $tx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'tipe_transaksi' => 'prepaid',
            'duration_seconds' => 3600, // 1 hour -> 30.000
        ], $this->kasir->id);
        $this->assertEquals(30000, $tx->total);

        // Extend by 30 minutes (0.5 hour)
        $extendedTx = $this->billingService->extendSession($tx->id_transaksi, 30, true);

        // Total 1h 30m (5400s) on 30.000/hr = 45.000
        $this->assertEquals(45000, $extendedTx->total);
        $this->assertEquals(5400, $extendedTx->duration_seconds);
        $this->assertEquals('2026-11-08 15:30:00', $extendedTx->expires_at->toDateTimeString());
    }

    /**
     * 14. Req 14: Transaksi lama tetap terbaca dengan benar (backward compatibility).
     */
    public function test_req_14_legacy_transactions_readable()
    {
        $legacyTx = Transaction::create([
            'status' => 'user',
            'nama' => 'Pelanggan Lama',
            'device_id' => $this->device->id,
            'playstation_id' => $this->playstation->id,
            'tipe_transaksi' => 'prepaid',
            'jam_main' => '2', // Legacy integer string
            'duration_seconds' => null,
            'harga' => '30000',
            'total' => '60000',
            'waktu_mulai' => '10:00',
            'waktu_Selesai' => '12:00',
            'status_transaksi' => 'selesai',
        ]);

        $this->assertEquals('2 Jam', $legacyTx->display_duration);
    }

    /**
     * 15. Req 15: Stop dan expiry menghasilkan status dan automation intent yang benar.
     */
    public function test_req_15_stop_and_expiry_automation_intent_accuracy()
    {
        $start = Carbon::parse('2026-11-08 19:40:15');
        Carbon::setTestNow($start);

        $tx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'tipe_transaksi' => 'prepaid',
            'duration_seconds' => 330, // 00:05:30
        ], $this->kasir->id);

        // Expiry must be exactly 19:45:45
        $this->assertEquals('2026-11-08 19:45:45', $tx->expires_at->toDateTimeString());

        // Verify pre-scheduled OFF intent exists at 19:45:45
        $offIntent = AutomationIntent::where('transaction_id', $tx->id_transaksi)
            ->where('event_type', AutomationIntent::EVENT_BILLING_EXPIRED)
            ->first();
        $this->assertNotNull($offIntent);
        $this->assertEquals('2026-11-08 19:45:45', $offIntent->scheduled_at->toDateTimeString());

        // Fast forward to 19:45:45
        Carbon::setTestNow(Carbon::parse('2026-11-08 19:45:45'));
        $eval = $this->billingService->evaluateExpiration();
        $this->assertEquals(1, $eval['count']);

        $tx->refresh();
        $this->assertEquals(Transaction::BILLING_EXPIRED_PENDING_ACTION, $tx->billing_status);
        $this->assertEquals('Tersedia', $this->device->fresh()->status);
    }

    /**
     * 16. Req 16: API calculate-duration-price endpoint produces same nominal as backend formula.
     */
    public function test_req_16_api_matches_backend_nominal()
    {
        // 00:05:00 (300s) -> 2.500
        $res5m = $this->actingAs($this->kasir)->getJson('/api/calculate-duration-price?' . http_build_query([
            'device' => $this->device->id,
            'hours' => 0,
            'minutes' => 5,
            'seconds' => 0,
        ]));
        $res5m->assertOk();
        $res5m->assertJson(['price' => 2500, 'duration_seconds' => 300]);

        // 01:15:30 (4530s) -> 38.000
        $res1h15m = $this->actingAs($this->kasir)->getJson('/api/calculate-duration-price?' . http_build_query([
            'device' => $this->device->id,
            'hours' => 1,
            'minutes' => 15,
            'seconds' => 30,
        ]));
        $res1h15m->assertOk();
        $res1h15m->assertJson(['price' => 38000, 'duration_seconds' => 4530]);
    }

    /**
     * 17. Timer clock crossing tests (minute, hour, midnight).
     */
    public function test_timer_accuracy_and_crossing()
    {
        // Midnight crossing: 23:55:00 + 10 mins (600s) = 00:05:00 next day
        $startMidnight = Carbon::parse('2026-11-08 23:55:00');
        Carbon::setTestNow($startMidnight);

        $tx = $this->billingService->startSession([
            'device_id' => $this->device->id,
            'tipe_transaksi' => 'prepaid',
            'duration_seconds' => 600, // 10 minutes
        ], $this->kasir->id);

        $this->assertEquals('2026-11-09 00:05:00', $tx->expires_at->toDateTimeString());
    }
}

