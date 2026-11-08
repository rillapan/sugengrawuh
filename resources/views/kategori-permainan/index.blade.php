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

    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">{{ $title }}</h1>
    </div>

    <div class="card shadow mb-4">
        <!-- Card Header - Dropdown -->
        <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
            <h6 class="m-0 font-weight-bold text-primary">
                Daftar Kategori Permainan
            </h6>
            <div class="dropdown no-arrow">
                <a href="{{ route('kategori-permainan.create') }}" class="btn btn-primary btn-sm">Tambah Kategori</a>
            </div>
        </div>
        <!-- Card Body -->
        <div class="card-body">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">No</th>
                            <th scope="col">Nama Permainan</th>
                            <th scope="col">Harga Dasar</th>
                            <th scope="col">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($kategori_permainan as $kategori)
                            <tr>
                                <th scope="row">
                                    {{ ($kategori_permainan->currentpage() - 1) * $kategori_permainan->perpage() + $loop->index + 1 }}
                                </th>
                                <td>{{ $kategori->nama }}</td>
                                <td>{{ 'Rp ' . number_format($kategori->harga, 0, ',', '.') }}</td>
                                <td class="d-flex">
                                    <a href="{{ route('kategori-permainan.edit', $kategori->id) }}" class="btn btn-sm btn-info mr-1" title="Edit Kategori">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="{{ route('device.byPlaystation', $kategori->id) }}" class="btn btn-sm btn-primary mr-1" title="Lihat Perangkat">
                                        <i class="fas fa-tv"></i>
                                    </a>
                                    <a href="{{ route('hourly-prices.hourly-prices.index', $kategori->id) }}" class="btn btn-sm btn-success mr-1" title="Kelola Tarif per Jam">
                                        <i class="fas fa-clock"></i>
                                    </a>
                                    <form action="{{ route('kategori-permainan.destroy', $kategori->id) }}" method="post" class="d-inline">
                                        @method('delete')
                                        @csrf
                                        <button class="btn btn-sm btn-danger" onclick="return confirm('Apakah Anda yakin ingin menghapus kategori permainan ini?')" title="Hapus Kategori">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center text-muted">Belum ada data kategori permainan.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $kategori_permainan->links() }}
        </div>
    </div>
@endsection
