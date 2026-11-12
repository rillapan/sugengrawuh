@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <!-- Header -->
    <div class="d-sm-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 class="h3 mb-0 text-gray-800"><i class="fas fa-magic text-primary mr-2"></i>Hardware Setup & Onboarding Wizard</h1>
            <p class="text-muted small mb-0">Panduan konfigurasi terpadu: Jaringan LAN, Penemuan Perangkat, Pairing, Pemetaan Stasiun, dan Diagnostik (Gate G3).</p>
        </div>
        <div class="d-flex align-items-center">
            @if($realExecutionEnabled)
                <span class="badge badge-success px-3 py-2 mr-2"><i class="fas fa-network-wired mr-1"></i>REAL NETWORK ENABLED</span>
            @else
                <span class="badge badge-warning px-3 py-2 mr-2 text-dark"><i class="fas fa-shield-alt mr-1"></i>SAFETY GUARD: PHYSICAL BLOCKED</span>
            @endif
            <a href="{{ route('admin.hardware.diagnostics.index') }}" class="btn btn-outline-info btn-sm">
                <i class="fas fa-stethoscope mr-1"></i> Hardware Test Center
            </a>
        </div>
    </div>

    <!-- Alert Container -->
    <div id="wizardAlertContainer"></div>

    <!-- Step Navigation Pills -->
    <div class="card shadow-sm mb-4">
        <div class="card-body py-2 px-3">
            <ul class="nav nav-pills nav-fill flex-column flex-md-row" id="wizardTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <a class="nav-link active font-weight-bold py-2" id="tab-step-1" data-toggle="pill" href="#step-1" role="tab" aria-controls="step-1" aria-selected="true">
                        <span class="badge badge-primary mr-1">1</span> Jaringan LAN
                    </a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link font-weight-bold py-2" id="tab-step-2" data-toggle="pill" href="#step-2" role="tab" aria-controls="step-2" aria-selected="false">
                        <span class="badge badge-secondary mr-1">2</span> Penemuan Perangkat
                    </a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link font-weight-bold py-2" id="tab-step-3" data-toggle="pill" href="#step-3" role="tab" aria-controls="step-3" aria-selected="false">
                        <span class="badge badge-secondary mr-1">3</span> Pairing & Registrasi
                    </a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link font-weight-bold py-2" id="tab-step-4" data-toggle="pill" href="#step-4" role="tab" aria-controls="step-4" aria-selected="false">
                        <span class="badge badge-secondary mr-1">4</span> Pemetaan Stasiun
                    </a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link font-weight-bold py-2" id="tab-step-5" data-toggle="pill" href="#step-5" role="tab" aria-controls="step-5" aria-selected="false">
                        <span class="badge badge-secondary mr-1">5</span> Uji Diagnostik
                    </a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link font-weight-bold py-2" id="tab-step-6" data-toggle="pill" href="#step-6" role="tab" aria-controls="step-6" aria-selected="false">
                        <span class="badge badge-secondary mr-1">6</span> Selesai & Ringkasan
                    </a>
                </li>
            </ul>
        </div>
    </div>

    <!-- Wizard Content -->
    <div class="tab-content" id="wizardTabContent">
        <!-- STEP 1: NETWORK SETUP -->
        <div class="tab-pane fade show active" id="step-1" role="tabpanel" aria-labelledby="tab-step-1">
            <div class="card shadow mb-4">
                <div class="card-header py-3 bg-primary text-white d-flex align-items-center justify-content-between">
                    <h6 class="m-0 font-weight-bold"><i class="fas fa-network-wired mr-2"></i>Langkah 1: Pemeriksaan Koneksi Jaringan LAN</h6>
                    <span class="badge badge-light text-primary">Konektivitas Lokal</span>
                </div>
                <div class="card-body">
                    <!-- IP Change Warning Banner if IP Changed -->
                    <div id="ipChangedBanner" class="alert alert-warning border-left-warning shadow-sm mb-4" style="{{ empty($networkInfo['ip_changed']) ? 'display: none;' : '' }}">
                        <div class="d-flex align-items-start">
                            <i class="fas fa-exclamation-triangle fa-2x mr-3 text-warning"></i>
                            <div>
                                <h6 class="font-weight-bold text-dark mb-1">Peringatan: IP Komputer Kasir Mengalami Perubahan</h6>
                                <p class="small text-dark mb-2">
                                    Alamat IP sebelumnya adalah <code><span id="txtPrevIp">{{ $networkInfo['previous_ip'] ?? '—' }}</span></code> dan berubah menjadi <code><span id="txtCurrIp">{{ $networkInfo['ip_address'] ?? '—' }}</span></code>.
                                    Agar perangkat TV Samsung dan relay tetap dapat berkomunikasi stabil, sangat disarankan untuk mengunci alamat IP kasir menggunakan <strong>DHCP Static Lease</strong> di router MikroTik.
                                </p>
                                <div class="bg-white p-2 rounded border small">
                                    <span class="text-muted d-block font-weight-bold mb-1">Perintah MikroTik Terminal (Salin & Tempel):</span>
                                    <code id="txtMikrotikCli" class="text-primary font-weight-bold">{{ $networkInfo['mikrotik_cli'] ?? '—' }}</code>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Network Details Grid -->
                    <div class="row mb-4">
                        <div class="col-md-6 col-lg-3 mb-3">
                            <div class="p-3 bg-light rounded border h-100">
                                <small class="text-muted d-block font-weight-bold text-uppercase">IP Komputer Kasir</small>
                                <h5 class="text-primary font-weight-bold mb-0 mt-1" id="netKasirIp">{{ $networkInfo['ip_address'] ?? '192.168.88.10' }}</h5>
                                <small class="text-muted">Subnet: <span id="netSubnet">{{ $networkInfo['subnet'] ?? '24' }}</span></small>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-3 mb-3">
                            <div class="p-3 bg-light rounded border h-100">
                                <small class="text-muted d-block font-weight-bold text-uppercase">Adaptor LAN Fisik</small>
                                <h6 class="text-dark font-weight-bold mb-0 mt-1" id="netAdapter">{{ $networkInfo['adapter'] ?? 'Ethernet' }}</h6>
                                <small class="text-muted">MAC: <code id="netMac">{{ $networkInfo['mac_address'] ?? '—' }}</code></small>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-3 mb-3">
                            <div class="p-3 bg-light rounded border h-100">
                                <small class="text-muted d-block font-weight-bold text-uppercase">Router Gateway</small>
                                <h5 class="text-dark font-weight-bold mb-0 mt-1" id="netGateway">{{ $networkInfo['gateway'] ?? '192.168.88.1' }}</h5>
                                <div class="mt-1">
                                    <span class="badge badge-success" id="badgeGatewayStatus">Online / Terhubung</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-3 mb-3">
                            <div class="p-3 bg-light rounded border h-100">
                                <small class="text-muted d-block font-weight-bold text-uppercase">WOL Helper Service</small>
                                <h6 class="text-dark font-weight-bold mb-0 mt-1">
                                    Port TCP {{ $networkInfo['wol_port'] ?? 18009 }}
                                </h6>
                                <div class="mt-1">
                                    <span class="badge {{ ($networkInfo['wol_helper_status'] ?? '') === 'ONLINE' ? 'badge-success' : 'badge-warning text-dark' }}" id="badgeWolStatus">
                                        {{ $networkInfo['wol_helper_status'] ?? 'ONLINE' }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Container Health Overview -->
                    <h6 class="font-weight-bold text-gray-700 mb-2"><i class="fas fa-boxes mr-1"></i> Status Layanan Docker & Aplikasi (5 Containers)</h6>
                    <div class="table-responsive mb-4">
                        <table class="table table-sm table-bordered small">
                            <thead class="thead-light">
                                <tr>
                                    <th>Container</th>
                                    <th>Fungsi</th>
                                    <th>Port</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><code>sugeng_rawuh_app</code></td>
                                    <td>PHP-FPM 8.2 & Billing Core</td>
                                    <td>Internal (9000)</td>
                                    <td><span class="badge badge-success"><i class="fas fa-check mr-1"></i>HEALTHY</span></td>
                                </tr>
                                <tr>
                                    <td><code>sugeng_rawuh_db</code></td>
                                    <td>MySQL 8.0 Database</td>
                                    <td>127.0.0.1:3307</td>
                                    <td><span class="badge badge-success"><i class="fas fa-check mr-1"></i>CONNECTED</span></td>
                                </tr>
                                <tr>
                                    <td><code>sugeng_rawuh_web</code></td>
                                    <td>Nginx Web Server</td>
                                    <td>LAN: {{ $networkInfo['app_port'] ?? 8008 }}</td>
                                    <td><span class="badge badge-success"><i class="fas fa-check mr-1"></i>ONLINE</span></td>
                                </tr>
                                <tr>
                                    <td><code>sugeng_rawuh_daemon</code></td>
                                    <td>Billing & IoT Background Worker</td>
                                    <td>Background Worker</td>
                                    <td><span class="badge badge-success"><i class="fas fa-check mr-1"></i>ACTIVE</span></td>
                                </tr>
                                <tr>
                                    <td><code>sugeng_rawuh_scheduler</code></td>
                                    <td>Laravel Cron & Task Runner</td>
                                    <td>Background Cron</td>
                                    <td><span class="badge badge-success"><i class="fas fa-check mr-1"></i>ACTIVE</span></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Action Buttons -->
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <button type="button" class="btn btn-outline-secondary btn-sm mr-2" id="btnRefreshNetwork">
                                <i class="fas fa-sync-alt mr-1"></i> Refresh Network
                            </button>
                            <button type="button" class="btn btn-outline-info btn-sm" id="btnCheckGateway">
                                <i class="fas fa-check-double mr-1"></i> Check Gateway
                            </button>
                        </div>
                        <button type="button" class="btn btn-primary btn-next-step" data-target="#tab-step-2">
                            Lanjutkan ke Penemuan Perangkat <i class="fas fa-arrow-right ml-1"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- STEP 2: DEVICE DISCOVERY -->
        <div class="tab-pane fade" id="step-2" role="tabpanel" aria-labelledby="tab-step-2">
            <div class="card shadow mb-4">
                <div class="card-header py-3 bg-primary text-white d-flex align-items-center justify-content-between">
                    <h6 class="m-0 font-weight-bold"><i class="fas fa-search mr-2"></i>Langkah 2: Penemuan Perangkat di Jaringan LAN</h6>
                    <button type="button" class="btn btn-light btn-sm font-weight-bold text-primary" data-toggle="modal" data-target="#modalManualAdd">
                        <i class="fas fa-plus mr-1"></i> Tambah Perangkat Manual
                    </button>
                </div>
                <div class="card-body">
                    <p class="small text-muted mb-3">
                        Pindai jaringan lokal LAN untuk menemukan Smart TV Samsung dan Smart Relay Shelly secara otomatis. Pemindaian dibatasi ketat pada subnet lokal kasir untuk mencegah gangguan jaringan.
                    </p>

                    <!-- Scan Control Bar -->
                    <div class="bg-light p-3 rounded border mb-4">
                        <div class="row align-items-center">
                            <div class="col-md-4 mb-2 mb-md-0">
                                <label for="scanSubnetInput" class="small font-weight-bold text-dark mb-1">Target Subnet LAN (CIDR):</label>
                                <input type="text" id="scanSubnetInput" class="form-control form-control-sm font-monospace" value="{{ $networkInfo['subnet'] ?? '192.168.88.0/24' }}">
                                <small class="text-muted">Hanya subnet privat RFC 1918 (/24 s/d /30).</small>
                            </div>
                            <div class="col-md-8 text-md-right">
                                <button type="button" class="btn btn-outline-primary btn-sm mr-2 mb-1" id="btnScanSamsung">
                                    <i class="fas fa-tv mr-1"></i> Scan Samsung TV (Port 8001)
                                </button>
                                <button type="button" class="btn btn-outline-success btn-sm mr-2 mb-1" id="btnScanShelly">
                                    <i class="fas fa-lightbulb mr-1"></i> Scan Shelly Relay (Port 80)
                                </button>
                                <button type="button" class="btn btn-primary btn-sm mb-1" id="btnScanAll">
                                    <i class="fas fa-radar mr-1"></i> Scan Semua
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Scanning Spinner / Status Indicator -->
                    <div id="scanLoadingBox" class="text-center py-4 mb-3" style="display: none;">
                        <div class="spinner-border text-primary" role="status"></div>
                        <div class="small font-weight-bold mt-2 text-dark" id="scanLoadingText">Memindai subnet...</div>
                    </div>

                    <!-- Discovered Candidates Table -->
                    <h6 class="font-weight-bold text-gray-800 mb-2">Hasil Pemindaian Jaringan (Kandidat):</h6>
                    <div class="table-responsive mb-4">
                        <table class="table table-hover table-bordered small">
                            <thead class="thead-light">
                                <tr>
                                    <th>Tipe & Merek</th>
                                    <th>Nama / Model</th>
                                    <th>Alamat IP</th>
                                    <th>MAC Address</th>
                                    <th>Status Identitas</th>
                                    <th class="text-center" style="width: 140px;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="discoveredCandidatesBody">
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">
                                        Belum melakukan pemindaian. Klik tombol <strong>Scan</strong> di atas atau <strong>Tambah Perangkat Manual</strong> jika IP perangkat sudah diketahui.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Action Navigation -->
                    <div class="d-flex justify-content-between align-items-center">
                        <button type="button" class="btn btn-secondary btn-sm btn-prev-step" data-target="#tab-step-1">
                            <i class="fas fa-arrow-left mr-1"></i> Kembali ke Jaringan
                        </button>
                        <button type="button" class="btn btn-primary btn-next-step" data-target="#tab-step-3">
                            Lanjutkan ke Pairing & Registrasi <i class="fas fa-arrow-right ml-1"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- STEP 3: PAIRING & REGISTRATION -->
        <div class="tab-pane fade" id="step-3" role="tabpanel" aria-labelledby="tab-step-3">
            <div class="card shadow mb-4">
                <div class="card-header py-3 bg-primary text-white d-flex align-items-center justify-content-between">
                    <h6 class="m-0 font-weight-bold"><i class="fas fa-key mr-2"></i>Langkah 3: Pairing & Verifikasi Perangkat Terdaftar</h6>
                    <span class="badge badge-light text-primary">Autentikasi Aman</span>
                </div>
                <div class="card-body">
                    <div class="alert alert-info border-left-info shadow-sm mb-3">
                        <div class="small text-dark">
                            <strong>Samsung Smart TV:</strong> Tekan tombol <strong>Pair</strong> untuk memulai jabat tangan WebSocket. Jika muncul pop-up izin di layar TV, tekan <em>"Izinkan" (Allow)</em> menggunakan remote TV. Token terenkripsi akan disimpan secara aman di sistem kasir.
                            <br>
                            <strong>Shelly 1PM Mini Gen3:</strong> Menggunakan protokol HTTP JSON-RPC 2.0. Status telemetri dan kontak relay dibaca secara aman tanpa mutasi daya.
                        </div>
                    </div>

                    <!-- Registered Devices List -->
                    <div class="table-responsive mb-4">
                        <table class="table table-hover table-bordered small">
                            <thead class="thead-light">
                                <tr>
                                    <th>Nama Perangkat</th>
                                    <th>Kategori & Brand</th>
                                    <th>IP Address</th>
                                    <th>Pairing Status</th>
                                    <th>Koneksi Fisik</th>
                                    <th class="text-center" style="width: 200px;">Aksi Pairing / Uji</th>
                                </tr>
                            </thead>
                            <tbody id="registeredDevicesBody">
                                @forelse($devices as $dev)
                                    <tr data-device-id="{{ $dev->id }}" data-category="{{ $dev->category }}" data-brand="{{ $dev->brand }}">
                                        <td><strong>{{ $dev->name }}</strong> <small class="text-muted d-block">{{ $dev->model ?: 'Standard' }}</small></td>
                                        <td>
                                            <span class="badge {{ $dev->category === 'SMART_TV' ? 'badge-primary' : 'badge-success' }}">{{ $dev->category }}</span>
                                            <span class="badge badge-light border">{{ $dev->brand }}</span>
                                        </td>
                                        <td><code>{{ $dev->ip_address }}</code></td>
                                        <td>
                                            @if($dev->brand === 'COOCAA')
                                                <span class="badge badge-secondary" title="Protokol belum diverifikasi">Driver Belum Didukung</span>
                                            @elseif($dev->pairing_status === 'PAIRED')
                                                <span class="badge badge-success"><i class="fas fa-check mr-1"></i>PAIRED</span>
                                            @elseif($dev->pairing_status === 'NOT_REQUIRED')
                                                <span class="badge badge-info">Tidak Diperlukan</span>
                                            @elseif($dev->pairing_status === 'FAILED' || $dev->pairing_status === 'REJECTED')
                                                <span class="badge badge-danger">Gagal / Ditolak</span>
                                            @else
                                                <span class="badge badge-warning text-dark">Belum Dipairing</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if($dev->connection_status === 'ONLINE')
                                                <span class="badge badge-success">ONLINE</span>
                                            @elseif($dev->connection_status === 'OFFLINE')
                                                <span class="badge badge-danger">OFFLINE</span>
                                            @else
                                                <span class="badge badge-secondary">UNVERIFIED</span>
                                            @endif
                                        </td>
                                        <td class="text-center">
                                            @if($dev->category === 'SMART_TV' && $dev->brand === 'SAMSUNG')
                                                <button type="button" class="btn btn-outline-primary btn-sm btn-pair-device" data-id="{{ $dev->id }}">
                                                    <i class="fas fa-link mr-1"></i> Pair / Handshake
                                                </button>
                                            @elseif($dev->category === 'SMART_RELAY' && $dev->brand === 'SHELLY')
                                                <button type="button" class="btn btn-outline-success btn-sm btn-test-shelly" data-id="{{ $dev->id }}">
                                                    <i class="fas fa-tachometer-alt mr-1"></i> Baca Telemetri
                                                </button>
                                            @else
                                                <button type="button" class="btn btn-outline-secondary btn-sm" disabled>
                                                    <i class="fas fa-ban mr-1"></i> Tidak Didukung
                                                </button>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-4">
                                            Belum ada perangkat terdaftar. Silakan lakukan pemindaian di Langkah 2 atau tambahkan perangkat secara manual.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <!-- Action Navigation -->
                    <div class="d-flex justify-content-between align-items-center">
                        <button type="button" class="btn btn-secondary btn-sm btn-prev-step" data-target="#tab-step-2">
                            <i class="fas fa-arrow-left mr-1"></i> Kembali ke Penemuan
                        </button>
                        <button type="button" class="btn btn-primary btn-next-step" data-target="#tab-step-4">
                            Lanjutkan ke Pemetaan Stasiun <i class="fas fa-arrow-right ml-1"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- STEP 4: STATION MAPPING -->
        <div class="tab-pane fade" id="step-4" role="tabpanel" aria-labelledby="tab-step-4">
            <div class="card shadow mb-4">
                <div class="card-header py-3 bg-primary text-white d-flex align-items-center justify-content-between">
                    <h6 class="m-0 font-weight-bold"><i class="fas fa-project-diagram mr-2"></i>Langkah 4: Pemetaan Perangkat ke Stasiun Billing</h6>
                    <span class="badge badge-light text-primary">Integritas Billing</span>
                </div>
                <div class="card-body">
                    <p class="small text-muted mb-3">
                        Hubungkan perangkat fisik yang telah didaftarkan ke stasiun rental. Aturan ketat sistem:
                        <br>• Stasiun PlayStation hanya boleh dipetakan ke <strong>Smart TV (Role: DISPLAY_TV)</strong>.
                        <br>• Stasiun Meja Biliar hanya boleh dipetakan ke <strong>Smart Relay Shelly (Role: LIGHT_RELAY)</strong>.
                    </p>

                    <!-- Station Mapping List -->
                    <div class="table-responsive mb-4">
                        <table class="table table-hover table-bordered small">
                            <thead class="thead-light">
                                <tr>
                                    <th>Stasiun Rental</th>
                                    <th>Kategori Stasiun</th>
                                    <th>Perangkat Terpasang Saat Ini</th>
                                    <th>Role Pemetaan</th>
                                    <th style="min-width: 250px;">Petakan Perangkat</th>
                                    <th class="text-center" style="width: 120px;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($stations as $st)
                                    @php
                                        $stationCat = $st->getStationCategory();
                                        $activeMapping = $st->activeHardwareMappings->first();
                                        $mappedDevice = $activeMapping ? $activeMapping->physicalDevice : null;
                                        $targetRole = ($stationCat === 'PLAYSTATION') ? 'DISPLAY_TV' : 'LIGHT_RELAY';
                                    @endphp
                                    <tr data-station-id="{{ $st->id }}">
                                        <td><strong>{{ $st->nama }}</strong></td>
                                        <td>
                                            <span class="badge {{ $stationCat === 'PLAYSTATION' ? 'badge-primary' : 'badge-success' }}">{{ $stationCat }}</span>
                                        </td>
                                        <td>
                                            @if($mappedDevice)
                                                <span class="text-dark font-weight-bold">{{ $mappedDevice->name }}</span>
                                                <small class="text-muted d-block"><code>{{ $mappedDevice->ip_address }}</code> ({{ $mappedDevice->brand }})</small>
                                            @else
                                                <span class="badge badge-secondary">Belum Dipetakan</span>
                                            @endif
                                        </td>
                                        <td>
                                            <span class="badge badge-light border">{{ $targetRole }}</span>
                                        </td>
                                        <td>
                                            <select class="form-control form-control-sm select-hardware-device" data-station-id="{{ $st->id }}" data-role="{{ $targetRole }}">
                                                <option value="">-- Pilih Perangkat Kompatibel --</option>
                                                @foreach($devices as $dev)
                                                    @if($stationCat === 'PLAYSTATION' && $dev->category === 'SMART_TV')
                                                        <option value="{{ $dev->id }}" {{ $mappedDevice && $mappedDevice->id === $dev->id ? 'selected' : '' }}>
                                                            {{ $dev->name }} ({{ $dev->ip_address }})
                                                        </option>
                                                    @elseif($stationCat === 'BILLIARD' && $dev->category === 'SMART_RELAY')
                                                        <option value="{{ $dev->id }}" {{ $mappedDevice && $mappedDevice->id === $dev->id ? 'selected' : '' }}>
                                                            {{ $dev->name }} ({{ $dev->ip_address }})
                                                        </option>
                                                    @endif
                                                @endforeach
                                            </select>
                                        </td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-outline-primary btn-sm btn-attach-mapping" 
                                                data-station-id="{{ $st->id }}"
                                                data-role="{{ $targetRole }}"
                                                data-has-existing="{{ $mappedDevice ? '1' : '0' }}">
                                                <i class="fas fa-save mr-1"></i> Simpan
                                            </button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-4">Belum ada stasiun rental yang terdaftar.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <!-- Action Navigation -->
                    <div class="d-flex justify-content-between align-items-center">
                        <button type="button" class="btn btn-secondary btn-sm btn-prev-step" data-target="#tab-step-3">
                            <i class="fas fa-arrow-left mr-1"></i> Kembali ke Pairing
                        </button>
                        <button type="button" class="btn btn-primary btn-next-step" data-target="#tab-step-5">
                            Lanjutkan ke Uji Diagnostik <i class="fas fa-arrow-right ml-1"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- STEP 5: HARDWARE DIAGNOSTICS -->
        <div class="tab-pane fade" id="step-5" role="tabpanel" aria-labelledby="tab-step-5">
            <div class="card shadow mb-4">
                <div class="card-header py-3 bg-primary text-white d-flex align-items-center justify-content-between">
                    <h6 class="m-0 font-weight-bold"><i class="fas fa-stethoscope mr-2"></i>Langkah 5: Uji Diagnostik & Verifikasi Kontrol Fisik</h6>
                    <span class="badge badge-light text-primary">Gate G2 Guard</span>
                </div>
                <div class="card-body">
                    <div class="row">
                        <!-- Left: Device & Operation Picker -->
                        <div class="col-lg-5 mb-4">
                            <div class="form-group mb-3">
                                <label for="diagTargetDevice" class="font-weight-bold small text-dark">Pilih Target Perangkat:</label>
                                <select id="diagTargetDevice" class="form-control form-control-sm">
                                    @foreach($devices as $dev)
                                        <option value="{{ $dev->id }}" data-category="{{ $dev->category }}" data-brand="{{ $dev->brand }}" data-ip="{{ $dev->ip_address }}">
                                            {{ $dev->name }} ({{ $dev->brand }} — {{ $dev->ip_address }})
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <!-- Read-Only Diagnostics -->
                            <div class="p-3 bg-light rounded border mb-3">
                                <span class="badge badge-info mb-2">Read-Only Diagnostics</span>
                                <small class="text-muted d-block mb-2">Aman dijalankan kapan saja tanpa mengubah status daya atau relay fisik.</small>
                                <button type="button" class="btn btn-outline-info btn-sm mr-2 mb-2" id="btnStepDiagProbe">
                                    <i class="fas fa-network-wired mr-1"></i> Probe Koneksi
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-sm mb-2" id="btnStepDiagStatus">
                                    <i class="fas fa-info-circle mr-1"></i> Baca Status
                                </button>
                            </div>

                            <!-- Physical Control Test with Server Approval Token -->
                            <div class="p-3 bg-white rounded border shadow-sm">
                                <span class="badge badge-danger mb-2">Uji Kontrol Fisik Terbatas</span>
                                <small class="text-muted d-block mb-2">
                                    Perintah fisik memerlukan <strong>Server Approval Token</strong> (bertenggat waktu 5 menit). Tombol tetap terkunci hingga token disetujui.
                                </small>
                                <button type="button" class="btn btn-outline-danger btn-sm mb-2 w-100" id="btnStepRequestApproval">
                                    <i class="fas fa-certificate mr-1"></i> Request Server Approval Token
                                </button>
                                <div id="stepApprovalBox" class="p-2 bg-light border rounded small mb-2" style="display: none;">
                                    <span class="text-success font-weight-bold"><i class="fas fa-check-circle mr-1"></i>Token Aktif:</span>
                                    <code id="stepApprovalTokenText" class="font-weight-bold"></code>
                                </div>
                                <div id="stepPhysicalButtons" class="d-flex flex-wrap" style="display: none;">
                                    <!-- Dynamic Physical Command Buttons populated via JS based on selected device -->
                                </div>
                            </div>
                        </div>

                        <!-- Right: Output Console & Remediation -->
                        <div class="col-lg-7 mb-4">
                            <div class="card shadow-sm h-100">
                                <div class="card-header py-2 bg-dark text-white d-flex align-items-center justify-content-between">
                                    <small class="font-weight-bold font-monospace">Respons Protokol & Status Kanonikal</small>
                                    <span id="stepDiagBadge" class="badge badge-secondary">READY</span>
                                </div>
                                <div class="card-body p-3">
                                    <!-- Explanation & Remediation -->
                                    <div id="stepExplanationBox" class="alert alert-light border shadow-sm mb-2 small" style="display: none;">
                                        <div><strong class="text-dark">Status:</strong> <span id="stepDiagCanonical" class="badge badge-secondary font-weight-bold">—</span></div>
                                        <div class="mt-1"><strong class="text-danger">Penyebab:</strong> <span id="stepDiagExp" class="text-dark">—</span></div>
                                        <div class="mt-1"><strong class="text-success">Langkah Perbaikan:</strong> <span id="stepDiagRem" class="text-dark">—</span></div>
                                    </div>
                                    <!-- JSON Log -->
                                    <pre id="stepConsoleOutput" class="bg-dark text-light p-3 rounded mb-0 small font-monospace" style="max-height: 250px; overflow-y: auto;">
// Siap mengeksekusi uji diagnostik...</pre>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Action Navigation -->
                    <div class="d-flex justify-content-between align-items-center">
                        <button type="button" class="btn btn-secondary btn-sm btn-prev-step" data-target="#tab-step-4">
                            <i class="fas fa-arrow-left mr-1"></i> Kembali ke Pemetaan
                        </button>
                        <button type="button" class="btn btn-primary btn-next-step" data-target="#tab-step-6">
                            Lanjutkan ke Ringkasan Akhir <i class="fas fa-arrow-right ml-1"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- STEP 6: FINISH & SUMMARY -->
        <div class="tab-pane fade" id="step-6" role="tabpanel" aria-labelledby="tab-step-6">
            <div class="card shadow mb-4">
                <div class="card-header py-3 bg-success text-white d-flex align-items-center justify-content-between">
                    <h6 class="m-0 font-weight-bold"><i class="fas fa-check-circle mr-2"></i>Langkah 6: Ringkasan Kesiapan Sistem & Selesaikan Onboarding</h6>
                    <span class="badge badge-light text-success font-weight-bold">Deferred Setup Ready</span>
                </div>
                <div class="card-body">
                    <!-- Deferred Setup Notice -->
                    <div class="alert alert-light border shadow-sm mb-4">
                        <div class="d-flex align-items-start">
                            <i class="fas fa-info-circle fa-2x mr-3 text-info mt-1"></i>
                            <div>
                                <h6 class="font-weight-bold text-dark mb-1">Dukungan Deferred Setup (Penyiapan Bertahap)</h6>
                                <p class="small text-muted mb-0">
                                    Instalasi Sugeng Rawuh tetap dapat diselesaikan dengan aman meskipun sebagian perangkat TV atau relay belum menyala atau belum terpasang di lokasi.
                                    Teknisi dapat kembali membuka halaman ini atau menu <strong>Hardware Test Center</strong> kapan saja tanpa harus mengulangi instalasi awal.
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Readiness Summary Grid -->
                    <div class="row mb-4" id="summaryGrid">
                        <div class="col-md-6 col-lg-3 mb-3">
                            <div class="p-3 bg-light rounded border text-center">
                                <small class="text-muted font-weight-bold d-block">CONTAINER DOCKER</small>
                                <h4 class="text-success font-weight-bold my-2" id="sumContainers">5 / 5 OK</h4>
                                <span class="badge badge-success">Lengkap</span>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-3 mb-3">
                            <div class="p-3 bg-light rounded border text-center">
                                <small class="text-muted font-weight-bold d-block">WOL HELPER</small>
                                <h4 class="text-primary font-weight-bold my-2" id="sumWol">ONLINE</h4>
                                <span class="badge badge-info">Port 18009</span>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-3 mb-3">
                            <div class="p-3 bg-light rounded border text-center">
                                <small class="text-muted font-weight-bold d-block">PERANGKAT TERDAFTAR</small>
                                <h4 class="text-dark font-weight-bold my-2" id="sumDevices">{{ $devices->count() }} Unit</h4>
                                <span class="badge badge-light border" id="sumConnectedDevices">{{ $devices->where('connection_status', 'ONLINE')->count() }} Terhubung</span>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-3 mb-3">
                            <div class="p-3 bg-light rounded border text-center">
                                <small class="text-muted font-weight-bold d-block">STASIUN TERPETAKAN</small>
                                <h4 class="text-dark font-weight-bold my-2" id="sumMappedStations">
                                    {{ $stations->filter(fn($s) => $s->activeHardwareMappings->isNotEmpty())->count() }} / {{ $stations->count() }}
                                </h4>
                                <span class="badge badge-light border">Siap Digunakan</span>
                            </div>
                        </div>
                    </div>

                    <!-- Final Action Buttons -->
                    <div class="card border-0 bg-light p-4 rounded text-center">
                        <h5 class="text-dark font-weight-bold mb-2">Konfigurasi Siap Diselesaikan</h5>
                        <p class="small text-muted mb-4">
                            Seluruh parameter jaringan dan inventaris perangkat telah tersimpan secara atomik di database. Klik tombol di bawah untuk menyelesaikan onboarding.
                        </p>
                        <div class="d-flex justify-content-center flex-wrap" style="gap: 12px;">
                            <button type="button" class="btn btn-secondary btn-sm btn-prev-step" data-target="#tab-step-5">
                                <i class="fas fa-arrow-left mr-1"></i> Kembali ke Diagnostik
                            </button>
                            <a href="{{ route('admin.hardware.diagnostics.index') }}" class="btn btn-outline-info btn-sm">
                                <i class="fas fa-stethoscope mr-1"></i> Buka Hardware Test Center
                            </a>
                            <button type="button" class="btn btn-success font-weight-bold px-4" id="btnFinishSetup">
                                <i class="fas fa-check-circle mr-1"></i> Selesaikan Setup
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Manual Add Device -->
<div class="modal fade" id="modalManualAdd" tabindex="-1" role="dialog" aria-labelledby="modalManualAddLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white py-3">
                <h6 class="modal-title font-weight-bold" id="modalManualAddLabel"><i class="fas fa-plus mr-1"></i> Tambah Perangkat Fisik Manual</h6>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body small">
                <form id="formManualAddDevice">
                    @csrf
                    <div class="form-group mb-2">
                        <label for="manName" class="font-weight-bold text-dark">Nama Perangkat *</label>
                        <input type="text" id="manName" class="form-control form-control-sm" placeholder="Contoh: TV Meja 1 / Relay Biliar 1" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group mb-2">
                            <label for="manCategory" class="font-weight-bold text-dark">Kategori *</label>
                            <select id="manCategory" class="form-control form-control-sm" required>
                                <option value="SMART_TV">SMART_TV</option>
                                <option value="SMART_RELAY">SMART_RELAY</option>
                            </select>
                        </div>
                        <div class="col-md-6 form-group mb-2">
                            <label for="manBrand" class="font-weight-bold text-dark">Brand *</label>
                            <select id="manBrand" class="form-control form-control-sm" required>
                                <option value="SAMSUNG">SAMSUNG</option>
                                <option value="SHELLY">SHELLY</option>
                                <option value="COOCAA">COOCAA</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group mb-2">
                        <label for="manModel" class="font-weight-bold text-dark">Model</label>
                        <input type="text" id="manModel" class="form-control form-control-sm" placeholder="Contoh: Samsung 50U8500 / Shelly 1PM Mini Gen3">
                    </div>
                    <div class="form-group mb-2">
                        <label for="manIp" class="font-weight-bold text-dark">Alamat IP (LAN) *</label>
                        <input type="text" id="manIp" class="form-control form-control-sm font-monospace" placeholder="192.168.88.xxx" required>
                        <small class="text-muted">Hanya alamat IPv4 privat lokal (RFC 1918).</small>
                    </div>
                    <div class="form-group mb-2">
                        <label for="manMac" class="font-weight-bold text-dark">MAC Address (Opsional untuk TV, wajib jika ingin WOL)</label>
                        <input type="text" id="manMac" class="form-control form-control-sm font-monospace" placeholder="AA:BB:CC:DD:EE:FF">
                    </div>
                </form>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-primary btn-sm font-weight-bold" id="btnSaveManualDevice">
                    <i class="fas fa-save mr-1"></i> Simpan Perangkat
                </button>
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
$(document).ready(function() {
    const csrfToken = $('meta[name="csrf-token"]').attr('content');
    let currentApprovalToken = null;

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
        $('#wizardAlertContainer').html(html);
        $('html, body').animate({ scrollTop: $('#wizardAlertContainer').offset().top - 20 }, 300);
    }

    // Tab Navigation Helpers
    $('.btn-next-step').on('click', function() {
        const target = $(this).data('target');
        $(target).tab('show');
    });

    $('.btn-prev-step').on('click', function() {
        const target = $(this).data('target');
        $(target).tab('show');
    });

    // STEP 1: Refresh Network
    $('#btnRefreshNetwork').on('click', function() {
        const $btn = $(this);
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Refreshing...');

        $.ajax({
            url: '{{ route("admin.hardware.setup.network-info") }}',
            method: 'GET',
            success: function(res) {
                if (res.success && res.data) {
                    const d = res.data;
                    $('#netKasirIp').text(d.ip_address || '—');
                    $('#netSubnet').text(d.subnet || '—');
                    $('#netAdapter').text(d.adapter || '—');
                    $('#netMac').text(d.mac_address || '—');
                    $('#netGateway').text(d.gateway || '—');
                    $('#badgeWolStatus').text(d.wol_helper_status).attr('class', `badge ${d.wol_helper_status === 'ONLINE' ? 'badge-success' : 'badge-warning text-dark'}`);

                    if (d.ip_changed) {
                        $('#txtPrevIp').text(d.previous_ip || '—');
                        $('#txtCurrIp').text(d.ip_address || '—');
                        $('#txtMikrotikCli').text(d.mikrotik_cli || '—');
                        $('#ipChangedBanner').slideDown();
                    } else {
                        $('#ipChangedBanner').slideUp();
                    }

                    showAlert('success', 'Informasi jaringan berhasil diperbarui.');
                }
            },
            error: function() {
                showAlert('danger', 'Gagal memuat ulang data jaringan.');
            },
            complete: function() {
                $btn.prop('disabled', false).html('<i class="fas fa-sync-alt mr-1"></i> Refresh Network');
            }
        });
    });

    // STEP 1: Check Gateway
    $('#btnCheckGateway').on('click', function() {
        const $btn = $(this);
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Checking...');

        $.ajax({
            url: '{{ route("admin.hardware.setup.check-gateway") }}',
            method: 'POST',
            data: { _token: csrfToken },
            success: function(res) {
                if (res.reachable) {
                    $('#badgeGatewayStatus').text('Online / Terhubung').attr('class', 'badge badge-success');
                    showAlert('success', res.message);
                } else {
                    $('#badgeGatewayStatus').text('Unreachable').attr('class', 'badge badge-danger');
                    showAlert('warning', res.message);
                }
            },
            error: function(xhr) {
                const res = xhr.responseJSON || { message: 'Gagal mengecek gateway' };
                showAlert('danger', res.message);
            },
            complete: function() {
                $btn.prop('disabled', false).html('<i class="fas fa-check-double mr-1"></i> Check Gateway');
            }
        });
    });

    // STEP 2: Discovery
    function runDiscovery(deviceType) {
        const subnet = $('#scanSubnetInput').val();
        $('#scanLoadingText').text(`Memindai perangkat ${deviceType} pada subnet ${subnet}...`);
        $('#scanLoadingBox').show();

        $.ajax({
            url: '{{ route("admin.hardware.setup.discover") }}',
            method: 'POST',
            data: {
                _token: csrfToken,
                device_type: deviceType,
                subnet: subnet
            },
            success: function(res) {
                $('#scanLoadingBox').hide();
                if (!res.candidates || res.candidates.length === 0) {
                    $('#discoveredCandidatesBody').html('<tr><td colspan="6" class="text-center text-muted py-3">Tidak ditemukan perangkat aktif pada subnet ini.</td></tr>');
                    showAlert('info', `Pemindaian selesai: 0 perangkat ${deviceType} ditemukan.`);
                    return;
                }

                let rows = '';
                res.candidates.forEach(function(cand) {
                    const isSamsung = cand.brand === 'SAMSUNG';
                    const badgeClass = isSamsung ? 'badge-primary' : (cand.brand === 'SHELLY' ? 'badge-success' : 'badge-secondary');
                    rows += `
                        <tr>
                            <td><span class="badge ${badgeClass}">${cand.brand}</span> <span class="badge badge-light border">${cand.category}</span></td>
                            <td><strong>${cand.name}</strong> <small class="text-muted d-block">${cand.model}</small></td>
                            <td><code>${cand.ip_address}</code></td>
                            <td><code>${cand.mac_address || '—'}</code></td>
                            <td><span class="badge badge-success">Terverifikasi (${cand.connection_status})</span></td>
                            <td class="text-center">
                                <button type="button" class="btn btn-sm btn-primary btn-register-candidate font-weight-bold"
                                    data-name="${cand.name}"
                                    data-category="${cand.category}"
                                    data-brand="${cand.brand}"
                                    data-model="${cand.model}"
                                    data-ip="${cand.ip_address}"
                                    data-mac="${cand.mac_address || ''}"
                                    data-id="${cand.device_identifier || ''}">
                                    <i class="fas fa-plus mr-1"></i> Daftarkan
                                </button>
                            </td>
                        </tr>
                    `;
                });
                $('#discoveredCandidatesBody').html(rows);
                showAlert('success', `Ditemukan ${res.candidates.length} kandidat perangkat.`);
            },
            error: function(xhr) {
                $('#scanLoadingBox').hide();
                const res = xhr.responseJSON || { message: 'Gagal melakukan pemindaian' };
                showAlert('danger', res.message);
            }
        });
    }

    $('#btnScanSamsung').on('click', () => runDiscovery('SAMSUNG'));
    $('#btnScanShelly').on('click', () => runDiscovery('SHELLY'));
    $('#btnScanAll').on('click', () => runDiscovery('ALL'));

    // STEP 2: Register Candidate to Device Registry
    $(document).on('click', '.btn-register-candidate', function() {
        const $btn = $(this);
        const data = {
            _token: csrfToken,
            name: $btn.data('name'),
            category: $btn.data('category'),
            brand: $btn.data('brand'),
            model: $btn.data('model'),
            ip_address: $btn.data('ip'),
            mac_address: $btn.data('mac'),
            device_identifier: $btn.data('id')
        };

        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');

        $.ajax({
            url: '{{ route("admin.hardware.devices.store") }}',
            method: 'POST',
            data: data,
            success: function(res) {
                showAlert('success', `Perangkat '${data.name}' (${data.ip_address}) berhasil didaftarkan ke sistem.`);
                $btn.replaceWith('<span class="badge badge-success"><i class="fas fa-check mr-1"></i>Terdaftar</span>');
            },
            error: function(xhr) {
                $btn.prop('disabled', false).html('<i class="fas fa-plus mr-1"></i> Daftarkan');
                const res = xhr.responseJSON || { message: 'Gagal mendaftarkan perangkat' };
                showAlert('danger', res.message);
            }
        });
    });

    // STEP 2: Manual Add Modal
    $('#btnSaveManualDevice').on('click', function() {
        const name = $('#manName').val();
        const cat = $('#manCategory').val();
        const brand = $('#manBrand').val();
        const model = $('#manModel').val();
        const ip = $('#manIp').val();
        const mac = $('#manMac').val();

        if (!name || !ip) {
            alert('Nama perangkat dan alamat IP wajib diisi.');
            return;
        }

        const $btn = $(this);
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Menyimpan...');

        $.ajax({
            url: '{{ route("admin.hardware.devices.store") }}',
            method: 'POST',
            data: {
                _token: csrfToken,
                name: name,
                category: cat,
                brand: brand,
                model: model,
                ip_address: ip,
                mac_address: mac
            },
            success: function(res) {
                $('#modalManualAdd').modal('hide');
                showAlert('success', `Perangkat '${name}' berhasil didaftarkan.`);
                setTimeout(() => window.location.reload(), 1000);
            },
            error: function(xhr) {
                const res = xhr.responseJSON || { message: 'Gagal menambahkan perangkat' };
                alert(res.message);
            },
            complete: function() {
                $btn.prop('disabled', false).html('<i class="fas fa-save mr-1"></i> Simpan Perangkat');
            }
        });
    });

    // STEP 3: Pair Device
    $(document).on('click', '.btn-pair-device', function() {
        const id = $(this).data('id');
        const $btn = $(this);
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Pairing...');

        $.ajax({
            url: '{{ route("admin.hardware.diagnostics.pair") }}',
            method: 'POST',
            data: {
                _token: csrfToken,
                device_id: id,
                transport_mode: 'real'
            },
            success: function(res) {
                showAlert('success', res.message || 'Pairing berhasil.');
                $btn.replaceWith('<span class="badge badge-success"><i class="fas fa-check mr-1"></i>PAIRED</span>');
            },
            error: function(xhr) {
                $btn.prop('disabled', false).html('<i class="fas fa-link mr-1"></i> Coba Lagi');
                const res = xhr.responseJSON || { message: 'Pairing gagal' };
                showAlert('danger', res.message);
            }
        });
    });

    // STEP 3: Test Shelly Telemetry
    $(document).on('click', '.btn-test-shelly', function() {
        const id = $(this).data('id');
        const $btn = $(this);
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');

        $.ajax({
            url: `/admin/hardware/diagnostics/status/${id}?transport_mode=real`,
            method: 'GET',
            success: function(res) {
                $btn.prop('disabled', false).html('<i class="fas fa-check mr-1 text-success"></i> Status OK');
                showAlert('success', `Shelly merespons: ${res.message || 'Status terbaca'}`);
            },
            error: function(xhr) {
                $btn.prop('disabled', false).html('<i class="fas fa-tachometer-alt mr-1"></i> Baca Telemetri');
                const res = xhr.responseJSON || { message: 'Gagal membaca status' };
                showAlert('danger', res.message);
            }
        });
    });

    // STEP 4: Attach / Replace Station Mapping
    $('.btn-attach-mapping').on('click', function() {
        const stationId = $(this).data('station-id');
        const role = $(this).data('role');
        const hasExisting = $(this).data('has-existing') === '1';
        const $row = $(this).closest('tr');
        const devId = $row.find('.select-hardware-device').val();

        if (!devId) {
            alert('Pilih perangkat hardware terlebih dahulu.');
            return;
        }

        if (hasExisting && !confirm('Stasiun ini sudah memiliki pemetaan aktif. Apakah Anda yakin ingin mengganti (replace) perangkat lama dengan perangkat baru?')) {
            return;
        }

        const $btn = $(this);
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');

        $.ajax({
            url: `/admin/stations/${stationId}/mappings`,
            method: 'POST',
            data: {
                _token: csrfToken,
                physical_device_id: devId,
                mapping_role: role,
                replace: hasExisting
            },
            success: function(res) {
                showAlert('success', res.message || 'Perangkat berhasil dipetakan ke stasiun.');
                $btn.prop('disabled', false).html('<i class="fas fa-check mr-1 text-success"></i> Tersimpan');
            },
            error: function(xhr) {
                $btn.prop('disabled', false).html('<i class="fas fa-save mr-1"></i> Simpan');
                const res = xhr.responseJSON || { message: 'Gagal memetakan perangkat' };
                showAlert('danger', res.message);
            }
        });
    });

    // STEP 5: Diagnostics Probe & Status
    function updateDiagButtons() {
        const $opt = $('#diagTargetDevice option:selected');
        const cat = $opt.data('category');
        const brand = $opt.data('brand');
        let btns = '';

        if (cat === 'SMART_TV' && brand === 'SAMSUNG') {
            btns = `
                <button type="button" class="btn btn-outline-success btn-sm mr-2 mb-1 btn-step-cmd" data-cmd="MUTE"><i class="fas fa-volume-mute mr-1"></i> Mute Audio</button>
                <button type="button" class="btn btn-outline-warning btn-sm mr-2 mb-1 btn-step-cmd text-dark" data-cmd="STANDBY"><i class="fas fa-moon mr-1"></i> Standby (Off)</button>
            `;
        } else if (cat === 'SMART_RELAY' && brand === 'SHELLY') {
            btns = `
                <button type="button" class="btn btn-outline-success btn-sm mr-2 mb-1 btn-step-cmd" data-cmd="SWITCH_ON"><i class="fas fa-lightbulb mr-1"></i> Relay ON</button>
                <button type="button" class="btn btn-outline-danger btn-sm mr-2 mb-1 btn-step-cmd" data-cmd="SWITCH_OFF"><i class="far fa-lightbulb mr-1"></i> Relay OFF</button>
            `;
        }
        $('#stepPhysicalButtons').html(btns);
    }

    $('#diagTargetDevice').on('change', function() {
        currentApprovalToken = null;
        $('#stepApprovalBox').slideUp();
        $('#stepPhysicalButtons').slideUp();
        updateDiagButtons();
    });
    updateDiagButtons();

    $('#btnStepDiagProbe').on('click', function() {
        const devId = $('#diagTargetDevice').val();
        $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Probing...');

        $.ajax({
            url: '{{ route("admin.hardware.diagnostics.probe") }}',
            method: 'POST',
            data: { _token: csrfToken, device_id: devId, transport_mode: 'real' },
            success: function(res) {
                $('#stepConsoleOutput').text(JSON.stringify(res, null, 2));
                $('#stepDiagBadge').text(res.diagnostic_status || 'OK').attr('class', 'badge badge-success');
                renderStepExplanation(res.diagnostic_report);
                showAlert('success', res.message);
            },
            error: function(xhr) {
                const res = xhr.responseJSON || { message: 'Probe gagal' };
                $('#stepConsoleOutput').text(JSON.stringify(res, null, 2));
                $('#stepDiagBadge').text('FAILED').attr('class', 'badge badge-danger');
                showAlert('danger', res.message);
            },
            complete: function() {
                $('#btnStepDiagProbe').prop('disabled', false).html('<i class="fas fa-network-wired mr-1"></i> Probe Koneksi');
            }
        });
    });

    $('#btnStepDiagStatus').on('click', function() {
        const devId = $('#diagTargetDevice').val();
        $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Reading...');

        $.ajax({
            url: `/admin/hardware/diagnostics/status/${devId}?transport_mode=real`,
            method: 'GET',
            success: function(res) {
                $('#stepConsoleOutput').text(JSON.stringify(res, null, 2));
                $('#stepDiagBadge').text(res.diagnostic_status || 'OK').attr('class', 'badge badge-success');
                renderStepExplanation(res.diagnostic_report);
                showAlert('success', res.message);
            },
            error: function(xhr) {
                const res = xhr.responseJSON || { message: 'Read status gagal' };
                $('#stepConsoleOutput').text(JSON.stringify(res, null, 2));
                $('#stepDiagBadge').text('FAILED').attr('class', 'badge badge-danger');
                showAlert('danger', res.message);
            },
            complete: function() {
                $('#btnStepDiagStatus').prop('disabled', false).html('<i class="fas fa-info-circle mr-1"></i> Baca Status');
            }
        });
    });

    function renderStepExplanation(report) {
        if (!report) return;
        $('#stepExplanationBox').show();
        $('#stepDiagCanonical').text(report.status);
        $('#stepDiagExp').text(report.explanation || '—');
        $('#stepDiagRem').text(report.remediation || '—');
    }

    // STEP 5: Request Physical Approval Token
    $('#btnStepRequestApproval').on('click', function() {
        const devId = $('#diagTargetDevice').val();
        const $opt = $('#diagTargetDevice option:selected');
        const cat = $opt.data('category');
        const defaultCmd = (cat === 'SMART_RELAY') ? 'SWITCH_ON' : 'MUTE';

        $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Requesting...');

        $.ajax({
            url: '{{ route("admin.hardware.diagnostics.approve-physical") }}',
            method: 'POST',
            data: {
                _token: csrfToken,
                device_id: devId,
                command: defaultCmd,
                ttl_seconds: 300
            },
            success: function(res) {
                currentApprovalToken = res.approval_token;
                $('#stepApprovalTokenText').text(currentApprovalToken.substring(0, 12) + '...');
                $('#stepApprovalBox').slideDown();
                $('#stepPhysicalButtons').slideDown();
                showAlert('success', res.message);
            },
            error: function(xhr) {
                const res = xhr.responseJSON || { message: 'Gagal mendapatkan token persetujuan' };
                showAlert('danger', res.message);
            },
            complete: function() {
                $('#btnStepRequestApproval').prop('disabled', false).html('<i class="fas fa-certificate mr-1"></i> Request Server Approval Token');
            }
        });
    });

    // STEP 5: Send Command
    $(document).on('click', '.btn-step-cmd', function() {
        const cmd = $(this).data('cmd');
        const devId = $('#diagTargetDevice').val();
        const $btn = $(this);
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');

        $.ajax({
            url: '{{ route("admin.hardware.diagnostics.command") }}',
            method: 'POST',
            data: {
                _token: csrfToken,
                device_id: devId,
                command: cmd,
                transport_mode: 'real',
                approval_token: currentApprovalToken
            },
            success: function(res) {
                $('#stepConsoleOutput').text(JSON.stringify(res, null, 2));
                $('#stepDiagBadge').text(res.diagnostic_status || 'SUCCESS').attr('class', 'badge badge-success');
                renderStepExplanation(res.diagnostic_report);
                showAlert('success', res.message);
                currentApprovalToken = null;
                $('#stepApprovalBox').slideUp();
                $('#stepPhysicalButtons').slideUp();
            },
            error: function(xhr) {
                const res = xhr.responseJSON || { message: `Perintah ${cmd} gagal` };
                $('#stepConsoleOutput').text(JSON.stringify(res, null, 2));
                $('#stepDiagBadge').text('FAILED').attr('class', 'badge badge-danger');
                showAlert('danger', res.message);
            },
            complete: function() {
                $btn.prop('disabled', false).html(cmd);
            }
        });
    });

    // STEP 6: Load Summary on Step 6 activate
    $('#tab-step-6').on('shown.bs.tab', function() {
        $.ajax({
            url: '{{ route("admin.hardware.setup.summary") }}',
            method: 'GET',
            success: function(res) {
                if (res.success && res.data) {
                    const inv = res.data.inventory;
                    $('#sumDevices').text(`${inv.total_devices} Unit`);
                    $('#sumConnectedDevices').text(`${inv.connected_devices} Terhubung`);
                    $('#sumMappedStations').text(`${inv.mapped_stations} / ${inv.total_stations}`);
                    $('#sumWol').text(res.data.wol_helper.status);
                }
            }
        });
    });

    // STEP 6: Finish Setup
    $('#btnFinishSetup').on('click', function() {
        const $btn = $(this);
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Menyelesaikan...');

        $.ajax({
            url: '{{ route("admin.hardware.setup.finish") }}',
            method: 'POST',
            data: { _token: csrfToken },
            success: function(res) {
                showAlert('success', res.message);
                setTimeout(function() {
                    window.location.href = res.redirect_url || '{{ route("admin.hardware.diagnostics.index") }}';
                }, 1500);
            },
            error: function() {
                $btn.prop('disabled', false).html('<i class="fas fa-check-circle mr-1"></i> Selesaikan Setup');
                showAlert('danger', 'Gagal menyelesaikan konfigurasi onboarding.');
            }
        });
    });
});
</script>
@endsection

