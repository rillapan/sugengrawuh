<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Playstation;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Barryvdh\DomPDF\Facade\Pdf as PDF;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\TransactionsExport;
use Carbon\Carbon;

class ReportController extends Controller
{
    private function getDatesFromRequest(Request $request)
    {
        $period = $request->input('period');
        $startDate = $request->input('tanggal_awal');
        $endDate = $request->input('tanggal_akhir');

        if ($period) {
            $now = Carbon::now();
            switch ($period) {
                case 'today':
                    $startDate = $now->copy()->startOfDay()->toDateString();
                    $endDate = $now->copy()->endOfDay()->toDateString();
                    break;
                case 'this_week':
                    $startDate = $now->copy()->startOfWeek()->toDateString();
                    $endDate = $now->copy()->endOfWeek()->toDateString();
                    break;
                case 'this_month':
                    $startDate = $now->copy()->startOfMonth()->toDateString();
                    $endDate = $now->copy()->endOfMonth()->toDateString();
                    break;
                case 'this_year':
                    $startDate = $now->copy()->startOfYear()->toDateString();
                    $endDate = $now->copy()->endOfYear()->toDateString();
                    break;
                case 'custom':
                default:
                    // Use provided start/end dates
                    break;
            }
        }

        return [$startDate, $endDate, $period];
    }

    /**
     * Query paid game/package transactions for a period, optionally filtered by PlayStation types.
     */
    private function getPaidTransactionsQuery(Carbon $start, Carbon $end, array $selectedPlaystationIds = [])
    {
        $query = Transaction::with(['device.playstation', 'transactionFnbs.fnb', 'custom_package'])
            ->where('payment_status', 'paid')
            ->whereBetween('created_at', [$start, $end]);

        if (!empty($selectedPlaystationIds)) {
            $query->whereHas('device', function ($q) use ($selectedPlaystationIds) {
                $q->whereIn('playstation_id', $selectedPlaystationIds);
            });
        }

        return $query;
    }

    /**
     * Query paid standalone F&B invoices (Paket extra, Lost Time early F&B, Non-playing walk-in),
     * excluding TYPE_GAME_AND_FNB and TYPE_GAME_ONLY to avoid double counting with Transaction.total.
     */
    private function getStandaloneFnbInvoicesQuery(array $selectedPlaystationIds = [])
    {
        $query = Invoice::with(['transaction.device.playstation', 'cashierUser', 'items'])
            ->where('invoice_type', Invoice::TYPE_FNB_STANDALONE)
            ->whereNotNull('paid_at');

        if (!empty($selectedPlaystationIds)) {
            $query->whereHas('transaction.device', function ($q) use ($selectedPlaystationIds) {
                $q->whereIn('playstation_id', $selectedPlaystationIds);
            });
        }

        return $query;
    }

    /**
     * Build sorted date => total map combining paid transactions and paid standalone F&B invoices.
     */
    private function buildDailyTotals(Collection $transactions, Collection $standaloneInvoices): Collection
    {
        $totals = [];

        foreach ($transactions as $transaction) {
            $date = $transaction->created_at->format('Y-m-d');
            $totals[$date] = ($totals[$date] ?? 0) + (float) $transaction->total;
        }

        foreach ($standaloneInvoices as $invoice) {
            $date = $invoice->paid_at->format('Y-m-d');
            $totals[$date] = ($totals[$date] ?? 0) + (float) $invoice->final_total;
        }

        ksort($totals);

        return collect($totals);
    }

