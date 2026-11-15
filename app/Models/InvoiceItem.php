<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    use HasFactory;

    public const ITEM_TYPE_GAME_RENTAL = 'GAME_RENTAL';
    public const ITEM_TYPE_FNB_ITEM = 'FNB_ITEM';

    protected $fillable = [
        'invoice_id',
        'item_type',
        'fnb_id',
        'fnb_order_item_id',
        'transaction_fnb_id',
        'name',
        'qty',
        'unit_price_beli',
        'unit_price_jual',
        'subtotal',
    ];

    protected $casts = [
        'qty' => 'integer',
        'unit_price_beli' => 'float',
        'unit_price_jual' => 'float',
        'subtotal' => 'float',
    ];

    public function setUnitPriceBeliAttribute($value): void
    {
        $this->attributes['unit_price_beli'] = (float) ($value ?? 0);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function fnb()
    {
        return $this->belongsTo(Fnb::class, 'fnb_id');
    }

    public function orderItem()
    {
        return $this->belongsTo(FnbOrderItem::class, 'fnb_order_item_id');
    }

    public function transactionFnb()
    {
        return $this->belongsTo(TransactionFnb::class, 'transaction_fnb_id');
    }
}
