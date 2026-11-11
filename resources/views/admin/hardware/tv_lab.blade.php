@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <!-- Page Heading -->
    <div class="d-sm-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 class="h3 mb-0 text-gray-800">
                <i class="fas fa-tv text-warning mr-2"></i>Samsung TV Lab — Simulator Layar 16:9 (43FE60)
            </h1>
            <p class="text-muted small mb-0">
                Simulator layar televisi PlayStation untuk menguji overlay peringatan, kontrol volume simulasi, dan protokol polling/ACK aplikasi Samsung Tizen.
            </p>
        </div>
        <div>
            <a href="{{ route('device.index') }}" class="btn btn-outline-primary btn-sm shadow-sm">
                <i class="fas fa-gamepad fa-sm mr-1"></i> Buka Halaman /device
            </a>
        </div>
    </div>

    <!-- Truthfulness Banner -->
    <div class="alert alert-warning border-left-warning shadow-sm mb-4" role="alert">
        <div class="d-flex align-items-center justify-content-between flex-wrap">
            <div>
                <h6 class="font-weight-bold mb-1 text-dark">
                    <i class="fas fa-exclamation-triangle mr-2"></i>SIMULASI — TIDAK MENGONTROL TV FISIK SAMSUNG
                </h6>
                <p class="small mb-0 text-dark">
                    Layar 16:9 di bawah merepresentasikan aplikasi <strong>Samsung Tizen Web App (WGT)</strong> pada TV <strong>Samsung 43FE60</strong>.
                    Status event baru berubah dari <code>PENDING</code> menjadi <code>DELIVERED_TO_APP</code> setelah consumer mengirim <code>POST /api/tv-agent/events/{id}/ack</code>.
                </p>
            </div>
            <span class="badge badge-dark px-3 py-2 mt-2 mt-sm-0">MODE: MOCK_SAMSUNG_TV</span>
        </div>
    </div>

    @if(empty($stationsPayload))
        <div class="card shadow mb-4">
            <div class="card-body text-center py-5">
                <i class="fas fa-tv fa-3x text-gray-300 mb-3 d-block"></i>
                <h5 class="text-gray-700 font-weight-bold">Belum Ada Stasiun PlayStation</h5>
                <p class="text-muted small mb-0">Silakan tambahkan perangkat kategori PlayStation terlebih dahulu.</p>
            </div>
        </div>
    @else
        <div class="row">
            <!-- Left Column: 16:9 TV Screen Simulator -->
            <div class="col-lg-8 mb-4">
                <div class="card shadow mb-4">
                    <div class="card-header py-3 bg-dark text-white d-flex justify-content-between align-items-center flex-wrap">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-desktop text-warning mr-2"></i>
                            <h6 class="m-0 font-weight-bold">Layar Virtual Samsung 43FE60 (16:9)</h6>
                        </div>
                        <div class="d-flex align-items-center mt-2 mt-sm-0">
                            <div class="custom-control custom-switch mr-3">
                                <input type="checkbox" class="custom-control-input" id="toggleAutoPoll" checked>
                                <label class="custom-control-label small text-light" for="toggleAutoPoll">Auto-Poll & ACK</label>
                            </div>
                            <span id="labConnectionBadge" class="badge badge-success px-2 py-1">TERHUBUNG KE SERVER</span>
                        </div>
                    </div>
                    <div class="card-body bg-secondary p-3">
                        <!-- 16:9 Aspect Ratio Container -->
                        <div id="tvScreen16x9" style="position: relative; width: 100%; padding-top: 56.25%; background: radial-gradient(circle at center, #1f2937 0%, #0b0f19 100%); border: 8px solid #111827; border-radius: 8px; overflow: hidden; box-shadow: 0 12px 30px rgba(0,0,0,0.65);">
                            <div style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; display: flex; flex-direction: column; justify-content: space-between; padding: 20px; color: #f9fafb; font-family: sans-serif;">
                                <!-- Top Bar -->
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div style="font-size: 0.78rem; letter-spacing: 2px; color: #f59e0b; font-weight: 800;">SUGENG RAWUH</div>
                                        <div id="screenStationLabel" style="font-size: 1.35rem; font-weight: 800; color: #ffffff;">PS 01 — PLAYSTATION</div>
                                        <div id="screenTvModelLabel" style="font-size: 0.75rem; color: #9ca3af;">Samsung 43FE60 Smart TV (Tizen Agent Simulator)</div>
                                    </div>
                                    <div class="text-right">
                                        <span id="screenBillingStatusBadge" class="badge badge-success px-2 py-1" style="font-size: 0.75rem;">DIGUNAKAN</span>
                                        <div class="mt-2 p-2 rounded" style="background: rgba(17, 24, 39, 0.85); border: 1px solid #374151; min-width: 155px;">
                                            <div class="d-flex justify-content-between align-items-center small">
                                                <span style="color: #9ca3af;"><i class="fas fa-volume-up mr-1"></i>Volume:</span>
                                                <strong id="screenVolumeText" style="color: #60a5fa;">30/100</strong>
                                            </div>
                                            <div class="progress mt-1" style="height: 6px; background-color: #374151;">
                                                <div id="screenVolumeBar" class="progress-bar bg-info" role="progressbar" style="width: 30%;"></div>
                                            </div>
                                            <div class="d-flex justify-content-between align-items-center mt-1" style="font-size: 0.7rem;">
                                                <span style="color: #9ca3af;">Audio Status:</span>
                                                <span id="screenMuteBadge" class="badge badge-success">UNMUTE (SIM)</span>
                                            </div>
                                            <div class="d-flex justify-content-between align-items-center mt-1" style="font-size: 0.7rem;">
                                                <span style="color: #9ca3af;"><i class="fas fa-shield-alt mr-1"></i>Batas Max:</span>
                                                <span id="screenVolLimitBadge" class="badge badge-secondary">NONAKTIF</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Center Game Area Placeholder -->
                                <div class="text-center my-auto py-3">
                                    <div style="display: inline-block; padding: 14px 28px; border: 2px dashed rgba(156, 163, 175, 0.35); border-radius: 10px; background: rgba(17, 24, 39, 0.45);">
                                        <i class="fab fa-playstation fa-2x mb-2 d-block" style="color: #60a5fa;"></i>
                                        <div style="font-weight: 700; letter-spacing: 1px; color: #d1d5db; font-size: 0.95rem;">[AREA SIMULASI GAME HDMI]</div>
                                        <div id="screenSessionGenLabel" style="font-size: 0.75rem; color: #9ca3af; margin-top: 4px;">Session Generation: #1</div>
                                    </div>
                                </div>

                                <!-- Volume Enforcement Overlay (Auto-clamped) -->
                                <div id="screenEnforcementBanner" class="d-none mt-2" style="background-color: #dc2626; color: #ffffff; border-radius: 6px; padding: 10px 14px; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.7); font-size: 0.85rem; border: 2px solid #f87171;">
                                    <i class="fas fa-exclamation-triangle mr-1"></i>
                                    <strong id="screenEnforcementText">Volume melebihi batas maksimal. Otomatis disesuaikan!</strong>
                                </div>

                                <!-- Bottom Alert Banner Overlay (Hidden when idle) -->
                                <div id="screenAlertBanner" class="d-none" style="background-color: #f59e0b; color: #111827; border-radius: 8px; padding: 14px 18px; box-shadow: 0 8px 25px rgba(0, 0, 0, 0.7); border: 2px solid #fde68a;">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="d-flex align-items-center">
                                            <i class="fas fa-bell fa-lg mr-2"></i>
                                            <strong id="screenAlertTitle" style="font-size: 1.05rem; letter-spacing: 0.5px; text-transform: uppercase;">PERINGATAN WAKTU BERMAIN</strong>
                                        </div>
                                        <span class="badge badge-dark" id="screenAlertCountdown">8s</span>
                                    </div>
                                    <div id="screenAlertBody" style="font-size: 0.92rem; font-weight: 600; margin-top: 4px;">
                                        Silakan hubungi kasir untuk memperpanjang waktu bermain.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Event Audit Log Table -->
                <div class="card shadow">
                    <div class="card-header py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-list-alt mr-1"></i> Riwayat Event Kontrol TV pada Station Ini
                        </h6>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="btnRefreshLabHistory">
                            <i class="fas fa-sync-alt fa-sm mr-1"></i> Refresh
                        </button>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-striped table-hover mb-0 small">
                                <thead class="thead-light">
                                    <tr>
                                        <th>ID</th>
                                        <th>Waktu</th>
                                        <th>Tipe / Command</th>
                                        <th>Judul & Pesan</th>
                                        <th>Status</th>
                                        <th>Result Code</th>
                                    </tr>
                                </thead>
                                <tbody id="labHistoryTableBody">
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-3">Memuat riwayat event...</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Station Selector & Tizen Connection Parameters -->
            <div class="col-lg-4 mb-4">
                <div class="card shadow mb-4">
                    <div class="card-header py-3 bg-primary text-white">
                        <h6 class="m-0 font-weight-bold"><i class="fas fa-sliders-h mr-1"></i> Pilih Stasiun PlayStation</h6>
                    </div>
                    <div class="card-body">
                        <div class="form-group">
                            <label for="labStationSelect" class="small font-weight-bold text-dark">Stasiun PlayStation Aktif:</label>
                            <select id="labStationSelect" class="form-control">
                                @foreach($stationsPayload as $st)
                                    <option value="{{ $st['id'] }}" {{ (int)$st['id'] === (int)$selectedStationId ? 'selected' : '' }}>
                                        {{ $st['nama'] }} ({{ $st['playstation_names'] }}) — [{{ $st['status'] }}]
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="p-2 bg-light border rounded small">
                            <div class="d-flex justify-content-between mb-1">
                                <span class="text-muted">Status Station:</span>
                                <strong id="infoStationStatus">-</strong>
                            </div>
                            <div class="d-flex justify-content-between mb-1">
                                <span class="text-muted">Samsung TV Terpetakan:</span>
                                <strong id="infoMappedTv">-</strong>
                            </div>
                            <div class="d-flex justify-content-between mb-1">
                                <span class="text-muted">Mode Driver:</span>
                                <span class="badge badge-info">MOCK (mock_samsung_tv)</span>
                            </div>
                            <div class="d-flex justify-content-between mb-1">
                                <span class="text-muted">Batas Volume Maks:</span>
                                <span id="infoVolLimit" class="badge badge-secondary">NONAKTIF</span>
                            </div>
                            <div class="d-flex justify-content-between mb-1">
                                <span class="text-muted">Status Penegakan:</span>
                                <span id="infoEnforceStatus" class="badge badge-light border">WITHIN_LIMIT</span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-muted">Last Poll:</span>
                                <code id="infoLastPollTime">-</code>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Volume Limit Enforcement Simulator Card -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3 bg-warning text-dark d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold"><i class="fas fa-shield-alt mr-1"></i> Uji Coba Penegakan Batas Volume</h6>
                    </div>
                    <div class="card-body small">
                        <p class="text-muted mb-2">
                            Simulasikan penegakan batas volume saat TV mendeteksi volume melebihi batas maksimal:
                        </p>
                        <div class="d-flex flex-wrap gap-2 mb-2">
                            <button type="button" class="btn btn-outline-danger btn-sm mr-2 mb-1" id="btnSimulateExceedVolume">
                                <i class="fas fa-volume-up mr-1"></i> Set Vol 85% (Langgar Batas)
                            </button>
                            <button type="button" class="btn btn-outline-success btn-sm mb-1" id="btnSimulateEnforceNow">
                                <i class="fas fa-shield-alt mr-1"></i> Trigger Enforce Now
                            </button>
                        </div>
                        <div id="simEnforcementFeedback" class="small mt-1 text-muted"></div>
                    </div>
                </div>

                <!-- Tizen Web App Config Info -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3 bg-dark text-white">
                        <h6 class="m-0 font-weight-bold"><i class="fas fa-code mr-1"></i> Parameter Tizen Web App (WGT)</h6>
                    </div>
                    <div class="card-body small">
                        <p class="text-muted mb-2">
                            Gunakan parameter berikut pada <strong>Samsung Tizen Studio Web Simulator</strong> untuk menguji polling dari luar browser:
                        </p>
                        <div class="mb-2">
                            <label class="font-weight-bold text-dark mb-0">Backend Base URL:</label>
                            <input type="text" class="form-control form-control-sm font-monospace" id="cfgBaseUrl" value="{{ url('/') }}" readonly>
                        </div>
                        <div class="mb-2">
                            <label class="font-weight-bold text-dark mb-0">Station ID:</label>
                            <input type="text" class="form-control form-control-sm font-monospace" id="cfgStationId" value="" readonly>
                        </div>
                        <div class="mb-2">
                            <label class="font-weight-bold text-dark mb-0">Device Token (HMAC):</label>
                            <input type="text" class="form-control form-control-sm font-monospace" id="cfgDeviceToken" value="" readonly>
                        </div>
                        <div class="mb-2">
                            <label class="font-weight-bold text-dark mb-0">Dev Token (Local/Test):</label>
                            <input type="text" class="form-control form-control-sm font-monospace" id="cfgDevToken" value="" readonly>
                        </div>
                        <div class="mb-0">
                            <label class="font-weight-bold text-dark mb-0">Poll Endpoint:</label>
                            <input type="text" class="form-control form-control-sm font-monospace" id="cfgPollUrl" value="" readonly>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>

