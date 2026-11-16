<style>
/* ==========================================================================
   Sugeng Rawuh - Station-Based Cashier POS Dashboard (Scoped Styles)
   Palette: Stitch Light-Slate Utility | Dial: ENERGY 1 / RHYTHM 1 / MOTION 1
   ========================================================================== */

.pos-shell {
    --pos-surface: #f8fafc;
    --pos-card: #ffffff;
    --pos-border: #e2e8f0;
    --pos-border-strong: #cbd5e1;
    --pos-text: #1e293b;
    --pos-text-muted: #475569;
    --pos-primary: #2563EB;
    --pos-primary-hover: #1d4ed8;
    --pos-accent: #00BCD4;
    --pos-accent-hover: #0097a7;
    --pos-emerald: #10B981;
    --pos-amber: #F59E0B;
    --pos-red: #EF4444;
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
    border-bottom: 1px solid var(--pos-border);
    background-color: var(--pos-card);
    border-radius: 0.75rem 0.75rem 0 0;
    padding: 0 0.75rem;
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
    background-color: var(--pos-primary);
    border-color: var(--pos-primary);
    color: #ffffff !important;
}
.pos-btn-primary:hover {
    background-color: var(--pos-primary-hover);
    border-color: var(--pos-primary-hover);
}

.pos-btn-outline-primary {
    background-color: #ffffff;
    border-color: #93c5fd;
    color: var(--pos-primary) !important;
}
.pos-btn-outline-primary:hover {
    background-color: #eff6ff;
    border-color: var(--pos-primary);
}

.pos-btn-accent {
    background-color: #0e7490;
    border-color: #0e7490;
    color: #ffffff !important;
}
.pos-btn-accent:hover {
    background-color: #155e75;
    border-color: #155e75;
}

.pos-btn-success {
    background-color: #047857;
    border-color: #047857;
    color: #ffffff !important;
}
.pos-btn-success:hover {
    background-color: #065f46;
    border-color: #065f46;
}

.pos-btn-warning {
    background-color: #b45309;
    border-color: #b45309;
    color: #ffffff !important;
}
.pos-btn-warning:hover {
    background-color: #92400e;
    border-color: #92400e;
}

.pos-btn-secondary {
    background-color: #334155;
    border-color: #334155;
    color: #ffffff !important;
}
.pos-btn-secondary:hover {
    background-color: #1e293b;
    border-color: #1e293b;
}

.pos-btn-outline-secondary {
    background-color: #ffffff;
    border-color: var(--pos-border-strong);
    color: var(--pos-text) !important;
}
.pos-btn-outline-secondary:hover {
    background-color: var(--pos-surface);
    border-color: #94a3b8;
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
    background-color: #f8fafc;
}

.pos-dialog-title {
    font-size: 1.0625rem;
    font-weight: 700;
    color: #1e293b;
    margin: 0;
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
}

.pos-dialog-close:hover {
    background-color: #f1f5f9;
    color: #1e293b;
}

.pos-dialog-body {
    padding: 1rem 1.25rem;
    max-height: 70vh;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 0.875rem;
}

.pos-dialog-footer {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: flex-end;
    gap: 0.5rem;
    padding: 0.875rem 1.25rem;
    border-top: 1px solid var(--pos-border);
    background-color: #f8fafc;
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

