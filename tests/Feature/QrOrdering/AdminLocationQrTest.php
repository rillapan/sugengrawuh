<?php

namespace Tests\Feature\QrOrdering;

use App\Models\Device;
use App\Models\Location;
use App\Models\Playstation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLocationQrTest extends TestCase
{
    use RefreshDatabase;

    protected $admin;
    protected $kasir;
    protected $owner;
    protected $device;
    protected $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'email' => 'admin@sugengrawuh.test',
        ]);

        $this->kasir = User::factory()->create([
            'role' => 'kasir',
            'email' => 'kasir@sugengrawuh.test',
        ]);

        $this->owner = User::factory()->create([
            'role' => 'owner',
            'email' => 'owner@sugengrawuh.test',
        ]);

        $playstation = Playstation::create([
            'nama' => 'PS5 Station',
            'harga' => 15000,
        ]);

        $this->device = Device::create([
            'nama' => 'PS-VIP-01',
            'playstation_id' => $playstation->id,
            'status' => 'Tersedia',
        ]);

        $this->location = Location::create([
            'name' => 'VIP Room 1',
            'code' => 'ROOM-VIP-01',
            'type' => 'station',
            'device_id' => $this->device->id,
            'is_active' => true,
        ]);
    }

    /** @test */
    public function test_admin_can_view_location_list_with_qr_code()
    {
        $response = $this->actingAs($this->admin)->get(route('admin.locations.index'));

        $response->assertStatus(200);
        $response->assertViewIs('admin.locations.index');
        $response->assertSee('VIP Room 1');
        $response->assertSee('ROOM-VIP-01');
        // Offline SVG preview generated
        $response->assertSee('<svg', false);
    }

    /** @test */
    public function test_admin_can_create_new_location()
    {
        $payload = [
            'name' => 'Meja Kafe 01',
            'code' => 'TABLE-01',
            'type' => 'dining_table',
            'is_active' => 1,
        ];

        $response = $this->actingAs($this->admin)->post(route('admin.locations.store'), $payload);

        $response->assertRedirect(route('admin.locations.index'));
        $this->assertDatabaseHas('locations', [
            'name' => 'Meja Kafe 01',
            'code' => 'TABLE-01',
            'type' => 'dining_table',
            'is_active' => 1,
        ]);
    }

    /** @test */
    public function test_admin_can_update_existing_location()
    {
        $payload = [
            'name' => 'VIP Room 1 Renamed',
            'code' => 'ROOM-VIP-01',
            'type' => 'station',
            'device_id' => $this->device->id,
            'is_active' => 0,
        ];

        $response = $this->actingAs($this->admin)->put(route('admin.locations.update', $this->location->id), $payload);

        $response->assertRedirect(route('admin.locations.index'));
        $this->assertDatabaseHas('locations', [
            'id' => $this->location->id,
            'name' => 'VIP Room 1 Renamed',
            'is_active' => 0,
        ]);
    }

    /** @test */
    public function test_admin_can_view_printable_qr_card()
    {
        $response = $this->actingAs($this->admin)->get(route('admin.locations.print', $this->location->id));

        $response->assertStatus(200);
        $response->assertViewIs('admin.locations.print_qr');
        $response->assertSee('VIP Room 1');
        $response->assertSee('ROOM-VIP-01');
        $response->assertSee('<svg', false);
    }

    /** @test */
    public function test_kasir_and_owner_cannot_access_admin_location_routes()
    {
        // Kasir
        $this->actingAs($this->kasir)->get(route('admin.locations.index'))->assertStatus(403);
        $this->actingAs($this->kasir)->post(route('admin.locations.store'), ['name' => 'Test'])->assertStatus(403);
        $this->actingAs($this->kasir)->put(route('admin.locations.update', $this->location->id), ['name' => 'Test'])->assertStatus(403);
        $this->actingAs($this->kasir)->get(route('admin.locations.print', $this->location->id))->assertStatus(403);

        // Owner
        $this->actingAs($this->owner)->get(route('admin.locations.index'))->assertStatus(403);
        $this->actingAs($this->owner)->post(route('admin.locations.store'), ['name' => 'Test'])->assertStatus(403);
        $this->actingAs($this->owner)->put(route('admin.locations.update', $this->location->id), ['name' => 'Test'])->assertStatus(403);
        $this->actingAs($this->owner)->get(route('admin.locations.print', $this->location->id))->assertStatus(403);
    }
}
