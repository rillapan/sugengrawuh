# Implementation Plan: Dynamic Payment Flow Transaksi F&B

**Date:** 2026-11-15  
**Document Reference:** User Request — Dynamic Payment Flow Transaksi F&B (`/fnb/orders/create`)  
**Approach:** Test-Driven Development (TDD) via Subagent-Driven Development (SDD)

---

## Objectives
Enhance the transaction flow on `http://127.0.0.1:8000/fnb/orders/create` so that action buttons, payment mechanisms, invoices, and order statuses dynamically adjust to customer types and game session types:
1. **Aktif Bermain — Paket:** Primary action is **Bayar Sekarang** (upfront F&B settlement, separate F&B invoice, game invoice untouched).
2. **Aktif Bermain — Lost Time:** Primary action is **Simpan Transaksi** (saved as UNPAID linked to session, settled at main cashier checkout, kitchen can process).
3. **Non-Bermain (Dine-in / Takeaway):** Primary action is **Bayar Sekarang** (standalone upfront F&B invoice before kitchen processing).
4. **Staf F&B RBAC:** Staff accounts cannot confirm payments; they save orders as pending with explicit cashier payment direction.
5. **Modal Confirmation:** Modal displays customer/station identity, itemized summary, payment method, discount, amount paid, and change calculation.
6. **Double-submit Protection:** Loading states and button disabling.

---

## Tasks

### Task 1: Dynamic Action Buttons & Payment Summary Modal UI
- **Goal:** Update `resources/views/fnb/transaction_create.blade.php` to dynamically change the primary action button based on the customer type and selected session (Lost Time vs Paket vs Non-Bermain), enrich the payment summary modal with customer identity and itemized list, handle F&B staff permissions, and prevent double submission.
- **Files to modify/create:**
  - Modify `resources/views/fnb/transaction_create.blade.php`:
    - JavaScript logic to update primary button label, icon, style, and behavior when `#transaction_id` or `customer_type` changes:
      - If Lost Time: Primary button is **"Simpan Transaksi"** (`class="btn btn-primary"`, submits `action=save`). Secondary option: "Bayar Lebih Awal" for cashier.
      - If Paket: Primary button is **"Bayar Sekarang"** (`class="btn btn-success"`, triggers payment modal for cashier; for F&B staff: "Simpan Order (Menunggu Kasir)").
      - If Non-Bermain: Primary button is **"Bayar Sekarang"** (`class="btn btn-success"`, triggers payment modal for cashier; for F&B staff: "Simpan Order (Menunggu Kasir)").
    - Enrich modal `#modalPaymentSummary`:
      - Section: **Identitas Pelanggan & Sesi** (Customer name, billing name, station/table, customer type).
      - Section: **Rincian Produk** (itemized table of selected items, quantities, unit prices, subtotals).
      - Section: **Rincian Pembayaran** (subtotal, payment method, discount %, final total, amount paid, change).
      - Action: "Konfirmasi Pembayaran & Cetak Struk" (`action=pay_now`).
      - Option: "Simpan Pending (Bayar di Kasir Nanti)" (`action=save`) if cashier chooses to save without settling immediately.
    - Double submit prevention: disable submit buttons on submit and show spinner/loading text.
    - F&B staff notice: If user is F&B staff, display explicit guidance banner that payment must be finalized at the main cashier.

---

### Task 2: TDD Test Suite & Backend Verification
- **Goal:** Create a comprehensive test suite `tests/Feature/Fnb/DynamicPaymentFlowFnbTest.php` testing all dynamic payment flows, backend settlement, invoice deduplication, and RBAC guards.
- **Files to modify/create:**
  - Create `tests/Feature/Fnb/DynamicPaymentFlowFnbTest.php`:
    - Test Paket order sets `requires_upfront_payment = true`, view displays "Bayar Sekarang", cashier settlement creates separate F&B invoice without changing game transaction status.
    - Test Lost Time order sets `requires_upfront_payment = false`, view displays primary button "Simpan Transaksi", order is saved as UNPAID without invoice, and kitchen can accept order.
    - Test Non-Bermain order sets `requires_upfront_payment = true`, view displays "Bayar Sekarang", cashier settlement creates standalone invoice.
    - Test F&B staff view displays "Simpan Order (Menunggu Kasir)", payment modal is hidden, and direct `action=pay_now` submission is rejected with HTTP 403.
    - Test that items settled via standalone F&B invoice are not rebilled during Lost Time final checkout.
    - Test double-submit protection / idempotency.

---

### Task 3: Full Verification & Regression Audit
- **Goal:** Verify that all F&B tests, QR ordering tests, production readiness tests, and full regression tests pass without errors.
- **Verification steps:**
  - Run `HARDWARE_DRIVER=mock php artisan test tests/Feature/Fnb/DynamicPaymentFlowFnbTest.php`.
  - Run `HARDWARE_DRIVER=mock php artisan test tests/Feature/Fnb/`.
  - Run `HARDWARE_DRIVER=mock php artisan test tests/Feature/QrOrdering/`.
  - Run `HARDWARE_DRIVER=mock php artisan test tests/Unit/Deployment/ProductionReadinessTest.php` (zero CDNs/Google Fonts).
  - Run full test suite `HARDWARE_DRIVER=mock php artisan test`.

