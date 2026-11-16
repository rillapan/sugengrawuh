<style>
/* ==========================================================================
   Sugeng Rawuh - Station-Based Cashier POS Dashboard (Stitch UI Redesign)
   Palette: Stitch Light-Slate Utility | High Contrast | Zero Slop
   ========================================================================== */

:root,
.pos-shell,
dialog.pos-dialog,
.pos-dialog {
    --pos-surface: #f8f9ff;
    --pos-surface-low: #eff4ff;
    --pos-surface-lowest: #ffffff;
    --pos-card: #ffffff;
    --pos-border: #e2e8f0;
    --pos-border-strong: #cbd5e1;
    --pos-text: #0b1c30;
    --pos-text-muted: #565e74;
    --pos-primary: #2563EB;
    --pos-primary-hover: #1d4ed8;
    --pos-primary-dark: #004ac6;
    --pos-primary-container: #2563EB;
    --pos-tertiary: #006243;
    --pos-tertiary-hover: #004d34;
    --pos-tertiary-container: #007d57;
    --pos-tertiary-fixed: #ecfdf5;
    --pos-on-tertiary-fixed: #004d34;
    --pos-accent: #f97316;
    --pos-accent-hover: #ea580c;
    --pos-emerald: #10B981;
    --pos-amber: #f59e0b;
    --pos-amber-bg: #fbbf24;
    --pos-amber-text: #451a03;
    --pos-red: #ba1a1a;
    --pos-red-light: #fef2f2;
    --pos-red-border: #fecaca;
    --pos-red-text: #991b1b;
    color: var(--pos-text);
}

.pos-shell :focus-visible,
dialog.pos-dialog :focus-visible {
    outline: 2px solid #2563EB;
    outline-offset: 2px;
}

/* Header & Action Bar */
.pos-header {
    background-color: var(--pos-card);
    border: 1px solid var(--pos-border);
    border-radius: 0.75rem;
    padding: 1.25rem;
    margin-bottom: 1rem;
}

.pos-header-top {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1rem;
}

.pos-title {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--pos-text);
    margin: 0 0 0.25rem;
    line-height: 1.25;
}

.pos-subtitle {
    font-size: 0.875rem;
    color: var(--pos-text-muted);
    margin: 0;
}

.pos-header-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
}

/* Quick Summary Strip */
.pos-stats-strip {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 0.75rem;
}

@media (max-width: 991.98px) {
    .pos-stats-strip {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 575.98px) {
    .pos-stats-strip {
        grid-template-columns: 1fr;
    }
}

.pos-stat-item {
    background-color: var(--pos-surface);
    border: 1px solid var(--pos-border);
    border-radius: 0.5rem;
    padding: 0.75rem 1rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
}

.pos-stat-label {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--pos-text-muted);
    margin-bottom: 0.125rem;
}

.pos-stat-value {
    font-size: 1.375rem;
    font-weight: 700;
    line-height: 1.2;
    font-variant-numeric: tabular-nums;
    color: var(--pos-text);
}

.pos-stat-icon {
    width: 2.25rem;
    height: 2.25rem;
    border-radius: 0.5rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.95rem;
    flex-shrink: 0;
}

.pos-stat-total .pos-stat-icon { background-color: #eff6ff; color: var(--pos-primary); }
.pos-stat-available .pos-stat-icon { background-color: #ecfdf5; color: #059669; }
.pos-stat-inuse .pos-stat-icon { background-color: #fef2f2; color: #dc2626; }
.pos-stat-unpaid .pos-stat-icon { background-color: #fffbeb; color: #d97706; }

/* Inline Feedback Banner */
.pos-feedback-banner {
    border-radius: 0.5rem;
    padding: 0.75rem 1rem;
    margin-bottom: 1rem;
    border: 1px solid var(--pos-border);
    background-color: var(--pos-card);
    font-size: 0.875rem;
    font-weight: 500;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
}

.pos-feedback-banner[hidden] {
    display: none;
}

.pos-feedback-banner.is-info {
    background-color: #eff6ff;
    border-color: #bfdbfe;
    color: #1e40af;
}

.pos-feedback-banner.is-success {
    background-color: #ecfdf5;
    border-color: #a7f3d0;
    color: #065f46;
}

.pos-feedback-banner.is-error {
    background-color: #fef2f2;
    border-color: #fecaca;
    color: #991b1b;
}

/* Accessible Tabs */
.pos-tablist-wrap {
    margin-bottom: 1rem;
    border: 1px solid var(--pos-border);
    background-color: var(--pos-card);
    border-radius: 0.75rem;
    padding: 0 1rem;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03);
}

.pos-live-legend {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 1rem;
    padding: 0.5rem 0;
}

.pos-legend-item {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.75rem;
    color: var(--pos-text-muted);
    font-weight: 500;
}

.pos-live-terminal-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.2rem 0.625rem;
    border-radius: 9999px;
    background-color: #ecfdf5;
    color: #006243;
    font-size: 0.75rem;
    font-weight: 700;
}

.pos-live-dot {
    width: 0.5rem;
    height: 0.5rem;
    border-radius: 50%;
    background-color: #006243;
    display: inline-block;
    animation: posPulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
}

@keyframes posPulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.4; transform: scale(0.9); }
}

