@php
    $paymentMethod = $invoice->payment_method ?? 'tunai';
    $cashierName = $invoice->cashierUser ? $invoice->cashierUser->name : (auth()->user()->name ?? 'Kasir');
    $paymentDate = $invoice->paid_at ?? $invoice->created_at;
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Struk F&amp;B #{{ $invoice->invoice_number }}</title>
    @include('layouts._thermal_receipt_styles')
</head>
<body>
    <div class="no-print">
        <div class="receipt-btn-group">
            <button type="button" onclick="window.print();" class="receipt-btn btn-thermal-print">📷 Cetak Struk F&amp;B</button>
            <a href="{{ route('fnb.queue.index') }}" class="receipt-btn btn-thermal-back">⬅ Kembali ke Antrean</a>
        </div>
        @if(request('action') === 'payment')
            <div class="print-status-banner">
                <p>Sedang memproses cetak... Halaman akan dialihkan setelah selesai.</p>
            </div>
        @endif
    </div>

    <div class="receipt-container">
        <div class="receipt-body">
            <!-- Header -->
            <div class="receipt-header">
                <div class="store-title">Sugeng Rawuh</div>
                <div class="store-subtitle">PlayStation &amp; Billiard</div>
                <div class="store-meta">Kemplong Barat, Kemplong, Kec. Wiradesa</div>
                <div class="store-meta">Kab. Pekalongan, Jawa Tengah</div>
                <div class="store-meta">Telp: +62 857-2505-6741</div>
            </div>

            <div class="receipt-divider"></div>

            <!-- Invoice Info -->
            <table class="receipt-info-table">
                <tr>
                    <td class="info-label">No. Invoice</td>
                    <td class="info-value">{{ $invoice->invoice_number }}</td>
                </tr>
                <tr>
                    <td class="info-label">Tanggal</td>
                    <td class="info-value">{{ $paymentDate ? $paymentDate->format('d/m/Y H:i') : '-' }}</td>
                </tr>
                <tr>
                    <td class="info-label">Kasir</td>
                    <td class="info-value">{{ $cashierName }}</td>
                </tr>
                <tr>
                    <td class="info-label">Metode Bayar</td>
                    <td class="info-value">{{ strtoupper(str_replace('_', ' ', $paymentMethod)) }}</td>
                </tr>
                @if($invoice->transaction)
                <tr>
                    <td class="info-label">Sesi / Station</td>
                    <td class="info-value">
                        {{ $invoice->transaction->nama }}
                        @if($invoice->transaction->device)
                            ({{ $invoice->transaction->device->nama }})
                        @endif
                    </td>
                </tr>
                @else
                <tr>
                    <td class="info-label">Kategori</td>
                    <td class="info-value">Non-Bermain / Standalone</td>
                </tr>
                @endif
            </table>

            <div class="receipt-divider"></div>

            <!-- Item Table -->
            <table class="receipt-item-table">
                <thead>
                    <tr>
                        <th class="col-desc">ITEM / QTY</th>
                        <th class="col-amount">TOTAL</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($invoice->items as $item)
                    <tr class="item-title-row">
                        <td colspan="2" class="item-name">{{ $item->name }}</td>
                    </tr>
                    <tr class="item-detail-row">
                        <td class="item-calc">{{ $item->qty }} x Rp {{ number_format($item->unit_price_jual, 0, ',', '.') }}</td>
                        <td class="item-subtotal">Rp {{ number_format($item->subtotal, 0, ',', '.') }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="receipt-divider"></div>

            <!-- Total Section -->
            <table class="receipt-total-table">
                <tr>
                    <td class="total-label">Subtotal</td>
                    <td class="total-value">Rp {{ number_format($invoice->subtotal, 0, ',', '.') }}</td>
                </tr>
                @if($invoice->discount > 0)
                <tr>
                    <td class="total-label">Diskon</td>
                    <td class="total-value">- Rp {{ number_format($invoice->discount, 0, ',', '.') }}</td>
                </tr>
                @endif
                <tr class="grand-total-row">
                    <td class="total-label">TOTAL</td>
                    <td class="total-value">Rp {{ number_format($invoice->final_total, 0, ',', '.') }}</td>
                </tr>
                <tr>
                    <td class="total-label">Bayar ({{ strtoupper(str_replace('_', ' ', $paymentMethod)) }})</td>
                    <td class="total-value">Rp {{ number_format($invoice->amount_paid, 0, ',', '.') }}</td>
                </tr>
                <tr>
                    <td class="total-label">Kembali</td>
                    <td class="total-value">Rp {{ number_format($invoice->change_amount, 0, ',', '.') }}</td>
                </tr>
            </table>

            <div class="receipt-divider"></div>

            <!-- Footer -->
            <div class="receipt-footer">
                <p>Terima kasih atas pesanan Anda!</p>
                <p>Main Puas, Harga Pas</p>
            </div>
        </div>
    </div>

    <script>
        (function() {
            const isPaymentRedirect = {{ request('action') === 'payment' ? 'true' : 'false' }};
            const redirectUrl = "{{ route('fnb.queue.index') }}";
            let printTriggered = false;
            let hasRedirected = false;

            function doRedirect() {
                if (isPaymentRedirect && !hasRedirected) {
                    hasRedirected = true;
                    window.location.href = redirectUrl;
                }
            }

            // Listen for afterprint
            window.addEventListener('afterprint', doRedirect);

            // Auto print only once if requested or default
            window.addEventListener('DOMContentLoaded', function() {
                const urlParams = new URLSearchParams(window.location.search);
                const shouldAutoPrint = isPaymentRedirect || urlParams.get('action') === 'print' || urlParams.get('autoprint') === '1';

                if (shouldAutoPrint && !printTriggered) {
                    printTriggered = true;
                    setTimeout(function() {
                        window.print();
                    }, 300);
                }
            });
        })();
    </script>
</body>
</html>
