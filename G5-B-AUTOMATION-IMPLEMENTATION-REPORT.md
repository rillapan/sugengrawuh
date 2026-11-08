# SUGENG RAWUH — G5-B AUTOMATION SOFTWARE IMPLEMENTATION REPORT

**Project:** Sugeng Rawuh — Integrated Billing & POS Management System  
**Repository:** `sistem_kasir_PS-main`  
**Gate:** G5-B — Billing-to-Hardware Automation Software Implementation  
**Previous Gate:** G5-A DESIGN RECONCILED — APPROVED  
**Approved Business Rule:** SR-G5-BR-001 (Automatic Billiard Light Shutdown on Prepaid Expiration: Immediate Cutoff, Zero Grace Period, No Cashier Confirmation)  
**Baseline Test Count:** 268 Passing Tests  
**Final Test Count:** 295 Passing Tests (268 Baseline + 27 New Tests, 1218 Assertions, 100% Green, 0 Failures, 0 Skipped)  
**Execution Mode:** IMPLEMENTATION AUTHORIZED — MOCK/SIMULATION ONLY (`HARDWARE_REAL_EXECUTION_ENABLED=false`, `HARDWARE_DRIVER=mock`)  
**Stack:** Laravel 8.83.27, PHP 8.2, MySQL 8.0, Blade, Bootstrap 4.6, jQuery  
**Date:** November 5, 2026  

---

## EXECUTIVE SUMMARY

In strict accordance with the approved **SR-G5-BR-001** business decision and the reconciled G5-A architecture specifications, Gate G5-B has been successfully implemented and verified end-to-end.

The system connects the **G2 Server-Side Billing Engine**, **G3 Station-to-Hardware Mappings**, and **G4-A/B1 Hardware Driver Layer** via a resilient **Transactional Outbox Architecture**. When prepaid billing expires, billiard table lights are ordered to turn **OFF immediately** at `server_now >= transactions.expires_at`, without any grace period, without awaiting client-side JavaScript timers, and without requiring cashier approval.

All hardware communications throughout Gate G5-B operate exclusively with mock/simulation transports. The production database (`billing-sugeng-rawuh`) remained **100% untouched and isolated**, while all database migrations and automated tests were executed solely against the dedicated testing database (`kasir_ps_test`). All 268 baseline tests remain fully green, and 27 newly authored automated tests pass with 100% success.

---

## 1. GIT BASELINE & FILE CHANGES

### 1.1 Git Status & Branch Information
- **Active Branch:** `main`
- **Working Tree Policy:** All G5-B code and migrations are localized to the working tree. In accordance with safety instructions, **NO commits, pushes, merges, or production deployments** have been performed.
- **Baseline Test Suite:** 268 tests passing (100% passing).
- **Post-Implementation Test Suite:** 295 tests passing (+27 new automated tests, 1218 assertions, 0 failures, 0 skipped).

### 1.2 Inventory of File Changes

#### A. Database Migrations (Testing Connection Only)
1. `database/migrations/2026_11_05_000003_create_automation_intents_table.php`  
   Creates `automation_intents` table with strict foreign key types (`BIGINT UNSIGNED`), composite indexes for worker claim performance (`status`, `scheduled_at`), station lease locking, generation tracking, and idempotency key enforcement.
2. `database/migrations/2026_11_05_000004_create_automation_execution_logs_table.php`  
   Creates `automation_execution_logs` table for telemetry and audit trail. Configured with foreign key constraint `ON DELETE SET NULL` on `intent_id` so that intent retention pruning (30 days) never cascades or destroys diagnostic logs (90-day retention).
3. `database/migrations/2026_11_05_000005_add_session_generation_and_hardware_fields.php`  
   Adds `session_generation` (`BIGINT UNSIGNED DEFAULT 0`), `last_hardware_state` (`VARCHAR(32)`), and `last_hardware_verified_at` (`TIMESTAMP NULL`) to `devices` table; adds `session_generation` (`BIGINT UNSIGNED DEFAULT 0`) to `transactions` table.

