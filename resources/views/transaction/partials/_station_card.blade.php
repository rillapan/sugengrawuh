@php
    $isUsed = ($device->status === 'Digunakan');
    $psList = ($device->playstations && $device->playstations->isNotEmpty())
        ? $device->playstations
        : ($device->playstation ? collect([$device->playstation]) : collect());

    $mappings = ($device->relationLoaded('activeHardwareMappings') && $device->activeHardwareMappings)
        ? $device->activeHardwareMappings
        : collect();

    if ($mappings->isEmpty()) {
        $hwLabel = 'Tanpa Otomasi';
        $hwClass = 'is-none';
    } else {
        $hwStatuses = $mappings->map(fn ($m) => strtoupper((string) optional(optional($m)->physicalDevice)->connection_status));
        if ($hwStatuses->contains('OFFLINE')) {
            $hwLabel = 'Hardware Offline';
            $hwClass = 'is-offline';
        } elseif ($hwStatuses->contains('ONLINE')) {
            $hwLabel = 'Hardware Online';
            $hwClass = 'is-online';
        } else {
            $hwLabel = 'Belum Verifikasi';
            $hwClass = 'is-unverified';
        }
    }

    $stationLabel = $stationNum ? ('Station ' . $stationNum) : 'Station';
@endphp

<article
    class="pos-station-card {{ $isUsed ? 'is-used' : 'is-available' }}"
    id="station-card-{{ $device->id }}"
    data-device-id="{{ $device->id }}"
    data-station-number="{{ $stationNum ?? '' }}"
