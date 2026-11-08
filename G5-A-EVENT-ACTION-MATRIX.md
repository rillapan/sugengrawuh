# G5-A EVENT-ACTION MATRIX: BILLING-TO-HARDWARE ORCHESTRATION

**Document ID:** SR-G5-EAM-001  
**Project:** Sugeng Rawuh — Integrated Billing & POS Management System  
**Gate:** G5-A — Billing-to-Hardware Automation Architecture & Design  
**Status:** DRAFT / PROPOSAL FOR REVIEW  
**Date:** 2026-11-05  
**Author:** AI Systems Architect & Core Team  

---

## 1. PURPOSE & SCOPE

This document defines the formal **Event-Action Matrix** mapping high-level billing lifecycle state transitions in Sugeng Rawuh (`sistem_kasir_PS-main`) to physical hardware driver invocations.

Each event is analyzed across:
1. **Trigger Condition & Repository Source** (`BillingService.php`, `Transaction.php`, `BillingWorkerCommand.php`).
2. **Action for Shelly 1PM Mini Gen3** (Billiard Lighting Relay).
3. **Action for Samsung Smart TV 50U8500** (PlayStation Display).
4. **Fencing & Preconditions** (Generation check, idempotency key, active mapping check).
5. **Expected Output State & Post-Execution Verification**.

---

## 2. HIGH-LEVEL EVENT-ACTION MATRIX

| Automation Event | Billing Type | Trigger Source | Shelly 1PM Mini (Billiard) | Samsung Smart TV (PlayStation) | Idempotency Key Pattern | Desired Output State |
|---|---|---|---|---|---|---|
| **BILLING_STARTED** | Prepaid | `BillingService::startSession()` | `SWITCH_ON` + Pre-schedule `SWITCH_OFF` at `expires_at` | `POWER_ON` (Only if verified) | `intent_{txId}_{gen}_start`<br>`intent_{txId}_{gen}_expire` | Shelly: `output=true`<br>TV: `verified_power=true` |
| **BILLING_EXTENDED** | Prepaid | `BillingService::extendSession()` | Cancel old `OFF` intent; Pre-schedule new `OFF` at `new_expires_at` | No command (Maintain `ON`) | `intent_{txId}_{gen}_extend` | Shelly: `output=true`<br>Old OFF `CANCELLED` |
| **BILLING_EXPIRED** | Prepaid | Server clock reaches `expires_at` (Pre-scheduled intent) | `SWITCH_OFF` (Immediate dispatch by worker) | `STANDBY` (Only if verified) | `intent_{txId}_{gen}_expire` | Shelly: `output=false`<br>TV: `standby=true` |
| **BILLING_STOPPED** | Prepaid | `BillingService::stopSession()` or `DeviceController::stopDevice()` | `SWITCH_OFF` (Immediate dispatch; cancel scheduled OFF) | `STANDBY` (Only if verified) | `intent_{txId}_{gen}_stop` | Shelly: `output=false`<br>TV: `standby=true` |
| **LOST_TIME_STARTED** | Lost Time | `BillingService::startSession()` | `SWITCH_ON` | `POWER_ON` (Only if verified) | `intent_{txId}_{gen}_lt_start` | Shelly: `output=true`<br>TV: `verified_power=true` |
| **LOST_TIME_RUNNING** | Lost Time | Continuous / Midnight Crossing | No command (Maintain `ON`) | No command (Maintain `ON`) | N/A | Shelly: `output=true`<br>No automated cutoff |
| **LOST_TIME_STOPPED** | Lost Time | `BillingService::stopSession()` or `DeviceController::stopDevice()` | `SWITCH_OFF` | `STANDBY` (Only if verified) | `intent_{txId}_{gen}_lt_stop` | Shelly: `output=false`<br>TV: `standby=true` |
| **BILLING_RECOVERED** | Both | `billing:reconcile` / Worker Boot | Safe Reconciliation | Safe Reconciliation | `intent_reconcile_{stationId}_{ts}` | Evaluates current active session vs relay state |

---

## 3. DEEP DIVE: EVENT SPECIFICATIONS

### 3.1 Event: `BILLING_STARTED`
- **Trigger Source:** `BillingService::startSession(array $data)` executes successfully.
- **Repository Context:** Transaction created with `billing_status = 'RUNNING'`, `jenis_paket = 'Prepaid'`, and non-null `expires_at`. Station device status updated to `'Digunakan'`.
- **Outbox Persistence (Option B):** Atomically commits two intents inside `DB::transaction()`:
  1. `BILLING_STARTED`: `desired_state = 'ON'`, `scheduled_at = NOW()`.
  2. `BILLING_EXPIRED`: `desired_state = 'OFF'`, `scheduled_at = expires_at` (Pre-scheduled cutoff).
