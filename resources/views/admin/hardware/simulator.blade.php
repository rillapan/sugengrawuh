@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <!-- Page Heading -->
    <div class="d-sm-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 class="h3 mb-0 text-gray-800"><i class="fas fa-flask text-warning mr-2"></i>Hardware Simulator</h1>
            <p class="text-muted small mb-0">Laboratorium simulasi komunikasi perangkat Samsung Smart TV dan Shelly Smart Relay tanpa hardware fisik.</p>
        </div>
        <button type="button" class="btn btn-outline-danger btn-sm shadow-sm" id="btnResetAllSim">
            <i class="fas fa-undo fa-sm mr-1"></i> Reset Seluruh Simulator
        </button>
    </div>

    <!-- Simulation Disclaimer Alert -->
    <div class="alert alert-warning border-left-warning shadow-sm mb-4" role="alert">
        <div class="d-flex align-items-center justify-content-between">
            <div>
                <h6 class="font-weight-bold mb-1 text-dark"><i class="fas fa-exclamation-triangle mr-2"></i>MODE SIMULASI AKTIF</h6>
                <p class="small mb-0 text-dark">
                    Seluruh respons berasal dari <strong>Mock Driver</strong> deterministik. Sistem <strong>TIDAK terhubung ke jaringan perangkat fisik</strong> dan tidak memalsukan status online di database utama.
                </p>
            </div>
            <span class="badge badge-dark px-3 py-2 font-monospace">DRIVER: {{ strtoupper($activeDriver) }}</span>
        </div>
    </div>

    <!-- Notification Alert Container -->
    <div id="simAlertContainer"></div>

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
            <!-- Left Column: Device Selection & Scenarios -->
            <div class="col-lg-5 col-md-12 mb-4">
                <!-- Device Picker Card -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3 bg-primary text-white">
                        <h6 class="m-0 font-weight-bold"><i class="fas fa-hdd mr-1"></i> Pilih Perangkat Virtual</h6>
                    </div>
                    <div class="card-body">
                        <div class="form-group mb-3">
                            <label for="deviceSelector" class="font-weight-bold small text-gray-700">Perangkat Terdaftar</label>
                            <select id="deviceSelector" class="form-control">
                                @foreach($devices as $dev)
                                    <option value="{{ $dev->id }}" 
                                        data-name="{{ $dev->name }}"
                                        data-category="{{ $dev->category }}"
                                        data-brand="{{ $dev->brand }}"
                                        data-model="{{ $dev->model }}"
                                        data-mac="{{ $dev->mac_address }}"
                                        data-ip="{{ $dev->ip_address }}"
                                        data-active="{{ $dev->is_active ? '1' : '0' }}"
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
                                <strong><span id="lblCategoryBrand">—</span></strong>
                            </div>
                            <div class="d-flex justify-content-between mb-1">
                                <span class="text-muted">Model & IP:</span>
                                <span><span id="lblModel">—</span> | <code id="lblIp">—</code></span>
                            </div>
                            <div class="d-flex justify-content-between mb-1">
                                <span class="text-muted">MAC Address:</span>
                                <code id="lblMac">—</code>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-muted">Station Mapping:</span>
                                <span id="lblStationMapping"><span class="badge badge-secondary">Belum Dipetakan</span></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Scenario Selection Card -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3 bg-dark text-white">
                        <h6 class="m-0 font-weight-bold"><i class="fas fa-sliders-h mr-1"></i> Skenario Simulasi Perangkat</h6>
                    </div>
                    <div class="card-body">
                        <p class="small text-muted mb-3">Tentukan perilaku respon perangkat virtual saat menerima perintah:</p>

                        <div class="custom-control custom-radio mb-2">
                            <input type="radio" id="scenSuccess" name="scenarioOption" value="SUCCESS" class="custom-control-input" checked>
                            <label class="custom-control-label font-weight-bold text-success" for="scenSuccess">
                                <i class="fas fa-check-circle mr-1"></i> SUCCESS (Respon Normal / ACK)
                            </label>
                            <div class="text-muted small ml-4">Perangkat merespons seketika dan mengubah simulated state.</div>
                        </div>

                        <div class="custom-control custom-radio mb-2">
                            <input type="radio" id="scenOffline" name="scenarioOption" value="OFFLINE" class="custom-control-input">
                            <label class="custom-control-label font-weight-bold text-danger" for="scenOffline">
                                <i class="fas fa-times-circle mr-1"></i> OFFLINE (Host Unreachable)
                            </label>
                            <div class="text-muted small ml-4">Perangkat tidak dapat dijangkau di jaringan virtual.</div>
                        </div>

                        <div class="custom-control custom-radio mb-2">
                            <input type="radio" id="scenTimeout" name="scenarioOption" value="TIMEOUT" class="custom-control-input">
                            <label class="custom-control-label font-weight-bold text-warning" for="scenTimeout">
                                <i class="fas fa-stopwatch mr-1"></i> TIMEOUT (Connection Timed Out)
                            </label>
                            <div class="text-muted small ml-4">Request simulasi melebihi batas waktu (3000ms).</div>
                        </div>

                        <div class="custom-control custom-radio mb-3">
                            <input type="radio" id="scenRejected" name="scenarioOption" value="REJECTED" class="custom-control-input">
                            <label class="custom-control-label font-weight-bold text-secondary" for="scenRejected">
                                <i class="fas fa-ban mr-1"></i> REJECTED (Perintah Ditolak / 403)
                            </label>
                            <div class="text-muted small ml-4">Perintah ditolak oleh protokol perangkat virtual.</div>
                        </div>

                        <button type="button" class="btn btn-primary btn-sm btn-block" id="btnApplyScenario">
                            <span class="spinner-border spinner-border-sm d-none mr-1" role="status"></span>
                            <i class="fas fa-save fa-sm mr-1"></i> Terapkan Skenario ke Perangkat
                        </button>
                    </div>
                </div>
            </div>

            <!-- Right Column: Commands & Live State -->
            <div class="col-lg-7 col-md-12 mb-4">
                <!-- Command Dispatcher Card -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-terminal mr-1"></i> Kirim Perintah Simulasi</h6>
                        <span id="activeScenarioBadge" class="badge badge-success px-2 py-1">SKENARIO: SUCCESS</span>
                    </div>
                    <div class="card-body">
                        <!-- Samsung TV Commands Panel -->
                        <div id="panelSamsungCommands" class="d-none">
                            <p class="small text-muted mb-2 font-weight-bold">Perintah Smart TV (Samsung):</p>
                            <div class="btn-group-vertical btn-group-sm d-block d-sm-none mb-2"></div>
                            <div class="d-none d-sm-flex flex-wrap mb-3">
                                <button type="button" class="btn btn-success btn-sm mr-2 mb-2 btn-sim-cmd" data-cmd="POWER_ON">
                                    <i class="fas fa-power-off mr-1"></i> Power ON
                                </button>
                                <button type="button" class="btn btn-secondary btn-sm mr-2 mb-2 btn-sim-cmd" data-cmd="STANDBY">
                                    <i class="fas fa-moon mr-1"></i> Standby
                                </button>
                                <button type="button" class="btn btn-warning btn-sm mr-2 mb-2 btn-sim-cmd" data-cmd="MUTE">
                                    <i class="fas fa-volume-mute mr-1"></i> Mute
                                </button>
                                <button type="button" class="btn btn-info btn-sm mr-2 mb-2 btn-sim-cmd" data-cmd="UNMUTE">
                                    <i class="fas fa-volume-up mr-1"></i> Unmute
                                </button>
                                <button type="button" class="btn btn-primary btn-sm mr-2 mb-2 btn-sim-cmd" data-cmd="GET_STATUS">
                                    <i class="fas fa-sync-alt mr-1"></i> Cek Status
                                </button>
                            </div>
                        </div>

                        <!-- Shelly Relay Commands Panel -->
                        <div id="panelShellyCommands" class="d-none">
                            <p class="small text-muted mb-2 font-weight-bold">Perintah Smart Relay 1PM (Shelly Meja Biliar):</p>
                            <div class="d-flex flex-wrap mb-3">
                                <button type="button" class="btn btn-success btn-sm mr-2 mb-2 btn-sim-cmd" data-cmd="SWITCH_ON">
                                    <i class="fas fa-lightbulb mr-1"></i> Nyalakan Lampu (Switch ON)
                                </button>
                                <button type="button" class="btn btn-secondary btn-sm mr-2 mb-2 btn-sim-cmd" data-cmd="SWITCH_OFF">
                                    <i class="far fa-lightbulb mr-1"></i> Matikan Lampu (Switch OFF)
                                </button>
                                <button type="button" class="btn btn-primary btn-sm mr-2 mb-2 btn-sim-cmd" data-cmd="GET_STATUS">
                                    <i class="fas fa-tachometer-alt mr-1"></i> Cek Status & Daya
                                </button>
                            </div>
                        </div>

                        <hr class="my-3">

                        <!-- Live Simulated State Card -->
                        <h6 class="font-weight-bold text-dark small mb-2"><i class="fas fa-microchip mr-1"></i> Live Simulated State:</h6>
                        <div class="row" id="stateCardsContainer">
                            <div class="col-sm-6 mb-2">
                                <div class="p-2 border rounded bg-light">
                                    <span class="text-muted small d-block">Status Daya / Output:</span>
                                    <span id="statePowerBadge" class="badge badge-secondary font-weight-bold py-1 px-2">—</span>
                                </div>
                            </div>
                            <div class="col-sm-6 mb-2">
                                <div class="p-2 border rounded bg-light">
                                    <span class="text-muted small d-block">Metrik / Atribut Tambahan:</span>
                                    <span id="stateExtraMetrics" class="small font-weight-bold text-dark">—</span>
                                </div>
                            </div>
                        </div>

                        <!-- Response Console -->
                        <h6 class="font-weight-bold text-dark small mt-3 mb-1"><i class="fas fa-code mr-1"></i> Respon Eksekusi Simulator:</h6>
                        <pre id="simResponseConsole" class="bg-dark text-success p-3 rounded small mb-0" style="max-height: 180px; overflow-y: auto;">// Menunggu perintah pertama...</pre>
                    </div>
                </div>

                <!-- Event History Card -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-history mr-1"></i> Log Event Simulasi Perangkat</h6>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="btnRefreshEvents">
                            <i class="fas fa-sync fa-sm"></i> Refresh Log
                        </button>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover table-striped mb-0 small text-gray-900" id="eventsTable">
                                <thead class="thead-light">
                                    <tr>
                                        <th style="width: 50px;">#</th>
                                        <th>Perintah</th>
                                        <th>Result Code</th>
                                        <th>Pesan Respons</th>
                                        <th>Waktu</th>
                                    </tr>
                                </thead>
                                <tbody id="eventsTableBody">
                                    <tr>
                                        <td colspan="5" class="text-center py-3 text-muted">Belum ada event simulasi tercatat.</td>
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
    // CSRF Setup
    $.ajaxSetup({
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content'),
            'Accept': 'application/json'
        }
    });

    function showNotification(type, message) {
        var alertHtml = '<div class="alert alert-' + type + ' alert-dismissible fade show" role="alert">' +
            (type === 'success' ? '<i class="fas fa-check-circle mr-1"></i> ' : '<i class="fas fa-exclamation-triangle mr-1"></i> ') +
            message +
            '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>' +
            '</div>';
        $('#simAlertContainer').html(alertHtml);
    }

    function updateDeviceView() {
        var $opt = $('#deviceSelector option:selected');
        if (!$opt.length) return;

        var category = $opt.data('category');
        var brand = $opt.data('brand');
        var model = $opt.data('model');
        var ip = $opt.data('ip');
        var mac = $opt.data('mac');
        var station = $opt.data('station');
        var role = $opt.data('role');

        $('#lblCategoryBrand').text(brand + ' (' + category + ')');
        $('#lblModel').text(model);
        $('#lblIp').text(ip);
        $('#lblMac').text(mac);

        if (station) {
            $('#lblStationMapping').html('<span class="badge badge-info">' + station + ' [' + role + ']</span>');
        } else {
            $('#lblStationMapping').html('<span class="badge badge-secondary">Belum Dipetakan</span>');
        }

        // Toggle Command Panel
        if (category === 'SMART_TV') {
            $('#panelSamsungCommands').removeClass('d-none');
            $('#panelShellyCommands').addClass('d-none');
        } else {
            $('#panelShellyCommands').removeClass('d-none');
            $('#panelSamsungCommands').addClass('d-none');
        }

        fetchDeviceStatus();
        fetchDeviceEvents();
    }

    function fetchDeviceStatus() {
        var deviceId = $('#deviceSelector').val();
        if (!deviceId) return;

        $.ajax({
            url: '/admin/hardware/simulator/status/' + deviceId,
            type: 'GET',
            success: function(res) {
                if (res.data && res.data.simulated_state) {
                    renderSimulatedState(res.data.simulated_state);
                }
            },
            error: function() {
                // If offline or error
                $('#statePowerBadge').text('OFFLINE / UNKNOWN').attr('class', 'badge badge-danger font-weight-bold py-1 px-2');
            }
        });
    }

    function renderSimulatedState(state) {
        if (state.power) {
            var pwrClass = state.power === 'ON' ? 'badge badge-success' : (state.power === 'OFFLINE' ? 'badge badge-danger' : 'badge badge-secondary');
            $('#statePowerBadge').text(state.power).attr('class', pwrClass + ' font-weight-bold py-1 px-2');
            $('#stateExtraMetrics').text('Mute: ' + (state.mute ? 'YA' : 'TIDAK') + ' | Volume: ' + (state.volume || 20));
        } else if (state.switch) {
            var swClass = state.switch === 'ON' ? 'badge badge-success' : (state.switch === 'OFFLINE' ? 'badge badge-danger' : 'badge badge-secondary');
            $('#statePowerBadge').text(state.switch).attr('class', swClass + ' font-weight-bold py-1 px-2');
            $('#stateExtraMetrics').text('Daya: ' + (state.power_watts || 0) + ' W | Tegangan: ' + (state.voltage || 228) + ' V');
        }
    }

    function fetchDeviceEvents() {
        var deviceId = $('#deviceSelector').val();
        if (!deviceId) return;

        $.ajax({
            url: '/admin/hardware/simulator/events/' + deviceId,
            type: 'GET',
            success: function(res) {
                var $tbody = $('#eventsTableBody');
                $tbody.empty();

                if (!res.data || res.data.length === 0) {
                    $tbody.html('<tr><td colspan="5" class="text-center py-3 text-muted">Belum ada event simulasi tercatat.</td></tr>');
                    return;
                }

                $.each(res.data, function(idx, ev) {
                    var badgeClass = ev.success ? 'badge badge-success' : 'badge badge-danger';
                    if (ev.result_code === 'TIMEOUT') badgeClass = 'badge badge-warning';
                    if (ev.result_code === 'COMMAND_REJECTED') badgeClass = 'badge badge-secondary';

                    var row = '<tr>' +
                        '<td>' + (idx + 1) + '</td>' +
                        '<td><code>' + ev.command + '</code></td>' +
                        '<td><span class="' + badgeClass + '">' + ev.result_code + '</span></td>' +
                        '<td>' + ev.message + '</td>' +
                        '<td><small class="text-muted">' + (ev.executed_at ? ev.executed_at.substring(11, 19) : '—') + '</small></td>' +
                        '</tr>';
                    $tbody.append(row);
                });
            }
        });
    }

    // Device selector change
    $('#deviceSelector').on('change', function() {
        updateDeviceView();
    });

    // Apply Scenario
    $('#btnApplyScenario').on('click', function() {
        var deviceId = $('#deviceSelector').val();
        var scenario = $('input[name="scenarioOption"]:checked').val();
        var $btn = $(this);

        $btn.prop('disabled', true);
        $btn.find('.spinner-border').removeClass('d-none');

        $.ajax({
            url: '/admin/hardware/simulator/scenario',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                device_id: parseInt(deviceId, 10),
                scenario: scenario
            }),
            success: function(res) {
                $btn.prop('disabled', false);
                $btn.find('.spinner-border').addClass('d-none');
                showNotification('success', res.message);
                $('#activeScenarioBadge').text('SKENARIO: ' + scenario);
                if (scenario === 'SUCCESS') $('#activeScenarioBadge').attr('class', 'badge badge-success px-2 py-1');
                if (scenario === 'OFFLINE') $('#activeScenarioBadge').attr('class', 'badge badge-danger px-2 py-1');
                if (scenario === 'TIMEOUT') $('#activeScenarioBadge').attr('class', 'badge badge-warning px-2 py-1');
                if (scenario === 'REJECTED') $('#activeScenarioBadge').attr('class', 'badge badge-secondary px-2 py-1');
                if (scenario === 'RECOVERED') $('#activeScenarioBadge').attr('class', 'badge badge-info px-2 py-1');
            },
            error: function(xhr) {
                $btn.prop('disabled', false);
                $btn.find('.spinner-border').addClass('d-none');
                showNotification('danger', xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Gagal menerapkan skenario.');
            }
        });
    });

    // Send Simulated Command
    $('.btn-sim-cmd').on('click', function() {
        var deviceId = $('#deviceSelector').val();
        var command = $(this).data('cmd');
        var $btn = $(this);

        $('.btn-sim-cmd').prop('disabled', true);
        $('#simResponseConsole').text('// Mengirim perintah ' + command + ' ke perangkat virtual...');

        $.ajax({
            url: '/admin/hardware/simulator/command',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                device_id: parseInt(deviceId, 10),
                command: command
            }),
            success: function(res) {
                $('.btn-sim-cmd').prop('disabled', false);
                $('#simResponseConsole').text(JSON.stringify(res, null, 2));
                if (res.data && res.data.simulated_state) {
                    renderSimulatedState(res.data.simulated_state);
                }
                fetchDeviceEvents();
            },
            error: function(xhr) {
                $('.btn-sim-cmd').prop('disabled', false);
                $('#simResponseConsole').text(JSON.stringify(xhr.responseJSON || { error: 'Request Failed' }, null, 2));
                fetchDeviceEvents();
            }
        });
    });

    // Refresh events
    $('#btnRefreshEvents').on('click', function() {
        fetchDeviceEvents();
    });

    // Reset All
    $('#btnResetAllSim').on('click', function() {
        if (!confirm('Apakah Anda yakin ingin mereset seluruh status simulasi hardware ke nilai awal?')) {
            return;
        }

        $.ajax({
            url: '/admin/hardware/simulator/reset',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({}),
            success: function(res) {
                showNotification('success', res.message);
                updateDeviceView();
            },
            error: function(xhr) {
                showNotification('danger', 'Gagal mereset simulator.');
            }
        });
    });

    // Initial load
    updateDeviceView();
});
</script>
@endsection
