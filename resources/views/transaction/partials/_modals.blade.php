{{-- 1. Lost Time Category Picker Dialog --}}
<dialog id="lostTimeCategoryDialog" class="pos-dialog" aria-labelledby="lostTimeCategoryDialogTitle">
    <div class="pos-dialog-header">
        <h2 id="lostTimeCategoryDialogTitle" class="pos-dialog-title">Mulai Sesi Lost Time</h2>
        <button
            type="button"
            class="pos-dialog-close"
            command="close"
            commandfor="lostTimeCategoryDialog"
            data-dialog-close
            aria-label="Tutup dialog"
        >
            <i class="fas fa-times" aria-hidden="true"></i>
        </button>
    </div>
    <form id="lost-time-form" method="post" action="{{ route('transaction.store') }}">
        @csrf
        <input type="hidden" name="tipe_transaksi" value="postpaid">
        <input type="hidden" name="device_id" id="lost-time-device-id" value="">
        <input type="hidden" name="playstation_id" id="lost-time-playstation-id" value="">

        <div class="pos-dialog-body">
            <div id="lost-time-station-label" class="pos-context-pill">Stasiun</div>

            <div class="pos-form-row">
                <div class="pos-form-group">
                    <label for="lost-time-nama" class="pos-form-label">Nama Pelanggan (Opsional)</label>
                    <input type="text" id="lost-time-nama" name="nama" class="form-control form-control-sm" placeholder="Pelanggan" maxlength="255" autofocus>
                </div>
                <div class="pos-form-group">
                    <label for="lost-time-telepon" class="pos-form-label">No. Telepon (Opsional)</label>
                    <input type="text" id="lost-time-telepon" name="no_telepon" class="form-control form-control-sm" placeholder="08..." maxlength="20">
                </div>
            </div>

            <div class="pos-form-group">
                <span class="pos-form-label">Pilih Kategori Permainan</span>
                <div id="lost-time-category-options" class="pos-category-options"></div>
            </div>

            <div id="lost-time-dialog-error" class="pos-dialog-error" role="alert" hidden></div>
        </div>

        <div class="pos-dialog-footer">
            <button
                type="button"
                class="pos-btn pos-btn-outline-secondary"
                command="close"
                commandfor="lostTimeCategoryDialog"
                data-dialog-close
            >Batal</button>
            <button type="submit" class="pos-btn pos-btn-primary" id="btn-submit-lost-time">Mulai Lost Time</button>
        </div>
    </form>
</dialog>

