@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">Tambah User</h1>
    </div>

    <div class="card shadow mb-4">
        <div class="card-body">
            <form action="{{ route('users.store') }}" method="POST">
                @csrf
                <div class="form-group">
                    <label for="name">Nama (Untuk Kasir, isi dengan Nama Shift, e.g., Shift Pagi)</label>
                    <input type="text" class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name') }}" required>
                    @error('name')
                        <span class="invalid-feedback" role="alert">
                            <strong>{{ $message }}</strong>
                        </span>
                    @enderror
                </div>
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" class="form-control @error('email') is-invalid @enderror" id="email" name="email" value="{{ old('email') }}" required>
                    @error('email')
                        <span class="invalid-feedback" role="alert">
                            <strong>{{ $message }}</strong>
                        </span>
                    @enderror
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" class="form-control @error('password') is-invalid @enderror" id="password" name="password" required>
                    @error('password')
                        <span class="invalid-feedback" role="alert">
                            <strong>{{ $message }}</strong>
                        </span>
                    @enderror
                </div>
                <div class="form-group">
                    <label for="password_confirmation">Konfirmasi Password</label>
                    <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" required>
                </div>
                <div class="form-group">
                    <label for="role">Role</label>
                    <select class="form-control @error('role') is-invalid @enderror" id="role" name="role" required>
                        <option value="">Pilih Role</option>
                        <option value="owner" {{ old('role') == 'owner' ? 'selected' : '' }}>Owner</option>
                        <option value="kasir" {{ old('role') == 'kasir' ? 'selected' : '' }}>Kasir</option>
                        <option value="admin" {{ old('role') == 'admin' ? 'selected' : '' }}>Admin</option>
                    </select>
                    @error('role')
                        <span class="invalid-feedback" role="alert">
                            <strong>{{ $message }}</strong>
                        </span>
                    @enderror
                </div>
                <div class="form-group" id="shift-group" style="display: none;">
                    <label for="shift">Shift</label>
                    <input type="text" class="form-control @error('shift') is-invalid @enderror" id="shift" name="shift" value="{{ old('shift') }}" placeholder="Contoh: Pagi, Siang, Malam">
                    @error('shift')
                        <span class="invalid-feedback" role="alert">
                            <strong>{{ $message }}</strong>
                        </span>
                    @enderror
                </div>
                <div class="form-group" id="work-shift-group" style="display: none;">
                    <label for="work_shift_id">Jam Kerja</label>
                    <div class="d-flex align-items-center">
                        <select class="form-control @error('work_shift_id') is-invalid @enderror mr-2" id="work_shift_id" name="work_shift_id">
                            <option value="">Pilih Jam Kerja</option>
                            @foreach($workShifts as $workShift)
                                <option value="{{ $workShift->id }}" {{ old('work_shift_id') == $workShift->id ? 'selected' : '' }}>
                                    {{ $workShift->nama_shift }} ({{ $workShift->jam_mulai }} - {{ $workShift->jam_selesai }})
                                </option>
                            @endforeach
                        </select>
                        <a href="{{ route('work_shifts.create') }}" class="btn btn-sm btn-outline-primary" title="Tambah Jam Kerja Baru">
                            <i class="fas fa-plus"></i>
                        </a>
                    </div>
                    @error('work_shift_id')
                        <span class="invalid-feedback" role="alert">
                            <strong>{{ $message }}</strong>
                        </span>
                    @enderror
                    <small class="text-muted">Klik tombol + untuk menambahkan jam kerja baru</small>
                </div>
                <div class="form-group" id="fnb-staff-group" style="display: none;">
                    <div class="custom-control custom-checkbox">
                        <input type="checkbox" class="custom-control-input" id="is_fnb_staff" name="is_fnb_staff" value="1" {{ old('is_fnb_staff') ? 'checked' : '' }}>
                        <label class="custom-control-label" for="is_fnb_staff">
                            Staf F&amp;B (Hanya akses Antrean &amp; Dapur F&amp;B; tidak bisa akses kasir/pembayaran/laporan)
                        </label>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">Simpan</button>
                <a href="{{ route('users.index') }}" class="btn btn-secondary">Batal</a>
            </form>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const roleSelect = document.getElementById('role');
        const shiftGroup = document.getElementById('shift-group');
        const workShiftGroup = document.getElementById('work-shift-group');
        const fnbStaffGroup = document.getElementById('fnb-staff-group');

        function toggleShift() {
            if (roleSelect.value === 'kasir') {
                shiftGroup.style.display = 'block';
                workShiftGroup.style.display = 'block';
                fnbStaffGroup.style.display = 'block';
            } else {
                shiftGroup.style.display = 'none';
                workShiftGroup.style.display = 'none';
                fnbStaffGroup.style.display = 'none';
            }
        }

        roleSelect.addEventListener('change', toggleShift);
        toggleShift(); // Run on load in case of validation redirect
    });
</script>
@endsection
