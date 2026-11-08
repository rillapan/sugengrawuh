# LAPORAN IMPLEMENTASI GATE G3-B2
## Controllers, Routes & API Integration — Physical Device Registry & Station Mapping

**Project:** Sugeng Rawuh — Integrated Billing & POS Management System  
**Repository:** `sistem_kasir_PS-main`  
**Gate:** G3-B2 — Controllers, Routes & API Integration  
**Status Target:** G3-B2 IMPLEMENTED — READY FOR REVIEW  
**Hardware Scope:** SAMSUNG SMART TV + SHELLY SMART RELAY (COOCAA strictly rejected)  
**Baseline Test:** 87 tests passing (G2 & G3-B1)  
**Total Current Test:** 108 tests passing (100% passing, 0 failure, 0 regression)  
**Database Testing:** `kasir_ps_test`  
**Database Utama:** `billing-sugeng-rawuh` (100% Unchanged, 0 modification)  

---

## 1. AUDIT CONTROLLERS, MIDDLEWARE, DAN ROUTES EXISTING

Sebelum melakukan implementasi, audit mendalam dilakukan terhadap komponen existing:

1. **Routes Existing (`routes/web.php`):**
   - Rute web diproteksi oleh middleware `auth` dan `check_role`.
   - Rute `/device` menangani CRUD billing station (Playstation/Billiard meja) dan relasi pricing.
   - Endpoint billing G2 seperti `/device/{id}/stop`, `/transactions`, `/api/billing/sync-timers` berjalan normal tanpa interferensi namespace.
   - Namespace baru ditempatkan secara terisolasi di `/admin/hardware/devices` dan `/admin/stations/{stationId}/mappings` sehingga tidak terjadi route collision dengan `/device` maupun endpoint POS/kasir.

2. **Middleware `CheckRole` & Format Role Aktual:**
   - Role tersimpan dalam huruf kecil pada database: `'admin'`, `'kasir'`, `'owner'`.
   - Middleware `CheckRole` (`app/Http/Middleware/CheckRole.php`) menerima variadic role parameter (contoh: `check_role:admin,owner`) dan mengeksekusi `abort(403, 'Unauthorized action.')` jika role tidak sesuai.
   - Pembagian akses diverifikasi konsisten dengan helper Eloquent: `$user->isAdmin()`, `$user->isKasir()`, `$user->isOwner()`.

3. **Authentication & Session Handling:**
   - Menggunakan Laravel 8 Web Session Authentication standar (`auth` middleware).
   - CSRF protection aktif secara default pada pipeline `web` middleware group.
   - Request API/AJAX wajib menyertakan header `Accept: application/json` agar Laravel mengembalikan status HTTP 401 Unauthorized dalam bentuk JSON alih-alih redirect ke `/login`.

4. **Controller dan Form Request Existing:**
   - Dibuat controller terpisah dengan namespace `App\Http\Controllers\Admin`:
     - `PhysicalDeviceController`
     - `StationHardwareMappingController`
   - Form Requests dibuat untuk validasi ketat dan otorisasi:
     - `StorePhysicalDeviceRequest`
     - `UpdatePhysicalDeviceRequest`
     - `AttachStationMappingRequest`
     - `DetachStationMappingRequest`

5. **API Response Pattern Existing:**
   - Pola respon JSON terstandarisasi:
     - Sukses: `{ "status": "success", "message": "...", "data": ... }` dengan status code 200/201.
     - Error Validasi: `{ "message": "The given data was invalid.", "errors": { ... } }` dengan status code 422.
     - Error Otorisasi: `{ "message": "Unauthorized action." }` dengan status code 403.
     - Error Conflict: `{ "status": "error", "message": "..." }` dengan status code 409.

6. **Service Domain `StationMappingService`:**
   - Seluruh mutasi domain (registrasi hardware, normalisasi MAC, deteksi konflik IP, attach mapping, replace mapping atomik, soft detach) didelegasikan sepenuhnya ke `StationMappingService`.
   - Controller tidak menduplikasi logic bisnis domain.

---

## 2. DAFTAR FILE YANG DIBUAT DAN DIUBAH

### File Baru:
1. `app/Http/Requests/StorePhysicalDeviceRequest.php`  
   Form request validasi registrasi physical device (admin only, validasi format MAC, IP, enum kategori/brand).
2. `app/Http/Requests/UpdatePhysicalDeviceRequest.php`  
   Form request validasi perubahan identitas hardware (admin only, allowlisted attributes).
3. `app/Http/Requests/AttachStationMappingRequest.php`  
   Form request validasi pemasangan dan penggantian mapping (admin only, role enum, optional notes).
