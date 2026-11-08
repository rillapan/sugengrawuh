# SUGENG RAWUH — G3-A DATABASE SCHEMA & MIGRATION DESIGN (FINAL APPROVED REVISION)
**Document ID:** SR-G3A-DB-003-REV2  
**Project:** Sugeng Rawuh — Integrated Billing & POS Management System  
**Repository:** `sistem_kasir_PS-main`  
**Phase:** G3-A (Audit & Architecture Design — Final Approval Corrections)  
**Status Gate:** G2 APPROVED — DATABASE DESIGN ONLY (NO CODE MODIFICATIONS / NO MIGRATIONS EXECUTED)  
**Date:** November 2026  

---

## 1. EVALUASI STRUKTUR & PRINSIP INTEGRITAS BASIS DATA

Sistem basis data dirancang untuk memenuhi 4 pilar integritas:
1. **Zero-Impact pada Billing G2:** Tabel `devices` eksisting tidak dimodifikasi struktur kolomnya. Relasi transaksi `transactions.device_id` tetap utuh.
2. **Historical Mapping Protection (Audit Trail Abadi):**
   - Menghapus kebijakan `ON DELETE CASCADE` pada foreign key `station_device_mappings.station_id`.
   - Menggantinya dengan **`ON DELETE RESTRICT`**. Stasiun (`devices`) yang memiliki riwayat pemetaan hardware dilarang keras dihapus secara fisik (hard delete) untuk mencegah lenyapnya rekam jejak audit mutasi hardware.
3. **Database-Level Active Mapping Uniqueness:**
   - Satu `physical_device` hanya boleh memiliki maksimal **SATU** mapping `ACTIVE`.
   - Satu `station` hanya boleh memiliki maksimal **SATU** mapping `ACTIVE` untuk `mapping_role` yang sama (misal 1 stasiun hanya boleh punya 1 `DISPLAY_TV` aktif).
   - Penegakan dilakukan ganda: di level basis data menggunakan **Virtual Generated Columns + Unique Index** (kompatibel penuh dengan MySQL 5.7 & MySQL 8.0) dan di level aplikasi menggunakan transaksi atomic ber-lock (`lockForUpdate`).
4. **Audit Trail Lengkap & Pemisahan Aktor:**
   - Memisahkan secara tegas aktor dan alasan saat pemasangan (`attached_by_user_id`, `attach_notes`) dari aktor dan alasan saat pelepasan (`detached_by_user_id`, `detach_notes`).
5. **Status Pairing Awal yang Jujur:**
   - Kolom `pairing_status` memiliki default **`'UNVERIFIED'`** (bukan `'NOT_REQUIRED'`), karena kebutuhan token pairing belum diverifikasi sebelum masuk Gate G4.

---

## 2. DIAGRAM HUBUNGAN ENTITAS (ERD) FINAL

```mermaid
erDiagram
    devices ||--o{ device_playstation : "mendukung tarif sewa"
    playstations ||--o{ device_playstation : "diasosiasikan"
    devices ||--o{ transactions : "memiliki riwayat rental"
    devices ||--o{ station_device_mappings : "memiliki riwayat hardware (RESTRICT)"
    physical_devices ||--o{ station_device_mappings : "diasosiasikan ke (RESTRICT)"
    users ||--o{ station_device_mappings : "aktor pasang (attached_by)"
    users ||--o{ station_device_mappings : "aktor lepas (detached_by)"

    devices {
        bigint id PK
        varchar nama "e.g. MEJA 1, Station 01"
        bigint playstation_id "legacy default"
        varchar status "Tersedia / Digunakan"
        timestamp created_at
        timestamp updated_at
    }

    physical_devices {
        bigint id PK
        varchar name "e.g. Samsung 50 TV 1, Shelly Meja 1"
        varchar category "SMART_TV / SMART_RELAY"
        varchar brand "SAMSUNG / SHELLY"
        varchar model "AU7000 / 1PM Gen3 Mini"
        varchar mac_address UK "Normalized: AA:BB:CC:DD:EE:FF"
        varchar ip_address "192.168.1.xxx (RFC 1918)"
        varchar device_identifier "Vendor ID / Serial DUID"
        varchar connection_status "Default: UNVERIFIED"
        varchar pairing_status "Default: UNVERIFIED"
        text pairing_payload "Encrypted Token (G4)"
        json verified_capabilities "Verified in G4"
        datetime last_seen_at
        boolean is_active "Default: true"
        timestamp created_at
        timestamp updated_at
    }

    station_device_mappings {
        bigint id PK
        bigint station_id FK "devices.id (ON DELETE RESTRICT)"
        bigint physical_device_id FK "physical_devices.id (ON DELETE RESTRICT)"
        varchar mapping_role "DISPLAY_TV / LIGHT_RELAY"
        varchar mapping_status "ACTIVE / INACTIVE"
        bigint attached_by_user_id FK "users.id (Aktor Pasang)"
        bigint detached_by_user_id FK "users.id (Aktor Lepas)"
        datetime attached_at "Waktu pasang aktif"
        datetime detached_at "Waktu lepas (nullable)"
        text attach_notes "Alasan pemasangan hardware"
        text detach_notes "Alasan pelepasan hardware"
        string active_physical_device_id VIRTUAL "Generated for Unique Active Device"
        string active_station_role VIRTUAL "Generated for Unique Active Station Role"
        timestamp created_at
        timestamp updated_at
    }

    transactions {
        bigint id_transaksi PK
        bigint device_id FK "devices.id"
        bigint playstation_id FK "playstations.id"
        varchar billing_status "RUNNING / COMPLETED / etc"
        datetime started_at
        datetime expires_at
        datetime ended_at
    }
```