#### B. Eloquent Domain Models
4. `app/Models/AutomationIntent.php`  
   Encapsulates state machine constants (`PENDING`, `PROCESSING`, `COMPLETED`, `FAILED`, `CANCELLED`, `STALE`, `RETRY_PENDING`), query scopes (`eligibleForExecution`, `staleLocks`), lease management, and relationships to `Device`, `Transaction`, `PhysicalDevice`, and `StationDeviceMapping`.
5. `app/Models/AutomationExecutionLog.php`  
   Captures immutable dispatch audit logs, attempt numbers, latency measurements in milliseconds, observed hardware states, error categories, and driver metadata.
6. `app/Models/Device.php` *(Updated)*  
   Added `session_generation`, `last_hardware_state`, `last_hardware_verified_at` casts, relationships `activeLightRelay()`, `activeDisplayTv()`, and `automationIntents()`.
7. `app/Models/Transaction.php` *(Updated)*  
   Added `session_generation` cast and `automationIntents()` relationship.

#### C. Core Automation Services
8. `app/Services/Hardware/Automation/AutomationCapabilityPolicy.php`  
   Enforces capability rules: Permits Shelly relay switching (`SWITCH_ON`, `SWITCH_OFF`) for billiard table lights; strictly prevents automated power toggling on Samsung TVs (`SKIPPED_UNVERIFIED_CAPABILITY`), preventing dangerous standby/power loops on commercial displays.
9. `app/Services/Hardware/Automation/AutomationFencingGuard.php`  
   Performs multi-point fencing checks before command dispatch: Validates station existence, session generation consistency (aborting stale intents), active station mapping status (`ACTIVE`), and transaction operational validity.
10. `app/Services/Hardware/Automation/AutomationIntentService.php`  
    Manages atomic intent creation inside billing transactions: Atomically increments station generation, creates `SWITCH_ON` (scheduled for immediate execution), pre-schedules `SWITCH_OFF` at `expires_at`, cancels superseded scheduled intents, and handles compensation intents.
11. `app/Services/Hardware/Automation/AutomationDispatcherService.php`  
    Orchestrates the 3-phase execution model: Phase 1 claim with `FOR UPDATE SKIP LOCKED` (< 5ms database row lock); Phase 2 driver dispatch outside database transactions (zero DB lock during network I/O); Phase 3 settlement with exponential retry backoff (+3s, +10s, +30s), pre-retry read-back probe, and dead lease recovery.
12. `app/Services/Hardware/Automation/AutomationStatusService.php`  
    Formats masked hardware telemetry for cashier UI badges (`Simulasi Relay ON`, `Simulasi Relay OFF`, `Command Pending`, `Command Failed`, `Device Offline`). Strictly masks sensitive credentials (IP, MAC, tokens, credentials).

#### D. Billing Engine Integration
13. `app/Services/BillingService.php` *(Integrated)*  
    Atomic integration with `AutomationIntentService`:
    - `startSession()`: Commits transaction, bumps generation, creates immediate `SWITCH_ON` and pre-scheduled `SWITCH_OFF`.
    - `extendSession()`: Bumps generation, cancels old scheduled `SWITCH_OFF`, creates new pre-scheduled `SWITCH_OFF` at new expiry timestamp.
    - `stopSession()`: Cancels pre-scheduled `SWITCH_OFF`, creates immediate `SWITCH_OFF` intent with updated generation.
    - `evaluateExpiration()`: Maintains G2 business status transitions while recognizing pre-scheduled automation cutoff without redundant intents.
14. `app/Http/Controllers/DeviceController.php` *(Refactored)*  
    Refactored `stopDevice($id)` to delegate directly to `BillingService::stopSession()` while maintaining 100% response contract parity (redirects, flash messages, HTTP status codes, and G2 fallback support).

#### E. Console Commands & Daemon Scheduling
15. `app/Console/Commands/BillingAutomationWorkerCommand.php`  
    `php artisan billing:automation-worker` supporting `--once`, `--interval` (default 500ms), and `--batch` (default 10). Includes graceful SIGINT/SIGTERM shutdown, worker heartbeat, and crash recovery.
