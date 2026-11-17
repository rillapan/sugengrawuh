@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <!-- Page Heading -->
    <div class="d-sm-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 class="h3 mb-0 text-gray-800"><i class="fas fa-stethoscope text-info mr-2"></i>Hardware Diagnostics</h1>
            <p class="text-muted small mb-0">Uji konektivitas, pairing aman, dan diagnostik kontrol Samsung Smart TV & Shelly 1PM Mini Gen3 (Gate G2).</p>
        </div>
        <div class="d-flex align-items-center">
            @if($realExecutionEnabled)
                <span class="badge badge-success px-3 py-2 mr-2"><i class="fas fa-shield-alt mr-1"></i>REAL NETWORK ENABLED</span>
            @else
                <span class="badge badge-warning px-3 py-2 mr-2 text-dark" id="badgeSafetyGuard"><i class="fas fa-shield-alt mr-1"></i>SAFETY GUARD: PHYSICAL BLOCKED</span>
            @endif
            <span class="badge badge-dark px-3 py-2 font-monospace">DRIVER: {{ strtoupper($activeDriver) }}</span>
        </div>
    </div>

    <!-- Safety Guard & Canonical Status Notice Banner -->
    <div class="card border-left-info shadow-sm mb-4">
        <div class="card-body py-3">
            <div class="row align-items-center">
                <div class="col-lg-7 col-md-12">
                    <h6 class="font-weight-bold text-gray-800 mb-1"><i class="fas fa-shield-alt mr-2 text-info"></i>GATE G4-B1 SAFETY PROTOCOL & GATE G2 HARDWARE DIAGNOSTICS</h6>
                    <p class="small text-muted mb-0">
                        Diagnostik memisahkan secara ketat operasi <strong>Read-Only</strong>, <strong>Pairing</strong>, dan <strong>Physical Control Test</strong>.
                        Operasi read-only tidak memutasi database maupun perangkat fisik. Pairing menyimpan token terenkripsi namun <strong>tidak secara otomatis mengaktifkan</strong> kontrol fisik otomatis (background physical auto-execution is <span class="badge badge-warning text-dark font-weight-bold">disabled</span> by default).
                        Kontrol fisik wajib melalui persetujuan server bertenggat waktu (Server Approval Token).
                    </p>
                </div>
                <div class="col-lg-5 col-md-12 mt-2 mt-lg-0">
                    <div class="small font-weight-bold text-gray-700 mb-1">Status Kanonikal Diagnostik:</div>
                    <div class="d-flex flex-wrap" style="gap: 4px;">
                        <span class="badge badge-success">Connected</span>
                        <span class="badge badge-danger">Disconnected</span>
                        <span class="badge badge-warning text-dark">Authentication Required</span>
                        <span class="badge badge-warning text-dark">Pairing Required</span>
                        <span class="badge badge-secondary">Driver Unsupported</span>
                        <span class="badge badge-danger">Timeout</span>
                        <span class="badge badge-dark">Configuration Error</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Alert Container -->
    <div id="diagAlertContainer"></div>

    @if($devices->isEmpty())
        <div class="card shadow mb-4">
            <div class="card-body text-center py-5">
                <i class="fas fa-microchip fa-3x text-gray-300 mb-3 d-block"></i>
                <h5 class="text-gray-700 font-weight-bold">Belum Ada Perangkat di Registry</h5>
                <p class="text-muted small mb-3">Silakan daftarkan perangkat Samsung TV atau Shelly Relay terlebih dahulu di Device Registry.</p>
                <a href="{{ route('admin.hardware.registry') }}" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus fa-sm mr-1"></i> Buka Device Registry
                </a>
            </div>
        </div>
    @else
        <div class="row">
            <!-- Left Column: Device Selector & Testing Parameters -->
            <div class="col-lg-5 col-md-12 mb-4">
                <!-- Device Picker Card -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3 bg-primary text-white">
                        <h6 class="m-0 font-weight-bold"><i class="fas fa-hdd mr-1"></i> Target Perangkat</h6>
                    </div>
                    <div class="card-body">
                        <div class="form-group mb-3">
                            <label for="diagDeviceSelector" class="font-weight-bold small text-gray-700">Pilih Perangkat</label>
                            <select id="diagDeviceSelector" class="form-control">
                                @foreach($devices as $dev)
                                    <option value="{{ $dev->id }}" 
                                        data-name="{{ $dev->name }}"
                                        data-category="{{ $dev->category }}"
                                        data-brand="{{ $dev->brand }}"
                                        data-model="{{ $dev->model }}"
                                        data-mac="{{ $dev->mac_address }}"
                                        data-ip="{{ $dev->ip_address }}"
                                        data-active="{{ $dev->is_active ? '1' : '0' }}"
                                        data-conn="{{ $dev->connection_status }}"
                                        data-pair="{{ $dev->pairing_status }}"
                                        data-station="{{ $dev->activeMapping && $dev->activeMapping->station ? $dev->activeMapping->station->nama : '' }}"
                                        data-role="{{ $dev->activeMapping ? $dev->activeMapping->mapping_role : '' }}">
                                        {{ $dev->name }} ({{ $dev->brand }} {{ $dev->category }})
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <!-- Device Info Box -->
                        <div class="p-3 bg-light rounded border mb-0 small">
                            <div class="d-flex justify-content-between mb-1">
                                <span class="text-muted">Kategori / Brand:</span>
                                <strong><span id="diagCategoryBrand">—</span></strong>
                            </div>
                            <div class="d-flex justify-content-between mb-1">
                                <span class="text-muted">Model & IP:</span>
                                <span><span id="diagModel">—</span> | <code id="diagIp">—</code></span>
                            </div>
                            <div class="d-flex justify-content-between mb-1">
                                <span class="text-muted">MAC Address:</span>
                                <code id="diagMac">—</code>
                            </div>
                            <div class="d-flex justify-content-between mb-1">
                                <span class="text-muted">Status DB:</span>
                                <div>
                                    <span id="diagConnStatus"><span class="badge badge-secondary">UNVERIFIED</span></span>
                                    <span id="diagPairStatus"><span class="badge badge-secondary">UNPAIRED</span></span>
                                </div>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-muted">Station Mapping:</span>
                                <span id="diagStationMapping"><span class="badge badge-secondary">Belum Dipetakan</span></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Transport & Protocol Mode Card -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3 bg-dark text-white d-flex align-items-center justify-content-between">
                        <h6 class="m-0 font-weight-bold"><i class="fas fa-cogs mr-1"></i> Mode Transport</h6>
                        <span class="badge badge-info">GATE G2</span>
                    </div>
                    <div class="card-body">
                        <div class="form-group mb-3">
                            <label class="font-weight-bold small text-gray-700">Pilihan Transport Layer</label>
                            @php
                                $isRealDefault = config('hardware.driver') === 'real';
                            @endphp
                            <div class="btn-group btn-group-toggle d-flex" data-toggle="buttons">
                                <label class="btn btn-outline-primary {{ $isRealDefault ? '' : 'active' }} w-50" id="btnModeFake">
                                    <input type="radio" name="transportMode" id="optFake" value="fake" {{ $isRealDefault ? '' : 'checked' }}>
                                    <i class="fas fa-vial mr-1"></i> Fake Transport
                                </label>
                                <label class="btn btn-outline-success {{ $isRealDefault ? 'active' : '' }} w-50" id="btnModeReal" title="Uji jaringan nyata ke IP LAN">
                                    <input type="radio" name="transportMode" id="optReal" value="real" {{ $isRealDefault ? 'checked' : '' }}>
                                    <i class="fas fa-network-wired mr-1"></i> Real Network
                                </label>
                            </div>
                            <small class="form-text text-muted mt-2">
                                <i class="fas fa-info-circle mr-1"></i> Mode Real Network mengizinkan Read-Only dan Pairing langsung oleh ADMIN. Kontrol fisik memerlukan token persetujuan server.
                            </small>
                        </div>

                        <!-- Scenario Selector for Fake Transport -->
                        <div class="form-group mb-3" id="groupFakeScenario" style="{{ $isRealDefault ? 'display: none;' : '' }}">
                            <label for="fakeScenarioSelect" class="font-weight-bold small text-gray-700">Skenario Simulasi (Fake Transport)</label>
                            <select id="fakeScenarioSelect" class="form-control form-control-sm">
                                <option value="SUCCESS">SUCCESS — Perangkat Normal & Responsif</option>
                                <option value="OFFLINE">OFFLINE — Perangkat Tidak Dapat Dihubungi</option>
                                <option value="TIMEOUT">TIMEOUT — Waktu Tunggu Habis (> 3000ms)</option>
                                <option value="AUTH_REJECTED">AUTH_REJECTED — Otorisasi Ditolak / Belum Paired</option>
                                <option value="INVALID_JSON">INVALID_JSON — Format Respons Malformed</option>
                            </select>
                        </div>

                        <!-- Password Input for Shelly -->
                        <div class="form-group mb-0" id="groupShellyPassword" style="display: none;">
                            <label for="inputShellyPassword" class="font-weight-bold small text-gray-700">Sandi Autentikasi Shelly (Opsional)</label>
                            <input type="password" id="inputShellyPassword" class="form-control form-control-sm" placeholder="Masukkan sandi jika perangkat diproteksi">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Protocol Controls & Live Response Console -->
            <div class="col-lg-7 col-md-12 mb-4">
                <!-- Diagnostic Controls Card -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3 bg-secondary text-white d-flex align-items-center justify-content-between">
                        <h6 class="m-0 font-weight-bold"><i class="fas fa-terminal mr-1"></i> Operasi Diagnostik</h6>
                        <span id="driverBadge" class="badge badge-light font-monospace text-dark">—</span>
                    </div>
                    <div class="card-body">
                        <!-- SECTION 1: READ-ONLY DIAGNOSTICS -->
                        <div class="border rounded p-3 mb-3 bg-light">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <h6 class="font-weight-bold text-primary mb-0">
                                    <i class="fas fa-eye mr-1"></i> 1. Read-Only Diagnostics
                                </h6>
                                <span class="badge badge-info">Zero Physical Mutation</span>
                            </div>
                            <p class="small text-muted mb-2">
                                Membaca respons socket atau HTTP tanpa memicu mutasi daya, volume, relay, atau status database utama.
                            </p>
                            <div class="d-flex flex-wrap">
                                <button type="button" class="btn btn-outline-info btn-sm mr-2 mb-2" id="btnDiagProbe">
                                    <i class="fas fa-network-wired mr-1"></i> Test Connection (Probe)
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-sm mb-2" id="btnDiagStatus">
                                    <i class="fas fa-info-circle mr-1"></i> Read Device Status
                                </button>
                            </div>
                        </div>

                        <!-- SECTION 2: DEVICE PAIRING -->
                        <div class="border rounded p-3 mb-3 bg-light">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <h6 class="font-weight-bold text-primary mb-0">
                                    <i class="fas fa-key mr-1"></i> 2. Device Pairing
                                </h6>
                                <span class="badge badge-warning text-dark">Admin Authorized</span>
                            </div>
                            <p class="small text-muted mb-2">
                                Meminta handshake/token otentikasi. Token disimpan terenkripsi dan dicatat pada audit log.
                                <span class="text-danger font-weight-bold d-block mt-1">
                                    <i class="fas fa-exclamation-circle mr-1"></i> Pairing berhasil tidak secara otomatis mengaktifkan kontrol fisik atau otomasi otomatisasi background.
                                </span>
                            </p>
                            <div class="d-flex flex-wrap">
                                <button type="button" class="btn btn-outline-primary btn-sm mb-2" id="btnDiagPair">
                                    <i class="fas fa-link mr-1"></i> Pair / Handshake
                                </button>
                            </div>
                        </div>

                        <!-- SECTION 3: PHYSICAL CONTROL TEST -->
                        <div class="border rounded p-3 mb-3 bg-white shadow-sm">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <h6 class="font-weight-bold text-danger mb-0">
                                    <i class="fas fa-bolt mr-1"></i> 3. Physical Control Test
                                </h6>
                                <span class="badge badge-danger">Explicit Server Approval</span>
                            </div>
                            <p class="small text-muted mb-3">
                                Pengujian perintah fisik nyata (Power, Standby, Relay). Memerlukan <strong>Server Approval Token</strong> bertenggat waktu (5 menit) dan terikat perangkat serta user ADMIN.
                            </p>

                            <!-- Server Approval Step 1 -->
                            <div class="p-2 border rounded bg-light mb-3">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div>
                                        <small class="font-weight-bold text-dark d-block">Langkah A: Dapatkan Token Persetujuan Server</small>
                                        <small class="text-muted">Kirim permintaan ke <code>/admin/hardware/diagnostics/approve-physical</code></small>
                                    </div>
                                    <button type="button" class="btn btn-outline-danger btn-sm" id="btnRequestApproval">
                                        <i class="fas fa-certificate mr-1"></i> Request Server Approval Token
                                    </button>
                                </div>
                                <div id="approvalTokenBox" class="mt-2 small" style="display: none;">
                                    <span class="badge badge-success">TOKEN AKTIF:</span> <code id="activeTokenDisplay" class="font-weight-bold"></code>
                                    <span class="text-muted ml-2">(Kedaluwarsa: <span id="tokenExpiryDisplay"></span>)</span>
                                </div>
                            </div>

                            <!-- Physical Commands for Samsung TV -->
                            <div id="samsungControls" class="p-2 bg-light rounded border mb-2" style="display: none;">
                                <div class="small font-weight-bold text-primary mb-2">Perintah Fisik Samsung Smart TV:</div>
                                <div class="d-flex flex-wrap">
                                    <button type="button" class="btn btn-success btn-sm mr-2 mb-2 btn-command" data-cmd="MUTE">
                                        <i class="fas fa-volume-mute mr-1"></i> Mute Audio
                                    </button>
                                    <button type="button" class="btn btn-info btn-sm mr-2 mb-2 btn-command" data-cmd="UNMUTE">
                                        <i class="fas fa-volume-up mr-1"></i> Unmute Audio
                                    </button>
                                    <button type="button" class="btn btn-warning btn-sm mr-2 mb-2 btn-command text-dark" data-cmd="STANDBY">
                                        <i class="fas fa-moon mr-1"></i> Standby (Off)
                                    </button>
                                    <button type="button" class="btn btn-outline-danger btn-sm mb-2 btn-command" data-cmd="POWER_ON" title="Memerlukan WOL pada TV tidur">
                                        <i class="fas fa-power-off mr-1"></i> Power On (WOL)
                                    </button>
                                </div>
                            </div>

                            <!-- Physical Commands for Shelly Relay -->
                            <div id="shellyControls" class="p-2 bg-light rounded border mb-2" style="display: none;">
                                <div class="small font-weight-bold text-success mb-2">Perintah Fisik Shelly 1PM Mini Gen3:</div>
                                <div class="d-flex flex-wrap">
                                    <button type="button" class="btn btn-success btn-sm mr-2 mb-2 btn-command" data-cmd="SWITCH_ON">
                                        <i class="fas fa-lightbulb mr-1"></i> Relay ON (Lampu Meja Biliar)
                                    </button>
                                    <button type="button" class="btn btn-danger btn-sm mr-2 mb-2 btn-command" data-cmd="SWITCH_OFF">
                                        <i class="far fa-lightbulb mr-1"></i> Relay OFF (Lampu Meja Biliar)
                                    </button>
                                </div>
                                <small class="text-danger d-block mt-1 font-weight-bold">
                                    <i class="fas fa-exclamation-triangle mr-1"></i> Shelly hanya untuk lampu biliar. DILARANG memutus daya TV utama!
                                </small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Structured Diagnostic Result Panel -->
                <div class="card shadow mb-4" id="diagnosticResultCard">
                    <div class="card-header py-3 bg-dark text-white d-flex align-items-center justify-content-between">
                        <h6 class="m-0 font-weight-bold font-monospace"><i class="fas fa-clipboard-check mr-1"></i> Hasil & Analisis Diagnostik</h6>
                        <span id="responseStatusBadge" class="badge badge-secondary font-monospace">READY</span>
                    </div>
                    <div class="card-body">
                        <!-- Standardized Status & Remediation Box -->
                        <div id="diagnosticExplanationBox" class="mb-3" style="display: none;">
                            <div class="alert alert-light border shadow-sm">
                                <div class="d-flex align-items-center mb-2">
                                    <strong class="text-dark mr-2">Status Diagnostik:</strong>
                                    <span id="diagStatusCanonicalBadge" class="badge badge-secondary font-weight-bold px-2 py-1">—</span>
                                    <span id="diagSubStatusBadge" class="badge badge-light border ml-2 small font-monospace">—</span>
                                </div>
                                <div class="mb-2">
                                    <strong class="text-danger d-block small font-weight-bold">Penyebab:</strong>
                                    <span id="diagExplanationText" class="text-dark small">—</span>
                                </div>
                                <div>
                                    <strong class="text-success d-block small font-weight-bold">Langkah Perbaikan:</strong>
                                    <span id="diagRemediationText" class="text-dark small">—</span>
                                </div>
                            </div>
                        </div>

                        <!-- Raw JSON Console -->
                        <pre id="consoleOutput" class="bg-dark text-light p-3 rounded mb-0 small font-monospace" style="max-height: 240px; overflow-y: auto;">
