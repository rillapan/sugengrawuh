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

    $stationCategory = method_exists($device, 'getStationCategory') ? $device->getStationCategory() : null;
    if (!$stationCategory && $psList->isNotEmpty()) {
        $detected = $psList->map(function ($ps) {
            if (method_exists($ps, 'getCategory') && $ps->getCategory()) {
                return $ps->getCategory();
            }
            $name = strtoupper(trim((string) $ps->nama));
            if (str_contains($name, 'BILLIARD') || str_contains($name, 'BILIAR') || str_starts_with($name, 'MEJA')) {
                return 'BILLIARD';
            }
            if (str_contains($name, 'PLAYSTATION') || preg_match('/\bPS[0-9]?\b/i', $name)) {
                return 'PLAYSTATION';
            }
            return null;
        })->filter()->unique()->values();

        if ($detected->count() === 1) {
            $stationCategory = $detected->first();
        }
    }

    $bgClass = match ($stationCategory) {
        'PLAYSTATION' => 'has-bg-ps',
        'BILLIARD' => 'has-bg-billiard',
        default => '',
    };
@endphp

<article
    class="pos-station-card {{ $isUsed ? 'is-used' : 'is-available' }} {{ $bgClass }}"
    id="station-card-{{ $device->id }}"
    data-device-id="{{ $device->id }}"
    data-station-number="{{ $stationNum ?? '' }}"
    @if ($stationCategory) data-station-category="{{ strtolower($stationCategory) }}" @endif