16. `app/Console/Commands/BillingAutomationPruneCommand.php`  
    `php artisan billing:automation-prune` implementing the 30-day intent retention policy while keeping audit logs intact. Scheduled daily in `app/Console/Kernel.php`.

#### F. Web API & Cashier Frontend
17. `app/Http/Controllers/StationAutomationController.php`  
    Implements `/api/stations/{id}/automation-status` and `/api/stations/{id}/automation/manual-action` protected by `auth` and `check_role:admin,kasir`.
18. `app/Http/Controllers/Admin/AutomationMonitoringController.php`  
    Admin telemetry dashboard endpoints for automation intents and audit logs.
19. `resources/views/device/index.blade.php` *(Enhanced)*  
    Integrated real-time hardware automation badges on cashier station cards, dynamic failure alert banners with prominent station names, and 3-second background polling via jQuery.
20. `routes/web.php` *(Updated)*  
    Registered automation status and monitoring routes with proper role-based authorization guards.

---

## 2. DATABASE SAFETY GATE & MIGRATION VERIFICATION

### 2.1 Database Connection Safety Gate
Before any migration scripts were executed, the active database connection was explicitly audited via Tinker:
- **Default Database Connection:** `billing-sugeng-rawuh` (Production Database)
- **Testing Database Connection:** `kasir_ps_test` (Host: 127.0.0.1, Port: 8889, User: root)

> [!IMPORTANT]
> **Safety Assertion:** All migration runs (`php artisan migrate --env=testing`) and PHPUnit test runs were strictly isolated to `kasir_ps_test`. The production database was completely shielded from any schema mutation or data write.

### 2.2 Production Database Verification
A schema audit of `billing-sugeng-rawuh` confirmed zero changes:
```
Table Count: 22 tables
Tables: custom_package_fnb, custom_package_playstation, custom_package_price_group,
        custom_packages, device_playstation, devices, expense_categories, expenses,
        failed_jobs, fnbs, hourly_prices, members, migrations, password_resets,
        personal_access_tokens, playstations, price_groups, stock_mutations,
        transaction_fnbs, transactions, users, work_shifts.
```
- No `automation_intents` table exists on `billing-sugeng-rawuh`.
- No `automation_execution_logs` table exists on `billing-sugeng-rawuh`.
- `devices` columns on production: `id, nama, playstation_id, status, created_at, updated_at` (no G5-B columns).
- `transactions` columns on production: original columns only (no G5-B columns).

### 2.3 Foreign Key Data Types & Constraints
On `kasir_ps_test`, all foreign key columns were implemented with exact matching types:
- `transactions.id_transaksi`: `BIGINT UNSIGNED`
- `devices.id`: `BIGINT UNSIGNED`
- `physical_devices.id`: `BIGINT UNSIGNED`
- `station_device_mappings.id`: `BIGINT UNSIGNED`

Foreign key constraint on `automation_execution_logs.intent_id` is set to `ON DELETE SET NULL`, ensuring 90-day failure log retention even after 30-day intent pruning.

---

## 3. AUTOMATION ARCHITECTURE IMPLEMENTATION

```
+-----------------------------------------------------------------------------------+
|                        BILLING ENGINE (G2 Lifecycle)                              |
|   startSession()          extendSession()          stopSession() / Expiry         |
+-----------------------------------------------------------------------------------+
                                      |
                                      | Atomic DB Transaction
                                      v
+-----------------------------------------------------------------------------------+
|                      TRANSACTIONAL OUTBOX (automation_intents)                    |
|   - SWITCH_ON  (scheduled_at = NOW)                                              |
|   - SWITCH_OFF (pre-scheduled_at = expires_at)                                    |
|   - session_generation incremented atomicity                                      |
+-----------------------------------------------------------------------------------+
                                      |
                                      | Phase 1: FOR UPDATE SKIP LOCKED (< 5ms)
                                      v
+-----------------------------------------------------------------------------------+
|                 AUTOMATION DISPATCHER SERVICE (3-Phase Worker)                    |
|   Phase 1: Claim Eligible Intents (Atomic lease lock)                             |
|   Phase 2: Dispatch via HardwareDriverInterface (Mock/Simulation Transport)       |
|            - Capability Policy Check (Shelly relay allowed; Samsung TV blocked)   |
|            - Fencing Guard Check (Station, mapping, session generation match)     |
|            - Pre-Retry Read-Back (Avoid redundant physical switching)             |
|   Phase 3: Settle & Audit (automation_execution_logs)                             |
+-----------------------------------------------------------------------------------+
```

