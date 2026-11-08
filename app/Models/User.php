<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'shift',
        'work_shift_id'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    public function workShift()
    {
        return $this->belongsTo(WorkShift::class);
    }

    public function transaction()
    {
        return $this->hasMany(Transaction::class);
    }

    public function member()
    {
        return $this->hasOne(Member::class);
    }

    /**
     * Check if user is admin
     */
    public function isAdmin()
    {
        return strtolower($this->role ?? '') === 'admin';
    }

    /**
     * Check if user is owner
     */
    public function isOwner()
    {
        return strtolower($this->role ?? '') === 'owner';
    }

    /**
     * Check if user is kasir
     */
    public function isKasir()
    {
        return strtolower($this->role ?? '') === 'kasir';
    }

    /**
     * Check if user has role
     */
    public function hasRole($role)
    {
        $currentRole = strtolower($this->role ?? '');
        if (is_array($role)) {
            return in_array($currentRole, array_map('strtolower', $role));
        }
        return $currentRole === strtolower($role);
    }
}