.pos-stat-pct {
    display: inline-flex;
    align-items: center;
    padding: 0.125rem 0.45rem;
    border-radius: 9999px;
    font-size: 0.6875rem;
    font-weight: 700;
}
.pos-stat-pct.is-mint {
    background-color: #ecfdf5;
    color: #006243;
}
.pos-stat-pct.is-blue {
    background-color: #dbeafe;
    color: #1e40af;
}

.pos-tablist {
    display: flex;
    align-items: center;
    gap: 0.25rem;
    overflow-x: auto;
    scroll-snap-type: inline proximity;
    scrollbar-width: thin;
}

.pos-tab {
    position: relative;
    flex: none;
    scroll-snap-align: start;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    background: transparent;
    border: 0;
    padding: 0.875rem 1rem;
    font-size: 0.9375rem;
    font-weight: 600;
    color: var(--pos-text-muted);
    cursor: pointer;
    white-space: nowrap;
}

.pos-tab:hover {
    color: var(--pos-text);
}

.pos-tab[aria-selected="true"] {
    color: var(--pos-primary);
}

.pos-tab[aria-selected="true"]::after {
    content: "";
    position: absolute;
    inset: auto 0 0;
    border-block-end: 3px solid var(--pos-primary);
}

.pos-tab-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 1.5rem;
    height: 1.375rem;
    padding: 0 0.45rem;
    border-radius: 0.375rem;
    background-color: #eff6ff;
    color: var(--pos-primary);
    font-size: 0.75rem;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
}

.pos-tabpanel[hidden] {
    display: none;
}

/* Buttons */
.pos-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.375rem;
    padding: 0.475rem 0.75rem;
    font-size: 0.8125rem;
    font-weight: 600;
    line-height: 1.25;
    border-radius: 0.4375rem;
    border: 1px solid transparent;
    text-decoration: none !important;
    cursor: pointer;
    transition: background-color 0.15s ease, border-color 0.15s ease, color 0.15s ease;
    white-space: nowrap;
}

.pos-btn-sm {
    padding: 0.375rem 0.625rem;
    font-size: 0.78125rem;
}

.pos-btn-primary {
    background-color: #2563EB !important;
    border-color: #2563EB !important;
    color: #ffffff !important;
    font-weight: 600;
}
.pos-btn-primary:hover,
.pos-btn-primary:focus {
    background-color: #1d4ed8 !important;
    border-color: #1d4ed8 !important;
    color: #ffffff !important;
}

.pos-btn-tertiary {
    background-color: #006243 !important;
    border-color: #006243 !important;
    color: #ffffff !important;
    font-weight: 600;
}
.pos-btn-tertiary:hover,
.pos-btn-tertiary:focus {
    background-color: #004d34 !important;
    border-color: #004d34 !important;
    color: #ffffff !important;
}

#btn-submit-paket-bayar {
    background-color: #006243 !important;
    border: 1px solid #006243 !important;
    color: #ffffff !important;
    font-weight: 600 !important;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.08);
}
#btn-submit-paket-bayar::before {
    content: "\f788";
    font-family: "Font Awesome 5 Free";
    font-weight: 900;
    margin-right: 0.375rem;
}
#btn-submit-paket-bayar:hover,
#btn-submit-paket-bayar:focus {
    background-color: #004d34 !important;
    border-color: #004d34 !important;
    color: #ffffff !important;
}

