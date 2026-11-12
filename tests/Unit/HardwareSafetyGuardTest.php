<?php

namespace Tests\Unit;

use App\Exceptions\HardwareSafetyException;
use App\Models\PhysicalDevice;
use App\Models\User;
use App\Services\Hardware\Security\HardwareSafetyGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class HardwareSafetyGuardTest extends TestCase
{
    use RefreshDatabase;

    protected PhysicalDevice $device;
    protected User $admin;
    protected User $kasir;
    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Admin Guard', 'email' => 'admin_guard@sugengrawuh.test']);
        $this->kasir = User::factory()->create(['role' => 'kasir', 'name' => 'Kasir Guard', 'email' => 'kasir_guard@sugengrawuh.test']);
        $this->owner = User::factory()->create(['role' => 'owner', 'name' => 'Owner Guard', 'email' => 'owner_guard@sugengrawuh.test']);

        $this->device = PhysicalDevice::create([
            'name' => 'Samsung TV Guard Test',
            'category' => PhysicalDevice::CATEGORY_SMART_TV,
            'brand' => PhysicalDevice::BRAND_SAMSUNG,
            'model' => '50U8500',
            'mac_address' => '00:11:22:33:44:55',
            'ip_address' => '192.168.1.50',
            'is_active' => true,
            'verified_capabilities' => ['MUTE', 'STANDBY'],
        ]);
    }

    /** @test */
    public function blocks_execution_if_driver_mode_is_not_real()
    {
        Config::set('hardware.driver', 'none');
        Config::set('hardware.real_execution_enabled', true);

        $this->expectException(HardwareSafetyException::class);
        $this->expectExceptionMessage("Driver hardware aktif saat ini adalah 'none'");

        HardwareSafetyGuard::assertRealExecutionAllowed($this->device, 'MUTE', $this->admin);
    }

    /** @test */
    public function blocks_execution_if_real_execution_is_disabled()
    {
        Config::set('hardware.driver', 'real');
        Config::set('hardware.real_execution_enabled', false);

        $this->expectException(HardwareSafetyException::class);
        $this->expectExceptionMessage('SAFETY GUARD ACTIVE: Eksekusi jaringan ke hardware fisik dinonaktifkan');

        HardwareSafetyGuard::assertRealExecutionAllowed($this->device, 'MUTE', $this->admin);
    }

    /** @test */
    public function blocks_non_admin_users_from_executing_real_commands()
    {
        Config::set('hardware.driver', 'real');
        Config::set('hardware.real_execution_enabled', true);

        // Kasir
        try {
            HardwareSafetyGuard::assertRealExecutionAllowed($this->device, 'MUTE', $this->kasir);
            $this->fail('Kasir should be rejected');
        } catch (HardwareSafetyException $e) {
            $this->assertStringContainsString("role 'kasir' tidak berwenang", $e->getMessage());
        }

        // Owner is permitted per approved RBAC policy
        Config::set('hardware.real_allowed_device_ids', [$this->device->id]);
        $this->device->verified_capabilities = ['MUTE'];
        $this->device->save();

        HardwareSafetyGuard::assertRealExecutionAllowed($this->device, 'MUTE', $this->owner);
        $this->assertTrue(true);
    }

    /** @test */
    public function blocks_execution_on_inactive_devices()
    {
        Config::set('hardware.driver', 'real');
        Config::set('hardware.real_execution_enabled', true);

        $this->device->is_active = false;
        $this->device->save();

        $this->expectException(HardwareSafetyException::class);
        $this->expectExceptionMessage('berstatus nonaktif');

        HardwareSafetyGuard::assertRealExecutionAllowed($this->device, 'MUTE', $this->admin);
    }

    /** @test */
    public function blocks_devices_not_in_real_allowed_devices_list()
    {
        Config::set('hardware.driver', 'real');
        Config::set('hardware.real_execution_enabled', true);
        Config::set('hardware.real_allowed_device_ids', [999, 888]); // Device ID is not 999 or 888

        $this->expectException(HardwareSafetyException::class);
        $this->expectExceptionMessage('tidak terdaftar dalam allowlist');

        HardwareSafetyGuard::assertRealExecutionAllowed($this->device, 'MUTE', $this->admin);
    }

    /** @test */
    public function blocks_unverified_capabilities_for_normal_commands()
    {
        Config::set('hardware.driver', 'real');
        Config::set('hardware.real_execution_enabled', true);
        Config::set('hardware.real_allowed_device_ids', []);

        // POWER_ON is not in $device->verified_capabilities (['MUTE', 'STANDBY'])
        $this->expectException(HardwareSafetyException::class);
        $this->expectExceptionMessage("CAPABILITY UNVERIFIED: Perintah 'POWER_ON' belum terverifikasi");

        HardwareSafetyGuard::assertRealExecutionAllowed($this->device, 'POWER_ON', $this->admin, false);
    }

    /** @test */
    public function allows_execution_when_all_guards_pass()
    {
        Config::set('hardware.driver', 'real');
        Config::set('hardware.real_execution_enabled', true);
        Config::set('hardware.real_allowed_device_ids', [$this->device->id]);

        // Should not throw any exception
        HardwareSafetyGuard::assertRealExecutionAllowed($this->device, 'MUTE', $this->admin, false);
        $this->assertTrue(HardwareSafetyGuard::isRealExecutionEnabled());
    }

    /** @test */
    public function allows_execution_when_wildcard_allowlist_configured()
    {
        Config::set('hardware.driver', 'real');
        Config::set('hardware.real_execution_enabled', true);
        Config::set('hardware.real_allowed_device_ids', ['*']);

        // Wildcard should permit any device without throwing deviceNotAllowlisted
        HardwareSafetyGuard::assertRealExecutionAllowed($this->device, 'MUTE', $this->admin, false);
        $this->assertTrue(true);
    }

    /** @test */
    public function read_only_diagnostic_allows_admin_for_probe_and_get_status_when_real_execution_is_disabled()
    {
        Config::set('hardware.driver', 'real');
        Config::set('hardware.real_execution_enabled', false);

        HardwareSafetyGuard::assertReadOnlyDiagnosticAllowed($this->device, 'PROBE', $this->admin, 8001);
        HardwareSafetyGuard::assertReadOnlyDiagnosticAllowed($this->device, 'GET_STATUS', $this->admin, 8001);

        $this->assertFalse(HardwareSafetyGuard::isRealExecutionEnabled());
    }

    /** @test */
    public function read_only_diagnostic_rejects_non_read_only_commands()
    {
        Config::set('hardware.driver', 'real');
        Config::set('hardware.real_execution_enabled', false);

        foreach (['PAIR', 'MUTE', 'STANDBY', 'POWER_ON', 'SWITCH_ON', 'SWITCH_OFF'] as $cmd) {
            try {
                HardwareSafetyGuard::assertReadOnlyDiagnosticAllowed($this->device, $cmd, $this->admin);
                $this->fail("Command {$cmd} should be rejected on read-only diagnostic path");
            } catch (HardwareSafetyException $e) {
                $this->assertEquals(403, $e->getStatusCode());
                $this->assertStringContainsString($cmd, $e->getMessage());
            }
        }
    }

    /** @test */
    public function read_only_diagnostic_rejects_non_admin_and_invalid_targets()
    {
        Config::set('hardware.driver', 'real');
        Config::set('hardware.real_execution_enabled', false);

        // Kasir rejected
        try {
            HardwareSafetyGuard::assertReadOnlyDiagnosticAllowed($this->device, 'PROBE', $this->kasir);
            $this->fail('Kasir must be rejected');
        } catch (HardwareSafetyException $e) {
            $this->assertEquals(403, $e->getStatusCode());
        }

        // Owner rejected (Diagnostics is strictly ADMIN-only)
        try {
            HardwareSafetyGuard::assertReadOnlyDiagnosticAllowed($this->device, 'PROBE', $this->owner);
            $this->fail('Owner must be rejected from diagnostics');
        } catch (HardwareSafetyException $e) {
            $this->assertEquals(403, $e->getStatusCode());
        }

        // Disallowed port rejected (422)
        try {
            HardwareSafetyGuard::assertReadOnlyDiagnosticAllowed($this->device, 'PROBE', $this->admin, 22);
            $this->fail('Port 22 must be rejected');
        } catch (HardwareSafetyException $e) {
            $this->assertEquals(422, $e->getStatusCode());
        }

        // Loopback IP rejected (422)
        $this->device->ip_address = '127.0.0.1';
        try {
            HardwareSafetyGuard::assertReadOnlyDiagnosticAllowed($this->device, 'PROBE', $this->admin, 8001);
            $this->fail('Loopback IP must be rejected');
        } catch (HardwareSafetyException $e) {
            $this->assertEquals(422, $e->getStatusCode());
        }
    }

    /** @test */
    public function pairing_guard_allows_admin_in_real_mode_and_rejects_unauthorized_roles()
    {
        Config::set('hardware.driver', 'real');
        Config::set('hardware.real_execution_enabled', false);

        HardwareSafetyGuard::assertPairingAllowed($this->device, $this->admin, 8002);

        try {
            HardwareSafetyGuard::assertPairingAllowed($this->device, $this->owner, 8002);
            $this->fail('Owner must not be allowed to run diagnostic pairing');
        } catch (HardwareSafetyException $e) {
            $this->assertEquals(403, $e->getStatusCode());
        }

        try {
            HardwareSafetyGuard::assertPairingAllowed($this->device, $this->kasir, 8002);
            $this->fail('Kasir must not be allowed to run diagnostic pairing');
        } catch (HardwareSafetyException $e) {
            $this->assertEquals(403, $e->getStatusCode());
        }
    }

    /** @test */
    public function physical_control_approval_grants_single_use_token_bound_to_device_user_and_command()
    {
        Config::set('hardware.driver', 'real');
        Config::set('hardware.real_execution_enabled', false);

        $approval = HardwareSafetyGuard::grantPhysicalControlApproval($this->device, $this->admin, 'MUTE', 300);

        $this->assertNotEmpty($approval['approval_token']);
        $this->assertEquals($this->device->id, $approval['device_id']);
        $this->assertEquals($this->admin->id, $approval['user_id']);
        $this->assertEquals('MUTE', $approval['command']);

        // Mismatched command must fail without consuming the MUTE token
        try {
            HardwareSafetyGuard::assertPhysicalControlTestAllowed($this->device, 'STANDBY', $this->admin, $approval['approval_token']);
            $this->fail('Mismatched command must be rejected');
        } catch (HardwareSafetyException $e) {
            $this->assertEquals(403, $e->getStatusCode());
        }

        // Matching command succeeds and consumes the token
        HardwareSafetyGuard::assertPhysicalControlTestAllowed($this->device, 'MUTE', $this->admin, $approval['approval_token']);

        // Reusing consumed token must be rejected
        try {
            HardwareSafetyGuard::assertPhysicalControlTestAllowed($this->device, 'MUTE', $this->admin, $approval['approval_token']);
            $this->fail('Reused approval token must be rejected');
        } catch (HardwareSafetyException $e) {
            $this->assertEquals(403, $e->getStatusCode());
        }
    }

    /** @test */
    public function physical_control_approval_rejects_expired_mismatched_device_or_non_physical_command()
    {
        Config::set('hardware.driver', 'real');
        Config::set('hardware.real_execution_enabled', false);

        // Non-physical command cannot receive approval token
        try {
            HardwareSafetyGuard::grantPhysicalControlApproval($this->device, $this->admin, 'PROBE', 300);
            $this->fail('PROBE should not be allowed in grantPhysicalControlApproval');
        } catch (HardwareSafetyException $e) {
            $this->assertEquals(422, $e->getStatusCode());
        }

        // Token bound to device 1 cannot be used on device 2
        $otherDevice = PhysicalDevice::create([
            'name' => 'Samsung TV Guard Test 2',
            'category' => PhysicalDevice::CATEGORY_SMART_TV,
            'brand' => PhysicalDevice::BRAND_SAMSUNG,
            'model' => '50U8500',
            'mac_address' => '00:11:22:33:44:56',
            'ip_address' => '192.168.1.51',
            'is_active' => true,
        ]);

        $approval = HardwareSafetyGuard::grantPhysicalControlApproval($this->device, $this->admin, 'STANDBY', 300);

        try {
            HardwareSafetyGuard::assertPhysicalControlTestAllowed($otherDevice, 'STANDBY', $this->admin, $approval['approval_token']);
            $this->fail('Mismatched device must be rejected');
        } catch (HardwareSafetyException $e) {
            $this->assertEquals(403, $e->getStatusCode());
        }

        // Expired token must be rejected
        $expiredApproval = HardwareSafetyGuard::grantPhysicalControlApproval($this->device, $this->admin, 'POWER_ON', -5);
        try {
            HardwareSafetyGuard::assertPhysicalControlTestAllowed($this->device, 'POWER_ON', $this->admin, $expiredApproval['approval_token']);
            $this->fail('Expired approval token must be rejected');
        } catch (HardwareSafetyException $e) {
            $this->assertEquals(403, $e->getStatusCode());
        }
    }
}
