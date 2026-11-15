# Sugeng Rawuh — QR Ordering & F&B Billing Integration Implementation Plan (v0.2 Audited)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Membangun sistem QR Ordering F&B Sugeng Rawuh yang terintegrasi dengan billing PS/biliar, antrean dapur satu pintu, alur pembayaran kasir manual, kepatuhan mutlak aturan bisnis BR-01–BR-12, penyelesaian keputusan OPEN-01–OPEN-06 (Approved 14 Nov 2026), serta pencegahan total invoice dan stok ganda.

**Architecture:** Memisahkan entitas operasional pemesanan (`fnb_orders`, `fnb_order_items`) dan lokasi statis (`locations`) dari entitas sesi billing rental (`transactions`), serta memperkenalkan pencatatan settlement finansial yang rapi (`invoices`, `invoice_items`) dengan relasi unik `fnb_order_item_id` dan `transaction_fnb_id`. Token sesi dinamis (`session_token`) pada transaksi rental PS/biliar mengikat pesanan QR ke sesi aktif dan otomatis dinonaktifkan seketika sesi berakhir melalui `BillingService` dan `TransactionController`. Seluruh kalkulasi tarif menggunakan pricing engine existing (`Playstation::calculateCustomPrice`, `BillingService::calculateLostTimePrice`, `BillingService::applyLostTimeRounding`) tanpa duplikasi formula.

**Tech Stack:** Laravel 8.x, PHP 8.x, MySQL 8 / MariaDB, Blade Templates, Bootstrap 4 / SB Admin 2 (offline local assets only), PHPUnit 9.x.

---

## Global Constraints

- **Platform Target:** Laravel 8, MySQL, Docker/WSL2 pada PC kasir Windows 11.
- **Offline Assets Only:** Seluruh Blade views & CSS DILARANG memuat CDN/font eksternal (`fonts.googleapis.com`, `cdnjs.cloudflare.com`, dll.) sesuai `ProductionReadinessTest`.
- **Pembayaran Terpusat di Kasir:** Seluruh pembayaran dilakukan secara manual melalui kasir utama; TIDAK ADA payment gateway online, Midtrans, atau tombol bayar di HP pelanggan (BR-06).
- **Paket Wajib Bayar di Awal:** Untuk transaksi PAKET (`prepaid`, `custom_package`), pembayaran permainan WAJIB di awal sebelum aktivasi permainan dan sebelum hardware TV/Shelly ON (BR-01, AC-01, AC-02).
- **F&B Tambahan Paket Wajib Bayar di Awal:** Pesanan F&B susulan setelah paket lunas wajib dibayar di kasir sebelum diproses dapur (*pay-first*). Diterbitkan invoice F&B terpisah, dan invoice paket awal tetap immutable (OPEN-01, BR-04, AC-03).
- **Lost Time Bayar di Akhir:** Untuk LOST TIME (`postpaid`), pembayaran permainan dilakukan di akhir berdasarkan durasi aktual (BR-02). F&B boleh dilunasi lebih awal di kasir dengan invoice tersendiri dan WAJIB dikeluarkan dari invoice akhir permainan (BR-03, BR-10, AC-04).
- **Pelanggan Non-Bermain:** Pesanan F&B non-bermain (dine-in / takeaway) dilakukan via kasir/staf F&B dan wajib bayar di kasir sebelum pesanan diproses (*pay-first*) (OPEN-02, BR-07, AC-10).
- **QR Statis & Token Sesi Dinamis:** QR Code fisik meja bersifat permanen (`/station/{code}`). Setiap sesi rental aktif menghasilkan token sesi unik (`session_token`). Order QR wajib memvalidasi `transaction_id` dan `session_token` secara atomik saat submit. Saat sesi berakhir (stop/expire/checkout), token dinonaktifkan seketika dan halaman lama ditolak (OPEN-03, BR-05, BR-11, AC-05, AC-06). Jika tidak ada sesi valid, arahkan pelanggan memesan melalui kasir/staf F&B.
- **Sesi Selesai, F&B Masih Diproses:** Sesi sewa boleh ditutup setelah sisa tagihan permainan lunas; pesanan dapur yang sudah diterima tetap diselesaikan dan diantar ke area tunggu/pickup tanpa berpindah ke pelanggan baru (OPEN-04).
- **RBAC Staf F&B:** Tetap mempertahankan 3 role existing: `OWNER`, `ADMIN`, `KASIR`. Akun staf F&B menggunakan role `KASIR` dengan flag `is_fnb_staff = true`, dibatasi hanya pada antrean order dapur dan input order F&B tanpa akses pembayaran, laporan finansial, atau kontrol hardware (OPEN-05).
- **Aturan Mutasi Stok & Expiry:** Stok dikurangi tepat satu kali saat pesanan dikonfirmasi diproses (`ACCEPTED`). Pesanan unpaid pending kedaluwarsa setelah 30 menit (`fnb:expire-pending`). Pembatalan hanya merestorasi stok jika stok sebelumnya sudah dikurangi. Tidak ada refund (OPEN-06, BR-12, AC-14).
- **Keamanan & Isolasi:** Endpoint publik (`/station/{code}`, `/order/status/{orderNumber}`) strictly terisolasi dari kontrol hardware, TV, Shelly, dan dashboard internal kasir (AC-13), serta tidak membocorkan nama lengkap penyewa atau daftar pesanan meja lain.
- **Migrasi Non-Destruktif & Tanpa Commit Otomatis:** DILARANG menjalankan `migrate:fresh`, `db:wipe`, `docker compose down -v`, atau `git commit`/`git push`.

