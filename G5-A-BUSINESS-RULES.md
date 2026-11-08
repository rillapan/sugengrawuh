# G5-A BUSINESS RULES CATALOG: HARDWARE AUTOMATION

**Document ID:** SR-G5-BR-CATALOG-001  
**Project:** Sugeng Rawuh — Integrated Billing & POS Management System  
**Gate:** G5-A — Billing-to-Hardware Automation Architecture & Design  
**Status:** DRAFT / PROPOSAL FOR REVIEW  
**Date:** 2026-11-05  
**Author:** AI Systems Architect & Core Team  

---

## 1. APPROVED BUSINESS DECISION (SR-G5-BR-001)

### Decision Record
- **Decision ID:** `SR-G5-BR-001`
- **Title:** Automatic Billiard Light Shutdown on Prepaid Expiration
- **Status:** **APPROVED & FINAL** (Non-negotiable, permanently locked)
- **Approved Policy Statement:**
  > "Lampu meja biliar harus langsung dimatikan secara otomatis ketika waktu billing prepaid habis, tanpa masa tenggang dan tanpa konfirmasi kasir."

### 10 Mandatory Principles of SR-G5-BR-001:
1. **Prepaid Start Intent:** When a prepaid session begins successfully, the system immediately generates an automation intent `SWITCH_ON` targeted at the Shelly relay mapped to the billiard station.
2. **Active Lighting Guarantee:** During active prepaid billing, the billiard light must remain illuminated (`ON`).
3. **Immediate Expiration Trigger:** When server time reaches or exceeds `expires_at`, the system must immediately process an automation intent `SWITCH_OFF` without artificial delays.
4. **Zero Grace Period:** There is strictly **zero (0) seconds** grace period. No extension of playtime beyond `expires_at` is granted.
5. **No Cashier Confirmation for Shutdown:** The system must **never** prompt the cashier for confirmation or wait for the cashier to click "Selesai" before issuing the `SWITCH_OFF` command for an expired prepaid session.
6. **Invalidation of Obsolete Commands:** If a customer extends a session prior to expiration, any existing scheduled or pending `SWITCH_OFF` command associated with the previous expiration time must immediately be revoked, cancelled, or marked `STALE`.
7. **Early Termination Shutdown:** If a session is stopped early by the cashier, the system immediately generates a `SWITCH_OFF` intent.
8. **Operational Failure Alerting:** If an automated `SWITCH_OFF` command fails (due to Shelly offline, network drop, etc.), the cashier must immediately receive a high-visibility operational alert, while the system initiates bounded safe retries.
9. **Zero Financial Impact of Hardware Failure:** Hardware failures, network drops, or relay malfunctions must **never** invalidate billing calculation, block payment checkout, distort revenue reporting, or roll back database transactions.
10. **Hardware Execution Gating:** Automated commands may only be transmitted to physical devices after physical protocol verification (G4-B2) and explicit operational authorization. During G5-A and G5-B, mock transports are strictly enforced.

---

## 2. PREPAID BILLING SESSION RULES

| Rule ID | Rule Name | Description & Specification |
|---|---|---|
| **BR-PRE-001** | **Server Time Truth** | `expires_at` recorded in MySQL `transactions` is the sole source of truth for expiration. The browser JavaScript countdown timer is purely an informational visual aid. Expiration evaluation is exclusively performed by the server. |
| **BR-PRE-002** | **Prepaid Ignition & Pre-Scheduled Expiry (Option B)** | Upon successful creation of a prepaid transaction in `BillingService::startSession()`, the system atomically commits two intents: (1) `BILLING_STARTED` (`desired_state = 'ON'`, `scheduled_at = NOW()`), and (2) `BILLING_EXPIRED` (`desired_state = 'OFF'`, `scheduled_at = expires_at`). |
| **BR-PRE-003** | **Prepaid Expiration Cutoff Execution** | When server time reaches `expires_at`, the pre-scheduled `BILLING_EXPIRED` intent is immediately claimed and dispatched by the automation worker (500ms polling). The cutoff executes without waiting for cashier interaction and without waiting for `billing:worker` G2 status reconciliation. |
| **BR-PRE-004** | **Prepaid Extension Safety** | When a prepaid session is extended via `BillingService::extendSession()`, the new `expires_at` is calculated. The station's `session_generation` token is incremented (+1), the previous scheduled `BILLING_EXPIRED` intent is marked `CANCELLED`, and a new scheduled `BILLING_EXPIRED` intent is created with `scheduled_at = new_expires_at`. No `SWITCH_ON` command is resent if the relay is already verified ON. |
| **BR-PRE-005** | **Prepaid Early Stop Cutoff** | If a customer leaves before `expires_at` and the cashier clicks "Stop" (via `DeviceController::stopDevice` or `BillingService::stopSession`), the scheduled `BILLING_EXPIRED` intent is marked `CANCELLED`, and an immediate `BILLING_STOPPED` intent is committed with `scheduled_at = NOW()`. |
| **BR-PRE-006** | **Prepaid Replay Invalidation** | Under no circumstances may an expired session's `SWITCH_OFF` command execute if a new customer has already begun a new session on the same station. The `session_generation` fencing token strictly prevents this. |

