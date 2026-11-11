# Samsung TV Volume Control Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement robust, honest, real hardware volume controls (Volume +5, Volume -5, Mute/Toggle, Set Volume rejection/fallback, and 50% soft volume limit) for Samsung Smart TV (specifically FE60EF on 192.168.200.240) via existing Samsung WebSocket transport without breaking existing manual ON/OFF or billing automation.

**Architecture:**
- Use existing `SamsungTvDriver` and `SamsungWebSocketTransport` with multi-frame transmission (up to 5 frames with 150ms interval) for relative volume adjustment (`KEY_VOLUP`, `KEY_VOLDOWN`).
- Support `MUTE_TOGGLE` mapped to `KEY_MUTE` with honest unverified audio state (`UNKNOWN_TOGGLE`).
- Explicitly reject absolute numeric `SET_VOLUME` with `UNSUPPORTED_CAPABILITY` on real hardware where volume readback is unverified.
- Enforce soft volume limit (e.g. 50%) at application level on outgoing commands.
- Eliminate synthetic/fake volume numbers; return null / "Tidak tersedia" / `UNVERIFIED` in DB, API responses, and `/device` UI.
- Update `/device` indicators to reflect `REAL` vs `SIMULATED`, `COMMAND_SENT`, `FAILED`, and `UNVERIFIED`.

**Tech Stack:** Laravel 8, PHP 8.x, WebSocket RFC 6455, Blade, Vanilla JS / jQuery.

## Global Constraints
- Target test TV: Samsung FE60EF, IP: 192.168.200.240, MAC: 98:4E:8A:2A:21:6C (Device ID: 4, Station ID: 4).
- Do NOT modify manual ON/OFF power control (`StationPowerControlService` / WOL / KEY_POWER) or billing lifecycle.
- Do NOT modify Shelly relays, PlayStation PS3/PS4 notifications, or popups.
- Do NOT commit or push git changes without explicit user approval.
- Do NOT trigger physical hardware commands automatically during automated tests (use mock/fake transport in unit/feature tests).
- All real physical test execution must be gated for explicit user authorization.

---

### Task 1: Hardware Config & SamsungTvDriver Multi-Frame Volume & Mute Enhancement

**Files:**
- Modify: `config/hardware.php`
- Modify: `app/Services/Hardware/Drivers/SamsungTvDriver.php`
- Create: `tests/Unit/Hardware/SamsungTvDriverVolumeTest.php`

**Interfaces:**
- `SamsungTvDriver::CMD_MUTE_TOGGLE = 'MUTE_TOGGLE'` added to `SUPPORTED_COMMANDS`.
- `SamsungTvDriver::sendCommand($device, 'VOLUME_UP' | 'VOLUME_DOWN', ['step' => 5])`: Sends up to 5 `sendKey` frames with configured interval (default 150ms).
- `SamsungTvDriver::sendCommand($device, 'SET_VOLUME', ['target_volume' => 40])`: Returns `UNSUPPORTED_CAPABILITY` when not in `$device->verified_capabilities`.
- `SamsungTvDriver::sendCommand($device, 'GET_VOLUME')`: Returns `UNVERIFIED` with volume `null`.

- [x] **Step 1: Write the unit test for SamsungTvDriver volume, mute, and set_volume capabilities**
- [x] **Step 2: Add volume timing configurations in config/hardware.php**
- [x] **Step 3: Update SamsungTvDriver to support CMD_MUTE_TOGGLE, multi-frame step loop, and honest telemetry**
- [x] **Step 4: Run unit tests to verify driver behavior in isolation**

---

### Task 2: TvAlertService & Controller Soft Limit & Real Telemetry Updates

**Files:**
- Modify: `app/Services/Hardware/TvAlertService.php`
- Modify: `app/Http/Controllers/Admin/TvControlController.php`
- Create: `tests/Feature/Hardware/SamsungTvVolumeControlRealModeTest.php`

**Interfaces:**
- `TvAlertService::sendVolumeCommand()`:
  - Enforces soft limit: blocks `VOLUME_UP` if target/current exceeds `max_volume_limit` when enabled.
  - Does NOT overwrite `$tv->current_volume` with fake simulated numbers when `physical_state_verified` is false.
  - Sets event status to `COMMAND_EXECUTED` (or `SENT_TO_SIMULATOR` with `result_code => 'COMMAND_SENT'`) rather than leaving it in `PENDING` waiting for consumer.