---

## Decision Log Summary (OPEN-01 – OPEN-06 Resolved)

| ID | Status | Keputusan Bisnis Final (Approved 14 November 2026) |
|---|---|---|
| **OPEN-01** | **RESOLVED / APPROVED** | Biaya paket wajib lunas di awal. F&B susulan pada paket wajib dibayar di kasir sebelum disiapkan dapur (*pay-first*). Invoice F&B terpisah diterbitkan; invoice paket immutable. |
| **OPEN-02** | **RESOLVED / APPROVED** | Pelanggan tanpa sesi permainan memesan melalui kasir atau staf F&B, dan wajib bayar sebelum pesanan diproses (*pay-first*). Invoice F&B mandiri. |
| **OPEN-03** | **RESOLVED / APPROVED** | QR meja fisik permanen (`/station/{code}`). Sesi aktif menghasilkan `session_token` unik. Order QR wajib validasi atomik saat submit. Sesi selesai = `session_token = null`. Tanpa sesi aktif, pelanggan diarahkan ke kasir/staf. |
| **OPEN-04** | **RESOLVED / APPROVED** | Sesi sewa boleh ditutup setelah sisa tagihan lunas. Pesanan dapur yang sudah diterima tetap diselesaikan dan diantar ke area tunggu/pickup. |
| **OPEN-05** | **RESOLVED / APPROVED** | Tetap 3 role (`OWNER`, `ADMIN`, `KASIR`). Akun staf F&B menggunakan role `KASIR` dengan flag `is_fnb_staff = true`, dibatasi hanya pada antrean order & menu F&B. |
| **OPEN-06** | **RESOLVED / APPROVED** | Stok dikurangi tepat 1x saat pesanan berstatus `ACCEPTED`. Pesanan pending unpaid kedaluwarsa 30 menit. Pembatalan merestorasi stok hanya jika status pernah `ACCEPTED`. |

---

## Implementation Tasks

### Task 1: Non-Destructive Schema, Models & RBAC Flag

**Files:**
- Create: `database/migrations/2026_11_15_000001_create_locations_table.php`
- Create: `database/migrations/2026_11_15_000002_add_session_token_to_transactions_table.php`
- Create: `database/migrations/2026_11_15_000003_add_fnb_staff_flag_to_users_table.php`
- Create: `database/migrations/2026_11_15_000004_create_fnb_orders_tables.php`
- Create: `database/migrations/2026_11_15_000005_create_invoices_and_items_tables.php`
- Create: `app/Models/Location.php`, `app/Models/FnbOrder.php`, `app/Models/FnbOrderItem.php`, `app/Models/Invoice.php`, `app/Models/InvoiceItem.php`
- Modify: `app/Models/Transaction.php`, `app/Models/User.php`
- Test: `tests/Unit/DatabaseSchemaSafetyTest.php`

**Interfaces:**
- Consumes: `devices.id`, `transactions.id_transaksi`, `fnbs.id`, `transaction_fnbs.id`, `users.id`.
- Produces: Tables `locations`, `fnb_orders`, `fnb_order_items`, `invoices`, `invoice_items`; columns `transactions.session_token`, `users.is_fnb_staff`; Eloquent models & `User::isFnbStaff()`.

- [ ] **Step 1: Write failing `DatabaseSchemaSafetyTest`** verifying all new tables, columns, and unique constraints (`invoice_items.fnb_order_item_id`, `invoice_items.transaction_fnb_id`, `transactions.session_token`, `users.is_fnb_staff`).
- [ ] **Step 2: Run test to verify failure:** `php artisan test tests/Unit/DatabaseSchemaSafetyTest.php` -> FAIL.
- [ ] **Step 3: Create the 5 additive migrations and 5 models; update `Transaction.php` and `User.php`.**
- [ ] **Step 4: Run test to verify pass:** `php artisan test tests/Unit/DatabaseSchemaSafetyTest.php` -> PASS.

