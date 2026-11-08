@extends('layouts.app')

@section('content')
    <!-- Content Row -->
    @if (session()->has('success'))
        <div class="alert alert-success col-lg-8" role="alert">
            {{ session('success') }}
        </div>
    @endif

    @if (session()->has('gagal'))
        <div class="alert alert-danger col-lg-8" role="alert">
            {{ session('gagal') }}
        </div>
    @endif

    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">Detail Transaksi #{{ $transaction->id_transaksi }}</h1>
        <a href="{{ route('transaction.index') }}" class="btn btn-secondary">Kembali</a>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Informasi Transaksi</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h5>Informasi Dasar</h5>
                            <table class="table table-borderless">
                                <tr>
                                    <td><strong>ID Transaksi:</strong></td>
                                    <td>{{ $transaction->id_transaksi }}</td>
                                </tr>
                                <tr>
                                    <td><strong>Nama:</strong></td>
                                    <td>{{ $transaction->nama }}</td>
                                </tr>
                                <tr>
                                    <td><strong>No Telf :</strong></td>
                                    <td>{{ $transaction->no_telepon }}</td>
                                </tr>
                                <tr>
                                    <td><strong>Tipe Transaksi:</strong></td>
                                   <td>{{ $transaction->tipe_transaksi === 'prepaid' ? 'Paket' : 'Lost Time' }}</td>
                                </tr>
                                
                                <tr>
                                    <td><strong>Tanggal:</strong></td>
                                    <td>{{ $transaction->created_at->format('d/m/Y') }}</td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <h5>Informasi Perangkat</h5>
                            <table class="table table-borderless">
                                <tr>
                                    <td><strong>Nama Perangkat:</strong></td>
                                    <td>{{ $transaction->device ? $transaction->device->nama : 'N/A' }}</td>
                                </tr>
                                <tr>
                                    <td><strong>Jenis Playstation:</strong></td>
                                    <td>{{ $transaction->device && $transaction->device->playstation ? $transaction->device->playstation->nama : 'N/A' }}</td>
                                </tr>
                               
                            </table>
                        </div>
                    </div>

                    <hr>

                    <div class="row">
                        <div class="col-md-6">
                            <h5>Waktu Bermain</h5>
                            <table class="table table-borderless">
                                <tr>
                                    <td><strong>Waktu Mulai:</strong></td>
                                    <td>{{ $transaction->waktu_mulai }}</td>
                                </tr>
                                <tr>
                                    <td><strong>Waktu Selesai:</strong></td>
                                    <td>{{ $transaction->waktu_Selesai ?: '-' }}</td>
                                </tr>
                                @if($transaction->tipe_transaksi === 'postpaid' && $transaction->status_transaksi === 'selesai')
                                    @php
                                        $startTime = \Carbon\Carbon::parse($transaction->created_at->toDateString() . ' ' . $transaction->waktu_mulai);
                                        $endTime = \Carbon\Carbon::parse($transaction->created_at->toDateString() . ' ' . $transaction->waktu_Selesai);
                                        $duration = $startTime->diff($endTime);
                                        $hours = $duration->h;
                                        $minutes = $duration->i;
                                        $seconds = $duration->s;
                                    @endphp
                                    <tr>
                                        <td><strong>Durasi Bermain:</strong></td>
                                        <td>{{ $hours }} jam {{ $minutes }} menit {{ $seconds }} detik</td>
                                    </tr>
                                @elseif($transaction->tipe_transaksi === 'postpaid' && $transaction->status_transaksi === 'berjalan')
                                    <tr>
                                        <td><strong>Durasi Saat Ini:</strong></td>
                                        <td>
                                            <div class="timer" data-start="{{ $transaction->created_at->toDateString() }} {{ $transaction->waktu_mulai }}" data-transaction-id="{{ $transaction->id_transaksi }}">
                                                <span class="timer-display">Lama Main: 00:00:00 (0 jam 0 menit)</span>
                                            </div>
                                        </td>
                                    </tr>
                                @endif
                            </table>
                        </div>
                        <div class="col-md-6">
                            <h5>Biaya</h5>
                            <table class="table table-borderless">
                                <tr>
                                    <td><strong>Harga per Jam:</strong></td>
                                    <td>Rp {{ number_format($transaction->harga, 0, ',', '.') }}</td>
                                </tr>
                                @if($transaction->jam_main)
                                    <tr>
                                        <td><strong>Lama Main:</strong></td>
                                        <td>{{ $transaction->display_duration }}</td>
                                    </tr>
                                @endif
                                <tr>
                                    <td><strong>Biaya Playstation:</strong></td>
                                    <td>
                                        @if($transaction->tipe_transaksi === 'custom_package')
                                            <span class="badge badge-info">Harga Paket</span>
                                        @else
                                            Rp {{ number_format($transaction->total - $transaction->getFnbTotalAttribute(), 0, ',', '.') }}
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>Biaya FnB:</strong></td>
                                    <td>Rp {{ number_format($transaction->getFnbTotalAttribute(), 0, ',', '.') }}</td>
                                </tr>
                                @if($transaction->diskon && $transaction->diskon > 0)
                                    <tr>
                                        <td class="text-info"><strong>Harga Sebelum Diskon:</strong></td>
                                        <td class="text-info"><strong>Rp {{ number_format($transaction->total, 0, ',', '.') }}</strong></td>
                                    </tr>
                                    <tr>
                                        <!-- harga sebelum diskon -->
                                        
                                        <td class="text-warning"><strong>Diskon:</strong></td>
                                        <td class="text-warning"><strong>{{ $transaction->diskon }}% (-Rp {{ number_format($transaction->total * $transaction->diskon / 100, 0, ',', '.') }})</strong></td>
                                    </tr>
                                @endif
                                <tr>
                                    <td><strong><h5>Total Biaya:</h5></strong></td>
                                    <td><h5>Rp {{ number_format($transaction->diskon && $transaction->diskon > 0 ? $transaction->total - ($transaction->total * $transaction->diskon / 100) : $transaction->total, 0, ',', '.') }}</h5></td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    @if($transaction->transactionFnbs->isNotEmpty())
                        <hr>
                        <h5>Detail FnB</h5>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Nama FnB</th>
                                        <th>Jumlah</th>
                                        <th>Harga Satuan</th>
                                        <th>Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @php
                                        // Create lookup for package items if custom package
                                        $packageItems = [];
                                        if($transaction->tipe_transaksi === 'custom_package' && $transaction->custom_package) {
                                            foreach($transaction->custom_package->fnbs as $pFnb) {
                                                $packageItems[$pFnb->id] = $pFnb->pivot->quantity;
                                            }
                                        }
                                    @endphp

                                    @foreach($transaction->transactionFnbs as $fnbItem)
                                        @php
                                            $qtyInPackage = $packageItems[$fnbItem->fnb_id] ?? 0;
                                            $isPackageItem = $qtyInPackage > 0;
                                            $displayTotal = '';
                                            
                                            if ($isPackageItem) {
                                                if ($fnbItem->qty <= $qtyInPackage) {
                                                    $displayTotal = '<span class="badge badge-info">harga paket</span>';
                                                } else {
                                                    $paidQty = $fnbItem->qty - $qtyInPackage;
                                                    $paidTotal = $paidQty * $fnbItem->harga_jual;
                                                    $displayTotal = 'Rp ' . number_format($paidTotal, 0, ',', '.') . ' <br><small class="text-muted">(' . $paidQty . ' tambahan berbayar)</small>';
                                                }
                                            } else {
                                                $displayTotal = 'Rp ' . number_format($fnbItem->qty * $fnbItem->harga_jual, 0, ',', '.');
                                            }
                                        @endphp
                                        <tr>
                                            <td>
                                                {{ $fnbItem->fnb->nama }}
                                                @if($isPackageItem)
                                                    <span class="badge badge-secondary ml-1" style="font-size: 0.7em;">Paket</span>
                                                @endif
                                            </td>
                                            <td>{{ $fnbItem->qty }}</td>
                                            <td>Rp {{ number_format($fnbItem->harga_jual, 0, ',', '.') }}</td>
                                            <td>{!! $displayTotal !!}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    @if($transaction->tipe_transaksi === 'postpaid' && $transaction->status_transaksi === 'berjalan')
                        <hr>
                        <div class="text-center">
                            <form action="{{ route('transaction.end', $transaction->id_transaksi) }}" method="post" class="d-inline">
                                @csrf
                                <button type="submit" class="btn btn-danger btn-lg" onclick="return confirm('Apakah Anda yakin ingin mengakhiri transaksi ini?')">
                                    <i class="fas fa-stop"></i> STOP
                                </button>
                            </form>
                        </div>
                    @endif
                    
                    @if($transaction->payment_status === 'unpaid' && $transaction->status_transaksi === 'selesai')
                        <hr>
                        <div class="text-center">
                            <a href="{{ route('transaction.showPayment', $transaction->id_transaksi) }}" class="btn btn-success btn-lg">
                                <i class="fas fa-credit-card"></i> BAYAR SEKARANG
                            </a>
                            <p class="mt-2 text-muted">
                                Total yang harus dibayar: <strong>Rp {{ number_format($transaction->diskon && $transaction->diskon > 0 ? $transaction->total - ($transaction->total * $transaction->diskon / 100) : $transaction->total, 0, ',', '.') }}</strong>
                                @if($transaction->diskon && $transaction->diskon > 0)
                                    <br><small class="text-info">Setelah diskon {{ $transaction->diskon }}% (hemat Rp {{ number_format($transaction->total * $transaction->diskon / 100, 0, ',', '.') }})</small>
                                @endif
                            </p>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <script>
        function updateTimers() {
            const timers = document.querySelectorAll('.timer');
            timers.forEach(timer => {
                const start = timer.getAttribute('data-start');
                const transactionId = timer.getAttribute('data-transaction-id');
                const displayElement = timer.querySelector('.timer-display');

                if (!start || !displayElement) {
                    return;
                }

                const startTime = new Date(start);
                const now = new Date();
                let diff = Math.floor((now - startTime) / 1000); // difference in seconds

                if (diff < 0) {
                    diff = 0;
                }

                const hours = Math.floor(diff / 3600);
                const minutes = Math.floor((diff % 3600) / 60);
                const seconds = diff % 60;

                const timeString = `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
                const durationString = `${hours} jam ${minutes} menit`;

                displayElement.textContent = `Lama Main: ${timeString} (${durationString})`;
            });
        }

        // Update timers every second
        setInterval(updateTimers, 1000);

        // Initial update when page loads
        document.addEventListener('DOMContentLoaded', updateTimers);


    </script>
@endsection