---

## 3. LOST TIME (POSTPAID) BILLING RULES

| Rule ID | Rule Name | Description & Specification |
|---|---|---|
| **BR-LT-001** | **Lost Time Ignition (SWITCH_ON)** | When a Lost Time (postpaid/open-ended) session begins via `BillingService::startSession()`, an intent for `SWITCH_ON` is committed with `scheduled_at = NOW()`. |
| **BR-LT-002** | **Absence of Scheduled Expiry** | Lost Time transactions have `expires_at = NULL`. The automation engine must **never** schedule or execute an automated expiration cutoff for Lost Time sessions. |
| **BR-LT-003** | **Continuous Illumination** | The billiard light or TV display must remain active throughout the duration of a Lost Time session until explicitly stopped by the cashier. |
| **BR-LT-004** | **Lost Time Stop Cutoff (SWITCH_OFF)** | When the cashier concludes a Lost Time session via `BillingService::stopSession()` or `DeviceController::stopDevice()`, the transaction transitions to `COMPLETED` (or `STOPPED_EARLY`), and an intent for `SWITCH_OFF` is committed with `scheduled_at = NOW()`. |
| **BR-LT-005** | **Midnight Crossing Immunity** | Lost Time sessions running past midnight (23:59:59 → 00:00:00) must continue uninterrupted. The automation worker must never treat midnight rollover as a session termination event. G2 billing calculation remains continuous based on elapsed minutes and package rates. |

---

## 4. STATION MAPPING & HARDWARE INVARIANTS

| Rule ID | Rule Name | Description & Specification |
|---|---|---|
| **BR-MAP-001** | **Role-Specific Device Mapping** | Each station (`devices` table) may have at most **one active primary device** per role: `DISPLAY_TV` (PlayStation stations) or `LIGHT_RELAY` (Billiard tables) in `station_device_mappings` where `mapping_status = 'ACTIVE'`. |
| **BR-MAP-002** | **Software-Only Fallback (No Hardware)** | If a station has no active `StationDeviceMapping` configured, all billing operations (start, extend, expire, stop) proceed normally as pure software billing. In this case, the automation system logs `SKIPPED_NO_MAPPING` and generates no network intents. |
| **BR-MAP-003** | **Cross-Station Isolation** | An automation intent for Station X must **never** trigger hardware mapped to Station Y. All dispatch operations strictly resolve physical device credentials via `StationDeviceMapping` bound to the station ID. |
| **BR-MAP-004** | **Mapping Mutation Invalidation** | If an admin changes or deactivates a station's device mapping while an intent is in-flight or retrying, the worker re-validates the mapping ID. If the active mapping has changed, pending intents targeting the old mapping are marked `STALE_MAPPING_CHANGED`. |

---

## 5. HARDWARE CAPABILITY POLICIES & VERIFICATION BOUNDARIES

