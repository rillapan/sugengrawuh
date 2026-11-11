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
use App\Services\Hardware\StationNotificationDispatcher;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class StationNotificationDispatcherIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $kasir;
    protected Playstation $psCategory;
    protected Device $station;
    protected PhysicalDevice $tv;
    protected PhysicalDevice $console;
    protected StationDeviceMapping $tvMapping;
    protected StationDeviceMapping $consoleMapping;
    protected BillingService $billingService;
    protected StationNotificationDispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('hardware.driver', 'mock');
        Config::set('hardware.real_execution_enabled', false);
        Config::set('hardware.simulation_enabled', true);
        Config::set('hardware.automation_enabled', true);

        $this->admin = User::factory()->create([
            'name' => 'Admin Notif Test',
            'email' => 'admin_notif@sugengrawuh.test',
            'role' => 'admin',
        ]);

        $this->kasir = User::factory()->create([
            'name' => 'Kasir Notif Test',
            'email' => 'kasir_notif@sugengrawuh.test',
            'role' => 'kasir',
        ]);

        $this->psCategory = Playstation::create([
            'nama' => 'PlayStation 4 Pro',
            'harga' => 15000,
        ]);

        $this->station = Device::create([
            'nama' => 'Station VIP PS4',
            'status' => 'Tersedia',
            'playstation_id' => $this->psCategory->id,
            'session_generation' => 1,
        ]);

        $this->tv = PhysicalDevice::create([
            'name' => 'Samsung TV VIP 55',
            'category' => PhysicalDevice::CATEGORY_SMART_TV,
            'brand' => PhysicalDevice::BRAND_SAMSUNG,
            'model' => '55Q60A',
            'mac_address' => '00:11:22:33:44:55',
            'ip_address' => '192.168.1.101',
            'connection_status' => PhysicalDevice::CONNECTION_ONLINE,
            'pairing_status' => PhysicalDevice::PAIRING_PAIRED,
            'is_active' => true,
        ]);

        $this->console = PhysicalDevice::create([
            'name' => 'PS4 HEN Console Station VIP',
            'category' => PhysicalDevice::CATEGORY_GAME_CONSOLE,
            'brand' => PhysicalDevice::BRAND_SONY,
            'model' => PhysicalDevice::MODEL_PS4_HEN,
            'mac_address' => '00:11:22:33:44:66',
            'ip_address' => '192.168.1.102',
            'connection_status' => PhysicalDevice::CONNECTION_ONLINE,
            'pairing_status' => PhysicalDevice::PAIRING_NOT_REQUIRED,
            'is_active' => true,
        ]);

        $this->tvMapping = StationDeviceMapping::create([
            'station_id' => $this->station->id,
            'physical_device_id' => $this->tv->id,
            'mapping_role' => StationDeviceMapping::ROLE_DISPLAY_TV,
            'mapping_status' => StationDeviceMapping::STATUS_ACTIVE,
            'attached_at' => now(),
        ]);

        $this->consoleMapping = StationDeviceMapping::create([
            'station_id' => $this->station->id,
            'physical_device_id' => $this->console->id,
            'mapping_role' => StationDeviceMapping::ROLE_GAME_CONSOLE,
            'mapping_status' => StationDeviceMapping::STATUS_ACTIVE,
            'attached_at' => now(),
        ]);

        $this->station->playstations()->attach($this->psCategory->id);
        $this->billingService = app(BillingService::class);
        $this->dispatcher = app(StationNotificationDispatcher::class);
    }

    protected function startPrepaidSession(int $hours = 1): Transaction
    {
        $tx = $this->billingService->startSession([
            'device_id' => $this->station->id,
            'playstation_id' => $this->station->playstation_id,
            'nama' => 'Customer Notif QA',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => $hours,
        ], $this->admin->id);

        $this->station->refresh();
        return $tx;
    }

    public function test_auto_channel_prioritizes_game_console_when_mapped()
    {
        $tx = $this->startPrepaidSession(60);

        $result = $this->dispatcher->dispatchNotification(
            $this->station,
            $this->admin,
            [
                'channel' => 'AUTO',
                'preset' => 'REMAINING_10_MIN',
            ]
        );

        $this->assertTrue($result['success']);
        $this->assertEquals('CONSOLE', $result['channel_used']);

        /** @var TvAlertEvent $event */
        $event = $result['primary_event'];
        $this->assertNotNull($event);
        $this->assertEquals($this->console->id, $event->physical_device_id);
        $this->assertEquals('CONSOLE', $event->target_channel);
        $this->assertEquals(TvAlertEvent::STATUS_SENT_TO_SIMULATOR, $event->status);
    }

    public function test_explicit_tv_channel_routes_to_samsung_tv()
    {
        $tx = $this->startPrepaidSession(60);

        $result = $this->dispatcher->dispatchNotification(
            $this->station,
            $this->admin,
            [
                'channel' => 'TV',
                'preset' => 'LOWER_VOLUME',
            ]
        );

        $this->assertTrue($result['success']);
        $this->assertEquals('TV', $result['channel_used']);

        /** @var TvAlertEvent $event */
        $event = $result['primary_event'];
        $this->assertNotNull($event);
        $this->assertEquals($this->tv->id, $event->physical_device_id);
        $this->assertEquals('TV', $event->target_channel);
        $this->assertEquals(TvAlertEvent::STATUS_PENDING, $event->status);
    }

    public function test_channel_both_dispatches_to_console_and_tv()
    {
        $tx = $this->startPrepaidSession(60);

        $result = $this->dispatcher->dispatchNotification(
            $this->station,
            $this->admin,
            [
                'channel' => 'BOTH',
                'preset' => 'CONTACT_CASHIER',
            ]
        );

        $this->assertTrue($result['success']);
        $this->assertEquals('BOTH', $result['channel_used']);
        $this->assertArrayHasKey('console', $result['dispatches']);
        $this->assertArrayHasKey('tv', $result['dispatches']);
    }

    public function test_dispatch_blocked_when_station_has_no_active_session()
    {
        $this->expectException(\App\Exceptions\TvControlException::class);

        $this->dispatcher->dispatchNotification(
            $this->station,
            $this->admin,
            [
                'preset' => 'REMAINING_10_MIN',
            ]
        );
    }

    public function test_api_endpoint_dispatches_notification_for_admin()
    {
        $tx = $this->startPrepaidSession(60);

        $res = $this->actingAs($this->admin)->postJson(
            route('admin.stations.notifications.store', ['station' => $this->station->id]),
            [
                'channel' => 'CONSOLE',
                'preset' => 'CUSTOM',
                'custom_message' => 'Silakan pesan makanan di kasir',
                'duration_seconds' => 10,
            ]
        );

        $res->assertStatus(200);
        $res->assertJsonPath('success', true);
        $res->assertJsonPath('data.channel_used', 'CONSOLE');
    }

    public function test_api_endpoint_forbidden_for_kasir_role()
    {
        $tx = $this->startPrepaidSession(60);

        $res = $this->actingAs($this->kasir)->postJson(
            route('admin.stations.notifications.store', ['station' => $this->station->id]),
            [
                'preset' => 'REMAINING_10_MIN',
            ]
        );

        $res->assertStatus(403);
    }
}
