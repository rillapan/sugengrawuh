<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Fnb extends Model
{
    use HasFactory;

    protected $fillable = [
        'nama',
        'harga_beli',
        'harga_jual',
        'stok',
        'deskripsi',
        'image',
        'price_group_id',
    ];

    public function getImageUrlAttribute(): string
    {
        if (!empty($this->image) && Storage::disk('public')->exists($this->image)) {
            return asset('storage/' . $this->image);
        }

        return asset('img/fnb-placeholder.svg');
    }

    public function priceGroup()
    {
        return $this->belongsTo(PriceGroup::class);
    }

    public function stockMutations()
    {
        return $this->hasMany(StockMutation::class);
    }

    public function transactionFnbs()
    {
        return $this->hasMany(TransactionFnb::class);
    }

    public function fnbOrderItems()
    {
        return $this->hasMany(FnbOrderItem::class, 'fnb_id');
    }
}
