<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Playstation;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class DeviceStatusManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $kasir;
    protected Playstation $playstation;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('hardware.driver', 'mock');
        Config::set('hardware.real_execution_enabled', false);
        Config::set('hardware.simulation_enabled', true);

        $this->admin = User::factory()->create([
            'name' => 'Admin Station Test',
            'role' => 'admin',
            'email' => 'admin_station@sugengrawuh.test',
        ]);

        $this->kasir = User::factory()->create([
            'name' => 'Kasir Station Test',
            'role' => 'kasir',
            'email' => 'kasir_station@sugengrawuh.test',
        ]);

        $this->playstation = Playstation::create([
            'nama' => 'PS5 Station Service',
            'harga' => 20000,
        ]);
    }

    /**
     * Test: Device index loads successfully and passes resolved station numbers.
     */
    public function test_device_index_loads_with_global_station_numbering(): void
    {
        $device1 = Device::create([
            'nama' => 'Rental PS 01',
            'status' => 'Digunakan',
            'playstation_id' => $this->playstation->id,
        ]);
        $device1->playstations()->attach($this->playstation->id);

        Transaction::create([
            'nama' => 'Player 1',
            'device_id' => $device1->id,
            'status' => 'user',
            'harga' => 20000,
            'jam_main' => 1,
            'waktu_mulai' => now()->format('H:i:s'),
            'tipe_transaksi' => 'postpaid',
            'status_transaksi' => 'berjalan',
            'billing_status' => Transaction::BILLING_RUNNING,
            'total' => 20000,
        ]);

        $device2 = Device::create([
            'nama' => 'Rental PS 02',
            'status' => 'Tersedia',
            'playstation_id' => $this->playstation->id,
        ]);
        $device2->playstations()->attach($this->playstation->id);

        $response = $this->actingAs($this->admin)->get(route('device.index'));

        $response->assertStatus(200);
        $response->assertViewHas('stationNumbers');
        $response->assertViewHas('devices');

        $stationNumbers = $response->viewData('stationNumbers');
        $this->assertIsArray($stationNumbers);
        $this->assertArrayHasKey($device1->id, $stationNumbers);
        $this->assertArrayHasKey($device2->id, $stationNumbers);

        $devices = $response->viewData('devices');
        foreach ($devices as $dev) {
            $this->assertNotNull($dev->station_number);
            $this->assertSame($stationNumbers[$dev->id], $dev->station_number);
        }

        // Verify HTML card headers display Station {number}
        $response->assertSee('Station ' . $stationNumbers[$device1->id]);
        $response->assertSee('Station ' . $stationNumbers[$device2->id]);
        $response->assertSee($device1->nama);
        $response->assertSee($device2->nama);

        // Verify TV control summary displays consistent station number for admin when in use
        $response->assertSee('Station ' . $stationNumbers[$device1->id] . ' · ' . $device1->nama);
    }

    /**
     * Test: Kasir can view device index with consistent station numbering in card headers.
     */
    public function test_kasir_can_view_device_index_with_station_numbers(): void
    {
        $device = Device::create([
            'nama' => 'VIP PS 01',
            'status' => 'Tersedia',
            'playstation_id' => $this->playstation->id,
        ]);
        $device->playstations()->attach($this->playstation->id);

        $response = $this->actingAs($this->kasir)->get(route('device.index'));

        $response->assertStatus(200);
        $stationNumbers = $response->viewData('stationNumbers');
        $this->assertSame(1, $stationNumbers[$device->id]);
        $response->assertSee('Station 1');
        $response->assertSee('VIP PS 01');
    }

    /**
     * Test: Global station numbers are assigned sequentially based on deterministic order.
     */
    public function test_deterministic_station_numbers_order(): void
    {
        $d3 = Device::create(['nama' => 'Station C', 'status' => 'Tersedia', 'playstation_id' => $this->playstation->id]);
        $d1 = Device::create(['nama' => 'Station A', 'status' => 'Tersedia', 'playstation_id' => $this->playstation->id]);
        $d2 = Device::create(['nama' => 'Station B', 'status' => 'Tersedia', 'playstation_id' => $this->playstation->id]);

        $all = Device::with(['playstations', 'playstation'])->get();
        $sorted = Device::sortStationsDeterministically($all);
        $mapping = Device::resolveGlobalStationNumbers($sorted);

        $this->assertCount(3, $mapping);
        // All values must be sequential 1, 2, 3
        $this->assertEqualsCanonicalizing([1, 2, 3], array_values($mapping));
    }
}
