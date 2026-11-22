# SR-FINANCE-001 — Implementation Plan v1.1
## Multi-Cashier Revenue System (Billing Utama & F&B)

## Global Constraints
1. Immutable Cashier Channel Lock:
   - Kasir Billing: role = 'kasir', is_fnb_staff = false
   - Kasir F&B: role = 'kasir', is_fnb_staff = true
   - Backend validation in UserManagementController prohibits mutating is_fnb_staff on existing cashier accounts (AC-16, AC-17, AC-21).
   - Staff reassignment across channels requires creating a new account (AC-19).
   - Deactivating accounts preserves historical transaction associations (AC-20).
2. Three-Dimension Separation:
   - Order Creator (transactions.user_id / fnb_orders.created_by_user_id) != Payment Receiver (invoices.cashier_user_id) != Revenue Category (PLAYSTATION, BILLIARD, CAFE).
3. Single Source of Truth:
   - Cash receipts derived strictly from `invoices` where status is paid, using `final_total` (net), grouped by `cashier_user_id`, filtered by `paid_at`.
   - Legacy paid transactions without invoices attributed to `UNATTRIBUTED / BELUM TERATRIBUSI`.
4. Unpaid Isolation:
   - Unpaid or pending transactions/orders excluded from revenue totals; tracked as operational receivables only.
5. Proportional Discount Allocation:
   - Invoice discount allocated proportionally to item categories based on subtotal ratio; rounding remainder attributed to largest item so sum(net) == final_total.
6. Payment Integrity:
   - FnbStockService::cancelAndRestoreStock rejects cancellation of paid orders.
   - Remove silent fallback to User::value('id') ?? 1 or created_by_user_id in PaketBillingService and LostTimeBillingService.
   - Consistent lock ordering: Transaction then FnbOrder.
7. Zero Destructive DB Changes & Zero Regressions:
   - No new tables or columns.
   - Keep timer sessions and /fnb/queue fully operational.
   - Ensure all existing unit/feature tests pass.

# Task 1: Account Channel Lock & Payment Integrity Hardening
- Implement backend validation rule in `app/Http/Controllers/UserManagementController.php` method `update()`:
  - If target user has role === 'kasir', is_fnb_staff cannot be changed. Throw ValidationException / 422 if changed.
  - Updating name, email, password must remain allowed.
- Harden `app/Services/Billing/PaketBillingService.php`:
  - Require valid cashier_user_id when settling payment. Remove fallback `User::value('id') ?? 1` or `created_by_user_id`. Throw InvalidArgumentException if missing.
- Harden `app/Services/Billing/LostTimeBillingService.php`:
  - Require valid cashier_user_id. Remove silent fallbacks.
  - In settleFnbOrder(), acquire lock on Transaction before FnbOrder to prevent deadlocks.
- Harden `app/Services/Fnb/FnbStockService.php`:
  - In cancelAndRestoreStock(), check if `$order->payment_status === 'paid'`. If so, throw InvalidArgumentException("Pesanan yang sudah dibayar tidak dapat dibatalkan.").
- Create tests:
  - `tests/Feature/Finance/UserChannelLockTest.php` (AC-16, AC-17, AC-18, AC-21).
  - `tests/Feature/Finance/PaymentIntegrityHardeningTest.php` (AC-06, AC-07).

