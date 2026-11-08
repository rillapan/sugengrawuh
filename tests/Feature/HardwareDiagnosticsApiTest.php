<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\Device;
use App\Models\PhysicalDevice;
use App\Models\Playstation;
use App\Models\StationDeviceMapping;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class HardwareDiagnosticsApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $kasir;
    protected User $owner;
    protected PhysicalDevice $samsungTv;
    protected PhysicalDevice $shellyRelay;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('hardware.driver', 'real');
        Config::set('hardware.real_execution_enabled', false); // G4-B1: Physical blocked

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'name' => 'Admin Diagnostics',
            'email' => 'admin_diag@sugengrawuh.test',
        ]);

        $this->kasir = User::factory()->create([
            'role' => 'kasir',
            'name' => 'Kasir Diagnostics',
            'email' => 'kasir_diag@sugengrawuh.test',
        ]);

        $this->owner = User::factory()->create([
            'role' => 'owner',
            'name' => 'Owner Diagnostics',
            'email' => 'owner_diag@sugengrawuh.test',
        ]);

        $this->samsungTv = PhysicalDevice::create([
            'name' => 'Samsung TV Diag 1',
            'category' => PhysicalDevice::CATEGORY_SMART_TV,
            'brand' => PhysicalDevice::BRAND_SAMSUNG,
            'model' => '50U8500',
            'mac_address' => '00:11:22:33:55:01',
            'ip_address' => '192.168.1.101',
            'connection_status' => PhysicalDevice::CONNECTION_UNVERIFIED,
            'pairing_status' => PhysicalDevice::PAIRING_UNVERIFIED,
            'is_active' => true,
        ]);

        $this->shellyRelay = PhysicalDevice::create([
            'name' => 'Shelly Relay Diag 1',
            'category' => PhysicalDevice::CATEGORY_SMART_RELAY,
            'brand' => PhysicalDevice::BRAND_SHELLY,
            'model' => 'Shelly 1PM Mini Gen3',
            'mac_address' => '00:11:22:33:55:02',
            'ip_address' => '192.168.1.102',
            'connection_status' => PhysicalDevice::CONNECTION_UNVERIFIED,
            'pairing_status' => PhysicalDevice::PAIRING_UNVERIFIED,
            'is_active' => true,
        ]);
    }

    /** @test */
    public function guest_cannot_access_diagnostics_endpoints()
    {
        $response = $this->getJson('/admin/hardware/diagnostics/devices');
        $response->assertStatus(401);
    }

    /** @test */
    public function kasir_is_strictly_forbidden_from_diagnostics_endpoints()
    {
        $this->actingAs($this->kasir);

        $this->getJson('/admin/hardware/diagnostics/devices')->assertStatus(403);

        $this->postJson('/admin/hardware/diagnostics/probe', [
            'device_id' => $this->samsungTv->id,
            'transport_mode' => 'fake',
        ])->assertStatus(403);
    }

    /** @test */
    public function owner_is_strictly_forbidden_from_diagnostics_endpoints()
    {
        $this->actingAs($this->owner);

        $this->getJson('/admin/hardware/diagnostics/devices')->assertStatus(403);

        $this->postJson('/admin/hardware/diagnostics/command', [
            'device_id' => $this->shellyRelay->id,
            'command' => 'SWITCH_ON',
            'transport_mode' => 'fake',
        ])->assertStatus(403);
    }

    /** @test */
    public function admin_can_list_diagnostics_devices_with_metadata()
    {
        $this->actingAs($this->admin);

        $response = $this->getJson('/admin/hardware/diagnostics/devices');
        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $this->assertCount(2, $response->json('data'));
    }

    /** @test */
    public function admin_probe_with_fake_transport_succeeds_with_disclaimer()
    {
        $this->actingAs($this->admin);

        $response = $this->postJson('/admin/hardware/diagnostics/probe', [
            'device_id' => $this->samsungTv->id,
            'transport_mode' => 'fake',
            'scenario' => 'SUCCESS',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('transport_mode', 'FAKE_TRANSPORT');
        $this->assertStringContainsString('Fake Transport', $response->json('disclaimer'));
    }

    /** @test */
    public function admin_pair_with_fake_transport_succeeds_without_mutating_real_database()
    {
        $this->actingAs($this->admin);

        $response = $this->postJson('/admin/hardware/diagnostics/pair', [
            'device_id' => $this->samsungTv->id,
            'transport_mode' => 'fake',
            'scenario' => 'SUCCESS',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.result_code', 'PAIRED');

        // Physical database column MUST remain UNVERIFIED in G4-B1 fake tests!
        $refreshed = $this->samsungTv->fresh();
        $this->assertEquals(PhysicalDevice::PAIRING_UNVERIFIED, $refreshed->pairing_status);
        $this->assertNull($refreshed->pairing_payload);
    }

    /** @test */
    public function admin_can_send_command_to_samsung_tv_via_fake_transport()
    {
        $this->actingAs($this->admin);

        $response = $this->postJson('/admin/hardware/diagnostics/command', [
            'device_id' => $this->samsungTv->id,
            'command' => 'MUTE',
            'transport_mode' => 'fake',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.result_code', 'ACK_TRANSPORT');
    }

    /** @test */
    public function admin_can_send_command_to_shelly_relay_via_fake_transport()
    {
        $this->actingAs($this->admin);

        $response = $this->postJson('/admin/hardware/diagnostics/command', [
            'device_id' => $this->shellyRelay->id,
            'command' => 'SWITCH_ON',
            'transport_mode' => 'fake',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.result_code', 'SUCCESS');
        $this->assertTrue($response->json('data.simulated_state.output'));
    }

    /** @test */
    public function real_mode_execution_is_blocked_by_safety_guard_in_g4_b1()
    {
        $this->actingAs($this->admin);

        // HARDWARE_REAL_EXECUTION_ENABLED is false
        $response = $this->postJson('/admin/hardware/diagnostics/probe', [
            'device_id' => $this->samsungTv->id,
            'transport_mode' => 'real',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('success', false);
        $this->assertStringContainsString('SAFETY GUARD ACTIVE: Eksekusi jaringan ke hardware fisik dinonaktifkan', $response->json('message'));
    }

    /** @test */
    public function csrf_protection_is_enforced_on_mutations()
    {
        $middleware = new class(app(), app('encrypter')) extends VerifyCsrfToken {
            protected function runningUnitTests()
            {
                return false;
            }
        };

        $invalidRequest = \Illuminate\Http\Request::create('/admin/hardware/diagnostics/probe', 'POST', [
            'device_id' => $this->samsungTv->id,
            'transport_mode' => 'fake',
        ]);
        $session = app('session.store');
        $session->start();
        $invalidRequest->setLaravelSession($session);

        $this->expectException(\Illuminate\Session\TokenMismatchException::class);
        $middleware->handle($invalidRequest, function () {
            return response('OK');
        });
    }

    /** @test */
    public function diagnostics_events_circular_buffer_logs_commands()
    {
        $this->actingAs($this->admin);

        // Execute a probe
        $this->postJson('/admin/hardware/diagnostics/probe', [
            'device_id' => $this->samsungTv->id,
            'transport_mode' => 'fake',
        ]);

        // Get events
        $response = $this->getJson("/admin/hardware/diagnostics/events/{$this->samsungTv->id}");
        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $this->assertNotEmpty($response->json('data'));
        $this->assertEquals('PROBE', $response->json('data.0.action'));
    }
}
