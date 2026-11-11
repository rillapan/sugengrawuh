<?php

namespace Tests\Unit\Hardware;

use App\Models\PhysicalDevice;
use App\Services\Hardware\Drivers\SamsungTvDriver;
use App\Services\Hardware\Transports\FakeSamsungWebSocketTransport;
use Tests\TestCase;

class SamsungTvDriverVolumeTest extends TestCase
{
    protected PhysicalDevice $samsungDevice;
    protected FakeSamsungWebSocketTransport $fakeTransport;
    protected SamsungTvDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->samsungDevice = new PhysicalDevice([
            'name' => 'TV PS 1 [UJI COBA]',
            'category' => PhysicalDevice::CATEGORY_SMART_TV,
            'brand' => PhysicalDevice::BRAND_SAMSUNG,
            'model' => 'FE60EF',
            'ip_address' => '192.168.200.240',
            'mac_address' => '98:4E:8A:2A:21:6C',
            'is_active' => true,
            'connection_status' => PhysicalDevice::CONNECTION_ONLINE,
            'pairing_status' => PhysicalDevice::PAIRING_PAIRED,
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
        $this->samsungDevice->id = 4;

        $this->fakeTransport = new FakeSamsungWebSocketTransport(FakeSamsungWebSocketTransport::SCENARIO_SUCCESS);
        $this->driver = new SamsungTvDriver($this->fakeTransport, null, true);
    }

    /** @test */
    public function volume_up_sends_multiple_frames_matching_step()
    {
        config(['hardware.tv_control.volume_frame_interval_ms' => 0]); // Zero interval for fast testing

        $result = $this->driver->sendCommand($this->samsungDevice, SamsungTvDriver::CMD_VOLUME_UP, ['step' => 5]);

        $this->assertTrue($result->success);
        $this->assertEquals('ACK_TRANSPORT', $result->resultCode);
        $this->assertFalse($result->telemetry['physical_state_verified']);
        $this->assertEquals(5, $result->telemetry['frames_sent']);

        $sentKeys = $this->fakeTransport->getSentKeys();
        $this->assertCount(5, $sentKeys);
        $this->assertEquals(['KEY_VOLUP', 'KEY_VOLUP', 'KEY_VOLUP', 'KEY_VOLUP', 'KEY_VOLUP'], $sentKeys);
    }

    /** @test */
    public function volume_down_sends_multiple_frames_bounded_by_max_step()
    {
        config(['hardware.tv_control.volume_frame_interval_ms' => 0]);
        config(['hardware.tv_control.max_step_frames' => 5]);

        // Attempt step of 10, should be capped at 5
        $result = $this->driver->sendCommand($this->samsungDevice, SamsungTvDriver::CMD_VOLUME_DOWN, ['step' => 10]);

        $this->assertTrue($result->success);
        $this->assertEquals(5, $result->telemetry['frames_sent']);

        $sentKeys = $this->fakeTransport->getSentKeys();
        $this->assertCount(5, $sentKeys);
        $this->assertEquals(['KEY_VOLDOWN', 'KEY_VOLDOWN', 'KEY_VOLDOWN', 'KEY_VOLDOWN', 'KEY_VOLDOWN'], $sentKeys);
    }

    /** @test */
    public function mute_toggle_is_supported_and_maps_to_key_mute()
    {
        $result = $this->driver->sendCommand($this->samsungDevice, SamsungTvDriver::CMD_MUTE_TOGGLE);

        $this->assertTrue($result->success);
        $this->assertEquals('ACK_TRANSPORT', $result->resultCode);
        $this->assertEquals('KEY_MUTE', $result->telemetry['remote_key']);
        $this->assertFalse($result->telemetry['physical_state_verified']);
        $this->assertEquals('UNKNOWN_TOGGLE', $result->telemetry['audio_state']);

        $sentKeys = $this->fakeTransport->getSentKeys();
        $this->assertContains('KEY_MUTE', $sentKeys);
    }

    /** @test */
    public function mute_and_unmute_commands_map_to_key_mute_with_honest_unverified_telemetry()
    {
        $muteResult = $this->driver->sendCommand($this->samsungDevice, SamsungTvDriver::CMD_MUTE);
        $this->assertTrue($muteResult->success);
        $this->assertFalse($muteResult->telemetry['physical_state_verified']);

        $unmuteResult = $this->driver->sendCommand($this->samsungDevice, SamsungTvDriver::CMD_UNMUTE);
        $this->assertTrue($unmuteResult->success);
        $this->assertFalse($unmuteResult->telemetry['physical_state_verified']);

        $sentKeys = $this->fakeTransport->getSentKeys();
        $this->assertEquals(['KEY_MUTE', 'KEY_MUTE'], $sentKeys);
    }

    /** @test */
    public function set_volume_returns_unsupported_capability_when_not_in_verified_capabilities()
    {
        // Verified capabilities of $this->samsungDevice does not include SET_VOLUME
        $result = $this->driver->sendCommand($this->samsungDevice, SamsungTvDriver::CMD_SET_VOLUME, ['target_volume' => 30]);

        $this->assertFalse($result->success);
        $this->assertEquals('CAPABILITY_UNSUPPORTED', $result->resultCode);
        $this->assertStringContainsString('SET_VOLUME', $result->message);
        $this->assertEmpty($this->fakeTransport->getSentKeys());
    }

    /** @test */
    public function get_volume_returns_unverified_with_null_volume()
    {
        $result = $this->driver->sendCommand($this->samsungDevice, SamsungTvDriver::CMD_GET_VOLUME);

        $this->assertTrue($result->success);
        $this->assertEquals('UNVERIFIED', $result->resultCode);
        $this->assertNull($result->telemetry['volume']);
        $this->assertFalse($result->telemetry['physical_state_verified']);
    }

    /** @test */
    public function driver_handles_tv_offline_gracefully()
    {
        $this->fakeTransport->setScenario(FakeSamsungWebSocketTransport::SCENARIO_OFFLINE);

        $result = $this->driver->sendCommand($this->samsungDevice, SamsungTvDriver::CMD_VOLUME_UP, ['step' => 5]);

        $this->assertFalse($result->success);
        $this->assertEquals('OFFLINE', $result->resultCode);
    }

    /** @test */
    public function driver_does_not_send_commands_to_wrong_ip()
    {
        config(['hardware.tv_control.volume_frame_interval_ms' => 0]);

        $tv1 = clone $this->samsungDevice;
        $tv1->id = 4;
        $tv1->ip_address = '192.168.200.240';

        $tv2 = clone $this->samsungDevice;
        $tv2->id = 5;
        $tv2->ip_address = '192.168.200.245';

        $this->driver->sendCommand($tv1, SamsungTvDriver::CMD_VOLUME_UP, ['step' => 1]);

        $attempts = $this->fakeTransport->getConnectionAttempts();
        $this->assertNotEmpty($attempts);
        $lastAttempt = end($attempts);
        $this->assertEquals('192.168.200.240', $lastAttempt['ip']);
        $this->assertNotEquals('192.168.200.245', $lastAttempt['ip']);
    }
}
