@extends('layouts.app')

@section('content')
    <!-- Page Heading -->
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">{{ $title }}</h1>
        @if(auth()->user()->isAdmin() || auth()->user()->isOwner())
        <div class="d-flex align-items-center">
            <form method="GET" action="{{ route('home') }}" class="form-inline">
                <div class="input-group input-group-sm">
                    <div class="input-group-prepend">
                        <span class="input-group-text bg-white"><i class="fas fa-filter text-primary mr-1"></i> Periode:</span>
                    </div>
                    <select name="period" class="form-control form-control-sm" onchange="this.form.submit()">
                        <option value="today" {{ ($period ?? 'today') === 'today' ? 'selected' : '' }}>Hari Ini (Kalender)</option>
                        <option value="shift" {{ ($period ?? '') === 'shift' ? 'selected' : '' }}>Per Shift</option>
                    </select>
                    @if(($period ?? '') === 'shift' && isset($workShifts))
                        <select name="shift_id" class="form-control form-control-sm ml-1" onchange="this.form.submit()">
                            @foreach($workShifts as $ws)
                                <option value="{{ $ws->id }}" {{ (request('shift_id') == $ws->id || (isset($shiftPeriod) && $shiftPeriod['shift_id'] == $ws->id)) ? 'selected' : '' }}>
                                    {{ $ws->nama_shift }} ({{ \Carbon\Carbon::parse($ws->jam_mulai)->format('H:i') }} - {{ \Carbon\Carbon::parse($ws->jam_selesai)->format('H:i') }})
                                </option>
                            @endforeach
                        </select>
                    @endif
                </div>
            </form>
        </div>
        @endif
    </div>



    <!-- Content Row -->
    <div class="row">
        @if(auth()->user()->isFnbStaff())
        <!-- Quick Action: Antrean F&B -->
        <div class="col-xl-4 col-md-6 mb-4">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                Antrean Dapur F&amp;B
                            </div>
                            <a href="{{ route('fnb.queue.index') }}" class="btn btn-sm btn-primary mt-2">
                                <i class="fas fa-concierge-bell"></i> Buka Antrean Order
                            </a>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-concierge-bell fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Action: Transaksi F&B -->
        <div class="col-xl-4 col-md-6 mb-4">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                Kasir Cafe / F&amp;B
                            </div>
                            <a href="{{ route('fnb.orders.create') }}" class="btn btn-sm btn-success mt-2">
                                <i class="fas fa-cash-register"></i> Buat Pesanan Baru
                            </a>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-utensils fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        @else
        <!-- Kategori Permainan -->
        <div class="col-xl-4 col-md-6 mb-4">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                Kategori Permainan
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                {{ $play }}
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fab fa-playstation fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Data Transaksi -->
        <div class="col-xl-4 col-md-6 mb-4">
            <div class="card border-left-info shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                Data Transaksi
                            </div>
                            <div class="row no-gutters align-items-center">
                                <div class="col-auto">
                                    <div class="h5 mb-0 font-weight-bold text-gray-800">
                                        {{ $transaksi }}
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-shopping-cart fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        @endif

        <!-- Revenue Summary Card -->
        <div class="col-xl-4 col-md-6 mb-4">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                @if(auth()->user()->isFnbStaff())
                                    @if(isset($shiftPeriod) && $shiftPeriod['has_shift'])
                                        Penerimaan Kasir F&amp;B — {{ $shiftPeriod['status_label'] }}
                                    @elseif(isset($shiftPeriod) && !$shiftPeriod['has_shift'])
                                        Penerimaan Kasir F&amp;B (Tanpa Jadwal Shift)
                                    @else
                                        Penerimaan Kasir F&amp;B Hari Ini
                                    @endif
                                @elseif(auth()->user()->isKasir())
                                    @if(isset($shiftPeriod) && $shiftPeriod['has_shift'])
                                        Penerimaan Kasir Billing — {{ $shiftPeriod['status_label'] }}
                                    @elseif(isset($shiftPeriod) && !$shiftPeriod['has_shift'])
                                        Penerimaan Kasir Billing (Tanpa Jadwal Shift)
                                    @else
                                        Penerimaan Kasir Billing Hari Ini
                                    @endif
                                @else
                                    @if(isset($shiftPeriod) && $shiftPeriod['has_shift'])
                                        Penerimaan Konsolidasi Usaha — {{ $shiftPeriod['shift_name'] }} ({{ $shiftPeriod['status_label'] }})
                                    @else
                                        Penerimaan Konsolidasi Usaha Hari Ini
                                    @endif
                                @endif
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                {{ 'Rp ' . number_format($today_pendapatan, 0, ',', '.') }}
                            </div>
                            <small class="text-muted">{{ $invoiceCount ?? 0 }} invoice lunas</small>
                            @if(isset($shiftPeriod) && $shiftPeriod['has_shift'])
                                <div class="mt-1" style="font-size: 0.75rem;">
                                    <span class="badge {{ $shiftPeriod['is_active'] ? 'badge-success' : 'badge-secondary' }} text-white">
                                        {{ $shiftPeriod['status_label'] }}
                                    </span>
                                    <span class="text-dark font-weight-bold">{{ $shiftPeriod['shift_name'] }}</span>
                                    <span class="text-muted">({{ \Carbon\Carbon::parse($shiftPeriod['starts_at'])->format('d M H:i') }} - {{ \Carbon\Carbon::parse($shiftPeriod['ends_at'])->format('d M H:i') }})</span>
                                </div>
                            @elseif(isset($shiftPeriod) && !$shiftPeriod['has_shift'] && auth()->user()->isKasir())
                                <div class="mt-1" style="font-size: 0.75rem;">
                                    <span class="badge badge-warning text-dark">Tanpa Jadwal Shift</span>
                                    <span class="text-muted">Mode Kalender Hari Ini</span>
                                </div>
                            @endif
                            @if(isset($consolidatedSummary) && (auth()->user()->isAdmin() || auth()->user()->isOwner()))
                                <div class="mt-1" style="font-size: 0.75rem;">
                                    <span class="text-primary font-weight-bold">Billing:</span> Rp {{ number_format($consolidatedSummary['totals']['total_billing'] ?? 0, 0, ',', '.') }}
                                    <span class="mx-1">|</span>
                                    <span class="text-success font-weight-bold">F&amp;B:</span> Rp {{ number_format($consolidatedSummary['totals']['total_fnb'] ?? 0, 0, ',', '.') }}
                                    @if(($consolidatedSummary['totals']['total_admin'] ?? 0) > 0)
                                        <span class="mx-1">|</span>
                                        <span class="text-secondary font-weight-bold">Admin:</span> Rp {{ number_format($consolidatedSummary['totals']['total_admin'] ?? 0, 0, ',', '.') }}
                                    @endif
                                </div>
                            @endif
                        </div>
                        <div class="col-auto">
                            <button type="button" class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#todayRevenueDetailModal" title="Lihat Detail Pembayaran">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if(isset($categorySummary) && (auth()->user()->isAdmin() || auth()->user()->isOwner()))
    <div class="row mb-2">
        <div class="col-md-4 mb-3">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body py-2">
                    <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Penjualan PlayStation (Neto)</div>
                    <div class="h5 mb-0 font-weight-bold text-gray-800">Rp {{ number_format($categorySummary['categories']['PLAYSTATION']['net'] ?? 0, 0, ',', '.') }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body py-2">
                    <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Penjualan Billiard (Neto)</div>
                    <div class="h5 mb-0 font-weight-bold text-gray-800">Rp {{ number_format($categorySummary['categories']['BILLIARD']['net'] ?? 0, 0, ',', '.') }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card border-left-info shadow h-100 py-2">
                <div class="card-body py-2">
                    <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Penjualan Cafe / F&amp;B (Neto)</div>
                    <div class="h5 mb-0 font-weight-bold text-gray-800">Rp {{ number_format($categorySummary['categories']['CAFE']['net'] ?? 0, 0, ',', '.') }}</div>
                </div>
            </div>
        </div>
    </div>
    @endif

    <!-- Content Row -->
    <div class="row">
        <!-- Area Chart -->
        <div class="col-12">
            <div class="card shadow mb-4">
                <!-- Card Header - Dropdown -->
                <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                    <h6 class="m-0 font-weight-bold text-primary">
                        @if(isset($shiftPeriod) && $shiftPeriod['has_shift'])
                            Grafik Pendapatan Shift {{ $shiftPeriod['shift_name'] }} ({{ $shiftPeriod['status_label'] }})
                        @else
                            Grafik Pendapatan Hari Ini
                        @endif
                    </h6>
                    <div class="dropdown no-arrow">
                        <a class="dropdown-toggle" href="#" role="button" id="dropdownMenuLink"
                            data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                            <i class="fas fa-ellipsis-v fa-sm fa-fw text-gray-400"></i>
                        </a>
                        <div class="dropdown-menu dropdown-menu-right shadow animated--fade-in"
                            aria-labelledby="dropdownMenuLink">
                            <div class="dropdown-header">Dropdown Header:</div>
                            <a class="dropdown-item" href="#">Action</a>
                            <a class="dropdown-item" href="#">Another action</a>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item" href="#">Something else here</a>
                        </div>
                    </div>
                </div>
                <!-- Card Body -->
                <div class="card-body">
                    <div class="chart-area" style="position: relative; height: 400px;">
                        <canvas id="myAreaChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
@section('scripts')
<!-- Page level plugins -->
<script src="vendor/chart.js/Chart.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Pie Chart for PlayStation Revenue
    var ctx = document.getElementById("myPieChart2");
    if (ctx) {
        // Get current period parameters from URL
        var urlParams = new URLSearchParams(window.location.search);
        var period = urlParams.get('period') || 'today';
        var startDate = urlParams.get('start_date') || '';
        var endDate = urlParams.get('end_date') || '';
        
        // Build chart data URL with period parameters
        var chartUrl = '{{ url("/chart-pie-data") }}';
        var params = new URLSearchParams();
        if (period) params.append('period', period);
        if (startDate) params.append('start_date', startDate);
        if (endDate) params.append('end_date', endDate);
        
        if (params.toString()) {
            chartUrl += '?' + params.toString();
        }
        
        // Fetch chart data with period parameters
        fetch(chartUrl)
            .then(response => response.json())
            .then(data => {
                var myPieChart2 = new Chart(ctx, {
                    type: 'doughnut',
                    data: data,
                    options: {
                        maintainAspectRatio: false,
                        tooltips: {
                            backgroundColor: "rgb(255,255,255)",
                            bodyFontColor: "#858796",
                            borderColor: '#dddfeb',
                            borderWidth: 1,
                            xPadding: 15,
                            yPadding: 15,
                            displayColors: false,
                            caretPadding: 10,
                            callbacks: {
                                label: function(tooltipItem, data) {
                                    var label = data.labels[tooltipItem.index] || '';
                                    if (label) {
                                        return label;
                                    }
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
                        cutoutPercentage: 60,
                    },
                });
                
                // Create dynamic legend
                var legendContainer = document.getElementById('dynamicLegend');
                if (legendContainer && data.labels && data.datasets[0]) {
                    var legendHtml = '';
                    data.labels.forEach(function(label, index) {
                        var color = data.datasets[0].backgroundColor[index];
                        legendHtml += '<span class="mr-2" style="display: inline-block; width: 12px; height: 12px; background-color: ' + color + '; border-radius: 50%;"></span>';
                        legendHtml += '<span class="mr-3">' + label + '</span>';
                    });
                    legendContainer.innerHTML = legendHtml;
                }
            })
            .catch(error => console.error('Error fetching chart data:', error));
    }

    // Bar Chart for Popular FnB
    var fnbData = {!! json_encode(app('App\Http\Controllers\HomeController')->popularFnbs()) !!};
    var fnbLabels = fnbData.labels || [];
    var fnbValues = fnbData.data || [];

    if (fnbLabels.length > 0 && fnbValues.length > 0) {
        var ctx2 = document.getElementById("fnbChart");
        if (ctx2) {
            var fnbChart = new Chart(ctx2, {
                type: 'bar',
                data: {
                    labels: fnbLabels,
                    datasets: [{
                        label: 'Jumlah Terjual',
                        data: fnbValues,
                        backgroundColor: '#4e73df',
                        hoverBackgroundColor: '#2e59d9',
                        borderColor: '#4e73df',
                    }],
                },
                options: {
                    maintainAspectRatio: false,
                    layout: {
                        padding: {
                            left: 10,
                            right: 25,
                            top: 25,
                            bottom: 0
                        }
                    },
                    scales: {
                        xAxes: [{
                            gridLines: {
                                display: false,
                                drawBorder: false
                            },
                            ticks: {
                                maxTicksLimit: 6
                            },
                            maxBarThickness: 25,
                        }],
                        yAxes: [{
                            ticks: {
                                beginAtZero: true,
                                stepSize: 1,
                                userCallback: function(value) {
                                    return value.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ".");
                                }
                            },
                            gridLines: {
                                color: "rgb(234, 236, 244)",
                                zeroLineColor: "rgb(234, 236, 244)",
                                drawBorder: false,
                                borderDash: [2],
                                zeroLineBorderDash: [2]
                            }
                        }],
                    },
                    legend: {
                        display: false
                    },
                    tooltips: {
                        titleMarginBottom: 10,
                        titleFontColor: '#6e707e',
                        titleFontSize: 14,
                        backgroundColor: "rgb(255,255,255)",
                        bodyFontColor: "#858796",
                        borderColor: '#dddfeb',
                        borderWidth: 1,
                        xPadding: 15,
                        yPadding: 15,
                        displayColors: false,
                        caretPadding: 10,
                        callbacks: {
                            label: function(tooltipItem, chart) {
                                var datasetLabel = chart.datasets[tooltipItem.datasetIndex].label || '';
                                return datasetLabel + ': ' + tooltipItem.yLabel + ' pcs';
                            }
                        }
                    },
                }
            });
        }
    } else {
        // Hide the chart container if no data
        var chartContainer = document.querySelector('#fnbChart').closest('.card');
        if (chartContainer) {
            chartContainer.style.display = 'none';
        }
    }

    // Area Chart for Hourly Revenue Today
    var ctx3 = document.getElementById("myAreaChart");
    if (ctx3) {
        var myAreaChart = new Chart(ctx3, {
            type: 'line',
            data: {!! json_encode(app('App\Http\Controllers\HomeController')->hourlyRevenueData(request())) !!},
            options: {
                responsive: true,
                maintainAspectRatio: false,
                layout: {
                    padding: {
                        left: 10,
                        right: 25,
                        top: 25,
                        bottom: 0
                    }
                },
                scales: {
                    xAxes: [{
                        time: {
                            unit: 'hour'
                        },
                        gridLines: {
                            display: false,
                            drawBorder: false
                        },
                        ticks: {
                            maxTicksLimit: 24
                        }
                    }],
                    yAxes: [{
                        ticks: {
                            maxTicksLimit: 5,
                            padding: 10,
                            callback: function(value, index, values) {
                                return 'Rp ' + value.toLocaleString('id-ID');
                            }
                        },
                        gridLines: {
                            color: "rgb(234, 236, 244)",
                            zeroLineColor: "rgb(234, 236, 244)",
                            drawBorder: false,
                            borderDash: [2],
                            zeroLineBorderDash: [2]
                        }
                    }],
                },
                legend: {
                    display: false
                },
                tooltips: {
                    backgroundColor: "rgb(255,255,255)",
                    bodyFontColor: "#858796",
                    titleMarginBottom: 10,
                    titleFontColor: '#6e707e',
                    titleFontSize: 14,
                    borderColor: '#dddfeb',
                    borderWidth: 1,
                    xPadding: 15,
                    yPadding: 15,
                    displayColors: false,
                    caretPadding: 10,
                    callbacks: {
                        label: function(tooltipItem, chart) {
                            var datasetLabel = chart.datasets[tooltipItem.datasetIndex].label || '';
                            return datasetLabel + ': Rp ' + tooltipItem.yLabel.toLocaleString('id-ID');
                        }
                    }
                }
            }
        });

        // Real-time update function for Hourly Revenue
        setInterval(function() {
            var hourlyUrl = '{{ url("/hourly-revenue-data") }}' + (window.location.search ? window.location.search : '');
            fetch(hourlyUrl)
                .then(response => response.json())
                .then(data => {
                    myAreaChart.data = data;
                    myAreaChart.update();
                })
                .catch(error => console.error('Error fetching realtime chart data:', error));
        }, 5000); // 5 seconds polling
    }
});
</script>
@endsection

<!-- Today's Revenue Detail Modal -->
<div class="modal fade" id="todayRevenueDetailModal" tabindex="-1" aria-labelledby="todayRevenueDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="todayRevenueDetailModalLabel">
                    @if(auth()->user()->isFnbStaff())
                        Detail Penerimaan Kasir F&amp;B
                    @elseif(auth()->user()->isKasir())
                        Detail Penerimaan Kasir Billing
                    @else
                        Detail Penerimaan &amp; Rekonsiliasi Usaha
                    @endif
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <h4 class="text-warning">
                        @if(auth()->user()->isFnbStaff())
                            @if(isset($shiftPeriod) && $shiftPeriod['has_shift'])
                                Penerimaan Kasir F&amp;B — {{ $shiftPeriod['status_label'] }}
                            @else
                                Penerimaan Kasir F&amp;B
                            @endif
                        @elseif(auth()->user()->isKasir())
                            @if(isset($shiftPeriod) && $shiftPeriod['has_shift'])
                                Penerimaan Kasir Billing — {{ $shiftPeriod['status_label'] }}
                            @else
                                Penerimaan Kasir Billing
                            @endif
                        @else
                            @if(isset($shiftPeriod) && $shiftPeriod['has_shift'])
                                Total Penerimaan Shift {{ $shiftPeriod['shift_name'] }} ({{ $shiftPeriod['status_label'] }})
                            @else
                                Total Penerimaan Konsolidasi
                            @endif
                        @endif
                    </h4>
                    <h2 class="font-weight-bold">Rp {{ number_format($today_pendapatan, 0, ',', '.') }}</h2>
                    <small class="text-muted">{{ $invoiceCount ?? 0 }} invoice lunas</small>

                    @if(isset($shiftPeriod) && $shiftPeriod['has_shift'])
                    <div class="alert alert-info py-2 px-3 mt-3 text-left" style="font-size: 0.85rem;">
                        <div><i class="fas fa-business-time mr-1"></i> <strong>Shift:</strong> {{ $shiftPeriod['shift_name'] }} ({{ $shiftPeriod['status_label'] }})</div>
                        <div><i class="fas fa-calendar-alt mr-1"></i> <strong>Tanggal Operasional:</strong> {{ \Carbon\Carbon::parse($shiftPeriod['operational_date'])->translatedFormat('d F Y') }}</div>
                        <div><i class="fas fa-clock mr-1"></i> <strong>Jam Operasional:</strong> {{ $shiftPeriod['starts_at'] }} s/d {{ $shiftPeriod['ends_at'] }} WIB</div>
                        <div><i class="fas fa-info-circle mr-1"></i> <strong>Status Periode:</strong> <span class="badge {{ $shiftPeriod['is_active'] ? 'badge-success' : 'badge-secondary' }}">{{ $shiftPeriod['status_label'] }}</span></div>
                    </div>
                    @elseif(isset($shiftPeriod) && !$shiftPeriod['has_shift'] && auth()->user()->isKasir())
                    <div class="alert alert-warning py-2 px-3 mt-3 text-left" style="font-size: 0.85rem;">
                        <div><i class="fas fa-exclamation-triangle mr-1"></i> <strong>Akun Belum Memiliki Jadwal Shift</strong></div>
                        <div class="text-muted mt-1">Laporan penerimaan menggunakan periode kalender hari ini (00:00:00 - 23:59:59). Hubungi Administrator untuk menetapkan jam kerja shift Anda.</div>
                    </div>
                    @endif
                </div>

                @if(isset($consolidatedSummary) && (auth()->user()->isAdmin() || auth()->user()->isOwner()))
                <hr>
                <div class="channel-breakdown mb-3">
                    <h6 class="text-muted mb-2 font-weight-bold">Rekapitulasi Per Kanal Kasir:</h6>
                    <div class="list-group">
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <span>Kasir Billing Utama</span>
                            <span class="font-weight-bold text-primary">Rp {{ number_format($consolidatedSummary['totals']['total_billing'], 0, ',', '.') }}</span>
                        </div>
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <span>Kasir F&amp;B</span>
                            <span class="font-weight-bold text-success">Rp {{ number_format($consolidatedSummary['totals']['total_fnb'], 0, ',', '.') }}</span>
                        </div>
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <span>Penerimaan Admin</span>
                            <span class="font-weight-bold text-info">Rp {{ number_format($consolidatedSummary['totals']['total_admin'], 0, ',', '.') }}</span>
                        </div>
                        @if($consolidatedSummary['totals']['total_legacy_unattributed'] > 0)
                        <div class="list-group-item d-flex justify-content-between align-items-center bg-light">
                            <span>Belum Teratribusi (Transaksi Legacy)</span>
                            <span class="font-weight-bold text-secondary">Rp {{ number_format($consolidatedSummary['totals']['total_legacy_unattributed'], 0, ',', '.') }}</span>
                        </div>
                        @endif
                        <div class="list-group-item d-flex justify-content-between align-items-center bg-light">
                            <span class="font-weight-bold">Grand Total Usaha</span>
                            <span class="font-weight-bold text-dark">Rp {{ number_format($consolidatedSummary['totals']['grand_total'], 0, ',', '.') }}</span>
                        </div>
                    </div>
                </div>
                @endif
                
                <hr>

                <!-- Revenue Per User Section -->
                @if(isset($todayRevenuePerUser) && $todayRevenuePerUser->count() > 0)
                    @if(!auth()->user()->isKasir())
                        <div class="revenue-per-user-details mb-4">
                            <h6 class="text-muted mb-3 font-weight-bold">Pendapatan Per Kasir:</h6>
                            <div class="list-group">
                                @foreach($todayRevenuePerUser as $userRevenue)
                                <div class="list-group-item d-flex justify-content-between align-items-center">
                                    <div>
                                        <span class="font-weight-bold">{{ $userRevenue->name }}</span>
                                        <small class="text-muted d-block">
                                            {{ ucfirst($userRevenue->role) }}
                                            @if($userRevenue->role === 'kasir' && $userRevenue->work_shift_id)
                                                @php
                                                    $workShift = \App\Models\WorkShift::find($userRevenue->work_shift_id);
                                                @endphp
                                                @if($workShift)
                                                    - {{ $workShift->nama_shift }} ({{ $workShift->jam_mulai }} - {{ $workShift->jam_selesai }})
                                                @endif
                                            @endif
                                        </small>
                                    </div>
                                    <span class="badge badge-success badge-pill" style="font-size: 1rem;">
                                        Rp {{ number_format($userRevenue->revenue, 0, ',', '.') }}
                                    </span>
                                </div>
                                @endforeach
                            </div>
                        </div>
                        <hr>
                    @endif
                @endif
                
                <div class="payment-method-details">
                    <h6 class="text-muted mb-3">Jenis Pembayaran:</h6>
                    
                    @if($todayPaymentMethodCounts['tunai'] > 0)
                    <div class="payment-method-item mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="fw-bold">Pembayaran Tunai</span>
                            <span class="badge bg-primary">{{ $todayPaymentMethodCounts['tunai'] }}</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted">Total Uang</span>
                            <span class="fw-bold text-success">Rp {{ number_format($todayPaymentMethodTotals['tunai'], 0, ',', '.') }}</span>
                        </div>
                    </div>
                    @endif
                    
                    @if($todayPaymentMethodCounts['e-wallet'] > 0)
                    <div class="payment-method-item mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="fw-bold">Pembayaran E-Wallet</span>
                            <span class="badge bg-success">{{ $todayPaymentMethodCounts['e-wallet'] }}</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted">Total Uang</span>
                            <span class="fw-bold text-success">Rp {{ number_format($todayPaymentMethodTotals['e-wallet'], 0, ',', '.') }}</span>
                        </div>
                    </div>
                    @endif
                    
                    @if($todayPaymentMethodCounts['transfer_bank'] > 0)
                    <div class="payment-method-item mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="fw-bold">Pembayaran Transfer Bank</span>
                            <span class="badge bg-warning">{{ $todayPaymentMethodCounts['transfer_bank'] }}</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted">Total Uang</span>
                            <span class="fw-bold text-success">Rp {{ number_format($todayPaymentMethodTotals['transfer_bank'], 0, ',', '.') }}</span>
                        </div>
                    </div>
                    @endif
                    
                    @if(($todayPaymentMethodCounts['other'] ?? 0) > 0)
                    <div class="payment-method-item mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="fw-bold">Pembayaran Lainnya</span>
                            <span class="badge bg-secondary">{{ $todayPaymentMethodCounts['other'] }}</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted">Total Uang</span>
                            <span class="fw-bold text-success">Rp {{ number_format($todayPaymentMethodTotals['other'] ?? 0, 0, ',', '.') }}</span>
                        </div>
                    </div>
                    @endif
                    
                    @if($today_pendapatan == 0)
                    <div class="text-center text-muted">
                        <i class="fas fa-info-circle fa-3x mb-3"></i>
                        <p>Belum ada pendapatan hari ini</p>
                    </div>
                    @endif
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

@endsection