// Siap mengeksekusi operasi diagnostik...
// Pilih perangkat dan klik aksi di atas untuk melihat respons protokol.</pre>
                    </div>
                    <div class="card-footer py-2 bg-light small text-muted d-flex justify-content-between align-items-center">
                        <span id="consoleFooterNotice"><i class="fas fa-shield-alt text-info mr-1"></i>Output protokol diverifikasi via Gate G2 Security Guard.</span>
                        <button type="button" class="btn btn-link btn-sm p-0 text-muted" id="btnClearConsole">Bersihkan Layar</button>
                    </div>
                </div>

                <!-- Recent Diagnostic Events Table Card -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3 bg-white d-flex align-items-center justify-content-between">
                        <h6 class="m-0 font-weight-bold text-gray-800"><i class="fas fa-history mr-1"></i> Riwayat Diagnostik Perangkat</h6>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnRefreshEvents">
                            <i class="fas fa-sync-alt fa-sm"></i>
                        </button>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-striped mb-0 small">
                                <thead class="thead-light">
                                    <tr>
                                        <th>Waktu</th>
                                        <th>Aksi</th>
                                        <th>Mode</th>
                                        <th>Hasil</th>
                                        <th>Pesan</th>
                                    </tr>
                                </thead>
                                <tbody id="diagEventsBody">
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-3">Memuat riwayat event...</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
@endsection

