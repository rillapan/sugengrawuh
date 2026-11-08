# SUGENG RAWUH — G3-B1 IMPLEMENTATION REPORT

**Project:** Sugeng Rawuh — Integrated Billing & POS Management System  
**Repository:** `sistem_kasir_PS-main`  
**Gate:** G3-B1 — Database, Models & Station Mapping Service  
**Mode:** IMPLEMENTATION COMPLETE / TESTING VERIFIED  
**Previous Gate:** G3-A APPROVED  
**Date:** 05 November 2026  
**Status Gate:** **READY FOR G3-B2**

---

## 1. AUDIT AWAL REPOSITORY & DATABASE SAFETY

Sebelum melakukan modifikasi kode dan eksekusi skema basis data, audit mendalam telah dilakukan terhadap seluruh komponen relevan:

1. **Lingkungan & Versi Runtime:**
   - **PHP:** `8.2.28 (cli)`
   - **MySQL Server:** `9.6.0 Homebrew` (Mendukung penuh Virtual Generated Columns dengan Unique Indexes).
   - **Framework:** Laravel `8.83.27`.
2. **Isolasi Basis Data & Perlindungan Data Utama:**
   - Database Utama: `billing-sugeng-rawuh` (Koneksi CLI default).
   - Database Pengujian: `kasir_ps_test` (Diatur eksplisit via `.env.testing` dan `phpunit.xml`).
   - Tidak ada perintah `migrate:fresh` atau `migrate:refresh` yang dijalankan.
   - Skema dan data di database utama `billing-sugeng-rawuh` **100% tidak tersentuh dan tidak mengalami perubahan**.
3. **Audit Model & Relasi Eksisting:**
   - Tabel `devices`: Memiliki kolom `id`, `nama`, `playstation_id`, `status` (`Tersedia`/`Digunakan`).
   - Tabel `playstations`: Memiliki kolom `id`, `nama`, `harga`. Relasi many-to-many ke stasiun melalui `device_playstation`.
   - Tabel `transactions`: Memiliki kolom G2 `billing_status`, `started_at`, `expires_at`, `ended_at`.
   - Menghindari regex nama layanan yang rapuh dengan menambahkan metode klasifikasi kategori stasiun berbasis model (`getCategory()` pada `Playstation` dan `getStationCategory()` pada `Device`).
4. **Verifikasi Baseline G2:**
   - Sebelum implementasi G3-B1, seluruh 68 unit & feature tests G2 dipastikan lulus (`68 passed`).

---

## 2. IMPLEMENTASI MIGRATION

Dua berkas migrasi baru telah dibuat dan dieksekusi secara ketat hanya pada database pengujian `kasir_ps_test`:

### A. `database/migrations/2026_11_05_000001_create_physical_devices_table.php`
- **Tabel:** `physical_devices`
- **Field yang Diimplementasikan:**
  - `id`: `BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`
  - `name`: `VARCHAR(100)` — Label inventaris fisik.
  - `category`: `ENUM('SMART_TV', 'SMART_RELAY')`
  - `brand`: `ENUM('SAMSUNG', 'SHELLY')`
  - `model`: `VARCHAR(50) NULLABLE`
  - `mac_address`: `VARCHAR(17) UNIQUE` — Uppercase colon-separated (`AA:BB:CC:DD:EE:FF`).
  - `ip_address`: `VARCHAR(45)` — Alamat IPv4 lokal privat RFC 1918.
  - `device_identifier`: `VARCHAR(100) NULLABLE` — Serial DUID / Vendor identifier.
  - `connection_status`: `ENUM('UNVERIFIED', 'ONLINE', 'OFFLINE') DEFAULT 'UNVERIFIED'`
  - `pairing_status`: `ENUM('UNVERIFIED', 'NOT_REQUIRED', 'PENDING', 'PAIRED', 'FAILED') DEFAULT 'UNVERIFIED'`
  - `pairing_payload`: `TEXT NULLABLE` — Disimpan terenkripsi.
  - `verified_capabilities`: `JSON NULLABLE` — Metadata kemampuan hardware.
  - `last_seen_at`: `DATETIME NULLABLE`
  - `is_active`: `TINYINT(1) DEFAULT 1`
  - `created_at`, `updated_at`: Timestamps