- **Shelly Relay Execution:**
  - Desired State: `ON`.
  - Worker Action: Calls `ShellyRelayDriver->switchOn(int $channel = 0)`.
  - Read-Back Verification: Calls `Switch.GetStatus {"id": 0}`. Verifies `output == true`.
  - Result: Sets station state cache to `RELAY_ON_VERIFIED` (Output relay terverifikasi aktif).
- **Samsung Smart TV Execution:**
  - Capability Check: Check if `'POWER_ON'` exists in `physical_devices.verified_capabilities`.
  - If verified: Sends WoL magic packet / WebSocket power-on packet.
  - If unverified (default in G4-B1): Logs `SKIPPED_UNVERIFIED_CAPABILITY`. Kasir turns TV on manually.
- **Fencing & Safety:**
  - Requires active `StationDeviceMapping` with `mapping_status = 'ACTIVE'`.
  - `session_generation` token initialized and matched.

---

### 3.2 Event: `BILLING_EXTENDED`
- **Trigger Source:** `BillingService::extendSession(Transaction $tx, int $additionalMinutes)` executes successfully.
- **Repository Context:** Transaction `expires_at` pushed forward. Station `session_generation` incremented (+1).
- **Outbox Persistence (Option B):**
  - Marks previous pre-scheduled `BILLING_EXPIRED` intent for this transaction as `status = 'CANCELLED'`.
  - Inserts new pre-scheduled `BILLING_EXPIRED` intent with `scheduled_at = new_expires_at` and `session_generation = new_gen`.
- **Shelly Relay Execution:**
  - Desired State: Maintain `ON`.
  - Hardware Action: **Do not flood the relay with redundant `SWITCH_ON` RPCs**.
  - Verification: Assumed `ON` unless previously failed.
- **Samsung Smart TV Execution:**
  - No command issued. TV display remains active.
- **Fencing & Safety:**
  - Generation token bump strictly prevents any in-flight or late cutoff from the old expiration time.

---

### 3.3 Event: `BILLING_EXPIRED` (Mandatory Rule SR-G5-BR-001)
- **Trigger Source:** Server clock reaches `expires_at`. The pre-scheduled `BILLING_EXPIRED` intent is claimed by the automation worker (500ms polling loop).
- **Repository Context:** Transaction transitions to `billing_status = 'EXPIRED_PENDING_ACTION'`, `ended_at = expires_at`, `status_transaksi = 'selesai'`. Device status set to `'Tersedia'`.
- **Shelly Relay Execution (SR-G5-BR-001 COMPLIANCE):**
  - Desired State: `OFF`.
  - Worker Action: **Immediate execution**. The automation worker detects `scheduled_at <= NOW()` on the pre-scheduled intent and calls `ShellyRelayDriver->switchOff(int $channel = 0)`.
  - No Grace Period: Pre-scheduled exactly at `expires_at` with zero added buffer.
  - Zero Cashier Confirmation: The worker processes the cutoff immediately without waiting for cashier checkout, payment settlement, or any button click.
  - Read-Back Verification: Calls `Switch.GetStatus {"id": 0}`. Verifies `output == false`.
  - Result: Sets station state cache to `RELAY_OFF_VERIFIED`.
- **Samsung Smart TV Execution:**
  - Capability Check: Check if `'STANDBY'` is physically verified.
  - If unverified: Logs `SKIPPED_UNVERIFIED_CAPABILITY` (avoids accidental power toggle).
- **Failure Handling:**
  - If Shelly unreachable: Set intent to `RETRY_PENDING`, schedule backoff retry (+3s, +10s, +30s), and fire **Cashier Alert: "Lampu Meja Biliar [Nama Meja] Gagal Dimatikan! Cek Koneksi / Matikan Saklar Fisik!"**

---

### 3.4 Event: `BILLING_STOPPED`
- **Trigger Source:** Cashier clicks "Stop" before expiration via `DeviceController::stopDevice()` or `BillingService::stopSession()`.
- **Repository Context:** Transaction transitions to `billing_status = 'STOPPED_EARLY'`, `ended_at = now()`. Device status set to `'Tersedia'`.
- **Outbox Persistence:**
  - Marks pre-scheduled `BILLING_EXPIRED` intent as `CANCELLED`.
  - Inserts immediate `BILLING_STOPPED` intent with `scheduled_at = NOW()`.