4. `app/Http/Requests/DetachStationMappingRequest.php`  
   Form request validasi pelepasan mapping (admin only, mandatory detach reason notes).
5. `app/Http/Controllers/Admin/PhysicalDeviceController.php`  
   HTTP Controller untuk Physical Device Registry (index, show, store, update, destroy).
6. `app/Http/Controllers/Admin/StationHardwareMappingController.php`  
   HTTP Controller untuk Station Device Mapping (index, store/replace, detach).
7. `tests/Feature/HardwareApiIntegrationTest.php`  
   Feature test suite komprehensif menguji RBAC, physical device API, station mapping API, dan regresi billing (21 test cases).
8. `G3-B2-IMPLEMENTATION-REPORT.md`  
   Dokumen laporan resmi implementasi G3-B2.

### File Diubah:
1. `app/Models/PhysicalDevice.php`  
   Menambahkan `protected $hidden = ['pairing_payload'];` untuk memastikan token autentikasi/kredensial hardware tidak pernah bocor dalam serialisasi JSON API.
2. `routes/web.php`  
   Mendaftarkan rute G3-B2 di dalam middleware group `['auth']` dengan granular `check_role`.

---

## 3. DAFTAR ENDPOINT YANG BERFUNGSI

| Method | URI | Name | Role Diizinkan | Fungsi |
|---|---|---|---|---|
| `GET` | `/admin/hardware/devices` | `admin.hardware.devices.index` | `admin`, `owner` | List device registry dengan filter (`category`, `brand`, `is_active`, `unmapped`) dan pagination |
| `POST` | `/admin/hardware/devices` | `admin.hardware.devices.store` | `admin` | Registrasi physical device baru (201 Created) |
| `GET` | `/admin/hardware/devices/{id}` | `admin.hardware.devices.show` | `admin`, `owner` | Detail physical device beserta riwayat mappingnya |
| `PUT` | `/admin/hardware/devices/{id}` | `admin.hardware.devices.update` | `admin` | Update identitas physical device (200 OK) |
| `DELETE` | `/admin/hardware/devices/{id}` | `admin.hardware.devices.destroy` | `admin` | Soft deactivation hardware (200 OK, ditolak 409 jika aktif) |
| `GET` | `/admin/stations/{stationId}/mappings` | `admin.stations.mappings.index` | `admin`, `owner`, `kasir` | Mapping station aktif (Kasir: ringkasan operasional; Admin/Owner: detail + histori) |
| `POST` | `/admin/stations/{stationId}/mappings` | `admin.stations.mappings.store` | `admin` | Attach hardware ke station atau replace hardware aktif (201 Created) |
| `POST` | `/admin/stations/{stationId}/mappings/{mappingId}/detach` | `admin.stations.mappings.detach` | `admin` | Soft detach hardware dari station dengan alasan & audit trail (200 OK) |

---

## 4. REQUEST DAN RESPONSE EXAMPLES

### A. Registrasi Physical Device (Samsung Smart TV)
- **Endpoint:** `POST /admin/hardware/devices`
- **Request Headers:**
  ```http
  Accept: application/json
  Content-Type: application/json
  ```
- **Request Body:**
  ```json
  {
    "name": "TV PlayStation Meja 01",
    "category": "SMART_TV",
    "brand": "SAMSUNG",
    "model": "UA43CU7000",
    "mac_address": "AA:BB:CC:DD:EE:01",
    "ip_address": "192.168.1.50",
    "device_identifier": "samsung-tv-ps01"
  }
  ```
- **Response (HTTP 201 Created):**
  ```json
  {
    "status": "success",
    "message": "Physical device successfully registered",
    "data": {
      "id": 1,
      "name": "TV PlayStation Meja 01",
      "category": "SMART_TV",
      "brand": "SAMSUNG",
      "model": "UA43CU7000",
      "mac_address": "AA:BB:CC:DD:EE:01",
      "ip_address": "192.168.1.50",
      "device_identifier": "samsung-tv-ps01",
      "connection_status": "UNVERIFIED",
      "pairing_status": "UNVERIFIED",
      "is_active": true,
      "created_at": "2026-11-05T03:05:27.000000Z",
      "updated_at": "2026-11-05T03:05:27.000000Z"
    }
  }
  ```

### B. Registrasi Perangkat Tidak Valid (COOCAA / Kombinasi Ditolak)
- **Endpoint:** `POST /admin/hardware/devices`
- **Request Body:**
  ```json
  {
    "name": "TV Coocaa Ruang 2",
    "category": "SMART_TV",
    "brand": "COOCAA",
    "model": "40S3U",
    "mac_address": "AA:BB:CC:DD:EE:02",
    "ip_address": "192.168.1.52"
  }
  ```
