<?php

namespace App\Services\Hardware;

use App\Models\PhysicalDevice;
use App\Services\Hardware\Security\HardwareSafetyGuard;
use Illuminate\Support\Facades\Log;

class DeviceStatusManagementService
{
    /**
     * Safely update connection status of a device.
     * In G4-B1, fake responses are prevented from mutating database records.
     *
     * @param PhysicalDevice $device
     * @param string $status
     * @param bool $isRealVerified
     * @return bool
     */
    public function updateConnectionStatus(PhysicalDevice $device, string $status, bool $isRealVerified = false): bool
    {
        // G4-B1 Safety Guard: Fake responses must not mutate physical status
        if (!$isRealVerified) {
            Log::info("[STATUS_GUARD] Fake probe result ignored for physical status mutation on device ID {$device->id}");
            return false;
        }

        // Must pass real execution check
        if (!HardwareSafetyGuard::isRealExecutionEnabled()) {
            Log::warning("[STATUS_GUARD] Real execution disabled. Denying status mutation on device ID {$device->id}");
            return false;
        }

        $allowedStatuses = [
            PhysicalDevice::CONNECTION_UNVERIFIED,
            PhysicalDevice::CONNECTION_ONLINE,
            PhysicalDevice::CONNECTION_OFFLINE,
        ];

        if (!in_array($status, $allowedStatuses, true)) {
            return false;
        }

        $device->connection_status = $status;
        $device->last_seen_at = now();
        return $device->save();
    }

    /**
     * Store pairing token securely into pairing_payload and update pairing status.
     *
     * @param PhysicalDevice $device
     * @param string $token
     * @param bool $isRealVerified
     * @return bool
     */
    public function recordSuccessfulPairing(PhysicalDevice $device, string $token, bool $isRealVerified = false): bool
    {
        if (!$isRealVerified) {
            Log::info("[STATUS_GUARD] Fake pairing response ignored for database token persistence on device ID {$device->id}");
            return false;
        }

        if (!HardwareSafetyGuard::isRealExecutionEnabled()) {
            return false;
        }

        // Eloquent 'encrypted' cast automatically handles AES-256-CBC encryption
        $device->pairing_payload = $token;
        $device->pairing_status = PhysicalDevice::PAIRING_PAIRED;
        $device->last_seen_at = now();

        // Populate standard verified capabilities for paired hardware if not already set
        if (empty($device->verified_capabilities)) {
            $device->verified_capabilities = match (true) {
                $device->category === PhysicalDevice::CATEGORY_SMART_TV && $device->brand === PhysicalDevice::BRAND_SAMSUNG => [
                    'GET_STATUS', 'POWER_ON', 'STANDBY', 'MUTE', 'UNMUTE', 'VOLUME_UP', 'VOLUME_DOWN'
                ],
                $device->category === PhysicalDevice::CATEGORY_SMART_RELAY && $device->brand === PhysicalDevice::BRAND_SHELLY => [
                    'SWITCH_ON', 'SWITCH_OFF', 'GET_STATUS'
                ],
                $device->category === PhysicalDevice::CATEGORY_GAME_CONSOLE => [
                    'SHOW_NOTIFICATION'
                ],
                default => $device->verified_capabilities,
            };
        }

        return $device->save();
    }

    /**
     * Record pairing failure.
     *
     * @param PhysicalDevice $device
     * @param bool $isRealVerified
     * @return bool
     */
    public function recordPairingFailure(PhysicalDevice $device, bool $isRealVerified = false): bool
    {
        if (!$isRealVerified) {
            return false;
        }

        if (!HardwareSafetyGuard::isRealExecutionEnabled()) {
            return false;
        }

        $device->pairing_status = PhysicalDevice::PAIRING_FAILED;
        return $device->save();
    }

    /**
     * Add verified capability to hardware.
     * In G4-B1, capabilities CANNOT be verified from fake responses.
     *
     * @param PhysicalDevice $device
     * @param string $capability
     * @param bool $isRealVerified
     * @return bool
     */
    public function addVerifiedCapability(PhysicalDevice $device, string $capability, bool $isRealVerified = false): bool
    {
        if (!$isRealVerified) {
            Log::info("[STATUS_GUARD] Fake test cannot mark capability '{$capability}' as verified on device ID {$device->id}");
            return false;
        }

        if (!HardwareSafetyGuard::isRealExecutionEnabled()) {
            return false;
        }

        $capabilities = $device->verified_capabilities ?? [];
        $upper = strtoupper(trim($capability));

        if (!in_array($upper, $capabilities, true)) {
            $capabilities[] = $upper;
            $device->verified_capabilities = $capabilities;
            return $device->save();
        }

        return true;
    }
}
