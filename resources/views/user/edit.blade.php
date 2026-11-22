@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">Edit User</h1>
    </div>

    <div class="card shadow mb-4">
        <div class="card-body">
            <form action="{{ route('users.update', $user->id) }}" method="POST">
                @csrf
                @method('PUT')
                <div class="form-group">
                    <label for="name">Nama (Untuk Kasir, isi dengan Nama Shift)</label>
                    <input type="text" class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name', $user->name) }}" required>
                    @error('name')
                        <span class="invalid-feedback" role="alert">
                            <strong>{{ $message }}</strong>
                        </span>
                    @enderror
                </div>
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" class="form-control @error('email') is-invalid @enderror" id="email" name="email" value="{{ old('email', $user->email) }}" required>
                    @error('email')
                        <span class="invalid-feedback" role="alert">
                            <strong>{{ $message }}</strong>
                        </span>
                    @enderror
                </div>
                <div class="form-group">
                    <label for="password">Password (Kosongkan jika tidak ingin mengubah)</label>
                    <input type="password" class="form-control @error('password') is-invalid @enderror" id="password" name="password">
                    @error('password')
                        <span class="invalid-feedback" role="alert">
                            <strong>{{ $message }}</strong>
                        </span>
                    @enderror
                </div>
                <div class="form-group">
                    <label for="password_confirmation">Konfirmasi Password</label>
                    <input type="password" class="form-control" id="password_confirmation" name="password_confirmation">
                </div>
                <!-- Prevent changing own role or locked cashier role -->
                <div class="form-group">
                    <label for="role">Role</label>
                    @if($user->isKasir())
                        <input type="text" class="form-control" value="Kasir ({{ $user->is_fnb_staff ? 'Kanal F&B' : 'Kanal Billing Utama' }})" disabled>
                        <input type="hidden" name="role" value="kasir">
                        <small class="form-text text-muted">Role kasir dan kanal operasional dikunci secara permanen demi integritas transaksi keuangan.</small>
                    @else
                        <select class="form-control @error('role') is-invalid @enderror" id="role" name="role" required {{ $user->id === auth()->id() ? 'disabled' : '' }}>
                            <option value="owner" {{ old('role', $user->role) == 'owner' ? 'selected' : '' }}>Owner</option>
                            <option value="kasir" {{ old('role', $user->role) == 'kasir' ? 'selected' : '' }}>Kasir</option>
                            <option value="admin" {{ old('role', $user->role) == 'admin' ? 'selected' : '' }}>Admin</option>
                        </select>
                        @if($user->id === auth()->id())
                            <input type="hidden" name="role" value="{{ $user->role }}">
                        @endif
                    @endif
                    @error('role')
                        <span class="invalid-feedback" role="alert">
                            <strong>{{ $message }}</strong>
                        </span>
                    @enderror
                </div>
                <div class="form-group" id="shift-group" style="display: none;">
                    <label for="shift">Shift</label>
                    <input type="text" class="form-control @error('shift') is-invalid @enderror" id="shift" name="shift" value="{{ old('shift', $user->shift) }}">
                    @error('shift')
                        <span class="invalid-feedback" role="alert">
                            <strong>{{ $message }}</strong>
                        </span>
                    @enderror
                </div>
                <div class="form-group" id="work-shift-group" style="display: none;">
                    <label for="work_shift_id">Jam Kerja</label>
                    <select class="form-control @error('work_shift_id') is-invalid @enderror" id="work_shift_id" name="work_shift_id">
                        <option value="">Pilih Jam Kerja</option>
                        @foreach($workShifts as $workShift)
                            <option value="{{ $workShift->id }}" {{ old('work_shift_id', $user->work_shift_id) == $workShift->id ? 'selected' : '' }}>
                                {{ $workShift->nama_shift }} ({{ $workShift->jam_mulai }} - {{ $workShift->jam_selesai }})
                            </option>
                        @endforeach
                    </select>
                    @error('work_shift_id')
                        <span class="invalid-feedback" role="alert">
                            <strong>{{ $message }}</strong>
                        </span>
                    @enderror
                </div>
                <div class="form-group" id="fnb-staff-group" style="display: none;">
                    @if($user->isKasir())
                        <div class="alert alert-info py-2 mb-2">
                            <i class="fas fa-lock mr-1"></i>
                            <strong>Kanal Kasir Permanen:</strong>
                            <span class="badge {{ $user->is_fnb_staff ? 'badge-success' : 'badge-primary' }}">
                                {{ $user->is_fnb_staff ? 'Kasir F&B (Cafe)' : 'Kasir Billing Utama' }}
                            </span>
                            <input type="hidden" name="is_fnb_staff" value="{{ $user->is_fnb_staff ? '1' : '0' }}">
                            <div class="small text-muted mt-1">
                                Kanal akun kasir dikunci secara permanen demi integritas audit keuangan. Jika staf berpindah tugas, buat akun baru.
                            </div>
                        </div>
                    @else
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="is_fnb_staff" name="is_fnb_staff" value="1" {{ old('is_fnb_staff', $user->is_fnb_staff) ? 'checked' : '' }}>
                            <label class="custom-control-label" for="is_fnb_staff">
                                Staf F&amp;B (Hanya akses Antrean &amp; Dapur F&amp;B; tidak bisa akses kasir/pembayaran/laporan)
                            </label>
                        </div>
                    @endif
                </div>

                <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
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
            const currentRole = roleSelect ? roleSelect.value : '{{ $user->role }}';
            if (currentRole === 'kasir') {
                shiftGroup.style.display = 'block';
                workShiftGroup.style.display = 'block';
                fnbStaffGroup.style.display = 'block';
            } else {
                shiftGroup.style.display = 'none';
                workShiftGroup.style.display = 'none';
                fnbStaffGroup.style.display = 'none';
            }
        }

        if (roleSelect) {
            roleSelect.addEventListener('change', toggleShift);
        }
        toggleShift(); 
    });
</script>
@endsection