- `TvAlertService::getStationControlStatus()`:
  - Returns `control_mode` as `'REAL'` when `config('hardware.driver') === 'real'`.
  - Sets `actual_volume` to `$tv->current_volume` (null if unverified).
- `TvControlController::sendVolume()`:
  - Returns honest payload with `control_mode`, `execution_status`, `volume_display: 'Tidak tersedia'`.

- [x] **Step 1: Write failing feature test for volume command handling in mock and real mode**
- [x] **Step 2: Implement soft limit check and remove fake volume persistence in TvAlertService**
- [x] **Step 3: Update TvAlertService::getStationControlStatus to dynamically report control_mode and honest state**
- [x] **Step 4: Update TvControlController::sendVolume response formatting**
- [x] **Step 5: Run feature test to ensure all assertions pass**

---

### Task 3: UI Indicator & Control Refactoring on /device

**Files:**
- Modify: `resources/views/device/index.blade.php`

**Interfaces:**
- Blade rendering and JavaScript polling:
  - Replaces hardcoded `SIMULASI` badge with dynamic badges:
    - `REAL` (badge-success) vs `SIMULATED` (badge-info).
    - Status: `COMMAND_SENT` (badge-success), `FAILED` (badge-danger), `UNSUPPORTED` (badge-warning).
    - Actual volume: Displays `Aktual: Tidak tersedia` (badge-secondary) when null/unverified instead of `Aktual: 30/100`.
  - Replaces `MUTE (SIM)` with honest `TOGGLED` / `SENT`.
  - Removes `WAITING_CONSUMER` for direct WebSocket devices.
  - Updates button click handlers for `+5`, `-5`, `Mute`, and `Set` to handle response codes and show honest feedback alerts.
  - Disables or shows clear warning on `Set` button in REAL mode indicating absolute setting is unsupported.

- [x] **Step 1: Inspect and update the Blade badge markup in resources/views/device/index.blade.php**
- [x] **Step 2: Refactor renderStationTvControlState() to prevent resetting badges to SIMULASI**
- [x] **Step 3: Update click handlers for Volume +5, -5, Mute, and Set Volume**
- [x] **Step 4: Verify Blade syntax and run full test suite**

---

### Task 4: Comprehensive Verification, Mock Test Suite, & Real Hardware Test Readiness

**Files:**
- Test: `tests/Feature/Hardware/SamsungTvVolumeControlRealModeTest.php`
- Test: `tests/Unit/Hardware/SamsungTvDriverVolumeTest.php`
- Run existing suite: `php artisan test tests/Feature/Hardware/`

- [x] **Step 1: Run complete automated test suite**
- [x] **Step 2: Verify device 4 (Samsung FE60EF, 192.168.200.240) configuration in Hardware Registry and Station Mapping**
- [x] **Step 3: Prepare Real Hardware Test procedure checklist for user review without auto-executing**

---

### Task 5: Physical Hardware Verification on Samsung FE60EF (Approved by User)

**Target Device:**
- Samsung UA43FE60EFKXXD (IP: 192.168.200.240, MAC: 98:4E:8A:2A:21:6C, Station ID: 4)

- [x] **Step 1: Execute Volume -5 on physical TV (Sent 5 frames KEY_VOLDOWN over WSS 8002 -> COMMAND_SENT)**
- [x] **Step 2: Execute Volume +5 on physical TV (Sent 5 frames KEY_VOLUP over WSS 8002 -> COMMAND_SENT)**
- [x] **Step 3: Execute Mute on physical TV (Sent frame KEY_MUTE over WSS 8002 -> COMMAND_SENT)**
- [x] **Step 4: Execute Unmute on physical TV (Sent frame KEY_MUTE over WSS 8002 -> COMMAND_SENT)**
- [x] **Step 5: Verify SET_VOLUME rejection (HTTP 422 CAPABILITY_UNSUPPORTED) and 50% soft volume limit guard (HTTP 422 INVALID_INPUT)**


