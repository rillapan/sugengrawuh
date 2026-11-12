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
     * Gate G2: Pairing stores encrypted token and marks PAIRED, but NEVER auto-populates
     * physical control capabilities (POWER_ON, STANDBY, MUTE, SWITCH_ON, SWITCH_OFF)
     * and NEVER auto-enables background station automation.
     *
     * @param PhysicalDevice $device
     * @param string $token
     * @param bool $isRealVerified
     * @param bool $isAuthorizedPairing
     * @return bool
     */
    public function recordSuccessfulPairing(
        PhysicalDevice $device,
        string $token,
        bool $isRealVerified = false,
        bool $isAuthorizedPairing = false
    ): bool {
        if (!$isAuthorizedPairing && !$isRealVerified) {
            Log::info("[STATUS_GUARD] Fake pairing response ignored for database token persistence on device ID {$device->id}");
            return false;
        }

        if (!$isAuthorizedPairing && !HardwareSafetyGuard::isRealExecutionEnabled()) {
            return false;
        }

        // Eloquent 'encrypted' cast automatically handles AES-256-CBC encryption
        $device->pairing_payload = $token;
        $device->pairing_status = PhysicalDevice::PAIRING_PAIRED;
        $device->last_seen_at = now();

        $saved = $device->save();

        Log::info('[HARDWARE_PAIRING_AUDIT] Pairing succeeded', [
            'device_id' => $device->id,
            'device_name' => $device->name,
            'brand' => $device->brand,
            'ip' => $device->ip_address,
            'is_authorized_pairing' => $isAuthorizedPairing,
            'is_real_verified' => $isRealVerified,
        ]);

        return $saved;
    }

    /**
     * Record pairing failure.
     *
     * @param PhysicalDevice $device
     * @param string|bool $reasonOrRealVerified
     * @param bool $isRealVerified
     * @param bool $isAuthorizedPairing
     * @return bool
     */
    public function recordPairingFailure(
        PhysicalDevice $device,
        $reasonOrRealVerified = false,
        bool $isRealVerified = false,
        bool $isAuthorizedPairing = false
    ): bool {
        if (is_bool($reasonOrRealVerified)) {
            $reason = 'Pairing rejected or failed';
            $realVerifiedFlag = $reasonOrRealVerified;
            $authorizedFlag = $isRealVerified;
        } else {
            $reason = (string) $reasonOrRealVerified;
            $realVerifiedFlag = $isRealVerified;
            $authorizedFlag = $isAuthorizedPairing;
        }

        if (!$authorizedFlag && !$realVerifiedFlag) {
            return false;
        }

        if (!$authorizedFlag && !HardwareSafetyGuard::isRealExecutionEnabled()) {
            return false;
        }

        $device->pairing_status = PhysicalDevice::PAIRING_FAILED;
        $saved = $device->save();

        Log::warning('[HARDWARE_PAIRING_AUDIT] Pairing failed', [
            'device_id' => $device->id,
            'device_name' => $device->name,
            'brand' => $device->brand,
            'ip' => $device->ip_address,
            'reason' => $reason,
            'is_authorized_pairing' => $authorizedFlag,
            'is_real_verified' => $realVerifiedFlag,
        ]);

        return $saved;
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
