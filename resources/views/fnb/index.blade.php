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
                Data Barang FnB
            </h6>
            <div class="dropdown no-arrow">
                <a class="dropdown-toggle" href="#" role="button" id="dropdownMenuLink" data-toggle="dropdown"
                    aria-haspopup="true" aria-expanded="false">
                </a>
                <a href="{{ route('fnb.create') }}" class="btn btn-primary btn-sm">Tambah Barang</a>
                <a href="{{ route('fnb.laporan') }}" class="btn btn-info btn-sm ml-2">Laporan Penjualan</a>
            </div>
        </div>
        <!-- Card Body -->
        <div class="card-body">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Gambar</th>
                        <th scope="col">Nama</th>
                        <th scope="col">Kelompok Harga</th>
                        <th scope="col">Harga Jual</th>
                        <th scope="col">Stok</th>
                        <th scope="col">Deskripsi</th>
                        <th scope="col">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($fnbs as $fnb)
                        <tr>
                            <td>
                                <img src="{{ $fnb->image_url }}" alt="{{ $fnb->nama }}" style="width: 48px; height: 48px; object-fit: cover; border-radius: 6px; border: 1px solid #e3e6f0;">
                            </td>
                            <td>{{ $fnb->nama }}</td>
                            <td>{{ $fnb->priceGroup ? $fnb->priceGroup->nama : '-' }}</td>
                            <td>Rp {{ number_format($fnb->harga_jual, 0, ',', '.') }}</td>
                            <td>
                                @if($fnb->stok == -1)
                                    <span class="badge badge-success">Unlimited</span>
                                @else
                                    {{ $fnb->stok }} {{ $fnb->satuan ?? '' }}
                                @endif
                            </td>
                            <td>{{ $fnb->deskripsi ? Str::limit($fnb->deskripsi, 50) : '' }}</td>
                            <td>
                                <a href="{{ route('fnb.edit', $fnb->id) }}" class="btn btn-warning btn-sm">Edit</a>
                                <form action="{{ route('fnb.destroy', $fnb->id) }}" method="post" class="d-inline">
                                    @method('delete')
                                    @csrf
                                    <button class="btn btn-danger btn-sm" onclick="return confirm('Anda yakin menghapus barang ini?')">Hapus</button>
                                </form>
                                <a href="{{ route('stock.history', $fnb->id) }}" class="btn btn-info btn-sm">Riwayat Stok</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            {{ $fnbs->links() }}
        </div>
    </div>
@endsection
