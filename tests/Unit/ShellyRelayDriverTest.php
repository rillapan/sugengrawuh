<?php

namespace Tests\Unit;

use App\Exceptions\HardwareSafetyException;
use App\Exceptions\HardwareSimulationException;
use App\Models\Device;
use App\Models\PhysicalDevice;
use App\Models\StationDeviceMapping;
use App\Services\Hardware\DeviceStatusManagementService;
use App\Services\Hardware\Drivers\ShellyRelayDriver;
use App\Services\Hardware\Transports\FakeShellyHttpRpcTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ShellyRelayDriverTest extends TestCase
{
    use RefreshDatabase;

    protected PhysicalDevice $device;
    protected FakeShellyHttpRpcTransport $fakeTransport;
    protected DeviceStatusManagementService $statusService;
    protected ShellyRelayDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('hardware.driver', 'real');
        Config::set('hardware.default_ports.SHELLY_HTTP', 80);

        $this->device = PhysicalDevice::create([
            'name' => 'Shelly 1PM Mini Billiard Lamp 1',
            'category' => PhysicalDevice::CATEGORY_SMART_RELAY,
            'brand' => PhysicalDevice::BRAND_SHELLY,
            'model' => 'Shelly 1PM Mini Gen3',
            'mac_address' => 'AA:BB:CC:DD:EE:77',
            'ip_address' => '192.168.1.177',
            'connection_status' => PhysicalDevice::CONNECTION_UNVERIFIED,
            'pairing_status' => PhysicalDevice::PAIRING_UNVERIFIED,
            'is_active' => true,
        ]);

        $this->fakeTransport = new FakeShellyHttpRpcTransport();
        $this->statusService = new DeviceStatusManagementService();
        $this->driver = new ShellyRelayDriver($this->fakeTransport, $this->statusService, false);
    }

    /** @test */
    public function driver_identifies_as_real_shelly_relay_with_supported_commands()
    {
        $this->assertEquals('REAL_SHELLY_RELAY', $this->driver->getDriverType());
        $this->assertContains('SWITCH_ON', $this->driver->getSupportedCommands());
        $this->assertContains('SWITCH_OFF', $this->driver->getSupportedCommands());
        $this->assertContains('GET_STATUS', $this->driver->getSupportedCommands());
    }

    /** @test */
    public function probe_succeeds_and_identifies_gen3_device()
    {
        $this->fakeTransport->setScenario(FakeShellyHttpRpcTransport::SCENARIO_SUCCESS);
        $result = $this->driver->probe($this->device);

        $this->assertTrue($result->success);
        $this->assertEquals('SUCCESS', $result->resultCode);
        $this->assertStringContainsString('Plus1PMMini', $result->message);
    }

    /** @test */
    public function probe_detects_offline_shelly()
    {
        $this->fakeTransport->setScenario(FakeShellyHttpRpcTransport::SCENARIO_OFFLINE);
        $result = $this->driver->probe($this->device);

        $this->assertFalse($result->success);
        $this->assertEquals('OFFLINE', $result->resultCode);
    }

    /** @test */
    public function probe_detects_timeout()
    {
        $this->fakeTransport->setScenario(FakeShellyHttpRpcTransport::SCENARIO_TIMEOUT);
        $result = $this->driver->probe($this->device);

        $this->assertFalse($result->success);
        $this->assertEquals('TIMEOUT', $result->resultCode);
    }

    /** @test */
    public function probe_detects_authentication_required()
    {
        $this->fakeTransport->setScenario(FakeShellyHttpRpcTransport::SCENARIO_AUTH_REQUIRED);
        $result = $this->driver->probe($this->device);

        $this->assertFalse($result->success);
        $this->assertEquals('AUTH_REQUIRED', $result->resultCode);
    }

    /** @test */
    public function pairing_verifies_authorization_password()
    {
        $result = $this->driver->pair($this->device, ['password' => 'shelly_pass_123']);

        $this->assertTrue($result->success);
        $this->assertEquals('PAIRED', $result->resultCode);
    }

    /** @test */
    public function pairing_fails_on_invalid_credentials()
    {
        $this->fakeTransport->setScenario(FakeShellyHttpRpcTransport::SCENARIO_INVALID_CREDENTIALS);
        $result = $this->driver->pair($this->device, ['password' => 'wrong_pass']);

        $this->assertFalse($result->success);
        $this->assertEquals('COMMAND_REJECTED', $result->resultCode);
    }

    /** @test */
    public function switch_on_turns_on_relay_and_verifies_state()
    {
        $result = $this->driver->sendCommand($this->device, 'SWITCH_ON');

        $this->assertTrue($result->success);
        $this->assertEquals('SUCCESS', $result->resultCode);
        $this->assertTrue($result->simulatedState['output']);
        $this->assertGreaterThan(0, $result->simulatedState['apower']);
        $this->assertStringContainsString('Output ON', $result->message);
    }

    /** @test */
    public function switch_off_turns_off_relay_and_verifies_state()
    {
        // First set ON
        $this->fakeTransport->setOutputState(true, 45.0);

        $result = $this->driver->sendCommand($this->device, 'SWITCH_OFF');

        $this->assertTrue($result->success);
        $this->assertEquals('SUCCESS', $result->resultCode);
        $this->assertFalse($result->simulatedState['output']);
        $this->assertEquals(0.0, $result->simulatedState['apower']);
        $this->assertStringContainsString('Output OFF', $result->message);
    }

    /** @test */
    public function read_back_verification_detects_state_mismatch()
    {
        // Mock a transport where Switch.Set returns success, but read-back reports output remains false
        $stubTransport = new class extends FakeShellyHttpRpcTransport {
            public function sendRpc(string $ip, int $port = 80, string $method = 'Switch.GetStatus', array $params = [], ?string $authPassword = null, int $timeoutMs = 3000): array {
                if ($method === 'Switch.Set') {
                    return ['success' => true, 'status' => 'SUCCESS', 'result' => ['was_on' => false]];
                }
                if ($method === 'Switch.GetStatus') {
                    // Mismatched state: still false even though SWITCH_ON was requested!
                    return ['success' => true, 'status' => 'SUCCESS', 'result' => ['output' => false, 'apower' => 0.0, 'voltage' => 220]];
                }
                return parent::sendRpc($ip, $port, $method, $params, $authPassword, $timeoutMs);
            }
        };

        $driverWithStub = new ShellyRelayDriver($stubTransport, $this->statusService, false);
        $result = $driverWithStub->sendCommand($this->device, 'SWITCH_ON');

        $this->assertFalse($result->success);
        $this->assertEquals('STATE_MISMATCH', $result->resultCode);
        $this->assertStringContainsString('verifikasi read-back output tidak sesuai', $result->message);
    }

    /** @test */
    public function get_status_reads_output_and_telemetry()
    {
        $this->fakeTransport->setOutputState(true, 48.6);
        $result = $this->driver->getStatus($this->device);

        $this->assertTrue($result->success);
        $this->assertEquals('SUCCESS', $result->resultCode);
        $this->assertTrue($result->simulatedState['output']);
        $this->assertEquals(48.6, $result->simulatedState['apower']);
        $this->assertEquals(228.4, $result->simulatedState['voltage']);
    }

    /** @test */
    public function hardware_safety_strictly_forbids_mapping_shelly_to_playstation_station()
    {
        $psService = \App\Models\Playstation::create([
            'nama' => 'PLAYSTATION 5 VIP',
            'harga' => 20000,
        ]);

        $psStation = Device::create([
            'nama' => 'PS5-STATION-01',
            'status' => 'Tersedia',
            'playstation_id' => $psService->id,
        ]);
        $psStation->playstations()->attach($psService->id);

        StationDeviceMapping::create([
            'station_id' => $psStation->id,
            'physical_device_id' => $this->device->id,
            'mapping_role' => StationDeviceMapping::ROLE_LIGHT_RELAY,
            'mapping_status' => StationDeviceMapping::STATUS_ACTIVE,
        ]);

        $this->expectException(HardwareSafetyException::class);
        $this->expectExceptionMessage('DILARANG menggunakan relay untuk memutus daya TV/PlayStation! Shelly hanya boleh digunakan untuk lampu meja biliar.');

        $this->driver->probe($this->device->fresh());
    }

    /** @test */
    public function hardware_safety_strictly_forbids_mapping_shelly_as_display_tv_role()
    {
        $billiardService = \App\Models\Playstation::create([
            'nama' => 'BILIAR 9-FEET MEJA 1',
            'harga' => 35000,
        ]);

        $billiardStation = Device::create([
            'nama' => 'BILIAR-MEJA-01',
            'status' => 'Tersedia',
            'playstation_id' => $billiardService->id,
        ]);
        $billiardStation->playstations()->attach($billiardService->id);

        // Attempt to map as DISPLAY_TV
        $mapping = new StationDeviceMapping([
            'station_id' => $billiardStation->id,
            'physical_device_id' => $this->device->id,
            'mapping_role' => StationDeviceMapping::ROLE_DISPLAY_TV,
            'mapping_status' => StationDeviceMapping::STATUS_ACTIVE,
        ]);
        $mapping->save();

        $this->expectException(HardwareSafetyException::class);
        $this->expectExceptionMessage("Shelly Smart Relay dipetakan dengan peran 'DISPLAY_TV'. Shelly hanya diizinkan untuk 'LIGHT_RELAY'");

        $this->driver->probe($this->device->fresh());
    }

    /** @test */
    public function hardware_safety_allows_mapping_shelly_to_billiard_as_light_relay()
    {
        $billiardService = \App\Models\Playstation::create([
            'nama' => 'BILIAR 9-FEET MEJA 2',
            'harga' => 35000,
        ]);

        $billiardStation = Device::create([
            'nama' => 'BILIAR-MEJA-02',
            'status' => 'Tersedia',
            'playstation_id' => $billiardService->id,
        ]);
        $billiardStation->playstations()->attach($billiardService->id);

        StationDeviceMapping::create([
            'station_id' => $billiardStation->id,
            'physical_device_id' => $this->device->id,
            'mapping_role' => StationDeviceMapping::ROLE_LIGHT_RELAY,
            'mapping_status' => StationDeviceMapping::STATUS_ACTIVE,
        ]);

        // Must succeed without throwing HardwareSafetyException
        $result = $this->driver->probe($this->device->fresh());
        $this->assertTrue($result->success);
    }
}
