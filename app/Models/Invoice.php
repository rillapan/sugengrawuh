<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use HasFactory;

    public const TYPE_GAME_AND_FNB = 'GAME_AND_FNB';
    public const TYPE_GAME_ONLY = 'GAME_ONLY';
    public const TYPE_FNB_STANDALONE = 'FNB_STANDALONE';

    protected $fillable = [
        'invoice_number',
        'transaction_id',
        'invoice_type',
        'subtotal',
        'discount',
        'final_total',
        'amount_paid',
        'change_amount',
        'payment_method',
        'cashier_user_id',
        'paid_at',
    ];

    protected $casts = [
        'paid_at' => 'datetime',
        'subtotal' => 'float',
        'discount' => 'float',
        'final_total' => 'float',
        'amount_paid' => 'float',
        'change_amount' => 'float',
    ];

    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id', 'id_transaksi');
    }

    public function cashierUser()
    {
        return $this->belongsTo(User::class, 'cashier_user_id');
    }

    public function items()
    {
        return $this->hasMany(InvoiceItem::class, 'invoice_id');
    }
}
