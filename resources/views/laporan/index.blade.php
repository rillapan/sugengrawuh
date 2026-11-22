@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <!-- Page Heading -->
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">{{ $title }}</h1>
        <div>
            <a href="{{ route('transaction.report') }}" class="btn btn-info btn-sm">
                <i class="fas fa-list-alt"></i> Laporan Transaksi Detail
            </a>
        </div>
    </div>

    <!-- Content Row -->
    <div class="row">
        <!-- Daily Income Card -->
        <div class="col-xl-4 col-md-6 mb-4">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                Pendapatan Harian (Hari Ini)
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                Rp {{ number_format($dailyIncome, 0, ',', '.') }}
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-calendar-day fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Monthly Income Card -->
        <div class="col-xl-4 col-md-6 mb-4">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                Pendapatan Bulanan (Bulan Ini)
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                Rp {{ number_format($monthlyIncome, 0, ',', '.') }}
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-calendar-alt fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Yearly Income Card -->
        <div class="col-xl-4 col-md-6 mb-4">
            <div class="card border-left-info shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                Pendapatan Tahunan (Tahun Ini)
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                Rp {{ number_format($yearlyIncome, 0, ',', '.') }}
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-calendar fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Custom Date Range Form -->
    <div class="row">
        <div class="col-lg-12">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Generate Laporan Berdasarkan Tanggal</h6>
                </div>
                <div class="card-body">
                    <form method="GET" action="{{ route('report') }}" id="reportForm">
                        @csrf
                        <div class="form-group row">
                            <label for="period" class="col-sm-2 col-form-label">Periode</label>
                            <div class="col-sm-4">
                                <select class="form-control" id="period" name="period">
                                    <option value="" {{ !request('period') ? 'selected' : '' }}>Pilih Periode</option>
                                    <option value="today" {{ request('period') == 'today' ? 'selected' : '' }}>Hari Ini</option>
                                    <option value="this_week" {{ request('period') == 'this_week' ? 'selected' : '' }}>Minggu Ini</option>
                                    <option value="this_month" {{ request('period') == 'this_month' ? 'selected' : '' }}>Bulan Ini</option>
                                    <option value="this_year" {{ request('period') == 'this_year' ? 'selected' : '' }}>Tahun Ini</option>
                                    <option value="custom" {{ request('period') == 'custom' ? 'selected' : '' }}>Custom Range</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group row" id="customDateRange" style="{{ request('period') == 'custom' ? '' : 'display: none;' }}">
                            <label for="tanggal_awal" class="col-sm-2 col-form-label">Tanggal Awal</label>
                            <div class="col-sm-4">
                                <input type="date" class="form-control" id="tanggal_awal" name="tanggal_awal" value="{{ $startDate }}">
                            </div>
                            <label for="tanggal_akhir" class="col-sm-2 col-form-label">Tanggal Akhir</label>
                            <div class="col-sm-4">
                                <input type="date" class="form-control" id="tanggal_akhir" name="tanggal_akhir" value="{{ $endDate }}">
                            </div>
                        </div>

                        <div class="form-group row">
                            <label for="cashier_user_id" class="col-sm-2 col-form-label">Kasir Penerima</label>
                            <div class="col-sm-4">
                                <select class="form-control" id="cashier_user_id" name="cashier_user_id">
                                    <option value="">Semua Kasir</option>
                                    @if(isset($cashierList))
                                        @foreach($cashierList as $c)
                                            <option value="{{ $c->id }}" {{ (isset($selectedCashierUserId) && $selectedCashierUserId == $c->id) ? 'selected' : '' }}>
                                                {{ $c->name }} ({{ $c->role === 'kasir' ? ($c->is_fnb_staff ? 'Kasir F&B' : 'Kasir Billing') : ucfirst($c->role) }})
                                            </option>
                                        @endforeach
                                    @endif
                                </select>
                            </div>
                            <label for="payment_method" class="col-sm-2 col-form-label">Metode Bayar</label>
                            <div class="col-sm-4">
                                <select class="form-control" id="payment_method" name="payment_method">
                                    <option value="">Semua Metode</option>
                                    <option value="tunai" {{ (isset($paymentMethod) && $paymentMethod === 'tunai') ? 'selected' : '' }}>Tunai</option>
                                    <option value="e-wallet" {{ (isset($paymentMethod) && $paymentMethod === 'e-wallet') ? 'selected' : '' }}>E-Wallet (QRIS)</option>
                                    <option value="transfer_bank" {{ (isset($paymentMethod) && $paymentMethod === 'transfer_bank') ? 'selected' : '' }}>Transfer Bank</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group row">
                            <label class="col-sm-2 col-form-label">Jenis PlayStation</label>
                            <div class="col-sm-10">
                                <div class="mb-2">
                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAllPlaystations()">Pilih Semua</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="resetPlaystations()">Reset</button>
                                </div>
                                <div class="row">
                                    @foreach($playstationTypes as $playstation)
                                        <div class="col-sm-3 mb-2">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="playstation_types[]" value="{{ $playstation->id }}" id="ps_{{ $playstation->id }}" {{ in_array($playstation->id, $selectedPlaystationIds) ? 'checked' : '' }}>
                                                <label class="form-check-label" for="ps_{{ $playstation->id }}">
                                                    {{ $playstation->nama }}
                                                </label>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                                <small class="form-text text-muted">Pilih jenis PlayStation yang ingin ditampilkan dalam laporan.</small>
                            </div>
                        </div>

                        <div class="form-group row">
                            <div class="col-sm-12">
                                <button type="submit" class="btn btn-info mr-2">Tampilkan Laporan</button>
                                <button type="submit" class="btn btn-primary mr-2" formaction="{{ url('/generate-pdf') }}">Generate PDF</button>
                                <button type="submit" class="btn btn-success" formaction="{{ url('/generate-excel') }}">Generate Excel</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    @if($startDate && $endDate)
    <!-- Period Income and Chart -->
    <div class="row">
        <div class="col-xl-12 col-md-12 mb-4">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                Total Pendapatan Periode ({{ $startDate }} - {{ $endDate }})
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                Rp {{ number_format($periodIncome, 0, ',', '.') }}
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-chart-line fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-12">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Grafik Statistik Pendapatan</h6>
                </div>
                <div class="card-body">
                    <div class="chart-area">
                        <canvas id="incomeChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if(isset($consolidatedSummary) && isset($categorySummary))
    <!-- 3 Perspectives Section: Multi-Cashier Financial Report -->
    <div class="row">
        <!-- Perspektif 1: Penerimaan Kasir -->
        <div class="col-xl-6 col-lg-12 mb-4">
            <div class="card shadow h-100">
                <div class="card-header py-3 d-flex justify-content-between align-items-center bg-primary text-white">
                    <h6 class="m-0 font-weight-bold"><i class="fas fa-cash-register mr-1"></i> Perspektif 1: Penerimaan Kasir</h6>
                    <span class="badge badge-light">Penerima Kas Nyata</span>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th>Kanal / Kasir</th>
                                    <th class="text-center">Invoice</th>
                                    <th class="text-right">Total Neto</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>
                                        <i class="fas fa-desktop text-primary mr-1"></i> <strong>Kasir Billing Utama</strong>
                                        <br><small class="text-muted">Rental PS, Billiard, &amp; F&B via Billing</small>
                                    </td>
                                    <td class="text-center align-middle">
                                        {{ array_sum(array_column($consolidatedSummary['billing_cashiers'], 'invoice_count')) }}
                                    </td>
                                    <td class="text-right align-middle font-weight-bold text-primary">
                                        Rp {{ number_format($consolidatedSummary['totals']['total_billing'], 0, ',', '.') }}
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <i class="fas fa-utensils text-success mr-1"></i> <strong>Kasir F&B (Cafe)</strong>
                                        <br><small class="text-muted">Pembayaran langsung kasir F&B</small>
                                    </td>
                                    <td class="text-center align-middle">
                                        {{ array_sum(array_column($consolidatedSummary['fnb_cashiers'], 'invoice_count')) }}
                                    </td>
                                    <td class="text-right align-middle font-weight-bold text-success">
                                        Rp {{ number_format($consolidatedSummary['totals']['total_fnb'], 0, ',', '.') }}
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <i class="fas fa-user-shield text-info mr-1"></i> <strong>Admin / Owner</strong>
                                        <br><small class="text-muted">Pembayaran langsung via Admin</small>
                                    </td>
                                    <td class="text-center align-middle">
                                        {{ array_sum(array_column($consolidatedSummary['admin_cashiers'], 'invoice_count')) }}
                                    </td>
                                    <td class="text-right align-middle font-weight-bold text-info">
                                        Rp {{ number_format($consolidatedSummary['totals']['total_admin'], 0, ',', '.') }}
                                    </td>
                                </tr>
                                @if(($consolidatedSummary['totals']['total_legacy_unattributed'] ?? 0) > 0)
                                <tr class="table-warning">
                                    <td>
                                        <i class="fas fa-history text-warning mr-1"></i> <strong>Belum Teratribusi (Legacy)</strong>
                                        <br><small class="text-muted">Transaksi berbayar lama tanpa invoice</small>
                                    </td>
                                    <td class="text-center align-middle">
                                        {{ $consolidatedSummary['totals']['legacy_unattributed_count'] }}
                                    </td>
                                    <td class="text-right align-middle font-weight-bold text-dark">
                                        Rp {{ number_format($consolidatedSummary['totals']['total_legacy_unattributed'], 0, ',', '.') }}
                                    </td>
                                </tr>
                                @endif
                            </tbody>
                            <tfoot class="bg-light">
                                <tr class="font-weight-bold">
                                    <td>Total Penerimaan Kasir</td>
                                    <td class="text-center">{{ array_sum(array_column($consolidatedSummary['billing_cashiers'], 'invoice_count')) + array_sum(array_column($consolidatedSummary['fnb_cashiers'], 'invoice_count')) + array_sum(array_column($consolidatedSummary['admin_cashiers'], 'invoice_count')) + ($consolidatedSummary['totals']['legacy_unattributed_count'] ?? 0) }}</td>
                                    <td class="text-right text-success">Rp {{ number_format($consolidatedSummary['totals']['grand_total'], 0, ',', '.') }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Perspektif 2: Penjualan Kategori -->
        <div class="col-xl-6 col-lg-12 mb-4">
            <div class="card shadow h-100">
                <div class="card-header py-3 d-flex justify-content-between align-items-center bg-success text-white">
                    <h6 class="m-0 font-weight-bold"><i class="fas fa-tags mr-1"></i> Perspektif 2: Penjualan Kategori</h6>
                    <span class="badge badge-light">Alokasi Diskon Proporsional</span>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th>Kategori Usaha</th>
                                    <th class="text-right">Bruto</th>
                                    <th class="text-right">Diskon</th>
                                    <th class="text-right">Neto</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($categorySummary['categories'] as $catKey => $catData)
                                <tr>
                                    <td>
                                        <strong>{{ $catData['label'] }}</strong>
                                        <br><small class="text-muted">{{ $catData['qty'] }} item/sesi terjual</small>
                                    </td>
                                    <td class="text-right align-middle">Rp {{ number_format($catData['gross'], 0, ',', '.') }}</td>
                                    <td class="text-right align-middle text-danger">{{ $catData['discount'] > 0 ? 'Rp ' . number_format($catData['discount'], 0, ',', '.') : '-' }}</td>
                                    <td class="text-right align-middle font-weight-bold text-dark">Rp {{ number_format($catData['net'], 0, ',', '.') }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="bg-light">
                                <tr class="font-weight-bold">
                                    <td>Total Penjualan Kategori</td>
                                    <td class="text-right">Rp {{ number_format($categorySummary['total_gross'], 0, ',', '.') }}</td>
                                    <td class="text-right text-danger">{{ $categorySummary['total_discount'] > 0 ? 'Rp ' . number_format($categorySummary['total_discount'], 0, ',', '.') : '-' }}</td>
                                    <td class="text-right text-success">Rp {{ number_format($categorySummary['total_net'], 0, ',', '.') }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    @if($categorySummary['is_balanced'])
                        <div class="alert alert-success py-1 px-2 mt-2 mb-0 small">
                            <i class="fas fa-check-circle"></i> <strong>100% Cocok:</strong> Total penjualan kategori tepat seimbang dengan total penerimaan kas neto.
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- Perspektif 3: Konsolidasi Usaha (Owner/Admin View) -->
    <div class="row">
        <div class="col-lg-12 mb-4">
            <div class="card shadow border-left-info">
                <div class="card-header py-3 bg-light d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-info"><i class="fas fa-balance-scale mr-1"></i> Perspektif 3: Konsolidasi Usaha &amp; Rekonsiliasi (Owner/Admin)</h6>
                    @if($consolidatedSummary['is_reconciled'])
                        <span class="badge badge-success px-2 py-1"><i class="fas fa-check"></i> Rekonsiliasi Terverifikasi</span>
                    @else
                        <span class="badge badge-warning px-2 py-1"><i class="fas fa-exclamation-triangle"></i> Selisih Ditemukan</span>
                    @endif
                </div>
                <div class="card-body">
                    <div class="row text-center mb-3">
                        <div class="col-md-3 mb-2">
                            <div class="border rounded p-2">
                                <span class="text-muted small">Billing Kasir</span>
                                <div class="h6 font-weight-bold text-primary mb-0">Rp {{ number_format($consolidatedSummary['totals']['total_billing'], 0, ',', '.') }}</div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-2">
                            <div class="border rounded p-2">
                                <span class="text-muted small">F&amp;B Kasir</span>
                                <div class="h6 font-weight-bold text-success mb-0">Rp {{ number_format($consolidatedSummary['totals']['total_fnb'], 0, ',', '.') }}</div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-2">
                            <div class="border rounded p-2">
                                <span class="text-muted small">Admin Langsung</span>
                                <div class="h6 font-weight-bold text-info mb-0">Rp {{ number_format($consolidatedSummary['totals']['total_admin'], 0, ',', '.') }}</div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-2">
                            <div class="border rounded p-2 bg-light">
                                <span class="text-muted small font-weight-bold">Grand Total Konsolidasi</span>
                                <div class="h5 font-weight-bold text-dark mb-0">Rp {{ number_format($consolidatedSummary['totals']['grand_total'], 0, ',', '.') }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="small text-muted text-center">
                        <i class="fas fa-shield-alt text-success"></i> <strong>Integritas Finansial:</strong> Formula <code>Total Billing + Total F&B + Total Admin + Belum Teratribusi = Grand Total Konsolidasi</code> terpenuhi secara presisi tanpa double-counting.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Detail Rekapitulasi Invoice Pembayaran -->
    @if(isset($invoices) && $invoices->isNotEmpty())
    <div class="row">
        <div class="col-lg-12">
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-file-invoice-dollar mr-1"></i> Rincian Invoice Pembayaran Lunas</h6>
                    <span class="badge badge-primary">{{ $invoices->count() }} Invoice</span>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover table-sm" id="dataTableInvoices" width="100%" cellspacing="0">
                            <thead class="thead-light">
                                <tr>
                                    <th>No. Invoice</th>
                                    <th>Kasir Penerima</th>
                                    <th>Kanal</th>
                                    <th>Tipe Invoice</th>
                                    <th class="text-right">Subtotal</th>
                                    <th class="text-right">Diskon</th>
                                    <th class="text-right">Total Neto</th>
                                    <th class="text-center">Metode</th>
                                    <th class="text-center">Waktu Bayar</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($invoices as $inv)
                                <tr>
                                    <td><strong>{{ $inv->invoice_number }}</strong></td>
                                    <td>{{ $inv->cashierUser ? $inv->cashierUser->name : '-' }}</td>
                                    <td>
                                        @if($inv->cashierUser)
                                            <span class="badge {{ $inv->cashierUser->role === 'kasir' ? ($inv->cashierUser->is_fnb_staff ? 'badge-success' : 'badge-primary') : 'badge-info' }}">
                                                {{ $inv->cashierUser->role === 'kasir' ? ($inv->cashierUser->is_fnb_staff ? 'Kasir F&B' : 'Kasir Billing') : ucfirst($inv->cashierUser->role) }}
                                            </span>
                                        @else
                                            <span class="badge badge-secondary">Tidak Teratribusi</span>
                                        @endif
                                    </td>
                                    <td>{{ str_replace('_', ' ', $inv->invoice_type) }}</td>
                                    <td class="text-right">Rp {{ number_format($inv->subtotal, 0, ',', '.') }}</td>
                                    <td class="text-right text-danger">{{ $inv->discount > 0 ? 'Rp ' . number_format($inv->discount, 0, ',', '.') : '-' }}</td>
                                    <td class="text-right font-weight-bold text-dark">Rp {{ number_format($inv->final_total, 0, ',', '.') }}</td>
                                    <td class="text-center">{{ ucfirst(str_replace('_', ' ', $inv->payment_method ?: '-')) }}</td>
                                    <td class="text-center">{{ $inv->paid_at ? $inv->paid_at->format('d/m/Y H:i') : '-' }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif
    @endif
    @endif
</div>
@endsection

@section('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Toggle Custom Date Range based on Period selection
        const periodSelect = document.getElementById('period');
        const customDateRange = document.getElementById('customDateRange');
        const startDateInput = document.getElementById('tanggal_awal');
        const endDateInput = document.getElementById('tanggal_akhir');

        if (periodSelect) {
            function toggleCustomDate() {
                if (periodSelect.value === 'custom') {
                    customDateRange.style.display = 'flex';
                    startDateInput.required = true;
                    endDateInput.required = true;
                } else {
                    customDateRange.style.display = 'none';
                    startDateInput.required = false;
                    endDateInput.required = false;
                }
            }

            periodSelect.addEventListener('change', toggleCustomDate);
            toggleCustomDate(); // Run on load
        }
    });

    function selectAllPlaystations() {
        const checkboxes = document.querySelectorAll('input[name="playstation_types[]"]');
        checkboxes.forEach(checkbox => {
            checkbox.checked = true;
        });
    }

    function resetPlaystations() {
        const checkboxes = document.querySelectorAll('input[name="playstation_types[]"]');
        checkboxes.forEach(checkbox => {
            checkbox.checked = false;
        });
    }
</script>

@if($startDate && $endDate)
<script>
    // Set new default font family and font color to mimic Bootstrap's default styling
    Chart.defaults.global.defaultFontFamily = 'Nunito', '-apple-system,system-ui,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif';
    Chart.defaults.global.defaultFontColor = '#858796';

    function number_format(number, decimals, dec_point, thousands_sep) {
        // *     example: number_format(1234.56, 2, ',', ' ');
        // *     return: '1 234,56'
        number = (number + '').replace(',', '').replace(' ', '');
        var n = !isFinite(+number) ? 0 : +number,
            prec = !isFinite(+decimals) ? 0 : Math.abs(decimals),
            sep = (typeof thousands_sep === 'undefined') ? ',' : thousands_sep,
            dec = (typeof dec_point === 'undefined') ? '.' : dec_point,
            s = '',
            toFixedFix = function (n, prec) {
                var k = Math.pow(10, prec);
                return '' + Math.round(n * k) / k;
            };
        // Fix for IE parseFloat(0.55).toFixed(0) = 0;
        s = (prec ? toFixedFix(n, prec) : '' + Math.round(n)).split('.');
        if (s[0].length > 3) {
            s[0] = s[0].replace(/\B(?=(?:\d{3})+(?!\d))/g, sep);
        }
        if ((s[1] || '').length < prec) {
            s[1] = s[1] || '';
            s[1] += new Array(prec - s[1].length + 1).join('0');
        }
        return s.join(dec);
    }

    // Area Chart Example
    var ctx = document.getElementById("incomeChart");
    
    @if(!empty($chartDatasets))
        // Multiple datasets for different PlayStation types
        var myLineChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: @json($chartLabels),
                datasets: @json($chartDatasets),
            },
            options: {
                maintainAspectRatio: false,
                layout: {
                    padding: {
                        left: 10,
                        right: 25,
                        top: 25,
                        bottom: 0
                    }
                },
                scales: {
                    xAxes: [{
                        time: {
                            unit: 'date'
                        },
                        gridLines: {
                            display: false,
                            drawBorder: false
                        },
                        ticks: {
                            maxTicksLimit: 7
                        }
                    }],
                    yAxes: [{
                        ticks: {
                            maxTicksLimit: 5,
                            padding: 10,
                            // Include a dollar sign in the ticks
                            callback: function(value, index, values) {
                                return 'Rp ' + number_format(value);
                            }
                        },
                        gridLines: {
                            color: "rgb(234, 236, 244)",
                            zeroLineColor: "rgb(234, 236, 244)",
                            drawBorder: false,
                            borderDash: [2],
                            zeroLineBorderDash: [2]
                        }
                    }],
                },
                legend: {
                    display: true,
                    position: 'top'
                },
                tooltips: {
                    backgroundColor: "rgb(255,255,255)",
                    bodyFontColor: "#858796",
                    titleMarginBottom: 10,
                    titleFontColor: '#6e707e',
                    titleFontSize: 14,
                    borderColor: '#dddfeb',
                    borderWidth: 1,
                    xPadding: 15,
                    yPadding: 15,
                    displayColors: true,
                    intersect: false,
                    mode: 'index',
                    caretPadding: 10,
                    callbacks: {
                        label: function(tooltipItem, chart) {
                            var datasetLabel = chart.datasets[tooltipItem.datasetIndex].label || '';
                            return datasetLabel + ': Rp ' + number_format(tooltipItem.yLabel);
                        }
                    }
                }
            }
        });
    @else
        // Single dataset for all types
        var myLineChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: @json($chartLabels),
                datasets: [{
                    label: "Pendapatan",
                    lineTension: 0.3,
                    backgroundColor: "rgba(78, 115, 223, 0.05)",
                    borderColor: "rgba(78, 115, 223, 1)",
                    pointRadius: 3,
                    pointBackgroundColor: "rgba(78, 115, 223, 1)",
                    pointBorderColor: "rgba(78, 115, 223, 1)",
                    pointHoverRadius: 3,
                    pointHoverBackgroundColor: "rgba(78, 115, 223, 1)",
                    pointHoverBorderColor: "rgba(78, 115, 223, 1)",
                    pointHitRadius: 10,
                    pointBorderWidth: 2,
                    data: @json($chartData),
                }],
            },
            options: {
                maintainAspectRatio: false,
                layout: {
                    padding: {
                        left: 10,
                        right: 25,
                        top: 25,
                        bottom: 0
                    }
                },
                scales: {
                    xAxes: [{
                        time: {
                            unit: 'date'
                        },
                        gridLines: {
                            display: false,
                            drawBorder: false
                        },
                        ticks: {
                            maxTicksLimit: 7
                        }
                    }],
                    yAxes: [{
                        ticks: {
                            maxTicksLimit: 5,
                            padding: 10,
                            // Include a dollar sign in the ticks
                            callback: function(value, index, values) {
                                return 'Rp ' + number_format(value);
                            }
                        },
                        gridLines: {
                            color: "rgb(234, 236, 244)",
                            zeroLineColor: "rgb(234, 236, 244)",
                            drawBorder: false,
                            borderDash: [2],
                            zeroLineBorderDash: [2]
                        }
                    }],
                },
                legend: {
                    display: false
                },
                tooltips: {
                    backgroundColor: "rgb(255,255,255)",
                    bodyFontColor: "#858796",
                    titleMarginBottom: 10,
                    titleFontColor: '#6e707e',
                    titleFontSize: 14,
                    borderColor: '#dddfeb',
                    borderWidth: 1,
                    xPadding: 15,
                    yPadding: 15,
                    displayColors: false,
                    intersect: false,
                    mode: 'index',
                    caretPadding: 10,
                    callbacks: {
                        label: function(tooltipItem, chart) {
                            var datasetLabel = chart.datasets[tooltipItem.datasetIndex].label || '';
                            return datasetLabel + ': Rp ' + number_format(tooltipItem.yLabel);
                        }
                    }
                }
            }
        });
    @endif
</script>
@endif
@endsection
