<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Playstation;
use App\Models\Transaction;
use App\Models\Fnb;
use App\Models\TransactionFnb;
use App\Models\StockMutation;
use App\Models\CustomPackage;
use App\Models\Location;
use App\Models\TvAlertEvent;
use App\Services\Hardware\TvAlertService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class TransactionController extends Controller
{
    private function updateDeviceStatuses()
    {
        // This method is removed because DeviceController@index has more comprehensive status update logic
    }

    public function showPayment($id)
    {
        $transaction = Transaction::with([
            'device.playstation', 
            'playstation',
            'transactionFnbs.fnb', 
            'transactionFnbs.invoiceItem',
            'user', 
            'custom_package.playstations', 
            'custom_package.fnbs',
            'fnbOrders.items.fnb',
            'invoices.items'
        ])->findOrFail($id);

        if ($transaction->payment_status === 'paid') {
            return redirect()->route('transaction.print', $transaction->id_transaksi)
                ->with('gagal', 'Transaksi ini sudah lunas.');
        }

        $isPaket = in_array($transaction->tipe_transaksi, ['prepaid', 'custom_package'], true);
        $unpaidFnbOrdersTotal = $transaction->unpaid_fnb_order_total;
        $paidFnbOrdersTotal = $transaction->paid_fnb_order_total;
        $legacyFnbTotal = $transaction->uninvoiced_legacy_fnb_total;
        $totalFnbToPay = $legacyFnbTotal + $unpaidFnbOrdersTotal;

        if ($isPaket && $transaction->payment_status === 'unpaid') {
            $psCostDisplay = $transaction->resolvePaketRentalTotal();
            $payableSubtotal = $psCostDisplay + $totalFnbToPay;
        } else {
            $payableSubtotal = (float) $transaction->total;
            $psCostDisplay = max(0, $payableSubtotal - $totalFnbToPay);
        }

        return view('transaction.payment', [
            'title' => 'Pembayaran Transaksi',
            'active' => 'transaction',
            'transaction' => $transaction,
            'psCostDisplay' => $psCostDisplay,
            'legacyFnbTotal' => $legacyFnbTotal,
            'unpaidFnbOrdersTotal' => $unpaidFnbOrdersTotal,
            'paidFnbOrdersTotal' => $paidFnbOrdersTotal,
            'totalFnbToPay' => $totalFnbToPay,
            'payableSubtotal' => $payableSubtotal,
        ]);
    }

    public function printReceipt($id)
    {
        $transaction = Transaction::with([
            'device.playstation', 
            'transactionFnbs.fnb', 
            'user', 
            'custom_package.playstations', 
            'custom_package.fnbs',
            'fnbOrders.items.fnb',
            'invoices.items'
        ])->findOrFail($id);
        
        // Retrieve temporary payment data from session if available
        $amountPaid = session('amount_paid');
        $change = session('change');
        
        // If viewing from history (no session data), assume exact payment or calculate if we had the field
        if ($amountPaid === null) {
            // For older transactions or re-prints, we don't have the exact amount paid recorded on this table structure yet
            // So we display standard info
        } else {
            // Inject into transaction object temporarily for the view
            $transaction->bayar_nominal = $amountPaid;
            $transaction->kembalian = $change;
        }

        // Preserve flash messages for the next redirect (to index)
        session()->keep(['success', 'gagal']);

        return view('transaction.receipt', [
            'transaction' => $transaction
        ]);
    }

    public function processPayment(Request $request, $id)
    {
        $discount = (float) $request->input('discount', 0);
        $amountPaid = (float) $request->input('amount_paid');
        $paymentMethod = $request->input('payment_method');

        $transaction = Transaction::where('id_transaksi', $id)->firstOrFail();

        if ($transaction->payment_status === 'paid') {
            return redirect()->route('transaction.print', $transaction->id_transaksi)
                ->with('gagal', 'Transaksi ini sudah lunas.');
        }

        $payableSubtotal = in_array($transaction->tipe_transaksi, ['prepaid', 'custom_package'], true)
            ? $transaction->paket_payable_total
            : (float) $transaction->total;

        $finalTotal = $payableSubtotal - ($payableSubtotal * $discount / 100);

        $request->validate([
            'payment_method' => 'required|in:tunai,e-wallet,transfer_bank',
            'amount_paid' => 'required|numeric|min:' . $finalTotal,
            'discount' => 'nullable|numeric|min:0|max:100',
        ]);

        if (in_array($transaction->tipe_transaksi, ['prepaid', 'custom_package'])) {
            $paketBillingService = app(\App\Services\Billing\PaketBillingService::class);
            try {
                $invoice = $paketBillingService->activatePaidPaket($transaction, [
                    'payment_method' => $paymentMethod,
                    'amount_paid' => $amountPaid,
                    'discount' => $discount,
                    'cashier_user_id' => auth()->id(),
                ]);

                return redirect()->route('transaction.print', ['id' => $transaction->id_transaksi, 'action' => 'payment'])
                    ->with([
                        'success' => 'Pembayaran berhasil. Kembalian: Rp ' . number_format($invoice->change_amount, 0, ',', '.'),
                        'amount_paid' => $invoice->amount_paid,
                        'change' => $invoice->change_amount
                    ]);
            } catch (\InvalidArgumentException $e) {
                $transaction->refresh();
                if ($transaction->payment_status === 'paid') {
                    return redirect()->route('transaction.print', $transaction->id_transaksi)
                        ->with('gagal', 'Transaksi ini sudah lunas.');
                }
                return redirect()->back()->with('gagal', $e->getMessage());
            }
        }

        if ($transaction->tipe_transaksi === 'postpaid') {
            $lostTimeBillingService = app(\App\Services\Billing\LostTimeBillingService::class);
            try {
                $invoice = $lostTimeBillingService->checkoutLostTime($transaction->id_transaksi, [
                    'payment_method' => $paymentMethod,
                    'amount_paid' => $amountPaid,
                    'discount' => $discount,
                    'cashier_user_id' => auth()->id(),
                ]);

                return redirect()->route('transaction.print', ['id' => $transaction->id_transaksi, 'action' => 'payment'])
                    ->with([
                        'success' => 'Pembayaran berhasil. Kembalian: Rp ' . number_format($invoice->change_amount, 0, ',', '.'),
                        'amount_paid' => $invoice->amount_paid,
                        'change' => $invoice->change_amount
                    ]);
            } catch (\InvalidArgumentException $e) {
                $transaction->refresh();
                if ($transaction->payment_status === 'paid') {
                    return redirect()->route('transaction.print', $transaction->id_transaksi)
                        ->with('gagal', 'Transaksi ini sudah lunas.');
                }
                return redirect()->back()->with('gagal', $e->getMessage());
            }
        }

        $result = DB::transaction(function () use ($id, $discount, $amountPaid, $paymentMethod, $finalTotal) {
            // Pessimistic write lock (SELECT ... FOR UPDATE) to prevent race conditions on concurrent payment requests
            $transaction = Transaction::where('id_transaksi', $id)->lockForUpdate()->firstOrFail();

            if ($transaction->payment_status === 'paid') {
                return [
                    'already_paid' => true,
                    'transaction' => $transaction
                ];
            }

            $change = $amountPaid - $finalTotal;

            $updateData = [
                'payment_status' => 'paid',
                'payment_method' => $paymentMethod,
                'status_transaksi' => 'selesai',
                'paid_at' => now(),
            ];

            if ($discount > 0) {
                $updateData['diskon'] = $discount;
            }

            $transaction->update($updateData);

            // Update device status to available ONLY IF the billing session has ended
            if ($transaction->device) {
                if (Transaction::hasG2Schema()) {
                    $isSessionStillRunning = ($transaction->billing_status === Transaction::BILLING_RUNNING)
                        && ($transaction->expires_at && $transaction->expires_at->isFuture());

                    if (!$isSessionStillRunning) {
                        $transaction->device->update(['status' => 'Tersedia']);
                        if ($transaction->billing_status === Transaction::BILLING_RUNNING) {
                            $transaction->update(['billing_status' => Transaction::BILLING_COMPLETED]);
                        }
                    }
                } else {
                    $transaction->device->update(['status' => 'Tersedia']);
                }
            }

            return [
                'already_paid' => false,
                'transaction' => $transaction,
                'change' => $change,
                'amount_paid' => $amountPaid
            ];
        });

        if ($result['already_paid']) {
            return redirect()->route('transaction.print', $result['transaction']->id_transaksi)
                ->with('gagal', 'Transaksi ini sudah lunas.');
        }

        return redirect()->route('transaction.print', ['id' => $result['transaction']->id_transaksi, 'action' => 'payment'])
            ->with([
                'success' => 'Pembayaran berhasil. Kembalian: Rp ' . number_format($result['change'], 0, ',', '.'),
                'amount_paid' => $result['amount_paid'],
                'change' => $result['change']
            ]);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $now = Carbon::now();

        // Non-blocking expiration & hardware automation heartbeat (mirrors DeviceController@index without inline mutation loop)
        try {
            if (Transaction::hasG2Schema()) {
                $hasExpired = Transaction::where('billing_status', Transaction::BILLING_RUNNING)
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '<=', $now)
                    ->exists();

                if ($hasExpired) {
                    app(\App\Services\BillingService::class)->evaluateExpiration($now);
                }
            }

            if (\App\Services\Hardware\Automation\AutomationIntentService::hasG5Schema() &&
                app(\App\Services\Hardware\Automation\AutomationIntentService::class)->isAutomationEnabled()) {
                $hasEligibleIntents = \App\Models\AutomationIntent::eligible($now)->exists();
                if ($hasEligibleIntents) {
                    app(\App\Services\Hardware\Automation\AutomationDispatcherService::class)->dispatchBatch(10, $now);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('TransactionController index heartbeat issue: ' . $e->getMessage());
        }

        $type = $request->input('type', 'all');
        $search = trim((string) $request->input('search', ''));
        $activeTab = $request->filled('tab')
            ? $request->input('tab')
            : (($request->has('page') || $request->filled('type') || $request->filled('search')) ? 'history' : 'stations');

        $query = Transaction::with(['device.playstation', 'transactionFnbs.fnb', 'custom_package']);

        $userRole = strtolower(auth()->user()->role ?? '');
        if (!in_array($userRole, ['admin', 'kasir', 'owner'])) {
            $query->where('user_id', auth()->user()->id);
        }

        if ($type !== 'all') {
            $query->where('tipe_transaksi', $type);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('id_transaksi', 'like', "%{$search}%")
                    ->orWhere('nama', 'like', "%{$search}%")
                    ->orWhereHas('device', fn ($dq) => $dq->where('nama', 'like', "%{$search}%"));
            });
        }

        $transaction = $query->latest()->paginate(10)->appends($request->query());

        // Calculate counts for transaction types and payment statuses
        $postpaidCount = Transaction::where('tipe_transaksi', 'postpaid')->count();
        $prepaidCount = Transaction::where('tipe_transaksi', 'prepaid')->count();
        $customPackageCount = Transaction::where('tipe_transaksi', 'custom_package')->count();
        $paidCount = Transaction::where('payment_status', 'paid')->count();
        $unpaidCount = Transaction::where('payment_status', 'unpaid')->count();

        // Calculate payment method breakdown for paid transactions
        $paymentMethodCounts = [
            'tunai' => Transaction::where('payment_status', 'paid')->where('payment_method', 'tunai')->count(),
            'e-wallet' => Transaction::where('payment_status', 'paid')->where('payment_method', 'e-wallet')->count(),
            'transfer_bank' => Transaction::where('payment_status', 'paid')->where('payment_method', 'transfer_bank')->count(),
        ];

        $paymentMethodTotals = [
            'tunai' => Transaction::where('payment_status', 'paid')->where('payment_method', 'tunai')->sum('total'),
            'e-wallet' => Transaction::where('payment_status', 'paid')->where('payment_method', 'e-wallet')->sum('total'),
            'transfer_bank' => Transaction::where('payment_status', 'paid')->where('payment_method', 'transfer_bank')->sum('total'),
        ];

        // Load all registered stations deterministically (no pagination cut-off, eager-loaded)
        $deviceRelations = ['playstations.hourlyPrices', 'playstation.hourlyPrices'];
        if (Schema::hasTable('station_device_mappings')) {
            $deviceRelations[] = 'activeHardwareMappings.physicalDevice';
            $deviceRelations[] = 'activeDisplayTv.physicalDevice';
        }
        $hasLocationsTable = Schema::hasTable('locations');
        if ($hasLocationsTable) {
            $deviceRelations[] = 'locations';
        }

        $devices = Device::sortStationsDeterministically(
            Device::with($deviceRelations)->get()
        );

        $stationNumbers = Device::resolveGlobalStationNumbers($devices);
        foreach ($devices as $device) {
            $resolvedNumber = $stationNumbers[$device->id] ?? null;
            $device->setAttribute('station_number', $resolvedNumber);
        }

        // Load active transactions for occupied/running devices in one batch query (no N+1)
        $activeTransactions = collect();
        $deviceIds = $devices->pluck('id');
        $activeTxRelations = [
            'playstation',
            'custom_package.playstations',
            'custom_package.fnbs',
            'transactionFnbs.fnb',
            'transactionFnbs.invoiceItem',
            'fnbOrders.items.fnb',
            'invoices',
        ];

        if ($deviceIds->isNotEmpty()) {
            if (Transaction::hasG2Schema()) {
                $activeTransactions = Transaction::with($activeTxRelations)
                    ->whereIn('device_id', $deviceIds)
                    ->where('billing_status', Transaction::BILLING_RUNNING)
                    ->latest('id_transaksi')
                    ->get()
                    ->groupBy('device_id')
                    ->map
                    ->first();
            } else {
                $activeTransactions = Transaction::with($activeTxRelations)
                    ->whereIn('device_id', $deviceIds)
                    ->where('status_transaksi', 'berjalan')
                    ->latest('id_transaksi')
                    ->get()
                    ->groupBy('device_id')
                    ->map
                    ->first();
            }

            // Legacy fallback for occupied devices not matched by BILLING_RUNNING / berjalan
            $occupiedWithoutActiveTx = $devices
                ->filter(fn ($d) => $d->status === 'Digunakan' && !$activeTransactions->has($d->id))
                ->pluck('id');

            if ($occupiedWithoutActiveTx->isNotEmpty()) {
                $today = $now->toDateString();
                $legacyCandidates = Transaction::with($activeTxRelations)
                    ->whereIn('device_id', $occupiedWithoutActiveTx)
                    ->whereDate('created_at', $today)
                    ->latest('id_transaksi')
                    ->get()
                    ->groupBy('device_id');

                foreach ($legacyCandidates as $devId => $candidates) {
                    $match = $candidates->first(function ($tx) use ($now) {
                        if ($tx->status_transaksi === 'berjalan') {
                            return true;
                        }
                        if (in_array($tx->tipe_transaksi, ['prepaid', 'custom_package'], true)
                            && $tx->status_transaksi !== 'selesai'
                            && $tx->waktu_Selesai) {
                            $txDate = $tx->created_at->format('Y-m-d');
                            $startDt = Carbon::parse($txDate . ' ' . $tx->waktu_mulai);
                            $endDt = Carbon::parse($txDate . ' ' . $tx->waktu_Selesai);
                            if ($endDt < $startDt) {
                                $endDt->addDay();
                            }
                            $targetExpiresAt = $tx->expires_at ?: $endDt;
                            return $targetExpiresAt > $now;
                        }
                        return false;
                    });

                    if ($match) {
                        $activeTransactions->put($devId, $match);
                    }
                }
            }
        }

        $stationStats = [
            'total' => $devices->count(),
            'available' => $devices->where('status', 'Tersedia')->count(),
            'in_use' => $devices->where('status', 'Digunakan')->count(),
            'unpaid_attention' => Transaction::where('payment_status', 'unpaid')
                ->where('status_transaksi', '!=', 'draft')
                ->count(),
        ];

        $customPackages = CustomPackage::active()
            ->with(['playstations', 'fnbs', 'priceGroups'])
            ->get();

        $fnbs = Fnb::with('priceGroup')
            ->where(function ($q) {
                $q->where('stok', '>', 0)->orWhere('stok', -1);
            })
            ->orderBy('nama')
            ->get()
            ->map(function ($fnb) {
                $fnb->effective_price = $fnb->priceGroup
                    ? (float) $fnb->priceGroup->harga
                    : (float) $fnb->harga_jual;
                return $fnb;
            });

        $locations = $hasLocationsTable
            ? Location::where('is_active', true)->orderBy('name')->get()
            : collect();

        $currentNow = Carbon::now();
        $serverTimestamp = $currentNow->timestamp * 1000;
        $serverNow = $currentNow->toIso8601String();

        return view('transaction.index', [
            'title' => 'Data Tansaksi',
            'active' => 'transaction',
            'transactions' => $transaction,
            'currentType' => $type,
            'postpaidCount' => $postpaidCount,
            'prepaidCount' => $prepaidCount,
            'customPackageCount' => $customPackageCount,
            'paidCount' => $paidCount,
            'unpaidCount' => $unpaidCount,
            'paymentMethodCounts' => $paymentMethodCounts,
            'paymentMethodTotals' => $paymentMethodTotals,
            'search' => $search,
            'activeTab' => $activeTab,
            'devices' => $devices,
            'stationNumbers' => $stationNumbers,
            'activeTransactions' => $activeTransactions,
            'stationStats' => $stationStats,
            'customPackages' => $customPackages,
            'fnbs' => $fnbs,
            'locations' => $locations,
            'serverTimestamp' => $serverTimestamp,
            'serverNow' => $serverNow,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        // Environment debugging
        $environment = app()->environment();
        $phpVersion = PHP_VERSION;
        $memoryLimit = ini_get('memory_limit');
        $maxExecutionTime = ini_get('max_execution_time');
        
        \Log::info('=== TRANSACTION CREATE DEBUG ===');
        \Log::info('Environment: ' . $environment);
        \Log::info('PHP Version: ' . $phpVersion);
        \Log::info('Memory Limit: ' . $memoryLimit);
        \Log::info('Max Execution Time: ' . $maxExecutionTime);
        \Log::info('User role: ' . (auth()->user()->role ?? 'guest'));
        \Log::info('User ID: ' . (auth()->user()->id ?? 'none'));

        // Call DeviceController's status update logic and evaluate expired sessions to ensure device statuses are accurate
        try {
            app(\App\Http\Controllers\DeviceController::class)->index();
            if (Transaction::hasG2Schema()) {
                app(\App\Services\BillingService::class)->evaluateExpiration(Carbon::now());
            }
            \Log::info('Device status update completed successfully');
        } catch (\Exception $e) {
            \Log::error('Device status update failed: ' . $e->getMessage());
            \Log::error('Error trace: ' . $e->getTraceAsString());
        }

        // Ensure devices are loaded with playstation relationships and proper status
        try {
            $device = Device::with(['playstation', 'playstations'])
                ->where('status', 'Tersedia')
                ->get();
                
            \Log::info('Device query executed successfully');
            \Log::info('Device count: ' . $device->count());
            \Log::info('Devices data:', $device->toArray());
            
        } catch (\Exception $e) {
            \Log::error('Device query failed: ' . $e->getMessage());
            $device = collect(); // Empty collection as fallback
        }
            
        $noDevices = $device->isEmpty();
        $playstation = Playstation::all();
        $fnbs = Fnb::all();
        $customPackages = CustomPackage::active()->with(['playstations', 'fnbs', 'priceGroups'])->get();

        $now = Carbon::now();
        $currentTime = $now->format('H:i');

        \Log::info('No devices flag: ' . ($noDevices ? 'true' : 'false'));
        \Log::info('Playstation count: ' . $playstation->count());
        \Log::info('Custom packages count: ' . $customPackages->count());
        \Log::info('=== END TRANSACTION CREATE DEBUG ===');

        return view('transaction.create', [
            'title' => 'Create Transaction',
            'active' => 'transaction',
            'playstations' => $playstation,
            'devices' => $device,
            'noDevices' => $noDevices,
            'currentTime' => $currentTime,
            'fnbs' => $fnbs,
            'customPackages' => $customPackages
        ]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $tanggal = Carbon::now()->format('Y-m-d');
        $start_time = Carbon::now()->format('H:i');
        $durationSeconds = null;
        
        // Correctly identify duration early for calculations
        if ($request->tipe_transaksi === 'custom_package') {
            $customPackage = CustomPackage::with('playstations')->findOrFail($request->custom_package_id);
            // We'll calculate specific duration later based on device, 
            // but for initial end_time estimate we can use the sum
            $jam_main_calc = $customPackage->playstations->sum('pivot.lama_main');
            $durationSeconds = (int) ($jam_main_calc * 60);
            $start_datetime = Carbon::now();
            $end_datetime = $start_datetime->copy()->addSeconds($durationSeconds);
            $end_time = $end_datetime->format('H:i');
        } elseif ($request->tipe_transaksi === 'prepaid') {
            // Determine duration in seconds from preset or custom inputs
            if ($request->filled('duration_seconds') && (int) $request->duration_seconds > 0) {
                $durationSeconds = (int) $request->duration_seconds;
            } elseif ($request->filled('jam_main_select') && $request->jam_main_select !== 'custom') {
                $val = $request->jam_main_select;
                if (preg_match('/^(\d+):(\d{2})(?::(\d{2}))?$/', $val, $m)) {
                    $durationSeconds = ($m[1] * 3600) + ($m[2] * 60) + (isset($m[3]) ? (int)$m[3] : 0);
                } elseif (is_numeric($val)) {
                    $num = (int) $val;
                    // If <= 24, legacy hours; if > 24, seconds
                    $durationSeconds = ($num <= 24) ? ($num * 3600) : $num;
                }
            } else {
                // Custom manual inputs
                if ($request->filled('custom_hours') || $request->filled('custom_minutes') || $request->filled('custom_seconds')) {
                    $cH = (int) ($request->custom_hours ?? 0);
                    $cM = (int) ($request->custom_minutes ?? 0);
                    $cS = (int) ($request->custom_seconds ?? 0);
                    $durationSeconds = ($cH * 3600) + ($cM * 60) + $cS;
                } elseif ($request->filled('duration_seconds')) {
                    $durationSeconds = (int) $request->duration_seconds;
                } elseif ($request->filled('jam_main')) {
                    if (is_string($request->jam_main) && preg_match('/^(\d+):(\d{2})(?::(\d{2}))?$/', $request->jam_main, $m)) {
                        $durationSeconds = ($m[1] * 3600) + ($m[2] * 60) + (isset($m[3]) ? (int)$m[3] : 0);
                    } elseif (is_numeric($request->jam_main)) {
                        $durationSeconds = (int) round((float) $request->jam_main * 3600);
                    }
                }
            }

            if ($durationSeconds !== null && $durationSeconds > 0) {
                $start_datetime = Carbon::now();
                $end_datetime = $start_datetime->copy()->addSeconds($durationSeconds);
                $end_time = ($durationSeconds % 60 === 0) ? $end_datetime->format('H:i') : $end_datetime->format('H:i:s');
                $jam_main_calc = \App\Models\HourlyPrice::formatSeconds($durationSeconds);
            } else {
                $end_time = null;
                $jam_main_calc = null;
            }
        } else {
            // Postpaid
            $jam_main_calc = null;
            $end_time = null;
        }
       
        // Mengecek apakah waktu mulai dan waktu selesai sudah ada di database
        $existingTransaction = Transaction::where('device_id', $request->device_id)->whereDate('created_at', $tanggal)
            ->where(function ($query) use ($start_time, $end_time) {
                if (!$end_time) return; // Skip if no end time (postpaid)
                $query->where(function ($query) use ($start_time) {
                    $query->where('waktu_mulai', '<=', $start_time)
                        ->where('waktu_Selesai', '>', $start_time);
                })
                    ->orWhere(function ($query) use ($end_time) {
                        $query->where('waktu_mulai', '<', $end_time)
                            ->where('waktu_Selesai', '>=', $end_time);
                    });
            })
            ->doesntExist();

        // Jika waktu mulai dan waktu selesai sudah ada di database, kembalikan response error
        // Removed notification to allow transactions for available devices

        if ($jam_main_calc && !$request->filled('jam_main')) {
            $request->merge(['jam_main' => $jam_main_calc]);
        }

        $validatedData = $request->validate([
            'nama' => 'nullable|string|max:255',
            'no_telepon' => 'nullable|string|max:20',
            'device_id' => 'required_if:tipe_transaksi,prepaid,postpaid,custom_package|exists:devices,id',
            'playstation_id' => 'nullable|exists:playstations,id',
            'custom_package_id' => 'required_if:tipe_transaksi,custom_package|exists:custom_packages,id',
            'user_id' => 'nullable|exists:users,id',
            'harga' => 'required_if:tipe_transaksi,prepaid,custom_package|nullable',
            'jam_main' => 'required_if:tipe_transaksi,prepaid|nullable',
            'jam_main_select' => 'nullable|string',
            'total' => 'required_unless:tipe_transaksi,postpaid|nullable',
            'status_transaksi' => 'nullable',
            'tipe_transaksi' => 'required|in:prepaid,postpaid,custom_package',
            'action' => 'nullable|in:bayar,simpan',
            'fnb_ids.*' => 'nullable|exists:fnbs,id',
            'fnbs_qty.*' => 'nullable|integer|min:1',
            'fnbs_harga.*' => 'nullable|integer|min:0'
        ]);
        $validatedData['nama'] = trim((string) ($validatedData['nama'] ?? '')) ?: 'Pelanggan';
        unset($validatedData['action']);

        $isPaket = in_array($request->tipe_transaksi, ['prepaid', 'custom_package']);
        $activatePaketNow = $isPaket && ($request->input('action') === 'simpan');

        // Additional validation: Check if device has multiple PlayStation types and require playstation_id
        if ($request->device_id && $request->tipe_transaksi !== 'custom_package') {
            $device = Device::with('playstations')->find($request->device_id);
            if ($device && $device->playstations->count() > 1) {
                if (!$request->playstation_id) {
                    return $this->respondStoreError($request, 'Silakan pilih jenis perangkat terlebih dahulu.');
                }
                
                // Validate that the selected playstation_id is associated with this device
                if (!$device->playstations()->where('playstations.id', $request->playstation_id)->exists()) {
                    return $this->respondStoreError($request, 'Jenis perangkat yang dipilih tidak valid untuk perangkat ini.');
                }
            }
        }

        $validatedData['status'] = 'user'; // Provide default value for status
        $validatedData['waktu_mulai'] = $start_time;
        $validatedData['tipe_transaksi'] = $request->tipe_transaksi;
        
        // Use the duration we already calculated or extracted
        $jam_main = $jam_main_calc;

        if ($request->tipe_transaksi === 'custom_package') {
            $customPackage = CustomPackage::with(['playstations', 'fnbs'])->findOrFail($request->custom_package_id);

            // Validate that device_id is provided and exists
            if (!$request->device_id) {
                return $this->respondStoreError($request, 'Silakan pilih perangkat terlebih dahulu.');
            }

            // Get the selected device and validate it belongs to the package's PlayStation types
            $selectedDevice = Device::with('playstations')->findOrFail($request->device_id);
            $playstationIds = $customPackage->playstations->pluck('id')->toArray();
            
            // Check if device matches via many-to-many OR old single column
            $isCompatible = $selectedDevice->playstations->pluck('id')->intersect($playstationIds)->isNotEmpty()
                          || (isset($selectedDevice->playstation_id) && in_array($selectedDevice->playstation_id, $playstationIds));

            if (!$isCompatible) {
                return $this->respondStoreError($request, 'Perangkat yang dipilih tidak sesuai dengan paket custom.');
            }

            if ($selectedDevice->status !== 'Tersedia') {
                return $this->respondStoreError($request, 'Perangkat yang dipilih tidak tersedia.');
            }

            // Find the duration for this specific PlayStation type from the package
            $playstationId = $request->playstation_id ?: $selectedDevice->playstation_id;
            $packagePlaystation = $customPackage->playstations->where('id', $playstationId)->first();

            // If device has many-to-many playstations, try to match any of them
            if (!$packagePlaystation && $selectedDevice->playstations->isNotEmpty()) {
                $packagePlaystation = $customPackage->playstations->intersect($selectedDevice->playstations)->first();
            }

            $resolvedPlaystationId = $playstationId ?: ($packagePlaystation ? $packagePlaystation->id : null);

            // Calculate total playtime from package duration for this specific PS type
            $totalJamMain = $packagePlaystation ? $packagePlaystation->pivot->lama_main : $customPackage->playstations->sum('pivot.lama_main');

            // Calculate proper end time with exact seconds
            $start_datetime = Carbon::now();
            $end_datetime = $start_datetime->copy()->addMinutes($totalJamMain);
            $start_time = $start_datetime->format('H:i');

            $validatedData['device_id'] = $selectedDevice->id;
            if ($resolvedPlaystationId) {
                $validatedData['playstation_id'] = $resolvedPlaystationId;
            }
            $validatedData['custom_package_id'] = $customPackage->id;
            $validatedData['harga'] = $customPackage->harga_total;
            $validatedData['total'] = $request->filled('total') ? (float)$request->total : $customPackage->harga_total;
            $validatedData['jam_main'] = $totalJamMain;
            $validatedData['duration_seconds'] = (int) ($totalJamMain * 60);
            $validatedData['waktu_mulai'] = $start_time;
            $validatedData['waktu_Selesai'] = $end_datetime->format('H:i');

            if ($activatePaketNow) {
                $validatedData['status_transaksi'] = 'sukses';
                $validatedData['payment_status'] = 'unpaid';
                $validatedData['billing_status'] = Transaction::BILLING_RUNNING;
                $validatedData['started_at'] = $start_datetime;
                $validatedData['expires_at'] = $end_datetime;
                $validatedData['session_token'] = Str::random(40);
            } else {
                $validatedData['status_transaksi'] = 'draft';
                $validatedData['payment_status'] = 'unpaid';
                $validatedData['billing_status'] = Transaction::BILLING_IDLE;
                $validatedData['started_at'] = null;
                $validatedData['expires_at'] = null;
                $validatedData['session_token'] = null;
            }

        } elseif ($request->tipe_transaksi === 'prepaid') {
            // Get device and selected PlayStation
            $device = Device::findOrFail($request->device_id);
            
            // Use selected PlayStation if provided, otherwise fallback to default
            if ($request->playstation_id) {
                $playstation = Playstation::findOrFail($request->playstation_id);
                
                // Validate that the selected PlayStation is associated with this device
                $isAssociated = $device->playstations()->where('playstations.id', $request->playstation_id)->exists() 
                              || ($device->playstation_id && $device->playstation_id == $request->playstation_id);
                
                if (!$isAssociated) {
                    return $this->respondStoreError($request, 'Playstation yang dipilih tidak terkait dengan perangkat ini.');
                }
            } else {
                // Fallback to default PlayStation (backward compatibility)
                $playstation = $device->playstation;
            }
            
            if ($durationSeconds === null || $durationSeconds <= 0) {
                return $this->respondStoreError($request, 'Durasi bermain harus lebih dari 0 detik.');
            }
            if ($durationSeconds > 86400) {
                return $this->respondStoreError($request, 'Durasi bermain maksimal 24 jam.');
            }

            $totalPs = $playstation->calculateCustomPrice($durationSeconds);

            $fnbTotal = 0;
            if ($request->has('fnb_ids')) {
                foreach ($request->fnb_ids as $index => $fnb_id) {
                    if ($fnb_id && isset($request->fnbs_qty[$index]) && $request->fnbs_qty[$index] > 0) {
                        $fnb = Fnb::findOrFail($fnb_id);
                        $harga_jual = $request->fnbs_harga[$index] ?? $fnb->harga_jual;
                        $fnbTotal += $request->fnbs_qty[$index] * $harga_jual;
                    }
                }
            }

            // Calculate proper end time based on exact seconds
            $start_datetime = Carbon::now();
            $end_datetime = $start_datetime->copy()->addSeconds($durationSeconds);

            $validatedData['waktu_mulai'] = $start_datetime->format('H:i');
            $validatedData['waktu_Selesai'] = ($durationSeconds % 60 === 0) ? $end_datetime->format('H:i') : $end_datetime->format('H:i:s');
            $validatedData['duration_seconds'] = $durationSeconds;
            $validatedData['jam_main'] = \App\Models\HourlyPrice::formatSeconds($durationSeconds);
            $validatedData['harga'] = $playstation->harga ?? 0; // Store base hourly rate for reference
            $validatedData['total'] = $totalPs + $fnbTotal;

            if ($activatePaketNow) {
                $validatedData['status_transaksi'] = 'sukses';
                $validatedData['payment_status'] = 'unpaid';
                $validatedData['billing_status'] = Transaction::BILLING_RUNNING;
                $validatedData['started_at'] = $start_datetime;
                $validatedData['expires_at'] = $end_datetime;
                $validatedData['session_token'] = Str::random(40);
            } else {
                $validatedData['status_transaksi'] = 'draft';
                $validatedData['payment_status'] = 'unpaid';
                $validatedData['billing_status'] = Transaction::BILLING_IDLE;
                $validatedData['started_at'] = null;
                $validatedData['expires_at'] = null;
                $validatedData['session_token'] = null;
            }

            // Store the selected PlayStation ID for reference
            if ($request->playstation_id) {
                $validatedData['playstation_id'] = $request->playstation_id;
            } elseif ($playstation) {
                $validatedData['playstation_id'] = $playstation->id;
            }
        } elseif ($request->tipe_transaksi === 'postpaid') {
            // Postpaid (Lost Time) - device is now required
            $device = Device::findOrFail($request->device_id);
            if ($device->status !== 'Tersedia') {
                return $this->respondStoreError($request, 'Perangkat yang dipilih tidak tersedia.');
            }
            
            // Use selected PlayStation if provided, otherwise fallback to default
            if ($request->playstation_id) {
                $playstation = Playstation::findOrFail($request->playstation_id);
                
                // Validate that the selected PlayStation is associated with this device
                $isAssociated = $device->playstations()->where('playstations.id', $request->playstation_id)->exists() 
                              || ($device->playstation_id && $device->playstation_id == $request->playstation_id);
                
                if (!$isAssociated) {
                    return $this->respondStoreError($request, 'Playstation yang dipilih tidak terkait dengan perangkat ini.');
                }
            } else {
                // Fallback to default PlayStation (backward compatibility)
                $playstation = $device->playstation;
            }
            
            $validatedData['device_id'] = $device->id;
            $validatedData['harga'] = $playstation->harga ?? 0;
            $validatedData['waktu_mulai'] = $start_time;
            $validatedData['waktu_Selesai'] = null;
            $validatedData['jam_main'] = null;
            $validatedData['status_transaksi'] = 'berjalan';
            $validatedData['payment_status'] = 'unpaid';
            $validatedData['lost_time_start'] = Carbon::now();
            $validatedData['started_at'] = Carbon::now();
            $validatedData['expires_at'] = null;
            $validatedData['billing_status'] = Transaction::BILLING_RUNNING;
            $validatedData['session_token'] = \Illuminate\Support\Str::random(40);
            
            // Store the selected PlayStation ID for reference
            if ($request->playstation_id) {
                $validatedData['playstation_id'] = $request->playstation_id;
            }
            
            // For postpaid, total is calculated from FnB items only (no device cost initially)
            $validatedData['total'] = $request->total ?? 0;
        }

        // Set user_id if not provided
        $validatedData['user_id'] = $validatedData['user_id'] ?? auth()->id();

        // Filter out FNB arrays for Transaction creation
        unset($validatedData['fnb_ids']);
        unset($validatedData['fnbs_qty']);
        unset($validatedData['fnbs_harga']);

        try {
            \DB::beginTransaction();

            // Lock device row and verify station availability to prevent concurrent double-booking
            $targetDevice = Device::where('id', $validatedData['device_id'])->lockForUpdate()->firstOrFail();
            if ($targetDevice->status !== 'Tersedia') {
                \DB::rollBack();
                return $this->respondStoreError($request, 'Perangkat ' . $targetDevice->nama . ' sedang tidak tersedia (' . $targetDevice->status . ').');
            }

            if (Transaction::hasG2Schema()) {
                // Ensure expired sessions on this device are evaluated first
                try {
                    app(\App\Services\BillingService::class)->evaluateExpiration(Carbon::now());
                } catch (\Throwable $e) {
                    \Log::warning('Evaluate expiration before store failed: ' . $e->getMessage());
                }

                $activeExisting = Transaction::where('device_id', $targetDevice->id)
                    ->where('billing_status', Transaction::BILLING_RUNNING)
                    ->exists();
                if ($activeExisting) {
                    \DB::rollBack();
                    return $this->respondStoreError($request, 'Perangkat ' . $targetDevice->nama . ' masih memiliki sesi billing aktif.');
                }

                if (!$isPaket) {
                    $validatedData['started_at'] = Carbon::now();
                    $validatedData['billing_status'] = Transaction::BILLING_RUNNING;
                    $validatedData['expires_at'] = null;
                }
            }

            $transaction = Transaction::create($validatedData);
            
            // Handle FnB items
            $addedFnbIds = [];

            if ($request->has('fnb_ids')) {
                // For regular transactions or additional items in custom package, handle FnB from form
                foreach ($request->fnb_ids as $index => $fnb_id) {
                    if ($fnb_id && isset($request->fnbs_qty[$index]) && $request->fnbs_qty[$index] > 0) {
                        // Skip if already added as part of the package
                        if (in_array($fnb_id, $addedFnbIds)) continue;
                        
                        $fnb = (!$isPaket || $activatePaketNow)
                            ? Fnb::where('id', $fnb_id)->lockForUpdate()->firstOrFail()
                            : Fnb::findOrFail($fnb_id);
    
                        // Check stock only if not unlimited (stok != -1)
                        if ($fnb->stok != -1 && $fnb->stok < $request->fnbs_qty[$index]) {
                            \DB::rollBack();
                            return $this->respondStoreError($request, 'Stok FnB tidak cukup untuk ' . $fnb->nama, 422, false);
                        }
    
                        TransactionFnb::create([
                            'transaction_id' => $transaction->id_transaksi,
                            'fnb_id' => $fnb_id,
                            'qty' => $request->fnbs_qty[$index],
                            'harga_jual' => $request->fnbs_harga[$index] ?? $fnb->harga_jual,
                            'harga_beli' => $fnb->harga_beli ?? 0,
                        ]);
    
                        // Deduct stock and mutate ONLY when session starts immediately (postpaid or action=simpan)
                        if (!$isPaket || $activatePaketNow) {
                            if ($fnb->stok != -1) {
                                $fnb->decrement('stok', $request->fnbs_qty[$index]);
                            }
        
                            StockMutation::create([
                                'fnb_id' => $fnb_id,
                                'type' => 'out',
                                'qty' => $request->fnbs_qty[$index],
                                'date' => now()->toDateString(),
                                'note' => 'Penjualan transaksi #' . $transaction->id_transaksi
                            ]);
                        }
                    }
                }
            }
    
            if (!$isPaket || $activatePaketNow) {
                $targetDevice->update(['status' => 'Digunakan']);

                /** @var \App\Services\Hardware\Automation\AutomationIntentService $intentService */
                $intentService = app(\App\Services\Hardware\Automation\AutomationIntentService::class);
                if ($intentService->isAutomationEnabled()) {
                    $intentService->assertAutomationReady();
                    $targetDevice->increment('session_generation');
                    $targetDevice->refresh();
                    $generation = (int) $targetDevice->session_generation;
                    $transaction->update(['session_generation' => $generation]);

                    $intentService->recordIntent(
                        $targetDevice,
                        $transaction,
                        \App\Models\AutomationIntent::EVENT_BILLING_STARTED,
                        \App\Models\AutomationIntent::STATE_ON,
                        $transaction->started_at ?? Carbon::now()
                    );

                    if ($activatePaketNow && $transaction->expires_at) {
                        $intentService->recordIntent(
                            $targetDevice,
                            $transaction,
                            \App\Models\AutomationIntent::EVENT_BILLING_EXPIRED,
                            \App\Models\AutomationIntent::STATE_OFF,
                            $transaction->expires_at
                        );
                    }
                }

                if (TvAlertService::hasTvControlSchema()) {
                    app(TvAlertService::class)->markStalePendingAlertsForStation($targetDevice, 'New session started on station');
                }
            }

            \DB::commit();

            if (!$isPaket || $activatePaketNow) {
                // Trigger immediate dispatch of outbox intent so hardware turns ON immediately
                try {
                    if (app(\App\Services\Hardware\Automation\AutomationIntentService::class)->isAutomationEnabled()) {
                        app(\App\Services\Hardware\Automation\AutomationDispatcherService::class)->dispatchBatch(5);
                    }
                } catch (\Throwable $e) {
                    \Log::warning('Immediate automation dispatch after store failed: ' . $e->getMessage());
                }
            }
        } catch (\Exception $e) {
            \DB::rollBack();
            \Log::error('Transaction Store Error: ' . $e->getMessage());
            \Log::error($e->getTraceAsString());
            return $this->respondStoreError($request, 'Gagal menyimpan transaksi: ' . $e->getMessage(), 422, false);
        }

        if ($activatePaketNow) {
            return $this->respondStoreSuccess(
                $request,
                $transaction,
                route('transaction.index', ['tab' => 'stations']),
                'Transaksi paket berhasil disimpan dan sesi dimulai.',
                true
            );
        }

        if ($isPaket || $request->action === 'bayar') {
            return $this->respondStoreSuccess(
                $request,
                $transaction,
                route('transaction.showPayment', $transaction->id_transaksi)
            );
        }

        return $this->respondStoreSuccess(
            $request,
            $transaction,
            route('transaction.index'),
            'Data transaksi berhasil disimpan.',
            true
        );
    }

    private function respondStoreError(Request $request, string $message, int $status = 422, bool $withInput = true)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => $message,
            ], $status);
        }

        $redirect = redirect()->back()->with('gagal', $message);
        return $withInput ? $redirect->withInput() : $redirect;
    }

    private function respondStoreSuccess(
        Request $request,
        Transaction $transaction,
        string $redirectUrl,
        string $message = 'Data transaksi berhasil disimpan.',
        bool $withSuccessFlash = false
    ) {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'transaction_id' => $transaction->id_transaksi,
                'redirect_url' => $redirectUrl,
            ], 201);
        }

        $redirect = redirect($redirectUrl);
        return $withSuccessFlash ? $redirect->with('success', $message) : $redirect;
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $transaction = Transaction::with(['device.playstation', 'transactionFnbs.fnb', 'user'])->findOrFail($id);

        return view('transaction.show', [
            'title' => 'Detail Transaksi',
            'active' => 'transaction',
            'transaction' => $transaction
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        $transaction = Transaction::with(['device.playstation', 'custom_package', 'transactionFnbs.fnb'])->findOrFail($id);
        
        // Only allow editing unpaid transactions
        if ($transaction->payment_status !== 'unpaid') {
            return redirect()->route('transaction.index')->with('gagal', 'Hanya transaksi yang belum dibayar yang dapat diedit.');
        }
        
        // Get only available devices + the current device being used by this transaction
        $devices = Device::with('playstation')
            ->where(function($query) use ($transaction) {
                $query->where('status', 'Tersedia')
                      ->orWhere('id', $transaction->device_id);
            })
            ->get();
        
        // Get all custom packages for custom_package type
        $customPackages = CustomPackage::active()->with(['playstations', 'fnbs'])->get();
        
        // Get all available FnB items (for adding new items)
        $fnbs = Fnb::where(function($query) {
            $query->where('stok', '>=', 1)
                  ->orWhere('stok', -1); // Include unlimited stock items
        })->get();
        
        return view('transaction.edit', [
            'title' => 'Edit Transaksi',
            'active' => 'transaction',
            'transaction' => $transaction,
            'devices' => $devices,
            'customPackages' => $customPackages,
            'fnbs' => $fnbs
        ]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $transaction = Transaction::findOrFail($id);
        
        // Only allow editing unpaid transactions
        if ($transaction->payment_status !== 'unpaid') {
            return redirect()->route('transaction.index')->with('gagal', 'Hanya transaksi yang belum dibayar yang dapat diedit.');
        }
        
        $validatedData = $request->validate([
            'tipe_transaksi' => 'required|in:prepaid,postpaid,custom_package',
            'device_id' => 'required|exists:devices,id',
            'jam_main' => 'nullable|integer|min:1',
            'custom_package_id' => 'nullable|exists:custom_packages,id'
        ]);
        
        $oldDeviceId = $transaction->device_id;
        $newDeviceId = $validatedData['device_id'];
        $oldTipeTransaksi = $transaction->tipe_transaksi;
        $newTipeTransaksi = $validatedData['tipe_transaksi'];
        
        // Get the new device
        $newDevice = Device::with('playstation')->findOrFail($newDeviceId);
        
        // Check if new device is available (or if it's the same device)
        if ($newDevice->status !== 'Tersedia' && $newDeviceId != $oldDeviceId) {
            return redirect()->back()->with('gagal', 'Perangkat yang dipilih tidak tersedia.');
        }
        
        // Prepare update data
        $updateData = [
            'device_id' => $newDeviceId,
            'tipe_transaksi' => $newTipeTransaksi,
        ];
        
        // Handle transaction type changes
        if ($newTipeTransaksi === 'postpaid') {
            // Changed to Lost Time
            $updateData['harga'] = $newDevice->playstation->harga ?? 0;
            $updateData['jam_main'] = null;
            $updateData['waktu_Selesai'] = null;
            $updateData['status_transaksi'] = 'berjalan';
            
            // Keep the original lost_time_start if it exists, otherwise set it based on waktu_mulai
            // This ensures timer continues from the original start time
            if (!$transaction->lost_time_start) {
                // Calculate lost_time_start from waktu_mulai to maintain consistency
                $waktuMulaiCarbon = Carbon::parse($transaction->created_at->toDateString() . ' ' . $transaction->waktu_mulai);
                $updateData['lost_time_start'] = $waktuMulaiCarbon;
            }
            // If lost_time_start already exists, don't change it - timer continues
            
            // For postpaid, total is FnB only initially (device cost calculated on end)
            $fnbTotal = $transaction->getFnbTotalAttribute();
            $updateData['total'] = $fnbTotal;

        } elseif ($newTipeTransaksi === 'prepaid') {
            // Changed to Paket OR updating existing Paket
            $playstation = $newDevice->playstation;
            $rawJamMain = $validatedData['jam_main'] ?? 1;

            if (is_numeric($rawJamMain)) {
                $durationSeconds = (int) round((float) $rawJamMain * 3600);
            } elseif (is_string($rawJamMain) && preg_match('/^(\d+):(\d{2})(?::(\d{2}))?$/', $rawJamMain, $m)) {
                $durationSeconds = ($m[1] * 3600) + ($m[2] * 60) + (isset($m[3]) ? (int)$m[3] : 0);
            } else {
                $durationSeconds = 3600;
            }

            $totalPs = $playstation ? $playstation->calculateCustomPrice($durationSeconds) : 0;
            $fnbTotal = $transaction->getFnbTotalAttribute();

            $updateData['duration_seconds'] = $durationSeconds;
            $updateData['jam_main'] = is_numeric($rawJamMain) ? $rawJamMain : \App\Models\HourlyPrice::formatSeconds($durationSeconds);
            $updateData['harga'] = $playstation->harga ?? 0; // Store base hourly rate for reference
            $updateData['total'] = $totalPs + $fnbTotal;
            $updateData['status_transaksi'] = 'sukses';

            // Calculate elapsed time from started_at / waktu_mulai
            $waktuMulaiCarbon = $transaction->started_at ?? Carbon::parse($transaction->created_at->toDateString() . ' ' . $transaction->waktu_mulai);
            $now = Carbon::now();
            $elapsedSeconds = max(0, $waktuMulaiCarbon->diffInSeconds($now));
            $remainingSeconds = $durationSeconds - $elapsedSeconds;

            if ($remainingSeconds > 0) {
                // There's still time remaining
                $newExpiresAt = $now->copy()->addSeconds($remainingSeconds);
                $updateData['waktu_Selesai'] = $newExpiresAt->format('H:i');
                if (Transaction::hasG2Schema()) {
                    $updateData['expires_at'] = $newExpiresAt;
                    $updateData['billing_status'] = Transaction::BILLING_RUNNING;
                }
            } else {
                // Time already exceeded, set end time to now (will be marked as overtime)
                $updateData['waktu_Selesai'] = $now->format('H:i');
                if (Transaction::hasG2Schema()) {
                    $updateData['expires_at'] = $now;
                    $updateData['billing_status'] = Transaction::BILLING_EXPIRED_PENDING_ACTION;
                }
            }
            
        } elseif ($newTipeTransaksi === 'custom_package') {
            // Changed to Custom Package
            $customPackageId = $validatedData['custom_package_id'] ?? null;
            
            if (!$customPackageId) {
                return redirect()->back()->with('gagal', 'Silakan pilih paket custom.');
            }
            
            $customPackage = CustomPackage::with(['playstations', 'fnbs'])->findOrFail($customPackageId);
            
            // Validate device is compatible with package
            $playstationIds = $customPackage->playstations->pluck('id');
            if (!$playstationIds->contains($newDevice->playstation_id)) {
                return redirect()->back()->with('gagal', 'Perangkat yang dipilih tidak sesuai dengan paket custom.');
            }
            
            $totalJamMain = $customPackage->playstations->sum('pivot.lama_main');
            
            $updateData['custom_package_id'] = $customPackageId;
            $updateData['playstation_id'] = $newDevice->playstation_id;
            $updateData['harga'] = $customPackage->harga_total;
            $updateData['total'] = $customPackage->harga_total + $transaction->uninvoiced_legacy_fnb_total;
            $updateData['jam_main'] = $totalJamMain;
            $updateData['duration_seconds'] = (int) ($totalJamMain * 60);
            $updateData['status_transaksi'] = 'sukses';
            
            // Calculate elapsed time from started_at / waktu_mulai
            $waktuMulaiCarbon = $transaction->started_at ?? Carbon::parse($transaction->created_at->toDateString() . ' ' . $transaction->waktu_mulai);
            $now = Carbon::now();
            $elapsedMinutes = $waktuMulaiCarbon->diffInMinutes($now);
            
            // Calculate new end time: now + (package duration - elapsed time)
            $remainingMinutes = $totalJamMain - $elapsedMinutes;
            
            if ($remainingMinutes > 0) {
                $newExpiresAt = $now->copy()->addMinutes($remainingMinutes);
                $updateData['waktu_Selesai'] = $newExpiresAt->format('H:i');
                if (Transaction::hasG2Schema()) {
                    $updateData['expires_at'] = $newExpiresAt;
                    $updateData['billing_status'] = Transaction::BILLING_RUNNING;
                }
            } else {
                $updateData['waktu_Selesai'] = $now->format('H:i');
                if (Transaction::hasG2Schema()) {
                    $updateData['expires_at'] = $now;
                    $updateData['billing_status'] = Transaction::BILLING_EXPIRED_PENDING_ACTION;
                }
            }
        }

        
        // Update the transaction
        $transaction->update($updateData);
        
        // Handle device status changes
        if ($oldDeviceId != $newDeviceId) {
            // Release old device
            $oldDevice = Device::find($oldDeviceId);
            if ($oldDevice) {
                $oldDevice->update(['status' => 'Tersedia']);
            }
            
            // Mark new device as in use
            $newDevice->update(['status' => 'Digunakan']);
        }

        /** @var \App\Services\Hardware\Automation\AutomationIntentService $intentService */
        $intentService = app(\App\Services\Hardware\Automation\AutomationIntentService::class);
        if ($intentService->isAutomationEnabled() && isset($newExpiresAt) && $newDevice) {
            $intentService->assertAutomationReady();
            $newDevice->increment('session_generation');
            $newDevice->refresh();
            $newGen = (int) $newDevice->session_generation;
            $transaction->update(['session_generation' => $newGen]);

            $intentService->cancelPendingOffIntents($transaction);
            $intentService->recordIntent(
                $newDevice,
                $transaction,
                \App\Models\AutomationIntent::EVENT_BILLING_EXPIRED,
                \App\Models\AutomationIntent::STATE_OFF,
                $newExpiresAt
            );
        }

        if (TvAlertService::hasTvControlSchema()) {
            app(TvAlertService::class)->cancelPendingAlertsForTransaction(
                $transaction,
                TvAlertEvent::STATUS_CANCELLED,
                'Session updated/extended'
            );
        }
        
        return redirect()->route('transaction.index')->with('success', 'Transaksi berhasil diupdate. Timer tetap berjalan.');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $transaction = Transaction::with(['transactionFnbs.fnb', 'device'])->findOrFail($id);

        // Transaksi yang sudah lunas dilarang keras dihapus demi integritas data dan audit keuangan
        if ($transaction->payment_status === 'paid') {
            return redirect('transaction')->with('gagal', 'Transaksi yang sudah lunas tidak dapat dihapus demi integritas audit keuangan.');
        }

        DB::transaction(function () use ($transaction) {
            // 1. Kembalikan stok FnB dan catat mutasi stok masuk untuk transaksi unpaid yang dihapus
            if ($transaction->status_transaksi !== 'draft' && $transaction->transactionFnbs) {
                foreach ($transaction->transactionFnbs as $tFnb) {
                    $fnb = $tFnb->fnb;
                    if ($fnb) {
                        if ($fnb->stok != -1) {
                            $fnb->increment('stok', $tFnb->qty);
                        }

                        StockMutation::create([
                            'fnb_id' => $fnb->id,
                            'type' => 'in',
                            'qty' => $tFnb->qty,
                            'date' => now()->toDateString(),
                            'note' => 'Penghapusan Transaksi Unpaid #' . $transaction->id_transaksi
                        ]);
                    }
                }
            }

            // 2. Kembalikan status perangkat ke 'Tersedia' jika masih terikat transaksi ini
            if ($transaction->device && $transaction->device->status === 'Digunakan') {
                $transaction->device->update(['status' => 'Tersedia']);
            }

            if (TvAlertService::hasTvControlSchema()) {
                app(TvAlertService::class)->cancelPendingAlertsForTransaction(
                    $transaction,
                    TvAlertEvent::STATUS_CANCELLED,
                    'Transaction deleted'
                );
            }

            \Illuminate\Support\Facades\Log::warning('Transaksi dibatalkan/dihapus oleh Admin.', [
                'id_transaksi' => $transaction->id_transaksi,
                'user_id' => auth()->id(),
                'total' => $transaction->total,
                'fnb_count' => $transaction->transactionFnbs ? $transaction->transactionFnbs->count() : 0
            ]);

            // 3. Eksekusi penghapusan transaksi
            $transaction->delete();
        });

        return redirect('transaction')->with('success', 'Data transaksi berhasil dihapus.');
    }

    public function getHarga(Request $request)
    {
        $deviceId = $request->query('device');
        $playstationId = $request->query('playstation_id');

        if ($playstationId) {
            $playstation = Playstation::find($playstationId);
            return response()->json(['harga' => $playstation ? $playstation->harga : 0]);
        }

        $device = Device::find($deviceId);
        if (!$device) {
            return response()->json(['harga' => 0]);
        }

        $playstation = Playstation::find($device->playstation_id);
        if (!$playstation) {
            return response()->json(['harga' => 0]);
        }

        return response()->json(['harga' => $playstation->harga]);
    }

    public function getHourlyPrices(Request $request)
    {
        $deviceId = $request->query('device');
        $playstationId = $request->query('playstation_id');

        $playstation = null;
        if ($playstationId) {
            $playstation = Playstation::find($playstationId);
        } elseif ($deviceId) {
            $device = Device::find($deviceId);
            if ($device) {
                $playstation = $device->playstations()->first() ?: Playstation::find($device->playstation_id);
            }
        }

        if (!$playstation) {
            return response()->json([
                'prices' => [],
                'durations' => [],
                'base_price' => 0,
            ]);
        }

        return response()->json([
            'prices' => $playstation->getAvailableHoursWithPrices(),
            'durations' => $playstation->getAvailableDurationsWithPrices(),
            'base_price' => (int) ($playstation->harga ?? 0),
        ]);
    }

    /**
     * API to calculate exact duration price based on business rules.
     */
    public function calculateDurationPrice(Request $request)
    {
        $deviceId = $request->input('device') ?? $request->query('device');
        $playstationId = $request->input('playstation_id') ?? $request->query('playstation_id');

        $playstation = null;
        if ($playstationId) {
            $playstation = Playstation::find($playstationId);
        } elseif ($deviceId) {
            $device = Device::find($deviceId);
            if ($device) {
                $playstation = $device->playstations()->first() ?: Playstation::find($device->playstation_id);
            }
        }

        if (!$playstation) {
            return response()->json(['error' => 'PlayStation tidak ditemukan'], 404);
        }

        $hours = (int) ($request->input('hours') ?? 0);
        $minutes = (int) ($request->input('minutes') ?? 0);
        $seconds = (int) ($request->input('seconds') ?? 0);
        $totalSeconds = (int) ($request->input('duration_seconds') ?? \App\Models\HourlyPrice::toTotalSeconds($hours, $minutes, $seconds));

        if ($totalSeconds <= 0) {
            return response()->json([
                'price' => 0,
                'duration_seconds' => 0,
                'formatted' => '00:00:00',
                'human' => '0 detik',
                'is_preset' => false,
            ]);
        }

        $presetPrice = $playstation->getPriceForDuration($totalSeconds);
        $finalPrice = $playstation->calculateCustomPrice($totalSeconds);

        return response()->json([
            'price' => $finalPrice,
            'duration_seconds' => $totalSeconds,
            'formatted' => \App\Models\HourlyPrice::formatSeconds($totalSeconds),
            'human' => \App\Models\HourlyPrice::humanizeSeconds($totalSeconds),
            'is_preset' => $presetPrice !== null,
        ]);
    }

    public function getFnbsByPriceGroup(Request $request)
    {
        $priceGroupId = $request->query('price_group_id');

        if (!$priceGroupId) {
            return response()->json(['fnbs' => []]);
        }

        $fnbs = Fnb::where('price_group_id', $priceGroupId)->get(['id', 'nama', 'harga_jual', 'stok']);

        return response()->json(['fnbs' => $fnbs]);
    }

    public function getFnbsByPriceGroups(Request $request)
    {
        $priceGroupIds = $request->query('price_group_ids');

        if (!$priceGroupIds || !is_array($priceGroupIds) || empty($priceGroupIds)) {
            return response()->json(['fnbs' => []]);
        }

        $fnbs = Fnb::whereIn('price_group_id', $priceGroupIds)
            ->get(['id', 'nama', 'harga_jual', 'stok', 'price_group_id']);

        return response()->json(['fnbs' => $fnbs]);
    }

    public function getPriceGroups()
    {
        $priceGroups = \App\Models\PriceGroup::all(['id', 'nama', 'harga', 'deskripsi']);

        return response()->json($priceGroups);
    }

    public function updateStatus(Request $request, $id)
    {
        $transaction = Transaction::findOrFail($id);
        $transaction->update(['status_transaksi' => $request->status_transaksi]);

        $deviceStatus = $request->status;
        if ($request->status_transaksi == 'selesai') {
            $deviceStatus = 'Tersedia';
        }
        Device::where('id', $request->device_id)->update(['status' => $deviceStatus]);

        return redirect('transaction')->with('success', 'Status transaksi berhasil diupdate.');
    }

    public function endTransaction($id)
    {
        $transaction = Transaction::findOrFail($id);

        if (!in_array(auth()->user()->role, ['admin', 'kasir']) && $transaction->user_id !== auth()->id()) {
            abort(403, 'Unauthorized');
        }

        $canEnd = $transaction->status_transaksi === 'berjalan'
            || (Transaction::hasG2Schema() && $transaction->billing_status === Transaction::BILLING_RUNNING);

        if (!$canEnd) {
            return redirect('transaction')->with('gagal', 'Transaksi tidak dapat diakhiri.');
        }

        $waktuSelesai = Carbon::now();
        $wasPaid = ($transaction->payment_status === 'paid');

        if ($transaction->tipe_transaksi === 'postpaid') {
            $totalFnb = $transaction->getFnbTotalAttribute() + $transaction->getUnpaidFnbOrderTotalAttribute();

            // Calculate duration and cost for postpaid (Lost Time) transactions
            $waktuMulai = Carbon::parse($transaction->created_at->toDateString() . ' ' . $transaction->waktu_mulai);
            $durationMinutes = $waktuMulai->diffInMinutes($waktuSelesai, false);

            if ($durationMinutes < 0) {
                // If somehow negative, perhaps next day
                $durationMinutes = 24 * 60 + $durationMinutes;
            }

            // Get the device and playstation for hourly pricing
            $device = $transaction->device;
            $playstation = $device->playstation;
            
            // Calculate pricing based on hourly prices
            $totalPs = $this->calculateLostTimePrice($playstation, $durationMinutes);
            
            // Apply specific rounding rules for lost time
            $roundedTotalPs = $this->applyLostTimeRounding($totalPs);
            $total = $roundedTotalPs + $totalFnb;

            $hours = floor($durationMinutes / 60);
            $minutes = $durationMinutes % 60;
            $formattedDuration = sprintf('%d jam %d menit', $hours, $minutes);

            $updateData = [
                'waktu_Selesai' => $waktuSelesai->format('H:i'),
                'jam_main' => $formattedDuration,
                'total' => $total,
            ];
        } else {
            $rentalCost = $transaction->resolvePaketRentalTotal();
            $extraLegacyFnbTotal = $transaction->uninvoiced_legacy_fnb_total;
            $unpaidFnbOrdersTotal = $transaction->unpaid_fnb_order_total;

            $updateData = [
                'waktu_Selesai' => $waktuSelesai->format('H:i'),
            ];
            if (!$wasPaid) {
                $updateData['total'] = $rentalCost + $extraLegacyFnbTotal + $unpaidFnbOrdersTotal;
            }
        }

        // Common updates for both transaction types
        $commonUpdates = [
            'status_transaksi' => 'selesai',
            'session_token' => null,
        ];
        if (!$wasPaid) {
            $commonUpdates['payment_status'] = 'unpaid';
        }
        if (Transaction::hasG2Schema()) {
            $commonUpdates['ended_at'] = $waktuSelesai;
            $commonUpdates['billing_status'] = Transaction::BILLING_COMPLETED;
        }
        $updateData = array_merge($updateData, $commonUpdates);

        $transaction->update($updateData);

        // Update device status to available
        if ($transaction->device) {
            $transaction->device->update(['status' => 'Tersedia']);
        }

        // Wire automation OFF intent for manual session stop
        try {
            /** @var \App\Services\Hardware\Automation\AutomationIntentService $intentService */
            $intentService = app(\App\Services\Hardware\Automation\AutomationIntentService::class);
            if ($intentService->isAutomationEnabled() && $transaction->device) {
                $station = $transaction->device;
                $newGen = $intentService->bumpSessionGeneration($station);
                $transaction->update(['session_generation' => $newGen]);
                $intentService->cancelPendingOffIntents($transaction);
                $intentService->recordIntent(
                    $station,
                    $transaction,
                    \App\Models\AutomationIntent::EVENT_BILLING_STOPPED,
                    \App\Models\AutomationIntent::STATE_OFF,
                    Carbon::now()
                );

                // Trigger immediate dispatch so hardware turns OFF immediately
                app(\App\Services\Hardware\Automation\AutomationDispatcherService::class)->dispatchBatch(5);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Failed to record automation intent on manual stop: ' . $e->getMessage(), [
                'transaction_id' => $transaction->id_transaksi ?? null,
                'exception' => $e,
            ]);
        }

        if (TvAlertService::hasTvControlSchema()) {
            app(TvAlertService::class)->cancelPendingAlertsForTransaction(
                $transaction,
                TvAlertEvent::STATUS_CANCELLED,
                'Session stopped'
            );
        }

        if ($wasPaid) {
            return redirect()->route('transaction.index')
                ->with('success', 'Transaksi berhasil diakhiri.');
        }

        // Redirect to payment page
        return redirect()->route('transaction.showPayment', $transaction->id_transaksi)
            ->with('success', 'Transaksi berhasil diakhiri. Silakan lakukan pembayaran.');
    }

    // app/Http/Controllers/TransactionController.php

    public function addOrder($id)
    {
        $transaction = Transaction::findOrFail($id);

        if ($transaction->payment_status !== 'unpaid') {
            return redirect()->route('transaction.index')->with('error', 'Hanya bisa menambahkan pesanan untuk transaksi yang belum dibayar.');
        }

        $fnbs = Fnb::where(function($query) {
            $query->where('stok', '>=', 1)
                  ->orWhere('stok', -1);
        })->get();

        // Calculate existing total (PS cost + existing FNB cost)
        $existingFnbTotal = $transaction->uninvoiced_legacy_fnb_total;

        // Calculate existing PS cost based on transaction type
        $totalPs = 0;
        if (in_array($transaction->tipe_transaksi, ['prepaid', 'custom_package'], true)) {
            $totalPs = $transaction->resolvePaketRentalTotal();
        } elseif ($transaction->tipe_transaksi === 'postpaid') {
            // For running lost time, calculate current cost
            $startTimestamp = $transaction->lost_time_start 
                ? Carbon::parse($transaction->lost_time_start)
                : Carbon::parse($transaction->created_at->toDateString() . ' ' . $transaction->waktu_mulai);
            
            $now = Carbon::now();
            $durationMinutes = $startTimestamp->diffInMinutes($now, false);
            
            if ($durationMinutes < 0) {
                 // Fallback if start is future or wrong
                 $durationMinutes = 0;
            }
            
            // Use the new lost time pricing calculation
            $device = $transaction->device;
            $playstation = $device->playstation;
            $rawTotalPs = $this->calculateLostTimePrice($playstation, $durationMinutes);
            $totalPs = $this->applyLostTimeRounding($rawTotalPs);
        }

        $existingTotal = $totalPs + $existingFnbTotal;

        return view('transaction.add-order', [
            'title' => 'Tambah Pesanan',
            'active' => 'transaction',
            'transaction' => $transaction,
            'fnbs' => $fnbs,
            'existingTotal' => $existingTotal
        ]);
    }

    public function storeOrder(Request $request, $id)
    {
        $transaction = Transaction::findOrFail($id);
        
        if ($transaction->payment_status !== 'unpaid') {
            return redirect()->route('transaction.index')->with('error', 'Hanya bisa menambahkan pesanan untuk transaksi yang belum dibayar.');
        }

        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.fnb_id' => 'required|exists:fnbs,id',
            'items.*.qty' => 'required|integer|min:1',
        ]);

        $totalAdded = 0;
        
        // Process each item in the order
        foreach ($request->items as $item) {
            $fnb = Fnb::findOrFail($item['fnb_id']);
            $qty = (int)$item['qty'];
            
            // Check stock only if not unlimited (stok != -1)
            if ($fnb->stok != -1 && $fnb->stok < $qty) {
                return redirect()->back()
                    ->with('error', 'Stok ' . $fnb->nama . ' tidak mencukupi. Stok tersedia: ' . $fnb->stok);
            }

            // Check if this FNB is already in the transaction
            $transactionFnb = TransactionFnb::where('transaction_id', $transaction->id_transaksi)
                ->where('fnb_id', $fnb->id)
                ->first();

            if ($transactionFnb) {
                // Update existing FNB in the transaction
                $transactionFnb->qty += $qty;
                $transactionFnb->save();
            } else {
                // Create new transaction FNB
                $transactionFnb = new TransactionFnb([
                    'transaction_id' => $transaction->id_transaksi,
                    'fnb_id' => $fnb->id,
                    'qty' => $qty,
                    'harga_jual' => $fnb->harga_jual,
                    'harga_beli' => $fnb->harga_beli
                ]);
                $transactionFnb->save();
            }

            // Update FNB stock only if not unlimited (stok != -1)
            if ($fnb->stok != -1) {
                $fnb->stok -= $qty;
                $fnb->save();
            }

            // Create stock mutation
            StockMutation::create([
                'fnb_id' => $fnb->id,
                'type' => 'out',
                'qty' => $qty,
                'date' => now()->toDateString(),
                'note' => 'Penjualan - Transaksi #' . $transaction->id_transaksi
            ]);

            $totalAdded++;
        }

        // Recalculate the total for the transaction (PS cost + FNB total)
        $transaction->unsetRelation('transactionFnbs');
        $fnbTotal = $transaction->uninvoiced_legacy_fnb_total;

        $totalPs = 0;
        
        if (in_array($transaction->tipe_transaksi, ['prepaid', 'custom_package'], true)) {
            $totalPs = $transaction->resolvePaketRentalTotal();
        } elseif ($transaction->tipe_transaksi === 'postpaid') {
            if ($transaction->status_transaksi === 'selesai' && $transaction->waktu_Selesai) {
                $waktuMulai = Carbon::parse($transaction->created_at->toDateString() . ' ' . $transaction->waktu_mulai);
                $waktuSelesai = Carbon::parse($transaction->created_at->toDateString() . ' ' . $transaction->waktu_Selesai);
                
                $durationMinutes = $waktuMulai->diffInMinutes($waktuSelesai, false);
                if ($durationMinutes < 0) {
                    $durationMinutes += 24 * 60;
                }
                
                // Use the new lost time pricing calculation
                $device = $transaction->device;
                $playstation = $device->playstation;
                $rawTotalPs = $this->calculateLostTimePrice($playstation, $durationMinutes);
                $totalPs = $this->applyLostTimeRounding($rawTotalPs);
            } else {
                $totalPs = 0;
            }
        }

        $transaction->total = $totalPs + $fnbTotal;
        $transaction->save();
        
        return redirect()->route('transaction.showPayment', $transaction->id_transaksi)
            ->with('success', $totalAdded . ' item pesanan berhasil ditambahkan');
    }

    /**
     * Add a new FnB item to an existing transaction
     */
    public function addFnbToTransaction($id, Request $request)
    {
        $transaction = Transaction::findOrFail($id);
        
        // Only allow editing unpaid transactions
        if ($transaction->payment_status !== 'unpaid') {
            return response()->json(['success' => false, 'message' => 'Hanya transaksi yang belum dibayar yang dapat diedit.'], 403);
        }
        
        $request->validate([
            'fnb_id' => 'required|exists:fnbs,id',
            'qty' => 'required|integer|min:1',
        ]);
        
        $fnb = Fnb::findOrFail($request->fnb_id);
        $qty = (int)$request->qty;
        
        // Check stock only if not unlimited (stok != -1)
        if ($fnb->stok != -1 && $fnb->stok < $qty) {
            return response()->json([
                'success' => false,
                'message' => 'Stok ' . $fnb->nama . ' tidak mencukupi. Stok tersedia: ' . $fnb->stok
            ], 400);
        }
        
        DB::beginTransaction();
        try {
            // Check if this FNB is already in the transaction
            $transactionFnb = TransactionFnb::where('transaction_id', $transaction->id_transaksi)
                ->where('fnb_id', $fnb->id)
                ->first();
            
            if ($transactionFnb) {
                // Update existing FNB quantity
                $transactionFnb->qty += $qty;
                $transactionFnb->save();
            } else {
                // Create new transaction FNB
                $transactionFnb = TransactionFnb::create([
                    'transaction_id' => $transaction->id_transaksi,
                    'fnb_id' => $fnb->id,
                    'qty' => $qty,
                    'harga_jual' => $fnb->harga_jual,
                    'harga_beli' => (float) ($fnb->harga_beli ?? 0),
                ]);
            }
            
            // Update FNB stock only if not unlimited (stok != -1)
            if ($fnb->stok != -1) {
                $fnb->decrement('stok', $qty);
            }
            
            // Create stock mutation
            StockMutation::create([
                'fnb_id' => $fnb->id,
                'type' => 'out',
                'qty' => $qty,
                'date' => now()->toDateString(),
                'note' => 'Tambah FnB - Transaksi #' . $transaction->id_transaksi
            ]);
            
            // Recalculate transaction total
            $this->recalculateTransactionTotal($transaction);
            
            DB::commit();
            
            // Reload transaction with fresh data
            $transaction->load('transactionFnbs.fnb');
            
            return response()->json([
                'success' => true,
                'message' => 'FnB berhasil ditambahkan',
                'fnb' => $transactionFnb->load('fnb'),
                'total' => $transaction->fresh()->total
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Add FnB to Transaction Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal menambahkan FnB: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Update an existing FnB item in a transaction
     */
    public function updateTransactionFnb($id, $fnbId, Request $request)
    {
        $transaction = Transaction::findOrFail($id);
        
        // Only allow editing unpaid transactions
        if ($transaction->payment_status !== 'unpaid') {
            return response()->json(['success' => false, 'message' => 'Hanya transaksi yang belum dibayar yang dapat diedit.'], 403);
        }
        
        $request->validate([
            'qty' => 'required|integer|min:1',
            'harga_jual' => 'nullable|integer|min:0',
        ]);
        
        $transactionFnb = TransactionFnb::where('transaction_id', $transaction->id_transaksi)
            ->where('fnb_id', $fnbId)
            ->firstOrFail();
        
        $fnb = $transactionFnb->fnb;
        $oldQty = $transactionFnb->qty;
        $newQty = (int)$request->qty;
        $qtyDifference = $newQty - $oldQty;
        
        DB::beginTransaction();
        try {
            // If quantity is increasing, check stock
            if ($qtyDifference > 0) {
                if ($fnb->stok != -1 && $fnb->stok < $qtyDifference) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Stok ' . $fnb->nama . ' tidak mencukupi. Stok tersedia: ' . $fnb->stok
                    ], 400);
                }
                
                // Decrease stock
                if ($fnb->stok != -1) {
                    $fnb->decrement('stok', $qtyDifference);
                }
                
                // Create stock mutation for increase
                StockMutation::create([
                    'fnb_id' => $fnb->id,
                    'type' => 'out',
                    'qty' => $qtyDifference,
                    'date' => now()->toDateString(),
                    'note' => 'Update FnB qty - Transaksi #' . $transaction->id_transaksi
                ]);
            } elseif ($qtyDifference < 0) {
                // Quantity is decreasing, restore stock
                if ($fnb->stok != -1) {
                    $fnb->increment('stok', abs($qtyDifference));
                }
                
                // Create stock mutation for decrease
                StockMutation::create([
                    'fnb_id' => $fnb->id,
                    'type' => 'in',
                    'qty' => abs($qtyDifference),
                    'date' => now()->toDateString(),
                    'note' => 'Update FnB qty - Transaksi #' . $transaction->id_transaksi
                ]);
            }
            
            // Update transaction FnB
            $transactionFnb->qty = $newQty;
            if ($request->has('harga_jual')) {
                $transactionFnb->harga_jual = $request->harga_jual;
            }
            $transactionFnb->save();
            
            // Recalculate transaction total
            $this->recalculateTransactionTotal($transaction);
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'FnB berhasil diupdate',
                'fnb' => $transactionFnb->fresh()->load('fnb'),
                'total' => $transaction->fresh()->total
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Update Transaction FnB Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengupdate FnB: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Delete an FnB item from a transaction
     */
    public function deleteTransactionFnb($id, $fnbId)
    {
        $transaction = Transaction::findOrFail($id);
        
        // Only allow editing unpaid transactions
        if ($transaction->payment_status !== 'unpaid') {
            return response()->json(['success' => false, 'message' => 'Hanya transaksi yang belum dibayar yang dapat diedit.'], 403);
        }
        
        $transactionFnb = TransactionFnb::where('transaction_id', $transaction->id_transaksi)
            ->where('fnb_id', $fnbId)
            ->firstOrFail();
        
        $fnb = $transactionFnb->fnb;
        $qty = $transactionFnb->qty;
        
        DB::beginTransaction();
        try {
            // Restore stock only if not unlimited (stok != -1)
            if ($fnb->stok != -1) {
                $fnb->increment('stok', $qty);
            }
            
            // Create stock mutation
            StockMutation::create([
                'fnb_id' => $fnb->id,
                'type' => 'in',
                'qty' => $qty,
                'date' => now()->toDateString(),
                'note' => 'Hapus FnB - Transaksi #' . $transaction->id_transaksi
            ]);
            
            // Delete transaction FnB
            $transactionFnb->delete();
            
            // Recalculate transaction total
            $this->recalculateTransactionTotal($transaction);
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'FnB berhasil dihapus',
                'total' => $transaction->fresh()->total
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Delete Transaction FnB Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus FnB: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Recalculate transaction total based on PS cost and FnB items
     */
    private function recalculateTransactionTotal($transaction)
    {
        $transaction->unsetRelation('transactionFnbs');
        $fnbTotal = $transaction->uninvoiced_legacy_fnb_total;
        
        // Calculate PS cost
        $totalPs = 0;
        if (in_array($transaction->tipe_transaksi, ['prepaid', 'custom_package'], true)) {
            $totalPs = $transaction->resolvePaketRentalTotal();
        } elseif ($transaction->tipe_transaksi === 'postpaid') {
            // For running lost time, don't calculate until transaction ends
            $totalPs = 0;
        }
        
        $transaction->total = $totalPs + $fnbTotal;
        $transaction->save();
    }

    /**
     * Calculate lost time price based on hourly pricing rules
     * If duration matches hourly package duration, use package price
     * Otherwise, use per-minute pricing from base price
     *
     * @param Playstation $playstation
     * @param int $durationMinutes
     * @return float
     */
    private function calculateLostTimePrice($playstation, $durationMinutes)
    {
        // Get all available hourly prices (packages) for this PlayStation
        $hourlyPrices = $playstation->getAvailableHoursWithPrices();
        $basePrice = (float) ($playstation->harga ?? 0);
        $costPerMinute = $basePrice / 60;
        
        // Split duration into full hours and remaining minutes
        $fullHours = (int) floor($durationMinutes / 60);
        $remainingMinutes = (int) ($durationMinutes % 60);
        
        $totalPrice = 0;
        
        // 1. Calculate price for the full hours part
        if ($fullHours > 0) {
            if (isset($hourlyPrices[$fullHours])) {
                // If there's an hourly package for this exact number of hours, use it
                $totalPrice += $hourlyPrices[$fullHours];
            } else {
                // Otherwise, multiply full hours by the base hourly price
                $totalPrice += $basePrice * $fullHours;
            }
        }
        
        // 2. Add per-minute cost for the remaining minutes
        if ($remainingMinutes > 0) {
            $totalPrice += $remainingMinutes * $costPerMinute;
        }
        
        return $totalPrice;
    }

    /**
     * Apply specific rounding rules for lost time pricing
     * If difference <= 500, round to 500
     * If difference > 500, round to 1000
     */
    private function applyLostTimeRounding($total)
    {
        // Find the nearest lower thousand
        $lowerThousand = floor($total / 1000) * 1000;
        
        // Calculate difference from the lower thousand
        $difference = $total - $lowerThousand;
        
        // Apply rounding rules
        if ($difference == 0) {
            // Already a perfect thousand
            return $total;
        } elseif ($difference <= 500) {
            // Round up to 500 (including exactly 500)
            return $lowerThousand + 500;
        } else {
            // Round up to next thousand
            return $lowerThousand + 1000;
        }
    }
}