---

### Task 2: Paket Upfront Payment & Controller/BillingService Integration (BR-01, BR-04, AC-01, AC-02, AC-03)

**Files:**
- Create: `app/Services/Billing/PaketBillingService.php`
- Modify: `app/Services/BillingService.php` (generate `session_token` on `startSession`; clear `session_token` on `stopSession` and `evaluateExpiration`)
- Modify: `app/Http/Controllers/TransactionController.php` (`store`, `processPayment`, `endTransaction`, `destroy`, `showPayment`)
- Modify: `resources/views/transaction/create.blade.php` (enforce pay-first button for Paket)
- Modify existing superseded HTTP tests: `tests/Feature/BillingWorkflowRegressionTest.php`, `tests/Feature/ServerSideBillingEngineTest.php`, `tests/Feature/DurationRegressionHotfixTest.php`, `tests/Feature/MidnightTimerCrossingTest.php`, `tests/Feature/Hardware/AutomationBillingLifecycleTest.php`, `tests/Feature/HardwareFrontendTest.php`
- Create: `tests/Feature/Billing/PaketUpfrontPaymentTest.php`

**Interfaces:**
- Consumes: `Playstation::calculateCustomPrice`, `CustomPackage`, `AutomationIntentService`, `Fnb`, `StockMutation`, `Invoice`, `InvoiceItem`.
- Produces: `PaketBillingService::activatePaidPaket(Transaction $tx, array $paymentData): Invoice`; draft-on-store & activate-on-payment flow in `TransactionController`.

- [ ] **Step 1: Write failing `PaketUpfrontPaymentTest`** covering AC-01 (draft Paket does NOT occupy device, does NOT set `started_at`/`session_token`, does NOT fire hardware ON, does NOT deduct stock) and AC-02/AC-03 (payment activates session, generates `session_token`, fires hardware ON/OFF intent, deducts stock 1x, creates single immutable `Invoice` with accurate `totalPs`, and keeps Paket invoice immutable if additional F&B is ordered later).
- [ ] **Step 2: Run test to verify failure:** `php artisan test tests/Feature/Billing/PaketUpfrontPaymentTest.php` -> FAIL.
- [ ] **Step 3: Implement `PaketBillingService`, wire `TransactionController` (`store`, `processPayment`, `endTransaction`, `destroy`) and `BillingService` (`session_token` lifecycle), update `transaction/create.blade.php`.**
- [ ] **Step 4: Run `PaketUpfrontPaymentTest` and full billing regression tests to verify pass.**

---

### Task 3: Lost Time Checkout, Early F&B Settlement & Unpaid-Only Billing (BR-02, BR-03, BR-10, OPEN-04, AC-04)

**Files:**
- Create: `app/Services/Billing/LostTimeBillingService.php`
- Modify: `app/Http/Controllers/TransactionController.php` (`endTransaction`, `processPayment`, `showPayment`)
- Modify: `app/Services/BillingService.php` (`stopSession` unpaid F&B calculation)
- Test: `tests/Feature/Billing/LostTimeCheckoutTest.php`

**Interfaces:**
- Consumes: `BillingService::calculateLostTimePrice`, `BillingService::applyLostTimeRounding`, `FnbOrder`, `FnbOrderItem`, `Invoice`, `InvoiceItem`.
- Produces: `LostTimeBillingService::settleFnbOrder(int $orderId, array $paymentData): Invoice`, `LostTimeBillingService::checkoutLostTime(int $transactionId, array $paymentData): Invoice`.

- [ ] **Step 1: Write failing `LostTimeCheckoutTest`** covering BR-02, BR-03, BR-10, AC-04 (early settlement of Order 1 generates separate `Invoice`, marks items `paid`, final Lost Time checkout uses exact `calculateLostTimePrice` + `applyLostTimeRounding`, includes ONLY unpaid Order 2, excludes Order 1, clears `session_token = null`, and prevents double-invoicing via unique `fnb_order_item_id`). Also test OPEN-04 (closing Lost Time session while accepted F&B is still preparing keeps the F&B order alive with origin `transaction_id` and `billing_name`).
- [ ] **Step 2: Run test to verify failure:** `php artisan test tests/Feature/Billing/LostTimeCheckoutTest.php` -> FAIL.
- [ ] **Step 3: Implement `LostTimeBillingService` using `BillingService` pricing engine and wire into `TransactionController@endTransaction` & `TransactionController@processPayment`.**
- [ ] **Step 4: Run test to verify pass:** `php artisan test tests/Feature/Billing/LostTimeCheckoutTest.php` -> PASS.

