# G5-A DATABASE DESIGN: AUTOMATION INTENTS & AUDIT LOGS (RECONCILED)

**Document ID:** SR-G5-DB-001  
**Project:** Sugeng Rawuh — Integrated Billing & POS Management System  
**Gate:** G5-A — Billing-to-Hardware Automation Architecture & Design  
**Status:** RECONCILED / PROPOSAL FOR REVIEW  
**Date:** 2026-11-05  
**Author:** AI Systems Architect & Core Team  

---

## 1. PURPOSE & ARCHITECTURAL PRINCIPLES

This document defines the relational database schema required to support Gate G5 hardware automation under the **Transactional Outbox Pattern**, strictly reconciled against actual database column types, foreign key constraints, and retention policies in `kasir_ps_test`.

### Core Database Principles:
1. **Single Source of Truth:** Expiration is derived from `transactions.expires_at` on the MySQL server.
2. **Zero In-Flight Loss:** Automation intents are persisted atomically in the same database transaction that updates billing session state.
3. **Exact Type & Referential Matching:** Foreign keys strictly match actual primary key types:
   - `devices.id` is `BIGINT UNSIGNED`.
   - `transactions.id_transaksi` is `BIGINT UNSIGNED`.
   - `physical_devices.id` is `BIGINT UNSIGNED`.
   - `station_device_mappings.id` is `BIGINT UNSIGNED`.
4. **Referential Integrity & History Protection:**
   - Using `ON DELETE RESTRICT` for station deletion ensures historical billing and automation intents cannot be silently wiped.
   - Using `ON DELETE SET NULL` on `automation_execution_logs.intent_id` decouples log retention from intent retention, resolving the 30-day intent vs. 90-day failed log conflict.
5. **Credential Isolation:** Under no circumstances may passwords, pairing tokens, PINs, or device credentials ever be logged or stored in `automation_intents` or `automation_execution_logs`.

---

## 2. PROPOSED SCHEMA SPECIFICATIONS

### 2.1 Table 1: `automation_intents` (Outbox Queue)
Represents discrete, desired-state hardware actions created by billing lifecycle events.

