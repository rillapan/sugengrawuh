<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    protected $primaryKey = 'id_transaksi';
    public $incrementing = true;
    protected $keyType = 'int';

    public const BILLING_IDLE = 'IDLE';
    public const BILLING_RUNNING = 'RUNNING';
    public const BILLING_EXPIRED_PENDING_ACTION = 'EXPIRED_PENDING_ACTION';
    public const BILLING_STOPPED_EARLY = 'STOPPED_EARLY';
    public const BILLING_COMPLETED = 'COMPLETED';

    protected $fillable = [
        'status',
        'member_id',
        'nama',
        'no_telepon',
        'device_id',
        'playstation_id',
        'user_id',
        'harga',
        'jam_main',
        'duration_seconds',
        'waktu_mulai',
        'waktu_Selesai',
        'total',
        'status_transaksi',
        'payment_status',
        'payment_method',
        'tipe_transaksi',
        'custom_package_id',
        'diskon',
        'lost_time_start',
        'paid_at',
        'started_at',
        'expires_at',
        'ended_at',
        'billing_status',
        'session_token',
        'session_generation',
    ];

    protected $casts = [
        'paid_at' => 'datetime',
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
        'ended_at' => 'datetime',
        'lost_time_start' => 'datetime',
        'session_generation' => 'integer',
        'duration_seconds' => 'integer',
    ];

    /**
     * Normalize any date/datetime value to the configured application timezone.
     */
    private function normalizeToAppTimezone(mixed $value): Carbon
    {
        $tz = config('app.timezone', 'Asia/Jakarta');

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->copy()->setTimezone($tz);
        }

        return Carbon::parse($value, $tz)->setTimezone($tz);
    }

    /**
     * Get authoritative start timestamp with second precision.
     */
    public function getEffectiveStartTime(): Carbon
    {
        $tz = config('app.timezone', 'Asia/Jakarta');

        if ($this->started_at) {
            return $this->normalizeToAppTimezone($this->started_at);
        }
        if ($this->lost_time_start) {
            return $this->normalizeToAppTimezone($this->lost_time_start);
        }
        $dateStr = $this->created_at
            ? $this->normalizeToAppTimezone($this->created_at)->toDateString()
            : Carbon::now($tz)->toDateString();
        $timeStr = $this->waktu_mulai ?: '00:00:00';
        return Carbon::parse($dateStr . ' ' . $timeStr, $tz);
    }

    /**
     * Get authoritative end timestamp with second precision.
     */
    public function getEffectiveEndTime(): Carbon
    {
        $tz = config('app.timezone', 'Asia/Jakarta');

        if ($this->ended_at) {
            return $this->normalizeToAppTimezone($this->ended_at);
        }
        if ($this->waktu_Selesai !== null && trim((string) $this->waktu_Selesai) !== '') {
            $start = $this->getEffectiveStartTime();
            $end = Carbon::parse($start->toDateString() . ' ' . $this->waktu_Selesai, $tz);
            if ($end->lt($start)) {
                $end->addDay();
            }
            return $end;
        }
        return Carbon::now($tz);
    }

    /**
     * Resolve expiration timestamp for prepaid or custom package transactions,
     * handling cross-midnight legacy waktu_Selesai values when expires_at is null.
     */
    public function resolveLegacyExpiresAt(): ?Carbon
    {
        $tz = config('app.timezone', 'Asia/Jakarta');

        if ($this->expires_at) {
            return $this->normalizeToAppTimezone($this->expires_at);
        }

        if (
            in_array($this->tipe_transaksi, ['prepaid', 'custom_package'], true)
            && $this->waktu_Selesai !== null
            && trim((string) $this->waktu_Selesai) !== ''
        ) {
            $start = $this->getEffectiveStartTime();
            $end = Carbon::parse($start->toDateString() . ' ' . $this->waktu_Selesai, $tz);
            if ($end->lt($start)) {
                $end->addDay();
            }
            return $end;
        }

        return null;
    }

    /**
     * Determine whether a legacy (null billing_status or pre-G2) transaction is currently active.
     */
    public function isLegacyActiveSession(?Carbon $now = null): bool
    {
        $tz = config('app.timezone', 'Asia/Jakarta');
        $now = $now ? $this->normalizeToAppTimezone($now) : Carbon::now($tz);

        if (in_array($this->status_transaksi, ['selesai', 'draft'], true)) {
            return false;
        }

        if (in_array($this->billing_status, [
            self::BILLING_COMPLETED,
            self::BILLING_STOPPED_EARLY,
            self::BILLING_EXPIRED_PENDING_ACTION,
            self::BILLING_IDLE,
        ], true)) {
            return false;
        }

        if ($this->tipe_transaksi === 'postpaid') {
            return $this->status_transaksi === 'berjalan';
        }

        if (in_array($this->tipe_transaksi, ['prepaid', 'custom_package'], true)) {
            $expires = $this->resolveLegacyExpiresAt();
            return $expires !== null && $expires->gt($now);
        }

        return false;
    }

    /**
     * Get human-readable duration description across prepaid, postpaid, and custom package.
     */
    public function getDisplayDurationAttribute(): string
    {
        if ($this->tipe_transaksi === 'postpaid') {
            if (!empty($this->jam_main)) {
                return (string) $this->jam_main;
            }
            $start = $this->getEffectiveStartTime();
            $end = $this->status_transaksi === 'selesai' ? $this->getEffectiveEndTime() : \Carbon\Carbon::now();
            $diffMins = max(1, (int) $start->diffInMinutes($end, false));
            return sprintf('%d jam %d menit', floor($diffMins / 60), $diffMins % 60);
        }

        if ($this->tipe_transaksi === 'custom_package') {
            return is_numeric($this->jam_main) ? "{$this->jam_main} Menit" : (string) ($this->jam_main ?: '-');
        }

        // Prepaid
        if ($this->duration_seconds !== null && $this->duration_seconds > 0) {
            $formatted = HourlyPrice::formatSeconds($this->duration_seconds);
            $human = HourlyPrice::humanizeSeconds($this->duration_seconds);
            return "{$formatted} ({$human})";
        }

        if (!empty($this->jam_main)) {
            if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $this->jam_main)) {
                return $this->jam_main;
            }
            return is_numeric($this->jam_main) ? "{$this->jam_main} Jam" : $this->jam_main;
        }

        return '-';
    }

    public function scopeActiveBilling($query)
    {
        return $query->where('billing_status', self::BILLING_RUNNING);
    }

    public function isActiveSession(): bool
    {
        return $this->billing_status === self::BILLING_RUNNING;
    }

    public function isExpired(): bool
    {
        if (!$this->expires_at) {
            return false;
        }
        return now()->greaterThanOrEqualTo($this->expires_at);
    }

    protected static ?bool $hasG2Schema = null;

    /**
     * Required columns for G2 Server-Side Billing Engine.
     */
    public static function getRequiredG2Columns(): array
    {
        return ['started_at', 'expires_at', 'ended_at', 'billing_status'];
    }

    public static ?array $mockMissingColumns = null;

    /**
     * Return list of missing G2 columns on the active database connection.
     */
    public static function getMissingG2Columns(): array
    {
        if (static::$mockMissingColumns !== null) {
            return static::$mockMissingColumns;
        }

        try {
            $missing = [];
            foreach (self::getRequiredG2Columns() as $column) {
                if (!\Illuminate\Support\Facades\Schema::hasColumn('transactions', $column)) {
                    $missing[] = $column;
                }
            }
            return $missing;
        } catch (\Throwable $e) {
            return self::getRequiredG2Columns();
        }
    }

    /**
     * Check if the database has complete G2 absolute timestamp & status columns.
     * Cached statically per request lifecycle.
     */
    public static function hasG2Schema(bool $refresh = false): bool
    {
        if (static::$hasG2Schema === null || $refresh) {
            try {
                static::$hasG2Schema = empty(self::getMissingG2Columns());
            } catch (\Throwable $e) {
                static::$hasG2Schema = false;
            }
        }
        return (bool) static::$hasG2Schema;
    }

    public function device()
    {
        return $this->belongsTo(Device::class);
    }

    public function playstation()
    {
        return $this->belongsTo(Playstation::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function transactionFnbs()
    {
        return $this->hasMany(TransactionFnb::class, 'transaction_id', 'id_transaksi');
    }

    public function custom_package()
    {
        return $this->belongsTo(CustomPackage::class, 'custom_package_id');
    }

    public function getFnbTotalAttribute()
    {
        return $this->transactionFnbs->sum(function ($item) {
            return $item->qty * $item->harga_jual;
        });
    }

    /**
     * Resolve exact rental total for Paket (prepaid / custom_package) transactions.
     */
    public function resolvePaketRentalTotal(): float
    {
        if ($this->tipe_transaksi === 'custom_package') {
            if ((float) ($this->harga ?? 0) > 0) {
                return (float) $this->harga;
            }

            $customPackage = $this->relationLoaded('custom_package')
                ? $this->custom_package
                : $this->custom_package()->first();

            return (float) ($customPackage->harga_total ?? $this->total ?? 0);
        }

        if ($this->tipe_transaksi === 'prepaid') {
            $segments = $this->getResolvedPricingSegments();
            if ($segments->isNotEmpty()) {
                $billingService = app(\App\Services\BillingService::class);
                if (method_exists($billingService, 'calculateTimerWithSegments')) {
                    return (float) ($billingService->calculateTimerWithSegments($this)['rental_total'] ?? 0.0);
                }
            }

            if ($this->duration_seconds === null && is_numeric($this->jam_main)) {
                return (float) $this->harga * (float) $this->jam_main;
            }

            $durationSeconds = ($this->duration_seconds !== null && (int) $this->duration_seconds > 0)
                ? (int) $this->duration_seconds
                : null;

            if ($durationSeconds === null && !empty($this->jam_main)) {
                if (is_numeric($this->jam_main)) {
                    $durationSeconds = (int) round((float) $this->jam_main * 3600);
                } elseif (is_string($this->jam_main) && preg_match('/^(\d+):(\d{2})(?::(\d{2}))?$/', trim($this->jam_main), $m)) {
                    $durationSeconds = ((int) $m[1] * 3600) + ((int) $m[2] * 60) + (isset($m[3]) ? (int) $m[3] : 0);
                }
            }

            if ($durationSeconds !== null && $durationSeconds > 0) {
                $playstation = $this->relationLoaded('playstation')
                    ? $this->playstation
                    : $this->playstation()->first();

                if (!$playstation) {
                    $device = $this->relationLoaded('device')
                        ? $this->device
                        : $this->device()->with('playstation')->first();
                    $playstation = $device?->playstation;
                }

                if ($playstation && ((float) ($this->harga ?? 0) <= 0 || abs((float) $this->harga - (float) ($playstation->harga ?? 0)) < 0.01)) {
                    return (float) $playstation->calculateCustomPrice($durationSeconds);
                }

                if ((float) ($this->harga ?? 0) > 0) {
                    return (float) ((float) $this->harga * ($durationSeconds / 3600));
                }
            }

            return (float) ($this->harga ?? 0);
        }

        return 0.0;
    }

    /**
     * Sum chargeable legacy TransactionFnb rows that have not yet been linked to an InvoiceItem.
     */
    public function getUninvoicedLegacyFnbTotalAttribute(): float
    {
        $legacyFnbs = $this->relationLoaded('transactionFnbs')
            ? $this->transactionFnbs
            : $this->transactionFnbs()->with('invoiceItem')->get();

        if ($legacyFnbs->isEmpty()) {
            return 0.0;
        }

        $packageItems = [];
        if ($this->tipe_transaksi === 'custom_package') {
            $customPackage = $this->relationLoaded('custom_package')
                ? $this->custom_package
                : $this->custom_package()->with('fnbs')->first();

            if ($customPackage) {
                if (!$customPackage->relationLoaded('fnbs')) {
                    $customPackage->load('fnbs');
                }
                foreach ($customPackage->fnbs as $pFnb) {
                    $packageItems[$pFnb->id] = (int) $pFnb->pivot->quantity;
                }
            }
        }

        $total = 0.0;
        foreach ($legacyFnbs as $tFnb) {
            $hasInvoiceItem = $tFnb->relationLoaded('invoiceItem')
                ? ($tFnb->invoiceItem !== null)
                : $tFnb->invoiceItem()->exists();

            if ($hasInvoiceItem) {
                continue;
            }

            $qtyInPackage = $packageItems[$tFnb->fnb_id] ?? 0;
            $chargeableQty = max(0, (int) $tFnb->qty - $qtyInPackage);
            $total += $chargeableQty * (float) $tFnb->harga_jual;
        }

        return (float) $total;
    }

    /**
     * Total payable amount for a Paket (prepaid / custom_package) transaction.
     */
    public function getPaketPayableTotalAttribute(): float
    {
        return $this->resolvePaketRentalTotal()
            + $this->uninvoiced_legacy_fnb_total
            + $this->unpaid_fnb_order_total;
    }

    public function getUnpaidFnbOrderTotalAttribute(): float
    {
        $orders = $this->relationLoaded('fnbOrders')
            ? $this->fnbOrders
            : $this->fnbOrders()->with('items')->get();

        return (float) $orders
            ->reject(fn($order) => in_array($order->status, [FnbOrder::STATUS_CANCELLED, FnbOrder::STATUS_EXPIRED]))
            ->sum(function ($order) {
                if ($order->relationLoaded('items')) {
                    return $order->items->where('payment_status', 'unpaid')->sum('subtotal');
                }
                return $order->items()->where('payment_status', 'unpaid')->sum('subtotal');
            });
    }

    public function getPaidFnbOrderTotalAttribute(): float
    {
        $orders = $this->relationLoaded('fnbOrders')
            ? $this->fnbOrders
            : $this->fnbOrders()->with('items')->get();

        return (float) $orders
            ->reject(fn($order) => in_array($order->status, [FnbOrder::STATUS_CANCELLED, FnbOrder::STATUS_EXPIRED]))
            ->sum(function ($order) {
                if ($order->relationLoaded('items')) {
                    return $order->items->where('payment_status', 'paid')->sum('subtotal');
                }
                return $order->items()->where('payment_status', 'paid')->sum('subtotal');
            });
    }

    public function automationIntents()
    {
        return $this->hasMany(AutomationIntent::class, 'transaction_id', 'id_transaksi');
    }

    public function automationExecutionLogs()
    {
        return $this->hasMany(AutomationExecutionLog::class, 'transaction_id', 'id_transaksi');
    }

    public function tvAlertEvents()
    {
        return $this->hasMany(TvAlertEvent::class, 'transaction_id', 'id_transaksi');
    }

    public function fnbOrders()
    {
        return $this->hasMany(FnbOrder::class, 'transaction_id', 'id_transaksi');
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class, 'transaction_id', 'id_transaksi');
    }

    public function pricingSegments()
    {
        return $this->hasMany(TransactionPricingSegment::class, 'transaction_id', 'id_transaksi')
            ->orderBy('segment_order', 'asc')
            ->orderBy('id', 'asc');
    }

    public function getResolvedPricingSegments(): \Illuminate\Support\Collection
    {
        if (!TransactionPricingSegment::hasSchema()) {
            return collect();
        }

        return $this->relationLoaded('pricingSegments')
            ? $this->pricingSegments
            : $this->pricingSegments()->with('playstation')->get();
    }

    public function resolveCurrentPlaystation(): ?Playstation
    {
        if ($this->playstation_id) {
            $ps = $this->relationLoaded('playstation') ? $this->playstation : $this->playstation()->first();
            if ($ps) {
                return $ps;
            }
        }

        $device = $this->relationLoaded('device')
            ? $this->device
            : $this->device()->with(['playstations', 'playstation'])->first();

        return $device ? $device->getAvailableCategories()->first() : null;
    }

    public function resolveTotalDurationSeconds(): ?int
    {
        if ($this->duration_seconds !== null && (int) $this->duration_seconds > 0) {
            return (int) $this->duration_seconds;
        }

        if (!empty($this->jam_main)) {
            if ($this->tipe_transaksi === 'custom_package' && is_numeric($this->jam_main)) {
                return (int) round((float) $this->jam_main * 60);
            }
            if (is_numeric($this->jam_main)) {
                return (int) round((float) $this->jam_main * 3600);
            }
            if (is_string($this->jam_main) && preg_match('/^(\d+):(\d{2})(?::(\d{2}))?$/', trim($this->jam_main), $m)) {
                return ((int) $m[1] * 3600) + ((int) $m[2] * 60) + (isset($m[3]) ? (int) $m[3] : 0);
            }
        }

        if (in_array($this->tipe_transaksi, ['prepaid', 'custom_package'], true)) {
            $expires = $this->resolveLegacyExpiresAt();
            $start = $this->getEffectiveStartTime();
            if ($expires && $expires->gt($start)) {
                return (int) $start->diffInSeconds($expires, false);
            }
        }

        return null;
    }

    public function hasGameSettlementInvoice(): bool
    {
        if ($this->relationLoaded('invoices')) {
            return $this->invoices->contains(fn ($inv) => $inv->invoice_type !== Invoice::TYPE_FNB_STANDALONE);
        }

        return $this->invoices()->where('invoice_type', '!=', Invoice::TYPE_FNB_STANDALONE)->exists();
    }
}
