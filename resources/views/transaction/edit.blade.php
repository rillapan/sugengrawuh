@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-10">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Edit Transaksi #{{ $transaction->id_transaksi }}</h5>
                    <small class="text-muted">Hanya transaksi yang belum dibayar yang dapat diedit</small>
                </div>

                <div class="card-body">
                    @if (session('success'))
                        <div class="alert alert-success" role="alert">
                            {{ session('success') }}
                        </div>
                    @endif

                    @if (session('error') || session('gagal'))
                        <div class="alert alert-danger" role="alert">
                            {{ session('error') ?? session('gagal') }}
                        </div>
                    @endif

                    @if ($errors->any())
                        <div class="alert alert-danger" role="alert">
                            <ul class="mb-0">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @php
                        $currentPlaystation = $currentPlaystation ?? $transaction->resolveCurrentPlaystation();
                        $currentCategoryUnavailable = $currentCategoryUnavailable ?? false;
                        $isSessionEnded = $isSessionEnded ?? false;
                        $currentDurationSeconds = $currentDurationSeconds ?? $transaction->resolveTotalDurationSeconds();
                        $deviceCategoriesMap = $deviceCategoriesMap ?? [];
                        $initialPreview = $initialPreview ?? [];
                        $currentCatName = $currentPlaystation->nama ?? ($transaction->playstation->nama ?? 'Tidak Tersedia');
                        $currentCatRate = (float) ($currentPlaystation->harga ?? $transaction->harga ?? 0);
                        $activeDeviceCategories = $deviceCategoriesMap[(string) $transaction->device_id] ?? [];
                        $selectedPsId = old('playstation_id', $currentCategoryUnavailable ? '' : ($transaction->playstation_id ?: ($currentPlaystation->id ?? '')));
                        $selectedPricingMode = old('pricing_mode', 'from_now');
                        $selectedDurationSeconds = (int) old('duration_seconds', $currentDurationSeconds ?: 3600);
                    @endphp

                    @if ($isSessionEnded)
                        <div class="alert alert-danger" role="alert" id="session_ended_alert">
                            <i class="fas fa-exclamation-circle"></i>
                            <strong>Sesi Berakhir / Kedaluwarsa:</strong> Sesi transaksi sudah berakhir atau kedaluwarsa dan tidak dapat diaktifkan kembali.
                        </div>
                    @endif

                    @if ($currentCategoryUnavailable)
                        <div class="alert alert-warning" role="alert" id="category_unavailable_alert">
                            <i class="fas fa-exclamation-triangle"></i>
                            Kategori permainan saat ini tidak lagi tersedia pada konfigurasi perangkat ini. Silakan pilih kategori permainan yang valid secara manual.
                        </div>
                    @endif

                    <!-- Current Category & Tariff Summary Banner -->
                    <div class="card bg-light border mb-3" id="current_tariff_summary_banner">
                        <div class="card-body py-2 px-3">
                            <div class="row align-items-center">
                                <div class="col-md-4 mb-1 mb-md-0">
                                    <small class="text-muted d-block">Perangkat Saat Ini</small>
                                    <strong>{{ $transaction->device->nama ?? 'N/A' }}</strong>
                                </div>
                                <div class="col-md-4 mb-1 mb-md-0">
                                    <small class="text-muted d-block">Kategori Saat Ini</small>
                                    <strong id="banner_current_category">{{ $currentCatName }}</strong>
                                    @if ($currentCategoryUnavailable)
                                        <span class="badge badge-warning text-dark ml-1">Tidak Tersedia</span>
                                    @endif
                                </div>
                                <div class="col-md-4">
                                    <small class="text-muted d-block">Tarif Saat Ini</small>
                                    <strong id="banner_current_rate">Rp {{ number_format($currentCatRate, 0, ',', '.') }}/jam</strong>
                                </div>
                            </div>
                        </div>
                    </div>

                    <form method="POST" action="{{ route('transaction.update', $transaction->id_transaksi) }}" id="editForm">
                        @csrf
                        @method('PUT')

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group mb-3">
                                    <label for="nama" class="form-label">Nama Pelanggan</label>
                                    <input type="text" class="form-control" id="nama" value="{{ $transaction->nama }}" disabled>
                                    <small class="text-muted">Nama pelanggan tidak dapat diubah</small>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="form-group mb-3">
                                    <label for="waktu_mulai" class="form-label">Waktu Mulai</label>
                                    <input type="text" class="form-control" id="waktu_mulai" value="{{ $transaction->waktu_mulai }}" disabled>
                                    <small class="text-muted">Waktu mulai tidak dapat diubah</small>
                                </div>
                            </div>
                        </div>

                        <div class="form-group mb-3">
                            <label for="tipe_transaksi" class="form-label">Tipe Transaksi <span class="text-danger">*</span></label>
                            <select class="form-control" id="tipe_transaksi" name="tipe_transaksi" required>
                                <option value="postpaid" {{ old('tipe_transaksi', $transaction->tipe_transaksi) === 'postpaid' ? 'selected' : '' }}>Lost Time</option>
                                <option value="prepaid" {{ old('tipe_transaksi', $transaction->tipe_transaksi) === 'prepaid' ? 'selected' : '' }}>Timer</option>
                                <option value="custom_package" {{ old('tipe_transaksi', $transaction->tipe_transaksi) === 'custom_package' ? 'selected' : '' }}>Custom Paket</option>
                            </select>
                            <small class="text-muted">Anda dapat mengubah tipe transaksi. Timer akan tetap berjalan.</small>
                        </div>

                        <!-- Timer Preview Section -->
                        <div class="alert alert-info" id="timer_preview_section">
                            <h6 class="mb-3"><i class="fas fa-clock"></i> Preview Timer</h6>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="card bg-light">
                                        <div class="card-body">
                                            <h6 class="text-muted mb-2">Timer Saat Ini</h6>
                                            <div class="timer-display-large" id="current_timer">
                                                <span class="timer-value">00:00:00</span>
                                            </div>
                                            <small class="text-muted" id="current_timer_type">
                                                {{ $transaction->tipe_transaksi === 'postpaid' ? 'Lost Time (Count Up)' : 'Timer (Countdown)' }}
                                            </small>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="card bg-success text-white" id="new_timer_card">
                                        <div class="card-body">
                                            <h6 class="mb-2">Timer Setelah Edit</h6>
                                            <div class="timer-display-large" id="new_timer">
                                                <span class="timer-value">00:00:00</span>
                                            </div>
                                            <small id="new_timer_type">-</small>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-3" id="timer_explanation">
                                <small class="text-muted">
                                    <i class="fas fa-info-circle"></i> 
                                    <span id="explanation_text">Timer akan disesuaikan berdasarkan waktu yang sudah terpakai.</span>
                                </small>
                            </div>
                        </div>

                        <div class="form-group mb-3">
                            <label for="device_id" class="form-label">Perangkat <span class="text-danger">*</span></label>
                            <select class="form-control" id="device_id" name="device_id" required>
                                @foreach($devices as $device)
                                    @php
                                        $devCats = $deviceCategoriesMap[(string) $device->id] ?? [];
                                        $defaultCatHarga = !empty($devCats) ? $devCats[0]['harga'] : ($device->playstation->harga ?? 0);
                                        $defaultCatId = !empty($devCats) ? $devCats[0]['id'] : ($device->playstation_id ?? '');
                                    @endphp
                                    <option value="{{ $device->id }}" 
                                        {{ (int) old('device_id', $transaction->device_id) === (int) $device->id ? 'selected' : '' }}
                                        data-harga="{{ $defaultCatHarga }}"
                                        data-playstation-id="{{ $defaultCatId }}">
                                        {{ $device->nama }} — {{ $device->playstation_names ?: 'N/A' }}
                                        @if($transaction->device_id == $device->id)
                                            (Sedang Digunakan)
                                        @else
                                            (Tersedia)
                                        @endif
                                    </option>
                                @endforeach
                            </select>
                            <small class="text-muted">Hanya menampilkan perangkat yang tersedia. Anda dapat mengubah perangkat dan timer akan tetap berjalan.</small>
                        </div>

                        <!-- Kategori Permainan -->
                        <div class="form-group mb-3" id="playstation_category_container" style="display: {{ old('tipe_transaksi', $transaction->tipe_transaksi) === 'custom_package' ? 'none' : 'block' }}">
                            <label for="playstation_id" class="form-label">Kategori Permainan <span class="text-danger">*</span></label>
                            <select class="form-control @error('playstation_id') is-invalid @enderror"
                                    id="playstation_id"
                                    name="playstation_id"
                                    @if($currentCategoryUnavailable || $errors->has('playstation_id')) aria-invalid="true" aria-describedby="playstation_id_error" @endif>
                                @if($currentCategoryUnavailable)
                                    <option value="" {{ $selectedPsId === '' || $selectedPsId === null ? 'selected' : '' }}>-- Pilih Kategori Permainan Valid --</option>
                                @endif
                                @foreach($activeDeviceCategories as $idx => $cat)
                                    @php
                                        $isCatSelected = false;
                                        if ($selectedPsId !== '' && $selectedPsId !== null) {
                                            $isCatSelected = ((int) $selectedPsId === (int) $cat['id']);
                                        } elseif (!$currentCategoryUnavailable && $idx === 0) {
                                            $isCatSelected = true;
                                        }
                                    @endphp
                                    <option value="{{ $cat['id'] }}"
                                            data-harga="{{ $cat['harga'] }}"
                                            {{ $isCatSelected ? 'selected' : '' }}>
                                        {{ $cat['nama'] }} — Rp {{ number_format($cat['harga'], 0, ',', '.') }}/jam
                                    </option>
                                @endforeach
                            </select>
                            @error('playstation_id')
                                <div class="invalid-feedback d-block" id="playstation_id_error" role="alert">{{ $message }}</div>
                            @enderror
                            <small class="text-muted">Pilih kategori permainan sesuai tarif perangkat yang berlaku.</small>
                        </div>

                        <!-- Berlaku (Mode Perubahan Harga) -->
                        <fieldset class="form-group mb-3 p-3 border rounded bg-light" id="pricing_mode_fieldset" style="display: {{ old('tipe_transaksi', $transaction->tipe_transaksi) === 'custom_package' ? 'none' : 'block' }}">
                            <legend class="col-form-label font-weight-bold pt-0 mb-2" style="font-size: 1rem;">Berlaku (Mode Perubahan Harga)</legend>
                            <div class="form-check mb-2">
                                <input class="form-check-input"
                                       type="radio"
                                       name="pricing_mode"
                                       id="pricing_mode_from_now"
                                       value="from_now"
                                       style="accent-color: #0d6efd;"
                                       {{ $selectedPricingMode === 'from_now' ? 'checked' : '' }}>
                                <label class="form-check-label" for="pricing_mode_from_now">
                                    <strong>Mulai Sekarang (Pindah Permainan Saat Berjalan)</strong>
                                    <span class="d-block small text-muted">Biaya sebelum perubahan mengikuti tarif kategori lama, biaya setelah perubahan mengikuti tarif kategori baru.</span>
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input"
                                       type="radio"
                                       name="pricing_mode"
                                       id="pricing_mode_correction"
                                       value="correction"
                                       style="accent-color: #0d6efd;"
                                       {{ $selectedPricingMode === 'correction' ? 'checked' : '' }}>
                                <label class="form-check-label" for="pricing_mode_correction">
                                    <strong>Koreksi dari Awal (Koreksi Kategori)</strong>
                                    <span class="d-block small text-muted">Digunakan jika kasir salah memilih kategori sejak awal. Seluruh durasi dihitung ulang memakai tarif kategori baru.</span>
                                </label>
                            </div>
                        </fieldset>

                        <!-- Custom Package Selection (only shown for custom_package type) -->
                        <div id="custom_package_container" style="display: {{ old('tipe_transaksi', $transaction->tipe_transaksi) === 'custom_package' ? 'block' : 'none' }}">
                            <div class="form-group mb-3">
                                <label for="custom_package_id" class="form-label">Pilih Custom Paket <span class="text-danger">*</span></label>
                                <select class="form-control" id="custom_package_id" name="custom_package_id">
                                    <option value="">-- Pilih Custom Paket --</option>
                                    @foreach($customPackages as $package)
                                        <option value="{{ $package->id }}" 
                                            {{ (int) old('custom_package_id', $transaction->custom_package_id) === (int) $package->id ? 'selected' : '' }}
                                            data-harga="{{ $package->harga_total }}"
                                            data-lama-main="{{ $package->playstations->sum('pivot.lama_main') }}"
                                            data-playstation-ids="{{ $package->playstations->pluck('id')->implode(',') }}">
                                            {{ $package->nama_paket }} - Rp {{ number_format($package->harga_total, 0, ',', '.') }}
                                            ({{ $package->playstations->sum('pivot.lama_main') }} menit)
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <!-- Jam Main (only shown for prepaid type) -->
                        <div id="jam_main_container" style="display: {{ old('tipe_transaksi', $transaction->tipe_transaksi) === 'prepaid' ? 'block' : 'none' }}">
                            <input type="hidden" name="duration_seconds" id="duration_seconds" value="{{ $selectedDurationSeconds }}">
                            <div class="form-group mb-3">
                                <label for="jam_main" class="form-label">Jam Main / Durasi <span class="text-danger">*</span></label>
                                @php
                                    $durationOptionsBySec = [];
                                    $activeCatForDur = collect($activeDeviceCategories)->firstWhere('id', (int) $selectedPsId)
                                        ?: ($activeDeviceCategories[0] ?? null);
                                    if ($activeCatForDur && !empty($activeCatForDur['durations'])) {
                                        foreach ($activeCatForDur['durations'] as $dPreset) {
                                            $dSec = (int) ($dPreset['duration_seconds'] ?? 0);
                                            if ($dSec > 0) {
                                                $isWholeHour = ($dSec % 3600 === 0);
                                                $hVal = intdiv($dSec, 3600);
                                                $durationOptionsBySec[$dSec] = [
                                                    'value' => $isWholeHour ? (string) $hVal : (string) $dPreset['formatted'],
                                                    'seconds' => $dSec,
                                                    'label' => ($dPreset['human'] ?? ($hVal . ' Jam')) . ' — Rp ' . number_format((float) ($dPreset['price'] ?? 0), 0, ',', '.'),
                                                ];
                                            }
                                        }
                                    }
                                    for ($i = 1; $i <= 10; $i++) {
                                        $hSec = $i * 3600;
                                        if (!isset($durationOptionsBySec[$hSec])) {
                                            $durationOptionsBySec[$hSec] = [
                                                'value' => (string) $i,
                                                'seconds' => $hSec,
                                                'label' => $i . ' Jam',
                                            ];
                                        }
                                    }
                                    if ($selectedDurationSeconds > 0 && !isset($durationOptionsBySec[$selectedDurationSeconds])) {
                                        $isWhole = ($selectedDurationSeconds % 3600 === 0);
                                        $durationOptionsBySec[$selectedDurationSeconds] = [
                                            'value' => $isWhole ? (string) intdiv($selectedDurationSeconds, 3600) : \App\Models\HourlyPrice::formatSeconds($selectedDurationSeconds),
                                            'seconds' => $selectedDurationSeconds,
                                            'label' => \App\Models\HourlyPrice::humanizeSeconds($selectedDurationSeconds) . ' (Durasi Saat Ini)',
                                        ];
                                    }
                                    ksort($durationOptionsBySec);
                                @endphp
                                <select class="form-control" id="jam_main" name="jam_main">
                                    @foreach($durationOptionsBySec as $dSec => $opt)
                                        <option value="{{ $opt['value'] }}"
                                                data-duration-seconds="{{ $opt['seconds'] }}"
                                                {{ (int) $selectedDurationSeconds === (int) $dSec ? 'selected' : '' }}>
                                            {{ $opt['label'] }}
                                        </option>
                                    @endforeach
                                </select>
                                <small class="text-muted">Mengubah jam main akan menyesuaikan harga secara otomatis. Mengubah kategori saja tidak akan mengubah durasi sesi.</small>
                            </div>

                            <!-- Price Preview for Prepaid -->
                            <div class="alert alert-info" id="price_preview">
                                <strong>Estimasi Harga PS:</strong> <span id="estimated_price">Rp {{ number_format((float) ($initialPreview['new_rental_estimate'] ?? 0), 0, ',', '.') }}</span>
                            </div>
                        </div>

                        <!-- Info for Lost Time -->
                        <div id="lost_time_info" style="display: {{ old('tipe_transaksi', $transaction->tipe_transaksi) === 'postpaid' ? 'block' : 'none' }}">
                            <div class="alert alert-warning">
                                <i class="fas fa-info-circle"></i> 
                                <strong>Lost Time:</strong> Harga akan dihitung saat transaksi selesai berdasarkan durasi bermain.
                                Timer tetap berjalan dari waktu mulai awal.
                            </div>
                        </div>

                        <!-- Estimasi Harga & Perbandingan Perubahan Card -->
                        @php
                            $prevOldCat = $initialPreview['current_category']['nama'] ?? $currentCatName;
                            $prevOldRate = (float) ($initialPreview['current_category']['harga'] ?? $currentCatRate);
                            $prevNewCat = $initialPreview['new_category']['nama'] ?? $prevOldCat;
                            $prevNewRate = (float) ($initialPreview['new_category']['harga'] ?? $prevOldRate);
                            $prevModeLabel = $initialPreview['pricing_mode_label'] ?? 'Mulai Sekarang (Pindah Permainan Saat Berjalan)';
                            $prevOldRental = (float) ($initialPreview['current_rental_estimate'] ?? 0);
                            $prevNewRental = (float) ($initialPreview['new_rental_estimate'] ?? 0);
                            $prevDiff = (float) ($initialPreview['price_difference'] ?? ($prevNewRental - $prevOldRental));
                            $prevFnbUnpaid = (float) ($initialPreview['fnb_unpaid_total'] ?? 0);
                            $prevPayable = (float) ($initialPreview['new_payable_total'] ?? ($prevNewRental + $prevFnbUnpaid));
                            $prevSegments = $initialPreview['segments_preview'] ?? [];
                            $prevError = $initialPreview['error'] ?? null;
                        @endphp
                        <div class="card border-info mb-3" id="repricing_preview_card" role="status" aria-live="polite">
                            <div class="card-header bg-info text-white py-2">
                                <h6 class="mb-0"><i class="fas fa-calculator"></i> Estimasi Harga &amp; Perbandingan Perubahan</h6>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-danger mb-3" id="preview_error_alert" role="alert" @if(empty($prevError)) hidden @endif>
                                    {{ $prevError }}
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <table class="table table-sm table-borderless mb-0">
                                            <tr>
                                                <td class="text-muted">Kategori Saat Ini / Tarif Lama:</td>
                                                <td>
                                                    <strong id="preview_old_category">{{ $prevOldCat }}</strong> —
                                                    <span id="preview_old_rate">Rp {{ number_format($prevOldRate, 0, ',', '.') }}/jam</span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td class="text-muted">Kategori Pengganti / Tarif Baru:</td>
                                                <td>
                                                    <strong id="preview_new_category">{{ $prevNewCat }}</strong> —
                                                    <span id="preview_new_rate">Rp {{ number_format($prevNewRate, 0, ',', '.') }}/jam</span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td class="text-muted">Mode Perubahan:</td>
                                                <td><strong id="preview_mode_label">{{ $prevModeLabel }}</strong></td>
                                            </tr>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <table class="table table-sm table-borderless mb-0">
                                            <tr>
                                                <td class="text-muted">Estimasi Sewa Saat Ini:</td>
                                                <td><strong id="preview_old_rental">Rp {{ number_format($prevOldRental, 0, ',', '.') }}</strong></td>
                                            </tr>
                                            <tr>
                                                <td class="text-muted">Estimasi Sewa Setelah Perubahan:</td>
                                                <td><strong id="preview_new_rental">Rp {{ number_format($prevNewRental, 0, ',', '.') }}</strong></td>
                                            </tr>
                                            <tr>
                                                <td class="text-muted">Selisih Harga:</td>
                                                <td>
                                                    <strong id="preview_price_diff" class="{{ $prevDiff > 0 ? 'text-danger' : ($prevDiff < 0 ? 'text-success' : '') }}">
                                                        {{ $prevDiff > 0 ? '+Rp ' . number_format($prevDiff, 0, ',', '.') : ($prevDiff < 0 ? '-Rp ' . number_format(abs($prevDiff), 0, ',', '.') : 'Rp 0') }}
                                                    </strong>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td class="text-muted">FnB Belum Dibayar:</td>
                                                <td><span id="preview_fnb_unpaid">Rp {{ number_format($prevFnbUnpaid, 0, ',', '.') }}</span></td>
                                            </tr>
                                            <tr class="border-top">
                                                <td><strong>Estimasi Total Tagihan:</strong></td>
                                                <td><strong class="text-primary" id="preview_payable_total">Rp {{ number_format($prevPayable, 0, ',', '.') }}</strong></td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>

                                <div class="mt-3" id="preview_segments_container" @if(count($prevSegments) < 2) hidden @endif>
                                    <h6 class="small font-weight-bold text-uppercase text-muted mb-2">Rincian Segmen Tarif</h6>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-bordered mb-0">
                                            <thead class="thead-light">
                                                <tr>
                                                    <th>#</th>
                                                    <th>Kategori</th>
                                                    <th>Waktu</th>
                                                    <th>Durasi</th>
                                                    <th>Tarif/Jam</th>
                                                    <th>Subtotal</th>
                                                </tr>
                                            </thead>
                                            <tbody id="preview_segments_tbody">
                                                @foreach($prevSegments as $seg)
                                                    <tr>
                                                        <td>{{ $seg['segment_order'] ?? $loop->iteration }}</td>
                                                        <td>{{ $seg['playstation_name'] ?? 'Game' }}</td>
                                                        <td>{{ ($seg['started_at_display'] ?? '-') . ' - ' . ($seg['ended_at_display'] ?? '-') }}</td>
                                                        <td>{{ $seg['duration_display'] ?? '-' }}</td>
                                                        <td>Rp {{ number_format((float) ($seg['hourly_rate'] ?? 0), 0, ',', '.') }}/jam</td>
                                                        <td>Rp {{ number_format((float) ($seg['allocated_billed_amount'] ?? $seg['raw_amount'] ?? 0), 0, ',', '.') }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <hr>

                        <div class="card">
                            <div class="card-header bg-primary text-white">
                                <h6 class="mb-0"><i class="fas fa-utensils"></i> Kelola FnB Pesanan</h6>
                            </div>
                            <div class="card-body">
                                <!-- FnB Items Table -->
                                <div class="table-responsive mb-3">
                                    <table class="table table-bordered" id="fnbTable">
                                        <thead class="table-light">
                                            <tr>
                                                <th style="width: 40%">Nama FnB</th>
                                                <th style="width: 15%">Qty</th>
                                                <th style="width: 20%">Harga</th>
                                                <th style="width: 20%">Subtotal</th>
                                                <th style="width: 5%">Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody id="fnbTableBody">
                                            @if($transaction->transactionFnbs->isEmpty())
                                                <tr id="emptyRow">
                                                    <td colspan="5" class="text-center text-muted">Belum ada FnB dalam transaksi ini</td>
                                                </tr>
                                            @else
                                                @foreach($transaction->transactionFnbs as $fnbItem)
                                                    <tr data-fnb-id="{{ $fnbItem->fnb_id }}" class="fnb-row">
                                                        <td>{{ $fnbItem->fnb->nama }}</td>
                                                        <td>
                                                            <input type="number" 
                                                                   class="form-control form-control-sm qty-input" 
                                                                   value="{{ $fnbItem->qty }}" 
                                                                   min="1"
                                                                   data-fnb-id="{{ $fnbItem->fnb_id }}"
                                                                   data-old-qty="{{ $fnbItem->qty }}">
                                                        </td>
                                                        <td>
                                                            <input type="number" 
                                                                   class="form-control form-control-sm price-input" 
                                                                   value="{{ $fnbItem->harga_jual }}" 
                                                                   min="0"
                                                                   data-fnb-id="{{ $fnbItem->fnb_id }}"
                                                                   data-old-price="{{ $fnbItem->harga_jual }}">
                                                        </td>
                                                        <td class="subtotal-cell">
                                                            Rp {{ number_format($fnbItem->qty * $fnbItem->harga_jual, 0, ',', '.') }}
                                                        </td>
                                                        <td class="text-center">
                                                            <button type="button" 
                                                                    class="btn btn-sm btn-danger delete-fnb" 
                                                                    data-fnb-id="{{ $fnbItem->fnb_id }}"
                                                                    title="Hapus">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            @endif
                                        </tbody>
                                        <tfoot>
                                            <tr class="table-info">
                                                <th colspan="3" class="text-end">Total FnB:</th>
                                                <th id="fnbTotalDisplay">Rp {{ number_format($transaction->getFnbTotalAttribute(), 0, ',', '.') }}</th>
                                                <th></th>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>

                                <!-- Add New FnB Section -->
                                <div class="card bg-light">
                                    <div class="card-body">
                                        <h6 class="mb-3"><i class="fas fa-plus-circle"></i> Tambah FnB Baru</h6>
                                        <div class="row g-2">
                                            <div class="col-md-5">
                                                <select class="form-control form-control-sm" id="newFnbSelect">
                                                    <option value="">-- Pilih FnB --</option>
                                                    @foreach($fnbs as $fnb)
                                                        <option value="{{ $fnb->id }}" 
                                                                data-harga="{{ $fnb->harga_jual }}"
                                                                data-stok="{{ $fnb->stok }}">
                                                            {{ $fnb->nama }} - Rp {{ number_format($fnb->harga_jual, 0, ',', '.') }}
                                                            @if($fnb->stok == -1)
                                                                (Stok: Unlimited)
                                                            @else
                                                                (Stok: {{ $fnb->stok }})
                                                            @endif
                                                        </option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="col-md-3">
                                                <input type="number" 
                                                       class="form-control form-control-sm" 
                                                       id="newFnbQty" 
                                                       placeholder="Qty" 
                                                       min="1" 
                                                       value="1">
                                            </div>
                                            <div class="col-md-4">
                                                <button type="button" class="btn btn-sm btn-success" id="addFnbBtn">
                                                    <i class="fas fa-plus"></i> Tambah FnB
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-group mt-4">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Simpan Perubahan
                            </button>
                            <a href="{{ route('transaction.index') }}" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Batal
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const tipeTransaksiSelect = document.getElementById('tipe_transaksi');
    const jamMainContainer = document.getElementById('jam_main_container');
    const jamMainSelect = document.getElementById('jam_main');
    const durationSecondsInput = document.getElementById('duration_seconds');
    const deviceSelect = document.getElementById('device_id');
    const playstationSelect = document.getElementById('playstation_id');
    const playstationCategoryContainer = document.getElementById('playstation_category_container');
    const pricingModeFieldset = document.getElementById('pricing_mode_fieldset');
    const pricingModeRadios = document.querySelectorAll('input[name="pricing_mode"]');
    const customPackageContainer = document.getElementById('custom_package_container');
    const customPackageSelect = document.getElementById('custom_package_id');
    const lostTimeInfo = document.getElementById('lost_time_info');
    const pricePreview = document.getElementById('price_preview');
    const estimatedPrice = document.getElementById('estimated_price');

    const deviceCategoriesMap = @json($deviceCategoriesMap ?? []);
    const initialDeviceId = String(@json((string) $transaction->device_id));
    const initialPlaystationId = @json($transaction->playstation_id ? (int) $transaction->playstation_id : null);
    const currentCategoryUnavailable = Boolean(@json((bool) ($currentCategoryUnavailable ?? false)));
    const editPreviewUrl = @json(route('transaction.editPreview', $transaction->id_transaksi));

    // Timer data from server using ISO timestamps (midnight-safe & HH:MM:SS-safe)
    const startedAtIso = @json($transaction->getEffectiveStartTime()->toIso8601String());
    const expiresAtIso = @json(optional($transaction->resolveLegacyExpiresAt())->toIso8601String());
    const currentTipe = @json($transaction->tipe_transaksi);
    const currentDurationSeconds = {{ (int) ($currentDurationSeconds ?? 0) }};

    const startTime = new Date(startedAtIso);
    const expiresTime = expiresAtIso ? new Date(expiresAtIso) : null;
    let previewAbortController = null;

    function formatTime(seconds) {
        const safeSec = Math.max(0, Math.floor(Number(seconds) || 0));
        const hours = Math.floor(safeSec / 3600);
        const minutes = Math.floor((safeSec % 3600) / 60);
        const secs = safeSec % 60;
        return `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
    }

    function formatRupiah(amount) {
        const num = Math.round(Number(amount) || 0);
        return 'Rp ' + num.toLocaleString('id-ID');
    }

    function getSelectedPricingMode() {
        for (let i = 0; i < pricingModeRadios.length; i++) {
            if (pricingModeRadios[i].checked) {
                return pricingModeRadios[i].value;
            }
        }
        return 'from_now';
    }

    function getSelectedDurationSeconds() {
        if (jamMainSelect && jamMainSelect.selectedIndex >= 0) {
            const opt = jamMainSelect.options[jamMainSelect.selectedIndex];
            if (opt && opt.dataset.durationSeconds) {
                const parsed = parseInt(opt.dataset.durationSeconds, 10);
                if (parsed > 0) {
                    return parsed;
                }
            }
            if (opt && opt.value) {
                const rawVal = String(opt.value);
                const hmsMatch = rawVal.match(/^(\d+):(\d{2})(?::(\d{2}))?$/);
                if (hmsMatch) {
                    return (parseInt(hmsMatch[1], 10) * 3600) + (parseInt(hmsMatch[2], 10) * 60) + (hmsMatch[3] ? parseInt(hmsMatch[3], 10) : 0);
                }
                const numVal = parseFloat(rawVal);
                if (!Number.isNaN(numVal) && numVal > 0) {
                    return Math.round(numVal * 3600);
                }
            }
        }
        if (durationSecondsInput && parseInt(durationSecondsInput.value, 10) > 0) {
            return parseInt(durationSecondsInput.value, 10);
        }
        return currentDurationSeconds > 0 ? currentDurationSeconds : 3600;
    }

    function syncDurationHiddenInput() {
        if (durationSecondsInput) {
            durationSecondsInput.value = String(getSelectedDurationSeconds());
        }
    }

    function getActiveCategoryObject() {
        const devId = String(deviceSelect.value || '');
        const cats = Array.isArray(deviceCategoriesMap[devId]) ? deviceCategoriesMap[devId] : [];
        const selectedPsId = playstationSelect ? parseInt(playstationSelect.value, 10) : NaN;
        if (!Number.isNaN(selectedPsId)) {
            for (let i = 0; i < cats.length; i++) {
                if (Number(cats[i].id) === selectedPsId) {
                    return cats[i];
                }
            }
        }
        return cats.length > 0 ? cats[0] : null;
    }

    function populateCategoryOptionsForDevice(preserveSelection) {
        if (!playstationSelect) return;
        const devId = String(deviceSelect.value || '');
        const cats = Array.isArray(deviceCategoriesMap[devId]) ? deviceCategoriesMap[devId] : [];
        const previousPsId = preserveSelection && playstationSelect.value ? parseInt(playstationSelect.value, 10) : null;

        playstationSelect.replaceChildren();

        const isOriginalDeviceWithUnavailableCategory = (devId === initialDeviceId && currentCategoryUnavailable);
        let hasMatchedOption = false;

        if (isOriginalDeviceWithUnavailableCategory || cats.length === 0) {
            const placeholderOpt = document.createElement('option');
            placeholderOpt.value = '';
            placeholderOpt.textContent = '-- Pilih Kategori Permainan Valid --';
            playstationSelect.appendChild(placeholderOpt);
        }

        cats.forEach(function(cat, idx) {
            const opt = document.createElement('option');
            opt.value = String(cat.id);
            opt.dataset.harga = String(cat.harga || 0);
            opt.textContent = `${cat.nama} — ${formatRupiah(cat.harga)}/jam`;

            if (previousPsId !== null && Number(cat.id) === previousPsId) {
                opt.selected = true;
                hasMatchedOption = true;
            } else if (previousPsId === null && !isOriginalDeviceWithUnavailableCategory) {
                if (initialPlaystationId !== null && Number(cat.id) === Number(initialPlaystationId)) {
                    opt.selected = true;
                    hasMatchedOption = true;
                } else if (initialPlaystationId === null && idx === 0) {
                    opt.selected = true;
                    hasMatchedOption = true;
                }
            }

            playstationSelect.appendChild(opt);
        });

        if (!hasMatchedOption && !isOriginalDeviceWithUnavailableCategory && cats.length > 0) {
            playstationSelect.value = String(cats[0].id);
        }
    }

    function rebuildDurationOptionsForCategory() {
        if (!jamMainSelect) return;
        const preservedSeconds = getSelectedDurationSeconds();
        const cat = getActiveCategoryObject();
        const optionsBySec = {};

        if (cat && Array.isArray(cat.durations)) {
            cat.durations.forEach(function(dPreset) {
                const sec = parseInt(dPreset.duration_seconds, 10);
                if (sec > 0) {
                    const isWholeHour = (sec % 3600 === 0);
                    const hVal = Math.floor(sec / 3600);
                    optionsBySec[sec] = {
                        value: isWholeHour ? String(hVal) : String(dPreset.formatted || formatTime(sec)),
                        seconds: sec,
                        label: `${dPreset.human || (hVal + ' Jam')} — ${formatRupiah(dPreset.price || 0)}`
                    };
                }
            });
        }

        for (let h = 1; h <= 10; h++) {
            const hSec = h * 3600;
            if (!optionsBySec[hSec]) {
                optionsBySec[hSec] = {
                    value: String(h),
                    seconds: hSec,
                    label: `${h} Jam`
                };
            }
        }

        if (preservedSeconds > 0 && !optionsBySec[preservedSeconds]) {
            const isWhole = (preservedSeconds % 3600 === 0);
            optionsBySec[preservedSeconds] = {
                value: isWhole ? String(Math.floor(preservedSeconds / 3600)) : formatTime(preservedSeconds),
                seconds: preservedSeconds,
                label: `${formatTime(preservedSeconds)} (Durasi Saat Ini)`
            };
        }

        const sortedSecs = Object.keys(optionsBySec).map(Number).sort(function(a, b) { return a - b; });
        jamMainSelect.replaceChildren();
        sortedSecs.forEach(function(sec) {
            const item = optionsBySec[sec];
            const opt = document.createElement('option');
            opt.value = item.value;
            opt.dataset.durationSeconds = String(item.seconds);
            opt.textContent = item.label;
            if (item.seconds === preservedSeconds) {
                opt.selected = true;
            }
            jamMainSelect.appendChild(opt);
        });

        syncDurationHiddenInput();
    }

    function updateTimers() {
        const now = new Date();
        const elapsedSeconds = Math.max(0, Math.floor((now - startTime) / 1000));
        const elapsedMinutes = Math.floor(elapsedSeconds / 60);

        const currentTimerEl = document.getElementById('current_timer').querySelector('.timer-value');
        const currentTimerTypeEl = document.getElementById('current_timer_type');

        if (currentTipe === 'postpaid') {
            currentTimerEl.textContent = formatTime(elapsedSeconds);
            currentTimerTypeEl.textContent = 'Lost Time (Count Up ⏱️)';
        } else if (currentTipe === 'prepaid') {
            let remainingSeconds = 0;
            if (expiresTime && !Number.isNaN(expiresTime.getTime())) {
                remainingSeconds = Math.max(0, Math.floor((expiresTime - now) / 1000));
            } else {
                const totalSeconds = currentDurationSeconds > 0 ? currentDurationSeconds : 3600;
                remainingSeconds = Math.max(0, totalSeconds - elapsedSeconds);
            }
            currentTimerEl.textContent = formatTime(remainingSeconds);
            currentTimerTypeEl.textContent = 'Timer (Countdown ⏳)';
        } else {
            let remainingSeconds = 0;
            if (expiresTime && !Number.isNaN(expiresTime.getTime())) {
                remainingSeconds = Math.max(0, Math.floor((expiresTime - now) / 1000));
            } else {
                remainingSeconds = Math.max(0, currentDurationSeconds - elapsedSeconds);
            }
            currentTimerEl.textContent = formatTime(remainingSeconds);
            currentTimerTypeEl.textContent = 'Custom Paket (Countdown ⏳)';
        }

        updateNewTimerPreview(elapsedSeconds, elapsedMinutes);
    }

    function updateNewTimerPreview(elapsedSeconds, elapsedMinutes) {
        const selectedTipe = tipeTransaksiSelect.value;
        const newTimerEl = document.getElementById('new_timer').querySelector('.timer-value');
        const newTimerTypeEl = document.getElementById('new_timer_type');
        const explanationEl = document.getElementById('explanation_text');
        const newTimerCard = document.getElementById('new_timer_card');

        if (selectedTipe === currentTipe && selectedTipe !== 'prepaid') {
            newTimerCard.classList.remove('bg-success', 'bg-warning', 'bg-info', 'bg-danger');
            newTimerCard.classList.add('bg-secondary');
            newTimerEl.textContent = 'Tidak ada perubahan';
            newTimerTypeEl.textContent = 'Tipe transaksi sama';
            explanationEl.textContent = 'Timer tetap berjalan tanpa perubahan durasi.';
            return;
        }

        newTimerCard.classList.remove('bg-secondary');

        if (selectedTipe === 'postpaid') {
            newTimerCard.classList.remove('bg-warning', 'bg-info', 'bg-danger');
            newTimerCard.classList.add('bg-success');
            newTimerEl.textContent = formatTime(elapsedSeconds);
            newTimerTypeEl.textContent = 'Lost Time (Count Up ⏱️)';

            const hours = Math.floor(elapsedMinutes / 60);
            const mins = elapsedMinutes % 60;
            explanationEl.innerHTML = `<strong>Lost Time → Count Up:</strong> Timer melanjutkan dari ${hours} jam ${mins} menit yang sudah terpakai. Harga dihitung saat selesai.`;
        } else if (selectedTipe === 'prepaid') {
            const totalSeconds = getSelectedDurationSeconds();
            const remainingSeconds = Math.max(0, totalSeconds - elapsedSeconds);

            newTimerCard.classList.remove('bg-success', 'bg-info', 'bg-warning', 'bg-danger');
            if (remainingSeconds === 0) {
                newTimerCard.classList.add('bg-danger');
            } else {
                newTimerCard.classList.add('bg-warning');
            }

            newTimerEl.textContent = formatTime(remainingSeconds);
            newTimerTypeEl.textContent = 'Timer (Countdown ⏳)';

            const hours = Math.floor(elapsedMinutes / 60);
            const mins = elapsedMinutes % 60;
            const remHours = Math.floor(remainingSeconds / 3600);
            const remMins = Math.floor((remainingSeconds % 3600) / 60);

            if (remainingSeconds === 0) {
                explanationEl.innerHTML = `<strong class="text-danger">⚠️ Overtime!</strong> Waktu sudah terpakai ${hours} jam ${mins} menit, melebihi durasi yang dipilih. Timer akan menunjukkan 00:00:00.`;
            } else {
                explanationEl.innerHTML = `<strong>Timer → Countdown:</strong> Sudah terpakai ${hours} jam ${mins} menit. Sisa waktu: ${remHours} jam ${remMins} menit.`;
            }
        } else if (selectedTipe === 'custom_package') {
            const selectedPackage = customPackageSelect.options[customPackageSelect.selectedIndex];
            if (!selectedPackage || !selectedPackage.value) {
                newTimerEl.textContent = 'Pilih paket';
                newTimerTypeEl.textContent = 'Custom Paket';
                explanationEl.textContent = 'Pilih custom paket untuk melihat preview timer.';
                return;
            }

            const lamaMain = parseInt(selectedPackage.getAttribute('data-lama-main'), 10) || 0;
            const totalSeconds = lamaMain * 60;
            const remainingSeconds = Math.max(0, totalSeconds - elapsedSeconds);

            newTimerCard.classList.remove('bg-success', 'bg-warning', 'bg-danger');
            newTimerCard.classList.add('bg-info');
            newTimerEl.textContent = formatTime(remainingSeconds);
            newTimerTypeEl.textContent = 'Custom Paket (Countdown ⏳)';

            const remMins = Math.floor(remainingSeconds / 60);
            explanationEl.innerHTML = `<strong>Custom Paket:</strong> Total ${lamaMain} menit, sudah terpakai ${elapsedMinutes} menit. Sisa: ${remMins} menit.`;
        }
    }

    function renderRepricingPreview(data) {
        if (!data || typeof data !== 'object') return;

        const errAlert = document.getElementById('preview_error_alert');
        if (errAlert) {
            if (!data.valid && data.error) {
                errAlert.textContent = String(data.error);
                errAlert.hidden = false;
            } else {
                errAlert.textContent = '';
                errAlert.hidden = true;
            }
        }

        if (data.current_category) {
            const oldCatEl = document.getElementById('preview_old_category');
            const oldRateEl = document.getElementById('preview_old_rate');
            if (oldCatEl) oldCatEl.textContent = String(data.current_category.nama || '-');
            if (oldRateEl) oldRateEl.textContent = formatRupiah(data.current_category.harga || 0) + '/jam';
        }

        if (data.new_category) {
            const newCatEl = document.getElementById('preview_new_category');
            const newRateEl = document.getElementById('preview_new_rate');
            if (newCatEl) newCatEl.textContent = String(data.new_category.nama || '-');
            if (newRateEl) newRateEl.textContent = formatRupiah(data.new_category.harga || 0) + '/jam';
        }

        const modeLabelEl = document.getElementById('preview_mode_label');
        if (modeLabelEl && data.pricing_mode_label) {
            modeLabelEl.textContent = String(data.pricing_mode_label);
        }

        const oldRentalEl = document.getElementById('preview_old_rental');
        if (oldRentalEl && data.current_rental_estimate !== undefined) {
            oldRentalEl.textContent = formatRupiah(data.current_rental_estimate);
        }

        const newRentalEl = document.getElementById('preview_new_rental');
        if (newRentalEl && data.new_rental_estimate !== undefined) {
            newRentalEl.textContent = formatRupiah(data.new_rental_estimate);
            if (estimatedPrice) {
                estimatedPrice.textContent = formatRupiah(data.new_rental_estimate);
            }
        }

        const diffEl = document.getElementById('preview_price_diff');
        if (diffEl && data.price_difference !== undefined) {
            const diff = Number(data.price_difference || 0);
            diffEl.classList.remove('text-danger', 'text-success');
            if (diff > 0) {
                diffEl.classList.add('text-danger');
                diffEl.textContent = '+' + formatRupiah(diff);
            } else if (diff < 0) {
                diffEl.classList.add('text-success');
                diffEl.textContent = '-' + formatRupiah(Math.abs(diff));
            } else {
                diffEl.textContent = 'Rp 0';
            }
        }

        const fnbUnpaidEl = document.getElementById('preview_fnb_unpaid');
        if (fnbUnpaidEl && data.fnb_unpaid_total !== undefined) {
            fnbUnpaidEl.textContent = formatRupiah(data.fnb_unpaid_total);
        }

        const payableEl = document.getElementById('preview_payable_total');
        if (payableEl && data.new_payable_total !== undefined) {
            payableEl.textContent = formatRupiah(data.new_payable_total);
        }

        const segContainer = document.getElementById('preview_segments_container');
        const segTbody = document.getElementById('preview_segments_tbody');
        if (segContainer && segTbody) {
            segTbody.replaceChildren();
            const segs = Array.isArray(data.segments_preview) ? data.segments_preview : [];
            if (segs.length >= 2) {
                segs.forEach(function(seg, idx) {
                    const tr = document.createElement('tr');

                    const tdOrder = document.createElement('td');
                    tdOrder.textContent = String(seg.segment_order || (idx + 1));
                    tr.appendChild(tdOrder);

                    const tdName = document.createElement('td');
                    tdName.textContent = String(seg.playstation_name || 'Game');
                    tr.appendChild(tdName);

                    const tdTime = document.createElement('td');
                    tdTime.textContent = `${seg.started_at_display || '-'} - ${seg.ended_at_display || '-'}`;
                    tr.appendChild(tdTime);

                    const tdDur = document.createElement('td');
                    tdDur.textContent = String(seg.duration_display || '-');
                    tr.appendChild(tdDur);

                    const tdRate = document.createElement('td');
                    tdRate.textContent = formatRupiah(seg.hourly_rate || 0) + '/jam';
                    tr.appendChild(tdRate);

                    const tdSub = document.createElement('td');
                    const subVal = seg.allocated_billed_amount !== undefined ? seg.allocated_billed_amount : (seg.raw_amount || 0);
                    tdSub.textContent = formatRupiah(subVal);
                    tr.appendChild(tdSub);

                    segTbody.appendChild(tr);
                });
                segContainer.hidden = false;
            } else {
                segContainer.hidden = true;
            }
        }
    }

    function fetchServerEditPreview() {
        if (!editPreviewUrl) return;
        if (previewAbortController) {
            previewAbortController.abort();
        }
        previewAbortController = new AbortController();

        syncDurationHiddenInput();

        const payload = {
            tipe_transaksi: tipeTransaksiSelect ? tipeTransaksiSelect.value : currentTipe,
            device_id: deviceSelect && deviceSelect.value ? parseInt(deviceSelect.value, 10) : null,
            playstation_id: playstationSelect && playstationSelect.value !== '' ? parseInt(playstationSelect.value, 10) : null,
            pricing_mode: getSelectedPricingMode(),
            jam_main: jamMainSelect ? jamMainSelect.value : null,
            duration_seconds: getSelectedDurationSeconds(),
            custom_package_id: customPackageSelect && customPackageSelect.value !== '' ? parseInt(customPackageSelect.value, 10) : null
        };

        fetch(editPreviewUrl, {
            method: 'POST',
            signal: previewAbortController.signal,
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify(payload)
        })
        .then(function(response) {
            return response.json().catch(function() { return null; });
        })
        .then(function(data) {
            if (data) {
                renderRepricingPreview(data);
            }
        })
        .catch(function(err) {
            if (err && err.name === 'AbortError') {
                return;
            }
        });
    }

    function updateFormDisplay() {
        const tipeTransaksi = tipeTransaksiSelect.value;

        jamMainContainer.style.display = 'none';
        customPackageContainer.style.display = 'none';
        lostTimeInfo.style.display = 'none';
        pricePreview.style.display = 'none';

        if (playstationCategoryContainer) {
            playstationCategoryContainer.style.display = (tipeTransaksi === 'custom_package') ? 'none' : 'block';
        }
        if (pricingModeFieldset) {
            pricingModeFieldset.style.display = (tipeTransaksi === 'custom_package') ? 'none' : 'block';
        }

        if (tipeTransaksi === 'prepaid') {
            jamMainContainer.style.display = 'block';
            jamMainSelect.setAttribute('required', 'required');
            customPackageSelect.removeAttribute('required');
            pricePreview.style.display = 'block';
            syncDurationHiddenInput();
        } else if (tipeTransaksi === 'postpaid') {
            lostTimeInfo.style.display = 'block';
            jamMainSelect.removeAttribute('required');
            customPackageSelect.removeAttribute('required');
        } else if (tipeTransaksi === 'custom_package') {
            customPackageContainer.style.display = 'block';
            customPackageSelect.setAttribute('required', 'required');
            jamMainSelect.removeAttribute('required');
        }

        updateTimers();
        fetchServerEditPreview();
    }

    function validateCustomPackageDevice() {
        const tipeTransaksi = tipeTransaksiSelect.value;
        if (tipeTransaksi !== 'custom_package') return true;

        const selectedPackage = customPackageSelect.options[customPackageSelect.selectedIndex];
        if (!selectedPackage || !selectedPackage.value) return true;

        const packagePlaystationIds = (selectedPackage.getAttribute('data-playstation-ids') || '')
            .split(',')
            .map(function(v) { return v.trim(); })
            .filter(Boolean);

        const devId = String(deviceSelect.value || '');
        const devCats = Array.isArray(deviceCategoriesMap[devId]) ? deviceCategoriesMap[devId] : [];
        const devCatIds = devCats.map(function(c) { return String(c.id); });
        const selectedDevice = deviceSelect.options[deviceSelect.selectedIndex];
        const fallbackPsId = selectedDevice ? String(selectedDevice.getAttribute('data-playstation-id') || '') : '';
        if (fallbackPsId && !devCatIds.includes(fallbackPsId)) {
            devCatIds.push(fallbackPsId);
        }

        const isCompatible = devCatIds.some(function(id) {
            return packagePlaystationIds.includes(id);
        });

        if (!isCompatible) {
            alert('Perangkat yang dipilih tidak sesuai dengan paket custom yang dipilih!');
            return false;
        }

        return true;
    }

    // Event listeners
    tipeTransaksiSelect.addEventListener('change', updateFormDisplay);
    jamMainSelect.addEventListener('change', function() {
        syncDurationHiddenInput();
        updateTimers();
        fetchServerEditPreview();
    });
    deviceSelect.addEventListener('change', function() {
        populateCategoryOptionsForDevice(false);
        rebuildDurationOptionsForCategory();
        validateCustomPackageDevice();
        updateTimers();
        fetchServerEditPreview();
    });
    if (playstationSelect) {
        playstationSelect.addEventListener('change', function() {
            playstationSelect.removeAttribute('aria-invalid');
            rebuildDurationOptionsForCategory();
            updateTimers();
            fetchServerEditPreview();
        });
    }
    pricingModeRadios.forEach(function(radio) {
        radio.addEventListener('change', function() {
            fetchServerEditPreview();
        });
    });
    customPackageSelect.addEventListener('change', function() {
        validateCustomPackageDevice();
        updateTimers();
        fetchServerEditPreview();
    });

    // Form submission validation
    document.getElementById('editForm').addEventListener('submit', function(e) {
        syncDurationHiddenInput();
        if (!validateCustomPackageDevice()) {
            e.preventDefault();
            return false;
        }
    });

    // Initial timer render
    updateTimers();

    // Update timers every second
    setInterval(updateTimers, 1000);

    // ====== FnB Management Functions ======
    
    const transactionId = '{{ $transaction->id_transaksi }}';
    const csrfToken = '{{ csrf_token() }}';

    // Helper function to format currency
    function formatCurrency(number) {
        return 'Rp ' + parseInt(number).toLocaleString('id-ID');
    }

    // Helper function to show notification
    function showNotification(message, type = 'success') {
        const alertClass = type === 'success' ? 'alert-success' : 'alert-danger';
        const alert = `
            <div class="alert ${alertClass} alert-dismissible fade show" role="alert">
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        `;
        
        // Insert at the top of card-body
        const cardBody = document.querySelector('.card-body');
        cardBody.insertAdjacentHTML('afterbegin', alert);
        
        // Auto-dismiss after 3 seconds
        setTimeout(() => {
            const alertEl = cardBody.querySelector('.alert');
            if (alertEl) alertEl.remove();
        }, 3000);
    }

    // Add New FnB
    document.getElementById('addFnbBtn').addEventListener('click', function() {
        const fnbSelect = document.getElementById('newFnbSelect');
        const fnbId = fnbSelect.value;
        const qty = parseInt(document.getElementById('newFnbQty').value);
        
        if (!fnbId) {
            alert('Silakan pilih FnB terlebih dahulu');
            return;
        }
        
        if (!qty || qty < 1) {
            alert('Quantity harus lebih dari 0');
            return;
        }
        
        // Check stock
        const selectedOption = fnbSelect.options[fnbSelect.selectedIndex];
        const stok = parseInt(selectedOption.getAttribute('data-stok'));
        
        if (stok !== -1 && qty > stok) {
            alert(`Stok tidak mencukupi. Stok tersedia: ${stok}`);
            return;
        }
        
        // Disable button during request
        this.disabled = true;
        this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menambahkan...';
        
        fetch(`/transaction/${transactionId}/fnb/add`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken
            },
            body: JSON.stringify({
                fnb_id: fnbId,
                qty: qty
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification(data.message, 'success');
                
                // Check if FnB already exists in table
                const existingRow = document.querySelector(`tr[data-fnb-id="${fnbId}"]`);
                
                if (existingRow) {
                    // Update existing row
                    const qtyInput = existingRow.querySelector('.qty-input');
                    const newQty = parseInt(qtyInput.value) + qty;
                    qtyInput.value = newQty;
                    qtyInput.setAttribute('data-old-qty', newQty);
                    updateSubtotal(existingRow);
                } else {
                    // Add new row
                    const newRow = createFnbRow(data.fnb);
                    const emptyRow = document.getElementById('emptyRow');
                    if (emptyRow) emptyRow.remove();
                    
                    document.getElementById('fnbTableBody').appendChild(newRow);
                }
                
                // Update total
                updateFnbTotal(data.total);
                
                // Reset form
                fnbSelect.value = '';
                document.getElementById('newFnbQty').value = '1';
            } else {
                showNotification(data.message, 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('Terjadi kesalahan saat menambahkan FnB', 'error');
        })
        .finally(() => {
            // Re-enable button
            this.disabled = false;
            this.innerHTML = '<i class="fas fa-plus"></i> Tambah FnB';
        });
    });

    // Create FnB Row HTML
    function createFnbRow(fnbData) {
        const row = document.createElement('tr');
        row.className = 'fnb-row';
        row.setAttribute('data-fnb-id', fnbData.fnb_id);
        
        const subtotal = fnbData.qty * fnbData.harga_jual;
        
        row.innerHTML = `
            <td>${fnbData.fnb.nama}</td>
            <td>
                <input type="number" 
                       class="form-control form-control-sm qty-input" 
                       value="${fnbData.qty}" 
                       min="1"
                       data-fnb-id="${fnbData.fnb_id}"
                       data-old-qty="${fnbData.qty}">
            </td>
            <td>
                <input type="number" 
                       class="form-control form-control-sm price-input" 
                       value="${fnbData.harga_jual}" 
                       min="0"
                       data-fnb-id="${fnbData.fnb_id}"
                       data-old-price="${fnbData.harga_jual}">
            </td>
            <td class="subtotal-cell">${formatCurrency(subtotal)}</td>
            <td class="text-center">
                <button type="button" 
                        class="btn btn-sm btn-danger delete-fnb" 
                        data-fnb-id="${fnbData.fnb_id}"
                        title="Hapus">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        `;
        
        return row;
    }

    // Update FnB (Quantity or Price Change)
    function updateFnb(fnbId, qty, hargaJual) {
        fetch(`/transaction/${transactionId}/fnb/${fnbId}/update`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken
            },
            body: JSON.stringify({
                qty: qty,
                harga_jual: hargaJual
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification(data.message, 'success');
                
                // Update the row's data attributes
                const row = document.querySelector(`tr[data-fnb-id="${fnbId}"]`);
                const qtyInput = row.querySelector('.qty-input');
                const priceInput = row.querySelector('.price-input');
                
                qtyInput.setAttribute('data-old-qty', qty);
                priceInput.setAttribute('data-old-price', hargaJual);
                
                updateSubtotal(row);
                updateFnbTotal(data.total);
            } else {
                showNotification(data.message, 'error');
                
                // Revert to old values
                const row = document.querySelector(`tr[data-fnb-id="${fnbId}"]`);
                const qtyInput = row.querySelector('.qty-input');
                const priceInput = row.querySelector('.price-input');
                
                qtyInput.value = qtyInput.getAttribute('data-old-qty');
                priceInput.value = priceInput.getAttribute('data-old-price');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('Terjadi kesalahan saat mengupdate FnB', 'error');
        });
    }

    // Update subtotal in a row
    function updateSubtotal(row) {
        const qty = parseInt(row.querySelector('.qty-input').value);
        const price = parseInt(row.querySelector('.price-input').value);
        const subtotal = qty * price;
        
        row.querySelector('.subtotal-cell').textContent = formatCurrency(subtotal);
    }

    // Update FnB total display
    function updateFnbTotal(total) {
        document.getElementById('fnbTotalDisplay').textContent = formatCurrency(total);
    }

    // Delete FnB
    document.addEventListener('click', function(e) {
        if (e.target.closest('.delete-fnb')) {
            const button = e.target.closest('.delete-fnb');
            const fnbId = button.getAttribute('data-fnb-id');
            
            if (!confirm('Apakah Anda yakin ingin menghapus FnB ini?')) {
                return;
            }
            
            // Disable button during request
            button.disabled = true;
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            
            fetch(`/transaction/${transactionId}/fnb/${fnbId}/delete`, {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message, 'success');
                    
                    // Remove the row
                    const row = document.querySelector(`tr[data-fnb-id="${fnbId}"]`);
                    row.remove();
                    
                    // Check if table is empty
                    const remainingRows = document.querySelectorAll('.fnb-row');
                    if (remainingRows.length === 0) {
                        const tbody = document.getElementById('fnbTableBody');
                        tbody.innerHTML = '<tr id="emptyRow"><td colspan="5" class="text-center text-muted">Belum ada FnB dalam transaksi ini</td></tr>';
                    }
                    
                    // Update total
                    updateFnbTotal(data.total);
                } else {
                    showNotification(data.message, 'error');
                    button.disabled = false;
                    button.innerHTML = '<i class="fas fa-trash"></i>';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Terjadi kesalahan saat menghapus FnB', 'error');
                button.disabled = false;
                button.innerHTML = '<i class="fas fa-trash"></i>';
            });
        }
    });

    // Handle quantity change
    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('qty-input')) {
            const input = e.target;
            const fnbId = input.getAttribute('data-fnb-id');
            const newQty = parseInt(input.value);
            const oldQty = parseInt(input.getAttribute('data-old-qty'));
            
            if (newQty === oldQty) return;
            
            if (newQty < 1) {
                alert('Quantity harus minimal 1');
                input.value = oldQty;
                return;
            }
            
            const row = input.closest('tr');
            const priceInput = row.querySelector('.price-input');
            const hargaJual = parseInt(priceInput.value);
            
            updateFnb(fnbId, newQty, hargaJual);
        }
    });

    // Handle price change
    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('price-input')) {
            const input = e.target;
            const fnbId = input.getAttribute('data-fnb-id');
            const newPrice = parseInt(input.value);
            const oldPrice = parseInt(input.getAttribute('data-old-price'));
            
            if (newPrice === oldPrice) return;
            
            if (newPrice < 0) {
                alert('Harga tidak boleh negatif');
                input.value = oldPrice;
                return;
            }
            
            const row = input.closest('tr');
            const qtyInput = row.querySelector('.qty-input');
            const qty = parseInt(qtyInput.value);
            
            updateFnb(fnbId, qty, newPrice);
        }
    });
});
</script>

<style>
.form-label {
    font-weight: 600;
}
.text-danger {
    color: #dc3545;
}
.timer-display-large {
    text-align: center;
    padding: 15px 0;
}
.timer-display-large .timer-value {
    font-size: 2.5rem;
    font-weight: bold;
    font-family: 'Courier New', monospace;
    letter-spacing: 3px;
}
#timer_preview_section .card {
    transition: all 0.3s ease;
    min-height: 150px;
}
#timer_preview_section .card-body {
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
}
#new_timer_card.bg-danger .timer-value {
    animation: pulse 1s infinite;
}
@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.6; }
}
</style>
@endsection
