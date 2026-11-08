# G5-A FINAL DESIGN QUALITY GATE REPORT (RECONCILED)

**Project:** Sugeng Rawuh — Integrated Billing & POS Management System  
**Repository:** `sistem_kasir_PS-main`  
**Gate:** G5-A — Final Architecture and Database Contract Review  
**Mode:** DOCUMENTATION FIX ONLY — STRICTLY NO CODING  
**Baseline Test Suite:** **268 Automated Tests Passing (100% Green, 0 Failures)**  
**Production DB Status:** `billing-sugeng-rawuh` (22 tabel) **100% Untouched**  
**Core Approved Business Decision:** **SR-G5-BR-001** (Automatic Billiard Light Shutdown on Prepaid Expiration)  
**Status Target:** **G5-A DESIGN RECONCILED — READY FOR G5-B SIGN-OFF**  
**Date:** 2026-11-05  

---

## 1. HASIL AUDIT DAN KOREKSI KONTRAK 10 DOKUMEN G5-A

Audit rekonsiliasi telah dilakukan terhadap sepuluh dokumen arsitektur G5-A dengan mencocokkan skema fisik database testing (`kasir_ps_test`) melalui `SHOW CREATE TABLE` dan penelusuran kode aktual `app/Http/Controllers/DeviceController.php`, `TransactionController.php`, dan `BillingService.php`.

### Temuan Awal & Koreksi yang Diberlakukan:
1. **Kesesuaian Tipe Kolom & Foreign Key:**
   - Primary Key `transactions` adalah `id_transaksi` bernilai `BIGINT UNSIGNED`, bukan `id` `INT UNSIGNED`.
   - Primary Key `devices`, `physical_devices`, dan `station_device_mappings` semuanya bertipe `BIGINT UNSIGNED`.
   - Pada tabel G3 `station_device_mappings`, status adalah `mapping_status` (`enum('ACTIVE','INACTIVE')`), bukan `is_active`. Role pemetaan adalah `enum('DISPLAY_TV','LIGHT_RELAY')`.
   - **Koreksi:** Seluruh tipe ID dan nama kolom pada proposal `automation_intents` dan `automation_execution_logs` telah disesuaikan menjadi `BIGINT UNSIGNED` dan mereferensikan kolom PK yang benar (`transactions.id_transaksi`, `devices.id`, dll.).
2. **Konflik Retensi Data vs Referential Integrity:**
   - Proposal awal menetapkan retensi `automation_intents` 30 hari dan `automation_execution_logs` gagal 90 hari, tetapi foreign key log menggunakan `ON DELETE CASCADE`. Akibatnya, pembersihan intent pada hari ke-30 akan menghapus log kegagalan secara tidak sengaja.
   - **Koreksi:** Relasi foreign key diubah menjadi `intent_id BIGINT UNSIGNED NULL` dengan `ON DELETE SET NULL`. Log eksekusi dilengkapi kolom snapshot `station_name` dan `station_id` (dengan `ON DELETE SET NULL`), sehingga log kegagalan dapat bertahan selama 90 hari penuh secara deterministik meskipun intent asalnya telah dibersihkan.
3. **Pemberian ON DELETE RESTRICT pada Stasiun:**
   - `fk_auto_intents_station` diubah menjadi `ON DELETE RESTRICT` (selaras dengan foreign key G3 `station_device_mappings`). Hal ini menjamin admin tidak dapat menghapus stasiun yang memiliki histori intent aktif tanpa membersihkan relasi terlebih dahulu.
4. **Rekonsiliasi Latensi Pemadaman (Option B Pre-Scheduled Intent):**
   - Menghubungkan jeda 2 detik dari `billing:worker --sleep=2` dengan worker otomasi 500ms. Diputuskan menggunakan **Option B**: membuat intent `BILLING_EXPIRED` pra-terjadwal (`scheduled_at = expires_at`) sejak sesi prepaid dimulai, sehingga pemadaman dieksekusi tepat pada `expires_at` tanpa menunggu worker G2.
5. **Penemuan Jalur Direct Controller Mutation:**
   - Ditemukan bahwa `DeviceController::stopDevice()` melakukan mutasi langsung ke database tanpa memanggil `BillingService::stopSession()`.
   - Ditetapkan batas integrasi tunggal (*single integration boundary*): Pada G5-B, `DeviceController::stopDevice()` didelegasikan langsung ke `BillingService::stopSession()` untuk menjamin pembuatan intent pemadaman secara konsisten.
6. **Koreksi Query Worker Claim & Bug OR:**
   - Memperbaiki query claim worker agar tidak menggunakan klausa OR ambigu yang berpotensi memicu retry sebelum `next_retry_at` tercapai.