#btn-submit-paket-simpan {
    background-color: #2563EB !important;
    border: 1px solid #2563EB !important;
    color: #ffffff !important;
    font-weight: 600 !important;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.08);
}
#btn-submit-paket-simpan::before {
    content: "\f04b";
    font-family: "Font Awesome 5 Free";
    font-weight: 900;
    margin-right: 0.375rem;
}
#btn-submit-paket-simpan:hover,
#btn-submit-paket-simpan:focus {
    background-color: #1d4ed8 !important;
    border-color: #1d4ed8 !important;
    color: #ffffff !important;
}

#btn-submit-lost-time {
    background-color: #006243 !important;
    border: 1px solid #006243 !important;
    color: #ffffff !important;
    font-weight: 600 !important;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.08);
}
#btn-submit-lost-time::before {
    content: "\f04b";
    font-family: "Font Awesome 5 Free";
    font-weight: 900;
    margin-right: 0.375rem;
}
#btn-submit-lost-time:hover,
#btn-submit-lost-time:focus {
    background-color: #004d34 !important;
    border-color: #004d34 !important;
    color: #ffffff !important;
}

.pos-btn-lost-time {
    background-color: #fbbf24 !important;
    border: 1px solid #f59e0b !important;
    color: #451a03 !important;
    font-weight: 600;
}
.pos-btn-lost-time:hover,
.pos-btn-lost-time:focus {
    background-color: #f59e0b !important;
    border-color: #d97706 !important;
    color: #451a03 !important;
}

.pos-btn-paket-card {
    background-color: #fffbeb !important;
    border: 1px solid #fcd34d !important;
    color: #78350f !important;
    font-weight: 600;
}
.pos-btn-paket-card:hover,
.pos-btn-paket-card:focus {
    background-color: #fef3c7 !important;
    border-color: #fbbf24 !important;
    color: #78350f !important;
}

.pos-btn-outline-primary {
    background-color: #eff6ff;
    border-color: #93c5fd;
    color: #1d4ed8 !important;
    font-weight: 600;
}
.pos-btn-outline-primary:hover,
.pos-btn-outline-primary:focus {
    background-color: #dbeafe;
    border-color: #2563eb;
    color: #1e40af !important;
}

.pos-btn-accent {
    background-color: #f97316 !important;
    border-color: #ea580c !important;
    color: #ffffff !important;
    font-weight: 600;
}
.pos-btn-accent:hover,
.pos-btn-accent:focus {
    background-color: #ea580c !important;
    border-color: #c2410c !important;
    color: #ffffff !important;
}

.pos-btn-success {
    background-color: #006243 !important;
    border-color: #006243 !important;
    color: #ffffff !important;
    font-weight: 600;
}
.pos-btn-success:hover,
.pos-btn-success:focus {
    background-color: #004d34 !important;
    border-color: #004d34 !important;
    color: #ffffff !important;
}

.pos-btn-warning {
    background-color: #fef3c7 !important;
    border-color: #fcd34d !important;
    color: #92400e !important;
    font-weight: 600;
}
.pos-btn-warning:hover,
.pos-btn-warning:focus {
    background-color: #fde68a !important;
    border-color: #f59e0b !important;
    color: #78350f !important;
}

.pos-btn-secondary {
    background-color: #eff4ff !important;
    border-color: #cbd5e1 !important;
    color: #0b1c30 !important;
    font-weight: 600;
}
.pos-btn-secondary:hover,
.pos-btn-secondary:focus {
    background-color: #dbeafe !important;
    border-color: #94a3b8 !important;
    color: #0b1c30 !important;
}

.pos-btn-outline-secondary {
    background-color: #ffffff;
    border-color: #cbd5e1;
    color: #565e74 !important;
    font-weight: 600;
}
.pos-btn-outline-secondary:hover,
.pos-btn-outline-secondary:focus {
    background-color: #f1f5f9;
    border-color: #94a3b8;
    color: #0b1c30 !important;
}

.pos-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

/* Station Grid */
.pos-station-grid {
    display: grid;
    gap: 1rem;
    grid-template-columns: repeat(auto-fill, minmax(270px, 1fr));
}

@media (min-width: 1600px) {
    .pos-station-grid {
        grid-template-columns: repeat(5, minmax(0, 1fr));
    }
}

@media (max-width: 575.98px) {
    .pos-station-grid {
        grid-template-columns: 1fr;
    }
}