>
    @if (!$isUsed)
        {{-- State A: Available --}}
        <header class="pos-card-head">
            <div class="pos-card-top-row">
                <span class="pos-station-label">{{ $stationLabel }}</span>
                <span class="pos-status-pill is-available">TERSEDIA</span>
            </div>
            <h2 class="pos-station-name">{{ $device->nama }}</h2>
            @if ($psList->isNotEmpty())
                <div class="pos-chip-row">
                    @foreach ($psList as $ps)
                        <span class="pos-chip">{{ $ps->nama }}</span>
                    @endforeach
                </div>
            @endif
        </header>

        <div class="pos-card-body">
            <div class="pos-tariff-line">
                @if ($psList->count() === 1)
                    Rp {{ number_format($psList->first()->harga ?? 0, 0, ',', '.') }}/jam
                @elseif ($psList->count() > 1)
                    {{ $psList->map(fn ($ps) => $ps->nama . ': Rp ' . number_format($ps->harga ?? 0, 0, ',', '.') . '/jam')->implode(' | ') }}
                @else
                    Tarif belum diatur
                @endif
            </div>

            @if ($mappings->isNotEmpty())
                <div class="pos-hw-status {{ $hwClass }}">
                    <span class="pos-hw-dot" aria-hidden="true"></span>
                    <span>{{ $hwLabel }}</span>
                </div>
            @endif
        </div>

        <footer class="pos-card-footer">
            @if ($canOperate)
                <button
                    type="button"
                    class="pos-btn pos-btn-primary js-start-lost-time"
                    data-device-id="{{ $device->id }}"
                >Lost Time</button>
                <button
                    type="button"
                    class="pos-btn pos-btn-outline-primary js-open-paket"
                    data-device-id="{{ $device->id }}"
                >Paket</button>
                <button
                    type="button"
                    class="pos-btn pos-btn-accent js-open-cafe"
                    data-device-id="{{ $device->id }}"
                    data-mode="standalone"
                >Cafe</button>
            @else
                <p class="pos-readonly-note">Mode Pantau (Read-Only)</p>
            @endif
        </footer>
    @elseif ($activeTx)
        {{-- State B: Active with matched transaction --}}
        @php
            if (isset($txSummary) && is_array($txSummary)) {
                $rentalSubtotal = (float) ($txSummary['rental_subtotal'] ?? 0);
                $fnbPaidTotal = (float) ($txSummary['fnb_paid_total'] ?? 0);
                $unpaidFnbTotal = (float) ($txSummary['fnb_unpaid_total'] ?? 0);
                $totalFnbDisplay = (float) ($txSummary['total_fnb_display'] ?? ($fnbPaidTotal + $unpaidFnbTotal));
            } else {
                $packageFnbs = [];
                if ($activeTx->tipe_transaksi === 'custom_package' && $activeTx->custom_package) {
                    foreach ($activeTx->custom_package->fnbs as $pFnb) {
                        $packageFnbs[(int) $pFnb->id] = (int) ($pFnb->pivot->quantity ?? 0);
                    }
                }
                $extraLegacyFnbTotal = 0.0;
                foreach ($activeTx->transactionFnbs as $tf) {
                    $qtyInPkg = $packageFnbs[(int) $tf->fnb_id] ?? 0;
                    $chargeableQty = ($activeTx->tipe_transaksi === 'custom_package')
                        ? max(0, (int) $tf->qty - $qtyInPkg)
                        : (int) $tf->qty;
                    $extraLegacyFnbTotal += $chargeableQty * (float) $tf->harga_jual;
                }
                $unpaidOrderFnbTotal = (float) $activeTx->unpaid_fnb_order_total;
                $paidOrderFnbTotal = (float) $activeTx->paid_fnb_order_total;
                $fnbPaidTotal = $paidOrderFnbTotal + ($activeTx->payment_status === 'paid' ? $extraLegacyFnbTotal : 0.0);
                $unpaidFnbTotal = $unpaidOrderFnbTotal + ($activeTx->payment_status === 'paid' ? 0.0 : $extraLegacyFnbTotal);
                $totalFnbDisplay = $fnbPaidTotal + $unpaidFnbTotal;
                $rentalSubtotal = $activeTx->tipe_transaksi === 'custom_package'
                    ? (float) ($activeTx->harga ?: $activeTx->total)
                    : ($activeTx->tipe_transaksi === 'prepaid'
                        ? max(0.0, (float) $activeTx->total - $extraLegacyFnbTotal)
                        : 0.0);
            }

            $isPaid = ($activeTx->payment_status === 'paid');
            $txTypeLabel = match ($activeTx->tipe_transaksi) {
                'prepaid' => 'Paket',
                'custom_package' => 'Custom Paket',
                default => 'Lost Time',
            };
            $txTypeClass = match ($activeTx->tipe_transaksi) {
                'prepaid' => 'is-prepaid',
                'custom_package' => 'is-custom',
                default => 'is-postpaid',
            };

            $activeCategoryName = $activeTx->tipe_transaksi === 'custom_package'
                ? ($activeTx->custom_package->nama_paket ?? ($device->playstation_names ?: 'Custom Paket'))
                : ($activeTx->playstation->nama ?? ($device->playstation_names ?: '-'));

            $startedAtIso = optional($activeTx->started_at)->toIso8601String()
                ?: ($activeTx->created_at ? $activeTx->created_at->toDateString() . 'T' . (strlen((string) $activeTx->waktu_mulai) === 5 ? $activeTx->waktu_mulai . ':00' : $activeTx->waktu_mulai) : '');
            $lostTimeStartIso = optional($activeTx->lost_time_start)->toIso8601String() ?? '';
            $expiresAtIso = optional($activeTx->expires_at)->toIso8601String() ?? '';
        @endphp

        <header class="pos-card-head">
            <div class="pos-card-top-row">
                <span class="pos-station-label">{{ $stationLabel }}</span>
                <span class="pos-status-pill is-used">BERMAIN</span>
            </div>
            <h2 class="pos-station-name">{{ $device->nama }}</h2>
            <div class="pos-chip-row">
                <span class="pos-chip">{{ $activeCategoryName }}</span>
                <span class="pos-type-badge {{ $txTypeClass }}">{{ $txTypeLabel }}</span>
            </div>
        </header>

        <div class="pos-card-body">
            <div class="pos-timer-box">
                <div
                    id="pos-timer-{{ $device->id }}"
                    class="pos-timer-value js-pos-timer"
                    data-device-id="{{ $device->id }}"
                    data-tx-id="{{ $activeTx->id_transaksi }}"
                    data-tipe="{{ $activeTx->tipe_transaksi }}"
                    data-started-at="{{ $startedAtIso }}"
                    data-lost-time-start="{{ $lostTimeStartIso }}"
                    data-expires-at="{{ $expiresAtIso }}"
                    data-hourly-rate="{{ (int) ($activeTx->harga ?? 0) }}"
                    data-fnb-unpaid="{{ (int) $unpaidFnbTotal }}"
                >--:--:--</div>
                <div class="pos-timer-sub">
                    Mulai: {{ $activeTx->waktu_mulai }} | Selesai: {{ $activeTx->waktu_Selesai ?: 'Berjalan' }}
                </div>
            </div>

            <div class="pos-cost-box">
                @if ($activeTx->tipe_transaksi === 'postpaid')
                    <div class="pos-cost-row">
                        <span>Estimasi Main:</span>
                        <strong id="pos-est-rental-{{ $device->id }}">Rp 0</strong>
                    </div>
                    @if ($totalFnbDisplay > 0)
                        <div class="pos-cost-row">
                            <span>FnB:</span>
                            <strong>Rp {{ number_format($totalFnbDisplay, 0, ',', '.') }}</strong>
                        </div>
                    @endif
                @else
                    <div class="pos-cost-row">
                        <span>Sewa:</span>
                        <strong>Rp {{ number_format($rentalSubtotal, 0, ',', '.') }}</strong>
                    </div>
                    @if ($totalFnbDisplay > 0)
                        <div class="pos-cost-row">
                            <span>FnB:</span>
                            <strong>Rp {{ number_format($totalFnbDisplay, 0, ',', '.') }}</strong>
                        </div>
                    @endif
                    <div class="pos-cost-row pos-cost-total">
                        <span>Total:</span>
                        <strong>Rp {{ number_format($rentalSubtotal + $totalFnbDisplay, 0, ',', '.') }}</strong>
                    </div>
                @endif
            </div>

            <div class="pos-meta-row">
                <span class="pos-pay-badge {{ $isPaid ? 'is-paid' : 'is-unpaid' }}">
                    {{ $isPaid ? 'LUNAS' : 'BELUM BAYAR' }}
                </span>
                @if ($activeTx->nama && $activeTx->nama !== 'Pelanggan')
                    <span class="pos-customer-name">{{ $activeTx->nama }}</span>
                @endif
            </div>

            @if ($mappings->isNotEmpty())
                <div class="pos-hw-status {{ $hwClass }}">
                    <span class="pos-hw-dot" aria-hidden="true"></span>
                    <span>{{ $hwLabel }}</span>
                </div>
            @endif
        </div>

        <footer class="pos-card-footer">
            <button
                type="button"
                class="pos-btn pos-btn-secondary js-open-detail"
                data-device-id="{{ $device->id }}"
                data-tx-id="{{ $activeTx->id_transaksi }}"
            >Detail</button>

            @if ($canOperate)
                @if ($activeTx->payment_status === 'unpaid')
                    <a href="{{ route('transaction.showPayment', $activeTx->id_transaksi) }}" class="pos-btn pos-btn-success">Bayar</a>
                    <a href="{{ route('transaction.edit', $activeTx->id_transaksi) }}" class="pos-btn pos-btn-outline-primary">Edit</a>
                @elseif ($activeTx->payment_status === 'paid')
                    <a href="{{ route('transaction.print', $activeTx->id_transaksi) }}" target="_blank" rel="noopener" class="pos-btn pos-btn-outline-secondary">Struk</a>
                @endif

                <button
                    type="button"
                    class="pos-btn pos-btn-accent js-open-cafe"
                    data-device-id="{{ $device->id }}"
                    data-tx-id="{{ $activeTx->id_transaksi }}"
                    data-mode="session"
                >Cafe</button>

                <button
                    type="button"
                    class="pos-btn pos-btn-warning js-open-berhenti"
                    data-device-id="{{ $device->id }}"
                    data-tx-id="{{ $activeTx->id_transaksi }}"
                    data-device-name="{{ $device->nama }}"
                >Berhenti</button>

                @if ($activeTx->tipe_transaksi === 'postpaid' && $activeTx->status_transaksi === 'berjalan')
                    <button
                        type="button"
                        class="pos-btn pos-btn-success js-open-selesai"
                        data-device-id="{{ $device->id }}"
                        data-tx-id="{{ $activeTx->id_transaksi }}"
                        data-device-name="{{ $device->nama }}"
                        data-action="{{ route('transaction.end', $activeTx->id_transaksi) }}"
                    >Selesai</button>
                @endif
            @endif
        </footer>
    @else
        {{-- State B Fallback: Device is Digunakan without matched active transaction --}}
        <header class="pos-card-head">
            <div class="pos-card-top-row">
                <span class="pos-station-label">{{ $stationLabel }}</span>
                <span class="pos-status-pill is-used">BERMAIN</span>
            </div>
            <h2 class="pos-station-name">{{ $device->nama }}</h2>
            @if ($psList->isNotEmpty())
                <div class="pos-chip-row">
                    @foreach ($psList as $ps)
                        <span class="pos-chip">{{ $ps->nama }}</span>
                    @endforeach
                </div>
            @endif
        </header>

        <div class="pos-card-body">
            <p class="pos-fallback-note">
                Stasiun berstatus Digunakan, namun sesi aktif tidak ditemukan. Periksa Detail atau hentikan stasiun jika perlu reset.
            </p>
            @if ($mappings->isNotEmpty())
                <div class="pos-hw-status {{ $hwClass }}">
                    <span class="pos-hw-dot" aria-hidden="true"></span>
                    <span>{{ $hwLabel }}</span>
                </div>
            @endif
        </div>

        <footer class="pos-card-footer">
            <button
                type="button"
                class="pos-btn pos-btn-secondary js-open-detail"
                data-device-id="{{ $device->id }}"
                data-tx-id=""
            >Detail</button>
            @if ($canOperate)
                <button
                    type="button"
                    class="pos-btn pos-btn-warning js-open-berhenti"
                    data-device-id="{{ $device->id }}"
                    data-tx-id=""
                    data-device-name="{{ $device->nama }}"
                >Berhenti</button>
            @endif
        </footer>
    @endif
</article>

