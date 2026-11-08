<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HardwarePowerCommandLog extends Model
{
    use HasFactory;

    protected $table = 'hardware_power_command_logs';

    protected $fillable = [
        'station_id',
        'physical_device_id',
        'user_id',
        'desired_state',
        'reported_state',
        'execution_status',
        'command_sent',
        'driver_type',
        'idempotency_key',
        'result_code',
        'message',
        'telemetry',
        'latency_ms',
    ];

    protected $casts = [
        'telemetry' => 'array',
        'latency_ms' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function station()
    {
        return $this->belongsTo(Device::class, 'station_id');
    }

    public function physicalDevice()
    {
        return $this->belongsTo(PhysicalDevice::class, 'physical_device_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