/* Station Card */
.pos-station-card {
    position: relative;
    overflow: hidden;
    isolation: isolate;
    background-color: var(--pos-card);
    border: 1px solid var(--pos-border);
    border-top: 3px solid var(--pos-emerald);
    border-radius: 0.75rem;
    padding: 1rem;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    height: 100%;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}

.pos-station-card > * {
    position: relative;
    z-index: 1;
}

/* Category Backgrounds (Scoped for POS Station Cards) */
.pos-station-card.has-bg-ps::before,
.pos-station-card.has-bg-playstation::before,
.pos-station-card.has-bg-billiard::before {
    content: "";
    position: absolute;
    top: 3.25rem;
    bottom: 3.75rem;
    left: 50%;
    transform: translateX(-50%);
    background-size: contain;
    background-position: center center;
    background-repeat: no-repeat;
    opacity: 0.6;
    pointer-events: none;
    z-index: 0;
}

.pos-station-card.has-bg-ps::before,
.pos-station-card.has-bg-playstation::before {
    width: 68%; /* 65-70% lebar card */
    background-image: url('{{ asset('img/bg-ps.webp') }}');
}

.pos-station-card.has-bg-billiard::before {
    width: 72%; /* 70-75% lebar card */
    background-image: url('{{ asset('img/bg-billard.png') }}');
}

/* Semi-transparent surface backings for high legibility on background-image cards */
.pos-station-card.has-bg-ps .pos-card-head,
.pos-station-card.has-bg-billiard .pos-card-head {
    background-color: rgba(255, 255, 255, 0.88);
    border: 1px solid rgba(226, 232, 240, 0.9);
    border-radius: 0.5rem;
    padding: 0.625rem 0.75rem;
    backdrop-filter: blur(2px);
}

.pos-station-card.has-bg-ps .pos-tariff-line,
.pos-station-card.has-bg-billiard .pos-tariff-line {
    background-color: rgba(255, 255, 255, 0.9);
    backdrop-filter: blur(2px);
}

.pos-station-card.has-bg-ps .pos-timer-box,
.pos-station-card.has-bg-billiard .pos-timer-box {
    background-color: rgba(255, 255, 255, 0.9);
    backdrop-filter: blur(2px);
}

.pos-station-card.has-bg-ps .pos-cost-box,
.pos-station-card.has-bg-billiard .pos-cost-box {
    background-color: rgba(255, 255, 255, 0.9);
    border: 1px solid var(--pos-border);
    border-radius: 0.4375rem;
    padding: 0.5rem 0.625rem;
    backdrop-filter: blur(2px);
}

.pos-station-card.has-bg-ps .pos-meta-row,
.pos-station-card.has-bg-billiard .pos-meta-row {
    background-color: rgba(255, 255, 255, 0.9);
    border: 1px solid var(--pos-border);
    border-radius: 0.4375rem;
    padding: 0.35rem 0.5rem;
}

.pos-station-card.has-bg-ps .pos-fallback-note,
.pos-station-card.has-bg-billiard .pos-fallback-note {
    background-color: rgba(255, 255, 255, 0.9);
    border-radius: 0.4375rem;
    padding: 0.5rem;
}

.pos-station-card:hover {
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.06);
}

.pos-station-card.is-used {
    border-top-color: var(--pos-red);
}

.pos-card-head {
    display: flex;
    flex-direction: column;
    gap: 0.375rem;
    padding-bottom: 0.625rem;
    border-bottom: 1px solid #f1f5f9;
}

.pos-card-top-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
}

.pos-station-label {
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--pos-text-muted);
}

.pos-status-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.175rem 0.5rem;
    border-radius: 9999px;
    font-size: 0.6875rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

.pos-status-pill.is-available {
    background-color: #ecfdf5;
    color: #047857;
    border: 1px solid #a7f3d0;
}

.pos-status-pill.is-used {
    background-color: #fef2f2;
    color: #b91c1c;
    border: 1px solid #fecaca;
}

.pos-station-name {
    font-size: 1.0625rem;
    font-weight: 700;
    color: var(--pos-text);
    margin: 0;
    line-height: 1.3;
}

.pos-chip-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.375rem;
}

.pos-chip {
    display: inline-flex;
    align-items: center;
    padding: 0.125rem 0.45rem;
    border-radius: 0.3125rem;
    background-color: #f1f5f9;
    border: 1px solid var(--pos-border);
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--pos-text-muted);
}

.pos-type-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.125rem 0.5rem;
    border-radius: 0.3125rem;
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

