<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no, viewport-fit=cover">
    <title>Menu Pemesanan F&B - {{ $location->name }}</title>
    <link href="{{ asset('css/offline-fonts.css') }}" rel="stylesheet">
    <link href="{{ asset('css/sb-admin-2.min.css') }}" rel="stylesheet">
    <style>
        body {
            background-color: #f4f6f9;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            color: #2d3748;
            padding-bottom: 105px;
            overflow-x: hidden;
        }
        body.modal-open {
            overflow: hidden;
        }

        /* Header Station */
        .header-card {
            background: linear-gradient(135deg, #4e73df 0%, #224abe 100%);
            color: #ffffff;
            border-radius: 0 0 24px 24px;
            padding: 20px 18px 22px;
            margin-bottom: 20px;
            box-shadow: 0 4px 16px rgba(78, 115, 223, 0.25);
        }
        .location-pill {
            background: rgba(255, 255, 255, 0.2);
            padding: 5px 12px;
            border-radius: 9999px;
            font-size: 13px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
        }
        .btn-header-orders {
            background: #ffffff;
            color: #224abe;
            border: none;
            border-radius: 9999px;
            padding: 6px 14px;
            font-size: 13px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12);
            transition: transform 0.1s ease, background-color 0.15s ease;
            text-decoration: none;
        }
        .btn-header-orders:hover {
            background: #f8f9fc;
            color: #1e3d99;
            transform: translateY(-1px);
        }
        .btn-header-orders:active {
            transform: translateY(0);
        }

        /* F&B Cards */
        .fnb-card {
            background: #ffffff;
            border-radius: 14px;
            padding: 14px 16px;
            margin-bottom: 12px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
            border: 1px solid #edf2f7;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .fnb-img-wrapper {
            width: 68px;
            height: 68px;
            min-width: 68px;
            border-radius: 10px;
            overflow: hidden;
            background: #f8f9fc;
            border: 1px solid #e3e6f0;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            cursor: pointer;
            flex-shrink: 0;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        .fnb-img-wrapper:hover {
            transform: scale(1.02);
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.1);
        }
        .fnb-img-wrapper img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .fnb-img-zoom-badge {
            position: absolute;
            bottom: 3px;
            right: 3px;
            background: rgba(0, 0, 0, 0.55);
            color: #ffffff;
            border-radius: 4px;
            font-size: 10px;
            padding: 1px 4px;
            line-height: 1;
            pointer-events: none;
        }
        .fnb-name {
            font-weight: 600;
            font-size: 15px;
            color: #1a202c;
            margin-bottom: 3px;
            line-height: 1.3;
        }
        .fnb-price {
            font-weight: 700;
            color: #4e73df;
            font-size: 14px;
            margin-bottom: 2px;
        }
        .fnb-stock {
            font-size: 12px;
            color: #718096;
        }

        /* Quantity Stepper */
        .qty-stepper {
            display: flex;
            align-items: center;
            background: #f7fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            overflow: hidden;
            flex-shrink: 0;
        }
        .qty-btn {
            background: none;
            border: none;
            width: 36px;
            height: 36px;
            font-size: 18px;
            font-weight: 700;
            color: #4e73df;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            user-select: none;
            transition: background-color 0.1s ease;
        }
        .qty-btn:active {
            background-color: #edf2f7;
        }
        .qty-input {
            width: 36px;
            text-align: center;
            border: none;
            background: transparent;
            font-weight: 600;
            font-size: 15px;
            color: #2d3748;
        }

        /* Floating Footer */
        .floating-footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: #ffffff;
            border-top: 1px solid #e2e8f0;
            padding: 12px 20px;
            padding-bottom: max(12px, env(safe-area-inset-bottom));
            box-shadow: 0 -4px 15px rgba(0, 0, 0, 0.08);
            display: flex;
            align-items: center;
            justify-content: space-between;
            z-index: 1000;
        }
        .total-label {
            font-size: 11px;
            color: #718096;
            text-transform: uppercase;
            font-weight: 600;
        }
        .total-amount {
            font-size: 18px;
            font-weight: 700;
            color: #1a202c;
        }
        .btn-order-submit {
            background-color: #1cc88a;
            color: #ffffff;
            border: none;
            border-radius: 10px;
            font-weight: 700;
            font-size: 15px;
            padding: 12px 24px;
            cursor: pointer;
            transition: background 0.15s ease-in-out;
        }
        .btn-order-submit:disabled {
            background-color: #cbd5e0;
            cursor: not-allowed;
        }

        /* Shared Modal Transitions */
        .modal {
            transition: opacity 0.15s linear;
        }
        .modal.fade .modal-dialog {
            transition: transform 0.2s ease-out;
            transform: translate(0, -20px);
        }
        .modal.show .modal-dialog {
            transform: none;
        }
        .border-bottom-dashed {
            border-bottom: 1px dashed #e3e6f0;
        }

        /* Lightbox Image Preview Modal */
        #imageLightboxModal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.88);
            z-index: 1100;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 16px;
            padding-bottom: max(16px, env(safe-area-inset-bottom));
            opacity: 0;
            transition: opacity 0.2s ease;
        }
        #imageLightboxModal.show {
            display: flex;
            opacity: 1;
        }
        .lightbox-dialog {
            max-width: 90vw;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
        }
        .lightbox-close-btn {
            position: absolute;
            top: -42px;
            right: 0;
            background: rgba(255, 255, 255, 0.25);
            border: none;
            color: #ffffff;
            font-size: 24px;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            line-height: 1;
            transition: background 0.15s ease;
        }
        .lightbox-close-btn:hover {
            background: rgba(255, 255, 255, 0.4);
        }
        .lightbox-box {
            background: #ffffff;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
            max-width: 100%;
            max-height: 80vh;
            display: flex;
            flex-direction: column;
        }
        .lightbox-media {
            flex: 1 1 auto;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #111827;
            max-height: 65vh;
            min-height: 180px;
        }
        .lightbox-media img {
            max-width: 100%;
            max-height: 65vh;
            object-fit: contain;
            display: block;
        }
        .lightbox-caption {
            padding: 12px 16px;
            background: #ffffff;
            border-top: 1px solid #edf2f7;
            text-align: center;
        }

        /* Responsive Breakpoints */
        @media (max-width: 576px) {
            .header-card {
                padding: 16px 14px 18px;
                border-radius: 0 0 20px 20px;
            }
            .fnb-card {
                padding: 12px;
            }
            .fnb-img-wrapper {
                width: 60px;
                height: 60px;
                min-width: 60px;
            }
            .lightbox-dialog {
                max-width: 95vw;
            }
            .lightbox-media {
                max-height: 60vh;
            }
            .lightbox-media img {
                max-height: 60vh;
            }
            .modal-dialog-scrollable .modal-content {
                max-height: 85vh;
            }
        }
    </style>
