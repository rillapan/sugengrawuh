<?php

namespace App\Exceptions;

use Exception;
use Throwable;

class TvControlException extends Exception
{
    protected int $statusCode;
    protected string $errorCode;

    public function __construct(
        string $message,
        int $statusCode = 422,
        ?Throwable $previous = null,
        string $errorCode = 'TV_CONTROL_ERROR'
    ) {
        parent::__construct($message, $statusCode, $previous);
        $this->statusCode = $statusCode;
        $this->errorCode = $errorCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public static function forbidden(string $message = 'Akses ditolak. Hanya ADMIN yang diizinkan mengirim kontrol Samsung TV.'): self
    {
        return new self($message, 403, null, 'FORBIDDEN');
    }

    public static function invalidCategory(string $stationName): self
    {
        return new self(
            "Stasiun '{$stationName}' bukan kategori PlayStation. Kontrol Samsung TV hanya diizinkan untuk stasiun PlayStation.",
            422,
            null,
            'INVALID_STATION_CATEGORY'
        );
    }

    public static function stationAvailable(string $stationName): self
    {
        return new self(
            "Stasiun '{$stationName}' sedang Tersedia (belum digunakan pelanggan). Kontrol manual TV ditolak.",
            409,
            null,
            'STATION_AVAILABLE'
        );
    }

    public static function noActiveSession(string $stationName): self
    {
        return new self(
            "Tidak ada sesi billing aktif yang valid pada stasiun '{$stationName}'.",
            409,
            null,
            'NO_ACTIVE_SESSION'
        );
    }

    public static function noActiveTvMapping(string $stationName): self
    {
        return new self(
            "Stasiun '{$stationName}' belum memiliki mapping Samsung Smart TV aktif.",
            422,
            null,
            'NO_ACTIVE_TV_MAPPING'
        );
    }

    public static function invalidInput(string $message): self
    {
        return new self($message, 422, null, 'INVALID_INPUT');
    }

    public static function conflict(string $message): self
    {
        return new self($message, 409, null, 'CONFLICT');
    }

    public static function rateLimited(string $stationName): self
    {
        return new self(
            "Terlalu banyak perintah volume dalam waktu singkat pada stasiun '{$stationName}'. Mohon tunggu sejenak.",
            429,
            null,
            'RATE_LIMITED'
        );
    }
}

