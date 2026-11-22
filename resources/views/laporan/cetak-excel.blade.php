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
    <table>
        <thead></thead>
        <tbody>
            <tr>
                <td colspan="8" style="text-align: center; font-size:20px; font-weight: bold;">
                    <h3 class="text-center mb-3">
                        Laporan Keuangan dan Transaksi (Multi-Kasir)
                    </h3>
                </td>
            </tr>
            <tr>
                <td colspan="8">
                    <p>Dari Tanggal: {{ $startDate }} Sampai: {{ $endDate }}</p>
                    @if(isset($selectedCashier) && $selectedCashier)
                        <p><strong>Filter Kasir:</strong> {{ $selectedCashier->name }} ({{ $selectedCashier->role === 'kasir' ? ($selectedCashier->is_fnb_staff ? 'Kasir FnB' : 'Kasir Billing') : ucfirst($selectedCashier->role) }})</p>
                    @endif
                    @if(isset($selectedPlaystationIds) && !empty($selectedPlaystationIds))
                        <p>
                            <strong>Jenis PlayStation:</strong> 
                            @php
                                $playstationTypes = \App\Models\Playstation::whereIn('id', $selectedPlaystationIds)->get();
                                $psNames = $playstationTypes->pluck('nama')->implode(', ');
                            @endphp
                            {{ $psNames }}
                        </p>
                    @else
                        <p><strong>Jenis PlayStation:</strong> Semua Jenis</p>
                    @endif
                </td>
            </tr>
        </tbody>
    </table>

    @if(isset($consolidatedSummary) && isset($consolidatedSummary['totals']))
    <table>
        <thead></thead>
        <tbody>
            <tr><td colspan="8">&nbsp;</td></tr>
            <tr>
                <td colspan="8" style="font-weight: bold; font-size: 16px;">1. Ringkasan Konsolidasi Penerimaan Kasir</td>
            </tr>
        </tbody>
    </table>
    <table class="table">
        <thead>
            <tr>
                <th style="font-weight:bold">Kanal Penerimaan</th>
                <th style="font-weight:bold">Total Neto</th>
                <th colspan="2" style="font-weight:bold">Keterangan</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Kasir Billing Utama</td>
                <td>Rp {{ number_format($consolidatedSummary['totals']['total_billing'], 0, ',', '.') }}</td>
                <td colspan="2">Rental PS, Billiard, dan FnB via Billing</td>
            </tr>
            <tr>
                <td>Kasir FnB (Cafe)</td>
                <td>Rp {{ number_format($consolidatedSummary['totals']['total_fnb'], 0, ',', '.') }}</td>
                <td colspan="2">Pembayaran langsung kasir FnB</td>
            </tr>
            <tr>
                <td>Admin / Owner</td>
                <td>Rp {{ number_format($consolidatedSummary['totals']['total_admin'], 0, ',', '.') }}</td>
                <td colspan="2">Pembayaran langsung via Admin</td>
            </tr>
            @if(($consolidatedSummary['totals']['total_legacy_unattributed'] ?? 0) > 0)
            <tr>
                <td>Belum Teratribusi (Legacy)</td>
                <td>Rp {{ number_format($consolidatedSummary['totals']['total_legacy_unattributed'], 0, ',', '.') }}</td>
                <td colspan="2">Transaksi lama tanpa invoice ({{ $consolidatedSummary['totals']['legacy_unattributed_count'] }} transaksi)</td>
            </tr>
            @endif
            <tr style="font-weight: bold;">
                <td>Grand Total Penerimaan Konsolidasi</td>
                <td colspan="3">Rp {{ number_format($consolidatedSummary['totals']['grand_total'], 0, ',', '.') }}</td>
            </tr>
        </tbody>
    </table>
    @endif

    @if(isset($categorySummary) && isset($categorySummary['categories']))
    <table>
        <thead></thead>
        <tbody>
            <tr><td colspan="8">&nbsp;</td></tr>
            <tr>
                <td colspan="8" style="font-weight: bold; font-size: 16px;">2. Ringkasan Penjualan Berdasarkan Kategori</td>
            </tr>
        </tbody>
    </table>
    <table class="table">
        <thead>
            <tr>
                <th style="font-weight:bold">Kategori Usaha</th>
                <th style="font-weight:bold">Penjualan Bruto</th>
                <th style="font-weight:bold">Alokasi Diskon</th>
                <th style="font-weight:bold">Penjualan Neto</th>
                <th style="font-weight:bold">Qty Item / Sesi</th>
            </tr>
        </thead>
        <tbody>
            @foreach($categorySummary['categories'] as $catKey => $catData)
            <tr>
                <td>{{ $catData['label'] }}</td>
                <td>Rp {{ number_format($catData['gross'], 0, ',', '.') }}</td>
                <td>Rp {{ number_format($catData['discount'], 0, ',', '.') }}</td>
                <td>Rp {{ number_format($catData['net'], 0, ',', '.') }}</td>
                <td>{{ $catData['qty'] }}</td>
            </tr>
            @endforeach
            <tr style="font-weight: bold;">
                <td>Total Penjualan Kategori (Neto)</td>
                <td>Rp {{ number_format($categorySummary['total_gross'], 0, ',', '.') }}</td>
                <td>Rp {{ number_format($categorySummary['total_discount'], 0, ',', '.') }}</td>
                <td colspan="2">Rp {{ number_format($categorySummary['total_net'], 0, ',', '.') }}</td>
            </tr>
        </tbody>
    </table>
    @endif

    @if(isset($invoices) && $invoices->isNotEmpty())
    <table>
        <thead></thead>
        <tbody>
            <tr><td colspan="8">&nbsp;</td></tr>
            <tr>
                <td colspan="8" style="font-weight: bold; font-size: 16px;">3. Rekapitulasi Invoice Pembayaran</td>
            </tr>
        </tbody>
    </table>
    <table>
        <thead>
            <tr>
                <th style="font-weight:bold">No. Invoice</th>
                <th style="font-weight:bold">Kasir Penerima</th>
                <th style="font-weight:bold">Kanal</th>
                <th style="font-weight:bold">Tipe Invoice</th>
                <th style="font-weight:bold">Subtotal</th>
                <th style="font-weight:bold">Diskon</th>
                <th style="font-weight:bold">Total Neto</th>
                <th style="font-weight:bold">Metode Bayar</th>
                <th style="font-weight:bold">Waktu Bayar</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoices as $inv)
            <tr>
                <td>{{ $inv->invoice_number }}</td>
                <td>{{ $inv->cashierUser ? $inv->cashierUser->name : '-' }}</td>
                <td>
                    @if($inv->cashierUser)
                        {{ $inv->cashierUser->role === 'kasir' ? ($inv->cashierUser->is_fnb_staff ? 'FnB' : 'Billing') : ucfirst($inv->cashierUser->role) }}
                    @else
                        -
                    @endif
                </td>
                <td>{{ str_replace('_', ' ', $inv->invoice_type) }}</td>
                <td>Rp {{ number_format($inv->subtotal, 0, ',', '.') }}</td>
                <td>{{ $inv->discount > 0 ? 'Rp ' . number_format($inv->discount, 0, ',', '.') : '-' }}</td>
                <td>Rp {{ number_format($inv->final_total, 0, ',', '.') }}</td>
                <td>{{ ucfirst(str_replace('_', ' ', $inv->payment_method ?: '-')) }}</td>
                <td>{{ $inv->paid_at ? $inv->paid_at->format('Y-m-d H:i') : '-' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif
    
    @if(isset($dailyTotals) && $dailyTotals->count() > 0)
    <table>
        <thead></thead>
        <tbody>
            <tr>
                <td colspan="8" style="font-weight: bold; font-size: 16px;">Statistik Pendapatan Harian</td>
            </tr>
        </tbody>
    </table>
    <table class="table">
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
    <table>
        <thead></thead>
        <tbody>
            <tr><td colspan="8">&nbsp;</td></tr>
        </tbody>
    </table>
    @endif
    
    <table>
        <thead></thead>
        <tbody>
            <tr>
                <td colspan="8" style="font-weight: bold; font-size: 16px;">Detail Transaksi</td>
            </tr>
        </tbody>
    </table>
    <table>
        <thead>
            <tr>
                <th scope="col" style="font-weight:bold">ID Transaksi</th>
                <th scope="col" style="font-weight:bold">Nama</th>
                <th scope="col" style="font-weight:bold">Data Perangkat</th>
                <th scope="col" style="font-weight:bold">Tipe</th>
                <th scope="col" style="font-weight:bold">Jam Main</th>
                <th scope="col" style="font-weight:bold">Waktu Mulai</th>
                <th scope="col" style="font-weight:bold">Waktu Selesai</th>
                <th scope="col" style="font-weight:bold">FnB</th>
                <th scope="col" style="font-weight:bold">Total</th>
                <th scope="col" style="font-weight:bold">Diskon</th>
                <th scope="col" style="font-weight:bold">Total Setelah Diskon</th>
                <th scope="col" style="font-weight:bold">Tanggal</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($transactions as $transaksi)
                <tr>
                    <td style="text-align: left">{{ $transaksi->id_transaksi }}</td>
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
    <table>
        <thead></thead>
        <tbody>
            <tr><td colspan="8">&nbsp;</td></tr>
            <tr>
                <td colspan="8" style="font-weight: bold; font-size: 16px;">Detail Penjualan FnB Standalone</td>
            </tr>
        </tbody>
    </table>
    <table>
        <thead>
            <tr>
                <th scope="col" style="font-weight:bold">No. Invoice</th>
                <th scope="col" style="font-weight:bold">Stasiun / Sumber</th>
                <th scope="col" style="font-weight:bold">Kasir</th>
                <th scope="col" style="font-weight:bold">Item FnB</th>
                <th scope="col" style="font-weight:bold">Subtotal</th>
                <th scope="col" style="font-weight:bold">Diskon</th>
                <th scope="col" style="font-weight:bold">Total Bayar</th>
                <th scope="col" style="font-weight:bold">Tanggal Bayar</th>
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