```sql
CREATE TABLE `automation_intents` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `transaction_id` BIGINT UNSIGNED NULL COMMENT 'FK to transactions.id_transaksi (nullable for station-level reconcile)',
  `station_id` BIGINT UNSIGNED NOT NULL COMMENT 'FK to devices.id (station table)',
  `station_name` VARCHAR(255) NULL COMMENT 'Snapshot of station name for audit preservation',
  `physical_device_id` BIGINT UNSIGNED NULL COMMENT 'FK to physical_devices.id (resolved mapping)',
  `station_device_mapping_id` BIGINT UNSIGNED NULL COMMENT 'FK to station_device_mappings.id',
  `session_generation` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Fencing token captured at intent creation',
  `event_type` VARCHAR(40) NOT NULL COMMENT 'e.g. BILLING_STARTED, BILLING_EXPIRED, BILLING_STOPPED, BILLING_EXTENDED, BILLING_RECOVERED',
  `desired_state` VARCHAR(20) NOT NULL COMMENT 'ON, OFF, STANDBY',
  `target_channel` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Relay channel (e.g. 0 for Shelly 1PM)',
  `idempotency_key` VARCHAR(140) NOT NULL COMMENT 'Unique deduplication key',
  `status` ENUM('PENDING', 'PROCESSING', 'COMPLETED', 'FAILED', 'RETRY_PENDING', 'CANCELLED', 'STALE', 'SKIPPED') NOT NULL DEFAULT 'PENDING',
  `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Current attempt count (1 initial + up to 3 retries)',
  `max_attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 4 COMMENT 'Total attempts allowed (1 initial + 3 retries = 4)',
  `scheduled_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Trigger deadline (e.g. expires_at or now)',
  `next_retry_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'Strict backoff deadline for RETRY_PENDING',
  `locked_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'Worker claim timestamp',
  `locked_by` VARCHAR(60) NULL DEFAULT NULL COMMENT 'Worker PID or machine hostname',
  `completed_at` TIMESTAMP NULL DEFAULT NULL,
  `last_error_category` VARCHAR(50) NULL DEFAULT NULL COMMENT 'OFFLINE, TIMEOUT, MISMATCH, CONFIG_ERROR',
  `last_error_message` VARCHAR(255) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_auto_intents_idempotency` (`idempotency_key`),
  KEY `idx_auto_intents_poll_pending` (`status`, `scheduled_at`),
  KEY `idx_auto_intents_poll_retry` (`status`, `next_retry_at`),
  KEY `idx_auto_intents_station_gen` (`station_id`, `session_generation`),
  KEY `idx_auto_intents_tx` (`transaction_id`),
  CONSTRAINT `fk_auto_intents_station` FOREIGN KEY (`station_id`) REFERENCES `devices` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_auto_intents_transaction` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id_transaksi`) ON DELETE SET NULL,
  CONSTRAINT `fk_auto_intents_physical_device` FOREIGN KEY (`physical_device_id`) REFERENCES `physical_devices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_auto_intents_mapping` FOREIGN KEY (`station_device_mapping_id`) REFERENCES `station_device_mappings` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

### 2.2 Table 2: `automation_execution_logs` (Immutable Audit Trail)
Stores detailed diagnostic telemetry for every physical dispatch attempt, read-back verification, and error response.

```sql
CREATE TABLE `automation_execution_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `intent_id` BIGINT UNSIGNED NULL COMMENT 'FK to automation_intents.id (NULL if intent pruned after 30d)',
  `transaction_id` BIGINT UNSIGNED NULL COMMENT 'FK to transactions.id_transaksi',
  `station_id` BIGINT UNSIGNED NULL COMMENT 'FK to devices.id',
  `station_name` VARCHAR(255) NOT NULL COMMENT 'Preserved snapshot of station name',
  `physical_device_id` BIGINT UNSIGNED NULL COMMENT 'FK to physical_devices.id',
  `driver_type` VARCHAR(40) NOT NULL COMMENT 'shelly_relay, samsung_tv, mock_relay',
  `command_sent` VARCHAR(60) NOT NULL COMMENT 'Switch.Set, Switch.GetStatus, KEY_POWER',
  `attempt_number` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `execution_status` ENUM('SUCCESS', 'FAILED', 'SKIPPED', 'RETRY_SCHEDULED') NOT NULL,
  `result_code` VARCHAR(50) NOT NULL COMMENT 'VERIFIED_OK, TIMEOUT, CONNECTION_REFUSED, READBACK_MISMATCH, SKIPPED_UNVERIFIED',
  `error_category` VARCHAR(50) NULL DEFAULT NULL,
  `error_details` TEXT NULL DEFAULT NULL COMMENT 'Sanitized error snippet (STRICTLY NO credentials)',
  `observed_output_state` VARCHAR(20) NULL DEFAULT NULL COMMENT 'true, false, unknown',
  `observed_power_watts` DECIMAL(8,2) NULL DEFAULT NULL COMMENT 'Shelly apower telemetry in watts',
  `latency_ms` INT UNSIGNED NULL DEFAULT NULL COMMENT 'Round-trip duration in milliseconds',
  `executed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_auto_logs_intent` (`intent_id`),
  KEY `idx_auto_logs_station_date` (`station_id`, `executed_at`),
  KEY `idx_auto_logs_status_date` (`execution_status`, `executed_at`),
  CONSTRAINT `fk_auto_logs_intent` FOREIGN KEY (`intent_id`) REFERENCES `automation_intents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_auto_logs_station` FOREIGN KEY (`station_id`) REFERENCES `devices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_auto_logs_transaction` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id_transaksi`) ON DELETE SET NULL,
  CONSTRAINT `fk_auto_logs_physical_device` FOREIGN KEY (`physical_device_id`) REFERENCES `physical_devices` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

### 2.3 Proposed Alterations to Existing Tables

#### Alteration to `devices` (Station Model)
```sql
ALTER TABLE `devices` 
ADD COLUMN `session_generation` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `status`,
ADD COLUMN `last_hardware_state` VARCHAR(30) NOT NULL DEFAULT 'STATE_UNKNOWN' AFTER `session_generation`,
ADD COLUMN `last_hardware_verified_at` TIMESTAMP NULL DEFAULT NULL AFTER `last_hardware_state`;
```

#### Alteration to `transactions` (Session Model)
```sql
ALTER TABLE `transactions`
ADD COLUMN `session_generation` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `billing_status`;
```

---

## 3. WORKER CLAIMING PATTERN (TWO-PHASE NON-BLOCKING)

To guarantee that **no MySQL row locks are held during IoT network communication**:

