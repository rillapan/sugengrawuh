<?php

namespace Tests\Feature\Hardware;

use App\Models\PhysicalDevice;
use App\Services\Hardware\Automation\AutomationCapabilityPolicy;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class AutomationCapabilityPolicyTest extends TestCase
{
    private AutomationCapabilityPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new AutomationCapabilityPolicy();
        Config::set('hardware.driver', 'mock');
    }

    private function shellyRelay(bool $active = true): PhysicalDevice
    {
        return new PhysicalDevice([
            'name' => 'Test Shelly',
            'category' => PhysicalDevice::CATEGORY_SMART_RELAY,
            'brand' => PhysicalDevice::BRAND_SHELLY,
            'is_active' => $active,
            'verified_capabilities' => [],
        ]);
    }

    private function samsungTv(bool $active = true, array $capabilities = []): PhysicalDevice
    {
        return new PhysicalDevice([
            'name' => 'Test Samsung TV',
            'category' => PhysicalDevice::CATEGORY_SMART_TV,
            'brand' => PhysicalDevice::BRAND_SAMSUNG,
            'is_active' => $active,
            'verified_capabilities' => $capabilities,
        ]);
    }

    // Case 1: Shelly relay ON — existing behavior unchanged
    public function test_shelly_relay_allows_on(): void
    {
        $result = $this->policy->isCommandAllowed($this->shellyRelay(), 'ON');
        $this->assertTrue($result['allowed']);
        $this->assertSame(AutomationCapabilityPolicy::RESULT_ALLOWED, $result['result_code']);
    }

    // Case 2: Samsung TV ON in mock mode → ALLOWED
    public function test_samsung_tv_on_allowed_in_mock_mode(): void
    {
        Config::set('hardware.driver', 'mock');
        $result = $this->policy->isCommandAllowed($this->samsungTv(), 'ON');
        $this->assertTrue($result['allowed']);
        $this->assertSame(AutomationCapabilityPolicy::RESULT_ALLOWED, $result['result_code']);
    }

    // Case 3: Samsung TV OFF in mock mode → ALLOWED
    public function test_samsung_tv_off_allowed_in_mock_mode(): void
    {
        Config::set('hardware.driver', 'mock');
        $result = $this->policy->isCommandAllowed($this->samsungTv(), 'OFF');
        $this->assertTrue($result['allowed']);
        $this->assertSame(AutomationCapabilityPolicy::RESULT_ALLOWED, $result['result_code']);
    }

    // Case 4: Samsung TV ON in real mode with POWER_ON capability → ALLOWED
    public function test_samsung_tv_on_allowed_in_real_mode_with_power_on_capability(): void
    {
        Config::set('hardware.driver', 'real');
        $result = $this->policy->isCommandAllowed($this->samsungTv(true, ['POWER_ON', 'STANDBY']), 'ON');
        $this->assertTrue($result['allowed']);
        $this->assertSame(AutomationCapabilityPolicy::RESULT_ALLOWED, $result['result_code']);
    }

    // Case 5: Samsung TV OFF in real mode with STANDBY capability → ALLOWED
    public function test_samsung_tv_off_allowed_in_real_mode_with_standby_capability(): void
    {
        Config::set('hardware.driver', 'real');
        $result = $this->policy->isCommandAllowed($this->samsungTv(true, ['POWER_ON', 'STANDBY']), 'OFF');
        $this->assertTrue($result['allowed']);
        $this->assertSame(AutomationCapabilityPolicy::RESULT_ALLOWED, $result['result_code']);
    }

    // Case 6: Samsung TV ON in real mode without POWER_ON → SKIPPED_UNVERIFIED
    public function test_samsung_tv_on_skipped_in_real_mode_without_power_on_capability(): void
    {
        Config::set('hardware.driver', 'real');
        $result = $this->policy->isCommandAllowed($this->samsungTv(true, ['STANDBY']), 'ON');
        $this->assertFalse($result['allowed']);
        $this->assertSame(AutomationCapabilityPolicy::RESULT_SKIPPED_UNVERIFIED, $result['result_code']);
    }

    // Case 7: Samsung TV OFF in real mode without STANDBY → SKIPPED_UNVERIFIED
    public function test_samsung_tv_off_skipped_in_real_mode_without_standby_capability(): void
    {
        Config::set('hardware.driver', 'real');
        $result = $this->policy->isCommandAllowed($this->samsungTv(true, ['POWER_ON']), 'OFF');
        $this->assertFalse($result['allowed']);
        $this->assertSame(AutomationCapabilityPolicy::RESULT_SKIPPED_UNVERIFIED, $result['result_code']);
    }

    // Case 8: Null device → INCOMPATIBLE_DEVICE_CATEGORY
    public function test_null_device_returns_incompatible(): void
    {
        $result = $this->policy->isCommandAllowed(null, 'ON');
        $this->assertFalse($result['allowed']);
        $this->assertSame(AutomationCapabilityPolicy::RESULT_INCOMPATIBLE, $result['result_code']);
    }

    // Case 9: Inactive device → DEVICE_INACTIVE
    public function test_inactive_device_returns_device_inactive(): void
    {
        $result = $this->policy->isCommandAllowed($this->samsungTv(false), 'ON');
        $this->assertFalse($result['allowed']);
        $this->assertSame(AutomationCapabilityPolicy::RESULT_DEVICE_INACTIVE, $result['result_code']);
    }
}
