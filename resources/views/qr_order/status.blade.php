<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no, viewport-fit=cover">
    <title>Status Pesanan #{{ $order->order_number }} | Sugeng Rawuh</title>
    <link href="{{ asset('css/offline-fonts.css') }}" rel="stylesheet">
    <style>
        :root {
            --sr-primary: #3158C9;
            --sr-primary-hover: #2749AE;
            --sr-primary-soft: #EEF2FF;
            --sr-bg: #F8FAFC;
            --sr-surface: #FFFFFF;
            --sr-text-primary: #0F172A;
            --sr-text-secondary: #64748B;
            --sr-border: #E2E8F0;
            --sr-border-light: #F1F5F9;
            --sr-radius: 12px;
            --sr-radius-sm: 8px;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            background-color: var(--sr-bg);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            color: var(--sr-text-primary);
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
            padding: 24px 16px;
            min-height: 100vh;
            display: flex;
            align-items: flex-start;
            justify-content: center;
        }

        .status-container {
            width: 100%;
            max-width: 480px;
            margin: 0 auto;
        }

        .status-card {
            background: var(--sr-surface);
            border: 1px solid var(--sr-border);
            border-radius: var(--sr-radius);
            padding: 24px 20px;
            box-shadow: 0 1px 3px 0 rgba(15, 23, 42, 0.05);
        }

        .card-header-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 14px;
            border-bottom: 1px solid var(--sr-border-light);
        }

        .station-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background-color: var(--sr-primary-soft);
            color: var(--sr-primary);
            font-size: 12px;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 9999px;
            border: 1px solid #E0E7FF;
        }

        .order-time {
            font-size: 12px;
            color: var(--sr-text-secondary);
        }

        .order-header-center {
            text-align: center;
            padding: 6px 0 18px;
        }

        .order-number-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--sr-text-secondary);
            font-weight: 600;
            margin-bottom: 4px;
        }

        .order-number {
            font-size: 20px;
            font-weight: 700;
            color: var(--sr-text-primary);
            margin-bottom: 14px;
            letter-spacing: -0.01em;
        }

        /* Status Badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 9999px;
            font-weight: 600;
            font-size: 13px;
        }

        .badge-pending {
            background-color: #FEF3C7;
            color: #92400E;
            border: 1px solid #FDE68A;
        }

        .badge-accepted {
            background-color: #E0F2FE;
            color: #0369A1;
            border: 1px solid #BAE6FD;
        }

        .badge-preparing {
            background-color: #EEF2FF;
            color: #3730A3;
            border: 1px solid #C7D2FE;
        }

        .badge-ready {
            background-color: #DCFCE7;
            color: #166534;
            border: 1px solid #BBF7D0;
        }

        .badge-delivered {
            background-color: #F1F5F9;
            color: #334155;
            border: 1px solid #E2E8F0;
        }

        .badge-cancelled {
            background-color: #FEE2E2;
            color: #991B1B;
            border: 1px solid #FECACA;
        }

        .badge-expired {
            background-color: #F1F5F9;
            color: #64748B;
            border: 1px solid #E2E8F0;
        }

        /* Notice / Payment policy alert */
        .policy-alert {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            border-radius: var(--sr-radius-sm);
            padding: 12px 14px;
            font-size: 13px;
            line-height: 1.5;
            margin: 16px 0;
        }

        .policy-alert svg {
            flex-shrink: 0;
            margin-top: 2px;
        }

        .policy-alert-warning {
            background-color: #FEF3C7;
            border: 1px solid #FDE68A;
            color: #78350F;
        }

        .policy-alert-info {
            background-color: #E0F2FE;
            border: 1px solid #BAE6FD;
            color: #075985;
        }

        .customer-meta-card {
            background: #F8FAFC;
            border: 1px solid var(--sr-border);
            border-radius: var(--sr-radius-sm);
            padding: 12px 14px;
            margin-bottom: 18px;
            font-size: 13px;
        }

        .meta-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .meta-row + .meta-row {
            margin-top: 6px;
            padding-top: 6px;
            border-top: 1px dashed var(--sr-border);
        }

        .meta-label {
            color: var(--sr-text-secondary);
            font-weight: 500;
        }

        .meta-value {
            font-weight: 600;
            color: var(--sr-text-primary);
            text-align: right;
        }

        .section-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--sr-text-primary);
            margin-bottom: 10px;
        }

        .items-list {
            margin-bottom: 18px;
        }

        .item-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid var(--sr-border-light);
            gap: 12px;
        }

        .item-row:last-child {
            border-bottom: none;
        }

        .item-content {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
            flex: 1 1 auto;
        }

        .item-thumb {
            width: 44px;
            height: 44px;
            min-width: 44px;
            object-fit: cover;
            border-radius: 6px;
            border: 1px solid var(--sr-border);
            background: #F8FAFC;
        }

        .item-details {
            min-width: 0;
        }

        .item-name {
            font-weight: 600;
            font-size: 14px;
            color: var(--sr-text-primary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .item-subtext {
            font-size: 12px;
            color: var(--sr-text-secondary);
        }

        .item-notes {
            color: var(--sr-primary);
            font-size: 12px;
            font-style: italic;
        }

        .item-price {
            font-weight: 600;
            font-size: 14px;
            color: var(--sr-text-primary);
            flex-shrink: 0;
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 14px;
            border-top: 1px solid var(--sr-border);
            margin-bottom: 20px;
        }

        .total-label {
            font-size: 14px;
            font-weight: 600;
            color: var(--sr-text-secondary);
        }

        .total-amount {
            font-size: 18px;
            font-weight: 700;
            color: var(--sr-primary);
        }

        .btn-refresh {
            background-color: var(--sr-primary);
            color: #FFFFFF;
            font-weight: 600;
            font-size: 14px;
            border-radius: var(--sr-radius-sm);
            padding: 12px 18px;
            border: none;
            width: 100%;
            min-height: 44px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: background-color 0.15s ease;
        }

        .btn-refresh:hover {
            background-color: var(--sr-primary-hover);
            color: #FFFFFF;
            text-decoration: none;
        }

        .btn-refresh:focus-visible {
            outline: 2px solid var(--sr-primary);
            outline-offset: 2px;
        }
    </style>
</head>
<body>
    <main class="status-container">
        <div class="status-card">
            <div class="card-header-meta">
                <span class="station-badge">
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/>
                        <circle cx="12" cy="10" r="3"/>
                    </svg>
                    <span>{{ $order->location->name ?? 'Stasiun' }}</span>
                </span>
                <span class="order-time">
                    {{ $order->created_at->format('d M Y, H:i') }}
                </span>
            </div>

            <div class="order-header-center">
                <div class="order-number-label">Nomor Pesanan</div>
                <h1 class="order-number">{{ $order->order_number }}</h1>

                @if ($order->status === 'PENDING')
                    <span class="status-badge badge-pending">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        <span>Menunggu Konfirmasi Dapur</span>
                    </span>
                @elseif ($order->status === 'ACCEPTED')
                    <span class="status-badge badge-accepted">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>
                        <span>Diterima &amp; Disiapkan</span>
                    </span>
                @elseif ($order->status === 'PREPARING')
                    <span class="status-badge badge-preparing">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2c1 3 2.5 4.5 4 6 2 2 3 4.5 3 7a7 7 0 0 1-14 0c0-2.5 1-5 3-7 1.5-1.5 3-3 4-6Z"/></svg>
                        <span>Sedang Dimasak / Disiapkan</span>
                    </span>
                @elseif ($order->status === 'READY')
                    <span class="status-badge badge-ready">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
                        <span>Pesanan Siap / Sedang Diantar</span>
                    </span>
                @elseif ($order->status === 'DELIVERED')
                    <span class="status-badge badge-delivered">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        <span>Pesanan Selesai Terkirim</span>
                    </span>
                @elseif ($order->status === 'CANCELLED')
                    <span class="status-badge badge-cancelled">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>
                        <span>Pesanan Dibatalkan</span>
                    </span>
                @elseif ($order->status === 'EXPIRED')
                    <span class="status-badge badge-expired">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
                        <span>Pesanan Kedaluwarsa</span>
                    </span>
                @else
                    <span class="status-badge badge-pending">{{ $order->status }}</span>
                @endif
            </div>

            <!-- Payment Policy Instruction -->
            <div class="policy-alert {{ $order->requires_upfront_payment ? 'policy-alert-warning' : 'policy-alert-info' }}">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" x2="12" y1="8" y2="12"/>
                    <line x1="12" x2="12.01" y1="16" y2="16"/>
                </svg>
                <div>
                    @if ($order->requires_upfront_payment)
                        <strong>Informasi Pembayaran:</strong> Anda memesan pada sesi Paket. Pembayaran dapat dilakukan kepada staf saat pesanan diantarkan atau setelah sesi bermain selesai.
                    @else
                        <strong>Tagihan Lost Time:</strong> Pesanan ini dicatat ke sesi bermain Anda dan dibayarkan saat checkout di kasir.
                    @endif
                </div>
            </div>

            <div class="customer-meta-card">
                <div class="meta-row">
                    <span class="meta-label">Pemesan:</span>
                    <span class="meta-value">{{ $order->customer_name }}</span>
                </div>
                @if ($order->notes)
                    <div class="meta-row">
                        <span class="meta-label">Catatan:</span>
                        <span class="meta-value" style="font-weight: 500; color: var(--sr-text-secondary);">{{ $order->notes }}</span>
                    </div>
                @endif
            </div>

            <h2 class="section-title">Rincian Item Pesanan</h2>
            <div class="items-list">
                @foreach ($order->items as $item)
                    <div class="item-row">
                        <div class="item-content">
                            <img src="{{ $item->fnb ? $item->fnb->image_url : asset('img/fnb-placeholder.svg') }}" 
                                 alt="{{ $item->fnb->nama ?? 'Menu F&B' }}" 
                                 class="item-thumb" 
                                 loading="lazy">
                            <div class="item-details">
                                <div class="item-name">{{ $item->fnb->nama ?? 'Menu F&B' }}</div>
                                <div class="item-subtext">
                                    {{ $item->qty }}x @ Rp {{ number_format($item->unit_price_jual, 0, ',', '.') }}
                                    @if ($item->notes)
                                        <span class="item-notes">({{ $item->notes }})</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                        <div class="item-price">
                            Rp {{ number_format($item->subtotal, 0, ',', '.') }}
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="total-row">
                <span class="total-label">Total Pembayaran</span>
                <span class="total-amount">
                    Rp {{ number_format($order->total_amount, 0, ',', '.') }}
                </span>
            </div>

            <a href="javascript:location.reload()" class="btn-refresh">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/>
                    <path d="M21 3v5h-5"/>
                    <path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/>
                    <path d="M8 16H3v5"/>
                </svg>
                <span>Cek Status Terkini</span>
            </a>
        </div>
    </main>
</body>
</html>