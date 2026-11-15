# Implementation Plan: Active F&B Orders & Order Confirmation

**Date:** 2026-11-15  
**Document Reference:** User Request — Active F&B Orders & Order Confirmation (`/station/{code}`)  
**Approach:** Test-Driven Development (TDD) via Subagent-Driven Development (SDD)

---

## Tasks

### Task 1: Active Station F&B Orders Backend & API
- **Goal:** Provide active F&B orders for the station's active session, securely scoped to `transaction_id` and validated against `session_token`.
- **Files to modify/create:**
  - Modify `app/Services/Fnb/QrOrderService.php`:
    - In `getStationMenuData(string $code)`: include `activeOrders` and `sessionType` (`tipe_transaksi`).
    - Add `getActiveStationOrders(string $code, string $token): array`: validates active session and token, retrieves orders where `transaction_id = $session->id_transaksi` and `status IN ('PENDING', 'ACCEPTED', 'PREPARING', 'READY', 'DELIVERED')` (excluding `CANCELLED` and `EXPIRED`), ordered by active priority (`READY`, `PREPARING`, `ACCEPTED`, `PENDING`, `DELIVERED`).
  - Modify `app/Http/Controllers/PublicQrOrderController.php`:
    - Add `getActiveOrders(Request $request, string $code)`: endpoint returning JSON `{ success: true, orders: [...] }` protected by rate-limit `throttle:60,1`.
    - Pass `activeOrders` and `sessionType` in `showStationMenu`.
  - Modify `routes/web.php`:
    - Register `Route::get('/station/{code}/orders', [PublicQrOrderController::class, 'getActiveOrders'])->name('public.qr.station.orders');`
  - Create test `tests/Feature/QrOrdering/ActiveStationOrdersTest.php`:
    - Test active session returns orders.
    - Test multiple devices on same session see all session orders.
    - Test cancelled and expired orders are excluded.
    - Test session rollover from Customer A to Customer B does not leak Customer A's orders.
    - Test invalid or expired token is rejected (403).

---

### Task 2: Order Confirmation Modal & Double-Submit Protection
- **Goal:** Intercept "Pesan Sekarang" on `/station/{code}` with a confirmation modal showing player name, customer name, station name, itemized products/quantities/subtotals, total, and payment instructions. Require explicit confirmation before submission, and prevent double submission.
- **Files to modify/create:**
  - Modify `resources/views/qr_order/menu.blade.php`:
    - Add Confirmation Modal (responsive, 100% offline bootstrap/SB Admin 2 modal).
    - Client-side validation: customer name required and at least 1 item selected before opening modal.
    - Modal displays:
      - Station / Meja: `{{ $location->name }} ({{ $location->code }})`
      - Pemain Utama: `{{ $playerName }}`
      - Pemesan: dynamic value from `customer_name` input
      - Itemized list: Name, Qty, Subtotal
      - Total amount
      - Payment note based on session type (Paket: bayar di muka ke kasir; Lost Time: dicatat ke tagihan main dan dibayar saat checkout).
      - Buttons: "Kembali / Edit Pesanan" and "Ya, Konfirmasi Pesanan".
    - Prevent double submission: click on "Ya, Konfirmasi Pesanan" generates `idempotency_key`, disables buttons, shows spinner/loading text, and submits via `fetch()`.
    - On success: shows success alert banner/modal with order number, clears selection, and triggers active orders refresh.
  - Create test `tests/Feature/QrOrdering/QrOrderConfirmationAndIdempotencyTest.php`:
    - Verifies JSON submission with `idempotency_key` creates order successfully.
    - Verifies repeated submission with same `idempotency_key` returns the same order without duplicating.
    - Verifies submit is rejected when session is expired or token invalid.

---

### Task 3: "Pesanan Saya" UI Section, Polling & Full Regression Verification
- **Goal:** Render a dedicated "Pesanan Saya" section on `/station/{code}` displaying active orders, priority badges, kitchen & payment statuses, and periodic auto-refresh without manual page reload.
- **Files to modify/create:**
  - Modify `resources/views/qr_order/menu.blade.php`:
    - Render **"Pesanan Saya"** section above or below the menu.
    - Displays:
      - Order Number, Time (`H:i`)
      - Pemesan (`customer_name`)
      - Items & Qty
      - Total (`Rp ...`)
      - Kitchen Status Badge (`PENDING`: Menunggu Dapur, `ACCEPTED`: Diterima, `PREPARING`: Dimasak/Disiapkan, `READY`: Siap Diantar, `DELIVERED`: Selesai Diantar)
      - Payment Status Badge (`Lunas` / `Belum Bayar`)
    - Client-side vanilla JS polling every 8 seconds via `GET /station/{code}/orders?token=...`:
      - Updates order list in place without screen flicker.
      - If session ends, notifies user and redirects to `/station/{code}` (which renders `no_session`).
  - Create test `tests/Feature/QrOrdering/StationActiveOrdersSectionTest.php`:
    - Verifies station menu renders "Pesanan Saya" with correct order status and items.
    - Verifies `DELIVERED` is shown in session history, while `CANCELLED`/`EXPIRED` are excluded.
  - Verification:
    - `tests/Unit/Deployment/ProductionReadinessTest.php` (zero external CDNs or Google Fonts).
    - Full regression: `php artisan test` (all tests pass).

