@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <!-- Page Heading -->
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <div>
            <h1 class="h3 mb-0 text-gray-800">Station Hardware Mapping</h1>
            <p class="text-muted small mb-0">Pemetaan perangkat fisik (Samsung Smart TV / Shelly Smart Relay) ke stasiun billing PlayStation dan Meja Biliar.</p>
        </div>
        @if(!auth()->user()->isAdmin())
            <span class="badge badge-info py-2 px-3"><i class="fas fa-eye mr-1"></i> Mode Read-Only (Owner)</span>
        @endif
    </div>

    <!-- Dynamic Alerts Container -->
    <div id="alertContainer">
        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle mr-1"></i> {{ session('success') }}
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        @endif
        @if(session('error'))
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle mr-1"></i> {{ session('error') }}
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        @endif
    </div>

    <!-- Station Selection Bar -->
    <div class="card shadow mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.hardware.mappings') }}" id="stationSelectForm" class="form-row align-items-center">
                <div class="col-md-5 mb-2 mb-md-0">
                    <label for="station_select" class="font-weight-bold text-gray-800 mr-2 mb-0">
                        <i class="fas fa-tv mr-1 text-primary"></i> Pilih Stasiun Billing:
                    </label>
                </div>
                <div class="col-md-7">
                    <select name="station_id" id="station_select" class="form-control font-weight-bold" onchange="this.form.submit()">
                        @forelse($stations as $st)
                            @php
                                $stCategory = $st->getStationCategory() ?? 'Umum';
                                $hasHw = $st->activeHardwareMappings->isNotEmpty();
                            @endphp
                            <option value="{{ $st->id }}" {{ $selectedStation && $selectedStation->id === $st->id ? 'selected' : '' }}>
                                {{ $st->nama }} [{{ $stCategory }}] — Status: {{ $st->status }} {{ $hasHw ? '(Hardware Terpasang)' : '(Tanpa Hardware)' }}
                            </option>
                        @empty
                            <option value="">Tidak ada stasiun billing tersedia</option>
                        @endforelse
                    </select>
                </div>
            </form>
        </div>
    </div>

    @if($selectedStation)
        @php
            $stationCategory = $selectedStation->getStationCategory();
            $isOccupied = ($selectedStation->status === 'Digunakan');
        @endphp

        <!-- Station Info Banner -->
        <div class="row mb-4">
            <div class="col-lg-12">
                <div class="card border-left-{{ $isOccupied ? 'warning' : 'primary' }} shadow h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                    Informasi Stasiun Terpilih
                                </div>
                                <div class="h5 mb-1 font-weight-bold text-gray-800">
                                    {{ $selectedStation->nama }}
                                </div>
                                <div class="mb-2">
                                    <span class="mr-2">
                                        <strong>Kategori Layanan:</strong>
                                        @if($stationCategory === 'PLAYSTATION')
                                            <span class="badge badge-primary"><i class="fas fa-gamepad mr-1"></i>PLAYSTATION</span>
                                        @elseif($stationCategory === 'BILLIARD')
                                            <span class="badge badge-success"><i class="fas fa-circle mr-1"></i>BILLIARD</span>
                                        @else
                                            <span class="badge badge-secondary">TIDAK DIKETAHUI</span>
                                        @endif
                                    </span>
                                    <span>
                                        <strong>Status Billing:</strong>
                                        @if($isOccupied)
                                            <span class="badge badge-danger"><i class="fas fa-stopwatch mr-1"></i>DIGUNAKAN (BILLING AKTIF)</span>
                                        @else
                                            <span class="badge badge-success"><i class="fas fa-check-circle mr-1"></i>TERSEDIA</span>
                                        @endif
                                    </span>
                                </div>
                                <div class="small text-muted">
                                    <i class="fas fa-info-circle mr-1 text-info"></i>
                                    @if($stationCategory === 'PLAYSTATION')
                                        <strong>Aturan Hardware:</strong> Stasiun PlayStation hanya dapat dipasangkan dengan <strong>Samsung Smart TV</strong> (Role: <code>DISPLAY_TV</code>).
                                    @elseif($stationCategory === 'BILLIARD')
                                        <strong>Aturan Hardware:</strong> Stasiun Biliar hanya dapat dipasangkan dengan <strong>Shelly Smart Relay</strong> (Role: <code>LIGHT_RELAY</code>).
                                    @else
                                        <strong>Aturan Hardware:</strong> Kategori stasiun belum terdefinisi secara valid.
                                    @endif
                                    <span class="text-success ml-2"><em>*Stasiun tanpa hardware tetap dapat menjalankan transaksi billing manual.</em></span>
                                </div>
                                @if($isOccupied)
                                    <div class="alert alert-warning py-1 px-2 small mt-2 mb-0">
                                        <i class="fas fa-exclamation-triangle mr-1"></i>
                                        <strong>Proteksi Billing Aktif:</strong> Mutasi hardware (attach, replace, detach) ditolak oleh sistem selama sesi billing sedang berjalan di stasiun ini.
                                    </div>
                                @endif
                            </div>
                            <div class="col-auto">
                                <i class="fas {{ $stationCategory === 'BILLIARD' ? 'fa-bowling-ball' : 'fa-tv' }} fa-3x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Active Hardware Card -->
        <div class="card shadow mb-4">
            <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                <h6 class="m-0 font-weight-bold text-primary">
                    <i class="fas fa-microchip mr-1"></i> Hardware Aktif Saat Ini
                </h6>
                @if(auth()->user()->isAdmin() && $activeMappings->isEmpty())
                    <button type="button" class="btn btn-primary btn-sm shadow-sm" data-toggle="modal" data-target="#attachHardwareModal" {{ $isOccupied ? 'disabled' : '' }}>
                        <i class="fas fa-plus fa-sm text-white-50 mr-1"></i> Petakan Hardware (Attach)
                    </button>
                @endif
            </div>
            <div class="card-body">
                @if($activeMappings->isNotEmpty())
                    @foreach($activeMappings as $mapping)
                        @php
                            $pDev = $mapping->physicalDevice;
                        @endphp
                        <div class="border rounded p-3 bg-light mb-3">
                            <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3">
                                <div>
                                    <h5 class="font-weight-bold text-dark mb-1">
                                        {{ $pDev->name }}
                                        <span class="badge badge-info ml-1">{{ $mapping->mapping_role }}</span>
                                    </h5>
                                    <div class="text-muted small">
                                        Brand: <strong>{{ $pDev->brand }}</strong> | Model: <strong>{{ $pDev->model }}</strong> | Kategori: <strong>{{ $pDev->category }}</strong>
                                    </div>
                                </div>
                                <div class="mt-2 mt-md-0">
                                    @if(auth()->user()->isAdmin())
                                        <div class="btn-group" role="group">
                                            <button type="button" class="btn btn-warning btn-sm btn-open-replace" 
                                                data-mapping-id="{{ $mapping->id }}"
                                                data-device-id="{{ $pDev->id }}"
                                                data-device-name="{{ $pDev->name }}"
                                                data-role="{{ $mapping->mapping_role }}"
                                                {{ $isOccupied ? 'disabled title="Ditolak saat billing aktif"' : '' }}>
                                                <i class="fas fa-exchange-alt mr-1"></i> Ganti Hardware (Replace)
                                            </button>
                                            <button type="button" class="btn btn-danger btn-sm btn-open-detach" 
                                                data-mapping-id="{{ $mapping->id }}"
                                                data-device-id="{{ $pDev->id }}"
                                                data-device-name="{{ $pDev->name }}"
                                                {{ $isOccupied ? 'disabled title="Ditolak saat billing aktif"' : '' }}>
                                                <i class="fas fa-unlink mr-1"></i> Lepas Hardware (Detach)
                                            </button>
                                        </div>
                                    @endif
                                </div>
                            </div>
                            <div class="row small text-gray-800">
                                <div class="col-md-3 mb-2">
                                    <span class="text-muted d-block">IP Address:</span>
                                    <code class="font-weight-bold text-dark">{{ $pDev->ip_address }}</code>
                                </div>
                                <div class="col-md-3 mb-2">
                                    <span class="text-muted d-block">MAC Address:</span>
                                    <code class="font-weight-bold text-dark">{{ $pDev->mac_address }}</code>
                                </div>
                                <div class="col-md-3 mb-2">
                                    <span class="text-muted d-block">Status Koneksi:</span>
                                    @if($pDev->connection_status === 'ONLINE')
                                        <span class="badge badge-success">ONLINE</span>
                                    @elseif($pDev->connection_status === 'OFFLINE')
                                        <span class="badge badge-danger">OFFLINE</span>
                                    @else
                                        <span class="badge badge-warning text-dark">UNVERIFIED</span>
                                    @endif
                                </div>
                                <div class="col-md-3 mb-2">
                                    <span class="text-muted d-block">Status Pairing:</span>
                                    <span class="badge badge-secondary">{{ $pDev->pairing_status }}</span>
                                </div>
                            </div>
                            <hr class="my-2">
                            <div class="small text-muted d-flex flex-column flex-md-row justify-content-between">
                                <div>
                                    <i class="fas fa-user-check mr-1"></i> Dipasang oleh:
                                    <strong>{{ $mapping->attachedBy->nama ?? $mapping->attachedBy->username ?? 'Admin' }}</strong>
                                    pada {{ $mapping->attached_at ? $mapping->attached_at->format('d/m/Y H:i:s') : '—' }}
                                </div>
                                <div>
                                    <i class="fas fa-sticky-note mr-1"></i> Catatan: <em>{{ $mapping->attach_notes ?: 'Tidak ada catatan' }}</em>
                                </div>
                            </div>
                        </div>
                    @endforeach
                @else
                    <div class="text-center py-4">
                        <i class="fas fa-unlink fa-3x text-gray-300 mb-3 d-block"></i>
                        <h6 class="text-gray-700 font-weight-bold">Belum Ada Hardware Terpasang</h6>
                        <p class="text-muted small mb-3">Stasiun ini beroperasi dalam mode mandiri tanpa perangkat IoT terhubung. Sesi billing tetap dapat dijalankan normal oleh Kasir.</p>
                        @if(auth()->user()->isAdmin())
                            <button type="button" class="btn btn-primary btn-sm shadow-sm" data-toggle="modal" data-target="#attachHardwareModal" {{ $isOccupied ? 'disabled' : '' }}>
                                <i class="fas fa-plus fa-sm text-white-50 mr-1"></i> Petakan Hardware Sekarang
                            </button>
                        @endif
                    </div>
                @endif
            </div>
        </div>

        <!-- Mapping History (Audit Trail) Card -->
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">
                    <i class="fas fa-history mr-1"></i> Histori Pemetaan Stasiun (Audit Trail)
                </h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-striped mb-0 text-gray-900">
                        <thead class="thead-light">
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th>Perangkat</th>
                                <th>Role</th>
                                <th>Tanggal Pasang</th>
                                <th>Dipasang Oleh</th>
                                <th>Catatan Pasang</th>
                                <th>Tanggal Lepas</th>
                                <th>Dilepas Oleh</th>
                                <th>Alasan Lepas / Penggantian</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($historyMappings as $idx => $hist)
                                <tr>
                                    <td>{{ $idx + 1 }}</td>
                                    <td class="font-weight-bold">
                                        {{ $hist->physicalDevice ? $hist->physicalDevice->name : 'Perangkat Terhapus' }}
                                        @if($hist->physicalDevice)
                                            <div class="small text-muted">{{ $hist->physicalDevice->brand }} {{ $hist->physicalDevice->model }}</div>
                                        @endif
                                    </td>
                                    <td><span class="badge badge-secondary">{{ $hist->mapping_role }}</span></td>
                                    <td><small>{{ $hist->attached_at ? $hist->attached_at->format('d/m/Y H:i:s') : '—' }}</small></td>
                                    <td><small>{{ $hist->attachedBy->nama ?? $hist->attachedBy->username ?? 'Admin' }}</small></td>
                                    <td><small class="text-muted">{{ $hist->attach_notes ?: '—' }}</small></td>
                                    <td><small>{{ $hist->detached_at ? $hist->detached_at->format('d/m/Y H:i:s') : '—' }}</small></td>
                                    <td><small>{{ $hist->detachedBy ? ($hist->detachedBy->nama ?? $hist->detachedBy->username) : '—' }}</small></td>
                                    <td><small class="text-danger font-italic">{{ $hist->detach_notes ?: '—' }}</small></td>
                                    <td>
                                        @if($hist->mapping_status === 'ACTIVE')
                                            <span class="badge badge-success">ACTIVE</span>
                                        @else
                                            <span class="badge badge-light border">INACTIVE</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="10" class="text-center py-4 text-muted">
                                        <i class="fas fa-info-circle fa-2x mb-2 text-gray-400 d-block"></i>
                                        Belum ada rekaman histori pemetaan untuk stasiun ini.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer py-2 text-muted small">
                <i class="fas fa-shield-alt mr-1 text-primary"></i> Seluruh data histori pemetaan disimpan secara permanen untuk keperluan audit trail.
            </div>
        </div>

        @if(auth()->user()->isAdmin())
            <!-- Attach Hardware Modal -->
            <div class="modal fade" id="attachHardwareModal" tabindex="-1" role="dialog" aria-labelledby="attachHardwareModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-lg" role="document">
                    <div class="modal-content">
                        <form id="attachHardwareForm">
                            @csrf
                            <input type="hidden" name="station_id" id="attach_station_id" value="{{ $selectedStation->id }}">
                            <div class="modal-header bg-primary text-white">
                                <h5 class="modal-title" id="attachHardwareModalLabel">
                                    <i class="fas fa-plus-circle mr-1"></i> Petakan Hardware ke {{ $selectedStation->nama }}
                                </h5>
                                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                                    <span aria-hidden="true">&times;</span>
                                </button>
                            </div>
                            <div class="modal-body">
                                <div id="attachErrorAlert" class="alert alert-danger d-none"></div>

                                @php
                                    $availableDevices = ($stationCategory === 'BILLIARD')
                                        ? $availableRelays
                                        : ($availableTvs->merge($availableConsoles ?? collect()));
                                    $targetRole = ($stationCategory === 'BILLIARD') ? 'LIGHT_RELAY' : 'DISPLAY_TV';
                                @endphp

                                <div class="form-group">
                                    <label for="attach_device_id" class="font-weight-bold">
                                        Pilih Perangkat Fisik Yang Tersedia <span class="text-danger">*</span>
                                    </label>
                                    <select class="form-control" id="attach_device_id" name="physical_device_id" required>
                                        <option value="">-- Pilih Perangkat Kompatibel (Belum Terpetakan) --</option>
                                        @forelse($availableDevices as $ad)
                                            <option value="{{ $ad->id }}" data-category="{{ $ad->category }}">
                                                {{ $ad->name }} ({{ $ad->brand }} {{ $ad->category }} — IP: {{ $ad->ip_address }})
                                            </option>
                                        @empty
                                            <option value="" disabled>Tidak ada perangkat hardware yang belum terpetakan.</option>
                                        @endforelse
                                    </select>
                                    <small class="form-text text-muted">
                                        Hanya menampilkan hardware aktif yang kompatibel dengan jenis stasiun ini dan belum terpasang di stasiun manapun.
                                    </small>
                                </div>

                                <div class="form-group">
                                    <label for="attach_mapping_role" class="font-weight-bold">Peran Pemetaan (Mapping Role)</label>
                                    <input type="text" class="form-control bg-light font-weight-bold" id="attach_mapping_role" name="mapping_role" value="{{ $targetRole }}" readonly>
                                    <small class="form-text text-muted">
                                        Otomatis: Smart TV = <code>DISPLAY_TV</code>, Game Console = <code>GAME_CONSOLE</code>, Smart Relay = <code>LIGHT_RELAY</code>.
                                    </small>
                                </div>

                                <div class="form-group">
                                    <label for="attach_notes" class="font-weight-bold">Catatan Pemasangan <span class="text-muted">(Opsional)</span></label>
                                    <textarea class="form-control" id="attach_notes" name="attach_notes" rows="2" placeholder="Misal: Pemasangan TV baru Meja 1 oleh Teknisi"></textarea>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                                <button type="submit" class="btn btn-primary" id="btnSubmitAttach" {{ $availableDevices->isEmpty() ? 'disabled' : '' }}>
                                    <span class="spinner-border spinner-border-sm d-none mr-1" role="status" aria-hidden="true"></span>
                                    Pasang Hardware
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Replace Hardware Modal -->
            <div class="modal fade" id="replaceHardwareModal" tabindex="-1" role="dialog" aria-labelledby="replaceHardwareModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-lg" role="document">
                    <div class="modal-content">
                        <form id="replaceHardwareForm">
                            @csrf
                            <input type="hidden" name="station_id" id="replace_station_id" value="{{ $selectedStation->id }}">
                            <input type="hidden" name="mapping_role" id="replace_mapping_role" value="">
                            <input type="hidden" name="replace" value="true">
                            <div class="modal-header bg-warning text-dark">
                                <h5 class="modal-title font-weight-bold" id="replaceHardwareModalLabel">
                                    <i class="fas fa-exchange-alt mr-1"></i> Ganti Hardware Stasiun (Atomic Replace)
                                </h5>
                                <button type="button" class="close text-dark" data-dismiss="modal" aria-label="Close">
                                    <span aria-hidden="true">&times;</span>
                                </button>
                            </div>
                            <div class="modal-body">
                                <div id="replaceErrorAlert" class="alert alert-danger d-none"></div>

                                <div class="alert alert-light border">
                                    <small class="text-muted d-block">Perangkat Yang Sedang Aktif:</small>
                                    <strong id="replace_current_device_name" class="text-dark"></strong>
                                </div>

                                <div class="form-group">
                                    <label for="replace_device_id" class="font-weight-bold">
                                        Pilih Perangkat Pengganti <span class="text-danger">*</span>
                                    </label>
                                    <select class="form-control" id="replace_device_id" name="physical_device_id" required>
                                        <option value="">-- Pilih Perangkat Pengganti --</option>
                                        @forelse($availableDevices as $ad)
                                            <option value="{{ $ad->id }}">
                                                {{ $ad->name }} ({{ $ad->brand }} {{ $ad->model }} — IP: {{ $ad->ip_address }} — MAC: {{ $ad->mac_address }})
                                            </option>
                                        @empty
                                            <option value="" disabled>Tidak ada perangkat pengganti yang tersedia saat ini.</option>
                                        @endforelse
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label for="replace_detach_notes" class="font-weight-bold text-danger">
                                        Alasan Pergantian Hardware (Wajib) <span class="text-danger">*</span>
                                    </label>
                                    <textarea class="form-control" id="replace_detach_notes" name="detach_notes" rows="2" placeholder="Contoh: TV lama rusak/diservis, kabel HDMI bermasalah, pergantian tipe relay..." required></textarea>
                                    <small class="form-text text-muted">Alasan ini akan dicatat ke dalam audit trail mapping lama secara permanen.</small>
                                </div>

                                <div class="form-group">
                                    <label for="replace_attach_notes" class="font-weight-bold">Catatan Pemasangan Pengganti <span class="text-muted">(Opsional)</span></label>
                                    <textarea class="form-control" id="replace_attach_notes" name="attach_notes" rows="2" placeholder="Contoh: Menggunakan unit cadangan rak 2"></textarea>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                                <button type="submit" class="btn btn-warning font-weight-bold text-dark" id="btnSubmitReplace" {{ $availableDevices->isEmpty() ? 'disabled' : '' }}>
                                    <span class="spinner-border spinner-border-sm d-none mr-1" role="status" aria-hidden="true"></span>
                                    Konfirmasi Ganti Hardware
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Detach Hardware Modal -->
            <div class="modal fade" id="detachHardwareModal" tabindex="-1" role="dialog" aria-labelledby="detachHardwareModalLabel" aria-hidden="true">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <form id="detachHardwareForm">
                            @csrf
                            <input type="hidden" name="mapping_id" id="detach_mapping_id" value="">
                            <div class="modal-header bg-danger text-white">
                                <h5 class="modal-title" id="detachHardwareModalLabel">
                                    <i class="fas fa-unlink mr-1"></i> Konfirmasi Pelepasan Hardware
                                </h5>
                                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                                    <span aria-hidden="true">&times;</span>
                                </button>
                            </div>
                            <div class="modal-body">
                                <div id="detachErrorAlert" class="alert alert-danger d-none"></div>

                                <p>Apakah Anda yakin ingin melepas perangkat fisik berikut dari stasiun <strong>{{ $selectedStation->nama }}</strong>?</p>
                                <div class="alert alert-light border">
                                    <strong id="detach_device_name" class="text-dark"></strong>
                                </div>

                                <div class="form-group">
                                    <label for="detach_notes" class="font-weight-bold text-danger">
                                        Alasan Pelepasan Hardware <span class="text-danger">*</span>
                                    </label>
                                    <textarea class="form-control" id="detach_notes" name="detach_notes" rows="3" placeholder="Contoh: Perawatan stasiun, perbaikan kelistrikan, stasiun beralih ke mode manual..." required></textarea>
                                    <small class="form-text text-muted">Alasan pelepasan dan identitas Anda sebagai Admin akan dicatat ke riwayat audit.</small>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                                <button type="submit" class="btn btn-danger" id="btnSubmitDetach">
                                    <span class="spinner-border spinner-border-sm d-none mr-1" role="status" aria-hidden="true"></span>
                                    Lepas Hardware
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endif
    @endif
