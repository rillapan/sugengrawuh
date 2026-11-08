<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\PhysicalDevice;
use App\Models\Playstation;
use App\Models\StationDeviceMapping;
use App\Models\User;
use App\Services\BillingService;
use App\Services\Hardware\StationMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HardwareFrontendTest extends TestCase
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
            'email' => 'admin_ui@sugengrawuh.test',
        ]);

        $this->owner = User::factory()->create([
            'name' => 'Owner Testing',
            'role' => 'owner',
            'email' => 'owner_ui@sugengrawuh.test',
        ]);

        $this->kasir = User::factory()->create([
            'name' => 'Kasir Testing',
            'role' => 'kasir',
            'email' => 'kasir_ui@sugengrawuh.test',
        ]);

        // Create PlayStation Service & Station
        $this->psService = Playstation::create([
            'nama' => 'PS5 VIP ROOM',
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

    /**
     * Test 01: Guest cannot access Blade views and is redirected to login.
     */
    public function test_guest_cannot_access_blade_views()
    {
        $resRegistry = $this->get('/admin/hardware/registry');
        $resRegistry->assertStatus(302)->assertRedirect('/login');

        $resMappings = $this->get('/admin/hardware/mappings');
        $resMappings->assertStatus(302)->assertRedirect('/login');
    }

    /**
     * Test 02: Admin has full access to Device Registry view with mutation modals.
     */
    public function test_admin_can_access_registry_view_with_mutation_controls()
    {
        $device = PhysicalDevice::create([
            'name' => 'Samsung TV 43 Inch',
            'category' => PhysicalDevice::CATEGORY_SMART_TV,
            'brand' => PhysicalDevice::BRAND_SAMSUNG,
            'model' => 'UA43CU7000',
            'mac_address' => 'AA:BB:CC:DD:EE:01',
            'ip_address' => '192.168.1.50',
            'device_identifier' => 'tv-ps5-01',
            'connection_status' => PhysicalDevice::CONNECTION_UNVERIFIED,
            'pairing_status' => PhysicalDevice::PAIRING_UNVERIFIED,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)->get('/admin/hardware/registry');

        $response->assertStatus(200);
        $response->assertSee('Device Registry — Hardware Fisik');
        $response->assertSee('Registrasi Hardware Baru');
        $response->assertSee('registerDeviceModal');
        $response->assertSee('editDeviceModal');
        $response->assertSee('deactivateDeviceModal');
        $response->assertSee('Samsung TV 43 Inch');
        $response->assertSee('AA:BB:CC:DD:EE:01');
        $response->assertSee('192.168.1.50');
        $response->assertSee('UNVERIFIED');
    }

    /**
     * Test 03: Owner has read-only access to Device Registry view (no mutation controls).
     */
    public function test_owner_can_access_registry_view_read_only()
    {
        $device = PhysicalDevice::create([
            'name' => 'Shelly Relay Meja 1',
            'category' => PhysicalDevice::CATEGORY_SMART_RELAY,
            'brand' => PhysicalDevice::BRAND_SHELLY,
            'model' => '1PM Gen3 Mini',
            'mac_address' => '11:22:33:44:55:66',
            'ip_address' => '192.168.1.60',
            'connection_status' => PhysicalDevice::CONNECTION_UNVERIFIED,
            'pairing_status' => PhysicalDevice::PAIRING_UNVERIFIED,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->owner)->get('/admin/hardware/registry');

        $response->assertStatus(200);
        $response->assertSee('Device Registry — Hardware Fisik');
        $response->assertSee('Mode Read-Only (Owner)');
        $response->assertDontSee('data-target="#registerDeviceModal"', false);
        $response->assertDontSee('id="registerDeviceModal"', false);
        $response->assertSee('Shelly Relay Meja 1');
        $response->assertSee('Read-only');
    }

    /**
     * Test 04: Kasir is strictly forbidden (403) from Device Registry view.
     */
    public function test_kasir_is_forbidden_from_registry_view()
    {
        $response = $this->actingAs($this->kasir)->get('/admin/hardware/registry');
        $response->assertStatus(403);
    }

    /**
     * Test 05: Admin can access Station Mapping view with attach/replace/detach modals.
     */
    public function test_admin_can_access_mappings_view_with_mutation_modals()
    {
        $response = $this->actingAs($this->admin)->get('/admin/hardware/mappings?station_id=' . $this->psStation->id);

        $response->assertStatus(200);
        $response->assertSee('Station Hardware Mapping');
        $response->assertSee('PS5-VIP-01');
        $response->assertSee('PLAYSTATION');
        $response->assertSee('Samsung Smart TV');
        $response->assertSee('attachHardwareModal');
        $response->assertSee('replaceHardwareModal');
        $response->assertSee('detachHardwareModal');
    }

    /**
     * Test 06: Owner has read-only access to Station Mapping view.
     */
    public function test_owner_can_access_mappings_view_read_only()
    {
        $response = $this->actingAs($this->owner)->get('/admin/hardware/mappings?station_id=' . $this->psStation->id);

        $response->assertStatus(200);
        $response->assertSee('Station Hardware Mapping');
        $response->assertSee('Mode Read-Only (Owner)');
        $response->assertDontSee('id="attachHardwareModal"', false);
        $response->assertDontSee('id="replaceHardwareModal"', false);
        $response->assertDontSee('id="detachHardwareModal"', false);
    }

    /**
     * Test 07: Kasir is strictly forbidden (403) from Station Mapping view.
     */
    public function test_kasir_is_forbidden_from_mappings_view()
    {
        $response = $this->actingAs($this->kasir)->get('/admin/hardware/mappings');
        $response->assertStatus(403);
    }

    /**
     * Test 08: /device station card displays concise hardware info for PlayStation (Samsung Smart TV).
     */
    public function test_device_page_displays_hardware_info_for_playstation_station()
    {
        $tv = PhysicalDevice::create([
            'name' => 'TV Samsung Ruang VIP',
            'category' => PhysicalDevice::CATEGORY_SMART_TV,
            'brand' => PhysicalDevice::BRAND_SAMSUNG,
            'model' => 'UA43CU7000',
            'mac_address' => 'AA:BB:CC:DD:EE:11',
            'ip_address' => '192.168.1.51',
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
            'attach_notes' => 'Pemasangan awal TV',
        ]);

        // Kasir visiting /device
        $response = $this->actingAs($this->kasir)->get('/device');

        $response->assertStatus(200);
        $response->assertSee('Hardware:');
        $response->assertSee('TV Samsung Ruang VIP');
        $response->assertSee('SAMSUNG UA43CU7000');
        $response->assertSee('UNVERIFIED');
        $response->assertSee('DISPLAY_TV');
        // Critical: Cashier NEVER sees IP or MAC address on station card
        $response->assertDontSee('192.168.1.51');
        $response->assertDontSee('AA:BB:CC:DD:EE:11');
    }

    /**
     * Test 09: /device station card displays concise hardware info for Billiard (Shelly Relay).
     */
    public function test_device_page_displays_hardware_info_for_billiard_station()
    {
        $relay = PhysicalDevice::create([
            'name' => 'Shelly Lampu Meja 01',
            'category' => PhysicalDevice::CATEGORY_SMART_RELAY,
            'brand' => PhysicalDevice::BRAND_SHELLY,
            'model' => '1PM Gen3 Mini',
            'mac_address' => '33:44:55:66:77:88',
            'ip_address' => '192.168.1.70',
            'connection_status' => PhysicalDevice::CONNECTION_UNVERIFIED,
            'pairing_status' => PhysicalDevice::PAIRING_UNVERIFIED,
            'is_active' => true,
        ]);

        StationDeviceMapping::create([
            'station_id' => $this->billiardStation->id,
            'physical_device_id' => $relay->id,
            'mapping_role' => StationDeviceMapping::ROLE_LIGHT_RELAY,
            'mapping_status' => StationDeviceMapping::STATUS_ACTIVE,
            'attached_by_user_id' => $this->admin->id,
            'attached_at' => now(),
            'attach_notes' => 'Relay lampu meja biliar',
        ]);

        $response = $this->actingAs($this->kasir)->get('/device');

        $response->assertStatus(200);
        $response->assertSee('Hardware:');
        $response->assertSee('Shelly Lampu Meja 01');
        $response->assertSee('SHELLY 1PM Gen3 Mini');
        $response->assertSee('LIGHT_RELAY');
        $response->assertSee('UNVERIFIED');
        $response->assertDontSee('192.168.1.70');
        $response->assertDontSee('33:44:55:66:77:88');
    }

    /**
     * Test 10: /device displays "Belum ada hardware terdaftar" when station is unmapped and billing works.
     */
    public function test_device_page_displays_unmapped_notice_and_runs_billing()
    {
        $response = $this->actingAs($this->kasir)->get('/device');

        $response->assertStatus(200);
        $response->assertSee('Belum ada hardware terdaftar');
        $response->assertSee('Billing tetap dapat digunakan');

        // Test running billing on this unmapped station via existing transaction store
        $startResponse = $this->actingAs($this->kasir)
            ->post('/transaction', [
                'device_id' => $this->psStation->id,
                'nama' => 'Pelanggan Manual',
                'tipe_transaksi' => 'postpaid',
                'jam_main' => 'Lost Time',
            ]);

        $startResponse->assertSessionHasNoErrors();
        $this->assertEquals('Digunakan', $this->psStation->fresh()->status);
    }

    /**
     * Test 11: Sidebar shows Hardware IoT menu to Admin and Owner, but hides from Kasir.
     */
    public function test_sidebar_rbac_visibility()
    {
        // Admin sees menu
        $resAdmin = $this->actingAs($this->admin)->get('/device');
        $resAdmin->assertSee('Hardware IoT');
        $resAdmin->assertSee(route('admin.hardware.registry'));
        $resAdmin->assertSee(route('admin.hardware.mappings'));

        // Owner sees menu
        $resOwner = $this->actingAs($this->owner)->get('/device');
        $resOwner->assertSee('Hardware IoT');
        $resOwner->assertSee(route('admin.hardware.registry'));
        $resOwner->assertSee(route('admin.hardware.mappings'));

        // Kasir DOES NOT see menu
        $resKasir = $this->actingAs($this->kasir)->get('/device');
        $resKasir->assertDontSee('Hardware IoT');
        $resKasir->assertDontSee(route('admin.hardware.registry'));
        $resKasir->assertDontSee(route('admin.hardware.mappings'));
    }

    /**
     * Test 12: Device Registry filtering by category works as expected.
     */
    public function test_registry_view_filtering()
    {
        PhysicalDevice::create([
            'name' => 'TV Samsung Filter Test',
            'category' => PhysicalDevice::CATEGORY_SMART_TV,
            'brand' => PhysicalDevice::BRAND_SAMSUNG,
            'model' => 'UA43',
            'mac_address' => 'AA:11:22:33:44:55',
            'ip_address' => '192.168.1.80',
            'connection_status' => PhysicalDevice::CONNECTION_UNVERIFIED,
            'pairing_status' => PhysicalDevice::PAIRING_UNVERIFIED,
            'is_active' => true,
        ]);

        PhysicalDevice::create([
            'name' => 'Relay Shelly Filter Test',
            'category' => PhysicalDevice::CATEGORY_SMART_RELAY,
            'brand' => PhysicalDevice::BRAND_SHELLY,
            'model' => '1PM',
            'mac_address' => 'BB:11:22:33:44:55',
            'ip_address' => '192.168.1.81',
            'connection_status' => PhysicalDevice::CONNECTION_UNVERIFIED,
            'pairing_status' => PhysicalDevice::PAIRING_UNVERIFIED,
            'is_active' => true,
        ]);

        $resTv = $this->actingAs($this->admin)->get('/admin/hardware/registry?category=SMART_TV');
        $resTv->assertSee('TV Samsung Filter Test');
        $resTv->assertDontSee('Relay Shelly Filter Test');

        $resRelay = $this->actingAs($this->admin)->get('/admin/hardware/registry?category=SMART_RELAY');
        $resRelay->assertSee('Relay Shelly Filter Test');
        $resRelay->assertDontSee('TV Samsung Filter Test');
    }

    /**
     * Test 13: Station Mapping view filters hardware compatibility by station type.
     */
    public function test_mappings_view_hardware_filtering_by_station_type()
    {
        $tv = PhysicalDevice::create([
            'name' => 'Samsung TV Unmapped',
            'category' => PhysicalDevice::CATEGORY_SMART_TV,
            'brand' => PhysicalDevice::BRAND_SAMSUNG,
            'model' => 'UA43',
            'mac_address' => 'AA:AA:AA:AA:AA:01',
            'ip_address' => '192.168.1.90',
            'connection_status' => PhysicalDevice::CONNECTION_UNVERIFIED,
            'pairing_status' => PhysicalDevice::PAIRING_UNVERIFIED,
            'is_active' => true,
        ]);

        $relay = PhysicalDevice::create([
            'name' => 'Shelly Relay Unmapped',
            'category' => PhysicalDevice::CATEGORY_SMART_RELAY,
            'brand' => PhysicalDevice::BRAND_SHELLY,
            'model' => '1PM',
            'mac_address' => 'BB:BB:BB:BB:BB:01',
            'ip_address' => '192.168.1.91',
            'connection_status' => PhysicalDevice::CONNECTION_UNVERIFIED,
            'pairing_status' => PhysicalDevice::PAIRING_UNVERIFIED,
            'is_active' => true,
        ]);

        // When viewing PlayStation station
        $resPs = $this->actingAs($this->admin)->get('/admin/hardware/mappings?station_id=' . $this->psStation->id);
        $resPs->assertSee('Samsung TV Unmapped');
        // In the attach modal for PS, Shelly Relay must NOT be an option
        $resPs->assertDontSee('Shelly Relay Unmapped');

        // When viewing Billiard station
        $resBilliard = $this->actingAs($this->admin)->get('/admin/hardware/mappings?station_id=' . $this->billiardStation->id);
        $resBilliard->assertSee('Shelly Relay Unmapped');
        // In the attach modal for Billiard, Samsung TV must NOT be an option
        $resBilliard->assertDontSee('Samsung TV Unmapped');
    }

    /**
     * Test 14: Station Mapping view alerts when billing is active on station.
     */
    public function test_mappings_view_shows_active_billing_protection_alert()
    {
        // Mark station as occupied
        $this->psStation->update(['status' => 'Digunakan']);

        $response = $this->actingAs($this->admin)->get('/admin/hardware/mappings?station_id=' . $this->psStation->id);

        $response->assertStatus(200);
        $response->assertSee('DIGUNAKAN (BILLING AKTIF)');
        $response->assertSee('Proteksi Billing Aktif');
    }

    /**
     * Test 15: Mapping history displays audit trail with admin actor and notes.
     */
    public function test_mappings_view_renders_audit_trail_history()
    {
        $tv = PhysicalDevice::create([
            'name' => 'TV Samsung Histori Test',
            'category' => PhysicalDevice::CATEGORY_SMART_TV,
            'brand' => PhysicalDevice::BRAND_SAMSUNG,
            'model' => 'UA43',
            'mac_address' => 'CC:CC:CC:CC:CC:01',
            'ip_address' => '192.168.1.95',
            'connection_status' => PhysicalDevice::CONNECTION_UNVERIFIED,
            'pairing_status' => PhysicalDevice::PAIRING_UNVERIFIED,
            'is_active' => true,
        ]);

        StationDeviceMapping::create([
            'station_id' => $this->psStation->id,
            'physical_device_id' => $tv->id,
            'mapping_role' => StationDeviceMapping::ROLE_DISPLAY_TV,
            'mapping_status' => StationDeviceMapping::STATUS_INACTIVE,
            'attached_by_user_id' => $this->admin->id,
            'attached_at' => now()->subDays(2),
            'attach_notes' => 'Catatan Pasang Historis',
            'detached_by_user_id' => $this->admin->id,
            'detached_at' => now()->subDay(),
            'detach_notes' => 'Catatan Lepas Historis',
        ]);

        $response = $this->actingAs($this->admin)->get('/admin/hardware/mappings?station_id=' . $this->psStation->id);

        $response->assertStatus(200);
        $response->assertSee('Histori Pemetaan Stasiun (Audit Trail)');
        $response->assertSee('TV Samsung Histori Test');
        $response->assertSee('Catatan Pasang Historis');
        $response->assertSee('Catatan Lepas Historis');
        $response->assertSee('INACTIVE');
    }
}