>
    @if (!$isUsed)
        {{-- State A: Available --}}
        <header class="pos-card-head">
            <div class="pos-card-top-row">
                <span class="pos-station-label">{{ $stationLabel }}</span>
                <span class="pos-status-pill is-available">
                    <i class="fas fa-check-circle mr-1" aria-hidden="true"></i>
                    <span>TERSEDIA</span>
                </span>
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
                <div class="pos-tariff-caption">Tarif Reguler:</div>
                <div class="pos-tariff-amount">
                    @if ($psList->count() === 1)
                        Rp {{ number_format($psList->first()->harga ?? 0, 0, ',', '.') }}/jam
                    @elseif ($psList->count() > 1)
                        {{ $psList->map(fn ($ps) => $ps->nama . ': Rp ' . number_format($ps->harga ?? 0, 0, ',', '.') . '/jam')->implode(' | ') }}
                    @else
                        Tarif belum diatur
                    @endif
                </div>
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
                    class="pos-btn pos-btn-lost-time js-start-lost-time"
                    data-device-id="{{ $device->id }}"
                >
                    <i class="fas fa-stopwatch mr-1" aria-hidden="true"></i>
                    <span>Lost Time</span>
                </button>
                <button
                    type="button"
                    class="pos-btn pos-btn-paket-card js-open-paket"
                    data-device-id="{{ $device->id }}"
                >
                    <i class="fas fa-calendar-alt mr-1" aria-hidden="true"></i>
                    <span>Timer</span>
                </button>
                <button
                    type="button"
                    class="pos-btn pos-btn-accent js-open-cafe"
                    data-device-id="{{ $device->id }}"
                    data-mode="standalone"
                >
                    <i class="fas fa-coffee mr-1" aria-hidden="true"></i>
                    <span>Cafe</span>
                </button>
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
                if (in_array($activeTx->tipe_transaksi, ['prepaid', 'custom_package'], true)) {
                    $rentalSubtotal = method_exists($activeTx, 'resolvePaketRentalTotal')
                        ? (float) $activeTx->resolvePaketRentalTotal()
                        : ($activeTx->tipe_transaksi === 'custom_package'
                            ? (float) ($activeTx->harga ?: $activeTx->total)
                            : max(0.0, (float) $activeTx->total - $extraLegacyFnbTotal));
                } else {
                    $rentalSubtotal = 0.0;
                }
            }

            $isPaid = ($activeTx->payment_status === 'paid');
            $txTypeLabel = match ($activeTx->tipe_transaksi) {
                'prepaid' => 'Timer',
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
                <span class="pos-status-pill is-used">
                    <span class="pos-hw-dot" style="background-color: var(--pos-red);" aria-hidden="true"></span>
                    <span>BERMAIN</span>
                </span>
            </div>
            <h2 class="pos-station-name">{{ $device->nama }}</h2>
            <div class="pos-chip-row">
                <span class="pos-chip">{{ $activeCategoryName }}</span>
                <span class="pos-type-badge {{ $txTypeClass }}">{{ $txTypeLabel }}</span>
            </div>
        </header>

        <div class="pos-card-body">
            <div class="pos-timer-box">
                <div class="pos-timer-label">Durasi Berjalan</div>
                @php
                    $activePs = $activeTx->playstation ?: ($device->playstation ?: ($device->playstations ? $device->playstations->first() : null));
                    $hourlyPackagesJson = $activePs ? json_encode($activePs->getAvailableHoursWithPrices()) : '{}';
                    $initEst = 0;
                    if ($activeTx->tipe_transaksi === 'postpaid') {
                        $initStart = $activeTx->getEffectiveStartTime();
                        $initElapsedMinutes = max(0, (int) $initStart->diffInMinutes(now(), false));
                        $initRaw = app(\App\Services\BillingService::class)->calculateLostTimePrice($activePs, $initElapsedMinutes);
                        $initEst = app(\App\Services\BillingService::class)->applyLostTimeRounding($initRaw);
                    }
                @endphp
                <div
                    id="pos-timer-{{ $device->id }}"
                    class="pos-timer-value js-pos-timer"
                    data-device-id="{{ $device->id }}"
                    data-tx-id="{{ $activeTx->id_transaksi }}"
                    data-tipe="{{ $activeTx->tipe_transaksi }}"
                    data-started-at="{{ $startedAtIso }}"
                    data-lost-time-start="{{ $lostTimeStartIso }}"
                    data-expires-at="{{ $expiresAtIso }}"
                    data-hourly-rate="{{ (int) ($activeTx->harga ?? ($activePs->harga ?? 0)) }}"
                    data-hourly-packages="{{ $hourlyPackagesJson }}"
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
                        <strong id="pos-est-rental-{{ $device->id }}">Rp {{ number_format($initEst, 0, ',', '.') }}</strong>
                    </div>
                    @if ($totalFnbDisplay > 0)
                        <div class="pos-cost-row">
                            <span>Total Cafe (FnB):</span>
                            <strong>Rp {{ number_format($totalFnbDisplay, 0, ',', '.') }}</strong>
                        </div>
                    @endif
                @else
                    <div class="pos-cost-row">
                        <span>Sewa / Paket:</span>
                        <strong>Rp {{ number_format($rentalSubtotal, 0, ',', '.') }}</strong>
                    </div>
                    @if ($totalFnbDisplay > 0)
                        <div class="pos-cost-row">
                            <span>Total Cafe (FnB):</span>
                            <strong>Rp {{ number_format($totalFnbDisplay, 0, ',', '.') }}</strong>
                        </div>
                    @endif
                    <div class="pos-cost-row pos-cost-total">
                        <span>Total Tagihan:</span>
                        <div class="d-flex align-items-center" style="gap: 0.35rem;">
                            <strong style="color: var(--pos-primary); font-size: 0.9375rem;">Rp {{ number_format($rentalSubtotal + $totalFnbDisplay, 0, ',', '.') }}</strong>
                            <span class="pos-pay-badge {{ $isPaid ? 'is-paid' : 'is-unpaid' }}">
                                {{ $isPaid ? 'LUNAS' : 'BELUM BAYAR' }}
                            </span>
                        </div>
                    </div>
                @endif
            </div>

            @if ($activeTx->tipe_transaksi === 'postpaid')
                <div class="pos-meta-row">
                    <span class="pos-pay-badge {{ $isPaid ? 'is-paid' : 'is-unpaid' }}">
                        {{ $isPaid ? 'LUNAS' : 'BELUM BAYAR' }}
                    </span>
                    @if ($activeTx->nama && $activeTx->nama !== 'Pelanggan')
                        <span class="pos-customer-name">{{ $activeTx->nama }}</span>
                    @endif
                </div>
            @else
                @if ($activeTx->nama && $activeTx->nama !== 'Pelanggan')
                    <div class="pos-meta-row">
                        <span class="pos-customer-name">{{ $activeTx->nama }}</span>
                    </div>
                @endif
            @endif

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
                    <a href="{{ route('transaction.showPayment', $activeTx->id_transaksi) }}" class="pos-btn pos-btn-success">
                        <i class="fas fa-money-bill-wave mr-1" aria-hidden="true"></i>
                        <span>Bayar</span>
                    </a>
                    <a href="{{ route('transaction.edit', $activeTx->id_transaksi) }}" class="pos-btn pos-btn-outline-primary">
                        <i class="fas fa-pencil-alt mr-1" aria-hidden="true"></i>
                        <span>Edit</span>
                    </a>
                @elseif ($activeTx->payment_status === 'paid')
                    <a href="{{ route('transaction.print', $activeTx->id_transaksi) }}" target="_blank" rel="noopener" class="pos-btn pos-btn-outline-secondary">
                        <i class="fas fa-print mr-1" aria-hidden="true"></i>
                        <span>Struk</span>
                    </a>
                @endif

                <button
                    type="button"
                    class="pos-btn pos-btn-accent js-open-cafe"
                    data-device-id="{{ $device->id }}"
                    data-tx-id="{{ $activeTx->id_transaksi }}"
                    data-mode="session"
                >
                    <i class="fas fa-coffee mr-1" aria-hidden="true"></i>
                    <span>Cafe</span>
                </button>

                <button
                    type="button"
                    class="pos-btn pos-btn-warning js-open-berhenti"
                    data-device-id="{{ $device->id }}"
                    data-tx-id="{{ $activeTx->id_transaksi }}"
                    data-device-name="{{ $device->nama }}"
                >
                    <i class="fas fa-pause mr-1" aria-hidden="true"></i>
                    <span>Berhenti</span>
                </button>

                @if ($activeTx->tipe_transaksi === 'postpaid' && $activeTx->status_transaksi === 'berjalan')
                    <button
                        type="button"
                        class="pos-btn pos-btn-success js-open-selesai"
                        data-device-id="{{ $device->id }}"
                        data-tx-id="{{ $activeTx->id_transaksi }}"
                        data-device-name="{{ $device->nama }}"
                        data-action="{{ route('transaction.end', $activeTx->id_transaksi) }}"
                    >
                        <i class="fas fa-check mr-1" aria-hidden="true"></i>
                        <span>Selesai</span>
                    </button>
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

