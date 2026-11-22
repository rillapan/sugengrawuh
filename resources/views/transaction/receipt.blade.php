@php
    $checkoutInvoice = $checkoutInvoice ?? (($transaction->relationLoaded('invoices') && $transaction->invoices->isNotEmpty())
        ? ($transaction->invoices->where('invoice_type', '!=', \App\Models\Invoice::TYPE_FNB_STANDALONE)->sortByDesc('id')->first() ?? $transaction->invoices->sortByDesc('id')->first())
        : null);

    $cashierName = ($checkoutInvoice && $checkoutInvoice->cashierUser)
        ? $checkoutInvoice->cashierUser->name
        : ($transaction->user ? $transaction->user->name : (auth()->user()->name ?? 'Admin'));

    $paymentDate = ($checkoutInvoice && $checkoutInvoice->paid_at)
        ? $checkoutInvoice->paid_at
        : ($transaction->paid_at ?? $transaction->created_at);

    $paymentMethod = ($checkoutInvoice && $checkoutInvoice->payment_method)
        ? $checkoutInvoice->payment_method
        : ($transaction->payment_method ?? 'tunai');

    $invoiceNumber = $checkoutInvoice ? $checkoutInvoice->invoice_number : ('#TX-' . $transaction->id_transaksi);

    $subtotal = $checkoutInvoice ? $checkoutInvoice->subtotal : (float) $transaction->total;
    $discountAmount = $checkoutInvoice ? $checkoutInvoice->discount : (($transaction->diskon > 0) ? ($transaction->total * $transaction->diskon / 100) : 0);
    $finalTotal = $checkoutInvoice ? $checkoutInvoice->final_total : max(0, $subtotal - $discountAmount);

    $amountPaid = $transaction->bayar_nominal ?? ($checkoutInvoice ? $checkoutInvoice->amount_paid : $finalTotal);
    $changeAmount = $transaction->kembalian ?? ($checkoutInvoice ? $checkoutInvoice->change_amount : max(0, $amountPaid - $finalTotal));

    // Rental items & cost calculation
    $rentalItems = ($checkoutInvoice && $checkoutInvoice->relationLoaded('items'))
        ? $checkoutInvoice->items->where('item_type', \App\Models\InvoiceItem::ITEM_TYPE_GAME_RENTAL)->values()
        : collect();
    $rentalItem = $rentalItems->first();
    $rentalCost = $rentalItems->isNotEmpty()
        ? (float) $rentalItems->sum('subtotal')
        : max(0, $transaction->total - ($transaction->getFnbTotalAttribute() + $transaction->getUnpaidFnbOrderTotalAttribute()));

    // FnB items from invoice or transaction
    $invoiceFnbItems = null;
    if ($checkoutInvoice && $checkoutInvoice->relationLoaded('items')) {
        $invoiceFnbItems = $checkoutInvoice->items->where('item_type', \App\Models\InvoiceItem::ITEM_TYPE_FNB_ITEM);
    }
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Struk Pembayaran #{{ $transaction->id_transaksi }}</title>
    @include('layouts._thermal_receipt_styles')
