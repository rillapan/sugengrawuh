<?php

namespace Tests\Feature\Hardware;

use App\Models\Device;
use App\Models\PhysicalDevice;
use App\Models\Playstation;
use App\Models\StationDeviceMapping;
use App\Models\Transaction;
use App\Models\TvAlertEvent;
use App\Models\User;
use App\Services\BillingService;
use App\Services\Hardware\TvAlertService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class SamsungTvAlertIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $kasir;
    protected User $owner;
    protected Playstation $psCategory;
    protected Playstation $billiardCategory;
    protected Device $ps01;
    protected Device $ps02;
    protected Device $billiard01;
    protected PhysicalDevice $tv01;
    protected PhysicalDevice $tv02;
    protected StationDeviceMapping $mapping01;
    protected StationDeviceMapping $mapping02;
    protected BillingService $billingService;
    protected TvAlertService $tvAlertService;

    protected function setUp(): void
    {
        parent::setUp();

        // Enforce mock & simulation safety configuration
        Config::set('hardware.driver', 'mock');
        Config::set('hardware.real_execution_enabled', false);
        Config::set('hardware.simulation_enabled', true);
        Config::set('hardware.automation_enabled', true);

        Cache::flush();

        $this->admin = User::factory()->create([
            'name' => 'Admin TV QA',
            'email' => 'admin_tv@sugengrawuh.test',
            'role' => 'admin',
        ]);

        $this->kasir = User::factory()->create([
            'name' => 'Kasir TV QA',
            'email' => 'kasir_tv@sugengrawuh.test',
            'role' => 'kasir',
        ]);

        $this->owner = User::factory()->create([
            'name' => 'Owner TV QA',
            'email' => 'owner_tv@sugengrawuh.test',
            'role' => 'owner',
        ]);

        $this->psCategory = Playstation::create([
            'nama' => 'PS 4',
            'harga' => 10000,
        ]);

        $this->billiardCategory = Playstation::create([
            'nama' => 'Meja Billiard',
            'harga' => 25000,
        ]);

        $this->ps01 = Device::create([
            'nama' => 'PS 01',
            'status' => 'Tersedia',
            'playstation_id' => $this->psCategory->id,
            'session_generation' => 0,
        ]);
        $this->ps01->playstations()->attach($this->psCategory->id);

        $this->ps02 = Device::create([
            'nama' => 'PS 02',
            'status' => 'Tersedia',
            'playstation_id' => $this->psCategory->id,
            'session_generation' => 0,
        ]);
        $this->ps02->playstations()->attach($this->psCategory->id);

        $this->billiard01 = Device::create([
            'nama' => 'BILLIARD 01',
            'status' => 'Tersedia',
            'playstation_id' => $this->billiardCategory->id,
            'session_generation' => 0,
        ]);
        $this->billiard01->playstations()->attach($this->billiardCategory->id);

        $this->tv01 = PhysicalDevice::create([
            'name' => 'Samsung TV PS 01',
            'category' => PhysicalDevice::CATEGORY_SMART_TV,
            'brand' => PhysicalDevice::BRAND_SAMSUNG,
            'model' => '43FE60',
            'mac_address' => 'AA:BB:CC:00:01:01',
            'ip_address' => '192.168.1.101',
            'connection_status' => PhysicalDevice::CONNECTION_ONLINE,
            'pairing_status' => PhysicalDevice::PAIRING_PAIRED,
            'is_active' => true,
        ]);

        $this->tv02 = PhysicalDevice::create([
            'name' => 'Samsung TV PS 02',
            'category' => PhysicalDevice::CATEGORY_SMART_TV,
            'brand' => PhysicalDevice::BRAND_SAMSUNG,
            'model' => '43FE60',
            'mac_address' => 'AA:BB:CC:00:01:02',
            'ip_address' => '192.168.1.102',
            'connection_status' => PhysicalDevice::CONNECTION_ONLINE,
            'pairing_status' => PhysicalDevice::PAIRING_PAIRED,
            'is_active' => true,
        ]);

        $this->mapping01 = StationDeviceMapping::create([
            'station_id' => $this->ps01->id,
            'physical_device_id' => $this->tv01->id,
            'mapping_role' => StationDeviceMapping::ROLE_DISPLAY_TV,
            'mapping_status' => StationDeviceMapping::STATUS_ACTIVE,
            'attached_by_user_id' => $this->admin->id,
            'attached_at' => now(),
        ]);

        $this->mapping02 = StationDeviceMapping::create([
            'station_id' => $this->ps02->id,
            'physical_device_id' => $this->tv02->id,
            'mapping_role' => StationDeviceMapping::ROLE_DISPLAY_TV,
            'mapping_status' => StationDeviceMapping::STATUS_ACTIVE,
            'attached_by_user_id' => $this->admin->id,
            'attached_at' => now(),
        ]);

        $this->billingService = app(BillingService::class);
        $this->tvAlertService = app(TvAlertService::class);
    }

    /**
     * Helper to start a running prepaid session on a station.
     */
    protected function startPrepaidSession(Device $station, int $hours = 1, string $customerName = 'Pelanggan PS'): Transaction
    {
        return $this->billingService->startSession([
            'device_id' => $station->id,
            'playstation_id' => $station->playstation_id,
            'nama' => $customerName,
            'tipe_transaksi' => 'prepaid',
            'jam_main' => $hours,
        ], $this->admin->id);
    }

    /**
     * Scenario 1: Admin can send manual alert to running PlayStation station with PENDING status.
     */
    public function test_admin_can_send_manual_alert_to_running_playstation_station_with_pending_status(): void
    {
        $tx = $this->startPrepaidSession($this->ps01, 1, 'Pelanggan PS 01');
        $this->ps01->refresh();

        $response = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-alerts.store', ['station' => $this->ps01->id]),
            [
                'preset' => '10_MIN_LEFT',
                'duration_seconds' => 8,
            ]
        );

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', TvAlertEvent::STATUS_PENDING)
            ->assertJsonPath('data.station_id', $this->ps01->id)
            ->assertJsonPath('data.session_generation', (int) $this->ps01->session_generation)
            ->assertJsonPath('data.display_duration_seconds', 8);

        $event = TvAlertEvent::where('station_id', $this->ps01->id)->first();
        $this->assertNotNull($event);
        $this->assertSame(TvAlertEvent::STATUS_PENDING, $event->status);
        $this->assertNotSame(TvAlertEvent::STATUS_DELIVERED_TO_APP, $event->status);
        $this->assertSame($this->admin->id, (int) $event->actor_user_id);
        $this->assertSame((int) $tx->id_transaksi, (int) $event->transaction_id);
        $this->assertSame(1, (int) $event->session_generation);
        $this->assertNull($event->acked_at);
    }

    /**
     * Scenario 2: Tizen consumer polls pending alert and ACKs to DELIVERED_TO_APP.
     */
    public function test_tizen_consumer_polls_pending_alert_and_acks_to_delivered_to_app(): void
    {
        $this->startPrepaidSession($this->ps01, 1);

        $createRes = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-alerts.store', ['station' => $this->ps01->id]),
            [
                'preset' => 'REMAINING_10_MIN',
                'duration_seconds' => 8,
            ]
        );
        $createRes->assertStatus(201);
        $eventId = $createRes->json('data.event_id');

        $devToken = 'dev_tv_station_' . $this->ps01->id;

        // 1. Poll pending event
        $pollRes = $this->getJson(route('api.tv-agent.events.index', [
            'station_id' => $this->ps01->id,
            'token' => $devToken,
        ]));

        $pollRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'events')
            ->assertJsonPath('events.0.id', $eventId)
            ->assertJsonPath('events.0.duration_seconds', 8)
            ->assertJsonPath('events.0.session_generation', 1);

        $this->assertNotEmpty($pollRes->json('events.0.message_text'));

        // Event must STILL be PENDING before ACK
        $this->assertSame(TvAlertEvent::STATUS_PENDING, TvAlertEvent::find($eventId)->status);

        // 2. Consumer sends ACK after rendering overlay
        $ackRes = $this->postJson(
            route('api.tv-agent.events.ack', ['event' => $eventId]),
            [
                'station_id' => $this->ps01->id,
                'token' => $devToken,
            ]
        );

        $ackRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', TvAlertEvent::STATUS_DELIVERED_TO_APP)
            ->assertJsonPath('data.result_code', 'ACKED_BY_SIMULATOR')
            ->assertJsonPath('data.physical_display_verified', false);

        $updatedEvent = TvAlertEvent::find($eventId);
        $this->assertSame(TvAlertEvent::STATUS_DELIVERED_TO_APP, $updatedEvent->status);
        $this->assertSame('ACKED_BY_SIMULATOR', $updatedEvent->result_code);
        $this->assertNotNull($updatedEvent->acked_at);

        // 3. Subsequent poll does NOT return already-delivered event
        $secondPollRes = $this->getJson(route('api.tv-agent.events.index', [
            'station_id' => $this->ps01->id,
            'token' => $devToken,
        ]));
        $secondPollRes->assertStatus(200)
            ->assertJsonCount(0, 'events');
    }

    /**
     * Scenario 3: Station isolation — PS 02 consumer cannot read or ACK PS 01 events.
     */
    public function test_station_isolation_ps01_consumer_cannot_read_or_ack_ps02_events(): void
    {
        $this->startPrepaidSession($this->ps01, 1, 'Pelanggan PS 01');
        $this->startPrepaidSession($this->ps02, 1, 'Pelanggan PS 02');

        $res01 = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-alerts.store', ['station' => $this->ps01->id]),
            ['preset' => 'REMAINING_10_MIN', 'duration_seconds' => 8]
        );
        $event01Id = $res01->json('data.event_id');

        $res02 = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-alerts.store', ['station' => $this->ps02->id]),
            ['preset' => 'LOWER_VOLUME', 'duration_seconds' => 8]
        );
        $event02Id = $res02->json('data.event_id');

        $tokenPs02 = 'dev_tv_station_' . $this->ps02->id;

        // 1. PS 02 consumer polls PS 02 -> only sees PS 02 event, never PS 01 event
        $pollPs02 = $this->getJson(route('api.tv-agent.events.index', [
            'station_id' => $this->ps02->id,
            'token' => $tokenPs02,
        ]));
        $pollPs02->assertStatus(200)
            ->assertJsonCount(1, 'events')
            ->assertJsonPath('events.0.id', $event02Id)
            ->assertJsonPath('events.0.station_id', $this->ps02->id);

        // 2. PS 02 consumer attempts to poll PS 01 with PS 02's token -> rejected with 403
        $crossPoll = $this->getJson(route('api.tv-agent.events.index', [
            'station_id' => $this->ps01->id,
            'token' => $tokenPs02,
        ]));
        $crossPoll->assertStatus(403)
            ->assertJsonPath('error_code', 'UNAUTHORIZED_CONSUMER');

        // 3. PS 02 consumer attempts to ACK PS 01's event -> rejected with 403, PS 01 event stays PENDING
        $crossAck = $this->postJson(
            route('api.tv-agent.events.ack', ['event' => $event01Id]),
            [
                'station_id' => $this->ps02->id,
                'token' => $tokenPs02,
            ]
        );
        $crossAck->assertStatus(403);
        $this->assertSame(TvAlertEvent::STATUS_PENDING, TvAlertEvent::find($event01Id)->status);
    }

    /**
     * Scenario 4: Consumer with missing or invalid token is rejected.
     */
    public function test_consumer_with_invalid_token_is_rejected(): void
    {
        $this->startPrepaidSession($this->ps01, 1);
        $res = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-alerts.store', ['station' => $this->ps01->id]),
            ['preset' => 'REMAINING_10_MIN']
        );
        $eventId = $res->json('data.event_id');

        // Missing token -> 401
        $missingTokenPoll = $this->getJson(route('api.tv-agent.events.index', [
            'station_id' => $this->ps01->id,
        ]));
        $missingTokenPoll->assertStatus(401)
            ->assertJsonPath('error_code', 'UNAUTHORIZED_CONSUMER');

        // Invalid token on poll -> 403
        $invalidTokenPoll = $this->getJson(route('api.tv-agent.events.index', [
            'station_id' => $this->ps01->id,
            'token' => 'forged_invalid_token_xyz',
        ]));
        $invalidTokenPoll->assertStatus(403)
            ->assertJsonPath('error_code', 'UNAUTHORIZED_CONSUMER');

        // Missing token on ACK -> 401
        $missingTokenAck = $this->postJson(route('api.tv-agent.events.ack', ['event' => $eventId]));
        $missingTokenAck->assertStatus(401)
            ->assertJsonPath('error_code', 'UNAUTHORIZED_CONSUMER');

        // Invalid token on ACK -> 403
        $invalidTokenAck = $this->postJson(route('api.tv-agent.events.ack', ['event' => $eventId]), [
            'station_id' => $this->ps01->id,
            'token' => 'forged_invalid_token_xyz',
        ]);
        $invalidTokenAck->assertStatus(403)
            ->assertJsonPath('error_code', 'UNAUTHORIZED_CONSUMER');
    }

    /**
     * Scenario 5: Admin volume controls (VOLUME_DOWN, VOLUME_UP, MUTE_TOGGLE) and rate limiting in mock mode.
     */
    public function test_admin_volume_controls_down_up_and_mute_toggle_in_mock_mode(): void
    {
        $this->startPrepaidSession($this->ps01, 1);

        // 1. VOLUME_DOWN from default 30 -> 25
        $downRes = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-volume.store', ['station' => $this->ps01->id]),
            ['action' => 'VOLUME_DOWN']
        );
        $downRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.volume', 25)
            ->assertJsonPath('data.muted', false);

        // 2. VOLUME_UP from 25 -> 30
        $upRes = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-volume.store', ['station' => $this->ps01->id]),
            ['command' => 'VOLUME_UP']
        );
        $upRes->assertStatus(200)
            ->assertJsonPath('data.volume', 30);

        // 3. MUTE_TOGGLE -> true, then false
        $muteRes1 = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-volume.store', ['station' => $this->ps01->id]),
            ['command' => 'MUTE_TOGGLE']
        );
        $muteRes1->assertStatus(200)
            ->assertJsonPath('data.muted', true);

        $muteRes2 = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-volume.store', ['station' => $this->ps01->id]),
            ['command' => 'MUTE_TOGGLE']
        );
        $muteRes2->assertStatus(200)
            ->assertJsonPath('data.muted', false);

        // 4. Rate limit enforcement on rapid burst
        Config::set('hardware.tv_control.max_burst_per_sec', 2);
        Cache::put("tv_vol_burst:{$this->ps01->id}", 2, 10);

        $rateLimitedRes = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-volume.store', ['station' => $this->ps01->id]),
            ['command' => 'VOLUME_DOWN']
        );
        $rateLimitedRes->assertStatus(429)
            ->assertJsonPath('error_code', 'RATE_LIMITED');
    }

    /**
     * Scenario 6: Available station (Tersedia) rejects TV alert and volume control.
     */
    public function test_available_station_tersedia_rejects_tv_alert_and_volume_control(): void
    {
        $this->assertSame('Tersedia', $this->ps01->status);

        $alertRes = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-alerts.store', ['station' => $this->ps01->id]),
            ['preset' => 'REMAINING_10_MIN']
        );
        $this->assertContains($alertRes->status(), [409, 422]);
        $this->assertSame('STATION_AVAILABLE', $alertRes->json('error_code'));
        $this->assertSame(0, TvAlertEvent::count());

        $volRes = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-volume.store', ['station' => $this->ps01->id]),
            ['command' => 'VOLUME_DOWN']
        );
        $this->assertContains($volRes->status(), [409, 422]);
        $this->assertSame(0, TvAlertEvent::count());
    }

    /**
     * Scenario 7: Billiard station rejects TV alert and volume control even when Digunakan.
     */
    public function test_billiard_station_rejects_tv_alert_and_volume_control(): void
    {
        $this->billingService->startSession([
            'device_id' => $this->billiard01->id,
            'playstation_id' => $this->billiardCategory->id,
            'nama' => 'Pelanggan Billiard',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
        ], $this->admin->id);

        $alertRes = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-alerts.store', ['station' => $this->billiard01->id]),
            ['preset' => 'REMAINING_10_MIN']
        );
        $alertRes->assertStatus(422)
            ->assertJsonPath('error_code', 'INVALID_STATION_CATEGORY');

        $volRes = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-volume.store', ['station' => $this->billiard01->id]),
            ['command' => 'VOLUME_DOWN']
        );
        $volRes->assertStatus(422)
            ->assertJsonPath('error_code', 'INVALID_STATION_CATEGORY');
    }

    /**
     * Scenario 8: Unmapped PlayStation station rejects TV alert.
     */
    public function test_unmapped_station_rejects_tv_alert(): void
    {
        $this->mapping01->delete();
        $this->startPrepaidSession($this->ps01, 1);

        $alertRes = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-alerts.store', ['station' => $this->ps01->id]),
            ['preset' => 'REMAINING_10_MIN']
        );

        $alertRes->assertStatus(422)
            ->assertJsonPath('error_code', 'NO_ACTIVE_TV_MAPPING');
    }

    /**
     * Scenario 9: Non-admin (Kasir and Owner) cannot access TV control endpoints.
     */
    public function test_non_admin_kasir_and_owner_cannot_access_tv_control_endpoints(): void
    {
        $this->startPrepaidSession($this->ps01, 1);

        foreach ([$this->kasir, $this->owner] as $nonAdmin) {
            $this->actingAs($nonAdmin)
                ->postJson(route('admin.stations.tv-alerts.store', ['station' => $this->ps01->id]), ['preset' => 'REMAINING_10_MIN'])
                ->assertStatus(403);

            $this->actingAs($nonAdmin)
                ->postJson(route('admin.stations.tv-volume.store', ['station' => $this->ps01->id]), ['command' => 'VOLUME_DOWN'])
                ->assertStatus(403);

            $this->actingAs($nonAdmin)
                ->getJson(route('admin.stations.tv-control.status', ['station' => $this->ps01->id]))
                ->assertStatus(403);

            $this->actingAs($nonAdmin)
                ->get(route('admin.tv-lab.index'))
                ->assertStatus(403);
        }
    }

    /**
     * Scenario 10: Custom message validation and XSS/HTML sanitization.
     */
    public function test_custom_message_validation_and_xss_html_sanitization(): void
    {
        $this->startPrepaidSession($this->ps01, 1);

        // 1. Valid custom message with embedded <script> and HTML tags gets stripped cleanly
        $xssRes = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-alerts.store', ['station' => $this->ps01->id]),
            [
                'preset' => 'CUSTOM',
                'custom_message' => '<script>alert("xss")</script>Halo Pelanggan <b>PS 01</b>',
                'duration_seconds' => 8,
            ]
        );

        $xssRes->assertStatus(201);
        $sanitizedBody = $xssRes->json('data.message_body');
        $this->assertSame('Halo Pelanggan PS 01', $sanitizedBody);
        $this->assertStringNotContainsString('<script>', $sanitizedBody);
        $this->assertStringNotContainsString('<b>', $sanitizedBody);

        // 2. Tag-only / empty message after sanitization is rejected with HTTP 422
        $emptyScriptRes = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-alerts.store', ['station' => $this->ps01->id]),
            [
                'preset' => 'CUSTOM',
                'custom_message' => '<script>evil()</script><b>   </b>',
            ]
        );
        $emptyScriptRes->assertStatus(422);

        // 3. Over-length custom message (>200 chars) is rejected with HTTP 422
        $tooLongRes = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-alerts.store', ['station' => $this->ps01->id]),
            [
                'preset' => 'CUSTOM',
                'custom_message' => str_repeat('A', 205),
            ]
        );
        $tooLongRes->assertStatus(422);
    }

    /**
     * Scenario 11: Automatic 10-minute alert created once for prepaid session and not duplicated.
     */
    public function test_automatic_10_minute_alert_created_once_for_prepaid_session_and_not_duplicated(): void
    {
        $tx = $this->startPrepaidSession($this->ps01, 1);
        $tx->update([
            'expires_at' => Carbon::now()->addMinutes(9),
        ]);

        // 1. Run artisan worker once
        $this->artisan('billing:tv-alert-worker --once')
            ->assertExitCode(0);

        $expectedKey = "auto_alert_10m_tx_{$tx->id_transaksi}_gen_1";
        $events = TvAlertEvent::where('station_id', $this->ps01->id)
            ->where('event_type', TvAlertEvent::TYPE_BILLING_ENDING_SOON)
            ->get();

        $this->assertCount(1, $events);
        $this->assertSame($expectedKey, $events->first()->idempotency_key);
        $this->assertSame(TvAlertEvent::STATUS_PENDING, $events->first()->status);

        // 2. Run worker a second time -> no duplicate created
        $this->artisan('billing:tv-alert-worker --once')
            ->assertExitCode(0);

        $this->assertSame(1, TvAlertEvent::where('station_id', $this->ps01->id)
            ->where('event_type', TvAlertEvent::TYPE_BILLING_ENDING_SOON)
            ->count());
    }

    /**
     * Scenario 12: Extending session cancels pending alert and allows new auto-alert on new generation.
     */
    public function test_extend_session_cancels_pending_alert_and_allows_new_auto_alert_on_new_generation(): void
    {
        $tx = $this->startPrepaidSession($this->ps01, 1);
        $tx->update([
            'expires_at' => Carbon::now()->addMinutes(8),
        ]);

        $this->tvAlertService->evaluateAutoTenMinuteAlerts();

        $gen1Event = TvAlertEvent::where('transaction_id', $tx->id_transaksi)->first();
        $this->assertNotNull($gen1Event);
        $this->assertSame(TvAlertEvent::STATUS_PENDING, $gen1Event->status);
        $this->assertSame(1, (int) $gen1Event->session_generation);

        // Extend session by 1 hour
        $extendedTx = $this->billingService->extendSession($tx->id_transaksi, 1);
        $this->ps01->refresh();

        // Old pending alert must be CANCELLED and station generation incremented to 2
        $this->assertSame(TvAlertEvent::STATUS_CANCELLED, $gen1Event->fresh()->status);
        $this->assertSame(2, (int) $this->ps01->session_generation);
        $this->assertSame(2, (int) $extendedTx->session_generation);

        // When extended session later reaches <= 10 minutes on generation 2, a new auto-alert is created
        $extendedTx->update([
            'expires_at' => Carbon::now()->addMinutes(9),
        ]);

        $evalResult = $this->tvAlertService->evaluateAutoTenMinuteAlerts();
        $this->assertSame(1, $evalResult['created_count']);

        $gen2Event = TvAlertEvent::where('transaction_id', $tx->id_transaksi)
            ->where('session_generation', 2)
            ->first();

        $this->assertNotNull($gen2Event);
        $this->assertSame(TvAlertEvent::STATUS_PENDING, $gen2Event->status);
        $this->assertSame("auto_alert_10m_tx_{$tx->id_transaksi}_gen_2", $gen2Event->idempotency_key);
    }

    /**
     * Scenario 13: Stop session and expiry invalidate pending alerts and reject late ACK.
     */
    public function test_stop_session_and_expiry_invalidate_pending_alerts_and_reject_late_ack(): void
    {
        $tx = $this->startPrepaidSession($this->ps01, 1);

        $res = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-alerts.store', ['station' => $this->ps01->id]),
            ['preset' => 'REMAINING_10_MIN']
        );
        $eventId = $res->json('data.event_id');

        // Stop session
        $this->billingService->stopSession($tx->id_transaksi);

        $event = TvAlertEvent::find($eventId);
        $this->assertSame(TvAlertEvent::STATUS_CANCELLED, $event->status);

        // Consumer attempts late ACK on cancelled event -> rejected with HTTP 409
        $lateAckRes = $this->postJson(
            route('api.tv-agent.events.ack', ['event' => $eventId]),
            [
                'station_id' => $this->ps01->id,
                'token' => 'dev_tv_station_' . $this->ps01->id,
            ]
        );
        $lateAckRes->assertStatus(409)
            ->assertJsonPath('error_code', 'CONFLICT');
    }

    /**
     * Scenario 14: New customer session does not receive previous session alert.
     */
    public function test_new_customer_session_does_not_receive_previous_session_alert(): void
    {
        // Customer A starts session (gen 1) and gets an alert
        $txA = $this->startPrepaidSession($this->ps01, 1, 'Customer A');
        $resA = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-alerts.store', ['station' => $this->ps01->id]),
            ['preset' => 'CONTACT_CASHIER']
        );
        $eventAId = $resA->json('data.event_id');

        // Customer A stops session
        $this->billingService->stopSession($txA->id_transaksi);

        // Customer B starts a new session on the same station
        $txB = $this->startPrepaidSession($this->ps01, 1, 'Customer B');
        $this->ps01->refresh();
        $this->assertGreaterThan(1, (int) $this->ps01->session_generation);

        // Create alert for Customer B
        $resB = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-alerts.store', ['station' => $this->ps01->id]),
            ['preset' => 'LOWER_VOLUME']
        );
        $eventBId = $resB->json('data.event_id');

        // Consumer polls -> only receives Customer B's event, never Customer A's
        $pollRes = $this->getJson(route('api.tv-agent.events.index', [
            'station_id' => $this->ps01->id,
            'token' => 'dev_tv_station_' . $this->ps01->id,
        ]));

        $pollRes->assertStatus(200)
            ->assertJsonCount(1, 'events')
            ->assertJsonPath('events.0.id', $eventBId)
            ->assertJsonPath('events.0.transaction_id', $txB->id_transaksi);

        $this->assertNotEquals($eventAId, $pollRes->json('events.0.id'));
    }

    /**
     * Scenario 15: Lost Time (postpaid) session never triggers automatic 10-minute alert.
     */
    public function test_lost_time_session_never_triggers_automatic_10_minute_alert(): void
    {
        $postpaidTx = $this->billingService->startSession([
            'device_id' => $this->ps01->id,
            'playstation_id' => $this->psCategory->id,
            'nama' => 'Pelanggan Lost Time',
            'tipe_transaksi' => 'postpaid',
        ], $this->admin->id);

        $this->assertNull($postpaidTx->expires_at);

        $this->artisan('billing:tv-alert-worker --once')
            ->assertExitCode(0);

        $this->assertSame(0, TvAlertEvent::where('event_type', TvAlertEvent::TYPE_BILLING_ENDING_SOON)->count());
    }

    /**
     * Scenario 16: Disconnected simulator leaves event PENDING without false ACK and mock safety is enforced.
     */
    public function test_disconnected_simulator_leaves_event_pending_without_false_ack_and_mock_safety_enforced(): void
    {
        $this->startPrepaidSession($this->ps01, 1);

        $res = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-alerts.store', ['station' => $this->ps01->id]),
            ['preset' => 'ALMOST_FINISHED', 'duration_seconds' => 10]
        );
        $res->assertStatus(201);
        $eventId = $res->json('data.event_id');

        // Without simulator polling/ACK, status endpoint reports WAITING_CONSUMER and event stays PENDING
        $statusRes = $this->actingAs($this->admin)->getJson(
            route('admin.stations.tv-control.status', ['station' => $this->ps01->id])
        );

        $statusRes->assertStatus(200)
            ->assertJsonPath('data.simulator_connection_status', 'WAITING_CONSUMER')
            ->assertJsonPath('data.latest_event.id', $eventId)
            ->assertJsonPath('data.latest_event.status', TvAlertEvent::STATUS_PENDING)
            ->assertJsonPath('data.physical_display_verified', false);

        $event = TvAlertEvent::find($eventId);
        $this->assertSame(TvAlertEvent::STATUS_PENDING, $event->status);
        $this->assertNull($event->acked_at);

        // Verify mock hardware safety invariants
        $this->assertFalse((bool) config('hardware.real_execution_enabled'));
        $this->assertSame('mock', config('hardware.driver'));
    }

    /**
     * Scenario 17: Admin can send explicit MUTE and UNMUTE audio commands.
     */
    public function test_admin_can_send_explicit_mute_and_unmute_commands(): void
    {
        $this->startPrepaidSession($this->ps01, 1);

        // 1. Send explicit MUTE
        $muteRes = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-volume.store', ['station' => $this->ps01->id]),
            ['command' => 'MUTE']
        );

        $muteRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.command', 'MUTE')
            ->assertJsonPath('data.muted', true)
            ->assertJsonPath('data.simulated_muted', true);

        $muteEvent = TvAlertEvent::where('station_id', $this->ps01->id)
            ->where('command', 'MUTE')
            ->first();
        $this->assertNotNull($muteEvent);
        $this->assertTrue($muteEvent->simulated_muted);

        // 2. Send explicit UNMUTE
        $unmuteRes = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-volume.store', ['station' => $this->ps01->id]),
            ['command' => 'UNMUTE']
        );

        $unmuteRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.command', 'UNMUTE')
            ->assertJsonPath('data.muted', false)
            ->assertJsonPath('data.simulated_muted', false);

        $unmuteEvent = TvAlertEvent::where('station_id', $this->ps01->id)
            ->where('command', 'UNMUTE')
            ->first();
        $this->assertNotNull($unmuteEvent);
        $this->assertFalse($unmuteEvent->simulated_muted);
    }

    /**
     * Scenario 18: Consumer ACK lifecycle statuses (COMMAND_RECEIVED, STATE_VERIFIED, UNSUPPORTED, FAILED).
     */
    public function test_consumer_ack_lifecycle_statuses(): void
    {
        $this->startPrepaidSession($this->ps01, 1);
        $devToken = 'dev_tv_station_' . $this->ps01->id;

        // 1. Create alert and ACK with STATE_VERIFIED
        $resAlert = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-alerts.store', ['station' => $this->ps01->id]),
            ['preset' => 'REMAINING_10_MIN']
        );
        $alertId = $resAlert->json('data.event_id');

        $ackRes = $this->postJson(
            route('api.tv-agent.events.ack', ['event' => $alertId]),
            [
                'station_id' => $this->ps01->id,
                'token' => $devToken,
                'execution_status' => 'STATE_VERIFIED',
                'real_volume' => 35,
                'real_muted' => false,
            ]
        );

        $ackRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.execution_status', 'STATE_VERIFIED')
            ->assertJsonPath('data.physical_display_verified', true)
            ->assertJsonPath('data.real_volume', 35)
            ->assertJsonPath('data.real_muted', false);

        $event = TvAlertEvent::find($alertId);
        $this->assertSame('STATE_VERIFIED', $event->execution_status);
        $this->assertSame(35, $event->real_volume);
        $this->assertFalse($event->real_muted);

        // Status endpoint should report physical_display_verified = true
        $statusRes = $this->actingAs($this->admin)->getJson(
            route('admin.stations.tv-control.status', ['station' => $this->ps01->id])
        );
        $statusRes->assertStatus(200)
            ->assertJsonPath('data.physical_display_verified', true);

        // 2. Create second alert and ACK with UNSUPPORTED
        $resAlert2 = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-alerts.store', ['station' => $this->ps01->id]),
            ['preset' => 'LOWER_VOLUME']
        );
        $alert2Id = $resAlert2->json('data.event_id');

        $ack2Res = $this->postJson(
            route('api.tv-agent.events.ack', ['event' => $alert2Id]),
            [
                'station_id' => $this->ps01->id,
                'token' => $devToken,
                'execution_status' => 'UNSUPPORTED',
            ]
        );
        $ack2Res->assertStatus(200)
            ->assertJsonPath('data.execution_status', 'UNSUPPORTED')
            ->assertJsonPath('data.result_code', 'UNSUPPORTED_ON_TARGET');

        $event2 = TvAlertEvent::find($alert2Id);
        $this->assertSame('UNSUPPORTED_ON_TARGET', $event2->result_code);

        // 3. Create third alert and ACK with FAILED and hardware error
        $resAlert3 = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-alerts.store', ['station' => $this->ps01->id]),
            ['preset' => 'CONTACT_CASHIER']
        );
        $alert3Id = $resAlert3->json('data.event_id');

        $ack3Res = $this->postJson(
            route('api.tv-agent.events.ack', ['event' => $alert3Id]),
            [
                'station_id' => $this->ps01->id,
                'token' => $devToken,
                'execution_status' => 'FAILED',
                'hardware_error_code' => 'TIZEN_RENDER_ERROR',
                'hardware_error_message' => 'WebAudio context blocked by autoplay policy',
            ]
        );
        $ack3Res->assertStatus(200)
            ->assertJsonPath('data.status', 'FAILED')
            ->assertJsonPath('data.execution_status', 'FAILED')
            ->assertJsonPath('data.hardware_error_code', 'TIZEN_RENDER_ERROR')
            ->assertJsonPath('data.hardware_error_message', 'WebAudio context blocked by autoplay policy');

        $event3 = TvAlertEvent::find($alert3Id);
        $this->assertSame(TvAlertEvent::STATUS_FAILED, $event3->status);
        $this->assertSame('TIZEN_RENDER_ERROR', $event3->hardware_error_code);
    }

    /**
     * Scenario 19: LAN Host IP configuration and exposure in consumer_config and setup demo command.
     */
    public function test_lan_host_ip_configuration_and_exposure(): void
    {
        $this->startPrepaidSession($this->ps01, 1);

        // 1. Default LAN host IP in status response
        $statusRes = $this->actingAs($this->admin)->getJson(
            route('admin.stations.tv-control.status', ['station' => $this->ps01->id])
        );

        $statusRes->assertStatus(200)
            ->assertJsonPath('data.consumer_config.lan_host_ip', '192.168.200.240');

        $this->assertStringContainsString('http://192.168.200.240:', $statusRes->json('data.consumer_config.lan_api_url'));
        $this->assertStringContainsString('/api/tv-agent/events?station_id=' . $this->ps01->id . '&token=dev_tv_station_' . $this->ps01->id, $statusRes->json('data.consumer_config.lan_api_url'));

        // 2. Custom LAN IP via TV_AGENT_HOST_IP env
        putenv('TV_AGENT_HOST_IP=192.168.1.50');
        $statusResCustom = $this->actingAs($this->admin)->getJson(
            route('admin.stations.tv-control.status', ['station' => $this->ps01->id])
        );
        $statusResCustom->assertStatus(200)
            ->assertJsonPath('data.consumer_config.lan_host_ip', '192.168.1.50');

        putenv('TV_AGENT_HOST_IP'); // cleanup

        // 3. Demo setup command includes LAN URL
        $exitCode = \Illuminate\Support\Facades\Artisan::call('tv:setup-poc-demo');
        $this->assertSame(0, $exitCode);
        $output = \Illuminate\Support\Facades\Artisan::output();
        $this->assertStringContainsString('192.168.200.240', $output);
    }
}
