<?php

namespace App\Exceptions;

use Exception;
use Throwable;

class StationMappingException extends Exception
{
    protected int $statusCode;

    public function __construct(string $message = "", int $statusCode = 422, ?Throwable $previous = null)
    {
        $this->statusCode = $statusCode;
        parent::__construct($message, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public static function stationInUse(string $stationName): self
    {
        return new self("Stasiun '{$stationName}' sedang digunakan kasir. Tidak dapat mengubah mapping saat stasiun tidak tersedia.", 409);
    }

    public static function activeBillingRunning(string $stationName): self
    {
        return new self("Stasiun '{$stationName}' memiliki sesi billing aktif (RUNNING). Selesaikan transaksi sebelum mengubah mapping hardware.", 409);
    }

    public static function hardwareAlreadyMapped(string $deviceName, ?string $stationName = null): self
    {
        $target = $stationName ? " ke stasiun '{$stationName}'" : "";
        return new self("Hardware fisik '{$deviceName}' sudah memiliki pemetaan aktif{$target}. Satu hardware hanya boleh dipetakan aktif ke satu stasiun.", 409);
    }

    public static function activeRoleAlreadyMapped(string $stationName, string $role): self
    {
        return new self("Stasiun '{$stationName}' sudah memiliki hardware aktif untuk peran '{$role}'. Gunakan operasi replace untuk mengganti hardware.", 409);
    }

    public static function cannotDeactivateMappedHardware(string $deviceName): self
    {
        return new self("Tidak dapat menonaktifkan hardware '{$deviceName}' karena masih memiliki pemetaan aktif pada stasiun. Lepaskan mapping terlebih dahulu.", 409);
    }

    public static function ipConflict(string $ip, ?string $existingDeviceName = null): self
    {
        $suffix = $existingDeviceName ? " oleh perangkat aktif '{$existingDeviceName}'" : "";
        return new self("Konflik IP Address: Alamat IP '{$ip}' sudah digunakan{$suffix}.", 409);
    }

    public static function duplicateMac(string $mac): self
    {
        return new self("MAC Address '{$mac}' sudah terdaftar dalam sistem.", 409);
    }

    public static function incompatibleHardware(string $message): self
    {
        return new self($message, 422);
    }

    public static function unknownStationCategory(string $stationName): self
    {
        return new self("Kategori stasiun '{$stationName}' tidak dikenali atau ambigu. Hanya stasiun PlayStation (Smart TV) dan Biliar (Smart Relay) yang dapat dipetakan.", 422);
    }

    public static function notFound(string $message): self
    {
        return new self($message, 404);
    }

    public static function invalidData(string $message): self
    {
        return new self($message, 422);
    }
}
