<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AutomationExecutionLog extends Model
{
    use HasFactory;

    public $timestamps = false;
    protected $table = 'automation_execution_logs';

    public const STATUS_SUCCESS = 'SUCCESS';
    public const STATUS_FAILED = 'FAILED';
    public const STATUS_SKIPPED = 'SKIPPED';
    public const STATUS_RETRY_SCHEDULED = 'RETRY_SCHEDULED';

    protected $fillable = [
        'intent_id',
        'transaction_id',
        'station_id',
        'station_name',
        'physical_device_id',
        'driver_type',
        'command_sent',
        'attempt_number',
        'execution_status',
        'result_code',
        'error_category',
        'error_details',
        'observed_output_state',
        'observed_power_watts',
        'latency_ms',
        'executed_at',
    ];

    protected $casts = [
        'attempt_number' => 'integer',
        'latency_ms' => 'integer',
        'observed_power_watts' => 'decimal:2',
        'executed_at' => 'datetime',
    ];

    public function intent()
    {
        return $this->belongsTo(AutomationIntent::class, 'intent_id');
    }

    public function station()
    {
        return $this->belongsTo(Device::class, 'station_id');
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id', 'id_transaksi');
    }

    public function physicalDevice()
    {
        return $this->belongsTo(PhysicalDevice::class, 'physical_device_id');
    }
}
