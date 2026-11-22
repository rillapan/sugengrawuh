<!-- Summary Cards Section -->
<div class="row mb-4">
    <!-- Transaction Types Row -->
    <div class="col-md-12 mb-3">
        <h2 class="h5 mb-3">Jenis Transaksi</h2>
        <div class="row">
            <div class="col-md-4 mb-2 mb-md-0">
                <div class="card border-left-primary shadow h-100 py-2">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Lost Time</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">{{ $postpaidCount }}</div>
                        </div>
                        <div>
                            <i class="fas fa-clock fa-2x text-gray-300" aria-hidden="true"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-2 mb-md-0">
                <div class="card border-left-success shadow h-100 py-2">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Timer</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">{{ $prepaidCount }}</div>
                        </div>
                        <div>
                            <i class="fas fa-credit-card fa-2x text-gray-300" aria-hidden="true"></i>
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
                            <i class="fas fa-box fa-2x text-gray-300" aria-hidden="true"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Payment Status Row -->
    <div class="col-md-12">
        <h2 class="h5 mb-3">Status Pembayaran</h2>
        <div class="row">
            <div class="col-md-6 mb-2 mb-md-0">
                <div class="card border-left-info shadow h-100 py-2">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Paid</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">{{ $paidCount }}</div>
                        </div>
                        <div>
                            <button
                                type="button"
                                class="btn btn-sm btn-info"
                                data-toggle="modal"
                                data-target="#paymentDetailModal"
                                aria-label="Lihat Detail Pembayaran"
                                title="Lihat Detail Pembayaran"
                            >
                                <i class="fas fa-eye" aria-hidden="true"></i>
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
                            <i class="fas fa-exclamation-triangle fa-2x text-gray-300" aria-hidden="true"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card shadow mb-4">
    <div class="card-header py-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center" style="gap: 0.75rem;">
            <form method="GET" action="{{ route('transaction.index') }}" class="pos-history-filter-form">
                <input type="hidden" name="tab" value="history">
                <div class="d-flex align-items-center">
                    <label for="history-search" class="sr-only">Cari Transaksi</label>
                    <input
                        type="search"
                        id="history-search"
                        name="search"
                        value="{{ $search }}"
                        class="form-control form-control-sm"
                        placeholder="Cari ID, nama, stasiun..."
                    >
                </div>
                <div class="d-flex align-items-center">
                    <label for="history-type" class="sr-only">Filter Tipe Transaksi</label>
                    <select id="history-type" name="type" class="form-control form-control-sm">
                        <option value="all" {{ $currentType == 'all' ? 'selected' : '' }}>Semua</option>
                        <option value="prepaid" {{ $currentType == 'prepaid' ? 'selected' : '' }}>Timer</option>
                        <option value="postpaid" {{ $currentType == 'postpaid' ? 'selected' : '' }}>Lost Time</option>
                        <option value="custom_package" {{ $currentType == 'custom_package' ? 'selected' : '' }}>Custom Paket</option>
                    </select>
                </div>
                <button type="submit" class="pos-btn pos-btn-primary pos-btn-sm">Filter</button>
                @if ($search !== '' || $currentType !== 'all')
                    <a href="{{ route('transaction.index', ['tab' => 'history']) }}" class="btn btn-outline-secondary btn-sm">Reset</a>
                @endif
            </form>

            <div class="d-flex align-items-center">
                @if ($canOperate)
                    <a href="{{ route('transaction.create') }}" class="pos-btn pos-btn-primary pos-btn-sm">
                        <i class="fas fa-plus" aria-hidden="true"></i> Tambah Data Pesanan
                    </a>
                @endif
            </div>
        </div>
    </div>

    <div class="card-body">
        <div tabindex="0" role="region" aria-label="Tabel Riwayat Transaksi" class="table-responsive pos-table-wrapper">
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
                        @if ($canOperate)
                            <th scope="col">Action</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($transactions as $transaksi)
                        <tr>
                            <td>{{ $transaksi->id_transaksi }}</td>
                            <td>{{ $transaksi->nama }}</td>
                            <td>
                                @php
                                    $historyPsName = optional($transaksi->playstation)->nama ?: optional(optional($transaksi->device)->playstation)->nama;
                                @endphp
                                @if ($transaksi->device && $historyPsName)
                                    {{ $transaksi->device->nama }} - {{ $historyPsName }}
                                @elseif ($transaksi->device)
                                    {{ $transaksi->device->nama }}
                                @else
                                    N/A
                                @endif
                            </td>
                            <td>
                                @if ($transaksi->tipe_transaksi === 'prepaid')
                                    Timer
                                @elseif ($transaksi->tipe_transaksi === 'custom_package')
                                    Custom Paket - {{ $transaksi->custom_package ? $transaksi->custom_package->nama_paket : 'N/A' }}
                                @else
                                    Lost Time
                                @endif
                            </td>
                            <td>{{ $transaksi->display_duration }}</td>
                            <td>{{ $transaksi->waktu_mulai }}</td>
                            <td>{{ $transaksi->waktu_Selesai ?: '-' }}</td>
                            <td>
                                @if ($transaksi->transactionFnbs->isEmpty())
                                    -
                                @else
                                    @foreach ($transaksi->transactionFnbs as $fnbItem)
                                        {{ optional($fnbItem->fnb)->nama ?? 'Item' }} ({{ $fnbItem->qty }} x Rp {{ number_format($fnbItem->harga_jual, 0, ',', '.') }})@if (!$loop->last), @endif
                                    @endforeach
                                @endif
                            </td>
                            <td>{{ 'Rp ' . number_format($transaksi->total, 0, ',', '.') }}</td>
                            <td>
                                @if ($transaksi->diskon && $transaksi->diskon > 0)
                                    {{ $transaksi->diskon }}%
                                @else
                                    -
                                @endif
                            </td>
                            <td>
                                @if ($transaksi->diskon && $transaksi->diskon > 0)
                                    {{ 'Rp ' . number_format($transaksi->total - ($transaksi->total * $transaksi->diskon / 100), 0, ',', '.') }}
                                @else
                                    {{ 'Rp ' . number_format($transaksi->total, 0, ',', '.') }}
                                @endif
                            </td>
                            <td>{{ $transaksi->created_at }}</td>
                            <td>{{ ucfirst($transaksi->status_transaksi) }}</td>
                            <td>
                                @if ($transaksi->payment_status === 'paid')
                                    Paid -
                                    @if ($transaksi->payment_method === 'tunai')
                                        Pembayaran Tunai
                                    @elseif ($transaksi->payment_method === 'e-wallet')
                                        Pembayaran Menggunakan E-Wallet
                                    @elseif ($transaksi->payment_method === 'transfer_bank')
                                        Transfer Bank
                                    @else
                                        {{ ucfirst($transaksi->payment_method ?? 'N/A') }}
                                    @endif
                                @else
                                    {{ ucfirst($transaksi->payment_status) }}
                                @endif
                            </td>
                            @if ($canOperate)
                                <td>
                                    @if ($transaksi->device)
                                        <div class="pos-history-actions" role="group" aria-label="Aksi Transaksi {{ $transaksi->id_transaksi }}">
                                            <a href="{{ route('transaction.show', $transaksi->id_transaksi) }}" class="btn btn-info btn-sm" aria-label="Detail transaksi {{ $transaksi->id_transaksi }}" title="Detail">
                                                <i class="fas fa-eye" aria-hidden="true"></i>
                                            </a>
                                            @if ($transaksi->payment_status === 'paid')
                                                <a href="{{ route('transaction.print', $transaksi->id_transaksi) }}" class="btn btn-secondary btn-sm" aria-label="Cetak struk transaksi {{ $transaksi->id_transaksi }}" title="Cetak Struk" target="_blank" rel="noopener">
                                                    <i class="fas fa-print" aria-hidden="true"></i>
                                                </a>
                                            @endif
                                            @if ($transaksi->payment_status === 'unpaid')
                                                <a href="{{ route('transaction.showPayment', $transaksi->id_transaksi) }}" class="btn btn-success btn-sm" title="Bayar">
                                                    <i class="fas fa-credit-card" aria-hidden="true"></i> Bayar
                                                </a>
                                                <a href="{{ route('transaction.edit', $transaksi->id_transaksi) }}" class="btn btn-warning btn-sm" title="Edit">
                                                    <i class="fas fa-edit" aria-hidden="true"></i> Edit
                                                </a>
                                                <a href="{{ route('transaction.add-order', $transaksi->id_transaksi) }}" class="btn btn-primary btn-sm" title="Tambah Pesanan">
                                                    <i class="fas fa-plus" aria-hidden="true"></i> Tambah Pesanan
                                                </a>
                                            @endif
                                            @if ($transaksi->tipe_transaksi === 'postpaid' && $transaksi->status_transaksi === 'berjalan' && $transaksi->id_transaksi)
                                                <form action="{{ route('transaction.end', $transaksi->id_transaksi) }}" method="post" class="d-inline">
                                                    @csrf
                                                    <button type="submit" class="btn btn-warning btn-sm" onclick="return confirm('Apakah Anda yakin ingin mengakhiri transaksi ini?')" title="Selesai">
                                                        <i class="fas fa-stop-circle" aria-hidden="true"></i> Selesai
                                                    </button>
                                                </form>
                                            @endif
                                        </div>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $canOperate ? 15 : 14 }}" class="text-center text-muted py-4">
                                Belum ada data transaksi yang sesuai.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-3">
            {{ $transactions->links() }}
        </div>
    </div>