### Three-Phase Worker Model
1. **Phase 1: Claim Intent**  
   The worker queries eligible intents (`PENDING` or `RETRY_PENDING` with `scheduled_at <= now()`) using MySQL 8 `FOR UPDATE SKIP LOCKED`. It assigns a `locked_by` worker ID and sets `status = 'PROCESSING'` with a 60-second lease, committing immediately. Row lock duration is kept under 5 milliseconds.
2. **Phase 2: Driver Execution**  
   Outside any database transaction, the dispatcher evaluates `AutomationFencingGuard` and `AutomationCapabilityPolicy`. It dispatches the command to `HardwareDriverManager` using the mock driver.
3. **Phase 3: Settlement & Audit Log**  
   A new short transaction records execution telemetry in `automation_execution_logs` (latency, observed output state, transport ack, result code). On success, the intent is marked `COMPLETED` and the station's `last_hardware_state` is updated. On failure, exponential backoff is scheduled (+3s, +10s, +30s) up to 4 total attempts.

---

## 4. BILLING LIFECYCLE INTEGRATION RESULTS

### 4.1 Prepaid Start
- Atomically increments station `session_generation` (e.g., from 0 to 1).
- Assigns generation 1 to the newly created transaction.
- Creates `SWITCH_ON` intent with `scheduled_at = now()`.
- Pre-schedules `SWITCH_OFF` intent with `scheduled_at = expires_at`.
- Both intents are committed atomically with the transaction.

### 4.2 Prepaid Extend
- Station generation is incremented (e.g., from 1 to 2).
- The superseded pre-scheduled `SWITCH_OFF` intent (generation 1) is immediately marked `CANCELLED`.
- A new `SWITCH_OFF` intent is created with generation 2 and `scheduled_at = new_expires_at`.
- If an older worker attempts to dispatch the cancelled intent, the `AutomationFencingGuard` rejects it as `STALE`.

### 4.3 Prepaid Expiry (Approved Rule SR-G5-BR-001)
- The worker claims the pre-scheduled `SWITCH_OFF` intent as soon as `server_now >= expires_at`.
- **Immediate Cutoff:** The light is turned OFF without waiting for cashier confirmation, without awaiting client JavaScript timers, and without any grace period.
- **Independence from G2 Billing Status:** If the intent triggers while G2 billing worker has not yet transitioned `billing_status` from `RUNNING` to `EXPIRED_PENDING_ACTION`, the intent is verified valid and dispatched immediately. Expiration check does not depend on billing status changes.

### 4.4 Prepaid Stop Early
- The cashier stops the session prior to scheduled expiry.
- `stopSession()` marks the pending pre-scheduled `SWITCH_OFF` intent as `CANCELLED`.
- An immediate `SWITCH_OFF` intent (`event_type = 'BILLING_STOPPED'`) is created and executed immediately by the worker.

### 4.5 Lost Time (Postpaid)
- Start creates immediate `SWITCH_ON` intent with `expires_at = null` and no pre-scheduled expiry.
- Operates continuously across midnight without cutoff.
- Upon cashier stop, an immediate `SWITCH_OFF` intent is dispatched.

