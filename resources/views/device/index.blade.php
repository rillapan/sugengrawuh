@extends('layouts.app')

@section('content')
    <!-- Content Row -->
    @if (session()->has('success'))
        <div class="alert alert-success col-lg-8" role="alert">
            {{ session('success') }}
        </div>
    @endif

    @if (session()->has('gagal'))
        <div class="alert alert-danger col-lg-8" role="alert">
            {{ session('gagal') }}
        </div>
    @endif

    <div class="row mb-4">
        <div class="col-lg-6 mb-3">
            <div class="card border-left-primary shadow h-100 py-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div class="text-xl font-weight-bold text-primary text-uppercase">Data Perangkat Tersedia</div>
                        <div class="h3 mb-0 font-weight-bold text-gray-800">{{ $countAvailable }}</div>
                    </div>
                    @if(!empty($availableDevices))
                        <div class="mt-2">
                            <small class="text-muted font-weight-bold">Perangkat:</small>
                            <div class="mt-1">
                                @foreach($availableDevices as $device)
                                    <span class="badge badge-success mr-1 mb-1">{{ $device }}</span>
                                @endforeach
                            </div>
                        </div>
                    @else
                        <small class="text-muted">Tidak ada perangkat tersedia</small>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-lg-6 mb-3">
            <div class="card border-left-warning shadow h-100 py-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div class="text-xsl font-weight-bold text-warning text-uppercase">Data Perangkat Digunakan</div>
                        <div class="h3 mb-0 font-weight-bold text-gray-800">{{ $countInUse }}</div>
                    </div>
                    @if(!empty($inUseDevices))
                        <div class="mt-2">
                            <small class="text-muted font-weight-bold">Perangkat:</small>
                            <div class="mt-1">
                                @foreach($inUseDevices as $device)
                                    <span class="badge badge-warning mr-1 mb-1">{{ $device }}</span>
                                @endforeach
                            </div>
                        </div>
                    @else
                        <small class="text-muted">Tidak ada perangkat digunakan</small>
                    @endif
                </div>
            </div>
        </div>
    </div>
    <div class="card shadow mb-4">
        <!-- Card Header - Dropdown -->
        <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
            <h6 class="m-0 font-weight-bold text-primary">
                Data Perangkat
            </h6>
            <div class="dropdown no-arrow">
                <a class="dropdown-toggle" href="#" role="button" id="dropdownMenuLink" data-toggle="dropdown"
                    aria-haspopup="true" aria-expanded="false">
                </a>

                @if (auth()->user()->role === 'admin')
                    <a href="{{ route('device.create') }}" class="btn btn-primary btn-sm">Tambah Data</a>
                @endif
            </div>
        </div>
        <!-- Card Body -->
        <div class="card-body">
            <div class="row">
                @foreach ($devices as $device)
                    <div class="col-lg-4 col-md-6 mb-4">
                        <div class="card shadow h-100">
                            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                                <h6 class="m-0 font-weight-bold text-primary">{{ $device->nama }}</h6>
                                @if ($device->status === 'Digunakan' && isset($customers[$device->id]))
                                    <span class="badge 
                                        @if($customers[$device->id]['tipe_transaksi'] === 'prepaid') badge-primary
                                        @elseif($customers[$device->id]['tipe_transaksi'] === 'custom_package') badge-warning
                                        @else badge-secondary
                                        @endif">
                                        @if($customers[$device->id]['tipe_transaksi'] === 'prepaid')
                                            Paket
                                        @elseif($customers[$device->id]['tipe_transaksi'] === 'custom_package')
                                            Custom Paket
                                        @else
                                            Lost Time
                                        @endif
                                    </span>
                                @endif
                            </div>
                            <div class="card-body">
                                <div id="automation-alert-banner-{{ $device->id }}" class="alert alert-danger p-2 small mb-2 d-none" role="alert">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <i class="fas fa-exclamation-triangle mr-1"></i>
                                            <span class="alert-message"></span>
                                        </div>
                                        <button type="button" class="btn btn-sm btn-outline-danger ml-2 btn-manual-retry" data-device-id="{{ $device->id }}">
                                            <i class="fas fa-redo"></i> Coba Ulang
                                        </button>
                                    </div>
                                </div>
                                <div class="mb-2">
                                    <strong>Jenis Playstation:</strong> 
                                    @if($device->playstations->count() > 0)
                                        @foreach($device->playstations as $playstation)
                                            <span class="badge badge-info">{{ $playstation->nama }}</span>
                                        @endforeach
                                    @else
                                        {{ $device->playstation->nama ?? 'Tidak Diketahui' }}
                                    @endif
                                </div>
                                <div class="mb-2" id="status-{{ $device->id }}">
                                    <strong>Status:</strong>
                                    @if ($device->status === 'Tersedia')
                                        <i class="fa fa-circle text-success" aria-hidden="true"></i>
                                        {{ ucfirst($device->status) }}
                                    @else
                                        <i class="fa fa-circle text-danger" aria-hidden="true"></i>
                                        {{ ucfirst($device->status) }}
                                    @endif
                                </div>
                                <div class="mb-2 p-2 bg-light rounded border">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <small class="font-weight-bold text-gray-700">
                                            <i class="fas fa-microchip mr-1"></i>Hardware:
                                        </small>
                                        @if((auth()->user()->isAdmin() || auth()->user()->isOwner()) && \Illuminate\Support\Facades\Route::has('admin.hardware.mappings'))
                                            <a href="{{ route('admin.hardware.mappings', ['station_id' => $device->id]) }}" class="badge badge-light border text-primary" title="Kelola Mapping Hardware">
                                                <i class="fas fa-cog"></i> Kelola
                                            </a>
                                        @endif
                                    </div>
                                    @php
                                        $activeMapping = $device->relationLoaded('activeHardwareMappings') 
                                            ? $device->activeHardwareMappings->first() 
                                            : null;
                                    @endphp
                                    @if($activeMapping && $activeMapping->physicalDevice)
                                        <div class="small">
                                            <div class="font-weight-bold text-dark">
                                                {{ $activeMapping->physicalDevice->name }}
                                                <span class="text-muted font-weight-normal">({{ $activeMapping->physicalDevice->brand }} {{ $activeMapping->physicalDevice->model }})</span>
                                            </div>
                                            <div class="d-flex align-items-center mt-1">
                                                <span class="text-muted mr-1">Status:</span>
                                                @php
                                                    $connStatus = $activeMapping->physicalDevice->connection_status ?? 'UNVERIFIED';
                                                @endphp
                                                @if($connStatus === 'ONLINE')
                                                    <span class="badge badge-success"><i class="fa fa-circle text-white mr-1 small"></i>ONLINE</span>
                                                @elseif($connStatus === 'OFFLINE')
                                                    <span class="badge badge-danger"><i class="fa fa-circle text-white mr-1 small"></i>OFFLINE</span>
                                                @else
                                                    <span class="badge badge-warning text-dark"><i class="fa fa-question-circle mr-1"></i>UNVERIFIED</span>
                                                @endif
                                                <span class="badge badge-secondary ml-1">{{ $activeMapping->mapping_role }}</span>
                                            </div>
                                            <div class="mt-2 d-flex align-items-center justify-content-between automation-pollable" data-device-id="{{ $device->id }}">
                                                <span class="text-muted mr-1">Otomasi Relay:</span>
                                                <span id="automation-badge-{{ $device->id }}" class="badge badge-info">
                                                    <i class="fas fa-bolt mr-1"></i>{{ $device->last_hardware_state ?? 'STATE_UNKNOWN' }}
                                                </span>
                                            </div>
                                        </div>
                                    @else
                                        <div class="small text-muted">
                                            <em>Belum ada hardware terdaftar</em>
                                            <div class="text-success small"><i class="fas fa-check-circle mr-1"></i>Billing tetap dapat digunakan</div>
                                        </div>
                                    @endif
                                </div>
                                <div class="mb-2">
                                    <strong>Timer:</strong>
                                    @if ($device->status === 'Digunakan' && isset($customers[$device->id]))
                                        @if (($customers[$device->id]['tipe_transaksi'] === 'prepaid' || $customers[$device->id]['tipe_transaksi'] === 'custom_package') && isset($timers[$device->id]))
                                            <span id="timer-{{ $device->id }}" data-type="{{ $customers[$device->id]['tipe_transaksi'] }}">Memuat...</span>
                                        @elseif($customers[$device->id]['tipe_transaksi'] === 'postpaid' && $customers[$device->id]['status_transaksi'] === 'berjalan')
                                            <span id="timer-{{ $device->id }}" data-type="postpaid"
                                                data-lost-time-start="{{ isset($postpaidTransactions[$device->id]) ? $postpaidTransactions[$device->id]['lost_time_start'] : '' }}">
                                                <span id="elapsed-time-{{ $device->id }}">00:00:00</span>
                                            </span>
                                        @else
                                            -
                                        @endif
                                    @else
                                        -
                                    @endif
                                </div>

                                {{-- SR-TV-INTEGRATION-001: Kontrol Samsung TV Panel (PlayStation Stations Only) --}}
                                @php
                                    $stationCategory = $device->getStationCategory();
                                    $tvMapping = $device->relationLoaded('activeDisplayTv')
                                        ? $device->activeDisplayTv
                                        : $device->activeDisplayTv()->with('physicalDevice')->first();
                                    $tvPhysical = $tvMapping ? $tvMapping->physicalDevice : null;
                                    $initTvCtrl = $tvControlData[$device->id] ?? null;
                                    $initVol = $initTvCtrl['tv_state']['volume'] ?? 30;
                                    $initMute = !empty($initTvCtrl['tv_state']['mute']);
                                    $initLatestEvent = $initTvCtrl['latest_event'] ?? null;
                                    $initSimOnline = !empty($initTvCtrl['simulator_online']);
                                @endphp
                                @if ($stationCategory === 'PLAYSTATION')
                                    @if ($device->status === 'Tersedia')
                                        <div class="mt-2 mb-2 p-2 bg-light border rounded small text-muted" id="tv-control-inactive-{{ $device->id }}">
                                            <i class="fas fa-tv mr-1"></i><strong>Kontrol Samsung TV:</strong> Nonaktif (Station belum sedang digunakan pelanggan).
                                        </div>
                                    @elseif ($device->status === 'Digunakan' && auth()->user()->isAdmin())
                                        <div class="mt-2 mb-3 p-2 bg-white border border-warning rounded shadow-sm tv-control-panel"
                                             id="tv-control-panel-{{ $device->id }}"
                                             data-station-id="{{ $device->id }}"
                                             data-station-name="{{ $device->nama }}"
                                             data-alert-url="{{ route('admin.stations.tv-alerts.store', ['station' => $device->id]) }}"
                                             data-volume-url="{{ route('admin.stations.tv-volume.store', ['station' => $device->id]) }}"
                                             data-status-url="{{ route('admin.stations.tv-control.status', ['station' => $device->id]) }}"
                                             data-lab-url="{{ route('admin.tv-lab.index', ['station_id' => $device->id]) }}">
                                            <div class="d-flex justify-content-between align-items-center mb-1">
                                                <small class="font-weight-bold text-dark text-uppercase">
                                                    <i class="fas fa-tv text-primary mr-1"></i>KONTROL SAMSUNG TV
                                                </small>
                                                <a href="{{ route('admin.tv-lab.index', ['station_id' => $device->id]) }}" target="_blank" class="badge badge-dark text-warning" title="Buka Simulator TV 16:9 untuk station ini">
                                                    <i class="fas fa-external-link-alt mr-1"></i>TV Lab
                                                </a>
                                            </div>
                                            <span class="badge badge-warning text-dark d-block mb-2 py-1 text-wrap">
                                                <i class="fas fa-exclamation-triangle mr-1"></i>SIMULASI — TIDAK MENGONTROL TV FISIK
                                            </span>

                                            <div class="small mb-2">
                                                <div class="d-flex justify-content-between">
                                                    <span class="text-muted">Station & Kategori:</span>
                                                    <strong class="text-dark">{{ $device->nama }} ({{ $device->playstation_names ?: 'PlayStation' }})</strong>
                                                </div>
                                                <div class="d-flex justify-content-between align-items-center mt-1">
                                                    <span class="text-muted">Samsung TV:</span>
                                                    @if ($tvPhysical && $tvPhysical->brand === 'SAMSUNG' && $tvPhysical->category === 'SMART_TV')
                                                        <strong class="text-dark" id="tv-name-{{ $device->id }}">{{ $tvPhysical->name }} ({{ $tvPhysical->model }})</strong>
                                                    @else
                                                        <span class="badge badge-danger" id="tv-name-{{ $device->id }}">Belum Dipetakan</span>
                                                    @endif
                                                </div>
                                                <div class="d-flex justify-content-between align-items-center mt-1">
                                                    <span class="text-muted">Koneksi & Pairing:</span>
                                                    <span>
                                                        <span class="badge badge-secondary" id="tv-conn-status-{{ $device->id }}">{{ $tvPhysical->connection_status ?? 'UNVERIFIED' }}</span>
                                                        <span class="badge badge-light border text-dark" id="tv-pair-status-{{ $device->id }}">{{ $tvPhysical->pairing_status ?? 'UNVERIFIED' }}</span>
                                                    </span>
                                                </div>
                                                <div class="d-flex justify-content-between align-items-center mt-1">
                                                    <span class="text-muted">Mode Kontrol & Consumer:</span>
                                                    <span>
                                                        <span class="badge badge-info" id="tv-mode-{{ $device->id }}">MOCK</span>
                                                        <span class="badge {{ $initSimOnline ? 'badge-success' : 'badge-secondary' }}" id="tv-sim-conn-{{ $device->id }}">
                                                            {{ $initSimOnline ? 'CONNECTED' : 'WAITING_CONSUMER' }}
                                                        </span>
                                                    </span>
                                                </div>
                                                <div class="d-flex justify-content-between align-items-center mt-1">
                                                    <span class="text-muted">Volume & Audio (Simulasi):</span>
                                                    <span>
                                                        <span class="badge badge-primary" id="tv-vol-badge-{{ $device->id }}">Vol: {{ $initVol }}/100</span>
                                                        <span class="badge {{ $initMute ? 'badge-danger' : 'badge-success' }}" id="tv-mute-badge-{{ $device->id }}">
                                                            {{ $initMute ? 'MUTE (SIM)' : 'UNMUTE (SIM)' }}
                                                        </span>
                                                    </span>
                                                </div>
                                                <div class="d-flex justify-content-between align-items-center mt-1">
                                                    <span class="text-muted">Status Event Terakhir:</span>
                                                    <span class="badge badge-secondary" id="tv-last-event-badge-{{ $device->id }}">
                                                        {{ $initLatestEvent ? $initLatestEvent->status : 'BELUM ADA EVENT' }}
                                                    </span>
                                                </div>
                                            </div>

                                            <div class="d-flex flex-wrap">
                                                <button type="button"
                                                        class="btn btn-xs btn-sm btn-primary mr-1 mb-1 btn-open-tv-alert-modal"
                                                        data-station-id="{{ $device->id }}"
                                                        data-station-name="{{ $device->nama }}"
                                                        data-alert-url="{{ route('admin.stations.tv-alerts.store', ['station' => $device->id]) }}"
                                                        {{ (!$tvPhysical || $tvPhysical->brand !== 'SAMSUNG') ? 'disabled title=Belum_ada_Samsung_TV_aktif' : '' }}>
                                                    <i class="fas fa-bullhorn mr-1"></i> Kirim Peringatan
                                                </button>
                                                <button type="button"
                                                        class="btn btn-xs btn-sm btn-outline-secondary mr-1 mb-1 btn-tv-volume-cmd"
                                                        data-station-id="{{ $device->id }}"
                                                        data-command="VOLUME_DOWN"
                                                        data-volume-url="{{ route('admin.stations.tv-volume.store', ['station' => $device->id]) }}"
                                                        {{ (!$tvPhysical || $tvPhysical->brand !== 'SAMSUNG') ? 'disabled' : '' }}>
                                                    <i class="fas fa-volume-down"></i> Vol Turun
                                                </button>
                                                <button type="button"
                                                        class="btn btn-xs btn-sm btn-outline-secondary mr-1 mb-1 btn-tv-volume-cmd"
                                                        data-station-id="{{ $device->id }}"
                                                        data-command="VOLUME_UP"
                                                        data-volume-url="{{ route('admin.stations.tv-volume.store', ['station' => $device->id]) }}"
                                                        {{ (!$tvPhysical || $tvPhysical->brand !== 'SAMSUNG') ? 'disabled' : '' }}>
                                                    <i class="fas fa-volume-up"></i> Vol Naik
                                                </button>
                                                <button type="button"
                                                        class="btn btn-xs btn-sm btn-outline-warning text-dark mr-1 mb-1 btn-tv-volume-cmd"
                                                        data-station-id="{{ $device->id }}"
                                                        data-command="MUTE_TOGGLE"
                                                        data-volume-url="{{ route('admin.stations.tv-volume.store', ['station' => $device->id]) }}"
                                                        {{ (!$tvPhysical || $tvPhysical->brand !== 'SAMSUNG') ? 'disabled' : '' }}>
                                                    <i class="fas fa-volume-mute"></i> Mute/Unmute (Sim)
                                                </button>
                                                <button type="button"
                                                        class="btn btn-xs btn-sm btn-outline-info mb-1 btn-open-tv-history-modal"
                                                        data-station-id="{{ $device->id }}"
                                                        data-station-name="{{ $device->nama }}"
                                                        data-status-url="{{ route('admin.stations.tv-control.status', ['station' => $device->id]) }}"
                                                        data-lab-url="{{ route('admin.tv-lab.index', ['station_id' => $device->id]) }}">
                                                    <i class="fas fa-history mr-1"></i> Lihat Riwayat Kontrol
                                                </button>
                                            </div>
                                        </div>
                                    @endif
                                @endif
                                <div class="d-flex flex-wrap gap-1">
                                    @if ($device->status === 'Digunakan' && isset($customers[$device->id]))
                                        <button type="button" class="btn btn-sm btn-info" data-toggle="modal" data-target="#customerModal" data-bs-toggle="modal" data-bs-target="#customerModal"
                                            data-id-transaksi="{{ $customers[$device->id]['id_transaksi'] }}"
                                            data-nama="{{ $customers[$device->id]['nama'] }}"
                                            data-nama-perangkat="{{ $customers[$device->id]['nama_perangkat'] }}"
                                            data-jenis-playstation="{{ $customers[$device->id]['jenis_playstation'] }}"
                                            data-jam-main="{{ $customers[$device->id]['jam_main'] }}"
                                            data-waktu-mulai="{{ $customers[$device->id]['waktu_mulai'] }}"
                                            data-waktu-selesai="{{ $customers[$device->id]['waktu_selesai'] }}"
                                            data-total="{{ $customers[$device->id]['total'] }}"
                                            data-tanggal="{{ $customers[$device->id]['tanggal'] }}">
                                            <i class="fas fa-eye"></i> Lihat Pelanggan
                                        </button>
                                    @endif

                                    @if (auth()->user()->role === 'admin' || auth()->user()->role === 'kasir')
                                        @if ($device->status === 'Digunakan')
                                            <button type="button" class="btn btn-sm btn-warning" 
                                                onclick="confirmStopDevice({{ $device->id }}, '{{ $device->nama }}')"
                                                title="Hentikan timer dan ubah status menjadi tersedia">
                                                <i class="fas fa-stop"></i> Berhenti
                                            </button>
                                        @endif
                                        @if (isset($customers[$device->id]) && $customers[$device->id]['tipe_transaksi'] === 'postpaid' && $customers[$device->id]['status_transaksi'] === 'berjalan')
                                            <button type="button" class="btn btn-sm btn-success" 
                                                data-toggle="modal" 
                                                data-target="#finishTransactionModal"
                                                data-bs-toggle="modal" 
                                                data-bs-target="#finishTransactionModal"
                                                data-action="{{ route('transaction.end', $customers[$device->id]['id_transaksi']) }}"
                                                data-device-id="{{ $device->id }}"
                                                data-device-info="{{ $device->playstation->nama ?? 'Unknown' }} | {{ $device->nama }}">
                                                <i class="fas fa-check"></i> Selesai
                                            </button>
                                        @endif
                                        <a href="/device/{{ $device->id }}/edit" class="btn btn-sm btn-primary"><i class="fas fa-edit"></i></a>
                                        <form action="/device/{{ $device->id }}" method="post" class="d-inline">
                                            @method('delete')
                                            @csrf
                                            <button class="btn btn-sm btn-danger" onclick="return confirm('Apakah Anda yakin ingin menghapus perangkat ini?')">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </form>
                                    @else
                                        <a href="/booking/{{ $device->id }}" class="btn btn-sm btn-primary">Lihat Jadwal</a>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
            {{ $devices->links() }}
        </div>
    </div>

    <!-- Customer Info Modal -->
    <div class="modal fade" id="customerModal" tabindex="-1" role="dialog" aria-labelledby="customerModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="customerModalLabel">Informasi Pelanggan</h5>
                    <button type="button" class="close btn-close" data-dismiss="modal" data-bs-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p><strong>ID Transaksi:</strong> <span id="modal-id-transaksi">Tidak tersedia</span></p>
                    <p><strong>Nama Pelanggan:</strong> <span id="modal-nama">Tidak tersedia</span></p>
                    <p><strong>Nama Perangkat:</strong> <span id="modal-nama-perangkat">Tidak tersedia</span></p>
                    <p><strong>Jenis Playstation:</strong> <span id="modal-jenis-playstation">Tidak tersedia</span></p>
                    <p><strong>Jam Main:</strong> <span id="modal-jam-main">Tidak tersedia</span></p>
                    <p><strong>Waktu Mulai:</strong> <span id="modal-waktu-mulai">Tidak tersedia</span></p>
                    <p><strong>Waktu Selesai:</strong> <span id="modal-waktu-selesai">Tidak tersedia</span></p>
                    <p><strong>Total:</strong> <span id="modal-total">Tidak tersedia</span></p>
                    <p><strong>Tanggal:</strong> <span id="modal-tanggal">Tidak tersedia</span></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal" data-bs-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Stop Device Confirmation Modal -->
    <div class="modal fade" id="stopDeviceModal" tabindex="-1" role="dialog" aria-labelledby="stopDeviceModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="stopDeviceModalLabel">Konfirmasi Berhenti</h5>
                    <button type="button" class="close btn-close" data-dismiss="modal" data-bs-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body text-center">
                    <h5>Apakah Anda yakin ingin menghentikan timer perangkat ini?</h5>
                    <p class="mb-0"><strong id="stop-device-name">Nama Perangkat</strong></p>
                    <p class="text-muted">Timer akan dihentikan dan status perangkat akan diubah menjadi "Tersedia"</p>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal" data-bs-dismiss="modal">Batal</button>
                    <form id="stopDeviceForm" method="POST" action="">
                        @csrf
                        <button type="submit" class="btn btn-warning"><i class="fas fa-stop"></i> Ya, Berhenti</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Finish Transaction Modal -->
    <div class="modal fade" id="finishTransactionModal" tabindex="-1" role="dialog" aria-labelledby="finishTransactionModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="finishTransactionModalLabel">Konfirmasi Selesai</h5>
                    <button type="button" class="close btn-close" data-dismiss="modal" data-bs-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body text-center">
                    <h5>Apakah Anda yakin ingin menyelesaikan transaksi ini?</h5>
                    <h2 id="modal-finish-timer" class="text-primary my-3 font-weight-bold">00:00:00</h2>
                    <p class="mb-0 text-muted font-weight-bold" id="modal-finish-device">Jenis PS | Nama Perangkat</p>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal" data-bs-dismiss="modal">Batal</button>
                    <form id="finishTransactionForm" method="POST" action="">
                        @csrf
                        <button type="submit" class="btn btn-success"><i class="fas fa-check"></i> Ya, Selesai</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    @if(auth()->user()->isAdmin())
        <!-- Send TV Alert Modal -->
        <div class="modal fade" id="tvAlertModal" tabindex="-1" role="dialog" aria-labelledby="tvAlertModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered" role="document">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="tvAlertModalLabel">Kirim Peringatan ke <span id="tv-alert-station-name">PS 01</span></h5>
                        <button type="button" class="close btn-close text-white" data-dismiss="modal" data-bs-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <form id="tvAlertForm">
                        <div class="modal-body">
                            <div class="alert alert-warning py-2 px-3 small mb-3">
                                <i class="fas fa-exclamation-triangle mr-1"></i>
                                <strong>SIMULASI — TIDAK MENGONTROL TV FISIK:</strong> Peringatan dicatat sebagai <code>PENDING</code> dan hanya menjadi <code>DELIVERED_TO_APP</code> setelah aplikasi Tizen / Simulator TV mengirim ACK.
                            </div>
                            <input type="hidden" id="tv-alert-station-id" name="station_id" value="">
                            <input type="hidden" id="tv-alert-action-url" value="">

                            <div class="form-group">
                                <label for="tv-alert-preset" class="font-weight-bold small text-dark">Pilih Preset Peringatan</label>
                                <select class="form-control" id="tv-alert-preset" name="preset" required>
                                    <option value="REMAINING_10_MIN">REMAINING_10_MIN — Waktu bermain tersisa 10 menit.</option>
                                    <option value="ALMOST_FINISHED">ALMOST_FINISHED — Waktu bermain hampir habis.</option>
                                    <option value="LOWER_VOLUME">LOWER_VOLUME — Mohon kecilkan volume permainan.</option>
                                    <option value="CONTACT_CASHIER">CONTACT_CASHIER — Silakan hubungi kasir.</option>
                                    <option value="CUSTOM">CUSTOM — Pesan khusus.</option>
                                </select>
                            </div>

                            <div class="form-group d-none" id="tv-alert-custom-group">
                                <label for="tv-alert-custom-message" class="font-weight-bold small text-dark">
                                    Pesan Khusus <span class="text-danger">*</span> <small class="text-muted">(Maks. 200 karakter, teks biasa)</small>
                                </label>
                                <textarea class="form-control" id="tv-alert-custom-message" name="custom_message" rows="3" maxlength="200" placeholder="Tulis pesan peringatan untuk ditampilkan pada layar TV station ini..."></textarea>
                                <div class="d-flex justify-content-between mt-1">
                                    <small class="text-muted">Tag HTML/script akan dibersihkan secara otomatis.</small>
                                    <small class="text-muted"><span id="tv-alert-char-count">0</span>/200</small>
                                </div>
                            </div>

                            <div class="form-group mb-3">
                                <label for="tv-alert-duration" class="font-weight-bold small text-dark">Durasi Tampilan Overlay (Detik)</label>
                                <select class="form-control" id="tv-alert-duration" name="duration_seconds">
                                    <option value="5">5 detik</option>
                                    <option value="6">6 detik</option>
                                    <option value="8" selected>8 detik (Default)</option>
                                    <option value="10">10 detik</option>
                                    <option value="12">12 detik</option>
                                    <option value="15">15 detik</option>
                                </select>
                            </div>

                            <div class="p-2 rounded border" style="background-color: #f59e0b; color: #111827;">
                                <div class="small font-weight-bold text-uppercase" id="tv-alert-preview-title">WAKTU BERMAIN TERSISA 10 MENIT!</div>
                                <div class="small mt-1" id="tv-alert-preview-body">Silakan hubungi kasir untuk memperpanjang waktu bermain.</div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal" data-bs-dismiss="modal">Batal</button>
                            <button type="submit" class="btn btn-primary btn-sm" id="btnSubmitTvAlert">
                                <i class="fas fa-paper-plane mr-1"></i> Kirim Peringatan
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- TV Control History & Consumer Config Modal -->
        <div class="modal fade" id="tvHistoryModal" tabindex="-1" role="dialog" aria-labelledby="tvHistoryModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
                <div class="modal-content">
                    <div class="modal-header bg-dark text-white">
                        <h5 class="modal-title" id="tvHistoryModalLabel">
                            <i class="fas fa-history text-warning mr-1"></i> Riwayat Kontrol & Koneksi Samsung TV — <span id="tv-history-station-name">PS 01</span>
                        </h5>
                        <button type="button" class="close btn-close text-white" data-dismiss="modal" data-bs-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="p-2 mb-3 bg-light border rounded small">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <strong class="text-dark"><i class="fas fa-plug text-primary mr-1"></i>Parameter Koneksi Aplikasi Samsung Tizen / Simulator:</strong>
                                <a href="#" id="tv-history-open-lab" target="_blank" class="btn btn-xs btn-sm btn-warning text-dark font-weight-bold">
                                    <i class="fas fa-tv mr-1"></i> Buka Simulator Layar 16:9 (/admin/tv-lab)
                                </a>
                            </div>
                            <div class="row mt-2">
                                <div class="col-md-4 mb-1">
                                    <span class="text-muted">Station ID:</span> <code id="tv-cfg-station-id">-</code>
                                </div>
                                <div class="col-md-8 mb-1">
                                    <span class="text-muted">Device Token (HMAC):</span> <code id="tv-cfg-device-token">-</code>
                                </div>
                                <div class="col-md-4 mb-1">
                                    <span class="text-muted">Dev Token:</span> <code id="tv-cfg-dev-token">-</code>
                                </div>
                                <div class="col-md-8 mb-1">
                                    <span class="text-muted">Poll Endpoint:</span> <code id="tv-cfg-poll-url">-</code>
                                </div>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-sm table-bordered table-striped small mb-0">
                                <thead class="thead-light">
                                    <tr>
                                        <th>ID</th>
                                        <th>Waktu</th>
                                        <th>Tipe / Command</th>
                                        <th>Judul & Pesan</th>
                                        <th>Vol/Mute</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody id="tv-history-table-body">
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-3">Memuat riwayat kontrol...</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal" data-bs-dismiss="modal">Tutup</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
    </div>
    <script>
        // Server time synchronization: calculate offset between client browser clock and server clock
        const initialServerTimestamp = {{ $serverTimestamp ?? (now()->timestamp * 1000) }};
        const clientClockOffset = Date.now() - initialServerTimestamp;

        function getServerNow() {
            return new Date(Date.now() - clientClockOffset);
        }

        // Function to update prepaid & custom package countdown timer
        function updatePrepaidTimer(deviceId, timerData) {
            let endTime = timerData.expires_at ? new Date(timerData.expires_at) : new Date(`${timerData.end_date} ${timerData.end_time}`);
            let timerTd = document.getElementById('timer-' + deviceId);
            
            function tick() {
                let currentServerTime = getServerNow();
                let diffMs = endTime.getTime() - currentServerTime.getTime();
                
                if (diffMs <= 0) {
                    if (timerTd) {
                        timerTd.innerHTML = '<span class="text-danger fw-bold">00:00:00 (Habis)</span>';
                    }
                    // Note: In Gate G2, backend background worker evaluates expiration.
                    // Frontend countdown reaching zero does NOT alter database state directly.
                    return;
                }
                
                let diffSec = Math.floor(diffMs / 1000);
                let hh = Math.floor(diffSec / 3600).toString().padStart(2, '0');
                let mm = Math.floor((diffSec % 3600) / 60).toString().padStart(2, '0');
                let ss = (diffSec % 60).toString().padStart(2, '0');
                
                if (timerTd) {
                    timerTd.innerHTML = `${hh}:${mm}:${ss}`;
                }
                setTimeout(tick, 1000);
            }
            tick();
        }

        // Function to update postpaid timer (elapsed time from 00:00:00)
        function updatePostpaidTimer(deviceId, lostTimeStart) {
            const startTimestamp = new Date(lostTimeStart).getTime();
            const elapsedEl = document.getElementById(`elapsed-time-${deviceId}`);
            
            function updateElapsedTime() {
                const currentServerTime = getServerNow().getTime();
                const diffMs = currentServerTime - startTimestamp;
                
                if (diffMs < 0) {
                    if (elapsedEl) elapsedEl.textContent = '00:00:00';
                } else {
                    const totalSeconds = Math.floor(diffMs / 1000);
                    const hours = Math.floor(totalSeconds / 3600).toString().padStart(2, '0');
                    const minutes = Math.floor((totalSeconds % 3600) / 60).toString().padStart(2, '0');
                    const seconds = (totalSeconds % 60).toString().padStart(2, '0');
                    
                    if (elapsedEl) elapsedEl.textContent = `${hours}:${minutes}:${seconds}`;
                }
                
                setTimeout(updateElapsedTime, 1000);
            }
            
            updateElapsedTime();
        }

        // Periodic background state synchronization with server (every 5 seconds)
        function syncDeviceTimersWithServer() {
            fetch('{{ route("device.timers") }}', {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(res => res.json())
            .then(data => {
                if (data && data.devices) {
                    data.devices.forEach(dev => {
                        const statusTd = document.getElementById('status-' + dev.id);
                        if (statusTd) {
                            if (dev.status === 'Tersedia' && !statusTd.innerHTML.includes('Tersedia')) {
                                statusTd.innerHTML = '<i class="fa fa-circle text-success" aria-hidden="true"></i> Tersedia';
                                const timerTd = document.getElementById('timer-' + dev.id);
                                if (timerTd) {
                                    timerTd.textContent = '-';
                                }
                            }
                        }
                    });
                }
            })
            .catch(err => {
                console.warn('Timer sync heartbeat failed (will retry):', err);
            });
        }

        // Initialize timers when the page loads
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize prepaid and custom package timers
            document.querySelectorAll('[data-type="prepaid"], [data-type="custom_package"]').forEach(timerElement => {
                const deviceId = timerElement.id.replace('timer-', '');
                const timerData = @json($timers);
                
                if (timerData[deviceId]) {
                    updatePrepaidTimer(deviceId, timerData[deviceId]);
                }
            });
            
            // Initialize postpaid timers
            document.querySelectorAll('[data-type="postpaid"]').forEach(timerElement => {
                const deviceId = timerElement.id.replace('timer-', '');
                const lostTimeStart = timerElement.dataset.lostTimeStart;
                
                if (lostTimeStart) {
                    updatePostpaidTimer(deviceId, lostTimeStart);
                }
            });

            // Start polling sync every 5 seconds
            setInterval(syncDeviceTimersWithServer, 5000);
        });

        // Safe Modal Helper Functions (Bootstrap 4 jQuery, Bootstrap 5, and direct DOM cleanup)
        function safeShowModal(modalId) {
            var modalEl = document.getElementById(modalId);
            if (!modalEl) return;

            // 1. Try jQuery Bootstrap 4 / 5 method
            if (window.jQuery && typeof window.jQuery(modalEl).modal === 'function') {
                window.jQuery(modalEl).modal('show');
                return;
            }

            // 2. Try Bootstrap 5
            if (window.bootstrap && window.bootstrap.Modal) {
                try {
                    if (typeof window.bootstrap.Modal.getOrCreateInstance === 'function') {
                        window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
                        return;
                    }
                    if (typeof window.bootstrap.Modal === 'function') {
                        new window.bootstrap.Modal(modalEl).show();
                        return;
                    }
                } catch (e) {}
            }

            // 3. Fallback
            modalEl.classList.add('show');
            modalEl.style.display = 'block';
            modalEl.removeAttribute('aria-hidden');
            modalEl.setAttribute('aria-modal', 'true');
            document.body.classList.add('modal-open');
        }

        function safeHideModal(modalId) {
            var modalEl = document.getElementById(modalId);
            if (!modalEl) return;

            // 1. Try jQuery Bootstrap 4 / 5 method
            if (window.jQuery && typeof window.jQuery(modalEl).modal === 'function') {
                try {
                    window.jQuery(modalEl).modal('hide');
                } catch (e) {}
            }

            // 2. Try Bootstrap 5
            if (window.bootstrap && window.bootstrap.Modal) {
                try {
                    if (typeof window.bootstrap.Modal.getInstance === 'function') {
                        var inst = window.bootstrap.Modal.getInstance(modalEl);
                        if (inst && typeof inst.hide === 'function') {
                            inst.hide();
                        }
                    } else if (typeof window.bootstrap.Modal.getOrCreateInstance === 'function') {
                        var inst = window.bootstrap.Modal.getOrCreateInstance(modalEl);
                        if (inst && typeof inst.hide === 'function') {
                            inst.hide();
                        }
                    }
                } catch (e) {}
            }

            // 3. Guaranteed DOM cleanup fallback (removes backdrop & body lock)
            setTimeout(function() {
                modalEl.classList.remove('show');
                modalEl.style.display = 'none';
                modalEl.setAttribute('aria-hidden', 'true');
                modalEl.removeAttribute('aria-modal');
                var backdrops = document.querySelectorAll('.modal-backdrop');
                backdrops.forEach(function(b) { b.remove(); });
                document.body.classList.remove('modal-open');
                document.body.style.removeProperty('padding-right');
            }, 100);
        }

        function showFeedbackAlert(type, message) {
            var existingAlert = document.getElementById('device-feedback-alert');
            if (existingAlert) existingAlert.remove();

            var alertDiv = document.createElement('div');
            alertDiv.id = 'device-feedback-alert';
            alertDiv.className = 'alert alert-' + type + ' alert-dismissible fade show col-lg-8';
            alertDiv.role = 'alert';
            alertDiv.innerHTML = '<span>' + message + '</span>' +
                '<button type="button" class="close" data-dismiss="alert" aria-label="Close">' +
                '<span aria-hidden="true">&times;</span></button>';

            var container = document.querySelector('.container-fluid');
            if (container) {
                container.insertBefore(alertDiv, container.firstChild);
            }

            setTimeout(function() {
                if (alertDiv && alertDiv.parentNode) {
                    alertDiv.remove();
                }
            }, 4000);
        }

        // Modal population script
        function populateCustomerModal(button) {
            if (!button) return;
            var idTransaksi = button.getAttribute('data-id-transaksi');
            var nama = button.getAttribute('data-nama');
            var namaPerangkat = button.getAttribute('data-nama-perangkat');
            var jenisPlaystation = button.getAttribute('data-jenis-playstation');
            var jamMain = button.getAttribute('data-jam-main');
            var waktuMulai = button.getAttribute('data-waktu-mulai');
            var waktuSelesai = button.getAttribute('data-waktu-selesai');
            var total = button.getAttribute('data-total');
            var tanggal = button.getAttribute('data-tanggal');

            var setVal = function(id, val) {
                var el = document.getElementById(id);
                if (el) el.textContent = val || 'Tidak tersedia';
            };

            setVal('modal-id-transaksi', idTransaksi);
            setVal('modal-nama', nama);
            setVal('modal-nama-perangkat', namaPerangkat);
            setVal('modal-jenis-playstation', jenisPlaystation);
            setVal('modal-jam-main', jamMain);
            setVal('modal-waktu-mulai', waktuMulai);
            setVal('modal-waktu-selesai', waktuSelesai);
            setVal('modal-total', total);
            setVal('modal-tanggal', tanggal);
        }

        var customerModal = document.getElementById('customerModal');
        if (customerModal) {
            customerModal.addEventListener('show.bs.modal', function (event) {
                populateCustomerModal(event.relatedTarget);
            });
            if (window.jQuery) {
                window.jQuery(customerModal).on('show.bs.modal', function (event) {
                    populateCustomerModal(event.relatedTarget);
                });
            }
        }

        // Finish Transaction Modal Script
        function populateFinishModal(button) {
            if (!button) return;
            var actionUrl = button.getAttribute('data-action');
            var deviceId = button.getAttribute('data-device-id');
            var deviceInfo = button.getAttribute('data-device-info');

            var form = document.getElementById('finishTransactionForm');
            if (form) form.action = actionUrl;

            var devEl = document.getElementById('modal-finish-device');
            if (devEl) devEl.textContent = deviceInfo;

            var timerElement = document.getElementById('timer-' + deviceId);
            var timerValue = '00:00:00';
            if (timerElement) {
                timerValue = timerElement.innerText.trim();
            }
            var timerSnapshot = document.getElementById('modal-finish-timer');
            if (timerSnapshot) timerSnapshot.textContent = timerValue;
        }

        var finishTransactionModal = document.getElementById('finishTransactionModal');
        if (finishTransactionModal) {
            finishTransactionModal.addEventListener('show.bs.modal', function (event) {
                populateFinishModal(event.relatedTarget);
            });
            if (window.jQuery) {
                window.jQuery(finishTransactionModal).on('show.bs.modal', function (event) {
                    populateFinishModal(event.relatedTarget);
                });
            }
        }

        // Stop Device Confirmation Function
        function confirmStopDevice(deviceId, deviceName) {
            console.log('Stopping device:', deviceId, deviceName);
            
            var nameEl = document.getElementById('stop-device-name');
            if (nameEl) nameEl.textContent = deviceName;
            
            var form = document.getElementById('stopDeviceForm');
            if (form) form.action = '/device/' + deviceId + '/stop';
            
            safeShowModal('stopDeviceModal');
        }

        // Handle Stop Device Form Submission with duplicate request protection
        var isStopSubmitting = false;
        var stopDeviceFormEl = document.getElementById('stopDeviceForm');
        if (stopDeviceFormEl) {
            stopDeviceFormEl.addEventListener('submit', function(e) {
                e.preventDefault();
                
                if (isStopSubmitting) {
                    return; // Prevent duplicate request
                }
                
                var form = e.target;
                var submitButton = form.querySelector('button[type="submit"]');
                var originalButtonText = submitButton ? submitButton.innerHTML : 'Ya, Berhenti';
                
                isStopSubmitting = true;
                if (submitButton) {
                    submitButton.disabled = true;
                    submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Memproses...';
                }
                
                var csrfToken = document.querySelector('meta[name="csrf-token"]');
                if (!csrfToken) {
                    console.error('CSRF token meta tag not found');
                    isStopSubmitting = false;
                    if (submitButton) {
                        submitButton.disabled = false;
                        submitButton.innerHTML = originalButtonText;
                    }
                    showFeedbackAlert('danger', 'CSRF token tidak ditemukan. Silakan refresh halaman.');
                    return;
                }
                
                var formData = new FormData(form);
                
                fetch(form.action, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-CSRF-TOKEN': csrfToken.getAttribute('content'),
                        'Accept': 'application/json'
                    }
                })
                .then(function(response) {
                    return response.json().then(function(data) {
                        return { ok: response.ok, status: response.status, data: data };
                    });
                })
                .then(function(res) {
                    var data = res.data;
                    console.log('Stop device response:', res.status, data);

                    if (res.ok && data.success) {
                        // 1. Close confirmation modal safely
                        safeHideModal('stopDeviceModal');
                        
                        // 2. Immediately update device status in DOM without waiting for reload
                        var deviceId = data.device_id;
                        try {
                            var statusElement = document.getElementById('status-' + deviceId);
                            if (statusElement) {
                                statusElement.innerHTML = '<i class="fa fa-circle text-success" aria-hidden="true"></i> Tersedia';
                            }
                            
                            var timerElement = document.getElementById('timer-' + deviceId);
                            if (timerElement) {
                                timerElement.textContent = '-';
                                timerElement.removeAttribute('data-lost-time-start');
                            }
                            
                            var stopButton = document.querySelector('button[onclick*="confirmStopDevice(' + deviceId + '"]');
                            if (stopButton) {
                                stopButton.remove();
                            }
                        } catch (domErr) {
                            console.warn('DOM update error ignored:', domErr);
                        }

                        // 3. Show success alert
                        showFeedbackAlert('success', data.message || 'Timer perangkat berhasil dihentikan dan status diubah menjadi tersedia.');

                        // 4. Smooth reload to sync counters and server cards
                        setTimeout(function() {
                            window.location.reload();
                        }, 1200);

                    } else {
                        safeHideModal('stopDeviceModal');
                        showFeedbackAlert('warning', data.message || 'Gagal menghentikan perangkat.');

                        if (data.message && (data.message.includes('sudah selesai') || data.message.includes('Tidak ada transaksi'))) {
                            setTimeout(function() {
                                window.location.reload();
                            }, 1000);
                        }
                    }
                })
                .catch(function(error) {
                    console.error('Network or fetch error:', error);
                    safeHideModal('stopDeviceModal');
                    showFeedbackAlert('danger', 'Terjadi kesalahan koneksi saat menghubungi server. Silakan coba lagi.');
                })
                .finally(function() {
                    isStopSubmitting = false;
                    if (submitButton) {
                        submitButton.disabled = false;
                        submitButton.innerHTML = originalButtonText;
                    }
                });
            });
        }

        // G5-B Automation Live Status Poller & Manual Action
        function pollAutomationStatus() {
            $('.automation-pollable').each(function() {
                var deviceId = $(this).data('device-id');
                if (!deviceId) return;
                $.ajax({
                    url: '/api/stations/' + deviceId + '/automation-status',
                    method: 'GET',
                    dataType: 'json',
                    success: function(res) {
                        if (res.status === 'success' && res.data) {
                            var badge = res.data.hardware_badge;
                            var badgeEl = $('#automation-badge-' + deviceId);
                            if (badge && badgeEl.length) {
                                badgeEl.attr('class', 'badge ' + badge.color_class)
                                       .html('<i class="' + badge.icon + ' mr-1"></i>' + badge.label);
                            }
                            var alertEl = $('#automation-alert-banner-' + deviceId);
                            if (res.data.alert) {
                                alertEl.removeClass('d-none');
                                alertEl.find('.alert-message').text(res.data.alert.message);
                            } else {
                                alertEl.addClass('d-none');
                            }
                        }
                    }
                });
            });
        }

        $(document).ready(function() {
            pollAutomationStatus();
            setInterval(pollAutomationStatus, 3000);

            $(document).on('click', '.btn-manual-retry', function() {
                var deviceId = $(this).data('device-id');
                var btn = $(this);
                btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');
                $.ajax({
                    url: '/api/stations/' + deviceId + '/automation/manual-action',
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                    data: { action: 'RETRY_LAST_INTENT', reason: 'Kasir manual retry via dashboard' },
                    success: function(res) {
                        btn.prop('disabled', false).html('<i class="fas fa-redo"></i> Coba Ulang');
                        pollAutomationStatus();
                    },
                    error: function(xhr) {
                        btn.prop('disabled', false).html('<i class="fas fa-redo"></i> Coba Ulang');
                        alert(xhr.responseJSON ? xhr.responseJSON.message : 'Gagal mengirim perintah retry');
                    }
                });
            });
        });

        // SR-TV-INTEGRATION-001: Admin Samsung TV Control Panel, Modals & Live Status Poller
        @if(auth()->user()->isAdmin())
        (function() {
            var presetCatalog = @json($tvPresets ?? \App\Models\TvAlertEvent::getPresetMessages());
            var activeHistoryStationId = null;
            var activeHistoryStatusUrl = null;

            function getEventBadgeClass(status) {
                switch (status) {
                    case 'PENDING': return 'badge-warning text-dark';
                    case 'DELIVERED_TO_APP': return 'badge-success';
                    case 'ACKED_BY_SIMULATOR': return 'badge-success';
                    case 'EXPIRED': return 'badge-secondary';
                    case 'CANCELLED': return 'badge-secondary';
                    case 'STALE': return 'badge-dark';
                    case 'FAILED': return 'badge-danger';
                    case 'REJECTED': return 'badge-danger';
                    default: return 'badge-secondary';
                }
            }

            function updateTvPreview() {
                var presetEl = document.getElementById('tv-alert-preset');
                var customGroup = document.getElementById('tv-alert-custom-group');
                var customInput = document.getElementById('tv-alert-custom-message');
                var charCountEl = document.getElementById('tv-alert-char-count');
                var titlePreview = document.getElementById('tv-alert-preview-title');
                var bodyPreview = document.getElementById('tv-alert-preview-body');
                if (!presetEl) return;

                var preset = presetEl.value;
                var info = presetCatalog[preset] || { title: 'PERINGATAN TV', body: '' };

                if (preset === 'CUSTOM') {
                    if (customGroup) customGroup.classList.remove('d-none');
                    var rawVal = customInput ? customInput.value : '';
                    if (charCountEl) charCountEl.textContent = String(rawVal.length);
                    if (titlePreview) titlePreview.textContent = info.title;
                    if (bodyPreview) bodyPreview.textContent = rawVal.trim() !== '' ? rawVal : '(Tulis pesan khusus Anda di atas...)';
                } else {
                    if (customGroup) customGroup.classList.add('d-none');
                    if (titlePreview) titlePreview.textContent = info.title;
                    if (bodyPreview) bodyPreview.textContent = info.body;
                }
            }

            function renderStationTvControlState(stationId, data) {
                if (!data) return;
                var volBadge = document.getElementById('tv-vol-badge-' + stationId);
                if (volBadge) {
                    var vol = (data.tv_state && typeof data.tv_state.volume !== 'undefined') ? data.tv_state.volume : data.simulated_volume;
                    volBadge.textContent = 'Vol: ' + (typeof vol !== 'undefined' ? vol : 30) + '/100';
                }

                var muteBadge = document.getElementById('tv-mute-badge-' + stationId);
                if (muteBadge) {
                    var isMute = (data.tv_state && typeof data.tv_state.mute !== 'undefined') ? data.tv_state.mute : data.simulated_muted;
                    muteBadge.className = 'badge ' + (isMute ? 'badge-danger' : 'badge-success');
                    muteBadge.textContent = isMute ? 'MUTE (SIM)' : 'UNMUTE (SIM)';
                }

                var simConn = document.getElementById('tv-sim-conn-' + stationId);
                if (simConn) {
                    var isOnline = !!data.simulator_online;
                    simConn.className = 'badge ' + (isOnline ? 'badge-success' : 'badge-secondary');
                    simConn.textContent = isOnline ? 'CONNECTED' : 'WAITING_CONSUMER';
                }

                var lastEvBadge = document.getElementById('tv-last-event-badge-' + stationId);
                if (lastEvBadge) {
                    if (data.latest_event && data.latest_event.status) {
                        var st = data.latest_event.status;
                        lastEvBadge.className = 'badge ' + getEventBadgeClass(st);
                        lastEvBadge.textContent = st;
                    } else {
                        lastEvBadge.className = 'badge badge-secondary';
                        lastEvBadge.textContent = 'BELUM ADA EVENT';
                    }
                }
            }

            function renderHistoryTable(data) {
                var tbody = document.getElementById('tv-history-table-body');
                if (!tbody) return;
                tbody.innerHTML = '';

                if (data.consumer_config) {
                    var cfgSt = document.getElementById('tv-cfg-station-id');
                    var cfgTok = document.getElementById('tv-cfg-device-token');
                    var cfgDev = document.getElementById('tv-cfg-dev-token');
                    var cfgUrl = document.getElementById('tv-cfg-poll-url');
                    var openLab = document.getElementById('tv-history-open-lab');
                    if (cfgSt) cfgSt.textContent = String(data.consumer_config.station_id || '-');
                    if (cfgTok) cfgTok.textContent = data.consumer_config.device_token || '-';
                    if (cfgDev) cfgDev.textContent = data.consumer_config.dev_token || '-';
                    if (cfgUrl) cfgUrl.textContent = data.consumer_config.poll_url || '-';
                    if (openLab && data.consumer_config.tv_lab_url) {
                        openLab.setAttribute('href', data.consumer_config.tv_lab_url);
                    }
                }

                var history = data.history || [];
                if (!history.length) {
                    var emptyRow = document.createElement('tr');
                    var emptyTd = document.createElement('td');
                    emptyTd.colSpan = 6;
                    emptyTd.className = 'text-center text-muted py-3';
                    emptyTd.textContent = 'Belum ada riwayat event kontrol TV pada station ini.';
                    emptyRow.appendChild(emptyTd);
                    tbody.appendChild(emptyRow);
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
                    var strongTitle = document.createElement('strong');
                    strongTitle.className = 'd-block text-dark';
                    strongTitle.textContent = ev.message_title || '-';
                    var spanBody = document.createElement('span');
                    spanBody.className = 'text-muted';
                    spanBody.textContent = ev.message_body || '-';
                    tdMsg.appendChild(strongTitle);
                    tdMsg.appendChild(spanBody);
                    tr.appendChild(tdMsg);

                    var tdVol = document.createElement('td');
                    tdVol.textContent = (typeof ev.simulated_volume !== 'undefined' ? ev.simulated_volume : 30) + '/100 ' + (ev.simulated_muted ? '(MUTE)' : '');
                    tr.appendChild(tdVol);

                    var tdStatus = document.createElement('td');
                    var badgeSpan = document.createElement('span');
                    badgeSpan.className = 'badge ' + getEventBadgeClass(ev.status);
                    badgeSpan.textContent = ev.status;
                    tdStatus.appendChild(badgeSpan);
                    tr.appendChild(tdStatus);

                    tbody.appendChild(tr);
                });
            }

            function pollTvControlPanels() {
                document.querySelectorAll('.tv-control-panel').forEach(function(panel) {
                    var stationId = panel.getAttribute('data-station-id');
                    var statusUrl = panel.getAttribute('data-status-url');
                    if (!stationId || !statusUrl) return;

                    fetch(statusUrl, {
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (res && res.success && res.data) {
                            renderStationTvControlState(stationId, res.data);
                            if (activeHistoryStationId && String(activeHistoryStationId) === String(stationId)) {
                                renderHistoryTable(res.data);
                            }
                        }
                    })
                    .catch(function() {});
                });
            }

            document.addEventListener('DOMContentLoaded', function() {
                var presetSelect = document.getElementById('tv-alert-preset');
                var customTextarea = document.getElementById('tv-alert-custom-message');
                if (presetSelect) presetSelect.addEventListener('change', updateTvPreview);
                if (customTextarea) customTextarea.addEventListener('input', updateTvPreview);

                // Open Send TV Alert Modal
                document.addEventListener('click', function(e) {
                    var btn = e.target.closest('.btn-open-tv-alert-modal');
                    if (!btn) return;

                    var stationId = btn.getAttribute('data-station-id');
                    var stationName = btn.getAttribute('data-station-name');
                    var alertUrl = btn.getAttribute('data-alert-url');

                    var stNameEl = document.getElementById('tv-alert-station-name');
                    if (stNameEl) stNameEl.textContent = stationName;
                    var stIdInput = document.getElementById('tv-alert-station-id');
                    if (stIdInput) stIdInput.value = stationId;
                    var urlInput = document.getElementById('tv-alert-action-url');
                    if (urlInput) urlInput.value = alertUrl;

                    if (presetSelect) presetSelect.value = 'REMAINING_10_MIN';
                    if (customTextarea) customTextarea.value = '';
                    updateTvPreview();

                    safeShowModal('tvAlertModal');
                });

                // Submit Send TV Alert Form
                var tvAlertForm = document.getElementById('tvAlertForm');
                if (tvAlertForm) {
                    tvAlertForm.addEventListener('submit', function(e) {
                        e.preventDefault();
                        var stationId = document.getElementById('tv-alert-station-id').value;
                        var actionUrl = document.getElementById('tv-alert-action-url').value;
                        var preset = document.getElementById('tv-alert-preset').value;
                        var customMsg = document.getElementById('tv-alert-custom-message').value;
                        var durationSec = parseInt(document.getElementById('tv-alert-duration').value || '8', 10);
                        var submitBtn = document.getElementById('btnSubmitTvAlert');

                        if (!actionUrl) return;
                        if (submitBtn) {
                            submitBtn.disabled = true;
                            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Mengirim...';
                        }

                        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
                        fetch(actionUrl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                                'X-CSRF-TOKEN': csrfMeta ? csrfMeta.getAttribute('content') : ''
                            },
                            body: JSON.stringify({
                                preset: preset,
                                custom_message: preset === 'CUSTOM' ? customMsg : null,
                                duration_seconds: durationSec
                            })
                        })
                        .then(function(r) {
                            return r.json().then(function(body) {
                                return { ok: r.ok, status: r.status, body: body };
                            });
                        })
                        .then(function(res) {
                            if (res.ok && res.body && res.body.success) {
                                safeHideModal('tvAlertModal');
                                var badge = document.getElementById('tv-last-event-badge-' + stationId);
                                if (badge && res.body.data) {
                                    badge.className = 'badge ' + getEventBadgeClass(res.body.data.status);
                                    badge.textContent = res.body.data.status;
                                }
                                showFeedbackAlert('success', res.body.message || 'Peringatan berhasil dicatat (PENDING).');
                                pollTvControlPanels();
                            } else {
                                var errMsg = (res.body && res.body.message) ? res.body.message : 'Gagal mengirim peringatan ke station.';
                                showFeedbackAlert('danger', errMsg);
                            }
                        })
                        .catch(function() {
                            showFeedbackAlert('danger', 'Gagal menghubungi server untuk mengirim peringatan TV.');
                        })
                        .finally(function() {
                            if (submitBtn) {
                                submitBtn.disabled = false;
                                submitBtn.innerHTML = '<i class="fas fa-paper-plane mr-1"></i> Kirim Peringatan';
                            }
                        });
                    });
                }

                // Volume & Mute Commands
                document.addEventListener('click', function(e) {
                    var btn = e.target.closest('.btn-tv-volume-cmd');
                    if (!btn || btn.disabled) return;

                    var stationId = btn.getAttribute('data-station-id');
                    var command = btn.getAttribute('data-command');
                    var volumeUrl = btn.getAttribute('data-volume-url');
                    if (!volumeUrl || !command) return;

                    btn.disabled = true;
                    var csrfMeta = document.querySelector('meta[name="csrf-token"]');

                    fetch(volumeUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': csrfMeta ? csrfMeta.getAttribute('content') : ''
                        },
                        body: JSON.stringify({ command: command })
                    })
                    .then(function(r) {
                        return r.json().then(function(body) {
                            return { ok: r.ok, status: r.status, body: body };
                        });
                    })
                    .then(function(res) {
                        if (res.ok && res.body && res.body.success && res.body.data) {
                            var d = res.body.data;
                            var volBadge = document.getElementById('tv-vol-badge-' + stationId);
                            if (volBadge) volBadge.textContent = 'Vol: ' + d.volume + '/100';
                            var muteBadge = document.getElementById('tv-mute-badge-' + stationId);
                            if (muteBadge) {
                                muteBadge.className = 'badge ' + (d.mute ? 'badge-danger' : 'badge-success');
                                muteBadge.textContent = d.mute ? 'MUTE (SIM)' : 'UNMUTE (SIM)';
                            }
                            var lastEv = document.getElementById('tv-last-event-badge-' + stationId);
                            if (lastEv && d.status) {
                                lastEv.className = 'badge ' + getEventBadgeClass(d.status);
                                lastEv.textContent = d.status;
                            }
                        } else {
                            var errMsg = (res.body && res.body.message) ? res.body.message : 'Gagal mengirim perintah volume.';
                            showFeedbackAlert('warning', errMsg);
                        }
                    })
                    .catch(function() {
                        showFeedbackAlert('danger', 'Gagal menghubungi server untuk perintah volume.');
                    })
                    .finally(function() {
                        btn.disabled = false;
                    });
                });

                // Open TV History Modal
                document.addEventListener('click', function(e) {
                    var btn = e.target.closest('.btn-open-tv-history-modal');
                    if (!btn) return;

                    var stationId = btn.getAttribute('data-station-id');
                    var stationName = btn.getAttribute('data-station-name');
                    var statusUrl = btn.getAttribute('data-status-url');
                    var labUrl = btn.getAttribute('data-lab-url');

                    activeHistoryStationId = stationId;
                    activeHistoryStatusUrl = statusUrl;

                    var stNameEl = document.getElementById('tv-history-station-name');
                    if (stNameEl) stNameEl.textContent = stationName;
                    var openLab = document.getElementById('tv-history-open-lab');
                    if (openLab && labUrl) openLab.setAttribute('href', labUrl);

                    safeShowModal('tvHistoryModal');

                    if (statusUrl) {
                        fetch(statusUrl, {
                            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                        })
                        .then(function(r) { return r.json(); })
                        .then(function(res) {
                            if (res && res.success && res.data) {
                                renderStationTvControlState(stationId, res.data);
                                renderHistoryTable(res.data);
                            }
                        });
                    }
                });

                // Poll TV control status every 2.5 seconds
                pollTvControlPanels();
                setInterval(pollTvControlPanels, 2500);
            });
        })();
        @endif
    </script>
@endsection
