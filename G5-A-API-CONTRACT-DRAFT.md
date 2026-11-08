# G5-A API CONTRACT DRAFT: INTERNAL SERVICES & REST ENDPOINTS

**Document ID:** SR-G5-API-001  
**Project:** Sugeng Rawuh — Integrated Billing & POS Management System  
**Gate:** G5-A — Billing-to-Hardware Automation Architecture & Design  
**Status:** DRAFT / PROPOSAL FOR REVIEW  
**Date:** 2026-11-05  
**Author:** AI Systems Architect & Core Team  

---

## 1. PURPOSE & ARCHITECTURAL OVERVIEW

This document outlines:
1. **Internal PHP Service Contracts:** Object-oriented interfaces decoupling Billing Engine G2 from hardware dispatching.
2. **REST / JSON Endpoints:** Lightweight HTTP APIs serving the Cashier POS dashboard, AJAX polling badges, and Admin diagnostic screens.
3. **RBAC & Security Constraints:** Ensuring cashier views never expose hardware credentials (IP, MAC, pairing keys, passwords) while providing clear operational alerts.

---

## 2. INTERNAL SERVICE INTERFACES (PHP CONTRACTS)

### 2.1 `AutomationIntentServiceInterface`
Responsible for transactional outbox persistence and lifecycle management inside `BillingService`.

```php
namespace App\Services\Hardware;

use App\Models\Transaction;
use App\Models\Device;
use Carbon\Carbon;

interface AutomationIntentServiceInterface
{
    /**
     * Record a new desired-state automation intent atomically inside DB transaction.
     */
    public function recordIntent(
        Device $station,
        ?Transaction $transaction,
        string $eventType,
        string $desiredState,
        ?Carbon $scheduledAt = null
    ): AutomationIntent;

    /**
     * Invalidate/cancel any pending OFF intents for a transaction (e.g. on extension).
     */
    public function cancelPendingOffIntents(Transaction $transaction): int;

    /**
     * Increment session generation token for a station and invalidate conflicting intents.
     */
    public function bumpSessionGeneration(Device $station): int;
}
```

---

### 2.2 `AutomationDispatcherServiceInterface`
Executed exclusively by CLI worker daemons (`php artisan billing:automation-worker`).

```php
namespace App\Services\Hardware;

interface AutomationDispatcherServiceInterface
{
    /**
     * Fetch and process the next batch of eligible intents using row-level locking.
     * Returns count of processed intents.
     */
    public function dispatchBatch(int $limit = 10): int;

    /**
     * Process a single intent through the fencing, capability check, driver dispatch,
     * read-back verification, and logging pipeline.
     */
    public function processIntent(AutomationIntent $intent): AutomationExecutionResult;

    /**
     * Re-verify fencing and station state immediately prior to physical dispatch.
     */
    public function validateFencing(AutomationIntent $intent): bool;
}
```

---

## 3. REST / JSON API ENDPOINTS

### 3.1 Endpoint 1: Cashier Station Hardware Status (Non-Blocking Polling)
- **Route:** `GET /api/stations/{id}/automation-status`
- **Access:** `auth` (KASIR, ADMIN, OWNER)
- **Description:** Non-blocking endpoint polled by the cashier POS station card every 3–5 seconds to render live hardware badges.

#### Request Headers:
```http
GET /api/stations/4/automation-status HTTP/1.1
Accept: application/json
X-Requested-With: XMLHttpRequest
```

#### Successful Response (HTTP 200 OK):
```json
{
  "status": "success",
  "data": {
    "station_id": 4,
    "station_name": "Meja Biliar 04",
    "hardware_state": "RELAY_ON_VERIFIED",
    "hardware_badge": {
      "code": "RELAY_ON_VERIFIED",
      "label": "Relay ON (Terverifikasi)",
      "color_class": "badge-success",
      "icon": "fas fa-bolt"
    },
    "has_hardware": true,
    "device_role": "primary_billiard_relay",
    "last_verified_at": "2026-11-05 14:30:15",
    "pending_intent": null,
    "alert": null
  }
}
```

