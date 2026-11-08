<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Struk Pembayaran #{{ $transaction->id_transaksi }}</title>
    <style>
        body {
            font-family: 'Consolas', 'Courier New', monospace;
            font-size: 12px;
            width: 58mm; /* Standard thermal paper width */
            margin: 0;
            padding: 5px;
            color: #000;
        }
        .header {
            text-align: center;
            margin-bottom: 10px;
        }
        .header h3 {
            margin: 0;
            font-size: 16px;
            text-transform: uppercase;
        }
        .header p {
            margin: 2px 0;
            font-size: 10px;
        }
        .divider {
            border-top: 1px dashed #000;
            margin: 5px 0;
        }
        .info-table {
            width: 100%;
            font-size: 10px;
        }
        .info-table td {
            padding: 1px 0;
            vertical-align: top;
        }
        .item-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
        }
        .item-table th, .item-table td {
            text-align: left;
            padding: 2px 0;
        }
        .text-right {
            text-align: right !important;
        }
        .text-center {
            text-align: center !important;
        }
        .total-section {
            margin-top: 5px;
            font-weight: bold;
        }
        .footer {
            text-align: center;
            margin-top: 15px;
            font-size: 10px;
        }
        
        @media print {
            .no-print {
                display: none;
            }
            body {
                width: auto;
                margin: 0;
                padding: 0;
            }
        }

        .btn-print {
            display: block;
            width: 100%;
            padding: 10px;
            background: #333;
            color: #fff;
            text-align: center;
            text-decoration: none;
            margin-bottom: 10px;
            font-family: sans-serif;
            border-radius: 4px;
        }
        .btn-back {
            display: block;
            width: 100%;
            padding: 10px;
            background: #eee;
            color: #333;
            text-align: center;
            text-decoration: none;
            margin-bottom: 10px;
            font-family: sans-serif;
            border-radius: 4px;
            border: 1px solid #ccc;
        }
    </style>
