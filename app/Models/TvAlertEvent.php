<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TvAlertEvent extends Model
{
    use HasFactory;

    protected $table = 'tv_control_events';

    // Event Types
    public const TYPE_MANUAL_ALERT = 'MANUAL_ALERT';
    public const TYPE_BILLING_ENDING_SOON = 'BILLING_ENDING_SOON';
    public const TYPE_VOLUME_CONTROL = 'VOLUME_CONTROL';

    // Commands
    public const CMD_SHOW_ALERT = 'SHOW_ALERT';
    public const CMD_VOLUME_DOWN = 'VOLUME_DOWN';
    public const CMD_VOLUME_UP = 'VOLUME_UP';
    public const CMD_MUTE_TOGGLE = 'MUTE_TOGGLE';

    // Statuses
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_SENT_TO_SIMULATOR = 'SENT_TO_SIMULATOR';
    public const STATUS_DELIVERED_TO_APP = 'DELIVERED_TO_APP';
    public const STATUS_ACKED_BY_SIMULATOR = 'ACKED_BY_SIMULATOR';
    public const STATUS_EXPIRED = 'EXPIRED';
    public const STATUS_CANCELLED = 'CANCELLED';
    public const STATUS_STALE = 'STALE';
    public const STATUS_FAILED = 'FAILED';
    public const STATUS_REJECTED = 'REJECTED';

    // Message Presets
    public const PRESET_REMAINING_10_MIN = 'REMAINING_10_MIN';
    public const PRESET_ALMOST_FINISHED = 'ALMOST_FINISHED';
    public const PRESET_LOWER_VOLUME = 'LOWER_VOLUME';
    public const PRESET_CONTACT_CASHIER = 'CONTACT_CASHIER';
    public const PRESET_CUSTOM = 'CUSTOM';

    public static function getPresetMessages(): array
    {
        return [
            self::PRESET_REMAINING_10_MIN => [
                'title' => 'WAKTU BERMAIN TERSISA 10 MENIT!',
                'body' => 'Silakan hubungi kasir untuk memperpanjang waktu bermain.',
            ],
            self::PRESET_ALMOST_FINISHED => [
                'title' => 'WAKTU BERMAIN HAMPIR HABIS!',
                'body' => 'Waktu bermain Anda hampir berakhir. Silakan hubungi kasir bila ingin memperpanjang.',
            ],
            self::PRESET_LOWER_VOLUME => [
                'title' => 'MOHON KECILKAN VOLUME PERMAINAN',
                'body' => 'Demi kenyamanan bersama, mohon kecilkan volume televisi Anda. Terima kasih.',
            ],
            self::PRESET_CONTACT_CASHIER => [
                'title' => 'SILAKAN HUBUNGI KASIR',
                'body' => 'Mohon perwakilan pelanggan pada station ini menghubungi meja kasir.',
            ],
            self::PRESET_CUSTOM => [
                'title' => 'PESAN DARI KASIR / ADMIN',
                'body' => '',
            ],
        ];
    }

    protected $fillable = [
        'station_id',
        'station_name',
        'transaction_id',
        'physical_device_id',
        'station_device_mapping_id',
        'session_generation',
        'actor_user_id',
        'actor_role',
        'event_type',
        'command',
        'message_preset',
        'message_title',
        'message_body',
        'display_duration_seconds',
        'simulated_volume',
        'simulated_muted',
        'control_mode',
        'driver_type',
        'idempotency_key',
        'status',
        'result_code',
        'error_message',
        'scheduled_at',
        'delivered_at',
        'acked_at',
        'cancelled_at',
    ];

    protected $casts = [
        'session_generation' => 'integer',
        'display_duration_seconds' => 'integer',
        'simulated_volume' => 'integer',
        'simulated_muted' => 'boolean',
        'scheduled_at' => 'datetime',
        'delivered_at' => 'datetime',
        'acked_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function station()
    {
        return $this->belongsTo(Device::class, 'station_id');
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id', 'id_transaksi');
    }

    public function physicalDevice()
    {
        return $this->belongsTo(PhysicalDevice::class, 'physical_device_id');
    }

    public function stationDeviceMapping()
    {
        return $this->belongsTo(StationDeviceMapping::class, 'station_device_mapping_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}

