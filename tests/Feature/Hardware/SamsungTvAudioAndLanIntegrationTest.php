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
use App\Services\Hardware\Drivers\MockSamsungTvDriver;
use App\Services\Hardware\TvAlertService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class SamsungTvAudioAndLanIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $kasir;
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
            'name' => 'Admin Audio QA',
            'email' => 'admin_audio@sugengrawuh.test',
            'role' => 'admin',
        ]);

        $this->kasir = User::factory()->create([
            'name' => 'Kasir Audio QA',
            'email' => 'kasir_audio@sugengrawuh.test',
            'role' => 'kasir',
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
            'mac_address' => 'AA:BB:CC:00:02:01',
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
            'mac_address' => 'AA:BB:CC:00:02:02',
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
     * Scenario 1: Admin can send explicit MUTE and UNMUTE commands to running station.
     */
    public function test_admin_can_send_explicit_mute_and_unmute_commands_to_running_station(): void
    {
        $tx = $this->startPrepaidSession($this->ps01, 1, 'Pelanggan PS 01');
        $this->ps01->refresh();

        // 1. Send explicit MUTE
        $muteRes = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-volume.store', ['station' => $this->ps01->id]),
            ['command' => 'MUTE']
        );

        $muteRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.command', 'MUTE')
            ->assertJsonPath('data.mute', true)
            ->assertJsonPath('data.muted', true)
            ->assertJsonPath('data.simulated_muted', true)
            ->assertJsonPath('data.status', TvAlertEvent::STATUS_PENDING);

        $muteEvent = TvAlertEvent::where('station_id', $this->ps01->id)
            ->where('command', 'MUTE')
            ->first();
        $this->assertNotNull($muteEvent);
        $this->assertSame(TvAlertEvent::STATUS_PENDING, $muteEvent->status);
        $this->assertTrue((bool) $muteEvent->simulated_muted);
        $this->assertSame((int) $tx->id_transaksi, (int) $muteEvent->transaction_id);

        // 2. Send explicit UNMUTE
        $unmuteRes = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-volume.store', ['station' => $this->ps01->id]),
            ['command' => 'UNMUTE']
        );

        $unmuteRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.command', 'UNMUTE')
            ->assertJsonPath('data.mute', false)
            ->assertJsonPath('data.muted', false)
            ->assertJsonPath('data.simulated_muted', false)
            ->assertJsonPath('data.status', TvAlertEvent::STATUS_PENDING);

        $unmuteEvent = TvAlertEvent::where('station_id', $this->ps01->id)
            ->where('command', 'UNMUTE')
            ->first();
        $this->assertNotNull($unmuteEvent);
        $this->assertSame(TvAlertEvent::STATUS_PENDING, $unmuteEvent->status);
        $this->assertFalse((bool) $unmuteEvent->simulated_muted);

        // 3. Enforce rate limit protection on rapid bursts
        Cache::put("tv_vol_burst:{$this->ps01->id}", 10, 1);

        $rateLimitedRes = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-volume.store', ['station' => $this->ps01->id]),
            ['command' => 'MUTE']
        );

        $rateLimitedRes->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'RATE_LIMITED');
    }

    /**
     * Scenario 2: Admin can send VOLUME_UP and VOLUME_DOWN commands with custom step.
     */
    public function test_admin_can_send_volume_up_and_volume_down_commands_with_custom_step(): void
    {
        $this->startPrepaidSession($this->ps01, 1);

        // 1. Send VOLUME_UP with step 10 (default 30 -> 40)
        $upRes = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-volume.store', ['station' => $this->ps01->id]),
            [
                'command' => 'VOLUME_UP',
                'step' => 10,
            ]
        );

        $upRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.command', 'VOLUME_UP')
            ->assertJsonPath('data.volume', 40)
            ->assertJsonPath('data.simulated_volume', 40);

        $upEvent = TvAlertEvent::where('station_id', $this->ps01->id)
            ->where('command', 'VOLUME_UP')
            ->first();
        $this->assertNotNull($upEvent);
        $this->assertSame(40, (int) $upEvent->simulated_volume);

        // 2. Send VOLUME_DOWN with step 15 (40 -> 25)
        $downRes = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-volume.store', ['station' => $this->ps01->id]),
            [
                'command' => 'VOLUME_DOWN',
                'step' => 15,
            ]
        );

        $downRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.command', 'VOLUME_DOWN')
            ->assertJsonPath('data.volume', 25)
            ->assertJsonPath('data.simulated_volume', 25);

        // 3. Invalid volume command rejected by validation
        $invalidRes = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-volume.store', ['station' => $this->ps01->id]),
            ['command' => 'INVALID_COMMAND_NAME']
        );

        $invalidRes->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');
    }

    /**
     * Scenario 3: Consumer polls MUTE and UNMUTE commands with correct payload.
     */
    public function test_consumer_polls_mute_and_unmute_commands_with_correct_payload(): void
    {
        $this->startPrepaidSession($this->ps01, 1);
        $devToken = 'dev_tv_station_' . $this->ps01->id;

        // 1. Admin sends MUTE command
        $muteRes = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-volume.store', ['station' => $this->ps01->id]),
            ['command' => 'MUTE']
        );
        $muteRes->assertStatus(200);
        $muteEventId = $muteRes->json('data.event_id');

        // 2. Consumer polls pending event
        $pollRes = $this->getJson(route('api.tv-agent.events.index', [
            'station_id' => $this->ps01->id,
            'token' => $devToken,
        ]));

        $pollRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'events')
            ->assertJsonPath('events.0.id', $muteEventId)
            ->assertJsonPath('events.0.command', 'MUTE')
            ->assertJsonPath('events.0.event_type', TvAlertEvent::TYPE_VOLUME_CONTROL)
            ->assertJsonPath('events.0.simulated_muted', true);

        // Acknowledge the MUTE event
        $ackRes = $this->postJson(
            route('api.tv-agent.events.ack', ['event' => $muteEventId]),
            [
                'station_id' => $this->ps01->id,
                'token' => $devToken,
            ]
        );
        $ackRes->assertStatus(200);

        // 3. Admin sends UNMUTE command
        $unmuteRes = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-volume.store', ['station' => $this->ps01->id]),
            ['command' => 'UNMUTE']
        );
        $unmuteRes->assertStatus(200);
        $unmuteEventId = $unmuteRes->json('data.event_id');

        // 4. Consumer polls UNMUTE event
        $secondPollRes = $this->getJson(route('api.tv-agent.events.index', [
            'station_id' => $this->ps01->id,
            'token' => $devToken,
        ]));

        $secondPollRes->assertStatus(200)
            ->assertJsonCount(1, 'events')
            ->assertJsonPath('events.0.id', $unmuteEventId)
            ->assertJsonPath('events.0.command', 'UNMUTE')
            ->assertJsonPath('events.0.event_type', TvAlertEvent::TYPE_VOLUME_CONTROL)
            ->assertJsonPath('events.0.simulated_muted', false);
    }

    /**
     * Scenario 4: Consumer ACK with STATE_VERIFIED updates real_volume, real_muted, and physical_display_verified.
     */
    public function test_consumer_ack_with_state_verified_updates_real_volume_and_muted(): void
    {
        $this->startPrepaidSession($this->ps01, 1);
        $devToken = 'dev_tv_station_' . $this->ps01->id;

        $muteRes = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-volume.store', ['station' => $this->ps01->id]),
            ['command' => 'MUTE']
        );
        $muteRes->assertStatus(200);
        $eventId = $muteRes->json('data.event_id');

        $ackRes = $this->postJson(
            route('api.tv-agent.events.ack', ['event' => $eventId]),
            [
                'station_id' => $this->ps01->id,
                'token' => $devToken,
                'status' => 'DELIVERED_TO_APP',
                'execution_status' => 'STATE_VERIFIED',
                'real_volume' => 45,
                'real_muted' => true,
                'simulator_info' => 'Tizen-TVAudioControl-Physical',
            ]
        );

        $ackRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', TvAlertEvent::STATUS_DELIVERED_TO_APP)
            ->assertJsonPath('data.execution_status', 'STATE_VERIFIED')
            ->assertJsonPath('data.real_volume', 45)
            ->assertJsonPath('data.real_muted', true)
            ->assertJsonPath('data.physical_display_verified', true);

        $event = TvAlertEvent::find($eventId);
        $this->assertSame(45, (int) $event->real_volume);
        $this->assertTrue((bool) $event->real_muted);
        $this->assertSame(TvAlertEvent::STATUS_STATE_VERIFIED, $event->execution_status);
        $this->assertSame(TvAlertEvent::STATUS_DELIVERED_TO_APP, $event->status);
        $this->assertNotNull($event->acked_at);
    }

    /**
     * Scenario 5: Consumer ACK with COMMAND_EXECUTED keeps physical_display_verified false.
     */
    public function test_consumer_ack_with_command_executed_keeps_physical_display_unverified(): void
    {
        $this->startPrepaidSession($this->ps01, 1);
        $devToken = 'dev_tv_station_' . $this->ps01->id;

        $upRes = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-volume.store', ['station' => $this->ps01->id]),
            ['command' => 'VOLUME_UP']
        );
        $upRes->assertStatus(200);
        $eventId = $upRes->json('data.event_id');

        $ackRes = $this->postJson(
            route('api.tv-agent.events.ack', ['event' => $eventId]),
            [
                'station_id' => $this->ps01->id,
                'token' => $devToken,
                'status' => 'DELIVERED_TO_APP',
                'execution_status' => 'COMMAND_EXECUTED',
                'real_volume' => 35,
                'real_muted' => false,
            ]
        );

        $ackRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', TvAlertEvent::STATUS_DELIVERED_TO_APP)
            ->assertJsonPath('data.execution_status', 'COMMAND_EXECUTED')
            ->assertJsonPath('data.real_volume', 35)
            ->assertJsonPath('data.real_muted', false)
            ->assertJsonPath('data.physical_display_verified', false);

        $event = TvAlertEvent::find($eventId);
        $this->assertSame(TvAlertEvent::STATUS_COMMAND_EXECUTED, $event->execution_status);
        $this->assertSame(35, (int) $event->real_volume);
        $this->assertFalse((bool) $event->real_muted);
    }

    /**
     * Scenario 6: Consumer ACK with UNSUPPORTED marks event appropriately with error codes.
     */
    public function test_consumer_ack_with_unsupported_marks_event_as_unsupported(): void
    {
        $this->startPrepaidSession($this->ps01, 1);
        $devToken = 'dev_tv_station_' . $this->ps01->id;

        $muteRes = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-volume.store', ['station' => $this->ps01->id]),
            ['command' => 'MUTE']
        );
        $muteRes->assertStatus(200);
        $eventId = $muteRes->json('data.event_id');

        $ackRes = $this->postJson(
            route('api.tv-agent.events.ack', ['event' => $eventId]),
            [
                'station_id' => $this->ps01->id,
                'token' => $devToken,
                'status' => 'DELIVERED_TO_APP',
                'execution_status' => 'UNSUPPORTED',
                'hardware_error_code' => 'TIZEN_AUDIO_UNAVAILABLE',
                'hardware_error_message' => 'tizen.tvaudiocontrol is not supported on this platform',
            ]
        );

        $ackRes->assertStatus(200)
            ->assertJsonPath('data.execution_status', 'UNSUPPORTED')
            ->assertJsonPath('data.result_code', 'UNSUPPORTED_ON_TARGET')
            ->assertJsonPath('data.physical_display_verified', false)
            ->assertJsonPath('data.hardware_error_code', 'TIZEN_AUDIO_UNAVAILABLE')
            ->assertJsonPath('data.hardware_error_message', 'tizen.tvaudiocontrol is not supported on this platform');

        $event = TvAlertEvent::find($eventId);
        $this->assertSame(TvAlertEvent::STATUS_UNSUPPORTED, $event->execution_status);
        $this->assertSame('UNSUPPORTED_ON_TARGET', $event->result_code);
        $this->assertSame('TIZEN_AUDIO_UNAVAILABLE', $event->hardware_error_code);
        $this->assertSame('tizen.tvaudiocontrol is not supported on this platform', $event->hardware_error_message);
    }

    /**
     * Scenario 7: Consumer ACK with FAILED marks event as failed with hardware error code and message.
     */
    public function test_consumer_ack_with_failed_marks_event_as_failed_with_hardware_error(): void
    {
        $this->startPrepaidSession($this->ps01, 1);
        $devToken = 'dev_tv_station_' . $this->ps01->id;

        $muteRes = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-volume.store', ['station' => $this->ps01->id]),
            ['command' => 'VOLUME_DOWN']
        );
        $muteRes->assertStatus(200);
        $eventId = $muteRes->json('data.event_id');

        $ackRes = $this->postJson(
            route('api.tv-agent.events.ack', ['event' => $eventId]),
            [
                'station_id' => $this->ps01->id,
                'token' => $devToken,
                'status' => 'FAILED',
                'execution_status' => 'FAILED',
                'hardware_error_code' => 'AUDIO_DAC_TIMEOUT',
                'hardware_error_message' => 'Failed to communicate with TV DAC',
            ]
        );

        $ackRes->assertStatus(200)
            ->assertJsonPath('data.status', TvAlertEvent::STATUS_FAILED)
            ->assertJsonPath('data.execution_status', 'FAILED')
            ->assertJsonPath('data.result_code', 'AUDIO_DAC_TIMEOUT')
            ->assertJsonPath('data.hardware_error_code', 'AUDIO_DAC_TIMEOUT')
            ->assertJsonPath('data.hardware_error_message', 'Failed to communicate with TV DAC')
            ->assertJsonPath('data.physical_display_verified', false);

        $event = TvAlertEvent::find($eventId);
        $this->assertSame(TvAlertEvent::STATUS_FAILED, $event->status);
        $this->assertSame('FAILED', $event->execution_status);
        $this->assertSame('AUDIO_DAC_TIMEOUT', $event->result_code);
        $this->assertSame('AUDIO_DAC_TIMEOUT', $event->hardware_error_code);
        $this->assertSame('Failed to communicate with TV DAC', $event->hardware_error_message);
    }

    /**
     * Scenario 8: Status endpoint exposes LAN configuration (lan_host_ip, lan_api_url) and read-back telemetry.
     */
    public function test_status_endpoint_exposes_lan_configuration_to_admin(): void
    {
        $this->startPrepaidSession($this->ps01, 1);

        // 1. Check default LAN host IP and URL
        $statusRes = $this->actingAs($this->admin)->getJson(
            route('admin.stations.tv-control.status', ['station' => $this->ps01->id])
        );

        $statusRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.consumer_config.lan_host_ip', '192.168.200.240');

        $lanApiUrl = $statusRes->json('data.consumer_config.lan_api_url');
        $this->assertStringContainsString('http://192.168.200.240:', $lanApiUrl);
        $this->assertStringContainsString('station_id=' . $this->ps01->id, $lanApiUrl);
        $this->assertStringContainsString('token=dev_tv_station_' . $this->ps01->id, $lanApiUrl);

        // 2. Custom LAN IP via TV_AGENT_HOST_IP environment variable
        putenv('TV_AGENT_HOST_IP=192.168.1.188');

        $customStatusRes = $this->actingAs($this->admin)->getJson(
            route('admin.stations.tv-control.status', ['station' => $this->ps01->id])
        );
        $customStatusRes->assertStatus(200)
            ->assertJsonPath('data.consumer_config.lan_host_ip', '192.168.1.188');
        $this->assertStringContainsString('http://192.168.1.188:', $customStatusRes->json('data.consumer_config.lan_api_url'));

        putenv('TV_AGENT_HOST_IP'); // cleanup

        // 3. Read-back values from STATE_VERIFIED ACK reflected in status endpoint
        $muteRes = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-volume.store', ['station' => $this->ps01->id]),
            ['command' => 'MUTE']
        );
        $eventId = $muteRes->json('data.event_id');

        $this->postJson(
            route('api.tv-agent.events.ack', ['event' => $eventId]),
            [
                'station_id' => $this->ps01->id,
                'token' => 'dev_tv_station_' . $this->ps01->id,
                'status' => 'DELIVERED_TO_APP',
                'execution_status' => 'STATE_VERIFIED',
                'real_volume' => 42,
                'real_muted' => true,
            ]
        )->assertStatus(200);

        $verifiedStatusRes = $this->actingAs($this->admin)->getJson(
            route('admin.stations.tv-control.status', ['station' => $this->ps01->id])
        );

        $verifiedStatusRes->assertStatus(200)
            ->assertJsonPath('data.latest_event.real_volume', 42)
            ->assertJsonPath('data.latest_event.real_muted', true)
            ->assertJsonPath('data.latest_event.execution_status', 'STATE_VERIFIED')
            ->assertJsonPath('data.physical_display_verified', true)
            ->assertJsonPath('data.control_mode', 'MOCK')
            ->assertJsonPath('data.driver_type', MockSamsungTvDriver::DRIVER_TYPE);
    }

    /**
     * Scenario 9: Available and Billiard stations reject MUTE, UNMUTE, and volume commands.
     */
    public function test_billiard_and_available_stations_reject_mute_and_unmute(): void
    {
        // 1. Station PS 01 in 'Tersedia' (no active session) rejects MUTE and UNMUTE with HTTP 409
        $this->assertSame('Tersedia', $this->ps01->status);

        $availMuteRes = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-volume.store', ['station' => $this->ps01->id]),
            ['command' => 'MUTE']
        );
        $availMuteRes->assertStatus(409)
            ->assertJsonPath('error_code', 'STATION_AVAILABLE');

        $availUnmuteRes = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-volume.store', ['station' => $this->ps01->id]),
            ['command' => 'UNMUTE']
        );
        $availUnmuteRes->assertStatus(409)
            ->assertJsonPath('error_code', 'STATION_AVAILABLE');

        // 2. Billiard station in 'Tersedia' rejects MUTE and UNMUTE with HTTP 422
        $billiardMuteRes = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-volume.store', ['station' => $this->billiard01->id]),
            ['command' => 'MUTE']
        );
        $billiardMuteRes->assertStatus(422)
            ->assertJsonPath('error_code', 'INVALID_STATION_CATEGORY');

        $billiardUnmuteRes = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-volume.store', ['station' => $this->billiard01->id]),
            ['command' => 'UNMUTE']
        );
        $billiardUnmuteRes->assertStatus(422)
            ->assertJsonPath('error_code', 'INVALID_STATION_CATEGORY');

        // 3. Billiard station even with an active session rejects MUTE with HTTP 422
        $billiardTx = $this->billingService->startSession([
            'device_id' => $this->billiard01->id,
            'playstation_id' => $this->billiardCategory->id,
            'nama' => 'Pelanggan Billiard',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
        ], $this->admin->id);

        $this->billiard01->refresh();
        $this->assertSame('Digunakan', $this->billiard01->status);

        $activeBilliardMuteRes = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-volume.store', ['station' => $this->billiard01->id]),
            ['command' => 'MUTE']
        );
        $activeBilliardMuteRes->assertStatus(422)
            ->assertJsonPath('error_code', 'INVALID_STATION_CATEGORY');
    }

    /**
     * Scenario 10: Cross-station ACK isolation (PS 02 cannot forge or ACK PS 01 events).
     */
    public function test_cross_station_ack_cannot_forge_execution_status(): void
    {
        $this->startPrepaidSession($this->ps01, 1, 'Pelanggan PS 01');
        $this->startPrepaidSession($this->ps02, 1, 'Pelanggan PS 02');

        // Admin creates volume command on PS 01
        $res01 = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-volume.store', ['station' => $this->ps01->id]),
            ['command' => 'MUTE']
        );
        $res01->assertStatus(200);
        $event01Id = $res01->json('data.event_id');

        $tokenPs02 = 'dev_tv_station_' . $this->ps02->id;

        // Attempt A: PS 02 consumer tries to ACK PS 01 event using station_id = PS 01 with PS 02's token
        // Rejected with 403 UNAUTHORIZED_CONSUMER (token does not match station 1)
        $forgeAttemptA = $this->postJson(
            route('api.tv-agent.events.ack', ['event' => $event01Id]),
            [
                'station_id' => $this->ps01->id,
                'token' => $tokenPs02,
                'execution_status' => 'STATE_VERIFIED',
                'real_volume' => 99,
                'real_muted' => true,
            ]
        );
        $forgeAttemptA->assertStatus(403)
            ->assertJsonPath('error_code', 'UNAUTHORIZED_CONSUMER');

        // Attempt B: PS 02 consumer tries to ACK PS 01 event with station_id = PS 02 and valid PS 02 token
        // Rejected with 403 FORBIDDEN (consumer station 2 is forbidden from modifying station 1's event)
        $forgeAttemptB = $this->postJson(
            route('api.tv-agent.events.ack', ['event' => $event01Id]),
            [
                'station_id' => $this->ps02->id,
                'token' => $tokenPs02,
                'execution_status' => 'STATE_VERIFIED',
                'real_volume' => 99,
                'real_muted' => true,
            ]
        );
        $forgeAttemptB->assertStatus(403)
            ->assertJsonPath('error_code', 'FORBIDDEN');

        // Verify PS 01 event remains intact and uncorrupted
        $event01 = TvAlertEvent::find($event01Id);
        $this->assertSame(TvAlertEvent::STATUS_PENDING, $event01->status);
        $this->assertNull($event01->execution_status);
        $this->assertNull($event01->real_volume);
        $this->assertNull($event01->acked_at);
    }

    /**
     * Scenario 11: Non-admin users cannot send volume commands.
     */
    public function test_non_admin_users_cannot_send_volume_commands(): void
    {
        $this->startPrepaidSession($this->ps01, 1);

        // Kasir user attempt is forbidden
        $kasirRes = $this->actingAs($this->kasir)->postJson(
            route('admin.stations.tv-volume.store', ['station' => $this->ps01->id]),
            ['command' => 'MUTE']
        );
        $kasirRes->assertStatus(403);

        // Unauthenticated guest is rejected (HTTP 401)
        auth()->logout();
        $guestRes = $this->postJson(
            route('admin.stations.tv-volume.store', ['station' => $this->ps01->id]),
            ['command' => 'MUTE']
        );
        $guestRes->assertStatus(401);
    }

    /**
     * Scenario 12: Hardware safety invariants preserved.
     */
    public function test_hardware_safety_invariants_preserved(): void
    {
        // 1. Safe simulation environment configuration
        $this->assertFalse((bool) config('hardware.real_execution_enabled'));
        $this->assertSame('mock', config('hardware.driver'));
        $this->assertTrue((bool) config('hardware.simulation_enabled'));

        // 2. Volume command records mock driver and mock control mode without real hardware execution
        $this->startPrepaidSession($this->ps01, 1);

        $res = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-volume.store', ['station' => $this->ps01->id]),
            ['command' => 'MUTE']
        );
        $res->assertStatus(200)
            ->assertJsonPath('data.control_mode', 'MOCK')
            ->assertJsonPath('data.driver_type', MockSamsungTvDriver::DRIVER_TYPE);

        $event = TvAlertEvent::find($res->json('data.event_id'));
        $this->assertSame('MOCK', $event->control_mode);
        $this->assertSame(MockSamsungTvDriver::DRIVER_TYPE, $event->driver_type);
    }
}
