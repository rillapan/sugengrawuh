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
    public const CATEGORY_GAME_CONSOLE = 'GAME_CONSOLE';

    // Hardware Brands
    public const BRAND_SAMSUNG = 'SAMSUNG';
    public const BRAND_SHELLY = 'SHELLY';
    public const BRAND_SONY = 'SONY';
    public const BRAND_PLAYSTATION = 'PLAYSTATION';

    // Hardware Models
    public const MODEL_PS3_HEN = 'PS3_HEN';
    public const MODEL_PS4_HEN = 'PS4_HEN';

    // Hardware Drivers
    public const DRIVER_PS3_WEBMAN = 'PS3_WEBMAN';
    public const DRIVER_PS4_GOLDHEN = 'PS4_GOLDHEN';
    public const DRIVER_SAMSUNG_TV = 'SAMSUNG_TV';
    public const DRIVER_SHELLY_RELAY = 'SHELLY_RELAY';

    // Homebrew Types
    public const HOMEBREW_HEN = 'HEN';
    public const HOMEBREW_CFW = 'CFW';
    public const HOMEBREW_GOLDHEN = 'GOLDHEN';
    public const HOMEBREW_WEBMAN = 'WEBMAN_MOD';

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
        'device_token_hash',
        'firmware_version',
        'homebrew_type',
        'homebrew_version',
        'driver_type',
        'transport_config',
        'connection_status',
        'pairing_status',
        'pairing_payload',
        'verified_capabilities',
        'last_seen_at',
        'is_active',
        'auto_volume_limit_enabled',
        'max_volume_limit',
        'current_volume',
        'is_muted',
        'last_volume_verified_at',
        'volume_enforcement_status',
        'auto_volume_limit_updated_by_user_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'verified_capabilities' => 'array',
        'transport_config' => 'array',
        'last_seen_at' => 'datetime',
        'pairing_payload' => 'encrypted',
        'auto_volume_limit_enabled' => 'boolean',
        'max_volume_limit' => 'integer',
        'current_volume' => 'integer',
        'is_muted' => 'boolean',
        'last_volume_verified_at' => 'datetime',
        'auto_volume_limit_updated_by_user_id' => 'integer',
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
            self::CATEGORY_GAME_CONSOLE => [self::BRAND_SONY, self::BRAND_PLAYSTATION],
        ];

        if (!isset($allowed[$category])) {
            throw new InvalidArgumentException("Kategori hardware tidak valid: '{$category}'. Pilihan: SMART_TV, SMART_RELAY, GAME_CONSOLE.");
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
        if ($value === null || trim((string) $value) === '') {
            $this->attributes['mac_address'] = null;
            return;
        }
        $this->attributes['mac_address'] = self::normalizeMacAddress((string) $value);
    }

    /**
     * Generate and persist a new random device token (returning the raw token).
     *
     * @return string
     */
    public function generateDeviceToken(): string
    {
        $rawToken = 'sr_dev_' . bin2hex(random_bytes(24));
        $this->device_token_hash = hash('sha256', $rawToken);
        $this->save();

        return $rawToken;
    }

    /**
     * Verify whether a raw token matches this device's token hash.
     *
     * @param string $rawToken
     * @return bool
     */
    public function verifyDeviceToken(string $rawToken): bool
    {
        if (empty($this->device_token_hash) || empty($rawToken)) {
            return false;
        }

        return hash_equals($this->device_token_hash, hash('sha256', $rawToken));
    }

    /**
     * Look up a physical device by its raw token.
     *
     * @param string $rawToken
     * @return self|null
     */
    public static function findByToken(string $rawToken): ?self
    {
        if (empty($rawToken)) {
            return null;
        }

        $hash = hash('sha256', trim($rawToken));
        return self::where('device_token_hash', $hash)->first();
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

    /**
     * Volume enforcement logs recorded for this TV.
     */
    public function volumeEnforcementLogs()
    {
        return $this->hasMany(TvVolumeEnforcementLog::class, 'physical_device_id');
    }
}
