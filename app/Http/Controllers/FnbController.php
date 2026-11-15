<?php

namespace App\Http\Controllers;

use App\Models\Fnb;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PriceGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use Barryvdh\DomPDF\Facade\Pdf as PDF;
use App\Exports\FnbSalesExport;


class FnbController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $fnbs = Fnb::with('priceGroup')->latest()->paginate(10);
        return view('fnb.index', [
            'title' => 'Kelola Barang',
            'active' => 'fnb',
            'fnbs' => $fnbs
        ]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $priceGroups = PriceGroup::all();
        return view('fnb.create', [
            'title' => 'Tambah Barang',
            'active' => 'fnb',
            'priceGroups' => $priceGroups
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
        $rules = [
            'nama' => 'required|string|max:255',
            'price_group_id' => 'required|exists:price_groups,id',
            'stok_type' => 'required|in:limit,unlimit',
            'deskripsi' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
        ];

        // If stok_type is limit, stok is required and must be >= 0
        if ($request->stok_type === 'limit') {
            $rules['stok'] = 'required|integer|min:0';
        }

        $validatedData = $request->validate($rules);

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('fnb', 'public');
            $validatedData['image'] = $path;
        }

        // Get price from price group
        $priceGroup = PriceGroup::findOrFail($validatedData['price_group_id']);
        $validatedData['harga_jual'] = $priceGroup->harga;
        
        // Handle stok based on type
        if ($validatedData['stok_type'] === 'unlimit') {
            $validatedData['stok'] = -1; // Use -1 as marker for unlimited
        }
        
        // Remove stok_type from validated data as it's not a database field
        unset($validatedData['stok_type']);

        Fnb::create($validatedData);

        return redirect('/fnb')->with('success', 'Barang berhasil ditambahkan.');
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
        $fnb = Fnb::findOrFail($id);
        $priceGroups = PriceGroup::all();
        
        // Determine stok_type based on stok value
        $stokType = ($fnb->stok == -1) ? 'unlimit' : 'limit';
        
        return view('fnb.edit', [
            'title' => 'Edit Barang',
            'active' => 'fnb',
            'fnb' => $fnb,
            'priceGroups' => $priceGroups,
            'stokType' => $stokType
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
        $fnb = Fnb::findOrFail($id);

        $rules = [
            'nama' => 'required|string|max:255',
            'price_group_id' => 'required|exists:price_groups,id',
            'stok_type' => 'required|in:limit,unlimit',
            'deskripsi' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
        ];

        // If stok_type is limit, stok is required and must be >= 0
        if ($request->stok_type === 'limit') {
            $rules['stok'] = 'required|integer|min:0';
        }

        $validatedData = $request->validate($rules);

        if ($request->hasFile('image')) {
            if ($fnb->image && Storage::disk('public')->exists($fnb->image)) {
                Storage::disk('public')->delete($fnb->image);
            }
            $path = $request->file('image')->store('fnb', 'public');
            $validatedData['image'] = $path;
        }

        // Get price from price group
        $priceGroup = PriceGroup::findOrFail($validatedData['price_group_id']);
        $validatedData['harga_jual'] = $priceGroup->harga;
        
        // Handle stok based on type
        if ($validatedData['stok_type'] === 'unlimit') {
            $validatedData['stok'] = -1; // Use -1 as marker for unlimited
        }
        
        // Keep existing harga_beli if it exists (for backward compatibility)
        if (!isset($validatedData['harga_beli'])) {
            $validatedData['harga_beli'] = $fnb->harga_beli;
        }
        
        // Remove stok_type from validated data as it's not a database field
        unset($validatedData['stok_type']);

        $fnb->update($validatedData);

        return redirect('/fnb')->with('success', 'Barang berhasil diupdate.');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $fnb = Fnb::findOrFail($id);
        if ($fnb->image && Storage::disk('public')->exists($fnb->image)) {
            Storage::disk('public')->delete($fnb->image);
        }
        $fnb->delete();
        return redirect('/fnb')->with('success', 'Barang berhasil dihapus.');
    }

    public function laporanPenjualan(Request $request)
    {
        [$period, $startDate, $endDate] = $this->resolveReportDateRange($request);

        $salesData = $this->getAggregatedSalesData($startDate, $endDate);

        $chartData = [
            'sales_over_time' => $this->getSalesOverTime($startDate, $endDate),
            'product_distribution' => $salesData->pluck('jumlah_terjual', 'nama')->toArray(),
            'profit_loss' => $salesData->pluck('laba_rugi', 'nama')->toArray(),
        ];

        return view('fnb.laporan-penjualan', [
            'title' => 'Laporan Penjualan FnB',
            'active' => 'fnb-laporan',
            'salesData' => $salesData,
            'chartData' => $chartData,
            'period' => $period,
            'startDate' => $startDate->format('Y-m-d'),
            'endDate' => $endDate->format('Y-m-d'),
        ]);
    }

    public function exportExcel(Request $request)
    {
        [, $startDate, $endDate] = $this->resolveReportDateRange($request);
        $salesData = $this->getAggregatedSalesData($startDate, $endDate);

        return Excel::download(new FnbSalesExport($salesData, $startDate, $endDate), 'laporan_penjualan_fnb.xlsx');
    }

    public function exportPdf(Request $request)
    {
        [, $startDate, $endDate] = $this->resolveReportDateRange($request);
        $salesData = $this->getAggregatedSalesData($startDate, $endDate);

        $pdf = PDF::loadView('fnb.laporan-pdf', compact('salesData', 'startDate', 'endDate'));
        return $pdf->download('laporan_penjualan_fnb.pdf');
    }

    /**
     * Resolve period and Carbon start/end bounds for F&B sales reports.
     *
     * @return array{0: string, 1: Carbon, 2: Carbon}
     */
    private function resolveReportDateRange(Request $request): array
    {
        $period = $request->get('period', 'this_month');
        $rawStart = $request->get('start_date');
        $rawEnd = $request->get('end_date');

        switch ($period) {
            case 'today':
                $startDate = Carbon::now()->startOfDay();
                $endDate = Carbon::now()->endOfDay();
                break;
            case 'this_year':
                $startDate = Carbon::now()->startOfYear();
                $endDate = Carbon::now()->endOfYear();
                break;
            case 'custom':
                $startDate = $rawStart ? Carbon::parse($rawStart)->startOfDay() : Carbon::now()->startOfMonth();
                $endDate = $rawEnd ? Carbon::parse($rawEnd)->endOfDay() : Carbon::now()->endOfMonth();
                break;
            case 'this_month':
            default:
                $startDate = Carbon::now()->startOfMonth();
                $endDate = Carbon::now()->endOfMonth();
                break;
        }

        return [$period, $startDate, $endDate];
    }

    /**
     * Build a deduplicated UNION ALL query combining snapshotted invoice_items (Source A)
     * and legacy paid transaction_fnbs without matching invoice items (Source B).
     */
    private function buildDeduplicatedSalesBaseQuery(Carbon $startDate, Carbon $endDate)
    {
        $sourceA = DB::table('invoice_items')
            ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->leftJoin('fnbs', 'invoice_items.fnb_id', '=', 'fnbs.id')
            ->where('invoice_items.item_type', InvoiceItem::ITEM_TYPE_FNB_ITEM)
            ->whereNotNull('invoices.paid_at')
            ->whereBetween('invoices.paid_at', [$startDate, $endDate])
            ->selectRaw('
                invoice_items.fnb_id as fnb_id,
                COALESCE(fnbs.nama, invoice_items.name) as nama,
                invoice_items.qty as qty,
                (invoice_items.unit_price_beli * invoice_items.qty) as total_modal,
                invoice_items.subtotal as total_penjualan,
                (invoice_items.subtotal - (invoice_items.unit_price_beli * invoice_items.qty)) as laba_rugi,
                DATE(invoices.paid_at) as sale_date
            ');

        $unitCostExpr = Schema::hasColumn('transaction_fnbs', 'harga_beli')
            ? 'COALESCE(NULLIF(transaction_fnbs.harga_beli, 0), fnbs.harga_beli, 0)'
            : 'COALESCE(fnbs.harga_beli, 0)';

        $sourceB = DB::table('transaction_fnbs')
            ->join('transactions', 'transaction_fnbs.transaction_id', '=', 'transactions.id_transaksi')
            ->join('fnbs', 'transaction_fnbs.fnb_id', '=', 'fnbs.id')
            ->where('transactions.payment_status', 'paid')
            ->whereRaw('COALESCE(transactions.paid_at, transactions.created_at) BETWEEN ? AND ?', [$startDate, $endDate])
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('invoice_items as ii')
                    ->whereColumn('ii.transaction_fnb_id', 'transaction_fnbs.id');
            })
            ->whereNotExists(function ($query) {
                // Paket upfront on stations with a Location links InvoiceItem via fnb_order_item_id
                // and leaves transaction_fnb_id NULL, so exclude items already invoiced on the game invoice.
                $query->select(DB::raw(1))
                    ->from('invoice_items as ii')
                    ->join('invoices as inv', 'ii.invoice_id', '=', 'inv.id')
                    ->whereColumn('inv.transaction_id', 'transaction_fnbs.transaction_id')
                    ->whereIn('inv.invoice_type', [Invoice::TYPE_GAME_AND_FNB, Invoice::TYPE_GAME_ONLY])
                    ->where('ii.item_type', InvoiceItem::ITEM_TYPE_FNB_ITEM)
                    ->whereColumn('ii.fnb_id', 'transaction_fnbs.fnb_id');
            })
            ->selectRaw("
                transaction_fnbs.fnb_id as fnb_id,
                fnbs.nama as nama,
                transaction_fnbs.qty as qty,
                ({$unitCostExpr} * transaction_fnbs.qty) as total_modal,
                (transaction_fnbs.harga_jual * transaction_fnbs.qty) as total_penjualan,
                ((transaction_fnbs.harga_jual - {$unitCostExpr}) * transaction_fnbs.qty) as laba_rugi,
                DATE(COALESCE(transactions.paid_at, transactions.created_at)) as sale_date
            ");

        return $sourceA->unionAll($sourceB);
    }

    /**
     * Aggregate deduplicated F&B sales rows grouped by product.
     */
    private function getAggregatedSalesData(Carbon $startDate, Carbon $endDate)
    {
        $unionQuery = $this->buildDeduplicatedSalesBaseQuery($startDate, $endDate);

        return DB::query()
            ->fromSub($unionQuery, 'sales_rows')
            ->select(
                'fnb_id as id',
                'nama',
                DB::raw('SUM(qty) as jumlah_terjual'),
                DB::raw('SUM(total_modal) as total_modal'),
                DB::raw('SUM(total_penjualan) as total_penjualan'),
                DB::raw('SUM(laba_rugi) as laba_rugi')
            )
            ->groupBy('fnb_id', 'nama')
            ->orderBy('nama')
            ->get();
    }

    /**
     * Aggregate deduplicated F&B quantities sold per day for chart display.
     */
    private function getSalesOverTime(Carbon $startDate, Carbon $endDate): array
    {
        $unionQuery = $this->buildDeduplicatedSalesBaseQuery($startDate, $endDate);

        return DB::query()
            ->fromSub($unionQuery, 'sales_rows')
            ->select(
                'sale_date as date',
                DB::raw('SUM(qty) as total_qty')
            )
            ->groupBy('sale_date')
            ->orderBy('sale_date')
            ->get()
            ->pluck('total_qty', 'date')
            ->map(fn ($qty) => (int) $qty)
            ->toArray();
    }
}
