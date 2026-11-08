@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-flex align-items-center justify-content-between">
                <h4 class="mb-0">{{ $title }}</h4>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Daftar Harga per Jam</h5>
                    <a href="{{ route('hourly-prices.hourly-prices.create', $playstation->id) }}" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Tambah Harga
                    </a>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-centered table-nowrap mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Durasi (Jam)</th>
                                    <th>Harga</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($hourlyPrices as $price)
                                <tr>
                                    <td>{{ $price->hour }} jam</td>
                                    <td>Rp {{ number_format($price->price, 0, ',', '.') }}</td>
                                    <td>
                                        <a href="{{ route('hourly-prices.hourly-prices.edit', [$playstation->id, $price->id]) }}" 
                                           class="btn btn-sm btn-warning">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                        <form action="{{ route('hourly-prices.hourly-prices.destroy', [$playstation->id, $price->id]) }}" 
                                              method="POST" 
                                              class="d-inline" 
                                              onsubmit="return confirm('Yakin ingin menghapus harga ini?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-danger">
                                                <i class="fas fa-trash"></i> Hapus
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="3" class="text-center">Belum ada harga per jam yang ditentukan</td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer">
                    <a href="{{ route('kategori-permainan.index') }}" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Kembali ke Kategori Permainan
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection