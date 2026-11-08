# G5-A UI/UX FLOW: CASHIER OPERATIONAL EXPERIENCE & ADMIN MONITORING

**Document ID:** SR-G5-UX-001  
**Project:** Sugeng Rawuh — Integrated Billing & POS Management System  
**Gate:** G5-A — Billing-to-Hardware Automation Architecture & Design  
**Status:** DRAFT / PROPOSAL FOR REVIEW  
**Date:** 2026-11-05  
**Author:** AI Systems Architect & Core Team  

---

## 1. DESIGN PHILOSOPHY & OBJECTIVES

The user interface for Sugeng Rawuh G5 is designed around three non-negotiable principles:
1. **Zero Friction on Prepaid Expiration (SR-G5-BR-001):** The billiard light must turn off completely automatically upon expiration. The cashier must **never** be interrupted by blocking modal popups asking "Waktu habis, matikan lampu sekarang?".
2. **Immediate Ambiguity Elimination:** Cashiers must always know the actual operational state of the table relays via clear, real-time badges (`RELAY_ON_VERIFIED`, `RELAY_OFF_VERIFIED`, `COMMAND_FAILED`).
3. **Truthful State Representation:** The UI clearly specifies that relay read-back confirms contact output state, not light bulb integrity. In mock/simulation testing, badges explicitly indicate `SIMULATED`.
4. **Actionable Emergency Guidance:** If hardware fails or goes offline, the system clearly displays the exact station name and gives step-by-step physical manual instructions (wall switch override) without leaking network credentials.

---

## 2. HARDWARE STATUS BADGE TAXONOMY

On each station card (PlayStation station or Billiard table), a dedicated hardware status badge is rendered alongside the existing billing status (`Digunakan`, `Tersedia`).

| Status Code | Badge Label (Bahasa Indonesia) | Bootstrap 4.6 Class | FontAwesome Icon | Meaning & Operational Context |
|---|---|---|---|---|
| `RELAY_ON_VERIFIED` | **Relay ON (Terverifikasi)** | `badge badge-success` | `fas fa-bolt` | Output relay terkonfirmasi `true` via `Switch.GetStatus`. Kontak relay aktif. |
| `RELAY_OFF_VERIFIED` | **Relay OFF (Terverifikasi)** | `badge badge-secondary` | `far fa-circle` | Output relay terkonfirmasi `false` via `Switch.GetStatus`. Kontak relay terbuka. |
| `COMMAND_PENDING` | **Mengirim Perintah...** | `badge badge-warning` | `fas fa-spinner fa-spin` | Automation intent diambil oleh worker; menunggu respons network RPC (< 2s). |
| `COMMAND_FAILED` | **Perintah Gagal** | `badge badge-danger` | `fas fa-exclamation-triangle` | Driver execution atau read-back gagal setelah 4 total attempts. Perlu aksi manual. |
| `DEVICE_OFFLINE` | **Perangkat Offline** | `badge badge-dark` | `fas fa-wifi-slash` | Shelly atau TV tidak merespons TCP/HTTP ping; WiFi atau catu daya terputus. |
| `STATE_UNKNOWN` | **Status Belum Dicek** | `badge badge-light text-muted` | `fas fa-question-circle` | Stasiun memiliki pemetaan hardware, namun belum ada read-back pasca boot. |
| `SIMULATED_ON` / `OFF` | **Simulasi ON / OFF** | `badge badge-info` | `fas fa-vial` | Mode Mock/Testing G4-B1/G5-A: Driver simulasi aktif (bukan perangkat fisik). |
| `NO_HARDWARE` | **Tanpa Otomasi** | *(Hidden / Muted)* | `fas fa-minus` | Stasiun beroperasi murni software billing tanpa hardware fisik. |

---

## 3. CASHIER POS DASHBOARD FLOWS

### 3.1 Flow A: Prepaid Expiration (Zero Cashier Modal Interaction)
```
[Customer is playing Meja Biliar 03]
                |
                v
[Timer reaches 00:00:00 on Cashier Card]
  - Card countdown changes to 00:00:00 (Red)
  - Station billing_status automatically becomes EXPIRED_PENDING_ACTION
                |
                v
[Worker immediately executes Pre-Scheduled SWITCH_OFF via Shelly RPC]
  - Station badge transitions: `RELAY_ON_VERIFIED` -> `COMMAND_PENDING` (~300ms)
  - Shelly confirms `output: false` via GetStatus
  - Station badge transitions: `RELAY_OFF_VERIFIED` (gray)
                |
                v
[Cashier Dashboard State]
  - NO modal popup interrupts the cashier.
  - Cashier continues serving F&B or handling other tables uninterrupted.
  - Station card displays "Selesai / Bayar" button for final bill settlement at cashier convenience.
```

### 3.2 Flow B: Prepaid Extension Before Expiry
```
[Customer requests 1-Hour Extension with 2 minutes remaining]
                |
                v
[Cashier clicks "Perpanjang" on Station Card]
  - Modal opens: Cashier selects +60 minutes and payment method.
  - Cashier confirms extension.
                |
                v
[Billing Engine commits Extension]
  - `expires_at` is extended by 60 minutes.
  - `session_generation` token is incremented (+1).
  - Any pending `BILLING_EXPIRED` intent for this session is marked `CANCELLED`.
                |
                v
[Hardware Continuity]
  - Relay remains ON (`RELAY_ON_VERIFIED`).
  - No redundant `SWITCH_ON` command is sent.
  - Zero disruption to customer gameplay.
```