#### Alert Response (When Command Fails - HTTP 200 OK):
```json
{
  "status": "warning",
  "data": {
    "station_id": 4,
    "station_name": "Meja Biliar 04",
    "hardware_state": "COMMAND_FAILED",
    "hardware_badge": {
      "code": "COMMAND_FAILED",
      "label": "Gagal Mematikan Lampu",
      "color_class": "badge-danger",
      "icon": "fas fa-exclamation-triangle"
    },
    "has_hardware": true,
    "device_role": "primary_billiard_relay",
    "last_verified_at": "2026-11-05 14:35:02",
    "pending_intent": {
      "id": 892,
      "event_type": "BILLING_EXPIRED",
      "attempts": 3,
      "status": "RETRY_PENDING"
    },
    "alert": {
      "level": "danger",
      "message": "Lampu Meja Biliar 04 gagal dimatikan otomatis setelah waktu habis! Periksa koneksi Shelly atau matikan saklar fisik.",
      "allow_manual_retry": true
    }
  }
}
```

---

### 3.2 Endpoint 2: Cashier Manual Hardware Action / Retry
- **Route:** `POST /api/stations/{id}/automation/manual-action`
- **Access:** `auth`, `role:kasir,admin,owner`, CSRF protected.
- **Description:** Allows cashier to manually trigger a retry of a failed command or perform an authorized emergency state override.

#### Request Body:
```json
{
  "action": "RETRY_LAST_INTENT",
  "reason": "Kasir memeriksa koneksi WiFi dan mencoba ulang"
}
```

#### Response (HTTP 200 OK):
```json
{
  "status": "success",
  "message": "Perintah retry telah dimasukkan ke dalam antrean eksekusi.",
  "data": {
    "intent_id": 893,
    "status": "PENDING",
    "scheduled_at": "2026-11-05 14:36:10"
  }
}
```

---

### 3.3 Endpoint 3: Admin Automation Intents Monitoring
- **Route:** `GET /admin/hardware/automation/intents`
- **Access:** `auth`, `role:admin,owner`
- **Description:** Administrative data table view filtering intents by status, station, event type, and date range.

#### Query Parameters:
- `station_id` (optional, integer)
- `status` (optional: `PENDING`, `PROCESSING`, `COMPLETED`, `FAILED`, `CANCELLED`, `STALE`)
- `event_type` (optional: `BILLING_EXPIRED`, `BILLING_STARTED`, etc.)
- `page` (integer, default 1)

#### Response (HTTP 200 OK):
```json
{
  "status": "success",
  "data": {
    "current_page": 1,
    "total": 45,
    "per_page": 15,
    "items": [
      {
        "id": 892,
        "transaction_id": 312,
        "station_name": "Meja Biliar 04",
        "event_type": "BILLING_EXPIRED",
        "desired_state": "OFF",
        "status": "COMPLETED",
        "attempts": 1,
        "scheduled_at": "2026-11-05 14:00:00",
        "completed_at": "2026-11-05 14:00:00.642",
        "latency_ms": 142
      }
    ]
  }
}
```

---

### 3.4 Endpoint 4: Admin Execution Logs Telemetry
- **Route:** `GET /admin/hardware/automation/logs`
- **Access:** `auth`, `role:admin,owner`
- **Description:** Granular audit logs showing driver type, command sent, observed output state, latency, and sanitized error messages.

#### Response (HTTP 200 OK):
```json
{
  "status": "success",
  "data": [
    {
      "id": 1402,
      "intent_id": 892,
      "station_name": "Meja Biliar 04",
      "driver_type": "shelly_relay",
      "command_sent": "Switch.Set {\"id\":0,\"on\":false}",
      "execution_status": "SUCCESS",
      "result_code": "VERIFIED_OK",
      "observed_output_state": "false",
      "observed_power_watts": 0.00,
      "latency_ms": 138,
      "executed_at": "2026-11-05 14:00:00"
    }
  ]
}
```

---

## 4. SECURITY & CREDENTIAL SANITIZATION RULES

1. **Kasir Privacy Firewall:** All API endpoints accessible by role `kasir` are strictly filtered through API Resource Transformers. The fields `ip_address`, `mac_address`, `auth_token`, `auth_password`, and raw payload dumps are **strictly stripped**.
2. **Sanitized Error Strings:** If an HTTP 401 or network error occurs containing device tokens in the URL query string, the error parser replaces the credentials with `[REDACTED]` prior to saving in `automation_execution_logs`.
3. **CSRF Protection:** All `POST` endpoints enforce Laravel's default `VerifyCsrfToken` middleware.
