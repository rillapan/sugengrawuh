<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class AutomationIntent extends Model
{
    use HasFactory;

    protected $table = 'automation_intents';

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_PROCESSING = 'PROCESSING';
    public const STATUS_COMPLETED = 'COMPLETED';
    public const STATUS_FAILED = 'FAILED';
    public const STATUS_RETRY_PENDING = 'RETRY_PENDING';
    public const STATUS_CANCELLED = 'CANCELLED';
    public const STATUS_STALE = 'STALE';
    public const STATUS_SKIPPED = 'SKIPPED';

    public const EVENT_BILLING_STARTED = 'BILLING_STARTED';
    public const EVENT_BILLING_EXPIRED = 'BILLING_EXPIRED';
    public const EVENT_BILLING_STOPPED = 'BILLING_STOPPED';
    public const EVENT_BILLING_EXTENDED = 'BILLING_EXTENDED';
    public const EVENT_BILLING_RECOVERED = 'BILLING_RECOVERED';
    public const EVENT_MANUAL_OVERRIDE = 'MANUAL_OVERRIDE';

    public const STATE_ON = 'ON';
    public const STATE_OFF = 'OFF';
    public const STATE_STANDBY = 'STANDBY';

    protected $fillable = [
        'transaction_id',
        'station_id',
        'station_name',
        'physical_device_id',
        'station_device_mapping_id',
        'session_generation',
        'event_type',
        'desired_state',
        'target_channel',
        'idempotency_key',
        'status',
        'attempts',
        'max_attempts',
        'scheduled_at',
        'next_retry_at',
        'locked_at',
        'locked_by',
        'completed_at',
        'last_error_category',
        'last_error_message',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'next_retry_at' => 'datetime',
        'locked_at' => 'datetime',
        'completed_at' => 'datetime',
        'session_generation' => 'integer',
        'attempts' => 'integer',
        'max_attempts' => 'integer',
        'target_channel' => 'integer',
    ];

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

    public function stationDeviceMapping()
    {
        return $this->belongsTo(StationDeviceMapping::class, 'station_device_mapping_id');
    }

    public function executionLogs()
    {
        return $this->hasMany(AutomationExecutionLog::class, 'intent_id');
    }

    /**
     * Scope eligible intents ready for worker processing.
     * Separates PENDING deadline from RETRY_PENDING deadline strictly.
     */
    public function scopeEligible($query, ?Carbon $asOf = null)
    {
        $time = $asOf ?? Carbon::now();

        return $query->where(function ($q) use ($time) {
            $q->where(function ($sub) use ($time) {
                $sub->where('status', self::STATUS_PENDING)
                    ->where('scheduled_at', '<=', $time);
            })->orWhere(function ($sub) use ($time) {
                $sub->where('status', self::STATUS_RETRY_PENDING)
                    ->where('next_retry_at', '<=', $time);
            });
        });
    }

    /**
     * Scope stale locked intents (worker crashed or died during I/O).
     */
    public function scopeStaleLocked($query, int $leaseSeconds = 60, ?Carbon $asOf = null)
    {
        $time = $asOf ?? Carbon::now();
        $staleThreshold = (clone $time)->subSeconds($leaseSeconds);

        return $query->where('status', self::STATUS_PROCESSING)
            ->where('locked_at', '<', $staleThreshold);
    }
}
