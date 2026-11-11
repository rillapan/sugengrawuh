<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
}
