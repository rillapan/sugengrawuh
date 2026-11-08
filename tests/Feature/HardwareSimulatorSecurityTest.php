<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\PhysicalDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HardwareSimulatorSecurityTest extends TestCase
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
            'name' => 'Admin Security',
            'role' => 'admin',
            'email' => 'admin_sec@sugengrawuh.test',
        ]);

        $this->owner = User::factory()->create([
            'name' => 'Owner Security',
            'role' => 'owner',
            'email' => 'owner_sec@sugengrawuh.test',
        ]);

        $this->kasir = User::factory()->create([
            'name' => 'Kasir Security',
            'role' => 'kasir',
            'email' => 'kasir_sec@sugengrawuh.test',
        ]);

        $this->device = PhysicalDevice::create([
            'name' => 'Device Security Test',
            'category' => PhysicalDevice::CATEGORY_SMART_TV,
            'brand' => PhysicalDevice::BRAND_SAMSUNG,
            'model' => 'QA55',
            'mac_address' => '00:11:22:33:99:99',
            'ip_address' => '192.168.1.199',
            'connection_status' => PhysicalDevice::CONNECTION_UNVERIFIED,
            'is_active' => true,
        ]);
    }

    /** @test */
    public function guest_is_redirected_to_login_or_receives_401()
    {
        // HTML view request redirects to login
        $resHtml = $this->get('/admin/hardware/simulator');
        $resHtml->assertStatus(302)->assertRedirect('/login');

        // JSON requests receive 401 Unauthorized
        $resJson = $this->getJson('/admin/hardware/simulator/devices');
        $resJson->assertStatus(401);

        $resPost = $this->postJson('/admin/hardware/simulator/command', []);
        $resPost->assertStatus(401);
    }

    /** @test */
    public function kasir_is_strictly_forbidden_from_all_simulator_endpoints()
    {
        $this->actingAs($this->kasir);

        $resHtml = $this->get('/admin/hardware/simulator');
        $resHtml->assertStatus(403);

        $resDevices = $this->getJson('/admin/hardware/simulator/devices');
        $resDevices->assertStatus(403);

        $resCmd = $this->postJson('/admin/hardware/simulator/command', [
            'device_id' => $this->device->id,
            'command' => 'POWER_ON',
        ]);
        $resCmd->assertStatus(403);
    }

    /** @test */
    public function owner_is_strictly_forbidden_from_all_simulator_endpoints()
    {
        $this->actingAs($this->owner);

        $resHtml = $this->get('/admin/hardware/simulator');
        $resHtml->assertStatus(403);

        $resDevices = $this->getJson('/admin/hardware/simulator/devices');
        $resDevices->assertStatus(403);

        $resCmd = $this->postJson('/admin/hardware/simulator/command', [
            'device_id' => $this->device->id,
            'command' => 'POWER_ON',
        ]);
        $resCmd->assertStatus(403);
    }

    /** @test */
    public function production_database_guard_blocks_simulator_access()
    {
        $this->actingAs($this->admin);

        // Mock DB connection returning production DB name
        DB::shouldReceive('connection->getDatabaseName')
            ->once()
            ->andReturn('billing-sugeng-rawuh');

        $response = $this->getJson('/admin/hardware/simulator/devices');

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
            ]);
        $this->assertStringContainsString('billing-sugeng-rawuh', $response->json('message'));
    }

    /** @test */
    public function simulation_disabled_guard_blocks_simulator_access()
    {
        $this->actingAs($this->admin);

        Config::set('hardware.simulation_enabled', false);

        $response = $this->getJson('/admin/hardware/simulator/devices');

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
            ]);
    }

    /** @test */
    public function csrf_protection_is_enforced_on_simulator_mutations()
    {
        $middleware = new class(app(), app('encrypter')) extends VerifyCsrfToken {
            protected function runningUnitTests()
            {
                return false;
            }
        };

        $invalidRequest = \Illuminate\Http\Request::create('/admin/hardware/simulator/command', 'POST', [
            'device_id' => $this->device->id,
            'command' => 'POWER_ON',
        ]);
        $session = app('session.store');
        $session->start();
        $invalidRequest->setLaravelSession($session);

        $this->expectException(\Illuminate\Session\TokenMismatchException::class);
        $middleware->handle($invalidRequest, function () {
            return response('OK');
        });
    }
}