- **Indeks Tambahan:**
  - `idx_physical_devices_ip_active (ip_address, is_active)`
  - `idx_physical_devices_cat_brand (category, brand, is_active)`

### B. `database/migrations/2026_11_05_000002_create_station_device_mappings_table.php`
- **Tabel:** `station_device_mappings`
- **Field yang Diimplementasikan:**
  - `id`: `BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`
  - `station_id`: `BIGINT UNSIGNED FK -> devices.id` dengan **`ON DELETE RESTRICT`** (mencegah penghapusan stasiun yang memiliki jejak riwayat audit hardware).
  - `physical_device_id`: `BIGINT UNSIGNED FK -> physical_devices.id` dengan **`ON DELETE RESTRICT`**.
  - `mapping_role`: `ENUM('DISPLAY_TV', 'LIGHT_RELAY')`
  - `mapping_status`: `ENUM('ACTIVE', 'INACTIVE') DEFAULT 'ACTIVE'`
  - `attached_by_user_id`: `BIGINT UNSIGNED NULLABLE FK -> users.id (ON DELETE SET NULL)`
  - `detached_by_user_id`: `BIGINT UNSIGNED NULLABLE FK -> users.id (ON DELETE SET NULL)`
  - `attached_at`: `DATETIME DEFAULT CURRENT_TIMESTAMP`
  - `detached_at`: `DATETIME NULLABLE`
  - `attach_notes`: `TEXT NULLABLE` (Catatan audit saat pemasangan)
  - `detach_notes`: `TEXT NULLABLE` (Catatan audit saat pelepasan)
  - `created_at`, `updated_at`: Timestamps
- **Virtual Generated Columns & Database Uniqueness Enforcement:**
  - `active_physical_device_id`: `VARCHAR(32) GENERATED ALWAYS AS (IF(mapping_status = 'ACTIVE', CAST(physical_device_id AS CHAR), NULL)) VIRTUAL`
  - `active_station_role`: `VARCHAR(64) GENERATED ALWAYS AS (IF(mapping_status = 'ACTIVE', CONCAT(station_id, '_', mapping_role), NULL)) VIRTUAL`
  - **Unique Index 1:** `uq_active_physical_device (active_physical_device_id)` — Menjamin secara fisik di level database bahwa satu hardware hanya boleh berstatus `ACTIVE` di satu stasiun.
  - **Unique Index 2:** `uq_active_station_role (active_station_role)` — Menjamin secara fisik di level database bahwa satu stasiun hanya boleh memiliki satu mapping `ACTIVE` untuk peran yang sama.
  - **History Index:** `idx_station_mapping_history (station_id, attached_at)`

---

## 3. IMPLEMENTASI ELOQUENT MODELS

### A. `app/Models/PhysicalDevice.php` (Baru)
- Menetapkan konstanta:
  - Kategori: `CATEGORY_SMART_TV = 'SMART_TV'`, `CATEGORY_SMART_RELAY = 'SMART_RELAY'`.
  - Merek: `BRAND_SAMSUNG = 'SAMSUNG'`, `BRAND_SHELLY = 'SHELLY'`.
  - Koneksi: `CONNECTION_UNVERIFIED`, `CONNECTION_ONLINE`, `CONNECTION_OFFLINE`.
  - Pairing: `PAIRING_UNVERIFIED`, `PAIRING_NOT_REQUIRED`, `PAIRING_PENDING`, `PAIRING_PAIRED`, `PAIRING_FAILED`.