7. **Standarisasi Siklus Retry:**
   - Disamakan di seluruh dokumen: total 4 attempts (1 dispatch awal + 3 retries) dengan backoff +3s, +10s, +30s, lease timeout stale lock 60 detik, dan mandatory read-back sebelum retry.
8. **Kejujuran Status Fisik (Truthfulness):**
   - Mode Mock/Test menggunakan badge `SIMULATED_ON`/`OFF`. Mode Real menggunakan badge `RELAY_ON_VERIFIED`/`RELAY_OFF_VERIFIED` yang memverifikasi output kontak relay, bukan filamen bohlam lampu. Samsung TV power commands tetap dinonaktifkan.
9. **Klasifikasi Keputusan & Sumber Approval:**
   - Seluruh keputusan teknis diklasifikasikan dengan tegas disertai sumber persetujuannya.

---

## 2. SKEMA DATABASE FINAL YANG KOMPATIBEL

Skema proposal final di bawah ini 100% kompatibel dengan tipe data aktual pada `kasir_ps_test` dan siap dibuatkan filenya di G5-B (tanpa menjalankan migrasi pada G5-A):

```sql
-- TABEL 1: Outbox Queue
CREATE TABLE `automation_intents` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `transaction_id` BIGINT UNSIGNED NULL,
  `station_id` BIGINT UNSIGNED NOT NULL,
  `station_name` VARCHAR(255) NULL,
  `physical_device_id` BIGINT UNSIGNED NULL,
  `station_device_mapping_id` BIGINT UNSIGNED NULL,
  `session_generation` INT UNSIGNED NOT NULL DEFAULT 1,
  `event_type` VARCHAR(40) NOT NULL,
  `desired_state` VARCHAR(20) NOT NULL,
  `target_channel` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `idempotency_key` VARCHAR(140) NOT NULL,
  `status` ENUM('PENDING', 'PROCESSING', 'COMPLETED', 'FAILED', 'RETRY_PENDING', 'CANCELLED', 'STALE', 'SKIPPED') NOT NULL DEFAULT 'PENDING',
  `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `max_attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 4,
  `scheduled_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `next_retry_at` TIMESTAMP NULL DEFAULT NULL,
  `locked_at` TIMESTAMP NULL DEFAULT NULL,
  `locked_by` VARCHAR(60) NULL DEFAULT NULL,
  `completed_at` TIMESTAMP NULL DEFAULT NULL,
  `last_error_category` VARCHAR(50) NULL DEFAULT NULL,
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

-- TABEL 2: Audit Logs
CREATE TABLE `automation_execution_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `intent_id` BIGINT UNSIGNED NULL,
  `transaction_id` BIGINT UNSIGNED NULL,
  `station_id` BIGINT UNSIGNED NULL,
  `station_name` VARCHAR(255) NOT NULL,
  `physical_device_id` BIGINT UNSIGNED NULL,
  `driver_type` VARCHAR(40) NOT NULL,
  `command_sent` VARCHAR(60) NOT NULL,
  `attempt_number` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `execution_status` ENUM('SUCCESS', 'FAILED', 'SKIPPED', 'RETRY_SCHEDULED') NOT NULL,
  `result_code` VARCHAR(50) NOT NULL,
  `error_category` VARCHAR(50) NULL DEFAULT NULL,
  `error_details` TEXT NULL DEFAULT NULL,
  `observed_output_state` VARCHAR(20) NULL DEFAULT NULL,
  `observed_power_watts` DECIMAL(8,2) NULL DEFAULT NULL,
  `latency_ms` INT UNSIGNED NULL DEFAULT NULL,
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

-- ALTERASI TABEL EKSISTING:
ALTER TABLE `devices` 
ADD COLUMN `session_generation` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `status`,
ADD COLUMN `last_hardware_state` VARCHAR(30) NOT NULL DEFAULT 'STATE_UNKNOWN' AFTER `session_generation`,
ADD COLUMN `last_hardware_verified_at` TIMESTAMP NULL DEFAULT NULL AFTER `last_hardware_state`;

ALTER TABLE `transactions`
ADD COLUMN `session_generation` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `billing_status`;
```

---

## 3. ALGORITMA DAN KONTRAK EXPIRY FINAL (OPTION B)

1. **Inisialisasi Prepaid (`startSession`):**
   - Transaksi prepaid dibuat di MySQL dengan `expires_at = 15:00:00`.
   - Atomik di dalam transaksi yang sama:
     - Dibuat intent 1: `event_type = 'BILLING_STARTED'`, `desired_state = 'ON'`, `scheduled_at = NOW()`.
     - Dibuat intent 2: `event_type = 'BILLING_EXPIRED'`, `desired_state = 'OFF'`, `scheduled_at = expires_at` (15:00:00).
