<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\PhysicalDevice;
use App\Models\Playstation;
use App\Models\StationDeviceMapping;
use App\Models\User;
use App\Services\Hardware\Simulation\HardwareSimulationManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class HardwareSimulatorApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected PhysicalDevice $samsungTv;
    protected PhysicalDevice $shellyRelay;
    protected Device $psStation;
    protected Device $billiardStation;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('hardware.driver', 'mock');
        Config::set('hardware.simulation_enabled', true);
        Config::set('hardware.allowed_environments', ['local', 'testing']);
        Config::set('hardware.allowed_databases', ['kasir_ps_test']);

        $this->admin = User::factory()->create([
            'name' => 'Admin Simulator',
            'role' => 'admin',
            'email' => 'admin_sim@sugengrawuh.test',
        ]);

        $this->samsungTv = PhysicalDevice::create([
            'name' => 'Samsung TV 55 Simulator',
            'category' => PhysicalDevice::CATEGORY_SMART_TV,
            'brand' => PhysicalDevice::BRAND_SAMSUNG,
            'model' => 'QA55Q60B',
            'mac_address' => '00:11:22:33:44:01',
            'ip_address' => '192.168.1.101',
            'connection_status' => PhysicalDevice::CONNECTION_UNVERIFIED,
            'pairing_status' => PhysicalDevice::PAIRING_UNVERIFIED,
            'is_active' => true,
        ]);

        $this->shellyRelay = PhysicalDevice::create([
            'name' => 'Shelly Relay Simulator',
            'category' => PhysicalDevice::CATEGORY_SMART_RELAY,
            'brand' => PhysicalDevice::BRAND_SHELLY,
            'model' => 'Gen3 Mini',
            'mac_address' => 'AA:BB:CC:DD:EE:02',
            'ip_address' => '192.168.1.102',
            'connection_status' => PhysicalDevice::CONNECTION_UNVERIFIED,
            'pairing_status' => PhysicalDevice::PAIRING_UNVERIFIED,
            'is_active' => true,
        ]);

        $psService = Playstation::create(['nama' => 'PS5 VIP', 'harga' => 20000]);
        $this->psStation = Device::create(['nama' => 'PS5-VIP-01', 'status' => 'Tersedia', 'playstation_id' => $psService->id]);
        $this->psStation->playstations()->attach($psService->id);

        // Map Samsung TV to PS Station
        StationDeviceMapping::create([
            'station_id' => $this->psStation->id,
            'physical_device_id' => $this->samsungTv->id,
            'mapping_role' => StationDeviceMapping::ROLE_DISPLAY_TV,
            'mapping_status' => StationDeviceMapping::STATUS_ACTIVE,
            'attached_by_user_id' => $this->admin->id,
            'attached_at' => now(),
            'attach_notes' => 'Setup awal simulator',
        ]);
    }

    /** @test */
    public function admin_can_list_virtual_devices_with_mapping_and_simulation_info()
    {
        $this->actingAs($this->admin);

        $response = $this->getJson('/admin/hardware/simulator/devices');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $data = $response->json('data');
        $this->assertCount(2, $data);

        // Find Samsung TV
        $tvItem = collect($data)->firstWhere('id', $this->samsungTv->id);
        $this->assertNotNull($tvItem);
        $this->assertEquals('SMART_TV', $tvItem['category']);
        $this->assertEquals('SAMSUNG', $tvItem['brand']);
        $this->assertEquals('PS5-VIP-01', $tvItem['station_mapping']['station_name']);
        $this->assertEquals('DISPLAY_TV', $tvItem['station_mapping']['mapping_role']);
        $this->assertContains('POWER_ON', $tvItem['supported_commands']);
    }

    /** @test */
    public function admin_can_set_deterministic_simulation_scenario()
    {
        $this->actingAs($this->admin);

        $response = $this->postJson('/admin/hardware/simulator/scenario', [
            'device_id' => $this->samsungTv->id,
            'scenario' => HardwareSimulationManager::SCENARIO_OFFLINE,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'device_id' => $this->samsungTv->id,
                    'scenario' => 'OFFLINE',
                ],
            ]);
    }

    /** @test */
    public function admin_can_send_power_on_to_samsung_tv_and_updates_simulated_state()
    {
        $this->actingAs($this->admin);

        $response = $this->postJson('/admin/hardware/simulator/command', [
            'device_id' => $this->samsungTv->id,
            'command' => 'POWER_ON',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'device_id' => $this->samsungTv->id,
                    'command' => 'POWER_ON',
                    'result_code' => 'SUCCESS',
                    'simulated_state' => [
                        'power' => 'ON',
                    ],
                ],
            ]);
    }

    /** @test */
    public function admin_can_send_switch_on_to_shelly_relay()
    {
        $this->actingAs($this->admin);

        $response = $this->postJson('/admin/hardware/simulator/command', [
            'device_id' => $this->shellyRelay->id,
            'command' => 'SWITCH_ON',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'device_id' => $this->shellyRelay->id,
                    'command' => 'SWITCH_ON',
                    'result_code' => 'SUCCESS',
                    'simulated_state' => [
                        'switch' => 'ON',
                        'output' => true,
                    ],
                ],
            ]);
    }

    /** @test */
    public function admin_can_get_status_and_events_for_device()
    {
        $this->actingAs($this->admin);

        $this->postJson('/admin/hardware/simulator/command', [
            'device_id' => $this->samsungTv->id,
            'command' => 'POWER_ON',
        ]);

        $resStatus = $this->getJson("/admin/hardware/simulator/status/{$this->samsungTv->id}");
        $resStatus->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'simulated_state' => [
                        'power' => 'ON',
                    ],
                ],
            ]);

        $resEvents = $this->getJson("/admin/hardware/simulator/events/{$this->samsungTv->id}");
        $resEvents->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);
        $this->assertNotEmpty($resEvents->json('data'));
        $this->assertEquals('GET_STATUS', $resEvents->json('data.0.command'));
        $this->assertEquals('POWER_ON', $resEvents->json('data.1.command'));
    }

    /** @test */
    public function offline_scenario_returns_proper_simulation_failure_code()
    {
        $this->actingAs($this->admin);

        $this->postJson('/admin/hardware/simulator/scenario', [
            'device_id' => $this->samsungTv->id,
            'scenario' => 'OFFLINE',
        ]);

        $response = $this->postJson('/admin/hardware/simulator/command', [
            'device_id' => $this->samsungTv->id,
            'command' => 'POWER_ON',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'data' => [
                    'result_code' => 'OFFLINE',
                ],
            ]);
    }

    /** @test */
    public function timeout_scenario_returns_timeout_result_code()
    {
        $this->actingAs($this->admin);

        $this->postJson('/admin/hardware/simulator/scenario', [
            'device_id' => $this->shellyRelay->id,
            'scenario' => 'TIMEOUT',
        ]);

        $response = $this->postJson('/admin/hardware/simulator/command', [
            'device_id' => $this->shellyRelay->id,
            'command' => 'SWITCH_ON',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'data' => [
                    'result_code' => 'TIMEOUT',
                ],
            ]);
    }

    /** @test */
    public function rejected_scenario_returns_command_rejected_code()
    {
        $this->actingAs($this->admin);

        $this->postJson('/admin/hardware/simulator/scenario', [
            'device_id' => $this->samsungTv->id,
            'scenario' => 'REJECTED',
        ]);

        $response = $this->postJson('/admin/hardware/simulator/command', [
            'device_id' => $this->samsungTv->id,
            'command' => 'POWER_ON',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'data' => [
                    'result_code' => 'COMMAND_REJECTED',
                ],
            ]);
    }

    /** @test */
    public function invalid_command_returns_422_with_invalid_command_code()
    {
        $this->actingAs($this->admin);

        $response = $this->postJson('/admin/hardware/simulator/command', [
            'device_id' => $this->samsungTv->id,
            'command' => 'SELF_DESTRUCT',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'data' => [
                    'result_code' => 'INVALID_COMMAND',
                ],
            ]);
    }

    /** @test */
    public function admin_can_reset_single_device_or_all_devices()
    {
        $this->actingAs($this->admin);

        // Reset single
        $resSingle = $this->postJson('/admin/hardware/simulator/reset', [
            'device_id' => $this->samsungTv->id,
        ]);
        $resSingle->assertStatus(200)->assertJson(['success' => true]);

        // Reset all
        $resAll = $this->postJson('/admin/hardware/simulator/reset', []);
        $resAll->assertStatus(200)->assertJson(['success' => true]);
    }

    /** @test */
    public function physical_devices_database_columns_are_never_mutated_by_simulation_api()
    {
        $this->actingAs($this->admin);

        $this->postJson('/admin/hardware/simulator/command', [
            'device_id' => $this->samsungTv->id,
            'command' => 'POWER_ON',
        ]);

        $fresh = $this->samsungTv->fresh();

        $this->assertEquals(PhysicalDevice::CONNECTION_UNVERIFIED, $fresh->connection_status);
        $this->assertEquals(PhysicalDevice::PAIRING_UNVERIFIED, $fresh->pairing_status);
        $this->assertNull($fresh->pairing_payload);
        $this->assertNull($fresh->last_seen_at);
    }
}
