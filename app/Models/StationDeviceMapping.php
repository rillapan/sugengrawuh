<?php

namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StationDeviceMapping extends Model
{
    use HasFactory;

    protected $table = 'station_device_mappings';

    // Roles
    public const ROLE_DISPLAY_TV = 'DISPLAY_TV';
    public const ROLE_LIGHT_RELAY = 'LIGHT_RELAY';
    public const ROLE_GAME_CONSOLE = 'GAME_CONSOLE';

    // Statuses
    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_INACTIVE = 'INACTIVE';

    protected $fillable = [
        'station_id',
        'physical_device_id',
        'mapping_role',
        'mapping_status',
        'attached_by_user_id',
        'detached_by_user_id',
        'attached_at',
        'detached_at',
        'attach_notes',
        'detach_notes',
    ];

    protected $casts = [
        'attached_at' => 'datetime',
        'detached_at' => 'datetime',
    ];

    protected $attributes = [
        'mapping_status' => self::STATUS_ACTIVE,
    ];

    /**
     * Relationship to Station (Device).
     */
    public function station()
    {
        return $this->belongsTo(Device::class, 'station_id');
    }

    /**
     * Relationship to Physical Device.
     */
    public function physicalDevice()
    {
        return $this->belongsTo(PhysicalDevice::class, 'physical_device_id');
    }

    /**
     * Relationship to the user who attached the device.
     */
    public function attachedBy()
    {
        return $this->belongsTo(User::class, 'attached_by_user_id');
    }

    /**
     * Relationship to the user who detached the device.
     */
    public function detachedBy()
    {
        return $this->belongsTo(User::class, 'detached_by_user_id');
    }

    /**
     * Scope for active mappings.
     */
    public function scopeActive($query)
    {
        return $query->where('mapping_status', self::STATUS_ACTIVE);
    }

    /**
     * Scope for inactive mappings (historical).
     */
    public function scopeInactive($query)
    {
        return $query->where('mapping_status', self::STATUS_INACTIVE);
    }
}
