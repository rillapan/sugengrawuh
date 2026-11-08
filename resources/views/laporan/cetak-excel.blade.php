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
    <link
        href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i"
        rel="stylesheet">

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
                        Laporan Transaksi Rental Playstation
                    </h3>
                </td>
            </tr>
            <tr>
                <td colspan="8">
                    <p>Dari Tanggal: {{ $startDate }} Sampai: {{ $endDate }}</p>
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
                            Paket
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

    <!-- Bootstrap core JavaScript-->
    <script src="vendor/jquery/jquery.min.js"></script>
    <script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

    <!-- Core plugin JavaScript-->
    <script src="vendor/jquery-easing/jquery.easing.min.js"></script>

    <!-- Custom scripts for all pages-->
    <script src="js/sb-admin-2.min.js"></script>
</body>

</html>