.pos-type-badge.is-postpaid {
    background-color: #eff6ff;
    color: #1d4ed8;
    border: 1px solid #bfdbfe;
}

.pos-type-badge.is-prepaid {
    background-color: #ecfeff;
    color: #0e7490;
    border: 1px solid #a5f3fc;
}

.pos-type-badge.is-custom {
    background-color: #fffbeb;
    color: #b45309;
    border: 1px solid #fde68a;
}

/* Card Body */
.pos-card-body {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    flex: 1 1 auto;
}

.pos-tariff-line {
    font-size: 0.84375rem;
    font-weight: 600;
    color: var(--pos-text);
    background-color: var(--pos-surface);
    border: 1px solid var(--pos-border);
    border-radius: 0.4375rem;
    padding: 0.5rem 0.625rem;
}

.pos-hw-status {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--pos-text-muted);
}

.pos-hw-dot {
    width: 0.5rem;
    height: 0.5rem;
    border-radius: 50%;
    background-color: #94a3b8;
    flex-shrink: 0;
}

.pos-hw-status.is-online .pos-hw-dot { background-color: var(--pos-emerald); }
.pos-hw-status.is-offline .pos-hw-dot { background-color: var(--pos-red); }
.pos-hw-status.is-unverified .pos-hw-dot { background-color: var(--pos-amber); }

/* Timer Block */
.pos-timer-box {
    background-color: var(--pos-surface);
    border: 1px solid var(--pos-border);
    border-radius: 0.5rem;
    padding: 0.625rem 0.75rem;
}

.pos-timer-value {
    font-family: 'Roboto Mono', ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 1.375rem;
    font-weight: 700;
    line-height: 1.2;
    font-variant-numeric: tabular-nums;
    color: var(--pos-text);
}

.pos-timer-sub {
    font-size: 0.75rem;
    color: var(--pos-text-muted);
    margin-top: 0.25rem;
}

/* Cost & Payment Summary on Active Card */
.pos-cost-box {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    font-size: 0.8125rem;
}

.pos-cost-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    color: var(--pos-text-muted);
}

.pos-cost-row strong {
    color: var(--pos-text);
    font-variant-numeric: tabular-nums;
}

.pos-cost-total {
    padding-top: 0.25rem;
    border-top: 1px dashed var(--pos-border);
    font-weight: 700;
    color: var(--pos-text);
}

.pos-meta-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 0.375rem;
}

.pos-pay-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.125rem 0.5rem;
    border-radius: 0.3125rem;
    font-size: 0.6875rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

.pos-pay-badge.is-paid {
    background-color: #ecfdf5;
    color: #047857;
    border: 1px solid #a7f3d0;
}

.pos-pay-badge.is-unpaid {
    background-color: #fffbeb;
    color: #b45309;
    border: 1px solid #fde68a;
}

.pos-customer-name {
    font-size: 0.78125rem;
    font-weight: 600;
    color: var(--pos-text-muted);
}

.pos-fallback-note {
    font-size: 0.8125rem;
    color: #92400e;
    background-color: #fffbeb;
    border: 1px solid #fde68a;
    border-radius: 0.4375rem;
    padding: 0.5rem 0.625rem;
    margin: 0;
}

/* Card Footer Actions */
.pos-card-footer {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(84px, 1fr));
    gap: 0.4375rem;
    padding-top: 0.625rem;
    border-top: 1px solid #f1f5f9;
}

.pos-readonly-note {
    grid-column: 1 / -1;
    margin: 0;
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--pos-text-muted);
    text-align: center;
    padding: 0.25rem 0;
}

/* Empty State */
.pos-empty-state {
    background-color: var(--pos-card);
    border: 1px dashed var(--pos-border-strong);
    border-radius: 0.75rem;
    padding: 2.5rem 1.25rem;
    text-align: center;
    color: var(--pos-text-muted);
}

.pos-empty-state h2 {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--pos-text);
    margin: 0.5rem 0 0.375rem;
}

/* History Tab Enhancements */
.pos-history-filter-form {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
}

.pos-table-wrapper {
    overflow: auto;
    max-width: 100%;
}

.pos-table-wrapper table.table-bordered {
    border: 1px solid #dee2e6;
    margin-bottom: 0;
}

.pos-table-wrapper table.table-bordered th,
.pos-table-wrapper table.table-bordered td {
    border: 1px solid #dee2e6;
    vertical-align: middle;
}

