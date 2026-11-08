# G5-A AUTOMATION ARCHITECTURE: BILLING-TO-HARDWARE ORCHESTRATION (RECONCILED)

**Document ID:** SR-G5-ARCH-001  
**Project:** Sugeng Rawuh — Integrated Billing & POS Management System  
**Gate:** G5-A — Billing-to-Hardware Automation Architecture & Design  
**Status:** RECONCILED / PROPOSAL FOR REVIEW  
**Date:** 2026-11-05  
**Author:** AI Systems Architect & Core Team  

---

## 1. EXECUTIVE SUMMARY & OBJECTIVES

Gate G5 introduces automated hardware orchestration to the Sugeng Rawuh billing engine under the **Transactional Outbox Pattern**.

The core architecture satisfies:
1. **Decision SR-G5-BR-001:** Immediate automatic billiard light cutoff upon prepaid expiration without grace period and without cashier confirmation.
2. **Distributed Non-Atomicity Acknowledgment:** Database commits and remote IoT network calls **cannot be atomic** (no 2-Phase Commit across Wi-Fi). The outbox guarantees DB consistency, while compensating logic and read-backs reconcile network state.
3. **Decoupled Billing & Financials:** IoT hardware latency, network timeouts, or power failures **never block, fail, or roll back financial transactions**.
4. **Race Condition Prevention:** Fencing tokens (`session_generation`) and station-level serialization strictly prevent stale command replays and extend-vs-expire collisions.
5. **Truthful State Reporting:** Clear separation between software-tested mock states and physical relay contact verification.

---

## 2. EXPIRATION DETECTION & LATENCY RECONCILIATION

### 2.1 The Latency Conflict
In the existing G2 system:
- `php artisan billing:worker` runs with a default sleep interval of **2 seconds** (`--sleep=2`).
- The automation worker polls every **500ms**.
- The operational target for physical light cutoff is **500ms – 1500ms** after `expires_at`.

If expiration detection relies on `billing:worker` discovering the expired state first, the initial detection alone could lag by up to 2000ms, making the 500ms–1500ms target impossible to achieve consistently.

### 2.2 Evaluation of Architectural Options

| Evaluation Metric | Option A: Increase G2 Expiry Frequency | Option B: Pre-Scheduled Outbox Intent (PRIMARY) |
|---|---|---|
| **Mechanism** | Reduce `billing:worker --sleep=1` or run expiry evaluation inside the 500ms automation worker loop. | At prepaid start, immediately insert an `automation_intents` record with `desired_state = 'OFF'` and `scheduled_at = expires_at`. |
| **G2 Code Disruption** | High: Alters core billing scheduler loop parameters or duplicates expiration logic. | Zero: Does not alter `billing:worker` loop or G2 financial calculations. |
| **Detection Latency** | 0 to 1000ms delay before intent is even created. | **0ms delay**: Intent is already in MySQL waiting for its timestamp to arrive. |
| **Worker Dispatch Speed** | Intent created after expiry, then picked up in next poll (total 500–2500ms). | **Immediate**: Worker polls `WHERE scheduled_at <= NOW()`, picking up intent within 0–500ms of `expires_at`. |
| **Extension Handling** | Relies on worker catching updated `expires_at`. | `extendSession()` atomically marks old intent `CANCELLED` and inserts new intent with new `expires_at`. |
| **Early Stop Handling** | Relies on worker catching early stop. | `stopSession()` atomically marks scheduled intent `CANCELLED` and inserts immediate `BILLING_STOPPED` intent. |

### 2.3 Primary Architectural Choice: Option B (Pre-Scheduled Outbox Intent)
**Option B is adopted as the primary architecture.**
- When a prepaid session begins, the system commits both `BILLING_STARTED` (`scheduled_at = NOW()`) and pre-schedules `BILLING_EXPIRED` (`scheduled_at = expires_at`).
- When the server clock reaches `expires_at`, the automation worker's polling query (`scheduled_at <= NOW()`) instantly claims the intent.
- `billing:worker` continues running its normal G2 reconciliation loop (sleep=2) to update `transactions.billing_status = EXPIRED_PENDING_ACTION` and `devices.status = 'Tersedia'` without timing dependencies.
- **Latency Acceptance Target:** The cutoff latency target of **500ms – 1500ms** is formally defined as an **acceptance target to be measured and verified in G5-B/G5-C**, not an absolute mathematical guarantee, due to LAN Wi-Fi jitter.

---

## 3. END-TO-END AUTOMATION PIPELINE

