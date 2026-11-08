<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\Device;
use App\Models\PhysicalDevice;
use App\Models\Playstation;
use App\Models\StationDeviceMapping;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HardwareFinalIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $owner;
    protected User $kasir;
    protected Device $psStation;
    protected Device $billiardStation;
    protected Playstation $psService;
    protected Playstation $billiardService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'name' => 'Admin QA Final',
            'role' => 'admin',
            'email' => 'admin_final@sugengrawuh.test',
        ]);

        $this->owner = User::factory()->create([
            'name' => 'Owner QA Final',
            'role' => 'owner',
            'email' => 'owner_final@sugengrawuh.test',
        ]);

        $this->kasir = User::factory()->create([
            'name' => 'Kasir QA Final',
            'role' => 'kasir',
            'email' => 'kasir_final@sugengrawuh.test',
        ]);

        // Create PlayStation Service & Station
        $this->psService = Playstation::create([
            'nama' => 'PS5 VIP 01',
            'harga' => 20000,
        ]);

        $this->psStation = Device::create([
            'nama' => 'PS5-VIP-01',
            'status' => 'Tersedia',
            'playstation_id' => $this->psService->id,
        ]);
        $this->psStation->playstations()->attach($this->psService->id);

        // Create Billiard Service & Station
        $this->billiardService = Playstation::create([
            'nama' => 'MEJA BILIAR 01',
            'harga' => 35000,
        ]);

        $this->billiardStation = Device::create([
            'nama' => 'BILIAR-01',
            'status' => 'Tersedia',
            'playstation_id' => $this->billiardService->id,
        ]);
        $this->billiardStation->playstations()->attach($this->billiardService->id);
    }

    /**
     * Test 01: CSRF Protection is strictly enforced on mutation endpoints when CSRF middleware is active.
     */
    public function test_csrf_protection_is_enforced_on_mutations()
    {
        // Test with VerifyCsrfToken middleware actively evaluating requests
        $middleware = new class(app(), app('encrypter')) extends VerifyCsrfToken {
            protected function runningUnitTests()
            {
                return false;
            }
        };

        // 1. Request without CSRF token throws TokenMismatchException
        $invalidRequest = \Illuminate\Http\Request::create('/admin/hardware/devices', 'POST', [
            'name' => 'TV CSRF Test',
        ]);
        $session = app('session.store');
        $session->start();
        $invalidRequest->setLaravelSession($session);

        $this->expectException(\Illuminate\Session\TokenMismatchException::class);
        $middleware->handle($invalidRequest, function () {
            return response('OK');
        });
    }

    /**
     * Test 01b: When valid CSRF token is provided, middleware allows request through.
     */
    public function test_csrf_middleware_allows_request_with_valid_token()
    {
        $middleware = new class(app(), app('encrypter')) extends VerifyCsrfToken {
            protected function runningUnitTests()
            {
                return false;
            }
        };

        $validRequest = \Illuminate\Http\Request::create('/admin/hardware/devices', 'POST', [
            'name' => 'TV CSRF Valid',
        ]);
        $session = app('session.store');
        $session->start();
        $token = $session->token();
        $validRequest->setLaravelSession($session);
        $validRequest->headers->set('X-CSRF-TOKEN', $token);

        $passed = false;
        $response = $middleware->handle($validRequest, function () use (&$passed) {
            $passed = true;
            return response('PASSED');
        });

        $this->assertTrue($passed);
        $this->assertEquals('PASSED', $response->getContent());
    }

    /**
     * Test 02: Route parameter tampering attack is rejected (mapping ID belongs to another station).
     */
    public function test_route_parameter_tampering_is_rejected()
    {
        $tv = PhysicalDevice::create([
            'name' => 'TV Station A',
            'category' => PhysicalDevice::CATEGORY_SMART_TV,
            'brand' => PhysicalDevice::BRAND_SAMSUNG,
            'model' => 'UA43',
            'mac_address' => 'AA:BB:CC:11:22:44',
            'ip_address' => '192.168.1.101',
            'connection_status' => PhysicalDevice::CONNECTION_UNVERIFIED,
            'pairing_status' => PhysicalDevice::PAIRING_UNVERIFIED,
            'is_active' => true,
        ]);

        // Attach TV to psStation (ID = $this->psStation->id)
        $mapping = StationDeviceMapping::create([
            'station_id' => $this->psStation->id,
            'physical_device_id' => $tv->id,
            'mapping_role' => StationDeviceMapping::ROLE_DISPLAY_TV,
            'mapping_status' => StationDeviceMapping::STATUS_ACTIVE,
            'attached_by_user_id' => $this->admin->id,
            'attached_at' => now(),
        ]);

        // Attacker attempts to detach $mapping using $billiardStation in URL path
        $tamperedUrl = '/admin/stations/' . $this->billiardStation->id . '/mappings/' . $mapping->id . '/detach';

        $response = $this->actingAs($this->admin)->postJson($tamperedUrl, [
            'detach_notes' => 'Tampered detach attempt',
        ]);

        // Must reject with 422 Unprocessable Entity
        $response->assertStatus(422);
        $this->assertEquals(StationDeviceMapping::STATUS_ACTIVE, $mapping->fresh()->mapping_status);
    }

    /**
     * Test 03: Pairing payload, secret tokens, and credentials are never exposed in API responses.
     */
    public function test_pairing_payload_is_never_exposed()
    {
        $tv = PhysicalDevice::create([
            'name' => 'Secure TV',
            'category' => PhysicalDevice::CATEGORY_SMART_TV,
            'brand' => PhysicalDevice::BRAND_SAMSUNG,
            'model' => 'UA43',
            'mac_address' => 'AA:BB:CC:11:22:55',
            'ip_address' => '192.168.1.102',
            'pairing_payload' => 'super_secret_token_12345',
            'connection_status' => PhysicalDevice::CONNECTION_UNVERIFIED,
            'pairing_status' => PhysicalDevice::PAIRING_UNVERIFIED,
            'is_active' => true,
        ]);

        // Index JSON endpoint
        $resIndex = $this->actingAs($this->admin)->getJson('/admin/hardware/devices');
        $resIndex->assertStatus(200);
        $resIndex->assertDontSee('super_secret_token_12345');
        $resIndex->assertDontSee('pairing_payload');

        // Show JSON endpoint
        $resShow = $this->actingAs($this->admin)->getJson('/admin/hardware/devices/' . $tv->id);
        $resShow->assertStatus(200);
        $resShow->assertDontSee('super_secret_token_12345');
        $resShow->assertDontSee('pairing_payload');

        // Blade HTML Registry view
        $resBlade = $this->actingAs($this->admin)->get('/admin/hardware/registry');
        $resBlade->assertStatus(200);
        $resBlade->assertDontSee('super_secret_token_12345');
    }

    /**
     * Test 04: Owner direct mutation attempts via API are strictly forbidden (403).
     */
    public function test_owner_direct_api_mutations_forbidden()
    {
        // Owner attempting hardware registration
        $resCreate = $this->actingAs($this->owner)->postJson('/admin/hardware/devices', [
            'name' => 'Owner TV Test',
            'category' => 'SMART_TV',
            'brand' => 'SAMSUNG',
            'model' => 'UA43',
            'mac_address' => 'AA:BB:CC:11:22:66',
            'ip_address' => '192.168.1.103',
        ]);
        $resCreate->assertStatus(403);

        // Owner attempting station mapping attach
        $resAttach = $this->actingAs($this->owner)->postJson('/admin/stations/' . $this->psStation->id . '/mappings', [
            'physical_device_id' => 1,
            'mapping_role' => 'DISPLAY_TV',
        ]);
        $resAttach->assertStatus(403);
    }

    /**
     * Test 05: Kasir station mappings operational endpoint strictly filters out technical details.
     */
    public function test_kasir_station_mappings_operational_partitioning()
    {
        $tv = PhysicalDevice::create([
            'name' => 'TV Kasir View Test',
            'category' => PhysicalDevice::CATEGORY_SMART_TV,
            'brand' => PhysicalDevice::BRAND_SAMSUNG,
            'model' => 'UA43',
            'mac_address' => 'AA:BB:CC:11:22:77',
            'ip_address' => '192.168.1.104',
            'device_identifier' => 'secret_dev_id_999',
            'connection_status' => PhysicalDevice::CONNECTION_UNVERIFIED,
            'pairing_status' => PhysicalDevice::PAIRING_UNVERIFIED,
            'is_active' => true,
        ]);

        StationDeviceMapping::create([
            'station_id' => $this->psStation->id,
            'physical_device_id' => $tv->id,
            'mapping_role' => StationDeviceMapping::ROLE_DISPLAY_TV,
            'mapping_status' => StationDeviceMapping::STATUS_ACTIVE,
            'attached_by_user_id' => $this->admin->id,
            'attached_at' => now(),
        ]);

        $response = $this->actingAs($this->kasir)->getJson('/admin/stations/' . $this->psStation->id . '/mappings');

        $response->assertStatus(200);
        $response->assertJsonPath('data.active_hardware.0.device_name', 'TV Kasir View Test');
        $response->assertJsonPath('data.active_hardware.0.brand', 'SAMSUNG');
        $response->assertJsonPath('data.active_hardware.0.connection_status', 'UNVERIFIED');

        // Verify technical attributes are NOT present in response
        $response->assertDontSee('192.168.1.104');
        $response->assertDontSee('AA:BB:CC:11:22:77');
        $response->assertDontSee('secret_dev_id_999');
        $response->assertDontSee('history');
    }

    /**
     * Test 06: Deactivating actively mapped device is rejected with 409 Conflict.
     */
    public function test_actively_mapped_device_cannot_be_deleted()
    {
        $tv = PhysicalDevice::create([
            'name' => 'TV Active Deletion Test',
            'category' => PhysicalDevice::CATEGORY_SMART_TV,
            'brand' => PhysicalDevice::BRAND_SAMSUNG,
            'model' => 'UA43',
            'mac_address' => 'AA:BB:CC:11:22:88',
            'ip_address' => '192.168.1.105',
            'connection_status' => PhysicalDevice::CONNECTION_UNVERIFIED,
            'pairing_status' => PhysicalDevice::PAIRING_UNVERIFIED,
            'is_active' => true,
        ]);

        StationDeviceMapping::create([
            'station_id' => $this->psStation->id,
            'physical_device_id' => $tv->id,
            'mapping_role' => StationDeviceMapping::ROLE_DISPLAY_TV,
            'mapping_status' => StationDeviceMapping::STATUS_ACTIVE,
            'attached_by_user_id' => $this->admin->id,
            'attached_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->deleteJson('/admin/hardware/devices/' . $tv->id);

        $response->assertStatus(409);
        $this->assertTrue($tv->fresh()->is_active);
    }

    /**
     * Test 07: Station mapping mutation during RUNNING billing returns 409 Conflict.
     */
    public function test_mapping_mutations_rejected_during_active_billing()
    {
        $tv = PhysicalDevice::create([
            'name' => 'TV Billing Conflict Test',
            'category' => PhysicalDevice::CATEGORY_SMART_TV,
            'brand' => PhysicalDevice::BRAND_SAMSUNG,
            'model' => 'UA43',
            'mac_address' => 'AA:BB:CC:11:22:99',
            'ip_address' => '192.168.1.106',
            'connection_status' => PhysicalDevice::CONNECTION_UNVERIFIED,
            'pairing_status' => PhysicalDevice::PAIRING_UNVERIFIED,
            'is_active' => true,
        ]);

        // Occupy station
        $this->psStation->update(['status' => 'Digunakan']);

        // Attempt attach
        $resAttach = $this->actingAs($this->admin)->postJson('/admin/stations/' . $this->psStation->id . '/mappings', [
            'physical_device_id' => $tv->id,
            'mapping_role' => 'DISPLAY_TV',
        ]);
        $resAttach->assertStatus(409);
        $resAttach->assertJsonPath('success', false);

        // Free station and attach
        $this->psStation->update(['status' => 'Tersedia']);
        $resAttachFree = $this->actingAs($this->admin)->postJson('/admin/stations/' . $this->psStation->id . '/mappings', [
            'physical_device_id' => $tv->id,
            'mapping_role' => 'DISPLAY_TV',
        ]);
        $resAttachFree->assertStatus(201);
        $mappingId = $resAttachFree->json('data.mapping_id');

        // Occupy again and attempt detach
        $this->psStation->update(['status' => 'Digunakan']);
        $resDetach = $this->actingAs($this->admin)->postJson('/admin/stations/' . $this->psStation->id . '/mappings/' . $mappingId . '/detach', [
            'detach_notes' => 'Attempting detach while in use',
        ]);
        $resDetach->assertStatus(409);
    }
}