- **Response (HTTP 422 Unprocessable Entity):**
  ```json
  {
    "message": "The given data was invalid.",
    "errors": {
      "brand": [
        "The selected brand is invalid."
      ]
    }
  }
  ```

### C. Attach Hardware ke Station (Samsung TV ke PlayStation)
- **Endpoint:** `POST /admin/stations/1/mappings`
- **Request Body:**
  ```json
  {
    "physical_device_id": 1,
    "mapping_role": "DISPLAY_TV",
    "attach_notes": "Pemasangan TV baru meja 1"
  }
  ```
- **Response (HTTP 201 Created):**
  ```json
  {
    "status": "success",
    "message": "Hardware successfully mapped to station",
    "data": {
      "id": 1,
      "station_id": 1,
      "physical_device_id": 1,
      "mapping_role": "DISPLAY_TV",
      "is_active": true,
      "attached_at": "2026-11-05T03:05:27.000000Z",
      "attached_by": 1,
      "detached_at": null,
      "detached_by": null,
      "attach_notes": "Pemasangan TV baru meja 1",
      "detach_notes": null
    }
  }
  ```

### D. Penggantian Hardware (Replace Mapping Atomik)
- **Endpoint:** `POST /admin/stations/1/mappings`
- **Request Body:**
  ```json
  {
    "physical_device_id": 2,
    "mapping_role": "DISPLAY_TV",
    "replace": true,
    "attach_notes": "Ganti TV Samsung 50 inch",
    "detach_notes": "TV lama dipindah servis"
  }
  ```
- **Response (HTTP 201 Created):**
  ```json
  {
    "status": "success",
    "message": "Hardware successfully replaced on station",
    "data": {
      "id": 2,
      "station_id": 1,
      "physical_device_id": 2,
      "mapping_role": "DISPLAY_TV",
      "is_active": true,
      "attached_at": "2026-11-05T03:05:27.000000Z",
      "attached_by": 1,
      "detached_at": null,
      "detached_by": null,
      "attach_notes": "Ganti TV Samsung 50 inch",
      "detach_notes": null
    }
  }
  ```

### E. Soft Detach Hardware
- **Endpoint:** `POST /admin/stations/1/mappings/2/detach`
- **Request Body:**
  ```json
  {
    "detach_notes": "Perawatan rutin kabel HDMI"
  }
  ```
- **Response (HTTP 200 OK):**
  ```json
  {
    "status": "success",
    "message": "Hardware successfully detached from station",
    "data": {
      "id": 2,
      "station_id": 1,
      "physical_device_id": 2,
      "mapping_role": "DISPLAY_TV",
      "is_active": false,
      "attached_at": "2026-11-05T03:05:27.000000Z",
      "attached_by": 1,
      "detached_at": "2026-11-05T03:05:27.000000Z",
      "detached_by": 1,
      "attach_notes": "Ganti TV Samsung 50 inch",
      "detach_notes": "Perawatan rutin kabel HDMI"
    }
  }
  ```

### F. Ringkasan Operasional untuk Kasir
- **Endpoint:** `GET /admin/stations/1/mappings` (Login as Kasir)
- **Response (HTTP 200 OK):**
  ```json
  {
    "status": "success",
    "data": {
      "station": {
        "id": 1,
        "name": "Meja Rental 01",
        "category": "PLAYSTATION",
        "status": "Tersedia"
      },
      "active_hardware": [
        {
          "mapping_id": 1,
          "mapping_role": "DISPLAY_TV",
          "device_name": "TV PlayStation Meja 01",
          "category": "SMART_TV",
          "brand": "SAMSUNG",
          "connection_status": "UNVERIFIED"
        }
      ]
    }
  }
  ```
  *(Catatan: Kasir tidak menerima IP address, MAC address, token, histori audit, atau device identifier teknis).*

---

## 5. HASIL PENGUJIAN RBAC

Matriks Otorisasi HTTP Endpoint diverifikasi secara menyeluruh melalui feature tests:

| Role | List Registry | Detail Device | Register/Update/Delete | View Station Mapping | Attach/Replace/Detach |
|---|:---:|:---:|:---:|:---:|:---:|
| **Guest (Unauthenticated)** | 401 Unauthorized | 401 Unauthorized | 401 Unauthorized | 401 Unauthorized | 401 Unauthorized |
| **Kasir** | 403 Forbidden | 403 Forbidden | 403 Forbidden | 200 OK (Operasional) | 403 Forbidden |
| **Owner** | 200 OK | 200 OK | 403 Forbidden | 200 OK (Full + History) | 403 Forbidden |
| **Admin** | 200 OK | 200 OK | 200/201 OK | 200 OK (Full + History) | 200/201 OK |

