@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <!-- Page Heading -->
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">{{ $title }}</h1>
    </div>

    <!-- Filter Form -->
    <div class="row">
        <div class="col-lg-12">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Filter Laporan Transaksi</h6>
                </div>
                <div class="card-body">
                    <form method="GET" action="{{ route('transaction.report') }}">
                        @csrf
                        <div class="form-group row">
                            <label for="start_datetime" class="col-sm-2 col-form-label">Dari Tanggal & Jam</label>
                            <div class="col-sm-4">
                                <input type="datetime-local" class="form-control" id="start_datetime" name="start_datetime" value="{{ $startDateTime }}" required>
                            </div>
                            <label for="end_datetime" class="col-sm-2 col-form-label">Hingga Tanggal & Jam</label>
                            <div class="col-sm-4">
                                <input type="datetime-local" class="form-control" id="end_datetime" name="end_datetime" value="{{ $endDateTime }}" required>
                            </div>
                        </div>

                        <div class="form-group row">
                            <div class="col-sm-12">
                                <button type="submit" class="btn btn-info">Tampilkan Laporan</button>
                                <a href="{{ route('report') }}" class="btn btn-secondary">Kembali ke Laporan</a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    @if($startDateTime && $endDateTime)
    @php
        $standaloneList = $standaloneInvoices ?? collect();
        $grandTotal = $transactions->sum('total') + $standaloneList->sum('final_total');
    @endphp
    <!-- Transaction Report -->
    <div class="row">
        <div class="col-lg-12">
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">
                        Data Transaksi dari {{ \Carbon\Carbon::parse($startDateTime)->format('d M Y H:i') }} hingga {{ \Carbon\Carbon::parse($endDateTime)->format('d M Y H:i') }}
                    </h6>
                    <div>
                        <span class="badge badge-info">Total Transaksi: {{ $transactions->count() + $standaloneList->count() }}</span>
                        <span class="badge badge-success">Total Pendapatan: Rp {{ number_format($grandTotal, 0, ',', '.') }}</span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered" id="transactionTable" width="100%" cellspacing="0">
                            <thead>
                                <tr>
                                    <th class="text-center" style="width: 50px;">NO</th>
                                    <th>No. Invoice / ID Transaksi</th>
                                    <th>Nama Perangkat - Jenis Perangkat</th>
                                    <th>Operator</th>
                                    <th>Start (Mulai Transaksi)</th>
                                    <th>Stop (Selesai Bayar)</th>
                                    <th class="text-right">Harga per Jam</th>
                                    <th class="text-center">Durasi</th>
                                    <th class="text-right">Total</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php($no = 1)
                                @foreach($transactions as $transaction)
                                    <tr>
                                        <td class="text-center">{{ $no++ }}</td>
                                        <td>{{ $transaction->id_transaksi }}</td>
                                        <td>
                                            @if($transaction->device)
                                                {{ $transaction->device->nama ?? 'Unknown Device' }}
                                                @if($transaction->device->playstation)
                                                    - {{ $transaction->device->playstation->nama ?? 'Unknown Type' }}
                                                @else
                                                    - No Type
                                                @endif
                                            @else
                                                No Device
                                            @endif
                                        </td>
                                        <td>
                                            @if($transaction->user)
                                                {{ $transaction->user->name }}
                                            @else
                                                -
                                            @endif
                                        </td>
                                        <td>{{ $transaction->created_at->format('d M Y H:i:s') }}</td>
                                        <td>{{ $transaction->paid_at ? $transaction->paid_at->format('d M Y H:i:s') : '-' }}</td>
                                        <td class="text-right">Rp {{ number_format($transaction->harga, 0, ',', '.') }}</td>
                                        <td class="text-center">{{ $transaction->jam_main }} jam</td>
                                        <td class="text-right">Rp {{ number_format($transaction->total, 0, ',', '.') }}</td>
                                        <td class="text-center">
                                            <a href="{{ route('transaction.show', $transaction->id_transaksi) }}" class="btn btn-sm btn-primary" title="Detail">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            @if($transaction->payment_status == 'paid')
                                                <a href="{{ route('transaction.print', $transaction->id_transaksi) }}" class="btn btn-sm btn-success" title="Print" target="_blank">
                                                    <i class="fas fa-print"></i>
                                                </a>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr class="font-weight-bold">
                                    <td colspan="8" class="text-right">Total:</td>
                                    <td class="text-right">Rp {{ number_format($transactions->sum('total'), 0, ',', '.') }}</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if($standaloneList->isNotEmpty())
    <div class="row">
        <div class="col-lg-12">
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">Transaksi FnB Standalone</h6>
                    <div>
                        <span class="badge badge-info">Jumlah Invoice: {{ $standaloneList->count() }}</span>
                        <span class="badge badge-success">Subtotal FnB Standalone: Rp {{ number_format($standaloneList->sum('final_total'), 0, ',', '.') }}</span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered" width="100%" cellspacing="0">
                            <thead>
                                <tr>
                                    <th class="text-center" style="width: 50px;">NO</th>
                                    <th>No. Invoice</th>
                                    <th>Stasiun / Sumber</th>
                                    <th>Kasir</th>
                                    <th>Item FnB</th>
                                    <th>Waktu Bayar</th>
                                    <th class="text-right">Total</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($standaloneList as $inv)
                                    <tr>
                                        <td class="text-center">{{ $loop->iteration }}</td>
                                        <td>{{ $inv->invoice_number }}</td>
                                        <td>
                                            @if($inv->transaction && $inv->transaction->device)
                                                {{ $inv->transaction->device->nama }}
                                                @if($inv->transaction->device->playstation)
                                                    - {{ $inv->transaction->device->playstation->nama }}
                                                @endif
                                                (Sesi #{{ $inv->transaction_id }})
                                            @else
                                                Walk-in / Non-Playing
                                            @endif
                                        </td>
                                        <td>{{ $inv->cashierUser ? $inv->cashierUser->name : '-' }}</td>
                                        <td>
                                            @foreach($inv->items as $item)
                                                {{ $item->name }} ({{ $item->qty }}x)@if(!$loop->last), @endif
                                            @endforeach
                                        </td>
                                        <td>{{ $inv->paid_at ? $inv->paid_at->format('d M Y H:i:s') : '-' }}</td>
                                        <td class="text-right">Rp {{ number_format($inv->final_total, 0, ',', '.') }}</td>
                                        <td class="text-center">
                                            <a href="{{ route('fnb.invoice.show', $inv->id) }}" class="btn btn-sm btn-primary" title="Detail Invoice">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr class="font-weight-bold">
                                    <td colspan="6" class="text-right">Total FnB Standalone:</td>
                                    <td class="text-right">Rp {{ number_format($standaloneList->sum('final_total'), 0, ',', '.') }}</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif
    @endif
</div>
@endsection

@section('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize DataTable if transactions exist
        @if($startDateTime && $endDateTime && $transactions->count() > 0)
        $('#transactionTable').DataTable({
            "responsive": true,
            "lengthChange": false,
            "pageLength": 25,
            "ordering": true,
            "info": true,
            "language": {
                "search": "Cari:",
                "lengthMenu": "Tampilkan _MENU_ data",
                "info": "Menampilkan _START_ hingga _END_ dari _TOTAL_ data",
                "paginate": {
                    "first": "Pertama",
                    "last": "Terakhir",
                    "next": "Selanjutnya",
                    "previous": "Sebelumnya"
                }
            }
        });
        @endif
    });
</script>
@endsection
