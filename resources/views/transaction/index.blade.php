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

    <!-- Summary Cards Section -->
    <div class="row mb-4">
        <!-- Transaction Types Row -->
        <div class="col-md-12 mb-3">
            <h5 class="mb-3">Jenis Transaksi</h5>
            <div class="row">
                <div class="col-md-4">
                    <div class="card border-left-primary shadow h-100 py-2">
                        <div class="card-body d-flex justify-content-between align-items-center">
                            <div>
                                <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Lost Time</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">{{ $postpaidCount }}</div>
                            </div>
                            <div>
                                <i class="fas fa-clock fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card border-left-success shadow h-100 py-2">
                        <div class="card-body d-flex justify-content-between align-items-center">
                            <div>
                                <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Paket</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">{{ $prepaidCount }}</div>
                            </div>
                            <div>
                                <i class="fas fa-credit-card fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card border-left-warning shadow h-100 py-2">
                        <div class="card-body d-flex justify-content-between align-items-center">
                            <div>
                                <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Custom Paket</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">{{ $customPackageCount }}</div>
                            </div>
                            <div>
                                <i class="fas fa-box fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Payment Status Row -->
        <div class="col-md-12">
            <h5 class="mb-3">Status Pembayaran</h5>
            <div class="row">
                <div class="col-md-6">
                    <div class="card border-left-info shadow h-100 py-2">
                        <div class="card-body d-flex justify-content-between align-items-center">
                            <div>
                                <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Paid</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">{{ $paidCount }}</div>
                            </div>
                            <div>
                                <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#paymentDetailModal" title="Lihat Detail Pembayaran">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card border-left-warning shadow h-100 py-2">
                        <div class="card-body d-flex justify-content-between align-items-center">
                            <div>
                                <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Unpaid</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">{{ $unpaidCount }}</div>
                            </div>
                            <div>
                                <i class="fas fa-exclamation-triangle fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow mb-4">
        <!-- Card Header - Dropdown -->
        <div class="card-header py-3">
            <div class="d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center">
                    <form method="GET" action="{{ route('transaction.index') }}" class="d-flex mr-2">
                        <select name="type" class="form-control form-control-sm mr-2">
                            <option value="all" {{ $currentType == 'all' ? 'selected' : '' }}>Semua</option>
                            <option value="prepaid" {{ $currentType == 'prepaid' ? 'selected' : '' }}>Paket</option>
                            <option value="postpaid" {{ $currentType == 'postpaid' ? 'selected' : '' }}>Lost Time</option>
                            <option value="custom_package" {{ $currentType == 'custom_package' ? 'selected' : '' }}>Custom Paket</option>
                        </select>
                        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                    </form>
                </div>
                
                <div class="d-flex align-items-center">
                    @if (auth()->user()->isAdmin() || auth()->user()->isKasir())
                        <a href="{{ route('transaction.create') }}" class="btn btn-primary btn-sm">
                            <i class="fas fa-plus"></i> Tambah Data Pesanan
                        </a>
                    @endif
                </div>
            </div>
        </div>
        <!-- Card Body -->
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover">
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
                        <th scope="col">Status Transaksi</th>
                        <th scope="col">Status Pembayaran</th>
                       

                        @if (auth()->user()->isAdmin() || auth()->user()->isKasir())
                            <th scope="col">Action</th>
                        @endif
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
                                    N/A
                                @endif
                            </td>
                            <td>
                                @if($transaksi->tipe_transaksi === 'prepaid')
                                    Paket
                                @elseif($transaksi->tipe_transaksi === 'custom_package')
                                    Custom Paket - {{ $transaksi->custom_package ? $transaksi->custom_package->nama_paket : 'N/A' }}
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
                                        {{ $fnbItem->fnb->nama }} ({{ $fnbItem->qty }} x Rp {{ number_format($fnbItem->harga_jual, 0, ',', '.') }})
                                        @if(!$loop->last), @endif
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
                            <td>{{ ucfirst($transaksi->status_transaksi) }}</td>
                            <td>
                                @if($transaksi->payment_status === 'paid')
                                    Paid - 
                                    @if($transaksi->payment_method === 'tunai')
                                        Pembayaran Tunai
                                    @elseif($transaksi->payment_method === 'e-wallet')
                                        Pembayaran Menggunakan E-Wallet
                                    @elseif($transaksi->payment_method === 'transfer_bank')
                                        Transfer Bank
                                    @else
                                        {{ ucfirst($transaksi->payment_method ?? 'N/A') }}
                                    @endif
                                @else
                                    {{ ucfirst($transaksi->payment_status) }}
                                @endif
                            </td>


                            @if ((auth()->user()->isAdmin() || auth()->user()->isKasir()) && $transaksi->device)
                                <td>
                                    <div class="btn-group" role="group" aria-label="Action Buttons">
                                        <a href="{{ route('transaction.show', ['transaction' => $transaksi->id_transaksi]) }}" class="btn btn-info btn-sm" title="Detail">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        @if($transaksi->payment_status === 'paid')
                                            <a href="{{ route('transaction.print', $transaksi->id_transaksi) }}" class="btn btn-secondary btn-sm" title="Cetak Struk" target="_blank">
                                                <i class="fas fa-print"></i>
                                            </a>
                                        @endif
                                        @if($transaksi->payment_status === 'unpaid')
                                            <a href="{{ route('transaction.showPayment', ['id' => $transaksi->id_transaksi]) }}" class="btn btn-success btn-sm" title="Bayar">
                                                <i class="fas fa-credit-card"></i> Bayar
                                            </a>
                                        @endif
                                        @if($transaksi->payment_status === 'unpaid')
                                            <a href="{{ route('transaction.edit', $transaksi->id_transaksi) }}" class="btn btn-warning btn-sm" title="Edit">
                                                <i class="fas fa-edit"></i> Edit
                                            </a>
                                        @endif
                                        @if($transaksi->payment_status === 'unpaid')
                                            <a href="{{ route('transaction.add-order', $transaksi->id_transaksi) }}" class="btn btn-primary btn-sm" title="Tambah Pesanan">
                                                <i class="fas fa-plus"></i> Tambah Pesanan
                                            </a>
                                        @endif

                                        <form action="/transaction/{{ $transaksi->id }}/update" method="post" class="d-inline">
                                            @method('put')
                                            @csrf
                                            <input type="hidden" name="device_id" value="{{ $transaksi->device->id }}">
                                            <input type="hidden" name="status" value="digunakan">
                                            <input type="hidden" name="status_transaksi" value="sukses">
                                            {{-- <button class="btn btn-success btn-sm" onclick="return confirm('Are you sure?')" title="Update Status">
                                                <i class="fa fa-check-square fa-lg" aria-hidden="true"></i>
                                            </button> --}}
                                        </form>
                                        @if($transaksi->status_transaksi === 'berjalan' && $transaksi->id)
                                            <form action="{{ route('transaction.end', ['transaction' => $transaksi->id_transaksi]) }}" method="post" class="d-inline">
                                                @csrf
                                                <button type="submit" class="btn btn-warning btn-sm" onclick="return confirm('Apakah Anda yakin ingin mengakhiri transaksi ini?')" title="Selesai">
                                                    <i class="fas fa-stop-circle"></i> Selesai
                                                </button>
                                            </form>
                                        @endif
                                        <form action="/transaction/{{ $transaksi->id }}" method="post" class="d-inline">
                                            @method('delete')
                                            @csrf
                                            {{-- <button class="btn btn-danger btn-sm" onclick="return confirm('Are you sure?')" title="Hapus">
                                                <i class="fas fa-trash-alt fa-lg"></i>
                                            </button> --}}
                                        </form>
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
                </table>
            </div>
            {{ $transactions->links() }}
        </div>
    </div>
    </div>