<script>
(function() {
    var stationsMap = {};
    var stationsList = @json($stationsPayload);
    stationsList.forEach(function(st) {
        stationsMap[String(st.id)] = st;
    });

    var pollEndpointBase = @json(route('api.tv-agent.events.index'));
    var ackEndpointTemplate = @json(route('api.tv-agent.events.ack', ['event' => '__EVENT_ID__']));

    var currentStationId = String(@json($selectedStationId));
    var renderedEventIds = {};
    var bannerTimer = null;
    var countdownInterval = null;

    function getBadgeClass(status) {
        switch (status) {
            case 'PENDING': return 'badge-warning text-dark';
            case 'DELIVERED_TO_APP': return 'badge-success';
            case 'ACKED_BY_SIMULATOR': return 'badge-success';
            case 'EXPIRED': return 'badge-secondary';
            case 'CANCELLED': return 'badge-secondary';
            case 'STALE': return 'badge-dark';
            case 'FAILED': return 'badge-danger';
            default: return 'badge-secondary';
        }
    }

    function updateStationSelectionUI() {
        var st = stationsMap[currentStationId];
        if (!st) return;

        var screenStationLabel = document.getElementById('screenStationLabel');
        if (screenStationLabel) {
            screenStationLabel.textContent = st.nama + ' — PLAYSTATION (' + st.playstation_names + ')';
        }

        var screenTvModel = document.getElementById('screenTvModelLabel');
        if (screenTvModel) {
            screenTvModel.textContent = st.tv_device
                ? (st.tv_device.name + ' (' + st.tv_device.model + ' — ' + st.tv_device.ip_address + ')')
                : 'Belum ada Samsung Smart TV terpetakan';
        }

        var statusBadge = document.getElementById('screenBillingStatusBadge');
        if (statusBadge) {
            statusBadge.className = 'badge px-2 py-1 ' + (st.status === 'Digunakan' ? 'badge-success' : 'badge-secondary');
            statusBadge.textContent = st.status.toUpperCase();
        }

        var genLabel = document.getElementById('screenSessionGenLabel');
        if (genLabel) {
            genLabel.textContent = 'Session Generation: #' + (st.session_generation || 1);
        }

        var infoStatus = document.getElementById('infoStationStatus');
        if (infoStatus) infoStatus.textContent = st.status;

        var infoTv = document.getElementById('infoMappedTv');
        if (infoTv) {
            infoTv.textContent = st.tv_device ? (st.tv_device.name + ' (' + st.tv_device.model + ')') : 'Tidak Ada';
        }

        var limitCfg = st.volume_limit_config || { enabled: false, max_limit: 50, enforcement_status: 'WITHIN_LIMIT' };
        var scrVolLimit = document.getElementById('screenVolLimitBadge');
        if (scrVolLimit) {
            scrVolLimit.className = 'badge ' + (limitCfg.enabled ? 'badge-success' : 'badge-secondary');
            scrVolLimit.textContent = limitCfg.enabled ? ('BATAS: ' + limitCfg.max_limit + '%') : 'NONAKTIF';
        }
        var infoLimit = document.getElementById('infoVolLimit');
        if (infoLimit) {
            infoLimit.className = 'badge ' + (limitCfg.enabled ? 'badge-success' : 'badge-secondary');
            infoLimit.textContent = limitCfg.enabled ? ('BATAS: ' + limitCfg.max_limit + '%') : 'NONAKTIF';
        }
        var infoEnforce = document.getElementById('infoEnforceStatus');
        if (infoEnforce) {
            infoEnforce.textContent = limitCfg.enforcement_status || 'WITHIN_LIMIT';
        }

        var cfgStationId = document.getElementById('cfgStationId');
        var cfgDeviceToken = document.getElementById('cfgDeviceToken');
        var cfgDevToken = document.getElementById('cfgDevToken');
        var cfgPollUrl = document.getElementById('cfgPollUrl');

        if (cfgStationId) cfgStationId.value = String(st.id);
        if (cfgDeviceToken) cfgDeviceToken.value = st.device_token || '';
        if (cfgDevToken) cfgDevToken.value = st.dev_token || '';
        if (cfgPollUrl) cfgPollUrl.value = pollEndpointBase + '?station_id=' + encodeURIComponent(st.id);

        if (st.tv_state) {
            renderSimulatedAudio(st.tv_state.volume, st.tv_state.mute);
        }
    }

    function renderSimulatedAudio(volume, mute) {
        var vol = typeof volume === 'number' ? volume : 30;
        var isMuted = !!mute;

        var volText = document.getElementById('screenVolumeText');
        if (volText) volText.textContent = vol + '/100';

        var volBar = document.getElementById('screenVolumeBar');
        if (volBar) volBar.style.width = Math.max(0, Math.min(100, vol)) + '%';

        var muteBadge = document.getElementById('screenMuteBadge');
        if (muteBadge) {
            muteBadge.className = 'badge ' + (isMuted ? 'badge-danger' : 'badge-success');
            muteBadge.textContent = isMuted ? 'MUTE (SIM)' : 'UNMUTE (SIM)';
        }
    }

    function showOverlayAlertOnScreen(ev) {
        var banner = document.getElementById('screenAlertBanner');
        var titleEl = document.getElementById('screenAlertTitle');
        var bodyEl = document.getElementById('screenAlertBody');
        var countEl = document.getElementById('screenAlertCountdown');
        if (!banner || !titleEl || !bodyEl) return;

        // Render strictly with textContent to prevent XSS
        titleEl.textContent = ev.message_title || 'PERINGATAN KASIR';
        bodyEl.textContent = ev.message_body || '';

        var duration = parseInt(ev.display_duration_seconds || 8, 10);
        if (isNaN(duration) || duration < 3) duration = 8;

        if (bannerTimer) clearTimeout(bannerTimer);
        if (countdownInterval) clearInterval(countdownInterval);

        var remaining = duration;
        if (countEl) countEl.textContent = remaining + 's';
        banner.classList.remove('d-none');

        countdownInterval = setInterval(function() {
            remaining -= 1;
            if (remaining > 0 && countEl) {
                countEl.textContent = remaining + 's';
            }
        }, 1000);

        bannerTimer = setTimeout(function() {
            banner.classList.add('d-none');
            if (countdownInterval) clearInterval(countdownInterval);
        }, duration * 1000);
    }

    function acknowledgeEventToServer(ev, st) {
        var ackUrl = ackEndpointTemplate.replace('__EVENT_ID__', encodeURIComponent(ev.id));
        return fetch(ackUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-TV-Station-Id': String(st.id),
                'X-TV-Device-Token': st.device_token
            },
            body: JSON.stringify({
                station_id: st.id,
                consumer: 'admin_tv_lab_simulator',
                rendered_at: new Date().toISOString()
            })
        })
        .then(function(r) { return r.json(); })
        .then(function() {
            fetchAdminStationHistory();
        })
        .catch(function() {});
    }

    var lastEnforcementReportTime = 0;

    function enforceVolumeLimitInSimulator(currentVol, maxLimit, st) {
        // Clamp simulated audio
        renderSimulatedAudio(maxLimit, false);

        // Show screen overlay notification
        var enfBanner = document.getElementById('screenEnforcementBanner');
        var enfText = document.getElementById('screenEnforcementText');
        if (enfBanner && enfText) {
            enfText.textContent = 'Volume melebihi batas (' + currentVol + '% > ' + maxLimit + '%). Otomatis diturunkan ke ' + maxLimit + '%!';
            enfBanner.classList.remove('d-none');
            setTimeout(function() { enfBanner.classList.add('d-none'); }, 5000);
        }

        // Rate-limit report to server (at most once every 3 seconds)
        var now = Date.now();
        if (now - lastEnforcementReportTime < 3000) return;
        lastEnforcementReportTime = now;

        var enforceUrl = '/api/tv-agent/events/volume-enforcement';
        fetch(enforceUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-TV-Station-Id': String(st.id),
                'X-TV-Device-Token': st.device_token
            },
            body: JSON.stringify({
                station_id: st.id,
                previous_volume: currentVol,
                enforced_volume: maxLimit,
                max_limit: maxLimit,
                enforcement_source: 'SIMULATOR',
                status: 'ENFORCED',
                details: 'Penegakan otomatis oleh TV Lab Simulator (volume diturunkan dari ' + currentVol + ' ke ' + maxLimit + ').'
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            var fb = document.getElementById('simEnforcementFeedback');
            if (fb) {
                fb.className = 'small mt-1 text-success font-weight-bold';
                fb.textContent = 'Penegakan batas volume berhasil dilaporkan (Log ID: #' + (res.data ? res.data.enforcement_id : 'OK') + ')';
                setTimeout(function() { fb.textContent = ''; }, 4000);
            }
            fetchAdminStationHistory();
        })
        .catch(function() {});
    }

    function pollConsumerEvents() {
        var toggle = document.getElementById('toggleAutoPoll');
        if (toggle && !toggle.checked) return;

        var st = stationsMap[currentStationId];
        if (!st) return;

        var url = pollEndpointBase + '?station_id=' + encodeURIComponent(st.id);
        fetch(url, {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'X-TV-Station-Id': String(st.id),
                'X-TV-Device-Token': st.device_token
            }
        })
        .then(function(r) {
            return r.json().then(function(body) {
                return { ok: r.ok, body: body };
            });
        })
        .then(function(res) {
            var connBadge = document.getElementById('labConnectionBadge');
            var lastPollEl = document.getElementById('infoLastPollTime');
            if (lastPollEl) {
                lastPollEl.textContent = new Date().toLocaleTimeString();
            }

            if (!res.ok || !res.body || !res.body.success) {
                if (connBadge) {
                    connBadge.className = 'badge badge-danger px-2 py-1';
                    connBadge.textContent = 'POLL ERROR';
                }
                return;
            }

            if (connBadge) {
                connBadge.className = 'badge badge-success px-2 py-1';
                connBadge.textContent = 'TERHUBUNG KE SERVER';
            }

            if (res.body.station) {
                st.status = res.body.station.status;
                st.session_generation = res.body.station.session_generation;
                var statusBadge = document.getElementById('screenBillingStatusBadge');
                if (statusBadge) {
                    statusBadge.className = 'badge px-2 py-1 ' + (st.status === 'Digunakan' ? 'badge-success' : 'badge-secondary');
                    statusBadge.textContent = st.status.toUpperCase();
                }
            }

            if (res.body.simulated_state) {
                renderSimulatedAudio(res.body.simulated_state.volume, res.body.simulated_state.mute);
            }

            if (res.body.volume_limit_config) {
                var limitCfg = res.body.volume_limit_config;
                st.volume_limit_config = limitCfg;
                var scrVolLimit = document.getElementById('screenVolLimitBadge');
                if (scrVolLimit) {
                    scrVolLimit.className = 'badge ' + (limitCfg.enabled ? 'badge-success' : 'badge-secondary');
                    scrVolLimit.textContent = limitCfg.enabled ? ('BATAS: ' + limitCfg.max_limit + '%') : 'NONAKTIF';
                }
                var infoLimit = document.getElementById('infoVolLimit');
                if (infoLimit) {
                    infoLimit.className = 'badge ' + (limitCfg.enabled ? 'badge-success' : 'badge-secondary');
                    infoLimit.textContent = limitCfg.enabled ? ('BATAS: ' + limitCfg.max_limit + '%') : 'NONAKTIF';
                }
                var infoEnforce = document.getElementById('infoEnforceStatus');
                if (infoEnforce) {
                    infoEnforce.textContent = limitCfg.enforcement_status || 'WITHIN_LIMIT';
                }

                if (limitCfg.enabled && res.body.simulated_state && typeof res.body.simulated_state.volume === 'number') {
                    var curVol = res.body.simulated_state.volume;
                    if (curVol > limitCfg.max_limit) {
                        enforceVolumeLimitInSimulator(curVol, limitCfg.max_limit, st);
                    }
                }
            }

            var events = res.body.events || [];
            events.forEach(function(ev) {
                if (renderedEventIds[ev.id]) return;
                renderedEventIds[ev.id] = true;

                if (ev.command === 'SHOW_ALERT') {
                    showOverlayAlertOnScreen(ev);
                } else if (ev.command === 'VOLUME_DOWN' || ev.command === 'VOLUME_UP' || ev.command === 'MUTE_TOGGLE' || ev.command === 'SET_VOLUME') {
                    renderSimulatedAudio(ev.simulated_volume, ev.simulated_muted);
                }

                // Immediately ACK after rendering in DOM
                acknowledgeEventToServer(ev, st);
            });
        })
        .catch(function() {
            var connBadge = document.getElementById('labConnectionBadge');
            if (connBadge) {
                connBadge.className = 'badge badge-danger px-2 py-1';
                connBadge.textContent = 'TERPUTUS';
            }
        });
    }

    function fetchAdminStationHistory() {
        var st = stationsMap[currentStationId];
        if (!st || !st.status_url) return;

        fetch(st.status_url, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (!res || !res.success || !res.data) return;
            var tbody = document.getElementById('labHistoryTableBody');
            if (!tbody) return;
            tbody.innerHTML = '';

            var history = res.data.history || [];
            if (!history.length) {
                var tr = document.createElement('tr');
                var td = document.createElement('td');
                td.colSpan = 6;
                td.className = 'text-center text-muted py-3';
                td.textContent = 'Belum ada event kontrol TV untuk station ini.';
                tr.appendChild(td);
                tbody.appendChild(tr);
                return;
            }

            history.forEach(function(ev) {
                var tr = document.createElement('tr');

                var tdId = document.createElement('td');
                tdId.textContent = '#' + ev.id;
                tr.appendChild(tdId);

                var tdTime = document.createElement('td');
                tdTime.textContent = ev.created_at_formatted || (ev.created_at ? ev.created_at.substring(11, 19) : '-');
                tr.appendChild(tdTime);

                var tdCmd = document.createElement('td');
                var codeEl = document.createElement('code');
                codeEl.textContent = ev.command + ' (' + ev.event_type + ')';
                tdCmd.appendChild(codeEl);
                tr.appendChild(tdCmd);

                var tdMsg = document.createElement('td');
                var strongEl = document.createElement('strong');
                strongEl.className = 'd-block text-dark';
                strongEl.textContent = ev.message_title || '-';
                var spanEl = document.createElement('span');
                spanEl.className = 'text-muted';
                spanEl.textContent = ev.message_body || '-';
                tdMsg.appendChild(strongEl);
                tdMsg.appendChild(spanEl);
                tr.appendChild(tdMsg);

                var tdStatus = document.createElement('td');
                var badge = document.createElement('span');
                badge.className = 'badge ' + getBadgeClass(ev.status);
                badge.textContent = ev.status;
                tdStatus.appendChild(badge);
                tr.appendChild(tdStatus);

                var tdCode = document.createElement('td');
                var codeRes = document.createElement('code');
                codeRes.textContent = ev.result_code || '-';
                tdCode.appendChild(codeRes);
                tr.appendChild(tdCode);

                tbody.appendChild(tr);
            });
        })
        .catch(function() {});
    }

    document.addEventListener('DOMContentLoaded', function() {
        var selectEl = document.getElementById('labStationSelect');
        if (selectEl) {
            selectEl.addEventListener('change', function() {
                currentStationId = String(this.value);
                updateStationSelectionUI();
                pollConsumerEvents();
                fetchAdminStationHistory();
            });
        }

        var refreshBtn = document.getElementById('btnRefreshLabHistory');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', function() {
                fetchAdminStationHistory();
            });
        }

        var btnExceed = document.getElementById('btnSimulateExceedVolume');
        if (btnExceed) {
            btnExceed.addEventListener('click', function() {
                var st = stationsMap[currentStationId];
                if (!st) return;
                renderSimulatedAudio(85, false);
                var limitCfg = st.volume_limit_config || { enabled: true, max_limit: 50 };
                if (limitCfg.enabled) {
                    enforceVolumeLimitInSimulator(85, limitCfg.max_limit, st);
                } else {
                    var fb = document.getElementById('simEnforcementFeedback');
                    if (fb) {
                        fb.className = 'small mt-1 text-warning';
                        fb.textContent = 'Volume diubah ke 85%. Proteksi batas volume saat ini NONAKTIF.';
                        setTimeout(function() { fb.textContent = ''; }, 3000);
                    }
                }
            });
        }

        var btnEnforceNow = document.getElementById('btnSimulateEnforceNow');
        if (btnEnforceNow) {
            btnEnforceNow.addEventListener('click', function() {
                var st = stationsMap[currentStationId];
                if (!st) return;
                var csrfMeta = document.querySelector('meta[name="csrf-token"]');
                var enforceUrl = '/admin/stations/' + st.id + '/tv-volume-limit';
                fetch(enforceUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrfMeta ? csrfMeta.getAttribute('content') : ''
                    },
                    body: JSON.stringify({
                        enabled: true,
                        max_volume: 50,
                        enforce_now: true
                    })
                })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    var fb = document.getElementById('simEnforcementFeedback');
                    if (fb) {
                        fb.className = 'small mt-1 text-info font-weight-bold';
                        fb.textContent = res.message || 'Pengaturan batas volume diperbarui via Enforce Now.';
                        setTimeout(function() { fb.textContent = ''; }, 4000);
                    }
                    pollConsumerEvents();
                    fetchAdminStationHistory();
                })
                .catch(function() {});
            });
        }

        updateStationSelectionUI();
        pollConsumerEvents();
        fetchAdminStationHistory();

        setInterval(pollConsumerEvents, 1800);
        setInterval(fetchAdminStationHistory, 3500);
    });
})();
</script>
@endsection