{{-- 2. Paket & Custom Paket Dialog --}}
<dialog id="paketDialog" class="pos-dialog" aria-labelledby="paketDialogTitle">
    <div class="pos-dialog-header">
        <h2 id="paketDialogTitle" class="pos-dialog-title">Mulai Sesi Paket</h2>
        <button
            type="button"
            class="pos-dialog-close"
            command="close"
            commandfor="paketDialog"
            data-dialog-close
            aria-label="Tutup dialog"
        >
            <i class="fas fa-times" aria-hidden="true"></i>
        </button>
    </div>
    <form id="paket-form" method="post" action="{{ route('transaction.store') }}">
        @csrf
        <input type="hidden" name="device_id" id="paket-device-id" value="">
        <input type="hidden" name="tipe_transaksi" id="paket-tipe-transaksi" value="prepaid">
        <input type="hidden" name="harga" id="paket-harga-input" value="">
        <input type="hidden" name="total" id="paket-total-input" value="">
        <input type="hidden" name="duration_seconds" id="paket-duration-seconds-input" value="">

        <div class="pos-dialog-body">
            <div id="paket-station-label" class="pos-context-pill">Stasiun</div>

            <div class="pos-mode-switch" role="group" aria-label="Pilih Jenis Paket">
                <button type="button" class="pos-mode-btn" id="btn-mode-prepaid" data-paket-mode="prepaid" aria-pressed="true" autofocus>Paket Reguler</button>
                <button type="button" class="pos-mode-btn" id="btn-mode-custom" data-paket-mode="custom_package" aria-pressed="false">Custom Paket</button>
            </div>

            <div class="pos-form-row">
                <div class="pos-form-group">
                    <label for="paket-nama" class="pos-form-label">Nama Pelanggan (Opsional)</label>
                    <input type="text" id="paket-nama" name="nama" class="form-control form-control-sm" placeholder="Pelanggan" maxlength="255">
                </div>
                <div class="pos-form-group">
                    <label for="paket-telepon" class="pos-form-label">No. Telepon (Opsional)</label>
                    <input type="text" id="paket-telepon" name="no_telepon" class="form-control form-control-sm" placeholder="08..." maxlength="20">
                </div>
            </div>

            {{-- Paket Reguler Section --}}
            <div id="paket-prepaid-section">
                <div class="pos-form-group mb-2">
                    <label for="paket-playstation-id" class="pos-form-label">Kategori / Perangkat</label>
                    <select id="paket-playstation-id" name="playstation_id" class="form-control form-control-sm"></select>
                </div>

                <div class="pos-form-group mb-2">
                    <label for="paket-duration-select" class="pos-form-label">Pilih Durasi</label>
                    <select id="paket-duration-select" name="jam_main_select" class="form-control form-control-sm"></select>
                </div>

                <div id="paket-duration-presets" class="pos-chip-row mb-2"></div>

                <div id="paket-custom-duration-fields" class="pos-form-row-3 mb-2" hidden>
                    <div class="pos-form-group">
                        <label for="paket-custom-hours" class="pos-form-label">Jam</label>
                        <input type="number" id="paket-custom-hours" name="custom_hours" class="form-control form-control-sm" min="0" max="24" value="1">
                    </div>
                    <div class="pos-form-group">
                        <label for="paket-custom-minutes" class="pos-form-label">Menit</label>
                        <input type="number" id="paket-custom-minutes" name="custom_minutes" class="form-control form-control-sm" min="0" max="59" value="0">
                    </div>
                    <div class="pos-form-group">
                        <label for="paket-custom-seconds" class="pos-form-label">Detik</label>
                        <input type="number" id="paket-custom-seconds" name="custom_seconds" class="form-control form-control-sm" min="0" max="59" value="0">
                    </div>
                </div>

                <div id="paket-prepaid-price-preview" class="pos-context-pill">Estimasi Tarif: Rp 0</div>
            </div>

            {{-- Custom Paket Section --}}
            <div id="paket-custom-section" hidden>
                <div class="pos-form-group mb-2">
                    <label for="paket-custom-package-id" class="pos-form-label">Pilih Custom Paket</label>
                    <select id="paket-custom-package-id" name="custom_package_id" class="form-control form-control-sm"></select>
                </div>
                <div id="paket-custom-package-detail" class="pos-context-pill"></div>
            </div>

            <div id="paket-dialog-error" class="pos-dialog-error" role="alert" hidden></div>
        </div>

        <div class="pos-dialog-footer">
            <button
                type="button"
                class="pos-btn pos-btn-outline-secondary"
                command="close"
                commandfor="paketDialog"
                data-dialog-close
            >Batal</button>
            <button type="submit" class="pos-btn pos-btn-primary" id="btn-submit-paket">Buat &amp; Lanjut Bayar</button>
        </div>
    </form>
</dialog>

{{-- 3. Detail Sesi Dialog --}}
<dialog id="detailSessionDialog" class="pos-dialog pos-dialog-lg" aria-labelledby="detailSessionDialogTitle">
    <div class="pos-dialog-header">
        <h2 id="detailSessionDialogTitle" class="pos-dialog-title">Detail Sesi Stasiun</h2>
        <button
            type="button"
            class="pos-dialog-close"
            command="close"
            commandfor="detailSessionDialog"
            data-dialog-close
            aria-label="Tutup dialog"
        >
            <i class="fas fa-times" aria-hidden="true"></i>
        </button>
    </div>
    <div class="pos-dialog-body">
        <div id="detail-session-station-header" class="pos-context-pill">Stasiun</div>
        <div id="detail-session-fallback-note" class="pos-fallback-note" hidden>
            Stasiun sedang berstatus Digunakan, namun belum ada transaksi aktif yang tertaut.
        </div>
        <div id="detail-session-meta" class="pos-detail-grid"></div>
        <div>
            <h3 class="h6 font-weight-bold mb-2">Rincian Order FnB</h3>
            <div id="detail-session-fnb-list" class="small text-muted">Tidak ada order FnB.</div>
        </div>
    </div>
    <div class="pos-dialog-footer">
        <div id="detail-session-actions" class="d-flex flex-wrap mr-auto" style="gap: 0.5rem;"></div>
        <button
            type="button"
            class="pos-btn pos-btn-secondary"
            command="close"
            commandfor="detailSessionDialog"
            data-dialog-close
            autofocus
        >Tutup</button>
    </div>
