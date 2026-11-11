<?php

namespace Tests\Unit\Hardware;

use App\Models\PhysicalDevice;
use App\Services\Hardware\Drivers\Ps3HenNotificationDriver;
use App\Services\Hardware\DTO\HardwareCommandResult;
use App\Services\Hardware\Transports\MockPs3HenTransport;
use InvalidArgumentException;
use Tests\TestCase;

class Ps3HenNotificationDriverTest extends TestCase
{
    protected MockPs3HenTransport $transport;
    protected Ps3HenNotificationDriver $driver;
    protected PhysicalDevice $device;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = new MockPs3HenTransport();
        $this->driver = new Ps3HenNotificationDriver($this->transport, null, false);

        $this->device = new PhysicalDevice([
            'id' => 101,
            'name' => 'PS3 Test Console',
            'category' => PhysicalDevice::CATEGORY_GAME_CONSOLE,
            'brand' => PhysicalDevice::BRAND_SONY,
            'model' => PhysicalDevice::MODEL_PS3_HEN,
            'ip_address' => '192.168.1.150',
            'mac_address' => '00:1A:79:AB:CD:EF',
            'is_active' => true,
        ]);
        $this->device->id = 101;
    }

    public function test_driver_identifies_as_ps3_hen_http()
    {
        $this->assertEquals(Ps3HenNotificationDriver::DRIVER_TYPE, $this->driver->getDriverType());
        $this->assertContains('SHOW_NOTIFICATION', $this->driver->getSupportedCommands());
        $this->assertContains('SHOW_ALERT', $this->driver->getSupportedCommands());
        $this->assertContains('PROBE', $this->driver->getSupportedCommands());
    }

    public function test_probe_returns_online_with_mock_transport()
    {
        $result = $this->driver->probe($this->device);

        $this->assertTrue($result->success);
        $this->assertEquals('SUCCESS', $result->resultCode);
        $this->assertEquals('ONLINE', $result->simulatedState['status'] ?? '');
    }

    public function test_probe_handles_offline_scenario()
    {
        $this->transport->setScenario('OFFLINE');
        $result = $this->driver->probe($this->device);

        $this->assertFalse($result->success);
        $this->assertEquals('OFFLINE', $result->resultCode);
    }

    public function test_probe_handles_timeout_scenario()
    {
        $this->transport->setScenario('TIMEOUT');
        $result = $this->driver->probe($this->device);

        $this->assertFalse($result->success);
        $this->assertEquals('TIMEOUT', $result->resultCode);
    }

    public function test_pair_returns_not_required()
    {
        $result = $this->driver->pair($this->device);

        $this->assertTrue($result->success);
        $this->assertEquals('NOT_REQUIRED', $result->resultCode);
    }

    public function test_send_notification_transmits_sanitized_message()
    {
        $result = $this->driver->sendCommand($this->device, 'SHOW_ALERT', [
            'title' => 'KASIR',
            'message' => "<b>Waktu tinggal 10 menit!</b>\nSilakan perpanjang.",
        ]);

        $this->assertTrue($result->success);
        $this->assertEquals('ACK_HTTP', $result->resultCode);

        $sent = $this->transport->getSentNotifications();
        $this->assertCount(1, $sent);
        $this->assertEquals('192.168.1.150', $sent[0]['ip']);
        $this->assertEquals('KASIR: Waktu tinggal 10 menit! Silakan perpanjang.', $sent[0]['message']);
    }

    public function test_message_sanitizer_truncates_long_strings()
    {
        $longText = str_repeat('A', 200);
        $sanitized = $this->driver->sanitizeMessage($longText);

        $this->assertLessThanOrEqual(120, mb_strlen($sanitized));
        $this->assertStringEndsWith('...', $sanitized);
    }

    public function test_empty_message_returns_error()
    {
        $result = $this->driver->sendCommand($this->device, 'SHOW_ALERT', [
            'message' => '   ',
        ]);

        $this->assertFalse($result->success);
        $this->assertEquals('EMPTY_MESSAGE', $result->resultCode);
    }

    public function test_incompatible_category_throws_exception()
    {
        $invalidDevice = new PhysicalDevice([
            'category' => PhysicalDevice::CATEGORY_SMART_TV,
            'brand' => PhysicalDevice::BRAND_SAMSUNG,
            'ip_address' => '192.168.1.151',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->driver->sendCommand($invalidDevice, 'SHOW_ALERT', ['message' => 'Test']);
    }
}
