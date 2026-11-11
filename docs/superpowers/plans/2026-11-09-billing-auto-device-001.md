# SR-BILLING-AUTO-DEVICE-001 Implementation Plan (Revised): Billing Transaction & Device Automation Integration

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Integrate billing transactions with automated hardware power control so that starting a PlayStation or Billiard billing session automatically turns the station to `Digunakan` and triggers the correct power command (`POWER_ON`/`SWITCH_ON`) for the mapped device, and completing or stopping a session returns the station to `Tersedia` and triggers the correct off command (`STANDBY`/`SWITCH_OFF`).

**Architecture:**
1. Use transactional outbox pattern via existing `AutomationIntent` and `AutomationIntentService` — already implemented in `BillingService` and `TransactionController::store()`.
2. Async dispatch is handled by the existing `BillingAutomationWorkerCommand` (`billing:automation-worker`) — a long-running daemon that polls every 500ms and calls `AutomationDispatcherService::dispatchBatch()`. **Do NOT add inline `dispatchBatch()` calls to controllers.**
3. Make `AutomationCapabilityPolicy` and `AutomationDispatcherService` category-aware (Samsung TV uses `POWER_ON`/`STANDBY`, Shelly uses `SWITCH_ON`/`SWITCH_OFF`).
4. Keep physical device status distinct from billing status (Billing: `Tersedia`/`Digunakan`, Hardware: `SIMULATED_ON`/`SIMULATED_OFF`/`PENDING`/`VERIFIED`/`FAILED`/`UNKNOWN`).
5. Existing idempotency mechanism (`firstOrCreate` on `idempotency_key`) prevents double intent creation.

**Tech Stack:** Laravel 8, PHP 8.2, MySQL, jQuery/AJAX polling.

## Global Constraints

- `HARDWARE_DRIVER=mock` and `HARDWARE_REAL_EXECUTION_ENABLED=false` must remain default in config. Never change these.
- Never execute physical commands before database transaction commits.
- Hardware failures must never abort or roll back billing transactions.
- Never use a Shelly relay to cut power to a Samsung Smart TV.
- Distinguish `FAKE_TRANSPORT` / simulation from physical state verification (`PHYSICAL_STATE_VERIFIED`). A `FAKE_TRANSPORT` result must never produce `result_code = PHYSICAL_STATE_VERIFIED` or update `last_hardware_state` to `STATE_ON`/`STATE_OFF`.
- Do not add `dispatchBatch(5)` or any inline synchronous hardware dispatch call to `TransactionController`, `DeviceController`, or `BillingService`. The outbox + worker is the only dispatch mechanism.
- Extend (transaction update) must NOT create a new `ON` intent. It cancels the old `OFF` intent and schedules a new `OFF` intent only.
- Food/beverage (FnB-only) POS transactions must NOT trigger device intents.
- Auto-expiry via `evaluateExpiration()` must work even when the cashier page is not open.
- An `OFF` intent from a previous session must NEVER fire for a device already claimed by a new session (session_generation fence already implemented — verify tests prove it).
- Station status (`Tersedia`/`Digunakan`) and hardware status (`SIMULATED_ON`, etc.) are always separate fields in API responses.
- Do not run destructive migrations.
- Do not commit, push, merge, or deploy without human approval.
- Do not use the production/operational transaction database for Tinker simulations.
- Do not break existing Samsung TV control, Shelly relay integration, or billing functionality.

---

### Task 1: Update AutomationCapabilityPolicy to Support Samsung Smart TV in Mock Mode

**Context:** Currently `AutomationCapabilityPolicy::isCommandAllowed()` unconditionally blocks Samsung TV commands with `SKIPPED_UNVERIFIED_CAPABILITY`. This prevents the automation pipeline from sending `POWER_ON`/`STANDBY` to Samsung TVs even in mock mode. The fix allows TV commands in mock mode and in real mode only when the specific capability is verified — without touching any existing Shelly Relay logic.

**Files:**
- Modify: `app/Services/Hardware/Automation/AutomationCapabilityPolicy.php`
- Create: `tests/Feature/Hardware/AutomationCapabilityPolicyTest.php`

