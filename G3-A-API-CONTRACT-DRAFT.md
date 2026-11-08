# SUGENG RAWUH — G3-A API CONTRACT & ENDPOINT SPECIFICATIONS (FINAL APPROVED REVISION)
**Document ID:** SR-G3A-API-004-REV2  
**Project:** Sugeng Rawuh — Integrated Billing & POS Management System  
**Repository:** `sistem_kasir_PS-main`  
**Phase:** G3-A (Audit & Architecture Design — Final Approval Corrections)  
**Status Gate:** G2 APPROVED — API DRAFT ONLY (NO CONTROLLER/ROUTE IMPLEMENTATION)  
**Autentikasi:** Laravel Web Session + CSRF Token (Existing Stack, Zero Sanctum / Zero Extra Dependencies)  
**Date:** November 2026  

---

## 1. STANDAR & PROTOKOL API EKSISTING

Seluruh endpoint terintegrasi dengan arsitektur web aplikasi Laravel 8 eksisting:
- **Autentikasi & Session:** Cookie-based Laravel Web Session standar via middleware `web` dan `auth`.
- **Proteksi Mutasi:** Wajib menyertakan header `X-CSRF-TOKEN` atau input `_token` pada setiap request `POST`, `PUT`, `DELETE`.
- **Role-Based Access Control (RBAC):**
  - **ADMIN:** Akses penuh konfigurasi master registry hardware dan manajemen pemetaan stasiun (`check_role:admin`).
  - **OWNER:** Akses pemantauan read-only terhadap daftar perangkat dan pemetaan (`check_role:admin,kasir,owner`).
  - **KASIR:** Mengakses dashboard operasional sewa (`/device`) yang menampilkan badge hardware terpasang. Dilarang melakukan mutasi registry/mapping.
- **Dependency Guard:** **TIDAK MENGGUNAKAN** Laravel Sanctum, JWT, atau pustaka eksternal baru.

---

## 2. FINAL MVP API CONTRACT (SCOPE GATE G3-B)

Kontrak API di bawah ini adalah scope resmi yang diimplementasikan pada **Gate G3-B**. Seluruh status perangkat yang baru didaftarkan berada pada kondisi `UNVERIFIED` untuk koneksi maupun pairing.

---

### 2.1 Menampilkan Daftar Perangkat Fisik (List Devices)
- **Method:** `GET`
- **Endpoint:** `/admin/hardware/devices`
- **RBAC:** `check_role:admin,owner`
- **Query Parameters:**
  - `category`: optional (`SMART_TV` / `SMART_RELAY`)
  - `brand`: optional (`SAMSUNG` / `SHELLY`)
  - `unmapped_only`: optional (`1` untuk memfilter perangkat yang belum memiliki pemetaan aktif).
- **Response 200 OK:**
  ```json
  {
    "success": true,
    "message": "Daftar perangkat fisik berhasil diambil",
    "data": [
      {
        "id": 1,
        "name": "Samsung TV 50 QLED - Station 01",
        "category": "SMART_TV",
        "brand": "SAMSUNG",
        "model": "AU7000",
        "mac_address": "84:C0:EF:11:22:33",
        "ip_address": "192.168.1.201",
        "device_identifier": "samsung-tizen-duid-01",
        "connection_status": "UNVERIFIED",
        "pairing_status": "UNVERIFIED",
        "is_active": true,
        "active_mapping": {
          "station_id": 1,
          "station_name": "Station 01",
          "mapping_role": "DISPLAY_TV",
          "attached_at": "2026-11-04 10:00:00"
        }
      },
      {
        "id": 2,
        "name": "Shelly Relay Lampu Meja 1",
        "category": "SMART_RELAY",
        "brand": "SHELLY",
        "model": "1PM Gen3 Mini",
        "mac_address": "E8:DB:84:AA:BB:01",
        "ip_address": "192.168.1.211",
        "device_identifier": "shelly1pmminig3-e8db84aabb01",
        "connection_status": "UNVERIFIED",
        "pairing_status": "UNVERIFIED",
        "is_active": true,
        "active_mapping": {
          "station_id": 2,
          "station_name": "MEJA 1",
          "mapping_role": "LIGHT_RELAY",
          "attached_at": "2026-11-04 10:00:00"
        }
      }
    ]
  }
  ```

---

