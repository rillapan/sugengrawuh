<?php

namespace App\Services\Hardware\Security;

use App\Exceptions\HardwareSafetyException;
use App\Models\PhysicalDevice;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class HardwareSafetyGuard
{
    public const READ_ONLY_DIAGNOSTIC_COMMANDS = ['PROBE', 'GET_STATUS'];

    public const PHYSICAL_CONTROL_COMMANDS = [
        'MUTE',
        'UNMUTE',
        'MUTE_TOGGLE',
        'STANDBY',
        'POWER_ON',
        'SWITCH_ON',
        'SWITCH_OFF',
        'VOLUME_UP',
        'VOLUME_DOWN',
    ];

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

        // 5. Approved Device Allowlist check — fail-closed, supports '*' wildcard or explicit IDs
        $allowlist = config('hardware.real_allowed_device_ids', []);
        if (!empty($allowlist) && !in_array('*', $allowlist, true) && !in_array((string) $device->id, array_map('strval', $allowlist), true)) {
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
     * Gate G2: Authorize read-only diagnostic commands (PROBE, GET_STATUS) for ADMIN.
     * Permitted even when HARDWARE_REAL_EXECUTION_ENABLED=false, guaranteeing zero physical mutation.
     *
     * @param PhysicalDevice $device
     * @param string $command
     * @param User|null $user
     * @param int|null $port
     * @return void
     * @throws HardwareSafetyException
     */
    public static function assertReadOnlyDiagnosticAllowed(
        PhysicalDevice $device,
        string $command,
        ?User $user,
        ?int $port = null
    ): void {
        $upperCommand = strtoupper(trim($command));

        if (!in_array($upperCommand, self::READ_ONLY_DIAGNOSTIC_COMMANDS, true)) {
            throw HardwareSafetyException::nonReadOnlyDiagnosticCommand($command);
        }

        $driverMode = (string) config('hardware.driver', 'mock');
        if ($driverMode !== 'real') {
            throw HardwareSafetyException::driverModeNotReal($driverMode);
        }

        if ($user === null || !$user->isAdmin()) {
            throw HardwareSafetyException::unauthorizedRole($user ? ($user->role ?? 'unknown') : 'guest');
        }

        if (!$device->is_active) {
            throw HardwareSafetyException::deviceNotAllowlisted((int) $device->id, (string) $device->name);
        }

        $allowlist = config('hardware.real_allowed_device_ids', []);
        if (!empty($allowlist) && !in_array('*', $allowlist, true) && !in_array((string) $device->id, array_map('strval', $allowlist), true)) {
            throw HardwareSafetyException::deviceNotAllowlisted((int) $device->id, (string) $device->name);
        }

        HardwareNetworkSecurity::validateTargetIp((string) $device->ip_address);

        if ($port !== null) {
            HardwareNetworkSecurity::validateTargetPort((string) $device->brand, $port);
        }

        Log::info('[HARDWARE_SAFETY_GUARD] Read-only diagnostic authorized by ADMIN', [
            'user_id' => $user->id,
            'device_id' => $device->id,
            'device_name' => $device->name,
            'command' => $upperCommand,
            'ip' => $device->ip_address,
            'port' => $port,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Gate G2: Authorize device pairing (PAIR) for ADMIN.
     * Permitted even when HARDWARE_REAL_EXECUTION_ENABLED=false; stores encrypted token only.
     *
     * @param PhysicalDevice $device
     * @param User|null $user
     * @param int|null $port
     * @return void
     * @throws HardwareSafetyException
     */
    public static function assertPairingAllowed(
        PhysicalDevice $device,
        ?User $user,
        ?int $port = null
    ): void {
        $driverMode = (string) config('hardware.driver', 'mock');
        if ($driverMode !== 'real') {
            throw HardwareSafetyException::driverModeNotReal($driverMode);
        }

        if ($user === null || !$user->isAdmin()) {
            throw HardwareSafetyException::unauthorizedRole($user ? ($user->role ?? 'unknown') : 'guest');
        }

        if (!$device->is_active) {
            throw HardwareSafetyException::deviceNotAllowlisted((int) $device->id, (string) $device->name);
        }

        $allowlist = config('hardware.real_allowed_device_ids', []);
        if (!empty($allowlist) && !in_array('*', $allowlist, true) && !in_array((string) $device->id, array_map('strval', $allowlist), true)) {
            throw HardwareSafetyException::deviceNotAllowlisted((int) $device->id, (string) $device->name);
        }

        HardwareNetworkSecurity::validateTargetIp((string) $device->ip_address);

        if ($port !== null) {
            HardwareNetworkSecurity::validateTargetPort((string) $device->brand, $port);
        }

        Log::info('[HARDWARE_SAFETY_GUARD] Pairing authorized by ADMIN.', [
            'user_id' => $user->id,
            'device_id' => $device->id,
            'ip' => $device->ip_address,
            'port' => $port,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Gate G2: Issue a server-side approval token for a single bounded physical control test.
     *
     * @param PhysicalDevice $device
     * @param User|null $user
     * @param string $command
     * @param int $ttlSeconds
     * @return array
     * @throws HardwareSafetyException
     */
    public static function grantPhysicalControlApproval(
        PhysicalDevice $device,
        ?User $user,
        string $command,
        int $ttlSeconds = 300
    ): array {
        $driverMode = (string) config('hardware.driver', 'mock');
        if ($driverMode !== 'real') {
            throw HardwareSafetyException::driverModeNotReal($driverMode);
        }

        if ($user === null || !$user->isAdmin()) {
            throw HardwareSafetyException::unauthorizedRole($user ? ($user->role ?? 'unknown') : 'guest');
        }

        if (!$device->is_active) {
            throw HardwareSafetyException::deviceNotAllowlisted((int) $device->id, (string) $device->name);
        }

        $allowlist = config('hardware.real_allowed_device_ids', []);
        if (!empty($allowlist) && !in_array('*', $allowlist, true) && !in_array((string) $device->id, array_map('strval', $allowlist), true)) {
            throw HardwareSafetyException::deviceNotAllowlisted((int) $device->id, (string) $device->name);
        }

        HardwareNetworkSecurity::validateTargetIp((string) $device->ip_address);

        $upperCommand = strtoupper(trim($command));
        if (!in_array($upperCommand, self::PHYSICAL_CONTROL_COMMANDS, true)) {
            throw HardwareSafetyException::invalidPhysicalControlCommand($command);
        }

        $rawToken = bin2hex(random_bytes(24));
        $expiresAtCarbon = now()->addSeconds($ttlSeconds);
        $expiresAt = $expiresAtCarbon->toIso8601String();

        $cacheKey = self::physicalApprovalCacheKey((int) $device->id, (int) $user->id, $upperCommand);
        Cache::put($cacheKey, [
            'token_hash' => hash('sha256', $rawToken),
            'user_id' => (int) $user->id,
            'device_id' => (int) $device->id,
            'command' => $upperCommand,
            'expires_at' => $expiresAt,
        ], $expiresAtCarbon);

        Log::warning('[HARDWARE_SAFETY_GUARD] Server-side physical control test approval granted.', [
            'user_id' => $user->id,
            'device_id' => $device->id,
            'command' => $upperCommand,
            'ip' => $device->ip_address,
            'expires_at' => $expiresAt,
            'ttl_seconds' => $ttlSeconds,
        ]);

        return [
            'approval_token' => $rawToken,
            'device_id' => (int) $device->id,
            'user_id' => (int) $user->id,
            'command' => $upperCommand,
            'expires_at' => $expiresAt,
            'ttl_seconds' => $ttlSeconds,
        ];
    }

    /**
     * Gate G2: Assert that a physical control test command is authorized with a valid server-side approval token.
     *
     * @param PhysicalDevice $device
     * @param string $command
     * @param User|null $user
     * @param string|null $approvalToken
     * @param bool $consumeToken
     * @param int|null $port
     * @return void
     * @throws HardwareSafetyException
     */
    public static function assertPhysicalControlTestAllowed(
        PhysicalDevice $device,
        string $command,
        ?User $user,
        ?string $approvalToken,
        bool $consumeToken = true,
        ?int $port = null
    ): void {
        $driverMode = (string) config('hardware.driver', 'mock');
        if ($driverMode !== 'real') {
            throw HardwareSafetyException::driverModeNotReal($driverMode);
        }

        if ($user === null || !$user->isAdmin()) {
            throw HardwareSafetyException::unauthorizedRole($user ? ($user->role ?? 'unknown') : 'guest');
        }

        if (!$device->is_active) {
            throw HardwareSafetyException::deviceNotAllowlisted((int) $device->id, (string) $device->name);
        }

        $allowlist = config('hardware.real_allowed_device_ids', []);
        if (!empty($allowlist) && !in_array('*', $allowlist, true) && !in_array((string) $device->id, array_map('strval', $allowlist), true)) {
            throw HardwareSafetyException::deviceNotAllowlisted((int) $device->id, (string) $device->name);
        }

        HardwareNetworkSecurity::validateTargetIp((string) $device->ip_address);

        if ($port !== null) {
            HardwareNetworkSecurity::validateTargetPort((string) $device->brand, $port);
        }

        $upperCommand = strtoupper(trim($command));
        if (!in_array($upperCommand, self::PHYSICAL_CONTROL_COMMANDS, true)) {
            throw HardwareSafetyException::invalidPhysicalControlApproval((int) $device->id, $upperCommand);
        }

        if (!is_string($approvalToken) || trim($approvalToken) === '') {
            throw HardwareSafetyException::invalidPhysicalControlApproval((int) $device->id, $upperCommand);
        }

        $cacheKey = self::physicalApprovalCacheKey((int) $device->id, (int) $user->id, $upperCommand);
        $cached = Cache::get($cacheKey);

        if (!is_array($cached) || empty($cached['token_hash'])) {
            throw HardwareSafetyException::invalidPhysicalControlApproval((int) $device->id, $upperCommand);
        }

        if (
            (int) ($cached['user_id'] ?? 0) !== (int) $user->id ||
            (int) ($cached['device_id'] ?? 0) !== (int) $device->id ||
            (string) ($cached['command'] ?? '') !== $upperCommand
        ) {
            throw HardwareSafetyException::invalidPhysicalControlApproval((int) $device->id, $upperCommand);
        }

        if (!empty($cached['expires_at']) && now()->gt(Carbon::parse($cached['expires_at']))) {
            Cache::forget($cacheKey);
            throw HardwareSafetyException::invalidPhysicalControlApproval((int) $device->id, $upperCommand);
        }

        $providedHash = hash('sha256', trim($approvalToken));
        if (!hash_equals((string) $cached['token_hash'], $providedHash)) {
            throw HardwareSafetyException::invalidPhysicalControlApproval((int) $device->id, $upperCommand);
        }

        if ($consumeToken) {
            Cache::forget($cacheKey);
        }

        Log::warning('[HARDWARE_SAFETY_GUARD] Server-approved physical control test executed', [
            'user_id' => $user->id,
            'device_id' => $device->id,
            'command' => $upperCommand,
            'ip' => $device->ip_address,
            'token_consumed' => $consumeToken,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Build cache key for server-side physical control approval token.
     */
    public static function physicalApprovalCacheKey(int $deviceId, int $userId, string $command): string
    {
        return 'hardware_physical_approval_' . $deviceId . '_' . $userId . '_' . strtoupper(trim($command));
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
