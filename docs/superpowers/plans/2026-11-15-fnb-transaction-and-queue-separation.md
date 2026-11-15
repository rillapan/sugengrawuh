# Implementation Plan: Pisahkan Transaksi F&B dan Antrean Order F&B

**Date:** 2026-11-15  
**Document Reference:** User Request — Pisahkan Transaksi F&B dan Antrean Order F&B  
**Approach:** Test-Driven Development (TDD) via Subagent-Driven Development (SDD)

---

## Background & Objectives
Currently, creating a new F&B order is coupled to the kitchen queue page (`/fnb/queue`) via a modal ("Buat Order F&B Baru").
The objective is to refactor and separate F&B operational workflows into two dedicated interfaces:
1. **Transaksi F&B (`/fnb/orders/create`):** Dedicated POS page for Cashier and F&B staff to record F&B orders, support playing vs non-playing customers, item selection, notes, and provide both "Simpan Order" (enters queue) and "Bayar & Konfirmasi" (immediate settlement and invoice creation for authorized cashiers).
2. **Antrean Order F&B (`/fnb/queue`):** Dedicated kitchen operational dashboard to accept, prepare, ready, and deliver orders, with "Buat Order Baru" modal removed.
3. **Sidebar & RBAC:** Clear sidebar menu separation between "Transaksi F&B" and "Antrean Order F&B", with strict backend authorization preventing F&B staff from settling payments or viewing financial reports.

---

## Tasks

### Task 1: Backend F&B Transaction Controller & Routing
- **Goal:** Provide dedicated controller and endpoints for F&B Transactions (`GET /fnb/orders/create` and `POST /fnb/orders/create`), supporting both "save" (enters kitchen queue as UNPAID) and "pay_now" (immediate payment settlement and standalone F&B invoice generation via `LostTimeBillingService::settleFnbOrder`). Enforce backend RBAC blocking F&B staff from executing `pay_now`.
- **Files to modify/create:**
  - Create `app/Http/Controllers/FnbTransactionController.php`:
    - `create(Request $request)`: loads active running transactions, active locations, and available F&B products with `effective_price`. Returns `fnb.transaction_create`.
    - `store(Request $request)`: validates playing vs non-playing inputs, locks stock, creates `FnbOrder` and items. If `action === 'pay_now'`, validates cashier authority (`!isFnbStaff() && !isOwner()`), calls `LostTimeBillingService::settleFnbOrder`, and redirects to `fnb.invoice.show`. If `action === 'save'`, redirects to `fnb.queue.index` or returns JSON.
  - Modify `routes/web.php`:
    - Register `Route::get('/fnb/orders/create', [FnbTransactionController::class, 'create'])->name('fnb.orders.create');`
    - Update `Route::post('/fnb/orders/create', [FnbTransactionController::class, 'store'])->name('fnb.orders.store');`
  - Create `tests/Feature/Fnb/FnbTransactionSeparationTest.php`:
    - Test `GET /fnb/orders/create` is accessible by Admin, Kasir, and F&B Staff.
    - Test "Simpan Order" creates `FnbOrder` as PENDING / UNPAID and appears in queue.
    - Test "Bayar & Konfirmasi" creates `FnbOrder`, settles payment immediately, marks PAID, and generates standalone Invoice.
    - Test F&B staff attempting "Bayar & Konfirmasi" is rejected with 403.
    - Test Paket order with F&B sets `requires_upfront_payment = true`.
    - Test Lost Time order sets `requires_upfront_payment = false`.
    - Test Non-Bermain order sets `requires_upfront_payment = true`.

---

### Task 2: Transaksi F&B View & Sidebar Navigation
- **Goal:** Build the dedicated, responsive Transaksi F&B page (`resources/views/fnb/transaction_create.blade.php`) and update the sidebar navigation in `resources/views/layouts/sidebar.blade.php`.
- **Files to modify/create:**
  - Create `resources/views/fnb/transaction_create.blade.php`:
    - Responsive mobile-first and desktop layout using SB Admin 2 / Bootstrap.
    - Customer type toggle:
      - **Aktif Bermain:** Dropdown of running sessions with auto-populated billing name, station/device, customer name input, and package type notice.
      - **Non-Bermain:** Dine-in / Takeaway selector, customer name input, and upfront payment notice.
    - Interactive F&B item selection with thumbnail image, stock badge, quantity, item notes, item subtotals, and overall order total.
    - Action buttons:
      - "Simpan Order" (`button type="submit" name="action" value="save"`).
      - "Bayar & Konfirmasi" (`button type="button"` opening payment summary modal for cashiers/admins, with payment method, discount, amount paid, and change calculation).
  - Modify `resources/views/layouts/sidebar.blade.php`:
    - Under section "Dapur & F&B", add:
      1. `Transaksi F&B` (`route('fnb.orders.create')`, active: `fnb-transaction`, icon `fas fa-cash-register`).
      2. `Antrean Order F&B` (`route('fnb.queue.index')`, active: `fnb-queue`, icon `fas fa-concierge-bell`).
  - Update `tests/Feature/Fnb/FnbTransactionSeparationTest.php`:
    - Test sidebar renders both Transaksi F&B and Antrean Order F&B.
    - Test Transaksi F&B view renders active sessions, customer type options, menu products, and payment modal for cashier.

---

### Task 3: Refactor Antrean Order F&B (`/fnb/queue`) & Full Verification
- **Goal:** Remove the "Buat Order Baru" modal and button from `/fnb/queue`, keeping it strictly as a kitchen operational dashboard. Verify full end-to-end integration and run all regressions.
- **Files to modify/create:**
  - Modify `resources/views/fnb/orders_queue.blade.php`:
    - Remove button `data-target="#modalCreateFnbOrder"` and modal markup `#modalCreateFnbOrder`.
    - Add quick-link button: `Transaksi F&B Baru` pointing to `route('fnb.orders.create')`.
    - Retain all kitchen operations (Terima, Proses, Siap, Selesai, Batal, Bayar).
  - Verify kitchen queue transitions and payment locks:
    - Unpaid Paket order and Non-Bermain order cannot be accepted before payment.
    - Paid order can be accepted and advances to PREPARING, READY, DELIVERED.
  - Testing & Verification:
    - Run `ProductionReadinessTest` (zero CDN/Google Fonts).
    - Run full test suite `php artisan test` (all tests pass).

