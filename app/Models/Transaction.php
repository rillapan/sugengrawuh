<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    protected $primaryKey = 'id_transaksi';
    public $incrementing = true;
    protected $keyType = 'int';

    public const BILLING_IDLE = 'IDLE';
    public const BILLING_RUNNING = 'RUNNING';
    public const BILLING_EXPIRED_PENDING_ACTION = 'EXPIRED_PENDING_ACTION';
    public const BILLING_STOPPED_EARLY = 'STOPPED_EARLY';
    public const BILLING_COMPLETED = 'COMPLETED';

    protected $fillable = [
        'status',
        'member_id',
        'nama',
        'no_telepon',
        'device_id',
        'playstation_id',
        'user_id',
        'harga',
        'jam_main',
        'waktu_mulai',
        'waktu_Selesai',
        'total',
        'status_transaksi',
        'payment_status',
        'payment_method',
        'tipe_transaksi',
        'custom_package_id',
        'diskon',
        'lost_time_start',
        'paid_at',
        'started_at',
        'expires_at',
        'ended_at',
        'billing_status',
        'session_generation',
    ];

    protected $casts = [
        'paid_at' => 'datetime',
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
        'ended_at' => 'datetime',
        'lost_time_start' => 'datetime',
        'session_generation' => 'integer',
    ];

    public function scopeActiveBilling($query)
    {
        return $query->where('billing_status', self::BILLING_RUNNING);
    }

    public function isActiveSession(): bool
    {
        return $this->billing_status === self::BILLING_RUNNING;
    }

    public function isExpired(): bool
    {
        if (!$this->expires_at) {
            return false;
        }
        return now()->greaterThanOrEqualTo($this->expires_at);
    }

    protected static ?bool $hasG2Schema = null;

    /**
     * Required columns for G2 Server-Side Billing Engine.
     */
    public static function getRequiredG2Columns(): array
    {
        return ['started_at', 'expires_at', 'ended_at', 'billing_status'];
    }

    public static ?array $mockMissingColumns = null;

    /**
     * Return list of missing G2 columns on the active database connection.
     */
    public static function getMissingG2Columns(): array
    {
        if (static::$mockMissingColumns !== null) {
            return static::$mockMissingColumns;
        }

        try {
            $missing = [];
            foreach (self::getRequiredG2Columns() as $column) {
                if (!\Illuminate\Support\Facades\Schema::hasColumn('transactions', $column)) {
                    $missing[] = $column;
                }
            }
            return $missing;
        } catch (\Throwable $e) {
            return self::getRequiredG2Columns();
        }
    }

    /**
     * Check if the database has complete G2 absolute timestamp & status columns.
     * Cached statically per request lifecycle.
     */
    public static function hasG2Schema(bool $refresh = false): bool
    {
        if (static::$hasG2Schema === null || $refresh) {
            try {
                static::$hasG2Schema = empty(self::getMissingG2Columns());
            } catch (\Throwable $e) {
                static::$hasG2Schema = false;
            }
        }
        return (bool) static::$hasG2Schema;
    }

    public function device()
    {
        return $this->belongsTo(Device::class);
    }

    public function playstation()
    {
        return $this->belongsTo(Playstation::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function transactionFnbs()
    {
        return $this->hasMany(TransactionFnb::class, 'transaction_id', 'id_transaksi');
    }

    public function custom_package()
    {
        return $this->belongsTo(CustomPackage::class, 'custom_package_id');
    }

    public function getFnbTotalAttribute()
    {
        return $this->transactionFnbs->sum(function ($item) {
            return $item->qty * $item->harga_jual;
        });
    }

    public function automationIntents()
    {
        return $this->hasMany(AutomationIntent::class, 'transaction_id', 'id_transaksi');
    }

    public function automationExecutionLogs()
    {
        return $this->hasMany(AutomationExecutionLog::class, 'transaction_id', 'id_transaksi');
    }

    public function tvAlertEvents()
    {
        return $this->hasMany(TvAlertEvent::class, 'transaction_id', 'id_transaksi');
    }
}
