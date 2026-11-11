<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TvVolumeEnforcementLog extends Model
{
    use HasFactory;

    protected $table = 'tv_volume_enforcement_logs';

    // Enforcement Sources
    public const SOURCE_TIZEN_AGENT = 'TIZEN_AGENT';
    public const SOURCE_ADMIN_ENFORCE = 'ADMIN_ENFORCE';
    public const SOURCE_SIMULATOR = 'SIMULATOR';

    // Enforcement Statuses
    public const STATUS_ENFORCED = 'ENFORCED';
    public const STATUS_FAILED = 'FAILED';
    public const STATUS_UNSUPPORTED = 'UNSUPPORTED';

    protected $fillable = [
        'station_id',
        'physical_device_id',
        'previous_volume',
        'enforced_volume',
        'max_limit',
        'enforcement_source',
        'status',
        'details',
    ];

    protected $casts = [
        'station_id' => 'integer',
        'physical_device_id' => 'integer',
        'previous_volume' => 'integer',
        'enforced_volume' => 'integer',
        'max_limit' => 'integer',
    ];

    public function station()
    {
        return $this->belongsTo(Device::class, 'station_id');
    }

    public function physicalDevice()
    {
        return $this->belongsTo(PhysicalDevice::class, 'physical_device_id');
    }
}

