# G5-A IMPLEMENTATION PLAN FOR GATE G5-B: AUTOMATION CODING & VERIFICATION

**Document ID:** SR-G5-PLAN-001  
**Project:** Sugeng Rawuh — Integrated Billing & POS Management System  
**Gate:** G5-A — Billing-to-Hardware Automation Architecture & Design  
**Status:** DRAFT / PROPOSAL FOR REVIEW  
**Date:** 2026-11-05  
**Author:** AI Systems Architect & Core Team  

---

## 1. PURPOSE & BOUNDARIES

This implementation plan defines the step-by-step technical roadmap for **Gate G5-B (Hardware Automation Software Implementation)**.

### Strict Gate Boundaries:
- **G5-A:** Architectural audit, formal design, database proposals, and risk registers. **NO CODING ALLOWED.**
- **G5-B:** Implementation of migrations, Eloquent models, outbox worker, API endpoints, UI badges, and comprehensive automated test suite in test environment (`kasir_ps_test`).
- **Safety Invariant:** All G5-B automated tests must execute against fake transports and mock hardware drivers. **No physical packets will be transmitted across the LAN until G4-B2 physical validation is complete.**
- **Baseline Invariant:** Existing **268 automated tests** must remain 100% green without regressions.

---

## 2. G5-B TASK BREAKDOWN & SEQUENCING

```
+---------------------------------------------------------------------------------------+
| TASK 1: Schema Migrations (automation_intents, execution_logs, session_generation)     |
+---------------------------------------------------------------------------------------+
                                           |
                                           v
+---------------------------------------------------------------------------------------+
| TASK 2: Eloquent Models, Relationships & Model Factories                              |
+---------------------------------------------------------------------------------------+
                                           |
                                           v
+---------------------------------------------------------------------------------------+
| TASK 3: Core Automation Services (IntentService, DispatcherService, Fencing Guard)    |
+---------------------------------------------------------------------------------------+
                                           |
                                           v
+---------------------------------------------------------------------------------------+
| TASK 4: Billing Engine Integration (BillingService G2 State Transitions & Outbox)     |
+---------------------------------------------------------------------------------------+
                                           |
                                           v
+---------------------------------------------------------------------------------------+
| TASK 5: Automation Worker Command (php artisan billing:automation-worker)             |
+---------------------------------------------------------------------------------------+
                                           |
                                           v
+---------------------------------------------------------------------------------------+
| TASK 6: Capability Policy Enforcement (Shelly vs Samsung TV Whitelist)               |
+---------------------------------------------------------------------------------------+
                                           |
                                           v
+---------------------------------------------------------------------------------------+
| TASK 7: REST API Endpoints & Controllers (Cashier Status Polling & Admin Diagnostics) |
+---------------------------------------------------------------------------------------+
                                           |
                                           v
+---------------------------------------------------------------------------------------+
| TASK 8: Cashier POS UI Badges & Alerts (Blade & jQuery Non-Blocking Integration)     |
+---------------------------------------------------------------------------------------+
                                           |
                                           v
+---------------------------------------------------------------------------------------+
| TASK 9: Comprehensive Automated Test Suite (20+ New Tests covering all Race Modes)   |
+---------------------------------------------------------------------------------------+
                                           |
                                           v
+---------------------------------------------------------------------------------------+
| TASK 10: Full Regression Verification & G5-B Gate Sign-Off Report                     |
+---------------------------------------------------------------------------------------+
```

---

## 3. DETAILED TASK SPECIFICATIONS

### Task 1: Database Migrations
- Create migration `2026_11_05_000001_create_automation_intents_table.php`.
- Create migration `2026_11_05_000002_create_automation_execution_logs_table.php`.
- Create migration `2026_11_05_000003_add_session_generation_to_devices_and_transactions_table.php`.
- Verify migration rollbacks and re-runs on `kasir_ps_test`.

### Task 2: Eloquent Models & Relationships
- Implement `App\Models\AutomationIntent`.
- Implement `App\Models\AutomationExecutionLog`.
- Define relations:
  - `Device->automationIntents()`
  - `Transaction->automationIntents()`
  - `AutomationIntent->executionLogs()`
- Implement model scopes: `scopePending()`, `scopeEligible()`, `scopeStale()`.

### Task 3: Core Automation Services
- Implement `App\Services\Hardware\AutomationIntentService`:
  - `recordIntent()`
  - `cancelPendingOffIntents()`
  - `bumpSessionGeneration()`
- Implement `App\Services\Hardware\AutomationDispatcherService`:
  - Worker polling with `FOR UPDATE SKIP LOCKED`.
  - Fencing re-validation logic (`session_generation` check).
  - Driver dispatch via `HardwareSafetyGuard` and `HardwareDriverManager`.
  - Read-back verification via `Switch.GetStatus`.
  - Result recording and bounded retry scheduling (+3s, +10s, +30s).

