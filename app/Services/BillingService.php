<?php

namespace App\Services;

use App\Models\Device;
use App\Models\Playstation;
use App\Models\Transaction;
use App\Models\CustomPackage;
use App\Models\Fnb;
use App\Models\TransactionFnb;
use App\Models\AutomationIntent;
use App\Models\TvAlertEvent;
use App\Services\Hardware\Automation\AutomationIntentService;
use App\Services\Hardware\TvAlertService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BillingService
{
    protected AutomationIntentService $intentService;

    public function __construct(?AutomationIntentService $intentService = null)
    {
        $this->intentService = $intentService ?? app(AutomationIntentService::class);
    }

    /**
     * Check if IoT hardware automation is currently enabled.
     */
    public function isAutomationActive(): bool
    {
        return $this->intentService->isAutomationEnabled();
    }

    /**
     * Start a new billing session atomically.
     *
     * @param array $data
     * @param int|null $userId
     * @return Transaction
     * @throws \Exception
     */
    public function startSession(array $data, ?int $userId = null): Transaction
    {
        if ($this->isAutomationActive()) {
            $this->intentService->assertAutomationReady();
        }

        return DB::transaction(function () use ($data, $userId) {
            $deviceId = $data['device_id'] ?? null;
            if (!$deviceId) {
                throw new \InvalidArgumentException('Perangkat harus dipilih.');
            }

            // Pessimistic lock on device row to prevent concurrent sessions on the same station
            $device = Device::where('id', $deviceId)->lockForUpdate()->firstOrFail();

            // Validate station availability
            if ($device->status !== 'Tersedia') {
                throw new \RuntimeException('Perangkat "' . $device->nama . '" sedang tidak tersedia (' . $device->status . ').');
            }

            // Check if there is already an active RUNNING billing session for this device
            $activeExisting = Transaction::where('device_id', $deviceId)
                ->where('billing_status', Transaction::BILLING_RUNNING)
                ->exists();

            if ($activeExisting) {
                throw new \RuntimeException('Perangkat "' . $device->nama . '" masih memiliki sesi billing aktif yang belum selesai.');
            }

            $now = Carbon::now();
            $startedAt = $now;
            $waktuMulai = $startedAt->format('H:i');
            $tipeTransaksi = $data['tipe_transaksi'] ?? 'prepaid';

            $transactionData = [
                'status' => 'user',
                'member_id' => $data['member_id'] ?? null,
                'nama' => $data['nama'] ?? 'Pelanggan',
                'no_telepon' => $data['no_telepon'] ?? null,
                'device_id' => $device->id,
                'user_id' => $userId ?? auth()->id() ?? 1,
                'tipe_transaksi' => $tipeTransaksi,
                'waktu_mulai' => $waktuMulai,
                'started_at' => $startedAt,
                'billing_status' => Transaction::BILLING_RUNNING,
                'payment_status' => 'unpaid',
            ];

            // Resolve playstation associated with device
            $playstationId = $data['playstation_id'] ?? null;
            if ($playstationId) {
                $playstation = Playstation::findOrFail($playstationId);
            } else {
                $playstation = $device->playstations()->first() ?: $device->playstation;
            }
            $transactionData['playstation_id'] = $playstation ? $playstation->id : null;

            $totalPs = 0;
            $fnbTotal = 0;

            if ($tipeTransaksi === 'prepaid') {
                $jamMain = (int) ($data['jam_main'] ?? 1);
                if ($jamMain <= 0) {
                    $jamMain = 1;
                }

                $expiresAt = $startedAt->copy()->addHours($jamMain);
                $transactionData['jam_main'] = $jamMain;
                $transactionData['waktu_Selesai'] = $expiresAt->format('H:i');
                $transactionData['expires_at'] = $expiresAt;
                $transactionData['status_transaksi'] = 'sukses';

                // Hourly pricing logic
                if ($playstation) {
                    $hourlyPrice = $playstation->getPriceForHour($jamMain);
                    if ($hourlyPrice !== null) {
                        $totalPs = (float) $hourlyPrice;
                    } else {
                        $totalPs = (float) (($playstation->harga ?? 0) * $jamMain);
                    }
                    $transactionData['harga'] = $playstation->harga ?? 0;
                }

            } elseif ($tipeTransaksi === 'custom_package') {
                $customPackageId = $data['custom_package_id'] ?? null;
                if (!$customPackageId) {
                    throw new \InvalidArgumentException('Paket custom harus dipilih.');
                }
                $customPackage = CustomPackage::with(['playstations', 'fnbs'])->findOrFail($customPackageId);

                // Determine duration for this device's playstation type
                $packagePlaystation = null;
                if ($playstation) {
                    $packagePlaystation = $customPackage->playstations->where('id', $playstation->id)->first();
                }
                $totalMinutes = $packagePlaystation ? (int) $packagePlaystation->pivot->lama_main : (int) $customPackage->playstations->sum('pivot.lama_main');
                if ($totalMinutes <= 0) {
                    $totalMinutes = 60; // Fallback 1 hour
                }

                $expiresAt = $startedAt->copy()->addMinutes($totalMinutes);
                $transactionData['custom_package_id'] = $customPackage->id;
                $transactionData['jam_main'] = $totalMinutes;
                $transactionData['waktu_Selesai'] = $expiresAt->format('H:i');
                $transactionData['expires_at'] = $expiresAt;
                $transactionData['harga'] = $customPackage->harga_total;
                $transactionData['status_transaksi'] = 'sukses';
                $totalPs = (float) $customPackage->harga_total;

            } elseif ($tipeTransaksi === 'postpaid') {
                // Postpaid (Lost Time)
                $transactionData['waktu_Selesai'] = null;
                $transactionData['expires_at'] = null;
                $transactionData['jam_main'] = null;
                $transactionData['status_transaksi'] = 'berjalan';
                $transactionData['lost_time_start'] = $startedAt;
                $transactionData['harga'] = $playstation ? ($playstation->harga ?? 0) : 0;
                $totalPs = 0; // Calculated upon session end
            } else {
                throw new \InvalidArgumentException('Tipe transaksi tidak valid: ' . $tipeTransaksi);
            }

            // Calculate initial FnB from request if any
            $fnbItems = $data['fnbs'] ?? [];
            if (!empty($data['fnb_ids']) && is_array($data['fnb_ids'])) {
                foreach ($data['fnb_ids'] as $idx => $fId) {
                    if ($fId && isset($data['fnbs_qty'][$idx]) && $data['fnbs_qty'][$idx] > 0) {
                        $fnbItems[] = [
                            'fnb_id' => $fId,
                            'qty' => (int) $data['fnbs_qty'][$idx],
                            'harga_jual' => isset($data['fnbs_harga'][$idx]) ? (float) $data['fnbs_harga'][$idx] : null,
                        ];
                    }
                }
            }

            // Initial transaction creation
            $transactionData['total'] = $totalPs;
            $transaction = Transaction::create($transactionData);

            // Process FnB items
            foreach ($fnbItems as $item) {
                $fnb = Fnb::lockForUpdate()->findOrFail($item['fnb_id']);
                $qty = (int) $item['qty'];
                $unitPrice = $item['harga_jual'] !== null ? (float) $item['harga_jual'] : (float) $fnb->harga_jual;

                if ($fnb->stok != -1 && $fnb->stok < $qty) {
                    throw new \RuntimeException('Stok FnB tidak cukup untuk "' . $fnb->nama . '".');
                }

                TransactionFnb::create([
                    'transaction_id' => $transaction->id_transaksi,
                    'fnb_id' => $fnb->id,
                    'qty' => $qty,
                    'harga_jual' => $unitPrice,
                ]);

                if ($fnb->stok != -1) {
                    $fnb->decrement('stok', $qty);
                }

                StockMutation::create([
                    'fnb_id' => $fnb->id,
                    'type' => 'out',
                    'qty' => $qty,
                    'date' => $now->toDateString(),
                    'note' => 'Penjualan transaksi #' . $transaction->id_transaksi,
                ]);

                $fnbTotal += ($qty * $unitPrice);
            }

            // Update total with FnB
            $transaction->update([
                'total' => $totalPs + $fnbTotal,
            ]);

            // Occupy device
            $device->update(['status' => 'Digunakan']);

            $generation = null;
            if ($this->isAutomationActive()) {
                // Bump station generation atomically for the new session
                $device->increment('session_generation');
                $device->refresh();
                $generation = (int) $device->session_generation;
                $transaction->update(['session_generation' => $generation]);

                // Outbox intent: SWITCH_ON immediately
                $this->intentService->recordIntent(
                    $device,
                    $transaction,
                    AutomationIntent::EVENT_BILLING_STARTED,
                    AutomationIntent::STATE_ON,
                    $startedAt
                );

                // If prepaid, pre-schedule SWITCH_OFF at expires_at
                if (isset($expiresAt) && $expiresAt) {
                    $this->intentService->recordIntent(
                        $device,
                        $transaction,
                        AutomationIntent::EVENT_BILLING_EXPIRED,
                        AutomationIntent::STATE_OFF,
                        $expiresAt
                    );
                }
            }

            if (TvAlertService::hasTvControlSchema()) {
                app(TvAlertService::class)->markStalePendingAlertsForStation($device, 'New session started on station');
            }

            Log::info("BillingService: Session started", [
                'id_transaksi' => $transaction->id_transaksi,
                'device_id' => $device->id,
                'session_generation' => $generation,
                'tipe' => $tipeTransaksi,
                'started_at' => $startedAt->toDateTimeString(),
                'expires_at' => isset($expiresAt) ? $expiresAt->toDateTimeString() : null,
            ]);

            return $transaction;
        });
    }

    /**
     * Extend an active session atomically without race conditions.
     *
     * @param int $transactionId
     * @param int $additionalUnits Hours for prepaid, or minutes if $isMinutes is true
     * @param bool $isMinutes
     * @return Transaction
     * @throws \Exception
     */
    public function extendSession(int $transactionId, int $additionalUnits, bool $isMinutes = false): Transaction
    {
        if ($this->isAutomationActive()) {
            $this->intentService->assertAutomationReady();
        }

        return DB::transaction(function () use ($transactionId, $additionalUnits, $isMinutes) {
            $transaction = Transaction::where('id_transaksi', $transactionId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($transaction->billing_status !== Transaction::BILLING_RUNNING) {
                throw new \RuntimeException('Hanya sesi aktif (RUNNING) yang dapat diperpanjang.');
            }

            if ($transaction->tipe_transaksi === 'postpaid') {
                throw new \RuntimeException('Sesi Lost Time (postpaid) tidak memiliki batas durasi untuk diperpanjang.');
            }

            $additionalMinutes = $isMinutes ? $additionalUnits : ($additionalUnits * 60);
            if ($additionalMinutes <= 0) {
                throw new \InvalidArgumentException('Tambahan durasi harus lebih dari 0.');
            }

            // Calculate new expires_at from existing expires_at (or now if past)
            $baseExpiration = $transaction->expires_at ? Carbon::parse($transaction->expires_at) : Carbon::now();
            if ($baseExpiration->isPast()) {
                // If already slightly past, extend from now
                $newExpiresAt = Carbon::now()->addMinutes($additionalMinutes);
            } else {
                $newExpiresAt = $baseExpiration->copy()->addMinutes($additionalMinutes);
            }

            // Calculate new total duration
            $device = $transaction->device;
            $playstation = $transaction->playstation ?: ($device ? $device->playstation : null);

            $newJamMain = $transaction->jam_main;
            $additionalPsPrice = 0;

            if ($transaction->tipe_transaksi === 'prepaid') {
                $addedHours = $isMinutes ? ($additionalUnits / 60) : $additionalUnits;
                $currentHours = is_numeric($transaction->jam_main) ? (int)$transaction->jam_main : 1;
                $totalHours = $currentHours + (int)$addedHours;
                $newJamMain = $totalHours;

                if ($playstation) {
                    $hourlyPrice = $playstation->getPriceForHour($totalHours);
                    if ($hourlyPrice !== null) {
                        $newTotalPs = (float) $hourlyPrice;
                    } else {
                        $newTotalPs = (float) (($playstation->harga ?? 0) * $totalHours);
                    }
                } else {
                    $newTotalPs = (float) (($transaction->harga ?? 0) * $totalHours);
                }
            } else {
                // Custom package extension: treat additional time on base hourly rate
                $newJamMain = (is_numeric($transaction->jam_main) ? (int)$transaction->jam_main : 0) + $additionalMinutes;
                $hourlyRate = $playstation ? ($playstation->harga ?? 0) : ($transaction->harga ?? 0);
                $addedCost = ($hourlyRate / 60) * $additionalMinutes;
                $newTotalPs = (float)$transaction->total + $addedCost;
            }

            $fnbTotal = $transaction->fnb_total;
            $finalTotal = $newTotalPs + $fnbTotal;

            $transaction->update([
                'expires_at' => $newExpiresAt,
                'waktu_Selesai' => $newExpiresAt->format('H:i'),
                'jam_main' => $newJamMain,
                'total' => $finalTotal,
            ]);

            // Bump station generation on extension
            if ($device && $this->isAutomationActive()) {
                $device->increment('session_generation');
                $device->refresh();
                $newGen = (int) $device->session_generation;
                $transaction->update(['session_generation' => $newGen]);

                // Invalidate old pre-scheduled OFF intent
                $this->intentService->cancelPendingOffIntents($transaction);

                // Record new pre-scheduled OFF intent for new_expires_at
                $this->intentService->recordIntent(
                    $device,
                    $transaction,
                    AutomationIntent::EVENT_BILLING_EXPIRED,
                    AutomationIntent::STATE_OFF,
                    $newExpiresAt
                );
            }

            if (TvAlertService::hasTvControlSchema()) {
                app(TvAlertService::class)->cancelPendingAlertsForTransaction(
                    $transaction,
                    TvAlertEvent::STATUS_CANCELLED,
                    'Session extended'
                );
            }

            Log::info("BillingService: Session extended", [
                'id_transaksi' => $transaction->id_transaksi,
                'new_expires_at' => $newExpiresAt->toDateTimeString(),
                'new_total' => $finalTotal,
                'session_generation' => $device ? $device->session_generation : null,
            ]);

            return $transaction;
        });
    }

    /**
     * Stop an active session (manual finish, early stop, or cashier stop).
     *
     * @param int $transactionId
     * @param string $reason
     * @return Transaction
     * @throws \Exception
     */
    public function stopSession(int $transactionId, string $reason = 'manual_stop'): Transaction
    {
        if ($this->isAutomationActive()) {
            $this->intentService->assertAutomationReady();
        }

        return DB::transaction(function () use ($transactionId, $reason) {
            $transaction = Transaction::where('id_transaksi', $transactionId)
                ->lockForUpdate()
                ->firstOrFail();

            // Idempotent guard: if already stopped or completed, return cleanly
            if (in_array($transaction->billing_status, [Transaction::BILLING_COMPLETED, Transaction::BILLING_STOPPED_EARLY])) {
                return $transaction;
            }

            $now = Carbon::now();
            $endedAt = $now;
            $fnbTotal = $transaction->fnb_total;
            $updateData = [
                'ended_at' => $endedAt,
                'waktu_Selesai' => $endedAt->format('H:i'),
                'status_transaksi' => 'selesai',
            ];

            if ($transaction->tipe_transaksi === 'postpaid') {
                $startedAt = $transaction->started_at ?? Carbon::parse($transaction->created_at->toDateString() . ' ' . $transaction->waktu_mulai);
                $durationMinutes = max(1, $startedAt->diffInMinutes($endedAt));

                $playstation = $transaction->playstation ?: ($transaction->device ? $transaction->device->playstation : null);
                $totalPs = $this->calculateLostTimePrice($playstation, $durationMinutes);
                $roundedTotalPs = $this->applyLostTimeRounding($totalPs);

                $hours = floor($durationMinutes / 60);
                $minutes = $durationMinutes % 60;
                $formattedDuration = sprintf('%d jam %d menit', $hours, $minutes);

                $updateData['jam_main'] = $formattedDuration;
                $updateData['total'] = $roundedTotalPs + $fnbTotal;
                $updateData['billing_status'] = Transaction::BILLING_COMPLETED;
            } else {
                // Prepaid or custom package
                if ($transaction->expires_at && $endedAt->lt($transaction->expires_at)) {
                    $updateData['billing_status'] = Transaction::BILLING_STOPPED_EARLY;
                } else {
                    $updateData['billing_status'] = Transaction::BILLING_COMPLETED;
                }
            }

            $transaction->update($updateData);

            // Release device to available, bump generation, and trigger immediate OFF intent
            $station = $transaction->device;
            if ($station) {
                $station->update(['status' => 'Tersedia']);

                if ($this->isAutomationActive()) {
                    $station->increment('session_generation');
                    $station->refresh();
                    $newGen = (int) $station->session_generation;
                    $transaction->update(['session_generation' => $newGen]);

                    // Invalidate pre-scheduled OFF intent
                    $this->intentService->cancelPendingOffIntents($transaction);

                    // Record immediate STOP intent (OFF)
                    $this->intentService->recordIntent(
                        $station,
                        $transaction,
                        AutomationIntent::EVENT_BILLING_STOPPED,
                        AutomationIntent::STATE_OFF,
                        $endedAt
                    );
                }
            }

            if (TvAlertService::hasTvControlSchema()) {
                app(TvAlertService::class)->cancelPendingAlertsForTransaction(
                    $transaction,
                    TvAlertEvent::STATUS_CANCELLED,
                    'Session stopped'
                );
            }

            Log::info("BillingService: Session stopped", [
                'id_transaksi' => $transaction->id_transaksi,
                'reason' => $reason,
                'billing_status' => $updateData['billing_status'],
                'session_generation' => $station ? $station->session_generation : null,
                'ended_at' => $endedAt->toDateTimeString(),
            ]);

            return $transaction;
        });
    }

    /**
     * Evaluate expired sessions (called by background worker or ticker).
     *
     * @param Carbon|null $now
     * @return array
     */
    public function evaluateExpiration(?Carbon $now = null): array
    {
        $now = $now ?: Carbon::now();

        if (!Transaction::hasG2Schema()) {
            return [
                'count' => 0,
                'processed' => [],
                'evaluated_at' => $now->toDateTimeString(),
                'notice' => 'G2 schema (billing_status/ended_at) belum diterapkan pada database ini. Evaluasi kedaluwarsa dilewati.',
            ];
        }

        $expiredSessions = Transaction::where('billing_status', Transaction::BILLING_RUNNING)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->get();

        $processed = [];

        foreach ($expiredSessions as $session) {
            // Process each session in an atomic sub-transaction with pessimistic lock
            try {
                DB::transaction(function () use ($session, $now, &$processed) {
                    $lockedTx = Transaction::where('id_transaksi', $session->id_transaksi)
                        ->lockForUpdate()
                        ->first();

                    if (!$lockedTx) {
                        return;
                    }

                    // Re-verify conditions under lock to prevent double execution by concurrent workers
                    if ($lockedTx->billing_status !== Transaction::BILLING_RUNNING) {
                        return;
                    }

                    if (!$lockedTx->expires_at || $lockedTx->expires_at->gt($now)) {
                        return; // Session might have been extended just before locking
                    }

                    $lockedTx->update([
                        'ended_at' => $lockedTx->expires_at,
                        'billing_status' => Transaction::BILLING_EXPIRED_PENDING_ACTION,
                        'status_transaksi' => 'selesai',
                    ]);

                    if ($lockedTx->device) {
                        $lockedTx->device->update(['status' => 'Tersedia']);

                        if ($this->isAutomationActive()) {
                            // Idempotent safety net: ensure BILLING_EXPIRED intent exists
                            $this->intentService->recordIntent(
                                $lockedTx->device,
                                $lockedTx,
                                AutomationIntent::EVENT_BILLING_EXPIRED,
                                AutomationIntent::STATE_OFF,
                                $lockedTx->expires_at
                            );
                        }
                    }

                    if (TvAlertService::hasTvControlSchema()) {
                        app(TvAlertService::class)->cancelPendingAlertsForTransaction(
                            $lockedTx,
                            TvAlertEvent::STATUS_EXPIRED,
                            'Session expired'
                        );
                    }

                    $processed[] = [
                        'id_transaksi' => $lockedTx->id_transaksi,
                        'device_id' => $lockedTx->device_id,
                        'expires_at' => $lockedTx->expires_at->toDateTimeString(),
                        'billing_status' => Transaction::BILLING_EXPIRED_PENDING_ACTION,
                    ];

                    Log::info("BillingService: Session expired", [
                        'id_transaksi' => $lockedTx->id_transaksi,
                        'device_id' => $lockedTx->device_id,
                        'expires_at' => $lockedTx->expires_at->toDateTimeString(),
                    ]);
                });
            } catch (\Exception $e) {
                Log::error("BillingService: Failed to expire session #{$session->id_transaksi}: " . $e->getMessage());
            }
        }

        return [
            'count' => count($processed),
            'processed' => $processed,
            'evaluated_at' => $now->toDateTimeString(),
        ];
    }

    /**
     * Crash recovery and session reconciliation (called upon boot or restart).
     *
     * @param Carbon|null $now
     * @return array
     */
    public function reconcileSessions(?Carbon $now = null): array
    {
        $now = $now ?: Carbon::now();
        $report = [
            'expired_processed' => 0,
            'postpaid_survived' => 0,
            'devices_healed' => 0,
            'timestamp' => $now->toDateTimeString(),
        ];

        if (!Transaction::hasG2Schema()) {
            return $report;
        }

        // 1. Process overdue prepaid/packages
        $expirationResult = $this->evaluateExpiration($now);
        $report['expired_processed'] = $expirationResult['count'];

        // 2. Audit running postpaid sessions (must survive restart without being terminated)
        $runningPostpaid = Transaction::where('billing_status', Transaction::BILLING_RUNNING)
            ->where('tipe_transaksi', 'postpaid')
            ->count();
        $report['postpaid_survived'] = $runningPostpaid;

        // 3. Heal station availability discrepancies
        $allDevices = Device::all();
        foreach ($allDevices as $device) {
            $hasActiveSession = Transaction::where('device_id', $device->id)
                ->where('billing_status', Transaction::BILLING_RUNNING)
                ->exists();

            if (!$hasActiveSession && $device->status === 'Digunakan') {
                $device->update(['status' => 'Tersedia']);
                $report['devices_healed']++;
                Log::warning("BillingService [Reconcile]: Device {$device->nama} (ID: {$device->id}) healed from Digunakan to Tersedia (no active session).");
            } elseif ($hasActiveSession && $device->status === 'Tersedia') {
                $device->update(['status' => 'Digunakan']);
                $report['devices_healed']++;
                Log::warning("BillingService [Reconcile]: Device {$device->nama} (ID: {$device->id}) restored from Tersedia to Digunakan (active session found).");
            }
        }

        Log::info("BillingService [Reconcile] Completed", $report);
        return $report;
    }

    /**
     * Calculate lost time price based on hourly packages and per-minute breakdown.
     *
     * @param Playstation|null $playstation
     * @param int $durationMinutes
     * @return float
     */
    public function calculateLostTimePrice(?Playstation $playstation, int $durationMinutes): float
    {
        if (!$playstation) {
            return 0.0;
        }

        $hourlyPrices = $playstation->getAvailableHoursWithPrices();
        $basePrice = (float) ($playstation->harga ?? 0);
        $costPerMinute = $basePrice / 60;

        $fullHours = (int) floor($durationMinutes / 60);
        $remainingMinutes = (int) ($durationMinutes % 60);

        $totalPrice = 0.0;

        if ($fullHours > 0) {
            if (isset($hourlyPrices[$fullHours])) {
                $totalPrice += (float) $hourlyPrices[$fullHours];
            } else {
                $totalPrice += $basePrice * $fullHours;
            }
        }

        if ($remainingMinutes > 0) {
            $totalPrice += $remainingMinutes * $costPerMinute;
        }

        return $totalPrice;
    }

    /**
     * Rounding rules for lost time:
     * - Exactly 0 mod 1000 => unchanged
     * - <= 500 => round up to nearest 500
     * - > 500 => round up to nearest 1000
     *
     * @param float $total
     * @return float
     */
    public function applyLostTimeRounding(float $total): float
    {
        $lowerThousand = floor($total / 1000) * 1000;
        $difference = $total - $lowerThousand;

        if ($difference == 0) {
            return $total;
        } elseif ($difference <= 500) {
            return $lowerThousand + 500;
        } else {
            return $lowerThousand + 1000;
        }
    }
}
