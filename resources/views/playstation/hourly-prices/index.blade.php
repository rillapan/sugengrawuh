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
                    <h5 class="card-title mb-0">Daftar Tarif Paket Durasi</h5>
                    <a href="{{ route('hourly-prices.hourly-prices.create', $playstation->id) }}" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Tambah Tarif
                    </a>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-centered table-nowrap mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Durasi (Jam:Menit:Detik)</th>
                                    <th>Harga Paket</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($hourlyPrices as $price)
                                <tr>
                                    <td>
                                        <code class="fw-bold fs-6 text-dark">{{ $price->formatted_duration }}</code>
                                        <span class="text-muted ms-2 small">({{ $price->human_duration }})</span>
                                    </td>
                                    <td>
                                        <strong class="text-success">Rp {{ number_format($price->price, 0, ',', '.') }}</strong>
                                    </td>
                                    <td>
                                        <a href="{{ route('hourly-prices.hourly-prices.edit', [$playstation->id, $price->id]) }}" 
                                           class="btn btn-sm btn-warning">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                        <form action="{{ route('hourly-prices.hourly-prices.destroy', [$playstation->id, $price->id]) }}" 
                                              method="POST" 
                                              class="d-inline" 
                                              onsubmit="return confirm('Yakin ingin menghapus tarif ini?')">
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
                                    <td colspan="3" class="text-center text-muted py-4">Belum ada tarif paket durasi yang ditentukan untuk kategori ini.</td>
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