# Task 2: CashierRevenueService Single Source of Truth
- Create service `app/Services/Finance/CashierRevenueService.php` with:
  - `getCashierRevenueSummary(?Carbon $start, ?Carbon $end, ?int $cashierUserId): array`
    - Computes total_net (SUM(final_total)), invoice_count, payment_method breakdown (tunai, e-wallet, transfer_bank) from invoices.
    - Filters by paid_at between start and end.
    - If cashierUserId provided, filters by cashier_user_id.
  - `getChannelConsolidatedSummary(?Carbon $start, ?Carbon $end): array`
    - Returns breakdown:
      - billing_cashiers: receipts from kasir where is_fnb_staff == false.
      - fnb_cashiers: receipts from kasir where is_fnb_staff == true.
      - admin_cashiers: receipts from admin users.
      - unattributed: legacy paid transactions without invoices.
      - grand_total: sum of all above.
  - `getCategoryRevenueSummary(?Carbon $start, ?Carbon $end): array`
    - Calculates net revenue per category:
      - CAFE: items with item_type = 'FNB_ITEM'.
      - PLAYSTATION: items with item_type = 'GAME_RENTAL' where console category is PLAYSTATION.
      - BILLIARD: items with item_type = 'GAME_RENTAL' where console category is BILLIARD.
      - Applies proportional discount allocation to ensure category sum matches total invoice final_total.
  - `getHourlyRevenueSeries(Carbon $date, ?int $cashierUserId): array`
    - 24-hour distribution of receipts based on paid_at.
  - `getUnpaidReceivablesSummary(): array`
    - Active running sessions and pending orders summary.
- Create tests:
  - `tests/Unit/Finance/CashierRevenueServiceTest.php` covering AC-01, AC-02, AC-03, AC-04, AC-08, AC-09, AC-10, AC-11, AC-12, AC-22, AC-23.

# Task 3: Dashboard & RBAC Adaptation
- Modify `routes/web.php`:
  - Allow `fnb_staff` role to access `/home` and `/hourly-revenue-data`.
- Modify `app/Http/Controllers/HomeController.php`:
  - Remove forced redirect for `fnb_staff` role.
  - Delegate revenue, payment method, and chart data calculations to `CashierRevenueService`.
  - For Kasir Billing / Kasir F&B (`$user->isKasir()`): scope metrics strictly to `$user->id`.
  - For Admin / Owner: provide consolidated metrics and channel breakdowns.
- Modify `resources/views/layouts/sidebar.blade.php`:
  - Display Dashboard menu link for `fnb_staff`.
  - Ensure rental controls remain hidden for `fnb_staff`.
- Modify `resources/views/home.blade.php`:
  - Tailor dashboard card for F&B Cashier: "Penerimaan Kasir F&B Hari Ini", quick links to F&B Queue and Pos F&B.
  - Retain existing billing layout for Billing Cashier.
  - Show consolidated breakdown for Admin/Owner.
- Create tests:
  - `tests/Feature/Finance/DashboardMultiCashierTest.php` (AC-01, AC-02, AC-03, AC-04, AC-13).

# Task 4: Financial Reporting & Exports
- Modify `app/Http/Controllers/ReportController.php`:
  - Integrate `CashierRevenueService`.
  - Provide 3 report tabs:
    1. Cashier Receipt Breakdown (Kasir Penerima).
    2. Category Sales Breakdown (Rental PS, Billiard, Cafe).
    3. Consolidated Summary (Owner/Admin overview).
- Modify `app/Exports/TransactionsExport.php`:
  - Align export rows and totals with net invoice amounts and assigned cashiers.
- Modify `resources/views/laporan/index.blade.php`, `cetak-pdf.blade.php`, `cetak-excel.blade.php`:
  - Ensure printed/exported numbers match dashboard net figures.
- Create tests:
  - `tests/Feature/Finance/FinancialReportMultiCashierTest.php` (AC-10, AC-11, AC-12, AC-14, AC-15).

# Task 5: End-to-End Verification & Release Sign-Off
- Create `tests/Feature/Finance/MultiCashierEndToEndTest.php`:
  - Full end-to-end integration test simulating:
    1. User creation and channel lock enforcement.
    2. F&B direct order paid at Kasir F&B.
    3. F&B order paid at Kasir Billing.
    4. Combined Rental + F&B with discount settled at Kasir Billing.
    5. Dashboard query verification for each user.
    6. Report query verification and reconciliation.
- Run complete test suites:
  - `phpunit --filter=Finance`
  - `phpunit --filter=Billing`
  - `phpunit --filter=Fnb`
- Verify 100% clean test execution and zero regression.
