<?php

namespace Tests\Unit\Hardware;

use App\Models\Device;
use App\Models\PhysicalDevice;
use App\Models\Playstation;
use App\Models\StationDeviceMapping;
use App\Models\Transaction;
use App\Models\TvAlertEvent;
use App\Models\User;
use App\Services\BillingService;
use App\Services\Hardware\ConsoleNotificationService;
use App\Services\Hardware\TvAlertService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class TvAlertEventAndExpirationTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;
    protected Playstation $psCategory;
    protected Device $psStation;
    protected PhysicalDevice $consoleDevice;
    protected StationDeviceMapping $mapping;
    protected BillingService $billingService;
    protected TvAlertService $tvAlertService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Admin QA ' . uniqid(),
            'username' => 'admin_test_' . uniqid(),
            'email' => 'admin_' . uniqid() . '@pos.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);

        $this->psCategory = Playstation::create([
            'nama' => 'PS4 Pro',
            'harga' => 12000,
        ]);

        $this->psStation = Device::create([
            'nama' => 'Station PS4 ' . uniqid(),
            'status' => 'Tersedia',
            'playstation_id' => $this->psCategory->id,
            'session_generation' => 1,
        ]);
        $this->psStation->playstations()->attach($this->psCategory->id);

        $this->consoleDevice = PhysicalDevice::create([
            'name' => 'PS3 Test Console ' . uniqid(),
            'category' => PhysicalDevice::CATEGORY_GAME_CONSOLE,
            'brand' => PhysicalDevice::BRAND_SONY,
            'model' => PhysicalDevice::MODEL_PS3_HEN,
            'ip_address' => '192.168.1.188',
            'is_active' => true,
        ]);

        $this->mapping = StationDeviceMapping::create([
            'station_id' => $this->psStation->id,
            'physical_device_id' => $this->consoleDevice->id,
            'mapping_role' => StationDeviceMapping::ROLE_GAME_CONSOLE,
            'mapping_status' => StationDeviceMapping::STATUS_ACTIVE,
        ]);

        $this->billingService = app(BillingService::class);
        $this->tvAlertService = app(TvAlertService::class);
    }

    /** @test */
    public function tv_alert_event_has_required_constants_and_preset_definitions(): void
    {
        $this->assertTrue(defined('App\Models\TvAlertEvent::PRESET_TIME_EXPIRED'));
        $this->assertSame('TIME_EXPIRED', TvAlertEvent::PRESET_TIME_EXPIRED);

        $this->assertTrue(defined('App\Models\TvAlertEvent::TYPE_BILLING_EXPIRED'));
        $this->assertSame('BILLING_EXPIRED', TvAlertEvent::TYPE_BILLING_EXPIRED);

        $this->assertTrue(defined('App\Models\TvAlertEvent::STATUS_ACCEPTED'));
        $this->assertSame('ACCEPTED', TvAlertEvent::STATUS_ACCEPTED);

        $presets = TvAlertEvent::getPresetMessages();
        $this->assertArrayHasKey(TvAlertEvent::PRESET_TIME_EXPIRED, $presets);
        $this->assertSame('WAKTU BERMAIN TELAH HABIS!', $presets[TvAlertEvent::PRESET_TIME_EXPIRED]['title']);
        $this->assertSame('Waktu bermain telah habis. Silakan hubungi kasir.', $presets[TvAlertEvent::PRESET_TIME_EXPIRED]['body']);
    }

    /** @test */
    public function console_notification_service_normalizes_statuses_correctly(): void
    {
        $this->assertSame('ACCEPTED', ConsoleNotificationService::normalizeStatus('ACCEPTED'));
        $this->assertSame('ACCEPTED', ConsoleNotificationService::normalizeStatus('HTTP_ACCEPTED'));
        $this->assertSame('ACCEPTED', ConsoleNotificationService::normalizeStatus('ACK_HTTP'));
        $this->assertSame('ACCEPTED', ConsoleNotificationService::normalizeStatus('DELIVERED'));
        $this->assertSame('ACCEPTED', ConsoleNotificationService::normalizeStatus('DELIVERED_TO_APP'));
        $this->assertSame('ACCEPTED', ConsoleNotificationService::normalizeStatus('STATE_VERIFIED'));

        $this->assertSame('PENDING', ConsoleNotificationService::normalizeStatus('PENDING'));
        $this->assertSame('PENDING', ConsoleNotificationService::normalizeStatus('QUEUED_FOR_CONSUMER'));

        $this->assertSame('FAILED', ConsoleNotificationService::normalizeStatus('FAILED'));
        $this->assertSame('FAILED', ConsoleNotificationService::normalizeStatus('CANCELLED'));
        $this->assertSame('FAILED', ConsoleNotificationService::normalizeStatus('TIMEOUT'));
        $this->assertSame('FAILED', ConsoleNotificationService::normalizeStatus('OFFLINE'));

        $this->assertSame('UNVERIFIED', ConsoleNotificationService::normalizeStatus('UNVERIFIED'));
        $this->assertSame('UNVERIFIED', ConsoleNotificationService::normalizeStatus(null));
        $this->assertSame('UNVERIFIED', ConsoleNotificationService::normalizeStatus('UNKNOWN_STATUS'));
    }

    /** @test */
    public function evaluate_auto_alerts_generates_time_expired_event_and_deduplicates(): void
    {
        $tx = $this->billingService->startSession([
            'device_id' => $this->psStation->id,
            'playstation_id' => $this->psStation->playstation_id,
            'nama' => 'Pelanggan QA',
            'tipe_transaksi' => 'prepaid',
            'duration_seconds' => 3600,
        ], $this->admin->id);

        $tx->update([
            'expires_at' => Carbon::now()->subSeconds(5),
        ]);

        $this->psStation->refresh();
        $tx->refresh();

        // First run: should create exactly one expired event
        $evalResult = $this->tvAlertService->evaluateAutoAlerts();

        $this->assertGreaterThanOrEqual(1, $evalResult['created_count']);

        $generation = (int) ($this->psStation->session_generation ?: ($tx->session_generation ?: 1));
        $expectedIdempotencyKey = "auto_alert_expired_tx_{$tx->id_transaksi}_gen_{$generation}";
        $event = TvAlertEvent::where('idempotency_key', $expectedIdempotencyKey)->first();

        $this->assertNotNull($event, 'TIME_EXPIRED alert event was not generated.');
        $this->assertSame(TvAlertEvent::TYPE_BILLING_EXPIRED, $event->event_type);
        $this->assertSame(TvAlertEvent::PRESET_TIME_EXPIRED, $event->message_preset);
        $this->assertSame('WAKTU BERMAIN TELAH HABIS!', $event->message_title);
        $this->assertSame('Waktu bermain telah habis. Silakan hubungi kasir.', $event->message_body);
        $this->assertSame('CONSOLE', $event->target_channel);
        $this->assertSame(TvAlertEvent::STATUS_ACCEPTED, $event->status);

        // Second run: idempotency key prevents duplicate creation
        $evalResult2 = $this->tvAlertService->evaluateAutoAlerts();
        $count = TvAlertEvent::where('idempotency_key', $expectedIdempotencyKey)->count();
        $this->assertSame(1, $count, 'Deduplication failed: duplicate expired alert event created.');
    }

    /** @test */
    public function evaluate_auto_alerts_sweeps_prior_pending_alert_when_session_expires(): void
    {
        $tx = $this->billingService->startSession([
            'device_id' => $this->psStation->id,
            'playstation_id' => $this->psStation->playstation_id,
            'nama' => 'Pelanggan 10M Expire QA',
            'tipe_transaksi' => 'prepaid',
            'duration_seconds' => 3600,
        ], $this->admin->id);

        $this->psStation->refresh();
        $tx->refresh();
        $generation = (int) $this->psStation->session_generation;

        // Simulate 10-minute warning event queued as PENDING
        $pendingWarning = TvAlertEvent::create([
            'station_id' => $this->psStation->id,
            'station_name' => $this->psStation->nama,
            'transaction_id' => $tx->id_transaksi,
            'physical_device_id' => $this->consoleDevice->id,
            'station_device_mapping_id' => $this->mapping->id,
            'session_generation' => $generation,
            'actor_user_id' => null,
            'actor_role' => 'SYSTEM',
            'event_type' => TvAlertEvent::TYPE_BILLING_ENDING_SOON,
            'command' => TvAlertEvent::CMD_SHOW_ALERT,
            'message_preset' => TvAlertEvent::PRESET_REMAINING_10_MIN,
            'message_title' => 'WAKTU BERMAIN TERSISA 10 MENIT!',
            'message_body' => 'Waktu hampir habis.',
            'display_duration_seconds' => 8,
            'control_mode' => 'REAL',
            'driver_type' => 'PS3_HEN_HTTP',
            'target_channel' => 'CONSOLE',
            'idempotency_key' => "auto_alert_10m_tx_{$tx->id_transaksi}_gen_{$generation}",
            'status' => TvAlertEvent::STATUS_PENDING,
            'scheduled_at' => Carbon::now()->subMinutes(10),
        ]);

        // Session reaches expiration
        $tx->update([
            'expires_at' => Carbon::now()->subSeconds(10),
        ]);

        $evalResult = $this->tvAlertService->evaluateAutoAlerts();

        // Previous 10m alert should now be EXPIRED
        $pendingWarning->refresh();
        $this->assertSame(TvAlertEvent::STATUS_EXPIRED, $pendingWarning->status);

        // A new TIME_EXPIRED event should exist
        $expiredEvent = TvAlertEvent::where('idempotency_key', "auto_alert_expired_tx_{$tx->id_transaksi}_gen_{$generation}")->first();
        $this->assertNotNull($expiredEvent);
        $this->assertSame(TvAlertEvent::TYPE_BILLING_EXPIRED, $expiredEvent->event_type);
    }

    /** @test */
    public function evaluate_auto_alerts_generates_tv_time_expired_when_only_tv_mapped(): void
    {
        // Unmap console, map Samsung TV instead
        $this->mapping->delete();

        $tvDevice = PhysicalDevice::create([
            'name' => 'Samsung TV ' . uniqid(),
            'category' => PhysicalDevice::CATEGORY_SMART_TV,
            'brand' => PhysicalDevice::BRAND_SAMSUNG,
            'model' => 'QN55Q60AAFXZA',
            'ip_address' => '192.168.1.205',
            'is_active' => true,
        ]);

        StationDeviceMapping::create([
            'station_id' => $this->psStation->id,
            'physical_device_id' => $tvDevice->id,
            'mapping_role' => StationDeviceMapping::ROLE_DISPLAY_TV,
            'mapping_status' => StationDeviceMapping::STATUS_ACTIVE,
        ]);

        $tx = $this->billingService->startSession([
            'device_id' => $this->psStation->id,
            'playstation_id' => $this->psStation->playstation_id,
            'nama' => 'Pelanggan TV Only',
            'tipe_transaksi' => 'prepaid',
            'duration_seconds' => 3600,
        ], $this->admin->id);

        $tx->update([
            'expires_at' => Carbon::now()->subSeconds(2),
        ]);

        $this->psStation->refresh();
        $tx->refresh();
        $generation = (int) $this->psStation->session_generation;

        $evalResult = $this->tvAlertService->evaluateAutoAlerts();

        $tvExpiredEvent = TvAlertEvent::where('idempotency_key', "auto_alert_expired_tx_{$tx->id_transaksi}_gen_{$generation}")->first();
        $this->assertNotNull($tvExpiredEvent);
        $this->assertSame('TV', $tvExpiredEvent->target_channel);
        $this->assertSame(TvAlertEvent::TYPE_BILLING_EXPIRED, $tvExpiredEvent->event_type);
        $this->assertSame(TvAlertEvent::STATUS_PENDING, $tvExpiredEvent->status);
    }
}