### Task 4: Billing Engine & Controller Integration (Single Boundary)
- Refactor `App\Http\Controllers\DeviceController::stopDevice()`:
  - Delegate directly to `BillingService::stopSession($transactionId, 'cashier_stop')` to ensure atomic intent creation and eliminate duplicated mutation logic.
- Hook into `App\Services\BillingService`:
  - `startSession()`: Commit `BILLING_STARTED` (`scheduled_at = NOW()`) AND pre-schedule `BILLING_EXPIRED` (`scheduled_at = expires_at`) atomically.
  - `extendSession()`: Bump `session_generation` (+1), mark old pre-scheduled `BILLING_EXPIRED` intent as `CANCELLED`, insert new pre-scheduled intent for `new_expires_at`.
  - `stopSession()`: Bump `session_generation` (+1), mark pre-scheduled `BILLING_EXPIRED` as `CANCELLED`, insert immediate `BILLING_STOPPED` intent (`scheduled_at = NOW()`).
  - `evaluateExpiration()`: G2 status reconciliation remains consistent; pre-scheduled intent fires cutoff without dependency on worker sleep cycle.
- Ensure all intent commits are inside the exact same atomic `DB::transaction()`.

### Task 5: Automation Worker Command
- Implement `App\Console\Commands\BillingAutomationWorkerCommand`:
  - Signature: `php artisan billing:automation-worker {--interval=1} {--batch=10} {--once}`.
  - Continuous event loop with graceful `SIGTERM`/`SIGINT` handling.
  - Heartbeat updater (`billing:automation-worker:heartbeat`).
  - Stale lock detection (60s lease timeout) and startup crash recovery sweep.

### Task 6: Capability Policy Enforcement
- Enforce capability checks in `AutomationDispatcherService`:
  - Shelly 1PM: `SWITCH_ON`, `SWITCH_OFF` allowed.
  - Samsung TV: Skip unverified power commands, log `SKIPPED_UNVERIFIED_CAPABILITY`.

### Task 7: REST API Endpoints & Controllers
- Implement `App\Http\Controllers\Api\StationAutomationStatusController`:
  - `GET /api/stations/{id}/automation-status` (masks credentials, provides badge code and alert message).
  - `POST /api/stations/{id}/automation/manual-action` (bounded manual retry / manual override with audit log).
- Implement Admin controllers for intent inspection and logs.

### Task 8: Cashier POS UI Badges & Alerts
- Update Blade views:
  - Add live hardware badges (`RELAY_ON_VERIFIED`, `RELAY_OFF_VERIFIED`, `COMMAND_FAILED`) to station cards in `resources/views/device/index.blade.php`.
  - Add non-blocking AJAX polling script (every 3 seconds).
  - Render persistent alert banners for failed intents without blocking modals.

### Task 9: Comprehensive Automated Test Suite
Implement dedicated PHPUnit feature and unit test classes under `tests/Feature/Hardware/`:
1. `PrepaidAutomationWorkflowTest.php` (Start, normal expiry, extend, early stop).
2. `DeviceStopControllerAutomationTest.php` (`DeviceController::stopDevice` triggers cutoff).
3. `LostTimeAutomationWorkflowTest.php` (Start, midnight running, manual stop).
4. `ExtendVsExpireRaceConditionTest.php` (Extend while expiry in-flight, fencing validation).
5. `OfflineShellyAndRetryTest.php` (Timeout, backoff retry, max 4 attempts, cashier alert).
6. `ReadBackMismatchTest.php` (Relay mismatch detection and reporting).
7. `NewSessionCrossoverProtectionTest.php` (Old retry discarded when new customer starts).
8. `StationDeviceMappingAutomationTest.php` (Mapping changed, unmapped station fallback).
9. `SamsungTvCapabilityAutomationTest.php` (Unverified power skip).
10. `AutomationWorkerCrashRecoveryTest.php` (Stale lock 60s sweep, boot reconciliation).
11. `AutomationApiSecurityTest.php` (RBAC access, credential sanitization).

### Task 10: Full Regression Verification
- Run full test suite: Verify **268 baseline tests + 22+ new automation tests = 290+ tests passing (100% green)**.
- Verify production database `billing-sugeng-rawuh` remains untouched.
- Compile G5-B Completion Report.

---

## 4. G5-B TEST SCENARIO MATRIX (22 REQUIRED TEST CASES)