### 5.1 Shelly 1PM Mini Gen3 (Billiard Lighting Relay)
- **Capability Status:** **SOFTWARE-TESTED (Physical Verification Pending G4-B2)**.
- **Protocol Capabilities:** `SWITCH_ON`, `SWITCH_OFF`, `GET_STATUS`.
- **Desired-State Idempotency:** The Shelly Gen3 RPC `Switch.Set {"id": 0, "on": true/false}` sets absolute output state, not a toggle. Repeating `SWITCH_OFF` multiple times is safe and deterministic.
- **Protocol Read-Back Verification:** In software drivers, every command execution calls `Switch.GetStatus {"id": 0}` to verify the `output` boolean matches the desired state.
- **Physical Verification Boundary:** Passing tests with fake transport in G4-B1 validates JSON-RPC schema and driver logic, but does **NOT** constitute physical verification of relay contacts switching live AC power. Physical commands remain locked behind `HardwareSafetyGuard` until G4-B2.
- **Relay Truthfulness vs Bulb Health:** `Switch.GetStatus` confirms relay output contact state, not physical bulb illumination. UI labels specify `RELAY_ON_VERIFIED` / `RELAY_OFF_VERIFIED`.
- **Power Monitoring:** Shelly 1PM Mini provides real-time power measurement (`apower`). Current power draw data is logged for telemetry and bulb fault detection.

### 5.2 Samsung Smart TV 50U8500 (PlayStation Displays)
- **Capability Status:** **PHYSICAL-UNVERIFIED (Disabled / Restricted)**.
- **POWER_ON:** Requires Wake-on-LAN (WoL) packet and active standby listening. Currently unverified physically in G4-B1.
- **STANDBY:** WebSocket command `KEY_POWER` functions as a toggle switch on many Samsung Tizen firmware versions. Sending `KEY_POWER` when the TV is already in standby or turned off can accidentally turn the TV back ON.
- **Safety Restriction:** Under G5, automatic power toggle commands to Samsung TVs are **disabled by default** (`SKIPPED_UNSUPPORTED_CAPABILITY`) unless physical verification is established in G4-B2 and explicitly whitelisted in `physical_devices.verified_capabilities`.
- **No Shelly Relay on PS5 / TV Mains:** Under no circumstances may a Shelly relay be placed on the AC power line of a PlayStation console or TV to act as an automated cutoff switch. Abrupt power cuts cause file system corruption on gaming consoles.

---

## 6. OPERATIONAL SAFETY & VENUE ENVIRONMENT

| Rule ID | Rule Name | Description & Specification |
|---|---|---|
| **BR-OPS-001** | **General Ambient Lighting Requirement** | The automated cutoff only extinguishes the lamp directly suspended over the specific billiard table. General venue/room lighting must remain powered from independent, non-automated electrical circuits so players are never left in complete darkness when their timer expires. |
| **BR-OPS-002** | **Impending Expiration Visual Notification** | If the existing billing UI or station dashboard supports it, a visual banner (e.g. at 5 minutes and 1 minute remaining) is displayed to the cashier to notify players verbally. However, **this warning must never introduce a grace period** or delay the automated `SWITCH_OFF` at `expires_at`. |
| **BR-OPS-003** | **Emergency Manual Override** | In case of an emergency, electrical fault, or system crash, staff must have physical wall switch overrides or the ability via the Cashier UI to manually toggle a station's light regardless of billing status (authorized via RBAC). |

---

## 7. FINANCIAL DECOUPLING & RBAC INVARIANTS

| Rule ID | Rule Name | Description & Specification |
|---|---|---|
| **BR-FIN-001** | **Zero Accounting Distortion** | Whether a Shelly relay turns off in 200ms, times out after 2000ms, or fails permanently due to power loss, the recorded billing total, elapsed rental minutes, item charges, and payment amounts in `transactions` are 100% frozen at `expires_at` or `ended_at`. |
| **BR-FIN-002** | **Zero Refund Integration** | Billing Sugeng Rawuh has strictly **no refund feature** (as established in G1/G2). Hardware communication failures do not trigger automated refunds. |
| **BR-RBAC-001** | **Role Authorization Matrix & Override Bounds** | |
| | - **OWNER:** Read-only access to all hardware diagnostics, automation performance dashboards, and failure reports. |
| | - **ADMIN:** Full configuration of device registry, station mapping, automation parameters, manual driver diagnostics, and log inspection. |
| | - **KASIR:** Operational view only. Can view verified hardware status badges (`RELAY_ON_VERIFIED`, `RELAY_OFF_VERIFIED`, etc.), receive failure alerts, and execute authorized manual retry actions. **Kasir can ONLY retry OFF on an expired session or retry ON on an active session. Kasir is STRICTLY FORBIDDEN from turning on table lights without an active billing session! Zero access to hardware IP, MAC, tokens, or passwords.** |