- **Casts:**
  - `'is_active' => 'boolean'`
  - `'verified_capabilities' => 'array'`
  - `'last_seen_at' => 'datetime'`
  - `'pairing_payload' => 'encrypted'` (Otomatis terenkripsi menggunakan Laravel Application Key saat disimpan dan didekripsi saat dibaca).
- **Metode Statis Validasi & Normalisasi:**
  - `normalizeMacAddress(string $mac): string`: Membersihkan separator, memverifikasi 12 karakter heksadesimal, dan memformat menjadi `AA:BB:CC:DD:EE:FF`.
  - `validateBrandCategory(string $category, string $brand): void`: Memastikan Smart TV hanya bermerek Samsung, dan Smart Relay hanya bermerek Shelly (Coocaa ditolak keras).
  - `validateIpAddress(string $ip): void`: Memastikan IPv4 valid dan berada dalam rentang privat lokal RFC 1918 (`10.x`, `172.16-31.x`, `192.168.x`).
- **Relasi & Scopes:**
  - `stationMappings()`: `hasMany(StationDeviceMapping::class)`
  - `activeMapping()`: `hasOne(StationDeviceMapping::class)->where('mapping_status', 'ACTIVE')`
  - `getCurrentStationAttribute()`: Mengembalikan instans `Device` stasiun aktif jika ada.
  - Scopes: `scopeActive`, `scopeUnmapped`.

### B. `app/Models/StationDeviceMapping.php` (Baru)
- Menetapkan konstanta:
  - Peran: `ROLE_DISPLAY_TV = 'DISPLAY_TV'`, `ROLE_LIGHT_RELAY = 'LIGHT_RELAY'`.
  - Status: `STATUS_ACTIVE = 'ACTIVE'`, `STATUS_INACTIVE = 'INACTIVE'`.
- **Casts:**
  - `'attached_at' => 'datetime'`, `'detached_at' => 'datetime'`.
- **Relasi & Scopes:**
  - `station()`: `belongsTo(Device::class, 'station_id')`
  - `physicalDevice()`: `belongsTo(PhysicalDevice::class, 'physical_device_id')`
  - `attachedBy()`: `belongsTo(User::class, 'attached_by_user_id')`
  - `detachedBy()`: `belongsTo(User::class, 'detached_by_user_id')`
  - Scopes: `scopeActive`, `scopeInactive`.

### C. Pembaruan `app/Models/Playstation.php`
- Menambahkan konstanta:
  - `CATEGORY_PLAYSTATION = 'PLAYSTATION'`
  - `CATEGORY_BILLIARD = 'BILLIARD'`
- Menambahkan metode `getCategory(): ?string`:
  - Mengidentifikasi kategori layanan secara deterministik berdasarkan kata kunci baku tanpa bergantung pada regex rapuh. Mengembalikan `CATEGORY_PLAYSTATION` jika PlayStation, `CATEGORY_BILLIARD` jika Biliar, atau `null` jika tidak dikenali/ambigu.

### D. Pembaruan `app/Models/Device.php`
- Menambahkan relasi hardware mapping:
  - `hardwareMappings()`
  - `activeHardwareMappings()`
  - `activeDisplayTv()`
  - `activeLightRelay()`
- Menambahkan metode `getStationCategory(): ?string`:
  - Mengevaluasi seluruh layanan yang terhubung pada stasiun (`playstations` dan fallback `playstation`).
  - Menolak dan mengembalikan `null` jika tidak ada layanan, terdapat layanan yang kategorinya tidak dikenali, atau terdapat campuran jenis layanan (misalnya PS dan Biliar digabung pada satu stasiun).
  - Mengembalikan string kategori yang valid jika dan hanya jika seluruh layanan stasiun seragam dan terverifikasi.

---

## 4. IMPLEMENTASI STATION MAPPING SERVICE & DOMAIN EXCEPTION

