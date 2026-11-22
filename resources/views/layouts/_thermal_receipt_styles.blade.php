<style>
    /* ========================================================
       Standardisasi Struk Thermal 80mm — Sugeng Rawuh POS
       Paper Roll: 80mm | Printable Content: ~72mm
       ======================================================== */

    @page {
        size: 80mm auto;
        margin: 0mm;
    }

    * {
        box-sizing: border-box;
        margin: 0;
        padding: 0;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    html, body {
        width: 100%;
        margin: 0;
        padding: 0;
        background-color: #f1f5f9;
        color: #000;
        font-family: 'Consolas', 'Courier New', 'Courier', monospace;
        font-size: 11px;
        line-height: 1.35;
    }

    /* Screen Preview: Centered thermal receipt preview */
    .receipt-container {
        width: 80mm;
        margin: 15px auto;
        padding: 4mm;
        background: #ffffff;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.12);
        border-radius: 3px;
    }

    .receipt-body {
        width: 72mm;
        margin: 0 auto;
    }

    /* Header */
    .receipt-header {
        text-align: center;
        margin-bottom: 4px;
    }

    .receipt-header .store-title {
        font-size: 14px;
        font-weight: bold;
        letter-spacing: 0.5px;
        text-transform: uppercase;
        margin: 0 0 2px 0;
    }

    .receipt-header .store-subtitle {
        font-size: 11px;
        font-weight: bold;
        margin: 0 0 2px 0;
    }

    .receipt-header .store-meta {
        font-size: 9.5px;
        line-height: 1.25;
        margin: 1px 0;
        color: #111;
    }

    /* Dividers */
    .receipt-divider {
        border-top: 1px dashed #000;
        margin: 4px 0;
    }

    .receipt-divider-double {
        border-top: 2px dashed #000;
        margin: 5px 0;
    }

    /* Info Table (Metadata) */
    .receipt-info-table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
        font-size: 9.5px;
        margin: 2px 0;
    }

    .receipt-info-table td {
        padding: 1px 0;
        vertical-align: top;
    }

    .receipt-info-table .info-label {
        width: 38%;
        text-align: left;
        white-space: nowrap;
        color: #222;
    }

    .receipt-info-table .info-value {
        width: 62%;
        text-align: right;
        font-weight: 500;
        word-break: break-all;
        overflow-wrap: anywhere;
    }

    /* Item Table (Unified 2-row layout) */
    .receipt-item-table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
        font-size: 10px;
        margin: 2px 0;
    }

    .receipt-item-table th {
        padding: 2px 0 3px 0;
        font-size: 9.5px;
        font-weight: bold;
        text-transform: uppercase;
        border-bottom: 1px dashed #000;
    }

    .receipt-item-table th.col-desc {
        width: 58%;
        text-align: left;
    }

    .receipt-item-table th.col-amount {
        width: 42%;
        text-align: right;
    }

    .receipt-item-table td {
        padding: 1px 0;
        vertical-align: top;
    }

    .receipt-item-table .item-title-row td {
        padding-top: 3px;
        padding-bottom: 1px;
    }

    .receipt-item-table .item-name {
        font-weight: 600;
        word-break: break-word;
        overflow-wrap: break-word;
        font-size: 10.5px;
        line-height: 1.25;
    }

    .receipt-item-table .item-detail-row td {
        padding-bottom: 2px;
    }

    .receipt-item-table .item-calc {
        width: 58%;
        padding-left: 2mm;
        font-size: 9.5px;
        color: #222;
        word-break: break-word;
    }

    .receipt-item-table .item-subtotal {
        width: 42%;
        text-align: right;
        white-space: nowrap;
        font-weight: 600;
        font-size: 10px;
    }

    /* Total Section Table */
    .receipt-total-table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
        font-size: 10px;
        margin-top: 2px;
    }

    .receipt-total-table td {
        padding: 1.5px 0;
        vertical-align: top;
    }

    .receipt-total-table .total-label {
        width: 48%;
        text-align: left;
    }

    .receipt-total-table .total-value {
        width: 52%;
        text-align: right;
        white-space: nowrap;
        font-weight: 600;
    }

    .receipt-total-table .grand-total-row td {
        font-size: 12px;
        font-weight: bold;
        padding: 3px 0;
    }

    /* Footer */
    .receipt-footer {
        text-align: center;
        margin-top: 6px;
        font-size: 9.5px;
        line-height: 1.35;
        color: #222;
    }

    .receipt-footer p {
        margin: 2px 0;
    }

    .receipt-notice {
        font-size: 9px;
        color: #555;
        margin-top: 4px;
    }

    /* Screen-only UI Controls (Buttons) */
    .no-print {
        width: 80mm;
        margin: 0 auto 10px auto;
    }

    .receipt-btn-group {
        display: flex;
        gap: 8px;
    }

    .receipt-btn {
        flex: 1;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 8px 12px;
        text-align: center;
        text-decoration: none;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        font-size: 12px;
        font-weight: 600;
        border-radius: 4px;
        cursor: pointer;
        box-sizing: border-box;
        border: none;
        transition: background 0.15s ease;
    }

    .btn-thermal-print {
        background-color: #1e293b;
        color: #ffffff;
    }

    .btn-thermal-print:hover {
        background-color: #0f172a;
        color: #ffffff;
    }

    .btn-thermal-back {
        background-color: #e2e8f0;
        color: #334155;
        border: 1px solid #cbd5e1;
    }

    .btn-thermal-back:hover {
        background-color: #cbd5e1;
        color: #1e293b;
    }

    .print-status-banner {
        text-align: center;
        margin-top: 15px;
        font-size: 11px;
        color: #64748b;
    }

    /* ========================================================
       Print-Specific CSS: Strict 80mm roll, 72mm printable width,
       zero margins, suppresses browser headers/footers
       ======================================================== */
    @media print {
        html, body {
            width: 80mm !important;
            margin: 0 !important;
            padding: 0 !important;
            background: transparent !important;
        }

        .no-print {
            display: none !important;
            height: 0 !important;
            margin: 0 !important;
            padding: 0 !important;
        }

        .receipt-container {
            width: 80mm !important;
            max-width: 80mm !important;
            min-width: 80mm !important;
            margin: 0 !important;
            padding: 1mm 4mm 2mm 4mm !important;
            background: transparent !important;
            box-shadow: none !important;
            border: none !important;
            border-radius: 0 !important;
        }

        .receipt-body {
            width: 72mm !important;
            max-width: 72mm !important;
            min-width: 72mm !important;
            margin: 0 auto !important;
            padding: 0 !important;
        }
    }
</style>
