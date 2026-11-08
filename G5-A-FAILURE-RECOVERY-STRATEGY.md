# G5-A FAILURE RECOVERY STRATEGY: RESILIENCE & OFFLINE FALLBACK

**Document ID:** SR-G5-FAIL-001  
**Project:** Sugeng Rawuh — Integrated Billing & POS Management System  
**Gate:** G5-A — Billing-to-Hardware Automation Architecture & Design  
**Status:** DRAFT / PROPOSAL FOR REVIEW  
**Date:** 2026-11-05  
**Author:** AI Systems Architect & Core Team  

---

## 1. RESILIENCE PRINCIPLES & GOALS

In a brick-and-mortar cafe/billiard environment, IoT hardware is inherently prone to network interference, router reboots, power surges, and physical disconnections.

The Sugeng Rawuh automation engine is designed to be **fault-tolerant, non-blocking, and fail-safe**:
1. **Financial Decoupling:** Hardware downtime must never disrupt customer transactions, billing calculation, or payment collection.
2. **Safe Defaulting:** If the system is uncertain about the current state of a physical device, it alerts human staff rather than blindly firing destructive power commands.
3. **Bounded Retries:** The system never loops indefinitely; retries are capped, jittered, and guarded by generation fencing.
4. **Stale Command Suppression:** When an offline device reconnects after minutes or hours, old commands are strictly discarded if the station is no longer in the corresponding billing state.

---

## 2. FAILURE MODES & ROOT CAUSE TAXONOMY

| Failure Mode | Root Causes | Impact on Hardware | Detection Mechanism | Recovery Action |
|---|---|---|---|---|
| **FM-01: Shelly Offline (Unreachable)** | Router reboot, WiFi signal drop, Shelly powered down, DHCP IP lease changed. | Relay remains in current physical state. | TCP Connect Timeout (> 1000ms) or `EHOSTUNREACH`. | Transition intent to `RETRY_PENDING`. Alert cashier. Limit to 3 retries. Fallback to physical switch. |
| **FM-02: HTTP Request Timeout** | Network congestion, packet collision on 2.4GHz WiFi. | Command may or may not have reached Shelly. | Socket Read Timeout (> 2000ms). | Read-back physical state via `Switch.GetStatus`. If state matches desired state, mark SUCCESS. If not, schedule retry. |
| **FM-03: Read-Back State Mismatch** | Relay internal contact failure, mechanical latch failure, corrupted firmware state. | Relay acknowledges RPC `true`, but `Switch.GetStatus` reports `output: false`. | Post-dispatch comparison: `desired_state != observed_state`. | Log `READBACK_MISMATCH`. Treat as execution failure. Trigger alert for cashier. |
| **FM-04: Worker Crash In-Flight** | POS computer power cut, OS crash, PHP Fatal Error. | Intent left in `PROCESSING` state in MySQL. | Worker startup recovery: detects intents locked > 60 seconds without completion. | Reset intent to `RETRY_PENDING` (or `FAILED` if maxed out). Re-verify fencing before execution. |
| **FM-05: Extend vs. Expire Collision** | Cashier extends prepaid session exactly as worker triggers cutoff. | Potential premature light shutdown while customer is actively playing. | Pre-execution Fencing: `intent.session_generation != station.session_generation`. | Worker marks intent `STALE`/`CANCELLED`. Command is discarded. Light remains `ON`. |
| **FM-06: New Customer Session Crossover** | Customer A leaves; Customer B sits down immediately before old retry fires. | Potential shutdown of Customer B's new session. | Pre-execution Session Check: `intent.transaction_id != station.active_transaction_id`. | Intent marked `STALE_SESSION_CLOSED`. Retry cancelled immediately. |
| **FM-07: Unverified Samsung TV Toggle** | Attempting automatic power toggles via unverified WebSocket commands. | TV turns ON when it should turn OFF, or turns OFF during gameplay. | Capability Whitelist Guard: checks `verified_capabilities`. | Command blocked automatically. Intent marked `SKIPPED_UNVERIFIED_CAPABILITY`. |

---

## 3. BOUNDED EXPONENTIAL BACKOFF RETRY STRATEGY

When a command fails due to transient network conditions (e.g. FM-01, FM-02):

### 3.1 Retry Schedule, Limits & Mandatory Read-Back
- **Database Column:** `attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0`, `max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 4`.
- **Attempt Sequence:** Exactly **4 total attempts** (1 initial dispatch + up to 3 retries).
- **Backoff Schedule:**
  - Initial Failure: Schedule Retry 1 after **3 seconds** (`+3s`).
  - Attempt 2 Failure: Schedule Retry 2 after **10 seconds** (`+10s`).
  - Attempt 3 Failure: Schedule Retry 3 after **30 seconds** (`+30s`).
  - Attempt 4 Failure: Mark intent permanently as `FAILED`. No further automatic attempts.
