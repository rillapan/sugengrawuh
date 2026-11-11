<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Playstation extends Model
{
    use HasFactory;

    public const CATEGORY_PLAYSTATION = 'PLAYSTATION';
    public const CATEGORY_BILLIARD = 'BILLIARD';

    protected $guarded = [];

    /**
     * Identify station service category (PLAYSTATION vs BILLIARD).
     * Returns null if unrecognized or ambiguous.
     *
     * @return string|null
     */
    public function getCategory(): ?string
    {
        $name = strtoupper(trim((string) $this->nama));
        if ($name === '') {
            return null;
        }

        $isBilliard = str_contains($name, 'BILLIARD')
            || str_contains($name, 'BILIAR')
            || str_starts_with($name, 'MEJA');

        $isPlaystation = str_contains($name, 'PLAYSTATION')
            || str_contains($name, 'PS5')
            || str_contains($name, 'PS4')
            || str_contains($name, 'PS3')
            || str_contains($name, 'PS 5')
            || str_contains($name, 'PS 4')
            || str_contains($name, 'PS 3');

        if ($isBilliard && !$isPlaystation) {
            return self::CATEGORY_BILLIARD;
        }

        if ($isPlaystation && !$isBilliard) {
            return self::CATEGORY_PLAYSTATION;
        }

        return null;
    }

    public function device()
    {
        return $this->hasMany(Device::class);
    }

    public function hourlyPrices()
    {
        return $this->hasMany(HourlyPrice::class);
    }

    /**
     * Get the price for a specific duration in seconds.
     * Checks exact duration_seconds first, then falls back to whole hours.
     *
     * @param int $totalSeconds
     * @return int|null
     */
    public function getPriceForDuration(int $totalSeconds): ?int
    {
        if ($totalSeconds <= 0) {
            return null;
        }

        $preset = $this->hourlyPrices()
            ->where(function ($query) use ($totalSeconds) {
                $query->where('duration_seconds', $totalSeconds);
                if ($totalSeconds % 3600 === 0) {
                    $query->orWhere('hour', intdiv($totalSeconds, 3600));
                }
            })
            ->first();

        return $preset ? (int) $preset->price : null;
    }

    /**
     * Get the price for a specific hour (legacy method, backwards-compatible).
     *
     * @param int|float|string $hour
     * @return int|null
     */
    public function getPriceForHour($hour)
    {
        if (!is_numeric($hour) || $hour <= 0) {
            return null;
        }

        $totalSeconds = (int) round((float) $hour * 3600);
        return $this->getPriceForDuration($totalSeconds);
    }

    /**
     * Calculate price for given duration in seconds.
     * Business rule SR-BILLING-DURATION-001:
     * 1. If exact preset in hourly_prices exists -> use preset price (no extra rounding).
     * 2. If no preset -> prorate by total seconds, always round UP to nearest Rp 500:
     *    $numerator = $hargaPerJam * $totalDetik;
     *    $denominator = 3600 * 500;
     *    $hargaAkhir = intdiv($numerator + $denominator - 1, $denominator) * 500;
     *
     * @param int $totalSeconds
     * @return int
     */
    public function calculateCustomPrice(int $totalSeconds): int
    {
        if ($totalSeconds <= 0) {
            return 0;
        }

        // 1. Check exact preset first
        $preset = $this->getPriceForDuration($totalSeconds);
        if ($preset !== null) {
            return (int) $preset;
        }

        // 2. Custom manual without preset: Prorate by total seconds + round up to nearest Rp 500
        $baseHourly = (int) ($this->harga ?? 0);
        if ($baseHourly <= 0) {
            return 0;
        }

        $numerator = $baseHourly * $totalSeconds;
        $denominator = 3600 * 500;

        return (int) (intdiv($numerator + $denominator - 1, $denominator) * 500);
    }

    /**
     * Get all structured duration presets with prices for this Playstation.
     *
     * @return array
     */
    public function getAvailableDurationsWithPrices(): array
    {
        $durations = $this->hourlyPrices()
            ->orderByRaw('COALESCE(duration_seconds, hour * 3600) ASC')
            ->get()
            ->map(function (HourlyPrice $p) {
                $sec = $p->effective_duration_seconds;
                return [
                    'id' => $p->id,
                    'duration_seconds' => $sec,
                    'formatted' => HourlyPrice::formatSeconds($sec),
                    'human' => HourlyPrice::humanizeSeconds($sec),
                    'hours' => intdiv($sec, 3600),
                    'price' => (int) $p->price,
                ];
            })
            ->values()
            ->toArray();

        // Fallback: If no custom presets are defined, generate standard 1-5 hour durations from base price
        if (empty($durations) && (int) ($this->harga ?? 0) > 0) {
            $base = (int) $this->harga;
            for ($h = 1; $h <= 5; $h++) {
                $sec = $h * 3600;
                $durations[] = [
                    'id' => null,
                    'duration_seconds' => $sec,
                    'formatted' => HourlyPrice::formatSeconds($sec),
                    'human' => $h . ' jam',
                    'hours' => $h,
                    'price' => $base * $h,
                ];
            }
        }

        return $durations;
    }

    /**
     * Get all available hours with their prices (backwards-compatible dictionary).
     *
     * @return array
     */
    public function getAvailableHoursWithPrices()
    {
        $prices = [];
        $presets = $this->hourlyPrices()
            ->orderByRaw('COALESCE(duration_seconds, hour * 3600) ASC')
            ->get();

        foreach ($presets as $p) {
            $sec = $p->effective_duration_seconds;
            if ($p->hour !== null && $p->hour > 0) {
                $prices[$p->hour] = (int) $p->price;
            } elseif ($sec % 3600 === 0) {
                $prices[intdiv($sec, 3600)] = (int) $p->price;
            } else {
                // For non-integer hour presets (e.g. 1800s / 30 mins)
                $prices[HourlyPrice::formatSeconds($sec)] = (int) $p->price;
            }
        }

        // Fallback: If no custom presets are defined, generate standard 1-5 hour prices from base price
        if (empty($prices) && (int) ($this->harga ?? 0) > 0) {
            $base = (int) $this->harga;
            for ($h = 1; $h <= 5; $h++) {
                $prices[$h] = $base * $h;
            }
        }

        return $prices;
    }

    /**
     * Calculate total price for given hours using custom hourly pricing (legacy method).
     *
     * @param int|float $hours
     * @return int
     */
    public function calculateTotalPrice($hours)
    {
        $totalSeconds = (int) round((float) $hours * 3600);
        return $this->calculateCustomPrice($totalSeconds);
    }
}
