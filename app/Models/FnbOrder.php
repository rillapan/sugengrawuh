<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FnbOrder extends Model
{
    use HasFactory;

    public const SOURCE_QR = 'QR';
    public const SOURCE_CASHIER = 'CASHIER';
    public const SOURCE_FNB_STAFF = 'FNB_STAFF';

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_ACCEPTED = 'ACCEPTED';
    public const STATUS_PREPARING = 'PREPARING';
    public const STATUS_READY = 'READY';
    public const STATUS_DELIVERED = 'DELIVERED';
    public const STATUS_CANCELLED = 'CANCELLED';
    public const STATUS_EXPIRED = 'EXPIRED';

    public const PAYMENT_UNPAID = 'unpaid';
    public const PAYMENT_PAID = 'paid';

    protected $fillable = [
        'order_number',
        'session_token',
        'source',
        'location_id',
        'transaction_id',
        'customer_name',
        'billing_name',
        'status',
        'payment_status',
        'requires_upfront_payment',
        'notes',
        'accepted_at',
        'cancelled_at',
        'cancelled_by_user_id',
        'cancelled_reason',
        'created_by_user_id',
    ];

    protected $casts = [
        'requires_upfront_payment' => 'boolean',
        'accepted_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function location()
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id', 'id_transaksi');
    }

    public function items()
    {
        return $this->hasMany(FnbOrderItem::class, 'fnb_order_id');
    }

    public function createdByUser()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function cancelledByUser()
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function getTotalAmountAttribute()
    {
        return (float) $this->items->sum('subtotal');
    }
}
