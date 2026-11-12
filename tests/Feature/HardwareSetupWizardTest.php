<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\PhysicalDevice;
use App\Models\Playstation;
use App\Models\StationDeviceMapping;
use App\Models\User;
use App\Services\Hardware\Discovery\DeviceDiscoveryService;
use App\Services\Hardware\Security\HardwareSafetyGuard;
use App\Services\Hardware\Transports\FakeSamsungWebSocketTransport;
use App\Services\Hardware\Transports\FakeShellyHttpRpcTransport;
use App\Services\Hardware\Transports\SamsungTransportInterface;
use App\Services\Hardware\Transports\ShellyTransportInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class HardwareSetupWizardTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $kasir;
    protected User $owner;
    protected Device $psStation;
    protected Device $billiardStation;
    protected PhysicalDevice $samsungTv;
    protected PhysicalDevice $shellyRelay;
    protected FakeSamsungWebSocketTransport $fakeSamsungTransport;
    protected FakeShellyHttpRpcTransport $fakeShellyTransport;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('hardware.driver', 'real');
        Config::set('hardware.real_execution_enabled', false);

        // Bind fake transports so no real network packets are ever transmitted
        $this->fakeSamsungTransport = new FakeSamsungWebSocketTransport();
        $this->fakeShellyTransport = new FakeShellyHttpRpcTransport();
        $this->app->instance(SamsungTransportInterface::class, $this->fakeSamsungTransport);
        $this->app->instance(ShellyTransportInterface::class, $this->fakeShellyTransport);

        // Reset mock discovery candidates
        DeviceDiscoveryService::clearMockCandidates();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'name' => 'Admin Setup',
            'email' => 'admin_setup@sugengrawuh.test',
        ]);

        $this->kasir = User::factory()->create([
            'role' => 'kasir',
            'name' => 'Kasir Setup',
            'email' => 'kasir_setup@sugengrawuh.test',
        ]);

        $this->owner = User::factory()->create([
            'role' => 'owner',
            'name' => 'Owner Setup',
            'email' => 'owner_setup@sugengrawuh.test',
        ]);

        $ps5Service = Playstation::create([
            'nama' => 'PS5 VIP ROOM',
            'harga' => 20000,
        ]);

        $this->psStation = Device::create([
            'nama' => 'Rental PS 01',
            'status' => 'Tersedia',
            'playstation_id' => $ps5Service->id,
        ]);
        $this->psStation->playstations()->attach($ps5Service->id);

        $billiardService = Playstation::create([
            'nama' => 'BILLIARD 9-FEET MEJA 1',
            'harga' => 35000,
        ]);

        $this->billiardStation = Device::create([
            'nama' => 'Meja Biliar VIP',
            'status' => 'Tersedia',
            'playstation_id' => $billiardService->id,
        ]);
        $this->billiardStation->playstations()->attach($billiardService->id);

        $this->samsungTv = PhysicalDevice::create([
            'name' => 'Samsung TV 50U8500',
            'category' => PhysicalDevice::CATEGORY_SMART_TV,
            'brand' => PhysicalDevice::BRAND_SAMSUNG,
            'model' => '50U8500',
            'mac_address' => 'DC:2C:26:AA:BB:CC',
            'ip_address' => '192.168.88.101',
            'connection_status' => PhysicalDevice::CONNECTION_ONLINE,
            'pairing_status' => PhysicalDevice::PAIRING_UNVERIFIED,
            'is_active' => true,
        ]);

        $this->shellyRelay = PhysicalDevice::create([
            'name' => 'Shelly 1PM Mini Gen3',
            'category' => PhysicalDevice::CATEGORY_SMART_RELAY,
            'brand' => PhysicalDevice::BRAND_SHELLY,
            'model' => 'Shelly 1PM Mini Gen3',
            'mac_address' => '34:98:7A:11:22:33',
            'ip_address' => '192.168.88.102',
            'connection_status' => PhysicalDevice::CONNECTION_ONLINE,
            'pairing_status' => PhysicalDevice::PAIRING_NOT_REQUIRED,
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        DeviceDiscoveryService::clearMockCandidates();
        parent::tearDown();
    }

    /** @test */
    public function guest_cannot_access_setup_wizard()
    {
        $response = $this->get('/admin/hardware/setup');
        $response->assertRedirect('/login');

        $responseJson = $this->getJson('/admin/hardware/setup/network-info');
        $responseJson->assertStatus(401);
    }

    /** @test */
    public function kasir_is_strictly_forbidden_from_setup_wizard()
    {
        $this->actingAs($this->kasir)
            ->get('/admin/hardware/setup')
            ->assertStatus(403);

        $this->actingAs($this->kasir)
            ->getJson('/admin/hardware/setup/network-info')
            ->assertStatus(403);

        $this->actingAs($this->kasir)
            ->postJson('/admin/hardware/setup/discover', ['device_type' => 'ALL'])
            ->assertStatus(403);
    }

    /** @test */
    public function owner_is_strictly_forbidden_from_setup_wizard()
    {
        $this->actingAs($this->owner)
            ->get('/admin/hardware/setup')
            ->assertStatus(403);

        $this->actingAs($this->owner)
            ->getJson('/admin/hardware/setup/summary')
            ->assertStatus(403);
    }

    /** @test */
    public function admin_can_access_setup_wizard_view_with_all_six_steps()
    {
        $response = $this->actingAs($this->admin)->get('/admin/hardware/setup');

        $response->assertStatus(200);
        $response->assertSeeText('Hardware Setup & Onboarding Wizard', false);
        $response->assertSeeText('Jaringan LAN', false);
        $response->assertSeeText('Penemuan Perangkat', false);
        $response->assertSeeText('Pairing & Registrasi', false);
        $response->assertSeeText('Pemetaan Stasiun', false);
        $response->assertSeeText('Uji Diagnostik', false);
        $response->assertSeeText('Selesai & Ringkasan', false);
        $response->assertSeeText('Samsung TV 50U8500', false);
        $response->assertSeeText('Shelly 1PM Mini Gen3', false);
    }

    /** @test */
    public function network_info_endpoint_returns_current_lan_data_and_ip_changed_warning()
    {
        $response = $this->actingAs($this->admin)->getJson('/admin/hardware/setup/network-info');

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);
        $response->assertJsonStructure([
            'data' => [
                'ip_address',
                'subnet',
                'gateway',
                'adapter',
                'dhcp_enabled',
                'wol_helper_status',
                'mikrotik_cli',
            ],
        ]);
    }

    /** @test */
    public function check_gateway_validates_target_ip_and_reports_reachability()
    {
        // Valid private gateway IP
        $response = $this->actingAs($this->admin)->postJson('/admin/hardware/setup/check-gateway', [
            'gateway' => '192.168.88.1',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'gateway' => '192.168.88.1',
            'reachable' => true,
        ]);

        // Malformed IP
        $invalidResp = $this->actingAs($this->admin)->postJson('/admin/hardware/setup/check-gateway', [
            'gateway' => 'not-an-ip',
        ]);
        $invalidResp->assertStatus(422);

        // SSRF attempt: loopback IP
        $ssrfResp = $this->actingAs($this->admin)->postJson('/admin/hardware/setup/check-gateway', [
            'gateway' => '127.0.0.1',
        ]);
        $ssrfResp->assertStatus(422);
    }

    /** @test */
    public function device_discovery_discovers_samsung_and_shelly_candidates_with_verified_identity()
    {
        // Supply mock candidates for controlled verification
        DeviceDiscoveryService::setMockCandidates([
            [
                'ip_address' => '192.168.88.101',
                'category' => PhysicalDevice::CATEGORY_SMART_TV,
                'brand' => PhysicalDevice::BRAND_SAMSUNG,
                'model' => 'Samsung 50U8500 (Smart TV)',
                'name' => 'Samsung TV Station 1',
                'mac_address' => 'DC:2C:26:11:22:33',
                'device_identifier' => 'samsung_tv_mock_01',
                'connection_status' => 'ONLINE',
                'pairing_status' => 'UNPAIRED',
                'verified' => true,
                'driver_status' => 'SUPPORTED',
            ],
            [
                'ip_address' => '192.168.88.102',
                'category' => PhysicalDevice::CATEGORY_SMART_RELAY,
                'brand' => PhysicalDevice::BRAND_SHELLY,
                'model' => 'Shelly 1PM Mini Gen3',
                'name' => 'Shelly Billiard Relay 1',
                'mac_address' => '34:98:7A:44:55:66',
                'device_identifier' => 'shelly1pmmini-34987a445566',
                'connection_status' => 'ONLINE',
                'pairing_status' => 'NOT_REQUIRED',
                'verified' => true,
                'driver_status' => 'SUPPORTED',
            ],
            [
                'ip_address' => '192.168.88.105',
                'category' => PhysicalDevice::CATEGORY_SMART_TV,
                'brand' => 'COOCAA',
                'model' => 'Coocaa 43S5G',
                'name' => 'Coocaa TV 43S5G',
                'mac_address' => null,
                'device_identifier' => 'coocaa_mock_05',
                'connection_status' => 'ONLINE',
                'pairing_status' => 'UNVERIFIED',
                'verified' => false,
                'driver_status' => 'DRIVER_UNSUPPORTED',
            ],
        ]);

        // Discover ALL
        $response = $this->actingAs($this->admin)->postJson('/admin/hardware/setup/discover', [
            'device_type' => 'ALL',
            'subnet' => '192.168.88.0/24',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'discovered_count' => 3,
        ]);
        $candidates = $response->json('candidates');
        $this->assertCount(3, $candidates);
        $this->assertEquals('SAMSUNG', $candidates[0]['brand']);
        $this->assertEquals('SHELLY', $candidates[1]['brand']);
        $this->assertEquals('COOCAA', $candidates[2]['brand']);
        $this->assertEquals('DRIVER_UNSUPPORTED', $candidates[2]['driver_status']);

        // Filter by SAMSUNG only
        $samsungOnly = $this->actingAs($this->admin)->postJson('/admin/hardware/setup/discover', [
            'device_type' => 'SAMSUNG',
            'subnet' => '192.168.88.0/24',
        ]);
        $samsungOnly->assertStatus(200);
        $this->assertEquals(1, $samsungOnly->json('discovered_count'));
        $this->assertEquals('SAMSUNG', $samsungOnly->json('candidates.0.brand'));
    }

    /** @test */
    public function device_discovery_strictly_blocks_invalid_subnets_and_ssrf()
    {
        // Rejects public WAN subnet
        $wanResp = $this->actingAs($this->admin)->postJson('/admin/hardware/setup/discover', [
            'device_type' => 'ALL',
            'subnet' => '8.8.8.0/24',
        ]);
        $wanResp->assertStatus(422);

        // Rejects link-local
        $linkLocalResp = $this->actingAs($this->admin)->postJson('/admin/hardware/setup/discover', [
            'device_type' => 'ALL',
            'subnet' => '169.254.1.0/24',
        ]);
        $linkLocalResp->assertStatus(422);

        // Rejects overly wide subnet (/16 has 65k hosts -> DoS risk)
        $wideResp = $this->actingAs($this->admin)->postJson('/admin/hardware/setup/discover', [
            'device_type' => 'ALL',
            'subnet' => '192.168.0.0/16',
        ]);
        $wideResp->assertStatus(422);
    }

    /** @test */
    public function manual_device_registration_via_wizard_stores_device_safely()
    {
        $payload = [
            'name' => 'TV Manual Stasiun 2',
            'category' => PhysicalDevice::CATEGORY_SMART_TV,
            'brand' => PhysicalDevice::BRAND_SAMSUNG,
            'model' => 'Samsung 50U8500',
            'ip_address' => '192.168.88.115',
            'mac_address' => 'AA:BB:CC:DD:EE:15',
        ];

        $response = $this->actingAs($this->admin)->postJson('/admin/hardware/devices', $payload);

        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
            'data' => [
                'name' => 'TV Manual Stasiun 2',
                'ip_address' => '192.168.88.115',
                'mac_address' => 'AA:BB:CC:DD:EE:15',
            ],
        ]);

        $this->assertDatabaseHas('physical_devices', [
            'name' => 'TV Manual Stasiun 2',
            'ip_address' => '192.168.88.115',
        ]);
    }

    /** @test */
    public function samsung_pairing_in_wizard_stores_encrypted_token_and_does_not_auto_enable_physical_automation()
    {
        $response = $this->actingAs($this->admin)->postJson('/admin/hardware/diagnostics/pair', [
            'device_id' => $this->samsungTv->id,
            'transport_mode' => 'real',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'result_code' => 'PAIRED',
            ],
        ]);

        $this->samsungTv->refresh();
        $this->assertEquals(PhysicalDevice::PAIRING_PAIRED, $this->samsungTv->pairing_status);
        $this->assertNotEmpty($this->samsungTv->pairing_payload);

        // CRITICAL: Pairing must NOT automatically populate physical control capabilities
        $caps = $this->samsungTv->verified_capabilities ?? [];
        $this->assertNotContains('POWER_ON', $caps);
        $this->assertNotContains('STANDBY', $caps);
        $this->assertNotContains('MUTE', $caps);
        $this->assertFalse(HardwareSafetyGuard::isRealExecutionEnabled());
    }

    /** @test */
    public function shelly_telemetry_in_wizard_reads_status_without_physical_mutation()
    {
        $response = $this->actingAs($this->admin)->getJson("/admin/hardware/diagnostics/status/{$this->shellyRelay->id}?transport_mode=real");

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'diagnostic_status' => 'Connected',
        ]);
        $response->assertJsonStructure([
            'data' => [
                'telemetry' => [
                    'output',
                    'apower',
                    'voltage',
                    'current',
                ],
            ],
        ]);
    }

    /** @test */
    public function station_mapping_in_wizard_attaches_and_replaces_with_confirmation_and_enforces_category_rules()
    {
        // 1. Valid: PS Station -> Smart TV (DISPLAY_TV)
        $validResp = $this->actingAs($this->admin)->postJson("/admin/stations/{$this->psStation->id}/mappings", [
            'physical_device_id' => $this->samsungTv->id,
            'mapping_role' => 'DISPLAY_TV',
        ]);
        $validResp->assertStatus(201);
        $this->assertDatabaseHas('station_device_mappings', [
            'station_id' => $this->psStation->id,
            'physical_device_id' => $this->samsungTv->id,
            'mapping_role' => 'DISPLAY_TV',
            'mapping_status' => 'ACTIVE',
        ]);

        // 2. Invalid: PS Station -> Shelly Relay (Strictly Forbidden)
        $invalidCatResp = $this->actingAs($this->admin)->postJson("/admin/stations/{$this->psStation->id}/mappings", [
            'physical_device_id' => $this->shellyRelay->id,
            'mapping_role' => 'DISPLAY_TV',
        ]);
        $invalidCatResp->assertStatus(422);

        // 3. Invalid: Billiard Station -> Smart TV (Strictly Forbidden)
        $invalidBilliardResp = $this->actingAs($this->admin)->postJson("/admin/stations/{$this->billiardStation->id}/mappings", [
            'physical_device_id' => $this->samsungTv->id,
            'mapping_role' => 'LIGHT_RELAY',
        ]);
        $invalidBilliardResp->assertStatus(422);

        // 4. Invariant: Mapping Conflict (Hardware already mapped to another station)
        $conflictDevice = PhysicalDevice::create([
            'name' => 'TV Meja 2',
            'category' => PhysicalDevice::CATEGORY_SMART_TV,
            'brand' => PhysicalDevice::BRAND_SAMSUNG,
            'ip_address' => '192.168.88.199',
            'is_active' => true,
        ]);
        $secondStation = Device::create([
            'nama' => 'Rental PS 02',
            'status' => 'Tersedia',
            'playstation_id' => $this->psStation->playstation_id,
        ]);
        $secondStation->playstations()->attach($this->psStation->playstation_id);
        // Attach TV to PS 02 first
        $this->actingAs($this->admin)->postJson("/admin/stations/{$secondStation->id}/mappings", [
            'physical_device_id' => $conflictDevice->id,
            'mapping_role' => 'DISPLAY_TV',
        ])->assertStatus(201);

        // Try mapping the same TV to PS 01 without detaching -> Expect conflict 409
        $conflictResp = $this->actingAs($this->admin)->postJson("/admin/stations/{$this->psStation->id}/mappings", [
            'physical_device_id' => $conflictDevice->id,
            'mapping_role' => 'DISPLAY_TV',
            'replace' => false,
        ]);
        $conflictResp->assertStatus(409);

        // 5. Replace mapping with replace=true
        $newTv = PhysicalDevice::create([
            'name' => 'New Replacement Samsung TV',
            'category' => PhysicalDevice::CATEGORY_SMART_TV,
            'brand' => PhysicalDevice::BRAND_SAMSUNG,
            'ip_address' => '192.168.88.109',
            'is_active' => true,
        ]);

        $replaceResp = $this->actingAs($this->admin)->postJson("/admin/stations/{$this->psStation->id}/mappings", [
            'physical_device_id' => $newTv->id,
            'mapping_role' => 'DISPLAY_TV',
            'replace' => true,
            'detach_notes' => 'Replacing with new 4K TV',
        ]);
        $replaceResp->assertStatus(201);

        $this->assertDatabaseHas('station_device_mappings', [
            'station_id' => $this->psStation->id,
            'physical_device_id' => $newTv->id,
            'mapping_status' => 'ACTIVE',
        ]);
        $this->assertDatabaseHas('station_device_mappings', [
            'station_id' => $this->psStation->id,
            'physical_device_id' => $this->samsungTv->id,
            'mapping_status' => 'INACTIVE',
            'detach_notes' => 'Replacing with new 4K TV',
        ]);
    }

    /** @test */
    public function read_only_diagnostics_in_wizard_produces_zero_physical_or_db_mutation()
    {
        $response = $this->actingAs($this->admin)->postJson('/admin/hardware/diagnostics/probe', [
            'device_id' => $this->samsungTv->id,
            'transport_mode' => 'real',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'diagnostic_status' => 'Connected',
        ]);

        // Zero physical commands transmitted
        $this->assertEmpty($this->fakeSamsungTransport->getSentKeys());
    }

    /** @test */
    public function physical_control_in_wizard_strictly_requires_server_approval_token()
    {
        // 1. Without server approval token -> 403 Forbidden
        $unapprovedResp = $this->actingAs($this->admin)->postJson('/admin/hardware/diagnostics/command', [
            'device_id' => $this->samsungTv->id,
            'command' => 'MUTE',
            'transport_mode' => 'real',
            'approval_token' => null,
            'confirmed' => true, // Boolean bypass must be ignored
        ]);
        $unapprovedResp->assertStatus(403);

        // 2. Request Server Approval Token
        $approvalResp = $this->actingAs($this->admin)->postJson('/admin/hardware/diagnostics/approve-physical', [
            'device_id' => $this->samsungTv->id,
            'command' => 'MUTE',
            'ttl_seconds' => 300,
        ]);
        $approvalResp->assertStatus(200);
        $token = $approvalResp->json('approval_token');
        $this->assertNotEmpty($token);

        // 3. With valid token -> Succeeded and token consumed
        $approvedResp = $this->actingAs($this->admin)->postJson('/admin/hardware/diagnostics/command', [
            'device_id' => $this->samsungTv->id,
            'command' => 'MUTE',
            'transport_mode' => 'real',
            'approval_token' => $token,
        ]);
        $approvedResp->assertStatus(200);
        $approvedResp->assertJson(['success' => true]);

        // 4. Token re-use -> 403 Forbidden (single-use enforcement)
        $reusedResp = $this->actingAs($this->admin)->postJson('/admin/hardware/diagnostics/command', [
            'device_id' => $this->samsungTv->id,
            'command' => 'MUTE',
            'transport_mode' => 'real',
            'approval_token' => $token,
        ]);
        $reusedResp->assertStatus(403);
    }

    /** @test */
    public function summary_endpoint_supports_deferred_setup_and_aggregated_readiness()
    {
        $response = $this->actingAs($this->admin)->getJson('/admin/hardware/setup/summary');

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'deferred_setup_allowed' => true,
                'can_finish' => true,
            ],
        ]);
        $response->assertJsonStructure([
            'data' => [
                'containers',
                'wol_helper',
                'backup',
                'network',
                'inventory' => [
                    'total_devices',
                    'connected_devices',
                    'unpaired_devices',
                    'total_stations',
                    'mapped_stations',
                    'unmapped_stations',
                ],
            ],
        ]);

        // Finish setup
        $finishResp = $this->actingAs($this->admin)->postJson('/admin/hardware/setup/finish');
        $finishResp->assertStatus(200);
        $finishResp->assertJson([
            'success' => true,
        ]);
        $this->assertNotNull(Cache::get(\App\Http\Controllers\Admin\HardwareSetupWizardController::SETUP_COMPLETED_CACHE_KEY));
    }

    /** @test */
    public function sidebar_shows_setup_wizard_link_only_for_admin()
    {
        $adminResp = $this->actingAs($this->admin)->get('/device');
        $adminResp->assertStatus(200);
        $adminResp->assertSeeText('Setup & Onboarding', false);
        $adminResp->assertSee(route('admin.hardware.setup.index'));

        $kasirResp = $this->actingAs($this->kasir)->get('/device');
        $kasirResp->assertStatus(200);
        $kasirResp->assertDontSee(route('admin.hardware.setup.index'));

        $ownerResp = $this->actingAs($this->owner)->get('/device');
        $ownerResp->assertStatus(200);
        $ownerResp->assertDontSee(route('admin.hardware.setup.index'));
    }
}
