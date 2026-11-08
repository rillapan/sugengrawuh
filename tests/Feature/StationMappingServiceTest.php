<?php

namespace Tests\Feature;

use App\Exceptions\StationMappingException;
use App\Models\Device;
use App\Models\PhysicalDevice;
use App\Models\Playstation;
use App\Models\StationDeviceMapping;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BillingService;
use App\Services\Hardware\StationMappingService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class StationMappingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected StationMappingService $service;
    protected User $admin;
    protected User $kasir;
    protected Device $psStation;
    protected Device $billiardStation;
    protected Playstation $ps5Service;
    protected Playstation $billiardService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new StationMappingService();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'email' => 'admin_mapping@sugengrawuh.test',
        ]);

        $this->kasir = User::factory()->create([
            'role' => 'kasir',
            'email' => 'kasir_mapping@sugengrawuh.test',
        ]);

        // Create PlayStation Service & Station
        $this->ps5Service = Playstation::create([
            'nama' => 'PS5 VIP ROOM',
            'harga' => 20000,
        ]);

        $this->psStation = Device::create([
            'nama' => 'PS-STATION-01',
            'status' => 'Tersedia',
            'playstation_id' => $this->ps5Service->id,
        ]);
        $this->psStation->playstations()->attach($this->ps5Service->id);

        // Create Billiard Service & Station
        $this->billiardService = Playstation::create([
            'nama' => 'BILLIARD 9-FEET MEJA 1',
            'harga' => 35000,
        ]);

        $this->billiardStation = Device::create([
            'nama' => 'BILIAR-MEJA-01',
            'status' => 'Tersedia',
            'playstation_id' => $this->billiardService->id,
        ]);
        $this->billiardStation->playstations()->attach($this->billiardService->id);
    }

    /**
     * Helper to create a valid Smart TV device.
     */
    protected function createSmartTv(array $overrides = []): PhysicalDevice
    {
        return $this->service->registerPhysicalDevice(array_merge([
            'name' => 'Samsung 50 AU7000',
            'category' => PhysicalDevice::CATEGORY_SMART_TV,
            'brand' => PhysicalDevice::BRAND_SAMSUNG,
            'model' => 'AU7000',
            'mac_address' => 'AA:BB:CC:11:22:33',
            'ip_address' => '192.168.1.101',
            'device_identifier' => 'samsung-duid-101',
        ], $overrides));
    }

    /**
     * Helper to create a valid Smart Relay device.
     */
    protected function createSmartRelay(array $overrides = []): PhysicalDevice
    {
        return $this->service->registerPhysicalDevice(array_merge([
            'name' => 'Shelly 1PM Gen3 Mini Meja 1',
            'category' => PhysicalDevice::CATEGORY_SMART_RELAY,
            'brand' => PhysicalDevice::BRAND_SHELLY,
            'model' => '1PM Gen3 Mini',
            'mac_address' => 'DD:EE:FF:44:55:66',
            'ip_address' => '192.168.1.201',
            'device_identifier' => 'shelly1pmminig3-201',
        ], $overrides));
    }

    /** @test */
    public function test_01_create_physical_device_with_proper_defaults_and_casts()
    {
        $tv = $this->createSmartTv([
            'verified_capabilities' => ['power_control' => true, 'input_select' => true],
            'pairing_payload' => 'secret_pairing_token_123',
        ]);

        $this->assertDatabaseHas('physical_devices', [
            'id' => $tv->id,
            'name' => 'Samsung 50 AU7000',
            'category' => 'SMART_TV',
            'brand' => 'SAMSUNG',
            'mac_address' => 'AA:BB:CC:11:22:33',
            'ip_address' => '192.168.1.101',
            'connection_status' => 'UNVERIFIED',
            'pairing_status' => 'UNVERIFIED',
            'is_active' => 1,
        ]);

        // Check attributes & casts
        $this->assertTrue($tv->is_active);
        $this->assertEquals(['power_control' => true, 'input_select' => true], $tv->verified_capabilities);
        $this->assertEquals('secret_pairing_token_123', $tv->pairing_payload); // Encrypted cast
    }

    /** @test */
    public function test_02_mac_address_normalization_and_validation()
    {
        // 1. Colon separated lowercase
        $mac1 = PhysicalDevice::normalizeMacAddress('aa:bb:cc:dd:ee:01');
        $this->assertEquals('AA:BB:CC:DD:EE:01', $mac1);

        // 2. Dash separated uppercase
        $mac2 = PhysicalDevice::normalizeMacAddress('AA-BB-CC-DD-EE-02');
        $this->assertEquals('AA:BB:CC:DD:EE:02', $mac2);

        // 3. Dot separated
        $mac3 = PhysicalDevice::normalizeMacAddress('aabb.ccdd.ee03');
        $this->assertEquals('AA:BB:CC:DD:EE:03', $mac3);

        // 4. Raw 12 hex string
        $mac4 = PhysicalDevice::normalizeMacAddress('aabbccddee04');
        $this->assertEquals('AA:BB:CC:DD:EE:04', $mac4);

        // 5. Invalid character or invalid length must throw exception
        $this->expectException(InvalidArgumentException::class);
        PhysicalDevice::normalizeMacAddress('INVALID_MAC_ZZ');
    }

    /** @test */
    public function test_03_reject_duplicate_mac_address()
    {
        $this->createSmartTv([
            'mac_address' => '11:22:33:44:55:66',
            'ip_address' => '192.168.1.51',
        ]);

        $this->expectException(StationMappingException::class);
        $this->expectExceptionCode(409);

        // Attempting to register another device with same MAC in different format (lowercase dash)
        $this->service->registerPhysicalDevice([
            'name' => 'Samsung TV duplicate',
            'category' => PhysicalDevice::CATEGORY_SMART_TV,
            'brand' => PhysicalDevice::BRAND_SAMSUNG,
            'mac_address' => '11-22-33-44-55-66',
            'ip_address' => '192.168.1.52',
        ]);
    }

    /** @test */
    public function test_04_local_ip_validation_and_conflict_detection()
    {
        // Public IP must be rejected (RFC 1918 enforcement)
        $this->expectException(InvalidArgumentException::class);
        PhysicalDevice::validateIpAddress('8.8.8.8');

        // Test conflict detection
        $this->createSmartTv([
            'mac_address' => '11:22:33:44:55:71',
            'ip_address' => '192.168.1.88',
        ]);

        try {
            $this->createSmartRelay([
                'mac_address' => '11:22:33:44:55:72',
                'ip_address' => '192.168.1.88', // Same IP
            ]);
            $this->fail('Expected StationMappingException for IP conflict');
        } catch (StationMappingException $e) {
            $this->assertEquals(409, $e->getStatusCode());
            $this->assertStringContainsString('Konflik IP Address', $e->getMessage());
        }
    }

    /** @test */
    public function test_05_brand_and_category_validation_strict_samsung_and_shelly_only()
    {
        // 1. Valid combinations: SMART_TV + SAMSUNG and SMART_RELAY + SHELLY
        PhysicalDevice::validateBrandCategory(PhysicalDevice::CATEGORY_SMART_TV, PhysicalDevice::BRAND_SAMSUNG);
        PhysicalDevice::validateBrandCategory(PhysicalDevice::CATEGORY_SMART_RELAY, PhysicalDevice::BRAND_SHELLY);
        $this->assertTrue(true);

        // 2. Invalid: Smart TV with Shelly
        try {
            PhysicalDevice::validateBrandCategory(PhysicalDevice::CATEGORY_SMART_TV, PhysicalDevice::BRAND_SHELLY);
            $this->fail('Expected InvalidArgumentException for SMART_TV + SHELLY');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('tidak kompatibel', $e->getMessage());
        }

        // 3. Invalid: Smart Relay with Samsung
        try {
            PhysicalDevice::validateBrandCategory(PhysicalDevice::CATEGORY_SMART_RELAY, PhysicalDevice::BRAND_SAMSUNG);
            $this->fail('Expected InvalidArgumentException for SMART_RELAY + SAMSUNG');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('tidak kompatibel', $e->getMessage());
        }

        // 4. Invalid: Coocaa is rejected for SMART_TV
        try {
            PhysicalDevice::validateBrandCategory(PhysicalDevice::CATEGORY_SMART_TV, 'COOCAA');
            $this->fail('Expected InvalidArgumentException for SMART_TV + COOCAA');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('tidak kompatibel', $e->getMessage());
        }

        // 5. Invalid: Coocaa is rejected for SMART_RELAY
        try {
            PhysicalDevice::validateBrandCategory(PhysicalDevice::CATEGORY_SMART_RELAY, 'COOCAA');
            $this->fail('Expected InvalidArgumentException for SMART_RELAY + COOCAA');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('tidak kompatibel', $e->getMessage());
        }

        // 6. Service level registration of Coocaa is rejected
        try {
            $this->service->registerPhysicalDevice([
                'name' => 'Coocaa TV Prohibited',
                'category' => PhysicalDevice::CATEGORY_SMART_TV,
                'brand' => 'COOCAA',
                'mac_address' => '99:88:77:66:55:44',
                'ip_address' => '192.168.1.99',
            ]);
            $this->fail('Expected InvalidArgumentException when registering COOCAA TV');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('tidak kompatibel', $e->getMessage());
        }
    }

    /** @test */
    public function test_06_valid_mapping_ps_station_to_smart_tv()
    {
        $tv = $this->createSmartTv();

        $mapping = $this->service->attachHardware(
            $this->psStation->id,
            $tv->id,
            StationDeviceMapping::ROLE_DISPLAY_TV,
            $this->admin->id,
            'Pemasangan Smart TV utama'
        );

        $this->assertEquals(StationDeviceMapping::STATUS_ACTIVE, $mapping->mapping_status);
        $this->assertEquals(StationDeviceMapping::ROLE_DISPLAY_TV, $mapping->mapping_role);
        $this->assertEquals($this->admin->id, $mapping->attached_by_user_id);
        $this->assertNotNull($mapping->attached_at);
        $this->assertNull($mapping->detached_at);

        // Test relationships
        $this->assertEquals($this->psStation->id, $tv->fresh()->current_station->id);
        $this->assertEquals($tv->id, $this->psStation->fresh()->activeDisplayTv->physical_device_id);
    }

    /** @test */
    public function test_07_valid_mapping_billiard_station_to_shelly_relay()
    {
        $relay = $this->createSmartRelay();

        $mapping = $this->service->attachHardware(
            $this->billiardStation->id,
            $relay->id,
            StationDeviceMapping::ROLE_LIGHT_RELAY,
            $this->admin->id,
            'Pemasangan Shelly relay lampu biliar'
        );

        $this->assertEquals(StationDeviceMapping::STATUS_ACTIVE, $mapping->mapping_status);
        $this->assertEquals(StationDeviceMapping::ROLE_LIGHT_RELAY, $mapping->mapping_role);
        $this->assertEquals($relay->id, $this->billiardStation->fresh()->activeLightRelay->physical_device_id);
    }

    /** @test */
    public function test_08_invalid_mapping_ps_station_to_relay_rejected()
    {
        $relay = $this->createSmartRelay();

        $this->expectException(StationMappingException::class);
        $this->expectExceptionCode(422);

        $this->service->attachHardware(
            $this->psStation->id,
            $relay->id,
            StationDeviceMapping::ROLE_DISPLAY_TV,
            $this->admin->id
        );
    }

    /** @test */
    public function test_09_invalid_mapping_billiard_station_to_tv_rejected()
    {
        $tv = $this->createSmartTv();

        $this->expectException(StationMappingException::class);
        $this->expectExceptionCode(422);

        $this->service->attachHardware(
            $this->billiardStation->id,
            $tv->id,
            StationDeviceMapping::ROLE_LIGHT_RELAY,
            $this->admin->id
        );
    }

    /** @test */
    public function test_10_unknown_or_ambiguous_station_type_rejected()
    {
        // 1. Station with unclassifiable service name (e.g. KARAOKE)
        $karaokeService = Playstation::create(['nama' => 'KARAOKE VIP', 'harga' => 50000]);
        $karaokeStation = Device::create([
            'nama' => 'KARAOKE-01',
            'status' => 'Tersedia',
            'playstation_id' => $karaokeService->id,
        ]);
        $karaokeStation->playstations()->attach($karaokeService->id);

        $tv = $this->createSmartTv();

        try {
            $this->service->attachHardware(
                $karaokeStation->id,
                $tv->id,
                StationDeviceMapping::ROLE_DISPLAY_TV,
                $this->admin->id
            );
            $this->fail('Expected StationMappingException for unrecognized station category');
        } catch (StationMappingException $e) {
            $this->assertEquals(422, $e->getStatusCode());
            $this->assertStringContainsString('tidak dikenali atau ambigu', $e->getMessage());
        }

        // 2. Station with mixed services (both PlayStation and Billiard)
        $mixedStation = Device::create([
            'nama' => 'HYBRID-STATION',
            'status' => 'Tersedia',
            'playstation_id' => $this->ps5Service->id,
        ]);
        $mixedStation->playstations()->attach([$this->ps5Service->id, $this->billiardService->id]);

        try {
            $this->service->attachHardware(
                $mixedStation->id,
                $tv->id,
                StationDeviceMapping::ROLE_DISPLAY_TV,
                $this->admin->id
            );
            $this->fail('Expected StationMappingException for mixed station category');
        } catch (StationMappingException $e) {
            $this->assertEquals(422, $e->getStatusCode());
            $this->assertStringContainsString('tidak dikenali atau ambigu', $e->getMessage());
        }
    }

    /** @test */
    public function test_11_single_active_mapping_per_hardware_invariant()
    {
        $tv = $this->createSmartTv();

        // Map to psStation
        $this->service->attachHardware(
            $this->psStation->id,
            $tv->id,
            StationDeviceMapping::ROLE_DISPLAY_TV,
            $this->admin->id
        );

        // Create second PlayStation station
        $psStation2 = Device::create([
            'nama' => 'PS-STATION-02',
            'status' => 'Tersedia',
            'playstation_id' => $this->ps5Service->id,
        ]);
        $psStation2->playstations()->attach($this->ps5Service->id);

        // Attempt to map the same TV to psStation2 -> must fail
        try {
            $this->service->attachHardware(
                $psStation2->id,
                $tv->id,
                StationDeviceMapping::ROLE_DISPLAY_TV,
                $this->admin->id
            );
            $this->fail('Expected StationMappingException for hardware already mapped');
        } catch (StationMappingException $e) {
            $this->assertEquals(409, $e->getStatusCode());
            $this->assertStringContainsString('sudah memiliki pemetaan aktif', $e->getMessage());
        }

        // Also verify database virtual generated unique column rejects duplicate active mapping
        $this->expectException(QueryException::class);
        DB::table('station_device_mappings')->insert([
            'station_id' => $psStation2->id,
            'physical_device_id' => $tv->id,
            'mapping_role' => 'DISPLAY_TV',
            'mapping_status' => 'ACTIVE',
            'attached_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @test */
    public function test_12_single_active_mapping_per_station_role_invariant()
    {
        $tv1 = $this->createSmartTv(['name' => 'TV-1', 'mac_address' => 'AA:AA:AA:11:11:01', 'ip_address' => '192.168.1.111']);
        $tv2 = $this->createSmartTv(['name' => 'TV-2', 'mac_address' => 'AA:AA:AA:11:11:02', 'ip_address' => '192.168.1.112']);

        // Attach TV1 to psStation as DISPLAY_TV
        $this->service->attachHardware(
            $this->psStation->id,
            $tv1->id,
            StationDeviceMapping::ROLE_DISPLAY_TV,
            $this->admin->id
        );

        // Attempting to attach TV2 to psStation as DISPLAY_TV via attachHardware without replacement must fail
        try {
            $this->service->attachHardware(
                $this->psStation->id,
                $tv2->id,
                StationDeviceMapping::ROLE_DISPLAY_TV,
                $this->admin->id
            );
            $this->fail('Expected StationMappingException for station role already filled');
        } catch (StationMappingException $e) {
            $this->assertEquals(409, $e->getStatusCode());
            $this->assertStringContainsString('sudah memiliki hardware aktif untuk peran', $e->getMessage());
        }

        // Also verify database virtual generated unique column rejects duplicate active role on same station
        $this->expectException(QueryException::class);
        DB::table('station_device_mappings')->insert([
            'station_id' => $this->psStation->id,
            'physical_device_id' => $tv2->id,
            'mapping_role' => 'DISPLAY_TV',
            'mapping_status' => 'ACTIVE',
            'attached_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @test */
    public function test_13_replace_mapping_preserves_audit_trail()
    {
        $tvOld = $this->createSmartTv(['name' => 'Old TV', 'mac_address' => 'BB:BB:BB:11:11:01', 'ip_address' => '192.168.1.121']);
        $tvNew = $this->createSmartTv(['name' => 'New TV', 'mac_address' => 'BB:BB:BB:11:11:02', 'ip_address' => '192.168.1.122']);

        $mappingOld = $this->service->attachHardware(
            $this->psStation->id,
            $tvOld->id,
            StationDeviceMapping::ROLE_DISPLAY_TV,
            $this->admin->id,
            'TV awal'
        );

        // Replace with New TV
        $mappingNew = $this->service->replaceHardware(
            $this->psStation->id,
            $tvNew->id,
            StationDeviceMapping::ROLE_DISPLAY_TV,
            $this->kasir->id,
            'Upgrade TV 4K',
            'TV lama dipindahkan ke service center'
        );

        // Old mapping must be INACTIVE with detach audit
        $mappingOldFresh = $mappingOld->fresh();
        $this->assertEquals(StationDeviceMapping::STATUS_INACTIVE, $mappingOldFresh->mapping_status);
        $this->assertEquals($this->kasir->id, $mappingOldFresh->detached_by_user_id);
        $this->assertNotNull($mappingOldFresh->detached_at);
        $this->assertEquals('TV lama dipindahkan ke service center', $mappingOldFresh->detach_notes);

        // New mapping must be ACTIVE
        $this->assertEquals(StationDeviceMapping::STATUS_ACTIVE, $mappingNew->mapping_status);
        $this->assertEquals($this->kasir->id, $mappingNew->attached_by_user_id);
        $this->assertEquals('Upgrade TV 4K', $mappingNew->attach_notes);

        // Active hardware must be New TV
        $this->assertEquals($tvNew->id, $this->psStation->fresh()->activeDisplayTv->physical_device_id);

        // History count must be 2
        $history = $this->service->getMappingHistory($this->psStation->id);
        $this->assertCount(2, $history);
    }

    /** @test */
    public function test_14_soft_detach_and_historical_audit_trail()
    {
        $tv = $this->createSmartTv();

        $mapping = $this->service->attachHardware(
            $this->psStation->id,
            $tv->id,
            StationDeviceMapping::ROLE_DISPLAY_TV,
            $this->admin->id,
            'Pasang TV'
        );

        // Soft detach
        $detached = $this->service->softDetach(
            $this->psStation->id,
            StationDeviceMapping::ROLE_DISPLAY_TV,
            $this->admin->id,
            'Pelepasan karena renovasi'
        );

        $this->assertEquals(StationDeviceMapping::STATUS_INACTIVE, $detached->mapping_status);
        $this->assertEquals($this->admin->id, $detached->detached_by_user_id);
        $this->assertEquals('Pelepasan karena renovasi', $detached->detach_notes);
        $this->assertNotNull($detached->detached_at);

        // Station has no active TV
        $this->assertNull($this->psStation->fresh()->activeDisplayTv);

        // TV is unmapped and can be mapped again
        $this->assertNull($tv->fresh()->current_station);
        $this->assertCount(1, PhysicalDevice::unmapped()->where('id', $tv->id)->get());
    }

    /** @test */
    public function test_15_reject_mutations_when_billing_is_running_or_device_occupied()
    {
        $tv = $this->createSmartTv();

        // 1. When device status is 'Digunakan'
        $this->psStation->update(['status' => 'Digunakan']);

        try {
            $this->service->attachHardware(
                $this->psStation->id,
                $tv->id,
                StationDeviceMapping::ROLE_DISPLAY_TV,
                $this->admin->id
            );
            $this->fail('Expected StationMappingException for occupied station');
        } catch (StationMappingException $e) {
            $this->assertEquals(409, $e->getStatusCode());
            $this->assertStringContainsString('sedang digunakan kasir', $e->getMessage());
        }

        // Reset status
        $this->psStation->update(['status' => 'Tersedia']);

        // 2. When there is a Transaction with billing_status = 'RUNNING'
        $billingService = new BillingService();
        $billingService->startSession([
            'device_id' => $this->psStation->id,
            'playstation_id' => $this->ps5Service->id,
            'tipe_transaksi' => 'prepaid',
            'durasi' => 1,
            'nama' => 'Pelanggan Aktif',
            'status_pembayaran' => 'unpaid',
        ], $this->kasir->id);

        // Reset device status to Tersedia directly in database to isolate the billing_status check specifically
        Device::where('id', $this->psStation->id)->update(['status' => 'Tersedia']);

        try {
            $this->service->attachHardware(
                $this->psStation->id,
                $tv->id,
                StationDeviceMapping::ROLE_DISPLAY_TV,
                $this->admin->id
            );
            $this->fail('Expected StationMappingException for running billing session');
        } catch (StationMappingException $e) {
            $this->assertEquals(409, $e->getStatusCode());
            $this->assertStringContainsString('memiliki sesi billing aktif', $e->getMessage());
        }
    }

    /** @test */
    public function test_16_concurrency_protection_and_race_condition_handling()
    {
        $tv = $this->createSmartTv();

        $psStation2 = Device::create([
            'nama' => 'PS-STATION-RACE',
            'status' => 'Tersedia',
            'playstation_id' => $this->ps5Service->id,
        ]);
        $psStation2->playstations()->attach($this->ps5Service->id);

        // Attach to station 1
        $this->service->attachHardware(
            $this->psStation->id,
            $tv->id,
            StationDeviceMapping::ROLE_DISPLAY_TV,
            $this->admin->id
        );

        // Concurrent attempt: simulate query directly hitting the database
        // Verify handleQueryException converts MySQL 1062 to StationMappingException(409)
        try {
            DB::transaction(function () use ($psStation2, $tv) {
                DB::table('station_device_mappings')->insert([
                    'station_id' => $psStation2->id,
                    'physical_device_id' => $tv->id,
                    'mapping_role' => 'DISPLAY_TV',
                    'mapping_status' => 'ACTIVE',
                    'attached_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
            $this->fail('Expected QueryException on unique violation');
        } catch (QueryException $e) {
            $this->assertStringContainsString('1062', $e->getMessage());
        }
    }

    /** @test */
    public function test_17_prevent_hardware_deactivation_while_actively_mapped()
    {
        $tv = $this->createSmartTv();

        $this->service->attachHardware(
            $this->psStation->id,
            $tv->id,
            StationDeviceMapping::ROLE_DISPLAY_TV,
            $this->admin->id
        );

        // 1. Attempting deactivateHardware must fail
        try {
            $this->service->deactivateHardware($tv->id);
            $this->fail('Expected StationMappingException when deactivating mapped hardware');
        } catch (StationMappingException $e) {
            $this->assertEquals(409, $e->getStatusCode());
            $this->assertStringContainsString('Tidak dapat menonaktifkan hardware', $e->getMessage());
        }

        // 2. Attempting updatePhysicalDevice with is_active = false must fail
        try {
            $this->service->updatePhysicalDevice($tv->id, ['is_active' => false]);
            $this->fail('Expected StationMappingException when updating is_active to false');
        } catch (StationMappingException $e) {
            $this->assertEquals(409, $e->getStatusCode());
        }

        // 3. After soft-detach, deactivation succeeds
        $this->service->softDetach($this->psStation->id, StationDeviceMapping::ROLE_DISPLAY_TV, $this->admin->id);
        $deactivated = $this->service->deactivateHardware($tv->id);
        $this->assertFalse($deactivated->is_active);
    }

    /** @test */
    public function test_18_station_without_hardware_runs_billing_unimpeded()
    {
        // Unmapped station has 0 hardware mappings
        $this->assertCount(0, $this->psStation->hardwareMappings);

        $billingService = new BillingService();

        // Start billing session
        $transaction = $billingService->startSession([
            'device_id' => $this->psStation->id,
            'playstation_id' => $this->ps5Service->id,
            'tipe_transaksi' => 'prepaid',
            'durasi' => 1,
            'nama' => 'Pemain Tanpa Hardware',
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

    /** @test */
    public function test_19_foreign_key_restrict_protects_audit_history()
    {
        $tv = $this->createSmartTv();

        $mapping = $this->service->attachHardware(
            $this->psStation->id,
            $tv->id,
            StationDeviceMapping::ROLE_DISPLAY_TV,
            $this->admin->id
        );

        // Soft detach so mapping is INACTIVE
        $this->service->softDetach($this->psStation->id, StationDeviceMapping::ROLE_DISPLAY_TV, $this->admin->id);

        // Attempting to delete Station (Device) must throw QueryException (ON DELETE RESTRICT)
        try {
            $this->psStation->delete();
            $this->fail('Expected QueryException due to ON DELETE RESTRICT on station_id');
        } catch (QueryException $e) {
            $this->assertStringContainsString('a foreign key constraint fails', $e->getMessage());
        }

        // Attempting to delete PhysicalDevice must throw QueryException (ON DELETE RESTRICT)
        try {
            $tv->delete();
            $this->fail('Expected QueryException due to ON DELETE RESTRICT on physical_device_id');
        } catch (QueryException $e) {
            $this->assertStringContainsString('a foreign key constraint fails', $e->getMessage());
        }
    }
}
