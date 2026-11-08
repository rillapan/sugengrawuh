@extends('layouts.app')

@section('content')
    <!-- Page Heading -->
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">{{ $title }}</h1>
    </div>



    <!-- Content Row -->
    <div class="row">
        <!-- Earnings (Monthly) Card Example -->
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

        <!-- Earnings (Monthly) Card Example -->
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

        <!-- Pending Requests Card Example -->
        <div class="col-xl-4 col-md-6 mb-4">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                @if(auth()->user()->isKasir())
                                    Total Pendapatan Sesuai Jam Kerja Shift
                                @else
                                    Total Pendapatan Hari Ini
                                @endif
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                {{ 'Rp ' . number_format($today_pendapatan, 0, ',', '.') }}
                            </div>
                        </div>
                        <div class="col-auto">
                            <button type="button" class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#todayRevenueDetailModal" title="Lihat Detail Pembayaran Hari Ini">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Content Row -->
    <div class="row">
        <!-- Area Chart -->
        <div class="col-12">
            <div class="card shadow mb-4">
                <!-- Card Header - Dropdown -->
                <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                    <h6 class="m-0 font-weight-bold text-primary">
                        Grafik Pendapatan Hari Ini
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
            data: {!! json_encode(app('App\Http\Controllers\HomeController')->hourlyRevenueData()) !!},
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
            fetch('{{ url("/hourly-revenue-data") }}')
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
                    @if(auth()->user()->isKasir())
                        Detail Pendapatan Shift
                    @else
                        Detail Pembayaran Hari Ini
                    @endif
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                @if(auth()->user()->isKasir() && $todayRevenuePerUser->count() > 0)
                    @foreach($todayRevenuePerUser as $userRevenue)
                        @php
                            $workShift = \App\Models\WorkShift::find($userRevenue->work_shift_id);
                        @endphp
                        <div class="text-center mb-4">
                            <h4 class="text-warning">Total Pendapatan Shift</h4>
                            <h5 class="text-info">{{ $workShift ? $workShift->nama_shift : 'Shift' }} - {{ $userRevenue->name }}</h5>
                            <p class="text-muted mb-2">
                                <i class="fas fa-clock"></i> 
                                Jam Kerja: {{ $workShift ? $workShift->jam_mulai . ' - ' . $workShift->jam_selesai : '-' }}
                            </p>
                            <h2 class="font-weight-bold">Rp {{ number_format($userRevenue->revenue, 0, ',', '.') }}</h2>
                        </div>
                    @endforeach
                @else
                    <div class="text-center mb-3">
                        @if(auth()->user()->isKasir())
                            <h4 class="text-warning">Total Pendapatan Shift</h4>
                            <p class="text-muted">Belum ada pendapatan untuk shift Anda hari ini</p>
                        @else
                            <h4 class="text-warning">Total Pendapatan Hari Ini</h4>
                            <h2 class="font-weight-bold">Rp {{ number_format($today_pendapatan, 0, ',', '.') }}</h2>
                        @endif
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
