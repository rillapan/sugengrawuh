<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HourlyPrice extends Model
{
    use HasFactory;

    protected $fillable = [
        'playstation_id',
        'hour',
        'duration_seconds',
        'price'
    ];

    protected $casts = [
        'playstation_id' => 'integer',
        'hour' => 'integer',
        'duration_seconds' => 'integer',
        'price' => 'integer',
    ];

    public function playstation()
    {
        return $this->belongsTo(Playstation::class);
    }

    /**
     * Convert hours, minutes, and seconds into total seconds.
     */
    public static function toTotalSeconds(int $hours, int $minutes, int $seconds): int
    {
        return ($hours * 3600) + ($minutes * 60) + $seconds;
    }

    /**
     * Break total seconds down into [hours, minutes, seconds].
     */
    public static function secondsToParts(int $totalSeconds): array
    {
        $totalSeconds = max(0, $totalSeconds);
        $hours = intdiv($totalSeconds, 3600);
        $remainder = $totalSeconds % 3600;
        $minutes = intdiv($remainder, 60);
        $seconds = $remainder % 60;

        return [
            'hours' => $hours,
            'minutes' => $minutes,
            'seconds' => $seconds,
        ];
    }

    /**
     * Format seconds into HH:MM:SS.
     */
    public static function formatSeconds(int $totalSeconds): string
    {
        $parts = self::secondsToParts($totalSeconds);
        return sprintf('%02d:%02d:%02d', $parts['hours'], $parts['minutes'], $parts['seconds']);
    }

    /**
     * Human readable duration in Indonesian.
     * e.g. 5 menit, 30 menit, 1 jam, 1 jam 30 menit, 2 jam 15 menit 30 detik.
     */
    public static function humanizeSeconds(int $totalSeconds): string
    {
        $parts = self::secondsToParts($totalSeconds);
        $h = $parts['hours'];
        $m = $parts['minutes'];
        $s = $parts['seconds'];

        $segments = [];
        if ($h > 0) {
            $segments[] = "{$h} jam";
        }
        if ($m > 0) {
            $segments[] = "{$m} menit";
        }
        if ($s > 0) {
            $segments[] = "{$s} detik";
        }

        return !empty($segments) ? implode(' ', $segments) : '0 detik';
    }

    /**
     * Get effective duration in seconds.
     * Backwards-compatible if duration_seconds is null but hour is present.
     */
    public function getEffectiveDurationSecondsAttribute(): int
    {
        if ($this->duration_seconds !== null && $this->duration_seconds > 0) {
            return (int) $this->duration_seconds;
        }

        if ($this->hour !== null && $this->hour > 0) {
            return (int) ($this->hour * 3600);
        }

        return 0;
    }

    /**
     * Formatted duration HH:MM:SS
     */
    public function getFormattedDurationAttribute(): string
    {
        return self::formatSeconds($this->effective_duration_seconds);
    }

    /**
     * Human duration in Indonesian
     */
    public function getHumanDurationAttribute(): string
    {
        return self::humanizeSeconds($this->effective_duration_seconds);
    }
}