<style>
    .btn-group .btn {
        margin-right: 4px;
    }
    .btn-group .btn:last-child {
        margin-right: 0;
    }
    .timer {
        font-weight: 600;
        color: #333;
    }
    .timer-display {
        font-family: monospace;
    }
    table.table-bordered {
        border: 1px solid #dee2e6;
    }
    table.table-bordered th,
    table.table-bordered td {
        border: 1px solid #dee2e6;
        vertical-align: middle;
    }
</style>
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

    // Handle STOP button clicks to stop timer immediately
    document.addEventListener('submit', function(e) {
        if (e.target.matches('form[action*="end"]')) {
            e.preventDefault();

            const form = e.target;
            const transactionId = form.action.split('/').pop();

            // Stop the timer immediately
            const timer = document.querySelector(`.timer[data-transaction-id="${transactionId}"]`);
            if (timer) {
                const displayElement = timer.querySelector('.timer-display');
                if (displayElement) {
                    displayElement.textContent = 'Menghentikan...';
                }
            }

            // Submit the form
            fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                }
            }).then(response => {
                if (response.ok) {
                    window.location.reload();
                } else {
                    alert('Terjadi kesalahan saat mengakhiri transaksi');
                    // Restart timer if failed
                    updateTimers();
                }
            }).catch(error => {
                alert('Terjadi kesalahan saat mengakhiri transaksi');
                // Restart timer if failed
                updateTimers();
            });
        }
    });
</script>

<!-- Payment Detail Modal -->
<div class="modal fade" id="paymentDetailModal" tabindex="-1" aria-labelledby="paymentDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="paymentDetailModalLabel">Detail Pembayaran</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <h4 class="text-info">Total Transaksi Paid</h4>
                    <h2 class="font-weight-bold">{{ $paidCount }}</h2>
                </div>
                
                <hr>
                
                <div class="payment-method-details">
                    <h6 class="text-muted mb-3">Jenis Pembayaran:</h6>
                    
                    @if($paymentMethodCounts['tunai'] > 0)
                    <div class="payment-method-item mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="fw-bold">Pembayaran Tunai</span>
                            <span class="badge bg-primary">{{ $paymentMethodCounts['tunai'] }}</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted">Total Uang</span>
                            <span class="fw-bold text-success">Rp {{ number_format($paymentMethodTotals['tunai'], 0, ',', '.') }}</span>
                        </div>
                    </div>
                    @endif
                    
                    @if($paymentMethodCounts['e-wallet'] > 0)
                    <div class="payment-method-item mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="fw-bold">Pembayaran E-Wallet</span>
                            <span class="badge bg-success">{{ $paymentMethodCounts['e-wallet'] }}</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted">Total Uang</span>
                            <span class="fw-bold text-success">Rp {{ number_format($paymentMethodTotals['e-wallet'], 0, ',', '.') }}</span>
                        </div>
                    </div>
                    @endif
                    
                    @if($paymentMethodCounts['transfer_bank'] > 0)
                    <div class="payment-method-item mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="fw-bold">Pembayaran Transfer Bank</span>
                            <span class="badge bg-warning">{{ $paymentMethodCounts['transfer_bank'] }}</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted">Total Uang</span>
                            <span class="fw-bold text-success">Rp {{ number_format($paymentMethodTotals['transfer_bank'], 0, ',', '.') }}</span>
                        </div>
                    </div>
                    @endif
                    
                    @if($paidCount == 0)
                    <div class="text-center text-muted">
                        <i class="fas fa-info-circle fa-3x mb-3"></i>
                        <p>Belum ada pembayaran</p>
                    </div>
                    @endif
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>
@endsection