---

### Task 4: Public Static QR Menu, Atomic Session Token Validation & Offline QR SVG Generator (BR-05, BR-06, BR-11, OPEN-03, AC-05, AC-06, AC-08, AC-09, AC-12, AC-13)

**Files:**
- Create: `app/Services/Fnb/QrOrderService.php`
- Create: `app/Services/Qr/QrCodeSvgService.php` (zero-dependency offline QR matrix/SVG generator or clean local helper)
- Create: `app/Http/Controllers/PublicQrOrderController.php`
- Create: `app/Http/Controllers/Admin/LocationQrController.php`
- Create: `resources/views/qr_order/menu.blade.php`, `resources/views/qr_order/status.blade.php`, `resources/views/admin/locations/index.blade.php`
- Modify: `routes/web.php`, `resources/views/layouts/sidebar.blade.php`
- Test: `tests/Feature/QrOrdering/PublicQrOrderSubmissionTest.php`, `tests/Feature/QrOrdering/AdminLocationQrTest.php`

**Interfaces:**
- Consumes: `Location`, `Transaction`, `Fnb`, `PriceGroup`, `FnbOrder`, `FnbOrderItem`.
- Produces: Public routes `GET /station/{code}`, `POST /station/{code}/submit`, `GET /order/status/{orderNumber}` (rate-limited, no auth); Admin routes `/admin/locations` CRUD + QR print.

- [ ] **Step 1: Write failing `PublicQrOrderSubmissionTest` and `AdminLocationQrTest`** covering AC-05 (friend Riko orders on Budi's active session via static station QR), AC-06 (stale token from ended session or next customer's session rejected atomically with 403; idle table without session redirects/prompts customer to cashier/F&B staff), AC-08 (concurrent orders), AC-09 (insufficient stock rejected server-side with price snapshot `unit_price_beli` & `unit_price_jual`), AC-13 (public routes cannot reach hardware/TV/admin controls), and privacy (full `billing_name` NOT exposed in public view/JSON).
- [ ] **Step 2: Run tests to verify failure:** `php artisan test tests/Feature/QrOrdering/` -> FAIL.
- [ ] **Step 3: Implement `QrOrderService`, `PublicQrOrderController`, `LocationQrController`, `QrCodeSvgService`, routes, and offline-compliant Blade views.**
- [ ] **Step 4: Run tests to verify pass:** `php artisan test tests/Feature/QrOrdering/` -> PASS.

---

### Task 5: Unified Kitchen Queue, Stock-on-Acceptance, Order Expiry & RBAC Staf F&B (OPEN-01, OPEN-02, OPEN-05, OPEN-06, BR-07, BR-08, BR-09, BR-12, AC-10, AC-11, AC-14)

**Files:**
- Create: `app/Services/Fnb/FnbStockService.php`
- Create: `app/Http/Controllers/FnbOrderQueueController.php`
- Create: `app/Console/Commands/ExpirePendingFnbOrdersCommand.php`
- Create: `resources/views/fnb/orders_queue.blade.php`, `resources/views/fnb/invoice_receipt.blade.php`
- Modify: `app/Http/Middleware/CheckRole.php` (enforce `is_fnb_staff` restrictions)
- Modify: `app/Http/Controllers/UserManagementController.php`, `resources/views/user/` (allow Admin to toggle `is_fnb_staff` on `kasir` accounts)
- Modify: `app/Console/Kernel.php` (schedule `fnb:expire-pending` every 5 minutes)
- Modify: `resources/views/layouts/sidebar.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Fnb/StockAndRbacIntegrationTest.php`

**Interfaces:**
- Consumes: `FnbOrder`, `FnbOrderItem`, `Fnb`, `StockMutation`, `LostTimeBillingService::settleFnbOrder`.
- Produces: `FnbStockService::acceptAndDeductStock`, `FnbStockService::cancelAndRestoreStock`, Queue routes (`/fnb/queue`, `/fnb/orders/create`, `/fnb/queue/{id}/status`, `/fnb/queue/{id}/cancel`, `/fnb/queue/{id}/pay`), `fnb:expire-pending`.

