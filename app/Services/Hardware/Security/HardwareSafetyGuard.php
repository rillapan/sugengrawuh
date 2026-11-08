<?php

namespace App\Services\Hardware\Security;

use App\Exceptions\HardwareSafetyException;
use App\Models\PhysicalDevice;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class HardwareSafetyGuard
{
    /**
     * Check if real physical hardware execution is permitted.
     * Throws HardwareSafetyException if any safety condition fails.
     *
     * @param PhysicalDevice $device
     * @param string $command
     * @param User|null $user
     * @param bool $isDiagnostic
     * @return void
     * @throws HardwareSafetyException
     */
    public static function assertRealExecutionAllowed(
        PhysicalDevice $device,
        string $command,
        ?User $user = null,
        bool $isDiagnostic = false
    ): void {
        // 1. Active driver must be 'real'
        $activeDriver = config('hardware.driver', 'none');
        if ($activeDriver !== 'real') {
            throw HardwareSafetyException::driverModeNotReal($activeDriver);
        }

        // 2. Real execution master switch must be explicitly enabled
        $realExecutionEnabled = config('hardware.real_execution_enabled', false);
        if (!$realExecutionEnabled) {
            throw HardwareSafetyException::realExecutionDisabled();
        }

        // 3. User authorization check (ADMIN and OWNER only)
        if ($user !== null) {
            if (!$user->isAdmin() && !$user->isOwner()) {
                throw HardwareSafetyException::unauthorizedRole($user->role ?? 'unknown');
            }
        }

        // 4. Device must be active
        if (!$device->is_active) {
            throw new HardwareSafetyException("Perangkat '{$device->name}' berstatus nonaktif. Perintah fisik diblokir.", 409);
        }

        // 5. Approved Device Allowlist check (if allowlist is configured)
        $allowlist = config('hardware.real_allowed_device_ids', []);
        if (!empty($allowlist) && !in_array((string) $device->id, array_map('strval', $allowlist), true)) {
            throw HardwareSafetyException::deviceNotAllowlisted($device->id, $device->name);
        }

        // 6. Capability verification check for non-diagnostic commands
        if (!$isDiagnostic) {
            $verifiedCapabilities = $device->verified_capabilities ?? [];
            if (!in_array(strtoupper($command), array_map('strtoupper', $verifiedCapabilities), true)) {
                throw HardwareSafetyException::capabilityNotVerified($command, (string) $device->model);
            }
        }

        // 7. Audit log physical command attempt
        Log::info('[HARDWARE_SAFETY_GUARD] Physical execution authorized', [
            'device_id' => $device->id,
            'device_name' => $device->name,
            'command' => $command,
            'ip' => $device->ip_address,
            'user_id' => $user ? $user->id : null,
            'is_diagnostic' => $isDiagnostic,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Check boolean status whether real execution is enabled.
     *
     * @return bool
     */
    public static function isRealExecutionEnabled(): bool
    {
        return config('hardware.driver') === 'real'
            && (bool) config('hardware.real_execution_enabled', false);
    }
}
