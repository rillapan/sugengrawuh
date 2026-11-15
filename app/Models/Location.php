<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Location extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'type',
        'device_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function device()
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public function fnbOrders()
    {
        return $this->hasMany(FnbOrder::class, 'location_id');
    }
}
