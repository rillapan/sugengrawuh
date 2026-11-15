<?php

namespace App\Services\Fnb;

use App\Models\Fnb;
use App\Models\FnbOrder;
use App\Models\FnbOrderItem;
use App\Models\Location;
use App\Models\Transaction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class QrOrderService
{
    /**
     * Resolve station menu data, checking for active location and session.
     */
    public function getStationMenuData(string $code): array
    {
        $location = Location::where('code', $code)
            ->where('is_active', true)
            ->firstOrFail();

        $activeSession = null;
        if ($location->device_id) {
            $activeSession = Transaction::where('device_id', $location->device_id)
                ->where('billing_status', Transaction::BILLING_RUNNING)
                ->whereNotNull('session_token')
                ->first();
        }

        if (!$activeSession) {
            return [
                'location' => $location,
                'hasActiveSession' => false,
                'session' => null,
                'token' => null,
                'fnbs' => collect(),
                'playerName' => null,
                'activeOrders' => collect(),
                'sessionType' => null,
            ];
        }

        $activeOrders = FnbOrder::with(['items.fnb'])
            ->where('transaction_id', $activeSession->id_transaksi)
            ->whereIn('status', [
                FnbOrder::STATUS_PENDING,
                FnbOrder::STATUS_ACCEPTED,
                FnbOrder::STATUS_PREPARING,
                FnbOrder::STATUS_READY,
                FnbOrder::STATUS_DELIVERED,
            ])
            ->orderByRaw("FIELD(status, 'READY', 'PREPARING', 'ACCEPTED', 'PENDING', 'DELIVERED')")
            ->orderBy('created_at', 'desc')
            ->get();

        $fnbs = Fnb::with('priceGroup')
            ->where(function ($q) {
                $q->where('stok', '>', 0)
                    ->orWhere('stok', -1);
            })
            ->get()
            ->map(function ($fnb) {
                $fnb->effective_price = $fnb->priceGroup ? (float) $fnb->priceGroup->harga : (float) $fnb->harga_jual;
                return $fnb;
            });

        return [
            'location' => $location,
            'hasActiveSession' => true,
            'session' => $activeSession,
            'token' => $activeSession->session_token,
            'fnbs' => $fnbs,
            'playerName' => $activeSession->nama,
            'activeOrders' => $activeOrders,
            'sessionType' => $activeSession->tipe_transaksi,
        ];
    }

    /**
     * Retrieve active F&B orders for a station's current session.
     *
     * @throws AuthorizationException
     */
    public function getActiveStationOrders(string $code, string $token): array
    {
        $location = Location::where('code', $code)
            ->where('is_active', true)
            ->firstOrFail();

        if (!$location->device_id) {
            throw new AuthorizationException('Sesi tidak valid atau telah berakhir.');
        }

        $activeSession = Transaction::where('device_id', $location->device_id)
            ->where('billing_status', Transaction::BILLING_RUNNING)
            ->whereNotNull('session_token')
            ->first();

        if (!$activeSession || !hash_equals((string) $activeSession->session_token, (string) $token)) {
            throw new AuthorizationException('Sesi tidak valid atau telah berakhir.');
        }

        $orders = FnbOrder::with(['items.fnb'])
            ->where('transaction_id', $activeSession->id_transaksi)
            ->whereIn('status', [
                FnbOrder::STATUS_PENDING,
                FnbOrder::STATUS_ACCEPTED,
                FnbOrder::STATUS_PREPARING,
                FnbOrder::STATUS_READY,
                FnbOrder::STATUS_DELIVERED,
            ])
            ->orderByRaw("FIELD(status, 'READY', 'PREPARING', 'ACCEPTED', 'PENDING', 'DELIVERED')")
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($order) {
                return [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'created_at' => $order->created_at ? $order->created_at->toIso8601String() : null,
                    'created_time' => $order->created_at ? $order->created_at->format('H:i') : null,
                    'created_at_formatted' => $order->created_at ? $order->created_at->format('H:i') : null,
                    'customer_name' => $order->customer_name,
                    'status' => $order->status,
                    'payment_status' => $order->payment_status,
                    'requires_upfront_payment' => (bool) $order->requires_upfront_payment,
                    'total_amount' => (float) $order->total_amount,
                    'notes' => $order->notes,
                    'items' => $order->items->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'fnb_id' => $item->fnb_id,
                            'name' => $item->fnb ? $item->fnb->nama : 'Menu Dihapus',
                            'qty' => (int) $item->qty,
                            'subtotal' => (float) $item->subtotal,
                            'notes' => $item->notes,
                            'image_url' => $item->fnb ? $item->fnb->image_url : asset('img/fnb-placeholder.svg'),
                        ];
                    })->values()->all(),
                ];
            })
            ->values()
            ->all();

        return [
            'location' => $location,
            'session' => $activeSession,
            'playerName' => $activeSession->nama,
            'orders' => $orders,
        ];
    }

    /**
     * Atomically submit an F&B order from a station QR code.
     */
    public function submitStationOrder(string $code, array $payload): FnbOrder
    {
        $idempotencyKey = $payload['idempotency_key'] ?? null;
        if ($idempotencyKey) {
            $cachedOrderId = Cache::get("qr_order_idem:{$idempotencyKey}");
            if ($cachedOrderId) {
                $existingOrder = FnbOrder::with(['items.fnb', 'location'])->find($cachedOrderId);
                if ($existingOrder) {
                    return $existingOrder;
                }
            }
        }

        return DB::transaction(function () use ($code, $payload, $idempotencyKey) {
            $location = Location::where('code', $code)
                ->where('is_active', true)
                ->lockForUpdate()
                ->firstOrFail();

            if (!$location->device_id) {
                throw new AuthorizationException('Lokasi ini belum dikonfigurasi dengan perangkat bermain aktif.');
            }

            // Lock active session
            $activeSession = Transaction::where('device_id', $location->device_id)
                ->where('billing_status', Transaction::BILLING_RUNNING)
                ->lockForUpdate()
                ->first();

            $token = $payload['session_token'] ?? null;
            if (!$activeSession || !$activeSession->session_token || !hash_equals($activeSession->session_token, (string) $token)) {
                throw new AuthorizationException('Sesi bermain tidak valid atau telah berakhir.');
            }

            $items = $payload['items'] ?? [];
            if (empty($items)) {
                throw new InvalidArgumentException('Pilihan menu tidak boleh kosong.');
            }

            // Lock each F&B and validate stock & price
            $resolvedItems = [];
            foreach ($items as $item) {
                $fnbId = $item['fnb_id'] ?? null;
                $qty = (int) ($item['qty'] ?? 1);

                if (!$fnbId || $qty < 1) {
                    throw new InvalidArgumentException('Item menu atau jumlah tidak valid.');
                }

                $fnb = Fnb::where('id', $fnbId)
                    ->with('priceGroup')
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($fnb->stok != -1 && $fnb->stok < $qty) {
                    throw new InvalidArgumentException("Stok {$fnb->nama} tidak mencukupi (tersedia {$fnb->stok}).");
                }

                $effectivePrice = $fnb->priceGroup ? (float) $fnb->priceGroup->harga : (float) $fnb->harga_jual;

                if (isset($item['expected_price']) && abs((float) $item['expected_price'] - $effectivePrice) > 0.01) {
                    throw new InvalidArgumentException("Harga {$fnb->nama} telah diperbarui (Rp " . number_format($effectivePrice, 0, ',', '.') . "). Silakan muat ulang menu.");
                }

                $resolvedItems[] = [
                    'fnb' => $fnb,
                    'qty' => $qty,
                    'unit_price_beli' => (float) ($fnb->harga_beli ?? 0),
                    'unit_price_jual' => $effectivePrice,
                    'subtotal' => $qty * $effectivePrice,
                    'notes' => $item['notes'] ?? null,
                ];
            }

            // Policy: Lost Time (postpaid) allows pay at checkout (requires_upfront_payment = false).
            // Paket / Custom Package requires upfront payment (requires_upfront_payment = true).
            $requiresUpfront = ($activeSession->tipe_transaksi !== 'postpaid');

            $orderNumber = 'ORD-' . now()->format('ymdHis') . '-' . strtoupper(Str::random(4));

            $fnbOrder = FnbOrder::create([
                'order_number' => $orderNumber,
                'session_token' => $activeSession->session_token,
                'source' => FnbOrder::SOURCE_QR,
                'location_id' => $location->id,
                'transaction_id' => $activeSession->id_transaksi,
                'customer_name' => $payload['customer_name'] ?? 'Pelanggan QR',
                'billing_name' => $activeSession->nama,
                'status' => FnbOrder::STATUS_PENDING,
                'payment_status' => 'unpaid',
                'requires_upfront_payment' => $requiresUpfront,
                'notes' => $payload['notes'] ?? null,
            ]);

            foreach ($resolvedItems as $itemData) {
                FnbOrderItem::create([
                    'fnb_order_id' => $fnbOrder->id,
                    'fnb_id' => $itemData['fnb']->id,
                    'qty' => $itemData['qty'],
                    'unit_price_beli' => $itemData['unit_price_beli'],
                    'unit_price_jual' => $itemData['unit_price_jual'],
                    'subtotal' => $itemData['subtotal'],
                    'payment_status' => 'unpaid',
                    'notes' => $itemData['notes'],
                ]);
            }

            if ($idempotencyKey) {
                Cache::put("qr_order_idem:{$idempotencyKey}", $fnbOrder->id, now()->addMinutes(15));
            }

            return $fnbOrder->load(['items.fnb', 'location']);
        });
    }
}