**Interfaces:**
- Consumes: `PhysicalDevice`, `PhysicalDevice::verified_capabilities` (array), `Config::get('hardware.driver')`
- Produces: `isCommandAllowed(?PhysicalDevice $device, string $desiredState): array` returning `['allowed' => bool, 'result_code' => string, 'reason' => ?string]`

**Key constraints for Samsung TV:**
- `STATE_ON` maps to the `POWER_ON` capability; `STATE_OFF` maps to `STANDBY`.
- In mock mode (`Config::get('hardware.driver') === 'mock'`): return `ALLOWED` unconditionally for TV — simulated network WoL and simulated standby do not require physical verification.
- In real mode: return `ALLOWED` only if `$device->verified_capabilities` contains the required capability for the requested state (`POWER_ON` for ON, `STANDBY` for OFF). If the capability is missing, return `SKIPPED_UNVERIFIED_CAPABILITY`.
- Configured ≠ Tested: the presence of a capability in `verified_capabilities` only means it was verified during setup, not that it is currently working. The policy does NOT guarantee physical state.
- `FAKE_TRANSPORT` is a dispatcher-level concern, not a policy concern — the policy must not set any `PHYSICAL_STATE_VERIFIED` result code.

- [ ] **Step 1: Write the failing test**
Create `tests/Feature/Hardware/AutomationCapabilityPolicyTest.php` testing that:
1. Shelly relay allows `ON` and `OFF` — existing behavior unchanged.
2. Samsung TV: `ON` returns `ALLOWED` when `hardware.driver = mock`.
3. Samsung TV: `OFF` returns `ALLOWED` when `hardware.driver = mock`.
4. Samsung TV: `ON` returns `ALLOWED` in real mode when `verified_capabilities` contains `POWER_ON`.
5. Samsung TV: `OFF` returns `ALLOWED` in real mode when `verified_capabilities` contains `STANDBY`.
6. Samsung TV: `ON` returns `SKIPPED_UNVERIFIED_CAPABILITY` in real mode when `POWER_ON` is absent from `verified_capabilities`.
7. Samsung TV: `OFF` returns `SKIPPED_UNVERIFIED_CAPABILITY` in real mode when `STANDBY` is absent.
8. Null device returns `INCOMPATIBLE_DEVICE_CATEGORY`.
9. Inactive device returns `DEVICE_INACTIVE`.

- [ ] **Step 2: Run test to verify it fails**
Run `php artisan test tests/Feature/Hardware/AutomationCapabilityPolicyTest.php` and confirm Samsung TV tests fail.

- [ ] **Step 3: Update AutomationCapabilityPolicy**
In the Samsung TV branch, replace the unconditional `SKIPPED_UNVERIFIED` return with:
```php
$driverMode = Config::get('hardware.driver', 'real');
if ($driverMode === 'mock') {
    return ['allowed' => true, 'result_code' => self::RESULT_ALLOWED, 'reason' => null];
}
// Real mode: check specific capability
$requiredCapability = (strtoupper($desiredState) === 'ON') ? 'POWER_ON' : 'STANDBY';
$hasCapability = in_array($requiredCapability, (array)($device->verified_capabilities ?? []), true);
if (!$hasCapability) {
    return ['allowed' => false, 'result_code' => self::RESULT_SKIPPED_UNVERIFIED, 'reason' => "Samsung TV capability '{$requiredCapability}' not verified."];
}
return ['allowed' => true, 'result_code' => self::RESULT_ALLOWED, 'reason' => null];
```

- [ ] **Step 4: Run test to verify it passes**
Run `php artisan test tests/Feature/Hardware/AutomationCapabilityPolicyTest.php` — all 9 cases must pass.

- [ ] **Step 5: Commit**
`git add app/Services/Hardware/Automation/AutomationCapabilityPolicy.php tests/Feature/Hardware/AutomationCapabilityPolicyTest.php && git commit -m "feat(automation): allow samsung tv power commands in capability policy when mock or capability verified"`

---

### Task 2: Category-Aware Command Translation & Status Read-Back in AutomationDispatcherService

