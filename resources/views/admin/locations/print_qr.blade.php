<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Cetak QR Kartu - {{ $location->name }} ({{ $location->code }})</title>
    <link href="{{ asset('css/offline-fonts.css') }}" rel="stylesheet">
    <link href="{{ asset('css/sb-admin-2.min.css') }}" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fc;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            margin: 0;
            padding: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }
        .action-bar {
            margin-bottom: 24px;
        }
        .qr-card {
            width: 360px;
            background: #ffffff;
            border: 3px solid #1a202c;
            border-radius: 20px;
            padding: 28px 24px;
            text-align: center;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
        }
        .brand-title {
            font-size: 20px;
            font-weight: 800;
            letter-spacing: 1px;
            color: #1a202c;
            text-transform: uppercase;
            margin-bottom: 2px;
        }
        .brand-subtitle {
            font-size: 13px;
            color: #4a5568;
            font-weight: 600;
            margin-bottom: 16px;
        }
        .location-header {
            background-color: #4e73df;
            color: #ffffff;
            padding: 8px 16px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .location-name {
            font-size: 18px;
            font-weight: 700;
            margin: 0;
        }
        .location-code {
            font-size: 12px;
            opacity: 0.9;
            letter-spacing: 1px;
        }
        .qr-box {
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 0 auto 16px auto;
            padding: 10px;
            background: #ffffff;
            border: 2px dashed #cbd5e0;
            border-radius: 12px;
            width: 290px;
            height: 290px;
        }
        .instruction {
            font-size: 13px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 8px;
            line-height: 1.4;
        }
        .url-text {
            font-size: 11px;
            color: #718096;
            word-break: break-all;
            background: #f7fafc;
            padding: 4px 8px;
            border-radius: 6px;
            border: 1px solid #edf2f7;
        }
        @media print {
            body {
                background: none;
                padding: 0;
            }
            .action-bar {
                display: none;
            }
            .qr-card {
                box-shadow: none;
                border: 2px solid #000;
                margin: 0 auto;
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body>
    <div class="action-bar">
        <button onclick="window.print()" class="btn btn-primary btn-lg shadow">
            <i class="fas fa-print mr-2"></i> Cetak Kartu QR
        </button>
        <button onclick="window.close()" class="btn btn-secondary btn-lg ml-2">
            Tutup
        </button>
    </div>

    <div class="qr-card">
        <div class="brand-title">Sugeng Rawuh</div>
        <div class="brand-subtitle">Gaming Station & Cafe</div>

        <div class="location-header">
            <div class="location-name">{{ $location->name }}</div>
            <div class="location-code">KODE: {{ $location->code }}</div>
        </div>

        <div class="qr-box">
            {!! $qrSvg !!}
        </div>

        <div class="instruction">
            📱 Scan QR Code untuk pesan makanan & minuman langsung ke stasiun Anda!
        </div>

        <div class="url-text">
            {{ $url }}
        </div>
    </div>
</body>
</html>