### Temuan RBAC:
1. Guest yang mengakses tanpa session menghasilkan HTTP 401 saat menyertakan `Accept: application/json`.
2. Kasir diblokir total (403) dari registry inventaris dan seluruh endpoint mutasi.
3. Owner memiliki visibilitas penuh terhadap inventaris dan riwayat mapping, namun diblokir total (403) dari mutasi data.
4. Admin memiliki otoritas penuh terhadap siklus hidup hardware dan mapping.

---

## 6. HASIL VALIDASI SAMSUNG / SHELLY

Aturan domain strict scope hardware divalidasi pada Form Request dan Service layer:

1. **Samsung Smart TV:**
   - Kategori `SMART_TV` dengan brand `SAMSUNG` diterima (201 Created).
   - Validasi model, format MAC address (dinormalisasi ke huruf kapital dan pemisah titik dua), serta validasi IP lokal (`192.168.x.x`, `10.x.x.x`, `172.16-31.x.x`) lulus.
2. **Shelly Smart Relay:**
   - Kategori `SMART_RELAY` dengan brand `SHELLY` diterima (201 Created).
3. **Penolakan Coocaa:**
   - Brand `COOCAA` ditolak langsung pada validasi Form Request (`Rule::in(['SAMSUNG', 'SHELLY'])`) dengan respon HTTP 422 Unprocessable Entity.
4. **Penolakan Kombinasi Silang Brand/Kategori:**
   - `SMART_TV` dengan `SHELLY` ditolak (HTTP 422).
   - `SMART_RELAY` dengan `SAMSUNG` ditolak (HTTP 422).
5. **Proteksi Field Sensitif & Tampering:**
   - Request client yang mencoba mengirim `connection_status: 'ONLINE'`, `pairing_status: 'PAIRED'`, atau `pairing_payload: 'hacked'` diabaikan/dibersihkan.
   - Perangkat yang baru didaftarkan selalu mengembalikan status default:
     - `connection_status: 'UNVERIFIED'`
     - `pairing_status: 'UNVERIFIED'`
   - Properti `pairing_payload` disembunyikan secara absolut dari representasi JSON (`$hidden = ['pairing_payload']`).

---

## 7. HASIL PENGUJIAN MAPPING DAN ERROR HANDLING

1. **Aturan Kompatibilitas Station:**
   - Station PlayStation hanya menerima `SMART_TV` + `DISPLAY_TV` (Lulus 201).
   - Station Biliar hanya menerima `SMART_RELAY` + `LIGHT_RELAY` (Lulus 201).
   - Upaya mapping silang (misal: Shelly ke PlayStation, atau Samsung TV ke Biliar) ditolak dengan HTTP 422 Unprocessable Entity.
2. **Invarian Unik Hardware:**
   - Perangkat yang sedang aktif terpasang pada satu station tidak dapat dipasangkan ke station lain (Ditolak HTTP 409 Conflict).
3. **Invarian Role Station:**
   - Station yang sudah memiliki mapping aktif untuk role yang sama menolak attach baru kecuali flag `replace: true` dikirimkan (Ditolak HTTP 409 Conflict).
4. **Proteksi Billing Sedang Berjalan:**
   - Jika station sedang memiliki billing aktif atau statusnya bukan 'Tersedia' (`is_occupied`), segala mutasi hardware (attach, replace, detach) langsung ditolak dengan HTTP 409 Conflict.
5. **Validasi Relasi URL vs Resource ID:**
   - Upaya melakukan detach mapping ID yang bukan milik station ID pada rute URL ditolak dengan HTTP 422 Unprocessable Entity.
6. **Proteksi Deaktivasi:**
   - Hardware yang sedang memiliki mapping aktif tidak dapat dinonaktifkan (`DELETE /admin/hardware/devices/{id}` mengembalikan HTTP 409 Conflict).

---

## 8. HASIL AUTOMATED TESTS TERBARU

Dijalankan menggunakan environment terisolasi `kasir_ps_test` (`--env=testing`):

```bash
php artisan test --env=testing tests/Feature/HardwareApiIntegrationTest.php
```

