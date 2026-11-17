@extends('layouts.app', ['title' => $title ?? 'Antrean Order F&B', 'active' => 'fnb-queue'])

@section('content')
<style>
    /* Styling Kolom Aksi Antrean F&B */
    .table-fnb-queue th,
    .table-fnb-queue td {
        vertical-align: middle;
    }
    .fnb-action-col {
        width: 175px;
        min-width: 165px;
        text-align: center;
    }
    .fnb-action-cell {
        vertical-align: middle !important;
        text-align: center;
        padding: 0.55rem 0.5rem !important;
    }
    .fnb-actions-wrapper {
        width: 100%;
        max-width: 170px;
        margin: 0 auto;
        display: flex;
        flex-direction: column;
        gap: 5px;
    }
    .fnb-btn-pay {
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.775rem;
        font-weight: 700;
        letter-spacing: 0.02em;
        padding: 0.35rem 0.5rem;
        border-radius: 6px;
        line-height: 1.25;
        border: none;
        background-color: #10b981;
        color: #ffffff;
        box-shadow: 0 1px 2px rgba(16, 185, 129, 0.2);
        transition: background-color 0.15s ease, transform 0.1s ease;
    }
    .fnb-btn-pay:hover {
        background-color: #059669;
        color: #ffffff;
        transform: translateY(-1px);
    }
    .fnb-btn-pay:active {
        background-color: #047857;
        transform: translateY(0);
    }
    .fnb-actions-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 5px;
        width: 100%;
    }
    .fnb-actions-grid.single-action {
        grid-template-columns: 1fr;
    }
    .fnb-action-form {
        margin: 0;
        padding: 0;
        display: flex;
        width: 100%;
    }
    .fnb-act-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        font-size: 0.75rem;
        font-weight: 600;
        padding: 0.35rem 0.25rem;
        border-radius: 6px;
        white-space: nowrap;
        line-height: 1.25;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        transition: filter 0.15s ease, transform 0.1s ease;
    }
    .fnb-act-btn:hover:not(:disabled) {
        filter: brightness(0.95);
        transform: translateY(-1px);
    }
    .fnb-act-btn:active:not(:disabled) {
        transform: translateY(0);
    }
    .fnb-act-btn:disabled {
        opacity: 0.55;
        cursor: not-allowed;
        box-shadow: none;
    }
    .fnb-terminal-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.75rem;
        font-weight: 600;
        padding: 0.3rem 0.6rem;
        border-radius: 6px;
    }
