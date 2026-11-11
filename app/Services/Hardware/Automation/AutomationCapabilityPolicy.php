<?php

namespace App\Services\Hardware\Automation;

use App\Models\PhysicalDevice;
use Illuminate\Support\Facades\Config;

class AutomationCapabilityPolicy
{
    public const RESULT_ALLOWED = 'ALLOWED';
    public const RESULT_SKIPPED_UNVERIFIED = 'SKIPPED_UNVERIFIED_CAPABILITY';
    public const RESULT_INCOMPATIBLE = 'INCOMPATIBLE_DEVICE_CATEGORY';
    public const RESULT_DEVICE_INACTIVE = 'DEVICE_INACTIVE';

    /**
     * Verify whether an automation command is permitted for a physical device.
     *
     * @param PhysicalDevice|null $device
     * @param string $desiredState
     * @return array [bool $allowed, string $result_code, ?string $reason]
     */
    public function isCommandAllowed(?PhysicalDevice $device, string $desiredState): array
    {
        if (!$device) {
            return [
                'allowed' => false,
                'result_code' => self::RESULT_INCOMPATIBLE,
                'reason' => 'Physical device not resolved.',
            ];
        }

        if (!$device->is_active) {
            return [
                'allowed' => false,
                'result_code' => self::RESULT_DEVICE_INACTIVE,
                'reason' => "Physical device '{$device->name}' is marked inactive.",
            ];
        }

        // Shelly Smart Relay
        if ($device->category === PhysicalDevice::CATEGORY_SMART_RELAY && $device->brand === PhysicalDevice::BRAND_SHELLY) {
            if (in_array(strtoupper($desiredState), ['ON', 'OFF', 'GET_STATUS'], true)) {
                return [
                    'allowed' => true,
                    'result_code' => self::RESULT_ALLOWED,
                    'reason' => null,
                ];
            }

            return [
                'allowed' => false,
                'result_code' => self::RESULT_INCOMPATIBLE,
                'reason' => "Unsupported command '{$desiredState}' for Shelly Smart Relay.",
            ];
        }

        // Samsung Smart TV
        if ($device->category === PhysicalDevice::CATEGORY_SMART_TV && $device->brand === PhysicalDevice::BRAND_SAMSUNG) {
            if (Config::get('hardware.driver', 'real') === 'mock') {
                return ['allowed' => true, 'result_code' => self::RESULT_ALLOWED, 'reason' => null];
            }
            $requiredCapability = (strtoupper($desiredState) === 'ON') ? 'POWER_ON' : 'STANDBY';
            $hasCapability = in_array($requiredCapability, (array) ($device->verified_capabilities ?? []), true);
            if (!$hasCapability) {
                return [
                    'allowed' => false,
                    'result_code' => self::RESULT_SKIPPED_UNVERIFIED,
                    'reason' => "Samsung TV capability '{$requiredCapability}' not verified.",
                ];
            }
            return ['allowed' => true, 'result_code' => self::RESULT_ALLOWED, 'reason' => null];
        }

        // Game Console — notification-capable devices (Ps4GoldHenDriver, Ps3WebmanDriver)
        if ($device->category === PhysicalDevice::CATEGORY_GAME_CONSOLE) {
            $hasNotification = in_array('SHOW_NOTIFICATION', (array) ($device->verified_capabilities ?? []), true);
            if (!$hasNotification) {
                return [
                    'allowed' => false,
                    'result_code' => self::RESULT_SKIPPED_UNVERIFIED,
                    'reason' => "Game console 'SHOW_NOTIFICATION' capability not verified.",
                ];
            }
            return ['allowed' => true, 'result_code' => self::RESULT_ALLOWED, 'reason' => null];
        }

        return [
            'allowed' => false,
            'result_code' => self::RESULT_INCOMPATIBLE,
            'reason' => "Device category '{$device->category}' ({$device->brand}) is not supported for automation.",
        ];
    }
}
