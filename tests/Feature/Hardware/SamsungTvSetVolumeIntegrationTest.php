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
use App\Services\Hardware\Drivers\SamsungTvDriver;
use App\Services\Hardware\Transports\FakeSamsungWebSocketTransport;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class SamsungTvSetVolumeIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $kasir;
    protected Playstation $psCategory;
    protected Device $station;
    protected PhysicalDevice $tv;
    protected StationDeviceMapping $tvMapping;
    protected BillingService $billingService;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('hardware.driver', 'mock');
        Config::set('hardware.real_execution_enabled', false);
        Config::set('hardware.simulation_enabled', true);
        Config::set('hardware.automation_enabled', true);

        $this->admin = User::factory()->create([
            'name' => 'Admin Set Vol Test',
            'email' => 'admin_set_vol@sugengrawuh.test',
            'role' => 'admin',
        ]);

        $this->kasir = User::factory()->create([
            'name' => 'Kasir Set Vol Test',
            'email' => 'kasir_set_vol@sugengrawuh.test',
            'role' => 'kasir',
        ]);

        $this->psCategory = Playstation::create([
            'nama' => 'PS4 Regular',
            'harga' => 10000,
        ]);

        $this->station = Device::create([
            'nama' => 'Station 05',
            'status' => 'Tersedia',
            'playstation_id' => $this->psCategory->id,
            'session_generation' => 1,
        ]);

        $this->tv = PhysicalDevice::create([
            'name' => 'Samsung TV Station 05',
            'category' => PhysicalDevice::CATEGORY_SMART_TV,
            'brand' => PhysicalDevice::BRAND_SAMSUNG,
            'model' => '50U8500',
            'mac_address' => '00:11:22:99:88:77',
            'ip_address' => '192.168.1.105',
            'connection_status' => PhysicalDevice::CONNECTION_ONLINE,
            'pairing_status' => PhysicalDevice::PAIRING_PAIRED,
            'is_active' => true,
        ]);

        $this->tvMapping = StationDeviceMapping::create([
            'station_id' => $this->station->id,
            'physical_device_id' => $this->tv->id,
            'mapping_role' => StationDeviceMapping::ROLE_DISPLAY_TV,
            'mapping_status' => StationDeviceMapping::STATUS_ACTIVE,
            'attached_at' => now(),
        ]);

        $this->station->playstations()->attach($this->psCategory->id);
        $this->billingService = app(BillingService::class);
    }

    protected function startActiveSession(): Transaction
    {
        $tx = $this->billingService->startSession([
            'device_id' => $this->station->id,
            'playstation_id' => $this->station->playstation_id,
            'nama' => 'Customer Vol QA',
            'tipe_transaksi' => 'prepaid',
            'jam_main' => 1,
        ], $this->admin->id);

        $this->station->refresh();
        return $tx;
    }

    public function test_admin_can_set_volume_via_api()
    {
        $this->startActiveSession();

        $res = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-volume.store', ['station' => $this->station->id]),
            [
                'command' => 'SET_VOLUME',
                'target_volume' => 45,
            ]
        );

        $res->assertStatus(200);
        $res->assertJsonPath('success', true);
        $res->assertJsonPath('data.command', 'SET_VOLUME');
        $res->assertJsonPath('data.volume', 45);
        $res->assertJsonPath('data.simulated_volume', 45);

        $this->assertDatabaseHas('tv_control_events', [
            'station_id' => $this->station->id,
            'command' => 'SET_VOLUME',
            'simulated_volume' => 45,
        ]);
    }

    public function test_set_volume_rejects_values_out_of_bounds()
    {
        $this->startActiveSession();

        // Target volume > 100
        $res = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-volume.store', ['station' => $this->station->id]),
            [
                'command' => 'SET_VOLUME',
                'target_volume' => 150,
            ]
        );
        $res->assertStatus(422);

        // Target volume < 0
        $resNeg = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-volume.store', ['station' => $this->station->id]),
            [
                'command' => 'SET_VOLUME',
                'target_volume' => -5,
            ]
        );
        $resNeg->assertStatus(422);
    }

    public function test_real_samsung_driver_returns_unsupported_capability_when_set_volume_not_verified()
    {
        $fakeTransport = new FakeSamsungWebSocketTransport();
        $realDriver = new SamsungTvDriver($fakeTransport, null, true);

        // TV device without SET_VOLUME in verified_capabilities
        $unverifiedTv = new PhysicalDevice([
            'name' => 'Physical Samsung TV',
            'category' => PhysicalDevice::CATEGORY_SMART_TV,
            'brand' => PhysicalDevice::BRAND_SAMSUNG,
            'model' => '50U8500',
            'ip_address' => '192.168.1.200',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'verified_capabilities' => ['KEY_POWER', 'KEY_VOLUP', 'KEY_VOLDOWN'],
            'is_active' => true,
        ]);
        $unverifiedTv->id = 999;

        $result = $realDriver->sendCommand($unverifiedTv, 'SET_VOLUME', ['target_volume' => 40]);

        $this->assertFalse($result->success);
        $this->assertEquals('CAPABILITY_UNSUPPORTED', $result->resultCode);
        $this->assertStringContainsString('SET_VOLUME', $result->message);
    }
}