### 4.6 Direct Controller Path Parity (`DeviceController::stopDevice()`)
`DeviceController::stopDevice()` was audited and refactored:
```php
// app/Http/Controllers/DeviceController.php
public function stopDevice($id)
{
    $device = Device::findOrFail($id);
    $activeTx = Transaction::where('device_id', $id)
        ->whereIn('billing_status', [Transaction::BILLING_RUNNING, Transaction::BILLING_EXPIRED_PENDING_ACTION])
        ->latest('id_transaksi')
        ->first();

    if ($activeTx) {
        $this->billingService->stopSession($activeTx->id_transaksi, 'cashier_stop', auth()->id());
        return redirect()->route('device.index')->with('success', 'Billing perangkat ' . $device->nama . ' berhasil dihentikan.');
    }
    // Backward compatibility fallback...
}
```
All existing validation, redirect routes, flash messages, and error paths were retained with 100% parity (verified in `tests/Feature/DeviceStopEndpointTest.php`).

---

## 5. WORKER OPERATION & PERFORMANCE METRICS

### 5.1 Artisan Automation Worker Command
- **Command:** `php artisan billing:automation-worker`
- **Supported Options:**
  - `--once`: Processes one batch of eligible intents and exits cleanly.
  - `--interval=500`: Polling interval in milliseconds (default 500ms).
  - `--batch=10`: Maximum intents claimed per batch (default 10).
- **Graceful Termination:** Registers signal handlers for `SIGINT` and `SIGTERM` to allow in-flight dispatch completion before shutdown.
- **Heartbeat & Recovery:** Periodically checks for expired processing leases (> 60s) and resets orphaned locks caused by unexpected worker crashes.

### 5.2 Expiry Timing & Latency Results
From live mock driver execution telemetry recorded in `automation_execution_logs`:
- **Driver Dispatch Latency:** 1 ms to 3 ms (simulated HTTP RPC).
- **Worker Polling Latency:** 500 ms nominal polling frequency.
- **End-to-End Expiration Cutoff:** 500 ms to 1005 ms after `expires_at`.
- **Target Latency Requirement:** Satisfies the operational target of 500–1500 ms without real-world network jitter.

---

## 6. CONCURRENCY, FENCING & IDEMPOTENCY

| Scenario | Challenge | Architectural Resolution | Test Verification |
|---|---|---|---|
| **STOP vs EXPIRE Race** | Expiry triggers at same second cashier clicks Stop | Generation bump cancels scheduled OFF; immediate STOP intent executed; duplicate OFF skipped | `TC-11: test_11_stop_vs_expire_race` |
| **Extend vs Expiry Dispatch** | Cashier extends session while worker claims expiry | Worker fencing verifies generation at dispatch; stale intent aborted; light stays ON | `TC-12: test_12_extend_vs_expiry_dispatch_race` |
| **Pending ON After Stop** | Delayed start ON intent processed after session ended | Fencing guard verifies transaction is no longer active; marks ON intent `STALE` | `TC-13: test_13_pending_on_after_session_ended` |
| **New Customer vs Old OFF** | Retrying old customer's OFF after new customer started | Fencing compares station generation (2 > 1); old OFF rejected as `STALE`; new session unharmed | `TC-14: test_14_new_customer_session_crossover` |
| **Mapping Mutation** | Hardware mapping unmapped during active billing | Fencing detects inactive mapping; logs `SKIPPED_UNMAPPED`; avoids orphaned signals | `TC-15: test_15_station_mapping_deactivated` |
| **Worker Concurrency** | Multiple workers running simultaneously | `FOR UPDATE SKIP LOCKED` guarantees disjoint intent claims; zero duplicate commands | `TC-16: test_16_worker_concurrency_and_claim_ownership` |
| **Worker Crash Recovery** | Worker killed during network I/O | Lease expires after 60s; next worker resets lease to `RETRY_PENDING` and resumes | `TC-17: test_17_stale_worker_lease_recovery` |

---

## 7. RETRY & FAILURE RECOVERY MECHANISMS

1. **Bounded Retry Schedule:** Total maximum 4 attempts (1 initial dispatch + 3 retries).
   - Retry 1: +3 seconds
   - Retry 2: +10 seconds
   - Retry 3: +30 seconds
