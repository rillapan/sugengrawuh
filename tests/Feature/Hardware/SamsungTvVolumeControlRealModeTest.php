<?php

namespace Tests\Feature\Hardware;

use App\Models\Device;
use App\Models\PhysicalDevice;
use App\Models\Playstation;
use App\Models\StationDeviceMapping;
use App\Models\Transaction;
use App\Models\TvAlertEvent;
use App\Models\User;
use App\Services\Hardware\Drivers\SamsungTvDriver;
use App\Services\Hardware\Transports\FakeSamsungWebSocketTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SamsungTvVolumeControlRealModeTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Device $station;
    protected PhysicalDevice $samsungTv;
    protected StationDeviceMapping $mapping;
    protected Transaction $activeTx;
    protected FakeSamsungWebSocketTransport $fakeTransport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $playstation = Playstation::create([
            'nama' => 'PS4',
            'harga' => 15000,
        ]);

        $this->station = Device::create([
            'nama' => 'TV 1 [UJI COBA]',
            'playstation_id' => $playstation->id,
            'status' => 'Tersedia',
            'session_generation' => 1,
        ]);
        $this->station->playstations()->attach($playstation->id);

        $this->samsungTv = PhysicalDevice::create([
            'name' => 'TV PS 1 [UJI COBA]',
            'category' => PhysicalDevice::CATEGORY_SMART_TV,
            'brand' => PhysicalDevice::BRAND_SAMSUNG,
            'model' => 'FE60EF',
            'ip_address' => '192.168.200.240',
            'mac_address' => '98:4E:8A:2A:21:6C',
            'is_active' => true,
            'connection_status' => PhysicalDevice::CONNECTION_ONLINE,
            'pairing_status' => PhysicalDevice::PAIRING_PAIRED,
            'pairing_payload' => 'valid_token_123',
            'auto_volume_limit_enabled' => false,
            'max_volume_limit' => 50,
            'current_volume' => null, // Initially unverified / not available
            'verified_capabilities' => [
                'GET_STATUS',
                'POWER_ON',
                'STANDBY',
                'MUTE',
                'UNMUTE',
                'VOLUME_UP',
                'VOLUME_DOWN',
            ],
        ]);

        $this->mapping = StationDeviceMapping::create([
            'station_id' => $this->station->id,
            'physical_device_id' => $this->samsungTv->id,
            'mapping_role' => StationDeviceMapping::ROLE_DISPLAY_TV,
            'mapping_status' => StationDeviceMapping::STATUS_ACTIVE,
            'attached_by_user_id' => $this->admin->id,
            'attached_at' => now(),
        ]);

        $billingService = $this->app->make(\App\Services\BillingService::class);
        $this->activeTx = $billingService->startSession([
            'device_id' => $this->station->id,
            'playstation_id' => $this->station->playstation_id,
            'nama' => 'Pelanggan Uji Coba',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 2,
        ], $this->admin->id);

        $this->fakeTransport = new FakeSamsungWebSocketTransport(FakeSamsungWebSocketTransport::SCENARIO_SUCCESS);
        $this->app->instance(\App\Services\Hardware\Transports\SamsungTransportInterface::class, $this->fakeTransport);

        // Bind fake transport in HardwareDriverManager
        $driverManager = $this->app->make(\App\Services\Hardware\HardwareDriverManager::class);
        $driverManager->setSamsungTransport($this->fakeTransport);
        $driverManager->setBypassSafetyGuardForTesting(true);
    }

    /** @test */
    public function volume_up_in_real_mode_sends_frames_and_does_not_forge_current_volume()
    {
        config(['hardware.driver' => 'real']);
        config(['hardware.real_execution_enabled' => true]);
        config(['hardware.tv_control.volume_frame_interval_ms' => 0]);

        $response = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-volume.store', ['station' => $this->station->id]),
            [
                'command' => 'VOLUME_UP',
                'step' => 5,
            ]
        );

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'control_mode' => 'REAL',
                'execution_status' => 'COMMAND_SENT',
                'actual_volume' => null,
                'actual_volume_display' => 'Tidak tersedia',
            ],
        ]);

        // Verify sent keys to TV transport
        $sentKeys = $this->fakeTransport->getSentKeys();
        $this->assertCount(5, $sentKeys);
        $this->assertEquals(['KEY_VOLUP', 'KEY_VOLUP', 'KEY_VOLUP', 'KEY_VOLUP', 'KEY_VOLUP'], $sentKeys);

        // DB record must NOT be updated with fake simulated number 30
        $this->samsungTv->refresh();
        $this->assertNull($this->samsungTv->current_volume);
    }

    /** @test */
    public function volume_down_in_real_mode_sends_frames()
    {
        config(['hardware.driver' => 'real']);
        config(['hardware.real_execution_enabled' => true]);
        config(['hardware.tv_control.volume_frame_interval_ms' => 0]);

        $response = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-volume.store', ['station' => $this->station->id]),
            [
                'command' => 'VOLUME_DOWN',
                'step' => 5,
            ]
        );

        $response->assertStatus(200);
        $sentKeys = $this->fakeTransport->getSentKeys();
        $this->assertCount(5, $sentKeys);
        $this->assertEquals(['KEY_VOLDOWN', 'KEY_VOLDOWN', 'KEY_VOLDOWN', 'KEY_VOLDOWN', 'KEY_VOLDOWN'], $sentKeys);
    }

    /** @test */
    public function mute_toggle_in_real_mode_sends_key_mute_and_returns_command_sent()
    {
        config(['hardware.driver' => 'real']);
        config(['hardware.real_execution_enabled' => true]);

        $response = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-volume.store', ['station' => $this->station->id]),
            [
                'command' => 'MUTE_TOGGLE',
            ]
        );

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'control_mode' => 'REAL',
                'execution_status' => 'COMMAND_SENT',
            ],
        ]);

        $sentKeys = $this->fakeTransport->getSentKeys();
        $this->assertContains('KEY_MUTE', $sentKeys);
    }

    /** @test */
    public function set_volume_in_real_mode_is_rejected_as_unsupported_capability()
    {
        config(['hardware.driver' => 'real']);
        config(['hardware.real_execution_enabled' => true]);

        $response = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-volume.store', ['station' => $this->station->id]),
            [
                'command' => 'SET_VOLUME',
                'target_volume' => 35,
            ]
        );

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'error_code' => 'CAPABILITY_UNSUPPORTED',
        ]);

        $this->samsungTv->refresh();
        $this->assertNull($this->samsungTv->current_volume);
    }

    /** @test */
    public function soft_volume_limit_blocks_set_volume_exceeding_limit()
    {
        $this->samsungTv->update([
            'auto_volume_limit_enabled' => true,
            'max_volume_limit' => 50,
        ]);

        $response = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-volume.store', ['station' => $this->station->id]),
            [
                'command' => 'SET_VOLUME',
                'target_volume' => 60,
            ]
        );

        $response->assertStatus(422);
        $this->assertStringContainsString('50%', $response->json('message'));
    }

    /** @test */
    public function status_endpoint_returns_real_control_mode_and_null_actual_volume()
    {
        config(['hardware.driver' => 'real']);
        config(['hardware.real_execution_enabled' => true]);

        $response = $this->actingAs($this->admin)->getJson(
            route('admin.stations.tv-control.status', ['station' => $this->station->id])
        );

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertEquals('REAL', $data['control_mode']);
        $this->assertNull($data['volume_limit_config']['current_volume']);
        $this->assertEquals('UNVERIFIED', $data['volume_limit_config']['enforcement_status']);
    }

    /** @test */
    public function volume_up_in_real_mode_records_event_status_as_command_sent_never_sent_to_simulator()
    {
        config(['hardware.driver' => 'real']);
        config(['hardware.real_execution_enabled' => true]);
        config(['hardware.tv_control.volume_frame_interval_ms' => 0]);

        $response = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-volume.store', ['station' => $this->station->id]),
            [
                'command' => 'VOLUME_UP',
                'step' => 5,
            ]
        );

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'status' => 'COMMAND_SENT',
                'execution_status' => 'COMMAND_SENT',
            ],
        ]);

        $event = TvAlertEvent::latest('id')->first();
        $this->assertNotNull($event);
        $this->assertEquals('COMMAND_SENT', $event->status);
        $this->assertEquals('COMMAND_SENT', $event->execution_status);
        $this->assertNotEquals('SENT_TO_SIMULATOR', $event->status);
    }

    /** @test */
    public function tv_offline_in_real_mode_returns_failed_and_marks_event_failed()
    {
        config(['hardware.driver' => 'real']);
        config(['hardware.real_execution_enabled' => true]);

        // Put fake transport into offline scenario
        $this->fakeTransport->setScenario(FakeSamsungWebSocketTransport::SCENARIO_OFFLINE);

        $response = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-volume.store', ['station' => $this->station->id]),
            [
                'command' => 'VOLUME_UP',
                'step' => 5,
            ]
        );

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'error_code' => 'OFFLINE',
            'data' => [
                'status' => 'FAILED',
                'execution_status' => 'FAILED',
            ],
        ]);

        $event = TvAlertEvent::latest('id')->first();
        $this->assertNotNull($event);
        $this->assertEquals('FAILED', $event->status);
    }

    /** @test */
    public function concurrent_volume_commands_on_same_station_are_blocked_by_atomic_lock()
    {
        config(['hardware.driver' => 'real']);
        config(['hardware.real_execution_enabled' => true]);

        // Manually acquire lock for this station
        $lock = \Illuminate\Support\Facades\Cache::lock("tv_vol_mutex:{$this->station->id}", 5);
        $this->assertTrue($lock->get());

        try {
            $response = $this->actingAs($this->admin)->postJson(
                route('admin.stations.tv-volume.store', ['station' => $this->station->id]),
                [
                    'command' => 'VOLUME_UP',
                    'step' => 5,
                ]
            );

            $response->assertStatus(429);
            $response->assertJson([
                'success' => false,
                'error_code' => 'RATE_LIMITED',
            ]);
        } finally {
            $lock->release();
        }
    }

    /** @test */
    public function mock_mode_is_never_reported_as_real()
    {
        config(['hardware.driver' => 'mock']);
        config(['hardware.real_execution_enabled' => false]);

        $response = $this->actingAs($this->admin)->getJson(
            route('admin.stations.tv-control.status', ['station' => $this->station->id])
        );

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals('MOCK', $data['control_mode']);
        $this->assertEquals('MOCK_SAMSUNG_TV', $data['driver_type']);
        $this->assertNotEquals('REAL', $data['control_mode']);
    }
}
