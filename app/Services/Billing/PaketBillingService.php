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
     * Activate a prepaid or custom_package session upon upfront payment.
     *
     * @param Transaction|int $transaction
     * @param array $paymentData
     * @return Invoice
     * @throws \Throwable
     */
    public function activatePaidPaket($transaction, array $paymentData): Invoice
    {
        $transactionId = $transaction instanceof Transaction ? $transaction->id_transaksi : (int) $transaction;

        $invoice = DB::transaction(function () use ($transactionId, $paymentData) {
            /** @var Transaction $tx */
            $tx = Transaction::with(['transactionFnbs.fnb', 'device', 'custom_package.playstations'])
                ->where('id_transaksi', $transactionId)
                ->lockForUpdate()
                ->firstOrFail();

            // 1. Check idempotency: If already paid, return existing invoice
            if ($tx->payment_status === 'paid') {
                $existingInvoice = Invoice::where('transaction_id', $tx->id_transaksi)->latest()->first();
                if ($existingInvoice) {
                    return $existingInvoice;
                }
            }

            // 2. Lock and verify device availability
            $device = Device::where('id', $tx->device_id)->lockForUpdate()->firstOrFail();

            $activeOther = Transaction::where('device_id', $device->id)
                ->where('billing_status', Transaction::BILLING_RUNNING)
                ->where('id_transaksi', '!=', $tx->id_transaksi)
                ->exists();

            if ($activeOther) {
                throw new \RuntimeException('Perangkat "' . $device->nama . '" masih memiliki sesi billing aktif.');
            }

            $isLegacyRunning = ($tx->billing_status === Transaction::BILLING_RUNNING)
                || ($device->status === 'Digunakan' && !$activeOther);

            if (!$isLegacyRunning && $device->status !== 'Tersedia') {
                throw new \RuntimeException('Perangkat "' . $device->nama . '" sedang tidak tersedia (' . $device->status . ').');
            }

            // 3. Timing and session token calculations
            $now = Carbon::now();
            $startedAt = $now;
            $waktuMulai = $startedAt->format('H:i');

            if ($tx->tipe_transaksi === 'custom_package') {
                $jamMain = (int) $tx->jam_main;
                if ($jamMain <= 0 && $tx->custom_package) {
                    $playstationId = $tx->playstation_id ?: $device->playstation_id;
                    $pkgPs = $tx->custom_package->playstations->where('id', $playstationId)->first();
                    $jamMain = $pkgPs ? (int) $pkgPs->pivot->lama_main : (int) $tx->custom_package->playstations->sum('pivot.lama_main');
                }
                if ($jamMain <= 0) {
                    $jamMain = 60;
                }
                $expiresAt = $startedAt->copy()->addMinutes($jamMain);
                $waktuSelesai = $expiresAt->format('H:i');
            } else {
                // Prepaid
                $durationSeconds = $tx->duration_seconds;
                if (!$durationSeconds && $tx->jam_main) {
                    if (is_numeric($tx->jam_main)) {
                        $durationSeconds = (int) round((float) $tx->jam_main * 3600);
                    } elseif (is_string($tx->jam_main) && preg_match('/^(\d+):(\d{2})(?::(\d{2}))?$/', $tx->jam_main, $m)) {
                        $durationSeconds = ($m[1] * 3600) + ($m[2] * 60) + (isset($m[3]) ? (int)$m[3] : 0);
                    }
                }
                if (!$durationSeconds || $durationSeconds <= 0) {
                    $durationSeconds = 3600;
                }
                $expiresAt = $startedAt->copy()->addSeconds($durationSeconds);
                $waktuSelesai = ($durationSeconds % 60 === 0) ? $expiresAt->format('H:i') : $expiresAt->format('H:i:s');
            }

            $sessionToken = Str::random(40);
            $paymentMethod = $paymentData['payment_method'] ?? 'tunai';
            $discountPercent = (float) ($paymentData['discount'] ?? $tx->diskon ?? 0);
            $amountPaid = (float) ($paymentData['amount_paid'] ?? $tx->total);
            $cashierUserId = $paymentData['cashier_user_id'] ?? auth()->id() ?? $tx->user_id ?? 1;

            $wasDraft = ($tx->status_transaksi === 'draft');

            // Update transaction attributes
            $updateData = [
                'started_at' => $startedAt,
                'expires_at' => $expiresAt,
                'waktu_mulai' => $waktuMulai,
                'waktu_Selesai' => $waktuSelesai,
                'session_token' => $sessionToken,
                'billing_status' => Transaction::BILLING_RUNNING,
                'status_transaksi' => 'sukses',
                'payment_status' => 'paid',
                'paid_at' => $now,
                'payment_method' => $paymentMethod,
            ];
            if ($discountPercent > 0) {
                $updateData['diskon'] = $discountPercent;
            }
            $tx->update($updateData);

            // Update device status to Digunakan
            $device->update(['status' => 'Digunakan']);

            // 4. Stock deduction and FnbOrder creation if applicable
            $location = Location::where('device_id', $device->id)->first();
            $fnbOrder = null;

            // Deduct stock once if transaction was draft
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
            }

            // Create FnbOrder + FnbOrderItems if location exists and transaction has FnB items
            $orderItemsMap = [];
            if ($location && $tx->transactionFnbs->isNotEmpty()) {
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

                foreach ($tx->transactionFnbs as $tFnb) {
                    $fnb = $tFnb->fnb;
                    $priceBeli = (float) ($tFnb->harga_beli ?: ($fnb->harga_beli ?? 0));
                    $priceJual = (float) $tFnb->harga_jual;
                    $subtotal = $priceJual * $tFnb->qty;

                    $oItem = FnbOrderItem::create([
                        'fnb_order_id' => $fnbOrder->id,
                        'fnb_id' => $tFnb->fnb_id,
                        'qty' => $tFnb->qty,
                        'unit_price_beli' => $priceBeli,
                        'unit_price_jual' => $priceJual,
                        'subtotal' => $subtotal,
                        'payment_status' => 'paid',
                    ]);

                    $orderItemsMap[$tFnb->id] = $oItem;
                }
            }

            // 5. Create Invoice and InvoiceItems
            $fnbTotal = (float) $tx->fnb_total;
            $subtotal = (float) $tx->total;
            $discountAmount = ($subtotal * $discountPercent / 100);
            $finalTotal = $subtotal - $discountAmount;
            $changeAmount = max(0, $amountPaid - $finalTotal);

            $invoiceType = ($fnbTotal > 0) ? Invoice::TYPE_GAME_AND_FNB : Invoice::TYPE_GAME_ONLY;

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

            // Game Rental item: exact rental total = total - fnbTotal
            $rentalTotal = max(0, $subtotal - $fnbTotal);
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

            // F&B Items
            foreach ($tx->transactionFnbs as $tFnb) {
                $fnb = $tFnb->fnb;
                $priceBeli = $tFnb->harga_beli ?: ($fnb ? $fnb->harga_beli : 0);
                $priceJual = (float) $tFnb->harga_jual;
                $itemSubtotal = $priceJual * $tFnb->qty;

                $orderItem = $orderItemsMap[$tFnb->id] ?? null;

                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'item_type' => InvoiceItem::ITEM_TYPE_FNB_ITEM,
                    'fnb_id' => $tFnb->fnb_id,
                    'fnb_order_item_id' => $orderItem ? $orderItem->id : null,
                    'transaction_fnb_id' => $orderItem ? null : $tFnb->id,
                    'name' => $fnb ? $fnb->nama : 'F&B Item',
                    'qty' => $tFnb->qty,
                    'unit_price_beli' => $priceBeli,
                    'unit_price_jual' => $priceJual,
                    'subtotal' => $itemSubtotal,
                ]);
            }

            // 6. Automation and TV alert hooks
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
            }

            if (TvAlertService::hasTvControlSchema()) {
                app(TvAlertService::class)->markStalePendingAlertsForStation($device, 'New session started on station');
            }

            return $invoice;
        });

        // Trigger immediate automation outbox dispatch outside the transaction
        try {
            if ($this->intentService->isAutomationEnabled() && $this->dispatcherService) {
                $this->dispatcherService->dispatchBatch(5);
            }
        } catch (\Throwable $e) {
            \Log::warning('Immediate automation dispatch after activatePaidPaket failed: ' . $e->getMessage());
        }

        return $invoice;
    }
}