| Test Case ID | Test Class / Scenario | Expected Assertion |
|---|---|---|
| **TC-G5B-01** | Prepaid Start -> SWITCH_ON + Pre-schedule OFF | Intent `BILLING_STARTED` executed. Pre-scheduled `BILLING_EXPIRED` intent persisted with `scheduled_at = expires_at`. Badge = `RELAY_ON_VERIFIED`. |
| **TC-G5B-02** | Prepaid Normal Expiry -> Cutoff | At `expires_at`, worker claims pre-scheduled intent within 500ms. Driver receives `switchOff(0)`. Output verified `false`. Badge = `RELAY_OFF_VERIFIED`. |
| **TC-G5B-03** | Zero Grace Period Assertion | Worker executes cutoff at exact `expires_at` second without extra delay. |
| **TC-G5B-04** | Prepaid Extend -> Cancel Pending OFF | Extension bumps `session_generation`. Old `BILLING_EXPIRED` marked `CANCELLED`. New pre-scheduled OFF intent created for `new_expires_at`. No redundant ON sent. |
| **TC-G5B-05** | Prepaid Stop Early -> SWITCH_OFF | Cashier clicks stop via `stopDevice` or `stopSession`. Pre-scheduled OFF marked `CANCELLED`. Immediate STOP intent executed. |
| **TC-G5B-06** | DeviceController::stopDevice Delegation | Calling `POST /device/{id}/stop` properly triggers `BillingService::stopSession` and commits `SWITCH_OFF` intent. |
| **TC-G5B-07** | Lost Time Start -> SWITCH_ON | Intent created with `desired_state = 'ON'`. Light turns ON. No pre-scheduled expiry created. |
| **TC-G5B-08** | Lost Time Running / Midnight Crossing | Time crosses midnight. Session remains `RUNNING`. Zero expiration intents generated. |
| **TC-G5B-09** | Lost Time Stop -> SWITCH_OFF | Cashier clicks stop. Transaction completed. Intent `SWITCH_OFF` executed. |
| **TC-G5B-10** | Simultaneous STOP and EXPIRE | Pre-execution check resolves race; first executes, second marks `COMPLETED (DUPLICATE_NOOP)`. |
| **TC-G5B-11** | Worker Restart Stale Lock Recovery | Locked intent from crashed worker (`locked_at < NOW() - 60s`) reset to `RETRY_PENDING`. |
| **TC-G5B-12** | Offline Shelly (Connect Timeout) | Driver throws timeout exception. Intent marked `RETRY_PENDING`, attempt count incremented. Alert generated. |
| **TC-G5B-13** | Exponential Backoff Retry Timing | Retry 1 scheduled +3s, Retry 2 +10s, Retry 3 +30s. |
| **TC-G5B-14** | Max Retry Exhaustion (4 Total Attempts) | After Attempt 4 fails, intent marked permanently `FAILED`. Cashier banner active. |
| **TC-G5B-15** | Read-Back Prior to Retry | Before sending retry packet, worker checks `Switch.GetStatus`. If already OFF, marks `COMPLETED`. |
| **TC-G5B-16** | Read-Back Output Mismatch | RPC returns 200, but `GetStatus` output is `true` when expecting `false`. Logged as `READBACK_MISMATCH`. |
| **TC-G5B-17** | Reconnection Before Final Retry | Shelly comes back online on Retry 2. Dispatches successfully. Badge transitions to `RELAY_OFF_VERIFIED`. |
| **TC-G5B-18** | New Session Crossover During Retry Window | Customer A's retry fires after Customer B starts playing. Fencing detects generation mismatch and aborts retry as `STALE_SESSION_CLOSED`. |
| **TC-G5B-19** | Station Mapping Changed During In-Flight | Mapping disabled while intent pending. Worker marks intent `STALE_MAPPING_CHANGED`. |
| **TC-G5B-20** | Unmapped Station Billing Unhindered | Station without hardware mapping starts and expires billing normally; intent logged as `SKIPPED_NO_HARDWARE`. |
| **TC-G5B-21** | Samsung TV Unverified Power Skip | Attempting automated `POWER_ON`/`STANDBY` on Samsung TV logs `SKIPPED_UNVERIFIED_CAPABILITY`. |
| **TC-G5B-22** | Credential Sanitization in API & Logs | Kasir endpoint and execution log contain zero IP addresses, passwords, or tokens. |

---

## 5. ACCEPTANCE CRITERIA FOR G5-B GATE SIGN-OFF

To achieve official sign-off on Gate G5-B:
1. **Decision SR-G5-BR-001 Fully Operationalized:** Automatic billiard light cutoff executes upon prepaid expiry without cashier confirmation and with zero grace period.
2. **All 22 G5-B Automated Tests Passing:** Total test count reaches >= 290 tests passing (268 baseline + 22 new).
3. **Zero Test Regressions:** All 191 G4-A baseline tests and 77 G4-B1 tests remain green.
4. **Zero Production DB Modifications:** Database `billing-sugeng-rawuh` (22 tables) remains completely untouched.
5. **No Physical Network Traffic:** Real network calls remain guarded by `HardwareSafetyGuard` until G4-B2 physical hardware testing.
