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
    public const TYPE_BILLING_EXPIRED = 'BILLING_EXPIRED';
    public const TYPE_VOLUME_CONTROL = 'VOLUME_CONTROL';

    // Commands
    public const CMD_SHOW_ALERT = 'SHOW_ALERT';
    public const CMD_VOLUME_DOWN = 'VOLUME_DOWN';
    public const CMD_VOLUME_UP = 'VOLUME_UP';
    public const CMD_MUTE_TOGGLE = 'MUTE_TOGGLE';
    public const CMD_MUTE = 'MUTE';
    public const CMD_UNMUTE = 'UNMUTE';
    public const CMD_SET_VOLUME = 'SET_VOLUME';

    // Statuses
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_COMMAND_SENT = 'COMMAND_SENT';
    public const STATUS_SENT_TO_SIMULATOR = 'SENT_TO_SIMULATOR';
    public const STATUS_DELIVERED_TO_APP = 'DELIVERED_TO_APP';
    public const STATUS_DELIVERED = 'DELIVERED_TO_APP';
    public const STATUS_ACKED_BY_SIMULATOR = 'ACKED_BY_SIMULATOR';
    public const STATUS_ACKED = 'ACKED_BY_SIMULATOR';
    public const STATUS_COMPLETED = 'ACKED_BY_SIMULATOR';
    public const STATUS_EXPIRED = 'EXPIRED';
    public const STATUS_CANCELLED = 'CANCELLED';
    public const STATUS_STALE = 'STALE';
    public const STATUS_FAILED = 'FAILED';
    public const STATUS_REJECTED = 'REJECTED';
    public const STATUS_ACCEPTED = 'ACCEPTED';
    public const STATUS_UNVERIFIED = 'UNVERIFIED';

    // Consumer Execution Lifecycle Statuses
    public const STATUS_COMMAND_RECEIVED = 'COMMAND_RECEIVED';
    public const STATUS_COMMAND_EXECUTED = 'COMMAND_EXECUTED';
    public const STATUS_STATE_VERIFIED = 'STATE_VERIFIED';
    public const STATUS_UNSUPPORTED = 'UNSUPPORTED';

    // Message Presets
    public const PRESET_REMAINING_10_MIN = 'REMAINING_10_MIN';
    public const PRESET_ALMOST_FINISHED = 'ALMOST_FINISHED';
    public const PRESET_TIME_EXPIRED = 'TIME_EXPIRED';
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
            self::PRESET_TIME_EXPIRED => [
                'title' => 'WAKTU BERMAIN TELAH HABIS!',
                'body' => 'Waktu bermain telah habis. Silakan hubungi kasir.',
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
        'real_volume',
        'real_muted',
        'control_mode',
        'driver_type',
        'target_channel',
        'idempotency_key',
        'status',
        'execution_status',
        'result_code',
        'error_message',
        'hardware_error_code',
        'hardware_error_message',
        'agent_response_payload',
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
        'real_volume' => 'integer',
        'real_muted' => 'boolean',
        'scheduled_at' => 'datetime',
        'delivered_at' => 'datetime',
        'acked_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::creating(function (TvAlertEvent $event) {
            if (empty($event->idempotency_key)) {
                $event->idempotency_key = 'evt_' . uniqid('', true);
            }
        });
    }

    public function toConsumerPayload(): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->id,
            'station_id' => (int) $this->station_id,
            'station_name' => $this->station_name,
            'transaction_id' => $this->transaction_id,
            'session_generation' => (int) $this->session_generation,
            'event_type' => $this->event_type,
            'command' => $this->command,
            'message_preset' => $this->message_preset,
            'message_title' => $this->message_title,
            'message_body' => $this->message_body,
            'message_text' => $this->message_body,
            'display_duration_seconds' => (int) $this->display_duration_seconds,
            'duration_seconds' => (int) $this->display_duration_seconds,
            'simulated_volume' => $this->simulated_volume !== null ? (int) $this->simulated_volume : null,
            'simulated_muted' => $this->simulated_muted !== null ? (bool) $this->simulated_muted : null,
            'real_volume' => $this->real_volume !== null ? (int) $this->real_volume : null,
            'real_muted' => $this->real_muted !== null ? (bool) $this->real_muted : null,
            'control_mode' => $this->control_mode,
            'driver_type' => $this->driver_type,
            'status' => $this->status,
            'execution_status' => $this->execution_status,
            'result_code' => $this->result_code,
            'error_message' => $this->error_message,
            'hardware_error_code' => $this->hardware_error_code,
            'hardware_error_message' => $this->hardware_error_message,
            'created_at' => $this->created_at ? $this->created_at->toIso8601String() : null,
        ];
    }

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

