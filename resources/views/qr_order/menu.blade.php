<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no, viewport-fit=cover">
    <title>Menu Pemesanan F&B - {{ $location->name }} | Sugeng Rawuh</title>
    <link href="{{ asset('css/offline-fonts.css') }}" rel="stylesheet">
    <style>
        :root {
            --sr-primary: #3158C9;
            --sr-primary-hover: #2749AE;
            --sr-primary-active: #1E3A8A;
            --sr-primary-soft: #EEF2FF;
            --sr-primary-border: #C7D2FE;
            --sr-bg: #F8FAFC;
            --sr-surface: #FFFFFF;
            --sr-header-bg: #0F172A;
            --sr-text-primary: #0F172A;
            --sr-text-secondary: #64748B;
            --sr-text-muted: #94A3B8;
            --sr-border: #E2E8F0;
            --sr-border-light: #F1F5F9;
            --sr-success: #15803D;
            --sr-success-soft: #DCFCE7;
            --sr-success-border: #BBF7D0;
            --sr-warning: #B45309;
            --sr-warning-soft: #FEF3C7;
            --sr-warning-border: #FDE68A;
            --sr-danger: #B91C1C;
            --sr-danger-soft: #FEE2E2;
            --sr-danger-border: #FECACA;
            --sr-info: #0369A1;
            --sr-info-soft: #E0F2FE;
            --sr-info-border: #BAE6FD;
            --sr-radius: 12px;
            --sr-radius-sm: 8px;
            --sr-radius-xs: 6px;
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
            padding-bottom: 105px;
            overflow-x: hidden;
        }

        body.modal-open {
            overflow: hidden;
        }

        .container-app {
            width: 100%;
            max-width: 640px;
            margin: 0 auto;
            padding: 0 16px;
        }

        /* Compact Understated Header */
        .header-card {
            background-color: var(--sr-header-bg);
            color: #FFFFFF;
            border-bottom: 1px solid #1E293B;
            padding: 16px 0 18px;
            margin-bottom: 16px;
        }

        .header-top-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 12px;
        }

        .location-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255, 255, 255, 0.1);
            color: #F8FAFC;
            border: 1px solid rgba(255, 255, 255, 0.16);
            padding: 5px 10px;
            border-radius: 9999px;
            font-size: 12px;
            font-weight: 600;
            min-width: 0;
            flex: 1 1 auto;
        }

        .location-pill span {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .location-pill svg {
            flex-shrink: 0;
            color: #93C5FD;
        }

        .btn-header-orders {
            background: #FFFFFF;
            color: var(--sr-primary);
            border: 1px solid transparent;
            border-radius: 9999px;
            padding: 5px 12px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            cursor: pointer;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
            transition: all 0.15s ease;
            text-decoration: none;
            flex-shrink: 0;
            min-height: 34px;
        }

        .btn-header-orders:hover {
            background: #F1F5F9;
            color: var(--sr-primary-hover);
        }

        .btn-header-orders:focus-visible {
            outline: 2px solid #93C5FD;
            outline-offset: 2px;
        }

        .btn-header-orders svg {
            flex-shrink: 0;
        }

        .badge-count {
            background: var(--sr-primary);
            color: #FFFFFF;
            font-size: 11px;
            font-weight: 700;
            border-radius: 9999px;
            padding: 1px 7px;
            min-width: 18px;
            text-align: center;
            line-height: 1.4;
        }

        .header-meta-row {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: #94A3B8;
            margin-bottom: 12px;
            padding: 6px 10px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: var(--sr-radius-xs);
        }

        .header-meta-row svg {
            flex-shrink: 0;
            color: #94A3B8;
        }

        .header-player-name {
            color: #FFFFFF;
            font-weight: 600;
        }

        .header-title-box {
            text-align: left;
        }

        .header-title {
            font-size: 18px;
            font-weight: 700;
            color: #FFFFFF;
            letter-spacing: -0.01em;
            margin-bottom: 2px;
        }

        .header-subtitle {
            font-size: 13px;
            color: #94A3B8;
        }

        /* Order Form Card */
        .order-form-card {
            background: var(--sr-surface);
            border: 1px solid var(--sr-border);
            border-radius: var(--sr-radius);
            padding: 16px;
            margin-bottom: 20px;
            box-shadow: 0 1px 2px 0 rgba(15, 23, 42, 0.04);
        }

        .form-group {
            margin-bottom: 14px;
        }

        .form-group:last-child {
            margin-bottom: 0;
        }

        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #334155;
            margin-bottom: 6px;
        }

        .form-label .required-mark {
            color: var(--sr-danger);
            font-weight: 700;
        }

        .form-control {
            width: 100%;
            height: 42px;
            padding: 8px 12px;
            font-size: 14px;
            font-family: inherit;
            color: var(--sr-text-primary);
            background-color: #FFFFFF;
            border: 1px solid var(--sr-border);
            border-radius: var(--sr-radius-sm);
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--sr-primary);
            box-shadow: 0 0 0 3px rgba(49, 88, 201, 0.15);
        }

        .form-control::placeholder {
            color: #94A3B8;
        }

        /* Catalog Section */
        .catalog-section-title {
            font-size: 15px;
            font-weight: 700;
            color: var(--sr-text-primary);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .fnb-catalog-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-bottom: 24px;
        }

        .fnb-card {
            background: var(--sr-surface);
            border: 1px solid var(--sr-border);
            border-radius: var(--sr-radius);
            padding: 12px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
            box-shadow: 0 1px 2px 0 rgba(15, 23, 42, 0.03);
        }

        .fnb-card.has-selected {
            border-color: var(--sr-primary-border);
            background-color: #FAFCFF;
        }

        .fnb-card-left {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
            flex: 1 1 auto;
        }

        .fnb-img-wrapper {
            width: 64px;
            height: 64px;
            min-width: 64px;
            border-radius: var(--sr-radius-sm);
            overflow: hidden;
            background: #F1F5F9;
            border: 1px solid var(--sr-border);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            cursor: pointer;
            flex-shrink: 0;
            user-select: none;
            transition: transform 0.12s ease;
        }

        .fnb-img-wrapper:active {
            transform: scale(0.97);
        }

        .fnb-img-wrapper:focus-visible {
            outline: 2px solid var(--sr-primary);
            outline-offset: 2px;
        }

        .fnb-img-wrapper img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .fnb-img-zoom-badge {
            position: absolute;
            bottom: 3px;
            right: 3px;
            background: rgba(15, 23, 42, 0.7);
            color: #FFFFFF;
            border-radius: 4px;
            width: 18px;
            height: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            pointer-events: none;
        }

        .fnb-info {
            min-width: 0;
            flex: 1 1 auto;
        }

        .fnb-name {
            font-size: 15px;
            font-weight: 600;
            color: var(--sr-text-primary);
            line-height: 1.35;
            margin-bottom: 3px;
            word-break: break-word;
        }

        .fnb-price {
            font-size: 14px;
            font-weight: 700;
            color: var(--sr-primary);
            margin-bottom: 2px;
        }

        .fnb-stock {
            font-size: 12px;
            color: var(--sr-text-secondary);
        }

        .stock-available {
            color: var(--sr-success);
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .stock-dot {
            width: 6px;
            height: 6px;
            background-color: var(--sr-success);
            border-radius: 50%;
            display: inline-block;
        }

        .stock-low {
            color: var(--sr-warning);
            font-weight: 600;
        }

        .stock-normal {
            color: var(--sr-text-secondary);
        }

        /* Quantity Stepper */
        .qty-stepper {
            display: flex;
            align-items: center;
            background: #F8FAFC;
            border: 1px solid var(--sr-border);
            border-radius: var(--sr-radius-sm);
            overflow: hidden;
            flex-shrink: 0;
            height: 38px;
        }

        .qty-btn {
            background: transparent;
            border: none;
            width: 36px;
            height: 38px;
            color: var(--sr-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            user-select: none;
            transition: background-color 0.1s ease;
        }

        .qty-btn:active {
            background-color: #E2E8F0;
        }

        .qty-btn:focus-visible {
            outline: 2px solid var(--sr-primary);
            outline-offset: -2px;
        }

        .qty-input {
            width: 34px;
            text-align: center;
            border: none;
            background: transparent;
            font-weight: 700;
            font-size: 14px;
            color: var(--sr-text-primary);
            padding: 0;
            -moz-appearance: textfield;
        }

        .qty-input::-webkit-outer-spin-button,
        .qty-input::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }

        /* Floating Sticky Bottom Bar */
        .floating-footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: #FFFFFF;
            border-top: 1px solid var(--sr-border);
            padding: 12px 16px;
            padding-bottom: max(12px, env(safe-area-inset-bottom));
            box-shadow: 0 -2px 10px rgba(15, 23, 42, 0.05);
            z-index: 1000;
        }

        .floating-footer-inner {
            width: 100%;
            max-width: 640px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .total-box {
            min-width: 0;
        }

        .total-label {
            font-size: 11px;
            color: var(--sr-text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-weight: 600;
            margin-bottom: 2px;
        }

        .total-amount {
            font-size: 18px;
            font-weight: 700;
            color: var(--sr-text-primary);
            letter-spacing: -0.01em;
        }

        .btn-order-submit {
            background-color: var(--sr-primary);
            color: #FFFFFF;
            border: none;
            border-radius: var(--sr-radius-sm);
            font-weight: 600;
            font-size: 14px;
            padding: 10px 20px;
            min-height: 44px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            cursor: pointer;
            transition: background-color 0.15s ease;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .btn-order-submit:hover:not(:disabled) {
            background-color: var(--sr-primary-hover);
        }

        .btn-order-submit:active:not(:disabled) {
            background-color: var(--sr-primary-active);
        }

        .btn-order-submit:disabled {
            background-color: #E2E8F0;
            color: #94A3B8;
            cursor: not-allowed;
        }

        .btn-order-submit:focus-visible {
            outline: 2px solid var(--sr-primary);
            outline-offset: 2px;
        }

        /* Alert notifications */
        .alert-box {
            padding: 12px 14px;
            border-radius: var(--sr-radius-sm);
            margin-bottom: 16px;
            font-size: 13px;
            line-height: 1.5;
            position: relative;
        }

        .alert-box-success {
            background-color: var(--sr-success-soft);
            border: 1px solid var(--sr-success-border);
            color: #14532D;
        }

        .alert-box-danger {
            background-color: var(--sr-danger-soft);
            border: 1px solid var(--sr-danger-border);
            color: #7F1D1D;
        }

        .alert-box-info {
            background-color: var(--sr-info-soft);
            border: 1px solid var(--sr-info-border);
            color: #075985;
            text-align: center;
        }

        .alert-close-btn {
            position: absolute;
            top: 10px;
            right: 12px;
            background: none;
            border: none;
            color: inherit;
            cursor: pointer;
            font-size: 16px;
            line-height: 1;
            padding: 4px;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(15, 23, 42, 0.6);
            z-index: 1050;
            overflow-y: auto;
            align-items: center;
            justify-content: center;
            padding: 16px;
            padding-bottom: max(16px, env(safe-area-inset-bottom));
            opacity: 0;
            transition: opacity 0.15s ease;
        }

        .modal.show {
            display: flex;
            opacity: 1;
        }

        .modal-dialog {
            width: 100%;
            max-width: 520px;
            background: #FFFFFF;
            border-radius: var(--sr-radius);
            box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.15);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            max-height: 85vh;
            border: 1px solid var(--sr-border);
            transform: translateY(-8px);
            transition: transform 0.15s ease;
        }

        .modal.show .modal-dialog {
            transform: translateY(0);
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 18px;
            background-color: #FFFFFF;
            border-bottom: 1px solid var(--sr-border);
        }

        .modal-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--sr-text-primary);
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0;
        }

        .modal-close-btn {
            background: none;
            border: none;
            color: var(--sr-text-secondary);
            width: 32px;
            height: 32px;
            border-radius: var(--sr-radius-xs);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background-color 0.1s ease, color 0.1s ease;
        }

        .modal-close-btn:hover {
            background-color: #F1F5F9;
            color: var(--sr-text-primary);
        }

        .modal-close-btn:focus-visible {
            outline: 2px solid var(--sr-primary);
        }

        .modal-body {
            padding: 18px;
            overflow-y: auto;
            flex: 1 1 auto;
        }

        .modal-footer {
            padding: 12px 18px;
            background-color: #F8FAFC;
            border-top: 1px solid var(--sr-border);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .btn-modal {
            font-family: inherit;
            font-size: 14px;
            font-weight: 600;
            padding: 9px 16px;
            border-radius: var(--sr-radius-sm);
            cursor: pointer;
            min-height: 40px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid transparent;
            transition: all 0.15s ease;
        }

        .btn-modal-secondary {
            background-color: #FFFFFF;
            border-color: var(--sr-border);
            color: var(--sr-text-primary);
        }

        .btn-modal-secondary:hover {
            background-color: #F1F5F9;
        }

        .btn-modal-primary {
            background-color: var(--sr-primary);
            color: #FFFFFF;
        }

        .btn-modal-primary:hover:not(:disabled) {
            background-color: var(--sr-primary-hover);
        }

        .btn-modal-primary:disabled {
            background-color: #E2E8F0;
            color: #94A3B8;
            cursor: not-allowed;
        }

        /* Order history items inside Pesanan Saya modal */
        .order-card-item {
            background: #FFFFFF;
            border: 1px solid var(--sr-border);
            border-radius: var(--sr-radius-sm);
            padding: 14px;
            margin-bottom: 12px;
        }

        .order-card-item:last-child {
            margin-bottom: 0;
        }

        .order-item-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding-bottom: 10px;
            margin-bottom: 10px;
            border-bottom: 1px solid var(--sr-border-light);
            gap: 10px;
        }

        .order-num {
            font-weight: 700;
            font-size: 14px;
            color: var(--sr-text-primary);
            margin-bottom: 2px;
        }

        .order-meta-info {
            font-size: 12px;
            color: var(--sr-text-secondary);
        }

        .order-badges-col {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 4px;
            flex-shrink: 0;
        }

        /* Status badges */
        .badge-status {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 11px;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: 9999px;
            line-height: 1.3;
        }

        .badge-kitchen-pending {
            background-color: var(--sr-warning-soft);
            color: var(--sr-warning);
            border: 1px solid var(--sr-warning-border);
        }

        .badge-kitchen-accepted {
            background-color: var(--sr-info-soft);
            color: var(--sr-info);
            border: 1px solid var(--sr-info-border);
        }

        .badge-kitchen-preparing {
            background-color: var(--sr-primary-soft);
            color: var(--sr-primary);
            border: 1px solid var(--sr-primary-border);
        }

        .badge-kitchen-ready {
            background-color: var(--sr-success-soft);
            color: var(--sr-success);
            border: 1px solid var(--sr-success-border);
        }

        .badge-kitchen-delivered {
            background-color: #F1F5F9;
            color: #475569;
            border: 1px solid var(--sr-border);
        }

        .badge-payment-paid {
            background-color: var(--sr-success-soft);
            color: var(--sr-success);
            border: 1px solid var(--sr-success-border);
        }

        .badge-payment-unpaid {
            background-color: var(--sr-warning-soft);
            color: var(--sr-warning);
            border: 1px solid var(--sr-warning-border);
        }

        .order-item-list {
            list-style: none;
            margin-bottom: 10px;
        }

        .order-item-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
            padding: 5px 0;
            border-bottom: 1px dashed var(--sr-border-light);
        }

        .order-item-row:last-child {
            border-bottom: none;
        }

        .order-notes-box {
            font-size: 12px;
            color: var(--sr-text-secondary);
            background: #F8FAFC;
            border: 1px solid var(--sr-border-light);
            border-radius: var(--sr-radius-xs);
            padding: 6px 10px;
            margin-bottom: 10px;
        }

        .order-total-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 8px;
            border-top: 1px solid var(--sr-border-light);
            font-size: 13px;
        }

        .order-total-price {
            font-weight: 700;
            font-size: 15px;
            color: var(--sr-primary);
        }

        .empty-state-orders {
            text-align: center;
            padding: 32px 16px;
            color: var(--sr-text-secondary);
            font-size: 13px;
        }

        .empty-state-orders svg {
            margin-bottom: 10px;
            color: #94A3B8;
        }

        /* Lightbox Image Preview Modal */
        #imageLightboxModal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(15, 23, 42, 0.85);
            z-index: 1100;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 16px;
            padding-bottom: max(16px, env(safe-area-inset-bottom));
            opacity: 0;
            transition: opacity 0.15s ease;
        }

        #imageLightboxModal.show {
            display: flex;
            opacity: 1;
        }

        .lightbox-dialog {
            max-width: 90vw;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
        }

        .lightbox-close-btn {
            position: absolute;
            top: -40px;
            right: 0;
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: #FFFFFF;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background-color 0.15s ease;
        }

        .lightbox-close-btn:hover {
            background: rgba(255, 255, 255, 0.35);
        }

        .lightbox-box {
            background: #FFFFFF;
            border-radius: var(--sr-radius);
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4);
            max-width: 100%;
            max-height: 80vh;
            display: flex;
            flex-direction: column;
        }

        .lightbox-media {
            flex: 1 1 auto;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #0F172A;
            max-height: 65vh;
            min-height: 180px;
        }

        .lightbox-media img {
            max-width: 100%;
            max-height: 65vh;
            object-fit: contain;
            display: block;
        }

        .lightbox-caption {
            padding: 12px 16px;
            background: #FFFFFF;
            border-top: 1px solid var(--sr-border);
            text-align: center;
        }

        /* Confirmation Modal Item Table */
        .confirm-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .confirm-table td {
            padding: 8px 0;
            border-bottom: 1px solid var(--sr-border-light);
        }

        .confirm-table tr:last-child td {
            border-bottom: none;
        }

        .policy-box {
            border-radius: var(--sr-radius-sm);
            padding: 12px 14px;
            font-size: 13px;
            line-height: 1.5;
            margin-top: 14px;
        }

        .policy-box-warning {
            background-color: var(--sr-warning-soft);
            border: 1px solid var(--sr-warning-border);
            color: #78350F;
        }

        .policy-box-info {
            background-color: var(--sr-info-soft);
            border: 1px solid var(--sr-info-border);
            color: #075985;
        }

        /* Responsive Breakpoints */
        @media (min-width: 768px) {
            .container-app {
                max-width: 720px;
            }
            .header-title {
                font-size: 20px;
            }
            .fnb-catalog-list {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 12px;
            }
            .fnb-card {
                padding: 14px;
            }
            .fnb-img-wrapper {
                width: 68px;
                height: 68px;
                min-width: 68px;
            }
        }

        @media (min-width: 1024px) {
            .container-app {
                max-width: 760px;
            }
        }

        @media (max-width: 360px) {
            .container-app {
                padding: 0 10px;
            }
            .fnb-card {
                padding: 10px;
                gap: 8px;
            }
            .fnb-name {
                font-size: 13px;
            }
            .fnb-img-wrapper {
                width: 52px;
                height: 52px;
                min-width: 52px;
            }
            .qty-stepper {
                height: 34px;
            }
            .qty-btn {
                width: 30px;
                height: 34px;
            }
            .qty-input {
                width: 28px;
                font-size: 13px;
            }
            .btn-header-orders {
                padding: 4px 8px;
                font-size: 11px;
                min-height: 30px;
            }
            .floating-footer {
                padding: 10px 12px;
            }
            .total-amount {
                font-size: 16px;
            }
            .btn-order-submit {
                padding: 8px 14px;
                font-size: 13px;
                min-height: 40px;
            }
        }
    </style>