- **Shelly Relay Execution:**
  - Desired State: `OFF`.
  - Worker Action: Calls `ShellyRelayDriver->switchOff(int $channel = 0)`.
  - Read-Back Verification: Calls `Switch.GetStatus {"id": 0}`. Verifies `output == false`.
  - Result: Sets station state cache to `RELAY_OFF_VERIFIED`.
- **Samsung Smart TV Execution:**
  - Capability check applied. Skipped if unverified.

---

### 3.5 Event: `LOST_TIME_STARTED`
- **Trigger Source:** `BillingService::startSession(array $data)` called with `jenis_paket = 'Lost Time'`.
- **Repository Context:** Transaction created with `billing_status = 'RUNNING'`, `expires_at = NULL`. Device status set to `'Digunakan'`.
- **Shelly Relay Execution:**
  - Desired State: `ON`.
  - Worker Action: Calls `ShellyRelayDriver->switchOn(int $channel = 0)`.
  - Read-Back Verification: Verifies `output == true`.
  - Result: Sets station state cache to `RELAY_ON_VERIFIED`.
- **Samsung Smart TV Execution:**
  - Capability check applied.
- **Samsung Smart TV Execution:**
  - Capability check applied.

---

### 3.6 Event: `LOST_TIME_RUNNING` (Ongoing & Midnight Crossing)
- **Trigger Source:** System clock ticks; time crosses midnight (23:59:59 → 00:00:00).
- **Repository Context:** Session remains in `billing_status = 'RUNNING'`. Billing engine calculates elapsed minutes continuously.
- **Shelly Relay Execution:**
  - Desired State: Maintain `ON`.
  - Invariant: **Strictly NO automated cutoff**. Because `expires_at` is `NULL`, the worker never schedules an expiration intent.
  - Periodic Health Check (Optional): Background heartbeat verifies relay remains responsive without toggling switch state.
- **Samsung Smart TV Execution:**
  - Display remains active.

---

### 3.7 Event: `LOST_TIME_STOPPED`
- **Trigger Source:** Cashier clicks "Stop" for an active Lost Time session via `BillingService::stopSession()`.
- **Repository Context:** Transaction transitions to `COMPLETED` (or `STOPPED_EARLY`), final elapsed minutes calculated, `ended_at = now()`. Device status set to `'Tersedia'`.
- **Shelly Relay Execution:**
  - Desired State: `OFF`.
  - Worker Action: Dispatches `SWITCH_OFF`.
  - Read-Back Verification: Verifies `output == false`.
- **Samsung Smart TV Execution:**
  - Capability check applied.

---

### 3.8 Event: `BILLING_RECOVERED` (Crash Reconciliation)
- **Trigger Source:** System boot, application restart, or invocation of `php artisan billing:reconcile`.
- **Repository Context:** System re-evaluates all stations in MySQL to determine actual business state vs. physical hardware state.
- **Reconciliation Logic:**
  1. For each Station with an active `StationDeviceMapping`:
     - If station has an active `RUNNING` transaction:
       - Query physical relay state (`Switch.GetStatus`).
       - If physical relay is `OFF`: Dispatch non-blocking `SWITCH_ON` intent to recover proper lighting.
     - If station has NO active running transaction (status is `'Tersedia'` or billing status is `'EXPIRED_PENDING_ACTION'` / `'COMPLETED'`):
       - Query physical relay state (`Switch.GetStatus`).
       - If physical relay is `ON`: Dispatch safe `SWITCH_OFF` intent to eliminate unauthorized free play.
  2. All recovery intents are stamped with `event_type = 'BILLING_RECOVERED'` and logged.

---

## 4. PRECONDITION & INVARIANT SUMMARY TABLE

| Dimension | Rule |
|---|---|
| **Station Mapping** | Must exist and have `is_active = 1`. If missing, log `SKIPPED_NO_MAPPING`. |
| **Generation Fencing** | `intent.session_generation == station.session_generation`. If mismatched, mark `STALE`. |
| **Transaction State Fencing** | If intent is `SWITCH_OFF` (expired), but `transaction.billing_status == 'RUNNING'` (extended), mark `CANCELLED`. |
| **Capability Filter** | Samsung TV power commands require explicit capability whitelist. |
| **Execution Context** | Must execute strictly in CLI worker process (never in web request thread). |
| **Network Timeout** | Max 1000ms connect, 2000ms read. Max call duration 3000ms. |
| **Physical Read-Back** | Shelly commands require post-execution `Switch.GetStatus` comparison. |
