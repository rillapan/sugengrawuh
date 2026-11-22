<?php

namespace App\Services\Fnb;

use App\Models\Fnb;
use App\Models\FnbOrder;
use App\Models\StockMutation;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class FnbStockService
{
    /**
     * Allowed forward kitchen transitions after ACCEPTED.
     */
    private const ALLOWED_TRANSITIONS = [
        FnbOrder::STATUS_ACCEPTED => [
            FnbOrder::STATUS_PREPARING,
            FnbOrder::STATUS_READY,
            FnbOrder::STATUS_DELIVERED,
        ],
        FnbOrder::STATUS_PREPARING => [
            FnbOrder::STATUS_READY,
            FnbOrder::STATUS_DELIVERED,
        ],
        FnbOrder::STATUS_READY => [
            FnbOrder::STATUS_DELIVERED,
        ],
    ];

    /**
     * Accept a PENDING order and atomically deduct finite F&B stock once.
     *
     * @throws InvalidArgumentException
     */
    public function acceptAndDeductStock(int $orderId, ?int $actorUserId = null): FnbOrder
    {
        return DB::transaction(function () use ($orderId) {
            /** @var FnbOrder $order */
            $order = FnbOrder::with('items')
                ->where('id', $orderId)
                ->lockForUpdate()
                ->firstOrFail();

            // Only non-playing orders require upfront payment before kitchen acceptance.
            // Active playing sessions (Paket & Lost Time) can be accepted and prepared directly.
            if (!$order->transaction_id && $order->requires_upfront_payment && $order->payment_status !== 'paid') {
                throw new InvalidArgumentException(
                    'Pesanan Non-Bermain wajib dilunasi di kasir terlebih dahulu sebelum diproses dapur.'
                );
            }

            if (in_array($order->status, [FnbOrder::STATUS_CANCELLED, FnbOrder::STATUS_EXPIRED], true)) {
                throw new InvalidArgumentException('Pesanan sudah dibatalkan atau kedaluwarsa.');
            }

            // Idempotency guard: already accepted or beyond
            if (in_array($order->status, [
                FnbOrder::STATUS_ACCEPTED,
                FnbOrder::STATUS_PREPARING,
                FnbOrder::STATUS_READY,
                FnbOrder::STATUS_DELIVERED,
            ], true)) {
                return $order->fresh(['items.fnb', 'location', 'transaction']);
            }

            $now = Carbon::now();

            // Sort by fnb_id ascending to avoid lock-order deadlocks
            $sortedItems = $order->items->sortBy('fnb_id');

            foreach ($sortedItems as $item) {
                /** @var Fnb $fnb */
                $fnb = Fnb::where('id', $item->fnb_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                // -1 marks unlimited stock
                if ((int) $fnb->stok === -1) {
                    continue;
                }

                if ($fnb->stok < $item->qty) {
                    throw new InvalidArgumentException("Stok {$fnb->nama} tidak mencukupi.");
                }

                $fnb->stok -= $item->qty;
                $fnb->save();

                StockMutation::create([
                    'fnb_id' => $fnb->id,
                    'type' => 'out',
                    'qty' => $item->qty,
                    'date' => $now->toDateString(),
                    'note' => 'Pesanan FnB #' . $order->order_number,
                ]);
            }

            $order->status = FnbOrder::STATUS_ACCEPTED;
            $order->accepted_at = $now;
            $order->save();

            return $order->fresh(['items.fnb', 'location', 'transaction']);
        });
    }

    /**
     * Advance kitchen order status cleanly, delegating ACCEPTED to stock deduction.
     *
     * @throws InvalidArgumentException
     */
    public function advanceStatus(int $orderId, string $targetStatus, ?int $actorUserId = null): FnbOrder
    {
        if ($targetStatus === FnbOrder::STATUS_ACCEPTED) {
            return $this->acceptAndDeductStock($orderId, $actorUserId);
        }

        return DB::transaction(function () use ($orderId, $targetStatus) {
            /** @var FnbOrder $order */
            $order = FnbOrder::where('id', $orderId)
                ->lockForUpdate()
                ->firstOrFail();

            $allowed = self::ALLOWED_TRANSITIONS[$order->status] ?? [];
            if (!in_array($targetStatus, $allowed, true)) {
                throw new InvalidArgumentException(
                    "Transisi status tidak valid dari {$order->status} ke {$targetStatus}."
                );
            }

            $order->status = $targetStatus;
            $order->save();

            return $order->fresh(['items.fnb', 'location', 'transaction']);
        });
    }

    /**
     * Cancel an order and restore finite stock only if it was already deducted.
     * Performs no financial refund logic (OPEN-06).
     *
     * @throws InvalidArgumentException
     */
    public function cancelAndRestoreStock(int $orderId, ?int $actorUserId = null, ?string $reason = null): FnbOrder
    {
        return DB::transaction(function () use ($orderId, $actorUserId, $reason) {
            /** @var FnbOrder $order */
            $order = FnbOrder::with('items')
                ->where('id', $orderId)
                ->lockForUpdate()
                ->firstOrFail();

            $hasInvoice = \App\Models\InvoiceItem::whereIn('fnb_order_item_id', $order->items->pluck('id'))->exists()
                || ($order->transaction_id && \App\Models\Invoice::where('transaction_id', $order->transaction_id)->exists());

            if ($order->payment_status === 'paid' && ($hasInvoice || $order->source !== FnbOrder::SOURCE_QR)) {
                throw new InvalidArgumentException('Pesanan yang sudah dibayar tidak dapat dibatalkan.');
            }

            if (in_array($order->status, [
                FnbOrder::STATUS_CANCELLED,
                FnbOrder::STATUS_EXPIRED,
                FnbOrder::STATUS_DELIVERED,
            ], true)) {
                throw new InvalidArgumentException('Pesanan tidak dapat dibatalkan.');
            }

            $now = Carbon::now();

            $stockWasDeducted = in_array($order->status, [
                FnbOrder::STATUS_ACCEPTED,
                FnbOrder::STATUS_PREPARING,
                FnbOrder::STATUS_READY,
            ], true) || $order->accepted_at !== null;

            if ($stockWasDeducted) {
                $sortedItems = $order->items->sortBy('fnb_id');

                foreach ($sortedItems as $item) {
                    /** @var Fnb|null $fnb */
                    $fnb = Fnb::where('id', $item->fnb_id)
                        ->lockForUpdate()
                        ->first();

                    if (!$fnb || (int) $fnb->stok === -1) {
                        continue;
                    }

                    $fnb->stok += $item->qty;
                    $fnb->save();

                    StockMutation::create([
                        'fnb_id' => $fnb->id,
                        'type' => 'in',
                        'qty' => $item->qty,
                        'date' => $now->toDateString(),
                        'note' => 'Pembatalan Pesanan FnB #' . $order->order_number,
                    ]);
                }
            }

            $order->status = FnbOrder::STATUS_CANCELLED;
            $order->cancelled_at = $now;
            $order->cancelled_by_user_id = $actorUserId;
            $order->cancelled_reason = $reason;
            $order->save();

            return $order->fresh(['items.fnb', 'location', 'transaction']);
        });
    }
}
