@extends('layouts.app', ['title' => $title ?? 'Transaksi F&B', 'active' => 'fnb-transaction'])

@section('content')
<style>
/* Scoped styles for F&B POS Redesign (Google Stitch Aligned) */
.fnb-pos-container {
    color: #1e293b;
}

.fnb-pos-card {
    border-radius: 10px;
    border: 1px solid #e2e8f0;
    background: #ffffff;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
    overflow: hidden;
}

.fnb-pos-card .card-header {
    border-bottom: 1px solid #f1f5f9;
}

.fnb-segmented-toggle {
    background: #f1f5f9;
    padding: 4px;
    border-radius: 8px;
    display: flex;
    border: 1px solid #e2e8f0;
    gap: 4px;
}

.fnb-segmented-toggle .btn {
    border: none !important;
    border-radius: 6px !important;
    color: #475569;
    font-weight: 600;
    font-size: 13px;
    padding: 8px 14px;
    background: transparent;
    box-shadow: none !important;
    transition: all 0.15s ease-in-out;
}

.fnb-segmented-toggle .btn:hover {
    color: #0f172a;
    background: rgba(255, 255, 255, 0.6);
}

.fnb-segmented-toggle .btn.active {
    background: #2563eb !important;
    color: #ffffff !important;
    box-shadow: 0 1px 3px rgba(37, 99, 235, 0.25) !important;
}

.fnb-segmented-toggle-secondary .btn.active {
    background: #334155 !important;
    color: #ffffff !important;
    box-shadow: 0 1px 3px rgba(51, 65, 85, 0.25) !important;
}

/* Alert notices styling */
.alert-pos {
    border-radius: 8px;
    padding: 12px 14px;
    font-size: 13px;
    line-height: 1.5;
    border: 1px solid transparent;
}

.alert-pos.alert-paket {
    border-left: 4px solid #2563eb;
    background-color: #eff6ff;
    border-color: #dbeafe #dbeafe #dbeafe #2563eb;
    color: #1e3a8a;
}

.alert-pos.alert-lost-time {
    border-left: 4px solid #6366f1;
    background-color: #eef2ff;
    border-color: #e0e7ff #e0e7ff #e0e7ff #6366f1;
    color: #312e81;
}

.alert-pos.alert-non-playing {
    border-left: 4px solid #f59e0b;
    background-color: #fffbeb;
    border-color: #fef3c7 #fef3c7 #fef3c7 #f59e0b;
    color: #92400e;
}

.alert-pos.alert-helper {
    border-left: 4px solid #0284c7;
    background-color: #f0f9ff;
    border-color: #e0f2fe #e0f2fe #e0f2fe #0284c7;
    color: #0369a1;
}

/* Table styling */
.fnb-table-container {
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

#tableFnbItems thead th {
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    font-size: 11px;
    letter-spacing: 0.5px;
    color: #475569;
    padding-top: 10px;
    padding-bottom: 10px;
    white-space: nowrap;
}

#tableFnbItems tbody td {
    padding-top: 10px;
    padding-bottom: 10px;
    border-top: 1px solid #f1f5f9;
}

#tableFnbItems tbody tr:hover {
    background-color: #f8fafc;
}

.grand-total-highlight-box {
    background: #eff6ff;
    border-color: #bfdbfe !important;
}

.pos-status-indicator {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background-color: #2563eb;
    display: inline-block;
}

/* Tabular numbers for price clarity */
.row-price, .row-subtotal, #displayGrandTotal, .item-qty {
    font-variant-numeric: tabular-nums;
}

@media (max-width: 767.98px) {
    .fnb-pos-container {
        padding-left: 0.5rem;
        padding-right: 0.5rem;
    }
}
</style>

