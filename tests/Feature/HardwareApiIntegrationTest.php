<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\PhysicalDevice;
use App\Models\Playstation;
use App\Models\StationDeviceMapping;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BillingService;
use App\Services\Hardware\StationMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HardwareApiIntegrationTest extends TestCase
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
            'name' => 'Admin Testing',
            'role' => 'admin',
            'email' => 'admin_api@sugengrawuh.test',
        ]);

        $this->owner = User::factory()->create([
            'name' => 'Owner Testing',
            'role' => 'owner',
            'email' => 'owner_api@sugengrawuh.test',
        ]);

        $this->kasir = User::factory()->create([
            'name' => 'Kasir Testing',
            'role' => 'kasir',
            'email' => 'kasir_api@sugengrawuh.test',
        ]);

        // Create PlayStation Service & Station
        $this->psService = Playstation::create([
            'nama' => 'PS5 DELUXE ROOM',
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
            'nama' => 'BILLIARD 9-FEET 01',
            'harga' => 35000,
        ]);

        $this->billiardStation = Device::create([
            'nama' => 'MEJA-BILIAR-01',
            'status' => 'Tersedia',
            'playstation_id' => $this->billiardService->id,
        ]);
        $this->billiardStation->playstations()->attach($this->billiardService->id);
    }

    /*
    |--------------------------------------------------------------------------
    | 1. AUTHENTICATION & RBAC TESTS
    |--------------------------------------------------------------------------
    */

    /** @test */
    public function guest_cannot_access_protected_hardware_endpoints()
    {
        // Unauthenticated JSON request must receive 401 Unauthorized
        $response = $this->getJson('/admin/hardware/devices');
        $response->assertStatus(401);

        $response = $this->postJson('/admin/hardware/devices', []);
        $response->assertStatus(401);

        $response = $this->getJson("/admin/stations/{$this->psStation->id}/mappings");
        $response->assertStatus(401);
    }

    /** @test */
    public function admin_has_full_access_to_registry_and_mappings()
    {
        $this->actingAs($this->admin);

        // List devices
        $this->getJson('/admin/hardware/devices')
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        // Create device
        $createRes = $this->postJson('/admin/hardware/devices', [
            'name' => 'Samsung 50 AU7000',
            'category' => 'SMART_TV',
            'brand' => 'SAMSUNG',
            'model' => 'AU7000',
            'mac_address' => 'AA:BB:CC:11:22:01',
            'ip_address' => '192.168.1.101',
        ]);
        $createRes->assertStatus(201)
            ->assertJson(['success' => true]);

        $deviceId = $createRes->json('data.id');

        // Show detail
        $this->getJson("/admin/hardware/devices/{$deviceId}")
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        // Attach mapping
        $this->postJson("/admin/stations/{$this->psStation->id}/mappings", [
            'physical_device_id' => $deviceId,
            'mapping_role' => 'DISPLAY_TV',
            'attach_notes' => 'Pasang Smart TV',
        ])->assertStatus(201)
            ->assertJson(['success' => true]);

        // View station mappings
        $this->getJson("/admin/stations/{$this->psStation->id}/mappings")
            ->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => ['station_id', 'active_mappings', 'history'],
            ]);
    }

    /** @test */
    public function owner_can_read_registry_and_mappings_but_cannot_mutate()
    {
        $this->actingAs($this->owner);

        // Read registry -> 200
        $this->getJson('/admin/hardware/devices')->assertStatus(200);

        // Mutate registry -> 403 Forbidden
        $this->postJson('/admin/hardware/devices', [
            'name' => 'Unauthorized TV',
            'category' => 'SMART_TV',
            'brand' => 'SAMSUNG',
            'mac_address' => 'AA:BB:CC:11:22:02',
            'ip_address' => '192.168.1.102',
        ])->assertStatus(403);

        $this->putJson('/admin/hardware/devices/1', ['name' => 'Hacked'])->assertStatus(403);
        $this->deleteJson('/admin/hardware/devices/1')->assertStatus(403);

        // Read station mappings -> 200
        $this->getJson("/admin/stations/{$this->psStation->id}/mappings")->assertStatus(200);

        // Mutate station mapping -> 403 Forbidden
        $this->postJson("/admin/stations/{$this->psStation->id}/mappings", [
            'physical_device_id' => 1,
            'mapping_role' => 'DISPLAY_TV',
        ])->assertStatus(403);
    }

    /** @test */
    public function kasir_can_only_view_operational_mapping_and_cannot_access_registry_or_mutate()
    {
        $this->actingAs($this->kasir);

        // Kasir cannot view registry -> 403 Forbidden
        $this->getJson('/admin/hardware/devices')->assertStatus(403);
        $this->getJson('/admin/hardware/devices/1')->assertStatus(403);
        $this->postJson('/admin/hardware/devices', [])->assertStatus(403);
        $this->putJson('/admin/hardware/devices/1', [])->assertStatus(403);
        $this->deleteJson('/admin/hardware/devices/1')->assertStatus(403);

        // Kasir cannot mutate mapping -> 403 Forbidden
        $this->postJson("/admin/stations/{$this->psStation->id}/mappings", [])->assertStatus(403);
        $this->postJson("/admin/stations/{$this->psStation->id}/mappings/1/detach", [])->assertStatus(403);

        // Kasir CAN view station operational mapping -> 200 OK
        $res = $this->getJson("/admin/stations/{$this->psStation->id}/mappings");
        $res->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'station_id',
                    'station_name',
                    'station_status',
                    'active_hardware',
                ],
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 2. PHYSICAL DEVICE REGISTRY API TESTS
    |--------------------------------------------------------------------------
    */

    /** @test */
    public function register_samsung_tv_succeeds_with_unverified_status()
    {
        $this->actingAs($this->admin);

        $res = $this->postJson('/admin/hardware/devices', [
            'name' => 'Samsung TV 50 AU7000',
            'category' => 'SMART_TV',
            'brand' => 'SAMSUNG',
            'model' => 'AU7000',
            'mac_address' => 'aa-bb-cc-dd-ee-11',
            'ip_address' => '192.168.1.111',
            'device_identifier' => 'samsung-duid-111',
        ]);

        $res->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'category' => 'SMART_TV',
                    'brand' => 'SAMSUNG',
                    'mac_address' => 'AA:BB:CC:DD:EE:11', // Normalized
                    'ip_address' => '192.168.1.111',
                    'connection_status' => 'UNVERIFIED',
                    'pairing_status' => 'UNVERIFIED',
                    'is_active' => true,
                ],
            ]);
    }

    /** @test */
    public function register_shelly_smart_relay_succeeds()
    {
        $this->actingAs($this->admin);

        $res = $this->postJson('/admin/hardware/devices', [
            'name' => 'Shelly 1PM Gen3 Meja 1',
            'category' => 'SMART_RELAY',
            'brand' => 'SHELLY',
            'model' => '1PM Gen3 Mini',
            'mac_address' => 'dd:ee:ff:11:22:33',
            'ip_address' => '192.168.1.201',
            'device_identifier' => 'shelly-duid-201',
        ]);

        $res->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'category' => 'SMART_RELAY',
                    'brand' => 'SHELLY',
                    'mac_address' => 'DD:EE:FF:11:22:33',
                    'ip_address' => '192.168.1.201',
                ],
            ]);
    }

    /** @test */
    public function register_coocaa_is_strictly_rejected()
    {
        $this->actingAs($this->admin);

        $res = $this->postJson('/admin/hardware/devices', [
            'name' => 'Coocaa TV 43',
            'category' => 'SMART_TV',
            'brand' => 'COOCAA',
            'mac_address' => 'aa:bb:cc:33:44:55',
            'ip_address' => '192.168.1.115',
        ]);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['brand']);
    }

    /** @test */
    public function register_invalid_category_brand_combination_is_rejected()
    {
        $this->actingAs($this->admin);

        // Smart Relay with Samsung
        $res = $this->postJson('/admin/hardware/devices', [
            'name' => 'Invalid Relay Samsung',
            'category' => 'SMART_RELAY',
            'brand' => 'SAMSUNG',
            'mac_address' => 'aa:bb:cc:33:44:56',
            'ip_address' => '192.168.1.116',
        ]);

        $res->assertStatus(422);

        // Smart TV with Shelly
        $res = $this->postJson('/admin/hardware/devices', [
            'name' => 'Invalid TV Shelly',
            'category' => 'SMART_TV',
            'brand' => 'SHELLY',
            'mac_address' => 'aa:bb:cc:33:44:57',
            'ip_address' => '192.168.1.117',
        ]);

        $res->assertStatus(422);
    }

    /** @test */
    public function duplicate_mac_address_is_rejected_with_409()
    {
        $this->actingAs($this->admin);

        $this->postJson('/admin/hardware/devices', [
            'name' => 'Samsung TV Initial',
            'category' => 'SMART_TV',
            'brand' => 'SAMSUNG',
            'mac_address' => '11:22:33:44:55:66',
            'ip_address' => '192.168.1.120',
        ])->assertStatus(201);

        // Duplicate with different case / separators
        $res = $this->postJson('/admin/hardware/devices', [
            'name' => 'Samsung TV Duplicate',
            'category' => 'SMART_TV',
            'brand' => 'SAMSUNG',
            'mac_address' => '11-22-33-44-55-66',
            'ip_address' => '192.168.1.121',
        ]);

        $res->assertStatus(409)
            ->assertJson(['success' => false]);
    }

    /** @test */
    public function ip_conflict_with_active_device_is_rejected_with_409()
    {
        $this->actingAs($this->admin);

        $this->postJson('/admin/hardware/devices', [
            'name' => 'Device 1',
            'category' => 'SMART_TV',
            'brand' => 'SAMSUNG',
            'mac_address' => '11:22:33:44:55:01',
            'ip_address' => '192.168.1.130',
        ])->assertStatus(201);

        $res = $this->postJson('/admin/hardware/devices', [
            'name' => 'Device 2',
            'category' => 'SMART_RELAY',
            'brand' => 'SHELLY',
            'mac_address' => '11:22:33:44:55:02',
            'ip_address' => '192.168.1.130', // Same IP
        ]);

        $res->assertStatus(409)
            ->assertJson(['success' => false]);
    }

    /** @test */
    public function update_hardware_identity_and_reset_connection_status()
    {
        $this->actingAs($this->admin);

        $service = new StationMappingService();
        $device = $service->registerPhysicalDevice([
            'name' => 'Old Name',
            'category' => 'SMART_TV',
            'brand' => 'SAMSUNG',
            'mac_address' => '22:33:44:55:66:77',
            'ip_address' => '192.168.1.140',
        ]);

        $res = $this->putJson("/admin/hardware/devices/{$device->id}", [
            'name' => 'New Name VIP',
            'ip_address' => '192.168.1.141',
            'model' => 'AU7000-Updated',
        ]);

        $res->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'name' => 'New Name VIP',
                    'ip_address' => '192.168.1.141',
                    'model' => 'AU7000-Updated',
                ],
            ]);
    }

    /** @test */
    public function actively_mapped_device_cannot_be_deactivated()
    {
        $this->actingAs($this->admin);

        $service = new StationMappingService();
        $device = $service->registerPhysicalDevice([
            'name' => 'Samsung TV Mapped',
            'category' => 'SMART_TV',
            'brand' => 'SAMSUNG',
            'mac_address' => '33:44:55:66:77:88',
            'ip_address' => '192.168.1.150',
        ]);

        $service->attachHardware($this->psStation->id, $device->id, 'DISPLAY_TV', $this->admin->id);

        // Attempting to delete / deactivate via DELETE request must return 409 Conflict
        $delRes = $this->deleteJson("/admin/hardware/devices/{$device->id}");
        $delRes->assertStatus(409)
            ->assertJson(['success' => false]);

        // Attempting to deactivate via PUT request must also return 409 Conflict
        $putRes = $this->putJson("/admin/hardware/devices/{$device->id}", ['is_active' => false]);
        $putRes->assertStatus(409)
            ->assertJson(['success' => false]);

        // Detach hardware first
        $service->softDetach($this->psStation->id, 'DISPLAY_TV', $this->admin->id);

        // Deactivation now succeeds
        $this->deleteJson("/admin/hardware/devices/{$device->id}")
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertFalse((bool) $device->fresh()->is_active);
    }

    /** @test */
    public function security_sensitive_fields_cannot_be_tampered_and_pairing_payload_never_exposed()
    {
        $this->actingAs($this->admin);

        // Attempt to pass connection_status, pairing_payload, verified_capabilities
        $res = $this->postJson('/admin/hardware/devices', [
            'name' => 'Tamper Test Device',
            'category' => 'SMART_TV',
            'brand' => 'SAMSUNG',
            'mac_address' => '44:55:66:77:88:99',
            'ip_address' => '192.168.1.160',
            'connection_status' => 'ONLINE', // Client should not be able to set this
            'pairing_payload' => 'hacked_token', // Client should not be able to set this
            'verified_capabilities' => ['bypass' => true],
        ]);

        $res->assertStatus(201);
        $res->assertJsonMissing(['pairing_payload']);
        $res->assertJson([
            'data' => [
                'connection_status' => 'UNVERIFIED',
                'pairing_status' => 'UNVERIFIED',
            ],
        ]);

        $deviceId = $res->json('data.id');

        // Check show response: pairing_payload must never be exposed
        $showRes = $this->getJson("/admin/hardware/devices/{$deviceId}");
        $showRes->assertStatus(200);
        $showRes->assertJsonMissing(['pairing_payload']);
    }

    /*
    |--------------------------------------------------------------------------
    | 3. STATION MAPPING API TESTS
    |--------------------------------------------------------------------------
    */

    /** @test */
    public function attach_samsung_tv_to_playstation_station_succeeds()
    {
        $this->actingAs($this->admin);

        $service = new StationMappingService();
        $tv = $service->registerPhysicalDevice([
            'name' => 'Samsung TV Station 01',
            'category' => 'SMART_TV',
            'brand' => 'SAMSUNG',
            'mac_address' => '55:66:77:88:99:01',
            'ip_address' => '192.168.1.171',
        ]);

        $res = $this->postJson("/admin/stations/{$this->psStation->id}/mappings", [
            'physical_device_id' => $tv->id,
            'mapping_role' => 'DISPLAY_TV',
            'attach_notes' => 'Pasang TV Station 01',
        ]);

        $res->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'station_id' => $this->psStation->id,
                    'physical_device_id' => $tv->id,
                    'mapping_role' => 'DISPLAY_TV',
                    'mapping_status' => 'ACTIVE',
                    'attached_by_user_id' => $this->admin->id,
                    'attach_notes' => 'Pasang TV Station 01',
                ],
            ]);
    }

    /** @test */
    public function attach_shelly_relay_to_billiard_station_succeeds()
    {
        $this->actingAs($this->admin);

        $service = new StationMappingService();
        $relay = $service->registerPhysicalDevice([
            'name' => 'Shelly Relay Meja 1',
            'category' => 'SMART_RELAY',
            'brand' => 'SHELLY',
            'mac_address' => '55:66:77:88:99:02',
            'ip_address' => '192.168.1.172',
        ]);

        $res = $this->postJson("/admin/stations/{$this->billiardStation->id}/mappings", [
            'physical_device_id' => $relay->id,
            'mapping_role' => 'LIGHT_RELAY',
            'attach_notes' => 'Pasang Relay Lampu Meja 1',
        ]);

        $res->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'station_id' => $this->billiardStation->id,
                    'physical_device_id' => $relay->id,
                    'mapping_role' => 'LIGHT_RELAY',
                    'mapping_status' => 'ACTIVE',
                ],
            ]);
    }

    /** @test */
    public function cross_mapping_is_rejected_with_422()
    {
        $this->actingAs($this->admin);

        $service = new StationMappingService();
        $tv = $service->registerPhysicalDevice([
            'name' => 'Samsung TV',
            'category' => 'SMART_TV',
            'brand' => 'SAMSUNG',
            'mac_address' => '55:66:77:88:99:03',
            'ip_address' => '192.168.1.173',
        ]);

        $relay = $service->registerPhysicalDevice([
            'name' => 'Shelly Relay',
            'category' => 'SMART_RELAY',
            'brand' => 'SHELLY',
            'mac_address' => '55:66:77:88:99:04',
            'ip_address' => '192.168.1.174',
        ]);

        // Attempt to map Relay to PlayStation -> 422
        $res1 = $this->postJson("/admin/stations/{$this->psStation->id}/mappings", [
            'physical_device_id' => $relay->id,
            'mapping_role' => 'DISPLAY_TV',
        ]);
        $res1->assertStatus(422);

        // Attempt to map TV to Billiard -> 422
        $res2 = $this->postJson("/admin/stations/{$this->billiardStation->id}/mappings", [
            'physical_device_id' => $tv->id,
            'mapping_role' => 'LIGHT_RELAY',
        ]);
        $res2->assertStatus(422);
    }

    /** @test */
    public function mapping_mutation_while_station_is_in_use_rejected_with_409()
    {
        $this->actingAs($this->admin);

        $service = new StationMappingService();
        $tv = $service->registerPhysicalDevice([
            'name' => 'Samsung TV Occupied Test',
            'category' => 'SMART_TV',
            'brand' => 'SAMSUNG',
            'mac_address' => '55:66:77:88:99:05',
            'ip_address' => '192.168.1.175',
        ]);

        // Station is in 'Digunakan' state
        $this->psStation->update(['status' => 'Digunakan']);

        $res = $this->postJson("/admin/stations/{$this->psStation->id}/mappings", [
            'physical_device_id' => $tv->id,
            'mapping_role' => 'DISPLAY_TV',
        ]);

        $res->assertStatus(409)
            ->assertJson(['success' => false]);
    }

    /** @test */
    public function replace_hardware_preserves_audit_history()
    {
        $this->actingAs($this->admin);

        $service = new StationMappingService();
        $tvOld = $service->registerPhysicalDevice([
            'name' => 'Old TV 43',
            'category' => 'SMART_TV',
            'brand' => 'SAMSUNG',
            'mac_address' => '66:77:88:99:00:01',
            'ip_address' => '192.168.1.181',
        ]);

        $tvNew = $service->registerPhysicalDevice([
            'name' => 'New TV 55',
            'category' => 'SMART_TV',
            'brand' => 'SAMSUNG',
            'mac_address' => '66:77:88:99:00:02',
            'ip_address' => '192.168.1.182',
        ]);

        // Attach Old TV
        $this->postJson("/admin/stations/{$this->psStation->id}/mappings", [
            'physical_device_id' => $tvOld->id,
            'mapping_role' => 'DISPLAY_TV',
            'attach_notes' => 'Pasang TV pertama',
        ])->assertStatus(201);

        // Replace with New TV
        $res = $this->postJson("/admin/stations/{$this->psStation->id}/mappings", [
            'physical_device_id' => $tvNew->id,
            'mapping_role' => 'DISPLAY_TV',
            'replace' => true,
            'attach_notes' => 'Upgrade ke TV 55',
            'detach_notes' => 'TV 43 dipindahkan ke gudang',
        ]);

        $res->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'physical_device_id' => $tvNew->id,
                    'mapping_status' => 'ACTIVE',
                    'attach_notes' => 'Upgrade ke TV 55',
                ],
            ]);

        // Check history: should have 2 records
        $historyRes = $this->getJson("/admin/stations/{$this->psStation->id}/mappings");
        $historyRes->assertStatus(200);
        $this->assertCount(2, $historyRes->json('data.history'));
    }

    /** @test */
    public function soft_detach_records_admin_actor_and_notes()
    {
        $this->actingAs($this->admin);

        $service = new StationMappingService();
        $tv = $service->registerPhysicalDevice([
            'name' => 'TV Detach Test',
            'category' => 'SMART_TV',
            'brand' => 'SAMSUNG',
            'mac_address' => '77:88:99:00:11:22',
            'ip_address' => '192.168.1.190',
        ]);

        $mapping = $service->attachHardware($this->psStation->id, $tv->id, 'DISPLAY_TV', $this->admin->id);

        $res = $this->postJson("/admin/stations/{$this->psStation->id}/mappings/{$mapping->id}/detach", [
            'detach_notes' => 'Dilepas untuk renovasi ruangan',
        ]);

        $res->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'mapping_id' => $mapping->id,
                    'mapping_status' => 'INACTIVE',
                    'detached_by_user_id' => $this->admin->id,
                    'detach_notes' => 'Dilepas untuk renovasi ruangan',
                ],
            ]);
    }

    /** @test */
    public function detach_with_mismatched_station_id_returns_422()
    {
        $this->actingAs($this->admin);

        $service = new StationMappingService();
        $tv = $service->registerPhysicalDevice([
            'name' => 'TV Mismatch Test',
            'category' => 'SMART_TV',
            'brand' => 'SAMSUNG',
            'mac_address' => '88:99:00:11:22:33',
            'ip_address' => '192.168.1.195',
        ]);

        // Attached to psStation
        $mapping = $service->attachHardware($this->psStation->id, $tv->id, 'DISPLAY_TV', $this->admin->id);

        // Attempt to detach using billiardStation ID in URL
        $res = $this->postJson("/admin/stations/{$this->billiardStation->id}/mappings/{$mapping->id}/detach", [
            'detach_notes' => 'Wrong station test',
        ]);

        $res->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    /** @test */
    public function station_without_mapping_can_still_run_billing_unhindered()
    {
        $this->actingAs($this->kasir);

        // Station has 0 mappings
        $this->assertCount(0, $this->psStation->hardwareMappings);

        $billingService = new BillingService();

        // Start billing session
        $transaction = $billingService->startSession([
            'device_id' => $this->psStation->id,
            'playstation_id' => $this->psService->id,
            'tipe_transaksi' => 'prepaid',
            'durasi' => 1,
            'nama' => 'Pemain Tanpa Hardware API Test',
            'status_pembayaran' => 'unpaid',
        ], $this->kasir->id);

        $this->assertNotNull($transaction);
        $this->assertEquals(Transaction::BILLING_RUNNING, $transaction->billing_status);
        $this->assertEquals('Digunakan', $this->psStation->fresh()->status);

        // Stop session
        $stopped = $billingService->stopSession($transaction->id_transaksi, $this->kasir->id, 'Selesai normal');
        $this->assertEquals(Transaction::BILLING_STOPPED_EARLY, $stopped->billing_status);
        $this->assertEquals('Tersedia', $this->psStation->fresh()->status);
    }
}
