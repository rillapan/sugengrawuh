<?php

namespace Tests\Unit;

use App\Exceptions\HardwareSimulationException;
use App\Models\PhysicalDevice;
use App\Services\Hardware\DeviceStatusManagementService;
use App\Services\Hardware\Drivers\SamsungTvDriver;
use App\Services\Hardware\DTO\HardwareCommandResult;
use App\Services\Hardware\Transports\FakeSamsungWebSocketTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class SamsungTvDriverTest extends TestCase
{
    use RefreshDatabase;

    protected PhysicalDevice $device;
    protected FakeSamsungWebSocketTransport $fakeTransport;
    protected DeviceStatusManagementService $statusService;
    protected SamsungTvDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('hardware.driver', 'real');
        Config::set('hardware.default_ports.SAMSUNG_WS', 8001);

        $this->device = PhysicalDevice::create([
            'name' => 'Samsung TV 50U8500 Unit 1',
            'category' => PhysicalDevice::CATEGORY_SMART_TV,
            'brand' => PhysicalDevice::BRAND_SAMSUNG,
            'model' => '50U8500',
            'mac_address' => '00:11:22:33:44:88',
            'ip_address' => '192.168.1.188',
            'connection_status' => PhysicalDevice::CONNECTION_UNVERIFIED,
            'pairing_status' => PhysicalDevice::PAIRING_UNVERIFIED,
            'is_active' => true,
        ]);

        $this->fakeTransport = new FakeSamsungWebSocketTransport();
        $this->statusService = new DeviceStatusManagementService();
        $this->driver = new SamsungTvDriver($this->fakeTransport, $this->statusService, false);
    }

    /** @test */
    public function driver_identifies_as_real_samsung_tv_with_supported_commands()
    {
        $this->assertEquals('REAL_SAMSUNG_TV', $this->driver->getDriverType());
        $this->assertContains('MUTE', $this->driver->getSupportedCommands());
        $this->assertContains('UNMUTE', $this->driver->getSupportedCommands());
        $this->assertContains('STANDBY', $this->driver->getSupportedCommands());
        $this->assertContains('GET_STATUS', $this->driver->getSupportedCommands());
        // POWER_ON is not in supported commands because it is an unverified capability
        $this->assertNotContains('POWER_ON', $this->driver->getSupportedCommands());
    }

    /** @test */
    public function probe_succeeds_with_fake_transport()
    {
        $this->fakeTransport->setScenario(FakeSamsungWebSocketTransport::SCENARIO_SUCCESS);
        $result = $this->driver->probe($this->device);

        $this->assertTrue($result->success);
        $this->assertEquals('SUCCESS', $result->resultCode);
        $this->assertEquals('REAL_SAMSUNG_TV', $result->driverType);
        $this->assertFalse($result->simulationMode);
    }

    /** @test */
    public function probe_detects_offline_device()
    {
        $this->fakeTransport->setScenario(FakeSamsungWebSocketTransport::SCENARIO_OFFLINE);
        $result = $this->driver->probe($this->device);

        $this->assertFalse($result->success);
        $this->assertEquals('OFFLINE', $result->resultCode);
    }

    /** @test */
    public function probe_detects_timeout()
    {
        $this->fakeTransport->setScenario(FakeSamsungWebSocketTransport::SCENARIO_TIMEOUT);
        $result = $this->driver->probe($this->device);

        $this->assertFalse($result->success);
        $this->assertEquals('TIMEOUT', $result->resultCode);
    }

    /** @test */
    public function pairing_handshake_succeeds_and_captures_token()
    {
        $this->fakeTransport->setScenario(FakeSamsungWebSocketTransport::SCENARIO_SUCCESS);
        $result = $this->driver->pair($this->device);

        $this->assertTrue($result->success);
        $this->assertEquals('PAIRED', $result->resultCode);
        $this->assertNotNull($this->fakeTransport->getLastToken());
    }

    /** @test */
    public function pairing_rejected_by_user_on_screen_returns_rejected()
    {
        $this->fakeTransport->setScenario(FakeSamsungWebSocketTransport::SCENARIO_AUTH_REJECTED);
        $result = $this->driver->pair($this->device);

        $this->assertFalse($result->success);
        $this->assertEquals('COMMAND_REJECTED', $result->resultCode);
        $this->assertStringContainsString('Otorisasi pairing ditolak', $result->message);
    }

    /** @test */
    public function mute_command_transmits_key_and_returns_transport_acknowledgement()
    {
        $result = $this->driver->sendCommand($this->device, 'MUTE');

        $this->assertTrue($result->success);
        $this->assertEquals('ACK_TRANSPORT', $result->resultCode);
        $this->assertContains('KEY_MUTE', $this->fakeTransport->getSentKeys());
        $this->assertStringContainsString('ACK_TRANSPORT', $result->message);
        $this->assertStringContainsString('KEY_MUTE toggle', $result->message);
        $this->assertEquals('UNKNOWN_TOGGLE', $result->telemetry['audio_state']);
        $this->assertFalse($result->telemetry['is_idempotent']);
        $this->assertFalse($result->telemetry['physical_state_verified']);
    }

    /** @test */
    public function unmute_command_transmits_key_and_marks_physical_state_unverified()
    {
        $result = $this->driver->sendCommand($this->device, 'UNMUTE');

        $this->assertTrue($result->success);
        $this->assertEquals('ACK_TRANSPORT', $result->resultCode);
        $this->assertContains('KEY_MUTE', $this->fakeTransport->getSentKeys());
        $this->assertStringContainsString('PHYSICAL_STATE_UNVERIFIED', $result->message);
        $this->assertEquals('UNKNOWN_TOGGLE', $result->telemetry['audio_state']);
        $this->assertFalse($result->telemetry['is_idempotent']);
        $this->assertFalse($result->telemetry['physical_state_verified']);
    }

    /** @test */
    public function repeated_mute_and_unmute_acts_as_toggle_without_mutating_local_audio_status()
    {
        // 1. First MUTE call
        $first = $this->driver->sendCommand($this->device, 'MUTE');
        $this->assertTrue($first->success);
        $this->assertEquals('UNKNOWN_TOGGLE', $first->telemetry['audio_state']);

        // 2. Second MUTE call (acts as physical toggle, NOT idempotent setter)
        $second = $this->driver->sendCommand($this->device, 'MUTE');
        $this->assertTrue($second->success);
        $this->assertEquals('UNKNOWN_TOGGLE', $second->telemetry['audio_state']);

        // 3. UNMUTE call (also sends KEY_MUTE)
        $unmute = $this->driver->sendCommand($this->device, 'UNMUTE');
        $this->assertTrue($unmute->success);
        $this->assertEquals('UNKNOWN_TOGGLE', $unmute->telemetry['audio_state']);

        // Total KEY_MUTE sent must be 3 frames
        $muteKeys = array_filter($this->fakeTransport->getSentKeys(), fn ($k) => $k === 'KEY_MUTE');
        $this->assertCount(3, $muteKeys);

        // Device model in database must NOT be mutated with false audio state
        $this->device->refresh();
        $this->assertNull($this->device->verified_capabilities);
    }

    /** @test */
    public function standby_command_transmits_key_power()
    {
        $result = $this->driver->sendCommand($this->device, 'STANDBY');

        $this->assertTrue($result->success);
        $this->assertEquals('ACK_TRANSPORT', $result->resultCode);
        $this->assertContains('KEY_POWER', $this->fakeTransport->getSentKeys());
    }

    /** @test */
    public function power_on_is_rejected_as_unsupported_capability_if_unverified()
    {
        // $device->verified_capabilities is null / empty
        $result = $this->driver->sendCommand($this->device, 'POWER_ON');

        $this->assertFalse($result->success);
        $this->assertEquals('CAPABILITY_UNSUPPORTED', $result->resultCode);
        $this->assertStringContainsString('belum diverifikasi untuk model TV ini', $result->message);
        $this->assertEmpty($this->fakeTransport->getSentKeys());
    }

    /** @test */
    public function power_on_is_permitted_if_explicitly_in_verified_capabilities()
    {
        $this->device->verified_capabilities = ['POWER_ON'];
        $this->device->save();

        $result = $this->driver->sendCommand($this->device, 'POWER_ON');

        $this->assertTrue($result->success);
        $this->assertEquals('ACK_TRANSPORT', $result->resultCode);
        $this->assertContains('KEY_POWER', $this->fakeTransport->getSentKeys());
    }

    /** @test */
    public function get_status_retrieves_rest_info()
    {
        $result = $this->driver->getStatus($this->device);

        $this->assertTrue($result->success);
        $this->assertEquals('SUCCESS', $result->resultCode);
        $this->assertEquals('on', $result->simulatedState['power_state'] ?? null);
    }

    /** @test */
    public function malformed_protocol_response_is_handled_gracefully()
    {
        $this->fakeTransport->setScenario(FakeSamsungWebSocketTransport::SCENARIO_MALFORMED);
        $result = $this->driver->pair($this->device);

        $this->assertFalse($result->success);
        $this->assertEquals('MALFORMED_RESPONSE', $result->resultCode);
    }

    /** @test */
    public function credential_secrecy_is_preserved_and_token_never_leaked()
    {
        $this->fakeTransport->setCustomToken('super_secret_samsung_token_xyz999');
        $result = $this->driver->pair($this->device);

        $this->assertTrue($result->success);
        $serialized = json_encode($result);

        // Result DTO must not expose the raw token
        $this->assertStringNotContainsString('super_secret_samsung_token_xyz999', $serialized);

        // Eloquent model must hide pairing_payload
        $this->device->pairing_payload = 'super_secret_samsung_token_xyz999';
        $deviceJson = json_encode($this->device->toArray());
        $this->assertStringNotContainsString('super_secret_samsung_token_xyz999', $deviceJson);
    }

    /** @test */
    public function inactive_samsung_tv_is_rejected()
    {
        $this->device->is_active = false;
        $this->device->save();

        $this->expectException(HardwareSimulationException::class);
        $this->expectExceptionMessage('berstatus nonaktif');

        $this->driver->probe($this->device);
    }

    /** @test */
    public function incompatible_category_brand_is_rejected()
    {
        $wrongDevice = PhysicalDevice::create([
            'name' => 'Wrong Category Device',
            'category' => PhysicalDevice::CATEGORY_SMART_RELAY,
            'brand' => PhysicalDevice::BRAND_SHELLY,
            'mac_address' => '00:11:22:33:44:77',
            'ip_address' => '192.168.1.177',
            'is_active' => true,
        ]);

        $this->expectException(HardwareSimulationException::class);
        $this->expectExceptionMessage("Driver mengharapkan 'SMART_TV (SAMSUNG)'");

        $this->driver->probe($wrongDevice);
    }
}