2. **Pre-Retry Read-Back Optimization:** Before issuing a physical/mock retry command, the worker polls the hardware status (`GetStatus`). If the device's relay is already in the target state (e.g., relay is already `true` for `SWITCH_ON`), the intent is marked `COMPLETED` immediately (`VERIFIED_ALREADY_IN_STATE`), avoiding redundant mechanical contact cycles.
3. **Exhaustion Handling:** If all 4 attempts fail:
   - Intent transitions to `FAILED`.
   - Incident is logged in `automation_execution_logs` with error category and latency.
   - Cashier dashboard triggers an alert notification with the specific table name (`Meja Biliar 01`).
   - Billing session and financial records remain 100% unaffected.

---

## 8. HARDWARE SAFETY & SECURITY BOUNDARY AUDIT

### 8.1 Zero Real Network Execution
- `HARDWARE_REAL_EXECUTION_ENABLED` is set to `false`.
- `HARDWARE_DRIVER` is configured to `mock`.
- `HARDWARE_SIMULATION_ENABLED` is set to `true`.
- Zero sockets, HTTP connections, or WebSocket frames were opened to real IP addresses during tests or QA.

### 8.2 Samsung TV Safety Boundary
- `AutomationCapabilityPolicy` explicitly restricts power control for Samsung Smart TVs.
- Automated power switching for displays is marked `SKIPPED_UNVERIFIED_CAPABILITY`.
- Smart relays are strictly allocated to billiard lighting circuits, never to television or PlayStation console power mains.

### 8.3 Cashier Information Masking & RBAC
- `/api/stations/{id}/automation-status` filters out sensitive device parameters.
- Kasir and Owner dashboards receive only high-level status badges and user-friendly error banners.
- IP addresses (`192.168.1.150`), MAC addresses (`A8:03:2A:B1:01:50`), pairing tokens, and RPC credentials are completely stripped from all cashier-facing responses.

---

## 9. CASHIER & ADMIN UI IMPLEMENTATION

### 9.1 Station Card Hardware Badges
The station card view (`resources/views/device/index.blade.php`) has been augmented with real-time hardware status indicators:
- `Simulasi Relay OFF`: Grey outline badge (`badge-secondary`, icon `far fa-circle`)
- `Simulasi Relay ON`: Green badge (`badge-success`, icon `fas fa-bolt`)
- `Command Pending`: Blue badge (`badge-info`, icon `fas fa-spinner fa-spin`)
- `Command Failed`: Red badge (`badge-danger`, icon `fas fa-exclamation-triangle`)
- `Device Offline`: Orange badge (`badge-warning`, icon `fas fa-wifi-slash`)

### 9.2 Real-Time Polling & Failure Notification
- An unobtrusive AJAX poller queries `/api/stations/{id}/automation-status` every 3 seconds for active stations.
- When an intent fails after retry exhaustion, an alert banner appears prominently at the top of the cashier view:
  > **Peringatan Otomasi Hardware:** Gagal mematikan lampu meja secara otomatis untuk **Meja Biliar 01**. Silakan periksa relay atau gunakan tombol aksi manual.
- Displays table name clearly without technical jargon or device IPs.

---

## 10. MANUAL BROWSER QA VERIFICATION

Browser QA was conducted on `http://127.0.0.1:8008` connected to `kasir_ps_test`:

| Step | User Action | Expected Behavior | Observed Result | Evidence |
|---|---|---|---|---|
| **1** | Open `/device` as Kasir | `Meja Biliar 01` displays `Tersedia` with `Simulasi Relay OFF` | Grey `Simulasi Relay OFF` badge visible | `device_page_relay_off_1793883510009.png` |
| **2** | Start 1-hour prepaid billiard session | Station enters `Digunakan`; Relay switches ON automatically | Relay badge switches to green `Simulasi Relay ON` within 1s | Captured in worker log & API response |
| **3** | Extend session by 30 mins | Expiry extended; old OFF intent cancelled | Generation bumped to 2; old intent `CANCELLED`; new OFF scheduled | Intent 103 `CANCELLED`, Intent 104 scheduled |
| **4** | Stop session early | Station frees immediately; Relay switches OFF automatically | Relay badge switches to grey `Simulasi Relay OFF`; intent `COMPLETED` | Verified via API & Tinker |
| **5** | Session with F&B items | Add Teh Botol Sosro to billing | Stock decremented by 1; total billing computed accurately | Stock mutations verified |
| **6** | Video Session | Full browser interaction flow | Complete recording saved | `g5b_browser_e2e_qa_1793882886814.webp` |

