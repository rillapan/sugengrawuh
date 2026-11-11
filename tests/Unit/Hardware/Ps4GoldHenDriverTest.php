<?php

namespace Tests\Unit\Hardware;

use App\Models\PhysicalDevice;
use App\Services\Hardware\Drivers\Ps4GoldHenDriver;
use App\Services\Hardware\Transports\GoldHenHttpNotificationTransport;
use App\Services\Hardware\Transports\MockPs4HenTransport;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class Ps4GoldHenDriverTest extends TestCase
{
    use DatabaseTransactions;

    protected PhysicalDevice $validConsole;
    protected MockPs4HenTransport $mockTransport;
    protected Ps4GoldHenDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validConsole = PhysicalDevice::create([
            'name' => 'PS4 Station 02',
            'category' => PhysicalDevice::CATEGORY_GAME_CONSOLE,
            'brand' => PhysicalDevice::BRAND_SONY,
            'model' => PhysicalDevice::MODEL_PS4_HEN,
            'ip_address' => '192.168.1.182',
            'is_active' => true,
        ]);

        $this->mockTransport = new MockPs4HenTransport();
        $this->driver = new Ps4GoldHenDriver($this->mockTransport);
    }

    /** @test */
    public function driver_identifies_as_ps4_goldhen()
    {
        $this->assertEquals(Ps4GoldHenDriver::DRIVER_TYPE, $this->driver->getDriverType());
        $this->assertEquals('PS4_GOLDHEN', $this->driver->getDriverType());
    }

    /** @test */
    public function scenario_c_goldhen_http_transport_strictly_blocks_port_9090_binloader()
    {
        $realTransport = new GoldHenHttpNotificationTransport(null, true);

        // Attempting to send notification or check connection to port 9090 MUST be blocked
        $result = $realTransport->sendNotification('192.168.1.52', 9090, 'Test Alert', 2000);
        $this->assertFalse($result['success']);
        $this->assertEquals('UNSUPPORTED_PROTOCOL', $result['status']);
        $this->assertStringContainsString('BinLoader', $result['message']);
        $this->assertEquals('BINLOADER_PORT_MISMATCH', $result['data']['blocked_reason']);

        $conn = $realTransport->checkConnection('192.168.1.52', 9090, 2000);
        $this->assertFalse($conn['success']);
        $this->assertEquals('UNSUPPORTED_PROTOCOL', $conn['status']);
        $this->assertStringContainsString('BinLoader', $conn['message']);
    }

    /** @test */
    public function unverified_protocol_returns_unsupported_capability()
    {
        $realTransport = new GoldHenHttpNotificationTransport(null, false);
        $unverifiedDriver = new Ps4GoldHenDriver($realTransport);

        $probe = $unverifiedDriver->probe($this->validConsole);
        $this->assertFalse($probe->success);
        $this->assertEquals('CAPABILITY_UNSUPPORTED', $probe->resultCode);
    }

    /** @test */
    public function outbound_polling_mode_queues_message_as_pending()
    {
        // Driver without transport represents outbound polling mode
        $outboundDriver = new Ps4GoldHenDriver();
        $result = $outboundDriver->sendNotification($this->validConsole, 'Peringatan sisa waktu 10 menit');

        $this->assertTrue($result->success);
        $this->assertEquals('PENDING', $result->resultCode);
        $this->assertEquals('OUTBOUND_POLLING', $result->simulatedState['transport']);
        $this->assertStringContainsString('sugengrawuh_notify.prx', $result->message);
    }

    /** @test */
    public function pair_generates_device_token_for_agent_authentication()
    {
        $outboundDriver = new Ps4GoldHenDriver();
        $pairResult = $outboundDriver->pair($this->validConsole);

        $this->assertTrue($pairResult->success);
        $this->assertEquals('TOKEN_GENERATED', $pairResult->resultCode);
        $this->assertNotEmpty($pairResult->simulatedState['token']);
        $this->assertNotEmpty($this->validConsole->device_token_hash);
    }
}
