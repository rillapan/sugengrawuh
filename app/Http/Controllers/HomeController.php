<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Playstation;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class HomeController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Show the application dashboard.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function index(Request $request)
    {
        if (auth()->user()->isFnbStaff()) {
            return redirect()->route('fnb.queue.index');
        }

        // Get period filter from request
        $period = $request->input('period', 'today'); // today, week, month, custom
        $start_date = $request->input('start_date');
        $end_date = $request->input('end_date');
        
        // Calculate date range based on period
        $dateRange = $this->getDateRange($period, $start_date, $end_date);
        
        $member = 0;
        $play = Playstation::count();
        $transaction = Transaction::count();
        
        // Calculate total revenue from paid transactions within date range
        $revenue = Transaction::where('payment_status', 'paid')
            ->whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
            ->sum('total');
        
        // Calculate revenue based on user role and shift within date range
        $todayRevenue = $this->calculateShiftRevenue($dateRange['start'], $dateRange['end']);
            
        // Calculate payment method breakdown for paid transactions within date range
        $user = auth()->user();
        
        if ($user->isKasir()) {
            // For cashiers, only show their own payment methods
            $todayPaymentMethodCounts = [
                'tunai' => Transaction::whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
                    ->where('payment_status', 'paid')
                    ->where('payment_method', 'tunai')
                    ->where('user_id', $user->id)
                    ->count(),
                'e-wallet' => Transaction::whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
                    ->where('payment_status', 'paid')
                    ->where('payment_method', 'e-wallet')
                    ->where('user_id', $user->id)
                    ->count(),
                'transfer_bank' => Transaction::whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
                    ->where('payment_status', 'paid')
                    ->where('payment_method', 'transfer_bank')
                    ->where('user_id', $user->id)
                    ->count(),
            ];

            $todayPaymentMethodTotals = [
                'tunai' => Transaction::whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
                    ->where('payment_status', 'paid')
                    ->where('payment_method', 'tunai')
                    ->where('user_id', $user->id)
                    ->sum('total'),
                'e-wallet' => Transaction::whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
                    ->where('payment_status', 'paid')
                    ->where('payment_method', 'e-wallet')
                    ->where('user_id', $user->id)
                    ->sum('total'),
                'transfer_bank' => Transaction::whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
                    ->where('payment_status', 'paid')
                    ->where('payment_method', 'transfer_bank')
                    ->where('user_id', $user->id)
                    ->sum('total'),
            ];
        } else {
            // For admin/owner, show all payment methods
            $todayPaymentMethodCounts = [
                'tunai' => Transaction::whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
                    ->where('payment_status', 'paid')
                    ->where('payment_method', 'tunai')
                    ->count(),
                'e-wallet' => Transaction::whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
                    ->where('payment_status', 'paid')
                    ->where('payment_method', 'e-wallet')
                    ->count(),
                'transfer_bank' => Transaction::whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
                    ->where('payment_status', 'paid')
                    ->where('payment_method', 'transfer_bank')
                    ->count(),
            ];

            $todayPaymentMethodTotals = [
                'tunai' => Transaction::whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
                    ->where('payment_status', 'paid')
                    ->where('payment_method', 'tunai')
                    ->sum('total'),
                'e-wallet' => Transaction::whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
                    ->where('payment_status', 'paid')
                    ->where('payment_method', 'e-wallet')
                    ->sum('total'),
                'transfer_bank' => Transaction::whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
                    ->where('payment_status', 'paid')
                    ->where('payment_method', 'transfer_bank')
                    ->sum('total'),
            ];
        }
        
        // Calculate revenue per user (kasir/shift) for the selected period
        $user = auth()->user();
        
        if ($user->isKasir()) {
            // For cashiers, only show their own revenue
            $todayRevenuePerUser = Transaction::selectRaw('users.name, users.role, users.work_shift_id, SUM(total) as revenue')
                ->join('users', 'users.id', '=', 'transactions.user_id')
                ->whereBetween('transactions.created_at', [$dateRange['start'], $dateRange['end']])
                ->where('transactions.payment_status', 'paid')
                ->where('users.id', $user->id)
                ->groupBy('users.id', 'users.name', 'users.role', 'users.work_shift_id')
                ->get();
        } else {
            // For admin/owner, show all users' revenue
            $todayRevenuePerUser = Transaction::selectRaw('users.name, users.role, users.work_shift_id, SUM(total) as revenue')
                ->join('users', 'users.id', '=', 'transactions.user_id')
                ->whereBetween('transactions.created_at', [$dateRange['start'], $dateRange['end']])
                ->where('transactions.payment_status', 'paid')
                ->groupBy('users.id', 'users.name', 'users.role', 'users.work_shift_id')
                ->get();
        }
            
        return view('home', [
            'title' => 'Dashboard',
            'active' => 'dashboard',
            'member' => $member,
            'play' => $play,
            'transaksi' => $transaction,
            'pendapatan' => $revenue,
            'todayRevenue' => $todayRevenue,
            'today_pendapatan' => $todayRevenue, // Add this variable for backward compatibility
            'todayPaymentMethodCounts' => $todayPaymentMethodCounts,
            'todayPaymentMethodTotals' => $todayPaymentMethodTotals,
            'todayRevenuePerUser' => $todayRevenuePerUser,
            'period' => $period,
            'start_date' => $start_date,
            'end_date' => $end_date,
            'dateRange' => $dateRange
        ]);
    }

    /**
     * Calculate date range based on period
     *
     * @param string $period
     * @param string|null $start_date
     * @param string|null $end_date
     * @return array
     */
    private function getDateRange($period, $start_date = null, $end_date = null)
    {
        $now = now();
        
        switch ($period) {
            case 'today':
                return [
                    'start' => $now->copy()->startOfDay(),
                    'end' => $now->copy()->endOfDay()
                ];
                
            case 'week':
                return [
                    'start' => $now->copy()->startOfWeek(),
                    'end' => $now->copy()->endOfWeek()
                ];
                
            case 'month':
                return [
                    'start' => $now->copy()->startOfMonth(),
                    'end' => $now->copy()->endOfMonth()
                ];
                
            case 'custom':
                if ($start_date && $end_date) {
                    return [
                        'start' => \Carbon\Carbon::parse($start_date)->startOfDay(),
                        'end' => \Carbon\Carbon::parse($end_date)->endOfDay()
                    ];
                }
                // Fallback to today if custom dates are invalid
                return [
                    'start' => $now->copy()->startOfDay(),
                    'end' => $now->copy()->endOfDay()
                ];
                
            default:
                return [
                    'start' => $now->copy()->startOfDay(),
                    'end' => $now->copy()->endOfDay()
                ];
        }
    }

    /**
     * Calculate revenue based on user's shift (for cashiers) or daily (for admin/owner)
     */
    private function calculateShiftRevenue($startDate = null, $endDate = null)
    {
        $user = auth()->user();
        
        // For admin and owner, show full revenue for the period
        if ($user->isAdmin() || $user->isOwner()) {
            $query = Transaction::where('payment_status', 'paid');
            if ($startDate && $endDate) {
                $query->whereBetween('created_at', [$startDate, $endDate]);
            } else {
                $query->whereDate('created_at', today());
            }
            return $query->sum('total');
        }
        
        // For cashiers, show revenue based on their shift
        if ($user->isKasir()) {
            $query = Transaction::where('payment_status', 'paid')
                ->where('user_id', $user->id);
                
            if ($startDate && $endDate) {
                $query->whereBetween('created_at', [$startDate, $endDate]);
            } else {
                $query->whereDate('created_at', today());
            }
            
            return $query->sum('total');
        }
        
        return 0;
    }

    /**
     * Calculate revenue based on user's shift (for cashiers) or daily (for admin/owner)
     */
    private function calculateShiftRevenueOld()
    {
        $user = auth()->user();
        
        // For admin and owner, show full daily revenue
        if ($user->isAdmin() || $user->isOwner()) {
            return Transaction::whereDate('created_at', today())
                ->where('payment_status', 'paid')
                ->sum('total');
        }
        
        // For cashier, show revenue within their shift hours
        if ($user->isKasir() && $user->workShift) {
            $shift = $user->workShift;
            $currentTime = now();
            
            // If current time is within shift, show shift revenue for today
            if ($shift->isWithinShiftTime($currentTime)) {
                return Transaction::whereDate('created_at', today())
                    ->where('payment_status', 'paid')
                    ->where(function($query) use ($shift) {
                        $query->whereRaw('TIME(created_at) >= ?', [$shift->jam_mulai])
                              ->whereRaw('TIME(created_at) <= ?', [$shift->jam_selesai]);
                    })
                    ->sum('total');
            }
        }
        
        // Default to daily revenue if no shift or outside shift hours
        return Transaction::whereDate('created_at', today())
            ->where('payment_status', 'paid')
            ->sum('total');
    }



    public function pieCartData2(Request $request = null)
    {
        // Get period parameters from request or default to today
        $period = $request ? $request->input('period', 'today') : 'today';
        $start_date = $request ? $request->input('start_date') : null;
        $end_date = $request ? $request->input('end_date') : null;
        
        // Calculate date range based on period
        $dateRange = $this->getDateRange($period, $start_date, $end_date);
        
        $user = auth()->user();
        
        $playstations = Playstation::with(['device.transaction' => function($query) use ($user, $dateRange) {
            $query->where('payment_status', 'paid')
                  ->whereBetween('created_at', [$dateRange['start'], $dateRange['end']]);
                  
            // For cashiers, only show their own transactions
            if ($user->isKasir()) {
                $query->where('user_id', $user->id);
            }
        }])
        ->whereHas('device.transaction', function($query) use ($user, $dateRange) {
            $query->where('payment_status', 'paid')
                  ->whereBetween('created_at', [$dateRange['start'], $dateRange['end']]);
                  
            // For cashiers, only show their own transactions
            if ($user->isKasir()) {
                $query->where('user_id', $user->id);
            }
        })
        ->get();
        
        $totalRevenue = [];
        $labels = [];
        $backgroundColors = ['#4e73df', '#1cc88a', '#36b9cc', '#f6c23e', '#e74a3b', '#858796'];
        $hoverBackgroundColors = ['#2e59d9', '#17a673', '#2c9faf', '#dda20a', '#be2617', '#6c757d'];

        foreach ($playstations as $index => $playstation) {
            $sumRevenue = $playstation->device->sum(function($device) use ($dateRange, $user) {
                return $device->transaction->sum(function($transaction) use ($user) {
                    // Calculate only PlayStation revenue (harga * jam_main), excluding FnB
                    $playstationRevenue = 0;
                    
                    if ($transaction->tipe_transaksi === 'custom_package') {
                        // For custom packages, use the package price (which is PlayStation cost only)
                        $playstationRevenue = $transaction->harga;
                    } elseif ($transaction->tipe_transaksi === 'prepaid') {
                        // For prepaid, calculate harga * jam_main
                        $playstationRevenue = ($transaction->harga ?? 0) * ($transaction->jam_main ?? 0);
                    } elseif ($transaction->tipe_transaksi === 'postpaid') {
                        // For postpaid, use the harga field (base price)
                        $playstationRevenue = $transaction->harga ?? 0;
                    }
                    
                    return $playstationRevenue;
                });
            });
            
            if ($sumRevenue > 0) {
                $labels[] = $playstation->nama . ' (' . number_format($sumRevenue, 0, ',', '.') . ')';
                $totalRevenue[] = $sumRevenue;
            }
        }

        $chartData = [
            'labels' => $labels,
            'datasets' => [
                [
                    'data' => $totalRevenue,
                    'backgroundColor' => array_slice($backgroundColors, 0, count($labels)),
                    'hoverBackgroundColor' => array_slice($hoverBackgroundColors, 0, count($labels)),
                    'hoverBorderColor' => "rgba(234, 236, 244, 1)",
                ]
            ]
        ];

        return $chartData;
    }
    
    public function popularFnbs()
    {
        $popularFnbs = \App\Models\TransactionFnb::selectRaw('fnbs.nama, SUM(transaction_fnbs.qty) as total_qty')
            ->join('fnbs', 'fnbs.id', '=', 'transaction_fnbs.fnb_id')
            ->join('transactions', 'transactions.id_transaksi', '=', 'transaction_fnbs.transaction_id')
            ->where('transactions.payment_status', 'paid')
            ->groupBy('fnbs.id', 'fnbs.nama')
            ->orderBy('total_qty', 'DESC')
            ->limit(5)
            ->get();
            
        // If no data, return empty arrays for chart
        if ($popularFnbs->isEmpty()) {
            return [
                'labels' => [],
                'data' => []
            ];
        }
        
        return [
            'labels' => $popularFnbs->pluck('nama')->toArray(),
            'data' => $popularFnbs->pluck('total_qty')->map(function($item) {
                return (int)$item;
            })->toArray()
        ];
    }

    public function hourlyRevenueData()
    {
        $totals = [];
        $labels = [];
        
        // Generate hourly data for today (00:00 to 23:00)
        for ($hour = 0; $hour <= 23; $hour++) {
            $hourFormatted = str_pad($hour, 2, '0', STR_PAD_LEFT) . ':00';
            $labels[] = $hourFormatted;
            
            // Only include paid transactions for accurate revenue data
            $total = Transaction::whereDate('created_at', today())
                ->whereRaw('HOUR(created_at) = ?', [$hour])
                ->where('payment_status', 'paid')
                ->sum('total');
            $totals[] = $total;
        }

        $chartData = [
            'labels' => $labels,
            'datasets' => [
                [
                    "label" => "Pendapatan Per Jam (Transaksi Paid)",
                    "lineTension" => 0.3,
                    "backgroundColor" => "rgba(78, 115, 223, 0.05)",
                    "borderColor" => "rgba(78, 115, 223, 1)",
                    "pointRadius" => 3,
                    "pointBackgroundColor" => "rgba(78, 115, 223, 1)",
                    "pointBorderColor" => "rgba(78, 115, 223, 1)",
                    "pointHoverRadius" => 3,
                    "pointHoverBackgroundColor" => "rgba(78, 115, 223, 1)",
                    "pointHoverBorderColor" => "rgba(78, 115, 223, 1)",
                    "pointHitRadius" => 10,
                    "pointBorderWidth" => 2,
                    "data" => $totals,
                ]
            ]
        ];

        return $chartData;
    }

    public function areaCartData()
    {
        $totals = [];
        $currentYear = date('Y');
        
        for ($bulan = 1; $bulan <= 12; $bulan++) {
            $total = Transaction::whereYear('created_at', $currentYear)
                ->whereMonth('created_at', $bulan)
                ->where('payment_status', 'paid')
                ->sum('total');
            $totals[] = $total;
        }

        $chartData = [
            'datasets' => [
                [
                    "label" => "Pendapatan (Transaksi Paid)",
                    "lineTension" => 0.3,
                    "backgroundColor" => "rgba(78, 115, 223, 0.05)",
                    "borderColor" => "rgba(78, 115, 223, 1)",
                    "pointRadius" => 3,
                    "pointBackgroundColor" => "rgba(78, 115, 223, 1)",
                    "pointBorderColor" => "rgba(78, 115, 223, 1)",
                    "pointHoverRadius" => 3,
                    "pointHoverBackgroundColor" => "rgba(78, 115, 223, 1)",
                    "pointHoverBorderColor" => "rgba(78, 115, 223, 1)",
                    "pointHitRadius" => 10,
                    "pointBorderWidth" => 2,
                    "data" => $totals,
                ]
            ]
        ];

        return $chartData;
    }

    public function profile()
    {
        return view('profile.index', [
            'title' => 'Profile',
            'active' => ''
        ]);
    }

    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);
        
        // Ensure user is updating their own profile
        if (auth()->id() !== $user->id) {
            abort(403);
        }

        $validatedData = $request->validate([
            'name' => 'required|min:3',
            'email' => [
                'required',
                'email',
                Rule::unique('users')->ignore($user->id),
            ],
            'password' => 'nullable|min:6|confirmed',
        ]);

        $dataToUpdate = [
            'name' => $validatedData['name'],
            'email' => $validatedData['email'],
        ];

        if ($request->filled('password')) {
            $dataToUpdate['password'] = Hash::make($validatedData['password']);
        }

        $user->update($dataToUpdate);

        return redirect('/profile')->with('success', 'Profile berhasil di update.');
    }
}