```
+---------------------------------------------------------------------------------------------------------+
| PHASE 1: SYNCHRONOUS BILLING COMMIT (Inside DB Transaction)                                             |
+---------------------------------------------------------------------------------------------------------+
|                                                                                                         |
|  [Billing Engine / Controller Mutation]                                                                 |
|         |                                                                                               |
|         +---> Start / Extend / Stop / Expire Triggered                                                 |
|         |                                                                                               |
|         +---> DB::transaction() begins:                                                                 |
|                 1. Update `transactions` (billing_status, ended_at, total_numeric)                      |
|                 2. Update `devices` (status = 'Digunakan' or 'Tersedia')                                 |
|                 3. Assign / Increment `session_generation` on `devices` & `transactions`                |
|                 4. Insert into `automation_intents`:                                                    |
|                      - transaction_id, station_id, station_name, event_type, desired_state              |
|                      - session_generation, idempotency_key, status = 'PENDING'                          |
|                      - scheduled_at (NOW() or expires_at)                                               |
|                 5. If EXTEND or STOP: Mark previous pending OFF intents `CANCELLED`                     |
|         +---> DB::commit()                                                                              |
|                                                                                                         |
|  * BILLING COMMIT COMPLETE. ZERO NETWORK CALLS MADE. FAST (< 10ms).                                     |
+---------------------------------------------------------------------------------------------------------+
                                                     |
                                                     v
+---------------------------------------------------------------------------------------------------------+
| PHASE 2: ASYNCHRONOUS WORKER CLAIM (Short MySQL Transaction < 5ms)                                      |
+---------------------------------------------------------------------------------------------------------+
|                                                                                                         |
|  [Automation Worker: `php artisan billing:automation-worker`]                                            |
|         |                                                                                               |
|         +---> Claims batch of eligible intents using `SKIP LOCKED`:                                     |
|               UPDATE automation_intents SET status = 'PROCESSING', locked_at = NOW(),                   |
|               locked_by = :pid, attempts = attempts + 1 WHERE id IN (...)                               |
|         +---> DB::commit() (Releases MySQL row locks immediately!)                                      |
|                                                                                                         |
+---------------------------------------------------------------------------------------------------------+
                                                     |
                                                     v
+---------------------------------------------------------------------------------------------------------+
| PHASE 3: EXECUTION & READ-BACK (Outside DB Transaction — No Row Locks Held!)                            |
+---------------------------------------------------------------------------------------------------------+
|                                                                                                         |
|  [Worker Thread]                                                                                        |
|         |                                                                                               |
|         +---> Pre-Execution Fencing Check:                                                              |
|         |     - Verify `intent.session_generation == station.session_generation`                        |
|         |     - Check if station occupied by new transaction (if so, mark STALE and abort)              |
|         |     - Check if mapping changed (if so, mark STALE_MAPPING_CHANGED and abort)                  |
|         |                                                                                               |
|         +---> Driver Dispatch via HardwareSafetyGuard:                                                  |
|         |     - Shelly 1PM: Send HTTP RPC `Switch.Set {"id": 0, "on": true/false}` (Timeout: 2000ms)    |
|         |     - Samsung TV: Check verified_capabilities (Skip unverified power commands)                |
|         |                                                                                               |
|         +---> Read-Back Verification:                                                                   |
|         |     - Send HTTP RPC `Switch.GetStatus {"id": 0}`                                              |
|         |     - Compare `output` boolean with `desired_state`:                                          |
|         |       * MATCH -> Result = SUCCESS (`RELAY_OFF_VERIFIED` / `RELAY_ON_VERIFIED`)                |
|         |       * MISMATCH / TIMEOUT -> Result = RETRY_SCHEDULED or FAILED                               |
|                                                                                                         |
+---------------------------------------------------------------------------------------------------------+
                                                     |
                                                     v
+---------------------------------------------------------------------------------------------------------+
| PHASE 4: SETTLEMENT & TELEMETRY COMMIT (Short MySQL Transaction < 5ms)                                  |
+---------------------------------------------------------------------------------------------------------+
|                                                                                                         |
|  [Worker Thread]                                                                                        |
|         |                                                                                               |
|         +---> Insert immutable audit row into `automation_execution_logs`                               |
|         +---> Update `automation_intents`: status = COMPLETED | FAILED | RETRY_PENDING                  |
|         +---> Update `devices.last_hardware_state` & `last_hardware_verified_at`                        |
|         +---> If FAILED (after 4 total attempts): Trigger persistent alert for Cashier Dashboard        |
|                                                                                                         |
+---------------------------------------------------------------------------------------------------------+
```

---

## 4. CONCURRENCY CONTROL & FENCING CONTRACT

### 4.1 Generation Sequence Across Lifecycle

