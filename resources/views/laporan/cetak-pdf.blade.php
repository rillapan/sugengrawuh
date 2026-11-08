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
    <h3 class="text-center mb-3">
        Laporan Transaksi Rental Playstation
    </h3>
    <p class="mb-2">Dari Tanggal: {{ $startDate }} Sampai: {{ $endDate }}</p>
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