### Output:
```
   PASS  Tests\Feature\HardwareApiIntegrationTest
  ✓ guest cannot access protected hardware endpoints
  ✓ admin has full access to registry and mappings
  ✓ owner can read registry and mappings but cannot mutate
  ✓ kasir can only view operational mapping and cannot access registry or mutate
  ✓ register samsung tv succeeds with unverified status
  ✓ register shelly smart relay succeeds
  ✓ register coocaa is strictly rejected
  ✓ register invalid category brand combination is rejected
  ✓ duplicate mac address is rejected with 409
  ✓ ip conflict with active device is rejected with 409
  ✓ update hardware identity and reset connection status
  ✓ actively mapped device cannot be deactivated
  ✓ security sensitive fields cannot be tampered and pairing payload never exposed
  ✓ attach samsung tv to playstation station succeeds
  ✓ attach shelly relay to billiard station succeeds
  ✓ cross mapping is rejected with 422
  ✓ mapping mutation while station is in use rejected with 409
  ✓ replace hardware preserves audit history
  ✓ soft detach records admin actor and notes
  ✓ detach with mismatched station id returns 422
  ✓ station without mapping can still run billing unhindered

  Tests:  21 passed
  Time:   1.05s
```

---

## 9. HASIL REGRESI G2 DAN G3-B1

Seluruh test suite aplikasi dijalankan untuk memverifikasi tidak ada regresi pada modul billing G2 maupun service G3-B1:

```bash
php artisan test --env=testing
```

### Ringkasan Suite:
- `Tests\Unit\ExampleTest`: 1 passed
- `Tests\Unit\RbacAndSecurityTest`: 7 passed
- `Tests\Unit\TransactionFnbRelationTest`: 2 passed
- `Tests\Feature\BillingWorkflowRegressionTest`: 13 passed
- `Tests\Feature\DeviceStopEndpointTest`: 3 passed
- `Tests\Feature\ExampleTest`: 1 passed
- `Tests\Feature\HardwareApiIntegrationTest`: 21 passed (New G3-B2)
- `Tests\Feature\MidnightTimerCrossingTest`: 7 passed
- `Tests\Feature\ServerSideBillingEngineTest`: 23 passed
- `Tests\Feature\StationMappingServiceTest`: 19 passed (G3-B1)
- `Tests\Feature\TransactionFnbManagementTest`: 11 passed

**Total Tests:** **108 passed** (Baseline 87 + 21 baru = 108 tests)  
**Time:** 1.69s  
**Status:** **100% PASSING — ZERO REGRESSION**

---

## 10. VERIFIKASI KEAMANAN DATABASE UTAMA

Sesuai instruksi mutlak pada spesifikasi:
- Database pengujian yang digunakan: `kasir_ps_test`
- Database operasional utama: `billing-sugeng-rawuh`

Pemeriksaan integritas database utama dijalankan via Tinker:
```php
$tables = DB::connection('mysql')->select('SHOW TABLES');
// Primary DB table count: 22
// physical_devices in primary DB: NOT FOUND (SAFE)
// station_device_mappings in primary DB: NOT FOUND (SAFE)
```

**Hasil Verifikasi:**
- Database utama `billing-sugeng-rawuh` **100% tidak tersentuh (untouched)**.
- Tidak ada tabel G3 yang dibuat di database utama.
- Tidak ada data existing yang terhapus atau termodifikasi.
- Tidak ada migrasi `migrate:fresh` atau `migrate:refresh` yang dijalankan.

---

## 11. RISIKO ATAU BLOCKER

- **Risiko Teknis:** NIHIL.
- **Konflik Rute:** NIHIL. Rute baru berada di `/admin/hardware/devices` dan `/admin/stations/{stationId}/mappings`.
- **Blocker:** NIHIL. Lapisan database (G3-B1), service domain, Form Request, Controller, RBAC, dan Routes (G3-B2) terintegrasi dengan sempurna.

---

## 12. REKOMENDASI KELAYAKAN GATE

**STATUS: G3-B2 IMPLEMENTED — READY FOR REVIEW**

Seluruh kriteria penerimaan Gate G3-B2 telah terpenuhi:
1. Controller `PhysicalDeviceController` dan `StationHardwareMappingController` telah terpasang dengan validasi Form Request ketat.
2. Route contract terdaftar di `routes/web.php` dan dilindungi middleware RBAC (`admin`, `owner`, `kasir`).
3. Form input allowlist mencegah manipulasi status koneksi, pairing, atau payload kredensial.
4. Serialisasi Eloquent menyembunyikan `pairing_payload`.
5. Kasir dipartisi hanya untuk melihat ringkasan status operasional hardware station.
6. Seluruh 108 automated tests (87 baseline + 21 baru) lulus tanpa kegagalan.
7. Database utama `billing-sugeng-rawuh` aman dan tidak berubah.

Proses pengembangan siap dilanjutkan ke **Gate G3-B3 (Blade UI & Frontend Integration)** setelah mendapat review dan persetujuan pengguna.
