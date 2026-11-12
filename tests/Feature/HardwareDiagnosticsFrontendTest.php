<?php

namespace Tests\Feature;

use App\Models\PhysicalDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class HardwareDiagnosticsFrontendTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $kasir;
    protected User $owner;
    protected PhysicalDevice $device;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('hardware.driver', 'real');
        Config::set('hardware.real_execution_enabled', false);

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'name' => 'Admin Frontend Diag',
            'email' => 'admin_frontend_diag@sugengrawuh.test',
        ]);

        $this->kasir = User::factory()->create([
            'role' => 'kasir',
            'name' => 'Kasir Frontend Diag',
            'email' => 'kasir_frontend_diag@sugengrawuh.test',
        ]);

        $this->owner = User::factory()->create([
            'role' => 'owner',
            'name' => 'Owner Frontend Diag',
            'email' => 'owner_frontend_diag@sugengrawuh.test',
        ]);

        $this->device = PhysicalDevice::create([
            'name' => 'Samsung TV Frontend Diag',
            'category' => PhysicalDevice::CATEGORY_SMART_TV,
            'brand' => PhysicalDevice::BRAND_SAMSUNG,
            'model' => '50U8500',
            'mac_address' => '00:11:22:33:66:01',
            'ip_address' => '192.168.1.161',
            'is_active' => true,
        ]);
    }

    /** @test */
    public function guest_cannot_access_diagnostics_blade_view()
    {
        $response = $this->get('/admin/hardware/diagnostics');
        $response->assertRedirect('/login');
    }

    /** @test */
    public function kasir_is_forbidden_from_diagnostics_blade_view()
    {
        $response = $this->actingAs($this->kasir)->get('/admin/hardware/diagnostics');
        $response->assertStatus(403);
    }

    /** @test */
    public function owner_is_forbidden_from_diagnostics_blade_view()
    {
        $response = $this->actingAs($this->owner)->get('/admin/hardware/diagnostics');
        $response->assertStatus(403);
    }

    /** @test */
    public function admin_can_view_diagnostics_blade_view_with_safety_guard_badge()
    {
        $response = $this->actingAs($this->admin)->get('/admin/hardware/diagnostics');

        $response->assertStatus(200);
        $response->assertSee('Hardware Diagnostics');
        $response->assertSee('SAFETY GUARD: PHYSICAL BLOCKED');
        $response->assertSee('GATE G4-B1 SAFETY PROTOCOL');
        $response->assertSee('disabled');
        $response->assertSee('Fake Transport');
    }

    /** @test */
    public function sidebar_shows_hardware_diagnostics_link_for_admin()
    {
        $response = $this->actingAs($this->admin)->get('/device');

        $response->assertStatus(200);
        $response->assertSee('Hardware Diagnostics');
        $response->assertSee(route('admin.hardware.diagnostics.index'));
    }

    /** @test */
    public function sidebar_hides_hardware_diagnostics_link_for_kasir()
    {
        $response = $this->actingAs($this->kasir)->get('/device');

        $response->assertStatus(200);
        $response->assertDontSee('Hardware Diagnostics');
        $response->assertDontSee(route('admin.hardware.diagnostics.index'));
    }

    /** @test */
    public function sidebar_hides_hardware_diagnostics_link_for_owner()
    {
        $response = $this->actingAs($this->owner)->get('/device');

        $response->assertStatus(200);
        $response->assertDontSee('Hardware Diagnostics');
        $response->assertDontSee(route('admin.hardware.diagnostics.index'));
    }

    /** @test */
    public function diagnostics_blade_view_renders_g2_three_operational_sections_and_seven_canonical_statuses()
    {
        $response = $this->actingAs($this->admin)->get('/admin/hardware/diagnostics');

        $response->assertStatus(200);

        // 3 distinct operational sections
        $response->assertSee('Read-Only Diagnostics');
        $response->assertSee('Device Pairing');
        $response->assertSee('Physical Control Test');

        // Explicit notice that pairing does not auto-enable physical automation
        $response->assertSee('tidak secara otomatis mengaktifkan');

        // 2-step server-side physical control approval UI
        $response->assertSee('approve-physical');
        $response->assertSee('Request Server Approval Token');

        // 7 Canonical diagnostic status badges
        $response->assertSee('Connected');
        $response->assertSee('Disconnected');
        $response->assertSee('Authentication Required');
        $response->assertSee('Pairing Required');
        $response->assertSee('Driver Unsupported');
        $response->assertSee('Timeout');
        $response->assertSee('Configuration Error');

        // Explanation & Remediation panels
        $response->assertSee('Penyebab');
        $response->assertSee('Langkah Perbaikan');
    }
}
