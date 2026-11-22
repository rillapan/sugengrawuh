@extends('layouts.app')

@section('content')
    @php
        $currentTab = in_array($activeTab ?? 'stations', ['stations', 'history'], true) ? $activeTab : 'stations';
        $canOperate = auth()->user()->isAdmin() || auth()->user()->isKasir();
        $isAdmin = auth()->user()->isAdmin();

        $devicesPayload = $devices->map(function ($device) use ($stationNumbers, $activeTransactions) {
            $psList = ($device->playstations && $device->playstations->isNotEmpty())
                ? $device->playstations
                : ($device->playstation ? collect([$device->playstation]) : collect());

            $defaultLocation = null;
            if ($device->relationLoaded('locations')) {
                $defaultLocation = $device->locations->first(function ($loc) {
                    return $loc->type === 'station' && (bool) $loc->is_active;
                }) ?: $device->locations->first(function ($loc) {
                    return (bool) $loc->is_active;
                });
            }

            return [
                'id' => (int) $device->id,
                'station_number' => $stationNumbers[$device->id] ?? null,
                'nama' => (string) $device->nama,
                'status' => $activeTransactions->has((int) $device->id) ? 'Digunakan' : (string) $device->status,
                'playstation_id' => $device->playstation_id ? (int) $device->playstation_id : null,
                'default_location_id' => $defaultLocation ? (int) $defaultLocation->id : null,
                'playstations' => $psList->map(function ($ps) {
                    $presets = $ps->relationLoaded('hourlyPrices')
                        ? $ps->hourlyPrices->sortBy('effective_duration_seconds')->values()
                        : collect();

                    $durations = $presets->map(function ($p) {
                        $sec = (int) $p->effective_duration_seconds;
                        return [
                            'id' => (int) $p->id,
                            'duration_seconds' => $sec,
                            'formatted' => \App\Models\HourlyPrice::formatSeconds($sec),
                            'human' => \App\Models\HourlyPrice::humanizeSeconds($sec),
                            'hours' => intdiv($sec, 3600),
                            'price' => (int) $p->price,
                        ];
                    })->values()->all();

                    if (empty($durations) && (int) ($ps->harga ?? 0) > 0) {
                        $base = (int) $ps->harga;
                        for ($h = 1; $h <= 5; $h++) {
                            $sec = $h * 3600;
                            $durations[] = [
                                'id' => null,
                                'duration_seconds' => $sec,
                                'formatted' => \App\Models\HourlyPrice::formatSeconds($sec),
                                'human' => $h . ' jam',
                                'hours' => $h,
                                'price' => $base * $h,
                            ];
                        }
                    }

                    return [
                        'id' => (int) $ps->id,
                        'nama' => (string) $ps->nama,
                        'harga' => (int) ($ps->harga ?? 0),
                        'durations' => $durations,
                    ];
                })->values()->all(),
            ];
        })->values()->all();

        $devicesById = $devices->keyBy('id');
        $billingService = app(\App\Services\BillingService::class);
        $txSummaries = $txSummaries ?? [];
        $outstandingUnpaidByDevice = $outstandingUnpaidByDevice ?? collect();
        $outstandingSummaries = $outstandingSummaries ?? [];
        $activeTxPayload = [];

        foreach ($activeTransactions as $devId => $tx) {
            $txDevice = $devicesById->get((int) $devId);
            $summary = $txSummaries[(int) $devId] ?? null;
            if (!is_array($summary)) {
                $summary = $billingService->summarizeTransactionBilling($tx);
                $txSummaries[(int) $devId] = $summary;
            }

            $rentalSubtotal = (float) ($summary['rental_subtotal'] ?? 0);
            $fnbPaidTotal = (float) ($summary['fnb_paid_total'] ?? 0);
            $fnbUnpaidTotal = (float) ($summary['fnb_unpaid_total'] ?? 0);
            $fnbItems = $summary['fnb_items'] ?? [];

            $customPkgData = null;
            if ($tx->custom_package) {
                $customPkgData = [
                    'id' => (int) $tx->custom_package->id,
                    'nama_paket' => (string) $tx->custom_package->nama_paket,
                    'harga_total' => (float) $tx->custom_package->harga_total,
                    'playstations' => $tx->custom_package->playstations->map(fn ($p) => [
                        'id' => (int) $p->id,
                        'nama' => (string) $p->nama,
                        'lama_main' => (int) ($p->pivot->lama_main ?? 0),
                    ])->values()->all(),
                    'fnbs' => $tx->custom_package->fnbs->map(fn ($f) => [
                        'id' => (int) $f->id,
                        'nama' => (string) $f->nama,
                        'quantity' => (int) ($f->pivot->quantity ?? 1),
                    ])->values()->all(),
                ];
            }

            $resolvedPsNama = optional($tx->playstation)->nama ?: (optional($txDevice)->playstation_names ?: null);

            $activeTxPayload[(string) $devId] = [
                'id_transaksi' => (int) $tx->id_transaksi,
                'device_id' => (int) $tx->device_id,
                'playstation_id' => $tx->playstation_id ? (int) $tx->playstation_id : null,
                'playstation_nama' => $resolvedPsNama,
                'nama' => (string) $tx->nama,
                'no_telepon' => $tx->no_telepon ? (string) $tx->no_telepon : null,
                'tipe_transaksi' => (string) $tx->tipe_transaksi,
                'status_transaksi' => (string) $tx->status_transaksi,
                'payment_status' => (string) $tx->payment_status,
                'payment_method' => $tx->payment_method ? (string) $tx->payment_method : null,
                'waktu_mulai' => (string) $tx->waktu_mulai,
                'waktu_Selesai' => $tx->waktu_Selesai ? (string) $tx->waktu_Selesai : null,
                'started_at' => $tx->getEffectiveStartTime()->toIso8601String(),
                'expires_at' => optional($tx->resolveLegacyExpiresAt())->toIso8601String(),
                'lost_time_start' => $tx->lost_time_start
                    ? $tx->lost_time_start->copy()->setTimezone(config('app.timezone', 'Asia/Jakarta'))->toIso8601String()
                    : $tx->getEffectiveStartTime()->toIso8601String(),
                'display_duration' => (string) $tx->display_duration,
                'harga' => (float) ($tx->harga ?? 0),
                'diskon' => (float) ($tx->diskon ?? 0),
                'total' => (float) ($tx->total ?? 0),
                'rental_subtotal' => $rentalSubtotal,
                'fnb_unpaid_total' => $fnbUnpaidTotal,
                'fnb_paid_total' => $fnbPaidTotal,
                'fnb_items' => $fnbItems,
                'custom_package' => $customPkgData,
            ];
        }

        $customPackagesPayload = $customPackages->map(function ($pkg) {
            return [
                'id' => (int) $pkg->id,
                'nama_paket' => (string) $pkg->nama_paket,
                'harga_total' => (float) $pkg->harga_total,
                'deskripsi' => $pkg->deskripsi ? (string) $pkg->deskripsi : null,
                'price_group_id' => $pkg->price_group_id ? (int) $pkg->price_group_id : null,
                'playstations' => $pkg->playstations->map(fn ($p) => [
                    'id' => (int) $p->id,
                    'nama' => (string) $p->nama,
                    'lama_main' => (int) ($p->pivot->lama_main ?? 0),
                ])->values()->all(),
                'fnbs' => $pkg->fnbs->map(fn ($f) => [
                    'id' => (int) $f->id,
                    'nama' => (string) $f->nama,
                    'quantity' => (int) ($f->pivot->quantity ?? 1),
                ])->values()->all(),
            ];
        })->values()->all();

        $fnbsPayload = $fnbs->map(function ($fnb) {
            return [
                'id' => (int) $fnb->id,
                'nama' => (string) $fnb->nama,
                'harga_jual' => (float) $fnb->harga_jual,
                'effective_price' => (float) ($fnb->effective_price ?? $fnb->harga_jual),
                'stok' => (int) $fnb->stok,
                'price_group_id' => $fnb->price_group_id ? (int) $fnb->price_group_id : null,
            ];
        })->values()->all();

        $locationsPayload = $locations->map(function ($loc) {
            return [
                'id' => (int) $loc->id,
                'name' => (string) $loc->name,
                'code' => (string) $loc->code,
                'type' => (string) $loc->type,
                'device_id' => $loc->device_id ? (int) $loc->device_id : null,
            ];
        })->values()->all();

        $outstandingUnpaidPayload = [];
        foreach ($outstandingUnpaidByDevice as $devId => $unpaidGroup) {
            $items = [];
            foreach ($unpaidGroup as $unpaidTx) {
                $uSummary = $outstandingSummaries[(int) $unpaidTx->id_transaksi] ?? null;
                if (!is_array($uSummary)) {
                    $uSummary = $billingService->summarizeTransactionBilling($unpaidTx);
                    $outstandingSummaries[(int) $unpaidTx->id_transaksi] = $uSummary;
                }
                $items[] = [
                    'id_transaksi' => (int) $unpaidTx->id_transaksi,
                    'nama' => (string) ($unpaidTx->nama ?: 'Pelanggan'),
                    'tipe_transaksi' => (string) $unpaidTx->tipe_transaksi,
                    'waktu_mulai' => (string) ($unpaidTx->waktu_mulai ?? ''),
                    'waktu_Selesai' => $unpaidTx->waktu_Selesai ? (string) $unpaidTx->waktu_Selesai : null,
                    'payable_total' => (float) ($uSummary['payable_total'] ?? 0),
                    'payment_url' => $canOperate ? route('transaction.showPayment', $unpaidTx->id_transaksi) : null,
                ];
            }
            if (!empty($items)) {
                $outstandingUnpaidPayload[(string) $devId] = $items;
            }
        }

        $posBootstrap = [
            'serverTimestamp' => (int) $serverTimestamp,
            'serverNow' => (string) $serverNow,
            'csrfToken' => csrf_token(),
            'canOperate' => $canOperate,
            'routes' => [
                'storeTransaction' => route('transaction.store'),
                'fnbStore' => route('fnb.orders.store'),
                'timersSync' => route('device.timers'),
                'apiHourlyPrices' => url('/api/get-hourly-prices'),
                'apiCalcDurationPrice' => url('/api/calculate-duration-price'),
                'deviceStopTemplate' => url('/device/__DEVICE_ID__/stop'),
                'transactionEndTemplate' => url('/transaction/__TX_ID__/end'),
                'paymentTemplate' => url('/transaction/__TX_ID__/payment'),
                'editTemplate' => url('/transaction/__TX_ID__/edit'),
                'printTemplate' => url('/transaction/__TX_ID__/print'),
                'fnbQueue' => route('fnb.queue.index'),
                'fnbInvoiceTemplate' => url('/fnb/invoices/__INVOICE_ID__'),
            ],
            'devices' => $devicesPayload,
            'activeTransactions' => $activeTxPayload,
            'outstandingUnpaidTransactions' => $outstandingUnpaidPayload,
            'customPackages' => $customPackagesPayload,
            'fnbs' => $fnbsPayload,
            'locations' => $locationsPayload,
        ];
    @endphp

    @include('transaction.partials._styles')

    <div class="container-fluid px-3 px-md-4 pos-shell">
        @if (session()->has('success'))
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                {{ session('success') }}
                <button type="button" class="close" data-dismiss="alert" aria-label="Tutup">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        @endif

        @if (session()->has('gagal'))
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                {{ session('gagal') }}
                <button type="button" class="close" data-dismiss="alert" aria-label="Tutup">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger" role="alert">
                <ul class="mb-0 pl-3">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div id="pos-feedback-banner" class="pos-feedback-banner" role="status" aria-live="polite" hidden>
            <span id="pos-feedback-message"></span>
        </div>

        @php
            $totalCount = (int) ($stationStats['total'] ?? 0);
            $availCount = (int) ($stationStats['available'] ?? 0);
            $inUseCount = (int) ($stationStats['in_use'] ?? 0);
            $unpaidCount = (int) ($stationStats['unpaid_attention'] ?? 0);
            $availPct = $totalCount > 0 ? round(($availCount / $totalCount) * 100) : 0;
            $inUsePct = $totalCount > 0 ? round(($inUseCount / $totalCount) * 100) : 0;
        @endphp

        <header class="pos-header">
            <div class="pos-header-top">
                <div>
                    <div class="d-flex align-items-center" style="gap: 0.5rem; margin-bottom: 0.25rem;">
                        <h1 class="pos-title mb-0">Operasional Kasir</h1>
                        <span class="pos-live-terminal-badge">
                            <span class="pos-live-dot" aria-hidden="true"></span>
                            <span>Terminal Real-Time</span>
                        </span>
                    </div>
                    <p class="pos-subtitle">Kontrol billing stasiun real-time, order cafe, dan riwayat transaksi.</p>
                </div>

                <div class="pos-header-actions">
                    <a href="{{ route('device.index') }}" class="pos-btn pos-btn-outline-secondary">
                        <i class="fas fa-desktop" aria-hidden="true"></i>
                        <span>Kontrol Hardware</span>
                    </a>

                    @if ($canOperate)
                        <a href="{{ route('transaction.create') }}" class="pos-btn pos-btn-outline-primary">
                            <i class="fas fa-file-alt" aria-hidden="true"></i>
                            <span>Form Lengkap</span>
                        </a>
                        <button type="button" id="btn-standalone-cafe" class="pos-btn pos-btn-accent">
                            <i class="fas fa-coffee" aria-hidden="true"></i>
                            <span>Order Cafe Mandiri</span>
                        </button>
                    @endif
                </div>
            </div>

            <div class="pos-stats-strip" aria-label="Ringkasan Status Stasiun">
                <div class="pos-stat-item pos-stat-total">
                    <div>
                        <div class="pos-stat-label">Total Stasiun</div>
                        <div class="pos-stat-value" id="stat-total-stations">{{ $totalCount }} Unit</div>
                        <div class="small text-muted mt-1" style="font-size: 0.72rem;">Semua Stasiun Terdaftar</div>
                    </div>
                    <span class="pos-stat-icon" aria-hidden="true">
                        <i class="fas fa-gamepad"></i>
                    </span>
                </div>

                <div class="pos-stat-item pos-stat-available">
                    <div>
                        <div class="d-flex align-items-center justify-content-between" style="gap: 0.5rem;">
                            <div class="pos-stat-label" style="color: var(--pos-tertiary);">Tersedia</div>
                            <span class="pos-stat-pct is-mint">{{ $availPct }}%</span>
                        </div>
                        <div class="pos-stat-value" id="stat-available-stations" style="color: var(--pos-tertiary);">{{ $availCount }} Unit</div>
                        <div class="small text-muted mt-1" style="font-size: 0.72rem;">Siap Digunakan Langsung</div>
                    </div>
                    <span class="pos-stat-icon" aria-hidden="true">
                        <i class="fas fa-check-circle"></i>
                    </span>
                </div>

                <div class="pos-stat-item pos-stat-inuse">
                    <div>
                        <div class="d-flex align-items-center justify-content-between" style="gap: 0.5rem;">
                            <div class="pos-stat-label" style="color: var(--pos-primary);">Sedang Bermain</div>
                            <span class="pos-stat-pct is-blue">{{ $inUsePct }}%</span>
                        </div>
                        <div class="pos-stat-value" id="stat-inuse-stations" style="color: var(--pos-primary);">{{ $inUseCount }} Unit</div>
                        <div class="small text-muted mt-1" style="font-size: 0.72rem;">Sesi Aktif Berjalan</div>
                    </div>
                    <span class="pos-stat-icon" aria-hidden="true">
                        <i class="fas fa-play-circle"></i>
                    </span>
                </div>

                <div class="pos-stat-item pos-stat-unpaid">
                    <div>
                        <div class="pos-stat-label" style="color: #b45309;">Belum Lunas</div>
                        <div class="pos-stat-value" id="stat-unpaid-attention">{{ $unpaidCount }} Sesi</div>
                        <div class="small text-muted mt-1" style="font-size: 0.72rem;">Menunggu Penyelesaian</div>
                    </div>
                    <span class="pos-stat-icon" aria-hidden="true">
                        <i class="fas fa-receipt"></i>
                    </span>
                </div>
            </div>
        </header>

        <div class="pos-tablist-wrap">
            <div class="pos-tablist" role="tablist" aria-label="Navigasi Operasional Kasir">
                <button
                    type="button"
                    role="tab"
                    id="tab-stations"
                    class="pos-tab"
                    data-tab="stations"
                    aria-selected="{{ $currentTab === 'stations' ? 'true' : 'false' }}"
                    tabindex="{{ $currentTab === 'stations' ? '0' : '-1' }}"
                >
                    <span>Stasiun Aktif</span>
                    <span class="pos-tab-badge">{{ $totalCount }}</span>
                </button>
                <button
                    type="button"
                    role="tab"
                    id="tab-history"
                    class="pos-tab"
                    data-tab="history"
                    aria-selected="{{ $currentTab === 'history' ? 'true' : 'false' }}"
                    tabindex="{{ $currentTab === 'history' ? '0' : '-1' }}"
                >
                    <span>Riwayat Transaksi</span>
                </button>
            </div>

            <div class="pos-live-legend" aria-label="Status Indikator">
                <div class="pos-legend-item">
                    <span class="pos-hw-dot" style="background-color: var(--pos-tertiary);" aria-hidden="true"></span>
                    <span>Tersedia</span>
                </div>
                <div class="pos-legend-item">
                    <span class="pos-hw-dot" style="background-color: var(--pos-primary);" aria-hidden="true"></span>
                    <span>Bermain (Lost Time)</span>
                </div>
                <div class="pos-legend-item">
                    <span class="pos-hw-dot" style="background-color: #4f46e5;" aria-hidden="true"></span>
                    <span>Bermain (Timer)</span>
                </div>
                <div class="pos-legend-item">
                    <span class="pos-hw-dot" style="background-color: var(--pos-red);" aria-hidden="true"></span>
                    <span>Kritis (&lt;10m)</span>
                </div>
            </div>
        </div>

        <section
            id="panel-stations"
            role="tabpanel"
            aria-labelledby="tab-stations"
            class="pos-tabpanel"
            @if ($currentTab !== 'stations') hidden @endif
        >
            @if ($devices->isEmpty())
                <div class="pos-empty-state">
                    <i class="fas fa-tv fa-2x text-muted" aria-hidden="true"></i>
                    <h2>Belum Ada Perangkat Terdaftar</h2>
                    <p class="mb-3">
                        Belum ada perangkat terdaftar. Tambahkan perangkat di menu Data Perangkat untuk mulai operasional.
                    </p>
                    @if ($isAdmin)
                        <a href="{{ route('device.index') }}" class="pos-btn pos-btn-primary">
                            <i class="fas fa-plus" aria-hidden="true"></i> Kelola Data Perangkat
                        </a>
                    @endif
                </div>
            @else
                <div class="pos-station-grid" id="pos-station-grid">
                    @foreach ($devices as $device)
                        @include('transaction.partials._station_card', [
                            'device' => $device,
                            'stationNum' => $stationNumbers[$device->id] ?? null,
                            'activeTx' => $activeTransactions->get((int) $device->id),
                            'txSummary' => $txSummaries[(int) $device->id] ?? null,
                            'outstandingUnpaidTxs' => $outstandingUnpaidByDevice->get((int) $device->id, collect()),
                            'outstandingSummaries' => $outstandingSummaries,
                            'canOperate' => $canOperate,
                        ])
                    @endforeach
                </div>
            @endif
        </section>

        <section
            id="panel-history"
            role="tabpanel"
            aria-labelledby="tab-history"
            class="pos-tabpanel"
            @if ($currentTab !== 'history') hidden @endif
        >
            @include('transaction.partials._history_tab', [
                'canOperate' => $canOperate,
            ])
        </section>

        @include('transaction.partials._modals')
    </div>

    <script type="application/json" id="pos-bootstrap-data">{!! json_encode($posBootstrap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) !!}</script>
@endsection

@section('scripts')
    <script src="{{ asset('js/transaction-dashboard.js') }}" defer></script>
@endsection
