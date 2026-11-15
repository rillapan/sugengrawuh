@extends('layouts.app', ['title' => 'Kelola QR Lokasi'])

@section('content')
<div class="container-fluid">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">
            <i class="fas fa-qrcode text-primary mr-2"></i>Kelola QR Lokasi & Stasiun
        </h1>
        <button type="button" class="btn btn-primary btn-sm shadow-sm" data-toggle="modal" data-target="#modalCreateLocation">
            <i class="fas fa-plus fa-sm text-white-50 mr-1"></i> Tambah Lokasi
        </button>
    </div>

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('success') }}
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    @endif

    <div class="card shadow mb-4">
        <div class="card-header py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-primary">Daftar Lokasi & Meja</h6>
            <span class="badge badge-info">{{ $locations->count() }} Lokasi Terdaftar</span>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle" width="100%" cellspacing="0">
                    <thead class="thead-light">
                        <tr>
                            <th style="width: 50px;">ID</th>
                            <th>Nama Lokasi</th>
                            <th>Kode</th>
                            <th>Tipe</th>
                            <th>Perangkat Terhubung</th>
                            <th style="width: 120px;" class="text-center">Preview QR</th>
                            <th style="width: 90px;" class="text-center">Status</th>
                            <th style="width: 160px;" class="text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($locations as $loc)
                            <tr>
                                <td>{{ $loc->id }}</td>
                                <td class="font-weight-bold text-gray-900">{{ $loc->name }}</td>
                                <td><code>{{ $loc->code }}</code></td>
                                <td>
                                    <span class="badge badge-secondary text-uppercase">{{ $loc->type }}</span>
                                </td>
                                <td>
                                    @if ($loc->device)
                                        <span class="text-primary font-weight-bold">
                                            <i class="fas fa-tv mr-1"></i>{{ $loc->device->nama }}
                                        </span>
                                    @else
                                        <span class="text-muted small"><em>Belum terhubung</em></span>
                                    @endif
                                </td>
                                <td class="text-center p-2">
                                    <div style="width: 60px; height: 60px; margin: 0 auto; display: flex; align-items: center; justify-content: center;">
                                        {!! $loc->qr_svg !!}
                                    </div>
                                    <div class="small mt-1">
                                        <a href="{{ $loc->station_url }}" target="_blank" class="small text-muted" title="{{ $loc->station_url }}">
                                            Buka Link
                                        </a>
                                    </div>
                                </td>
                                <td class="text-center">
                                    @if ($loc->is_active)
                                        <span class="badge badge-success px-2 py-1">Aktif</span>
                                    @else
                                        <span class="badge badge-danger px-2 py-1">Nonaktif</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    <a href="{{ route('admin.locations.print', $loc->id) }}" class="btn btn-sm btn-info" title="Cetak Kartu QR" target="_blank">
                                        <i class="fas fa-print"></i> Cetak
                                    </a>
                                    <button type="button" 
                                            class="btn btn-sm btn-warning" 
                                            onclick="openEditModal({{ json_encode($loc) }})"
                                            title="Edit Lokasi">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center py-4 text-muted">
                                    Belum ada data lokasi. Silakan tambahkan lokasi baru.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal Tambah Lokasi -->
<div class="modal fade" id="modalCreateLocation" tabindex="-1" role="dialog" aria-labelledby="createModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form action="{{ route('admin.locations.store') }}" method="POST">
            @csrf
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title font-weight-bold text-primary" id="createModalLabel">Tambah Lokasi Baru</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label class="font-weight-bold small">Nama Lokasi <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" placeholder="Contoh: Bilik 1 PS5" required>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold small">Kode Unik <span class="text-danger">*</span></label>
                        <input type="text" name="code" class="form-control" placeholder="Contoh: STATION-01" required>
                        <small class="text-muted">Kode ini menjadi bagian dari URL QR Code: <code>/station/{code}</code></small>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold small">Tipe Lokasi <span class="text-danger">*</span></label>
                        <select name="type" class="form-control" required>
                            <option value="station">Stasiun Bermain (Station)</option>
                            <option value="dining_table">Meja Makan / Kafe (Dining Table)</option>
                            <option value="waiting_area">Area Menunggu (Waiting Area)</option>
                            <option value="takeaway">Bungkus / Takeaway</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold small">Perangkat Terhubung (Device PS)</label>
                        <select name="device_id" class="form-control">
                            <option value="">-- Tanpa Perangkat (Area Terpisah) --</option>
                            @foreach ($devices as $dev)
                                <option value="{{ $dev->id }}">{{ $dev->nama }}</option>
                            @endforeach
                        </select>
                        <small class="text-muted">Wajib dihubungkan jika lokasi ini merupakan bilik/meja rental PS.</small>
                    </div>
                    <div class="form-group form-check">
                        <input type="checkbox" name="is_active" value="1" class="form-check-input" id="checkActiveCreate" checked>
                        <label class="form-check-label font-weight-bold small" for="checkActiveCreate">Status Aktif</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan Lokasi</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Modal Edit Lokasi -->
<div class="modal fade" id="modalEditLocation" tabindex="-1" role="dialog" aria-labelledby="editModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form id="formEditLocation" action="" method="POST">
            @csrf
            @method('PUT')
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title font-weight-bold text-primary" id="editModalLabel">Edit Lokasi</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label class="font-weight-bold small">Nama Lokasi <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="editName" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold small">Kode Unik <span class="text-danger">*</span></label>
                        <input type="text" name="code" id="editCode" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold small">Tipe Lokasi <span class="text-danger">*</span></label>
                        <select name="type" id="editType" class="form-control" required>
                            <option value="station">Stasiun Bermain (Station)</option>
                            <option value="dining_table">Meja Makan / Kafe (Dining Table)</option>
                            <option value="waiting_area">Area Menunggu (Waiting Area)</option>
                            <option value="takeaway">Bungkus / Takeaway</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold small">Perangkat Terhubung (Device PS)</label>
                        <select name="device_id" id="editDeviceId" class="form-control">
                            <option value="">-- Tanpa Perangkat (Area Terpisah) --</option>
                            @foreach ($devices as $dev)
                                <option value="{{ $dev->id }}">{{ $dev->nama }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group form-check">
                        <input type="checkbox" name="is_active" value="1" class="form-check-input" id="editIsActive">
                        <label class="form-check-label font-weight-bold small" for="editIsActive">Status Aktif</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
    function openEditModal(location) {
        document.getElementById('formEditLocation').action = '/admin/locations/' + location.id;
        document.getElementById('editName').value = location.name;
        document.getElementById('editCode').value = location.code;
        document.getElementById('editType').value = location.type;
        document.getElementById('editDeviceId').value = location.device_id || '';
        document.getElementById('editIsActive').checked = Boolean(location.is_active);
        $('#modalEditLocation').modal('show');
    }
</script>
@endsection