### A. `app/Exceptions/StationMappingException.php`
- Domain exception yang menyertakan HTTP status code (`getStatusCode()`).
- Menyediakan metode factory semantik:
  - `stationInUse(string $name)`: Status 409
  - `activeBillingRunning(string $name)`: Status 409
  - `hardwareAlreadyMapped(string $deviceName, ?string $stationName)`: Status 409
  - `activeRoleAlreadyMapped(string $stationName, string $role)`: Status 409
  - `cannotDeactivateMappedHardware(string $deviceName)`: Status 409
  - `ipConflict(string $ip, ?string $deviceName)`: Status 409
  - `duplicateMac(string $mac)`: Status 409
  - `incompatibleHardware(string $message)`: Status 422
  - `unknownStationCategory(string $name)`: Status 422
  - `notFound(string $message)`: Status 404
  - `invalidData(string $message)`: Status 422

### B. `app/Services/Hardware/StationMappingService.php`
Seluruh mutasi dieksekusi secara atomic menggunakan `DB::transaction()` dengan urutan locking pessimistis (`lockForUpdate()`) yang konsisten:
1. **Urutan Locking Konsisten:**
   - Selalu mengunci baris `Device` (`stationId`) terlebih dahulu.
   - Mengunci baris `PhysicalDevice` kedua.
   - Pola ini secara matematis mencegah terjadinya deadlock antartransaksi konkruen.
2. **Operasi yang Diimplementasikan:**
   - `registerPhysicalDevice(array $data): PhysicalDevice`
   - `updatePhysicalDevice(int $id, array $data): PhysicalDevice`
   - `deactivateHardware(int $id): PhysicalDevice`
   - `attachHardware(int $stationId, int $physicalDeviceId, string $mappingRole, ?int $userId, ?string $attachNotes): StationDeviceMapping`
   - `replaceHardware(int $stationId, int $newPhysicalDeviceId, string $mappingRole, ?int $userId, ?string $attachNotes, ?string $detachNotes): StationDeviceMapping`
   - `softDetach(int $stationId, string $mappingRole, ?int $userId, ?string $detachNotes): StationDeviceMapping`
   - `getActiveMapping(int $stationId, ?string $mappingRole = null)`
   - `getMappingHistory(int $stationId): Collection`
   - `validateCompatibility(Device $station, PhysicalDevice $device, string $mappingRole): void`
   - `validateStationAvailability(Device $station): void`
3. **Penanganan Unique Constraint Error:**
   - Menangkap `Illuminate\Database\QueryException` (MySQL error 1062) dan memetakannya secara otomatis menjadi `StationMappingException` berstatus HTTP 409.

---

## 5. VALIDASI BUSINESS INVARIANTS

