<?php

namespace App\Services\Finance;

use App\Models\User;
use App\Models\WorkShift;
use Carbon\Carbon;

class ShiftPeriodResolver
{
    public const TIMEZONE = 'Asia/Jakarta';

    /**
     * Resolve shift period for a user at a given reference time.
     *
     * @param User|null $user
     * @param Carbon|string|null $referenceTime
     * @return array
     */
    public function resolveForUser(?User $user, $referenceTime = null): array
    {
        if (!$user || !$user->work_shift_id) {
            return $this->resolveFallbackPeriod($referenceTime);
        }

        $shift = $user->workShift ?? WorkShift::find($user->work_shift_id);
        if (!$shift) {
            return $this->resolveFallbackPeriod($referenceTime);
        }

        return $this->resolve($shift, $referenceTime);
    }

    /**
     * Resolve shift period for a WorkShift at a given reference time.
     *
     * @param WorkShift $shift
     * @param Carbon|string|null $referenceTime
     * @return array
     */
    public function resolve(WorkShift $shift, $referenceTime = null): array
    {
        $now = $this->parseTime($referenceTime);

        $startStr = $this->extractTime($shift->jam_mulai);
        $endStr = $this->extractTime($shift->jam_selesai);

        $isOvernight = ($startStr >= $endStr);
        $currentTimeStr = $now->format('H:i:s');

        if ($isOvernight) {
            // Overnight shift (e.g., 19:00:00 to 03:00:00 next day)
            if ($currentTimeStr >= $startStr) {
                // Evening part of active shift (e.g. 20:30 on 2026-11-21)
                $operationalDate = $now->format('Y-m-d');
                $start = $now->copy()->setTimeFromTimeString($startStr);
                $end = $now->copy()->addDay()->setTimeFromTimeString($endStr);
                $isActive = true;
            } elseif ($currentTimeStr < $endStr) {
                // Post-midnight part of active shift (e.g. 01:30 on 2026-11-22)
                $operationalDate = $now->copy()->subDay()->format('Y-m-d');
                $start = $now->copy()->subDay()->setTimeFromTimeString($startStr);
                $end = $now->copy()->setTimeFromTimeString($endStr);
                $isActive = true;
            } else {
                // Outside shift hours (e.g. 03:00:00 to 18:59:59 on 2026-11-22)
                // Shift has already ended; report the last completed shift
                $operationalDate = $now->copy()->subDay()->format('Y-m-d');
                $start = $now->copy()->subDay()->setTimeFromTimeString($startStr);
                $end = $now->copy()->setTimeFromTimeString($endStr);
                $isActive = false;
            }
        } else {
            // Regular shift (e.g., 06:00:00 to 14:00:00)
            if ($currentTimeStr >= $startStr && $currentTimeStr < $endStr) {
                // Currently active
                $operationalDate = $now->format('Y-m-d');
                $start = $now->copy()->setTimeFromTimeString($startStr);
                $end = $now->copy()->setTimeFromTimeString($endStr);
                $isActive = true;
            } elseif ($currentTimeStr >= $endStr) {
                // Completed today
                $operationalDate = $now->format('Y-m-d');
                $start = $now->copy()->setTimeFromTimeString($startStr);
                $end = $now->copy()->setTimeFromTimeString($endStr);
                $isActive = false;
            } else {
                // Shift hasn't started yet today (e.g. 04:00 before 06:00)
                // Last completed shift was yesterday's
                $operationalDate = $now->copy()->subDay()->format('Y-m-d');
                $start = $now->copy()->subDay()->setTimeFromTimeString($startStr);
                $end = $now->copy()->subDay()->setTimeFromTimeString($endStr);
                $isActive = false;
            }
        }

        return [
            'shift_id' => $shift->id,
            'shift_name' => $shift->nama_shift,
            'operational_date' => $operationalDate,
            'starts_at' => $start->format('Y-m-d H:i:s'),
            'ends_at' => $end->format('Y-m-d H:i:s'),
            'timezone' => self::TIMEZONE,
            'is_active' => $isActive,
            'has_shift' => true,
            'is_overnight' => $isOvernight,
            'start_carbon' => $start,
            'end_carbon' => $end,
            'status_label' => $isActive ? 'Shift Aktif' : 'Shift Terakhir',
            'jam_mulai' => $startStr,
            'jam_selesai' => $endStr,
        ];
    }

    /**
     * Resolve shift for an explicit operational date.
     *
     * @param WorkShift $shift
     * @param Carbon|string $operationalDate
     * @param Carbon|string|null $currentTime
     * @return array
     */
    public function resolveForDate(WorkShift $shift, $operationalDate, $currentTime = null): array
    {
        $date = $this->parseTime($operationalDate)->startOfDay();
        $now = $this->parseTime($currentTime);

        $startStr = $this->extractTime($shift->jam_mulai);
        $endStr = $this->extractTime($shift->jam_selesai);

        $isOvernight = ($startStr >= $endStr);

        $start = $date->copy()->setTimeFromTimeString($startStr);
        $end = $isOvernight
            ? $date->copy()->addDay()->setTimeFromTimeString($endStr)
            : $date->copy()->setTimeFromTimeString($endStr);

        $isActive = ($now >= $start && $now < $end);

        return [
            'shift_id' => $shift->id,
            'shift_name' => $shift->nama_shift,
            'operational_date' => $date->format('Y-m-d'),
            'starts_at' => $start->format('Y-m-d H:i:s'),
            'ends_at' => $end->format('Y-m-d H:i:s'),
            'timezone' => self::TIMEZONE,
            'is_active' => $isActive,
            'has_shift' => true,
            'is_overnight' => $isOvernight,
            'start_carbon' => $start,
            'end_carbon' => $end,
            'status_label' => $isActive ? 'Shift Aktif' : 'Shift Terakhir',
            'jam_mulai' => $startStr,
            'jam_selesai' => $endStr,
        ];
    }

    /**
     * Fallback period when user has no work shift.
     *
     * @param Carbon|string|null $referenceTime
     * @return array
     */
    public function resolveFallbackPeriod($referenceTime = null): array
    {
        $now = $this->parseTime($referenceTime);
        $start = $now->copy()->startOfDay();
        $end = $now->copy()->endOfDay();

        return [
            'shift_id' => null,
            'shift_name' => 'Tanpa Shift',
            'operational_date' => $now->format('Y-m-d'),
            'starts_at' => $start->format('Y-m-d H:i:s'),
            'ends_at' => $end->format('Y-m-d H:i:s'),
            'timezone' => self::TIMEZONE,
            'is_active' => false,
            'has_shift' => false,
            'is_overnight' => false,
            'start_carbon' => $start,
            'end_carbon' => $end,
            'status_label' => 'Tanpa Shift',
            'jam_mulai' => '00:00:00',
            'jam_selesai' => '23:59:59',
        ];
    }

    /**
     * Extract H:i:s time string safely from Carbon or string.
     */
    protected function extractTime($time): string
    {
        if ($time instanceof Carbon) {
            return $time->format('H:i:s');
        }

        if (is_string($time)) {
            $parsed = Carbon::parse($time, self::TIMEZONE);
            return $parsed->format('H:i:s');
        }

        return '00:00:00';
    }

    /**
     * Parse input into Carbon in Asia/Jakarta timezone.
     */
    protected function parseTime($time): Carbon
    {
        if ($time instanceof Carbon) {
            return $time->copy()->setTimezone(self::TIMEZONE);
        }

        if (is_string($time) && !empty($time)) {
            return Carbon::parse($time, self::TIMEZONE);
        }

        return Carbon::now(self::TIMEZONE);
    }
}
