# SUGENG RAWUH — G3-A EXISTING SYSTEM AUDIT REPORT
**Document ID:** SR-G3A-AUDIT-001  
**Project:** Sugeng Rawuh — Integrated Billing & POS Management System  
**Repository:** `sistem_kasir_PS-main`  
**Phase:** G3-A (Audit & Architecture Design)  
**Status Gate:** G2 APPROVED — G3-A AUDIT ONLY (NO CODE MODIFICATIONS)  
**Date:** November 2026  

---

## 1. RINGKASAN EKSEKUTIF AUDIT

Audit mendalam berbasis kode sumber aktual terhadap repository `sistem_kasir_PS-main` telah dilaksanakan untuk memetakan arsitektur entitas *device*, *station*, *layanan*, *transaksi*, dan *keamanan/RBAC*.

### Temuan Kunci Arsitektur:
1. **Entitas `devices` Eksisting adalah "Billing Station" (Spot Rental / Meja / Bilik), BUKAN Perangkat Fisik (Hardware):**
   - Kolom tabel `devices` hanya terdiri dari `id`, `nama`, `playstation_id` (legacy), `status` (`Tersedia`/`Digunakan`), dan timestamps (`created_at`, `updated_at`).
   - Tidak ada atribut jaringan (`ip_address`, `mac_address`), informasi vendor (`brand`, `model`), protokol kontrol, status pairing, maupun identitas IoT.
   - Contoh data aktual di seeder dan sistem: `MEJA 1`, `MEJA 2`, `MEJA 3`, `Station 01`, `Station 02`. Ini merepresentasikan meja biliar atau bilik rental fisik, bukan Smart TV atau Smart Relay.
2. **Entitas `playstations` adalah "Kategori Layanan / Tier Harga", BUKAN Konsol Fisik:**
   - Tabel `playstations` menyimpan nama paket sewa seperti `PS4`, `PS5`, `PS5 STANDAR`, `PS5 VIP`, `BILLIARD PAGI`, `BILLIARD SORE`.
   - Menghubungkan tarif dasar (`harga`) dan paket jam bertahap (`HourlyPrice`).