    /**
     * Build chart labels, single-series data, or multi-PlayStation datasets for a given period.
     *
     * @return array{0: array, 1: array, 2: array}
     */
    private function buildChartSeries(
        ?string $period,
        Collection $periodTransactions,
        Collection $periodStandaloneInvoices,
        Collection $playstationTypes,
        array $selectedPlaystationIds
    ): array {
        $chartLabels = [];
        $chartData = [];
        $chartDatasets = [];

        $colors = [
            'rgba(78, 115, 223, 1)',
            'rgba(28, 200, 138, 1)',
            'rgba(255, 99, 132, 1)',
            'rgba(255, 206, 86, 1)',
            'rgba(54, 162, 235, 1)',
        ];
        $bgColors = [
            'rgba(78, 115, 223, 0.05)',
            'rgba(28, 200, 138, 0.05)',
            'rgba(255, 99, 132, 0.05)',
            'rgba(255, 206, 86, 0.05)',
            'rgba(54, 162, 235, 0.05)',
        ];

        $resolveBucket = function (Carbon $timestamp) use ($period) {
            switch ($period) {
                case 'today':
                    return (int) $timestamp->format('H');
                case 'this_week':
                    return $timestamp->dayOfWeekIso - 1;
                case 'this_month':
                    $weekNum = $timestamp->weekOfMonth;
                    return ($weekNum > 5) ? 4 : $weekNum - 1;
                case 'this_year':
                    return $timestamp->month - 1;
                case 'custom':
                default:
                    return $timestamp->format('Y-m-d');
            }
        };

        switch ($period) {
            case 'today':
                for ($i = 0; $i < 24; $i++) {
                    $chartLabels[] = str_pad($i, 2, '0', STR_PAD_LEFT) . ':00';
                }
                $bucketCount = 24;
                break;
            case 'this_week':
                $chartLabels = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
                $bucketCount = 7;
                break;
            case 'this_month':
                for ($i = 1; $i <= 5; $i++) {
                    $chartLabels[] = 'Minggu ke-' . $i;
                }
                $bucketCount = 5;
                break;
            case 'this_year':
                $chartLabels = [
                    'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
                    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
                ];
                $bucketCount = 12;
                break;
            case 'custom':
            default:
                $txDates = $periodTransactions->map(fn ($t) => $t->created_at->format('Y-m-d'));
                $invDates = $periodStandaloneInvoices->map(fn ($inv) => $inv->paid_at->format('Y-m-d'));
                $allDates = $txDates->merge($invDates)->unique()->sort()->values();
                $chartLabels = $allDates->toArray();
                $bucketCount = $allDates->count();
                break;
        }

        $isCustom = !in_array($period, ['today', 'this_week', 'this_month', 'this_year'], true);
        $dateIndexMap = $isCustom ? array_flip($chartLabels) : [];

        $accumulateSeries = function (Collection $transactions, Collection $invoices) use ($bucketCount, $isCustom, $dateIndexMap, $resolveBucket): array {
            $series = array_fill(0, $bucketCount, 0);

            foreach ($transactions as $t) {
                $bucket = $resolveBucket($t->created_at);
                $idx = $isCustom ? ($dateIndexMap[$bucket] ?? null) : $bucket;
                if ($idx !== null && array_key_exists($idx, $series)) {
                    $series[$idx] += (float) $t->total;
                }
            }

            foreach ($invoices as $inv) {
                $bucket = $resolveBucket($inv->paid_at);
                $idx = $isCustom ? ($dateIndexMap[$bucket] ?? null) : $bucket;
                if ($idx !== null && array_key_exists($idx, $series)) {
                    $series[$idx] += (float) $inv->final_total;
                }
            }

            return $series;
        };

        if (!empty($selectedPlaystationIds)) {
            foreach ($selectedPlaystationIds as $index => $psId) {
                $playstation = $playstationTypes->find($psId);
                $psTransactions = $periodTransactions->filter(fn ($t) => $t->device && $t->device->playstation_id == $psId);
                $psInvoices = $periodStandaloneInvoices->filter(fn ($inv) => $inv->transaction && $inv->transaction->device && $inv->transaction->device->playstation_id == $psId);

                $chartDatasets[] = [
                    'label' => $playstation ? $playstation->nama : 'Playstation ' . $psId,
                    'data' => $accumulateSeries($psTransactions, $psInvoices),
                    'borderColor' => $colors[$index % count($colors)],
                    'backgroundColor' => $bgColors[$index % count($bgColors)],
                    'lineTension' => 0.3,
                    'pointRadius' => 3,
                    'pointBackgroundColor' => $colors[$index % count($colors)],
                    'pointBorderColor' => $colors[$index % count($colors)],
                    'pointHoverRadius' => 3,
                    'pointHoverBackgroundColor' => $colors[$index % count($colors)],
                    'pointHoverBorderColor' => $colors[$index % count($colors)],
                    'pointHitRadius' => 10,
                    'pointBorderWidth' => 2,
                ];
            }
        } else {
            $chartData = $accumulateSeries($periodTransactions, $periodStandaloneInvoices);
        }

        return [$chartLabels, $chartData, $chartDatasets];
    }