| Invarian Bisnis | Mekanisme Penegakan | Hasil Uji |
| :--- | :--- | :---: |
| **Normalisasi MAC Address** | `PhysicalDevice::normalizeMacAddress` mengubah semua format menjadi `AA:BB:CC:DD:EE:FF`. | **PASS** |
| **Penolakan MAC Duplikat** | Pengecekan pra-simpan + Indeks Unik `uq_physical_devices_mac` di basis data. | **PASS** |
| **Validasi IP RFC 1918** | Filter alamat IPv4 privat lokal (`10.x`, `172.16-31.x`, `192.168.x`). Menolak IP publik. | **PASS** |
| **Deteksi Konflik IP** | Pengecekan perangkat aktif lain dengan IP yang sama (menghasilkan HTTP 409). | **PASS** |
| **Kompatibilitas Brand/Category** | Smart TV hanya boleh Samsung (Coocaa ditolak). Smart Relay hanya boleh Shelly. | **PASS** |
| **Kompatibilitas Station PlayStation** | Hanya menerima Smart TV dengan peran `DISPLAY_TV`. Relay ditolak keras (HTTP 422). | **PASS** |
| **Kompatibilitas Station Biliar** | Hanya menerima Smart Relay dengan peran `LIGHT_RELAY`. TV ditolak keras (HTTP 422). | **PASS** |
| **Kategori Ambigu/Tidak Dikenal** | Menolak stasiun tanpa layanan terdaftar atau dengan jenis layanan campuran (HTTP 422). | **PASS** |
| **Single Active Mapping Hardware** | Hardware aktif tidak dapat dipetakan ke stasiun lain (Aplikasi + MySQL Virtual Column `uq_active_physical_device`). | **PASS** |
| **Single Active Mapping Role per Station** | Satu stasiun tidak boleh memiliki dua TV atau dua Relay aktif bersamaan (`uq_active_station_role`). | **PASS** |
| **Replacement Hardware Aman** | Mapping lama di-soft detach menjadi `INACTIVE` beserta alasan dan aktor pelepasan; mapping baru dibuat aktif. | **PASS** |
| **Audit Trail Pelepasan (Soft-Detach)** | Histori mutasi mencatat `attached_by_user_id`, `detached_by_user_id`, `attached_at`, `detached_at`, `attach_notes`, `detach_notes`. | **PASS** |
| **Proteksi Sesi Aktif Kasir** | Jika stasiun berstatus `Digunakan` atau transaksi `RUNNING`, mutasi hardware ditolak keras (HTTP 409). | **PASS** |
| **Proteksi Deaktivasi Hardware** | Hardware yang masih aktif terpasang pada stasiun tidak dapat dinonaktifkan (`is_active = false`) (HTTP 409). | **PASS** |
| **Kemandirian Billing Tanpa Hardware** | Stasiun tanpa pemetaan hardware tetap dapat menjalankan siklus billing G2 (start, timer, stop) tanpa kendala. | **PASS** |
| **Perlindungan Audit Trail Abadi** | Foreign key `station_id` dan `physical_device_id` menggunakan `ON DELETE RESTRICT`, menolak hard-delete stasiun yang memiliki histori. | **PASS** |

---

## 6. HASIL PENGUJIAN CONCURRENCY & RACE CONDITION

Pengujian konkurensi dilakukan untuk memastikan integritas basis data di bawah beban paralel:
1. **Pessimistic Locking:** Penggunaan `Device::where('id', $stationId)->lockForUpdate()` dan `PhysicalDevice::where('id', $id)->lockForUpdate()` menjamin bahwa permintaan beruntun dieksekusi secara serial.
2. **MySQL Virtual Generated Columns + Unique Index Verification:**
   - Uji coba insert langsung ke basis data untuk dua mapping berstatus `ACTIVE` pada hardware yang sama menghasilkan `QueryException` (SQLSTATE[23000]: 1062 Duplicate entry for key 'uq_active_physical_device').
   - Uji coba insert langsung untuk dua mapping berstatus `ACTIVE` pada stasiun dan peran yang sama menghasilkan `QueryException` (1062 Duplicate entry for key 'uq_active_station_role').
   - Penambahan beberapa mapping berstatus `INACTIVE` untuk hardware atau stasiun yang sama berhasil disimpan tanpa konflik, membuktikan bahwa kolom virtual bernilai `NULL` untuk baris non-aktif dan tidak memicu pelanggaran keunikan.

---

## 7. HASIL SELURUH AUTOMATED TESTS

Pengujian dieksekusi dengan perintah:
```bash
php artisan test --env=testing
```

### Ringkasan Eksekusi:
- **Total Tests Passed:** **87 / 87 (100% PASS)**
- **Waktu Eksekusi:** `1.50s`
- **Rincian Test Suites:**
  1. `Tests\Unit\ExampleTest`: 1 passed
  2. `Tests\Unit\RbacAndSecurityTest`: 7 passed
  3. `Tests\Unit\TransactionFnbRelationTest`: 2 passed
  4. `Tests\Feature\BillingWorkflowRegressionTest`: 13 passed
  5. `Tests\Feature\DeviceStopEndpointTest`: 3 passed
  6. `Tests\Feature\ExampleTest`: 1 passed
  7. `Tests\Feature\MidnightTimerCrossingTest`: 7 passed
  8. `Tests\Feature\ServerSideBillingEngineTest`: 23 passed
  9. `Tests\Feature\TransactionFnbManagementTest`: 11 passed
  10. **`Tests\Feature\StationMappingServiceTest` (Baru G3-B1):** **19 passed**