---

## 3. SPESIFIKASI SKEMA TABEL LENGKAP

### 3.1 Tabel Eksisting: `devices` (Station Billing — Tidak Berubah)
Struktur dipertahankan 100% tanpa perubahan:
- `id`: `BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`
- `nama`: `VARCHAR(255)`
- `playstation_id`: `BIGINT UNSIGNED NULLABLE`
- `status`: `VARCHAR(255) DEFAULT 'Tersedia'`
- `created_at`, `updated_at`: `TIMESTAMP`

---

### 3.2 Tabel Baru: `physical_devices` (Physical Device Registry)

| Nama Kolom | Tipe Data | Nullable | Default | Definisi & Aturan Validasi |
| :--- | :--- | :---: | :--- | :--- |
| `id` | `BIGINT UNSIGNED` | NO | AUTO_INCREMENT | Primary Key. |
| `name` | `VARCHAR(100)` | NO | - | Nama label inventaris perangkat (misal: "Samsung TV 50 Meja 1"). |
| `category` | `ENUM('SMART_TV','SMART_RELAY')` | NO | - | Kategori hardware fisik (menentukan batas mapping). |
| `brand` | `ENUM('SAMSUNG','SHELLY')` | NO | - | Merek fisik (SAMSUNG untuk Smart TV, SHELLY untuk Smart Relay). |
| `model` | `VARCHAR(50)` | YES | NULL | Nomor model pabrik (misal: "AU7000", "1PM Gen3 Mini"). |
| `mac_address` | `VARCHAR(17)` | NO | - | MAC Address terformat uppercase colon-separated (`AA:BB:CC:DD:EE:FF`). **Wajib Unik Global**. |
| `ip_address` | `VARCHAR(45)` | NO | - | Alamat IPv4 lokal statis/DHCP reserved (`192.168.x.x`, `10.x.x.x`). |
| `device_identifier` | `VARCHAR(100)` | YES | NULL | DUID/Serial pabrik (e.g. `shelly1pmminig3-xxxx`). |
| `connection_status` | `ENUM('UNVERIFIED','ONLINE','OFFLINE')` | NO | `'UNVERIFIED'` | **Status awal G3 adalah UNVERIFIED**. Transisi ke `ONLINE`/`OFFLINE` diverifikasi oleh probe fisik di Gate G4. |
| `pairing_status` | `ENUM('UNVERIFIED','NOT_REQUIRED','PENDING','PAIRED','FAILED')` | NO | `'UNVERIFIED'` | **Status awal G3 adalah UNVERIFIED** (tidak mengklaim NOT_REQUIRED sampai diverifikasi modelnya di G4). |
| `pairing_payload` | `TEXT` | YES | NULL | Token otorisasi API terenkripsi (`Crypt::encryptString()`). Dilarang plaintext. |
| `verified_capabilities` | `JSON` | YES | NULL | Metadata kemampuan hardware hasil verifikasi di G4. |
| `last_seen_at` | `DATETIME` | YES | NULL | Timestamp respon hardware terakhir (diisi oleh G4). |
| `is_active` | `TINYINT(1)` | NO | `1` | Status keaktifan inventaris toko. |
| `created_at` | `TIMESTAMP` | YES | NULL | Waktu registrasi awal. |
| `updated_at` | `TIMESTAMP` | YES | NULL | Waktu pembaruan data. |

#### Indeks & Batasan Tabel `physical_devices`:
- `UNIQUE KEY uq_physical_devices_mac (mac_address)`: Menjamin tidak ada registrasi hardware ganda.
- `INDEX idx_physical_devices_ip_active (ip_address, is_active)`: Mempercepat validasi benturan IP lokal antar-perangkat aktif di LAN.
- `INDEX idx_physical_devices_cat_brand (category, brand, is_active)`: Mempercepat query dropdown pilihan hardware saat mapping.

