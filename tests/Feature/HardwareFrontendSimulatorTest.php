<?php

namespace Tests\Feature;

use App\Models\PhysicalDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class HardwareFrontendSimulatorTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $owner;
    protected User $kasir;
    protected PhysicalDevice $device;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('hardware.driver', 'mock');
        Config::set('hardware.simulation_enabled', true);
        Config::set('hardware.allowed_environments', ['local', 'testing']);
        Config::set('hardware.allowed_databases', ['kasir_ps_test']);

        $this->admin = User::factory()->create([
            'name' => 'Admin UI Sim',
            'role' => 'admin',
            'email' => 'admin_ui_sim@sugengrawuh.test',
        ]);

        $this->owner = User::factory()->create([
            'name' => 'Owner UI Sim',
            'role' => 'owner',
            'email' => 'owner_ui_sim@sugengrawuh.test',
        ]);

        $this->kasir = User::factory()->create([
            'name' => 'Kasir UI Sim',
            'role' => 'kasir',
            'email' => 'kasir_ui_sim@sugengrawuh.test',
        ]);

        $this->device = PhysicalDevice::create([
            'name' => 'Samsung TV 43 UI Test',
            'category' => PhysicalDevice::CATEGORY_SMART_TV,
            'brand' => PhysicalDevice::BRAND_SAMSUNG,
            'model' => 'QA43',
            'mac_address' => '00:11:22:33:44:55',
            'ip_address' => '192.168.1.155',
            'connection_status' => PhysicalDevice::CONNECTION_UNVERIFIED,
            'is_active' => true,
        ]);
    }

    /** @test */
    public function guest_cannot_access_simulator_view()
    {
        $response = $this->get('/admin/hardware/simulator');
        $response->assertStatus(302)->assertRedirect('/login');
    }

    /** @test */
    public function kasir_is_forbidden_from_simulator_view()
    {
        $this->actingAs($this->kasir);
        $response = $this->get('/admin/hardware/simulator');
        $response->assertStatus(403);
    }

    /** @test */
    public function owner_is_forbidden_from_simulator_view()
    {
        $this->actingAs($this->owner);
        $response = $this->get('/admin/hardware/simulator');
        $response->assertStatus(403);
    }

    /** @test */
    public function admin_can_access_simulator_view_with_disclaimer_and_controls()
    {
        $this->actingAs($this->admin);

        $response = $this->get('/admin/hardware/simulator');

        $response->assertStatus(200);
        $response->assertSee('Hardware Simulator');
        $response->assertSee('MODE SIMULASI AKTIF');
        $response->assertSee('Samsung TV 43 UI Test');
        $response->assertSee('Power ON');
        $response->assertSee('Standby');
    }

    /** @test */
    public function sidebar_shows_hardware_simulator_link_for_admin_when_simulation_enabled()
    {
        $this->actingAs($this->admin);

        $response = $this->get('/admin/hardware/registry');

        $response->assertStatus(200);
        $response->assertSee('Hardware Simulator');
        $response->assertSee(route('admin.hardware.simulator.index'));
    }

    /** @test */
    public function sidebar_hides_hardware_simulator_link_when_simulation_disabled()
    {
        $this->actingAs($this->admin);

        Config::set('hardware.simulation_enabled', false);

        $response = $this->get('/admin/hardware/registry');

        $response->assertStatus(200);
        $response->assertDontSee('Hardware Simulator');
    }

    /** @test */
    public function sidebar_hides_hardware_simulator_link_for_owner_and_kasir()
    {
        // Owner view
        $this->actingAs($this->owner);
        $resOwner = $this->get('/admin/hardware/registry');
        $resOwner->assertStatus(200);
        $resOwner->assertDontSee('Hardware Simulator');

        // Kasir view
        $this->actingAs($this->kasir);
        $resKasir = $this->get('/device');
        $resKasir->assertStatus(200);
        $resKasir->assertDontSee('Hardware Simulator');
    }
}