2. **Saat Jam Mencapai `expires_at`:**
   - Worker otomasi (berjalan setiap 500ms) memindai `automation_intents` dengan query `WHERE scheduled_at <= NOW() AND status = 'PENDING'`.
   - Worker langsung mengklaim intent dan mematikan relay tanpa menunggu siklus `billing:worker` G2 (2 detik).
   - `billing:worker` tetap menjalankan rekonsiliasi status bisnis G2 (`billing_status = EXPIRED_PENDING_ACTION`, `device->status = 'Tersedia'`) secara paralel tanpa konflik.
3. **Saat Terjadi Perpanjangan (`extendSession`):**
   - Generation dinaikkan: `devices.session_generation += 1`.
   - Intent pemadaman pra-terjadwal yang lama (`gen = G`) diubah statusnya menjadi `CANCELLED`.
   - Dibuat intent pemadaman pra-terjadwal baru (`gen = G+1`) dengan `scheduled_at = new_expires_at`.
4. **Saat Terjadi Penghentian Lebih Awal (`stopSession` / `stopDevice`):**
   - Generation dinaikkan: `devices.session_generation += 1`.
   - Intent pemadaman pra-terjadwal yang lama diubah menjadi `CANCELLED`.
   - Dibuat intent pemadaman seketika `BILLING_STOPPED` dengan `scheduled_at = NOW()`.
5. **Target Latensi Operasional:**
   - Target pemadaman **500ms – 1500ms** didefinisikan sebagai **acceptance target yang diukur pada pengujian G5-B/G5-C**, bukan jaminan matematis mutlak.

---

## 4. WORKER LOCKING & RETRY CONTRACT

1. **Pola Two-Phase Non-Blocking (Tanpa Row Lock Selama I/O):**
   - **Phase 1 (Klaim < 5ms):** Worker mengklaim 10 intent via `UPDATE ... FOR UPDATE SKIP LOCKED` dengan menstempel `locked_by` dan `locked_at = NOW()`. Transaksi database langsung di-commit.
   - **Phase 2 (Eksekusi Jaringan):** Panggilan soket HTTP RPC ke Shelly dilakukan di luar transaksi database. Tidak ada tabel atau baris yang terkunci selama I/O jaringan.
   - **Phase 3 (Penyelesaian < 5ms):** Hasil dicatat ke `automation_execution_logs` dan status intent diubah ke `COMPLETED`, `FAILED`, atau `RETRY_PENDING`.
2. **Kontrak Retry:**
   - Total Attempts: 4 (1 dispatch awal + 3 retries).
   - Interval Jeda: +3s, +10s, +30s.
   - Stale Lease Timeout: 60 detik (`locked_at < NOW() - INTERVAL 60 SECOND`).
   - Mandatory Read-Back: Sebelum mengirim paket retry, worker selalu menjalankan `Switch.GetStatus` untuk memastikan apakah perintah sebelumnya sebenarnya sudah berhasil beralih status pada perangkat.

---

## 5. JALUR MUTASI BILLING YANG DIINTEGRASIKAN

Untuk menjamin tidak ada mutasi status billing yang lolos dari pembuatan intent otomasi, integrasi pada G5-B ditetapkan melalui **Single Integration Boundary**:

1. **`BillingService::startSession()`** -> Menerbitkan intent `BILLING_STARTED` (ON) dan pra-terjadwal `BILLING_EXPIRED` (OFF).
2. **`BillingService::extendSession()`** -> Membatalkan intent OFF lama dan menerbitkan intent OFF pra-terjadwal baru.
3. **`BillingService::stopSession()`** -> Membatalkan intent OFF pra-terjadwal dan menerbitkan intent `BILLING_STOPPED` (OFF segera).
4. **`DeviceController::stopDevice()` (Refactor)** -> Didelegasikan untuk memanggil `BillingService::stopSession($transactionId, 'cashier_stop')` sehingga tidak ada duplikasi logika mutasi model.
5. **`TransactionController::store()`, `update()`, `bayar()`** -> Dihubungkan ke service otomasi atau dipastikan memanggil alur yang mengamankan state relay stasiun.

---

## 6. DAFTAR KEPUTUSAN & SUMBER APPROVAL FINAL