</head>
<body onload="window.print()">

    <div class="no-print">
        <a href="#" onclick="window.print(); return false;" class="btn-print">📷 Cetak / Print</a>
        <a href="{{ route('transaction.index') }}" class="btn-back">⬅ Kembali ke Menu</a>
    </div>

    <div class="header">
        <h3>Sugeng Rawuh Playstation & Billiard</h3>
        <p>Kemplong Barat, Kemplong, Kec. Wiradesa, Kabupaten Pekalongan, Jawa Tengah</p>
        <p>Telp: +62 857-2505-6741</p>
    </div>

    <div class="divider"></div>

    <table class="info-table">
        <tr>
            <td>No. Transaksi</td>
            <td class="text-right">#{{ $transaction->id_transaksi }}</td>
        </tr>
        <tr>
            <td>Tanggal</td>
            <td class="text-right">{{ $transaction->created_at->format('d/m/Y H:i') }}</td>
        </tr>
        <tr>
            <td>Pelanggan</td>
            <td class="text-right">{{ $transaction->nama }}</td>
        </tr>
        <tr>
            <td>Kasir</td>
            <td class="text-right">{{ auth()->user()->name ?? 'Admin' }}</td>
        </tr>
    </table>

    <div class="divider"></div>

    <table class="item-table">
        <!-- Rental Item -->
        <tr>
            <td colspan="2">
                @if($transaction->tipe_transaksi === 'custom_package' && $transaction->custom_package)
                    {{ $transaction->custom_package->nama_paket }}
                @else
                    Rental {{ $transaction->device->playstation->nama ?? 'PS' }}
                @endif
                <br>
                <small>{{ $transaction->device->nama ?? '-' }}</small>
            </td>
        </tr>
        <tr>
            <td>
                @if($transaction->tipe_transaksi === 'postpaid')
                    {{ $transaction->jam_main }}
                @elseif($transaction->tipe_transaksi === 'custom_package')
                   {{ $transaction->jam_main }} Menit
                @elseif($transaction->duration_seconds)
                   {{ $transaction->display_duration }}
                @elseif(preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $transaction->jam_main ?? ''))
                   {{ $transaction->jam_main }}
                @else
                   {{ $transaction->jam_main }} Jam
                @endif
            </td>
            <td class="text-right">
                @if($transaction->tipe_transaksi === 'custom_package')
                    Harga Paket
                @else
                    Rp {{ number_format($transaction->total - $transaction->getFnbTotalAttribute(), 0, ',', '.') }}
                @endif
            </td>
        </tr>

        <!-- FnB Items -->
        @if($transaction->transactionFnbs->isNotEmpty())
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
                    $displayText = '';
                    $displayTotal = '';
                    
                    if ($isPackageItem) {
                        if ($fnbItem->qty <= $qtyInPackage) {
                            $displayText = $fnbItem->qty . ' x (Paket)';
                            $displayTotal = 'Harga Paket';
                        } else {
                            $paidQty = $fnbItem->qty - $qtyInPackage;
                            $paidTotal = $paidQty * $fnbItem->harga_jual;
                            $displayText = $paidQty . ' x ' . number_format($fnbItem->harga_jual, 0, ',', '.') . ' (Ekstra)';
                            $displayTotal = 'Rp ' . number_format($paidTotal, 0, ',', '.');
                        }
                    } else {
                        $displayText = $fnbItem->qty . ' x ' . number_format($fnbItem->harga_jual, 0, ',', '.');
                        $displayTotal = 'Rp ' . number_format($fnbItem->qty * $fnbItem->harga_jual, 0, ',', '.');
                    }
                @endphp
                <tr>
                    <td colspan="2">
                        {{ $fnbItem->fnb->nama }}
                        @if($isPackageItem && $fnbItem->qty <= $qtyInPackage)
                             <small>(Paket)</small>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td>{{ $displayText }}</td>
                    <td class="text-right">
                        {{ $displayTotal }}
                    </td>
                </tr>
            @endforeach
        @endif
    </table>

    <div class="divider"></div>

    <table class="info-table total-section">
        <tr>
            <td>Subtotal</td>
            <td class="text-right">Rp {{ number_format($transaction->total, 0, ',', '.') }}</td>
        </tr>
        @if($transaction->diskon > 0)
        <tr>
            <td>Diskon</td>
            <td class="text-right">{{ $transaction->diskon }}%</td>
        </tr>
        <tr>
            <td>Potongan</td>
            <td class="text-right">-Rp {{ number_format($transaction->total * $transaction->diskon / 100, 0, ',', '.') }}</td>
        </tr>
        @endif
        <tr>
            <td style="font-size: 14px;">TOTAL</td>
            <td class="text-right" style="font-size: 14px;">
                @php
                    $finalTotal = $transaction->total;
                    if($transaction->diskon > 0) {
                        $finalTotal = $transaction->total - ($transaction->total * $transaction->diskon / 100);
                    }
                @endphp
                Rp {{ number_format($finalTotal, 0, ',', '.') }}
            </td>
        </tr>
        <tr>
            <td colspan="2" style="padding-top: 5px;"></td>
        </tr>
        <tr>
            <td>Bayar ({{ ucfirst($transaction->payment_method) }})</td>
            <td class="text-right">Rp {{ number_format($transaction->bayar_nominal ?? $finalTotal, 0, ',', '.') }}</td>
        </tr>
        <tr>
            <td>Kembali</td>
            <td class="text-right">Rp {{ number_format(($transaction->bayar_nominal ?? $finalTotal) - $finalTotal, 0, ',', '.') }}</td>
        </tr>
    </table>

    <div class="divider"></div>

    <div class="footer">
        <p>Terima Kasih atas kunjungan Anda!</p>
        <p>Main Puas, Harga Pas</p>
    </div>

    @if(request('action') == 'payment')
        <div class="no-print" style="text-align: center; margin-top: 20px; font-size: 11px; color: #666;">
            <p>Sedang mencetak... Halaman akan dialihkan dalam beberapa saat.</p>
        </div>
    @endif

    <script>
        // Check if this view was triggered from the payment process
        const fromPayment = {{ request('action') == 'payment' ? 'true' : 'false' }};
        
        window.onload = function() {
            // Initiate print
            window.print();
            
            // If from payment, redirect after a short delay
            // This covers browsers where window.print() is non-blocking or if the user cancels quickly
            if (fromPayment) {
                 // Use a slight delay to ensure print dialog has time to engage interaction if needed
                 // Note: In Chrome window.print() is blocking, so the timeout starts AFTER the dialog closes
                 setTimeout(function() {
                    window.location.href = "{{ route('transaction.index') }}";
                }, 500); 
            }
        }

        // Also listen for afterprint event for better reliability
        window.addEventListener('afterprint', (event) => {
            if (fromPayment) {
                window.location.href = "{{ route('transaction.index') }}";
            }
        });
    </script>
</body>
</html>
