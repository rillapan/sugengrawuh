@extends('layouts.app')

@section('content')
<style>
    /* Scoped UI Improvements for Device Registry */
    .device-registry-wrapper {
        width: 100%;
    }
    .registry-header-title {
        font-size: 1.65rem;
        font-weight: 700;
        letter-spacing: -0.02em;
        color: #2e384d;
    }
    .registry-header-subtitle {
        font-size: 0.95rem;
        color: #6c757d;
    }
    .card-registry {
        border: 1px solid #e3e6f0;
        border-radius: 10px;
        box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.08);
        overflow: hidden;
    }
    .card-registry .card-header {
        background-color: #ffffff;
        border-bottom: 1px solid #eaecf4;
        padding: 14px 20px;
    }
    .filter-input {
        height: 38px !important;
        border-radius: 6px;
        font-size: 0.875rem;
        border-color: #d1d3e2;
    }
    .filter-input:focus {
        border-color: #4e73df;
        box-shadow: 0 0 0 0.2rem rgba(78, 115, 223, 0.15);
    }
    .filter-label {
        font-size: 0.8rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #4e5e7a;
        margin-bottom: 6px;
        display: flex;
        align-items: center;
    }
    .filter-btn {
        height: 38px;
        border-radius: 6px;
        font-weight: 600;
        font-size: 0.875rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
    /* Modern 7-Column Responsive Table */
    .table-registry {
        margin-bottom: 0;
        width: 100%;
        table-layout: auto;
    }
    .table-registry thead th {
        background-color: #f8f9fc !important;
        color: #4e73df;
        font-size: 0.8rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.6px;
        border-top: none;
        border-bottom: 2px solid #e3e6f0;
        padding: 11px 10px;
        vertical-align: middle;
        white-space: nowrap;
    }
    .table-registry tbody td {
        padding: 11px 10px;
        font-size: 0.9rem;
        vertical-align: middle;
        border-top: 1px solid #eaecf4;
    }
    .table-registry tbody tr:hover {
        background-color: #f8faff;
    }
    .device-avatar {
        width: 38px;
        height: 38px;
        border-radius: 8px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    .device-avatar-tv {
        background-color: #e8f0fe;
        color: #1a73e8;
    }
    .device-avatar-relay {
        background-color: #e0f2fe;
        color: #0284c7;
    }
    .device-primary-name {
        font-size: 0.95rem;
        font-weight: 700;
        color: #1a202c;
        line-height: 1.35;
        word-break: break-word;
    }
    .badge-modern {
        border-radius: 6px;
        padding: 4px 8px;
        font-weight: 600;
        font-size: 0.75rem;
        letter-spacing: 0.3px;
        display: inline-flex;
        align-items: center;
    }
    .font-monospace-code {
        font-family: SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    }
    .net-box {
        display: flex;
        flex-direction: column;
        gap: 3px;
    }
    .net-row {
        display: flex;
        align-items: center;
        gap: 5px;
    }
    .net-tag {
        font-size: 0.68rem;
        font-weight: 700;
        padding: 1px 4px;
        border-radius: 4px;
        background-color: #f1f3f9;
        color: #4b5563;
        border: 1px solid #e2e8f0;
        min-width: 32px;
        text-align: center;
    }
    .action-btn-group {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        flex-wrap: nowrap;
    }
    .action-btn {
        border-radius: 6px;
        padding: 4px 8px;
        font-weight: 600;
        font-size: 0.78rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        white-space: nowrap;
    }
</style>

<div class="container-fluid px-3 px-md-4 device-registry-wrapper">
    <!-- Page Heading -->
    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between mb-4 pb-2 border-bottom">
        <div class="mb-3 mb-md-0">
            <h1 class="registry-header-title mb-1">
                <i class="fas fa-server text-primary mr-2"></i>Device Registry — Hardware Fisik
            </h1>
            <p class="registry-header-subtitle mb-0">
                Manajemen inventaris perangkat fisik Samsung Smart TV dan Shelly Smart Relay stasiun Sugeng Rawuh.
            </p>
        </div>
        <div class="d-flex align-items-center">
            @if(auth()->user()->isAdmin())
                <button type="button" class="btn btn-primary px-3 py-2 shadow-sm font-weight-bold" id="btnOpenRegisterModal" data-toggle="modal" data-target="#registerDeviceModal" style="border-radius: 8px;">
                    <i class="fas fa-plus fa-sm text-white-50 mr-2"></i>Registrasi Hardware Baru
                </button>
            @else
                <span class="badge badge-info py-2 px-3 font-weight-normal" style="font-size: 0.875rem;">
                    <i class="fas fa-eye mr-1"></i> Mode Read-Only (Owner)
                </span>
            @endif
        </div>
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

    <!-- Filter Card -->
    <div class="card card-registry mb-4">
        <div class="card-header d-flex align-items-center justify-content-between">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="fas fa-filter mr-1"></i> Filter Inventaris Hardware
            </h6>
            @if(!empty(array_filter(request()->only(['category', 'brand', 'is_active', 'unmapped']))))
                <span class="badge badge-warning text-dark px-2 py-1" style="border-radius: 4px;">
                    <i class="fas fa-check-circle mr-1"></i>Filter Aktif
                </span>
            @endif
        </div>
        <div class="card-body p-3 p-md-4">
            <form method="GET" action="{{ route('admin.hardware.registry') }}" id="filterForm">
                <div class="row align-items-end">
                    <div class="col-xl-3 col-lg-4 col-md-6 col-12 mb-3 mb-xl-0">
                        <label for="filter_category" class="filter-label">
                            <i class="fas fa-layer-group text-muted mr-1"></i>Kategori
                        </label>
                        <select name="category" id="filter_category" class="form-control custom-select filter-input">
                            <option value="">Semua Kategori</option>
                            <option value="SMART_TV" {{ request('category') === 'SMART_TV' ? 'selected' : '' }}>Smart TV (Samsung)</option>
                            <option value="SMART_RELAY" {{ request('category') === 'SMART_RELAY' ? 'selected' : '' }}>Smart Relay (Shelly)</option>
                            <option value="GAME_CONSOLE" {{ request('category') === 'GAME_CONSOLE' ? 'selected' : '' }}>Game Console (PS3 / PS4 HEN)</option>
                        </select>
                    </div>
                    <div class="col-xl-2 col-lg-4 col-md-6 col-12 mb-3 mb-xl-0">
                        <label for="filter_brand" class="filter-label">
                            <i class="fas fa-tag text-muted mr-1"></i>Brand
                        </label>
                        <select name="brand" id="filter_brand" class="form-control custom-select filter-input">
                            <option value="">Semua Brand</option>
                            <option value="SAMSUNG" {{ request('brand') === 'SAMSUNG' ? 'selected' : '' }}>SAMSUNG</option>
                            <option value="SHELLY" {{ request('brand') === 'SHELLY' ? 'selected' : '' }}>SHELLY</option>
                            <option value="SONY" {{ request('brand') === 'SONY' ? 'selected' : '' }}>SONY</option>
                            <option value="PLAYSTATION" {{ request('brand') === 'PLAYSTATION' ? 'selected' : '' }}>PLAYSTATION</option>
                        </select>
                    </div>
                    <div class="col-xl-2 col-lg-4 col-md-6 col-12 mb-3 mb-xl-0">
                        <label for="filter_status" class="filter-label">
                            <i class="fas fa-toggle-on text-muted mr-1"></i>Status Aktif
                        </label>
                        <select name="is_active" id="filter_status" class="form-control custom-select filter-input">
                            <option value="">Semua Status</option>
                            <option value="1" {{ request('is_active') === '1' ? 'selected' : '' }}>Aktif</option>
                            <option value="0" {{ request('is_active') === '0' ? 'selected' : '' }}>Nonaktif</option>
                        </select>
                    </div>
                    <div class="col-xl-2 col-lg-6 col-md-6 col-12 mb-3 mb-xl-0">
                        <label for="filter_unmapped" class="filter-label">
                            <i class="fas fa-link text-muted mr-1"></i>Status Pemetaan
                        </label>
                        <select name="unmapped" id="filter_unmapped" class="form-control custom-select filter-input">
                            <option value="">Semua Perangkat</option>
                            <option value="1" {{ request('unmapped') === '1' ? 'selected' : '' }}>Hanya Belum Terpetakan</option>
                        </select>
                    </div>
                    <div class="col-xl-3 col-lg-6 col-md-12 col-12">
                        <div class="d-flex align-items-center">
                            <button type="submit" class="btn btn-primary filter-btn flex-fill mr-2 shadow-sm">
                                <i class="fas fa-search fa-sm mr-1"></i> Terapkan
                            </button>
                            <a href="{{ route('admin.hardware.registry') }}" class="btn btn-outline-secondary filter-btn flex-fill">
                                <i class="fas fa-undo fa-sm mr-1"></i> Reset
                            </a>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Hardware List Table Card (Consolidated 7 Columns) -->
    <div class="card card-registry mb-4">
        <div class="card-header d-flex flex-row align-items-center justify-content-between">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="fas fa-list mr-1"></i> Daftar Perangkat Fisik (Total: {{ $devices->total() }})
            </h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-registry text-gray-900" id="devicesTable">
                    <thead>
                        <tr>
                            <th style="width: 38px;" class="text-center">#</th>
                            <th style="min-width: 190px;">Perangkat</th>
                            <th style="width: 155px; min-width: 150px;">Informasi Jaringan</th>
                            <th style="width: 125px; min-width: 120px;">Koneksi & Pairing</th>
                            <th style="width: 130px; min-width: 125px;">Stasiun Terhubung</th>
                            <th style="width: 80px; min-width: 75px;" class="text-center">Status</th>
                            <th style="width: 165px; min-width: 160px;" class="text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($devices as $index => $device)
                            <tr>
                                <td class="text-center align-middle text-muted font-weight-bold">
                                    {{ $devices->firstItem() + $index }}
                                </td>
                                <td class="align-middle">
                                    <div class="d-flex align-items-start">
                                        <div class="device-avatar mr-2 mt-1 {{ $device->category === 'SMART_TV' ? 'device-avatar-tv' : ($device->category === 'GAME_CONSOLE' ? 'device-avatar-console bg-dark text-warning' : 'device-avatar-relay') }}">
                                            @if($device->category === 'SMART_TV')
                                                <i class="fas fa-tv"></i>
                                            @elseif($device->category === 'GAME_CONSOLE')
                                                <i class="fas fa-gamepad"></i>
                                            @else
                                                <i class="fas fa-bolt"></i>
                                            @endif
                                        </div>
                                        <div class="flex-grow-1" style="min-width: 0;">
                                            <div class="device-primary-name">
                                                {{ $device->name }}
                                            </div>
                                            <div class="d-flex flex-wrap align-items-center mt-1" style="gap: 6px;">
                                                @if($device->category === 'SMART_TV')
                                                    <span class="badge badge-primary badge-modern">
                                                        <i class="fas fa-tv mr-1"></i>SAMSUNG SMART_TV
                                                    </span>
                                                @elseif($device->category === 'GAME_CONSOLE')
                                                    <span class="badge badge-dark text-warning badge-modern">
                                                        <i class="fas fa-gamepad mr-1"></i>{{ $device->brand }} {{ $device->category }}
                                                    </span>
                                                @else
                                                    <span class="badge badge-info badge-modern">
                                                        <i class="fas fa-bolt mr-1"></i>SHELLY SMART_RELAY
                                                    </span>
                                                @endif
                                                <span class="text-muted small">
                                                    <i class="fas fa-tag mr-1"></i>{{ $device->model }}
                                                </span>
                                                @if($device->device_identifier)
                                                    <span class="badge badge-light border text-muted badge-modern" style="font-size: 0.7rem;">
                                                        ID: {{ $device->device_identifier }}
                                                    </span>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="align-middle">
                                    <div class="net-box">
                                        <div class="net-row">
                                            <span class="net-tag">IP</span>
                                            <code class="font-weight-bold text-dark font-monospace-code" style="font-size: 0.92rem;">{{ $device->ip_address }}</code>
                                        </div>
                                        <div class="net-row">
                                            <span class="net-tag">MAC</span>
                                            <code class="text-secondary font-monospace-code" style="font-size: 0.85rem;">{{ $device->mac_address }}</code>
                                        </div>
                                    </div>
                                </td>
                                <td class="align-middle">
                                    <div class="d-flex flex-column align-items-start" style="gap: 5px;">
                                        {{-- Connection Status --}}
                                        @if($device->connection_status === 'ONLINE')
                                            <span class="badge badge-success badge-modern">
                                                <i class="fas fa-circle text-white mr-1" style="font-size: 0.55rem;"></i>ONLINE
                                            </span>
                                        @elseif($device->connection_status === 'OFFLINE')
                                            <span class="badge badge-danger badge-modern">
                                                <i class="fas fa-circle text-white mr-1" style="font-size: 0.55rem;"></i>OFFLINE
                                            </span>
                                        @else
                                            <span class="badge badge-warning text-dark badge-modern">
                                                <i class="fas fa-question-circle mr-1"></i>UNVERIFIED
                                            </span>
                                        @endif

                                        {{-- Pairing Status --}}
                                        @if($device->pairing_status === 'PAIRED')
                                            <span class="badge badge-success badge-modern">
                                                <i class="fas fa-link mr-1"></i>PAIRED
                                            </span>
                                        @elseif($device->pairing_status === 'FAILED')
                                            <span class="badge badge-danger badge-modern">
                                                <i class="fas fa-unlink mr-1"></i>FAILED
                                            </span>
                                        @else
                                            <span class="badge badge-secondary badge-modern">
                                                <i class="fas fa-shield-alt mr-1"></i>UNVERIFIED
                                            </span>
                                        @endif
                                    </div>
                                </td>
                                <td class="align-middle">
                                    @if($device->activeMapping && $device->activeMapping->station)
                                        <div class="mapped-station">
                                            <span class="badge badge-info badge-modern">
                                                <i class="fas fa-gamepad mr-1"></i>{{ $device->activeMapping->station->nama }}
                                            </span>
                                            <div class="small text-muted mt-1" style="font-size: 0.775rem;">
                                                Role: <span class="font-weight-bold text-dark">{{ $device->activeMapping->mapping_role }}</span>
                                            </div>
                                        </div>
                                    @else
                                        <span class="badge badge-light border text-muted badge-modern">
                                            <i class="fas fa-unlink text-muted mr-1"></i>Belum terpetakan
                                        </span>
                                    @endif
                                </td>
                                <td class="align-middle text-center">
                                    @if($device->is_active)
                                        <span class="badge badge-success badge-modern">
                                            <i class="fas fa-check-circle mr-1"></i>Aktif
                                        </span>
                                    @else
                                        <span class="badge badge-secondary badge-modern">
                                            <i class="fas fa-ban mr-1"></i>Nonaktif
                                        </span>
                                    @endif
                                </td>
                                <td class="align-middle text-center">
                                    @if(auth()->user()->isAdmin())
                                        <div class="action-btn-group" role="group">
                                            <button type="button" class="btn btn-sm btn-outline-primary action-btn btn-edit-device shadow-none" 
                                                title="Edit Perangkat"
                                                data-id="{{ $device->id }}"
                                                data-name="{{ $device->name }}"
                                                data-model="{{ $device->model }}"
                                                data-mac="{{ $device->mac_address }}"
                                                data-ip="{{ $device->ip_address }}"
                                                data-identifier="{{ $device->device_identifier }}">
                                                <i class="fas fa-edit mr-1"></i>Edit
                                            </button>
                                            @if($device->is_active)
                                                <button type="button" class="btn btn-sm btn-outline-danger action-btn btn-deactivate-device shadow-none" 
                                                    title="{{ $device->activeMapping ? 'Perangkat terpasang aktif, lepas mapping terlebih dahulu' : 'Nonaktifkan Perangkat' }}"
                                                    data-id="{{ $device->id }}"
                                                    data-name="{{ $device->name }}"
                                                    {{ $device->activeMapping ? 'disabled' : '' }}>
                                                    <i class="fas fa-power-off mr-1"></i>Nonaktifkan
                                                </button>
                                            @endif
                                        </div>
                                    @else
                                        <span class="badge badge-light border text-muted small py-1 px-2 font-italic">Read-only</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">
                                    <div class="py-3">
                                        <i class="fas fa-microchip fa-3x mb-3 text-gray-300 d-block"></i>
                                        <h6 class="font-weight-bold text-gray-700">Tidak ada data perangkat fisik yang ditemukan.</h6>
                                        <p class="small text-muted mb-0">Sesuaikan filter pencarian atau daftarkan hardware fisik baru.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($devices->hasPages())
            <div class="card-footer bg-white py-3 border-top">
                <div class="d-flex flex-column flex-sm-row align-items-center justify-content-between">
                    <div class="small text-muted mb-2 mb-sm-0">
                        Menampilkan {{ $devices->firstItem() }} sampai {{ $devices->lastItem() }} dari total {{ $devices->total() }} perangkat
                    </div>
                    <div>
                        {{ $devices->links() }}
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>

@if(auth()->user()->isAdmin())
    <!-- Register Device Modal -->
    <div class="modal fade" id="registerDeviceModal" tabindex="-1" role="dialog" aria-labelledby="registerDeviceModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content border-0 shadow">
                <form id="registerDeviceForm">
                    @csrf
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title font-weight-bold" id="registerDeviceModalLabel">
                            <i class="fas fa-plus-circle mr-2"></i>Registrasi Hardware Baru
                        </h5>
                        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body p-4">
                        <div id="registerErrorAlert" class="alert alert-danger d-none"></div>

                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label for="reg_name" class="font-weight-bold">Nama Perangkat <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="reg_name" name="name" placeholder="Misal: TV PlayStation Meja 01" required>
                                <div class="invalid-feedback" id="err_reg_name"></div>
                            </div>
                            <div class="form-group col-md-6">
                                <label for="reg_model" class="font-weight-bold">Model Perangkat <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="reg_model" name="model" placeholder="Misal: UA43CU7000 / 1PM Gen3 Mini" required>
                                <div class="invalid-feedback" id="err_reg_model"></div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label for="reg_category" class="font-weight-bold">Kategori Hardware <span class="text-danger">*</span></label>
                                <select class="form-control custom-select" id="reg_category" name="category" required>
                                    <option value="SMART_TV">Smart TV (Khusus PlayStation)</option>
                                    <option value="SMART_RELAY">Smart Relay (Khusus Meja Biliar)</option>
                                    <option value="GAME_CONSOLE">Game Console (PlayStation 3 / 4)</option>
                                </select>
                                <div class="invalid-feedback" id="err_reg_category"></div>
                            </div>
                            <div class="form-group col-md-6">
                                <label for="reg_brand" class="font-weight-bold">Brand Resmi</label>
                                <input type="text" class="form-control font-weight-bold bg-light" id="reg_brand" name="brand" value="SAMSUNG" readonly>
                                <small class="form-text text-muted">
                                    <i class="fas fa-info-circle mr-1"></i> Brand otomatis: Smart TV = SAMSUNG, Smart Relay = SHELLY (COOCAA tidak didukung).
                                </small>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label for="reg_mac_address" class="font-weight-bold">MAC Address <span class="text-danger">*</span></label>
                                <input type="text" class="form-control font-monospace-code" id="reg_mac_address" name="mac_address" placeholder="Contoh: AA:BB:CC:DD:EE:01" required>
                                <small class="form-text text-muted">Format 12 karakter hex dengan pemisah titik dua (:) atau strip (-).</small>
                                <div class="invalid-feedback" id="err_reg_mac_address"></div>
                            </div>
                            <div class="form-group col-md-6">
                                <label for="reg_ip_address" class="font-weight-bold">IP Address <span class="text-danger">*</span></label>
                                <input type="text" class="form-control font-monospace-code" id="reg_ip_address" name="ip_address" placeholder="Contoh: 192.168.1.50" required>
                                <small class="form-text text-muted">Alamat IPv4 privat lokal stasiun (192.168.x.x, 10.x.x.x, 172.16-31.x.x).</small>
                                <div class="invalid-feedback" id="err_reg_ip_address"></div>
                            </div>
                        </div>

                        <div class="form-group mb-3">
                            <label for="reg_device_identifier" class="font-weight-bold">Device Identifier <span class="text-muted">(Opsional)</span></label>
                            <input type="text" class="form-control" id="reg_device_identifier" name="device_identifier" placeholder="Misal: samsung-tv-ps01">
                            <div class="invalid-feedback" id="err_reg_device_identifier"></div>
                        </div>

                        <div class="alert alert-info py-2 small mb-0">
                            <i class="fas fa-shield-alt mr-1"></i> <strong>Keamanan:</strong> Status koneksi dan pairing awal perangkat otomatis disetel ke <code>UNVERIFIED</code>.
                        </div>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary px-3" data-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary px-4 font-weight-bold" id="btnSubmitRegister">
                            <span class="spinner-border spinner-border-sm d-none mr-1" role="status" aria-hidden="true"></span>
                            Simpan Perangkat
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Device Modal -->
    <div class="modal fade" id="editDeviceModal" tabindex="-1" role="dialog" aria-labelledby="editDeviceModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content border-0 shadow">
                <form id="editDeviceForm">
                    @csrf
                    @method('PUT')
                    <input type="hidden" id="edit_id" name="id">
                    <div class="modal-header bg-info text-white">
                        <h5 class="modal-title font-weight-bold" id="editDeviceModalLabel">
                            <i class="fas fa-edit mr-2"></i>Edit Data Perangkat Fisik
                        </h5>
                        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body p-4">
                        <div id="editErrorAlert" class="alert alert-danger d-none"></div>

                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label for="edit_name" class="font-weight-bold">Nama Perangkat <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="edit_name" name="name" required>
                                <div class="invalid-feedback" id="err_edit_name"></div>
                            </div>
                            <div class="form-group col-md-6">
                                <label for="edit_model" class="font-weight-bold">Model Perangkat <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="edit_model" name="model" required>
                                <div class="invalid-feedback" id="err_edit_model"></div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label for="edit_mac_address" class="font-weight-bold">MAC Address <span class="text-danger">*</span></label>
                                <input type="text" class="form-control font-monospace-code" id="edit_mac_address" name="mac_address" required>
                                <div class="invalid-feedback" id="err_edit_mac_address"></div>
                            </div>
                            <div class="form-group col-md-6">
                                <label for="edit_ip_address" class="font-weight-bold">IP Address <span class="text-danger">*</span></label>
                                <input type="text" class="form-control font-monospace-code" id="edit_ip_address" name="ip_address" required>
                                <div class="invalid-feedback" id="err_edit_ip_address"></div>
                            </div>
                        </div>

                        <div class="form-group mb-3">
                            <label for="edit_device_identifier" class="font-weight-bold">Device Identifier <span class="text-muted">(Opsional)</span></label>
                            <input type="text" class="form-control" id="edit_device_identifier" name="device_identifier">
                            <div class="invalid-feedback" id="err_edit_device_identifier"></div>
                        </div>

                        <div class="alert alert-warning py-2 small mb-0">
                            <i class="fas fa-exclamation-circle mr-1"></i> <strong>Perhatian:</strong> Mengubah alamat IP atau MAC Address akan otomatis mereset status koneksi menjadi <code>UNVERIFIED</code> untuk keamanan.
                        </div>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary px-3" data-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-info px-4 font-weight-bold" id="btnSubmitEdit">
                            <span class="spinner-border spinner-border-sm d-none mr-1" role="status" aria-hidden="true"></span>
                            Simpan Perubahan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Deactivate Device Confirmation Modal -->
    <div class="modal fade" id="deactivateDeviceModal" tabindex="-1" role="dialog" aria-labelledby="deactivateDeviceModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content border-0 shadow">
                <form id="deactivateDeviceForm">
                    @csrf
                    @method('DELETE')
                    <input type="hidden" id="deactivate_id" name="id">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title font-weight-bold" id="deactivateDeviceModalLabel">
                            <i class="fas fa-exclamation-triangle mr-2"></i>Konfirmasi Nonaktifkan Hardware
                        </h5>
                        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body p-4">
                        <div id="deactivateErrorAlert" class="alert alert-danger d-none"></div>
                        <p class="mb-2 text-gray-800">Apakah Anda yakin ingin menonaktifkan perangkat fisik berikut?</p>
                        <div class="alert alert-light border py-2 mb-3">
                            <strong id="deactivate_name" class="text-dark"></strong>
                        </div>
                        <div class="alert alert-warning py-2 small mb-0">
                            <i class="fas fa-info-circle mr-1"></i> Perangkat yang dinonaktifkan tidak dapat dipetakan ke stasiun billing. Perangkat yang sedang aktif terpasang pada stasiun tidak dapat dinonaktifkan.
                        </div>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary px-3" data-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-danger px-4 font-weight-bold" id="btnSubmitDeactivate">
                            <span class="spinner-border spinner-border-sm d-none mr-1" role="status" aria-hidden="true"></span>
                            Nonaktifkan Perangkat
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endif
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

    // Open Register Modal
    $('#btnOpenRegisterModal').on('click', function(e) {
        clearValidationErrors('err_reg_');
        $('#registerErrorAlert').addClass('d-none').text('');
        $('#registerDeviceModal').modal('show');
    });

    // Brand Automatic Sync on Category Change
    $('#reg_category').on('change', function() {
        var cat = $(this).val();
        if (cat === 'SMART_TV') {
            $('#reg_brand').val('SAMSUNG');
            $('#reg_mac_address').prop('required', true);
        } else if (cat === 'SMART_RELAY') {
            $('#reg_brand').val('SHELLY');
            $('#reg_mac_address').prop('required', true);
        } else if (cat === 'GAME_CONSOLE') {
            $('#reg_brand').val('SONY');
            $('#reg_mac_address').prop('required', false);
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

    // Helper: Reset Validation Errors
    function clearValidationErrors(prefix) {
        $('input, select').removeClass('is-invalid');
        $('[id^="' + prefix + '"]').text('').hide();
    }

    // Helper: Render Validation Errors from 422 Response
    function renderValidationErrors(errors, prefix) {
        $.each(errors, function(field, messages) {
            $('#' + prefix + field).text(messages[0]).show();
            $('#' + (prefix === 'err_reg_' ? 'reg_' : 'edit_') + field).addClass('is-invalid');
        });
    }

    // --- 1. SUBMIT REGISTER DEVICE ---
    $('#registerDeviceForm').on('submit', function(e) {
        e.preventDefault();
        clearValidationErrors('err_reg_');
        $('#registerErrorAlert').addClass('d-none').text('');

        var $btn = $('#btnSubmitRegister');
        $btn.prop('disabled', true);
        $btn.find('.spinner-border').removeClass('d-none');

        var payload = {
            name: $('#reg_name').val(),
            category: $('#reg_category').val(),
            brand: $('#reg_brand').val(),
            model: $('#reg_model').val(),
            mac_address: $('#reg_mac_address').val(),
            ip_address: $('#reg_ip_address').val(),
            device_identifier: $('#reg_device_identifier').val()
        };

        $.ajax({
            url: '{{ route("admin.hardware.devices.store") }}',
            type: 'POST',
            data: JSON.stringify(payload),
            contentType: 'application/json',
            success: function(response) {
                $('#registerDeviceModal').modal('hide');
                showNotification('success', response.message || 'Perangkat berhasil didaftarkan.');
                setTimeout(function() { window.location.reload(); }, 800);
            },
            error: function(xhr) {
                $btn.prop('disabled', false);
                $btn.find('.spinner-border').addClass('d-none');

                if (xhr.status === 422 && xhr.responseJSON && xhr.responseJSON.errors) {
                    renderValidationErrors(xhr.responseJSON.errors, 'err_reg_');
                } else if (xhr.status === 409 && xhr.responseJSON) {
                    $('#registerErrorAlert').removeClass('d-none').text(xhr.responseJSON.message || 'Terjadi konflik data (MAC/IP).');
                } else if (xhr.status === 403) {
                    $('#registerErrorAlert').removeClass('d-none').text('Akses ditolak: Anda tidak memiliki wewenang untuk aksi ini.');
                } else {
                    $('#registerErrorAlert').removeClass('d-none').text(xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Gagal menyimpan perangkat. Silakan coba lagi.');
                }
            }
        });
    });

    // --- 2. OPEN EDIT MODAL ---
    $('.btn-edit-device').on('click', function() {
        var id = $(this).data('id');
        var name = $(this).data('name');
        var model = $(this).data('model');
        var mac = $(this).data('mac');
        var ip = $(this).data('ip');
        var identifier = $(this).data('identifier');

        clearValidationErrors('err_edit_');
        $('#editErrorAlert').addClass('d-none').text('');

        $('#edit_id').val(id);
        $('#edit_name').val(name);
        $('#edit_model').val(model);
        $('#edit_mac_address').val(mac);
        $('#edit_ip_address').val(ip);
        $('#edit_device_identifier').val(identifier);

        $('#editDeviceModal').modal('show');
    });

    // --- 3. SUBMIT EDIT DEVICE ---
    $('#editDeviceForm').on('submit', function(e) {
        e.preventDefault();
        clearValidationErrors('err_edit_');
        $('#editErrorAlert').addClass('d-none').text('');

        var id = $('#edit_id').val();
        var $btn = $('#btnSubmitEdit');
        $btn.prop('disabled', true);
        $btn.find('.spinner-border').removeClass('d-none');

        var payload = {
            name: $('#edit_name').val(),
            model: $('#edit_model').val(),
            mac_address: $('#edit_mac_address').val(),
            ip_address: $('#edit_ip_address').val(),
            device_identifier: $('#edit_device_identifier').val()
        };

        $.ajax({
            url: '/admin/hardware/devices/' + id,
            type: 'PUT',
            data: JSON.stringify(payload),
            contentType: 'application/json',
            success: function(response) {
                $('#editDeviceModal').modal('hide');
                showNotification('success', response.message || 'Data perangkat berhasil diperbarui.');
                setTimeout(function() { window.location.reload(); }, 800);
            },
            error: function(xhr) {
                $btn.prop('disabled', false);
                $btn.find('.spinner-border').addClass('d-none');

                if (xhr.status === 422 && xhr.responseJSON && xhr.responseJSON.errors) {
                    renderValidationErrors(xhr.responseJSON.errors, 'err_edit_');
                } else if (xhr.status === 409 && xhr.responseJSON) {
                    $('#editErrorAlert').removeClass('d-none').text(xhr.responseJSON.message || 'Terjadi konflik data (MAC/IP).');
                } else if (xhr.status === 403) {
                    $('#editErrorAlert').removeClass('d-none').text('Akses ditolak: Anda tidak memiliki wewenang untuk aksi ini.');
                } else {
                    $('#editErrorAlert').removeClass('d-none').text(xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Gagal memperbarui perangkat.');
                }
            }
        });
    });

    // --- 4. OPEN DEACTIVATE MODAL ---
    $('.btn-deactivate-device').on('click', function() {
        var id = $(this).data('id');
        var name = $(this).data('name');

        $('#deactivate_id').val(id);
        $('#deactivate_name').text(name);
        $('#deactivateErrorAlert').addClass('d-none').text('');

        $('#deactivateDeviceModal').modal('show');
    });

    // --- 5. SUBMIT DEACTIVATE DEVICE ---
    $('#deactivateDeviceForm').on('submit', function(e) {
        e.preventDefault();
        var id = $('#deactivate_id').val();
        var $btn = $('#btnSubmitDeactivate');
        $btn.prop('disabled', true);
        $btn.find('.spinner-border').removeClass('d-none');

        $.ajax({
            url: '/admin/hardware/devices/' + id,
            type: 'DELETE',
            success: function(response) {
                $('#deactivateDeviceModal').modal('hide');
                showNotification('success', response.message || 'Perangkat berhasil dinonaktifkan.');
                setTimeout(function() { window.location.reload(); }, 800);
            },
            error: function(xhr) {
                $btn.prop('disabled', false);
                $btn.find('.spinner-border').addClass('d-none');

                if (xhr.status === 409 && xhr.responseJSON) {
                    $('#deactivateErrorAlert').removeClass('d-none').text(xhr.responseJSON.message || 'Perangkat tidak dapat dinonaktifkan karena sedang aktif terpasang.');
                } else if (xhr.status === 403) {
                    $('#deactivateErrorAlert').removeClass('d-none').text('Akses ditolak: Anda tidak memiliki wewenang untuk aksi ini.');
                } else {
                    $('#deactivateErrorAlert').removeClass('d-none').text(xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Gagal menonaktifkan perangkat.');
                }
            }
        });
    });
});
</script>
@endsection