- **Mandatory Read-Back Prior to Retry:** Before dispatching any retry network packet, the worker calls `Switch.GetStatus {"id": 0}`. If the relay output already matches `desired_state` (meaning the previous timeout command actually succeeded on the device), the intent is immediately marked `COMPLETED` and no redundant toggle packet is transmitted.

### 3.2 The Mandatory Pre-Retry Re-Validation Check (Fencing)
Before dispatching any scheduled retry, the worker must re-verify all 4 conditions:
```php
public function canExecuteRetry(AutomationIntent $intent): bool
{
    $station = Device::find($intent->station_id);
    $transaction = Transaction::find($intent->transaction_id);

    // 1. Check Station Generation
    if ($station->session_generation !== $intent->session_generation) {
        $intent->markAsStale("Generation mismatch on retry");
        return false;
    }

    // 2. Check Active Transaction on Station
    if ($intent->desired_state === 'OFF') {
        // If station now has a RUNNING transaction with different ID, abort!
        $activeTx = $station->activeTransaction();
        if ($activeTx && $activeTx->id_transaksi !== $intent->transaction_id && $activeTx->billing_status === 'RUNNING') {
            $intent->markAsStale("Station already occupied by new transaction #{$activeTx->id_transaksi}");
            return false;
        }

        // If the same transaction was extended, abort!
        if ($transaction && $transaction->billing_status === 'RUNNING') {
            $intent->markAsCancelled("Transaction was extended during retry window");
            return false;
        }
    }

    // 3. Check Active Mapping
    if (!$station->activeMapping || $station->activeMapping->id !== $intent->station_device_mapping_id) {
        $intent->markAsStale("Station mapping changed during retry window");
        return false;
    }

    return true;
}
```

---

## 4. CASHIER OPERATIONAL FALLBACK WORKFLOW & RBAC BOUNDARIES

When all automated retries are exhausted (`attempts >= max_attempts`):

1. **Dashboard Alert Activated:**
   - Cashier POS screen presents an urgent alert:
     > **"Peringatan Meja Biliar [Nama Meja]: Gagal mematikan relay lampu otomatis setelah waktu habis. Harap matikan saklar fisik di panel kontrol."**
2. **Station Card Hardware Badge:**
   - Switched to `COMMAND_FAILED` (Red, exclamation icon).
3. **Cashier Action Options & Security Boundaries:**
   - **Option 1: [Coba Lagi]:** Kasir hanya diizinkan memicu Coba Lagi untuk mematikan lampu yang gagal padam, atau mengulang perintah ON untuk sesi yang *sedang aktif*. **Kasir dilarang keras menyalakan lampu stasiun tanpa ada sesi billing aktif!**
   - **Option 2: [Sudah Dimatikan Manual]:** Kasir mengonfirmasi saklar fisik telah dimatikan; status badge diperbarui ke `RELAY_OFF_MANUAL` dengan pencatatan audit user ID kasir.
4. **Billing & Checkout Integrity:**
   - Kasir dapat langsung mengklik "Selesaikan Pembayaran", memproses cash/QRIS, dan mencetak struk.
   - Kegagalan hardware **tidak pernah memblokir** penyelesaian pembayaran atau perhitungan keuangan.

---

## 5. SYSTEM CRASH & RESTART RECONCILIATION

### 5.1 On Worker Daemon Boot / Restart:
Whenever `php artisan billing:automation-worker` starts:
1. **Stale Intent Sweep (60s Lease Timeout):**
   - Queries `automation_intents` for records with `status = 'PROCESSING'` where `locked_at < NOW() - 60 seconds`.
   - Releases the lock, increments `attempts`, and resets status to `RETRY_PENDING` if `attempts < max_attempts`, or `FAILED` if maxed out.
2. **Expired Session Check:**
   - Queries `transactions` for any prepaid sessions where `expires_at <= NOW()` and `billing_status = 'RUNNING'`.
   - Calls `BillingService::evaluateExpiration()` to transition billing status and generate missing `SWITCH_OFF` intents.

### 5.2 Command: `php artisan billing:reconcile`
An administrative Artisan command executed on demand or scheduled at daily opening (08:30 WIB):
- Iterates over all billiard tables and PlayStation stations.
- Cross-references the database billing state (`RUNNING` vs `Tersedia`) against the physical hardware state via `Switch.GetStatus`.
- If a station is `Tersedia` but the physical relay output is `true`: logs warning and issues a non-destructive `SWITCH_OFF` intent.
- If a station is `Digunakan` (active billing) but the relay output is `false`: logs warning and issues a `SWITCH_ON` intent.
- Generates a full reconciliation summary report for management.