</style>
<div class="container-fluid">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">
            <i class="fas fa-concierge-bell text-primary mr-2"></i>Antrean Order F&amp;B
        </h1>
        <div class="d-flex align-items-center">
            <button type="button" id="btnToggleQrSound" class="btn btn-sm btn-outline-success mr-2" title="Aktifkan/Nonaktifkan Suara Notifikasi">
                <i class="fas fa-volume-up mr-1" id="qrSoundIcon"></i>
                <span id="qrSoundLabel">Suara Notifikasi: Aktif</span>
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary mr-2" onclick="window.location.reload()">
                <i class="fas fa-sync-alt mr-1"></i> Muat Ulang
            </button>
            @if(!auth()->user()->isOwner())
            <a href="{{ route('fnb.orders.create') }}" class="btn btn-sm btn-primary shadow-sm">
                <i class="fas fa-plus fa-sm text-white-50 mr-1"></i> Transaksi F&amp;B Baru
            </a>
            @endif
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('success') }}
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    @endif

    @if (session('gagal'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            {{ session('gagal') }}
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    @endif

    <div class="card shadow mb-4">
        <div class="card-header py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-primary">Daftar Pesanan Masuk (QR, Kasir, Staf F&amp;B)</h6>
            <span class="badge badge-info" id="fnbQueueOrderCount">{{ $orders->count() }} Order</span>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle table-fnb-queue" width="100%" cellspacing="0">
                    <thead class="thead-light">
                        <tr>
                            <th>No. Order</th>
                            <th>Sumber</th>
                            <th>Lokasi / Sesi</th>
                            <th>Pemesan &amp; Billing</th>
                            <th>Item Pesanan</th>
                            <th class="text-right">Total</th>
                            <th>Pembayaran</th>
                            <th>Status Dapur</th>
                            <th class="fnb-action-col">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="fnbQueueTableBody">
                        @forelse ($orders as $order)
                            @php
                                $statusBadges = [
                                    'PENDING' => 'badge-warning',
                                    'ACCEPTED' => 'badge-info',
                                    'PREPARING' => 'badge-primary',
                                    'READY' => 'badge-success',
                                    'DELIVERED' => 'badge-secondary',
                                    'CANCELLED' => 'badge-danger',
                                    'EXPIRED' => 'badge-dark',
                                ];
                                $sourceBadges = [
                                    'QR' => 'badge-primary',
                                    'CASHIER' => 'badge-info',
                                    'FNB_STAFF' => 'badge-warning',
                                ];
                                // Non-Bermain orders that are unpaid require upfront payment before acceptance.
                                // Active sessions (Lost Time & Paket) can be accepted immediately.
                                $isBlockedUpfront = !$order->transaction_id && $order->payment_status !== 'paid';
                            @endphp
                            <tr>
                                <td>
                                    <strong>{{ $order->order_number }}</strong>
                                    <div class="small text-muted">{{ $order->created_at ? $order->created_at->format('H:i') : '-' }}</div>
                                </td>
                                <td>
                                    <span class="badge {{ $sourceBadges[$order->source] ?? 'badge-secondary' }}">
                                        {{ $order->source }}
                                    </span>
                                </td>
                                <td>
                                    <div class="font-weight-bold">
                                        {{ $order->location ? $order->location->name : 'Non-Bermain / Takeaway' }}
                                    </div>
                                    @if($order->transaction && $order->transaction->device)
                                        <small class="text-muted">Perangkat: {{ $order->transaction->device->nama }}</small>
                                    @endif
                                </td>
                                <td>
                                    <div><strong>Pemesan:</strong> {{ $order->customer_name }}</div>
                                    @if($order->billing_name)
                                        <div class="small text-muted"><strong>Billing:</strong> {{ $order->billing_name }}</div>
                                    @endif
                                    <div class="mt-1">
                                        @if($order->transaction)
                                            @if($order->transaction->tipe_transaksi === 'postpaid')
                                                <span class="badge badge-info">Lost Time</span>
                                            @elseif($order->transaction->tipe_transaksi === 'custom_package')
                                                <span class="badge badge-primary">Paket Custom</span>
                                            @else
                                                <span class="badge badge-primary">Paket</span>
                                            @endif
                                        @else
                                            <span class="badge badge-secondary">Non-Bermain</span>
                                        @endif
                                    </div>
                                    @if($order->notes)
                                        <div class="small text-info mt-1"><em>Catatan: {{ $order->notes }}</em></div>
                                    @endif
                                </td>
                                <td>
                                    <ul class="list-unstyled mb-0 small">
                                        @foreach($order->items as $item)
                                            <li class="mb-1">
                                                <img src="{{ $item->fnb ? $item->fnb->image_url : asset('img/fnb-placeholder.svg') }}" alt="{{ $item->fnb->nama ?? 'F&B' }}" style="width: 24px; height: 24px; object-fit: cover; border-radius: 4px; vertical-align: middle; margin-right: 4px;">
                                                <span class="font-weight-bold">{{ $item->qty }}x</span>
                                                {{ $item->fnb ? $item->fnb->nama : 'Item F&B' }}
                                                <span class="text-muted">(Rp {{ number_format($item->subtotal, 0, ',', '.') }})</span>
                                                @if($item->notes)
                                                    <div class="text-muted ml-2">- {{ $item->notes }}</div>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                </td>
                                <td class="text-right font-weight-bold">
                                    Rp {{ number_format($order->total_amount, 0, ',', '.') }}
                                </td>
                                <td>
                                    @if($order->payment_status === 'paid')
                                        <span class="badge badge-success">Lunas</span>
                                    @else
                                        <span class="badge badge-danger">Belum Bayar</span>
                                    @endif
                                    <div class="small mt-1">
                                        @if($order->transaction)
                                            @if($order->transaction->tipe_transaksi === 'postpaid')
                                                <span class="text-muted">Bayar Saat Selesai Main</span>
                                            @else
                                                <span class="text-info font-weight-bold">Bayar Saat Antar / Selesai</span>
                                            @endif
                                        @else
                                            <span class="text-warning font-weight-bold">Bayar di Muka</span>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    <span class="badge {{ $statusBadges[$order->status] ?? 'badge-secondary' }}">
                                        {{ $order->status }}
                                    </span>
                                </td>
                                <td class="fnb-action-cell">
                                    @if(!auth()->user()->isOwner())
                                        @php
                                            $hasPayButton = ($order->payment_status === 'unpaid' && !in_array($order->status, ['CANCELLED', 'EXPIRED']));
                                            $hasKitchenAction = in_array($order->status, ['PENDING', 'ACCEPTED', 'PREPARING', 'READY']);
                                            $hasCancelAction = !in_array($order->status, ['DELIVERED', 'CANCELLED', 'EXPIRED']);
                                            $gridClass = ($hasKitchenAction && $hasCancelAction) ? '' : 'single-action';
                                        @endphp

                                        <div class="fnb-actions-wrapper">
                                            @if($hasPayButton)
                                                <button type="button"
                                                    class="btn btn-sm btn-success btn-block fnb-btn-pay"
                                                    data-toggle="modal"
                                                    data-target="#modalPayOrder"
                                                    onclick="preparePayModal({{ $order->id }}, '{{ $order->order_number }}', {{ (float) $order->total_amount }})">
                                                    <i class="fas fa-cash-register mr-1"></i> Bayar
                                                </button>
                                            @endif

                                            @if($hasKitchenAction || $hasCancelAction)
                                                <div class="fnb-actions-grid {{ $gridClass }}">
                                                    @if($order->status === 'PENDING')
                                                        <form action="{{ route('fnb.queue.status', $order->id) }}" method="POST" class="fnb-action-form">
                                                            @csrf
                                                            <input type="hidden" name="status" value="ACCEPTED">
                                                            <button type="submit"
                                                                class="btn btn-sm btn-info fnb-act-btn"
                                                                {{ $isBlockedUpfront ? 'disabled title=Wajib_lunas_terlebih_dahulu' : '' }}>
                                                                <i class="fas fa-check mr-1"></i>Terima
                                                            </button>
                                                        </form>
                                                    @elseif($order->status === 'ACCEPTED')
                                                        <form action="{{ route('fnb.queue.status', $order->id) }}" method="POST" class="fnb-action-form">
                                                            @csrf
                                                            <input type="hidden" name="status" value="PREPARING">
                                                            <button type="submit" class="btn btn-sm btn-primary fnb-act-btn">
                                                                <i class="fas fa-fire mr-1"></i>Proses
                                                            </button>
                                                        </form>
                                                    @elseif($order->status === 'PREPARING')
                                                        <form action="{{ route('fnb.queue.status', $order->id) }}" method="POST" class="fnb-action-form">
                                                            @csrf
                                                            <input type="hidden" name="status" value="READY">
                                                            <button type="submit" class="btn btn-sm btn-success fnb-act-btn">
                                                                <i class="fas fa-bell mr-1"></i>Siap
                                                            </button>
                                                        </form>
                                                    @elseif($order->status === 'READY')
                                                        <form action="{{ route('fnb.queue.status', $order->id) }}" method="POST" class="fnb-action-form">
                                                            @csrf
                                                            <input type="hidden" name="status" value="DELIVERED">
                                                            <button type="submit" class="btn btn-sm btn-secondary fnb-act-btn">
                                                                <i class="fas fa-check-double mr-1"></i>Selesai
                                                            </button>
                                                        </form>
                                                    @endif

                                                    @if($hasCancelAction)
                                                        <form action="{{ route('fnb.queue.cancel', $order->id) }}" method="POST" class="fnb-action-form" onsubmit="return confirm('Batalkan pesanan ini?');">
                                                            @csrf
                                                            <input type="hidden" name="reason" value="Dibatalkan oleh petugas">
                                                            <button type="submit" class="btn btn-sm btn-danger fnb-act-btn" title="Batalkan Pesanan">
                                                                <i class="fas fa-times mr-1"></i>Batal
                                                            </button>
                                                        </form>
                                                    @endif
                                                </div>
                                            @elseif(!$hasPayButton)
                                                @if($order->status === 'DELIVERED')
                                                    <span class="badge badge-light border text-success fnb-terminal-badge">
                                                        <i class="fas fa-check-circle mr-1"></i>Selesai
                                                    </span>
                                                @elseif($order->status === 'CANCELLED')
                                                    <span class="badge badge-light border text-danger fnb-terminal-badge">
                                                        <i class="fas fa-times-circle mr-1"></i>Batal
                                                    </span>
                                                @elseif($order->status === 'EXPIRED')
                                                    <span class="badge badge-light border text-secondary fnb-terminal-badge">
                                                        <i class="fas fa-clock mr-1"></i>Kedaluwarsa
                                                    </span>
                                                @else
                                                    <span class="text-muted small">-</span>
                                                @endif
                                            @endif
                                        </div>
                                    @else
                                        <span class="badge badge-light border text-muted fnb-terminal-badge">
                                            <i class="fas fa-eye mr-1"></i>Monitoring
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="text-center py-4 text-muted">
                                    Belum ada antrean order F&amp;B saat ini.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal Pembayaran Order F&B -->
@if(!auth()->user()->isOwner())
<div class="modal fade" id="modalPayOrder" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form id="payOrderForm" method="POST" action="">
            @csrf
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Pembayaran Order <span id="payOrderNumber"></span></h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        Total Tagihan: <strong id="payOrderTotalText">Rp 0</strong>
                    </div>
                    <div class="form-group">
                        <label for="pay_method">Metode Pembayaran</label>
                        <select name="payment_method" id="pay_method" class="form-control" required>
                            <option value="tunai">Tunai</option>
                            <option value="e-wallet">E-Wallet</option>
                            <option value="transfer_bank">Transfer Bank</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="pay_discount">Diskon (%)</label>
                        <input type="number" name="discount" id="pay_discount" class="form-control" min="0" max="100" value="0">
                    </div>
                    <div class="form-group">
                        <label for="pay_amount">Jumlah Bayar (Rp)</label>
                        <input type="number" name="amount_paid" id="pay_amount" class="form-control" min="0" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-success">Proses Pembayaran &amp; Cetak Struk</button>
                </div>
            </div>
        </form>
    </div>
</div>
@endif

<!-- Pop-up Card Pesanan Masuk (QR & Kasir) -->
<div class="modal fade" id="qrIncomingModal" tabindex="-1" role="dialog" aria-labelledby="qrIncomingModalLabel" aria-hidden="true" data-backdrop="static" data-keyboard="false">
    <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
        <div class="modal-content border-left-warning shadow-lg">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title font-weight-bold" id="qrIncomingModalLabel">
                    <i class="fas fa-bell mr-2"></i>Pesanan Masuk (<span id="qrIncomingModalCount">{{ $pendingAlertCount ?? $pendingQrCount ?? 0 }}</span>)
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Tutup">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body" id="qrIncomingModalBody">
                <p class="text-muted mb-0">Memuat pesanan masuk...</p>
            </div>
            <div class="modal-footer bg-light d-flex justify-content-between align-items-center">
                <small id="qrAudioBlockedHint" class="text-muted d-none">
                    <i class="fas fa-volume-mute mr-1"></i>Klik di mana saja pada halaman ini untuk mengizinkan suara notifikasi browser.
                </small>
                <small class="text-muted ml-auto">Pop-up tertutup otomatis setelah semua pesanan diproses atau dibatalkan.</small>
            </div>
        </div>
    </div>
</div>

<script>
    function preparePayModal(orderId, orderNumber, totalAmount) {
        const form = document.getElementById('payOrderForm');
        if (!form) return;
        form.action = '/fnb/queue/' + orderId + '/pay';
        document.getElementById('payOrderNumber').textContent = '#' + orderNumber;
        document.getElementById('payOrderTotalText').textContent = 'Rp ' + Number(totalAmount).toLocaleString('id-ID');
        document.getElementById('pay_amount').value = Math.ceil(totalAmount);
    }

    (function () {
        const POLL_URL = @json(route('fnb.queue.poll'));
        const QUEUE_URL = @json(route('fnb.queue.index'));
        const CSRF_TOKEN = @json(csrf_token());
        const CAN_ACTION = @json(!auth()->user()->isOwner());
        const SOUND_STORAGE_KEY = 'fnb_qr_sound_enabled';
        const ALARM_INTERVAL_MS = 3500;
        const POLL_INTERVAL_MS = 5000;

        let pendingQrCount = {{ (int) ($pendingQrCount ?? 0) }};
        let pendingAlertCount = {{ (int) ($pendingAlertCount ?? $pendingQrCount ?? 0) }};
        let pendingAlertOrders = [];
        let lastSignature = null;
        let lastModalHash = '';
        let soundEnabled = localStorage.getItem(SOUND_STORAGE_KEY) !== 'false';
        let audioCtx = null;
        let alarmTimer = null;

        const btnToggle = document.getElementById('btnToggleQrSound');
        const soundIcon = document.getElementById('qrSoundIcon');
        const soundLabel = document.getElementById('qrSoundLabel');
        const modalEl = document.getElementById('qrIncomingModal');
        const modalCountEl = document.getElementById('qrIncomingModalCount');
        const modalBodyEl = document.getElementById('qrIncomingModalBody');
        const audioBlockedHint = document.getElementById('qrAudioBlockedHint');

        function escapeHtml(str) {
            return String(str ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function formatRupiah(amount) {
            return 'Rp ' + Number(amount || 0).toLocaleString('id-ID');
        }

        function getAudioContext() {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return null;
            if (!audioCtx) {
                audioCtx = new Ctx();
            }
            return audioCtx;
        }

        function updateSoundButtonUi() {
            if (!btnToggle || !soundIcon || !soundLabel) return;
            if (soundEnabled) {
                btnToggle.classList.remove('btn-outline-secondary');
                btnToggle.classList.add('btn-outline-success');
                soundIcon.className = 'fas fa-volume-up mr-1';
                soundLabel.textContent = 'Suara Notifikasi: Aktif';
            } else {
                btnToggle.classList.remove('btn-outline-success');
                btnToggle.classList.add('btn-outline-secondary');
                soundIcon.className = 'fas fa-volume-mute mr-1';
                soundLabel.textContent = 'Suara Notifikasi: Mati';
            }
        }

        function renderIncomingQrModal() {
            if (modalCountEl) {
                modalCountEl.textContent = String(pendingAlertCount);
            }
            if (!modalEl || !modalBodyEl) return;

            const payModal = document.getElementById('modalPayOrder');
            const isPayModalOpen = payModal && payModal.classList.contains('show');

            if (pendingAlertCount <= 0 || pendingAlertOrders.length === 0) {
                lastModalHash = '';
                modalBodyEl.innerHTML = '';
                if (audioBlockedHint) {
                    audioBlockedHint.classList.add('d-none');
                }
                if (window.jQuery && window.jQuery(modalEl).hasClass('show')) {
                    window.jQuery(modalEl).modal('hide');
                }
                return;
            }

            const newHash = pendingAlertOrders.map(function (o) {
                return o.id + ':' + o.order_number + ':' + o.status + ':' + o.total_amount;
            }).join('|');

            if (newHash !== lastModalHash) {
                lastModalHash = newHash;
                modalBodyEl.innerHTML = pendingAlertOrders.map(function (order) {
                    const itemsHtml = (order.items || []).map(function (item) {
                        const notesHtml = item.notes
                            ? ' <small class="text-muted">(' + escapeHtml(item.notes) + ')</small>'
                            : '';
                        return '<li class="mb-1"><strong>' + Number(item.qty) + 'x</strong> ' + escapeHtml(item.name) + notesHtml + '</li>';
                    }).join('');

                    const sessionBadge = order.session_type
                        ? '<span class="badge badge-' + (order.session_type === 'Lost Time' ? 'warning' : 'info') + ' ml-1">' + escapeHtml(order.session_type) + '</span>'
                        : '';

                    const sourceBadgeClass = order.source === 'QR' ? 'badge-primary' : (order.source === 'CASHIER' ? 'badge-info' : 'badge-warning');
                    const sourceBadge = '<span class="badge ' + sourceBadgeClass + ' mr-1">' + escapeHtml(order.source || 'ORDER') + '</span>';

                    const orderNotes = order.notes
                        ? '<div class="small text-muted mt-1"><i class="fas fa-sticky-note mr-1"></i>Catatan: ' + escapeHtml(order.notes) + '</div>'
                        : '';

                    const isAccepted = (order.status === 'ACCEPTED');
                    const actionLabel = isAccepted ? 'Proses Pesanan' : 'Terima Pesanan';
                    const actionStatus = isAccepted ? 'PREPARING' : 'ACCEPTED';
                    const actionBtnClass = isAccepted ? 'btn-primary' : 'btn-info';
                    const actionIcon = isAccepted ? 'fas fa-fire' : 'fas fa-check';

                    const actionsHtml = CAN_ACTION
                        ? '<div class="mt-3 d-flex justify-content-end">' +
                            '<button type="button" class="btn btn-outline-danger btn-sm mr-2 js-qr-popup-action" data-action="cancel" data-url="' + escapeHtml(order.cancel_url) + '" data-order-number="' + escapeHtml(order.order_number) + '">' +
                                '<i class="fas fa-times mr-1"></i>Batalkan' +
                            '</button>' +
                            '<button type="button" class="btn ' + actionBtnClass + ' btn-sm font-weight-bold js-qr-popup-action" data-action="accept" data-url="' + escapeHtml(order.accept_url) + '" data-order-number="' + escapeHtml(order.order_number) + '" data-status="' + actionStatus + '">' +
                                '<i class="' + actionIcon + ' mr-1"></i>' + actionLabel +
                            '</button>' +
                          '</div>'
                        : '<div class="mt-2 text-right small text-muted">Mode Monitoring</div>';

                    return '<div class="card border-warning mb-3 shadow-sm">' +
                        '<div class="card-header py-2 d-flex justify-content-between align-items-center bg-light">' +
                            '<div>' +
                                sourceBadge +
                                '<span class="badge badge-dark mr-1">ID #' + Number(order.id) + '</span>' +
                                '<strong class="text-primary">#' + escapeHtml(order.order_number) + '</strong>' +
                                sessionBadge +
                            '</div>' +
                            '<small class="text-muted"><i class="far fa-clock mr-1"></i>' + escapeHtml(order.created_at_formatted || '-') + '</small>' +
                        '</div>' +
                        '<div class="card-body py-3">' +
                            '<div class="d-flex justify-content-between flex-wrap mb-2">' +
                                '<div><strong>' + escapeHtml(order.station_name) + '</strong> &middot; Pemesan: <strong>' + escapeHtml(order.customer_name) + '</strong></div>' +
                                '<div class="font-weight-bold text-success">' + formatRupiah(order.total_amount) + '</div>' +
                            '</div>' +
                            '<ul class="pl-3 mb-1 small">' + itemsHtml + '</ul>' +
                            orderNotes +
                            actionsHtml +
                        '</div>' +
                    '</div>';
                }).join('');
            }

            if (!isPayModalOpen && window.jQuery && !window.jQuery(modalEl).hasClass('show')) {
                window.jQuery(modalEl).modal('show');
            }
        }

        async function handlePopupOrderAction(btn) {
            const action = btn.getAttribute('data-action');
            const url = btn.getAttribute('data-url');
            const targetStatus = btn.getAttribute('data-status') || 'ACCEPTED';
            const orderNumber = btn.getAttribute('data-order-number') || '';
            if (!url) return;

            if (action === 'cancel' && !confirm('Batalkan pesanan #' + orderNumber + '?')) {
                return;
            }

            const card = btn.closest('.card');
            const cardButtons = card ? card.querySelectorAll('.js-qr-popup-action') : [btn];
            cardButtons.forEach(function (b) { b.disabled = true; });

            const formData = new FormData();
            formData.append('_token', CSRF_TOKEN);
            if (action === 'accept') {
                formData.append('status', targetStatus);
            } else {
                formData.append('reason', 'Dibatalkan oleh petugas melalui pop-up');
            }

            try {
                await fetch(url, {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                await refreshQueueTable();
                await pollQueueStatus();
            } catch (e) {
                cardButtons.forEach(function (b) { b.disabled = false; });
            }
        }

        if (modalBodyEl) {
            modalBodyEl.addEventListener('click', function (e) {
                const btn = e.target.closest('.js-qr-popup-action');
                if (btn) {
                    handlePopupOrderAction(btn);
                }
            });
        }

        function playQrNotificationChime() {
            if (!soundEnabled || pendingAlertCount <= 0) return;
            const ctx = getAudioContext();
            if (!ctx) return;

            if (ctx.state === 'suspended') {
                ctx.resume().then(function () {
                    if (audioBlockedHint) audioBlockedHint.classList.add('d-none');
                    triggerChimeNotes(ctx);
                }).catch(function () {
                    if (audioBlockedHint) audioBlockedHint.classList.remove('d-none');
                });
                return;
            }

            if (audioBlockedHint) audioBlockedHint.classList.add('d-none');
            triggerChimeNotes(ctx);
        }

        function triggerChimeNotes(ctx) {
            const now = ctx.currentTime;
            // Pleasant 3-note ascending bell chime (C6 -> E6 -> G6)
            const notes = [
                { freq: 1046.50, start: 0.00, duration: 0.45, gain: 0.22 },
                { freq: 1318.51, start: 0.16, duration: 0.50, gain: 0.24 },
                { freq: 1567.98, start: 0.32, duration: 0.75, gain: 0.26 },
            ];

            notes.forEach(function (note) {
                const osc = ctx.createOscillator();
                const gainNode = ctx.createGain();
                osc.type = 'triangle';
                osc.frequency.setValueAtTime(note.freq, now + note.start);

                gainNode.gain.setValueAtTime(0.0001, now + note.start);
                gainNode.gain.exponentialRampToValueAtTime(note.gain, now + note.start + 0.02);
                gainNode.gain.exponentialRampToValueAtTime(0.0001, now + note.start + note.duration);

                osc.connect(gainNode);
                gainNode.connect(ctx.destination);
                osc.start(now + note.start);
                osc.stop(now + note.start + note.duration + 0.05);
            });
        }

        function syncAlarmLoop() {
            if (pendingAlertCount > 0 && soundEnabled) {
                if (!alarmTimer) {
                    playQrNotificationChime();
                    alarmTimer = setInterval(playQrNotificationChime, ALARM_INTERVAL_MS);
                }
            } else if (alarmTimer) {
                clearInterval(alarmTimer);
                alarmTimer = null;
            }
        }

        function unlockAudioOnGesture() {
            const ctx = getAudioContext();
            if (ctx && ctx.state === 'suspended') {
                ctx.resume().then(function () {
                    if (audioBlockedHint) audioBlockedHint.classList.add('d-none');
                    if (pendingAlertCount > 0 && soundEnabled) {
                        playQrNotificationChime();
                    }
                }).catch(function () {});
            }
        }

        ['pointerdown', 'keydown'].forEach(function (evtName) {
            document.addEventListener(evtName, unlockAudioOnGesture, { passive: true });
        });

        if (btnToggle) {
            btnToggle.addEventListener('click', function () {
                soundEnabled = !soundEnabled;
                localStorage.setItem(SOUND_STORAGE_KEY, soundEnabled ? 'true' : 'false');
                updateSoundButtonUi();
                unlockAudioOnGesture();
                syncAlarmLoop();
            });
        }

        async function refreshQueueTable() {
            const payModal = document.getElementById('modalPayOrder');
            if (payModal && payModal.classList.contains('show')) {
                return;
            }
            try {
                const res = await fetch(QUEUE_URL, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                if (!res.ok) return;
                const html = await res.text();
                const doc = new DOMParser().parseFromString(html, 'text/html');
                const newTbody = doc.getElementById('fnbQueueTableBody');
                const curTbody = document.getElementById('fnbQueueTableBody');
                if (newTbody && curTbody) {
                    curTbody.innerHTML = newTbody.innerHTML;
                }
                const newCount = doc.getElementById('fnbQueueOrderCount');
                const curCount = document.getElementById('fnbQueueOrderCount');
                if (newCount && curCount) {
                    curCount.textContent = newCount.textContent;
                }
            } catch (e) {
                // Ignore transient network errors during background refresh
            }
        }

        async function pollQueueStatus() {
            try {
                const res = await fetch(POLL_URL, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                if (!res.ok) return;
                const data = await res.json();
                pendingQrCount = Number(data.pending_qr_count || 0);
                pendingAlertCount = Number(data.pending_alert_count !== undefined ? data.pending_alert_count : (data.pending_qr_count || 0));
                pendingAlertOrders = Array.isArray(data.pending_alert_orders) ? data.pending_alert_orders : (Array.isArray(data.pending_qr_orders) ? data.pending_qr_orders : []);
                renderIncomingQrModal();
                syncAlarmLoop();

                if (lastSignature !== null && data.orders_signature !== lastSignature) {
                    await refreshQueueTable();
                }
                lastSignature = data.orders_signature;
            } catch (e) {
                // Ignore transient polling failures
            }
        }

        updateSoundButtonUi();
        syncAlarmLoop();
        pollQueueStatus();
        setInterval(pollQueueStatus, POLL_INTERVAL_MS);
    })();
</script>
@endsection
