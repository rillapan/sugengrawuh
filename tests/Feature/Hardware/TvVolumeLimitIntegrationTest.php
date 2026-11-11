<?php

namespace Tests\Feature\Hardware;

use App\Models\Device;
use App\Models\PhysicalDevice;
use App\Models\Playstation;
use App\Models\StationDeviceMapping;
use App\Models\Transaction;
use App\Models\TvAlertEvent;
use App\Models\TvVolumeEnforcementLog;
use App\Models\User;
use App\Services\BillingService;
use App\Services\Hardware\TvAlertService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class TvVolumeLimitIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $kasir;
    protected User $owner;
    protected Playstation $psCategory;
    protected Playstation $billiardCategory;
    protected Device $ps01;
    protected Device $billiard01;
    protected PhysicalDevice $tv01;
    protected StationDeviceMapping $mapping01;
    protected BillingService $billingService;
    protected TvAlertService $tvAlertService;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('hardware.driver', 'mock');
        Config::set('hardware.real_execution_enabled', false);
        Config::set('hardware.simulation_enabled', true);
        Config::set('hardware.automation_enabled', true);

        Cache::flush();

        $this->admin = User::factory()->create([
            'name' => 'Admin TV Volume QA',
            'email' => 'admin_tv_vol@sugengrawuh.test',
            'role' => 'admin',
        ]);

        $this->owner = User::factory()->create([
            'name' => 'Owner TV Volume QA',
            'email' => 'owner_tv_vol@sugengrawuh.test',
            'role' => 'owner',
        ]);

        $this->kasir = User::factory()->create([
            'name' => 'Kasir TV Volume QA',
            'email' => 'kasir_tv_vol@sugengrawuh.test',
            'role' => 'kasir',
        ]);

        $this->psCategory = Playstation::create([
            'nama' => 'PS 4',
            'harga' => 15000,
        ]);

        $this->billiardCategory = Playstation::create([
            'nama' => 'Meja Billiard',
            'harga' => 30000,
        ]);

        $this->ps01 = Device::create([
            'nama' => 'PS-01',
            'status' => 'Tersedia',
            'session_generation' => 1,
            'playstation_id' => $this->psCategory->id,
        ]);
        $this->ps01->playstations()->attach($this->psCategory->id);

        $this->billiard01 = Device::create([
            'nama' => 'Billiard-01',
            'status' => 'Tersedia',
            'session_generation' => 1,
            'playstation_id' => $this->billiardCategory->id,
        ]);
        $this->billiard01->playstations()->attach($this->billiardCategory->id);

        $this->tv01 = PhysicalDevice::create([
            'name' => 'Samsung 43FE60 PS01',
            'category' => PhysicalDevice::CATEGORY_SMART_TV,
            'brand' => PhysicalDevice::BRAND_SAMSUNG,
            'model' => '43FE60',
            'mac_address' => 'AA:BB:CC:11:22:33',
            'ip_address' => '192.168.1.101',
            'connection_status' => PhysicalDevice::CONNECTION_ONLINE,
            'pairing_status' => PhysicalDevice::PAIRING_PAIRED,
            'auto_volume_limit_enabled' => false,
            'max_volume_limit' => 50,
            'is_active' => true,
        ]);

        $this->mapping01 = StationDeviceMapping::create([
            'station_id' => $this->ps01->id,
            'physical_device_id' => $this->tv01->id,
            'mapping_role' => StationDeviceMapping::ROLE_DISPLAY_TV,
            'mapping_status' => StationDeviceMapping::STATUS_ACTIVE,
        ]);

        $this->billingService = app(BillingService::class);
        $this->tvAlertService = app(TvAlertService::class);
    }

    protected function startPrepaidSession(Device $station, int $hours = 1, string $customerName = 'Pelanggan Volume Test'): Transaction
    {
        $tx = $this->billingService->startSession([
            'device_id' => $station->id,
            'playstation_id' => $station->playstation_id,
            'nama' => $customerName,
            'tipe_transaksi' => 'prepaid',
            'jam_main' => $hours,
        ], $this->admin->id);

        $station->refresh();
        return $tx;
    }

    /**
     * Test 1: Admin and Owner can update maximum volume settings.
     */
    public function test_admin_and_owner_can_update_volume_limit_settings(): void
    {
        // 1. Admin sets volume limit
        $resAdmin = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-volume-limit.store', ['station' => $this->ps01->id]),
            [
                'enabled' => true,
                'max_volume' => 45,
            ]
        );

        $resAdmin->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.auto_volume_limit_enabled', true)
            ->assertJsonPath('data.max_volume_limit', 45);

        $this->tv01->refresh();
        $this->assertTrue($this->tv01->auto_volume_limit_enabled);
        $this->assertSame(45, (int) $this->tv01->max_volume_limit);
        $this->assertSame($this->admin->id, (int) $this->tv01->auto_volume_limit_updated_by_user_id);

        // 2. Owner can update volume limit
        $resOwner = $this->actingAs($this->owner)->postJson(
            route('admin.stations.tv-volume-limit.store', ['station' => $this->ps01->id]),
            [
                'enabled' => true,
                'max_volume' => 60,
            ]
        );

        $resOwner->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.max_volume_limit', 60);

        $this->tv01->refresh();
        $this->assertSame(60, (int) $this->tv01->max_volume_limit);
        $this->assertSame($this->owner->id, (int) $this->tv01->auto_volume_limit_updated_by_user_id);
    }

    /**
     * Test 2: Kasir or unauthenticated users cannot update volume limit settings.
     */
    public function test_kasir_cannot_update_volume_limit_settings(): void
    {
        $resKasir = $this->actingAs($this->kasir)->postJson(
            route('admin.stations.tv-volume-limit.store', ['station' => $this->ps01->id]),
            [
                'enabled' => true,
                'max_volume' => 40,
            ]
        );

        $resKasir->assertStatus(403);
    }

    /**
     * Test 3: Validation rules for volume limit payload.
     */
    public function test_validation_rules_for_volume_limit(): void
    {
        // Max volume above 100
        $res = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-volume-limit.store', ['station' => $this->ps01->id]),
            [
                'enabled' => true,
                'max_volume' => 150,
            ]
        );
        $res->assertStatus(422)->assertJsonPath('error_code', 'VALIDATION_ERROR');

        // Missing enabled
        $res2 = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-volume-limit.store', ['station' => $this->ps01->id]),
            [
                'max_volume' => 50,
            ]
        );
        $res2->assertStatus(422)->assertJsonPath('error_code', 'VALIDATION_ERROR');

        // Cannot set on Billiard station
        $resBilliard = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-volume-limit.store', ['station' => $this->billiard01->id]),
            [
                'enabled' => true,
                'max_volume' => 50,
            ]
        );
        $resBilliard->assertStatus(422)->assertJsonPath('error_code', 'INVALID_STATION_CATEGORY');
    }

    /**
     * Test 4: Enforce now dispatches SET_VOLUME and creates enforcement log.
     */
    public function test_enforce_now_dispatches_set_volume_and_records_enforcement(): void
    {
        $this->startPrepaidSession($this->ps01, 2);

        // Pre-set TV current volume to 75
        $this->tv01->update(['current_volume' => 75]);

        $res = $this->actingAs($this->admin)->postJson(
            route('admin.stations.tv-volume-limit.store', ['station' => $this->ps01->id]),
            [
                'enabled' => true,
                'max_volume' => 50,
                'enforce_now' => true,
            ]
        );

        $res->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.enforced', true);

        // Verify enforcement log created
        $log = TvVolumeEnforcementLog::where('station_id', $this->ps01->id)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame(75, (int) $log->previous_volume);
        $this->assertSame(50, (int) $log->enforced_volume);
        $this->assertSame(50, (int) $log->max_limit);
        $this->assertSame(TvVolumeEnforcementLog::SOURCE_ADMIN_ENFORCE, $log->enforcement_source);
        $this->assertSame(TvVolumeEnforcementLog::STATUS_ENFORCED, $log->status);

        // Verify physical device status updated
        $this->tv01->refresh();
        $this->assertSame(50, (int) $this->tv01->current_volume);
        $this->assertSame('ENFORCED', $this->tv01->volume_enforcement_status);
        $this->assertNotNull($this->tv01->last_volume_verified_at);

        // Verify SET_VOLUME event was generated
        $event = TvAlertEvent::where('station_id', $this->ps01->id)
            ->where('command', TvAlertEvent::CMD_SET_VOLUME)
            ->first();
        $this->assertNotNull($event);
        $this->assertSame(50, (int) $event->simulated_volume);
    }

    /**
     * Test 5: Status endpoint returns volume_limit_config and enforcement logs.
     */
    public function test_status_endpoint_returns_volume_limit_config_and_logs(): void
    {
        $this->startPrepaidSession($this->ps01, 1);

        $this->tv01->update([
            'auto_volume_limit_enabled' => true,
            'max_volume_limit' => 40,
            'current_volume' => 40,
            'volume_enforcement_status' => 'WITHIN_LIMIT',
            'last_volume_verified_at' => now(),
        ]);

        TvVolumeEnforcementLog::create([
            'station_id' => $this->ps01->id,
            'physical_device_id' => $this->tv01->id,
            'previous_volume' => 65,
            'enforced_volume' => 40,
            'max_limit' => 40,
            'enforcement_source' => TvVolumeEnforcementLog::SOURCE_TIZEN_AGENT,
            'status' => TvVolumeEnforcementLog::STATUS_ENFORCED,
            'details' => 'Auto-clamped by Tizen Agent',
        ]);

        $res = $this->actingAs($this->admin)->getJson(
            route('admin.stations.tv-control.status', ['station' => $this->ps01->id])
        );

        $res->assertStatus(200)
            ->assertJsonPath('data.volume_limit_config.enabled', true)
            ->assertJsonPath('data.volume_limit_config.max_limit', 40)
            ->assertJsonPath('data.volume_limit_config.current_volume', 40)
            ->assertJsonPath('data.volume_limit_config.enforcement_status', 'WITHIN_LIMIT')
            ->assertJsonCount(1, 'data.enforcement_logs')
            ->assertJsonPath('data.enforcement_logs.0.enforced_volume', 40);
    }

    /**
     * Test 6: Consumer TV Agent polling includes volume_limit_config.
     */
    public function test_tv_agent_poll_includes_volume_limit_config(): void
    {
        $this->startPrepaidSession($this->ps01, 1);

        $this->tv01->update([
            'auto_volume_limit_enabled' => true,
            'max_volume_limit' => 35,
        ]);

        $token = $this->tvAlertService->generateConsumerToken($this->ps01->id, $this->tv01->id);

        $res = $this->getJson(
            route('api.tv-agent.events.index', [
                'station_id' => $this->ps01->id,
                'token' => $token,
            ])
        );

        $res->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('volume_limit_config.enabled', true)
            ->assertJsonPath('volume_limit_config.max_limit', 35);
    }

    /**
     * Test 7: Consumer reports volume enforcement via API.
     */
    public function test_consumer_reports_volume_enforcement_via_api(): void
    {
        $this->startPrepaidSession($this->ps01, 1);
        $token = $this->tvAlertService->generateConsumerToken($this->ps01->id, $this->tv01->id);

        // 1. Unauthorized without token
        $resUnauth = $this->postJson(route('api.tv-agent.events.volume-enforcement'), [
            'station_id' => $this->ps01->id,
            'previous_volume' => 80,
            'enforced_volume' => 50,
            'max_limit' => 50,
        ]);
        $resUnauth->assertStatus(401);

        // 2. Successful enforcement report
        $res = $this->withHeaders([
            'X-TV-Station-Id' => (string) $this->ps01->id,
            'X-TV-Device-Token' => $token,
        ])->postJson(route('api.tv-agent.events.volume-enforcement'), [
            'station_id' => $this->ps01->id,
            'previous_volume' => 85,
            'enforced_volume' => 50,
            'max_limit' => 50,
            'enforcement_source' => 'TIZEN_AGENT',
            'status' => 'ENFORCED',
            'details' => 'Customer exceeded volume limit; clamped to 50.',
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.previous_volume', 85)
            ->assertJsonPath('data.enforced_volume', 50);

        // Verify DB updates
        $this->tv01->refresh();
        $this->assertSame(50, (int) $this->tv01->current_volume);
        $this->assertSame('ENFORCED', $this->tv01->volume_enforcement_status);
        $this->assertNotNull($this->tv01->last_volume_verified_at);

        $log = TvVolumeEnforcementLog::find($res->json('data.enforcement_id'));
        $this->assertNotNull($log);
        $this->assertSame(85, (int) $log->previous_volume);
        $this->assertSame(50, (int) $log->enforced_volume);
        $this->assertSame('TIZEN_AGENT', $log->enforcement_source);
    }

    /**
     * Test 8: Control volume supports SET_VOLUME command.
     */
    public function test_control_volume_supports_set_volume(): void
    {
        $this->startPrepaidSession($this->ps01, 1);

        $result = $this->tvAlertService->controlVolume(
            $this->ps01,
            $this->admin,
            TvAlertEvent::CMD_SET_VOLUME,
            ['target_volume' => 42]
        );

        $this->assertTrue($result['success']);
        $this->assertSame(42, (int) $result['volume']);

        $this->tv01->refresh();
        $this->assertSame(42, (int) $this->tv01->current_volume);
    }
}
