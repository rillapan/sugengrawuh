<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\HardwarePowerCommandLog;
use App\Models\Member;
use App\Models\Playstation;
use App\Models\StationDeviceMapping;
use App\Models\Transaction;
use App\Models\TvAlertEvent;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DeviceController extends Controller
{
    /**
     * Display devices by playstation type.
     *
     * @param  int  $playstationId
     * @return \Illuminate\Http\Response
     */
    public function byPlaystation($playstationId)
    {
        $playstation = Playstation::findOrFail($playstationId);
        $devices = Device::whereHas('playstations', function ($query) use ($playstationId) {
            $query->where('playstations.id', $playstationId);
        })
            ->with(['playstations', 'playstation'])
            ->paginate(10);

        $title = 'Devices - ' . $playstation->nama;
        $active = 'device'; // This will highlight the 'Data Perangkat' menu in the sidebar
        
        return view('device.by_playstation', compact('devices', 'title', 'playstation', 'active'));
    }
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $tz = config('app.timezone', 'Asia/Jakarta');
        $now = Carbon::now($tz);

        // Autonomous Expiration & Hardware Automation Heartbeat:
        // Evaluates expired sessions and dispatches pending hardware intents smoothly
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
            \Illuminate\Support\Facades\Log::warning('DeviceController index heartbeat issue: ' . $e->getMessage());
        }

        $withRelations = ['playstations', 'playstation'];
        if (Schema::hasTable('station_device_mappings')) {
            $withRelations[] = 'activeHardwareMappings.physicalDevice';
            $withRelations[] = 'activeDisplayTv.physicalDevice';
        }
        if (Schema::hasTable('locations')) {
            $withRelations[] = 'locations';
        }

        $sortedDevices = Device::sortStationsDeterministically(
            Device::with($withRelations)->get()
        );

        /** @var \App\Services\BillingService $billingService */
        $billingService = app(\App\Services\BillingService::class);
        $activeSessions = $billingService->resolveActiveSessionsByDevice(
            $sortedDevices->pluck('id'),
            $now,
            [],
            $sortedDevices
        );

        $stationNumbers = Device::resolveGlobalStationNumbers($sortedDevices);
        foreach ($sortedDevices as $device) {
            $device->setAttribute('station_number', $stationNumbers[$device->id] ?? null);
            $effectiveStatus = $activeSessions->has((int) $device->id) ? 'Digunakan' : $device->status;
            $device->setAttribute('effective_status', $effectiveStatus);
        }

        $perPage = 25;
        $currentPage = LengthAwarePaginator::resolveCurrentPage();
        $devices = new LengthAwarePaginator(
            $sortedDevices->forPage($currentPage, $perPage)->values(),
            $sortedDevices->count(),
            $perPage,
            $currentPage,
            [
                'path' => request()->url(),
                'query' => request()->query(),
            ]
        );

        // Derive status counts and station lists from effective status without mutating DB on GET
        $inUseCollection = $sortedDevices->filter(
            fn ($d) => ($d->effective_status ?? $d->status) === 'Digunakan'
        );
        $availableCollection = $sortedDevices->filter(
            fn ($d) => ($d->effective_status ?? $d->status) === 'Tersedia'
        );

        $countAvailable = $availableCollection->count();
        $countInUse = $inUseCollection->count();

        $availableDevices = $availableCollection->pluck('nama')->values()->toArray();
        $inUseDevices = $inUseCollection->pluck('nama')->values()->toArray();

        // Collect end times and transaction info for active sessions
        $timers = [];
        $customers = [];
        $postpaidTransactions = [];

        foreach ($devices as $dev) {
            $transaction = $activeSessions->get((int) $dev->id);
            if (!$transaction) {
                continue;
            }

            $effectiveStart = $transaction->getEffectiveStartTime();

            if (in_array($transaction->tipe_transaksi, ['prepaid', 'custom_package'], true)) {
                $targetExpiresAt = $transaction->resolveLegacyExpiresAt();
                if ($targetExpiresAt && $targetExpiresAt->gt($now)) {
                    $timers[$dev->id] = [
                        'expires_at' => $targetExpiresAt->toIso8601String(),
                        'end_time' => $targetExpiresAt->format('H:i:s'),
                        'end_date' => $targetExpiresAt->format('Y-m-d'),
                        'start_date' => $effectiveStart->format('Y-m-d'),
                        'start_time' => $transaction->waktu_mulai ?: $effectiveStart->format('H:i:s'),
                    ];
                }
            } elseif (
                $transaction->tipe_transaksi === 'postpaid'
                && (
                    $transaction->status_transaksi === 'berjalan'
                    || (Transaction::hasG2Schema() && $transaction->billing_status === Transaction::BILLING_RUNNING)
                )
            ) {
                $postpaidTransactions[$dev->id] = [
                    'start_time' => $transaction->waktu_mulai ?: $effectiveStart->format('H:i:s'),
                    'start_date' => $effectiveStart->format('Y-m-d'),
                    'lost_time_start' => $effectiveStart->toIso8601String(),
                ];
            }

            $customers[$dev->id] = [
                'id_transaksi' => $transaction->id_transaksi,
                'nama' => $transaction->nama ?? 'Tidak tersedia',
                'nama_perangkat' => $dev->nama,
                'jenis_playstation' => $dev->playstation_names ?? 'Tidak Diketahui',
                'jam_main' => $transaction->jam_main ?? 'Tidak tersedia',
                'waktu_mulai' => $transaction->waktu_mulai,
                'waktu_selesai' => $transaction->waktu_Selesai,
                'total' => $transaction->total ?? 'Tidak tersedia',
                'tanggal' => $effectiveStart->format('Y-m-d'),
                'tipe_transaksi' => $transaction->tipe_transaksi,
                'status_transaksi' => $transaction->status_transaksi,
            ];
        }

        $tvControlData = [];
        if (auth()->check() && auth()->user()->isAdmin()) {
            try {
                /** @var \App\Services\Hardware\TvAlertService $tvAlertService */
                $tvAlertService = app(\App\Services\Hardware\TvAlertService::class);
                $tvAlertService->evaluateAutoAlerts();
                foreach ($devices as $dev) {
                    if ($dev->getStationCategory() === Playstation::CATEGORY_PLAYSTATION) {
                        $tvControlData[$dev->id] = $tvAlertService->getStationControlStatus($dev, 10);
                    }
                }
            } catch (\Throwable $e) {
                // Non-blocking fallback if TV schema is unavailable
            }
        }

        return view('device.index', [
            'title' => 'Data Perangkat',
            'active' => 'device',
            'devices' => $devices,
            'stationNumbers' => $stationNumbers,
            'activeSessions' => $activeSessions,
            'timers' => $timers,
            'customers' => $customers,
            'postpaidTransactions' => $postpaidTransactions,
            'countAvailable' => $countAvailable,
            'countInUse' => $countInUse,
            'availableDevices' => $availableDevices,
            'inUseDevices' => $inUseDevices,
            'serverTimestamp' => $now->timestamp * 1000,
            'serverNow' => $now->toIso8601String(),
            'tvPresets' => \App\Models\TvAlertEvent::getPresetMessages(),
            'tvControlData' => $tvControlData,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $play = Playstation::all();
        return view('device.create', [
            'title' => 'Tambah Perangkat',
            'active' => 'device',
            'plays' => $play
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
        $validatedData = $request->validate([
            'nama' => 'required|min:3',
            'playstation_ids' => 'required|array|min:1',
            'playstation_ids.*' => 'exists:playstations,id',
            'status' => 'required'
        ]);

        try {
            // Create the device
            $device = Device::create([
                'nama' => $validatedData['nama'],
                'status' => $validatedData['status'],
                'playstation_id' => $validatedData['playstation_ids'][0] // Keep for backward compatibility
            ]);

            // Attach the device to multiple PlayStations
            $device->playstations()->attach($validatedData['playstation_ids']);

            return redirect('device')->with('success', 'Data perangkat berhasil ditambahkan.');
        } catch (\Exception $e) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Terjadi kesalahan saat menyimpan perangkat: ' . $e->getMessage());
        }
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        $device = Device::with('playstations')->find($id);
        $playstation = Playstation::all();
        return view('device.edit', [
            'title' => 'Edit Perangkat',
            'active' => 'device',
            'device' => $device,
            'playstations' => $playstation
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
        $validatedData = $request->validate([
            'nama' => 'required|min:3',
            'playstation_ids' => 'required|array|min:1',
            'playstation_ids.*' => 'exists:playstations,id'
        ]);

        $device = Device::find($id);
        
        // Update device basic info
        $device->update([
            'nama' => $validatedData['nama']
        ]);

        // Update PlayStation relationships
        $device->playstations()->sync($validatedData['playstation_ids']);

        return redirect('device')->with('success', 'Data perangkat berhasil diperbarui.');
    }

    /**
     * Update device status via AJAX.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */


    /**
     * Check and update device status based on active timers
     * This method should be called periodically to update device statuses
     */
    public function updateDeviceStatusesFromTimers()
    {
        $tz = config('app.timezone', 'Asia/Jakarta');
        $now = Carbon::now($tz);

        $devices = Device::with(['playstations'])->get();

        /** @var \App\Services\BillingService $billingService */
        $billingService = app(\App\Services\BillingService::class);
        $activeSessions = $billingService->resolveActiveSessionsByDevice(
            $devices->pluck('id'),
            $now,
            [],
            $devices
        );

        $updatedCount = 0;

        foreach ($devices as $device) {
            // Never release a device that has an authoritative active session
            if ($activeSessions->has((int) $device->id)) {
                if ($device->status !== 'Digunakan') {
                    $device->update(['status' => 'Digunakan']);
                    $updatedCount++;
                }
                continue;
            }

            if ($device->status !== 'Digunakan') {
                continue;
            }

            // Remaining occupied device without an active session in $activeSessions
            $latestTransaction = Transaction::where('device_id', $device->id)
                ->latest('id_transaksi')
                ->first();

            if (!$latestTransaction) {
                $device->update(['status' => 'Tersedia']);
                $updatedCount++;
                continue;
            }

            $shouldRelease = false;

            if (in_array($latestTransaction->status_transaksi, ['selesai', 'draft'], true)) {
                $shouldRelease = true;
            } elseif (
                Transaction::hasG2Schema()
                && in_array($latestTransaction->billing_status, [
                    Transaction::BILLING_COMPLETED,
                    Transaction::BILLING_STOPPED_EARLY,
                    Transaction::BILLING_EXPIRED_PENDING_ACTION,
                    Transaction::BILLING_IDLE,
                ], true)
            ) {
                $shouldRelease = true;
            } elseif (in_array($latestTransaction->tipe_transaksi, ['prepaid', 'custom_package'], true)) {
                $expiresAt = $latestTransaction->resolveLegacyExpiresAt();

                if (!$expiresAt || $expiresAt->lte($now)) {
                    $shouldRelease = true;
                }
            } elseif ($latestTransaction->tipe_transaksi === 'postpaid' && $latestTransaction->status_transaksi !== 'berjalan') {
                $shouldRelease = true;
            }

            if ($shouldRelease) {
                $device->update(['status' => 'Tersedia']);
                $updatedCount++;
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Status perangkat diperbarui berdasarkan timer aktif',
            'updated_count' => $updatedCount,
        ]);
    }

    /**
     * Test device status update functionality
     */
    public function testStatus($id)
    {
        $device = Device::find($id);
        
        if (!$device) {
            return response()->json([
                'success' => false,
                'message' => 'Device not found',
                'device_id' => $id
            ]);
        }
        
        return response()->json([
            'success' => true,
            'message' => 'Device found',
            'device_id' => $id,
            'current_status' => $device->status,
            'device_name' => $device->nama,
            'csrf_token' => csrf_token()
        ]);
    }

    /**
     * Debug device status and transactions (read-only diagnostic endpoint).
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function debugDevice($id)
    {
        try {
            $device = Device::find($id);

            if (!$device) {
                return response()->json([
                    'success' => false,
                    'message' => 'Perangkat tidak ditemukan',
                    'device_id' => $id,
                ]);
            }

            $tz = config('app.timezone', 'Asia/Jakarta');
            $now = Carbon::now($tz);
            $today = $now->toDateString();

            /** @var \App\Services\BillingService $billingService */
            $billingService = app(\App\Services\BillingService::class);
            $activeSession = $billingService->resolveActiveSessionForDevice((int) $id, $now);

            $hasG2 = Transaction::hasG2Schema();
            $selectColumns = $hasG2
                ? ['id_transaksi', 'tipe_transaksi', 'status_transaksi', 'billing_status', 'waktu_mulai', 'waktu_Selesai', 'started_at', 'expires_at', 'created_at']
                : ['id_transaksi', 'tipe_transaksi', 'status_transaksi', 'waktu_mulai', 'waktu_Selesai', 'created_at'];

            if ($hasG2) {
                $activeTransactions = Transaction::where('device_id', $id)
                    ->where('billing_status', Transaction::BILLING_RUNNING)
                    ->latest('id_transaksi')
                    ->get($selectColumns);

                if ($activeTransactions->isEmpty() && $activeSession) {
                    $activeTransactions = Transaction::where('id_transaksi', $activeSession->id_transaksi)
                        ->get($selectColumns);
                }
            } else {
                $activeTransactions = $activeSession
                    ? Transaction::where('id_transaksi', $activeSession->id_transaksi)->get($selectColumns)
                    : collect();
            }

            $activeIds = $activeTransactions->pluck('id_transaksi')->all();

            // Include today's transactions plus any active cross-midnight transactions
            $allTransactions = Transaction::where('device_id', $id)
                ->where(function ($query) use ($today, $activeIds) {
                    $query->whereDate('created_at', $today);
                    if (!empty($activeIds)) {
                        $query->orWhereIn('id_transaksi', $activeIds);
                    }
                })
                ->latest('id_transaksi')
                ->get($selectColumns);

            $effectiveStatus = $activeSession ? 'Digunakan' : $device->status;
            $hasStatusMismatch = ($activeSession !== null && $device->status !== 'Digunakan')
                || ($activeSession === null && $device->status === 'Digunakan');

            return response()->json([
                'success' => true,
                'device_id' => $id,
                'device_name' => $device->nama,
                'device_status' => $device->status,
                'effective_status' => $effectiveStatus,
                'has_status_mismatch' => $hasStatusMismatch,
                'today' => $today,
                'active_session_id' => $activeSession ? $activeSession->id_transaksi : null,
                'all_transactions_count' => $allTransactions->count(),
                'active_transactions_count' => $activeTransactions->count(),
                'all_transactions' => $allTransactions,
                'active_transactions' => $activeTransactions,
                'should_show_stop_button' => ($effectiveStatus === 'Digunakan') || $activeTransactions->isNotEmpty(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
                'device_id' => $id,
            ]);
        }
    }

    /**
     * Test stop device functionality (debugging)
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function testStopDevice($id)
    {
        try {
            $device = Device::find($id);
            
            if (!$device) {
                return response()->json([
                    'success' => false,
                    'message' => 'Perangkat tidak ditemukan',
                    'device_id' => $id
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Test berhasil - device ditemukan',
                'device_id' => $id,
                'device_name' => $device->nama,
                'current_status' => $device->status,
                'csrf_token' => csrf_token()
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
                'device_id' => $id
            ]);
        }
    }

    /**
     * Stop device timer and change status to 'Tersedia'
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function stopDevice($id)
    {
        try {
            $device = Device::find($id);
            
            if (!$device) {
                return response()->json([
                    'success' => false,
                    'message' => 'Perangkat tidak ditemukan'
                ], 404);
            }

            $now = Carbon::now(config('app.timezone', 'Asia/Jakarta'));

            /** @var \App\Services\BillingService $billingService */
            $billingService = app(\App\Services\BillingService::class);
            $activeTransaction = $billingService->resolveActiveSessionForDevice((int) $id, $now);

            if (!$activeTransaction) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak ada transaksi aktif untuk perangkat ini'
                ], 400);
            }
            
            // Skip if transaction is already finished
            if ($activeTransaction->status_transaksi === 'selesai') {
                return response()->json([
                    'success' => false,
                    'message' => 'Transaksi sudah selesai'
                ], 400);
            }

            $previousStatus = $device->status;

            // Delegate core session stop and outbox intent creation to BillingService
            $stoppedTx = $billingService->stopSession($activeTransaction->id_transaksi, 'cashier_stop');

            return response()->json([
                'success' => true,
                'message' => 'Timer perangkat berhasil dihentikan dan status diubah menjadi tersedia',
                'device_id' => $id,
                'device_name' => $device->nama,
                'new_status' => 'Tersedia',
                'previous_status' => $previousStatus,
                'transaction_id' => $stoppedTx->id_transaksi
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $device = Device::findOrFail($id);

        // Prevent deletion if there are active or unpaid transactions
        $hasActiveTransactions = Transaction::where('device_id', $id)
            ->where(function ($q) {
                if (Transaction::hasG2Schema()) {
                    $q->where('billing_status', Transaction::BILLING_RUNNING);
                } else {
                    $q->where('status_transaksi', '!=', 'selesai');
                }
                $q->orWhere('payment_status', 'unpaid');
            })
            ->where('created_at', '>=', now()->subHours(24))
            ->exists();

        if ($hasActiveTransactions) {
            return redirect('device')->with('error', 'Perangkat tidak dapat dihapus karena masih memiliki transaksi yang sedang berjalan atau belum lunas.');
        }

        DB::transaction(function () use ($device, $id) {
            // Clean up related hardware mappings
            StationDeviceMapping::where('station_id', $id)->delete();

            // Clean up hardware command logs
            HardwarePowerCommandLog::where('station_id', $id)->delete();

            // Clean up TV alerts & automation intents if any
            TvAlertEvent::where('station_id', $id)->delete();
            DB::table('automation_intents')->where('station_id', $id)->delete();
            DB::table('automation_execution_logs')->where('station_id', $id)->delete();

            // Detach playstation pivot
            $device->playstations()->detach();

            // Delete locations
            $device->locations()->delete();

            // Delete device
            $device->delete();
        });

        return redirect('device')->with('success', 'Data perangkat berhasil dihapus.');
    }

    public function booking($id)
    {
        $tanggal = Carbon::now()->format('Y-m-d');
        $device = Device::find($id);
        $transactions = Transaction::where('device_id', $id)->whereDate('created_at', $tanggal)->paginate(5);
        // ddd($transactions);
        return view('device.booking', [
            'title' => 'Booking',
            'active' => 'device',
            'transactions' => $transactions,
            'device' => $device
        ]);
    }

    public function bookingAdd($id)
    {
        $device = Device::find($id);
        $playstation = Playstation::all();
        return view('device.booking-add', [
            'title' => 'Add Booking',
            'active' => 'device',
            'playstations' => $playstation,
            'device' => $device,
        ]);
    }

    /**
     * Legacy station status release endpoint.
     * Refuses to free a station or terminate an active RUNNING session without a legitimate stop/end flow.
     */
    public function updateStatus($id)
    {
        $user = auth()->user();
        $role = strtolower((string) ($user->role ?? ''));
        if (!$user || !in_array($role, ['admin', 'kasir'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $tz = config('app.timezone', 'Asia/Jakarta');
        $now = Carbon::now($tz);
        /** @var \App\Services\BillingService $billingService */
        $billingService = app(\App\Services\BillingService::class);

        if (Transaction::hasG2Schema()) {
            try {
                $billingService->evaluateExpiration($now, (int) $id);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Evaluate expiration in updateStatus failed: ' . $e->getMessage());
            }
        }

        return DB::transaction(function () use ($id, $now, $billingService) {
            $device = Device::where('id', $id)->lockForUpdate()->first();
            if (!$device) {
                return response()->json(['success' => false, 'message' => 'Perangkat tidak ditemukan'], 404);
            }

            $activeSession = $billingService->resolveActiveSessionForDevice((int) $device->id, $now);
            if ($activeSession) {
                if ($device->status !== 'Digunakan') {
                    $device->update(['status' => 'Digunakan']);
                }

                return response()->json([
                    'success' => false,
                    'message' => 'Stasiun masih memiliki sesi billing aktif. Gunakan tombol Berhenti atau Selesai yang sah untuk mengakhiri sesi.',
                    'device_id' => $device->id,
                    'device_status' => 'Digunakan',
                    'transaction_id' => $activeSession->id_transaksi,
                ], 409);
            }

            if ($device->status !== 'Tersedia') {
                $device->update(['status' => 'Tersedia']);
            }

            return response()->json([
                'success' => true,
                'device_id' => $device->id,
                'new_status' => 'Tersedia',
            ]);
        });
    }

    /**
     * Calculate lost time price based on hourly pricing rules (delegated to BillingService)
     */
    private function calculateLostTimePrice($playstation, $durationMinutes)
    {
        return app(\App\Services\BillingService::class)->calculateLostTimePrice($playstation, (int) $durationMinutes);
    }

    /**
     * Apply specific rounding rules for lost time pricing (delegated to BillingService)
     */
    private function applyLostTimeRounding($total)
    {
        return app(\App\Services\BillingService::class)->applyLostTimeRounding((float) $total);
    }

    /**
     * Provide synchronized server-side timer status for all devices.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getTimerSyncData()
    {
        $now = Carbon::now(config('app.timezone', 'Asia/Jakarta'));

        // Autonomous Expiration & Hardware Automation Heartbeat:
        // Evaluates expired sessions and dispatches pending hardware intents smoothly
        // even if no background CLI daemon is currently running.
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
            \Illuminate\Support\Facades\Log::warning('Device timer sync heartbeat issue: ' . $e->getMessage());
        }

        $devices = Device::all();
        $hasG2 = Transaction::hasG2Schema();
        $billingService = app(\App\Services\BillingService::class);
        $activeSessions = $billingService->resolveActiveSessionsByDevice(
            $devices->pluck('id'),
            $now,
            [],
            $devices
        );
        $outstandingUnpaidByDevice = $billingService->resolveOutstandingUnpaidTransactionsByDevice(
            $devices->pluck('id'),
            [],
            $activeSessions->pluck('id_transaksi')->filter()->values()
        );

        $deviceData = [];

        foreach ($devices as $dev) {
            $activeTx = $activeSessions->get((int) $dev->id);
            $effectiveStatus = $activeTx ? 'Digunakan' : $dev->status;
            $txInfo = null;

            if ($activeTx) {
                $started = $activeTx->getEffectiveStartTime();
                $expiresAt = $activeTx->resolveLegacyExpiresAt();
                $remainingSeconds = $expiresAt ? max(0, $now->diffInSeconds($expiresAt, false)) : 0;
                $elapsedSeconds = max(0, $started->diffInSeconds($now, false));

                $txInfo = [
                    'id_transaksi' => $activeTx->id_transaksi,
                    'nama' => $activeTx->nama,
                    'tipe_transaksi' => $activeTx->tipe_transaksi,
                    'billing_status' => $hasG2 ? ($activeTx->billing_status ?: 'RUNNING') : 'RUNNING',
                    'started_at' => $started->toIso8601String(),
                    'expires_at' => $expiresAt ? $expiresAt->toIso8601String() : null,
                    'remaining_seconds' => $remainingSeconds,
                    'elapsed_seconds' => $elapsedSeconds,
                    'is_expired' => $expiresAt ? $now->greaterThanOrEqualTo($expiresAt) : false,
                ];
            }

            $deviceData[] = [
                'id' => $dev->id,
                'nama' => $dev->nama,
                'status' => $effectiveStatus,
                'transaction' => $txInfo,
                'unpaid_transaction_ids' => $outstandingUnpaidByDevice
                    ->get((int) $dev->id, collect())
                    ->pluck('id_transaksi')
                    ->map(fn ($id) => (int) $id)
                    ->values()
                    ->all(),
            ];
        }

        return response()->json([
            'server_now' => $now->toIso8601String(),
            'server_timestamp' => $now->timestamp * 1000,
            'devices' => $deviceData,
        ]);
    }
}