**Context:** `AutomationDispatcherService::processIntent()` currently hardcodes `$command = SWITCH_ON / SWITCH_OFF` for ALL device categories, including Samsung Smart TV. It also hardcodes `command_sent` log entries as "Switch.Set on=true/false". The fix introduces category-aware translation (TV → `POWER_ON`/`STANDBY`, Relay → `SWITCH_ON`/`SWITCH_OFF`) and correct read-back verification per category. Critically, `FAKE_TRANSPORT` results must be logged as `SIMULATED_ON`/`SIMULATED_OFF`, never as physically verified state.

**Files:**
- Modify: `app/Services/Hardware/Automation/AutomationDispatcherService.php` (specifically `processIntent`, `settleSuccess`, `handleDispatchFailure`)
- Create: `tests/Feature/Hardware/AutomationDispatcherTvAndRelayTest.php`

**Interfaces:**
- Consumes: `AutomationIntent`, `HardwareDriverManager`, `PhysicalDevice`
- Produces: Correct driver command and correct telemetry inspection per device category.

**Key constraints:**
- TV: `STATE_ON → POWER_ON`, `STATE_OFF → STANDBY`. Read-back checks `$state['power_state']` or `$state['power']`, not `$state['output']`.
- Relay: `STATE_ON → SWITCH_ON`, `STATE_OFF → SWITCH_OFF`. Read-back checks `$state['output']` or `$state['switch']`.
- If driver result contains `transport_type = FAKE_TRANSPORT` or `result_code = FAKE_TRANSPORT`: update `last_hardware_state` to `SIMULATED_ON` or `SIMULATED_OFF`. Never `STATE_ON`/`STATE_OFF` (which would imply physical verification).
- `command_sent` in `AutomationExecutionLog` must be the actual command string sent (`POWER_ON`, `STANDBY`, `SWITCH_ON`, `SWITCH_OFF`), not hardcoded "Switch.Set on=true".
- `driver_type` in failure logs must reflect actual driver type, not hardcoded `'shelly_relay'`.
- Shelly channel: use `$intent->target_channel` from `StationDeviceMapping` — do not change any existing Shelly configuration.

- [ ] **Step 1: Write the failing test**
Create `tests/Feature/Hardware/AutomationDispatcherTvAndRelayTest.php` testing:
1. Dispatching `ON` intent to Samsung TV calls driver with `POWER_ON` command (not `SWITCH_ON`).
2. Dispatching `OFF` intent to Samsung TV calls driver with `STANDBY` command (not `SWITCH_OFF`).
3. Dispatching `ON`/`OFF` to Shelly relay calls driver with `SWITCH_ON`/`SWITCH_OFF`.
4. TV read-back verifies via `power_state` or `power` field, not `output`.
5. Relay read-back verifies via `output` field.
6. FAKE_TRANSPORT result sets `last_hardware_state = SIMULATED_ON` (not `STATE_ON`).
7. `AutomationExecutionLog.command_sent` stores the actual command string.

- [ ] **Step 2: Run test to verify it fails**
Run `php artisan test tests/Feature/Hardware/AutomationDispatcherTvAndRelayTest.php`.

- [ ] **Step 3: Implement category-aware dispatch and read-back**
In `AutomationDispatcherService::processIntent()`:
```php
// Category-aware command translation
$command = match([$physicalDevice->category, $intent->desired_state]) {
    [PhysicalDevice::CATEGORY_SMART_TV, AutomationIntent::STATE_ON] => 'POWER_ON',
    [PhysicalDevice::CATEGORY_SMART_TV, AutomationIntent::STATE_OFF] => 'STANDBY',
    [PhysicalDevice::CATEGORY_SMART_RELAY, AutomationIntent::STATE_ON] => 'SWITCH_ON',
    default => 'SWITCH_OFF',
};
```