</div>
@endsection

@section('scripts')
<script>
$(document).ready(function() {
    // CSRF & JSON Header Setup
    $.ajaxSetup({
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content'),
            'Accept': 'application/json'
        }
    });

    // Helper: Display Alert
    function showNotification(type, message) {
        var alertHtml = '<div class="alert alert-' + type + ' alert-dismissible fade show" role="alert">' +
            (type === 'success' ? '<i class="fas fa-check-circle mr-1"></i> ' : '<i class="fas fa-exclamation-triangle mr-1"></i> ') +
            message +
            '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>' +
            '</div>';
        $('#alertContainer').html(alertHtml);
        $('html, body').animate({ scrollTop: $('#alertContainer').offset().top - 20 }, 300);
    }

    // Dynamic role mapping on device selection
    $('#attach_device_id').on('change', function() {
        var cat = $(this).find('option:selected').data('category');
        if (cat === 'GAME_CONSOLE') {
            $('#attach_mapping_role').val('GAME_CONSOLE');
        } else if (cat === 'SMART_TV') {
            $('#attach_mapping_role').val('DISPLAY_TV');
        } else if (cat === 'SMART_RELAY') {
            $('#attach_mapping_role').val('LIGHT_RELAY');
        }
    });

    // --- 1. SUBMIT ATTACH HARDWARE ---
    $('#attachHardwareForm').on('submit', function(e) {
        e.preventDefault();
        $('#attachErrorAlert').addClass('d-none').text('');

        var stationId = $('#attach_station_id').val();
        var $btn = $('#btnSubmitAttach');
        $btn.prop('disabled', true);
        $btn.find('.spinner-border').removeClass('d-none');

        var payload = {
            physical_device_id: parseInt($('#attach_device_id').val(), 10),
            mapping_role: $('#attach_mapping_role').val(),
            attach_notes: $('#attach_notes').val()
        };

        $.ajax({
            url: '/admin/stations/' + stationId + '/mappings',
            type: 'POST',
            data: JSON.stringify(payload),
            contentType: 'application/json',
            success: function(response) {
                $('#attachHardwareModal').modal('hide');
                showNotification('success', response.message || 'Hardware berhasil dipetakan ke stasiun.');
                setTimeout(function() { window.location.reload(); }, 800);
            },
            error: function(xhr) {
                $btn.prop('disabled', false);
                $btn.find('.spinner-border').addClass('d-none');

                var msg = 'Gagal memasang hardware.';
                if (xhr.status === 409 && xhr.responseJSON) {
                    msg = xhr.responseJSON.message || 'Konflik pemetaan atau billing stasiun sedang aktif.';
                } else if (xhr.status === 422 && xhr.responseJSON) {
                    msg = xhr.responseJSON.message || 'Validasi gagal: periksa kecocokan jenis stasiun dan hardware.';
                } else if (xhr.status === 403) {
                    msg = 'Akses ditolak: Anda tidak memiliki wewenang untuk aksi ini.';
                } else if (xhr.responseJSON && xhr.responseJSON.message) {
                    msg = xhr.responseJSON.message;
                }
                $('#attachErrorAlert').removeClass('d-none').text(msg);
            }
        });
    });

    // --- 2. OPEN REPLACE MODAL ---
    $('.btn-open-replace').on('click', function() {
        var mappingId = $(this).data('mapping-id');
        var deviceName = $(this).data('device-name');
        var role = $(this).data('role');

        $('#replace_current_device_name').text(deviceName);
        $('#replace_mapping_role').val(role);
        $('#replace_detach_notes').val('');
        $('#replace_attach_notes').val('');
        $('#replaceErrorAlert').addClass('d-none').text('');

        $('#replaceHardwareModal').modal('show');
    });

    // --- 3. SUBMIT REPLACE HARDWARE ---
    $('#replaceHardwareForm').on('submit', function(e) {
        e.preventDefault();
        $('#replaceErrorAlert').addClass('d-none').text('');

        var stationId = $('#replace_station_id').val();
        var $btn = $('#btnSubmitReplace');
        $btn.prop('disabled', true);
        $btn.find('.spinner-border').removeClass('d-none');

        var payload = {
            physical_device_id: parseInt($('#replace_device_id').val(), 10),
            mapping_role: $('#replace_mapping_role').val(),
            replace: true,
            detach_notes: $('#replace_detach_notes').val(),
            attach_notes: $('#replace_attach_notes').val()
        };

        $.ajax({
            url: '/admin/stations/' + stationId + '/mappings',
            type: 'POST',
            data: JSON.stringify(payload),
            contentType: 'application/json',
            success: function(response) {
                $('#replaceHardwareModal').modal('hide');
                showNotification('success', response.message || 'Hardware stasiun berhasil diganti.');
                setTimeout(function() { window.location.reload(); }, 800);
            },
            error: function(xhr) {
                $btn.prop('disabled', false);
                $btn.find('.spinner-border').addClass('d-none');

                var msg = 'Gagal mengganti hardware.';
                if (xhr.status === 409 && xhr.responseJSON) {
                    msg = xhr.responseJSON.message || 'Konflik pemetaan atau billing stasiun sedang aktif.';
                } else if (xhr.status === 422 && xhr.responseJSON) {
                    msg = xhr.responseJSON.message || 'Validasi gagal: alasan pergantian wajib diisi.';
                } else if (xhr.status === 403) {
                    msg = 'Akses ditolak: Anda tidak memiliki wewenang untuk aksi ini.';
                } else if (xhr.responseJSON && xhr.responseJSON.message) {
                    msg = xhr.responseJSON.message;
                }
                $('#replaceErrorAlert').removeClass('d-none').text(msg);
            }
        });
    });

    // --- 4. OPEN DETACH MODAL ---
    $('.btn-open-detach').on('click', function() {
        var mappingId = $(this).data('mapping-id');
        var deviceName = $(this).data('device-name');

        $('#detach_mapping_id').val(mappingId);
        $('#detach_device_name').text(deviceName);
        $('#detach_notes').val('');
        $('#detachErrorAlert').addClass('d-none').text('');

        $('#detachHardwareModal').modal('show');
    });

    // --- 5. SUBMIT DETACH HARDWARE ---
    $('#detachHardwareForm').on('submit', function(e) {
        e.preventDefault();
        $('#detachErrorAlert').addClass('d-none').text('');

        var stationId = '{{ $selectedStation ? $selectedStation->id : 0 }}';
        var mappingId = $('#detach_mapping_id').val();
        var $btn = $('#btnSubmitDetach');
        $btn.prop('disabled', true);
        $btn.find('.spinner-border').removeClass('d-none');

        var payload = {
            detach_notes: $('#detach_notes').val()
        };

        $.ajax({
            url: '/admin/stations/' + stationId + '/mappings/' + mappingId + '/detach',
            type: 'POST',
            data: JSON.stringify(payload),
            contentType: 'application/json',
            success: function(response) {
                $('#detachHardwareModal').modal('hide');
                showNotification('success', response.message || 'Hardware berhasil dilepas dari stasiun.');
                setTimeout(function() { window.location.reload(); }, 800);
            },
            error: function(xhr) {
                $btn.prop('disabled', false);
                $btn.find('.spinner-border').addClass('d-none');

                var msg = 'Gagal melepas hardware.';
                if (xhr.status === 409 && xhr.responseJSON) {
                    msg = xhr.responseJSON.message || 'Konflik: stasiun sedang aktif billing.';
                } else if (xhr.status === 422 && xhr.responseJSON) {
                    msg = xhr.responseJSON.message || 'Catatan alasan pelepasan wajib diisi.';
                } else if (xhr.status === 403) {
                    msg = 'Akses ditolak: Anda tidak memiliki wewenang untuk aksi ini.';
                } else if (xhr.responseJSON && xhr.responseJSON.message) {
                    msg = xhr.responseJSON.message;
                }
                $('#detachErrorAlert').removeClass('d-none').text(msg);
            }
        });
    });
});
</script>
@endsection