</dialog>

{{-- 4. Cafe / F&B Order Dialog --}}
<dialog id="cafeOrderDialog" class="pos-dialog pos-dialog-lg" aria-labelledby="cafeOrderDialogTitle">
    <div class="pos-dialog-header">
        <h2 id="cafeOrderDialogTitle" class="pos-dialog-title">Order Cafe / F&amp;B</h2>
        <button
            type="button"
            class="pos-dialog-close"
            command="close"
            commandfor="cafeOrderDialog"
            data-dialog-close
            aria-label="Tutup dialog"
        >
            <i class="fas fa-times" aria-hidden="true"></i>
        </button>
    </div>
    <form id="cafe-order-form" method="post" action="{{ route('fnb.orders.store') }}">
        @csrf
        <input type="hidden" name="transaction_id" id="cafe-transaction-id" value="">

        <div class="pos-dialog-body">
            <div id="cafe-order-context" class="pos-context-pill">Order Cafe Mandiri</div>

            <div id="cafe-standalone-fields" class="pos-form-row">
                <div class="pos-form-group">
                    <label for="cafe-location-id" class="pos-form-label">Lokasi / Stasiun (Opsional)</label>
                    <select id="cafe-location-id" name="location_id" class="form-control form-control-sm">
                        <option value="">Tanpa Lokasi Khusus</option>
                    </select>
                </div>
                <div class="pos-form-group">
                    <label for="cafe-customer-name" class="pos-form-label">Nama Pelanggan (Opsional)</label>
                    <input type="text" id="cafe-customer-name" name="customer_name" class="form-control form-control-sm" placeholder="Pelanggan Cafe" maxlength="100">
                </div>
            </div>

            <div class="pos-form-group">
                <label for="cafe-menu-search" class="pos-form-label">Cari Menu Cafe</label>
                <input type="search" id="cafe-menu-search" class="form-control form-control-sm" placeholder="Ketik nama makanan atau minuman..." autofocus>
            </div>

            <div id="cafe-menu-list" class="pos-cafe-menu-list" aria-live="polite"></div>

            <div id="cafe-cart-summary" class="pos-context-pill">Belum ada item dipilih. Subtotal: Rp 0</div>

            <div class="pos-form-group">
                <label for="cafe-order-notes" class="pos-form-label">Catatan Pesanan (Opsional)</label>
                <input type="text" id="cafe-order-notes" name="notes" class="form-control form-control-sm" placeholder="Contoh: Es sedikit, tanpa sambal" maxlength="255">
            </div>

            <div id="cafe-payment-section" class="pos-form-group">
                <div class="pos-form-group mb-2">
                    <label for="cafe-action-mode" class="pos-form-label">Tipe Penyelesaian Order</label>
                    <select id="cafe-action-mode" name="action" class="form-control form-control-sm">
                        <option value="save">Simpan ke Antrian / Gabung Tagihan Sesi</option>
                        <option value="pay_now">Bayar Langsung Sekarang</option>
                    </select>
                </div>

                <div id="cafe-pay-now-fields" class="pos-form-row-3" hidden>
                    <div class="pos-form-group">
                        <label for="cafe-payment-method" class="pos-form-label">Metode Bayar</label>
                        <select id="cafe-payment-method" name="payment_method" class="form-control form-control-sm">
                            <option value="tunai">Tunai</option>
                            <option value="e-wallet">E-Wallet</option>
                            <option value="transfer_bank">Transfer Bank</option>
                        </select>
                    </div>
                    <div class="pos-form-group">
                        <label for="cafe-discount" class="pos-form-label">Diskon (%)</label>
                        <input type="number" id="cafe-discount" name="discount" class="form-control form-control-sm" min="0" max="100" step="1" value="0">
                    </div>
                    <div class="pos-form-group">
                        <label for="cafe-amount-paid" class="pos-form-label">Nominal Bayar</label>
                        <input type="number" id="cafe-amount-paid" name="amount_paid" class="form-control form-control-sm" min="0" step="500" value="0">
                    </div>
                </div>
            </div>

            <div id="cafe-dialog-error" class="pos-dialog-error" role="alert" hidden></div>
        </div>

        <div class="pos-dialog-footer">
            <button
                type="button"
                class="pos-btn pos-btn-outline-secondary"
                command="close"
                commandfor="cafeOrderDialog"
                data-dialog-close
            >Batal</button>
            <button type="submit" class="pos-btn pos-btn-accent" id="btn-submit-cafe">Kirim Order</button>
        </div>
    </form>