In `settleSuccess()`:
- Determine if result is simulated: check `$cmdResult->resultCode === 'FAKE_TRANSPORT'` or `$cmdResult->simulatedState !== null`.
- If simulated: `$badge = $observedOutput ? 'SIMULATED_ON' : 'SIMULATED_OFF'`; leave VERIFIED badges only for real transport confirmation.
- For TV read-back: check `$state['power_state'] === 'on'` or `$state['power'] === 'ON'` (not `output`).
- Fix `command_sent` to store `$command` (the actual command string).
- Fix `driver_type` in `handleDispatchFailure` to use `$driver->getDriverType()` (not hardcoded `'shelly_relay'`).

- [ ] **Step 4: Run test to verify it passes**
Run `php artisan test tests/Feature/Hardware/AutomationDispatcherTvAndRelayTest.php`.

- [ ] **Step 5: Commit**
`git add app/Services/Hardware/Automation/AutomationDispatcherService.php tests/Feature/Hardware/AutomationDispatcherTvAndRelayTest.php && git commit -m "feat(automation): category-aware command translation and correct readback in dispatcher"`

---

### Task 3: Verify & Test the Outbox Integration — Single Intent Per Billing Transition

**Context:** The outbox pattern is ALREADY implemented. `TransactionController::store()` creates intents atomically inside `DB::beginTransaction()/commit()`. `BillingService::startSession/stopSession/evaluateExpiration()` create intents inside `DB::transaction()`. The `BillingAutomationWorkerCommand` polls every 500ms and dispatches. The goal of this task is to **audit the existing code for correctness**, write end-to-end integration tests proving the acceptance criteria, and fix any gaps found — WITHOUT adding inline `dispatchBatch()` calls.

**Files:**
- Audit (read-only): `app/Http/Controllers/TransactionController.php`, `app/Services/BillingService.php`
- Fix if gaps found: `app/Http/Controllers/TransactionController.php`, `app/Services/BillingService.php`
- Create: `tests/Feature/BillingAutoDeviceIntegrationTest.php`

**What NOT to do:**
- Do NOT add `AutomationDispatcherService::dispatchBatch()` calls to `TransactionController`, `DeviceController`, or `BillingService`.
- Do NOT make the HTTP request wait for hardware response.
- Do NOT call `processIntent()` synchronously inline after `DB::commit()`.
- Do NOT create a new queue job for immediate dispatch — the worker already handles this.

**Audit checklist (verify before writing tests):**
1. `TransactionController::store()`: intent created inside the DB transaction after `Transaction::create()` and device status update. `session_generation` bumped before intent creation. Idempotency key uses `transaction_id + generation + event_suffix`.
2. `TransactionController::update()` (extend): old OFF intent cancelled via `cancelPendingOffIntents()`; new OFF intent scheduled at new `expires_at`; NO new ON intent created.
3. `BillingService::startSession()`: intent recorded inside `DB::transaction()`.
4. `BillingService::stopSession()`: stop intent (OFF) recorded; device status set to Tersedia.
5. `BillingService::evaluateExpiration()`: expired session intent (OFF) created; works without open cashier page.
6. No path creates TWO ON intents for the same session (idempotency via `firstOrCreate`).
7. FnB-only transactions (no `device_id`) do NOT trigger device intents.

- [ ] **Step 1: Write the failing tests**
Create `tests/Feature/BillingAutoDeviceIntegrationTest.php` with:
- **Test A (PS - billing start)**: POST to create a PS station transaction → station status = `Digunakan`; exactly ONE `AutomationIntent` for that station with `desired_state = ON, status = PENDING, event_type = BILLING_STARTED`. Worker processes it → intent completed, `last_hardware_state = SIMULATED_ON`.
- **Test B (Billiard - billing start)**: Same for a Billiard station with Shelly relay mapping → ONE intent ON, station `Digunakan`.
- **Test C (Stop)**: Call stop on active session → station `Tersedia`; ONE intent with `desired_state = OFF, event_type = BILLING_STOPPED`.
- **Test D (Extend)**: Extend active session → NO new ON intent; previous OFF intent CANCELLED; ONE new OFF intent with new `expires_at`; device stays `Digunakan`.
- **Test E (Failed validation)**: Invalid transaction (no device, or device unavailable) → NO intent created; station status unchanged.
- **Test F (Idempotency)**: Call `recordIntent` twice with same params → only ONE `AutomationIntent` row in DB (via firstOrCreate).
- **Test G (Session fence)**: Create new session on device (session_generation bumps). Simulate old OFF intent firing via `fencingGuard` → intent is settled as STALE, NOT dispatched. New session's device is unaffected.
- **Test H (FnB-only)**: Create FnB transaction with no device_id → zero intents created.

