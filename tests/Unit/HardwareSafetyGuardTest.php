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
}