---

## 11. AUTOMATED TEST SUITE EXECUTION RESULTS

### 11.1 Full Test Suite Summary
```
PHPUnit 9.6.3 by Sebastian Bergmann and contributors.

...............................................................  63 / 295 ( 21%)
............................................................... 126 / 295 ( 42%)
............................................................... 189 / 295 ( 64%)
............................................................... 252 / 295 ( 85%)
...........................................                     295 / 295 (100%)

Time: 00:03.956, Memory: 60.50 MB
OK (295 tests, 1218 assertions)
```

### 11.2 Test Breakdown by Category

| Category / Test Suite | Class Name | Test Count | Status |
|---|---|---|---|
| **Baseline Tests (G1–G4-B1)** | Multiple existing feature & unit test suites | 268 tests | **268/268 PASS (100%)** |
| **G5-B Lifecycle Suite** | `Tests\Feature\Hardware\BillingAutomationLifecycleTest` | 8 tests | **8/8 PASS** |
| **G5-B Concurrency & Fencing** | `Tests\Feature\Hardware\BillingAutomationFencingAndConcurrencyTest` | 7 tests | **7/7 PASS** |
| **G5-B Resilience & Retention** | `Tests\Feature\Hardware\BillingAutomationResilienceAndRetentionTest` | 10 tests | **10/10 PASS** |
| **G5-B Artisan Commands** | `Tests\Feature\Hardware\BillingAutomationCommandsTest` | 2 tests | **2/2 PASS** |
| **Total Test Suite** | Full Repository Test Suite | **295 tests** | **295/295 PASS (100%)** |

### 11.3 G5-B Detailed Test Matrix (27 Tests)

```
Billing Automation Commands (Tests\Feature\Hardware\BillingAutomationCommands)
  ✔ Artisan automation worker once executes and exits
  ✔ Artisan automation prune removes old terminal intents and keeps audit logs

Billing Automation Fencing And Concurrency (Tests\Feature\Hardware\BillingAutomationFencingAndConcurrency)
  ✔ 11 stop vs expire race cancels old scheduled off and executes immediate stop
  ✔ 12 extend vs expiry dispatch race aborts stale cutoff
  ✔ 13 pending on after session ended is rejected as stale by fencing
  ✔ 14 new customer session crossover discards old customer off retry
  ✔ 15 station mapping deactivated rejects intent via fencing
  ✔ 16 worker concurrency and claim ownership prevents duplicate processing
  ✔ 17 stale worker lease recovery resets dead processing locks

Billing Automation Lifecycle (Tests\Feature\Hardware\BillingAutomationLifecycle)
  ✔ 01 prepaid start creates switch on and prescheduled switch off intents
  ✔ 02 prescheduled expiry intent dispatches switch off at expires at
  ✔ 05 prepaid extend bumps generation cancels old off and schedules new off
  ✔ 06 prepaid stop early cancels prescheduled off and triggers immediate cutoff
  ✔ 07 device controller stop device triggers immediate cutoff intent
  ✔ 08 lost time start commits switch on with no prescheduled expiry
  ✔ 09 lost time midnight crossing remains running without cutoff
  ✔ 10 lost time stop triggers immediate switch off

Billing Automation Resilience And Retention (Tests\Feature\Hardware\BillingAutomationResilienceAndRetention)
  ✔ 18 offline shelly schedules bounded backoff retries
  ✔ 19 max retries exhausted transitions to failed with cashier alert
  ✔ 20 readback prior to retry settles completed when device already in desired state
  ✔ 21 readback mismatch triggers failure
  ✔ 22 samsung tv automated power commands skipped by capability policy
  ✔ 23 financial transaction isolation preserves billing state on hardware failure
  ✔ 24 rbac and credential secrecy masks hardware details from cashier
  ✔ 25 intent pruning preserves execution logs via set null
  ✔ 26 status running at expiry second triggers prescheduled off intent
  ✔ 27 foreign key schema and column types
```