---

### 3.3 Tabel Baru: `station_device_mappings` (Station Hardware Mapping)

| Nama Kolom | Tipe Data | Nullable | Default | Definisi & Aturan Validasi |
| :--- | :--- | :---: | :--- | :--- |
| `id` | `BIGINT UNSIGNED` | NO | AUTO_INCREMENT | Primary Key. |
| `station_id` | `BIGINT UNSIGNED` | NO | - | Foreign Key ke `devices.id` dengan **`ON DELETE RESTRICT`**. Menolak penghapusan stasiun jika memiliki histori mapping. |
| `physical_device_id` | `BIGINT UNSIGNED` | NO | - | Foreign Key ke `physical_devices.id` dengan **`ON DELETE RESTRICT`**. |
| `mapping_role` | `ENUM('DISPLAY_TV','LIGHT_RELAY')` | NO | - | Peran perangkat pada stasiun bersangkutan. |
| `mapping_status` | `ENUM('ACTIVE','INACTIVE')` | NO | `'ACTIVE'` | Status keaktifan keterikatan pemetaan. |
| `attached_by_user_id` | `BIGINT UNSIGNED` | YES | NULL | Foreign Key ke `users.id` (Admin aktor yang memasang perangkat). |
| `detached_by_user_id` | `BIGINT UNSIGNED` | YES | NULL | Foreign Key ke `users.id` (Admin aktor yang melepas perangkat). |
| `attached_at` | `DATETIME` | NO | CURRENT_TIMESTAMP | Waktu perangkat fisik mulai aktif dipasangkan ke stasiun. |
| `detached_at` | `DATETIME` | YES | NULL | Waktu perangkat fisik dilepas dari stasiun (soft-detach). |
| `attach_notes` | `TEXT` | YES | NULL | Alasan spesifik pemasangan perangkat (Audit Trail). |
| `detach_notes` | `TEXT` | YES | NULL | Alasan spesifik pelepasan perangkat (Audit Trail). |
| `active_physical_device_id` | `VARCHAR(32)` | YES | VIRTUAL | Kolom virtual MySQL: `IF(mapping_status = 'ACTIVE', CAST(physical_device_id AS CHAR), NULL)`. |
| `active_station_role` | `VARCHAR(64)` | YES | VIRTUAL | Kolom virtual MySQL: `IF(mapping_status = 'ACTIVE', CONCAT(station_id, '_', mapping_role), NULL)`. |
| `created_at` | `TIMESTAMP` | YES | NULL | Timestamp pembuatan record. |
| `updated_at` | `TIMESTAMP` | YES | NULL | Timestamp update record. |

#### Indeks, Foreign Keys & Database Enforcement:
1. `FOREIGN KEY (station_id) REFERENCES devices(id) ON DELETE RESTRICT`
2. `FOREIGN KEY (physical_device_id) REFERENCES physical_devices(id) ON DELETE RESTRICT`
3. `FOREIGN KEY (attached_by_user_id) REFERENCES users(id) ON DELETE SET NULL`
4. `FOREIGN KEY (detached_by_user_id) REFERENCES users(id) ON DELETE SET NULL`
5. **Enforcement MySQL Active Mapping Uniqueness (Database Layer):**
   - **Unique Key 1 (Single Active Physical Device):**
     `UNIQUE KEY uq_active_physical_device (active_physical_device_id)`
     *Mekanisme:* Jika `mapping_status = 'ACTIVE'`, kolom virtual berisi string ID perangkat; jika `INACTIVE`, kolom berisi `NULL`. Di MySQL, nilai `NULL` tidak melanggar keunikan, tetapi nilai non-null dicek secara unik. Ini menjamin satu perangkat fisik **hanya boleh aktif di satu stasiun**.
   - **Unique Key 2 (Single Active Role per Station):**
     `UNIQUE KEY uq_active_station_role (active_station_role)`
     *Mekanisme:* Jika `mapping_status = 'ACTIVE'`, kolom virtual berisi `station_id_mapping_role` (misal `1_DISPLAY_TV`); jika `INACTIVE`, bernilai `NULL`. Ini menjamin satu stasiun **hanya boleh memiliki satu perangkat aktif untuk role yang sama**.
6. `INDEX idx_station_mapping_history (station_id, attached_at)`: Mempercepat pembacaan riwayat mutasi stasiun.

---

## 4. INTEGRITAS TRANSAKSI & LOGIKA CONCURRENCY (APPLICATION LAYER)

Selain proteksi level database di atas, aplikasi menerapkan transaksi database atomic ber-lock:

