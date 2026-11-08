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
            <div class="card shadow mb-4 border-left-info">
                <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                    <h6 class="m-0 font-weight-bold text-info">
                        Keamanan & Integritas Transaksi
                    </h6>
                </div>
                <div class="card-body">
                    <div class="alert alert-info mb-0">
                        <i class="fas fa-shield-alt"></i>
                        <strong>Perlindungan Data Operasional:</strong>
                        <p class="mt-2 mb-0">
                            Fitur reset massal transaksi telah dinonaktifkan demi menjaga integritas pembukuan keuangan, histori transaksi rental, dan kepatuhan audit sistem Sugeng Rawuh.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
