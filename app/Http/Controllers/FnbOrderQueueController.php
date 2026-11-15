<?php

namespace App\Http\Controllers;

use App\Models\Fnb;
use App\Models\FnbOrder;
use App\Models\FnbOrderItem;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\Transaction;
use App\Services\Billing\LostTimeBillingService;
use App\Services\Fnb\FnbStockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class FnbOrderQueueController extends Controller
{
    protected FnbStockService $stockService;

    public function __construct(FnbStockService $stockService)
    {
        $this->stockService = $stockService;
    }

    /**
     * Display the unified F&B kitchen queue (QR, Cashier, F&B Staff).
     */
    public function index(Request $request)
    {
        $orders = FnbOrder::with(['items.fnb', 'location', 'transaction.device'])
            ->orderByRaw("FIELD(status, 'PENDING', 'ACCEPTED', 'PREPARING', 'READY', 'DELIVERED', 'CANCELLED', 'EXPIRED')")
            ->orderBy('created_at', 'desc')
            ->get();

        $pendingQrCount = $orders->where('source', FnbOrder::SOURCE_QR)
            ->where('status', FnbOrder::STATUS_PENDING)
            ->count();

        return view('fnb.orders_queue', [
            'title' => 'Antrean Order F&B',
            'active' => 'fnb-queue',
            'orders' => $orders,
            'pendingQrCount' => $pendingQrCount,
        ]);
    }

    /**
     * Poll current queue state for real-time QR order notification & table refresh.
     */
    public function poll(Request $request)
    {
        $orders = FnbOrder::select('id', 'source', 'status', 'payment_status', 'updated_at')
            ->orderBy('id', 'asc')
            ->get();

        $pendingQrOrders = FnbOrder::with(['items.fnb', 'location', 'transaction.device'])
            ->where('source', FnbOrder::SOURCE_QR)
            ->where('status', FnbOrder::STATUS_PENDING)
            ->orderBy('created_at', 'desc')
            ->get();

        $signatureRaw = $orders->map(function ($order) {
            return implode(':', [
                $order->id,
                $order->status,
                $order->payment_status,
                optional($order->updated_at)->timestamp ?? 0,
            ]);
        })->implode('|');

        return response()->json([
            'pending_qr_count' => $pendingQrOrders->count(),
            'pending_qr_orders' => $pendingQrOrders->map(function (FnbOrder $order) {
                $stationName = optional($order->location)->name
                    ?? optional(optional($order->transaction)->device)->nama
                    ?? 'Station';
                $sessionType = null;
                if ($order->transaction) {
                    $sessionType = $order->transaction->tipe_transaksi === 'postpaid'
                        ? 'Lost Time'
                        : 'Paket';
                }

                return [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'customer_name' => $order->customer_name ?? '-',
                    'station_name' => $stationName,
                    'session_type' => $sessionType,
                    'notes' => $order->notes,
                    'total_amount' => (float) $order->total_amount,
                    'created_at_formatted' => optional($order->created_at)->format('H:i:s'),
                    'accept_url' => route('fnb.queue.status', $order->id),
                    'cancel_url' => route('fnb.queue.cancel', $order->id),
                    'items' => $order->items->map(function ($item) {
                        return [
                            'name' => optional($item->fnb)->nama ?? 'Item F&B',
                            'qty' => (int) $item->qty,
                            'notes' => $item->notes,
                            'subtotal' => (float) $item->subtotal,
                        ];
                    })->values(),
                ];
            })->values(),
            'total_orders' => $orders->count(),
            'orders_signature' => sha1($signatureRaw),
        ]);
    }

    /**
     * Create a new F&B order from Cashier or F&B Staff (active session or non-playing walk-in).
     */
    public function storeOrder(Request $request)
    {
        $validated = $request->validate([
            'transaction_id' => 'nullable|integer|exists:transactions,id_transaksi',
            'location_id' => 'nullable|integer|exists:locations,id',
            'customer_name' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.fnb_id' => 'required|integer|exists:fnbs,id',
            'items.*.qty' => 'required|integer|min:1',
            'items.*.notes' => 'nullable|string|max:255',
        ]);

        try {
            $order = DB::transaction(function () use ($validated) {
                $transaction = null;
                $sessionToken = null;
                $locationId = $validated['location_id'] ?? null;
                $requiresUpfront = true;
                $customerInput = trim((string) ($validated['customer_name'] ?? ''));

                if (!empty($validated['transaction_id'])) {
                    $transaction = Transaction::where('id_transaksi', $validated['transaction_id'])
                        ->lockForUpdate()
                        ->firstOrFail();

                    $isRunning = $transaction->billing_status === Transaction::BILLING_RUNNING
                        || in_array(strtolower((string) $transaction->status_transaksi), ['berjalan', 'aktif'], true);

                    if (!$isRunning) {
                        throw new InvalidArgumentException('Sesi bermain untuk transaksi ini tidak sedang berjalan.');
                    }

                    // Lost Time (postpaid) allows checkout settlement; Paket / Custom Package requires upfront payment
                    $requiresUpfront = ($transaction->tipe_transaksi !== 'postpaid');
                    $sessionToken = $transaction->session_token;
                    $billingName = $transaction->nama;
                    $customerName = $customerInput !== '' ? $customerInput : $transaction->nama;

                    if (!$locationId && $transaction->device_id) {
                        $location = Location::where('device_id', $transaction->device_id)
                            ->where('is_active', true)
                            ->first();
                        if ($location) {
                            $locationId = $location->id;
                        }
                    }
                } else {
                    $requiresUpfront = true;
                    $customerName = $customerInput !== '' ? $customerInput : 'Pelanggan Non-Bermain';
                    $billingName = $customerName;
                }

                // Sort items by fnb_id to avoid deadlocks when locking F&B rows
                $sortedItems = collect($validated['items'])->sortBy('fnb_id')->values();
                $resolvedItems = [];

                foreach ($sortedItems as $item) {
                    $qty = (int) $item['qty'];
                    /** @var Fnb $fnb */
                    $fnb = Fnb::with('priceGroup')
                        ->where('id', $item['fnb_id'])
                        ->lockForUpdate()
                        ->firstOrFail();

                    if ((int) $fnb->stok !== -1 && $fnb->stok < $qty) {
                        throw new InvalidArgumentException("Stok {$fnb->nama} tidak mencukupi (tersedia {$fnb->stok}).");
                    }

                    $effectivePrice = $fnb->priceGroup
                        ? (float) $fnb->priceGroup->harga
                        : (float) $fnb->harga_jual;

                    $resolvedItems[] = [
                        'fnb_id' => $fnb->id,
                        'qty' => $qty,
                        'unit_price_beli' => (float) ($fnb->harga_beli ?? 0),
                        'unit_price_jual' => $effectivePrice,
                        'subtotal' => $qty * $effectivePrice,
                        'notes' => $item['notes'] ?? null,
                    ];
                }

                $user = auth()->user();
                $source = $user->isFnbStaff()
                    ? FnbOrder::SOURCE_FNB_STAFF
                    : FnbOrder::SOURCE_CASHIER;

                $order = FnbOrder::create([
                    'order_number' => 'ORD-' . now()->format('ymdHis') . '-' . strtoupper(Str::random(4)),
                    'session_token' => $sessionToken,
                    'source' => $source,
                    'location_id' => $locationId,
                    'transaction_id' => $transaction ? $transaction->id_transaksi : null,
                    'customer_name' => $customerName,
                    'billing_name' => $billingName,
                    'status' => FnbOrder::STATUS_PENDING,
                    'payment_status' => 'unpaid',
                    'requires_upfront_payment' => $requiresUpfront,
                    'notes' => $validated['notes'] ?? null,
                    'created_by_user_id' => $user->id,
                ]);

                foreach ($resolvedItems as $row) {
                    FnbOrderItem::create([
                        'fnb_order_id' => $order->id,
                        'fnb_id' => $row['fnb_id'],
                        'qty' => $row['qty'],
                        'unit_price_beli' => $row['unit_price_beli'],
                        'unit_price_jual' => $row['unit_price_jual'],
                        'subtotal' => $row['subtotal'],
                        'payment_status' => 'unpaid',
                        'notes' => $row['notes'],
                    ]);
                }

                return $order->load(['items.fnb', 'location', 'transaction']);
            });

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'order' => $order,
                ], 201);
            }

            return redirect()->route('fnb.queue.index')
                ->with('success', "Order {$order->order_number} berhasil dibuat.");
        } catch (InvalidArgumentException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
            return back()->withInput()->with('gagal', $e->getMessage());
        }
    }

    /**
     * Advance kitchen status (ACCEPTED deducts finite stock atomically).
     */
    public function updateStatus(Request $request, int $id)
    {
        $validated = $request->validate([
            'status' => 'required|in:ACCEPTED,PREPARING,READY,DELIVERED',
        ]);

        try {
            $order = $this->stockService->advanceStatus($id, $validated['status'], auth()->id());

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'order' => $order,
                ]);
            }

            return redirect()->route('fnb.queue.index')
                ->with('success', "Status order {$order->order_number} diperbarui menjadi {$order->status}.");
        } catch (InvalidArgumentException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
            return back()->with('gagal', $e->getMessage());
        }
    }

    /**
     * Cancel an order and restore finite stock only if previously accepted/deducted.
     */
    public function cancelOrder(Request $request, int $id)
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:255',
        ]);

        try {
            $order = $this->stockService->cancelAndRestoreStock(
                $id,
                auth()->id(),
                $validated['reason'] ?? null
            );

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'order' => $order,
                ]);
            }

            return redirect()->route('fnb.queue.index')
                ->with('success', "Order {$order->order_number} dibatalkan.");
        } catch (InvalidArgumentException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
            return back()->with('gagal', $e->getMessage());
        }
    }

    /**
     * Settle payment for an unpaid F&B order and generate standalone F&B invoice.
     */
    public function payOrder(Request $request, int $id)
    {
        if (auth()->user()->isOwner()) {
            abort(403, 'Unauthorized action.');
        }

        $validated = $request->validate([
            'payment_method' => 'nullable|in:tunai,e-wallet,transfer_bank',
            'discount' => 'nullable|numeric|min:0|max:100',
            'amount_paid' => 'nullable|numeric|min:0',
        ]);

        try {
            $paymentData = [
                'payment_method' => $validated['payment_method'] ?? 'tunai',
                'discount' => $validated['discount'] ?? 0,
                'cashier_user_id' => auth()->id(),
            ];

            if (array_key_exists('amount_paid', $validated) && $validated['amount_paid'] !== null) {
                $paymentData['amount_paid'] = $validated['amount_paid'];
            }

            $invoice = app(LostTimeBillingService::class)->settleFnbOrder($id, $paymentData);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'redirect_url' => route('fnb.invoice.show', $invoice->id),
                ]);
            }

            return redirect()->route('fnb.invoice.show', $invoice->id)
                ->with('success', "Pembayaran berhasil. Invoice #{$invoice->invoice_number}");
        } catch (InvalidArgumentException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
            return back()->with('gagal', $e->getMessage());
        }
    }

    /**
     * Show standalone F&B receipt/invoice.
     */
    public function showInvoice(int $invoiceId)
    {
        $invoice = Invoice::with(['items', 'transaction.device', 'cashierUser'])
            ->findOrFail($invoiceId);

        return view('fnb.invoice_receipt', [
            'invoice' => $invoice,
        ]);
    }
}
