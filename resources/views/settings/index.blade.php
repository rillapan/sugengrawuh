@extends('layouts.app')

@section('content')
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

    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">{{ $title }}</h1>
    </div>

    <div class="row">
        <div class="col-md-6">
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                    <h6 class="m-0 font-weight-bold text-primary">
                        Informasi Aplikasi
                    </h6>
                </div>
                <div class="card-body">
                    <div class="mb-2">
                        <strong>Nama Aplikasi</strong><br>
                        <span>{{ $appName }}</span>
                    </div>
                    <div class="mb-2">
                        <strong>Versi Aplikasi</strong><br>
                        <span>{{ $appVersion }}</span>
                    </div>
                    <div class="mb-2">
                        <strong>Teknologi yang Digunakan</strong><br>
                        <span>{{ $tools }}</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card shadow mb-4 border-left-danger">
                <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                    <h6 class="m-0 font-weight-bold text-danger">
                        <i class="fas fa-trash-alt mr-1"></i> Reset Data Penjualan & Transaksi
                    </h6>
                </div>
                <div class="card-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle mr-1"></i>
                        <strong>Peringatan Penting:</strong>
                        <p class="mt-2 mb-0 small">
                            Tindakan ini bersifat <strong>permanen</strong>. Seluruh riwayat transaksi rental, pesanan FnB, invoice, dan laporan penjualan akan dihapus bersih.
                            <br><br>
                            Data <strong>User/Kasir</strong>, <strong>Perangkat (Device)</strong>, <strong>Hardware</strong>, dan <strong>Master FnB/Kelompok Harga</strong> <u>TIDAK</u> akan terhapus.
                            Seluruh status perangkat akan dikembalikan menjadi <strong>Tersedia</strong>.
                        </p>
                    </div>

                    <form method="POST" action="{{ route('settings.reset-transactions') }}">
                        @csrf
                        <div class="form-group">
                            <label for="password_confirm" class="small font-weight-bold text-gray-700">Konfirmasi Password Admin:</label>
                            <input type="password" 
                                   class="form-control form-control-sm @error('password') is-invalid @enderror" 
                                   id="password_confirm" 
                                   name="password" 
                                   placeholder="Masukkan password akun admin Anda"
                                   required>
                            @error('password')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="confirm_delete" name="confirm_delete" value="1" required>
                            <label class="form-check-label small" for="confirm_delete">
                                Saya mengerti dan setuju untuk mereset seluruh data penjualan dan transaksi.
                            </label>
                        </div>

                        <button type="submit" class="btn btn-danger btn-sm"
                            onclick="return confirm('PERINGATAN TERAKHIR: Semua data transaksi dan laporan penjualan akan dihapus secara permanen. Apakah Anda yakin ingin melanjutkan?')">
                            <i class="fas fa-trash-alt mr-1"></i> Reset Data Penjualan
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