---

## 12. DATABASE ISOLATION EVIDENCE

| Verification Item | Production DB (`billing-sugeng-rawuh`) | Testing DB (`kasir_ps_test`) | Isolation Evidence |
|---|---|---|---|
| **Database Host / Port** | 127.0.0.1:8889 | 127.0.0.1:8889 | Isolated connection names |
| **Table Count** | 22 tables | 26 tables | Automation tables present only in test DB |
| **`automation_intents`** | **DOES NOT EXIST** | Exists with full schema | Zero production pollution |
| **`automation_execution_logs`** | **DOES NOT EXIST** | Exists with full schema | Zero production pollution |
| **`devices.session_generation`** | Column not present | `BIGINT UNSIGNED DEFAULT 0` | Verified via `Schema::getColumnListing` |
| **`transactions.session_generation`** | Column not present | `BIGINT UNSIGNED DEFAULT 0` | Verified via `Schema::getColumnListing` |

---

## 13. UNRESOLVED RISKS & MITIGATIONS

1. **Local Wi-Fi Network Fluctuation (Physical Deployments):**  
   *Risk:* In future live deployment, Wi-Fi interference in a billiard hall could cause temporary Shelly HTTP timeouts.  
   *Mitigation:* Handled by G5-B's bounded 4-attempt exponential backoff (+3s, +10s, +30s) and cashier visual alerts. Physical recommendation in G4-B2 is to assign static DHCP leases and dedicated 2.4GHz IoT SSID.
2. **Clock Drift Between Application Server and MySQL:**  
   *Risk:* Skew between server system time and MySQL `NOW()` could trigger expiry slightly earlier or later.  
   *Mitigation:* G5-B uses `Carbon::now()` synchronized with database timezone (`Asia/Jakarta`). Production deployment requires local NTP daemon synchronization.
3. **Physical Bulb Failure vs Relay Contact:**  
   *Risk:* Relay turns ON successfully, but the physical light bulb is burnt out or loose.  
   *Mitigation:* System labels state as `VERIFIED Relay Output` rather than guaranteeing room illumination. Power metering telemetry in G4-B2 will detect near-zero wattage during ON state to flag bulb burnouts.

---

## 14. REQUIREMENTS FOR GATE G4-B2 (PHYSICAL BENCH TESTING)

Prior to running automation against physical hardware in Gate G4-B2:
1. Physical Shelly 1PM Mini Gen3 connected to isolated bench power supply and test lamp load.
2. Network router configured with dedicated IoT subnet (e.g., `192.168.10.x`) and static MAC-to-IP reservations.
3. Verification of HTTP RPC endpoint `/rpc/Switch.Set` with real authentication token.
4. Physical bench validation of power metering read-back (`Switch.GetStatus` showing `apower > 5W`).
5. Zero physical power switching on TV/PlayStation circuits.

---

## 15. RECOMMENDATION FOR G5-B SIGN-OFF

All targets set forth in the G5-B user request and the G5-A reconciled architecture specifications have been fulfilled:
- **Approved Business Rule SR-G5-BR-001:** Fully implemented and automated (instant billiard light shutdown at `expires_at`, zero grace period, zero cashier confirmation).
- **Core Automation Services:** `AutomationIntentService`, `AutomationDispatcherService`, `AutomationFencingGuard`, `AutomationCapabilityPolicy`, `AutomationWorkerCommand`, `AutomationExecutionLog`, `AutomationStatusService` fully developed and operational.
- **Safety Boundary:** Strict mock/simulation enforcement (`HARDWARE_REAL_EXECUTION_ENABLED=false`, zero physical network packets, Samsung TV power automation disabled).
- **Test Integrity:** 295 automated tests passing (268 baseline + 27 new tests, 100% green).
- **Database Safety:** Production database completely untouched; all changes verified on `kasir_ps_test`.

**Final Recommendation:** **G5-B SOFTWARE IMPLEMENTED — MOCK E2E TESTED — READY FOR REVIEW AND SIGN-OFF.**