- [ ] **Step 2: Run tests to verify they fail**
Run `php artisan test tests/Feature/BillingAutoDeviceIntegrationTest.php`.

- [ ] **Step 3: Fix any gaps revealed by tests**
Only fix actual gaps found during the audit or test run. Common expected issues:
- If `TransactionController::store()` is not creating intents for all transaction types (e.g., hourly vs prepaid), add the missing path.
- If expiry intent is not being created when `expires_at` is null (open-ended billing), ensure no phantom OFF is scheduled.
- If session_generation is not properly saved to the transaction, fix.
Do NOT refactor logic that is already correct. Do NOT touch lines unrelated to the failing tests.

- [ ] **Step 4: Run tests to verify they pass**
Run `php artisan test tests/Feature/BillingAutoDeviceIntegrationTest.php`.

- [ ] **Step 5: Commit**
`git add app/Http/Controllers/TransactionController.php app/Services/BillingService.php tests/Feature/BillingAutoDeviceIntegrationTest.php && git commit -m "feat(billing): integration tests for outbox billing automation and session fence"`

---

### Task 4: Enhance AutomationStatusService and /device Live Polling Sync

**Context:** `AutomationStatusService::getCashierStatus()` returns hardware state but NOT the station's billing status (`Tersedia`/`Digunakan`). The `/device` polling endpoint needs `station_status` so the view can update the station badge without a full page reload. Hardware status must show evidence-based labels (`SIMULATED_ON`, `PENDING`, `VERIFIED`, `FAILED`, `UNKNOWN`) — never infer physical state from billing status.

**Files:**
- Modify: `app/Services/Hardware/Automation/AutomationStatusService.php`
- Modify: `resources/views/device/index.blade.php`
- Create: `tests/Feature/DevicePageLiveStatusSyncTest.php`

**Interfaces:**
- Consumes: `AutomationStatusService::getCashierStatus(Device $station): array`
- Produces: Polling JSON now includes `station_status` (string: `Tersedia`/`Digunakan`), `hardware_state` (evidence-based string), `hardware_badge` (array with code/label/color_class/icon), and optionally active session metadata.

**Key constraints:**
- `station_status` = `$station->status` (the billing field: `Tersedia` or `Digunakan`).
- `hardware_state` reflects the last known hardware evidence: `SIMULATED_ON`, `SIMULATED_OFF`, `COMMAND_PENDING`, `COMMAND_FAILED`, `NO_HARDWARE`, `STATE_UNKNOWN`.
- Setting station to `Digunakan` (billing active) must NEVER automatically set `hardware_state` to `STATE_ON`. They are independent.
- `hardware_badge` for SIMULATED states uses a distinct visual treatment from VERIFIED states.
- Active session metadata (optional, included when session exists): `customer_name`, `remaining_seconds`, `started_at`, `expires_at`.
- Use existing polling mechanism in view — do not introduce new AJAX endpoints unless none exist.

- [ ] **Step 1: Write the failing tests**
Create `tests/Feature/DevicePageLiveStatusSyncTest.php`:
1. `getCashierStatus()` response includes `station_status` = `'Tersedia'` when device has no active session.
2. `getCashierStatus()` response includes `station_status` = `'Digunakan'` when device has an active billing session.
3. `hardware_badge.code` = `'SIMULATED_ON'` when `last_hardware_state = SIMULATED_ON` (not `'STATE_ON'`).
4. `hardware_badge.code` = `'COMMAND_PENDING'` when there is a PENDING/PROCESSING intent.
5. `hardware_badge.code` = `'COMMAND_FAILED'` when there is a recent FAILED intent.
6. Station `Digunakan` with no hardware mapping → `hardware_state = 'NO_HARDWARE'`, `station_status = 'Digunakan'`.
7. Station `Tersedia` with SIMULATED_ON state → both returned correctly and independently.

