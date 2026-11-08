<?php

namespace Tests\Unit;

use App\Models\Device;
use App\Models\PhysicalDevice;
use App\Models\Playstation;
use App\Models\StationDeviceMapping;
use App\Services\Hardware\Drivers\ShellyRelayDriver;
use App\Services\Hardware\Transports\ShellyHttpRpcTransport;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShellyHttpRpcTransportContractTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function method_a_standard_json_rpc_sends_post_to_rpc_and_parses_frame_result()
    {
        $container = [];
        $history = Middleware::history($container);

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'id' => 1,
                'src' => 'shelly1pmmini3-aabbccddeeff',
                'result' => [
                    'was_on' => false,
                ],
            ])),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push($history);
        $client = new Client(['handler' => $handlerStack]);

        $transport = new ShellyHttpRpcTransport($client, ShellyHttpRpcTransport::FORMAT_STANDARD);

        $response = $transport->sendRpc('192.168.1.55', 80, 'Switch.Set', ['id' => 0, 'on' => true]);

        $this->assertTrue($response['success']);
        $this->assertEquals('SUCCESS', $response['status']);
        $this->assertEquals(['was_on' => false], $response['result']);
        $this->assertEquals('A', $response['rpc_format']);

        // Inspect captured HTTP request for Method A contract adherence
        $this->assertCount(1, $container);
        /** @var Request $request */
        $request = $container[0]['request'];
        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('/rpc', $request->getUri()->getPath());

        $sentBody = json_decode((string) $request->getBody(), true);
        $this->assertEquals(1, $sentBody['id']);
        $this->assertEquals('Switch.Set', $sentBody['method']);
        $this->assertEquals(['id' => 0, 'on' => true], $sentBody['params']);
        $this->assertEquals('sugeng_rawuh_pos', $sentBody['src']);
    }

    /** @test */
    public function method_b_short_endpoint_sends_post_to_rpc_method_with_params_only()
    {
        $container = [];
        $history = Middleware::history($container);

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'was_on' => false,
            ])),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push($history);
        $client = new Client(['handler' => $handlerStack]);

        $transport = new ShellyHttpRpcTransport($client, ShellyHttpRpcTransport::FORMAT_SHORT);

        $response = $transport->sendRpc('192.168.1.55', 80, 'Switch.Set', ['id' => 0, 'on' => true]);

        $this->assertTrue($response['success']);
        $this->assertEquals('SUCCESS', $response['status']);
        $this->assertEquals(['was_on' => false], $response['result']);
        $this->assertEquals('B', $response['rpc_format']);

        // Inspect captured HTTP request for Method B contract adherence
        $this->assertCount(1, $container);
        /** @var Request $request */
        $request = $container[0]['request'];
        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('/rpc/Switch.Set', $request->getUri()->getPath());

        $sentBody = json_decode((string) $request->getBody(), true);
        // Method B sends only params, not full JSON-RPC envelope
        $this->assertEquals(['id' => 0, 'on' => true], $sentBody);
        $this->assertArrayNotHasKey('method', $sentBody);
        $this->assertArrayNotHasKey('src', $sentBody);
    }

    /** @test */
    public function method_a_parses_json_rpc_error_object_correctly()
    {
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'id' => 1,
                'error' => [
                    'code' => -104,
                    'message' => 'Missing required argument id',
                ],
            ])),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $transport = new ShellyHttpRpcTransport($client, ShellyHttpRpcTransport::FORMAT_STANDARD);

        $response = $transport->sendRpc('192.168.1.55', 80, 'Switch.Set', ['on' => true]);

        $this->assertFalse($response['success']);
        $this->assertEquals('DEVICE_ERROR', $response['status']);
        $this->assertEquals(-104, $response['result']['code']);
        $this->assertStringContainsString('Missing required argument id', $response['message']);
    }

    /** @test */
    public function method_b_parses_direct_error_object_correctly()
    {
        $mock = new MockHandler([
            new Response(400, ['Content-Type' => 'application/json'], json_encode([
                'code' => 400,
                'message' => 'Bad Request: invalid parameter value',
            ])),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $transport = new ShellyHttpRpcTransport($client, ShellyHttpRpcTransport::FORMAT_SHORT);

        $response = $transport->sendRpc('192.168.1.55', 80, 'Switch.Set', ['id' => 99, 'on' => true]);

        $this->assertFalse($response['success']);
        $this->assertEquals('DEVICE_ERROR', $response['status']);
        $this->assertEquals(400, $response['result']['code']);
        $this->assertStringContainsString('Bad Request', $response['message']);
    }

    /** @test */
    public function http_401_returns_auth_required_status()
    {
        $mock = new MockHandler([
            new Response(401, ['WWW-Authenticate' => 'Digest realm="shelly"'], 'Unauthorized'),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $transport = new ShellyHttpRpcTransport($client);

        $response = $transport->sendRpc('192.168.1.55', 80, 'Switch.Set', ['id' => 0, 'on' => true]);

        $this->assertFalse($response['success']);
        $this->assertEquals('AUTH_REQUIRED', $response['status']);
        $this->assertEquals(401, $response['http_code']);
    }

    /** @test */
    public function connection_offline_returns_offline_status()
    {
        $mock = new MockHandler([
            new ConnectException('Connection refused', new Request('POST', 'http://192.168.1.55/rpc')),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $transport = new ShellyHttpRpcTransport($client);

        $response = $transport->sendRpc('192.168.1.55', 80, 'Switch.GetStatus');

        $this->assertFalse($response['success']);
        $this->assertEquals('OFFLINE', $response['status']);
    }

    /** @test */
    public function shelly_driver_executes_switch_set_and_validates_read_back_state_via_http_transport()
    {
        $container = [];
        $history = Middleware::history($container);

        // Sequence: 1. Switch.Set response -> 2. Switch.GetStatus read-back response
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'id' => 1,
                'result' => ['was_on' => false],
            ])),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'id' => 1,
                'result' => [
                    'id' => 0,
                    'output' => true,
                    'apower' => 52.8,
                    'voltage' => 228.4,
                    'current' => 0.23,
                    'temperature' => ['tC' => 38.5],
                ],
            ])),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push($history);
        $client = new Client(['handler' => $handlerStack]);

        $transport = new ShellyHttpRpcTransport($client, ShellyHttpRpcTransport::FORMAT_STANDARD);
        $driver = new ShellyRelayDriver($transport);

        $playstation = Playstation::create([
            'nama' => 'Meja Biliar Standard 1',
            'harga' => 30000,
        ]);

        $station = Device::create([
            'nama' => 'Meja Biliar Standard 1',
            'status' => 'tersedia',
            'playstation_id' => $playstation->id,
        ]);

        $physicalDevice = PhysicalDevice::create([
            'name' => 'Shelly 1PM Mini Light Relay',
            'category' => PhysicalDevice::CATEGORY_SMART_RELAY,
            'brand' => PhysicalDevice::BRAND_SHELLY,
            'model' => 'Shelly 1PM Mini Gen3',
            'mac_address' => '00:11:22:33:44:99',
            'ip_address' => '192.168.1.99',
            'connection_status' => PhysicalDevice::CONNECTION_UNVERIFIED,
            'pairing_status' => PhysicalDevice::PAIRING_UNVERIFIED,
            'is_active' => true,
        ]);

        StationDeviceMapping::create([
            'station_id' => $station->id,
            'physical_device_id' => $physicalDevice->id,
            'mapping_role' => StationDeviceMapping::ROLE_LIGHT_RELAY,
            'is_active' => true,
            'mapped_at' => now(),
        ]);

        $result = $driver->sendCommand($physicalDevice, 'SWITCH_ON');

        $this->assertTrue($result->success);
        $this->assertEquals('SUCCESS', $result->resultCode);
        $this->assertTrue($result->telemetry['output']);
        $this->assertEquals(52.8, $result->telemetry['apower']);

        // Verify two HTTP requests were sent: Switch.Set followed by Switch.GetStatus
        $this->assertCount(2, $container);
        $firstBody = json_decode((string) $container[0]['request']->getBody(), true);
        $secondBody = json_decode((string) $container[1]['request']->getBody(), true);

        $this->assertEquals('Switch.Set', $firstBody['method']);
        $this->assertEquals('Switch.GetStatus', $secondBody['method']);
    }
}