.pos-history-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.25rem;
}

/* Native <dialog> Modals */
dialog.pos-dialog:modal {
    overscroll-behavior: contain;
    max-width: min(560px, calc(100vw - 1.5rem));
    width: 100%;
    max-height: 90vh;
    border: 1px solid var(--pos-border);
    border-radius: 0.75rem;
    padding: 0;
    background-color: #ffffff;
    color: #1e293b;
    box-shadow: 0 20px 25px -5px rgba(15, 23, 42, 0.15), 0 8px 10px -6px rgba(15, 23, 42, 0.08);
    margin: auto;
}

dialog.pos-dialog.pos-dialog-lg:modal {
    max-width: min(720px, calc(100vw - 1.5rem));
}

dialog.pos-dialog[open] {
    display: flex;
    flex-direction: column;
}

dialog.pos-dialog::backdrop {
    background-color: rgba(15, 23, 42, 0.55);
    overflow: hidden;
    overscroll-behavior: contain;
}

.pos-dialog-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--pos-border);
    background-color: #f8f9ff;
}

.pos-modal-header-cluster {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.pos-modal-icon-badge {
    width: 2.5rem;
    height: 2.5rem;
    border-radius: 0.5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.125rem;
    flex-shrink: 0;
}
.pos-modal-icon-badge.is-blue {
    background-color: #2563EB;
    color: #ffffff;
}
.pos-modal-icon-badge.is-green {
    background-color: #006243;
    color: #ffffff;
}
.pos-modal-icon-badge.is-orange {
    background-color: #f97316;
    color: #ffffff;
}
.pos-modal-icon-badge.is-secondary {
    background-color: #eff4ff;
    color: #2563eb;
}

.pos-modal-title-wrap {
    display: flex;
    flex-direction: column;
}

.pos-modal-title-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.pos-dialog-title {
    font-size: 1.125rem;
    font-weight: 700;
    color: #0b1c30;
    margin: 0;
    line-height: 1.25;
}

.pos-dialog-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.125rem 0.5rem;
    border-radius: 9999px;
    font-size: 0.6875rem;
    font-weight: 700;
    letter-spacing: 0.03em;
}
.pos-dialog-badge.is-blue {
    background-color: #dbeafe;
    color: #1e40af;
}
.pos-dialog-badge.is-green {
    background-color: #ecfdf5;
    color: #006243;
}
.pos-dialog-badge.is-secondary {
    background-color: #f1f5f9;
    color: #475569;
}

.pos-dialog-subtitle {
    font-size: 0.75rem;
    color: #565e74;
    font-weight: 500;
    margin-top: 0.125rem;
}

.pos-dialog-close {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    border-radius: 0.375rem;
    border: 1px solid var(--pos-border);
    background-color: #ffffff;
    color: #475569;
    cursor: pointer;
    transition: background-color 0.15s ease, color 0.15s ease;
}

.pos-dialog-close:hover {
    background-color: #eff4ff;
    color: #0b1c30;
}

.pos-dialog-body {
    padding: 1.25rem;
    max-height: 72vh;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.pos-hw-id-card {
    background-color: #eff4ff;
    border: 1px solid #dbeafe;
    border-radius: 0.75rem;
    padding: 0.875rem 1rem;
    display: flex;
    flex-direction: column;
    gap: 0.625rem;
}

.pos-hw-id-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    font-size: 0.6875rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #565e74;
}

.pos-hw-relay-tag {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    color: #006243;
    font-weight: 600;
    font-size: 0.6875rem;
}

.pos-hw-device-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
}

.pos-hw-device-title {
    font-size: 0.9375rem;
    font-weight: 700;
    color: #0b1c30;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.pos-summary-bar {
    background-color: #eff4ff;
    border: 1px solid #bfdbfe;
    border-radius: 0.75rem;
    padding: 0.875rem 1.25rem;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
}

.pos-summary-meta {
    display: flex;
    align-items: center;
    gap: 1.25rem;
    font-size: 0.8125rem;
    color: #565e74;
}

.pos-summary-col {
    display: flex;
    flex-direction: column;
}

.pos-summary-col-label {
    font-size: 0.6875rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #565e74;
}

.pos-summary-col-value {
    font-size: 0.9375rem;
    font-weight: 700;
    color: #0b1c30;
}

.pos-summary-total-col {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
}

.pos-summary-total-label {
    font-size: 0.6875rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #565e74;
}