</head>
<body>
    <!-- Header Station -->
    <div class="header-card">
        <div class="container" style="max-width: 600px; padding: 0;">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <div class="location-pill">📍 {{ $location->name }} ({{ $location->code }})</div>
                <button type="button" class="btn-header-orders" onclick="openActiveOrdersModal()" aria-label="Lihat Pesanan Saya">
                    <span class="mr-1">📋</span>
                    <span>Pesanan Saya</span>
                    <span class="badge badge-pill badge-primary ml-1" id="active-orders-count-badge">{{ count($activeOrders ?? []) }}</span>
                </button>
            </div>
            <div class="small font-weight-bold mb-2">
                Nama Pemain: <span class="text-white">{{ $playerName ?? 'Belum Ada Pemain' }}</span>
            </div>
            <div class="text-center pt-1">
                <h1 class="h4 font-weight-bold mb-1 text-white">Menu Makanan &amp; Minuman</h1>
                <p class="mb-0 small" style="opacity: 0.9;">Pesan langsung ke stasiun bermain Anda</p>
            </div>
        </div>
    </div>

    <div class="container" style="max-width: 600px;">
        <div id="order-alert-container"></div>

        @if ($errors->any())
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <form id="qr-order-form" action="{{ route('public.qr.station.submit', $location->code) }}" method="POST" onsubmit="event.preventDefault(); handleOrderSubmitClick(event);">
            @csrf
            <input type="hidden" name="session_token" value="{{ $token }}">

            <!-- Form Pemesan -->
            <div class="card mb-3 shadow-sm border-0" style="border-radius: 14px;">
                <div class="card-body p-3">
                    <div class="form-group mb-2">
                        <label class="small font-weight-bold text-gray-700">Nama Pemesan <span class="text-danger">*</span></label>
                        <input type="text" name="customer_name" class="form-control" placeholder="Masukkan nama Anda (misal: Rendi)" required value="{{ old('customer_name') }}">
                    </div>
                    <div class="form-group mb-0">
                        <label class="small font-weight-bold text-gray-700">Catatan Pesanan (Opsional)</label>
                        <input type="text" name="notes" class="form-control" placeholder="Contoh: Es dipisah, jangan terlalu manis" value="{{ old('notes') }}">
                    </div>
                </div>
            </div>

            <!-- Katalog F&B -->
            <div class="mb-3">
                <h6 class="font-weight-bold text-gray-700 mb-2 px-1">Daftar Pilihan Menu</h6>

                @forelse ($fnbs as $index => $fnb)
                    <div class="fnb-card" data-fnb-id="{{ $fnb->id }}" data-price="{{ $fnb->effective_price }}">
                        <div class="d-flex align-items-center mr-3" style="min-width: 0; flex: 1 1 auto;">
                            <!-- Thumbnail with Lightbox Trigger -->
                            <div class="fnb-img-wrapper mr-3" 
                                 role="button" 
                                 tabindex="0"
                                 title="Klik untuk memperbesar gambar {{ $fnb->nama }}"
                                 data-name="{{ $fnb->nama }}"
                                 data-img="{{ $fnb->image_url }}"
                                 data-price="Rp {{ number_format($fnb->effective_price, 0, ',', '.') }}"
                                 onclick="handleThumbnailClick(this, event)"
                                 onkeydown="handleThumbnailKeydown(this, event)">
                                <img src="{{ $fnb->image_url }}" alt="{{ $fnb->nama }}" loading="lazy">
                                <div class="fnb-img-zoom-badge">🔍</div>
                            </div>
                            <div class="fnb-info" style="min-width: 0; flex: 1 1 auto;">
                                <div class="fnb-name">{{ $fnb->nama }}</div>
                                <div class="fnb-price">Rp {{ number_format($fnb->effective_price, 0, ',', '.') }}</div>
                                <div class="fnb-stock">
                                    @if ($fnb->stok == -1)
                                        <span class="text-success">● Selalu Tersedia</span>
                                    @else
                                        <span class="{{ $fnb->stok < 5 ? 'text-warning font-weight-bold' : 'text-muted' }}">
                                            Tersedia: {{ $fnb->stok }} porsi
                                        </span>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="qty-stepper">
                            <button type="button" class="qty-btn" onclick="changeQty({{ $fnb->id }}, -1)">−</button>
                            <input type="number" 
                                   id="qty-{{ $fnb->id }}" 
                                   class="qty-input" 
                                   value="0" 
                                   min="0" 
                                   max="{{ $fnb->stok == -1 ? 99 : $fnb->stok }}" 
                                   readonly>
                            <button type="button" class="qty-btn" onclick="changeQty({{ $fnb->id }}, 1, {{ $fnb->stok }})">+</button>
                        </div>

                        <!-- Dynamic hidden fields added when qty > 0 -->
                        <div id="hidden-inputs-{{ $fnb->id }}"></div>
                    </div>
                @empty
                    <div class="alert alert-info text-center" style="border-radius: 12px;">
                        Saat ini belum ada menu makanan/minuman yang tersedia.
                    </div>
                @endforelse
            </div>

            <!-- Sticky Bottom Bar -->
            <div class="floating-footer">
                <div>
                    <div class="total-label">Total Pesanan</div>
                    <div class="total-amount" id="total-display">Rp 0</div>
                </div>
                <button type="button" id="btn-submit" class="btn-order-submit" onclick="handleOrderSubmitClick(event)" disabled>
                    Pesan Sekarang
                </button>
            </div>
        </form>
    </div>

    <!-- Modal Pesanan Saya -->
    <div class="modal fade" id="activeOrdersModal" tabindex="-1" role="dialog" aria-labelledby="activeOrdersModalLabel" aria-hidden="true" style="display: none; background: rgba(0, 0, 0, 0.6); z-index: 1050; overflow-y: auto;">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" role="document" style="margin: 1.75rem auto; max-width: 520px; width: 95%;">
            <div class="modal-content shadow-lg border-0 rounded-lg">
                <div class="modal-header bg-primary text-white py-3">
                    <h5 class="modal-title font-weight-bold h6 mb-0 d-flex align-items-center" id="activeOrdersModalLabel">
                        <span class="mr-2">📋</span>
                        <span>Pesanan Saya</span>
                        <span class="badge badge-pill badge-light text-primary ml-2 font-weight-bold" id="active-orders-count">{{ count($activeOrders ?? []) }}</span>
                    </h5>
                    <button type="button" class="close text-white" aria-label="Close" onclick="closeActiveOrdersModal()">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body p-3" id="active-orders-modal-body" style="max-height: 70vh; overflow-y: auto;">
                    <div id="pesanan-saya-card">
                        <div id="active-orders-container">
                            @forelse ($activeOrders ?? [] as $order)
                                <div class="border rounded p-3 mb-3 bg-white shadow-sm order-card-item">
                                    <div class="d-flex justify-content-between align-items-start mb-2 pb-2 border-bottom">
                                        <div>
                                            <div class="font-weight-bold text-dark">#{{ $order->order_number }}</div>
                                            <div class="small text-muted">
                                                Waktu: {{ $order->created_at ? $order->created_at->format('H:i') : '-' }} &bull; Pemesan: <span class="font-weight-bold text-gray-800">{{ $order->customer_name }}</span>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            @if ($order->status === 'PENDING')
                                                <span class="badge badge-warning">Menunggu Dapur</span>
                                            @elseif ($order->status === 'ACCEPTED')
                                                <span class="badge badge-info">Diterima Dapur</span>
                                            @elseif ($order->status === 'PREPARING')
                                                <span class="badge badge-primary">Sedang Disiapkan</span>
                                            @elseif ($order->status === 'READY')
                                                <span class="badge badge-success font-weight-bold">Siap Diantar</span>
                                            @elseif ($order->status === 'DELIVERED')
                                                <span class="badge badge-secondary">Selesai Diantar</span>
                                            @else
                                                <span class="badge badge-light border">{{ $order->status }}</span>
                                            @endif

                                            <div class="mt-1">
                                                @if (strtolower($order->payment_status) === 'paid')
                                                    <span class="badge badge-success">Lunas</span>
                                                @else
                                                    <span class="badge badge-warning">Belum Bayar</span>
                                                @endif
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mb-2">
                                        <div class="small text-muted font-weight-bold mb-1">Rincian Menu:</div>
                                        <ul class="list-unstyled mb-0 small">
                                            @foreach ($order->items as $item)
                                                <li class="d-flex justify-content-between py-1 border-bottom-dashed">
                                                    <span>{{ $item->qty }}x {{ $item->fnb ? $item->fnb->nama : 'Menu Dihapus' }}</span>
                                                    <span class="text-muted">Rp {{ number_format($item->subtotal, 0, ',', '.') }}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>

                                    @if ($order->notes)
                                        <div class="small text-muted bg-light p-2 rounded mb-2">
                                            <em>Catatan: {{ $order->notes }}</em>
                                        </div>
                                    @endif

                                    <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                                        <span class="small font-weight-bold text-gray-700">Total Pesanan:</span>
                                        <span class="font-weight-bold text-primary">Rp {{ number_format($order->total_amount, 0, ',', '.') }}</span>
                                    </div>
                                </div>
                            @empty
                                <div class="alert alert-light border text-center text-muted small py-3 mb-0" id="no-active-orders-message">
                                    Belum ada pesanan aktif untuk sesi ini.
                                </div>
                            @endforelse
                        </div>
                    </div>
                </div>
                <div class="modal-footer p-2 bg-light border-top d-flex justify-content-end">
                    <button type="button" class="btn btn-secondary btn-sm font-weight-bold px-3" onclick="closeActiveOrdersModal()">
                        Tutup
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Konfirmasi Pesanan -->
    <div class="modal fade" id="confirmOrderModal" tabindex="-1" role="dialog" aria-labelledby="confirmOrderModalLabel" aria-hidden="true" style="display: none; background: rgba(0, 0, 0, 0.6); z-index: 1050; overflow-y: auto;">
        <div class="modal-dialog modal-dialog-centered" role="document" style="margin: 1.75rem auto; max-width: 500px; width: 95%;">
            <div class="modal-content shadow border-0 rounded-lg">
                <div class="modal-header bg-primary text-white py-3">
                    <h5 class="modal-title font-weight-bold h6 mb-0" id="confirmOrderModalLabel">Konfirmasi Pesanan</h5>
                    <button type="button" class="close text-white" aria-label="Close" onclick="closeConfirmModal()">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body p-3">
                    <!-- Detail Stasiun & Pemain -->
                    <div class="bg-light p-3 rounded mb-3 border">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted small">Station / Meja:</span>
                            <span class="font-weight-bold small text-dark">{{ $location->name }} ({{ $location->code }})</span>
                        </div>
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted small">Pemain Utama:</span>
                            <span class="font-weight-bold small text-dark">{{ $playerName ?? 'Belum Ada Pemain' }}</span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted small">Nama Pemesan:</span>
                            <span class="font-weight-bold small text-primary" id="modal-customer-name">-</span>
                        </div>
                    </div>

                    <!-- Rincian Menu -->
                    <h6 class="font-weight-bold text-gray-800 small mb-2">Rincian Menu:</h6>
                    <div id="modal-items-list" class="mb-3">
                        <!-- Dynamic itemized list inserted here -->
                    </div>

                    <div class="d-flex justify-content-between align-items-center border-top pt-2 mb-3">
                        <span class="font-weight-bold text-gray-800">Total Pesanan:</span>
                        <span class="font-weight-bold text-primary h5 mb-0" id="modal-total-price">Rp 0</span>
                    </div>

                    <div id="modal-notes-container" class="alert alert-secondary small py-2 px-3 mb-3" style="display: none;">
                        <strong>Catatan:</strong> <span id="modal-notes-text"></span>
                    </div>

                    <!-- Informasi Pembayaran -->
                    <div class="alert {{ ($sessionType ?? null) === 'postpaid' ? 'alert-info' : 'alert-warning' }} small mb-0">
                        @if (($sessionType ?? null) === 'postpaid')
                            <strong>Informasi Pembayaran:</strong> Anda memesan pada sesi Lost Time. Tagihan F&B akan dicatat ke tagihan meja Anda dan dibayarkan saat checkout permainan.
                        @else
                            <strong>Informasi Pembayaran:</strong> Anda memesan pada sesi Paket. Pembayaran dapat dilakukan kepada staf saat pesanan diantarkan atau setelah sesi bermain selesai.
                        @endif
                    </div>
                </div>
                <div class="modal-footer p-3 bg-light border-top">
                    <button type="button" id="btn-modal-cancel" class="btn btn-secondary font-weight-bold px-3" onclick="closeConfirmModal()">
                        Kembali / Edit Pesanan
                    </button>
                    <button type="button" id="btn-modal-confirm" class="btn btn-success font-weight-bold px-3" onclick="submitConfirmedOrder()">
                        Ya, Konfirmasi Pesanan
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Preview Gambar Produk (Lightbox) -->
    <div id="imageLightboxModal" tabindex="-1" role="dialog" aria-hidden="true" onclick="closeImageLightbox()">
        <div class="lightbox-dialog" onclick="event.stopPropagation()">
            <button type="button" class="lightbox-close-btn" aria-label="Tutup Preview Gambar" onclick="closeImageLightbox()">
                &times;
            </button>
            <div class="lightbox-box">
                <div class="lightbox-media">
                    <img id="lightbox-img" src="" alt="Preview Produk">
                </div>
                <div class="lightbox-caption">
                    <div id="lightbox-title" class="font-weight-bold text-dark" style="font-size: 16px;"></div>
                    <div id="lightbox-price" class="font-weight-bold text-primary" style="font-size: 15px;"></div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const fnbPrices = {
            @foreach ($fnbs as $fnb)
                {{ $fnb->id }}: {{ (float) $fnb->effective_price }},
            @endforeach
        };

        const fnbNames = {
            @foreach ($fnbs as $fnb)
                {{ $fnb->id }}: @json($fnb->nama),
            @endforeach
        };

        const fnbMaxStocks = {
            @foreach ($fnbs as $fnb)
                {{ $fnb->id }}: {{ (int) $fnb->stok }},
            @endforeach
        };

        function changeQty(fnbId, delta, maxStock = -1) {
            const input = document.getElementById('qty-' + fnbId);
            let current = parseInt(input.value) || 0;
            let next = current + delta;

            if (next < 0) next = 0;
            if (maxStock !== -1 && next > maxStock) {
                alert('Maksimal stok tersedia adalah ' + maxStock);
                next = maxStock;
            }

            input.value = next;
            updateHiddenFields(fnbId, next);
            updateTotal();
        }

        function updateHiddenFields(fnbId, qty) {
            const container = document.getElementById('hidden-inputs-' + fnbId);
            if (qty > 0) {
                container.innerHTML = `
                    <input type="hidden" name="items[${fnbId}][fnb_id]" value="${fnbId}">
                    <input type="hidden" name="items[${fnbId}][qty]" value="${qty}">
                    <input type="hidden" name="items[${fnbId}][expected_price]" value="${fnbPrices[fnbId]}">
                `;
            } else {
                container.innerHTML = '';
            }
        }

        function updateTotal() {
            let total = 0;
            let totalQty = 0;

            for (const fnbId in fnbPrices) {
                const input = document.getElementById('qty-' + fnbId);
                const qty = parseInt(input ? input.value : 0) || 0;
                total += qty * fnbPrices[fnbId];
                totalQty += qty;
            }

            document.getElementById('total-display').textContent = 'Rp ' + total.toLocaleString('id-ID');
            document.getElementById('btn-submit').disabled = (totalQty === 0);
        }

        /* Modal "Pesanan Saya" Controllers */
        function openActiveOrdersModal() {
            const modal = document.getElementById('activeOrdersModal');
            if (!modal) return;
            modal.style.display = 'block';
            setTimeout(() => { modal.classList.add('show'); }, 10);
            updateBodyScrollLock();
        }

        function closeActiveOrdersModal() {
            const modal = document.getElementById('activeOrdersModal');
            if (!modal) return;
            modal.classList.remove('show');
            setTimeout(() => {
                modal.style.display = 'none';
                updateBodyScrollLock();
            }, 150);
        }

        /* Modal Konfirmasi Pesanan Controllers */
        function openConfirmModal() {
            const modal = document.getElementById('confirmOrderModal');
            if (!modal) return;
            modal.style.display = 'block';
            setTimeout(() => { modal.classList.add('show'); }, 10);
            updateBodyScrollLock();
        }

        function closeConfirmModal() {
            const modal = document.getElementById('confirmOrderModal');
            if (!modal) return;
            modal.classList.remove('show');
            setTimeout(() => {
                modal.style.display = 'none';
                updateBodyScrollLock();
            }, 150);
        }

        /* Thumbnail Lightbox Trigger Handlers */
        function handleThumbnailClick(element, event) {
            if (event) {
                event.stopPropagation();
                event.preventDefault();
            }
            if (!element) return;
            const name = element.getAttribute('data-name') || '';
            const img = element.getAttribute('data-img') || '';
            const price = element.getAttribute('data-price') || '';
            openImageLightbox(name, img, price);
        }

        function handleThumbnailKeydown(element, event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                event.stopPropagation();
                handleThumbnailClick(element, event);
            }
        }

        /* Modal Preview Gambar (Lightbox) Controllers */
        function openImageLightbox(title, imgUrl, priceFormatted) {
            const modal = document.getElementById('imageLightboxModal');
            const img = document.getElementById('lightbox-img');
            const titleEl = document.getElementById('lightbox-title');
            const priceEl = document.getElementById('lightbox-price');
            if (!modal || !img) return;

            img.src = imgUrl;
            img.alt = title;
            if (titleEl) titleEl.textContent = title;
            if (priceEl) priceEl.textContent = priceFormatted;

            modal.style.display = 'flex';
            setTimeout(() => { modal.classList.add('show'); }, 10);
            updateBodyScrollLock();
        }

        function closeImageLightbox() {
            const modal = document.getElementById('imageLightboxModal');
            if (!modal) return;
            modal.classList.remove('show');
            setTimeout(() => {
                modal.style.display = 'none';
                updateBodyScrollLock();
            }, 150);
        }

        function updateBodyScrollLock() {
            const activeModal = document.getElementById('activeOrdersModal');
            const confirmModal = document.getElementById('confirmOrderModal');
            const lightboxModal = document.getElementById('imageLightboxModal');

            const hasOpenModal = (activeModal && activeModal.classList.contains('show')) ||
                                 (confirmModal && confirmModal.classList.contains('show')) ||
                                 (lightboxModal && lightboxModal.classList.contains('show'));

            if (hasOpenModal) {
                document.body.classList.add('modal-open');
            } else {
                document.body.classList.remove('modal-open');
            }
        }

        // Close modals if backdrop clicked
        window.addEventListener('click', function(event) {
            const confirmModal = document.getElementById('confirmOrderModal');
            if (event.target === confirmModal) {
                const btnCancel = document.getElementById('btn-modal-cancel');
                if (btnCancel && !btnCancel.disabled) {
                    closeConfirmModal();
                }
            }
            const activeOrdersModal = document.getElementById('activeOrdersModal');
            if (event.target === activeOrdersModal) {
                closeActiveOrdersModal();
            }
            const lightboxModal = document.getElementById('imageLightboxModal');
            if (event.target === lightboxModal) {
                closeImageLightbox();
            }
        });

        // Close modals on Escape key
        window.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                const lightboxModal = document.getElementById('imageLightboxModal');
                if (lightboxModal && lightboxModal.classList.contains('show')) {
                    closeImageLightbox();
                    return;
                }
                const activeOrdersModal = document.getElementById('activeOrdersModal');
                if (activeOrdersModal && activeOrdersModal.classList.contains('show')) {
                    closeActiveOrdersModal();
                    return;
                }
                const confirmModal = document.getElementById('confirmOrderModal');
                if (confirmModal && confirmModal.classList.contains('show')) {
                    const btnCancel = document.getElementById('btn-modal-cancel');
                    if (btnCancel && !btnCancel.disabled) {
                        closeConfirmModal();
                    }
                }
            }
        });

        function handleOrderSubmitClick(event) {
            if (event) event.preventDefault();

            const customerNameInput = document.querySelector('input[name="customer_name"]');
            const customerName = (customerNameInput ? customerNameInput.value : '').trim();

            if (!customerName) {
                alert('Silakan masukkan nama pemesan terlebih dahulu.');
                if (customerNameInput) customerNameInput.focus();
                return;
            }

            let totalQty = 0;
            let itemsList = [];

            for (const fnbId in fnbPrices) {
                const qtyInput = document.getElementById('qty-' + fnbId);
                const qty = parseInt(qtyInput ? qtyInput.value : 0) || 0;
                if (qty > 0) {
                    totalQty += qty;
                    itemsList.push({
                        id: fnbId,
                        name: fnbNames[fnbId] || ('Item #' + fnbId),
                        qty: qty,
                        price: fnbPrices[fnbId],
                        subtotal: qty * fnbPrices[fnbId]
                    });
                }
            }

            if (totalQty === 0 || itemsList.length === 0) {
                alert('Pilih minimal 1 menu makanan atau minuman.');
                return;
            }

            // Populate confirmation modal
            document.getElementById('modal-customer-name').textContent = customerName;

            let itemsHtml = '<div class="table-responsive"><table class="table table-sm table-borderless mb-0"><tbody>';
            let totalPrice = 0;
            itemsList.forEach(item => {
                totalPrice += item.subtotal;
                itemsHtml += `
                    <tr class="border-bottom">
                        <td class="pl-0 py-2">
                            <div class="font-weight-bold text-dark">${escapeHtml(item.name)}</div>
                            <div class="text-muted small">Rp ${item.price.toLocaleString('id-ID')}</div>
                        </td>
                        <td class="text-center font-weight-bold text-muted align-middle py-2">${item.qty}x</td>
                        <td class="text-right font-weight-bold align-middle pr-0 py-2">Rp ${item.subtotal.toLocaleString('id-ID')}</td>
                    </tr>
                `;
            });
            itemsHtml += '</tbody></table></div>';
            document.getElementById('modal-items-list').innerHTML = itemsHtml;
            document.getElementById('modal-total-price').textContent = 'Rp ' + totalPrice.toLocaleString('id-ID');

            // Notes
            const notesInput = document.querySelector('input[name="notes"]');
            const notes = notesInput ? notesInput.value.trim() : '';
            const modalNotesContainer = document.getElementById('modal-notes-container');
            const modalNotesText = document.getElementById('modal-notes-text');
            if (notes) {
                modalNotesText.textContent = notes;
                modalNotesContainer.style.display = 'block';
            } else {
                modalNotesContainer.style.display = 'none';
            }

            openConfirmModal();
        }

        function submitConfirmedOrder() {
            const form = document.getElementById('qr-order-form');
            const btnConfirm = document.getElementById('btn-modal-confirm');
            const btnCancel = document.getElementById('btn-modal-cancel');

            // Client-side idempotency key generation
            const idempotencyKey = 'idem_' + Date.now() + '_' + Math.random().toString(36).substring(2, 10);

            const formData = new FormData(form);
            formData.set('idempotency_key', idempotencyKey);

            // Double-submit protection
            btnConfirm.disabled = true;
            btnCancel.disabled = true;
            const originalConfirmText = btnConfirm.innerHTML;
            btnConfirm.innerHTML = 'Mengirim Pesanan...';

            fetch(form.action, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            })
            .then(async (response) => {
                if (response.status === 201) {
                    const data = await response.json();
                    closeConfirmModal();

                    // Reset quantities and total
                    for (const fnbId in fnbPrices) {
                        const input = document.getElementById('qty-' + fnbId);
                        if (input) input.value = 0;
                        updateHiddenFields(fnbId, 0);
                    }
                    const notesInput = document.querySelector('input[name="notes"]');
                    if (notesInput) notesInput.value = '';
                    updateTotal();

                    // Show success banner with action to open Pesanan Saya
                    const alertContainer = document.getElementById('order-alert-container');
                    if (alertContainer) {
                        const orderNum = data.order ? data.order.order_number : '';
                        alertContainer.innerHTML = `
                            <div class="alert alert-success alert-dismissible fade show shadow-sm mb-3" role="alert" style="border-radius: 12px;">
                                <div class="font-weight-bold mb-1">Pesanan Berhasil Dikirim!</div>
                                <div class="mb-2">Pesanan Anda telah dicatat dengan Nomor Pesanan: <strong>#${escapeHtml(orderNum)}</strong></div>
                                <button type="button" class="btn btn-sm btn-success font-weight-bold" onclick="openActiveOrdersModal()">
                                    📋 Lihat Pesanan Saya
                                </button>
                                <button type="button" class="close" data-dismiss="alert" aria-label="Close" onclick="this.parentElement.remove()">
                                    <span aria-hidden="true">&times;</span>
                                </button>
                            </div>
                        `;
                        window.scrollTo({ top: 0, behavior: 'smooth' });
                    }

                    // Trigger active orders refresh if defined
                    if (typeof window.loadActiveOrders === 'function') {
                        window.loadActiveOrders();
                    }
                } else if (response.status === 403) {
                    alert('Sesi bermain telah berakhir atau token tidak valid. Silakan hubungi kasir/staf F&B.');
                    window.location.reload();
                } else if (response.status === 422) {
                    const errorData = await response.json().catch(() => ({}));
                    let message = errorData.message || 'Validasi gagal. Silakan periksa kembali data pesanan.';
                    if (errorData.errors) {
                        const firstKey = Object.keys(errorData.errors)[0];
                        if (firstKey && errorData.errors[firstKey][0]) {
                            message = errorData.errors[firstKey][0];
                        }
                    }
                    alert(message);
                } else {
                    const errorData = await response.json().catch(() => ({}));
                    alert(errorData.message || 'Terjadi kesalahan sistem saat memproses pesanan.');
                }
            })
            .catch((err) => {
                console.error('Submit error:', err);
                alert('Terjadi kesalahan koneksi. Silakan periksa jaringan dan coba lagi.');
            })
            .finally(() => {
                btnConfirm.disabled = false;
                btnCancel.disabled = false;
                btnConfirm.innerHTML = originalConfirmText;
            });
        }

        function escapeHtml(text) {
            if (text === null || text === undefined) return '';
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
        }

        function getKitchenBadge(status) {
            switch (status) {
                case 'PENDING':
                    return '<span class="badge badge-warning">Menunggu Dapur</span>';
                case 'ACCEPTED':
                    return '<span class="badge badge-info">Diterima Dapur</span>';
                case 'PREPARING':
                    return '<span class="badge badge-primary">Sedang Disiapkan</span>';
                case 'READY':
                    return '<span class="badge badge-success font-weight-bold">Siap Diantar</span>';
                case 'DELIVERED':
                    return '<span class="badge badge-secondary">Selesai Diantar</span>';
                default:
                    return `<span class="badge badge-light border">${escapeHtml(status)}</span>`;
            }
        }

        function getPaymentBadge(paymentStatus) {
            if (String(paymentStatus).toLowerCase() === 'paid') {
                return '<span class="badge badge-success">Lunas</span>';
            }
            return '<span class="badge badge-warning">Belum Bayar</span>';
        }

        function renderActiveOrders(orders) {
            const container = document.getElementById('active-orders-container');
            const countBadge = document.getElementById('active-orders-count');
            const headerCountBadge = document.getElementById('active-orders-count-badge');
            if (!container) return;

            const orderCount = Array.isArray(orders) ? orders.length : 0;
            if (countBadge) countBadge.textContent = orderCount;
            if (headerCountBadge) headerCountBadge.textContent = orderCount;

            if (!orders || orders.length === 0) {
                container.innerHTML = `
                    <div class="alert alert-light border text-center text-muted small py-3 mb-0" id="no-active-orders-message">
                        Belum ada pesanan aktif untuk sesi ini.
                    </div>
                `;
                return;
            }

            let html = '';
            orders.forEach(order => {
                const orderTime = order.created_time || order.created_at_formatted || (order.created_at ? new Date(order.created_at).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }) : '-');
                const kitchenBadge = getKitchenBadge(order.status);
                const paymentBadge = getPaymentBadge(order.payment_status);
                const totalFormatted = 'Rp ' + Number(order.total_amount).toLocaleString('id-ID');

                let itemsHtml = '';
                if (order.items && order.items.length > 0) {
                    order.items.forEach(item => {
                        const subtotalFormatted = 'Rp ' + Number(item.subtotal).toLocaleString('id-ID');
                        itemsHtml += `
                            <li class="d-flex justify-content-between py-1 border-bottom-dashed">
                                <span>${item.qty}x ${escapeHtml(item.name)}</span>
                                <span class="text-muted">${subtotalFormatted}</span>
                            </li>
                        `;
                    });
                }

                let notesHtml = '';
                if (order.notes) {
                    notesHtml = `
                        <div class="small text-muted bg-light p-2 rounded mb-2">
                            <em>Catatan: ${escapeHtml(order.notes)}</em>
                        </div>
                    `;
                }

                html += `
                    <div class="border rounded p-3 mb-3 bg-white shadow-sm order-card-item">
                        <div class="d-flex justify-content-between align-items-start mb-2 pb-2 border-bottom">
                            <div>
                                <div class="font-weight-bold text-dark">#${escapeHtml(order.order_number)}</div>
                                <div class="small text-muted">
                                    Waktu: ${escapeHtml(orderTime)} &bull; Pemesan: <span class="font-weight-bold text-gray-800">${escapeHtml(order.customer_name)}</span>
                                </div>
                            </div>
                            <div class="text-right">
                                ${kitchenBadge}
                                <div class="mt-1">
                                    ${paymentBadge}
                                </div>
                            </div>
                        </div>

                        <div class="mb-2">
                            <div class="small text-muted font-weight-bold mb-1">Rincian Menu:</div>
                            <ul class="list-unstyled mb-0 small">
                                ${itemsHtml}
                            </ul>
                        </div>

                        ${notesHtml}

                        <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                            <span class="small font-weight-bold text-gray-700">Total Pesanan:</span>
                            <span class="font-weight-bold text-primary">${totalFormatted}</span>
                        </div>
                    </div>
                `;
            });

            container.innerHTML = html;
        }

        window.loadActiveOrders = function() {
            const ordersUrl = '{{ route('public.qr.station.orders', $location->code) }}?token={{ $token }}';

            fetch(ordersUrl, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(async (response) => {
                if (response.status === 200) {
                    const data = await response.json();
                    if (data && data.success && Array.isArray(data.orders)) {
                        renderActiveOrders(data.orders);
                    }
                } else if (response.status === 403) {
                    alert('Sesi bermain telah berakhir atau token tidak valid. Silakan hubungi kasir/staf F&B.');
                    window.location.reload();
                }
            })
            .catch((err) => {
                console.warn('Active orders polling error:', err);
            });
        };

        // Poll active orders every 8 seconds
        setInterval(window.loadActiveOrders, 8000);
    </script>
</body>
</html>
