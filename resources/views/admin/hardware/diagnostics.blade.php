@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <!-- Page Heading -->
    <div class="d-sm-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 class="h3 mb-0 text-gray-800"><i class="fas fa-stethoscope text-info mr-2"></i>Hardware Diagnostics</h1>
            <p class="text-muted small mb-0">Pengujian protokol driver Samsung Smart TV & Shelly 1PM Mini Gen3 (G4-B1 Protocol Lab).</p>
        </div>
        <div class="d-flex align-items-center">
            @if($realExecutionEnabled)
                <span class="badge badge-success px-3 py-2 mr-2"><i class="fas fa-shield-alt mr-1"></i>REAL NETWORK ENABLED</span>
            @else
                <span class="badge badge-danger px-3 py-2 mr-2" id="badgeSafetyGuard"><i class="fas fa-lock mr-1"></i>SAFETY GUARD: PHYSICAL BLOCKED</span>
            @endif
            <span class="badge badge-dark px-3 py-2 font-monospace">DRIVER: {{ strtoupper($activeDriver) }}</span>
        </div>
    </div>

    <!-- Safety Guard Notice Banner -->
    <div class="alert alert-info border-left-info shadow-sm mb-4" role="alert">
        <div class="d-flex align-items-start justify-content-between">
            <div>
                <h6 class="font-weight-bold mb-1 text-dark"><i class="fas fa-shield-alt mr-2 text-info"></i>GATE G4-B1 SAFETY PROTOCOL & ISOLATION GUARD</h6>
                <p class="small mb-0 text-dark">
                    Perangkat keras fisik Samsung 50U8500 dan Shelly 1PM Mini belum terpasang. Seluruh pengujian software driver pada gate ini 
                    <strong>wajib menggunakan Fake Transport terisolasi</strong>. Eksekusi jaringan ke IP fisik diblokir secara otomatis oleh sistem keamanan. 
                    Respons pengujian berlabel <code>[FAKE_TRANSPORT]</code> dan <strong>tidak memutasi status fisik di database utama</strong>.
                </p>
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
                        <h6 class="m-0 font-weight-bold"><i class="fas fa-hdd mr-1"></i> Pilih Target Perangkat</h6>
                    </div>
                    <div class="card-body">
                        <div class="form-group mb-3">
                            <label for="diagDeviceSelector" class="font-weight-bold small text-gray-700">Perangkat Terdaftar</label>
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
                                <span class="text-muted">Status Fisik (DB):</span>
                                <span id="diagConnStatus"><span class="badge badge-secondary">UNVERIFIED</span></span>
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
                        <h6 class="m-0 font-weight-bold"><i class="fas fa-cogs mr-1"></i> Mode Transport Pengujian</h6>
                        <span class="badge badge-info">G4-B1 TEST LAB</span>
                    </div>
                    <div class="card-body">
                        <div class="form-group mb-3">
                            <label class="font-weight-bold small text-gray-700">Pilihan Transport Layer</label>
                            <div class="btn-group btn-group-toggle d-flex" data-toggle="buttons">
                                <label class="btn btn-outline-primary active w-50" id="btnModeFake">
                                    <input type="radio" name="transportMode" id="optFake" value="fake" checked>
                                    <i class="fas fa-vial mr-1"></i> Fake Transport
                                </label>
                                <label class="btn btn-outline-danger w-50 disabled" id="btnModeReal" title="Physical execution is blocked by Safety Guard until G4-B2">
                                    <input type="radio" name="transportMode" id="optReal" value="real" disabled>
                                    <i class="fas fa-lock mr-1"></i> Real Network <small>(Blocked)</small>
                                </label>
                            </div>
                            <small class="form-text text-muted mt-2">
                                <em>Real Network terkunci secara otomatis. Seluruh uji software driver dijalankan aman via Fake Transport.</em>
                            </small>
                        </div>

                        <!-- Scenario Selector for Fake Transport -->
                        <div class="form-group mb-3" id="groupFakeScenario">
                            <label for="fakeScenarioSelect" class="font-weight-bold small text-gray-700">Skenario Respons Tiruan (Fake Protocol)</label>
                            <select id="fakeScenarioSelect" class="form-control form-control-sm">
                                <option value="SUCCESS">SUCCESS — Perangkat Normal & Responsif</option>
                                <option value="OFFLINE">OFFLINE — Host Tidak Dapat Dihubungi (Connection Refused)</option>
                                <option value="TIMEOUT">TIMEOUT — Waktu Tunggu Habis (> 3000ms)</option>
                                <option value="AUTH_REJECTED">AUTH_REJECTED — Otorisasi Ditolak / Sandi Salah</option>
                                <option value="INVALID_JSON">INVALID_JSON — Respons Protokol Rusak / Malformed</option>
                            </select>
                        </div>

                        <!-- Password Input for Shelly -->
                        <div class="form-group mb-0" id="groupShellyPassword" style="display: none;">
                            <label for="inputShellyPassword" class="font-weight-bold small text-gray-700">Sandi Autentikasi Shelly (Opsional)</label>
                            <input type="password" id="inputShellyPassword" class="form-control form-control-sm" placeholder="Masukkan sandi jika Shelly diproteksi">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Protocol Controls & Live Response Console -->
            <div class="col-lg-7 col-md-12 mb-4">
                <!-- Diagnostic Controls Card -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3 bg-secondary text-white d-flex align-items-center justify-content-between">
                        <h6 class="m-0 font-weight-bold"><i class="fas fa-terminal mr-1"></i> Operasi Diagnostik Driver</h6>
                        <span id="driverBadge" class="badge badge-light font-monospace text-dark">—</span>
                    </div>
                    <div class="card-body">
                        <!-- Primary Connection & Pairing Actions -->
                        <h6 class="font-weight-bold small text-uppercase text-gray-600 mb-2">1. Koneksi & Otorisasi</h6>
                        <div class="d-flex flex-wrap mb-4">
                            <button type="button" class="btn btn-outline-info btn-sm mr-2 mb-2" id="btnDiagProbe">
                                <i class="fas fa-network-wired mr-1"></i> Test Connection (Probe)
                            </button>
                            <button type="button" class="btn btn-outline-primary btn-sm mr-2 mb-2" id="btnDiagPair">
                                <i class="fas fa-key mr-1"></i> Pair / Handshake
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm mb-2" id="btnDiagStatus">
                                <i class="fas fa-info-circle mr-1"></i> Read Device Status
                            </button>
                        </div>

                        <!-- Device-Specific Operational Commands -->
                        <h6 class="font-weight-bold small text-uppercase text-gray-600 mb-2">2. Perintah Operasional Driver</h6>
                        
                        <!-- Samsung TV Buttons -->
                        <div id="samsungControls" class="p-3 bg-light rounded border mb-3" style="display: none;">
                            <span class="badge badge-primary mb-2">Samsung Smart TV (WebSocket)</span>
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
                                <button type="button" class="btn btn-outline-danger btn-sm mb-2 btn-command" data-cmd="POWER_ON" title="Optional unverified capability">
                                    <i class="fas fa-power-off mr-1"></i> Power On <small>(Unverified)</small>
                                </button>
                            </div>
                            <small class="text-muted d-block mt-1">
                                <i class="fas fa-info-circle mr-1"></i> Catatan: <code>POWER_ON</code> via WebSocket belum diverifikasi untuk Samsung 50U8500 karena TV menonaktifkan jaringan saat mode standby.
                            </small>
                        </div>

                        <!-- Shelly Relay Buttons -->
                        <div id="shellyControls" class="p-3 bg-light rounded border mb-3" style="display: none;">
                            <span class="badge badge-success mb-2">Shelly 1PM Mini Gen3 (HTTP RPC)</span>
                            <div class="d-flex flex-wrap">
                                <button type="button" class="btn btn-success btn-sm mr-2 mb-2 btn-command" data-cmd="SWITCH_ON">
                                    <i class="fas fa-lightbulb mr-1"></i> Relay ON (Lampu Meja Biliar)
                                </button>
                                <button type="button" class="btn btn-danger btn-sm mr-2 mb-2 btn-command" data-cmd="SWITCH_OFF">
                                    <i class="far fa-lightbulb mr-1"></i> Relay OFF (Lampu Meja Biliar)
                                </button>
                                <button type="button" class="btn btn-info btn-sm mb-2 btn-command" data-cmd="GET_STATUS">
                                    <i class="fas fa-tachometer-alt mr-1"></i> Read Telemetry (Watts/Volt/Temp)
                                </button>
                            </div>
                            <small class="text-danger d-block mt-1 font-weight-bold">
                                <i class="fas fa-exclamation-triangle mr-1"></i> Shelly hanya untuk lampu biliar. DILARANG memutus daya TV utama!
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Live Response Console Card -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3 bg-dark text-white d-flex align-items-center justify-content-between">
                        <h6 class="m-0 font-weight-bold font-monospace"><i class="fas fa-code mr-1"></i> Respons Diagnostik Terakhir</h6>
                        <span id="responseStatusBadge" class="badge badge-secondary font-monospace">READY</span>
                    </div>
                    <div class="card-body p-0">
                        <pre id="consoleOutput" class="bg-dark text-light p-3 m-0 small font-monospace" style="max-height: 280px; overflow-y: auto;">
