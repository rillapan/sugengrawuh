<?php

namespace App\Http\Controllers;

use App\Models\Fnb;
use App\Models\FnbOrder;
use App\Models\FnbOrderItem;
use App\Models\Location;
use App\Models\Transaction;
use App\Services\Billing\LostTimeBillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class FnbTransactionController extends Controller
{
    /**
     * Display the F&B transaction creation page.
     */
    public function create(Request $request)
    {
        $activeTransactions = Transaction::with('device')
            ->where('billing_status', Transaction::BILLING_RUNNING)
            ->orderBy('created_at', 'desc')
            ->get();

        $locations = Location::where('is_active', true)
            ->orderBy('name')
            ->get();

        $fnbs = Fnb::with('priceGroup')
            ->where(function ($q) {
                $q->where('stok', '>', 0)
                    ->orWhere('stok', -1);
            })
            ->orderBy('nama')
            ->get()
            ->map(function ($fnb) {
                $fnb->effective_price = $fnb->priceGroup
                    ? (float) $fnb->priceGroup->harga
                    : (float) $fnb->harga_jual;
                return $fnb;
            });

        return view('fnb.transaction_create', [
            'title' => 'Transaksi F&B',
            'active' => 'fnb-transaction',
            'activeTransactions' => $activeTransactions,
            'locations' => $locations,
            'fnbs' => $fnbs,
        ]);
    }

    /**
     * Store a new F&B transaction/order.
     * Handles both 'save' (queue as unpaid) and 'pay_now' (immediate payment & invoice settlement).
     */
    public function store(Request $request)
    {
        if ($request->has('items') && is_array($request->input('items'))) {
            $filtered = array_values(array_filter($request->input('items'), function ($item) {
                return isset($item['qty']) && (int) $item['qty'] > 0;
            }));
            $request->merge(['items' => $filtered]);
        }

        $validated = $request->validate([
            'transaction_id' => 'nullable|integer|exists:transactions,id_transaksi',
            'location_id' => 'nullable|integer|exists:locations,id',
            'customer_name' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.fnb_id' => 'required|integer|exists:fnbs,id',
            'items.*.qty' => 'required|integer|min:1',
            'items.*.notes' => 'nullable|string|max:255',
            'action' => 'nullable|in:save,pay_now',
            'payment_method' => 'nullable|in:tunai,e-wallet,transfer_bank',
            'discount' => 'nullable|numeric|min:0|max:100',
            'amount_paid' => 'nullable|numeric|min:0',
        ]);

        $action = $validated['action'] ?? 'save';

        // RBAC Enforcement: Owner is read-only and not authorized to confirm payment
        if ($action === 'pay_now') {
            if (auth()->user()->isOwner()) {
                abort(403, 'Owner tidak berwenang mengonfirmasi pembayaran.');
            }
        }

        try {
            [$order, $invoice] = DB::transaction(function () use ($validated, $action) {
                $transaction = null;
                $sessionToken = null;
                $locationId = $validated['location_id'] ?? null;
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

                    // Sesi Paket (tipe_transaksi !== 'postpaid') requires upfront payment
                    // Sesi Lost Time (tipe_transaksi === 'postpaid') allows checkout settlement
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

                // Sort items by fnb_id to avoid deadlock during pessimistic lock
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

                $invoice = null;
                if ($action === 'pay_now') {
                    $paymentData = [
                        'payment_method' => $validated['payment_method'] ?? 'tunai',
                        'discount' => $validated['discount'] ?? 0,
                        'cashier_user_id' => $user->id,
                    ];
                    if (array_key_exists('amount_paid', $validated) && $validated['amount_paid'] !== null) {
                        $paymentData['amount_paid'] = $validated['amount_paid'];
                    }

                    $invoice = app(LostTimeBillingService::class)->settleFnbOrder($order->id, $paymentData);

                    // Step setelah diterima pada jenis aktif bermain: langsung ke status ACCEPTED & potong stok
                    app(\App\Services\Fnb\FnbStockService::class)->advanceStatus($order->id, FnbOrder::STATUS_ACCEPTED, $user->id);
                }

                return [$order->load(['items.fnb', 'location', 'transaction']), $invoice];
            });

            if ($action === 'pay_now' && $invoice) {
                if ($request->expectsJson()) {
                    return response()->json([
                        'success' => true,
                        'order' => $order,
                        'invoice' => $invoice,
                        'redirect_url' => route('fnb.invoice.show', $invoice->id),
                    ], 201);
                }

                return redirect()->route('fnb.invoice.show', $invoice->id)
                    ->with('success', "Order {$order->order_number} berhasil dibayar. Invoice #{$invoice->invoice_number}");
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'order' => $order,
                    'redirect_url' => route('fnb.queue.index'),
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
}
