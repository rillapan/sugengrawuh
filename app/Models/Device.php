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
     * Deterministically resolve station number from active station locations or device name.
     * Never falls back to devices.id.
     */
    public function resolveStationNumber(?Collection $stationLocations = null): ?int
    {
        if ($stationLocations !== null) {
            $candidateLocations = $stationLocations;
        } elseif ($this->relationLoaded('locations')) {
            $candidateLocations = $this->locations;
        } elseif ($this->exists && Schema::hasTable('locations')) {
            $candidateLocations = $this->locations()->get();
        } else {
            $candidateLocations = collect();
        }

        $activeStationNumbers = $candidateLocations
            ->filter(function ($loc) {
                return $this->id !== null
                    && (int) $loc->device_id === (int) $this->id
                    && $loc->type === 'station'
                    && $loc->is_active === true;
            })
            ->map(function ($loc) {
                if (preg_match('/(\d+)/', (string) $loc->code, $m)) {
                    $num = (int) $m[1];
                    return $num > 0 ? $num : null;
                }
                return null;
            })
            ->filter(fn ($num) => $num !== null)
            ->unique()
            ->values();

        if ($activeStationNumbers->count() === 1) {
            return (int) $activeStationNumbers->first();
        }

        $cleanedName = preg_replace(
            '/(?<![A-Za-z0-9])(?:PlayStation\s*[345]|PS[345])(?![A-Za-z0-9])/iu',
            '',
            (string) $this->nama
        );

        if (preg_match('/(\d+)/', (string) $cleanedName, $m)) {
            $num = (int) $m[1];
            if ($num > 0) {
                return $num;
            }
        }

        return null;
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
     * Sort station devices deterministically by station number, category, name, and id.
     */
    public static function sortStationsDeterministically(Collection $devices): Collection
    {
        if ($devices->isEmpty()) {
            return $devices->values();
        }

        if ($devices instanceof EloquentCollection) {
            $devices->loadMissing(['playstations', 'playstation']);
        } else {
            $loadable = new EloquentCollection($devices->filter(function ($device) {
                return $device instanceof self && $device->getKey() !== null;
            })->all());
            if ($loadable->isNotEmpty()) {
                $loadable->loadMissing(['playstations', 'playstation']);
            }
        }

        $batchStationLocations = null;
        $needsLocations = $devices->contains(function ($device) {
            return $device instanceof self
                && !$device->relationLoaded('locations')
                && $device->getKey() !== null;
        });

        if ($needsLocations && Schema::hasTable('locations')) {
            $deviceIds = $devices->pluck('id')->filter()->unique()->values();
            $batchStationLocations = $deviceIds->isNotEmpty()
                ? Location::whereIn('device_id', $deviceIds)
                    ->where('type', 'station')
                    ->where('is_active', true)
                    ->get()
                : collect();
        }

        $sortKeys = [];
        foreach ($devices as $device) {
            $stationNumber = $device->resolveStationNumber(
                $device->relationLoaded('locations') ? null : $batchStationLocations
            );
            $sortKeys[spl_object_id($device)] = [
                $stationNumber ?? PHP_INT_MAX,
                $device->getStationCategoryRank(),
                mb_strtolower((string) $device->nama, 'UTF-8'),
                (int) $device->id,
            ];
        }

        return $devices->sort(function ($a, $b) use ($sortKeys) {
            return $sortKeys[spl_object_id($a)] <=> $sortKeys[spl_object_id($b)];
        })->values();
    }
}