</head>
<body>
    <!-- Compact Understated Header -->
    <header class="header-card">
        <div class="container-app">
            <div class="header-top-row">
                <div class="location-pill">
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/>
                        <circle cx="12" cy="10" r="3"/>
                    </svg>
                    <span>{{ $location->name }} ({{ $location->code }})</span>
                </div>
                <button type="button" class="btn-header-orders" onclick="openActiveOrdersModal()" aria-label="Lihat Pesanan Saya">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <rect width="8" height="4" x="8" y="2" rx="1" ry="1"/>
                        <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>
                        <path d="M12 11h4"/>
                        <path d="M12 16h4"/>
                        <path d="M8 11h.01"/>
                        <path d="M8 16h.01"/>
                    </svg>
                    <span>Pesanan Saya</span>
                    <span class="badge-count" id="active-orders-count-badge">{{ count($activeOrders ?? []) }}</span>
                </button>
            </div>

            <div class="header-meta-row">
                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/>
                    <circle cx="12" cy="7" r="4"/>
                </svg>
                <span>Nama Pemain:</span>
                <span class="header-player-name">{{ $playerName ?? 'Belum Ada Pemain' }}</span>
            </div>

            <div class="header-title-box">
                <h1 class="header-title">Menu Makanan &amp; Minuman</h1>
                <p class="header-subtitle">Pesan langsung ke stasiun bermain Anda</p>
            </div>
        </div>
    </header>

    <main class="container-app">
        <div id="order-alert-container"></div>

        @if (isset($errors) && $errors->any())
            <div class="alert-box alert-box-danger" role="alert">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <form id="qr-order-form" action="{{ route('public.qr.station.submit', $location->code) }}" method="POST" onsubmit="event.preventDefault(); handleOrderSubmitClick(event);">
            @csrf
            <input type="hidden" name="session_token" value="{{ $token }}">

            <!-- Form Pemesan -->
            <div class="order-form-card">
                <div class="form-group">
                    <label class="form-label" for="customer-name-input">
                        Nama Pemesan <span class="required-mark">*</span>
                    </label>
                    <input type="text" 
                           id="customer-name-input"
                           name="customer_name" 
                           class="form-control" 
                           placeholder="Masukkan nama Anda (misal: Rendi)" 
                           required 
                           autocomplete="name"
                           value="{{ old('customer_name') }}">
                </div>
                <div class="form-group">
                    <label class="form-label" for="notes-input">
                        Catatan Pesanan (Opsional)
                    </label>
                    <input type="text" 
                           id="notes-input"
                           name="notes" 
                           class="form-control" 
                           placeholder="Contoh: Es dipisah, jangan terlalu manis" 
                           value="{{ old('notes') }}">
                </div>
            </div>

            <!-- Katalog F&B -->
            <section aria-labelledby="catalog-title">
                <div class="catalog-section-title" id="catalog-title">
                    <span>Daftar Pilihan Menu</span>
                </div>

                <div class="fnb-catalog-list">
                    @forelse ($fnbs as $index => $fnb)
                        <article class="fnb-card" id="fnb-card-{{ $fnb->id }}" data-fnb-id="{{ $fnb->id }}" data-price="{{ $fnb->effective_price }}">
                            <div class="fnb-card-left">
                                <!-- Thumbnail with Lightbox Trigger -->
                                <div class="fnb-img-wrapper" 
                                     role="button" 
                                     tabindex="0"
                                     title="Klik untuk memperbesar gambar {{ $fnb->nama }}"
                                     data-name="{{ $fnb->nama }}"
                                     data-img="{{ $fnb->image_url }}"
                                     data-price="Rp {{ number_format($fnb->effective_price, 0, ',', '.') }}"
                                     onclick="handleThumbnailClick(this, event)"
                                     onkeydown="handleThumbnailKeydown(this, event)">
                                    <img src="{{ $fnb->image_url }}" alt="{{ $fnb->nama }}" loading="lazy">
                                    <div class="fnb-img-zoom-badge" aria-hidden="true">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                            <circle cx="11" cy="11" r="8"/>
                                            <line x1="21" x2="16.65" y1="21" x2="16.65"/>
                                            <line x1="11" x2="11" y1="8" y2="14"/>
                                            <line x1="8" x2="14" y1="11" y2="11"/>
                                        </svg>
                                    </div>
                                </div>
                                <div class="fnb-info">
                                    <h2 class="fnb-name">{{ $fnb->nama }}</h2>
                                    <div class="fnb-price">Rp {{ number_format($fnb->effective_price, 0, ',', '.') }}</div>
                                    <div class="fnb-stock">
                                        @if ($fnb->stok == -1)
                                            <span class="stock-available">
                                                <span class="stock-dot" aria-hidden="true"></span>
                                                <span>Selalu Tersedia</span>
                                            </span>
                                        @else
                                            <span class="{{ $fnb->stok < 5 ? 'stock-low' : 'stock-normal' }}">
                                                Tersedia: {{ $fnb->stok }} porsi
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            </div>

                            <div class="qty-stepper">
                                <button type="button" 
                                        class="qty-btn" 
                                        onclick="changeQty({{ $fnb->id }}, -1)"
                                        aria-label="Kurangi pesanan {{ $fnb->nama }}">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <line x1="5" x2="19" y1="12" y2="12"/>
                                    </svg>
                                </button>
                                <input type="number" 
                                       id="qty-{{ $fnb->id }}" 
                                       class="qty-input" 
                                       value="0" 
                                       min="0" 
                                       max="{{ $fnb->stok == -1 ? 99 : $fnb->stok }}" 
                                       readonly
                                       aria-label="Jumlah {{ $fnb->nama }}">
                                <button type="button" 
                                        class="qty-btn" 
                                        onclick="changeQty({{ $fnb->id }}, 1, {{ $fnb->stok }})"
                                        aria-label="Tambah pesanan {{ $fnb->nama }}">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <line x1="12" x2="12" y1="5" y2="19"/>
                                        <line x1="5" x2="19" y1="12" y2="12"/>
                                    </svg>
                                </button>
                            </div>

                            <!-- Dynamic hidden fields added when qty > 0 -->
                            <div id="hidden-inputs-{{ $fnb->id }}"></div>
                        </article>
                    @empty
                        <div class="alert-box alert-box-info" id="no-menu-alert">
                            Saat ini belum ada menu makanan/minuman yang tersedia.
                        </div>
                    @endforelse
                </div>
            </section>

            <!-- Sticky Bottom Bar -->
            <div class="floating-footer">
                <div class="floating-footer-inner">
                    <div class="total-box">
                        <div class="total-label">Total Pesanan</div>
                        <div class="total-amount" id="total-display">Rp 0</div>
                    </div>
                    <button type="button" id="btn-submit" class="btn-order-submit" onclick="handleOrderSubmitClick(event)" disabled>
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/>
                            <path d="M3 6h18"/>
                            <path d="M16 10a4 4 0 0 1-8 0"/>
                        </svg>
                        <span>Pesan Sekarang</span>
                    </button>
                </div>
            </div>
        </form>
    </main>

    <!-- Modal Pesanan Saya -->
    <div class="modal" id="activeOrdersModal" tabindex="-1" role="dialog" aria-labelledby="activeOrdersModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-header">
                <h2 class="modal-title" id="activeOrdersModalLabel">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <rect width="8" height="4" x="8" y="2" rx="1" ry="1"/>
                        <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>
                        <path d="M12 11h4"/>
                        <path d="M12 16h4"/>
                        <path d="M8 11h.01"/>
                        <path d="M8 16h.01"/>
                    </svg>
                    <span>Pesanan Saya</span>
                    <span class="badge-count" id="active-orders-count">{{ count($activeOrders ?? []) }}</span>
                </h2>
                <button type="button" class="modal-close-btn" aria-label="Tutup" onclick="closeActiveOrdersModal()">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M18 6 6 18"/>
                        <path d="m6 6 12 12"/>
                    </svg>
                </button>
            </div>
            <div class="modal-body" id="active-orders-modal-body">
                <div id="pesanan-saya-card">
                    <div id="active-orders-container">
                        @forelse ($activeOrders ?? [] as $order)
                            <div class="order-card-item">
                                <div class="order-item-header">
                                    <div>
                                        <div class="order-num">#{{ $order->order_number }}</div>
                                        <div class="order-meta-info">
                                            Waktu: {{ $order->created_at ? $order->created_at->format('H:i') : '-' }} &bull; Pemesan: <strong>{{ $order->customer_name }}</strong>
                                        </div>
                                    </div>
                                    <div class="order-badges-col">
                                        @if ($order->status === 'PENDING')
                                            <span class="badge-status badge-kitchen-pending">Menunggu Dapur</span>
                                        @elseif ($order->status === 'ACCEPTED')
                                            <span class="badge-status badge-kitchen-accepted">Diterima Dapur</span>
                                        @elseif ($order->status === 'PREPARING')
                                            <span class="badge-status badge-kitchen-preparing">Sedang Disiapkan</span>
                                        @elseif ($order->status === 'READY')
                                            <span class="badge-status badge-kitchen-ready">Siap Diantar</span>
                                        @elseif ($order->status === 'DELIVERED')
                                            <span class="badge-status badge-kitchen-delivered">Selesai Diantar</span>
                                        @else
                                            <span class="badge-status badge-kitchen-delivered">{{ $order->status }}</span>
                                        @endif

                                        @if (strtolower($order->payment_status) === 'paid')
                                            <span class="badge-status badge-payment-paid">Lunas</span>
                                        @else
                                            <span class="badge-status badge-payment-unpaid">Belum Bayar</span>
                                        @endif
                                    </div>
                                </div>

                                <ul class="order-item-list">
                                    @foreach ($order->items as $item)
                                        <li class="order-item-row">
                                            <span>{{ $item->qty }}x {{ $item->fnb ? $item->fnb->nama : 'Menu Dihapus' }}</span>
                                            <span style="color: var(--sr-text-secondary);">Rp {{ number_format($item->subtotal, 0, ',', '.') }}</span>
                                        </li>
                                    @endforeach
                                </ul>

                                @if ($order->notes)
                                    <div class="order-notes-box">
                                        <em>Catatan: {{ $order->notes }}</em>
                                    </div>
                                @endif

                                <div class="order-total-row">
                                    <span style="color: var(--sr-text-secondary); font-weight: 500;">Total Pesanan:</span>
                                    <span class="order-total-price">Rp {{ number_format($order->total_amount, 0, ',', '.') }}</span>
                                </div>
                            </div>
                        @empty
                            <div class="empty-state-orders" id="no-active-orders-message">
                                <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <rect width="8" height="4" x="8" y="2" rx="1" ry="1"/>
                                    <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>
                                </svg>
                                <div>Belum ada pesanan aktif untuk sesi ini.</div>
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-modal btn-modal-secondary" onclick="closeActiveOrdersModal()">
                    Tutup
                </button>
            </div>
        </div>
    </div>

    <!-- Modal Konfirmasi Pesanan -->
    <div class="modal" id="confirmOrderModal" tabindex="-1" role="dialog" aria-labelledby="confirmOrderModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-header">
                <h2 class="modal-title" id="confirmOrderModalLabel">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="10"/>
                        <path d="m9 12 2 2 4-4"/>
                    </svg>
                    <span>Konfirmasi Pesanan</span>
                </h2>
                <button type="button" class="modal-close-btn" aria-label="Tutup" onclick="closeConfirmModal()">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M18 6 6 18"/>
                        <path d="m6 6 12 12"/>
                    </svg>
                </button>
            </div>
            <div class="modal-body">
                <!-- Detail Stasiun & Pemain -->
                <div style="background: #F8FAFC; border: 1px solid var(--sr-border); border-radius: var(--sr-radius-sm); padding: 12px 14px; margin-bottom: 14px; font-size: 13px;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                        <span style="color: var(--sr-text-secondary);">Station / Meja:</span>
                        <span style="font-weight: 600; color: var(--sr-text-primary);">{{ $location->name }} ({{ $location->code }})</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                        <span style="color: var(--sr-text-secondary);">Pemain Utama:</span>
                        <span style="font-weight: 600; color: var(--sr-text-primary);">{{ $playerName ?? 'Belum Ada Pemain' }}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: var(--sr-text-secondary);">Nama Pemesan:</span>
                        <span style="font-weight: 700; color: var(--sr-primary);" id="modal-customer-name">-</span>
                    </div>
                </div>

                <!-- Rincian Menu -->
                <div style="font-size: 13px; font-weight: 700; color: var(--sr-text-primary); margin-bottom: 8px;">
                    Rincian Menu:
                </div>
                <div id="modal-items-list" style="margin-bottom: 14px;">
                    <!-- Dynamic itemized list inserted here -->
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid var(--sr-border); padding-top: 10px; margin-bottom: 12px;">
                    <span style="font-weight: 600; color: var(--sr-text-primary); font-size: 14px;">Total Pesanan:</span>
                    <span style="font-weight: 700; color: var(--sr-primary); font-size: 18px;" id="modal-total-price">Rp 0</span>
                </div>

                <div id="modal-notes-container" style="display: none; background: #F8FAFC; border: 1px solid var(--sr-border); border-radius: var(--sr-radius-xs); padding: 8px 12px; font-size: 13px; margin-bottom: 14px;">
                    <strong>Catatan:</strong> <span id="modal-notes-text"></span>
                </div>

                <!-- Informasi Pembayaran -->
                <div class="policy-box {{ ($sessionType ?? null) === 'postpaid' ? 'policy-box-info' : 'policy-box-warning' }}">
                    @if (($sessionType ?? null) === 'postpaid')
                        <strong>Informasi Pembayaran:</strong> Anda memesan pada sesi Lost Time. Tagihan F&B akan dicatat ke tagihan meja Anda dan dibayarkan saat checkout permainan.
                    @else
                        <strong>Informasi Pembayaran:</strong> Anda memesan pada sesi Paket. Pembayaran dapat dilakukan kepada staf saat pesanan diantarkan atau setelah sesi bermain selesai.
                    @endif
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" id="btn-modal-cancel" class="btn-modal btn-modal-secondary" onclick="closeConfirmModal()">
                    Kembali / Edit Pesanan
                </button>
                <button type="button" id="btn-modal-confirm" class="btn-modal btn-modal-primary" onclick="submitConfirmedOrder()">
                    Ya, Konfirmasi Pesanan
                </button>
            </div>
        </div>
    </div>

    <!-- Modal Preview Gambar Produk (Lightbox) -->
    <div id="imageLightboxModal" tabindex="-1" role="dialog" aria-hidden="true" onclick="closeImageLightbox()">
        <div class="lightbox-dialog" onclick="event.stopPropagation()">
            <button type="button" class="lightbox-close-btn" aria-label="Tutup Preview Gambar" onclick="closeImageLightbox()">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M18 6 6 18"/>
                    <path d="m6 6 12 12"/>
                </svg>
            </button>
            <div class="lightbox-box">
                <div class="lightbox-media">
                    <img id="lightbox-img" src="" alt="Preview Produk">
                </div>
                <div class="lightbox-caption">
                    <div id="lightbox-title" style="font-weight: 700; font-size: 15px; color: var(--sr-text-primary); margin-bottom: 2px;"></div>
                    <div id="lightbox-price" style="font-weight: 700; font-size: 14px; color: var(--sr-primary);"></div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const fnbPrices = {
            @foreach ($fnbs as $fnb)
                {{ $fnb->id }}: {{ (float) $fnb->effective_price }},
            @endforeach
        };

        const fnbNames = {
            @foreach ($fnbs as $fnb)
                {{ $fnb->id }}: @json($fnb->nama),
            @endforeach
        };

        const fnbMaxStocks = {
            @foreach ($fnbs as $fnb)
                {{ $fnb->id }}: {{ (int) $fnb->stok }},
            @endforeach
        };

        function changeQty(fnbId, delta, maxStock = -1) {
            const input = document.getElementById('qty-' + fnbId);
            if (!input) return;
            let current = parseInt(input.value) || 0;
            let next = current + delta;

            if (next < 0) next = 0;
            if (maxStock !== -1 && next > maxStock) {
                alert('Maksimal stok tersedia adalah ' + maxStock);
                next = maxStock;
            }

            input.value = next;

            // Highlight card subtly when selected
            const card = document.getElementById('fnb-card-' + fnbId);
            if (card) {
                if (next > 0) {
                    card.classList.add('has-selected');
                } else {
                    card.classList.remove('has-selected');
                }
            }

            updateHiddenFields(fnbId, next);
            updateTotal();
        }

        function updateHiddenFields(fnbId, qty) {
            const container = document.getElementById('hidden-inputs-' + fnbId);
            if (!container) return;
            if (qty > 0) {
                container.innerHTML = `
                    <input type="hidden" name="items[${fnbId}][fnb_id]" value="${fnbId}">
                    <input type="hidden" name="items[${fnbId}][qty]" value="${qty}">
                    <input type="hidden" name="items[${fnbId}][expected_price]" value="${fnbPrices[fnbId]}">
                `;
            } else {
                container.innerHTML = '';
            }
        }

        function updateTotal() {
            let total = 0;
            let totalQty = 0;

            for (const fnbId in fnbPrices) {
                const input = document.getElementById('qty-' + fnbId);
                const qty = parseInt(input ? input.value : 0) || 0;
                total += qty * fnbPrices[fnbId];
                totalQty += qty;
            }

            const totalDisplay = document.getElementById('total-display');
            if (totalDisplay) {
                totalDisplay.textContent = 'Rp ' + total.toLocaleString('id-ID');
            }
            const btnSubmit = document.getElementById('btn-submit');
            if (btnSubmit) {
                btnSubmit.disabled = (totalQty === 0);
            }
        }

        /* Modal "Pesanan Saya" Controllers */
        function openActiveOrdersModal() {
            const modal = document.getElementById('activeOrdersModal');
            if (!modal) return;
            modal.style.display = 'flex';
            setTimeout(() => { modal.classList.add('show'); }, 10);
            updateBodyScrollLock();
        }

        function closeActiveOrdersModal() {
            const modal = document.getElementById('activeOrdersModal');
            if (!modal) return;
            modal.classList.remove('show');
            setTimeout(() => {
                modal.style.display = 'none';
                updateBodyScrollLock();
            }, 150);
        }

        /* Modal Konfirmasi Pesanan Controllers */
        function openConfirmModal() {
            const modal = document.getElementById('confirmOrderModal');
            if (!modal) return;
            modal.style.display = 'flex';
            setTimeout(() => { modal.classList.add('show'); }, 10);
            updateBodyScrollLock();
        }

        function closeConfirmModal() {
            const modal = document.getElementById('confirmOrderModal');
            if (!modal) return;
            modal.classList.remove('show');
            setTimeout(() => {
                modal.style.display = 'none';
                updateBodyScrollLock();
            }, 150);
        }

        /* Thumbnail Lightbox Trigger Handlers */
        function handleThumbnailClick(element, event) {
            if (event) {
                event.stopPropagation();
                event.preventDefault();
            }
            if (!element) return;
            const name = element.getAttribute('data-name') || '';
            const img = element.getAttribute('data-img') || '';
            const price = element.getAttribute('data-price') || '';
            openImageLightbox(name, img, price);
        }

        function handleThumbnailKeydown(element, event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                event.stopPropagation();
                handleThumbnailClick(element, event);
            }
        }

        /* Modal Preview Gambar (Lightbox) Controllers */
        function openImageLightbox(title, imgUrl, priceFormatted) {
            const modal = document.getElementById('imageLightboxModal');
            const img = document.getElementById('lightbox-img');
            const titleEl = document.getElementById('lightbox-title');
            const priceEl = document.getElementById('lightbox-price');
            if (!modal || !img) return;

            img.src = imgUrl;
            img.alt = title;
            if (titleEl) titleEl.textContent = title;
            if (priceEl) priceEl.textContent = priceFormatted;

            modal.style.display = 'flex';
            setTimeout(() => { modal.classList.add('show'); }, 10);
            updateBodyScrollLock();
        }

        function closeImageLightbox() {
            const modal = document.getElementById('imageLightboxModal');
            if (!modal) return;
            modal.classList.remove('show');
            setTimeout(() => {
                modal.style.display = 'none';
                updateBodyScrollLock();
            }, 150);
        }

        function updateBodyScrollLock() {
            const activeModal = document.getElementById('activeOrdersModal');
            const confirmModal = document.getElementById('confirmOrderModal');
            const lightboxModal = document.getElementById('imageLightboxModal');

            const hasOpenModal = (activeModal && activeModal.classList.contains('show')) ||
                                 (confirmModal && confirmModal.classList.contains('show')) ||
                                 (lightboxModal && lightboxModal.classList.contains('show'));

            if (hasOpenModal) {
                document.body.classList.add('modal-open');
            } else {
                document.body.classList.remove('modal-open');
            }
        }

        // Close modals if backdrop clicked
        window.addEventListener('click', function(event) {
            const confirmModal = document.getElementById('confirmOrderModal');
            if (event.target === confirmModal) {
                const btnCancel = document.getElementById('btn-modal-cancel');
                if (btnCancel && !btnCancel.disabled) {
                    closeConfirmModal();
                }
            }
            const activeOrdersModal = document.getElementById('activeOrdersModal');
            if (event.target === activeOrdersModal) {
                closeActiveOrdersModal();
            }
            const lightboxModal = document.getElementById('imageLightboxModal');
            if (event.target === lightboxModal) {
                closeImageLightbox();
            }
        });

        // Close modals on Escape key
        window.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                const lightboxModal = document.getElementById('imageLightboxModal');
                if (lightboxModal && lightboxModal.classList.contains('show')) {
                    closeImageLightbox();
                    return;
                }
                const activeOrdersModal = document.getElementById('activeOrdersModal');
                if (activeOrdersModal && activeOrdersModal.classList.contains('show')) {
                    closeActiveOrdersModal();
                    return;
                }
                const confirmModal = document.getElementById('confirmOrderModal');
                if (confirmModal && confirmModal.classList.contains('show')) {
                    const btnCancel = document.getElementById('btn-modal-cancel');
                    if (btnCancel && !btnCancel.disabled) {
                        closeConfirmModal();
                    }
                }
            }
        });

        function handleOrderSubmitClick(event) {
            if (event) event.preventDefault();

            const customerNameInput = document.querySelector('input[name="customer_name"]');
            const customerName = (customerNameInput ? customerNameInput.value : '').trim();

            if (!customerName) {
                alert('Silakan masukkan nama pemesan terlebih dahulu.');
                if (customerNameInput) customerNameInput.focus();
                return;
            }

            let totalQty = 0;
            let itemsList = [];

            for (const fnbId in fnbPrices) {
                const qtyInput = document.getElementById('qty-' + fnbId);
                const qty = parseInt(qtyInput ? qtyInput.value : 0) || 0;
                if (qty > 0) {
                    totalQty += qty;
                    itemsList.push({
                        id: fnbId,
                        name: fnbNames[fnbId] || ('Item #' + fnbId),
                        qty: qty,
                        price: fnbPrices[fnbId],
                        subtotal: qty * fnbPrices[fnbId]
                    });
                }
            }

            if (totalQty === 0 || itemsList.length === 0) {
                alert('Pilih minimal 1 menu makanan atau minuman.');
                return;
            }

            // Populate confirmation modal
            const modalCustomerName = document.getElementById('modal-customer-name');
            if (modalCustomerName) modalCustomerName.textContent = customerName;

            let itemsHtml = '<table class="confirm-table"><tbody>';
            let totalPrice = 0;
            itemsList.forEach(item => {
                totalPrice += item.subtotal;
                itemsHtml += `
                    <tr>
                        <td style="text-align: left;">
                            <div style="font-weight: 600; color: var(--sr-text-primary);">${escapeHtml(item.name)}</div>
                            <div style="color: var(--sr-text-secondary); font-size: 12px;">Rp ${item.price.toLocaleString('id-ID')}</div>
                        </td>
                        <td style="text-align: center; font-weight: 600; color: var(--sr-text-secondary);">${item.qty}x</td>
                        <td style="text-align: right; font-weight: 700; color: var(--sr-text-primary);">Rp ${item.subtotal.toLocaleString('id-ID')}</td>
                    </tr>
                `;
            });
            itemsHtml += '</tbody></table>';

            const modalItemsList = document.getElementById('modal-items-list');
            if (modalItemsList) modalItemsList.innerHTML = itemsHtml;

            const modalTotalPrice = document.getElementById('modal-total-price');
            if (modalTotalPrice) modalTotalPrice.textContent = 'Rp ' + totalPrice.toLocaleString('id-ID');

            // Notes
            const notesInput = document.querySelector('input[name="notes"]');
            const notes = notesInput ? notesInput.value.trim() : '';
            const modalNotesContainer = document.getElementById('modal-notes-container');
            const modalNotesText = document.getElementById('modal-notes-text');
            if (notes) {
                if (modalNotesText) modalNotesText.textContent = notes;
                if (modalNotesContainer) modalNotesContainer.style.display = 'block';
            } else {
                if (modalNotesContainer) modalNotesContainer.style.display = 'none';
            }

            openConfirmModal();
        }

        function submitConfirmedOrder() {
            const form = document.getElementById('qr-order-form');
            const btnConfirm = document.getElementById('btn-modal-confirm');
            const btnCancel = document.getElementById('btn-modal-cancel');

            // Client-side idempotency key generation
            const idempotencyKey = 'idem_' + Date.now() + '_' + Math.random().toString(36).substring(2, 10);

            const formData = new FormData(form);
            formData.set('idempotency_key', idempotencyKey);

            // Double-submit protection
            btnConfirm.disabled = true;
            btnCancel.disabled = true;
            const originalConfirmText = btnConfirm.innerHTML;
            btnConfirm.innerHTML = 'Mengirim Pesanan...';

            fetch(form.action, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            })
            .then(async (response) => {
                if (response.status === 201) {
                    const data = await response.json();
                    closeConfirmModal();

                    // Reset quantities and total
                    for (const fnbId in fnbPrices) {
                        const input = document.getElementById('qty-' + fnbId);
                        if (input) input.value = 0;
                        const card = document.getElementById('fnb-card-' + fnbId);
                        if (card) card.classList.remove('has-selected');
                        updateHiddenFields(fnbId, 0);
                    }
                    const notesInput = document.querySelector('input[name="notes"]');
                    if (notesInput) notesInput.value = '';
                    updateTotal();

                    // Show success banner with clean button to open Pesanan Saya
                    const alertContainer = document.getElementById('order-alert-container');
                    if (alertContainer) {
                        const orderNum = data.order ? data.order.order_number : '';
                        alertContainer.innerHTML = `
                            <div class="alert-box alert-box-success" role="alert">
                                <div style="font-weight: 700; margin-bottom: 2px;">Pesanan Berhasil Dikirim!</div>
                                <div style="margin-bottom: 10px;">Pesanan Anda telah dicatat dengan Nomor Pesanan: <strong>#${escapeHtml(orderNum)}</strong></div>
                                <button type="button" class="btn-modal btn-modal-primary" style="font-size: 13px; min-height: 36px; padding: 6px 14px; gap: 6px;" onclick="openActiveOrdersModal()">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <rect width="8" height="4" x="8" y="2" rx="1" ry="1"/>
                                        <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>
                                    </svg>
                                    <span>Lihat Pesanan Saya</span>
                                </button>
                                <button type="button" class="alert-close-btn" aria-label="Tutup Notifikasi" onclick="this.parentElement.remove()">
                                    &times;
                                </button>
                            </div>
                        `;
                        window.scrollTo({ top: 0, behavior: 'smooth' });
                    }

                    // Trigger active orders refresh if defined
                    if (typeof window.loadActiveOrders === 'function') {
                        window.loadActiveOrders();
                    }
                } else if (response.status === 403) {
                    alert('Sesi bermain telah berakhir atau token tidak valid. Silakan hubungi kasir/staf F&B.');
                    window.location.reload();
                } else if (response.status === 422) {
                    const errorData = await response.json().catch(() => ({}));
                    let message = errorData.message || 'Validasi gagal. Silakan periksa kembali data pesanan.';
                    if (errorData.errors) {
                        const firstKey = Object.keys(errorData.errors)[0];
                        if (firstKey && errorData.errors[firstKey][0]) {
                            message = errorData.errors[firstKey][0];
                        }
                    }
                    alert(message);
                } else {
                    const errorData = await response.json().catch(() => ({}));
                    alert(errorData.message || 'Terjadi kesalahan sistem saat memproses pesanan.');
                }
            })
            .catch((err) => {
                console.error('Submit error:', err);
                alert('Terjadi kesalahan koneksi. Silakan periksa jaringan dan coba lagi.');
            })
            .finally(() => {
                btnConfirm.disabled = false;
                btnCancel.disabled = false;
                btnConfirm.innerHTML = originalConfirmText;
            });
        }

        function escapeHtml(text) {
            if (text === null || text === undefined) return '';
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
        }

        function getKitchenBadge(status) {
            switch (status) {
                case 'PENDING':
                    return '<span class="badge-status badge-kitchen-pending">Menunggu Dapur</span>';
                case 'ACCEPTED':
                    return '<span class="badge-status badge-kitchen-accepted">Diterima Dapur</span>';
                case 'PREPARING':
                    return '<span class="badge-status badge-kitchen-preparing">Sedang Disiapkan</span>';
                case 'READY':
                    return '<span class="badge-status badge-kitchen-ready">Siap Diantar</span>';
                case 'DELIVERED':
                    return '<span class="badge-status badge-kitchen-delivered">Selesai Diantar</span>';
                default:
                    return `<span class="badge-status badge-kitchen-delivered">${escapeHtml(status)}</span>`;
            }
        }

        function getPaymentBadge(paymentStatus) {
            if (String(paymentStatus).toLowerCase() === 'paid') {
                return '<span class="badge-status badge-payment-paid">Lunas</span>';
            }
            return '<span class="badge-status badge-payment-unpaid">Belum Bayar</span>';
        }

        function renderActiveOrders(orders) {
            const container = document.getElementById('active-orders-container');
            const countBadge = document.getElementById('active-orders-count');
            const headerCountBadge = document.getElementById('active-orders-count-badge');
            if (!container) return;

            const orderCount = Array.isArray(orders) ? orders.length : 0;
            if (countBadge) countBadge.textContent = orderCount;
            if (headerCountBadge) headerCountBadge.textContent = orderCount;

            if (!orders || orders.length === 0) {
                container.innerHTML = `
                    <div class="empty-state-orders" id="no-active-orders-message">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <rect width="8" height="4" x="8" y="2" rx="1" ry="1"/>
                            <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>
                        </svg>
                        <div>Belum ada pesanan aktif untuk sesi ini.</div>
                    </div>
                `;
                return;
            }

            let html = '';
            orders.forEach(order => {
                const orderTime = order.created_time || order.created_at_formatted || (order.created_at ? new Date(order.created_at).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }) : '-');
                const kitchenBadge = getKitchenBadge(order.status);
                const paymentBadge = getPaymentBadge(order.payment_status);
                const totalFormatted = 'Rp ' + Number(order.total_amount).toLocaleString('id-ID');

                let itemsHtml = '';
                if (order.items && order.items.length > 0) {
                    order.items.forEach(item => {
                        const subtotalFormatted = 'Rp ' + Number(item.subtotal).toLocaleString('id-ID');
                        itemsHtml += `
                            <li class="order-item-row">
                                <span>${item.qty}x ${escapeHtml(item.name)}</span>
                                <span style="color: var(--sr-text-secondary);">${subtotalFormatted}</span>
                            </li>
                        `;
                    });
                }

                let notesHtml = '';
                if (order.notes) {
                    notesHtml = `
                        <div class="order-notes-box">
                            <em>Catatan: ${escapeHtml(order.notes)}</em>
                        </div>
                    `;
                }

                html += `
                    <div class="order-card-item">
                        <div class="order-item-header">
                            <div>
                                <div class="order-num">#${escapeHtml(order.order_number)}</div>
                                <div class="order-meta-info">
                                    Waktu: ${escapeHtml(orderTime)} &bull; Pemesan: <strong>${escapeHtml(order.customer_name)}</strong>
                                </div>
                            </div>
                            <div class="order-badges-col">
                                ${kitchenBadge}
                                ${paymentBadge}
                            </div>
                        </div>

                        <ul class="order-item-list">
                            ${itemsHtml}
                        </ul>

                        ${notesHtml}

                        <div class="order-total-row">
                            <span style="color: var(--sr-text-secondary); font-weight: 500;">Total Pesanan:</span>
                            <span class="order-total-price">${totalFormatted}</span>
                        </div>
                    </div>
                `;
            });

            container.innerHTML = html;
        }

        window.loadActiveOrders = function() {
            const ordersUrl = '{{ route('public.qr.station.orders', $location->code) }}?token={{ $token }}';

            fetch(ordersUrl, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(async (response) => {
                if (response.status === 200) {
                    const data = await response.json();
                    if (data && data.success && Array.isArray(data.orders)) {
                        renderActiveOrders(data.orders);
                    }
                } else if (response.status === 403) {
                    alert('Sesi bermain telah berakhir atau token tidak valid. Silakan hubungi kasir/staf F&B.');
                    window.location.reload();
                }
            })
            .catch((err) => {
                console.warn('Active orders polling error:', err);
            });
        };

        // Poll active orders every 8 seconds
        setInterval(window.loadActiveOrders, 8000);
    </script>
</body>
</html>