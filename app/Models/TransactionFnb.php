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
        'harga_jual'
    ];

    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id', 'id_transaksi');
    }

    public function fnb()
    {
        return $this->belongsTo(Fnb::class);
    }
}
