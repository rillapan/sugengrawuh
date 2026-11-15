<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no, viewport-fit=cover">
    <title>Pemesanan F&B - Sesi Belum Aktif | Sugeng Rawuh</title>
    <link href="{{ asset('css/offline-fonts.css') }}" rel="stylesheet">
    <style>
        :root {
            --sr-primary: #3158C9;
            --sr-primary-hover: #2749AE;
            --sr-primary-soft: #EEF2FF;
            --sr-bg: #F8FAFC;
            --sr-surface: #FFFFFF;
            --sr-text-primary: #0F172A;
            --sr-text-secondary: #64748B;
            --sr-border: #E2E8F0;
            --sr-warning-bg: #FEF3C7;
            --sr-warning-border: #FDE68A;
            --sr-warning-text: #92400E;
            --sr-radius: 12px;
            --sr-radius-sm: 8px;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            background-color: var(--sr-bg);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            color: var(--sr-text-primary);
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
        }

        .empty-container {
            width: 100%;
            max-width: 440px;
        }

        .card {
            background: var(--sr-surface);
            border: 1px solid var(--sr-border);
            border-radius: var(--sr-radius);
            padding: 28px 22px;
            box-shadow: 0 1px 3px 0 rgba(15, 23, 42, 0.05);
            text-align: center;
        }

        .station-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background-color: var(--sr-primary-soft);
            color: var(--sr-primary);
            border: 1px solid #E0E7FF;
            font-weight: 600;
            font-size: 13px;
            padding: 5px 12px;
            border-radius: 9999px;
            margin-bottom: 12px;
        }

        .station-badge svg {
            flex-shrink: 0;
        }

        .player-info {
            font-size: 13px;
            color: var(--sr-text-secondary);
            font-weight: 500;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
        }

        .player-info strong {
            color: var(--sr-text-primary);
        }

        .icon-circle {
            width: 64px;
            height: 64px;
            background-color: var(--sr-primary-soft);
            color: var(--sr-primary);
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 18px;
        }

        .card-title {
            font-size: 19px;
            font-weight: 700;
            color: var(--sr-text-primary);
            margin-bottom: 10px;
            letter-spacing: -0.01em;
        }

        .card-description {
            font-size: 14px;
            color: var(--sr-text-secondary);
            line-height: 1.6;
            margin-bottom: 20px;
        }

        .notice-box {
            background-color: var(--sr-warning-bg);
            border: 1px solid var(--sr-warning-border);
            border-radius: var(--sr-radius-sm);
            padding: 12px 14px;
            text-align: left;
            font-size: 13px;
            color: var(--sr-warning-text);
            margin-bottom: 22px;
            display: flex;
            gap: 10px;
            align-items: flex-start;
        }

        .notice-box svg {
            flex-shrink: 0;
            margin-top: 2px;
        }

        .notice-box-title {
            font-weight: 600;
            margin-bottom: 2px;
        }

        .notice-box-text {
            color: #78350F;
            line-height: 1.45;
        }

        .btn-refresh {
            background-color: var(--sr-primary);
            color: #FFFFFF;
            font-weight: 600;
            font-size: 14px;
            border-radius: var(--sr-radius-sm);
            padding: 12px 20px;
            border: none;
            width: 100%;
            min-height: 46px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: background-color 0.15s ease;
        }

        .btn-refresh:hover {
            background-color: var(--sr-primary-hover);
            color: #FFFFFF;
            text-decoration: none;
        }

        .btn-refresh:focus-visible {
            outline: 2px solid var(--sr-primary);
            outline-offset: 2px;
        }

        @media (max-width: 360px) {
            .card {
                padding: 22px 16px;
            }
            .card-title {
                font-size: 17px;
            }
        }
    </style>
</head>
<body>
    <main class="empty-container">
        <div class="card">
            <div class="station-badge">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/>
                    <circle cx="12" cy="10" r="3"/>
                </svg>
                <span>{{ $location->name }} ({{ $location->code }})</span>
            </div>

            <div class="player-info">
                Nama Pemain: {{ $playerName ?? 'Belum Ada Pemain' }}
            </div>

            <div class="icon-circle" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="6" x2="10" y1="12" y2="12"/>
                    <line x1="8" x2="8" y1="10" y2="14"/>
                    <line x1="15" x2="15.01" y1="13" y2="13"/>
                    <line x1="18" x2="18.01" y1="11" y2="11"/>
                    <rect width="20" height="12" x="2" y="6" rx="6"/>
                </svg>
            </div>

            <h1 class="card-title">Sesi Bermain Belum Aktif</h1>

            <p class="card-description">
                Pemesanan melalui QR Code tersedia saat sesi bermain aktif di stasiun ini. Silakan hubungi <strong>kasir</strong> atau staf untuk memulai sesi.
            </p>

            <div class="notice-box">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" x2="12" y1="8" y2="12"/>
                    <line x1="12" x2="12.01" y1="16" y2="16"/>
                </svg>
                <div>
                    <div class="notice-box-title">Petunjuk:</div>
                    <div class="notice-box-text">
                        Setelah kasir mengaktifkan stasiun, muat ulang halaman ini untuk melihat menu makanan dan minuman.
                    </div>
                </div>
            </div>

            <a href="javascript:location.reload()" class="btn-refresh">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/>
                    <path d="M21 3v5h-5"/>
                    <path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/>
                    <path d="M8 16H3v5"/>
                </svg>
                <span>Muat Ulang Halaman</span>
            </a>
        </div>
    </main>
</body>
</html>