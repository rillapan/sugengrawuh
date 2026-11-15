<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransactionFnb extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_id',
        'fnb_id',
        'qty',
        'harga_jual',
        'harga_beli',
    ];

    public function setHargaBeliAttribute($value): void
    {
        $this->attributes['harga_beli'] = (float) ($value ?? 0);
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id', 'id_transaksi');
    }

    public function fnb()
    {
        return $this->belongsTo(Fnb::class);
    }

    public function invoiceItem()
    {
        return $this->hasOne(InvoiceItem::class, 'transaction_fnb_id');
    }
}
