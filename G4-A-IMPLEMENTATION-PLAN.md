# SUGENG RAWUH — G4-A IMPLEMENTATION PLAN
## Mock Hardware Drivers & Simulation Testing

**Target Gate:** G4-A  
**Baseline Tests:** 131 Passing  
**Target Tests:** 131 Baseline + ~25 New G4-A Tests = ~156 Passing  
**Safety Rules:**
1. Database `billing-sugeng-rawuh` is READ-ONLY. All writes strictly in `kasir_ps_test`.
2. NO migration to primary database. NO `migrate:fresh` or `migrate:refresh`.
3. Simulation is MOCK ONLY. NO socket, NO websocket, NO HTTP RPC calls to real devices.
4. Simulated state is strictly isolated from `physical_devices.connection_status` and pairing fields.
5. RBAC: ADMIN only for simulator. KASIR and OWNER strictly forbidden.
6. Billing Engine G2 is untouched.

---

### Task Breakdown

- **Task 1: Configuration, Exceptions, and Driver Abstraction Contracts & DTO**
  - Create `config/hardware.php` with safe defaults (`driver => 'none'`, `simulation_enabled => false`).
  - Create `app/Exceptions/HardwareSimulationException.php`.
  - Create `app/Services/Hardware/DTO/HardwareCommandResult.php`.
  - Create `app/Services/Hardware/Contracts/HardwareDriverInterface.php`.
  - Add unit test `tests/Unit/HardwareDriverAbstractionTest.php`.

- **Task 2: Simulation Scenario Engine & State Manager**
  - Create `app/Services/Hardware/Simulation/HardwareSimulationManager.php`.
  - Implement deterministic scenarios: SUCCESS, OFFLINE, TIMEOUT, REJECTED, RECOVERED.
  - Implement state isolation (cache store with fallback/array), TTL, event log buffer.
  - Implement database and environment guard (blocks if production DB or simulation disabled).
  - Add unit test `tests/Unit/HardwareSimulationManagerTest.php`.

- **Task 3: Mock Samsung TV Driver & Mock Shelly Relay Driver**
  - Create `app/Services/Hardware/Drivers/MockSamsungTvDriver.php` (POWER_ON, STANDBY, MUTE, UNMUTE, GET_STATUS).
  - Create `app/Services/Hardware/Drivers/MockShellyRelayDriver.php` (SWITCH_ON, SWITCH_OFF, GET_STATUS).
  - Implement `app/Services/Hardware/HardwareDriverManager.php` (factory / resolver).
  - Ensure zero network calls, zero writes to real connection/pairing fields.
  - Add unit tests `tests/Unit/MockSamsungTvDriverTest.php` and `tests/Unit/MockShellyRelayDriverTest.php`.

- **Task 4: Simulator API, Form Requests, and RBAC / Security**
  - Create `app/Http/Requests/SimulateCommandRequest.php` and `app/Http/Requests/SetSimulationScenarioRequest.php`.
  - Create `app/Http/Controllers/Admin/HardwareSimulatorController.php`.
  - Register `/admin/hardware/simulator/*` routes in `routes/web.php` with `auth` and `checkrole:admin`.
  - Implement strict RBAC (Guest 302/401, Kasir 403, Owner 403, Admin 200).
  - Implement production database guard in controller/middleware.
  - Add feature tests `tests/Feature/HardwareSimulatorApiTest.php` and `tests/Feature/HardwareSimulatorSecurityTest.php`.

- **Task 5: Mock Control UI (Blade) & Sidebar Integration**
  - Create `resources/views/admin/hardware/simulator.blade.php` with responsive Bootstrap 4.6 + jQuery UI.
  - Add simulation disclaimer: "MODE SIMULASI — Tidak terhubung ke perangkat fisik."
  - Update `resources/views/layouts/sidebar.blade.php` to display "Hardware Simulator" link only for ADMIN when simulation is enabled.
  - Add feature test `tests/Feature/HardwareFrontendSimulatorTest.php`.

- **Task 6: Full Regression, Browser E2E QA, and Deliverables**
  - Run full test suite (baseline 131 tests + all G4-A tests).
  - Run real browser E2E session testing virtual Samsung and Shelly commands, offline, timeout, reject, recovery, and reset.
  - Capture browser screenshots and video artifacts.
  - Verify primary database `billing-sugeng-rawuh` remains untouched (22 tables).
  - Generate `G4-A-MOCK-HARDWARE-IMPLEMENTATION-REPORT.md`.
