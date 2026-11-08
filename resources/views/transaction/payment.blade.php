@extends('layouts.app')

@section('content')
<div class="container">
    <h1 class="mb-4">Pembayaran Transaksi #{{ $transaction->id_transaksi }}</h1>

    <div class="card mb-4">
        <div class="card-header">
            <h5>Informasi Detail Transaksi</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <h6>Informasi Dasar</h6>
                    <table class="table table-borderless">
                        <tr>
                            <td><strong>ID Transaksi:</strong></td>
                            <td>{{ $transaction->id_transaksi }}</td>
                        </tr>
                        <tr>
                            <td><strong>Nama Pelanggan :</strong></td>
                            <td>
                                    {{ $transaction->nama }}
                            </td>
                        </tr>
                        <tr>
                            <td><strong>No Telf :</strong></td>
                            <td>{{ $transaction->no_telepon }}</td>
                        </tr>
                        <tr>
                            <td><strong>Tipe Transaksi:</strong></td>
                            <td>
                                @if($transaction->tipe_transaksi === 'prepaid')
                                    Paket
                                @elseif($transaction->tipe_transaksi === 'custom_package')
                                    Custom Paket
                                @else
                                    Lost Time
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Tanggal:</strong></td>
                            <td>{{ $transaction->created_at->format('d/m/Y') }}</td>
                        </tr>
                        @if($transaction->tipe_transaksi === 'custom_package' && $transaction->custom_package)
                            <tr>
                                <td><strong>Nama Paket:</strong></td>
                                <td>{{ $transaction->custom_package->nama_paket }}</td>
                            </tr>
                        @endif
                    </table>
                </div>
                <div class="col-md-6">
                    <h6>Informasi Perangkat</h6>
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

            @if($transaction->tipe_transaksi === 'custom_package' && $transaction->custom_package)
                <hr>
                <div class="row">
                    <div class="col-12">
                        <h6>Detail Custom Paket</h6>
                        <div class="alert alert-info">
                            <h6 class="alert-heading">{{ $transaction->custom_package->nama_paket }}</h6>
                            <p class="mb-2">{{ $transaction->custom_package->deskripsi }}</p>
                            <hr>
                            <div class="row">
                                <div class="col-md-6">
                                    <strong>Playstation dalam Paket:</strong>
                                    <ul class="mb-0">
                                        @if($transaction->custom_package->playstations->isNotEmpty())
                                            @foreach($transaction->custom_package->playstations as $playstation)
                                                <li>{{ $playstation->nama }} - {{ $playstation->pivot->lama_main }} menit</li>
                                            @endforeach
                                        @else
                                            <li>Tidak ada playstation</li>
                                        @endif
                                    </ul>
                                </div>
                                <div class="col-md-6">
                                    <strong>F&B dalam Paket:</strong>
                                    <ul class="mb-0">
                                        @if($transaction->custom_package->fnbs->isNotEmpty())
                                            @foreach($transaction->custom_package->fnbs as $fnb)
                                                <li>{{ $fnb->nama }} (Qty: {{ $fnb->pivot->quantity }})</li>
                                            @endforeach
                                        @else
                                            <li>Tidak ada F&B</li>
                                        @endif
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            <hr>

            <div class="row">
                <div class="col-md-6">
                    <h6>Waktu Bermain</h6>
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
                        @endif
                    </table>
                </div>
                <div class="col-md-6">
                    <h6>Biaya</h6>
                    <table class="table table-borderless">
                        <tr>
                            <td><strong>
                                @if($transaction->tipe_transaksi === 'custom_package')
                                    Harga Paket:
                                @else
                                    Harga per Jam:
                                @endif
                            </strong></td>
                            <td>Rp {{ number_format($transaction->harga, 0, ',', '.') }}</td>
                        </tr>
                        @if($transaction->jam_main)
                            <tr>
                                <td><strong>Lama Main:</strong></td>
                                <td>
                                    {{ $transaction->display_duration }}
                                </td>
                            </tr>
                        @endif
                        @if($transaction->tipe_transaksi !== 'custom_package')
                        <tr>
                            <td><strong>Biaya Playstation:</strong></td>
                            <td>Rp {{ number_format($transaction->total - $transaction->getFnbTotalAttribute(), 0, ',', '.') }}</td>
                        </tr>
                        @endif
                        @if($transaction->tipe_transaksi !== 'custom_package' || $transaction->transactionFnbs->isNotEmpty())
                        <tr>
                            <td><strong>Biaya FnB:</strong></td>
                            <td>Rp {{ number_format($transaction->getFnbTotalAttribute(), 0, ',', '.') }}</td>
                        </tr>
                        @endif
                        <tr id="discount-row" style="display: none;">
                            <td class="text-warning"><strong>Diskon:</strong></td>
                            <td class="text-warning"><strong>-Rp <span id="discount-display">0</span></strong></td>
                        </tr>
                        <tr class="bg-success">
                            <td class="text-white font-weight-bold"><h5>Total Biaya:</h5></td>
                            <td class="text-white font-weight-bold"><h5>Rp <span id="total-display">{{ number_format($transaction->total, 0, ',', '.') }}</span></h5></td>
                        </tr>

                    </table>
                </div>
            </div>

            @if($transaction->transactionFnbs->isNotEmpty())
                <hr>
                <h6>Detail FnB</h6>
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
                                    $showTotal = '';
                                    
                                    if ($isPackageItem) {
                                        if ($fnbItem->qty <= $qtyInPackage) {
                                            $showTotal = '<span class="badge badge-info">harga paket</span>';
                                        } else {
                                            $paidQty = $fnbItem->qty - $qtyInPackage;
                                            $paidTotal = $paidQty * $fnbItem->harga_jual;
                                            $showTotal = 'Rp ' . number_format($paidTotal, 0, ',', '.') . ' <br><small class="text-muted">(' . $paidQty . ' tambahan berbayar)</small>';
                                        }
                                    } else {
                                        $showTotal = 'Rp ' . number_format($fnbItem->qty * $fnbItem->harga_jual, 0, ',', '.');
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
                                    <td>{!! $showTotal !!}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">
            <h5>Apakah ada pesanan tambahan?</h5>
        </div>
        <div class="card-body">
            <p class="mb-3">Tambahkan makanan atau minuman untuk transaksi ini</p>
            <a href="{{ route('transaction.add-order', $transaction->id_transaksi) }}" class="btn btn-success">
                <i class="fas fa-plus"></i> Tambah Pesanan
            </a>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h5>Proses Pembayaran</h5>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('transaction.processPayment', ['id' => $transaction->id_transaksi]) }}">
                @csrf
                <div class="form-group mb-3">
                    <label for="payment_method">Metode Pembayaran <span class="text-danger">*</span></label>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="payment_method" id="payment_cash" value="tunai" required>
                        <label class="form-check-label" for="payment_cash">
                            Pembayaran Tunai
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="payment_method" id="payment_ewallet" value="e-wallet" required>
                        <label class="form-check-label" for="payment_ewallet">
                            Pembayaran Menggunakan E-Wallet
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="payment_method" id="payment_transfer" value="transfer_bank" required>
                        <label class="form-check-label" for="payment_transfer">
                            Transfer Bank
                        </label>
                    </div>
                    @error('payment_method')
                        <div class="text-danger small">{{ $message }}</div>
                    @enderror
                </div>

                <div class="form-group mb-3">
                    <label for="discount">Diskon (Opsional)</label>
                    <div class="input-group">
                        <input type="number" class="form-control @error('discount') is-invalid @enderror" id="discount" name="discount" min="0" max="100" value="0" step="0.1" oninput="updateTotalAfterDiscount()">
                        <div class="input-group-append">
                            <span class="input-group-text">%</span>
                        </div>
                    </div>
                    <small class="form-text text-muted">Masukkan persentase diskon (0-100%)</small>
                    @error('discount')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="form-group mb-3">
                    <label for="final_total">Total Setelah Diskon</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text">Rp</span>
                        </div>
                        <input type="number" class="form-control bg-light" id="final_total" readonly>
                    </div>
                    <small class="form-text text-info">Total yang harus dibayar setelah diskon</small>
                </div>

                <div class="form-group mb-3">
                    <label for="amount_paid">Jumlah Uang Dibayar</label>
                    <input type="number" class="form-control @error('amount_paid') is-invalid @enderror" id="amount_paid" name="amount_paid" required min="{{ $transaction->total }}" oninput="calculateChange()">
                    @error('amount_paid')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="form-group mb-3" id="change-group">
                    <label for="change">Kembalian</label>
                    <input type="number" class="form-control" id="change" readonly>
                </div>

                <div class="d-flex justify-content-between align-items-center mt-4">
                    <div>
                        <a href="{{ route('transaction.index') }}" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left"></i> Kembali ke Transaksi
                        </a>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="{{ route('transaction.index') }}" class="btn btn-outline-danger" onclick="return confirm('Apakah Anda yakin ingin membatalkan pembayaran?')">
                            <i class="fas fa-times"></i> Batal
                        </a>
                        <button type="submit" class="btn btn-success btn-lg px-4">
                            <i class="fas fa-credit-card"></i> Proses Pembayaran
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    const originalTotal = {{ $transaction->total }};
    let finalTotal = originalTotal;
    
    function updateTotalAfterDiscount() {
        const discountPercentage = parseFloat(document.getElementById('discount').value) || 0;
        const discountAmount = (originalTotal * discountPercentage) / 100;
        finalTotal = originalTotal - discountAmount;
        
        // Ensure final total is not negative
        if (finalTotal < 0) {
            finalTotal = 0;
        }
        
        // Update final total display only if discount > 0
        const finalTotalInput = document.getElementById('final_total');
        if (discountPercentage > 0) {
            finalTotalInput.value = finalTotal;
        } else {
            finalTotalInput.value = '';
        }
        
        // Update discount row in transaction details
        const discountRow = document.getElementById('discount-row');
        const discountDisplay = document.getElementById('discount-display');
        const totalDisplay = document.getElementById('total-display');
        
        if (discountPercentage > 0) {
            discountRow.style.display = 'table-row';
            discountDisplay.textContent = new Intl.NumberFormat('id-ID').format(discountAmount) + ' (' + discountPercentage + '%)';
            totalDisplay.textContent = new Intl.NumberFormat('id-ID').format(finalTotal);
        } else {
            discountRow.style.display = 'none';
            totalDisplay.textContent = new Intl.NumberFormat('id-ID').format(originalTotal);
        }
        
        // Update amount paid minimum
        document.getElementById('amount_paid').min = finalTotal;
        
        // If current amount paid is less than new minimum, clear it for manual input
        const currentAmountPaid = parseFloat(document.getElementById('amount_paid').value) || 0;
        if (currentAmountPaid < finalTotal) {
            document.getElementById('amount_paid').value = '';
        }
        
        // Recalculate change
        calculateChange();
    }
    
    function calculateChange() {
        const amountPaid = parseFloat(document.getElementById('amount_paid').value) || 0;
        const change = amountPaid - finalTotal;
        document.getElementById('change').value = change > 0 ? change : 0;
    }
    
    // Handle payment method change
    document.addEventListener('DOMContentLoaded', function() {
        const paymentMethods = document.querySelectorAll('input[name="payment_method"]');
        const amountPaidInput = document.getElementById('amount_paid');
        const changeGroup = document.getElementById('change-group');
        
        // Initialize final total
        updateTotalAfterDiscount();
        
        paymentMethods.forEach(method => {
            method.addEventListener('change', function() {
                if (this.value === 'tunai') {
                    // Cash payment - allow manual input and show change
                    amountPaidInput.readOnly = false;
                    amountPaidInput.value = '';
                    changeGroup.style.display = 'block';
                } else {
                    // E-wallet or Bank Transfer - set to final total, no change
                    amountPaidInput.readOnly = true;
                    const discountPercentage = parseFloat(document.getElementById('discount').value) || 0;
                    amountPaidInput.value = discountPercentage > 0 ? finalTotal : originalTotal;
                    document.getElementById('change').value = 0;
                    changeGroup.style.display = 'block';
                }
            });
        });
    });
</script>
@endsection