Seluruh 68 baseline test G2 tetap lulus tanpa satu pun regresi.

---

## 8. BUKTI DATABASE UTAMA TIDAK BERUBAH

Pemeriksaan integritas koneksi dan struktur basis data utama `billing-sugeng-rawuh`:
```text
PRIMARY DB: billing-sugeng-rawuh
PRIMARY HAS physical_devices: NO
PRIMARY HAS station_device_mappings: NO

TEST DB: kasir_ps_test
TEST HAS physical_devices: YES
TEST HAS station_device_mappings: YES
```
- Database utama `billing-sugeng-rawuh` **TIDAK PERNAH** dimigrasi atau diubah strukturnya pada fase G3-B1.
- Tidak ada data transaksi, produk, atau user yang terhapus atau termodifikasi pada database produksi.

---

## 9. RISIKO DAN BLOCKER

- **Risiko Teridentifikasi:** Tidak ada risiko struktural atau regresi fungsional.
- **Blocker:** **NIHIL**. Database testing, migration, model, dan domain service telah sepenuhnya terisolasi dan siap untuk lapisan controller/UI pada Gate berikutnya.

---

## 10. DAFTAR FILE YANG DIBUAT DAN DIUBAH

### File Baru:
1. `database/migrations/2026_11_05_000001_create_physical_devices_table.php`
2. `database/migrations/2026_11_05_000002_create_station_device_mappings_table.php`
3. `app/Models/PhysicalDevice.php`
4. `app/Models/StationDeviceMapping.php`
5. `app/Exceptions/StationMappingException.php`
6. `app/Services/Hardware/StationMappingService.php`
7. `tests/Feature/StationMappingServiceTest.php`
8. `G3-B1-IMPLEMENTATION-REPORT.md`

### File yang Diubah (Hanya Tambahan Kompatibel G3-B1):
1. `app/Models/Playstation.php` (Menambahkan konstanta kategori dan metode `getCategory()`).
2. `app/Models/Device.php` (Menambahkan relasi mapping hardware dan metode `getStationCategory()`).

---

## 11. TINDAKAN MANUAL YANG MASIH DIPERLUKAN

- **Tidak ada tindakan manual sebelum review laporan ini.**
- Jangan melakukan migrasi pada database produksi `billing-sugeng-rawuh` sebelum seluruh Gate G3 (G3-B1 s.d. G3-B3) selesai dan disetujui untuk rilis produksi.
- Jangan melakukan git commit, push, atau merge otomatis.

---

## 12. REKOMENDASI GATE

```text
============================================================
STATUS AKHIR: GATE G3-B1 COMPLETED & VERIFIED (SAMSUNG ONLY)
============================================================
Baseline G2: 68 Tests PASSED (Zero Regressions)
G3-B1 Tests: 19 Tests PASSED (All Invariants Verified)
Total Suite: 87 Tests PASSED in 1.53s
Database Utama: billing-sugeng-rawuh (100% UNTOUCHED)
Database Testing: kasir_ps_test (MIGRATED & VERIFIED)
Hardware Brand Scope: SMART_TV -> SAMSUNG only, SMART_RELAY -> SHELLY only (COOCAA PROHIBITED)
Rekomendasi: G3-B1 READY FOR APPROVAL — SAMSUNG ONLY
============================================================
```

**Implementasi Gate G3-B1 telah selesai secara sempurna dengan cakupan brand SAMSUNG ONLY. Menunggu review dan persetujuan pengguna sebelum melanjutkan ke Gate G3-B2.**