// Siap mengeksekusi operasi diagnostik...
// Pilih perangkat dan klik aksi di atas untuk melihat respons protokol.</pre>
                    </div>
                    <div class="card-footer py-2 bg-light small text-muted d-flex justify-content-between align-items-center">
                        <span id="consoleFooterNotice"><i class="fas fa-shield-alt text-info mr-1"></i>Mode G4-B1: Output protokol diverifikasi via Fake Transport.</span>
                        <button type="button" class="btn btn-link btn-sm p-0 text-muted" id="btnClearConsole">Bersihkan Layar</button>
                    </div>
                </div>

                <!-- Recent Diagnostic Events Table Card -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3 bg-white d-flex align-items-center justify-content-between">
                        <h6 class="m-0 font-weight-bold text-gray-800"><i class="fas fa-history mr-1"></i> Riwayat Diagnostik Perangkat Ini</h6>
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
        $('#diagConnStatus').html(`<span class="badge ${badgeClass}">${conn}</span>`);

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
            $('#driverBadge').text('UNKNOWN_DRIVER');
        }

        loadEvents(id);
    }

    function showOutput(data, statusText, isSuccess) {
        $('#consoleOutput').text(JSON.stringify(data, null, 2));
        const $badge = $('#responseStatusBadge');
        $badge.text(statusText || (isSuccess ? 'SUCCESS' : 'FAILED'));
        $badge.removeClass('badge-secondary badge-success badge-danger badge-warning');
        $badge.addClass(isSuccess ? 'badge-success' : 'badge-danger');
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

    $('#btnClearConsole').on('click', function() {
        $('#consoleOutput').text('// Layar dibersihkan.\n// Siap menerima output diagnostik baru...');
        $('#responseStatusBadge').text('CLEARED').removeClass('badge-success badge-danger').addClass('badge-secondary');
    });

    $('#btnRefreshEvents').on('click', function() {
        const id = $('#diagDeviceSelector').val();
        loadEvents(id);
    });

    // Probe
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

    // Pair
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
                $('#btnDiagPair').prop('disabled', false).html('<i class="fas fa-key mr-1"></i> Pair / Handshake');
            }
        });
    });

    // Read Device Status
    $('#btnDiagStatus').on('click', function() {
        const id = $('#diagDeviceSelector').val();
        $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Reading...');

        $.ajax({
            url: `/admin/hardware/diagnostics/status/${id}`,
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

    // Send Command Buttons
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
                scenario: scenario
            },
            success: function(res) {
                showOutput(res, res.data.result_code || 'ACK_TRANSPORT', res.success);
                showAlert(res.success ? 'success' : 'warning', res.message);
                loadEvents(id);
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
