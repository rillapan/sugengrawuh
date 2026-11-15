<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Status Pesanan #{{ $order->order_number }}</title>
    <link href="{{ asset('css/offline-fonts.css') }}" rel="stylesheet">
    <link href="{{ asset('css/sb-admin-2.min.css') }}" rel="stylesheet">
    <style>
        body {
            background-color: #f4f6f9;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            color: #2d3748;
            padding: 20px 16px;
        }
        .status-container {
            max-width: 550px;
            margin: 0 auto;
        }
        .status-card {
            background: #ffffff;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.06);
            border: 1px solid #edf2f7;
        }
        .status-badge {
            display: inline-block;
            padding: 6px 16px;
            border-radius: 9999px;
            font-weight: 700;
            font-size: 14px;
        }
        .badge-pending { background-color: #fff3cd; color: #856404; }
        .badge-accepted { background-color: #cce5ff; color: #004085; }
        .badge-preparing { background-color: #d1ecf1; color: #0c5460; }
        .badge-ready { background-color: #d4edda; color: #155724; }
        .badge-delivered { background-color: #28a745; color: #ffffff; }
        .badge-cancelled { background-color: #f8d7da; color: #721c24; }
        .badge-expired { background-color: #e2e3e5; color: #383d41; }
        .item-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #f1f3f5;
        }
        .item-row:last-child {
            border-bottom: none;
        }
        .btn-refresh {
            background-color: #4e73df;
            color: #ffffff;
            font-weight: 600;
            border-radius: 8px;
            padding: 10px 20px;
            border: none;
            cursor: pointer;
            width: 100%;
            text-align: center;
            text-decoration: none;
            display: inline-block;
        }
        .btn-refresh:hover {
            background-color: #2e59d9;
            color: #ffffff;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="status-container">
        <div class="status-card">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <span class="badge badge-light border text-muted px-2 py-1">
                    📍 {{ $order->location->name ?? 'Stasiun' }}
                </span>
                <span class="text-muted small">
                    {{ $order->created_at->format('d M Y, H:i') }}
                </span>
            </div>

            <div class="text-center py-3">
                <div class="small text-muted text-uppercase font-weight-bold mb-1">Nomor Pesanan</div>
                <h2 class="h4 font-weight-bold text-gray-900 mb-3">{{ $order->order_number }}</h2>

                @if ($order->status === 'PENDING')
                    <span class="status-badge badge-pending">⏳ Menunggu Konfirmasi Dapur</span>
                @elseif ($order->status === 'ACCEPTED')
                    <span class="status-badge badge-accepted">👨‍🍳 Diterima & Disiapkan</span>
                @elseif ($order->status === 'PREPARING')
                    <span class="status-badge badge-preparing">🍳 Sedang Dimasak / Disiapkan</span>
                @elseif ($order->status === 'READY')
                    <span class="status-badge badge-ready">🔔 Pesanan Siap / Sedang Diantar</span>
                @elseif ($order->status === 'DELIVERED')
                    <span class="status-badge badge-delivered">✅ Pesanan Selesai Terkirim</span>
                @elseif ($order->status === 'CANCELLED')
                    <span class="status-badge badge-cancelled">❌ Pesanan Dibatalkan</span>
                @elseif ($order->status === 'EXPIRED')
                    <span class="status-badge badge-expired">⌛ Pesanan Kedaluwarsa</span>
                @else
                    <span class="status-badge badge-pending">{{ $order->status }}</span>
                @endif
            </div>

            <!-- Payment Policy Instruction -->
            <div class="alert {{ $order->requires_upfront_payment ? 'alert-warning' : 'alert-info' }} my-3 small">
                @if ($order->requires_upfront_payment)
                    <strong>💰 Informasi Pembayaran:</strong> Anda memesan pada sesi Paket. Pembayaran dapat dilakukan kepada staf saat pesanan diantarkan atau setelah sesi bermain selesai.
                @else
                    <strong>📋 Tagihan Lost Time:</strong> Pesanan ini dicatat ke sesi bermain Anda dan dibayarkan saat checkout di kasir.
                @endif
            </div>

            <div class="bg-light p-3 rounded my-3">
                <div class="d-flex justify-content-between small text-muted mb-1">
                    <span>Pemesan:</span>
                    <strong class="text-gray-900">{{ $order->customer_name }}</strong>
                </div>
                @if ($order->notes)
                    <div class="d-flex justify-content-between small text-muted">
                        <span>Catatan:</span>
                        <span class="text-gray-800 text-right">{{ $order->notes }}</span>
                    </div>
                @endif
            </div>

            <h6 class="font-weight-bold text-gray-800 mt-4 mb-2">Rincian Item Pesanan</h6>
            <div class="mb-3">
                @foreach ($order->items as $item)
                    <div class="item-row">
                        <div class="d-flex align-items-center" style="min-width: 0; flex: 1 1 auto; margin-right: 12px;">
                            <img src="{{ $item->fnb ? $item->fnb->image_url : asset('img/fnb-placeholder.svg') }}" alt="{{ $item->fnb->nama ?? 'F&B' }}" style="width: 44px; height: 44px; min-width: 44px; object-fit: cover; border-radius: 6px; border: 1px solid #e3e6f0; margin-right: 12px;">
                            <div style="min-width: 0;">
                                <div class="font-weight-bold text-gray-800 text-truncate">{{ $item->fnb->nama ?? 'Menu F&B' }}</div>
                                <div class="small text-muted">
                                    {{ $item->qty }}x @ Rp {{ number_format($item->unit_price_jual, 0, ',', '.') }}
                                    @if ($item->notes)
                                        <span class="text-info">({{ $item->notes }})</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                        <div class="font-weight-bold text-gray-900" style="flex-shrink: 0;">
                            Rp {{ number_format($item->subtotal, 0, ',', '.') }}
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="d-flex justify-content-between align-items-center pt-3 border-top mb-4">
                <span class="h6 font-weight-bold text-gray-800 mb-0">Total Pembayaran:</span>
                <span class="h5 font-weight-bold text-primary mb-0">
                    Rp {{ number_format($order->total_amount, 0, ',', '.') }}
                </span>
            </div>

            <a href="javascript:location.reload()" class="btn-refresh">
                🔄 Cek Status Terkini
            </a>
        </div>
    </div>
</body>
</html>
