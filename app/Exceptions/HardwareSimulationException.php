<?php

namespace App\Exceptions;

use Exception;

class HardwareSimulationException extends Exception
{
    protected int $statusCode;

    public function __construct(string $message, int $statusCode = 400)
    {
        parent::__construct($message, $statusCode);
        $this->statusCode = $statusCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public static function simulationDisabled(): self
    {
        return new self("Hardware Simulation dinonaktifkan pada konfigurasi sistem.", 403);
    }

    public static function productionDatabaseGuard(string $dbName): self
    {
        return new self("AKSI DIBLOKIR: Hardware Simulator dilarang dijalankan pada database produksi '{$dbName}'. Hanya diizinkan pada database pengujian terisolasi.", 403);
    }

    public static function environmentGuard(string $env): self
    {
        return new self("AKSI DIBLOKIR: Hardware Simulator tidak diizinkan pada environment '{$env}'.", 403);
    }

    public static function unsupportedCommand(string $command, string $deviceType): self
    {
        return new self("Perintah '{$command}' tidak didukung oleh perangkat virtual berjenis '{$deviceType}'.", 422);
    }

    public static function incompatibleDevice(string $expected, string $actual): self
    {
        return new self("Tipe perangkat tidak sesuai. Driver mengharapkan '{$expected}', namun menerima '{$actual}'.", 422);
    }

    public static function deviceInactive(string $deviceName): self
    {
        return new self("Perangkat '{$deviceName}' berstatus nonaktif (is_active = false). Simulasi perintah diblokir.", 409);
    }

    public static function invalidScenario(string $scenario): self
    {
        return new self("Skenario simulasi '{$scenario}' tidak valid. Pilihan: SUCCESS, OFFLINE, TIMEOUT, REJECTED, RECOVERED.", 422);
    }

    public static function deviceNotFound(int $id): self
    {
        return new self("Perangkat fisik dengan ID {$id} tidak ditemukan.", 404);
    }
}
