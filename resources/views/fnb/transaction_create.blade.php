@extends('layouts.app', ['title' => $title ?? 'Transaksi F&B', 'active' => 'fnb-transaction'])

@section('content')
<div class="container-fluid">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <div>
            <h1 class="h3 mb-1 text-gray-800">
                <i class="fas fa-cash-register text-primary mr-2"></i>Transaksi F&amp;B
            </h1>
            <p class="text-muted small mb-0">Input pesanan makanan &amp; minuman mandiri untuk pelanggan bermain maupun walk-in.</p>
        </div>
        <a href="{{ route('fnb.queue.index') }}" class="btn btn-sm btn-outline-secondary shadow-sm">
            <i class="fas fa-concierge-bell mr-1"></i> Ke Antrean Kitchen
        </a>
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
            <!-- Left Column: Customer & Session Configuration -->
            <div class="col-lg-5">
                <div class="card shadow mb-4">
                    <div class="card-header py-3 bg-white">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-user-tag mr-1"></i> 1. Jenis Pelanggan &amp; Sesi
                        </h6>
                    </div>
                    <div class="card-body">
                        <!-- Customer Type Toggle -->
                        <div class="form-group">
                            <label class="font-weight-bold d-block text-gray-800">Tipe Pelanggan</label>
                            <div class="btn-group btn-group-toggle w-100" data-toggle="buttons">
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
                            <div class="form-group">
                                <label for="transaction_id" class="font-weight-bold text-gray-800">
                                    Sesi Bermain Aktif <span class="text-danger">*</span>
                                </label>
                                <select class="form-control" name="transaction_id" id="transaction_id">
                                    <option value="" data-type="" data-billing-name="" data-location-id="">-- Pilih Sesi Bermain Aktif --</option>
                                    @foreach($activeTransactions as $tx)
                                        @php
                                            $isPaket = ($tx->tipe_transaksi !== 'postpaid');
                                            $txTypeLabel = $isPaket ? 'Paket' : 'Lost Time';
                                            $matchingLoc = $locations->firstWhere('device_id', $tx->device_id);
                                        @endphp
                                        <option value="{{ $tx->id_transaksi }}"
                                            data-type="{{ $tx->tipe_transaksi }}"
                                            data-is-paket="{{ $isPaket ? '1' : '0' }}"
                                            data-billing-name="{{ $tx->nama }}"
                                            data-location-id="{{ $matchingLoc ? $matchingLoc->id : '' }}"
                                            {{ old('transaction_id') == $tx->id_transaksi ? 'selected' : '' }}>
                                            {{ $tx->device ? $tx->device->nama : 'Station' }} — #{{ $tx->id_transaksi }} ({{ $tx->nama }}) [{{ $txTypeLabel }}]
                                        </option>
                                    @endforeach
                                </select>
                                <small class="form-text text-muted">Hanya menampilkan rental dengan billing yang sedang berjalan.</small>
                            </div>

                            <div class="form-group">
                                <label class="font-weight-bold text-gray-800">Penanggung Jawab Tagihan (Billing)</label>
                                <input type="text" class="form-control bg-light" id="billing_name_display" readonly placeholder="Otomatis terisi dari nama sesi rental...">
                            </div>

                            <!-- Dynamic Session Payment Notices -->
                            <div id="noticePaket" class="alert alert-info d-none" role="alert">
                                <i class="fas fa-info-circle mr-2"></i>
                                <strong>Sesi Paket</strong> — Biaya main sudah lunas. Pesanan F&amp;B dapat dilayani langsung dan dibayarkan saat staf mengantar makanan atau setelah sesi bermain selesai.
                            </div>
                            <div id="noticeLostTime" class="alert alert-info d-none" role="alert">
                                <i class="fas fa-info-circle mr-2"></i>
                                <strong>Sesi Lost Time</strong> — F&amp;B dapat ditagihkan saat checkout permainan atau dibayar langsung sekarang.
                            </div>
                        </div>

                        <!-- Section: Non-Bermain -->
                        <div id="sectionCustomerNonPlaying" class="d-none">
                            <div class="form-group">
                                <label class="font-weight-bold d-block text-gray-800">Tipe Layanan</label>
                                <div class="btn-group btn-group-toggle w-100" data-toggle="buttons">
                                    <label class="btn btn-outline-secondary active w-50">
                                        <input type="radio" name="dining_option" id="diningDineIn" value="dine_in" checked autocomplete="off">
                                        <i class="fas fa-utensils mr-1"></i> Dine-in
                                    </label>
                                    <label class="btn btn-outline-secondary w-50">
                                        <input type="radio" name="dining_option" id="diningTakeaway" value="takeaway" autocomplete="off">
                                        <i class="fas fa-shopping-bag mr-1"></i> Takeaway
                                    </label>
                                </div>
                            </div>

                            <div id="noticeNonPlaying" class="alert alert-warning" role="alert">
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                <strong>Pelanggan Non-Bermain</strong> — Pesanan wajib dibayar di muka di kasir sebelum diproses dapur.
                            </div>
                        </div>

                        <!-- Common Customer Fields -->
                        <div class="form-group">
                            <label for="customer_name" class="font-weight-bold text-gray-800" id="labelCustomerName">
                                Nama Pemesan <span id="customerNameReqStar" class="text-danger d-none">*</span>
                            </label>
                            <input type="text" class="form-control" name="customer_name" id="customer_name"
                                value="{{ old('customer_name') }}"
                                placeholder="Nama pemesan jika berbeda dari billing">
                            <small class="form-text text-muted" id="customerNameHint">Boleh dikosongkan jika sesuai nama penanggung jawab billing.</small>
                        </div>

                        <div class="form-group">
                            <label for="location_id" class="font-weight-bold text-gray-800">Lokasi / Station / Meja</label>
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
                            <label for="notes" class="font-weight-bold text-gray-800">Catatan Pesanan Keseluruhan</label>
                            <textarea class="form-control" name="notes" id="notes" rows="2" placeholder="Catatan dapur (misal: diantar sekaligus)...">{{ old('notes') }}</textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: F&B Items Selection & Actions -->
            <div class="col-lg-7">
                <div class="card shadow mb-4">
                    <div class="card-header py-3 bg-white d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-utensils mr-1"></i> 2. Item Pesanan F&amp;B
                        </h6>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="btnAddRow">
                            <i class="fas fa-plus mr-1"></i> Tambah Baris Menu
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered align-middle" id="tableFnbItems">
                                <thead class="thead-light">
                                    <tr>
                                        <th style="min-width: 220px;">Menu F&amp;B</th>
                                        <th style="width: 105px;">Harga</th>
                                        <th style="width: 75px;">Stok</th>
                                        <th style="width: 130px;">Jumlah</th>
                                        <th>Catatan Item</th>
                                        <th style="width: 110px;">Subtotal</th>
                                        <th style="width: 45px;" class="text-center"><i class="fas fa-cog"></i></th>
                                    </tr>
                                </thead>
                                <tbody id="fnbItemsBody">
                                    <!-- Dynamic rows will be rendered here -->
                                </tbody>
                            </table>
                        </div>

                        <!-- Grand Total Ringkasan -->
                        <div class="card bg-light border-0 p-3 mb-4">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="h6 mb-0 text-gray-700 font-weight-bold">Total Tagihan:</span>
                                <span class="h4 mb-0 font-weight-bold text-primary" id="displayGrandTotal">Rp 0</span>
                            </div>
                        </div>

                        <!-- Action Area & Dynamic Buttons -->
                        <div class="mt-4 pt-3 border-top">
                            @if(!auth()->user()->isOwner())
                                <!-- Guidance / Helper Text for Kasir / Kasir F&B / Admin -->
                                <div id="actionHelperNotice" class="alert alert-info py-2 px-3 small mb-3 d-flex align-items-center">
                                    <i class="fas fa-info-circle mr-2 fa-lg text-info"></i>
                                    <span id="actionHelperText">Sesi Paket dapat dilayani langsung. Pembayaran dapat dilakukan saat staf mengantar makanan atau setelah sesi bermain selesai.</span>
                                </div>

                                <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center">
                                    <!-- Secondary Actions -->
                                    <div class="mb-2 mb-sm-0" id="secondaryActionContainer">
                                        <!-- Shown for Non-Bermain: Simpan Pending -->
                                        <button type="submit" name="action" value="save" id="btnSecondarySavePending" class="btn btn-outline-secondary shadow-sm">
                                            <i class="fas fa-clock mr-1"></i> <span id="labelSecondarySave">Simpan Pending (Bayar Nanti)</span>
                                        </button>
                                        <!-- Shown for Lost Time & Paket: Bayar Sekarang -->
                                        <button type="button" id="btnSecondaryPayEarly" class="btn btn-outline-success btn-sm shadow-sm d-none" onclick="openPaymentSummaryModal()">
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
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title font-weight-bold" id="modalPaymentSummaryLabel">
                            <i class="fas fa-receipt mr-1"></i> Ringkasan Pembayaran F&amp;B
                        </h5>
                        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
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
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
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