.pos-summary-total-value {
    font-size: 1.375rem;
    font-weight: 800;
    color: #2563EB;
    font-variant-numeric: tabular-nums;
    line-height: 1.2;
}

.pos-alert-formula {
    background-color: #fffbeb;
    border: 1px solid #fde68a;
    border-radius: 0.625rem;
    padding: 0.75rem 1rem;
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.pos-alert-formula-title {
    font-size: 0.8125rem;
    font-weight: 700;
    color: #78350f;
    display: flex;
    align-items: center;
    gap: 0.35rem;
}

.pos-alert-formula-text {
    font-size: 0.75rem;
    color: #92400e;
    margin: 0;
    line-height: 1.35;
}

.pos-hw-relay-note {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.75rem;
    font-weight: 600;
    color: #006243;
}

.pos-dialog-footer {
    display: flex;
    flex-direction: column;
    gap: 0.625rem;
    padding: 1rem 1.25rem;
    border-top: 1px solid var(--pos-border);
    background-color: #ffffff;
    box-shadow: 0 -4px 6px -1px rgba(0, 0, 0, 0.02);
}

.pos-dialog-footer-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    width: 100%;
}

.pos-dialog-footer-right {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
    margin-left: auto;
}

.pos-dialog-footer-note {
    font-size: 0.75rem;
    color: #565e74;
    display: flex;
    align-items: center;
    gap: 0.35rem;
    width: 100%;
}

.pos-dialog-error {
    padding: 0.625rem 0.75rem;
    border-radius: 0.4375rem;
    background-color: #fef2f2;
    border: 1px solid #fecaca;
    color: #991b1b;
    font-size: 0.8125rem;
    font-weight: 600;
}

.pos-dialog-error[hidden] {
    display: none;
}

.pos-form-group {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.pos-form-label {
    font-size: 0.8125rem;
    font-weight: 600;
    color: #334155;
    margin: 0;
}

.pos-form-row {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.75rem;
}

.pos-form-row-3 {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.5rem;
}

@media (max-width: 575.98px) {
    .pos-form-row,
    .pos-form-row-3 {
        grid-template-columns: 1fr;
    }
}

.pos-mode-switch {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.5rem;
}

.pos-mode-btn {
    padding: 0.5rem 0.75rem;
    border-radius: 0.4375rem;
    border: 1px solid var(--pos-border-strong);
    background-color: #f8fafc;
    color: #475569;
    font-size: 0.8125rem;
    font-weight: 600;
    cursor: pointer;
}

.pos-mode-btn[aria-pressed="true"] {
    background-color: #eff6ff;
    border-color: var(--pos-primary);
    color: var(--pos-primary);
}

.pos-context-pill {
    background-color: #f8fafc;
    border: 1px solid var(--pos-border);
    border-radius: 0.4375rem;
    padding: 0.5rem 0.75rem;
    font-size: 0.8125rem;
    font-weight: 600;
    color: #334155;
}

.pos-detail-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.5rem 0.75rem;
}

@media (max-width: 575.98px) {
    .pos-detail-grid {
        grid-template-columns: 1fr;
    }
}

.pos-detail-item {
    background-color: #f8fafc;
    border: 1px solid var(--pos-border);
    border-radius: 0.4375rem;
    padding: 0.5rem 0.625rem;
}

.pos-detail-key {
    font-size: 0.72rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: #64748b;
}

.pos-detail-val {
    font-size: 0.875rem;
    font-weight: 700;
    color: #1e293b;
}

.pos-cafe-menu-list {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    max-height: 240px;
    overflow-y: auto;
    padding-right: 0.25rem;
}

.pos-cafe-item-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 0.5rem 0.625rem;
    border: 1px solid var(--pos-border);
    border-radius: 0.4375rem;
    background-color: #ffffff;
}

.pos-category-options {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

/* Touch Targets on Mobile / Tablet */
@media (max-width: 767.98px) {
    .pos-btn,
    .pos-tab,
    .pos-dialog-close,
    .pos-mode-btn {
        min-height: 44px;
    }
    .pos-dialog-close {
        min-width: 44px;
    }
}

/* Reduced Motion */
@media (prefers-reduced-motion: reduce) {
    .pos-shell *,
    dialog.pos-dialog * {
        transition: none !important;
        animation: none !important;
        scroll-behavior: auto !important;
    }
}
</style>

