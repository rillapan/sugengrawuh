<?php

namespace App\Exceptions;

use Exception;

class HardwareSafetyException extends Exception
{
    protected int $statusCode;

    public function __construct(string $message, int $statusCode = 403)
    {
        parent::__construct($message, $statusCode);
        $this->statusCode = $statusCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public static function realExecutionDisabled(): self
    {
        return new self("SAFETY GUARD ACTIVE: Eksekusi jaringan ke hardware fisik dinonaktifkan (HARDWARE_REAL_EXECUTION_ENABLED = false). Perangkat fisik belum diverifikasi (G4-B1).", 403);
    }

    public static function driverModeNotReal(string $mode): self
    {
        return new self("SAFETY GUARD: Driver hardware aktif saat ini adalah '{$mode}'. Mode 'real' harus diaktifkan secara eksplisit.", 403);
    }

    public static function deviceNotAllowlisted(int $deviceId, string $deviceName): self
    {
        return new self("SAFETY GUARD: Perangkat '{$deviceName}' (ID: {$deviceId}) tidak terdaftar dalam allowlist perangkat fisik yang disetujui.", 403);
    }

    public static function unauthorizedRole(string $role): self
    {
        return new self("SAFETY GUARD: Pengguna dengan role '{$role}' tidak berwenang mengeksekusi kontrol hardware fisik. Hanya ADMIN yang diizinkan.", 403);
    }

    public static function invalidIpTarget(string $ip, string $reason): self
    {
        return new self("NETWORK SECURITY VIOLATION: Alamat IP target '{$ip}' ditolak: {$reason}. SSRF prevention active.", 422);
    }

    public static function portNotAllowed(string $brand, int $port): self
    {
        return new self("NETWORK SECURITY VIOLATION: Port {$port} tidak diizinkan untuk perangkat merek {$brand}.", 422);
    }

    public static function dangerousRelayAction(string $message): self
    {
        return new self("HARDWARE SAFETY VIOLATION: {$message}", 422);
    }

    public static function capabilityNotVerified(string $command, string $model): self
    {
        return new self("CAPABILITY UNVERIFIED: Perintah '{$command}' belum terverifikasi untuk model perangkat '{$model}'.", 422);
    }
}
