<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class TransactionPricingSegment extends Model
{
    use HasFactory;

    public const MODE_INITIAL = 'initial';
    public const MODE_CORRECTION = 'correction';
    public const MODE_FROM_NOW = 'from_now';

    protected $fillable = [
        'transaction_id',
        'segment_order',
        'device_id',
        'playstation_id',
        'playstation_name_snapshot',
        'hourly_rate_snapshot',
        'pricing_mode',
        'is_preset_price',
        'started_at',
        'ended_at',
        'duration_seconds',
        'raw_amount',
        'billed_amount',
        'changed_by_user_id',
        'change_reason',
    ];

    protected $casts = [
        'transaction_id' => 'integer',
        'segment_order' => 'integer',
        'device_id' => 'integer',
        'playstation_id' => 'integer',
        'hourly_rate_snapshot' => 'float',
        'is_preset_price' => 'boolean',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'duration_seconds' => 'integer',
        'raw_amount' => 'float',
        'billed_amount' => 'float',
        'changed_by_user_id' => 'integer',
    ];

    public static ?bool $hasSchemaCache = null;
    public static ?bool $mockHasSchema = null;

    public static function hasSchema(bool $refresh = false): bool
    {
        if (static::$mockHasSchema !== null) {
            return (bool) static::$mockHasSchema;
        }

        if (static::$hasSchemaCache === null || $refresh) {
            try {
                static::$hasSchemaCache = Schema::hasTable('transaction_pricing_segments');
            } catch (\Throwable $e) {
                static::$hasSchemaCache = false;
            }
        }

        return (bool) static::$hasSchemaCache;
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id', 'id_transaksi');
    }

    public function device()
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public function playstation()
    {
        return $this->belongsTo(Playstation::class, 'playstation_id');
    }

    public function changedByUser()
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