```php
DB::transaction(function () use ($stationId, $physicalDeviceId, $mappingRole, $userId, $attachNotes, $detachNotes) {
    // 1. Lock baris stasiun untuk verifikasi status operasional
    $station = Device::where('id', $stationId)->lockForUpdate()->firstOrFail();
    
    // Invarian: Tolak perubahan jika stasiun sedang digunakan kasir
    if ($station->status === 'Digunakan') {
        throw new \DomainException("Stasiun {$station->nama} sedang digunakan kasir. Tidak dapat mengubah mapping saat sesi berjalan.");
    }
    
    // Invarian: Tolak jika ada sesi billing RUNNING di G2
    $hasRunningBilling = Transaction::where('device_id', $stationId)
        ->where('billing_status', Transaction::BILLING_RUNNING)
        ->exists();
    if ($hasRunningBilling) {
        throw new \DomainException("Stasiun {$station->nama} memiliki sesi transaksi RUNNING. Selesaikan billing terlebih dahulu.");
    }

    // 2. Lock baris perangkat fisik
    $device = PhysicalDevice::where('id', $physicalDeviceId)->lockForUpdate()->firstOrFail();

    // Invarian: Validasi Kompatibilitas Stasiun vs Hardware
    // Jika stasiun PlayStation, wajib SMART_TV & DISPLAY_TV
    // Jika stasiun Biliar, wajib SMART_RELAY & LIGHT_RELAY
    $stationServiceNames = $station->playstations->pluck('nama')->implode(' ');
    $isPlaystation = preg_match('/PS4|PS5/i', $stationServiceNames);
    $isBilliard = preg_match('/BILLIARD/i', $stationServiceNames);

    if ($isPlaystation && ($device->category !== 'SMART_TV' || $mappingRole !== 'DISPLAY_TV')) {
        throw new \DomainException("Stasiun PlayStation hanya dapat menerima mapping Smart TV dengan peran DISPLAY_TV.");
    }
    if ($isBilliard && ($device->category !== 'SMART_RELAY' || $mappingRole !== 'LIGHT_RELAY')) {
        throw new \DomainException("Stasiun Biliar hanya dapat menerima mapping Smart Relay dengan peran LIGHT_RELAY.");
    }

    // Invarian: Single Active Mapping Check (Aplikasi + DB Lock)
    $alreadyActive = StationDeviceMapping::where('physical_device_id', $device->id)
        ->where('mapping_status', 'ACTIVE')
        ->exists();
    if ($alreadyActive) {
        throw new \DomainException("Perangkat fisik {$device->name} sudah dipetakan aktif ke stasiun lain.");
    }

    // 3. Replacement Aman: Soft-detach mapping lama pada role yang sama (jika ada)
    $existingMapping = StationDeviceMapping::where('station_id', $stationId)
        ->where('mapping_role', $mappingRole)
        ->where('mapping_status', 'ACTIVE')
        ->lockForUpdate()
        ->first();

    if ($existingMapping) {
        $existingMapping->update([
            'mapping_status' => 'INACTIVE',
            'detached_at' => now(),
            'detached_by_user_id' => $userId,
            'detach_notes' => $detachNotes ?? 'Digantikan oleh perangkat baru (' . $device->name . ')'
        ]);
    }

    // 4. Buat mapping baru
    StationDeviceMapping::create([
        'station_id' => $stationId,
        'physical_device_id' => $device->id,
        'mapping_role' => $mappingRole,
        'mapping_status' => 'ACTIVE',
        'attached_by_user_id' => $userId,
        'attached_at' => now(),
        'attach_notes' => $attachNotes
    ]);
});
```

---

## 5. STRATEGI MIGRASI & ROLLBACK

1. **File Migrasi:**
   - `2026_11_05_000001_create_physical_devices_table.php`
   - `2026_11_05_000002_create_station_device_mappings_table.php`
2. **Definisi Blueprint Kolom Virtual di Laravel:**
   ```php
   // Di dalam migrasi station_device_mappings:
   $table->string('active_physical_device_id', 32)
         ->virtualAs("IF(mapping_status = 'ACTIVE', CAST(physical_device_id AS CHAR), NULL)")
         ->nullable();
   $table->string('active_station_role', 64)
         ->virtualAs("IF(mapping_status = 'ACTIVE', CONCAT(station_id, '_', mapping_role), NULL)")
         ->nullable();

   $table->unique('active_physical_device_id', 'uq_active_physical_device');
   $table->unique('active_station_role', 'uq_active_station_role');
   ```
3. **Rollback Bersih (`down()`):**
   ```php
   Schema::dropIfExists('station_device_mappings');
   Schema::dropIfExists('physical_devices');
   ```
