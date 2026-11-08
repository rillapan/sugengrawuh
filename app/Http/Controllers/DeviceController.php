<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Member;
use App\Models\Playstation;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        $now = Carbon::now();
        $today = $now->toDateString();
        $currentTime = $now->format('H:i:s');

        // Update status for all devices based on transactions today
        $allDevices = Device::all();
        foreach ($allDevices as $device) {
            $transaction = Transaction::where('device_id', $device->id)
                ->whereDate('created_at', $today)
                ->latest()
                ->first();

            $newStatus = 'Tersedia';
            if ($transaction) {
                if ($transaction->tipe_transaksi === 'prepaid') {
                    $endTime = $transaction->waktu_Selesai;
                    if ($endTime) {
                        // Get the transaction start time and date
                        $startTime = $transaction->waktu_mulai;
                        $transactionDate = $transaction->created_at->format('Y-m-d');

                        // Create proper datetime objects for start and end times
                        $startDateTime = Carbon::parse($transactionDate . ' ' . $startTime);
                        $endDateTime = Carbon::parse($transactionDate . ' ' . $endTime);

                        // If end time is earlier than start time, it means it's the next day
                        if ($endDateTime < $startDateTime) {
                            $endDateTime->addDay();
                        }

                        // Compare with current time
                        if ($endDateTime > $now) {
                            $newStatus = 'Digunakan';
                        } else {
                            $newStatus = 'Tersedia';
                        }
                    } else {
                        $newStatus = 'Tersedia';
                    }
                } elseif ($transaction->tipe_transaksi === 'postpaid') {
                    if ($transaction->status_transaksi === 'berjalan') {
                        $newStatus = 'Digunakan';
                    } else {
                        $newStatus = 'Tersedia';
                    }
                } elseif ($transaction->tipe_transaksi === 'custom_package') {
                    $endTime = $transaction->waktu_Selesai;
                    if ($endTime) {
                        // Get the transaction start time and date
                        $startTime = $transaction->waktu_mulai;
                        $transactionDate = $transaction->created_at->format('Y-m-d');

                        // Create proper datetime objects for start and end times
                        $startDateTime = Carbon::parse($transactionDate . ' ' . $startTime);
                        $endDateTime = Carbon::parse($transactionDate . ' ' . $endTime);

                        // If end time is earlier than start time, it means it's the next day
                        if ($endDateTime < $startDateTime) {
                            $endDateTime->addDay();
                        }

                        // Compare with current time
                        if ($endDateTime > $now) {
                            $newStatus = 'Digunakan';
                        } else {
                            $newStatus = 'Tersedia';
                        }
                    } else {
                        $newStatus = 'Tersedia';
                    }
                }
            }
            $device->update(['status' => $newStatus]);
        }

        $latestTransactionsSub = Transaction::select('device_id', DB::raw('MAX(created_at) as latest_transaction_at'))
            ->groupBy('device_id');

        $withRelations = ['playstations', 'playstation'];
        if (\Illuminate\Support\Facades\Schema::hasTable('station_device_mappings')) {
            $withRelations[] = 'activeHardwareMappings.physicalDevice';
            $withRelations[] = 'activeDisplayTv.physicalDevice';
        }

        $devices = Device::select('devices.*')
            ->leftJoinSub($latestTransactionsSub, 'latest_transactions', 'devices.id', '=', 'latest_transactions.device_id')
            ->orderByRaw('CASE WHEN devices.status = "Digunakan" THEN 0 ELSE 1 END')
            ->orderBy('latest_transactions.latest_transaction_at', 'desc')
            ->with($withRelations)
            ->paginate(12);

        // Count devices by status
        $countAvailable = Device::where('status', 'Tersedia')->count();
        $countInUse = Device::where('status', 'Digunakan')->count();

        // Get device names by status
        $availableDevices = Device::where('status', 'Tersedia')->pluck('nama')->toArray();
        $inUseDevices = Device::where('status', 'Digunakan')->pluck('nama')->toArray();

        // Collect end times and transaction info for in-use devices
        $timers = [];
        $customers = [];
        $postpaidTransactions = [];
        
        foreach ($devices as $dev) {
            if ($dev->status === 'Digunakan') {
                $transaction = Transaction::where('device_id', $dev->id)
                    ->whereDate('created_at', $today)
                    ->latest()
                    ->first();
                    
                if ($transaction) {
                    // For prepaid transactions
                    if ($transaction->tipe_transaksi === 'prepaid' && $transaction->waktu_Selesai) {
                        // Get the transaction start time and date
                        $startTime = $transaction->waktu_mulai;
                        $transactionDate = $transaction->created_at->format('Y-m-d');
                        
                        // Create proper datetime objects for start and end times
                        $startDateTime = Carbon::parse($transactionDate . ' ' . $startTime);
                        $endDateTime = Carbon::parse($transactionDate . ' ' . $transaction->waktu_Selesai);
                        
                        // If end time is earlier than start time, it means it's the next day
                        if ($endDateTime < $startDateTime) {
                            $endDateTime->addDay();
                        }
                        
                        // Only set timer if the transaction is still active
                        if ($endDateTime > $now) {
                            $timers[$dev->id] = [
                                'end_time' => $transaction->waktu_Selesai,
                                'end_date' => $endDateTime->format('Y-m-d'),
                                'start_date' => $transactionDate,
                                'start_time' => $startTime
                            ];
                        }
                    }
                    // For custom package transactions
                    elseif ($transaction->tipe_transaksi === 'custom_package' && $transaction->waktu_Selesai) {
                        // Get the transaction start time and date
                        $startTime = $transaction->waktu_mulai;
                        $transactionDate = $transaction->created_at->format('Y-m-d');
                        
                        // Create proper datetime objects for start and end times
                        $startDateTime = Carbon::parse($transactionDate . ' ' . $startTime);
                        $endDateTime = Carbon::parse($transactionDate . ' ' . $transaction->waktu_Selesai);
                        
                        // If end time is earlier than start time, it means it's the next day
                        if ($endDateTime < $startDateTime) {
                            $endDateTime->addDay();
                        }
                        
                        // Only set timer if the transaction is still active
                        if ($endDateTime > $now) {
                            $timers[$dev->id] = [
                                'end_time' => $transaction->waktu_Selesai,
                                'end_date' => $endDateTime->format('Y-m-d'),
                                'start_date' => $transactionDate,
                                'start_time' => $startTime
                            ];
                        }
                    }
                    // For postpaid transactions that are still running
                    elseif ($transaction->tipe_transaksi === 'postpaid' && $transaction->status_transaksi === 'berjalan') {
                        $postpaidTransactions[$dev->id] = [
                            'start_time' => $transaction->waktu_mulai,
                            'start_date' => $transaction->created_at->format('Y-m-d'),
                            'lost_time_start' => $transaction->lost_time_start
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
                        'tanggal' => $transaction->created_at->format('Y-m-d'),
                        'tipe_transaksi' => $transaction->tipe_transaksi,
                        'status_transaksi' => $transaction->status_transaksi
                    ];
                }
            }
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
        $now = Carbon::now();
        $today = $now->toDateString();
        
        // Get all devices with their latest transactions
        $devices = Device::with(['playstations'])->get();
        
        foreach ($devices as $device) {
            // Get the latest transaction for this device
            $latestTransaction = Transaction::where('device_id', $device->id)
                ->where('payment_status', 'paid')
                ->latest()
                ->first();
                
            if (!$latestTransaction) {
                // No transaction found, set to available
                if ($device->status !== 'Tersedia') {
                    $device->update(['status' => 'Tersedia']);
                }
                continue;
            }
            
            $shouldUpdateStatus = false;
            $newStatus = $device->status;
            
            // Check different transaction types and their completion status
            if ($latestTransaction->tipe_transaksi === 'prepaid') {
                // For prepaid: check if timer has completed
                if ($latestTransaction->waktu_Selesai) {
                    $endTime = Carbon::parse(
                        $latestTransaction->created_at->format('Y-m-d') . ' ' . $latestTransaction->waktu_Selesai
                    );
                    
                    if ($endTime <= $now && $device->status === 'Digunakan') {
                        // Timer completed but device still marked as used
                        $shouldUpdateStatus = true;
                        $newStatus = 'Tersedia';
                    }
                }
            } elseif ($latestTransaction->tipe_transaksi === 'postpaid') {
                // For postpaid: check if transaction is completed
                if ($latestTransaction->status_transaksi === 'selesai') {
                    if ($device->status === 'Digunakan') {
                        $shouldUpdateStatus = true;
                        $newStatus = 'Tersedia';
                    }
                }
            } elseif ($latestTransaction->tipe_transaksi === 'custom_package') {
                // For custom packages: check if timer has completed
                if ($latestTransaction->waktu_Selesai) {
                    $endTime = Carbon::parse(
                        $latestTransaction->created_at->format('Y-m-d') . ' ' . $latestTransaction->waktu_Selesai
                    );
                    
                    if ($endTime <= $now && $device->status === 'Digunakan') {
                        // Timer completed but device still marked as used
                        $shouldUpdateStatus = true;
                        $newStatus = 'Tersedia';
                    }
                }
            }
            
            // Update status if needed
            if ($shouldUpdateStatus) {
                $device->update(['status' => $newStatus]);
            }
        }
        
        return response()->json([
            'success' => true,
            'message' => 'Status perangkat diperbarui berdasarkan timer aktif',
            'updated_count' => $devices->where('updated', true)->count()
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
     * Debug device status and transactions
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
                    'device_id' => $id
                ]);
            }

            $today = Carbon::now()->toDateString();
            
            // Get all transactions for this device today
            $allTransactions = Transaction::where('device_id', $id)
                ->whereDate('created_at', $today)
                ->get(['id_transaksi', 'tipe_transaksi', 'status_transaksi', 'waktu_mulai', 'waktu_Selesai', 'created_at']);

            // Get active transactions
            $activeTransactions = Transaction::where('device_id', $id)
                ->whereDate('created_at', $today)
                ->where(function($query) {
                    $query->where('status_transaksi', 'berjalan')
                          ->orWhereNull('status_transaksi');
                })
                ->get(['id_transaksi', 'tipe_transaksi', 'status_transaksi', 'waktu_mulai', 'waktu_Selesai', 'created_at']);

            return response()->json([
                'success' => true,
                'device_id' => $id,
                'device_name' => $device->nama,
                'device_status' => $device->status,
                'today' => $today,
                'all_transactions_count' => $allTransactions->count(),
                'active_transactions_count' => $activeTransactions->count(),
                'all_transactions' => $allTransactions,
                'active_transactions' => $activeTransactions,
                'should_show_stop_button' => $device->status === 'Digunakan' || $activeTransactions->count() > 0
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

            // Find any active transaction for this device today
            $today = Carbon::now()->toDateString();
            $activeTransaction = Transaction::where('device_id', $id)
                ->whereDate('created_at', $today)
                ->latest()
                ->first();

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
            /** @var \App\Services\BillingService $billingService */
            $billingService = app(\App\Services\BillingService::class);
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
        Device::destroy($id);

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

    public function updateStatus($id)
    {
        $device = Device::find($id);
        if ($device) {
            // Check if status is being changed to 'tersedia' and there's an active transaction
            if ($device->status !== 'Tersedia') {
                $activeTransaction = Transaction::where('device_id', $id)
                    ->where('status_transaksi', 'berjalan')
                    ->latest()
                    ->first();

                if ($activeTransaction) {
                    // End the active transaction and stop the timer
                    $waktuSelesai = Carbon::now();
                    $totalFnb = $activeTransaction->getFnbTotalAttribute();

                    if ($activeTransaction->tipe_transaksi === 'postpaid') {
                        // Calculate duration and cost for postpaid transactions
                        $waktuMulai = Carbon::parse($activeTransaction->created_at->toDateString() . ' ' . $activeTransaction->waktu_mulai);
                        $durationMinutes = $waktuMulai->diffInMinutes($waktuSelesai, false);

                        if ($durationMinutes < 0) {
                            $durationMinutes = 24 * 60 + $durationMinutes;
                        }

                        $playstation = $activeTransaction->device->playstation;
                        $totalPs = $this->calculateLostTimePrice($playstation, $durationMinutes);
                        $roundedTotalPs = $this->applyLostTimeRounding($totalPs);
                        $total = $roundedTotalPs + $totalFnb;

                        $hours = floor($durationMinutes / 60);
                        $minutes = $durationMinutes % 60;
                        $formattedDuration = sprintf('%d jam %d menit', $hours, $minutes);

                        $payload = [
                            'waktu_Selesai' => $waktuSelesai->format('H:i'),
                            'jam_main' => $formattedDuration,
                            'total' => $total,
                            'status_transaksi' => 'selesai',
                            'payment_status' => $activeTransaction->payment_status === 'paid' ? 'paid' : 'unpaid'
                        ];
                        if (Transaction::hasG2Schema()) {
                            $payload['ended_at'] = $waktuSelesai;
                            $payload['billing_status'] = Transaction::BILLING_COMPLETED;
                        }
                        $activeTransaction->update($payload);
                    } else {
                        // For prepaid and custom_package, mark as finished
                        $payload = [
                            'waktu_Selesai' => $waktuSelesai->format('H:i'),
                            'status_transaksi' => 'selesai',
                            'payment_status' => $activeTransaction->payment_status === 'paid' ? 'paid' : 'unpaid'
                        ];
                        if (Transaction::hasG2Schema()) {
                            $payload['ended_at'] = $waktuSelesai;
                            $payload['billing_status'] = Transaction::BILLING_COMPLETED;
                        }
                        $activeTransaction->update($payload);
                    }
                }
            }

            $device->update(['status' => 'Tersedia']);
            return response()->json(['success' => true]);
        }
        return response()->json(['success' => false], 404);
    }

    /**
     * Calculate the price for lost time based on PlayStation hourly prices
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

    /**
     * Provide synchronized server-side timer status for all devices.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getTimerSyncData()
    {
        $now = Carbon::now();
        $devices = Device::all();
        $deviceData = [];

        foreach ($devices as $dev) {
            $txQuery = Transaction::where('device_id', $dev->id);
            if (Transaction::hasG2Schema()) {
                $txQuery->where('billing_status', Transaction::BILLING_RUNNING);
            } else {
                $txQuery->where('status_transaksi', '!=', 'selesai');
            }
            $activeTx = $txQuery->latest()->first();

            $txInfo = null;
            if ($activeTx) {
                $hasG2 = Transaction::hasG2Schema();
                $remainingSeconds = 0;
                if ($hasG2 && $activeTx->expires_at) {
                    $remainingSeconds = max(0, $now->diffInSeconds($activeTx->expires_at, false));
                }

                $elapsedSeconds = 0;
                $started = ($hasG2 && $activeTx->started_at) ? $activeTx->started_at : Carbon::parse($activeTx->created_at->toDateString() . ' ' . $activeTx->waktu_mulai);
                if ($started) {
                    $elapsedSeconds = max(0, $started->diffInSeconds($now, false));
                }

                $txInfo = [
                    'id_transaksi' => $activeTx->id_transaksi,
                    'nama' => $activeTx->nama,
                    'tipe_transaksi' => $activeTx->tipe_transaksi,
                    'billing_status' => $hasG2 ? $activeTx->billing_status : 'RUNNING',
                    'started_at' => ($hasG2 && $activeTx->started_at) ? $activeTx->started_at->toIso8601String() : null,
                    'expires_at' => ($hasG2 && $activeTx->expires_at) ? $activeTx->expires_at->toIso8601String() : null,
                    'remaining_seconds' => $remainingSeconds,
                    'elapsed_seconds' => $elapsedSeconds,
                    'is_expired' => ($hasG2 && $activeTx->expires_at) ? $now->greaterThanOrEqualTo($activeTx->expires_at) : false,
                ];
            }

            $deviceData[] = [
                'id' => $dev->id,
                'nama' => $dev->nama,
                'status' => $dev->status,
                'transaction' => $txInfo,
            ];
        }

        return response()->json([
            'server_now' => $now->toIso8601String(),
            'server_timestamp' => $now->timestamp * 1000,
            'devices' => $deviceData,
        ]);
    }
}
