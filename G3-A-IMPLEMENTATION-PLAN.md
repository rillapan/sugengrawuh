# SUGENG RAWUH — G3-A IMPLEMENTATION PLAN & TESTING STRATEGY (FINAL APPROVED REVISION)
**Document ID:** SR-G3A-PLAN-007-REV2  
**Project:** Sugeng Rawuh — Integrated Billing & POS Management System  
**Repository:** `sistem_kasir_PS-main`  
**Phase:** G3-A (Audit & Architecture Design — Final Approval Corrections)  
**Status Gate:** G2 APPROVED — IMPLEMENTATION PLAN READY FOR REVIEW (NO CODING IN G3-A)  
**Date:** November 2026  

---

## 1. PEMBAGIAN GATE SECARA TEGAS (GATE DECOUPLING)

```text
┌─────────────────────────────────────────────────────────────────────────────┐
│ GATE G3-B: Registry & Mapping Implementation (Fokus Gate Berikutnya)        │
│ 1. Migrasi database tabel physical_devices dan station_device_mappings      │
│    - ON DELETE RESTRICT pada station_id (Perlindungan Audit Trail Abadi)    │
│    - Virtual Generated Column Unique Index (Enforcement MySQL Unik Aktif)   │
│    - Status Awal: connection_status & pairing_status = 'UNVERIFIED'         │
│ 2. Model Eloquent: Pisahkan attached_by/detached_by & attach/detach_notes   │
│ 3. Form Request: Normalisasi MAC Address, Validasi IPv4, Pencegahan Konflik │
│ 4. Station Mapping: Opsional (Station boleh tanpa hardware), Kompatibilitas │
│ 5. Concurrency Guard: DB Transaction + lockForUpdate + Tolak saat Berjalan  │
│ 6. Controller & Web Route: Admin Hardware CRUD & Mapping (Web Session+CSRF) │
│ 7. Tampilan Blade + Bootstrap 4 SB Admin 2 (Status Awal: UNVERIFIED)        │
│ 8. Automated Testing: Validasi Invarian, RBAC, dan Regresi G2 (68 Tests)    │
│ * TIDAK ADA: Kontrol Hardware, Pairing, atau Network Probe di G3-B *        │
└──────────────────────────────────────┬──────────────────────────────────────┘
                                       │
                                       ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│ GATE G4: Hardware Connectivity & Control PoC (Fase Pengujian Fisik)         │
│ 1. Implementasi Socket / HTTP Probe (Test Connection)                       │
│ 2. Implementasi Driver Protokol Samsung (Tizen WS) & Shelly Gen3 RPC        │
│ 3. Pengujian Kontrol TV saat Layar Menampilkan Input HDMI PlayStation       │
│ 4. Implementasi Driver Shelly 1PM Gen3 Mini RPC (/rpc/Switch.Set)           │
│ 5. Konfirmasi Jumlah Relay Fisik Meja Biliar                                │
│ 6. Pengujian Fisik Power ON/OFF, Mute/Volume, dan Relay Listrik             │
└──────────────────────────────────────┬──────────────────────────────────────┘
                                       │
                                       ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│ GATE G5: Billing-to-Hardware Automation (Integrasi Otomatis)                │
│ 1. Event Listener: Billing Mulai -> Trigger Nyala TV & Lampu Biliar         │
│ 2. Billing Worker: Billing Habis / Stop -> Trigger Standby TV & Lampu Padam │
│ 3. Penanganan Kegagalan Hardware Otomatis & Prosedur Manual Kasir           │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 2. DETAIL TAHAPAN IMPLEMENTASI G3-B

### Fase 1: Database Migration & Model Layer
1. **Migration Baru (Safe Migration):**
   - `2026_11_05_000001_create_physical_devices_table.php`:
     - Kolom identitas: `name`, `category`, `brand`, `model`, `mac_address`, `ip_address`, `device_identifier`.
     - Kolom status: `connection_status` (default: `'UNVERIFIED'`), `pairing_status` (default: `'UNVERIFIED'`), `is_active` (default: `1`).
     - Kolom audit: `pairing_payload`, `verified_capabilities`, `last_seen_at`, timestamps.
     - Indeks unik: `mac_address`. Indeks lookup: `(ip_address, is_active)`.
   - `2026_11_05_000002_create_station_device_mappings_table.php`:
     - Kolom relasi: `station_id` (FK `devices.id` **`ON DELETE RESTRICT`**), `physical_device_id` (FK `physical_devices.id` **`ON DELETE RESTRICT`**).
     - Kolom peran & status: `mapping_role` (`DISPLAY_TV`/`LIGHT_RELAY`), `mapping_status` (`ACTIVE`/`INACTIVE`).
     - Kolom audit terpisah:
       - `attached_by_user_id` (FK `users.id`), `attached_at`, `attach_notes`.
       - `detached_by_user_id` (FK `users.id`), `detached_at`, `detach_notes`.
     - Kolom virtual & unique key:
       - `active_physical_device_id` (Virtual generated: `IF(mapping_status = 'ACTIVE', CAST(physical_device_id AS CHAR), NULL)`).
       - `active_station_role` (Virtual generated: `IF(mapping_status = 'ACTIVE', CONCAT(station_id, '_', mapping_role), NULL)`).
       - `UNIQUE KEY uq_active_physical_device (active_physical_device_id)`
       - `UNIQUE KEY uq_active_station_role (active_station_role)`
2. **Model Eloquent:**
   - `App\Models\PhysicalDevice`:
     - Casts `is_active` boolean, casts `verified_capabilities` json.
     - Scope `active()`, scope `unmapped()`.
     - Relasi `activeMapping()`, relasi `mappingHistory()`.
   - `App\Models\StationDeviceMapping`:
     - Relasi `station()`, `physicalDevice()`, `attacher()`, `detacher()`.
     - Scope `active()`, scope `inactive()`.
3. **Penyempurnaan Model `Device` Eksisting (Non-Destructive):**
   - Menambahkan relasi `activeHardwareMappings()` dan `activeHardware()` tanpa mengubah atribut yang ada.

---

### Fase 2: Form Request, Normalisasi & Service Layer
1. **Normalisasi MAC Address:**
   - Helper yang mengonversi input string (strip, titik, huruf kecil) menjadi uppercase colon-separated (`AA:BB:CC:DD:EE:FF`).
2. **Form Request Validation:**
   - `StorePhysicalDeviceRequest` & `UpdatePhysicalDeviceRequest`:
     - Validasi MAC unik setelah normalisasi.
     - Validasi IPv4 lokal RFC 1918.
     - Deteksi benturan IP terhadap perangkat aktif lain.
   - `AttachStationMappingRequest`:
     - Validasi `station_id` dan `physical_device_id`.
     - Validasi kecocokan role (PlayStation wajib `DISPLAY_TV` & `SMART_TV`; Biliar wajib `LIGHT_RELAY` & `SMART_RELAY`).
     - Validasi ketersediaan perangkat (tidak boleh sedang `ACTIVE` di stasiun lain).
3. **StationMappingService (Atomic Concurrency):**
   - Mengeksekusi transaksi atomic (`DB::transaction` + `lockForUpdate` pada `devices` dan `physical_devices`).
   - Memastikan penolakan keras (HTTP 409) jika stasiun memiliki sesi billing `Digunakan` atau transaksi `RUNNING`.
   - Mengelola replacement aman: mapping lama di-update menjadi `INACTIVE`, `detached_at = now()`, `detached_by_user_id = auth()->id()`, dan `detach_notes = $detachNotes`.
   - Mapping baru dibuat dengan `attached_at = now()`, `attached_by_user_id = auth()->id()`, dan `attach_notes = $attachNotes`.

---

### Fase 3: Controller & Web Routes (Existing Stack)
1. **Controllers Admin:**
   - `App\Http\Controllers\Admin\PhysicalDeviceController`: Master CRUD registry hardware.
   - `App\Http\Controllers\Admin\StationMappingController`: Pengelolaan pemetaan hardware stasiun dan audit trail mutasi.
2. **Web Routes (`routes/web.php`):**
   - Menggunakan middleware group eksisting `['auth', 'check_role:admin']` untuk operasi mutasi.
   - Menggunakan middleware group eksisting `['auth', 'check_role:admin,kasir,owner']` untuk monitoring read-only.
   - Menggunakan session web Laravel dan CSRF token eksisting (Zero Sanctum).

---

### Fase 4: Blade Views & Bootstrap 4 UI
1. **Sidebar SB Admin 2:**
   - Menambahkan menu *Kelola Hardware* (Device Registry & Station Mapping) khusus untuk Admin.
2. **View Registry (`resources/views/hardware/devices/`):**
   - Tabel inventaris dengan badge status **`[UNVERIFIED]`** (Abu-abu / Neutral).
   - Form modal registrasi dengan validasi interaktif.
3. **View Mapping (`resources/views/hardware/mappings/`):**
   - Grid pemetaan stasiun, modal ganti hardware dengan proteksi stasiun aktif, dan modal konfirmasi soft-detach.
4. **Pembaruan Kartu Stasiun Kasir (`resources/views/device/index.blade.php`):**
   - Menampilkan badge hardware terpasang (`TV: UNVERIFIED` / `RELAY: UNVERIFIED` / `BELUM TERPASANG`).
   - Stasiun tanpa hardware tetap beroperasi normal seperti biasa.

---

### Fase 5: Pengujian Regresi & Validasi Otomatis

#### 1. Jaminan Zero-Regression pada Billing G2:
- Menjalankan seluruh 68 unit & feature tests G2 pada basis data testing:
  ```bash
  php artisan test --env=testing
  ```
- **Syarat Mutlak:** 100% test G2 (siklus prepaid, lost time, custom package, pembayaran, FnB, RBAC) **wajib tetap PASS (68/68)**.

#### 2. Test Suite Baru untuk G3-B:
- `tests/Feature/PhysicalDeviceRegistryTest.php`:
  - Test normalisasi format MAC Address (`84-c0-ef...` -> `84:C0:EF...`).
  - Test penolakan duplikasi MAC Address.
  - Test penolakan benturan IP Address lokal dengan perangkat aktif lain.
  - Test pendaftaran menghasilkan status awal `connection_status = UNVERIFIED` dan `pairing_status = UNVERIFIED`.
- `tests/Feature/StationDeviceMappingConcurrencyTest.php`:
  - Test stasiun dapat beroperasi tanpa hardware mapping (opsional).
  - Test pemetaan sukses Smart TV ke stasiun PlayStation.
  - Test pemetaan sukses Shelly Relay ke stasiun Biliar.
  - Test penolakan pemetaan silang yang tidak kompatibel (Relay ke PS atau TV ke Biliar sebagai Light Relay).
  - Test penolakan satu perangkat fisik aktif di dua stasiun (*Single Active Invariant* via virtual unique key).
  - Test penolakan satu stasiun memiliki 2 perangkat aktif pada role yang sama via virtual unique key.
  - Test penolakan pergantian mapping saat stasiun sedang `Digunakan` atau transaksi `RUNNING` (menghasilkan HTTP 409).
  - Test replacement mapping: row lama di-soft-detach dengan `detached_by_user_id` & `detach_notes`, row baru dicatat dengan `attached_by_user_id` & `attach_notes`.
  - Test penghapusan stasiun ditolak jika memiliki riwayat mapping (`ON DELETE RESTRICT`).
- `tests/Unit/HardwareRbacTest.php`:
  - Test Kasir ditolak dari form registrasi & mapping (HTTP 403 Forbidden).
  - Test Owner memiliki akses melihat data (read-only).
  - Test Admin memiliki akses penuh.

---

## 3. CHECKLIST KESIAPAN IMPLEMENTASI (IMPLEMENTATION READINESS CHECKLIST)

Pre-kondisi sebelum perintah eksekusi kode G3-B dimulai:
- [x] Dokumen arsitektur dan skema database final telah disetujui pengguna (G3-A Final Approved).
- [x] `ON DELETE RESTRICT` telah menggantikan `CASCADE` untuk melindungi audit trail.
- [x] Virtual generated columns MySQL dirancang untuk menegakkan keunikan pemetaan aktif.
- [x] Aktor dan catatan pemasangan (`attach`) serta pelepasan (`detach`) dipisahkan secara terstruktur.
- [x] Status awal `connection_status` dan `pairing_status` ditetapkan sebagai `UNVERIFIED`.
- [x] Stasiun dipastikan dapat beroperasi normal tanpa ketergantungan pemetaan hardware.
- [x] Validasi peran stasiun PlayStation (Smart TV/Display) dan Biliar (Smart Relay/Light) dikunci rapat.
- [x] Kebijakan hardware offline disepakati (Opsi A: tidak memblokir jalannya billing kasir).
- [x] Baseline testing G2 terverifikasi aman (68/68 test cases).

---

## 4. PERNYATAAN STATUS AKHIR

```text
============================================================
STATUS AKHIR: G3-A FINAL DESIGN READY FOR APPROVAL
============================================================
Mode: FOCUSED DESIGN CORRECTIONS ONLY / STRICTLY NO CODING
Repository: sistem_kasir_PS-main
Database: billing-sugeng-rawuh (UNTOUCHED) / kasir_ps_test (UNTOUCHED)
Billing Engine G2: 100% PRESERVED & STABLE
Historical Mapping Protection: ON DELETE RESTRICT (Audit Log Aman Abadi)
Active Uniqueness: MySQL Virtual Generated Columns + LockForUpdate Atomic
Audit Trail: Pisah Aktor & Catatan Pasang/Lepas (Complete History)
Pairing & Optional: Default UNVERIFIED, Stasiun Bebas Tanpa Hardware
Dokumentasi: 5 DOKUMEN DESAIN UTAMA TELAH DIPERBARUI & TERSINKRONISASI
============================================================
```

*Sistem berhenti di sini sesuai batasan Gate G3-A. Menunggu persetujuan final dari pengguna sebelum memulai eksekusi implementasi Gate G3-B.*