<!-- Template for F&B Item Options (JSON encoded for safe local rendering) -->
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
                    // Status Aksi dapat langsung diterima tidak harus menunggu bayar
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
                    // Tombol utama: "Bayar dan Proses"
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
                <td>
                    <div class="d-flex align-items-center">
                        <img src="${placeholderThumb}" class="row-thumb rounded border mr-2" style="width: 38px; height: 38px; object-fit: cover;" alt="thumb">
                        <select name="items[${rowIndex}][fnb_id]" class="form-control form-control-sm fnb-select" required>
                            ${optionsHtml}
                        </select>
                    </div>
                </td>
                <td class="align-middle text-nowrap"><span class="row-price font-weight-bold">Rp 0</span></td>
                <td class="align-middle text-center"><span class="badge badge-secondary row-stock">-</span></td>
                <td class="align-middle">
                    <div class="input-group input-group-sm" style="width: 110px;">
                        <div class="input-group-prepend">
                            <button type="button" class="btn btn-outline-secondary btn-step-down"><i class="fas fa-minus"></i></button>
                        </div>
                        <input type="number" name="items[${rowIndex}][qty]" class="form-control text-center item-qty" value="${initialQty}" min="1" max="999" required>
                        <div class="input-group-append">
                            <button type="button" class="btn btn-outline-secondary btn-step-up"><i class="fas fa-plus"></i></button>
                        </div>
                    </div>
                </td>
                <td class="align-middle">
                    <input type="text" name="items[${rowIndex}][notes]" class="form-control form-control-sm item-notes" placeholder="Catatan (e.g. Pedas)">
                </td>
                <td class="align-middle text-nowrap"><span class="row-subtotal font-weight-bold text-gray-900">Rp 0</span></td>
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
                if (parseInt(this.value) < 1 || isNaN(parseInt(this.value))) {
                    this.value = 1;
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
            document.querySelectorAll('.fnb-row').forEach(function (tr) {
                const selectEl = tr.querySelector('.fnb-select');
                const qtyInput = tr.querySelector('.item-qty');
                if (selectEl && selectEl.value) {
                    const selected = selectEl.options[selectEl.selectedIndex];
                    const price = parseFloat(selected.getAttribute('data-price')) || 0;
                    const qty = parseInt(qtyInput.value) || 0;
                    total += (price * qty);
                }
            });

            displayGrandTotal.innerText = formatRupiah(total);
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