- [ ] **Step 2: Run tests to verify they fail**
Run `php artisan test tests/Feature/DevicePageLiveStatusSyncTest.php`.

- [ ] **Step 3: Implement status service and view changes**
In `AutomationStatusService::getCashierStatus()`:
- Add `'station_status' => $station->status` to ALL return paths (both `has_hardware = false` and `has_hardware = true`).
- Add active session metadata when a BILLING_RUNNING transaction exists for the station.
- Ensure `formatBadge()` covers `SIMULATED_ON`, `SIMULATED_OFF`, `COMMAND_PENDING`, `COMMAND_FAILED`, `NO_HARDWARE`, `STATE_UNKNOWN`.

In `resources/views/device/index.blade.php`:
- In `pollAutomationStatus()` callback: if `res.data.station_status` changes, update the station status badge on the card (`Tersedia` / `Digunakan`).
- Keep the hardware badge update separate from the station badge update.
- Do NOT set any CSS/icon to "device is ON" based solely on `station_status = 'Digunakan'`.

- [ ] **Step 4: Run tests to verify they pass**
Run `php artisan test tests/Feature/DevicePageLiveStatusSyncTest.php`.

- [ ] **Step 5: Commit**
`git add app/Services/Hardware/Automation/AutomationStatusService.php resources/views/device/index.blade.php tests/Feature/DevicePageLiveStatusSyncTest.php && git commit -m "feat(device): add station_status and evidence-based hardware badge to automation poller"`

---

### Task 5: Regression Testing & Audit Report

**Context:** Verify all existing tests still pass after Tasks 1–4, record actual test count, and generate a structured audit report. Do NOT use the operational/production database for any simulation.

**Files:**
- Tests: Run full suite in `tests/`
- Report: Append findings to this plan file or write to `docs/superpowers/reports/`

**Safety constraints:**
- Run ALL tests with `HARDWARE_DRIVER=mock` and `HARDWARE_REAL_EXECUTION_ENABLED=false` (default — do not change).
- Do NOT use `php artisan tinker` against the operational database. Use the test database (`phpunit.xml` / `APP_ENV=testing`) for any manual verification.
- Do NOT run destructive migrations (`--fresh`, `migrate:reset`, `migrate:rollback`).
- Do NOT commit, push, or deploy without explicit human approval.
- Record the actual test count from `php artisan test --list-tests | wc -l`; do NOT assert a specific number.

- [ ] **Step 1: Run full test suite**
```bash
php artisan test
```
Record: total tests, passed, failed, skipped. Identify any regressions introduced by Tasks 1–4.

- [ ] **Step 2: Fix regressions (if any)**
If any existing tests fail due to changes in Tasks 1–4, fix the root cause. Do not comment out or skip tests. Only fix what is broken by this branch's changes.

- [ ] **Step 3: Verify intent flow with safe test-environment query**
Using `APP_ENV=testing` database (never production), confirm:
- An `AutomationIntent` with `desired_state = ON, status = PENDING` is created when billing starts.
- An `AutomationIntent` with `desired_state = OFF` is created when billing ends or expires.
- `session_generation` on `devices` and `transactions` match for the same session.
- Worker (`billing:automation-worker --once`) processes the intent and updates `status = COMPLETED`, `last_hardware_state = SIMULATED_ON/SIMULATED_OFF`.

- [ ] **Step 4: Document findings**
Write a brief report covering:
1. Root cause of original Samsung TV block (always SKIPPED_UNVERIFIED).
2. Root cause of Relay-only command translation (hardcoded SWITCH_ON/SWITCH_OFF).
3. Files modified and why.
4. Test results: total run, passed, any failures and resolution.
5. Worker requirements: command to start, what happens if not running (intents queue up, processed on next start).
6. Blockers for real hardware activation: what must be done before `HARDWARE_REAL_EXECUTION_ENABLED=true`.
7. Known gaps/deferred items.

- [ ] **Step 5: Final commit**
`git add -A && git commit -m "test(automation): full regression pass and audit report after billing device automation integration"`
