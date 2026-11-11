<?php

namespace Tests\Unit\Hardware;

use App\Models\PhysicalDevice;
use App\Services\Hardware\Drivers\Ps3WebmanDriver;
use App\Services\Hardware\Transports\MockPs3HenTransport;
use App\Services\Hardware\Transports\Ps3HenHttpTransport;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use Tests\TestCase;

class Ps3WebmanDriverTest extends TestCase
{
    protected PhysicalDevice $validConsole;
    protected MockPs3HenTransport $mockTransport;
    protected Ps3WebmanDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validConsole = new PhysicalDevice([
            'id' => 101,
            'name' => 'PS3 Station 01',
            'category' => PhysicalDevice::CATEGORY_GAME_CONSOLE,
            'brand' => PhysicalDevice::BRAND_SONY,
            'model' => PhysicalDevice::MODEL_PS3_HEN,
            'ip_address' => '192.168.1.51',
            'is_active' => true,
        ]);
        $this->validConsole->id = 101;

        $this->mockTransport = new MockPs3HenTransport();
        $this->driver = new Ps3WebmanDriver($this->mockTransport);
    }

    /** @test */
    public function driver_identifies_as_ps3_webman()
    {
        $this->assertEquals(Ps3WebmanDriver::DRIVER_TYPE, $this->driver->getDriverType());
        $this->assertEquals('PS3_WEBMAN', $this->driver->getDriverType());
        $this->assertContains('SHOW_NOTIFICATION', $this->driver->getSupportedCommands());
        $this->assertContains('SHOW_ALERT', $this->driver->getSupportedCommands());
        $this->assertContains('PROBE', $this->driver->getSupportedCommands());
        $this->assertContains('GET_STATUS', $this->driver->getSupportedCommands());
    }

    /** @test */
    public function scenario_a_message_sanitizer_truncates_long_strings_and_strips_html_and_scripts()
    {
        // 1. Strip script tag with its body and HTML tags
        $malicious = "<script>alert('xss')</script><b>Peringatan:</b> Waktu habis!\nHubungi kasir.";
        $sanitized = $this->driver->sanitizeMessage($malicious);
        $this->assertStringNotContainsString('<script>', $sanitized);
        $this->assertStringNotContainsString('</script>', $sanitized);
        $this->assertStringNotContainsString("alert('xss')", $sanitized);
        $this->assertStringNotContainsString('<b>', $sanitized);
        $this->assertStringNotContainsString('</b>', $sanitized);
        $this->assertStringNotContainsString("\n", $sanitized);
        $this->assertStringContainsString('Peringatan: Waktu habis! Hubungi kasir.', $sanitized);

        // 2. Control characters and multiple whitespace normalization
        $controlChars = "Line 1\r\n\tLine 2   \x00\x08with\x1Fspaces";
        $cleanControl = $this->driver->sanitizeMessage($controlChars);
        $this->assertEquals('Line 1 Line 2 withspaces', $cleanControl);

        // 3. Truncate messages longer than 120 characters strictly to <= 120 chars
        $longString = str_repeat('A', 150);
        $truncated = $this->driver->sanitizeMessage($longString, 120);
        $this->assertLessThanOrEqual(120, mb_strlen($truncated));
        $this->assertEquals(120, mb_strlen($truncated));
        $this->assertStringEndsWith('...', $truncated);
    }

    /** @test */
    public function empty_message_after_sanitization_returns_error()
    {
        $result = $this->driver->sendNotification($this->validConsole, "   <script>var x = 1;</script><b>   </b>\n\t  ");
        $this->assertFalse($result->success);
        $this->assertEquals('EMPTY_MESSAGE', $result->resultCode);
    }

    /** @test */
    public function scenario_b_url_encoding_uses_rawurlencode_in_endpoint_path()
    {
        $message = 'Waktu tinggal 10 menit! (Hubungi Kasir)';
        $path = $this->driver->buildNotificationPath($message);
        $url = $this->driver->buildNotificationUrl($this->validConsole, $message);

        // rawurlencode must encode space as %20, never '+'
        $this->assertStringNotContainsString('+', $path);
        $this->assertStringContainsString('%20', $path);
        $this->assertStringContainsString('%21', $path); // Exclamation mark
        $this->assertStringContainsString('%28', $path); // Open paren
        $this->assertStringContainsString('%29', $path); // Close paren

        $expectedEncoded = rawurlencode($this->driver->sanitizeMessage($message));
        $this->assertEquals("/popup.ps3/{$expectedEncoded}", $path);
        $this->assertEquals("http://192.168.1.51:80/popup.ps3/{$expectedEncoded}", $url);
    }

    /** @test */
    public function scenario_b_send_notification_returns_http_accepted_with_honest_unverified_telemetry()
    {
        $result = $this->driver->sendNotification($this->validConsole, 'Waktu bermain tersisa 10 menit.');

        $this->assertTrue($result->success);
        $this->assertEquals(Ps3WebmanDriver::STATUS_HTTP_ACCEPTED, $result->resultCode);
        $this->assertEquals('HTTP_ACCEPTED', $result->resultCode);
        $this->assertStringContainsString('webMAN MOD', $result->message);
        $this->assertStringContainsString('verifikasi fisik', $result->message);

        // Honest telemetry: Never claim popup is physically displayed merely because HTTP 200 was received
        $this->assertIsArray($result->telemetry);
        $this->assertArrayHasKey('physical_display_verified', $result->telemetry);
        $this->assertFalse($result->telemetry['physical_display_verified']);
        $this->assertEquals('HTTP_ACCEPTED', $result->telemetry['status_type']);
        $this->assertEquals('ACCEPTED', $result->telemetry['normalized_status']);
        $this->assertEquals('HTTP_WEBMAN', $result->telemetry['transport']);

        // Verify sent notification to simulated transport
        $dispatched = $this->mockTransport->getSentNotifications();
        $this->assertCount(1, $dispatched);
        $this->assertEquals('192.168.1.51', $dispatched[0]['ip']);
        $this->assertEquals(80, $dispatched[0]['port']);
        $this->assertEquals('Waktu bermain tersisa 10 menit.', $dispatched[0]['message']);
        $this->assertEquals(5000, $dispatched[0]['timeout_ms']);
    }

    /** @test */
    public function send_command_routes_show_alert_to_send_notification()
    {
        $result = $this->driver->sendCommand($this->validConsole, 'SHOW_ALERT', [
            'title' => 'PERINGATAN',
            'message' => 'Billing tersisa 5 menit!',
        ]);

        $this->assertTrue($result->success);
        $this->assertEquals('HTTP_ACCEPTED', $result->resultCode);
        $this->assertFalse($result->telemetry['physical_display_verified']);

        $dispatched = $this->mockTransport->getSentNotifications();
        $this->assertCount(1, $dispatched);
        $this->assertEquals('PERINGATAN: Billing tersisa 5 menit!', $dispatched[0]['message']);
    }

    /** @test */
    public function scenario_b_handles_offline_timeout_and_security_error_scenarios()
    {
        // 1. Offline
        $this->mockTransport->setScenario('OFFLINE');
        $offlineResult = $this->driver->sendNotification($this->validConsole, 'Test message');
        $this->assertFalse($offlineResult->success);
        $this->assertEquals('OFFLINE', $offlineResult->resultCode);

        // 2. Timeout
        $this->mockTransport->setScenario('TIMEOUT');
        $timeoutResult = $this->driver->sendNotification($this->validConsole, 'Test message');
        $this->assertFalse($timeoutResult->success);
        $this->assertEquals('TIMEOUT', $timeoutResult->resultCode);

        // 3. Security Rejected
        $this->mockTransport->setScenario('SECURITY_REJECTED');
        $secResult = $this->driver->sendNotification($this->validConsole, 'Test message');
        $this->assertFalse($secResult->success);
        $this->assertEquals('SECURITY_REJECTED', $secResult->resultCode);

        // 4. HTTP Error
        $this->mockTransport->setScenario('ERROR');
        $errResult = $this->driver->sendNotification($this->validConsole, 'Test message');
        $this->assertFalse($errResult->success);
        $this->assertEquals('HTTP_ERROR', $errResult->resultCode);
    }

    /** @test */
    public function check_connectivity_and_transport_status()
    {
        $conn = $this->driver->checkConnectivity($this->validConsole);
        $this->assertTrue($conn['success']);
        $this->assertEquals('ONLINE', $conn['status']);

        $status = $this->driver->getTransportStatus($this->validConsole);
        $this->assertEquals('PS3_WEBMAN', $status['driver_type']);
        $this->assertEquals('DIRECT_HTTP', $status['transport_mode']);
        $this->assertEquals('/popup.ps3', $status['endpoint']);
        $this->assertEquals(5000, $status['timeout_ms']);
        $this->assertEquals(2000, $status['connect_timeout_ms']);
        $this->assertFalse($status['physical_display_verified']);
    }

    /** @test */
    public function incompatible_category_throws_exception()
    {
        $tvDevice = new PhysicalDevice([
            'id' => 102,
            'name' => 'TV Station 01',
            'category' => PhysicalDevice::CATEGORY_SMART_TV,
            'brand' => PhysicalDevice::BRAND_SAMSUNG,
            'ip_address' => '192.168.1.52',
            'is_active' => true,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('hanya mendukung kategori GAME_CONSOLE');
        $this->driver->sendNotification($tvDevice, 'Test message');
    }

    /** @test */
    public function incompatible_brand_throws_exception()
    {
        $xboxDevice = new PhysicalDevice([
            'id' => 103,
            'name' => 'Xbox Station 01',
            'category' => PhysicalDevice::CATEGORY_GAME_CONSOLE,
            'brand' => 'MICROSOFT',
            'ip_address' => '192.168.1.53',
            'is_active' => true,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('hanya mendukung merek SONY atau PLAYSTATION');
        $this->driver->sendNotification($xboxDevice, 'Test message');
    }

    /** @test */
    public function ps3_hen_http_transport_encodes_url_and_sends_http_request_with_correct_timeouts()
    {
        $history = [];
        $historyMiddleware = Middleware::history($history);

        $mockHandler = new MockHandler([
            new Response(200, [], 'HTTP/1.1 200 OK - webMAN MOD popup dispatched'),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $handlerStack->push($historyMiddleware);

        $client = new Client(['handler' => $handlerStack]);
        $transport = new Ps3HenHttpTransport($client);

        $result = $transport->sendNotification('192.168.1.51', 80, 'Waktu main: 15 menit & selesai!', 5000);

        $this->assertTrue($result['success']);
        $this->assertEquals('ACK_HTTP', $result['status']);
        $this->assertCount(1, $history);

        $transaction = $history[0];
        /** @var Request $request */
        $request = $transaction['request'];
        $options = $transaction['options'];

        // Assert request method & target URI
        $this->assertEquals('GET', $request->getMethod());
        $expectedPath = '/popup.ps3/' . rawurlencode('Waktu main: 15 menit & selesai!');
        $this->assertEquals($expectedPath, $request->getUri()->getPath());
        $this->assertStringContainsString('%20', $request->getUri()->getPath());
        $this->assertStringNotContainsString('+', $request->getUri()->getPath());
        $this->assertEquals('SugengRawuhPOS-PS3/1.0', $request->getHeaderLine('User-Agent'));

        // Assert timeouts: connect timeout 2s (2000ms), request timeout 5s (5000ms)
        $this->assertEquals(2.0, $options['connect_timeout']);
        $this->assertEquals(5.0, $options['timeout']);
    }

    /** @test */
    public function ps3_hen_http_transport_enforces_ssrf_safety_rules()
    {
        $transport = new Ps3HenHttpTransport();

        // 1. Loopback rejected
        $loopbackResult = $transport->sendNotification('127.0.0.1', 80, 'Test', 5000);
        $this->assertFalse($loopbackResult['success']);
        $this->assertEquals('SECURITY_REJECTED', $loopbackResult['status']);

        // 2. Cloud metadata rejected
        $metadataResult = $transport->sendNotification('169.254.169.254', 80, 'Test', 5000);
        $this->assertFalse($metadataResult['success']);
        $this->assertEquals('SECURITY_REJECTED', $metadataResult['status']);

        // 3. Public IP rejected
        $publicResult = $transport->sendNotification('8.8.8.8', 80, 'Test', 5000);
        $this->assertFalse($publicResult['success']);
        $this->assertEquals('SECURITY_REJECTED', $publicResult['status']);

        // 4. Unauthorized port rejected (port 22)
        $portResult = $transport->sendNotification('192.168.1.51', 22, 'Test', 5000);
        $this->assertFalse($portResult['success']);
        $this->assertEquals('SECURITY_REJECTED', $portResult['status']);
    }

    /** @test */
    public function ps3_hen_http_transport_handles_connect_timeout_and_network_exceptions()
    {
        // 1. Connection Timeout
        $mockHandler = new MockHandler([
            new ConnectException('cURL error 28: Connection timed out after 2000 milliseconds', new Request('GET', 'test')),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mockHandler)]);
        $transport = new Ps3HenHttpTransport($client);

        $timeoutResult = $transport->sendNotification('192.168.1.51', 80, 'Test', 5000);
        $this->assertFalse($timeoutResult['success']);
        $this->assertEquals('TIMEOUT', $timeoutResult['status']);

        // 2. Offline / Connection Refused
        $mockHandlerOffline = new MockHandler([
            new ConnectException('cURL error 7: Failed to connect to 192.168.1.51 port 80: Connection refused', new Request('GET', 'test')),
        ]);
        $clientOffline = new Client(['handler' => HandlerStack::create($mockHandlerOffline)]);
        $transportOffline = new Ps3HenHttpTransport($clientOffline);

        $offlineResult = $transportOffline->sendNotification('192.168.1.51', 80, 'Test', 5000);
        $this->assertFalse($offlineResult['success']);
        $this->assertEquals('OFFLINE', $offlineResult['status']);

        // 3. Request Exception (HTTP 500)
        $mockHandlerError = new MockHandler([
            new RequestException('Server Error', new Request('GET', 'test'), new Response(500)),
        ]);
        $clientError = new Client(['handler' => HandlerStack::create($mockHandlerError)]);
        $transportError = new Ps3HenHttpTransport($clientError);

        $errorResult = $transportError->sendNotification('192.168.1.51', 80, 'Test', 5000);
        $this->assertFalse($errorResult['success']);
        $this->assertEquals('REQUEST_ERROR', $errorResult['status']);
    }
}
