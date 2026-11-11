# SR-CONSOLE-POPUP-001 — Integrasi Popup PS3 & PS4 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement PlayStation console popup notifications for PS3 (webMAN MOD direct HTTP) and PS4 (GoldHEN outbound polling + custom plugin) integrated into `/device` dashboard and billing lifecycle automation without modifying existing TV/Shelly hardware controls or billing calculations.

**Architecture:**
- **PS3 webMAN MOD Driver:** Direct HTTP `GET http://{PS3_IP}/popup.ps3/{encoded_message}`. Sanitizes messages to <= 120 chars, removes HTML, enforces timeouts (connect 2s, request 5s), records delivery status as `ACCEPTED` (`HTTP_ACCEPTED`) with unverified physical display notice.
- **PS4 GoldHEN Driver & Plugin:** Outbound polling architecture where Laravel queues events as `PENDING`, authenticated PS4 agent polls `/api/console-agent/v1/events` using Bearer / `X-Device-Token`, displays popup via Orbis OS native `sceKernelSendNotificationRequest`, and sends ACK to `/api/console-agent/v1/events/{id}/ack` marking status as `ACCEPTED`.
- **UI Integration on `/device`:** Displays **Kirim Popup** button for PlayStation stations currently `Digunakan` (accessible to Admin & Owner), providing templates (Sisa 10 Menit, Waktu Habis, Kecilkan Volume, Custom Admin), and real-time status badges (`PENDING`, `ACCEPTED`, `FAILED`, `UNVERIFIED`).
- **Billing Automation:** `BillingTvAlertWorkerCommand` and `TvAlertService` trigger automated 10-minute remaining popup (`REMAINING_10_MIN`) and session expired popup (`TIME_EXPIRED`) for prepaid and custom package sessions. Extend/Stop invalidates pending alerts. Lost time (postpaid) sessions only allow manual popup. Fencing by `session_generation` ensures old alerts are never delivered to new customer sessions.

**Tech Stack:** Laravel 8, PHP 8.2, MySQL, C (C99/pthread) for PS4 GoldHEN plugin, OpenOrbis Toolchain, Blade, JavaScript.

## Global Constraints
- Target hardware: PS3 (webMAN MOD, default port 80) and PS4 (GoldHEN, outbound polling).
- Do NOT modify Samsung TV driver or Shelly relay drivers.
- Do NOT modify manual ON/OFF power control or TV volume control.
- Do NOT modify billing pricing or duration calculation logic.
- Do NOT modify physical device IP/MAC addresses in production/test database.
- Do NOT commit or push git changes without explicit user approval.
- Enforce RBAC: Admin and Owner authorized; Kasir strictly forbidden from console notification endpoints (403 Forbidden).
- All automated tests run in `mock` transport mode (`CONSOLE_NOTIFICATIONS_TRANSPORT=mock`, `CONSOLE_REAL_EXECUTION_ENABLED=false`).

---

### Task 1: Audit & Model/Service Foundation for Time Expired & Status Constants

**Files:**
- Modify: `app/Models/TvAlertEvent.php`
- Modify: `app/Services/Hardware/ConsoleNotificationService.php`
- Modify: `app/Services/Hardware/TvAlertService.php`

**Interfaces:**
- `TvAlertEvent`:
  - `PRESET_TIME_EXPIRED = 'TIME_EXPIRED'` added to presets.
  - `TYPE_BILLING_EXPIRED = 'BILLING_EXPIRED'` added to event types.
  - `STATUS_ACCEPTED = 'ACCEPTED'` defined.
  - `getPresetMessages()` includes `PRESET_TIME_EXPIRED`.
- `ConsoleNotificationService`:
  - Status mapping: Normalizes delivery outcomes to `PENDING`, `ACCEPTED`, `FAILED`, `UNVERIFIED`.
- `TvAlertService`:
  - `evaluateAutoAlerts()` extended to handle both 10-minute warning (`REMAINING_10_MIN`) and session expiration (`TIME_EXPIRED`) with deduplicated idempotency keys.

- [ ] **Step 1: Write failing unit test for TvAlertEvent presets and billing expiration event creation**
- [ ] **Step 2: Add PRESET_TIME_EXPIRED and TYPE_BILLING_EXPIRED to TvAlertEvent**
- [ ] **Step 3: Update ConsoleNotificationService status normalization**
- [ ] **Step 4: Update TvAlertService::evaluateAutoAlerts to evaluate TIME_EXPIRED alerts**
- [ ] **Step 5: Run tests and verify foundation passes**

---

### Task 2: PS3 webMAN MOD Driver Verification & Status Handling

**Files:**
- Modify: `app/Services/Hardware/Drivers/Ps3WebmanDriver.php`
- Modify: `app/Services/Hardware/Transports/Ps3HenHttpTransport.php`
- Modify: `tests/Unit/Hardware/Ps3WebmanDriverTest.php`

**Interfaces:**
- `Ps3WebmanDriver`:
  - `sendNotification(PhysicalDevice $device, string $message)`:
    - Encodes message via `rawurlencode`.
    - Truncates to <= 120 chars, strips HTML and control characters.
    - Connect timeout: 2s, request timeout: 5s.
    - Returns `HTTP_ACCEPTED` with explicit notice that physical display is unverified.
    - Handles offline, timeout, network error with appropriate `HardwareCommandResult`.

