<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Pemesanan F&B - Sesi Belum Aktif</title>
    <link href="{{ asset('css/offline-fonts.css') }}" rel="stylesheet">
    <link href="{{ asset('css/sb-admin-2.min.css') }}" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fc;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            color: #2d3748;
        }
        .container-narrow {
            max-width: 500px;
            margin: 0 auto;
            padding: 24px 16px;
        }
        .notice-card {
            background: #ffffff;
            border-radius: 16px;
            padding: 32px 24px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            text-align: center;
        }
        .notice-icon {
            width: 72px;
            height: 72px;
            background-color: #fff3cd;
            color: #856404;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            margin-bottom: 20px;
        }
        .station-pill {
            display: inline-block;
            background-color: #e2e8f0;
            color: #4a5568;
            font-weight: 600;
            padding: 6px 16px;
            border-radius: 9999px;
            margin-bottom: 16px;
            font-size: 14px;
        }
        .btn-refresh {
            background-color: #4e73df;
            color: #ffffff;
            font-weight: 600;
            border-radius: 8px;
            padding: 12px 24px;
            border: none;
            width: 100%;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            margin-top: 20px;
        }
        .btn-refresh:hover {
            background-color: #2e59d9;
            color: #ffffff;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="container-narrow">
        <div class="notice-card">
            <div class="station-pill">
                📍 {{ $location->name }} ({{ $location->code }})
            </div>

            <div class="small font-weight-bold text-muted mb-3">
                Nama Pemain: {{ $playerName ?? 'Belum Ada Pemain' }}
            </div>

            <div class="notice-icon">
                🎮
            </div>

            <h3 class="h4 font-weight-bold mb-3 text-gray-900">Sesi Bermain Belum Aktif</h3>

            <p class="text-muted mb-4" style="line-height: 1.6;">
                Pemesanan mandiri via QR Code hanya dapat dilakukan saat sesi bermain aktif di stasiun ini. Silakan hubungi <strong>kasir</strong> atau staf F&B kami untuk mengaktifkan sesi atau memesan langsung.
            </p>

            <div class="p-3 bg-light rounded text-left small text-muted mb-3">
                <div class="font-weight-bold mb-1">💡 Petunjuk:</div>
                Setelah kasir menyalakan stasiun, muat ulang halaman ini untuk melihat menu makanan dan minuman.
            </div>

            <a href="javascript:location.reload()" class="btn-refresh">
                🔄 Muat Ulang Halaman
            </a>
        </div>
    </div>
</body>
</html>