<div class="container-fluid fnb-pos-container px-3 px-md-4 py-2">
    <!-- Screen Header Bar -->
    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between mb-4 pb-2 border-bottom bg-white p-3 rounded-lg shadow-sm">
        <div class="mb-2 mb-md-0">
            <div class="d-flex align-items-center">
                <span class="pos-status-indicator mr-2"></span>
                <h1 class="h4 mb-0 font-weight-bold text-gray-900">
                    <i class="fas fa-cash-register text-primary mr-2"></i>Transaksi F&amp;B
                </h1>
                <span class="badge badge-light border text-muted ml-2 font-weight-bold text-uppercase px-2 py-1" style="font-size: 11px; letter-spacing: 0.5px;">Order Entry</span>
            </div>
            <p class="text-muted small mb-0 mt-1">Kelola pesanan makanan dan minuman pelanggan operasional kasir.</p>
        </div>
        <div class="d-flex align-items-center">
            <a href="{{ route('fnb.queue.index') }}" class="btn btn-sm btn-outline-primary shadow-sm font-weight-bold d-flex align-items-center">
                <i class="fas fa-concierge-bell mr-2"></i> Ke Antrean Kitchen
            </a>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle mr-1"></i> {{ session('success') }}
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    @endif

    @if (session('gagal'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle mr-1"></i> {{ session('gagal') }}
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <strong><i class="fas fa-exclamation-circle mr-1"></i> Terdapat kesalahan pengisian:</strong>
            <ul class="mb-0 pl-3 mt-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    @endif

    <form action="{{ route('fnb.orders.store') }}" method="POST" id="formFnbTransaction">
        @csrf

        <div class="row">
            <!-- Left Column: Customer & Session Configuration (approx 35% on Desktop) -->
            <div class="col-xl-4 col-lg-5 mb-4">
                <div class="card fnb-pos-card mb-4">
                    <div class="card-header py-3 bg-white d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center">
                            <span class="badge badge-primary px-2 py-1 mr-2 font-weight-bold" style="font-size: 13px;">1</span>
                            <h6 class="m-0 font-weight-bold text-gray-900" style="font-size: 15px;">Informasi Pelanggan</h6>
                        </div>
                        <span class="text-muted small"><i class="fas fa-id-card text-primary"></i></span>
                    </div>
                    <div class="card-body p-3 p-md-4">
                        <!-- Customer Type Toggle -->
                        <div class="form-group mb-3">
                            <label class="font-weight-bold text-xs text-uppercase text-gray-600 tracking-wider mb-2 d-block">Tipe Pelanggan</label>
                            <div class="btn-group btn-group-toggle w-100 fnb-segmented-toggle" data-toggle="buttons">
                                <label class="btn btn-outline-primary active w-50 py-2 font-weight-bold" id="labelCustomerPlaying">
                                    <input type="radio" name="customer_type" id="customerTypePlaying" value="playing" checked autocomplete="off">
                                    <i class="fas fa-gamepad mr-1"></i> Aktif Bermain
                                </label>
                                <label class="btn btn-outline-primary w-50 py-2 font-weight-bold" id="labelCustomerNonPlaying">
                                    <input type="radio" name="customer_type" id="customerTypeNonPlaying" value="non_playing" autocomplete="off">
                                    <i class="fas fa-coffee mr-1"></i> Non-Bermain
                                </label>
                            </div>
                        </div>

                        <!-- Section: Aktif Bermain -->
                        <div id="sectionCustomerPlaying">
                            <div class="form-group mb-3">
                                <div class="d-flex align-items-center justify-content-between mb-1">
                                    <label for="transaction_id" class="font-weight-bold text-gray-800 mb-0">
                                        Sesi Bermain Aktif <span class="text-danger">*</span>
                                    </label>
                                    <span class="badge badge-light border text-success font-weight-500" style="font-size: 11px;">
                                        <i class="fas fa-circle mr-1" style="font-size: 7px;"></i> Active Sync
                                    </span>
                                </div>
                                <select class="form-control" name="transaction_id" id="transaction_id">
                                    @php
                                        $selectedTx = $activeTransactions->firstWhere('id_transaksi', old('transaction_id'));
                                        $selectedIsPaket = $selectedTx && ($selectedTx->tipe_transaksi !== 'postpaid');
                                        $selectedPaymentStatus = $selectedTx ? strtolower((string) ($selectedTx->payment_status ?? 'unpaid')) : 'unpaid';
                                    @endphp
                                    <option value="" data-type="" data-billing-name="" data-location-id="" data-payment-status="">-- Pilih Sesi Bermain Aktif --</option>
                                    @foreach($activeTransactions as $tx)
                                        @php
                                            $isPaket = ($tx->tipe_transaksi !== 'postpaid');
                                            $txTypeLabel = $isPaket ? 'Paket' : 'Lost Time';
                                            $matchingLoc = $locations->firstWhere('device_id', $tx->device_id);
                                            $txPaymentStatus = strtolower((string) ($tx->payment_status ?? 'unpaid'));
                                        @endphp
                                        <option value="{{ $tx->id_transaksi }}"
                                            data-type="{{ $tx->tipe_transaksi }}"
                                            data-is-paket="{{ $isPaket ? '1' : '0' }}"
                                            data-payment-status="{{ $txPaymentStatus }}"
                                            data-billing-name="{{ $tx->nama }}"
                                            data-location-id="{{ $matchingLoc ? $matchingLoc->id : '' }}"
                                            {{ old('transaction_id') == $tx->id_transaksi ? 'selected' : '' }}>
                                            {{ $tx->device ? $tx->device->nama : 'Station' }} — #{{ $tx->id_transaksi }} ({{ $tx->nama }}) [{{ $txTypeLabel }}]
                                        </option>
                                    @endforeach
                                </select>
                                <small class="form-text text-muted">Hanya menampilkan rental dengan billing yang sedang berjalan.</small>
                            </div>

                            <div class="form-group mb-3">
                                <label class="font-weight-bold text-gray-800 mb-1">Penanggung Jawab Tagihan (Billing)</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text bg-light border-right-0 text-muted"><i class="fas fa-shield-alt text-primary"></i></span>
                                    </div>
                                    <input type="text" class="form-control bg-light border-left-0" id="billing_name_display" readonly placeholder="Otomatis terisi dari nama sesi rental...">
                                </div>
                            </div>

                            <!-- Dynamic Session Payment Notices -->
                            <div id="noticePaket" class="alert alert-pos alert-paket d-none mb-3" role="alert">
                                <div class="d-flex align-items-start">
                                    <i class="fas fa-info-circle mr-2 mt-1 fa-lg text-primary"></i>
                                    <div>
                                        <strong id="noticePaketTitle">{{ ($selectedIsPaket && $selectedPaymentStatus === 'paid') ? 'Sesi Paket — Sudah Lunas' : 'Sesi Paket — Belum Lunas' }}</strong> — <span id="noticePaketText">{{ ($selectedIsPaket && $selectedPaymentStatus === 'paid') ? 'Biaya bermain sudah lunas. Pesanan F&B menjadi tagihan tambahan yang terpisah dari biaya bermain dan dapat dibayar sesuai pilihan pembayaran yang tersedia.' : 'Biaya bermain belum dibayar. Pesanan F&B dapat digabungkan dengan tagihan bermain untuk pembayaran di akhir sesi, atau dibayar secara terpisah sesuai pilihan pembayaran yang tersedia.' }}</span>
                                    </div>
                                </div>
                            </div>

                            <div id="noticeLostTime" class="alert alert-pos alert-lost-time d-none mb-3" role="alert">
                                <div class="d-flex align-items-start">
                                    <i class="fas fa-clock mr-2 mt-1 fa-lg text-primary"></i>
                                    <div>
                                        <strong>Sesi Lost Time</strong> — F&amp;B dapat ditagihkan saat checkout permainan atau dibayar langsung sekarang.
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Section: Non-Bermain -->
                        <div id="sectionCustomerNonPlaying" class="d-none">
                            <div class="form-group mb-3">
                                <label class="font-weight-bold text-xs text-uppercase text-gray-600 tracking-wider mb-2 d-block">Tipe Layanan</label>
                                <div class="btn-group btn-group-toggle w-100 fnb-segmented-toggle fnb-segmented-toggle-secondary" data-toggle="buttons">
                                    <label class="btn btn-outline-secondary active w-50 py-2 font-weight-bold">
                                        <input type="radio" name="dining_option" id="diningDineIn" value="dine_in" checked autocomplete="off">
                                        <i class="fas fa-utensils mr-1"></i> Dine-in
                                    </label>
                                    <label class="btn btn-outline-secondary w-50 py-2 font-weight-bold">
                                        <input type="radio" name="dining_option" id="diningTakeaway" value="takeaway" autocomplete="off">
                                        <i class="fas fa-shopping-bag mr-1"></i> Takeaway
                                    </label>
                                </div>
                            </div>

                            <div id="noticeNonPlaying" class="alert alert-pos alert-non-playing mb-3" role="alert">
                                <div class="d-flex align-items-start">
                                    <i class="fas fa-exclamation-triangle mr-2 mt-1 fa-lg text-warning"></i>
                                    <div>
                                        <strong>Pelanggan Non-Bermain</strong> — Pesanan wajib dibayar di muka di kasir sebelum diproses dapur.
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Common Customer Fields -->
                        <div class="form-group mb-3">
                            <label for="customer_name" class="font-weight-bold text-gray-800 mb-1" id="labelCustomerName">
                                Nama Pemesan <span id="customerNameReqStar" class="text-danger d-none">*</span>
                            </label>
                            <input type="text" class="form-control" name="customer_name" id="customer_name"
                                value="{{ old('customer_name') }}"
                                placeholder="Nama pemesan jika berbeda dari billing">
                            <small class="form-text text-muted" id="customerNameHint">Boleh dikosongkan jika sesuai nama penanggung jawab billing.</small>
                        </div>

                        <div class="form-group mb-3">
                            <label for="location_id" class="font-weight-bold text-gray-800 mb-1">Lokasi / Station / Meja</label>
                            <select class="form-control" name="location_id" id="location_id">
                                <option value="">-- Pilih Lokasi (Opsional) --</option>
                                @foreach($locations as $loc)
                                    <option value="{{ $loc->id }}" data-device-id="{{ $loc->device_id ?? '' }}" {{ old('location_id') == $loc->id ? 'selected' : '' }}>
                                        {{ $loc->name }} {{ $loc->code ? '('.$loc->code.')' : '' }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="form-group mb-0">
                            <label for="notes" class="font-weight-bold text-gray-800 mb-1">Catatan Pesanan Keseluruhan</label>
                            <textarea class="form-control" name="notes" id="notes" rows="2" placeholder="Catatan dapur (misal: diantar sekaligus)...">{{ old('notes') }}</textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: F&B Items Selection & Actions (approx 65% on Desktop) -->
            <div class="col-xl-8 col-lg-7 mb-4">
                <div class="card fnb-pos-card mb-4">
                    <div class="card-header py-3 bg-white d-flex flex-wrap justify-content-between align-items-center">
                        <div class="d-flex align-items-center flex-wrap my-1 mr-2">
                            <span class="badge badge-primary px-2 py-1 mr-2 font-weight-bold" style="font-size: 13px;">2</span>
                            <h6 class="m-0 font-weight-bold text-gray-900 mr-2" style="font-size: 15px;">Item Pesanan F&amp;B</h6>
                            <span id="orderItemCountBadge" class="badge badge-light border text-primary font-weight-bold" style="font-size: 11px;">1 Menu Dipilih</span>
                        </div>
                        <button type="button" class="btn btn-sm btn-primary font-weight-bold shadow-sm my-1" id="btnAddRow">
                            <i class="fas fa-plus-circle mr-1"></i> Tambah Baris Menu
                        </button>
                    </div>
                    <div class="card-body p-3 p-md-4">
                        <div class="table-responsive fnb-table-container mb-4">
                            <table class="table table-hover align-middle mb-0" id="tableFnbItems">
                                <thead class="thead-light">
                                    <tr>
                                        <th style="min-width: 240px;">Menu F&amp;B</th>
                                        <th class="text-right" style="width: 115px;">Harga</th>
                                        <th class="text-center" style="width: 75px;">Stok</th>
                                        <th class="text-center" style="width: 135px;">Jumlah</th>
                                        <th style="min-width: 170px;">Catatan Item</th>
                                        <th class="text-right" style="width: 120px;">Subtotal</th>
                                        <th class="text-center" style="width: 45px;"><i class="fas fa-cog text-muted"></i></th>
                                    </tr>
                                </thead>
                                <tbody id="fnbItemsBody">
                                    <!-- Dynamic rows will be rendered here -->
                                </tbody>
                            </table>
                        </div>

                        <!-- Quick Inline Info / Hint Bar -->
                        <div class="p-2 mb-4 bg-light rounded d-flex align-items-center justify-content-between border text-muted small">
                            <div class="d-flex align-items-center">
                                <i class="fas fa-info-circle mr-2 text-primary"></i>
                                <span>Pilih menu melalui dropdown di setiap baris atau tambah baris menu baru untuk variasi lain.</span>
                            </div>
                            <span class="badge badge-white border text-muted font-weight-normal px-2 py-1 d-none d-sm-inline-block">POS Mode</span>
                        </div>

                        <!-- Grand Total Ringkasan & Terminal -->
                        <div class="card-summary-terminal mb-4 p-3 rounded-lg border bg-light">
                            <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-receipt text-primary mr-2"></i>
                                    <h6 class="font-weight-bold text-gray-900 mb-0">Ringkasan Pesanan (Order Summary)</h6>
                                </div>
                                <span class="badge badge-light border text-muted px-2 py-1 font-weight-500">Order Entry</span>
                            </div>
                            <div class="row align-items-center">
                                <div class="col-md-6 mb-3 mb-md-0">
                                    <div class="p-3 bg-white rounded border">
                                        <div class="d-flex justify-content-between align-items-center mb-1 text-muted small">
                                            <span>Jumlah Variasi Item:</span>
                                            <strong class="text-gray-900" id="summaryItemCount">0 Jenis Menu</strong>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center mb-1 text-muted small">
                                            <span>Total Kuantitas Porsi:</span>
                                            <strong class="text-gray-900" id="summaryTotalQty">0 Porsi</strong>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center text-muted small pt-1 border-top">
                                            <span>Subtotal F&amp;B:</span>
                                            <strong class="text-primary font-weight-bold" id="summarySubtotal">Rp 0</strong>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="p-3 rounded border text-center text-md-right grand-total-highlight-box bg-white">
                                        <span class="text-xs text-uppercase font-weight-bold text-primary tracking-wider d-block mb-1">Total Tagihan F&amp;B</span>
                                        <span class="h3 font-weight-bold text-gray-900 mb-0 d-block" id="displayGrandTotal">Rp 0</span>
                                        <small class="text-muted mt-1 d-block" style="font-size: 11px;">
                                            <i class="fas fa-info-circle text-info mr-1"></i> Belum termasuk tagihan durasi main
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Action Area & Dynamic Buttons -->
                        <div class="mt-4 pt-3 border-top">
                            @if(!auth()->user()->isOwner())
                                <!-- Guidance / Helper Text for Kasir / Kasir F&B / Admin -->
                                <div id="actionHelperNotice" class="alert alert-pos alert-helper py-2 px-3 small mb-3 d-flex align-items-center">
                                    <i class="fas fa-info-circle mr-2 fa-lg text-info"></i>
                                    <span id="actionHelperText">Sesi Paket dapat dilayani langsung. Pembayaran dapat dilakukan saat staf mengantar makanan atau setelah sesi bermain selesai.</span>
                                </div>

                                <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center">
                                    <!-- Secondary Actions -->
                                    <div class="mb-2 mb-sm-0" id="secondaryActionContainer">
                                        <!-- Shown for Non-Bermain: Simpan Pending -->
                                        <button type="submit" name="action" value="save" id="btnSecondarySavePending" class="btn btn-outline-secondary font-weight-bold px-3 py-2 shadow-sm">
                                            <i class="fas fa-clock mr-1"></i> <span id="labelSecondarySave">Simpan Pending (Bayar Nanti)</span>
                                        </button>
                                        <!-- Shown for Lost Time & Paket: Bayar Sekarang -->
                                        <button type="button" id="btnSecondaryPayEarly" class="btn btn-outline-success font-weight-bold px-3 py-2 shadow-sm d-none" onclick="openPaymentSummaryModal()">
                                            <i class="fas fa-hand-holding-usd mr-1"></i> <span id="labelSecondaryPay">Bayar Sekarang</span>
                                        </button>
                                    </div>

                                    <!-- Primary Actions -->
                                    <div id="primaryActionContainer">
                                        <!-- Shown for Lost Time & Paket: Simpan Transaksi -->
                                        <button type="submit" name="action" value="save" id="btnPrimarySave" class="btn btn-primary font-weight-bold px-4 py-2 shadow-sm">
                                            <i class="fas fa-save mr-1"></i> Simpan Transaksi
                                        </button>
                                        <!-- Shown for Non-Bermain: Bayar dan Proses -->
                                        <button type="button" id="btnPrimaryPayNow" class="btn btn-success font-weight-bold px-4 py-2 shadow-sm d-none" onclick="openPaymentSummaryModal()">
                                            <i class="fas fa-cash-register mr-1"></i> <span id="labelPrimaryPay">Bayar dan Proses</span>
                                        </button>
                                    </div>
                                </div>
                            @else
                                <div class="alert alert-secondary py-2 px-3 small mb-0">
                                    <i class="fas fa-eye mr-1"></i> Akun Owner hanya dapat memantau menu transaksi F&amp;B.
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @if(!auth()->user()->isOwner())
        <!-- Payment Summary Modal -->
        <div class="modal fade" id="modalPaymentSummary" tabindex="-1" role="dialog" aria-labelledby="modalPaymentSummaryLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
                <div class="modal-content shadow-lg border-0" style="border-radius: 12px; overflow: hidden;">
                    <div class="modal-header bg-primary text-white py-3">
                        <h5 class="modal-title font-weight-bold" id="modalPaymentSummaryLabel">
                            <i class="fas fa-receipt mr-1"></i> Ringkasan Pembayaran F&amp;B
                        </h5>
                        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body p-4">
                        <!-- Identitas Pelanggan & Sesi -->
                        <div class="card bg-light border-0 mb-3">
                            <div class="card-body p-3">
                                <h6 class="font-weight-bold text-gray-800 mb-2">
                                    <i class="fas fa-user-tag mr-1 text-primary"></i> Identitas Pelanggan &amp; Sesi
                                </h6>
                                <div class="row small">
                                    <div class="col-md-4 mb-2 mb-md-0">
                                        <span class="text-muted d-block">Tipe Pelanggan:</span>
                                        <strong id="modalSummaryCustomerType" class="text-gray-800">-</strong>
                                    </div>
                                    <div class="col-md-4 mb-2 mb-md-0">
                                        <span class="text-muted d-block">Station / Meja:</span>
                                        <strong id="modalSummaryLocation" class="text-gray-800">-</strong>
                                    </div>
                                    <div class="col-md-4">
                                        <span class="text-muted d-block">Nama Pemesan / Billing:</span>
                                        <strong id="modalSummaryCustomerName" class="text-gray-800">-</strong>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Rincian Produk Pesanan -->
                        <div class="mb-3">
                            <h6 class="font-weight-bold text-gray-800 mb-2">
                                <i class="fas fa-utensils mr-1 text-primary"></i> Rincian Produk
                            </h6>
                            <div class="table-responsive" style="max-height: 180px; overflow-y: auto;">
                                <table class="table table-sm table-bordered mb-0">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>Menu</th>
                                            <th class="text-center" style="width: 70px;">Qty</th>
                                            <th class="text-right" style="width: 120px;">Harga Satuan</th>
                                            <th class="text-right" style="width: 130px;">Subtotal</th>
                                        </tr>
                                    </thead>
                                    <tbody id="modalOrderItemsBody">
                                        <!-- Rendered dynamically -->
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Rincian Pembayaran -->
                        <div class="list-group list-group-flush mb-3">
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                <span class="text-muted font-weight-bold">Total Tagihan F&amp;B:</span>
                                <span class="font-weight-bold text-gray-800 h6 mb-0" id="modalSubtotalDisplay">Rp 0</span>
                            </div>
                            <div class="list-group-item px-0">
                                <div class="form-group mb-2">
                                    <label for="payment_method" class="font-weight-bold text-gray-800">Metode Pembayaran</label>
                                    <select class="form-control" name="payment_method" id="payment_method">
                                        <option value="tunai">Tunai</option>
                                        <option value="e-wallet">E-Wallet (QRIS)</option>
                                        <option value="transfer_bank">Transfer Bank</option>
                                    </select>
                                </div>
                            </div>
                            <div class="list-group-item px-0">
                                <div class="form-group mb-2">
                                    <label for="discount" class="font-weight-bold text-gray-800">Diskon (%)</label>
                                    <input type="number" class="form-control" name="discount" id="discount" min="0" max="100" value="0">
                                </div>
                            </div>
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0 bg-light p-2 rounded">
                                <span class="font-weight-bold text-gray-800">Total Setelah Diskon:</span>
                                <span class="font-weight-bold text-success h5 mb-0" id="modalFinalTotalDisplay">Rp 0</span>
                            </div>
                            <div class="list-group-item px-0 border-bottom-0">
                                <div class="form-group mb-2">
                                    <label for="amount_paid" class="font-weight-bold text-gray-800">Nominal Dibayar (Rp)</label>
                                    <input type="number" class="form-control form-control-lg font-weight-bold" name="amount_paid" id="amount_paid" min="0" placeholder="0">
                                </div>
                                <div class="d-flex flex-wrap gap-1 mb-2">
                                    <button type="button" class="btn btn-sm btn-outline-secondary mr-1 mb-1 btn-quick-cash" data-mode="exact">Uang Pas</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary mr-1 mb-1 btn-quick-cash" data-amount="10000">10.000</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary mr-1 mb-1 btn-quick-cash" data-amount="20000">20.000</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary mr-1 mb-1 btn-quick-cash" data-amount="50000">50.000</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary mr-1 mb-1 btn-quick-cash" data-amount="100000">100.000</button>
                                </div>
                            </div>
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0 pt-2 border-top">
                                <span class="h6 mb-0 font-weight-bold text-gray-800">Kembalian:</span>
                                <span class="h5 mb-0 font-weight-bold text-primary" id="modalChangeDisplay">Rp 0</span>
                            </div>
                        </div>

                        <div id="modalPayAlert" class="alert alert-danger py-2 px-3 d-none mb-0 font-weight-bold small">
                            <i class="fas fa-exclamation-circle mr-1"></i> Nominal bayar kurang dari total tagihan!
                        </div>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary font-weight-bold" data-dismiss="modal">Batal</button>
                        <button type="submit" name="action" value="pay_now" id="btnSubmitPayNow" class="btn btn-success shadow-sm font-weight-bold">
                            <i class="fas fa-check-circle mr-1"></i> Konfirmasi Pembayaran &amp; Cetak Struk
                        </button>
                    </div>
                </div>
            </div>
        </div>
        @endif
    </form>
</div>
@endsection

@section('scripts')
<script>
    const fnbCatalog = @json($fnbs);
    const placeholderThumb = "{{ asset('img/fnb-placeholder.svg') }}";

    function formatRupiah(number) {
        return 'Rp ' + Number(number).toLocaleString('id-ID');
    }

    document.addEventListener('DOMContentLoaded', function () {
        let rowIndex = 0;
        const tbody = document.getElementById('fnbItemsBody');
        const btnAddRow = document.getElementById('btnAddRow');
        const displayGrandTotal = document.getElementById('displayGrandTotal');

        const customerTypePlaying = document.getElementById('customerTypePlaying');
        const customerTypeNonPlaying = document.getElementById('customerTypeNonPlaying');
        const sectionPlaying = document.getElementById('sectionCustomerPlaying');
        const sectionNonPlaying = document.getElementById('sectionCustomerNonPlaying');

        const txSelect = document.getElementById('transaction_id');
        const billingNameDisplay = document.getElementById('billing_name_display');
        const locationSelect = document.getElementById('location_id');
        const customerNameInput = document.getElementById('customer_name');
        const customerNameReqStar = document.getElementById('customerNameReqStar');
        const customerNameHint = document.getElementById('customerNameHint');
        const noticePaket = document.getElementById('noticePaket');
        const noticeLostTime = document.getElementById('noticeLostTime');

        // Dynamic Action Buttons Logic
        window.updateDynamicActionButtons = function () {
            const isPlaying = customerTypePlaying ? customerTypePlaying.checked : true;
            let isLostTime = false;
            let isPaket = false;

            if (isPlaying && txSelect) {
                const selectedOpt = txSelect.options[txSelect.selectedIndex];
                if (selectedOpt && selectedOpt.value) {
                    const txType = selectedOpt.getAttribute('data-type');
                    if (txType === 'postpaid') {
                        isLostTime = true;
                    } else {
                        isPaket = true;
                    }
                }
            }

            // Elements for Action Buttons
            const btnPrimarySave = document.getElementById('btnPrimarySave');
            const btnPrimaryPayNow = document.getElementById('btnPrimaryPayNow');
            const btnSecondarySavePending = document.getElementById('btnSecondarySavePending');
            const btnSecondaryPayEarly = document.getElementById('btnSecondaryPayEarly');
            const labelSecondarySave = document.getElementById('labelSecondarySave');
            const labelSecondaryPay = document.getElementById('labelSecondaryPay');
            const labelPrimaryPay = document.getElementById('labelPrimaryPay');
            const actionHelperText = document.getElementById('actionHelperText');

            if (btnPrimarySave && btnPrimaryPayNow) {
                if (isPlaying && isLostTime) {
                    // Case A: Aktif Bermain — Lost Time
                    btnPrimarySave.classList.remove('d-none');
                    btnPrimaryPayNow.classList.add('d-none');
                    if (btnSecondaryPayEarly) {
                        btnSecondaryPayEarly.classList.remove('d-none');
                        if (labelSecondaryPay) labelSecondaryPay.innerText = 'Bayar Sekarang Lebih Awal';
                    }
                    if (btnSecondarySavePending) btnSecondarySavePending.classList.add('d-none');
                    if (actionHelperText) {
                        actionHelperText.innerText = 'Tagihan F&B akan dicatat ke meja dan dilunasi bersama billing main saat checkout.';
                    }
                } else if (isPlaying) {
                    // Case B: Aktif Bermain — Paket (or session not yet picked)
                    btnPrimarySave.classList.remove('d-none');
                    btnPrimaryPayNow.classList.add('d-none');
                    if (btnSecondaryPayEarly) {
                        btnSecondaryPayEarly.classList.remove('d-none');
                        if (labelSecondaryPay) labelSecondaryPay.innerText = 'Bayar Sekarang';
                    }
                    if (btnSecondarySavePending) btnSecondarySavePending.classList.add('d-none');
                    if (actionHelperText) {
                        actionHelperText.innerText = 'Sesi Paket dapat dilayani langsung. Pembayaran dapat dilakukan saat staf mengantar makanan atau setelah sesi bermain selesai.';
                    }
                } else {
                    // Case C: Non-Bermain
                    btnPrimarySave.classList.add('d-none');
                    btnPrimaryPayNow.classList.remove('d-none');
                    if (labelPrimaryPay) labelPrimaryPay.innerText = 'Bayar dan Proses';
                    if (btnSecondaryPayEarly) btnSecondaryPayEarly.classList.add('d-none');
                    if (btnSecondarySavePending) {
                        btnSecondarySavePending.classList.remove('d-none');
                        if (labelSecondarySave) labelSecondarySave.innerText = 'Simpan Pending (Bayar Nanti)';
                    }
                    if (actionHelperText) {
                        actionHelperText.innerText = 'Pelanggan Non-Bermain dapat langsung bayar di kasir F&B dan pesanan langsung diproses dapur.';
                    }
                }
            }
        };

        // Customer Type Switch Handler
        function handleCustomerTypeChange() {
            if (customerTypePlaying.checked) {
                sectionPlaying.classList.remove('d-none');
                sectionNonPlaying.classList.add('d-none');
                customerNameReqStar.classList.add('d-none');
                customerNameInput.removeAttribute('required');
                customerNameInput.placeholder = "Nama pemesan jika berbeda dari billing";
                customerNameHint.innerText = "Boleh dikosongkan jika sesuai nama penanggung jawab billing.";
                handleSessionChange();
            } else {
                sectionPlaying.classList.add('d-none');
                sectionNonPlaying.classList.remove('d-none');
                customerNameReqStar.classList.remove('d-none');
                customerNameInput.setAttribute('required', 'required');
                customerNameInput.placeholder = "Nama pemesan (contoh: Mas Agus / Walk-in)";
                customerNameHint.innerText = "Wajib diisi untuk pelanggan non-bermain.";
                noticePaket.classList.add('d-none');
                noticeLostTime.classList.add('d-none');
                billingNameDisplay.value = '';
            }
            window.updateDynamicActionButtons();
        }

        customerTypePlaying.addEventListener('change', handleCustomerTypeChange);
        customerTypeNonPlaying.addEventListener('change', handleCustomerTypeChange);

        // Session Select Handler
        function handleSessionChange() {
            if (!customerTypePlaying.checked) return;

            const selected = txSelect.options[txSelect.selectedIndex];
            if (!selected || !selected.value) {
                billingNameDisplay.value = '';
                noticePaket.classList.add('d-none');
                noticeLostTime.classList.add('d-none');
                window.updateDynamicActionButtons();
                return;
            }

            const billingName = selected.getAttribute('data-billing-name') || '';
            const isPaket = selected.getAttribute('data-is-paket') === '1';
            const locationId = selected.getAttribute('data-location-id') || '';

            billingNameDisplay.value = billingName;

            if (locationId) {
                locationSelect.value = locationId;
            }

            if (isPaket) {
                const paymentStatus = (selected.getAttribute('data-payment-status') || '').toLowerCase();
                const noticePaketTitle = document.getElementById('noticePaketTitle');
                const noticePaketText = document.getElementById('noticePaketText');

                if (paymentStatus === 'paid') {
                    if (noticePaketTitle) noticePaketTitle.innerText = 'Sesi Paket — Sudah Lunas';
                    if (noticePaketText) noticePaketText.innerText = 'Biaya bermain sudah lunas. Pesanan F&B menjadi tagihan tambahan yang terpisah dari biaya bermain dan dapat dibayar sesuai pilihan pembayaran yang tersedia.';
                } else if (paymentStatus === 'unpaid') {
                    if (noticePaketTitle) noticePaketTitle.innerText = 'Sesi Paket — Belum Lunas';
                    if (noticePaketText) noticePaketText.innerText = 'Biaya bermain belum dibayar. Pesanan F&B dapat digabungkan dengan tagihan bermain untuk pembayaran di akhir sesi, atau dibayar secara terpisah sesuai pilihan pembayaran yang tersedia.';
                } else {
                    const statusLabel = paymentStatus ? paymentStatus.toUpperCase() : 'BELUM TERVERIFIKASI';
                    if (noticePaketTitle) noticePaketTitle.innerText = 'Sesi Paket — ' + statusLabel;
                    if (noticePaketText) noticePaketText.innerText = 'Status pembayaran sesi bermain: ' + statusLabel + '. Pesanan F&B dapat dibayar sesuai pilihan pembayaran yang tersedia.';
                }

                noticePaket.classList.remove('d-none');
                noticeLostTime.classList.add('d-none');
            } else {
                noticePaket.classList.add('d-none');
                noticeLostTime.classList.remove('d-none');
            }

            window.updateDynamicActionButtons();
        }

        txSelect.addEventListener('change', handleSessionChange);

        // Add Row Function
        window.createFnbRow = function (initialFnbId = null, initialQty = 1) {
            const tr = document.createElement('tr');
            tr.className = 'fnb-row';
            tr.dataset.index = rowIndex;

            let optionsHtml = '<option value="" data-price="0" data-stock="0" data-thumb="' + placeholderThumb + '">-- Pilih Menu F&amp;B --</option>';
            fnbCatalog.forEach(function (item) {
                const isSelected = initialFnbId && Number(initialFnbId) === Number(item.id) ? 'selected' : '';
                const stockLabel = item.stok === -1 ? '[Unlimited]' : '[Stok: ' + item.stok + ']';
                const thumb = item.image ? ('/storage/' + item.image) : placeholderThumb;
                optionsHtml += `<option value="${item.id}" data-price="${item.effective_price}" data-stock="${item.stok}" data-thumb="${thumb}" ${isSelected}>
                    ${item.nama} - Rp ${Number(item.effective_price).toLocaleString('id-ID')} ${stockLabel}
                </option>`;
            });

            tr.innerHTML = `
                <td class="align-middle">
                    <div class="d-flex align-items-center">
                        <img src="${placeholderThumb}" class="row-thumb rounded border mr-2 flex-shrink-0" style="width: 38px; height: 38px; object-fit: cover;" alt="thumb">
                        <select name="items[${rowIndex}][fnb_id]" class="form-control form-control-sm fnb-select" required style="min-width: 180px;">
                            ${optionsHtml}
                        </select>
                    </div>
                </td>
                <td class="align-middle text-right text-nowrap"><span class="row-price font-weight-bold text-gray-800">Rp 0</span></td>
                <td class="align-middle text-center"><span class="badge badge-secondary row-stock">-</span></td>
                <td class="align-middle">
                    <div class="input-group input-group-sm mx-auto" style="width: 115px;">
                        <div class="input-group-prepend">
                            <button type="button" class="btn btn-outline-secondary btn-step-down px-2"><i class="fas fa-minus"></i></button>
                        </div>
                        <input type="number" name="items[${rowIndex}][qty]" class="form-control text-center item-qty font-weight-bold px-1" value="${initialQty}" min="1" max="999" required>
                        <div class="input-group-append">
                            <button type="button" class="btn btn-outline-secondary btn-step-up px-2"><i class="fas fa-plus"></i></button>
                        </div>
                    </div>
                </td>
                <td class="align-middle">
                    <div class="input-group input-group-sm">
                        <div class="input-group-prepend">
                            <span class="input-group-text bg-white text-muted px-2"><i class="fas fa-edit" style="font-size: 11px;"></i></span>
                        </div>
                        <input type="text" name="items[${rowIndex}][notes]" class="form-control form-control-sm item-notes" placeholder="Catatan (e.g. Pedas)">
                    </div>
                </td>
                <td class="align-middle text-right text-nowrap"><span class="row-subtotal font-weight-bold text-gray-900">Rp 0</span></td>
                <td class="align-middle text-center">
                    <button type="button" class="btn btn-sm btn-outline-danger btn-remove-row" title="Hapus"><i class="fas fa-trash-alt"></i></button>
                </td>
            `;

            tbody.appendChild(tr);

            const selectEl = tr.querySelector('.fnb-select');
            const qtyInput = tr.querySelector('.item-qty');
            const btnStepDown = tr.querySelector('.btn-step-down');
            const btnStepUp = tr.querySelector('.btn-step-up');
            const btnRemove = tr.querySelector('.btn-remove-row');

            selectEl.addEventListener('change', function () {
                updateRow(tr);
                calculateGrandTotal();
            });

            qtyInput.addEventListener('input', function () {
                let val = parseInt(qtyInput.value);
                const max = parseInt(qtyInput.getAttribute('max'));
                if (val < 1 || isNaN(val)) {
                    qtyInput.value = 1;
                } else if (max && val > max) {
                    qtyInput.value = max;
                    alert('Jumlah melebihi stok yang tersedia (' + max + ')');
                }
                updateRow(tr);
                calculateGrandTotal();
            });

            btnStepDown.addEventListener('click', function () {
                let current = parseInt(qtyInput.value) || 1;
                if (current > 1) {
                    qtyInput.value = current - 1;
                    updateRow(tr);
                    calculateGrandTotal();
                }
            });

            btnStepUp.addEventListener('click', function () {
                let current = parseInt(qtyInput.value) || 1;
                const max = parseInt(qtyInput.getAttribute('max'));
                if (max && current >= max) {
                    alert('Jumlah sudah mencapai batas stok (' + max + ')');
                    return;
                }
                qtyInput.value = current + 1;
                updateRow(tr);
                calculateGrandTotal();
            });

            btnRemove.addEventListener('click', function () {
                if (document.querySelectorAll('.fnb-row').length > 1) {
                    tr.remove();
                    calculateGrandTotal();
                } else {
                    selectEl.value = '';
                    qtyInput.value = 1;
                    updateRow(tr);
                    calculateGrandTotal();
                }
            });

            if (initialFnbId) {
                selectEl.value = initialFnbId;
            }
            updateRow(tr);
            calculateGrandTotal();

            rowIndex++;
        };

        function updateRow(tr) {
            const selectEl = tr.querySelector('.fnb-select');
            const thumbEl = tr.querySelector('.row-thumb');
            const priceEl = tr.querySelector('.row-price');
            const stockEl = tr.querySelector('.row-stock');
            const qtyInput = tr.querySelector('.item-qty');
            const subtotalEl = tr.querySelector('.row-subtotal');

            const selected = selectEl.options[selectEl.selectedIndex];
            if (!selected || !selected.value) {
                thumbEl.src = placeholderThumb;
                priceEl.innerText = 'Rp 0';
                stockEl.innerText = '-';
                stockEl.className = 'badge badge-secondary row-stock';
                subtotalEl.innerText = 'Rp 0';
                return;
            }

            const price = parseFloat(selected.getAttribute('data-price')) || 0;
            const stock = parseInt(selected.getAttribute('data-stock'));
            const thumb = selected.getAttribute('data-thumb') || placeholderThumb;
            const qty = parseInt(qtyInput.value) || 1;

            thumbEl.src = thumb;
            priceEl.innerText = formatRupiah(price);

            if (stock === -1) {
                stockEl.innerText = 'Unlimited';
                stockEl.className = 'badge badge-info row-stock';
                qtyInput.removeAttribute('max');
            } else {
                stockEl.innerText = stock;
                stockEl.className = 'badge ' + (stock < 5 ? 'badge-warning' : 'badge-success') + ' row-stock';
                qtyInput.setAttribute('max', stock);
            }

            const subtotal = price * qty;
            subtotalEl.innerText = formatRupiah(subtotal);
        }

        window.calculateGrandTotal = function () {
            let total = 0;
            let distinctItemCount = 0;
            let totalPortionQty = 0;

            document.querySelectorAll('.fnb-row').forEach(function (tr) {
                const selectEl = tr.querySelector('.fnb-select');
                const qtyInput = tr.querySelector('.item-qty');
                if (selectEl && selectEl.value) {
                    distinctItemCount++;
                    const selected = selectEl.options[selectEl.selectedIndex];
                    const price = parseFloat(selected.getAttribute('data-price')) || 0;
                    const qty = parseInt(qtyInput.value) || 0;
                    totalPortionQty += qty;
                    total += (price * qty);
                }
            });

            displayGrandTotal.innerText = formatRupiah(total);

            const summaryItemCount = document.getElementById('summaryItemCount');
            const summaryTotalQty = document.getElementById('summaryTotalQty');
            const summarySubtotal = document.getElementById('summarySubtotal');
            const orderItemCountBadge = document.getElementById('orderItemCountBadge');

            if (summaryItemCount) summaryItemCount.innerText = distinctItemCount + ' Jenis Menu';
            if (summaryTotalQty) summaryTotalQty.innerText = totalPortionQty + ' Porsi';
            if (summarySubtotal) summarySubtotal.innerText = formatRupiah(total);
            if (orderItemCountBadge) orderItemCountBadge.innerText = (distinctItemCount > 0 ? distinctItemCount : 1) + ' Menu Dipilih';

            return total;
        };

        btnAddRow.addEventListener('click', function () {
            window.createFnbRow();
        });

        // Initialize with first row
        window.createFnbRow();

        // If there were old items from validation error
        handleCustomerTypeChange();
        window.updateDynamicActionButtons();

        // Double-Submit Prevention & Loading States
        const form = document.getElementById('formFnbTransaction');
        let isSubmitting = false;
        let lastClickedSubmitButton = null;

        form.querySelectorAll('button[type="submit"]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                lastClickedSubmitButton = this;
            });
        });

        form.addEventListener('submit', function (e) {
            if (isSubmitting) {
                e.preventDefault();
                return false;
            }

            const total = window.calculateGrandTotal();
            if (total <= 0) {
                e.preventDefault();
                alert('Silakan pilih minimal 1 menu F&B terlebih dahulu.');
                return false;
            }

            const action = lastClickedSubmitButton ? lastClickedSubmitButton.value : 'save';
            isSubmitting = true;

            // Ensure action value is submitted even when buttons are disabled
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = action;
            form.appendChild(actionInput);

            // Update loading indicators
            if (action === 'pay_now') {
                const submitPayBtn = document.getElementById('btnSubmitPayNow');
                if (submitPayBtn) {
                    submitPayBtn.disabled = true;
                    submitPayBtn.innerHTML = '<span class="spinner-border spinner-border-sm mr-1" role="status" aria-hidden="true"></span> Memproses Pembayaran...';
                }
            } else {
                if (lastClickedSubmitButton) {
                    lastClickedSubmitButton.disabled = true;
                    lastClickedSubmitButton.innerHTML = '<span class="spinner-border spinner-border-sm mr-1" role="status" aria-hidden="true"></span> Menyimpan Transaksi...';
                }
            }

            // Disable all other submit buttons on page
            form.querySelectorAll('button[type="submit"]').forEach(function (btn) {
                btn.disabled = true;
            });
        });
    });

    // Payment Summary Modal Functionality
    function openPaymentSummaryModal() {
        const selectedItems = [];
        let total = 0;

        document.querySelectorAll('.fnb-row').forEach(function (tr) {
            const selectEl = tr.querySelector('.fnb-select');
            const qtyInput = tr.querySelector('.item-qty');
            if (selectEl && selectEl.value) {
                const selectedOpt = selectEl.options[selectEl.selectedIndex];
                const price = parseFloat(selectedOpt.getAttribute('data-price')) || 0;
                const qty = parseInt(qtyInput.value) || 0;
                if (qty > 0) {
                    const subtotal = price * qty;
                    total += subtotal;
                    const itemRawText = selectedOpt.textContent.trim();
                    const itemName = itemRawText.split(' - Rp ')[0].trim();
                    selectedItems.push({
                        name: itemName,
                        qty: qty,
                        price: price,
                        subtotal: subtotal
                    });
                }
            }
        });

        if (selectedItems.length === 0 || total <= 0) {
            alert('Silakan pilih minimal 1 menu F&B terlebih dahulu.');
            return;
        }

        // Enrich Customer & Session Identity
        const customerTypePlaying = document.getElementById('customerTypePlaying');
        const txSelect = document.getElementById('transaction_id');
        const locationSelect = document.getElementById('location_id');
        const customerNameInput = document.getElementById('customer_name');
        const billingNameDisplay = document.getElementById('billing_name_display');

        const summaryCustomerType = document.getElementById('modalSummaryCustomerType');
        const summaryLocation = document.getElementById('modalSummaryLocation');
        const summaryCustomerName = document.getElementById('modalSummaryCustomerName');

        if (summaryCustomerType) {
            if (customerTypePlaying && customerTypePlaying.checked) {
                const selectedTx = txSelect ? txSelect.options[txSelect.selectedIndex] : null;
                const isPaket = selectedTx && selectedTx.getAttribute('data-is-paket') === '1';
                summaryCustomerType.innerText = 'Aktif Bermain — ' + (isPaket ? 'Paket' : 'Lost Time');
            } else {
                const diningOpt = document.querySelector('input[name="dining_option"]:checked');
                const diningLabel = diningOpt && diningOpt.value === 'takeaway' ? 'Takeaway' : 'Dine-in';
                summaryCustomerType.innerText = 'Non-Bermain — ' + diningLabel;
            }
        }

        if (summaryLocation) {
            if (locationSelect && locationSelect.selectedIndex > 0) {
                summaryLocation.innerText = locationSelect.options[locationSelect.selectedIndex].text.trim();
            } else {
                summaryLocation.innerText = '-';
            }
        }

        if (summaryCustomerName) {
            const custName = customerNameInput ? customerNameInput.value.trim() : '';
            const billingName = billingNameDisplay ? billingNameDisplay.value.trim() : '';
            if (custName && billingName && custName !== billingName) {
                summaryCustomerName.innerText = `${custName} (Billing: ${billingName})`;
            } else {
                summaryCustomerName.innerText = custName || billingName || 'Walk-in Pelanggan';
            }
        }

        // Populate Order Items Table in Modal
        const orderItemsBody = document.getElementById('modalOrderItemsBody');
        if (orderItemsBody) {
            orderItemsBody.innerHTML = '';
            selectedItems.forEach(function (item) {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td class="font-weight-bold text-gray-800">${item.name}</td>
                    <td class="text-center">${item.qty}x</td>
                    <td class="text-right text-nowrap">${formatRupiah(item.price)}</td>
                    <td class="text-right text-nowrap font-weight-bold text-gray-900">${formatRupiah(item.subtotal)}</td>
                `;
                orderItemsBody.appendChild(tr);
            });
        }

        // Modal Calculations & Setup
        const modalSubtotal = document.getElementById('modalSubtotalDisplay');
        const modalFinalTotal = document.getElementById('modalFinalTotalDisplay');
        const modalChange = document.getElementById('modalChangeDisplay');
        const discountInput = document.getElementById('discount');
        const amountPaidInput = document.getElementById('amount_paid');
        const modalAlert = document.getElementById('modalPayAlert');
        const btnSubmit = document.getElementById('btnSubmitPayNow');

        function updateModalTotals() {
            const currentTotal = window.calculateGrandTotal();
            const discountPercent = Math.min(100, Math.max(0, parseFloat(discountInput.value) || 0));
            const discountAmount = Math.round(currentTotal * (discountPercent / 100));
            const finalTotal = Math.max(0, currentTotal - discountAmount);
            const amountPaid = parseFloat(amountPaidInput.value) || 0;
            const change = amountPaid - finalTotal;

            modalSubtotal.innerText = formatRupiah(currentTotal);
            modalFinalTotal.innerText = formatRupiah(finalTotal);

            if (amountPaid > 0 && change < 0) {
                modalChange.innerText = 'Kurang ' + formatRupiah(Math.abs(change));
                modalChange.className = 'h5 mb-0 font-weight-bold text-danger';
                modalAlert.classList.remove('d-none');
            } else {
                modalChange.innerText = formatRupiah(Math.max(0, change));
                modalChange.className = 'h5 mb-0 font-weight-bold text-primary';
                modalAlert.classList.add('d-none');
            }
        }

        discountInput.addEventListener('input', updateModalTotals);
        amountPaidInput.addEventListener('input', updateModalTotals);

        document.querySelectorAll('.btn-quick-cash').forEach(function (btn) {
            btn.onclick = function () {
                const currentTotal = window.calculateGrandTotal();
                const discountPercent = Math.min(100, Math.max(0, parseFloat(discountInput.value) || 0));
                const discountAmount = Math.round(currentTotal * (discountPercent / 100));
                const finalTotal = Math.max(0, currentTotal - discountAmount);

                if (this.dataset.mode === 'exact') {
                    amountPaidInput.value = finalTotal;
                } else if (this.dataset.amount) {
                    amountPaidInput.value = this.dataset.amount;
                }
                updateModalTotals();
            };
        });

        // Set default exact cash if amount_paid is empty
        if (!amountPaidInput.value || parseFloat(amountPaidInput.value) === 0) {
            amountPaidInput.value = total;
        }

        updateModalTotals();
        $('#modalPaymentSummary').modal('show');
    }
</script>
@endsection