### 2.2 Pendaftaran Perangkat Fisik Baru (Create Device)
- **Method:** `POST`
- **Endpoint:** `/admin/hardware/devices`
- **RBAC:** `check_role:admin`
- **Request Body:**
  ```json
  {
    "name": "Samsung 43 Inch Smart TV",
    "category": "SMART_TV",
    "brand": "SAMSUNG",
    "model": "AU7000",
    "mac_address": "a0-9f-10-44-55-66",
    "ip_address": "192.168.1.202",
    "device_identifier": "samsung-tizen-serial-99"
  }
  ```
- **Aturan Validasi & Pemrosesan:**
  1. `mac_address` **wajib dinormalisasi** terlebih dahulu ke format uppercase colon-separated (`A0:9F:10:44:55:66`) sebelum pengecekan keunikan pada tabel `physical_devices`.
  2. `ip_address`: Wajib valid format IPv4, berada di rentang Private IP (RFC 1918), dan **belum terpakai** oleh perangkat lain yang berstatus aktif (`is_active = 1`).
  3. `category`: `required|in:SMART_TV,SMART_RELAY`
  4. `brand`: `required|in:SAMSUNG,SHELLY`
  5. `connection_status`: Otomatis diisi `'UNVERIFIED'`.
  6. `pairing_status`: Otomatis diisi `'UNVERIFIED'` (tidak mengklaim kebutuhan pairing sudah diketahui).
- **Response 201 Created:**
  ```json
  {
    "success": true,
    "message": "Perangkat fisik berhasil didaftarkan dengan status UNVERIFIED",
    "data": {
      "id": 3,
      "name": "Samsung 43 Inch Smart TV",
      "category": "SMART_TV",
      "brand": "SAMSUNG",
      "mac_address": "A0:9F:10:44:55:66",
      "ip_address": "192.168.1.202",
      "connection_status": "UNVERIFIED",
      "pairing_status": "UNVERIFIED"
    }
  }
  ```

---

### 2.3 Memperbarui Informasi Identitas & Jaringan (Update Device)
- **Method:** `PUT`
- **Endpoint:** `/admin/hardware/devices/{id}`
- **RBAC:** `check_role:admin`
- **Request Body:**
  ```json
  {
    "name": "Samsung TV 50 QLED - VIP 1",
    "model": "AU7000-Updated",
    "mac_address": "84:C0:EF:11:22:33",
    "ip_address": "192.168.1.205",
    "device_identifier": "samsung-duid-rev2"
  }
  ```
- **Aturan Validasi:**
  - Normalisasi MAC Address sebelum pengecekan keunikan (mengabaikan ID sendiri).
  - Pengecekan benturan IP Address lokal terhadap perangkat aktif lainnya.
  - Perubahan identitas jaringan mereset status koneksi kembali menjadi `'UNVERIFIED'`.
- **Response 200 OK:**
  ```json
  {
    "success": true,
    "message": "Data perangkat fisik berhasil diperbarui",
    "data": {
      "id": 1,
      "name": "Samsung TV 50 QLED - VIP 1",
      "ip_address": "192.168.1.205",
      "connection_status": "UNVERIFIED"
    }
  }
  ```

---

### 2.4 Menghapus Perangkat Fisik (Delete Device)
- **Method:** `DELETE`
- **Endpoint:** `/admin/hardware/devices/{id}`
- **RBAC:** `check_role:admin`
- **Aturan Validasi:**
  - Sistem **menolak penghapusan** jika perangkat fisik masih memiliki relasi pemetaan aktif (`mapping_status = 'ACTIVE'`).
  - Jika perangkat memiliki histori mapping lampau (`INACTIVE`), foreign key `ON DELETE RESTRICT` mencegah hard delete; perangkat ditandai nonaktif (`is_active = 0`) untuk menjaga audit trail.
- **Response 200 OK:**
  ```json
  {
    "success": true,
    "message": "Perangkat fisik berhasil dinonaktifkan dari inventaris"
  }
  ```
- **Response 409 Conflict:**
  ```json
  {
    "success": false,
    "message": "Perangkat tidak dapat dihapus karena sedang dipetakan aktif ke Station 01. Lepaskan pemetaan terlebih dahulu."
  }
  ```

---

### 2.5 Menghubungkan / Mengganti Perangkat Fisik ke Stasiun (Attach / Replace Mapping)
- **Method:** `POST`
- **Endpoint:** `/admin/stations/{stationId}/mappings`
- **RBAC:** `check_role:admin`
- **Request Body:**
  ```json
  {
    "physical_device_id": 3,
    "mapping_role": "DISPLAY_TV",
    "attach_notes": "Pemasangan Samsung Smart TV 43 inch pada Station 02",
    "detach_notes": "TV lama Samsung 50 dipindahkan ke ruang servis (diisi jika menggantikan TV lama)"
  }
  ```
