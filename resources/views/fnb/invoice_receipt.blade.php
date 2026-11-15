<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Struk F&amp;B #{{ $invoice->invoice_number }}</title>
    <style>
        body {
            font-family: 'Consolas', 'Courier New', monospace;
            font-size: 12px;
            width: 58mm;
            margin: 0;
            padding: 6px;
            color: #000;
        }
        .header {
            text-align: center;
            margin-bottom: 8px;
        }
        .header h3 {
            margin: 0;
            font-size: 15px;
            text-transform: uppercase;
        }
        .header p {
            margin: 2px 0;
            font-size: 10px;
        }
        .divider {
            border-top: 1px dashed #000;
            margin: 6px 0;
        }
        .info-table, .item-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
        }
        .info-table td, .item-table td, .item-table th {
            padding: 2px 0;
            vertical-align: top;
            text-align: left;
        }
        .text-right {
            text-align: right !important;
        }
        .text-center {
            text-align: center !important;
        }
        .total-section {
            font-weight: bold;
        }
        .footer {
            text-align: center;
            margin-top: 12px;
            font-size: 10px;
        }
        @media print {
            .no-print {
                display: none;
            }
            body {
                width: auto;
                margin: 0;
                padding: 0;
            }
        }
        .btn-action {
            display: block;
            width: 100%;
            padding: 8px;
            margin-bottom: 6px;
            text-align: center;
            text-decoration: none;
            font-family: sans-serif;
            font-size: 12px;
            border-radius: 4px;
            box-sizing: border-box;
        }
        .btn-print {
            background: #2c3e50;
            color: #fff;
        }
        .btn-back {
            background: #eee;
            color: #333;
            border: 1px solid #ccc;
        }
    </style>
</head>
<body>
    <div class="no-print">
        <a href="#" onclick="window.print(); return false;" class="btn-action btn-print">Cetak Struk F&amp;B</a>
        <a href="{{ route('fnb.queue.index') }}" class="btn-action btn-back">Kembali ke Antrean F&amp;B</a>
    </div>

    <div class="header">
        <h3>Sugeng Rawuh</h3>
        <p>PlayStation &amp; Billiard - Struk F&amp;B</p>
    </div>

    <div class="divider"></div>

    <table class="info-table">
        <tr>
            <td>No. Invoice</td>
            <td class="text-right">{{ $invoice->invoice_number }}</td>
        </tr>
        <tr>
            <td>Tanggal</td>
            <td class="text-right">{{ $invoice->paid_at ? $invoice->paid_at->format('d/m/Y H:i') : '-' }}</td>
        </tr>
        <tr>
            <td>Kasir</td>
            <td class="text-right">{{ $invoice->cashierUser ? $invoice->cashierUser->name : '-' }}</td>
        </tr>
        <tr>
            <td>Metode</td>
            <td class="text-right">{{ strtoupper(str_replace('_', ' ', $invoice->payment_method)) }}</td>
        </tr>
        @if($invoice->transaction)
        <tr>
            <td>Sesi / Device</td>
            <td class="text-right">
                {{ $invoice->transaction->nama }}
                @if($invoice->transaction->device)
                    ({{ $invoice->transaction->device->nama }})
                @endif
            </td>
        </tr>
        @else
        <tr>
            <td>Kategori</td>
            <td class="text-right">Non-Bermain / Standalone</td>
        </tr>
        @endif
    </table>

    <div class="divider"></div>

    <table class="item-table">
        <thead>
            <tr>
                <th>Item</th>
                <th class="text-center">Qty</th>
                <th class="text-right">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->items as $item)
            <tr>
                <td>
                    {{ $item->name }}<br>
                    <small>@ Rp {{ number_format($item->unit_price_jual, 0, ',', '.') }}</small>
                </td>
                <td class="text-center">{{ $item->qty }}</td>
                <td class="text-right">{{ number_format($item->subtotal, 0, ',', '.') }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="divider"></div>

    <table class="info-table total-section">
        <tr>
            <td>Subtotal</td>
            <td class="text-right">Rp {{ number_format($invoice->subtotal, 0, ',', '.') }}</td>
        </tr>
        @if($invoice->discount > 0)
        <tr>
            <td>Diskon</td>
            <td class="text-right">- Rp {{ number_format($invoice->discount, 0, ',', '.') }}</td>
        </tr>
        @endif
        <tr>
            <td>Total</td>
            <td class="text-right">Rp {{ number_format($invoice->final_total, 0, ',', '.') }}</td>
        </tr>
        <tr>
            <td>Bayar</td>
            <td class="text-right">Rp {{ number_format($invoice->amount_paid, 0, ',', '.') }}</td>
        </tr>
        <tr>
            <td>Kembali</td>
            <td class="text-right">Rp {{ number_format($invoice->change_amount, 0, ',', '.') }}</td>
        </tr>
    </table>

    <div class="divider"></div>

    <div class="footer">
        <p>Terima kasih atas pesanan Anda!</p>
    </div>
</body>
</html>
