<?php

namespace Tests\Unit\Hardware;

use App\Models\PhysicalDevice;
use App\Services\Hardware\Drivers\Ps4HenNotificationDriver;
use App\Services\Hardware\DTO\HardwareCommandResult;
use App\Services\Hardware\Transports\GoldHenHttpNotificationTransport;
use App\Services\Hardware\Transports\MockPs4HenTransport;
use InvalidArgumentException;
use Tests\TestCase;

class Ps4HenNotificationDriverTest extends TestCase
{
    protected MockPs4HenTransport $transport;
    protected Ps4HenNotificationDriver $driver;
    protected PhysicalDevice $device;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = new MockPs4HenTransport();
        $this->driver = new Ps4HenNotificationDriver($this->transport, null, false);

        $this->device = new PhysicalDevice([
            'id' => 102,
            'name' => 'PS4 Test Console',
            'category' => PhysicalDevice::CATEGORY_GAME_CONSOLE,
            'brand' => PhysicalDevice::BRAND_PLAYSTATION,
            'model' => PhysicalDevice::MODEL_PS4_HEN,
            'ip_address' => '192.168.1.160',
            'mac_address' => '00:1A:79:11:22:33',
            'is_active' => true,
        ]);
        $this->device->id = 102;
    }

    public function test_driver_identifies_as_ps4_hen_notify()
    {
        $this->assertEquals(Ps4HenNotificationDriver::DRIVER_TYPE, $this->driver->getDriverType());
        $this->assertContains('SHOW_NOTIFICATION', $this->driver->getSupportedCommands());
        $this->assertContains('SHOW_ALERT', $this->driver->getSupportedCommands());
    }

    public function test_probe_and_pair_succeeds_with_mock_transport()
    {
        $probeResult = $this->driver->probe($this->device);
        $this->assertTrue($probeResult->success);
        $this->assertEquals('SUCCESS', $probeResult->resultCode);

        $pairResult = $this->driver->pair($this->device);
        $this->assertTrue($pairResult->success);
        $this->assertEquals('NOT_REQUIRED', $pairResult->resultCode);
    }

    public function test_send_notification_transmits_message_in_mock_mode()
    {
        $result = $this->driver->sendCommand($this->device, 'SHOW_ALERT', [
            'title' => 'BILLING',
            'message' => 'Waktu bermain tersisa 10 menit.',
        ]);

        $this->assertTrue($result->success);
        $this->assertEquals('ACK_HTTP', $result->resultCode);

        $sent = $this->transport->getSentNotifications();
        $this->assertCount(1, $sent);
        $this->assertEquals('BILLING: Waktu bermain tersisa 10 menit.', $sent[0]['message']);
    }

    public function test_unverified_protocol_returns_unsupported_capability()
    {
        $this->transport->setProtocolVerified(false);

        $result = $this->driver->sendCommand($this->device, 'SHOW_ALERT', [
            'message' => 'Test message',
        ]);

        $this->assertFalse($result->success);
        $this->assertEquals('CAPABILITY_UNSUPPORTED', $result->resultCode);
        $this->assertStringContainsString('belum diverifikasi', $result->message);
    }

    public function test_goldhen_http_transport_strictly_blocks_port_9090_binloader()
    {
        $realTransport = new GoldHenHttpNotificationTransport(null, true);

        // Sending to port 9090 must be blocked immediately
        $res = $realTransport->sendNotification('192.168.1.160', 9090, 'Hello PS4', 2000);

        $this->assertFalse($res['success']);
        $this->assertEquals('UNSUPPORTED_PROTOCOL', $res['status']);
        $this->assertStringContainsString('BinLoader', $res['message']);
    }

    public function test_goldhen_http_transport_returns_not_configured_when_unverified()
    {
        // When protocol verified flag is false
        $unverifiedTransport = new GoldHenHttpNotificationTransport(null, false);

        $res = $unverifiedTransport->sendNotification('192.168.1.160', 8080, 'Hello PS4', 2000);

        $this->assertFalse($res['success']);
        $this->assertEquals('NOT_CONFIGURED', $res['status']);
    }
}