- [ ] **Step 1: Add unit tests verifying Ps3WebmanDriver message sanitization, URL encoding, and unverified display contract**
- [ ] **Step 2: Ensure Ps3WebmanDriver returns STATUS_ACCEPTED ('ACCEPTED' / 'HTTP_ACCEPTED') with honest telemetry**
- [ ] **Step 3: Verify Ps3HenHttpTransport network validation and error handling**
- [ ] **Step 4: Run unit tests to confirm all PS3 driver tests pass**

---

### Task 3: PS4 GoldHEN Driver, Outbound Polling API & Plugin Verification

**Files:**
- Modify: `app/Services/Hardware/Drivers/Ps4GoldHenDriver.php`
- Modify: `app/Http/Controllers/Api/ConsoleAgentController.php`
- Modify: `integrations/ps4-goldhen/src/main.c`
- Modify: `integrations/ps4-goldhen/src/notify.c`
- Modify: `tests/Unit/Hardware/Ps4GoldHenDriverTest.php`
- Modify: `tests/Feature/Hardware/ConsoleAgentApiTest.php`

**Interfaces:**
- `Ps4GoldHenDriver`:
  - Queues alerts as `PENDING` for outbound polling.
  - Generates unique device token for agent authentication (`pair()`).
  - Blocks dangerous port 9090 (BinLoader) strictly.
- `ConsoleAgentController`:
  - `pollEvents()`: Fetches pending events for authenticated device, marks as `DELIVERED`, validates session generation.
  - `acknowledge()`: Receives ACK from plugin, transitions status to `ACCEPTED` (`STATE_VERIFIED`).
  - `heartbeat()`: Updates `last_seen_at` and `connection_status: ONLINE`.
- `integrations/ps4-goldhen`:
  - Source plugin using GoldHEN SDK / `sceKernelSendNotificationRequest`.
  - Compile host test runner (`make`). Document Orbis toolchain requirements for `.prx`.

- [ ] **Step 1: Write tests for PS4 agent polling, ACK lifecycle to ACCEPTED, and offline handling**
- [ ] **Step 2: Ensure ConsoleAgentController acknowledges events as ACCEPTED with verified state**
- [ ] **Step 3: Verify ps4-goldhen plugin source and Makefile host build**
- [ ] **Step 4: Run unit and feature tests to confirm all PS4 driver & API tests pass**

---

### Task 4: UI Integration on `/device` — Kirim Popup & Status Badges

**Files:**
- Modify: `resources/views/device/index.blade.php`
- Modify: `app/Http/Controllers/Admin/ConsoleNotificationController.php`
- Modify: `tests/Feature/Hardware/ConsoleNotificationIntegrationTest.php`

**Interfaces:**
- `/device` Blade View:
  - Accessible to Admin and Owner (`isAdmin() || isOwner()`).
  - For active PlayStation stations (`Digunakan`):
    - Button labeled **Kirim Popup** with bullhorn/comment icon.
    - Modal provides 4 templates:
      1. Template Sisa 10 Menit (`REMAINING_10_MIN`)
      2. Template Waktu Habis (`TIME_EXPIRED`)
      3. Template Kecilkan Volume (`LOWER_VOLUME`)
      4. Pesan Khusus Admin (`CUSTOM`)
    - Real-time status display showing: `PENDING`, `ACCEPTED`, `FAILED`, `UNVERIFIED`.
- `ConsoleNotificationController`:
  - Enforces RBAC (Admin & Owner allowed; Kasir forbidden 403).
  - Validates station mapping and running session.
  - Returns structured JSON response with normalized status.

- [ ] **Step 1: Update ConsoleNotificationController validation to support all 4 templates and return normalized status**
- [ ] **Step 2: Update `/device` view with "Kirim Popup" button, modal templates, and status badge mapper**
- [ ] **Step 3: Update JavaScript on `/device` to poll and render status badges (PENDING, ACCEPTED, FAILED, UNVERIFIED)**
- [ ] **Step 4: Run feature tests covering UI controller, RBAC, templates, and station isolation**

---

### Task 5: Billing Lifecycle Automation Verification & End-to-End Suite

**Files:**
- Modify: `tests/Feature/Hardware/ConsoleBillingAutomationTest.php`
- Modify: `tests/Feature/Hardware/ConsoleNotificationIntegrationTest.php`

**Interfaces:**
- Automated Billing Scenarios:
  - Prepaid package: triggers 10-minute warning and time expired popups.
  - Custom package: triggers based on actual `expires_at`.
  - Postpaid lost time: no automated warnings (only manual popup allowed).
  - Session extend: cancels previous pending popup, increments generation.
  - Session stop: cancels pending popups, frees station.
  - Session fencing: prevents previous session events from leaking to new customer.
  - Deduplication: exactly one automated event per type per session.

- [ ] **Step 1: Create dedicated ConsoleBillingAutomationTest verifying all billing lifecycle automations**
- [ ] **Step 2: Run full automated test suite (`php artisan test`) and verify 100% pass**
- [ ] **Step 3: Compile summary report covering backend, UI, PS4 plugin compilation status, and testing evidence**