```
START SESSION:
  Station Gen = G
  Tx Gen = G
  Pre-scheduled OFF Intent Gen = G

EXTEND SESSION:
  Station Gen = G + 1
  Tx Gen = G + 1
  Old Scheduled OFF Intent Gen G -> CANCELLED
  New Scheduled OFF Intent Gen G + 1 -> PENDING (scheduled_at = new_expires_at)

EARLY STOP SESSION:
  Station Gen = G + 1
  Tx Gen = G + 1
  Scheduled OFF Intent Gen G -> CANCELLED
  Immediate STOP Intent Gen G + 1 -> PENDING (scheduled_at = NOW())

NORMAL EXPIRE:
  Worker claims Scheduled OFF Intent Gen G at expires_at.
  Worker verifies Station Gen == G.
  If matched -> Executes cutoff.
  If extended in the interim, Station Gen is already G + 1 -> Intent G marked STALE.
```

### 4.2 Deduplicating STOP vs. EXPIRE
- If a session expires naturally and the cashier clicks "Stop" at the exact same instant, two intents are queued:
  - `intent_tx_{txId}_gen{G}_expire`
  - `intent_tx_{txId}_gen{G+1}_stop`
- Because the idempotency keys differ, MySQL unique index does not collapse them.
- **Resolution Contract:**
  1. The worker claims intents serialized by station ID.
  2. The first intent executes `SWITCH_OFF` and updates `devices.last_hardware_state = 'RELAY_OFF_VERIFIED'`.
  3. When the second intent is evaluated: the pre-execution check queries `devices.last_hardware_state`. Since desired state is `OFF` and relay is already verified `OFF`, the worker marks the second intent `COMPLETED (DUPLICATE_NOOP)` without issuing a second network packet.

### 4.3 In-Flight `PROCESSING` Collision Handling
- If an intent is in-flight across the Wi-Fi socket (Phase 3) when an extension arrives:
  - The extension updates MySQL and increments `devices.session_generation`.
  - When the in-flight worker finishes, it records the execution log.
  - The cashier extension UI or worker reconciliation detects if the relay switched OFF during an extension, and immediately queues a compensating `SWITCH_ON` intent.

---

## 5. WORKER CLAIMING, LEASE TIMEOUT & RECOVERY

### 5.1 Claim Lifecycle Rules:
1. **Lease Timeout:** Exactly **60 seconds**.
2. **Worker Identification:** Every worker instance stamps `locked_by = :pid@hostname`.
3. **Recovery on Boot / Watchdog:**
   - On boot, the worker reclaims abandoned intents:
     ```sql
     UPDATE automation_intents
     SET status = 'RETRY_PENDING', locked_at = NULL, locked_by = NULL
     WHERE status = 'PROCESSING' AND locked_at < DATE_SUB(NOW(), INTERVAL 60 SECOND);
     ```

### 5.2 Retry Standardization Contract:
- **Total Attempts:** Exactly **4 attempts** (1 initial dispatch + up to 3 retries).
- **Max Retries:** Exactly **3 retries**.
- **Backoff Schedule:**
  - Initial Failure -> Retry 1 at `NOW() + 3 seconds`.
  - Attempt 2 Failure -> Retry 2 at `NOW() + 10 seconds`.
  - Attempt 3 Failure -> Retry 3 at `NOW() + 30 seconds`.
  - Attempt 4 Failure -> Mark `FAILED`. Trigger persistent alert on Cashier POS.
- **Mandatory Read-Back Before Retry:** Before re-sending a command, the worker calls `Switch.GetStatus` to verify if the previous attempt actually succeeded on the device before timing out.

---

## 6. HARDWARE STATE TRUTHFULNESS & CAPABILITY BOUNDARIES

### 6.1 Simulation vs. Physical Reality
1. **In Mock / Test Mode:**
   - Badges and logs must explicitly indicate simulated state (`SIMULATED_ON`, `SIMULATED_OFF`).
   - Mock execution **never mutates `physical_devices.verified_capabilities`**.
   - No claim of real physical illumination is permitted.
2. **In Live Real Mode (Post G4-B2):**
   - Shelly `Switch.GetStatus` confirms **relay contact output state**, NOT light bulb operational status (bulb might be burnt out).
   - UI Badges use truthful labels:
     - `RELAY_ON_VERIFIED` ("Relay Meja ON (Output Terverifikasi)")
     - `RELAY_OFF_VERIFIED` ("Relay Meja OFF (Output Terverifikasi)")
3. **Samsung Smart TV Boundary:**
   - Power commands (`POWER_ON`, `STANDBY`) remain **strictly disabled** (`SKIPPED_UNVERIFIED_CAPABILITY`) until physical testing in G4-B2 proves protocol determinism.