    public function index(Request $request)
    {
        $now = Carbon::now();

        // Get all PlayStation types for filtering
        $playstationTypes = Playstation::all();

        // Include paid Transaction totals PLUS paid standalone F&B invoices (no double-counting GAME_AND_FNB / GAME_ONLY)
        $dailyIncome = Transaction::where('payment_status', 'paid')
            ->whereDate('created_at', $now->toDateString())
            ->sum('total')
            + Invoice::where('invoice_type', Invoice::TYPE_FNB_STANDALONE)
                ->whereNotNull('paid_at')
                ->whereDate('paid_at', $now->toDateString())
                ->sum('final_total');

        $monthlyIncome = Transaction::where('payment_status', 'paid')
            ->whereYear('created_at', $now->year)
            ->whereMonth('created_at', $now->month)
            ->sum('total')
            + Invoice::where('invoice_type', Invoice::TYPE_FNB_STANDALONE)
                ->whereNotNull('paid_at')
                ->whereYear('paid_at', $now->year)
                ->whereMonth('paid_at', $now->month)
                ->sum('final_total');

        $yearlyIncome = Transaction::where('payment_status', 'paid')
            ->whereYear('created_at', $now->year)
            ->sum('total')
            + Invoice::where('invoice_type', Invoice::TYPE_FNB_STANDALONE)
                ->whereNotNull('paid_at')
                ->whereYear('paid_at', $now->year)
                ->sum('final_total');

        // Resolve dates
        list($startDate, $endDate, $period) = $this->getDatesFromRequest($request);

        // Get selected PlayStation types from request
        $selectedPlaystationIds = $request->input('playstation_types', []);

        $periodIncome = 0;
        $chartData = [];
        $chartLabels = [];
        $chartDatasets = [];

        if ($startDate && $endDate) {
            $start = Carbon::parse($startDate)->startOfDay();
            $end = Carbon::parse($endDate)->endOfDay();

            $periodTransactions = $this->getPaidTransactionsQuery($start, $end, $selectedPlaystationIds)->get();
            $periodStandaloneInvoices = $this->getStandaloneFnbInvoicesQuery($selectedPlaystationIds)
                ->whereBetween('paid_at', [$start, $end])
                ->get();

            $periodIncome = $periodTransactions->sum('total') + $periodStandaloneInvoices->sum('final_total');

            [$chartLabels, $chartData, $chartDatasets] = $this->buildChartSeries(
                $period,
                $periodTransactions,
                $periodStandaloneInvoices,
                $playstationTypes,
                $selectedPlaystationIds
            );
        }

        return view('laporan.index', [
            'title' => 'Laporan',
            'active' => 'report',
            'dailyIncome' => $dailyIncome,
            'monthlyIncome' => $monthlyIncome,
            'yearlyIncome' => $yearlyIncome,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'period' => $period,
            'periodIncome' => $periodIncome,
            'chartLabels' => $chartLabels,
            'chartData' => $chartData,
            'chartDatasets' => $chartDatasets,
            'playstationTypes' => $playstationTypes,
            'selectedPlaystationIds' => $selectedPlaystationIds,
        ]);
    }

    public function generatePDF(Request $request)
    {
        list($startDate, $endDate, $period) = $this->getDatesFromRequest($request);
        $selectedPlaystationIds = $request->input('playstation_types', []);

        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->endOfDay();

        $transactions = $this->getPaidTransactionsQuery($start, $end, $selectedPlaystationIds)->get();
        $standaloneInvoices = $this->getStandaloneFnbInvoicesQuery($selectedPlaystationIds)
            ->whereBetween('paid_at', [$start, $end])
            ->get();

        $total = $transactions->sum('total') + $standaloneInvoices->sum('final_total');
        $dailyTotals = $this->buildDailyTotals($transactions, $standaloneInvoices);

        $pdf = PDF::loadView('laporan.cetak-pdf', [
            'title' => 'Laporan Transaksi',
            'active' => 'report',
            'transactions' => $transactions,
            'standaloneInvoices' => $standaloneInvoices,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'total' => $total,
            'dailyTotals' => $dailyTotals,
            'selectedPlaystationIds' => $selectedPlaystationIds,
        ]);
        return $pdf->download('laporan.pdf');
    }

    public function generateExcel(Request $request)
    {
        list($startDate, $endDate, $period) = $this->getDatesFromRequest($request);
        $selectedPlaystationIds = $request->input('playstation_types', []);

        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->endOfDay();

        $transactions = $this->getPaidTransactionsQuery($start, $end, $selectedPlaystationIds)->get();
        $standaloneInvoices = $this->getStandaloneFnbInvoicesQuery($selectedPlaystationIds)
            ->whereBetween('paid_at', [$start, $end])
            ->get();

        $total = $transactions->sum('total') + $standaloneInvoices->sum('final_total');
        $dailyTotals = $this->buildDailyTotals($transactions, $standaloneInvoices);

        return Excel::download(
            new TransactionsExport($transactions, $startDate, $endDate, $total, $dailyTotals, $standaloneInvoices),
            'laporan.xlsx'
        );
    }

    public function transactionReport(Request $request)
    {
        $startDateTime = $request->input('start_datetime');
        $endDateTime = $request->input('end_datetime');

        $query = Transaction::with(['device.playstation', 'user'])
            ->where('payment_status', 'paid');

        $standaloneQuery = $this->getStandaloneFnbInvoicesQuery();

        if ($startDateTime) {
            $start = Carbon::parse($startDateTime);
            $query->where('created_at', '>=', $start);
            $standaloneQuery->where('paid_at', '>=', $start);
        }

        if ($endDateTime) {
            $end = Carbon::parse($endDateTime);
            $query->where('updated_at', '<=', $end);
            $standaloneQuery->where('paid_at', '<=', $end);
        }

        $transactions = $query->orderBy('created_at', 'desc')->get();
        $standaloneInvoices = $standaloneQuery->orderBy('paid_at', 'desc')->get();

        return view('laporan.transaction-report', [
            'title' => 'Laporan Transaksi',
            'active' => 'report',
            'transactions' => $transactions,
            'standaloneInvoices' => $standaloneInvoices,
            'startDateTime' => $startDateTime,
            'endDateTime' => $endDateTime,
        ]);
    }
}