| Decision ID | Judul | Klasifikasi | Sumber Persetujuan | Rangkuman Rationale |
|---|---|---|---|---|
| **SR-G5-BR-001** | Automatic Billiard Light Shutdown on Prepaid Expiry | **APPROVED** | User Decision (SR-G5-BR-001) | Lampu biliar langsung mati otomatis saat prepaid habis tanpa grace period dan tanpa konfirmasi kasir. Final & terkunci. |
| **SR-G5-DR-002** | Transactional Outbox Pattern Architecture | **APPROVED (DIRECTION)** | User Review Prompt G5-A | Pola Outbox disetujui sebagai dasar rancangan untuk meniadakan Redis dan menjamin konsistensi ACID. |
| **SR-G5-DR-003** | Session Generation Fencing Strategy | **PROPOSED** | Engineering Proposal | Token generasi pada station dan transaksi untuk mencegah balapan perpanjangan vs kedaluwarsa. |
| **SR-G5-DR-004** | Restricted Policy on Samsung Smart TV Power | **PROPOSED** | Safety Protocol | Perintah daya TV otomatis dinonaktifkan sampai terverifikasi fisik di G4-B2. |
| **SR-G5-DR-005** | Strict Decoupling of Hardware from Financials | **APPROVED** | User Mandatory Rule #9 | Gangguan IoT tidak pernah membatalkan atau mengubah kalkulasi transaksi billing kasir. |
| **SR-G5-DR-006** | Physical Verification on Live Hardware | **DEFERRED UNTIL G4-B2** | Gate Protocol | Pengujian kontak fisik relay dan protokol TV di lokasi fisik ditunda sampai Gate G4-B2. |
| **SR-G5-DR-007** | Audible Warning Chime Prior to Expiry | **NEEDS USER APPROVAL** | Pending User Decision | Pertanyaan terbuka: Chime audio browser kasir 1m sebelum habis. *(Rekomendasi: Opsi C - Tanpa chime, cukup visual countdown).* |
| **SR-G5-DR-008** | Batch Reconciliation Schedule & Frequency | **PROPOSED** | Engineering Proposal | Proposal: Menjalankan `billing:reconcile` setiap hari pukul 08:30 WIB dan saat boot worker. |
| **SR-G5-DR-009** | Cashier Manual Override Guardrail | **PROPOSED** | Security Guardrail | Kasir hanya boleh retry OFF pada sesi kedaluwarsa atau retry ON pada sesi aktif. Dilarang menyalakan lampu tanpa sesi aktif. |

---

## 7. ANALISIS RISIKO TERSISA & MITIGASI

1. **Jaringan Wi-Fi Lokal Mengalami Jitter:**  
   *Mitigasi:* Driver timeout dibatasi ketat 2000ms. Latensi operasional 500–1500ms adalah target terukur, didukung bounded retry +3s, +10s, +30s.
2. **Kasir Mengabaikan Peringatan Lampu Gagal Padam:**  
   *Mitigasi:* Banner peringatan darurat berwarna merah menetap di bagian atas layar POS dengan nama meja eksplisit dan instruksi mematikan saklar fisik manual.
3. **Kerusakan Filamen Lampu Biliar (Relay ON tetapi Bohlam Putus):**  
   *Mitigasi:* Telemetri `observed_power_watts` pada `automation_execution_logs` mencatat daya riil. Jika relay output `true` namun konsumsi daya mendekati 0 Watt, sistem dapat menandai indikasi bohlam rusak pada log diagnostik admin.

---

## 8. REKOMENDASI GO/NO-GO UNTUK GATE G5-B

Berdasarkan hasil verifikasi skema, audit jalur kode, penuntasan konflik retensi, dan keselarasan penuh terhadap keputusan bisnis **SR-G5-BR-001**:

- [x] Kontrak skema database 100% selaras dengan tipe data aktual di `kasir_ps_test`.
- [x] Konflik retensi data 30 hari vs 90 hari teratasi deterministik via `ON DELETE SET NULL`.
- [x] Latensi expiry terselesaikan melalui Option B (Pre-Scheduled Outbox Intent).
- [x] Batas integrasi controller (`stopDevice`) telah dirancang tanpa menduplikasi logika G2.
- [x] Seluruh keputusan memiliki sumber persetujuan dan klasifikasi yang akurat.
- [x] Baseline **268 automated tests tetap 100% lulus (green)**.
- [x] Tidak ada modifikasi kode aplikasi, migrasi database, atau commit yang dijalankan.

### **REKOMENDASI:** **GO UNTUK GATE G5-B**
### **PERNYATAAN RESMI:**
## `G5-A DESIGN RECONCILED — READY FOR G5-B SIGN-OFF`

---
*Proses berhenti di sini sesuai instruksi. Tidak ada coding, migrasi database, commit, push, atau deployment yang dilakukan sampai persetujuan tanda tangan G5-B diberikan oleh pengguna.*
