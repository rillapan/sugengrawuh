@php
    $outstandingUnpaidTxs = $outstandingUnpaidTxs ?? collect();
    $outstandingSummaries = $outstandingSummaries ?? [];
    $canOperate = $canOperate ?? false;
@endphp

@if ($outstandingUnpaidTxs->isNotEmpty())
    <div class="pos-unpaid-box">
        <div class="pos-unpaid-header">
            <h3 class="pos-unpaid-title">
                <i class="fas fa-receipt" aria-hidden="true"></i>
                <span>Tagihan Belum Lunas</span>
            </h3>
            <span class="pos-unpaid-count">{{ $outstandingUnpaidTxs->count() }}<span class="sr-only"> tagihan</span></span>
        </div>

        <ul class="pos-unpaid-list" role="list">
            @foreach ($outstandingUnpaidTxs as $unpaidTx)
                @php
                    $summary = $outstandingSummaries[(int) $unpaidTx->id_transaksi] ?? null;
                    if (!is_array($summary)) {
                        $summary = app(\App\Services\BillingService::class)->summarizeTransactionBilling($unpaidTx);
                    }
                    $payableAmount = (float) ($summary['payable_total'] ?? 0);
                    $customerName = $unpaidTx->nama ?: 'Pelanggan';
                    $typeLabel = match ($unpaidTx->tipe_transaksi) {
                        'prepaid' => 'Timer',
                        'custom_package' => 'Custom Paket',
                        default => 'Lost Time',
                    };
                    $typeClass = match ($unpaidTx->tipe_transaksi) {
                        'prepaid' => 'is-prepaid',
                        'custom_package' => 'is-custom',
                        default => 'is-postpaid',
                    };
                @endphp
                <li class="pos-unpaid-row" data-outstanding-tx-id="{{ $unpaidTx->id_transaksi }}">
                    <div class="pos-unpaid-info">
                        <div class="pos-unpaid-identity">
                            <span class="pos-unpaid-tx-id">#{{ $unpaidTx->id_transaksi }}</span>
                            <span class="pos-unpaid-customer">{{ $customerName }}</span>
                            <span class="pos-type-badge pos-unpaid-type {{ $typeClass }}">{{ $typeLabel }}</span>
                        </div>
                        <div class="pos-unpaid-meta">
                            Mulai {{ $unpaidTx->waktu_mulai ?: '-' }} / Selesai {{ $unpaidTx->waktu_Selesai ?: '-' }}
                        </div>
                    </div>

                    <div class="pos-unpaid-actions">
                        <strong class="pos-unpaid-amount">Rp {{ number_format($payableAmount, 0, ',', '.') }}</strong>
                        @if ($canOperate)
                            <a
                                href="{{ route('transaction.showPayment', $unpaidTx->id_transaksi) }}"
                                class="pos-btn pos-btn-success pos-btn-sm js-pay-outstanding"
                                data-outstanding-tx-id="{{ $unpaidTx->id_transaksi }}"
                                aria-label="Bayar tagihan #{{ $unpaidTx->id_transaksi }} ({{ $customerName }})"
                            >Bayar</a>
                        @else
                            <span class="pos-pay-badge is-unpaid">BELUM BAYAR</span>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </div>
@endif