</head>
<body>
    <div class="no-print">
        <div class="receipt-btn-group">
            <button type="button" onclick="window.print();" class="receipt-btn btn-thermal-print">📷 Cetak / Print</button>
            <a href="{{ route('transaction.index') }}" class="receipt-btn btn-thermal-back">⬅ Kembali ke Menu</a>
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

            <!-- Transaction Info -->
            <table class="receipt-info-table">
                <tr>
                    <td class="info-label">No. Transaksi</td>
                    <td class="info-value">#{{ $transaction->id_transaksi }}</td>
                </tr>
                @if($checkoutInvoice)
                <tr>
                    <td class="info-label">No. Invoice</td>
                    <td class="info-value">{{ $checkoutInvoice->invoice_number }}</td>
                </tr>
                @endif
                <tr>
                    <td class="info-label">Tanggal</td>
                    <td class="info-value">{{ $paymentDate->format('d/m/Y H:i') }}</td>
                </tr>
                <tr>
                    <td class="info-label">Pelanggan</td>
                    <td class="info-value">{{ $transaction->nama }}</td>
                </tr>
                <tr>
                    <td class="info-label">Kasir</td>
                    <td class="info-value">{{ $cashierName }}</td>
                </tr>
                <tr>
                    <td class="info-label">Metode Bayar</td>
                    <td class="info-value">{{ strtoupper(str_replace('_', ' ', $paymentMethod)) }}</td>
                </tr>
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
                    <!-- Rental Item -->
                    @if($rentalItems->count() >= 2)
                        @foreach($rentalItems as $rItem)
                            <tr class="item-title-row">
                                <td colspan="2" class="item-name">
                                    {{ $rItem->name }}
                                    <br>
                                    <small>{{ $transaction->device->nama ?? '-' }}</small>
                                </td>
                            </tr>
                            <tr class="item-detail-row">
                                <td class="item-calc">Segmen #{{ $loop->iteration }}</td>
                                <td class="item-subtotal">Rp {{ number_format($rItem->subtotal, 0, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    @else
                        <tr class="item-title-row">
                            <td colspan="2" class="item-name">
                                @if($transaction->tipe_transaksi === 'custom_package' && $transaction->custom_package)
                                    {{ $transaction->custom_package->nama_paket }}
                                @else
                                    Rental {{ $transaction->playstation->nama ?? $transaction->device->playstation->nama ?? 'PS' }}
                                @endif
                                <br>
                                <small>{{ $transaction->device->nama ?? '-' }}</small>
                            </td>
                        </tr>
                        <tr class="item-detail-row">
                            <td class="item-calc">
                                @if($transaction->tipe_transaksi === 'postpaid')
                                    {{ $transaction->jam_main }} (Lost Time)
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
                            <td class="item-subtotal">
                                @if($transaction->tipe_transaksi === 'custom_package')
                                    Harga Paket
                                @else
                                    Rp {{ number_format($rentalCost, 0, ',', '.') }}
                                @endif
                            </td>
                        </tr>
                    @endif

                    <!-- FnB Items -->
                    @if($invoiceFnbItems && $invoiceFnbItems->isNotEmpty())
                        @foreach($invoiceFnbItems as $invItem)
                            <tr class="item-title-row">
                                <td colspan="2" class="item-name">{{ $invItem->name }}</td>
                            </tr>
                            <tr class="item-detail-row">
                                <td class="item-calc">{{ $invItem->qty }} x Rp {{ number_format($invItem->unit_price_jual, 0, ',', '.') }}</td>
                                <td class="item-subtotal">Rp {{ number_format($invItem->subtotal, 0, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    @elseif($transaction->transactionFnbs->isNotEmpty())
                        @php
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
                                        $displayText = $paidQty . ' x Rp ' . number_format($fnbItem->harga_jual, 0, ',', '.') . ' (Ekstra)';
                                        $displayTotal = 'Rp ' . number_format($paidTotal, 0, ',', '.');
                                    }
                                } else {
                                    $displayText = $fnbItem->qty . ' x Rp ' . number_format($fnbItem->harga_jual, 0, ',', '.');
                                    $displayTotal = 'Rp ' . number_format($fnbItem->qty * $fnbItem->harga_jual, 0, ',', '.');
                                }
                            @endphp
                            <tr class="item-title-row">
                                <td colspan="2" class="item-name">
                                    {{ $fnbItem->fnb->nama }}
                                    @if($isPackageItem && $fnbItem->qty <= $qtyInPackage)
                                         <small>(Paket)</small>
                                    @endif
                                </td>
                            </tr>
                            <tr class="item-detail-row">
                                <td class="item-calc">{{ $displayText }}</td>
                                <td class="item-subtotal">{{ $displayTotal }}</td>
                            </tr>
                        @endforeach
                    @endif
                </tbody>
            </table>

            <div class="receipt-divider"></div>

            <!-- Total Section -->
            <table class="receipt-total-table">
                <tr>
                    <td class="total-label">Subtotal</td>
                    <td class="total-value">Rp {{ number_format($subtotal, 0, ',', '.') }}</td>
                </tr>
                @if(($checkoutInvoice && $checkoutInvoice->discount > 0) || (!$checkoutInvoice && $transaction->diskon > 0))
                    @php
                        $discountVal = $checkoutInvoice ? $checkoutInvoice->discount : ($transaction->total * $transaction->diskon / 100);
                        $discountLabel = (!$checkoutInvoice && $transaction->diskon > 0) ? "Diskon ({$transaction->diskon}%)" : "Diskon";
                    @endphp
                <tr>
                    <td class="total-label">{{ $discountLabel }}</td>
                    <td class="total-value">- Rp {{ number_format($discountVal, 0, ',', '.') }}</td>
                </tr>
                @endif
                <tr class="grand-total-row">
                    <td class="total-label">TOTAL</td>
                    <td class="total-value">Rp {{ number_format($finalTotal, 0, ',', '.') }}</td>
                </tr>
                <tr>
                    <td class="total-label">Bayar ({{ strtoupper(str_replace('_', ' ', $paymentMethod)) }})</td>
                    <td class="total-value">Rp {{ number_format($amountPaid, 0, ',', '.') }}</td>
                </tr>
                <tr>
                    <td class="total-label">Kembali</td>
                    <td class="total-value">Rp {{ number_format($changeAmount, 0, ',', '.') }}</td>
                </tr>
            </table>

            <div class="receipt-divider"></div>

            <!-- Footer -->
            <div class="receipt-footer">
                <p>Terima Kasih atas kunjungan Anda!</p>
                <p>Main Puas, Harga Pas</p>
            </div>
        </div>
    </div>

    <script>
        (function() {
            const isPaymentRedirect = {{ request('action') === 'payment' ? 'true' : 'false' }};
            const redirectUrl = "{{ route('transaction.index') }}";
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

            // Trigger print once on load
            window.addEventListener('DOMContentLoaded', function() {
                if (!printTriggered) {
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