### 3.3 Flow C: Hardware Failure Alert & Manual Handling
```
[Prepaid expires on Meja Biliar 02, but Shelly is disconnected from WiFi]
                |
                v
[Automation Worker attempts RPC]
  - Connect timeout after 1000ms.
  - Worker schedules bounded retry with backoff.
  - After attempt 3 fails, intent marked `FAILED`.
                |
                v
[Cashier Dashboard High-Visibility Alert]
  - A persistent, prominent alert banner slides down at the top of the POS screen:
    +-----------------------------------------------------------------------------------------------+
    | [!] PERHATIAN KASIR: Lampu Meja Biliar 02 Gagal Dimatikan Otomatis!                           |
    | Waktu billing sudah habis, namun perangkat relay tidak merespons (Offline/WiFi terputus).     |
    | TINDAKAN MANUAL: Harap matikan saklar fisik Meja 02 di panel kasir.                           |
    | [Coba Lagi via Sistem]  [Saya Sudah Matikan Manual]                                           |
    +-----------------------------------------------------------------------------------------------+
  - Station Card badge switches to `COMMAND_FAILED` (Red).
  - Cashier is given clear, safe manual instructions.
  - Billing calculation and payment settlement remain 100% normal and unblocked.
```

---

## 4. UI WIREFRAME MOCKUPS (BLADE / BOOTSTRAP 4.6)

### 4.1 Station Card (Billiard Table in Active Prepaid Play)
```html
<div class="card shadow-sm border-left-primary mb-3 station-card" id="station-card-3">
  <div class="card-body py-2">
    <div class="d-flex justify-content-between align-items-center">
      <h6 class="font-weight-bold text-primary m-0">Meja Biliar 03</h6>
      <span class="badge badge-success">Digunakan</span>
    </div>
    
    <div class="my-2">
      <div class="small text-muted">Pelanggan: <span class="font-weight-bold text-dark">Rian</span> (Prepaid)</div>
      <div class="h5 font-weight-bold text-danger m-0 countdown-timer" data-expires="2026-11-05T15:00:00">
        00:04:12
      </div>
    </div>
    
    <!-- HARDWARE AUTOMATION STATUS BADGE -->
    <div class="d-flex justify-content-between align-items-center pt-2 border-top">
      <small class="text-muted"><i class="fas fa-plug mr-1"></i>Hardware:</small>
      <span class="badge badge-success" id="hw-badge-3">
        <i class="fas fa-lightbulb mr-1"></i> Lampu ON (Terverifikasi)
      </span>
    </div>
    
    <div class="mt-2 text-right">
      <button class="btn btn-sm btn-info" onclick="openExtendModal(3)">Perpanjang</button>
      <button class="btn btn-sm btn-outline-danger" onclick="confirmStop(3)">Stop</button>
    </div>
  </div>
</div>
```

---

### 4.2 Station Card (Prepaid Expired - Light Verified OFF)
```html
<div class="card shadow-sm border-left-warning mb-3 station-card" id="station-card-3">
  <div class="card-body py-2">
    <div class="d-flex justify-content-between align-items-center">
      <h6 class="font-weight-bold text-warning m-0">Meja Biliar 03</h6>
      <span class="badge badge-warning">Waktu Habis</span>
    </div>
    
    <div class="my-2">
      <div class="small text-muted">Pelanggan: <span class="font-weight-bold text-dark">Rian</span></div>
      <div class="h5 font-weight-bold text-muted m-0">00:00:00</div>
    </div>
    
    <!-- HARDWARE AUTOMATION STATUS BADGE -->
    <div class="d-flex justify-content-between align-items-center pt-2 border-top">
      <small class="text-muted"><i class="fas fa-plug mr-1"></i>Hardware:</small>
      <span class="badge badge-secondary" id="hw-badge-3">
        <i class="far fa-lightbulb mr-1"></i> Lampu OFF (Terverifikasi)
      </span>
    </div>
    
    <div class="mt-2 text-right">
      <button class="btn btn-sm btn-success" onclick="openCheckoutModal(3)">
        <i class="fas fa-cash-register mr-1"></i> Selesaikan Pembayaran
      </button>
    </div>
  </div>
</div>
```

---

## 5. ADMIN DIAGNOSTIC & MONITORING SCREEN

In the Admin Navigation menu under **Hardware Management**, an **Otomasi Hardware** tab is added:
1. **Summary Metrics Cards:**
   - Total Relays Online vs Offline.
   - Automation Success Rate (last 24 hours, e.g. `99.4%`).
   - Average Cutoff Latency (e.g. `245ms`).
   - Active Hardware Alerts (e.g. `0`).
2. **Live Relay Grid:** Displays all stations with real-time Shelly apower (Watts), WiFi RSSI signal strength, and last ping timestamp.
3. **Execution Log Inspector:** Full search table allowing admins to inspect commands, latency, retry counts, and sanitized error traces.
4. **Manual Diagnostics Drawer (Admins Only):** Allows sending test `PING` or `GET_STATUS` to verify connectivity during non-operational hours.