3. **Hubungan Station ke Kategori Layanan:**
   - Telah menggunakan relasi many-to-many via tabel pivot `device_playstation` ([DevicePlaystationSeeder.php](file:///Users/mohammadkhairilaffan/Rillapancode/billing%20sugeng%20rawuh/sistem_kasir_PS-main/database/seeders/DevicePlaystationSeeder.php)).
   - Satu Station (misal `MEJA 1`) dapat melayani beberapa jenis tarif (`BILLIARD PAGI`, `BILLIARD SORE`). Satu Station PS dapat melayani beberapa varian PS.
4. **Lifecycle Transaksi G2 yang Sudah Approved:**
   - Model `Transaction` ([Transaction.php](file:///Users/mohammadkhairilaffan/Rillapancode/billing%20sugeng%20rawuh/sistem_kasir_PS-main/app/Models/Transaction.php)) mengunci status billing dengan konstanta G2: `BILLING_IDLE`, `BILLING_RUNNING`, `BILLING_EXPIRED_PENDING_ACTION`, `BILLING_STOPPED_EARLY`, dan `BILLING_COMPLETED`.
   - Transaksi terikat langsung ke `device_id` (`devices.id`).
   - Perubahan hardware tidak boleh merusak riwayat transaksi dan tidak boleh memutus relasi foreign key `transactions.device_id`.
5. **Absensi Hardware Driver / Network Protocol:**
   - Tidak ditemukan kode penanganan IP, MAC, WebSockets, REST client TV (Tizen/Android TV), ADB, maupun Shelly RPC di seluruh codebase.

---

## 2. AUDIT MODEL & RELASI

### 2.1 Model `Device` (`app/Models/Device.php`)
- **File:** [app/Models/Device.php](file:///Users/mohammadkhairilaffan/Rillapancode/billing%20sugeng%20rawuh/sistem_kasir_PS-main/app/Models/Device.php)
- **Tabel Basis Data:** `devices`
- **Kolom Tabel:**
  - `id`: BigInt unsigned auto-increment (Primary Key)
  - `nama`: Varchar(255) (e.g. "Station 01", "MEJA 1")
  - `playstation_id`: BigInt unsigned (kolom legacy sebelum migrasi many-to-many)
  - `status`: Varchar(255) nullable (`Tersedia` atau `Digunakan`)
  - `created_at`, `updated_at`: Timestamps
- **Relasi Eloquent:**
  - `playstations()`: `belongsToMany(Playstation::class, 'device_playstation')`
  - `playstation()`: `belongsTo(Playstation::class)` (fallback backward compatibility)
  - `transaction()`: `hasMany(Transaction::class)`
- **Mutator & Scope:**
  - `scopeAvailable($query)`: filter status = `Tersedia`
  - `getPlaystationNamesAttribute()`: mengembalikan string gabungan nama layanan (misal "PS5 STANDAR, PS5 VIP" atau "BILLIARD PAGI, BILLIARD SORE")

### 2.2 Model `Playstation` (`app/Models/Playstation.php`)
- **File:** [app/Models/Playstation.php](file:///Users/mohammadkhairilaffan/Rillapancode/billing%20sugeng%20rawuh/sistem_kasir_PS-main/app/Models/Playstation.php)
- **Tabel Basis Data:** `playstations`
- **Fungsi Aktual:** Mewakili *Katalog Paket Sewa / Jenis Layanan*, bukan konsol game fisik.
- **Relasi Eloquent:**
  - `device()`: `hasMany(Device::class)` (legacy)
  - `hourlyPrices()`: `hasMany(HourlyPrice::class)` (skema harga berjenjang / paket jam)

### 2.3 Model `Transaction` (`app/Models/Transaction.php`)
- **File:** [app/Models/Transaction.php](file:///Users/mohammadkhairilaffan/Rillapancode/billing%20sugeng%20rawuh/sistem_kasir_PS-main/app/Models/Transaction.php)
- **Primary Key:** `id_transaksi` (BigInt unsigned)
- **Kolom Utama G2:**
  - `device_id`: FK ke `devices.id`
  - `playstation_id`: FK ke `playstations.id`
  - `tipe_transaksi`: enum/varchar (`prepaid`, `postpaid`, `custom_package`)
  - `started_at`: Datetime (waktu mulai absolut server)
  - `expires_at`: Datetime nullable (waktu berakhir absolut server untuk prepaid & paket)
  - `ended_at`: Datetime nullable (waktu aktual sesi ditutup)
  - `billing_status`: Status G2 (`IDLE`, `RUNNING`, `EXPIRED_PENDING_ACTION`, `STOPPED_EARLY`, `COMPLETED`)
  - `payment_status`: `paid`, `unpaid`
  - `waktu_mulai`, `waktu_Selesai`: String waktu legacy (`H:i`)
- **Relasi Eloquent:**
  - `device()`: `belongsTo(Device::class)`
  - `playstation()`: `belongsTo(Playstation::class)`
  - `transactionFnbs()`: `hasMany(TransactionFnb::class, 'transaction_id', 'id_transaksi')`
  - `custom_package()`: `belongsTo(CustomPackage::class, 'custom_package_id')`

---

## 3. AUDIT CONTROLLER & LIFECYCLE OPERASIONAL

### 3.1 `DeviceController.php`
- **File:** [app/Http/Controllers/DeviceController.php](file:///Users/mohammadkhairilaffan/Rillapancode/billing%20sugeng%20rawuh/sistem_kasir_PS-main/app/Http/Controllers/DeviceController.php)
- **Peran:** Mengelola tampilan kartu station billing di operasional dan master data station.
- **Metode Utama:**
  - `index()`:
    - Melakukan evaluasi berkala status setiap station (`Tersedia` vs `Digunakan`) berdasarkan transaksi aktif hari ini.
    - Menghitung jumlah station tersedia (`$countAvailable`) dan digunakan (`$countInUse`).
    - Menyusun data timer countdown untuk prepaid dan custom package, serta stopwatch untuk postpaid (Lost Time).
  - `create()`, `store()`:
    - Menambah station baru dengan memilih nama station dan checkbox jenis layanan (`playstation_ids[]`).
  - `edit()`, `update()`:
    - Memperbarui nama station dan sinkronisasi relasi `device_playstation`.
  - `destroy()`:
    - Menghapus record station dari `devices`.
  - `stopDevice($id)`:
    - Endpoint operasional (Admin & Kasir) untuk menghentikan billing secara manual.
    - Mengubah `billing_status` menjadi `STOPPED_EARLY` (jika sebelum `expires_at`) atau `COMPLETED`.
    - Mengembalikan status `devices.status` menjadi `Tersedia`.

### 3.2 `TransactionController.php`
- **File:** [app/Http/Controllers/TransactionController.php](file:///Users/mohammadkhairilaffan/Rillapancode/billing%20sugeng%20rawuh/sistem_kasir_PS-main/app/Http/Controllers/TransactionController.php)
- **Alur Mulai Transaksi (`store` baris 279-615):**
  - Mengambil station yang berstatus `Tersedia`.
  - Memasang pessimistic write lock (`Device::where('id', ...)->lockForUpdate()`) untuk mencegah race condition / double-booking station.
  - Memvalidasi bahwa tidak ada transaksi aktif dengan `billing_status = 'RUNNING'` pada station tersebut.
  - Menyimpan transaksi, mengalokasikan timer, dan mengubah status station menjadi `Digunakan`.
- **Alur Pembayaran (`processPayment` baris 66-142):**
  - Mengunci baris transaksi dengan `lockForUpdate()`.
  - Mengubah `payment_status` menjadi `paid`.
  - Jika sesi billing sudah selesai, status station diubah kembali menjadi `Tersedia`.

---

## 4. AUDIT ROUTE & KEAMANAN / RBAC

### 4.1 Peta Middleware & Hak Akses ([routes/web.php](file:///Users/mohammadkhairilaffan/Rillapancode/billing%20sugeng%20rawuh/sistem_kasir_PS-main/routes/web.php))
1. **Universal Authenticated (`auth`):**
   - `/home`, `/profile`, `/server-time`, sinkronisasi timer `/device/timers`.
2. **Monitoring Read-Only (`check_role:admin,kasir,owner`):**
   - `GET /device`: Membuka dashboard station billing dan status okupansi.
   - `GET /transaction`: Melihat riwayat dan daftar transaksi.
   - `GET /expense`: Monitoring pengeluaran.
3. **Operational Mutations (`check_role:admin,kasir`):**
   - `GET /transaction/create`, `POST /transaction`: Membuka sesi billing baru.
   - `POST /transaction/{id}/process-payment`: Pembayaran kasir.
   - `POST /device/{id}/stop`: Memberhentikan sesi sewa station.
   - `GET /booking/{id}`, `GET /booking/{id}/add`: Reservasi station.
4. **Master Management & Configuration (`check_role:admin`):**
   - `GET /device/create`, `POST /device`: Menambah station billing baru.
   - `GET /device/{id}/edit`, `PUT /device/{id}`: Mengubah konfigurasi station.
   - `DELETE /device/{id}`: Menghapus station billing.
   - Master data User, Jenis Playstation, FnB, Hourly Prices, Custom Package, App Settings.
5. **Laporan & Supervisi (`check_role:admin,owner`):**
   - Laporan penjualan transaksi, export PDF, export Excel.

### 4.2 Middleware `CheckRole` ([CheckRole.php](file:///Users/mohammadkhairilaffan/Rillapancode/billing%20sugeng%20rawuh/sistem_kasir_PS-main/app/Http/Middleware/CheckRole.php))
- Memeriksa `auth()->user()->role` terhadap daftar parameter role yang diizinkan.
- Admin memiliki wewenang superuser.
- Kasir diizinkan melakukan operasional harian tetapi dibatasi dari perubahan master dan setting.
- Owner memiliki hak monitoring penuh (read-only) terhadap operasional dan laporan.

---

## 5. AUDIT VIEW EKSISTING

### 5.1 Halaman Data Perangkat (`resources/views/device/index.blade.php`)
- **Fungsi:** Dashboard operasional kasir untuk memantau status stasiun secara real-time.
- **Komponen:**
  - Kartu statistik atas: *Data Perangkat Tersedia* (Hijau) dan *Data Perangkat Digunakan* (Kuning/Oranye).
  - Grid kartu per station: Menampilkan nama station, badge tipe transaksi (Paket, Custom Paket, Lost Time), nama pelanggan, jam mulai, estimasi selesai, dan sisa waktu timer aktif.
  - Tombol aksi kasir: Tombol "Berhenti" (`stopDevice`) dengan modal konfirmasi Bootstrap 4.
  - Tombol admin: "Tambah Data" mengarah ke `/device/create`.
- **Keterbatasan Saat Ini:** Tidak menampilkan indikator fisik apapun (IP TV, status Smart TV online/offline, status relay biliar, lampu menyala/padam).

### 5.2 Halaman Form Tambah/Edit Perangkat (`create.blade.php` & `edit.blade.php`)
- **Input yang Tersedia:**
  - `nama`: Nama stasiun (misal "Station 03", "Meja 4").
  - `playstation_ids[]`: Checkbox multi-select tipe layanan yang didukung stasiun tersebut.
- **Kondisi:** Tidak terdapat field MAC Address, IP Address, Brand, Model, atau konfigurasi perangkat keras.

---

## 6. KESIMPULAN AUDIT SISTEM EKSISTING

| Aspek Audit | Temuan Aktual | Implikasi untuk Fase G3 |
| :--- | :--- | :--- |
| **Definisi `devices`** | Merepresentasikan *Billing Station / Rental Spot* | Jangan diubah menjadi tabel perangkat keras fisik; pertahankan sebagai Station Billing agar transaksi G2 tidak terganggu. |
| **Definisi `playstations`** | Merepresentasikan *Kategori Tarif / Layanan Sewa* | Hubungan station ke layanan tetap via `device_playstation`. |
| **Data Jaringan / IoT Eksisting** | Belum ada (nol baris kode untuk MAC/IP/Hardware) | Diperlukan modul terpisah untuk *Physical Device Registry* dan *Station Hardware Mapping*. |
| **Integritas Transaksi** | `transactions.device_id` mengikat ke `devices.id` | Penggantian Smart TV atau Smart Relay tidak boleh mengubah atau menghapus `devices.id`. |
| **RBAC Matrix** | ADMIN (Konfigurasi), KASIR (Operasional), OWNER (Supervisi) | Registry dan Mapping Hardware harus masuk wewenang ADMIN; status koneksi dapat dilihat KASIR dan OWNER. |

Audit menyimpulkan bahwa **Alternatif B (Pemisahan Station Billing, Physical Device Registry, dan Station Device Mapping) adalah satu-satunya pilihan arsitektur yang aman dan benar secara teknis**.
