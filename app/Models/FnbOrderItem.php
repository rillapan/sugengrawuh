<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FnbOrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'fnb_order_id',
        'fnb_id',
        'qty',
        'unit_price_beli',
        'unit_price_jual',
        'subtotal',
        'payment_status',
        'notes',
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

    public function order()
    {
        return $this->belongsTo(FnbOrder::class, 'fnb_order_id');
    }

    public function fnb()
    {
        return $this->belongsTo(Fnb::class, 'fnb_id');
    }

    public function invoiceItem()
    {
        return $this->hasOne(InvoiceItem::class, 'fnb_order_item_id');
    }
}
