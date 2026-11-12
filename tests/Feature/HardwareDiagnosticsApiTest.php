<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\Device;
use App\Models\PhysicalDevice;
use App\Models\Playstation;
use App\Models\StationDeviceMapping;
use App\Models\User;
use App\Services\Hardware\Security\HardwareSafetyGuard;
use App\Services\Hardware\Transports\FakeSamsungWebSocketTransport;
use App\Services\Hardware\Transports\FakeShellyHttpRpcTransport;
use App\Services\Hardware\Transports\SamsungTransportInterface;
use App\Services\Hardware\Transports\ShellyTransportInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class HardwareDiagnosticsApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $kasir;
    protected User $owner;
    protected PhysicalDevice $samsungTv;
    protected PhysicalDevice $shellyRelay;
    protected FakeSamsungWebSocketTransport $fakeSamsungTransport;
    protected FakeShellyHttpRpcTransport $fakeShellyTransport;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('hardware.driver', 'real');
        Config::set('hardware.real_execution_enabled', false); // Default after installer: background physical execution disabled

        // Bind safe fake transports into container so real-mode diagnostic tests NEVER send physical network packets
        $this->fakeSamsungTransport = new FakeSamsungWebSocketTransport();
        $this->fakeShellyTransport = new FakeShellyHttpRpcTransport();
        $this->app->instance(SamsungTransportInterface::class, $this->fakeSamsungTransport);
        $this->app->instance(ShellyTransportInterface::class, $this->fakeShellyTransport);

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
        $this->getJson('/admin/hardware/diagnostics/devices')->assertStatus(401);
        $this->postJson('/admin/hardware/diagnostics/probe', ['device_id' => $this->samsungTv->id])->assertStatus(401);
        $this->getJson("/admin/hardware/diagnostics/status/{$this->samsungTv->id}")->assertStatus(401);
        $this->postJson('/admin/hardware/diagnostics/pair', ['device_id' => $this->samsungTv->id])->assertStatus(401);
        $this->postJson('/admin/hardware/diagnostics/approve-physical', ['device_id' => $this->samsungTv->id, 'command' => 'MUTE'])->assertStatus(401);
        $this->postJson('/admin/hardware/diagnostics/command', ['device_id' => $this->samsungTv->id, 'command' => 'MUTE'])->assertStatus(401);
    }

    /** @test */
    public function kasir_is_strictly_forbidden_from_diagnostics_endpoints()
    {
        $this->actingAs($this->kasir);

        $this->getJson('/admin/hardware/diagnostics/devices')->assertStatus(403);
        $this->postJson('/admin/hardware/diagnostics/probe', ['device_id' => $this->samsungTv->id, 'transport_mode' => 'fake'])->assertStatus(403);
        $this->getJson("/admin/hardware/diagnostics/status/{$this->samsungTv->id}")->assertStatus(403);
        $this->postJson('/admin/hardware/diagnostics/pair', ['device_id' => $this->samsungTv->id, 'transport_mode' => 'real'])->assertStatus(403);
        $this->postJson('/admin/hardware/diagnostics/approve-physical', ['device_id' => $this->samsungTv->id, 'command' => 'MUTE'])->assertStatus(403);
        $this->postJson('/admin/hardware/diagnostics/command', ['device_id' => $this->samsungTv->id, 'command' => 'MUTE', 'transport_mode' => 'fake'])->assertStatus(403);
    }

    /** @test */
    public function owner_is_strictly_forbidden_from_diagnostics_endpoints()
    {
        $this->actingAs($this->owner);

        $this->getJson('/admin/hardware/diagnostics/devices')->assertStatus(403);
        $this->postJson('/admin/hardware/diagnostics/probe', ['device_id' => $this->samsungTv->id, 'transport_mode' => 'real'])->assertStatus(403);
        $this->getJson("/admin/hardware/diagnostics/status/{$this->samsungTv->id}")->assertStatus(403);
        $this->postJson('/admin/hardware/diagnostics/pair', ['device_id' => $this->samsungTv->id, 'transport_mode' => 'real'])->assertStatus(403);
        $this->postJson('/admin/hardware/diagnostics/approve-physical', ['device_id' => $this->shellyRelay->id, 'command' => 'SWITCH_ON'])->assertStatus(403);
        $this->postJson('/admin/hardware/diagnostics/command', ['device_id' => $this->shellyRelay->id, 'command' => 'SWITCH_ON', 'transport_mode' => 'fake'])->assertStatus(403);
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
        $response->assertJsonPath('diagnostic_status', 'Connected');
        $this->assertStringContainsString('Fake Transport', $response->json('disclaimer'));
    }

    /** @test */
    public function admin_can_run_read_only_probe_and_status_in_real_mode_with_zero_physical_or_db_mutations()
    {
        $this->actingAs($this->admin);
        Config::set('hardware.driver', 'real');
        Config::set('hardware.real_execution_enabled', false);

        // 1. Real mode PROBE on Samsung TV
        $probeRes = $this->postJson('/admin/hardware/diagnostics/probe', [
            'device_id' => $this->samsungTv->id,
            'transport_mode' => 'real',
        ]);
        $probeRes->assertStatus(200);
        $probeRes->assertJsonPath('success', true);
        $probeRes->assertJsonPath('transport_mode', 'REAL_NETWORK');
        $probeRes->assertJsonPath('diagnostic_status', 'Connected');

        // 2. Real mode GET_STATUS via /status/{id} on Shelly Relay
        $statusRes = $this->getJson("/admin/hardware/diagnostics/status/{$this->shellyRelay->id}?transport_mode=real");
        $statusRes->assertStatus(200);
        $statusRes->assertJsonPath('success', true);
        $statusRes->assertJsonPath('transport_mode', 'REAL_NETWORK');
        $statusRes->assertJsonPath('diagnostic_status', 'Connected');
        $this->assertArrayHasKey('apower', $statusRes->json('data.telemetry'));
        $this->assertArrayHasKey('voltage', $statusRes->json('data.telemetry'));

        // 3. Real mode GET_STATUS via /command endpoint
        $cmdStatusRes = $this->postJson('/admin/hardware/diagnostics/command', [
            'device_id' => $this->samsungTv->id,
            'command' => 'GET_STATUS',
            'transport_mode' => 'real',
        ]);
        $cmdStatusRes->assertStatus(200);
        $cmdStatusRes->assertJsonPath('success', true);

        // Zero physical keys sent to TV and zero Switch.Set calls to Shelly
        $this->assertEmpty($this->fakeSamsungTransport->getSentKeys());
        $shellySetCalls = array_filter($this->fakeShellyTransport->getRpcLog(), fn ($r) => ($r['method'] ?? '') === 'Switch.Set');
        $this->assertEmpty($shellySetCalls);

        // Zero DB status/capability mutations during read-only diagnostics
        $this->samsungTv->refresh();
        $this->shellyRelay->refresh();
        $this->assertEquals(PhysicalDevice::CONNECTION_UNVERIFIED, $this->samsungTv->connection_status);
        $this->assertEquals(PhysicalDevice::CONNECTION_UNVERIFIED, $this->shellyRelay->connection_status);
        $this->assertEmpty($this->samsungTv->verified_capabilities ?? []);
        $this->assertEmpty($this->shellyRelay->verified_capabilities ?? []);
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

        $refreshed = $this->samsungTv->fresh();
        $this->assertEquals(PhysicalDevice::PAIRING_UNVERIFIED, $refreshed->pairing_status);
        $this->assertNull($refreshed->pairing_payload);
    }

    /** @test */
    public function admin_pair_in_real_mode_persists_encrypted_token_and_audit_log_without_auto_enabling_physical_capabilities()
    {
        $this->actingAs($this->admin);
        Config::set('hardware.driver', 'real');
        Config::set('hardware.real_execution_enabled', false);

        Log::spy();

        $this->fakeSamsungTransport->setCustomToken('samsung_real_pair_token_778899');

        $response = $this->postJson('/admin/hardware/diagnostics/pair', [
            'device_id' => $this->samsungTv->id,
            'transport_mode' => 'real',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('transport_mode', 'REAL_NETWORK');
        $response->assertJsonPath('diagnostic_status', 'Connected');
        // Token must be redacted in JSON response
        $this->assertStringNotContainsString('samsung_real_pair_token_778899', $response->getContent());

        // Verify encrypted token saved & pairing_status = PAIRED
        $this->samsungTv->refresh();
        $this->assertEquals(PhysicalDevice::PAIRING_PAIRED, $this->samsungTv->pairing_status);
        $this->assertEquals('samsung_real_pair_token_778899', $this->samsungTv->pairing_payload);

        // CRITICAL: Pairing MUST NOT auto-populate physical control capabilities or enable automation
        $capabilities = $this->samsungTv->verified_capabilities ?? [];
        $this->assertNotContains('POWER_ON', $capabilities);
        $this->assertNotContains('STANDBY', $capabilities);
        $this->assertNotContains('MUTE', $capabilities);
        $this->assertNotContains('SWITCH_ON', $capabilities);
        $this->assertNotContains('SWITCH_OFF', $capabilities);
        $this->assertFalse(HardwareSafetyGuard::isRealExecutionEnabled());

        // Verify security audit logs were written
        Log::shouldHaveReceived('info')->withArgs(function ($message) {
            return str_contains($message, '[HARDWARE_SAFETY_GUARD] Pairing authorized by ADMIN');
        })->once();

        Log::shouldHaveReceived('info')->withArgs(function ($message) {
            return str_contains($message, '[HARDWARE_PAIRING_AUDIT] Pairing succeeded');
        })->once();
    }

    /** @test */
    public function admin_pair_rejection_in_real_mode_marks_pairing_failed_and_returns_pairing_required_status()
    {
        $this->actingAs($this->admin);
        $this->fakeSamsungTransport->setScenario(FakeSamsungWebSocketTransport::SCENARIO_AUTH_REJECTED);

        $response = $this->postJson('/admin/hardware/diagnostics/pair', [
            'device_id' => $this->samsungTv->id,
            'transport_mode' => 'real',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('diagnostic_status', 'Pairing Required');
        $response->assertJsonPath('diagnostic_report.sub_status', 'PAIRING_DENIED');

        $this->samsungTv->refresh();
        $this->assertEquals(PhysicalDevice::PAIRING_FAILED, $this->samsungTv->pairing_status);
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
    public function real_mode_physical_control_without_server_approval_token_is_always_blocked_even_with_boolean_bypass_flags()
    {
        $this->actingAs($this->admin);

        // Attempt 1: No approval_token
        $res1 = $this->postJson('/admin/hardware/diagnostics/command', [
            'device_id' => $this->samsungTv->id,
            'command' => 'MUTE',
            'transport_mode' => 'real',
        ]);
        $res1->assertStatus(403);
        $res1->assertJsonPath('safety_guard', 'BLOCKED');

        // Attempt 2: Client sends boolean flags attempting to bypass server approval
        $res2 = $this->postJson('/admin/hardware/diagnostics/command', [
            'device_id' => $this->shellyRelay->id,
            'command' => 'SWITCH_ON',
            'transport_mode' => 'real',
            'explicit_approval' => true,
            'confirmed' => true,
            'force' => true,
        ]);
        $res2->assertStatus(403);
        $res2->assertJsonPath('safety_guard', 'BLOCKED');

        // Zero physical commands were sent
        $this->assertEmpty($this->fakeSamsungTransport->getSentKeys());
        $this->assertEmpty($this->fakeShellyTransport->getRpcLog());
    }

    /** @test */
    public function real_mode_physical_control_succeeds_with_valid_server_approval_token_and_enforces_single_use_and_scope()
    {
        $this->actingAs($this->admin);

        // Step 1: Request server approval token for Samsung MUTE
        $approveRes = $this->postJson('/admin/hardware/diagnostics/approve-physical', [
            'device_id' => $this->samsungTv->id,
            'command' => 'MUTE',
        ]);
        $approveRes->assertStatus(200);
        $approveRes->assertJsonPath('success', true);
        $token = $approveRes->json('approval_token');
        $this->assertNotEmpty($token);

        // Using token for wrong command (STANDBY) must fail with 403
        $wrongCmdRes = $this->postJson('/admin/hardware/diagnostics/command', [
            'device_id' => $this->samsungTv->id,
            'command' => 'STANDBY',
            'transport_mode' => 'real',
            'approval_token' => $token,
        ]);
        $wrongCmdRes->assertStatus(403);

        // Using token for wrong device (Shelly) must fail with 403
        $wrongDevRes = $this->postJson('/admin/hardware/diagnostics/command', [
            'device_id' => $this->shellyRelay->id,
            'command' => 'MUTE',
            'transport_mode' => 'real',
            'approval_token' => $token,
        ]);
        $wrongDevRes->assertStatus(403);

        // Step 2: Execute MUTE with valid token -> succeeds!
        $execRes = $this->postJson('/admin/hardware/diagnostics/command', [
            'device_id' => $this->samsungTv->id,
            'command' => 'MUTE',
            'transport_mode' => 'real',
            'approval_token' => $token,
        ]);
        $execRes->assertStatus(200);
        $execRes->assertJsonPath('success', true);
        $execRes->assertJsonPath('transport_mode', 'REAL_NETWORK');
        $execRes->assertJsonPath('diagnostic_status', 'Connected');
        $this->assertContains('KEY_MUTE', $this->fakeSamsungTransport->getSentKeys());

        // Step 3: Reusing the consumed single-use token must fail with 403
        $reuseRes = $this->postJson('/admin/hardware/diagnostics/command', [
            'device_id' => $this->samsungTv->id,
            'command' => 'MUTE',
            'transport_mode' => 'real',
            'approval_token' => $token,
        ]);
        $reuseRes->assertStatus(403);
    }

    /** @test */
    public function invalid_ip_targets_and_disallowed_ports_are_rejected_with_422()
    {
        $this->actingAs($this->admin);

        // Loopback IP
        $this->samsungTv->update(['ip_address' => '127.0.0.1']);
        $resLoopback = $this->postJson('/admin/hardware/diagnostics/probe', [
            'device_id' => $this->samsungTv->id,
            'transport_mode' => 'real',
        ]);
        $resLoopback->assertStatus(422);
        $resLoopback->assertJsonPath('diagnostic_status', 'Configuration Error');

        // Link-local metadata IP
        $this->samsungTv->update(['ip_address' => '169.254.169.254']);
        $this->postJson('/admin/hardware/diagnostics/probe', [
            'device_id' => $this->samsungTv->id,
            'transport_mode' => 'real',
        ])->assertStatus(422);

        // Public WAN IP
        $this->samsungTv->update(['ip_address' => '8.8.8.8']);
        $this->postJson('/admin/hardware/diagnostics/probe', [
            'device_id' => $this->samsungTv->id,
            'transport_mode' => 'real',
        ])->assertStatus(422);

        // Restore valid private LAN IP, test disallowed port
        $this->samsungTv->update(['ip_address' => '192.168.1.101']);
        $this->postJson('/admin/hardware/diagnostics/probe', [
            'device_id' => $this->samsungTv->id,
            'transport_mode' => 'real',
            'port' => 22,
        ])->assertStatus(422);
    }

    /** @test */
    public function samsung_and_shelly_transport_scenarios_map_accurately_to_all_7_canonical_statuses()
    {
        $this->actingAs($this->admin);

        // 1. Connected (Samsung & Shelly SUCCESS)
        $resConnected = $this->postJson('/admin/hardware/diagnostics/probe', [
            'device_id' => $this->shellyRelay->id,
            'transport_mode' => 'fake',
            'scenario' => 'SUCCESS',
        ]);
        $resConnected->assertStatus(200);
        $resConnected->assertJsonPath('diagnostic_status', 'Connected');
        $this->assertNotEmpty($resConnected->json('diagnostic_report.explanation'));
        $this->assertNotEmpty($resConnected->json('diagnostic_report.remediation'));

        // 2. Disconnected (Samsung & Shelly OFFLINE)
        $resOffline = $this->postJson('/admin/hardware/diagnostics/probe', [
            'device_id' => $this->samsungTv->id,
            'transport_mode' => 'fake',
            'scenario' => 'OFFLINE',
        ]);
        $resOffline->assertStatus(422);
        $resOffline->assertJsonPath('diagnostic_status', 'Disconnected');

        // 3. Timeout (Samsung & Shelly TIMEOUT)
        $resTimeout = $this->postJson('/admin/hardware/diagnostics/probe', [
            'device_id' => $this->shellyRelay->id,
            'transport_mode' => 'fake',
            'scenario' => 'TIMEOUT',
        ]);
        $resTimeout->assertStatus(422);
        $resTimeout->assertJsonPath('diagnostic_status', 'Timeout');

        // 4. Authentication Required (Shelly AUTH_REQUIRED / HTTP 401)
        $resShellyAuth = $this->postJson('/admin/hardware/diagnostics/probe', [
            'device_id' => $this->shellyRelay->id,
            'transport_mode' => 'fake',
            'scenario' => 'AUTH_REQUIRED',
        ]);
        $resShellyAuth->assertStatus(422);
        $resShellyAuth->assertJsonPath('diagnostic_status', 'Authentication Required');

        // 5. Pairing Required (Samsung AUTH_REJECTED / PAIRING_DENIED)
        $resSamsungPair = $this->postJson('/admin/hardware/diagnostics/probe', [
            'device_id' => $this->samsungTv->id,
            'transport_mode' => 'fake',
            'scenario' => 'AUTH_REJECTED',
        ]);
        $resSamsungPair->assertStatus(422);
        $resSamsungPair->assertJsonPath('diagnostic_status', 'Pairing Required');

        // 6. Configuration Error (Shelly INVALID_JSON & Samsung MALFORMED)
        $resInvalidJson = $this->postJson('/admin/hardware/diagnostics/probe', [
            'device_id' => $this->shellyRelay->id,
            'transport_mode' => 'fake',
            'scenario' => 'INVALID_JSON',
        ]);
        $resInvalidJson->assertStatus(422);
        $resInvalidJson->assertJsonPath('diagnostic_status', 'Configuration Error');

        // 7. Driver Unsupported — CAPABILITY_INCOMPATIBLE (e.g. SET_VOLUME on Samsung WS)
        $resUnsupportedCap = $this->postJson('/admin/hardware/diagnostics/command', [
            'device_id' => $this->samsungTv->id,
            'command' => 'SET_VOLUME',
            'transport_mode' => 'fake',
        ]);
        $resUnsupportedCap->assertStatus(422);
        $resUnsupportedCap->assertJsonPath('diagnostic_status', 'Driver Unsupported');
        $resUnsupportedCap->assertJsonPath('diagnostic_report.sub_status', 'CAPABILITY_INCOMPATIBLE');
    }

    /** @test */
    public function coocaa_device_returns_driver_unsupported_with_driver_not_available_sub_status()
    {
        $this->actingAs($this->admin);

        $coocaaTv = PhysicalDevice::create([
            'name' => 'Coocaa Smart TV Test',
            'category' => PhysicalDevice::CATEGORY_SMART_TV,
            'brand' => 'COOCAA',
            'model' => 'Coocaa 43S3U',
            'mac_address' => '00:11:22:33:55:99',
            'ip_address' => '192.168.1.109',
            'is_active' => true,
        ]);

        $response = $this->postJson('/admin/hardware/diagnostics/probe', [
            'device_id' => $coocaaTv->id,
            'transport_mode' => 'real',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('diagnostic_status', 'Driver Unsupported');
        $response->assertJsonPath('diagnostic_report.status', 'Driver Unsupported');
        $response->assertJsonPath('diagnostic_report.sub_status', 'DRIVER_NOT_AVAILABLE');
        $this->assertStringContainsString(
            'Driver belum didukung: Protokol kontrol untuk merek Coocaa belum terverifikasi pada rilis ini. Hal ini bukan berarti seluruh model Coocaa tidak dapat dikendalikan.',
            $response->json('diagnostic_report.explanation')
        );
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
