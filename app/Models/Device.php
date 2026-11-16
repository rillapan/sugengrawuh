<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class Device extends Model
{
    use HasFactory;

    protected $guarded = [];
    protected $appends = ['playstation_names'];

    public function playstations()
    {
        return $this->belongsToMany(Playstation::class, 'device_playstation');
    }

    // For backward compatibility - get the first PlayStation
    public function playstation()
    {
        return $this->belongsTo(Playstation::class);
    }

    public function transaction()
    {
        return $this->hasMany(Transaction::class);
    }

    // Scope to get only available devices
    public function scopeAvailable($query)
    {
        return $query->where('status', 'Tersedia');
    }

    // Helper method to get PlayStation names as string
    public function getPlaystationNamesAttribute()
    {
        $names = $this->playstations->pluck('nama');
        
        if ($names->isEmpty() && $this->playstation) {
            return $this->playstation->nama;
        }
        
        return $names->implode(', ');
    }

    /**
     * Historical & active hardware mappings for this station.
     */
    public function hardwareMappings()
    {
        return $this->hasMany(StationDeviceMapping::class, 'station_id');
    }

    /**
     * Active hardware mappings for this station.
     */
    public function activeHardwareMappings()
    {
        return $this->hasMany(StationDeviceMapping::class, 'station_id')
            ->where('mapping_status', StationDeviceMapping::STATUS_ACTIVE);
    }

    /**
     * Active Smart TV display mapping.
     */
    public function activeDisplayTv()
    {
        return $this->hasOne(StationDeviceMapping::class, 'station_id')
            ->where('mapping_status', StationDeviceMapping::STATUS_ACTIVE)
            ->where('mapping_role', StationDeviceMapping::ROLE_DISPLAY_TV);
    }

    /**
     * Active Shelly Light Relay mapping.
     */
    public function activeLightRelay()
    {
        return $this->hasOne(StationDeviceMapping::class, 'station_id')
            ->where('mapping_status', StationDeviceMapping::STATUS_ACTIVE)
            ->where('mapping_role', StationDeviceMapping::ROLE_LIGHT_RELAY);
    }

    /**
     * Active Game Console (PS3/PS4 HEN) mapping.
     */
    public function activeGameConsole()
    {
        return $this->hasOne(StationDeviceMapping::class, 'station_id')
            ->where('mapping_status', StationDeviceMapping::STATUS_ACTIVE)
            ->where('mapping_role', StationDeviceMapping::ROLE_GAME_CONSOLE);
    }

    /**
     * TV Volume enforcement logs recorded for this station.
     */
    public function volumeEnforcementLogs()
    {
        return $this->hasMany(TvVolumeEnforcementLog::class, 'station_id');
    }

    /**
     * Get verified station category (PLAYSTATION vs BILLIARD).
     * Returns null if ambiguous, empty, or unrecognized.
     *
     * @return string|null
     */
    public function getStationCategory(): ?string
    {
        $playstations = $this->playstations;
        if ($playstations->isEmpty() && $this->playstation) {
            $playstations = collect([$this->playstation]);
        }

        if ($playstations->isEmpty()) {
            return null;
        }

        $categories = $playstations->map(function ($ps) {
            return $ps->getCategory();
        })->unique()->values();

        if ($categories->contains(null) || $categories->count() !== 1) {
            return null;
        }

        return $categories->first();
    }

    protected $casts = [
        'session_generation' => 'integer',
        'last_hardware_verified_at' => 'datetime',
        'last_manual_power_at' => 'datetime',
    ];

    public function automationIntents()
    {
        return $this->hasMany(AutomationIntent::class, 'station_id');
    }

    public function automationExecutionLogs()
    {
        return $this->hasMany(AutomationExecutionLog::class, 'station_id');
    }

    public function tvAlertEvents()
    {
        return $this->hasMany(TvAlertEvent::class, 'station_id');
    }

    public function activeTransaction()
    {
        return $this->transaction()
            ->where('billing_status', Transaction::BILLING_RUNNING)
            ->latest('id_transaksi')
            ->first();
    }

    /**
     * Locations linked to this station.
     */
    public function locations()
    {
        return $this->hasMany(Location::class, 'device_id');
    }

    /**
     * Map all devices ordered by devices.id ASC to 1-based sequential station numbers.
     *
     * @param  \Illuminate\Support\Collection|null  $allDevices
     * @return array<int, int>
     */
    public static function resolveGlobalStationNumbers(?Collection $allDevices = null): array
    {
        if ($allDevices === null) {
            $orderedIds = static::query()->orderBy('id', 'asc')->pluck('id');
        } else {
            $orderedIds = $allDevices->sortBy(function ($device) {
                return (int) (is_object($device) ? ($device->id ?? 0) : ($device['id'] ?? 0));
            })->pluck('id')->filter()->unique()->values();
        }

        $mapping = [];
        $seq = 1;
        foreach ($orderedIds as $id) {
            $mapping[(int) $id] = $seq++;
        }

        return $mapping;
    }

    /**
     * Deterministically resolve station number based on devices.id ASC.
     * Never uses regex from device name or locations.
     *
     * @param  \Illuminate\Support\Collection|null  $stationLocations
     * @return int|null
     */
    public function resolveStationNumber(?Collection $stationLocations = null): ?int
    {
        if (isset($this->station_number) && $this->station_number !== null) {
            return (int) $this->station_number;
        }

        if ($this->id === null) {
            return null;
        }

        $mapping = static::resolveGlobalStationNumbers();

        return $mapping[(int) $this->id] ?? null;
    }

    /**
     * Category tie-breaker rank for deterministic station ordering.
     */
    public function getStationCategoryRank(): int
    {
        $category = $this->getStationCategory();

        if ($category === Playstation::CATEGORY_PLAYSTATION) {
            return 0;
        }

        if ($category === Playstation::CATEGORY_BILLIARD) {
            return 1;
        }

        return 2;
    }

    /**
     * Sort station devices deterministically strictly by devices.id ASC.
     */
    public static function sortStationsDeterministically(Collection $devices): Collection
    {
        if ($devices->isEmpty()) {
            return $devices->values();
        }

        return $devices->sortBy(function ($device) {
            return (int) (is_object($device) ? ($device->id ?? 0) : ($device['id'] ?? 0));
        })->values();
    }
}