- **Aturan Validasi & Concurrency Invariants (Atomic):**
  1. **Proteksi Sesi Berjalan:** Jika `devices.status == 'Digunakan'` atau transaksi `billing_status == 'RUNNING'` pada stasiun bersangkutan, request **DITOLAK KERAS** (HTTP 409 Conflict).
  2. **Single Active Mapping Invariant:**
     - Perangkat fisik yang dipilih **TIDAK BOLEH** sedang berstatus `ACTIVE` di stasiun manapun (ditegakkan via database index `uq_active_physical_device` & application lock).
     - Stasiun yang dipilih **TIDAK BOLEH** memiliki lebih dari satu mapping `ACTIVE` untuk `mapping_role` yang sama (ditegakkan via database index `uq_active_station_role`).
  3. **Kompatibilitas Layanan Stasiun:**
     - Stasiun PlayStation (PS4/PS5) wajib `DISPLAY_TV` dan `SMART_TV`.
     - Stasiun Biliar (Billiard) wajib `LIGHT_RELAY` dan `SMART_RELAY`.
  4. **Pembaruan Atomic & Pemisahan Audit Trail:**
     - Jika stasiun sudah memiliki perangkat aktif untuk role tersebut, mapping lama di-soft-detach (`INACTIVE`), `detached_at = now()`, `detached_by_user_id = auth()->id()`, dan `detach_notes = $detachNotes`.
     - Mapping baru dibuat: `ACTIVE`, `attached_at = now()`, `attached_by_user_id = auth()->id()`, dan `attach_notes = $attachNotes`.
- **Response 201 Created:**
  ```json
  {
    "success": true,
    "message": "Perangkat fisik berhasil dipetakan ke stasiun billing",
    "data": {
      "mapping_id": 12,
      "station_id": 2,
      "station_name": "Station 02",
      "physical_device_id": 3,
      "mapping_role": "DISPLAY_TV",
      "mapping_status": "ACTIVE",
      "attached_by_user_id": 1,
      "attached_at": "2026-11-04 22:00:00",
      "attach_notes": "Pemasangan Samsung Smart TV 43 inch pada Station 02"
    }
  }
  ```
- **Response 409 Conflict:**
  ```json
  {
    "success": false,
    "message": "Gagal mengubah mapping: Stasiun sedang memiliki sesi sewa berjalan. Perubahan hardware dilarang saat billing aktif."
  }
  ```

---

### 2.6 Melepas Pemetaan Hardware (Detach Mapping)
- **Method:** `POST`
- **Endpoint:** `/admin/stations/{stationId}/mappings/{mappingId}/detach`
- **RBAC:** `check_role:admin`
- **Request Body:**
  ```json
  {
    "detach_notes": "TV dilepas untuk keperluan servis panel layar berkala"
  }
  ```
- **Aturan Validasi:**
  1. Stasiun tidak boleh sedang memiliki transaksi aktif (`RUNNING`).
  2. Status diubah menjadi `INACTIVE`, `detached_at = now()`, aktor Admin pencopot dicatat (`detached_by_user_id = auth()->id()`), dan alasan (`detach_notes`) tersimpan rapi.
- **Response 200 OK:**
  ```json
  {
    "success": true,
    "message": "Pemetaan hardware berhasil dilepas (soft-detach)",
    "data": {
      "mapping_id": 12,
      "station_id": 2,
      "mapping_status": "INACTIVE",
      "detached_by_user_id": 1,
      "detached_at": "2026-11-04 22:05:00",
      "detach_notes": "TV dilepas untuk keperluan servis panel layar berkala"
    }
  }
  ```

---

## 3. FUTURE HARDWARE CONTRACT (SCOPE GATE G4 — BUKAN MVP G3-B)

Didokumentasikan sebagai target implementasi Gate G4 (Hardware Connectivity & Control PoC):
- `POST /admin/hardware/devices/{id}/test-connection` (Socket/HTTP ping probe)
- `POST /admin/hardware/devices/{id}/pair` (Handshake token otorisasi Tizen/Android TV)
- `POST /admin/hardware/devices/{id}/command` (Pengiriman perintah langsung Power, Volume, Relay Switch)
