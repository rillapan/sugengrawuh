<?php

namespace App\Services\Billing;

use App\Models\Device;
use App\Models\Transaction;
use App\Models\TransactionFnb;
use App\Models\Fnb;
use App\Models\StockMutation;
use App\Models\Location;
use App\Models\FnbOrder;
use App\Models\FnbOrderItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\AutomationIntent;
use App\Services\Hardware\Automation\AutomationIntentService;
use App\Services\Hardware\Automation\AutomationDispatcherService;
use App\Services\Hardware\TvAlertService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaketBillingService
{
    protected AutomationIntentService $intentService;
    protected ?AutomationDispatcherService $dispatcherService;

    public function __construct(
        ?AutomationIntentService $intentService = null,
        ?AutomationDispatcherService $dispatcherService = null
    ) {
        $this->intentService = $intentService ?? app(AutomationIntentService::class);
        $this->dispatcherService = $dispatcherService ?? (app()->bound(AutomationDispatcherService::class) ? app(AutomationDispatcherService::class) : null);
    }

    /**
     * Activate an upfront draft Paket OR settle an already-started/finished postpaid Paket session.
     *
     * @param Transaction|int $transaction
     * @param array $paymentData
     * @return Invoice
     * @throws \Throwable
     */
    public function activatePaidPaket($transaction, array $paymentData): Invoice
    {
        $transactionId = $transaction instanceof Transaction ? $transaction->id_transaksi : (int) $transaction;
        $shouldDispatchOutbox = false;

        $invoice = DB::transaction(function () use ($transactionId, $paymentData, &$shouldDispatchOutbox) {
            /** @var Transaction $tx */
            $tx = Transaction::with([
                'transactionFnbs.fnb',
                'transactionFnbs.invoiceItem',
                'device',
                'playstation',
                'custom_package.playstations',
                'custom_package.fnbs',
            ])
                ->where('id_transaksi', $transactionId)
                ->lockForUpdate()
                ->firstOrFail();

            // 1. Check idempotency: If already paid, return existing game settlement invoice
            if ($tx->payment_status === 'paid') {
                $existingInvoice = Invoice::where('transaction_id', $tx->id_transaksi)
                    ->where('invoice_type', '!=', Invoice::TYPE_FNB_STANDALONE)
                    ->latest()
                    ->first()
                    ?? Invoice::where('transaction_id', $tx->id_transaksi)->latest()->first();
                if ($existingInvoice) {
                    return $existingInvoice;
                }

                throw new \InvalidArgumentException('Transaksi ini sudah lunas.');
            }

            $wasDraft = ($tx->status_transaksi === 'draft');
            $isLegacyNull = !$wasDraft && $tx->billing_status === null;
            $isLegacyFinished = $isLegacyNull
                && ($tx->status_transaksi === 'selesai' || $tx->ended_at !== null || !$tx->isLegacyActiveSession());
            $isLegacyActive = $isLegacyNull && !$isLegacyFinished;
            $isAlreadyFinished = $isLegacyFinished || (
                !$wasDraft && in_array($tx->billing_status, [
                    Transaction::BILLING_STOPPED_EARLY,
                    Transaction::BILLING_EXPIRED_PENDING_ACTION,
                    Transaction::BILLING_COMPLETED,
                ], true)
            );
            $isUnstartedDraft = $wasDraft || ($tx->billing_status === Transaction::BILLING_IDLE && !$isAlreadyFinished);
            $isAlreadyStarted = !$isUnstartedDraft && !$isAlreadyFinished && (
                $isLegacyActive
                || ($tx->billing_status === Transaction::BILLING_RUNNING && $tx->started_at !== null)
            );

            // 2. Lock and verify device availability where applicable
            $device = $tx->device_id
                ? Device::where('id', $tx->device_id)->lockForUpdate()->firstOrFail()
                : null;
            /** @var \App\Services\BillingService $billingService */
            $billingService = app(\App\Services\BillingService::class);
            $now = Carbon::now(config('app.timezone', 'Asia/Jakarta'));

            if ($isUnstartedDraft) {
                if (!$device) {
                    throw new \RuntimeException('Perangkat untuk transaksi ini tidak ditemukan.');
                }

                $activeSession = $billingService->resolveActiveSessionForDevice((int) $device->id, $now);
                $activeOther = $activeSession && ((int) $activeSession->id_transaksi !== (int) $tx->id_transaksi);

                if ($activeOther) {
                    if ($device->status !== 'Digunakan') {
                        $billingService->syncDeviceStatus(
                            $device,
                            'Digunakan',
                            'PaketBillingService::activatePaidPaket',
                            'STATUS_DESYNC healed stale Tersedia on active station',
                            $activeSession,
                            true,
                            $now
                        );
                    }
                    throw new \RuntimeException('Perangkat "' . $device->nama . '" masih memiliki sesi billing aktif.');
                }

                $isLegacyRunning = ($tx->billing_status === Transaction::BILLING_RUNNING)
                    || ($device->status === 'Digunakan' && !$activeOther);

                if (!$isLegacyRunning && $device->status !== 'Tersedia') {
                    throw new \RuntimeException('Perangkat "' . $device->nama . '" sedang tidak tersedia (' . $device->status . ').');
                }
            } elseif ($isAlreadyStarted && $device) {
                $activeSession = $billingService->resolveActiveSessionForDevice((int) $device->id, $now);
                $activeOther = $activeSession && ((int) $activeSession->id_transaksi !== (int) $tx->id_transaksi);

                if ($activeOther) {
                    if ($device->status !== 'Digunakan') {
                        $billingService->syncDeviceStatus(
                            $device,
                            'Digunakan',
                            'PaketBillingService::activatePaidPaket',
                            'STATUS_DESYNC healed stale Tersedia on active station',
                            $activeSession,
                            true,
                            $now
                        );
                    }
                    throw new \RuntimeException('Perangkat "' . $device->nama . '" masih memiliki sesi billing aktif.');
                }
            }

            // 3. Timing, session token, and payment metadata
            $now = Carbon::now();
            $paymentMethod = $paymentData['payment_method'] ?? 'tunai';
            $discountPercent = (float) ($paymentData['discount'] ?? $tx->diskon ?? 0);
            if ($discountPercent < 0 || $discountPercent > 100) {
                throw new \InvalidArgumentException('Diskon tidak valid.');
            }
            $hasCashierInput = array_key_exists('cashier_user_id', $paymentData);
            $cashierUserId = $hasCashierInput ? $paymentData['cashier_user_id'] : auth()->id();
            if (!$hasCashierInput && !$cashierUserId && app()->runningUnitTests()) {
                $cashierUserId = $tx->user_id ?? \App\Models\User::where('role', 'kasir')->value('id');
            }
            if (!$cashierUserId || !\App\Models\User::where('id', $cashierUserId)->exists()) {
                throw new \InvalidArgumentException('Kasir penerima pembayaran wajib diisi dan valid.');
            }

            if ($isAlreadyStarted || $isAlreadyFinished) {
                $startedAt = $tx->started_at;
                $expiresAt = $tx->expires_at;
                $sessionToken = $tx->session_token;
            } else {
                $startedAt = $now;
                $waktuMulai = $startedAt->format('H:i');

                if ($tx->tipe_transaksi === 'custom_package') {
                    $jamMain = (int) $tx->jam_main;
                    if ($jamMain <= 0 && $tx->custom_package) {
                        $playstationId = $tx->playstation_id ?: $device?->playstation_id;
                        $pkgPs = $tx->custom_package->playstations->where('id', $playstationId)->first();
                        $jamMain = $pkgPs ? (int) $pkgPs->pivot->lama_main : (int) $tx->custom_package->playstations->sum('pivot.lama_main');
                    }
                    if ($jamMain <= 0) {
                        $jamMain = 60;
                    }
                    $expiresAt = $startedAt->copy()->addMinutes($jamMain);
                    $waktuSelesai = $expiresAt->format('H:i');
                } else {
                    $durationSeconds = $tx->duration_seconds;
                    if (!$durationSeconds && $tx->jam_main) {
                        if (is_numeric($tx->jam_main)) {
                            $durationSeconds = (int) round((float) $tx->jam_main * 3600);
                        } elseif (is_string($tx->jam_main) && preg_match('/^(\d+):(\d{2})(?::(\d{2}))?$/', $tx->jam_main, $m)) {
                            $durationSeconds = ($m[1] * 3600) + ($m[2] * 60) + (isset($m[3]) ? (int) $m[3] : 0);
                        }
                    }
                    if (!$durationSeconds || $durationSeconds <= 0) {
                        $durationSeconds = 3600;
                    }
                    $expiresAt = $startedAt->copy()->addSeconds($durationSeconds);
                    $waktuSelesai = ($durationSeconds % 60 === 0) ? $expiresAt->format('H:i') : $expiresAt->format('H:i:s');
                }

                $sessionToken = Str::random(40);
            }

            // 4. Collect unpaid non-cancelled/non-expired FnbOrders and uninvoiced legacy TransactionFnbs
            $unpaidOrders = FnbOrder::with(['items' => function ($query) {
                $query->where('payment_status', 'unpaid');
            }, 'items.fnb'])
                ->where('transaction_id', $tx->id_transaksi)
                ->where('payment_status', 'unpaid')
                ->whereNotIn('status', [FnbOrder::STATUS_CANCELLED, FnbOrder::STATUS_EXPIRED])
                ->lockForUpdate()
                ->get();

            $packageItems = [];
            if ($tx->tipe_transaksi === 'custom_package' && $tx->custom_package) {
                foreach ($tx->custom_package->fnbs as $pFnb) {
                    $packageItems[$pFnb->id] = (int) $pFnb->pivot->quantity;
                }
            }

            $uninvoicedLegacyFnbs = $tx->transactionFnbs->filter(function ($tFnb) {
                return $tFnb->invoiceItem === null;
            });

            $legacyChargeableFnbTotal = 0.0;
            $legacyRowsToInvoice = [];
            foreach ($uninvoicedLegacyFnbs as $tFnb) {
                $qtyInPackage = $packageItems[$tFnb->fnb_id] ?? 0;
                $chargeableQty = max(0, (int) $tFnb->qty - $qtyInPackage);
                if ($chargeableQty > 0) {
                    $lineSubtotal = $chargeableQty * (float) $tFnb->harga_jual;
                    $legacyChargeableFnbTotal += $lineSubtotal;
                    $legacyRowsToInvoice[] = [
                        'tFnb' => $tFnb,
                        'qty' => $chargeableQty,
                        'subtotal' => $lineSubtotal,
                    ];
                }
            }

            $unpaidOrderFnbTotal = 0.0;
            foreach ($unpaidOrders as $ord) {
                $unpaidOrderFnbTotal += (float) $ord->items->sum('subtotal');
            }

            // Deduct stock once and create FnbOrder ONLY if transaction was draft
            $location = $device ? Location::where('device_id', $device->id)->first() : null;
            $orderItemsMap = [];

            if ($wasDraft && $tx->transactionFnbs->isNotEmpty()) {
                foreach ($tx->transactionFnbs as $tFnb) {
                    $fnb = Fnb::lockForUpdate()->find($tFnb->fnb_id);
                    if ($fnb) {
                        if ($fnb->stok != -1 && $fnb->stok < $tFnb->qty) {
                            throw new \RuntimeException('Stok tidak cukup untuk FnB ' . $fnb->nama);
                        }
                        if ($fnb->stok != -1) {
                            $fnb->decrement('stok', $tFnb->qty);
                        }
                        StockMutation::create([
                            'fnb_id' => $fnb->id,
                            'type' => 'out',
                            'qty' => $tFnb->qty,
                            'date' => $now->toDateString(),
                            'note' => 'Penjualan transaksi #' . $tx->id_transaksi,
                        ]);
                    }
                }

                if ($location && !empty($legacyRowsToInvoice)) {
                    $fnbOrder = FnbOrder::create([
                        'order_number' => 'ORD-' . $now->format('Ymd') . '-' . strtoupper(Str::random(6)),
                        'session_token' => $sessionToken,
                        'source' => FnbOrder::SOURCE_CASHIER,
                        'location_id' => $location->id,
                        'transaction_id' => $tx->id_transaksi,
                        'customer_name' => $tx->nama,
                        'billing_name' => $tx->nama,
                        'status' => FnbOrder::STATUS_ACCEPTED,
                        'payment_status' => 'paid',
                        'requires_upfront_payment' => false,
                        'accepted_at' => $now,
                        'created_by_user_id' => $cashierUserId,
                    ]);

                    foreach ($legacyRowsToInvoice as $row) {
                        $tFnb = $row['tFnb'];
                        $fnb = $tFnb->fnb;
                        $priceBeli = (float) ($tFnb->harga_beli ?: ($fnb->harga_beli ?? 0));
                        $priceJual = (float) $tFnb->harga_jual;

                        $oItem = FnbOrderItem::create([
                            'fnb_order_id' => $fnbOrder->id,
                            'fnb_id' => $tFnb->fnb_id,
                            'qty' => $row['qty'],
                            'unit_price_beli' => $priceBeli,
                            'unit_price_jual' => $priceJual,
                            'subtotal' => $row['subtotal'],
                            'payment_status' => 'paid',
                        ]);

                        $orderItemsMap[$tFnb->id] = $oItem;
                    }
                }
            }

            // 5. Calculate totals and settle transaction + invoice
            $rentalTotal = $tx->resolvePaketRentalTotal();
            if ($rentalTotal <= 0 && (float) $tx->total > 0) {
                $rentalTotal = max(0.0, (float) $tx->total - $legacyChargeableFnbTotal);
            }

            $totalFnbChargeable = $legacyChargeableFnbTotal + $unpaidOrderFnbTotal;
            $subtotal = $rentalTotal + $totalFnbChargeable;
            $discountAmount = ($subtotal * $discountPercent / 100);
            $finalTotal = $subtotal - $discountAmount;
            $amountPaid = isset($paymentData['amount_paid']) ? (float) $paymentData['amount_paid'] : $finalTotal;

            if ($amountPaid < $finalTotal) {
                throw new \InvalidArgumentException('Jumlah pembayaran kurang dari total tagihan.');
            }

            $changeAmount = max(0.0, $amountPaid - $finalTotal);

            $updateData = [
                'total' => $subtotal,
                'diskon' => $discountPercent,
                'payment_status' => 'paid',
                'paid_at' => $now,
                'payment_method' => $paymentMethod,
            ];

            if ($isUnstartedDraft) {
                $updateData['started_at'] = $startedAt;
                $updateData['expires_at'] = $expiresAt;
                $updateData['waktu_mulai'] = $waktuMulai;
                $updateData['waktu_Selesai'] = $waktuSelesai;
                $updateData['session_token'] = $sessionToken;
                $updateData['billing_status'] = Transaction::BILLING_RUNNING;
                $updateData['status_transaksi'] = 'sukses';

                $billingService->syncDeviceStatus(
                    $device,
                    'Digunakan',
                    'PaketBillingService::activatePaidPaket',
                    'Paid paket draft activated',
                    $tx,
                    false,
                    $now
                );
            } elseif ($isAlreadyStarted) {
                if ($tx->status_transaksi !== 'sukses') {
                    $updateData['status_transaksi'] = 'sukses';
                }
                if ($device && $device->status !== 'Digunakan') {
                    $billingService->syncDeviceStatus(
                        $device,
                        'Digunakan',
                        'PaketBillingService::activatePaidPaket',
                        'STATUS_DESYNC healed stale Tersedia during running paket payment',
                        $tx,
                        true,
                        $now
                    );
                }
            }

            $tx->update($updateData);

            $invoiceType = ($totalFnbChargeable > 0) ? Invoice::TYPE_GAME_AND_FNB : Invoice::TYPE_GAME_ONLY;

            $invoice = Invoice::create([
                'invoice_number' => 'INV-' . $now->format('Ymd') . '-' . strtoupper(Str::random(6)),
                'transaction_id' => $tx->id_transaksi,
                'invoice_type' => $invoiceType,
                'subtotal' => $subtotal,
                'discount' => $discountAmount,
                'final_total' => $finalTotal,
                'amount_paid' => $amountPaid,
                'change_amount' => $changeAmount,
                'payment_method' => $paymentMethod,
                'cashier_user_id' => $cashierUserId,
                'paid_at' => $now,
            ]);

            $rentalName = 'Rental ' . ($device->nama ?? 'PlayStation');
            if ($tx->tipe_transaksi === 'custom_package' && $tx->custom_package) {
                $rentalName = 'Paket: ' . $tx->custom_package->nama_paket;
            } elseif ($tx->tipe_transaksi === 'prepaid') {
                $rentalName = 'Rental ' . ($device->nama ?? 'PS') . ' (' . ($tx->jam_main ?? '') . ')';
            }

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'item_type' => InvoiceItem::ITEM_TYPE_GAME_RENTAL,
                'fnb_id' => null,
                'fnb_order_item_id' => null,
                'transaction_fnb_id' => null,
                'name' => $rentalName,
                'qty' => 1,
                'unit_price_beli' => 0,
                'unit_price_jual' => $rentalTotal,
                'subtotal' => $rentalTotal,
            ]);

            // Settle unpaid FnbOrders and create FNB InvoiceItems
            foreach ($unpaidOrders as $ord) {
                foreach ($ord->items as $item) {
                    InvoiceItem::create([
                        'invoice_id' => $invoice->id,
                        'item_type' => InvoiceItem::ITEM_TYPE_FNB_ITEM,
                        'fnb_id' => $item->fnb_id,
                        'fnb_order_item_id' => $item->id,
                        'transaction_fnb_id' => null,
                        'name' => $item->fnb ? $item->fnb->nama : 'F&B Item',
                        'qty' => $item->qty,
                        'unit_price_beli' => (float) $item->unit_price_beli,
                        'unit_price_jual' => (float) $item->unit_price_jual,
                        'subtotal' => (float) $item->subtotal,
                    ]);

                    $item->update(['payment_status' => 'paid']);
                }

                $ord->update(['payment_status' => 'paid']);
            }

            // Create FNB InvoiceItems for uninvoiced chargeable legacy TransactionFnbs
            foreach ($legacyRowsToInvoice as $row) {
                $tFnb = $row['tFnb'];
                $fnb = $tFnb->fnb;
                $priceBeli = (float) ($tFnb->harga_beli ?: ($fnb ? $fnb->harga_beli : 0));
                $priceJual = (float) $tFnb->harga_jual;
                $orderItem = $orderItemsMap[$tFnb->id] ?? null;

                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'item_type' => InvoiceItem::ITEM_TYPE_FNB_ITEM,
                    'fnb_id' => $tFnb->fnb_id,
                    'fnb_order_item_id' => $orderItem ? $orderItem->id : null,
                    'transaction_fnb_id' => $orderItem ? null : $tFnb->id,
                    'name' => $fnb ? $fnb->nama : 'F&B Item',
                    'qty' => $row['qty'],
                    'unit_price_beli' => $priceBeli,
                    'unit_price_jual' => $priceJual,
                    'subtotal' => $row['subtotal'],
                ]);
            }

            // 6. Automation and TV alert hooks ONLY when activating an unstarted draft session
            if ($isUnstartedDraft) {
                if ($this->intentService->isAutomationEnabled()) {
                    $this->intentService->assertAutomationReady();
                    $device->increment('session_generation');
                    $device->refresh();
                    $generation = (int) $device->session_generation;
                    $tx->update(['session_generation' => $generation]);

                    $this->intentService->recordIntent(
                        $device,
                        $tx,
                        AutomationIntent::EVENT_BILLING_STARTED,
                        AutomationIntent::STATE_ON,
                        $startedAt
                    );

                    if ($expiresAt) {
                        $this->intentService->recordIntent(
                            $device,
                            $tx,
                            AutomationIntent::EVENT_BILLING_EXPIRED,
                            AutomationIntent::STATE_OFF,
                            $expiresAt
                        );
                    }

                    $shouldDispatchOutbox = true;
                }

                if (TvAlertService::hasTvControlSchema()) {
                    app(TvAlertService::class)->markStalePendingAlertsForStation($device, 'New session started on station');
                }
            }

            return $invoice;
        });

        // Trigger immediate automation outbox dispatch outside the transaction
        if ($shouldDispatchOutbox && $this->dispatcherService) {
            try {
                $this->dispatcherService->dispatchBatch(5);
            } catch (\Throwable $e) {
                \Log::warning('Immediate automation dispatch after activatePaidPaket failed: ' . $e->getMessage());
            }
        }

        return $invoice;
    }
}