- [ ] **Step 1: Write failing `StockAndRbacIntegrationTest`** covering:
  - OPEN-01 & OPEN-02: Paket extra F&B and non-playing F&B orders are `pay-first` (cannot transition to `ACCEPTED` while `payment_status === 'unpaid'`; must be settled by non-F&B-staff `kasir` first).
  - OPEN-06: Lost Time orders CAN transition to `ACCEPTED` before payment, decrementing stock & logging `StockMutation` (`out`) ONCE; pending unpaid orders expire after 30 min (`EXPIRED`), accepted orders do NOT expire; cancelling `PENDING` order does NOT touch stock, cancelling `ACCEPTED` order restores stock (`in`) once.
  - OPEN-05: `is_fnb_staff = true` `kasir` user can access `/fnb/queue` and create F&B orders, and receives 403 on payment, financial reports, and hardware control routes.
  - AC-10 & AC-11: Orders from `QR`, `CASHIER`, and `FNB_STAFF` all appear in `/fnb/queue`, and non-playing orders generate standalone F&B invoices.
- [ ] **Step 2: Run test to verify failure:** `php artisan test tests/Feature/Fnb/StockAndRbacIntegrationTest.php` -> FAIL.
- [ ] **Step 3: Implement `FnbStockService`, `FnbOrderQueueController`, `ExpirePendingFnbOrdersCommand`, `CheckRole` updates, `Kernel` schedule, queue & receipt Blade views, and routes.**
- [ ] **Step 4: Run test to verify pass:** `php artisan test tests/Feature/Fnb/StockAndRbacIntegrationTest.php` -> PASS.

---

### Task 6: Financial Reporting & Stock Reconciliation (BR-10, AC-02, AC-11)

**Files:**
- Modify: `app/Http/Controllers/FnbController.php` (`laporanPenjualan`, `getSalesOverTime`, `exportExcel`, `exportPdf`)
- Modify: `app/Http/Controllers/ReportController.php` (ensure standalone F&B invoices appear cleanly in revenue reports without double counting game transactions)
- Test: `tests/Feature/Fnb/FnbSalesReportingTest.php`

**Interfaces:**
- Consumes: `Invoice`, `InvoiceItem`, `TransactionFnb`, `Transaction`, `Fnb`.
- Produces: Deduplicated F&B sales & profit/loss reports across both new `invoice_items` and legacy `transaction_fnbs`.

- [ ] **Step 1: Write failing `FnbSalesReportingTest`** verifying:
  1. New F&B sales from Paket upfront, Lost Time early F&B, Lost Time final checkout, and standalone F&B invoices are aggregated from `invoice_items` (`unit_price_beli`, `unit_price_jual`, `subtotal`) without double counting even if `transaction_fnbs` also exists for the Paket.
  2. Legacy `transaction_fnbs` (without matching `invoice_items`) still appear in reports.
  3. Changing `fnbs.harga_jual` after a sale does NOT retroactively alter historical report revenue (snapshot preserved).
- [ ] **Step 2: Run test to verify failure:** `php artisan test tests/Feature/Fnb/FnbSalesReportingTest.php` -> FAIL.
- [ ] **Step 3: Update `FnbController` and `ReportController` queries with deduplicated SQL.**
- [ ] **Step 4: Run test to verify pass:** `php artisan test tests/Feature/Fnb/FnbSalesReportingTest.php` -> PASS.

---

### Task 7: Concurrency, Race Condition & Production Readiness Suite (AC-07, AC-08, AC-12, AC-13)

**Files:**
- Create: `tests/Feature/Concurrency/CheckoutAndOrderingRaceConditionTest.php`

**Interfaces:**
- Consumes: `LostTimeBillingService`, `QrOrderService`, `FnbStockService`, `PaketBillingService`.

- [ ] **Step 1: Write `CheckoutAndOrderingRaceConditionTest`** covering:
  - AC-07: Checkout completes & clears `session_token` right before QR order submits -> QR order fails deterministically with `AuthorizationException` (403), never enters settled invoice.
  - AC-08: Simultaneous QR order submissions for limited stock -> pessimistic lock (`lockForUpdate`) prevents overselling when `ACCEPTED`.
  - Session rollover: Customer A ends, Customer B starts new session with new `session_token`, Customer A's old tab submits with old token -> rejected, never charged to Customer B.
  - Double settlement guard: Trying to settle an already-settled `FnbOrderItem` fails or returns idempotently without creating a second `InvoiceItem`.
- [ ] **Step 2: Run test to verify pass:** `php artisan test tests/Feature/Concurrency/CheckoutAndOrderingRaceConditionTest.php` -> PASS.
- [ ] **Step 3: Run full test suite & `ProductionReadinessTest`:** `php artisan test` -> ALL PASS.
