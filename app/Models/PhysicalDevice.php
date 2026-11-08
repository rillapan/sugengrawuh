<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class PhysicalDevice extends Model
{
    use HasFactory;

    protected $table = 'physical_devices';

    // Hardware Categories
    public const CATEGORY_SMART_TV = 'SMART_TV';
    public const CATEGORY_SMART_RELAY = 'SMART_RELAY';

    // Hardware Brands
    public const BRAND_SAMSUNG = 'SAMSUNG';
    public const BRAND_SHELLY = 'SHELLY';

    // Connection Statuses
    public const CONNECTION_UNVERIFIED = 'UNVERIFIED';
    public const CONNECTION_ONLINE = 'ONLINE';
    public const CONNECTION_OFFLINE = 'OFFLINE';

    // Pairing Statuses
    public const PAIRING_UNVERIFIED = 'UNVERIFIED';
    public const PAIRING_NOT_REQUIRED = 'NOT_REQUIRED';
    public const PAIRING_PENDING = 'PENDING';
    public const PAIRING_PAIRED = 'PAIRED';
    public const PAIRING_FAILED = 'FAILED';

    protected $fillable = [
        'name',
        'category',
        'brand',
        'model',
        'mac_address',
        'ip_address',
        'device_identifier',
        'connection_status',
        'pairing_status',
        'pairing_payload',
        'verified_capabilities',
        'last_seen_at',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'verified_capabilities' => 'array',
        'last_seen_at' => 'datetime',
        'pairing_payload' => 'encrypted',
    ];

    protected $hidden = [
        'pairing_payload',
    ];

    protected $attributes = [
        'connection_status' => self::CONNECTION_UNVERIFIED,
        'pairing_status' => self::PAIRING_UNVERIFIED,
        'is_active' => true,
    ];

    /**
     * Normalize MAC Address into uppercase colon-separated format (AA:BB:CC:DD:EE:FF).
     *
     * @param string $mac
     * @return string
     * @throws InvalidArgumentException
     */
    public static function normalizeMacAddress(string $mac): string
    {
        $clean = preg_replace('/[^0-9A-Fa-f]/', '', $mac);
        if (strlen($clean) !== 12) {
            throw new InvalidArgumentException("Format MAC Address tidak valid: '{$mac}'. Harus terdiri dari 12 digit heksadesimal.");
        }
        return strtoupper(implode(':', str_split($clean, 2)));
    }

    /**
     * Validate Category and Brand pairing.
     *
     * @param string $category
     * @param string $brand
     * @return void
     * @throws InvalidArgumentException
     */
    public static function validateBrandCategory(string $category, string $brand): void
    {
        $allowed = [
            self::CATEGORY_SMART_TV => [self::BRAND_SAMSUNG],
            self::CATEGORY_SMART_RELAY => [self::BRAND_SHELLY],
        ];

        if (!isset($allowed[$category])) {
            throw new InvalidArgumentException("Kategori hardware tidak valid: '{$category}'. Pilihan: SMART_TV, SMART_RELAY.");
        }

        if (!in_array($brand, $allowed[$category], true)) {
            $allowedBrands = implode(', ', $allowed[$category]);
            throw new InvalidArgumentException("Merek '{$brand}' tidak kompatibel dengan kategori '{$category}'. Merek yang valid: {$allowedBrands}.");
        }
    }

    /**
     * Validate RFC 1918 Private IPv4 address.
     *
     * @param string $ip
     * @return void
     * @throws InvalidArgumentException
     */
    public static function validateIpAddress(string $ip): void
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new InvalidArgumentException("Format IP Address tidak valid: '{$ip}'. Harus berupa IPv4.");
        }

        $isPrivate = !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE);
        if (!$isPrivate) {
            throw new InvalidArgumentException("IP Address '{$ip}' harus merupakan alamat IPv4 privat lokal (RFC 1918: 10.x.x.x, 172.16-31.x.x, 192.168.x.x).");
        }
    }

    /**
     * Mutator for MAC address normalization.
     */
    public function setMacAddressAttribute($value): void
    {
        $this->attributes['mac_address'] = self::normalizeMacAddress((string) $value);
    }

    /**
     * All mapping records for this physical device (history).
     */
    public function stationMappings()
    {
        return $this->hasMany(StationDeviceMapping::class, 'physical_device_id');
    }

    /**
     * Current active mapping record for this device (if any).
     */
    public function activeMapping()
    {
        return $this->hasOne(StationDeviceMapping::class, 'physical_device_id')
            ->where('mapping_status', StationDeviceMapping::STATUS_ACTIVE);
    }

    /**
     * Current station mapped to this device.
     */
    public function getCurrentStationAttribute(): ?Device
    {
        return $this->activeMapping ? $this->activeMapping->station : null;
    }

    /**
     * Scope only active hardware.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope hardware that is currently unmapped.
     */
    public function scopeUnmapped($query)
    {
        return $query->whereDoesntHave('stationMappings', function ($q) {
            $q->where('mapping_status', StationDeviceMapping::STATUS_ACTIVE);
        });
    }
}
