<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="description" content="">
    <meta name="author" content="">

    <title>Laporan</title>

    <!-- Custom fonts for this template-->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link href="{{ asset('css/offline-fonts.css') }}" rel="stylesheet">

    <!-- Custom styles for this template-->
    <link href="css/sb-admin-2.min.css" rel="stylesheet">

</head>

<body style="font-size: 15px">
    <h3 class="text-center mb-3">
        Laporan Keuangan & Transaksi (Multi-Kasir)
    </h3>
    <p class="mb-2">Dari Tanggal: {{ $startDate }} Sampai: {{ $endDate }}</p>
    @if(isset($selectedCashier) && $selectedCashier)
        <p class="mb-2"><strong>Filter Kasir:</strong> {{ $selectedCashier->name }} ({{ $selectedCashier->role === 'kasir' ? ($selectedCashier->is_fnb_staff ? 'Kasir F&B' : 'Kasir Billing') : ucfirst($selectedCashier->role) }})</p>
    @endif
    @if(isset($selectedPlaystationIds) && !empty($selectedPlaystationIds))
        <p class="mb-2">
            <strong>Jenis PlayStation:</strong> 
            @php
                $playstationTypes = \App\Models\Playstation::whereIn('id', $selectedPlaystationIds)->get();
                $psNames = $playstationTypes->pluck('nama')->implode(', ');
            @endphp
            {{ $psNames }}
        </p>
    @else
        <p class="mb-2"><strong>Jenis PlayStation:</strong> Semua Jenis</p>
    @endif

    @if(isset($consolidatedSummary) && isset($consolidatedSummary['totals']))
    <h4 class="mt-4 mb-2">1. Ringkasan Konsolidasi Penerimaan Kasir</h4>
    <table class="table" style="font-size: 11px; margin-bottom: 20px;">
        <thead>
            <tr>
                <th>Kanal Penerimaan</th>
                <th>Total Neto</th>
                <th>Keterangan</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><strong>Kasir Billing Utama</strong></td>
                <td>Rp {{ number_format($consolidatedSummary['totals']['total_billing'], 0, ',', '.') }}</td>
                <td>Rental PS, Billiard, &amp; F&B via Billing</td>
            </tr>
            <tr>
                <td><strong>Kasir F&B (Cafe)</strong></td>
                <td>Rp {{ number_format($consolidatedSummary['totals']['total_fnb'], 0, ',', '.') }}</td>
                <td>Pembayaran langsung kasir F&B</td>
            </tr>
            <tr>
                <td><strong>Admin / Owner</strong></td>
                <td>Rp {{ number_format($consolidatedSummary['totals']['total_admin'], 0, ',', '.') }}</td>
                <td>Pembayaran langsung via Admin</td>
            </tr>
            @if(($consolidatedSummary['totals']['total_legacy_unattributed'] ?? 0) > 0)
            <tr>
                <td><strong>Belum Teratribusi (Legacy)</strong></td>
                <td>Rp {{ number_format($consolidatedSummary['totals']['total_legacy_unattributed'], 0, ',', '.') }}</td>
                <td>Transaksi lama tanpa invoice ({{ $consolidatedSummary['totals']['legacy_unattributed_count'] }} transaksi)</td>
            </tr>
            @endif
        </tbody>
        <tfoot>
            <tr style="font-weight: bold; background-color: #f2f2f2;">
                <td>Grand Total Penerimaan Konsolidasi</td>
                <td colspan="2">Rp {{ number_format($consolidatedSummary['totals']['grand_total'], 0, ',', '.') }}</td>
            </tr>
        </tfoot>
    </table>
    @endif

    @if(isset($categorySummary) && isset($categorySummary['categories']))
    <h4 class="mt-3 mb-2">2. Ringkasan Penjualan Berdasarkan Kategori</h4>
    <table class="table" style="font-size: 11px; margin-bottom: 20px;">
        <thead>
            <tr>
                <th>Kategori Usaha</th>
                <th>Penjualan Bruto</th>
                <th>Alokasi Diskon</th>
                <th>Penjualan Neto</th>
                <th>Qty Item / Sesi</th>
            </tr>
        </thead>
        <tbody>
            @foreach($categorySummary['categories'] as $catKey => $catData)
            <tr>
                <td>{{ $catData['label'] }}</td>
                <td>Rp {{ number_format($catData['gross'], 0, ',', '.') }}</td>
                <td>Rp {{ number_format($catData['discount'], 0, ',', '.') }}</td>
                <td><strong>Rp {{ number_format($catData['net'], 0, ',', '.') }}</strong></td>
                <td>{{ $catData['qty'] }}</td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr style="font-weight: bold; background-color: #f2f2f2;">
                <td>Total Penjualan Kategori (Neto)</td>
                <td>Rp {{ number_format($categorySummary['total_gross'], 0, ',', '.') }}</td>
                <td>Rp {{ number_format($categorySummary['total_discount'], 0, ',', '.') }}</td>
                <td colspan="2">Rp {{ number_format($categorySummary['total_net'], 0, ',', '.') }}</td>
            </tr>
        </tfoot>
    </table>
    @endif

    @if(isset($invoices) && $invoices->isNotEmpty())
    <h4 class="mt-3 mb-2">3. Rekapitulasi Invoice Pembayaran</h4>
    <table class="table" style="font-size: 10px; margin-bottom: 25px;">
        <thead>
            <tr>
                <th>No. Invoice</th>
                <th>Kasir Penerima</th>
                <th>Kanal</th>
                <th>Tipe Invoice</th>
                <th>Subtotal</th>
                <th>Diskon</th>
                <th>Total Neto</th>
                <th>Metode</th>
                <th>Waktu Bayar</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoices as $inv)
            <tr>
                <td>{{ $inv->invoice_number }}</td>
                <td>{{ $inv->cashierUser ? $inv->cashierUser->name : '-' }}</td>
                <td>
                    @if($inv->cashierUser)
                        {{ $inv->cashierUser->role === 'kasir' ? ($inv->cashierUser->is_fnb_staff ? 'F&B' : 'Billing') : ucfirst($inv->cashierUser->role) }}
                    @else
                        -
                    @endif
                </td>
                <td>{{ str_replace('_', ' ', $inv->invoice_type) }}</td>
                <td>Rp {{ number_format($inv->subtotal, 0, ',', '.') }}</td>
                <td>{{ $inv->discount > 0 ? 'Rp ' . number_format($inv->discount, 0, ',', '.') : '-' }}</td>
                <td><strong>Rp {{ number_format($inv->final_total, 0, ',', '.') }}</strong></td>
                <td>{{ ucfirst(str_replace('_', ' ', $inv->payment_method ?: '-')) }}</td>
                <td>{{ $inv->paid_at ? $inv->paid_at->format('Y-m-d H:i') : '-' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif
    
    @if(isset($dailyTotals) && $dailyTotals->count() > 0)
    <h4 class="mt-4 mb-3">Statistik Pendapatan Harian</h4>
    <table class="table" style="margin-bottom: 30px;">
        <thead>
            <tr>
                <th>Tanggal</th>
                <th>Total Pendapatan</th>
            </tr>
        </thead>
        <tbody>
            @foreach($dailyTotals as $date => $amount)
                <tr>
                    <td>{{ $date }}</td>
                    <td>Rp {{ number_format($amount, 0, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    <h4 class="mt-4 mb-3">Detail Transaksi</h4>
    <table class="table" style="font-size: 10px;">
        <thead>
            <tr>
                <th scope="col">ID Transaksi</th>
                <th scope="col">Nama</th>
                <th scope="col">Data Perangkat</th>
                <th scope="col">Tipe</th>
                <th scope="col">Jam Main</th>
                <th scope="col">Waktu Mulai</th>
                <th scope="col">Waktu Selesai</th>
                <th scope="col">FnB</th>
                <th scope="col">Total</th>
                <th scope="col">Diskon</th>
                <th scope="col">Total Setelah Diskon</th>
                <th scope="col">Tanggal</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($transactions as $transaksi)
                <tr>
                    <td>{{ $transaksi->id_transaksi }}</td>
                    <td>{{ $transaksi->nama }}</td>
                    <td>
                        @if($transaksi->device && $transaksi->device->playstation)
                            {{ $transaksi->device->nama }} - {{ $transaksi->device->playstation->nama }}
                        @else
                            -
                        @endif
                    </td>
                    <td>
                        @if($transaksi->tipe_transaksi === 'prepaid')
                            Timer
                        @elseif($transaksi->tipe_transaksi === 'custom_package')
                            Custom Paket
                        @else
                            Lost Time
                        @endif
                    </td>
                    <td>
                        {{ $transaksi->display_duration }}
                    </td>
                    <td>{{ $transaksi->waktu_mulai }}</td>
                    <td>{{ $transaksi->waktu_Selesai ?: '-' }}</td>
                    <td>
                        @if($transaksi->transactionFnbs->isEmpty())
                            -
                        @else
                            @foreach($transaksi->transactionFnbs as $fnbItem)
                                {{ $fnbItem->fnb->nama }} ({{ $fnbItem->qty }} x Rp {{ number_format($fnbItem->harga_jual, 0, ',', '.') }})@if(!$loop->last), @endif
                            @endforeach
                        @endif
                    </td>
                    <td>{{ 'Rp ' . number_format($transaksi->total, 0, ',', '.') }}</td>
                    <td>
                        @if($transaksi->diskon && $transaksi->diskon > 0)
                            {{ $transaksi->diskon }}%
                        @else
                            -
                        @endif
                    </td>
                    <td>
                        @if($transaksi->diskon && $transaksi->diskon > 0)
                            {{ 'Rp ' . number_format($transaksi->total - ($transaksi->total * $transaksi->diskon / 100), 0, ',', '.') }}
                        @else
                            {{ 'Rp ' . number_format($transaksi->total, 0, ',', '.') }}
                        @endif
                    </td>
                    <td>{{ $transaksi->created_at }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="8"><strong>Total:</strong></td>
                <td colspan="4"><strong>{{ 'Rp ' . number_format($total, 0, ',', '.') }}</strong></td>
            </tr>
        </tfoot>
    </table>

    @if(isset($standaloneInvoices) && $standaloneInvoices->isNotEmpty())
    <h4 class="mt-4 mb-3">Detail Penjualan FnB Standalone</h4>
    <table class="table" style="font-size: 10px;">
        <thead>
            <tr>
                <th scope="col">No. Invoice</th>
                <th scope="col">Stasiun / Sumber</th>
                <th scope="col">Kasir</th>
                <th scope="col">Item FnB</th>
                <th scope="col">Subtotal</th>
                <th scope="col">Diskon</th>
                <th scope="col">Total Bayar</th>
                <th scope="col">Tanggal Bayar</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($standaloneInvoices as $inv)
                <tr>
                    <td>{{ $inv->invoice_number }}</td>
                    <td>
                        @if($inv->transaction && $inv->transaction->device)
                            {{ $inv->transaction->device->nama }}
                            @if($inv->transaction->device->playstation)
                                - {{ $inv->transaction->device->playstation->nama }}
                            @endif
                        @else
                            Walk-in / Non-Playing
                        @endif
                    </td>
                    <td>{{ $inv->cashierUser ? $inv->cashierUser->name : '-' }}</td>
                    <td>
                        @foreach($inv->items as $item)
                            {{ $item->name }} ({{ $item->qty }} x Rp {{ number_format($item->unit_price_jual, 0, ',', '.') }})@if(!$loop->last), @endif
                        @endforeach
                    </td>
                    <td>{{ 'Rp ' . number_format($inv->subtotal, 0, ',', '.') }}</td>
                    <td>{{ $inv->discount > 0 ? 'Rp ' . number_format($inv->discount, 0, ',', '.') : '-' }}</td>
                    <td>{{ 'Rp ' . number_format($inv->final_total, 0, ',', '.') }}</td>
                    <td>{{ $inv->paid_at }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    <!-- Bootstrap core JavaScript-->
    <script src="vendor/jquery/jquery.min.js"></script>
    <script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

    <!-- Core plugin JavaScript-->
    <script src="vendor/jquery-easing/jquery.easing.min.js"></script>

    <!-- Custom scripts for all pages-->
    <script src="js/sb-admin-2.min.js"></script>

</body>

</html>