@section('scripts')
<script>
$(document).ready(function() {
    const csrfToken = $('meta[name="csrf-token"]').attr('content');
    let currentApprovalToken = null;
    let currentApprovalCommand = null;

    function updateSelectedDevice() {
        const $opt = $('#diagDeviceSelector option:selected');
        if (!$opt.length) return;

        const id = $opt.val();
        const category = $opt.data('category');
        const brand = $opt.data('brand');
        const model = $opt.data('model');
        const mac = $opt.data('mac');
        const ip = $opt.data('ip');
        const conn = $opt.data('conn');
        const pair = $opt.data('pair');
        const station = $opt.data('station');
        const role = $opt.data('role');

        $('#diagCategoryBrand').text(`${category} (${brand})`);
        $('#diagModel').text(model || 'Standard');
        $('#diagIp').text(ip);
        $('#diagMac').text(mac);

        // Connection Badge
        let badgeClass = 'badge-secondary';
        if (conn === 'ONLINE') badgeClass = 'badge-success';
        if (conn === 'OFFLINE') badgeClass = 'badge-danger';
        $('#diagConnStatus').html(`<span class="badge ${badgeClass}">${conn || 'UNVERIFIED'}</span>`);

        let pairBadgeClass = 'badge-secondary';
        if (pair === 'PAIRED') pairBadgeClass = 'badge-success';
        if (pair === 'FAILED' || pair === 'REJECTED') pairBadgeClass = 'badge-danger';
        $('#diagPairStatus').html(`<span class="badge ${pairBadgeClass}">${pair || 'UNPAIRED'}</span>`);

        // Station Mapping
        if (station) {
            $('#diagStationMapping').html(`<span class="badge badge-info">${station}</span> <small class="text-muted">(${role})</small>`);
        } else {
            $('#diagStationMapping').html('<span class="badge badge-secondary">Belum Dipetakan</span>');
        }

        // Toggle Driver Panels
        if (category === 'SMART_TV' && brand === 'SAMSUNG') {
            $('#samsungControls').show();
            $('#shellyControls').hide();
            $('#groupShellyPassword').hide();
            $('#driverBadge').text('SAMSUNG_TV_DRIVER (WebSocket: 8001)');
        } else if (category === 'SMART_RELAY' && brand === 'SHELLY') {
            $('#samsungControls').hide();
            $('#shellyControls').show();
            $('#groupShellyPassword').show();
            $('#driverBadge').text('SHELLY_RELAY_DRIVER (HTTP-RPC: 80)');
        } else {
            $('#samsungControls').hide();
            $('#shellyControls').hide();
            $('#groupShellyPassword').hide();
            $('#driverBadge').text(`GENERIC / ${brand}`);
        }

        // Reset approval token upon device change
        currentApprovalToken = null;
        $('#approvalTokenBox').hide();

        loadEvents(id);
    }

    function showOutput(data, statusText, isSuccess) {
        $('#consoleOutput').text(JSON.stringify(data, null, 2));
        const $badge = $('#responseStatusBadge');
        $badge.text(statusText || (isSuccess ? 'SUCCESS' : 'FAILED'));
        $badge.removeClass('badge-secondary badge-success badge-danger badge-warning');
        $badge.addClass(isSuccess ? 'badge-success' : 'badge-danger');

        // Render explanation and remediation if present
        const report = data.diagnostic_report || (data.data && data.data.diagnostic_report);
        if (report) {
            $('#diagnosticExplanationBox').show();
            const canonical = report.status || 'Connected';
            const sub = report.sub_status || '—';
            const exp = report.explanation || '—';
            const rem = report.remediation || '—';

            let canonClass = 'badge-secondary';
            if (canonical === 'Connected') canonClass = 'badge-success';
            else if (canonical === 'Disconnected' || canonical === 'Timeout') canonClass = 'badge-danger';
            else if (canonical === 'Authentication Required' || canonical === 'Pairing Required') canonClass = 'badge-warning text-dark';
            else if (canonical === 'Driver Unsupported') canonClass = 'badge-secondary';
            else if (canonical === 'Configuration Error') canonClass = 'badge-dark';

            $('#diagStatusCanonicalBadge').text(canonical).attr('class', `badge ${canonClass} font-weight-bold px-2 py-1`);
            $('#diagSubStatusBadge').text(sub);
            $('#diagExplanationText').text(exp);
            $('#diagRemediationText').text(rem);
        }
    }

    function showAlert(type, message) {
        const icon = type === 'success' ? 'check-circle' : 'exclamation-circle';
        const html = `
            <div class="alert alert-${type} alert-dismissible fade show shadow-sm mb-4" role="alert">
                <i class="fas fa-${icon} mr-2"></i> ${message}
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        `;
        $('#diagAlertContainer').html(html);
        $('html, body').animate({ scrollTop: $('#diagAlertContainer').offset().top - 20 }, 300);
    }

    function loadEvents(deviceId) {
        if (!deviceId) return;
        $.ajax({
            url: `/admin/hardware/diagnostics/events/${deviceId}`,
            method: 'GET',
            success: function(res) {
                if (!res.success || !res.data || res.data.length === 0) {
                    $('#diagEventsBody').html('<tr><td colspan="5" class="text-center text-muted py-3">Belum ada riwayat diagnostik untuk perangkat ini.</td></tr>');
                    return;
                }

                let rows = '';
                res.data.forEach(function(ev) {
                    const badge = ev.success ? '<span class="badge badge-success">OK</span>' : '<span class="badge badge-danger">FAIL</span>';
                    const time = ev.timestamp ? new Date(ev.timestamp).toLocaleTimeString() : '—';
                    rows += `
                        <tr>
                            <td><code>${time}</code></td>
                            <td><strong>${ev.action}</strong></td>
                            <td><span class="badge badge-light border">${ev.mode}</span></td>
                            <td>${badge} <small><code>${ev.result_code}</code></small></td>
                            <td class="text-truncate" style="max-width: 200px;" title="${ev.message}">${ev.message}</td>
                        </tr>
                    `;
                });
                $('#diagEventsBody').html(rows);
            },
            error: function() {
                $('#diagEventsBody').html('<tr><td colspan="5" class="text-center text-danger py-3">Gagal memuat riwayat diagnostik.</td></tr>');
            }
        });
    }

    // Event Listeners
    $('#diagDeviceSelector').on('change', updateSelectedDevice);

    $('input[name="transportMode"]').on('change', function() {
        if ($(this).val() === 'real') {
            $('#groupFakeScenario').slideUp();
        } else {
            $('#groupFakeScenario').slideDown();
        }
    });

    $('#btnClearConsole').on('click', function() {
        $('#consoleOutput').text('// Layar dibersihkan.\n// Siap menerima output diagnostik baru...');
        $('#responseStatusBadge').text('CLEARED').removeClass('badge-success badge-danger').addClass('badge-secondary');
        $('#diagnosticExplanationBox').hide();
    });

    $('#btnRefreshEvents').on('click', function() {
        const id = $('#diagDeviceSelector').val();
        loadEvents(id);
    });

    // 1. Read-Only Diagnostics: Probe
    $('#btnDiagProbe').on('click', function() {
        const id = $('#diagDeviceSelector').val();
        const mode = $('input[name="transportMode"]:checked').val() || 'fake';
        const scenario = $('#fakeScenarioSelect').val() || 'SUCCESS';

        $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Testing...');

        $.ajax({
            url: '{{ route("admin.hardware.diagnostics.probe") }}',
            method: 'POST',
            data: {
                _token: csrfToken,
                device_id: id,
                transport_mode: mode,
                scenario: scenario
            },
            success: function(res) {
                showOutput(res, res.data.result_code || 'SUCCESS', true);
                showAlert('success', res.message);
                loadEvents(id);
            },
            error: function(xhr) {
                const res = xhr.responseJSON || { message: 'Koneksi probe gagal' };
                showOutput(res, res.safety_guard || 'FAILED', false);
                showAlert('danger', res.message);
                loadEvents(id);
            },
            complete: function() {
                $('#btnDiagProbe').prop('disabled', false).html('<i class="fas fa-network-wired mr-1"></i> Test Connection (Probe)');
            }
        });
    });

    // 1. Read-Only Diagnostics: Read Status
    $('#btnDiagStatus').on('click', function() {
        const id = $('#diagDeviceSelector').val();
        const mode = $('input[name="transportMode"]:checked').val() || 'fake';
        const scenario = $('#fakeScenarioSelect').val() || 'SUCCESS';

        $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Reading...');

        $.ajax({
            url: `/admin/hardware/diagnostics/status/${id}?transport_mode=${mode}&scenario=${scenario}`,
            method: 'GET',
            success: function(res) {
                showOutput(res, 'SUCCESS', true);
                showAlert('success', res.message);
                loadEvents(id);
            },
            error: function(xhr) {
                const res = xhr.responseJSON || { message: 'Gagal mengambil status' };
                showOutput(res, 'FAILED', false);
                showAlert('danger', res.message);
            },
            complete: function() {
                $('#btnDiagStatus').prop('disabled', false).html('<i class="fas fa-info-circle mr-1"></i> Read Device Status');
            }
        });
    });

    // 2. Device Pairing
    $('#btnDiagPair').on('click', function() {
        const id = $('#diagDeviceSelector').val();
        const mode = $('input[name="transportMode"]:checked').val() || 'fake';
        const scenario = $('#fakeScenarioSelect').val() || 'SUCCESS';
        const password = $('#inputShellyPassword').val();

        $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Pairing...');

        $.ajax({
            url: '{{ route("admin.hardware.diagnostics.pair") }}',
            method: 'POST',
            data: {
                _token: csrfToken,
                device_id: id,
                transport_mode: mode,
                scenario: scenario,
                password: password
            },
            success: function(res) {
                showOutput(res, 'PAIRED', true);
                showAlert('success', res.message);
                loadEvents(id);
            },
            error: function(xhr) {
                const res = xhr.responseJSON || { message: 'Pairing gagal' };
                showOutput(res, 'FAILED', false);
                showAlert('danger', res.message);
                loadEvents(id);
            },
            complete: function() {
                $('#btnDiagPair').prop('disabled', false).html('<i class="fas fa-link mr-1"></i> Pair / Handshake');
            }
        });
    });

    // 3. Physical Control: Request Approval Token
    $('#btnRequestApproval').on('click', function() {
        const id = $('#diagDeviceSelector').val();
        const $opt = $('#diagDeviceSelector option:selected');
        const category = $opt.data('category');
        const brand = $opt.data('brand');

        let defaultCmd = 'MUTE';
        if (category === 'SMART_RELAY' || brand === 'SHELLY') {
            defaultCmd = 'SWITCH_ON';
        }

        $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Requesting...');

        $.ajax({
            url: '{{ route("admin.hardware.diagnostics.approve-physical") }}',
            method: 'POST',
            data: {
                _token: csrfToken,
                device_id: id,
                command: defaultCmd,
                ttl_seconds: 300
            },
            success: function(res) {
                currentApprovalToken = res.approval_token;
                currentApprovalCommand = defaultCmd;
                $('#activeTokenDisplay').text(currentApprovalToken.substring(0, 12) + '...');
                $('#tokenExpiryDisplay').text(new Date(res.expires_at).toLocaleTimeString());
                $('#approvalTokenBox').slideDown();
                showAlert('success', res.message);
            },
            error: function(xhr) {
                const res = xhr.responseJSON || { message: 'Gagal mendapatkan token persetujuan' };
                showAlert('danger', res.message);
            },
            complete: function() {
                $('#btnRequestApproval').prop('disabled', false).html('<i class="fas fa-certificate mr-1"></i> Request Server Approval Token');
            }
        });
    });

    // 3. Physical Control: Send Command
    $(document).on('click', '.btn-command', function() {
        const cmd = $(this).data('cmd');
        const id = $('#diagDeviceSelector').val();
        const mode = $('input[name="transportMode"]:checked').val() || 'fake';
        const scenario = $('#fakeScenarioSelect').val() || 'SUCCESS';

        const $btn = $(this);
        const origHtml = $btn.html();
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');

        $.ajax({
            url: '{{ route("admin.hardware.diagnostics.command") }}',
            method: 'POST',
            data: {
                _token: csrfToken,
                device_id: id,
                command: cmd,
                transport_mode: mode,
                scenario: scenario,
                approval_token: currentApprovalToken
            },
            success: function(res) {
                showOutput(res, res.data.result_code || 'ACK_TRANSPORT', res.success);
                showAlert(res.success ? 'success' : 'warning', res.message);
                loadEvents(id);
                // Token is single-use, clear if consumed
                if (mode === 'real') {
                    currentApprovalToken = null;
                    $('#approvalTokenBox').slideUp();
                }
            },
            error: function(xhr) {
                const res = xhr.responseJSON || { message: `Perintah ${cmd} gagal` };
                showOutput(res, res.data && res.data.result_code ? res.data.result_code : 'ERROR', false);
                showAlert('danger', res.message);
                loadEvents(id);
            },
            complete: function() {
                $btn.prop('disabled', false).html(origHtml);
            }
        });
    });

    // Initialize UI on load
    updateSelectedDevice();
});
</script>
@endsection