### Phase 1: Rapid Claim Transaction (< 5ms)
```sql
-- Step 1.1: Atomic claim using SKIP LOCKED in MySQL 8.0+
UPDATE `automation_intents`
SET `status` = 'PROCESSING',
    `locked_at` = NOW(),
    `locked_by` = :worker_id,
    `attempts` = `attempts` + 1
WHERE `id` IN (
    SELECT `id` FROM (
        SELECT `id` FROM `automation_intents`
        WHERE (`status` = 'PENDING' AND `scheduled_at` <= NOW())
           OR (`status` = 'RETRY_PENDING' AND `next_retry_at` <= NOW())
        ORDER BY `scheduled_at` ASC, `id` ASC
        LIMIT 10
        FOR UPDATE SKIP LOCKED
    ) AS `claimed_batch`
);
```

### Phase 2: Driver Execution Outside Database Transaction
- Worker retrieves the claimed records (`SELECT * FROM automation_intents WHERE locked_by = :worker_id AND status = 'PROCESSING'`).
- The database transaction is committed immediately. **Zero locks are held.**
- Worker performs fencing check, driver HTTP RPC (`Switch.Set`), and read-back (`Switch.GetStatus`).

### Phase 3: Settlement Transaction (< 5ms)
- Worker writes to `automation_execution_logs`.
- Worker updates `automation_intents` status to `COMPLETED`, `FAILED`, or `RETRY_PENDING` (with `next_retry_at`).

### Fallback Claim Query for MariaDB / Older MySQL:
```sql
-- Explicitly separates deadlines for PENDING vs RETRY_PENDING to prevent early retries!
UPDATE `automation_intents`
SET `status` = 'PROCESSING',
    `locked_at` = NOW(),
    `locked_by` = :worker_id,
    `attempts` = `attempts` + 1
WHERE (
    (`status` = 'PENDING' AND `scheduled_at` <= NOW())
    OR
    (`status` = 'RETRY_PENDING' AND `next_retry_at` <= NOW())
)
LIMIT 10;
```

---

## 4. IDEMPOTENCY KEY CONVENTIONS

Deduplication keys guarantee that billing events cannot create duplicate pending intents:
- **Prepaid Start:** `intent_tx_{txId}_gen{gen}_start`
- **Prepaid Scheduled Expiry:** `intent_tx_{txId}_gen{gen}_expire`
- **Prepaid Extend:** `intent_tx_{txId}_gen{gen}_extend`
- **Session Stop (Manual Early Stop):** `intent_tx_{txId}_gen{gen}_stop`
- **Lost Time Start:** `intent_tx_{txId}_gen{gen}_lt_start`
- **Lost Time Stop:** `intent_tx_{txId}_gen{gen}_lt_stop`
- **Reconciliation:** `intent_station_{stationId}_reconcile_{timestampMinute}`

---

## 5. DETERMINISTIC PRUNING & RETENTION POLICY

Resolving the retention conflict between 30-day intents and 90-day failed logs:

### Pruning Sequence (`php artisan billing:automation-prune`):
1. **Prune Successful Execution Logs (30 Days):**
   ```sql
   DELETE FROM `automation_execution_logs`
   WHERE `execution_status` = 'SUCCESS'
     AND `executed_at` < DATE_SUB(NOW(), INTERVAL 30 DAY);
   ```
2. **Prune Failed Execution Logs (90 Days):**
   ```sql
   DELETE FROM `automation_execution_logs`
   WHERE `execution_status` IN ('FAILED', 'RETRY_SCHEDULED')
     AND `executed_at` < DATE_SUB(NOW(), INTERVAL 90 DAY);
   ```
3. **Prune Inactive / Completed Intents (30 Days):**
   ```sql
   -- Because fk_auto_logs_intent has ON DELETE SET NULL, 
   -- deleting intents does NOT delete the 90-day failed logs!
   DELETE FROM `automation_intents`
   WHERE `status` IN ('COMPLETED', 'CANCELLED', 'STALE', 'SKIPPED')
     AND `updated_at` < DATE_SUB(NOW(), INTERVAL 30 DAY);
   ```
4. **Prune Failed Intents (90 Days):**
   ```sql
   DELETE FROM `automation_intents`
   WHERE `status` = 'FAILED'
     AND `updated_at` < DATE_SUB(NOW(), INTERVAL 90 DAY);
   ```
