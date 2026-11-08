@extends('layouts.app')

@section('content')
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">{{ $title }}</h1>
        <a href="{{ route('kategori-permainan.index') }}" class="d-none d-sm-inline-block btn btn-sm btn-primary shadow-sm">
            <i class="fas fa-arrow-left fa-sm text-white-50"></i> Kembali ke Daftar Kategori Permainan
        </a>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">Daftar Perangkat {{ $playstation->nama }}</h6>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered" width="100%" cellspacing="0">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Nama Perangkat</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($devices as $device)
                            <tr>
                                <td>{{ $loop->iteration }}</td>
                                <td>{{ $device->nama }}</td>
                                <td>
                                    @if($device->status === 'Tersedia')
                                        <span class="badge badge-success">Tersedia</span>
                                    @else
                                        <span class="badge badge-warning">Digunakan</span>
                                    @endif
                                </td>
                                <td>
                                   
                                    <a href="{{ route('device.edit', $device->id) }}" class="btn btn-warning btn-sm" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center">Tidak ada perangkat untuk {{ $playstation->nama }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
                {{ $devices->links() }}
            </div>
        </div>
    </div>
@endsection
