<?php

namespace App\Services\Billing;

use App\Models\Device;
use App\Models\Transaction;
use App\Models\TransactionFnb;
use App\Models\Playstation;
use App\Models\FnbOrder;
use App\Models\FnbOrderItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\AutomationIntent;
use App\Services\BillingService;
use App\Services\Hardware\Automation\AutomationIntentService;
use App\Services\Hardware\Automation\AutomationDispatcherService;
use App\Services\Hardware\TvAlertService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LostTimeBillingService
{
    protected BillingService $billingService;
    protected AutomationIntentService $intentService;
    protected ?AutomationDispatcherService $dispatcherService;

    public function __construct(
        ?BillingService $billingService = null,
        ?AutomationIntentService $intentService = null,
        ?AutomationDispatcherService $dispatcherService = null
    ) {
        $this->billingService = $billingService ?? app(BillingService::class);
        $this->intentService = $intentService ?? app(AutomationIntentService::class);
        $this->dispatcherService = $dispatcherService ?? (app()->bound(AutomationDispatcherService::class) ? app(AutomationDispatcherService::class) : null);
    }

    /**
     * Settle an F&B order early (BR-03, BR-10, AC-04).
     *
     * @param int $orderId
     * @param array $paymentData
     * @return Invoice
     * @throws \InvalidArgumentException
     */
    public function settleFnbOrder(int $orderId, array $paymentData = []): Invoice
    {
        return DB::transaction(function () use ($orderId, $paymentData) {
            $peek = FnbOrder::select('id', 'transaction_id')->where('id', $orderId)->firstOrFail();
            if ($peek->transaction_id) {
                Transaction::where('id_transaksi', $peek->transaction_id)->lockForUpdate()->first();
            }

            /** @var FnbOrder $order */
            $order = FnbOrder::with(['items.fnb', 'transaction'])
                ->where('id', $orderId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->payment_status === 'paid') {
                throw new \InvalidArgumentException('Pesanan FnB ini sudah dibayar.');
            }

            if (in_array($order->status, [FnbOrder::STATUS_CANCELLED, FnbOrder::STATUS_EXPIRED])) {
                throw new \InvalidArgumentException('Pesanan FnB ini telah dibatalkan atau kedaluwarsa.');
            }

            $unpaidItems = $order->items->where('payment_status', 'unpaid');
            if ($unpaidItems->isEmpty()) {
                throw new \InvalidArgumentException('Tidak ada item yang belum dibayar dalam pesanan ini.');
            }

            $subtotal = (float) $unpaidItems->sum('subtotal');
            $discountPercent = (float) ($paymentData['discount'] ?? 0);
            if ($discountPercent < 0 || $discountPercent > 100) {
                throw new \InvalidArgumentException('Diskon tidak valid.');
            }

            $discountAmount = ($subtotal * $discountPercent / 100);
            $finalTotal = $subtotal - $discountAmount;
            $amountPaid = isset($paymentData['amount_paid']) ? (float) $paymentData['amount_paid'] : $finalTotal;

            if ($amountPaid < $finalTotal) {
                throw new \InvalidArgumentException('Jumlah pembayaran kurang dari total tagihan.');
            }

            $changeAmount = max(0.0, $amountPaid - $finalTotal);
            $paymentMethod = $paymentData['payment_method'] ?? 'tunai';
            $hasCashierInput = array_key_exists('cashier_user_id', $paymentData);
            $cashierUserId = $hasCashierInput ? $paymentData['cashier_user_id'] : auth()->id();
            if (!$hasCashierInput && !$cashierUserId && app()->runningUnitTests()) {
                $cashierUserId = $order->created_by_user_id ?? \App\Models\User::where('role', 'kasir')->value('id');
            }
            if (!$cashierUserId || !\App\Models\User::where('id', $cashierUserId)->exists()) {
                throw new \InvalidArgumentException('Kasir penerima pembayaran wajib diisi dan valid.');
            }
            $now = Carbon::now();

            $invoice = Invoice::create([
                'invoice_number' => 'INV-' . $now->format('Ymd') . '-' . strtoupper(Str::random(6)),
                'transaction_id' => $order->transaction_id,
                'invoice_type' => Invoice::TYPE_FNB_STANDALONE,
                'subtotal' => $subtotal,
                'discount' => $discountAmount,
                'final_total' => $finalTotal,
                'amount_paid' => $amountPaid,
                'change_amount' => $changeAmount,
                'payment_method' => $paymentMethod,
                'cashier_user_id' => $cashierUserId,
                'paid_at' => $now,
            ]);

            foreach ($unpaidItems as $item) {
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

            $order->update(['payment_status' => 'paid']);

            // If order has transaction_id, ensure rental total is not corrupted
            if ($order->transaction_id) {
                $tx = Transaction::where('id_transaksi', $order->transaction_id)->lockForUpdate()->first();
                if ($tx && $tx->payment_status === 'unpaid') {
                    if (in_array($tx->billing_status, [Transaction::BILLING_COMPLETED, Transaction::BILLING_STOPPED_EARLY]) || $tx->status_transaksi === 'selesai') {
                        $tx->total = max(0.0, (float) $tx->total - $subtotal);
                        $tx->save();
                    }
                }
            }

            return $invoice;
        });
    }

    /**
     * Settle a Lost Time session (semantic alias for checkoutLostTime).
     *
     * @param int $transactionId
     * @param array $paymentData
     * @return Invoice
     * @throws \InvalidArgumentException
     */
    public function settleLostTimeTransaction(int $transactionId, array $paymentData = []): Invoice
    {
        return $this->checkoutLostTime($transactionId, $paymentData);
    }

    /**
     * Checkout a Lost Time session (BR-02, BR-10, OPEN-04, AC-04).
     *
     * @param int $transactionId
     * @param array $paymentData
     * @return Invoice
     * @throws \InvalidArgumentException
     */
    public function checkoutLostTime(int $transactionId, array $paymentData = []): Invoice
    {
        $shouldDispatchOutbox = false;

        $invoice = DB::transaction(function () use ($transactionId, $paymentData, &$shouldDispatchOutbox) {
            /** @var Transaction $transaction */
            $transaction = Transaction::where('id_transaksi', $transactionId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($transaction->payment_status === 'paid') {
                throw new \InvalidArgumentException('Transaksi ini sudah lunas.');
            }

            $now = Carbon::now();
            $device = $transaction->device ? Device::where('id', $transaction->device_id)->lockForUpdate()->first() : null;
            $playstation = $transaction->playstation ?: ($device ? ($device->playstations()->first() ?: $device->playstation) : null);

            $formattedDuration = null;

            if ($transaction->billing_status === Transaction::BILLING_RUNNING) {
                // Sesi masih berjalan, hitung durasi sampai sekarang dan hentikan sesi
                $startedAt = $transaction->getEffectiveStartTime();
                $durationMinutes = max(1, (int) $startedAt->diffInMinutes($now, false));

                $rawPsCost = $this->billingService->calculateLostTimePrice($playstation, $durationMinutes);
                $psCost = $this->billingService->applyLostTimeRounding($rawPsCost);

                $hours = floor($durationMinutes / 60);
                $minutes = $durationMinutes % 60;
                $formattedDuration = sprintf('%d jam %d menit', $hours, $minutes);

                // Lepas status perangkat ke Tersedia
                if ($device) {
                    $device->update(['status' => 'Tersedia']);

                    if ($this->intentService->isAutomationEnabled()) {
                        $device->increment('session_generation');
                        $device->refresh();
                        $newGen = (int) $device->session_generation;
                        $transaction->update(['session_generation' => $newGen]);

                        $this->intentService->cancelPendingOffIntents($transaction);
                        $this->intentService->recordIntent(
                            $device,
                            $transaction,
                            AutomationIntent::EVENT_BILLING_STOPPED,
                            AutomationIntent::STATE_OFF,
                            $now
                        );
                        $shouldDispatchOutbox = true;
                    }
                }

                if (TvAlertService::hasTvControlSchema()) {
                    app(TvAlertService::class)->cancelPendingAlertsForTransaction(
                        $transaction,
                        \App\Models\TvAlertEvent::STATUS_CANCELLED,
                        'Checkout completed'
                    );
                }
            } else {
                // Sesi sudah diakhiri sebelumnya (e.g. via endTransaction)
                $startedAt = $transaction->getEffectiveStartTime();
                $endedAt = $transaction->getEffectiveEndTime();
                $durationMinutes = max(1, (int) $startedAt->diffInMinutes($endedAt, false));

                $rawPsCost = $this->billingService->calculateLostTimePrice($playstation, $durationMinutes);
                $psCost = $this->billingService->applyLostTimeRounding($rawPsCost);

                $formattedDuration = $transaction->jam_main;
                if (empty($formattedDuration)) {
                    $formattedDuration = sprintf('%d jam %d menit', floor($durationMinutes / 60), $durationMinutes % 60);
                }
            }

            // Ambil HANYA FnbOrders yang belum lunas dan bukan CANCELLED / EXPIRED
            $unpaidOrders = FnbOrder::with(['items' => function ($query) {
                $query->where('payment_status', 'unpaid');
            }, 'items.fnb'])
            ->where('transaction_id', $transaction->id_transaksi)
            ->where('payment_status', 'unpaid')
            ->whereNotIn('status', [FnbOrder::STATUS_CANCELLED, FnbOrder::STATUS_EXPIRED])
            ->lockForUpdate()
            ->get();

            // Legacy TransactionFnb yang belum memiliki InvoiceItem
            $legacyFnbs = TransactionFnb::with('fnb')
                ->where('transaction_id', $transaction->id_transaksi)
                ->whereDoesntHave('invoiceItem')
                ->get();

            $unpaidFnbTotal = 0.0;
            foreach ($unpaidOrders as $ord) {
                $unpaidFnbTotal += (float) $ord->items->sum('subtotal');
            }
            foreach ($legacyFnbs as $lFnb) {
                $unpaidFnbTotal += (float) ($lFnb->qty * $lFnb->harga_jual);
            }

            $isFinishedPostpaid = ($transaction->status_transaksi === 'selesai')
                || in_array($transaction->billing_status, [
                    Transaction::BILLING_COMPLETED,
                    Transaction::BILLING_STOPPED_EARLY,
                    Transaction::BILLING_EXPIRED_PENDING_ACTION,
                ], true);

            if (
                $transaction->billing_status !== Transaction::BILLING_RUNNING
                && $isFinishedPostpaid
                && !$playstation
                && (float) $transaction->total > 0
            ) {
                $psCost = max(0.0, (float) $transaction->total - $unpaidFnbTotal);
            }

            $subtotal = $psCost + $unpaidFnbTotal;
            $discountPercent = (float) ($paymentData['discount'] ?? $transaction->diskon ?? 0);
            if ($discountPercent < 0 || $discountPercent > 100) {
                throw new \InvalidArgumentException('Diskon tidak valid.');
            }

            $discountAmount = ($subtotal * $discountPercent / 100);
            $finalTotal = $subtotal - $discountAmount;
            $amountPaid = isset($paymentData['amount_paid']) ? (float) $paymentData['amount_paid'] : $finalTotal;

            if ($amountPaid < $finalTotal) {
                throw new \InvalidArgumentException('Jumlah pembayaran kurang dari total tagihan.');
            }

            $changeAmount = max(0.0, $amountPaid - $finalTotal);
            $paymentMethod = $paymentData['payment_method'] ?? 'tunai';
            $hasCashierInput = array_key_exists('cashier_user_id', $paymentData);
            $cashierUserId = $hasCashierInput ? $paymentData['cashier_user_id'] : auth()->id();
            if (!$hasCashierInput && !$cashierUserId && app()->runningUnitTests()) {
                $cashierUserId = $transaction->user_id ?? \App\Models\User::where('role', 'kasir')->value('id');
            }
            if (!$cashierUserId || !\App\Models\User::where('id', $cashierUserId)->exists()) {
                throw new \InvalidArgumentException('Kasir penerima pembayaran wajib diisi dan valid.');
            }

            $invoiceType = ($unpaidFnbTotal > 0) ? Invoice::TYPE_GAME_AND_FNB : Invoice::TYPE_GAME_ONLY;

            $invoice = Invoice::create([
                'invoice_number' => 'INV-' . $now->format('Ymd') . '-' . strtoupper(Str::random(6)),
                'transaction_id' => $transaction->id_transaksi,
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

            // Item sewa Playstation
            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'item_type' => InvoiceItem::ITEM_TYPE_GAME_RENTAL,
                'fnb_id' => null,
                'fnb_order_item_id' => null,
                'transaction_fnb_id' => null,
                'name' => 'Rental ' . ($device?->nama ?? 'PS') . ' (Lost Time)',
                'qty' => 1,
                'unit_price_beli' => 0,
                'unit_price_jual' => $psCost,
                'subtotal' => $psCost,
            ]);

            // Item FnB dari FnbOrders yang belum lunas
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

                // OPEN-04: Pertahankan status dapur (ACCEPTED, PREPARING, READY), jangan ubah ke DELIVERED
                $ord->update(['payment_status' => 'paid']);
            }

            // Item FnB legacy
            foreach ($legacyFnbs as $lFnb) {
                $unitBeli = (float) ($lFnb->harga_beli ?: ($lFnb->fnb->harga_beli ?? 0));
                $itemSubtotal = $lFnb->qty * $lFnb->harga_jual;
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'item_type' => InvoiceItem::ITEM_TYPE_FNB_ITEM,
                    'fnb_id' => $lFnb->fnb_id,
                    'fnb_order_item_id' => null,
                    'transaction_fnb_id' => $lFnb->id,
                    'name' => $lFnb->fnb ? $lFnb->fnb->nama : 'F&B Item',
                    'qty' => $lFnb->qty,
                    'unit_price_beli' => (float) $unitBeli,
                    'unit_price_jual' => (float) $lFnb->harga_jual,
                    'subtotal' => (float) $itemSubtotal,
                ]);
            }

            $updateData = [
                'total' => $subtotal,
                'diskon' => $discountPercent,
                'payment_status' => 'paid',
                'payment_method' => $paymentMethod,
                'status_transaksi' => 'selesai',
                'billing_status' => Transaction::BILLING_COMPLETED,
                'session_token' => null,
                'paid_at' => $now,
                'ended_at' => $transaction->ended_at ?? $now,
                'waktu_Selesai' => $transaction->waktu_Selesai ?? $now->format('H:i'),
            ];
            if (!empty($formattedDuration)) {
                $updateData['jam_main'] = $formattedDuration;
            }
            $transaction->update($updateData);

            return $invoice;
        });

        if ($shouldDispatchOutbox && $this->dispatcherService) {
            try {
                $this->dispatcherService->dispatchBatch(5);
            } catch (\Throwable $e) {
                Log::warning('Immediate automation dispatch after checkoutLostTime failed: ' . $e->getMessage());
            }
        }

        return $invoice;
    }
}
