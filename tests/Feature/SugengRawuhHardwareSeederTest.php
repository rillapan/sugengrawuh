<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\PhysicalDevice;
use App\Models\Playstation;
use App\Models\StationDeviceMapping;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\SugengRawuhHardwareSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SugengRawuhHardwareSeederTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'email' => 'admin_test_seeder@sugengrawuh.test',
        ]);
    }

    public function test_seeder_populates_exactly_20_devices_and_hardware_with_proper_categories(): void
    {
        $this->seed(SugengRawuhHardwareSeeder::class);

        // 1. Total master devices = 20
        $this->assertEquals(20, Device::count());

        // 2. Total active hardware registry in inventory = 20
        $this->assertEquals(20, PhysicalDevice::where('is_active', true)->count());

        // 3. Total active hardware mappings = 20
        $this->assertEquals(20, StationDeviceMapping::where('mapping_status', StationDeviceMapping::STATUS_ACTIVE)->count());

        // 4. Kategori Billiard: 4
        $billiardStations = Device::get()->filter(fn(Device $d) => $d->getStationCategory() === Playstation::CATEGORY_BILLIARD);
        $this->assertCount(4, $billiardStations);

        // 5. Kategori TV (Playstation): 16
        $tvStations = Device::get()->filter(fn(Device $d) => $d->getStationCategory() === Playstation::CATEGORY_PLAYSTATION);
        $this->assertCount(16, $tvStations);

        // 6. Verify all 20 IPs match master data
        $expectedIps = array_column(SugengRawuhHardwareSeeder::INVENTORY, 'ip_address');
        $actualIps = PhysicalDevice::where('is_active', true)->pluck('ip_address')->all();
        sort($expectedIps);
        sort($actualIps);
        $this->assertEquals($expectedIps, $actualIps);

        // 7. Verify all 20 MACs match master data
        $expectedMacs = array_map(
            fn($item) => PhysicalDevice::normalizeMacAddress($item['mac_address']),
            SugengRawuhHardwareSeeder::INVENTORY
        );
        $actualMacs = PhysicalDevice::where('is_active', true)->pluck('mac_address')->all();
        sort($expectedMacs);
        sort($actualMacs);
        $this->assertEquals($expectedMacs, $actualMacs);

        // 8. No duplicates
        $this->assertEquals(20, count(array_unique($actualIps)));
        $this->assertEquals(20, count(array_unique($actualMacs)));
        $activePhysicalIds = StationDeviceMapping::where('mapping_status', StationDeviceMapping::STATUS_ACTIVE)
            ->pluck('physical_device_id')
            ->all();
        $this->assertEquals(20, count(array_unique($activePhysicalIds)));
    }

    public function test_seeder_is_strictly_idempotent(): void
    {
        $this->seed(SugengRawuhHardwareSeeder::class);

        $deviceCount1 = Device::count();
        $hardwareCount1 = PhysicalDevice::count();
        $mappingCount1 = StationDeviceMapping::count();

        // Run seeder a second time
        $this->seed(SugengRawuhHardwareSeeder::class);

        $this->assertEquals($deviceCount1, Device::count(), "Seeder kedua kali tidak boleh menambah jumlah Device.");
        $this->assertEquals($hardwareCount1, PhysicalDevice::count(), "Seeder kedua kali tidak boleh menambah jumlah PhysicalDevice.");
        $this->assertEquals($mappingCount1, StationDeviceMapping::count(), "Seeder kedua kali tidak boleh menambah jumlah StationDeviceMapping.");
    }

    public function test_mapping_correctly_pairs_each_device_to_exact_ip_and_role(): void
    {
        $this->seed(SugengRawuhHardwareSeeder::class);

        foreach (SugengRawuhHardwareSeeder::INVENTORY as $item) {
            $station = Device::where('nama', $item['nama'])->first();
            $this->assertNotNull($station, "Station {$item['nama']} harus ada.");

            $activeMapping = $station->activeHardwareMappings()->first();
            $this->assertNotNull($activeMapping, "Station {$item['nama']} harus memiliki active mapping.");

            $physicalDevice = $activeMapping->physicalDevice;
            $this->assertNotNull($physicalDevice, "Mapping harus memiliki relasi ke PhysicalDevice.");

            $this->assertEquals($item['ip_address'], $physicalDevice->ip_address, "IP {$item['nama']} harus cocok.");
            $this->assertEquals(
                PhysicalDevice::normalizeMacAddress($item['mac_address']),
                $physicalDevice->mac_address,
                "MAC {$item['nama']} harus cocok."
            );

            if ($item['kategori'] === 'BILLIARD') {
                $this->assertEquals(StationDeviceMapping::ROLE_LIGHT_RELAY, $activeMapping->mapping_role);
                $this->assertEquals(PhysicalDevice::CATEGORY_SMART_RELAY, $physicalDevice->category);
                $this->assertEquals(PhysicalDevice::BRAND_SHELLY, $physicalDevice->brand);
            } else {
                $this->assertEquals(StationDeviceMapping::ROLE_DISPLAY_TV, $activeMapping->mapping_role);
                $this->assertEquals(PhysicalDevice::CATEGORY_SMART_TV, $physicalDevice->category);
                $this->assertEquals(PhysicalDevice::BRAND_SAMSUNG, $physicalDevice->brand);
            }
        }
    }

    public function test_preserves_existing_transactions_and_running_billing_status(): void
    {
        // 1. Create initial device with running transaction
        $billiardPagi = Playstation::create(['nama' => 'BILLIARD pagi', 'harga' => 25000]);
        $device = Device::create([
            'nama' => 'Meja 2 - Billiard',
            'playstation_id' => $billiardPagi->id,
            'status' => 'Digunakan',
        ]);
        $device->playstations()->attach($billiardPagi->id);

        $tx = Transaction::create([
            'device_id' => $device->id,
            'nama' => 'Customer Existing',
            'tipe_transaksi' => 'postpaid',
            'waktu_mulai' => '10:00:00',
            'billing_status' => Transaction::BILLING_RUNNING,
            'payment_status' => 'unpaid',
            'status' => 'user',
            'status_transaksi' => 'berjalan',
            'harga' => '25000',
            'total' => '25000',
        ]);

        // 2. Run seeder
        $this->seed(SugengRawuhHardwareSeeder::class);

        // 3. Verify running transaction was NOT modified
        $freshTx = Transaction::find($tx->id_transaksi);
        $this->assertNotNull($freshTx);
        $this->assertEquals(Transaction::BILLING_RUNNING, $freshTx->billing_status);
        $this->assertEquals('unpaid', $freshTx->payment_status);
        $this->assertEquals('berjalan', $freshTx->status_transaksi);

        // 4. Verify device status remained 'Digunakan'
        $updatedDevice = Device::find($device->id);
        $this->assertEquals('Billiard 2', $updatedDevice->nama);
        $this->assertEquals('Digunakan', $updatedDevice->status);

        // 5. Verify no rogue running transactions were started
        $this->assertEquals(1, Transaction::where('billing_status', Transaction::BILLING_RUNNING)->count());
    }

    public function test_archives_extraneous_hardware_safely(): void
    {
        // Create an obsolete test hardware not in inventory
        $oldHw = PhysicalDevice::create([
            'name' => 'Old Test TV',
            'category' => PhysicalDevice::CATEGORY_SMART_TV,
            'brand' => PhysicalDevice::BRAND_SAMSUNG,
            'mac_address' => 'AA:AA:AA:AA:AA:AA',
            'ip_address' => '192.168.200.240',
            'is_active' => true,
        ]);

        $this->seed(SugengRawuhHardwareSeeder::class);

        // Old hardware should be archived (is_active = false)
        $oldHw->refresh();
        $this->assertFalse($oldHw->is_active);

        // Active hardware count should still be exactly 20
        $this->assertEquals(20, PhysicalDevice::where('is_active', true)->count());
    }

    public function test_dashboards_render_properly_with_seeded_data(): void
    {
        $this->seed(SugengRawuhHardwareSeeder::class);

        // Test /device
        $responseDevice = $this->actingAs($this->admin)->get('/device');
        $responseDevice->assertStatus(200);
        $responseDevice->assertSee('Billiard 1');
        $responseDevice->assertSee('TV1');
        $responseDevice->assertSee('TV16');

        // Test /admin/hardware/registry (Page 1 has 15 items, Page 2 has 5 items due to paginate(15))
        $responseRegistry1 = $this->actingAs($this->admin)->get('/admin/hardware/registry');
        $responseRegistry1->assertStatus(200);
        $responseRegistry1->assertSee('192.168.200.216'); // TV16 (page 1)

        $responseRegistry2 = $this->actingAs($this->admin)->get('/admin/hardware/registry?page=2');
        $responseRegistry2->assertStatus(200);
        $responseRegistry2->assertSee('192.168.200.241'); // Billiard 1 (page 2)
        $responseRegistry2->assertSee('DC:B4:D9:DD:46:AC');

        // Test /admin/hardware/mappings
        $responseMappings = $this->actingAs($this->admin)->get('/admin/hardware/mappings');
        $responseMappings->assertStatus(200);
        $responseMappings->assertSee('Station Hardware Mapping');
    }
}