</div>

<!-- Payment Detail Modal (Bootstrap 4) -->
<div class="modal fade" id="paymentDetailModal" tabindex="-1" role="dialog" aria-labelledby="paymentDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="paymentDetailModalLabel">Detail Pembayaran</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Tutup">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <h6 class="text-info mb-1">Total Transaksi Paid</h6>
                    <div class="h2 font-weight-bold mb-0">{{ $paidCount }}</div>
                </div>

                <hr>

                <div class="payment-method-details">
                    <h6 class="text-muted mb-3">Jenis Pembayaran:</h6>

                    @if ($paymentMethodCounts['tunai'] > 0)
                        <div class="payment-method-item mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="font-weight-bold">Pembayaran Tunai</span>
                                <span class="badge badge-primary">{{ $paymentMethodCounts['tunai'] }}</span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-muted">Total Uang</span>
                                <span class="font-weight-bold text-success">Rp {{ number_format($paymentMethodTotals['tunai'], 0, ',', '.') }}</span>
                            </div>
                        </div>
                    @endif

                    @if ($paymentMethodCounts['e-wallet'] > 0)
                        <div class="payment-method-item mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="font-weight-bold">Pembayaran E-Wallet</span>
                                <span class="badge badge-success">{{ $paymentMethodCounts['e-wallet'] }}</span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-muted">Total Uang</span>
                                <span class="font-weight-bold text-success">Rp {{ number_format($paymentMethodTotals['e-wallet'], 0, ',', '.') }}</span>
                            </div>
                        </div>
                    @endif

                    @if ($paymentMethodCounts['transfer_bank'] > 0)
                        <div class="payment-method-item mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="font-weight-bold">Pembayaran Transfer Bank</span>
                                <span class="badge badge-warning">{{ $paymentMethodCounts['transfer_bank'] }}</span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-muted">Total Uang</span>
                                <span class="font-weight-bold text-success">Rp {{ number_format($paymentMethodTotals['transfer_bank'], 0, ',', '.') }}</span>
                            </div>
                        </div>
                    @endif

                    @if ($paidCount == 0)
                        <div class="text-center text-muted py-3">
                            <i class="fas fa-info-circle fa-3x mb-3" aria-hidden="true"></i>
                            <p class="mb-0">Belum ada pembayaran</p>
                        </div>
                    @endif
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