</dialog>

{{-- 5. Berhenti Confirm Dialog --}}
<dialog id="berhentiConfirmDialog" class="pos-dialog" aria-labelledby="berhentiConfirmDialogTitle">
    <div class="pos-dialog-header">
        <h2 id="berhentiConfirmDialogTitle" class="pos-dialog-title">Konfirmasi Hentikan Stasiun</h2>
        <button
            type="button"
            class="pos-dialog-close"
            command="close"
            commandfor="berhentiConfirmDialog"
            data-dialog-close
            aria-label="Tutup dialog"
        >
            <i class="fas fa-times" aria-hidden="true"></i>
        </button>
    </div>
    <div class="pos-dialog-body">
        <p class="mb-0">
            Apakah Anda yakin ingin menghentikan stasiun <strong id="berhenti-device-name">-</strong>?
            Status perangkat akan diubah menjadi Tersedia dan otomasi hardware terkait akan dihentikan.
        </p>
        <div id="berhenti-dialog-error" class="pos-dialog-error" role="alert" hidden></div>
    </div>
    <div class="pos-dialog-footer">
        <button
            type="button"
            class="pos-btn pos-btn-outline-secondary"
            command="close"
            commandfor="berhentiConfirmDialog"
            data-dialog-close
            autofocus
        >Batal</button>
        <button type="button" class="pos-btn pos-btn-warning" id="btn-confirm-berhenti">Ya, Hentikan Stasiun</button>
    </div>
</dialog>

{{-- 6. Selesai Confirm Dialog --}}
<dialog id="selesaiConfirmDialog" class="pos-dialog" aria-labelledby="selesaiConfirmDialogTitle">
    <div class="pos-dialog-header">
        <h2 id="selesaiConfirmDialogTitle" class="pos-dialog-title">Konfirmasi Selesai Lost Time</h2>
        <button
            type="button"
            class="pos-dialog-close"
            command="close"
            commandfor="selesaiConfirmDialog"
            data-dialog-close
            aria-label="Tutup dialog"
        >
            <i class="fas fa-times" aria-hidden="true"></i>
        </button>
    </div>
    <div class="pos-dialog-body">
        <p class="mb-0">
            Akhiri sesi Lost Time untuk <strong id="selesai-device-name">-</strong> (Transaksi #<span id="selesai-tx-id">-</span>)?
            Durasi bermain akan dihentikan dan tagihan akhir dihitung untuk proses pembayaran.
        </p>
        <div id="selesai-dialog-error" class="pos-dialog-error" role="alert" hidden></div>
    </div>
    <div class="pos-dialog-footer">
        <button
            type="button"
            class="pos-btn pos-btn-outline-secondary"
            command="close"
            commandfor="selesaiConfirmDialog"
            data-dialog-close
            autofocus
        >Batal</button>
        <button type="button" class="pos-btn pos-btn-success" id="btn-confirm-selesai">Ya, Selesai &amp; Hitung</button>
    </div>
</dialog>

