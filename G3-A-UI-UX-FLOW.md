# SUGENG RAWUH — G3-A UI/UX FLOW & INTERACTION DESIGN (REVISED)
**Document ID:** SR-G3A-UIUX-005-REV1  
**Project:** Sugeng Rawuh — Integrated Billing & POS Management System  
**Repository:** `sistem_kasir_PS-main`  
**Phase:** G3-A (Audit & Architecture Design — Revised)  
**Status Gate:** G2 APPROVED — UI/UX SPECIFICATION ONLY (NO CODE/VIEW MODIFICATIONS)  
**Framework:** Laravel Blade + Bootstrap 4 (SB Admin 2 Eksisting — Tanpa Redesign Aplikasi)  
**Date:** November 2026  

---

## 1. PRINSIP DESAIN UI/UX G3

1. **Konsistensi Framework Eksisting:**
   - Seluruh tampilan memanfaatkan Bootstrap 4 dan styling SB Admin 2 yang sudah digunakan sistem.
   - Tidak melakukan redesign tata letak global, tidak menambahkan font/framework eksternal, dan tidak mengubah antarmuka alur transaksi kasir G2.
2. **Kebenaran Visual (No Fake Statuses):**
   - Pada Gate G3, karena belum ada modul network probe aktif, seluruh perangkat yang terdaftar di registry menampilkan badge **`[TERDAFTAR]`** / **`[UNVERIFIED]`** (Badge Secondary / Abu-abu).
   - Sistem **tidak boleh** menampilkan badge hijau `ONLINE` tanpa adanya bukti respon probe jaringan aktual (yang baru akan diimplementasikan pada Gate G4).
3. **Pemisahan Peran Pengguna (RBAC UX):**
   - **KASIR:** Hanya melihat badge informasi perangkat yang terpasang pada stasiun di dashboard `/device`. Tidak memiliki akses ke menu registrasi atau perubahan hardware.
   - **ADMIN:** Memiliki akses ke menu sidebar baru *Hardware & IoT* untuk mendaftarkan unit TV/Relay dan memetakan stasiun.
   - **OWNER:** Memiliki akses pantau (*read-only*) terhadap daftar perangkat fisik dan status pemetaan.

---

## 2. INTEGRASI STRUKTUR SIDEBAR SB ADMIN 2

Penambahan menu baru di [`sidebar.blade.php`](file:///Users/mohammadkhairilaffan/Rillapancode/billing%20sugeng%20rawuh/sistem_kasir_PS-main/resources/views/layouts/sidebar.blade.php) khusus untuk Admin:

```text
├── DASHBOARD
│
├── ADMIN MENU (Khusus Admin)
│   ├── Manajemen User
│   ├── Manajemen Jam Kerja
│   ├── Jenis Playstation
│   ├── Pengaturan Aplikasi
│   └── Kelola Hardware (BARU - G3-B)
│       ├── Device Registry (Daftar Smart TV & Relay)
│       └── Station Mapping (Pemetaan Hardware Stasiun)
│
├── OPERASIONAL (Admin, Kasir, Owner)
│   ├── Data Perangkat (/device - Dilengkapi Badge Hardware)
│   └── Transaksi
│
└── LAPORAN (Admin, Owner)
    └── Laporan Penjualan
```

---

## 3. WIREFRAME & INTERAKSI KOMPONEN BLADE

### 3.1 Peningkatan Halaman Operasional Kasir: Data Perangkat (`/device`)

Kartu stasiun tetap mempertahankan informasi billing G2, diperkaya dengan informasi pemetaan hardware yang jujur:

```text
+-----------------------------------------------------------------------------------------+
| Data Perangkat                                                [+ Tambah Station (Admin)]|
+-----------------------------------------------------------------------------------------+
| [Tersedia: 8] (Hijau)                                           [Digunakan: 4] (Kuning) |
+-----------------------------------------------------------------------------------------+
| Grid Stasiun:                                                                           |
|                                                                                         |
| +-----------------------------+  +-----------------------------+  +--------------------+|
| | Station 01        [Paket]   |  | MEJA 1            [Billiard]|  | Station 02 [Kosong]|
| | Status: Digunakan           |  | Status: Digunakan           |  | Status: Tersedia   |
| | Layanan: PS5 STANDAR        |  | Layanan: BILLIARD SORE      |  | Layanan: PS4 PRO   |
| | Pelanggan: Mas Budi         |  | Pelanggan: Mas Dimas        |  |                    |
| | Timer: 01:24:10             |  | Timer: 00:45:22 (Lost Time) |  |                    |
| +-----------------------------+  +-----------------------------+  +--------------------+|
| | Hardware Terpasang:         |  | Hardware Terpasang:         |  | Hardware:          |
| | [TV] Samsung 50" (QLED)     |  | [RELAY] Shelly 1PM (Lampu)  |  | [BELUM DIPETAKAN]  |
| | IP: 192.168.1.201           |  | IP: 192.168.1.211           |  | (Badge Sekunder)   |
| | Status: [UNVERIFIED]        |  | Status: [UNVERIFIED]        |  |                    |
| +-----------------------------+  +-----------------------------+  +--------------------+|
| | [ Tombol Berhenti ] (Kasir) |  | [ Tombol Berhenti ] (Kasir) |  | [ Mulai Sewa ]     |
| +-----------------------------+  +-----------------------------+  +--------------------+|
+-----------------------------------------------------------------------------------------+
```

#### Aturan Badge Visual Hardware pada Kartu Stasiun:
- **Badge Abu-abu (`badge-secondary`):** `[UNVERIFIED]` — Perangkat terkonfigurasi di sistem, belum diuji oleh probe fisik G4.
- **Badge Kuning (`badge-warning`):** `[BELUM TERPASANG]` — Stasiun belum dipasangkan dengan perangkat fisik di menu mapping.

---

### 3.2 Halaman Master: Device Registry (`/admin/hardware/devices`)

Menampilkan inventaris seluruh Smart TV dan Shelly Relay yang terdaftar di toko:

```text
+-----------------------------------------------------------------------------------------+
| Physical Device Registry (Master Perangkat Keras)              [+ Daftarkan Hardware]   |
+-----------------------------------------------------------------------------------------+
| Filter: [Semua Kategori v] [Semua Brand v]                                              |
+-----------------------------------------------------------------------------------------+
| No | Nama Perangkat        | Kategori   | Brand   | MAC Address       | IP Address    | Status     | Pemetaan Saat Ini | Aksi              |
|----+-----------------------+------------+---------+-------------------+---------------+------------+-------------------+-------------------|
| 1  | Samsung 50" TV Meja 1 | SMART_TV   | SAMSUNG | 84:C0:EF:11:22:33 | 192.168.1.201 | UNVERIFIED | Station 01        | [Detail] [Edit]   |
| 2  | Samsung 43" Smart TV 2| SMART_TV   | SAMSUNG | A0:9F:10:44:55:66 | 192.168.1.202 | UNVERIFIED | Station 02        | [Detail] [Edit]   |
| 3  | Shelly Relay Lampu 1  | SMART_RELAY| SHELLY  | E8:DB:84:AA:BB:01 | 192.168.1.211 | UNVERIFIED | MEJA 1            | [Detail] [Edit]   |
| 4  | Shelly Relay Lampu 2  | SMART_RELAY| SHELLY  | E8:DB:84:AA:BB:02 | 192.168.1.212 | UNVERIFIED | MEJA 2            | [Detail] [Edit]   |
| 5  | Samsung 50" Cadangan  | SMART_TV   | SAMSUNG | 84:C0:EF:99:88:77 | 192.168.1.205 | UNVERIFIED | (Belum Dipakai)   | [Detail] [Edit]   |
+-----------------------------------------------------------------------------------------+
```

#### Form Modal Daftarkan Hardware Baru (Scope G3-B MVP):
1. **Nama Perangkat:** Input teks (contoh: "Samsung TV 50 Meja 1").
2. **Kategori:** Radio button: `(o) Smart TV` atau `( ) Smart Relay`.
3. **Brand:** Dropdown otomatis:
   - Jika Smart TV: `Samsung`.
   - Jika Smart Relay: `Shelly`.
4. **Nomor Model (Opsional):** Input teks (contoh: "AU7000", "1PM Gen3 Mini").
5. **MAC Address:** Input teks dengan panduan otomatis. Sistem secara otomatis menormalisasi format saat input diketik atau disubmit.
6. **IP Address:** Input teks IPv4 dengan helper text: *"Pastikan IP telah didaftarkan pada DHCP Reservation di router toko."*
7. **Device Identifier (Opsional):** Input teks serial / DUID perangkat.
8. **Tombol Tindakan:**
   - `[Batal]` (Tutup modal)
   - `[Simpan Perangkat]` (Menyimpan data dengan status `UNVERIFIED`).
   *(Catatan: Tombol "Uji Koneksi / Probe" dipindahkan ke Gate G4).*

---

### 3.3 Halaman & Modal: Station Device Mapping (`/admin/hardware/mappings`)

Tempat Admin memetakan stasiun billing ke perangkat fisik:

```text
+-----------------------------------------------------------------------------------------+
| Pemetaan Stasiun ke Hardware (Station Mapping)                                          |
+-----------------------------------------------------------------------------------------+
| Pilih Stasiun: [ Station 01 (PlayStation 5)                                          v ]|
+-----------------------------------------------------------------------------------------+
| Status Stasiun: Tersedia | Sesi Transaksi: Tidak Ada (IDLE)                             |
|                                                                                         |
| Hardware Aktif:                                                                         |
| • Peran: DISPLAY_TV                                                                     |
| • Perangkat: Samsung 50" TV Meja 1 (MAC: 84:C0:EF:11:22:33 | IP: 192.168.1.201)        |
| • Dipasangkan Sejak: 04 Nov 2026, 14:00 (Oleh: Admin Amin)                              |
|                                                                                         |
| [ Ganti Perangkat ]                                   [ Lepas Pemetaan (Detach) ]       |
+-----------------------------------------------------------------------------------------+
| Riwayat Pemetaan Stasiun Ini (Audit Trail):                                             |
| • 01 Nov 2026 - 04 Nov 2026: Samsung 43" (Dilepas: TV diganti ke ukuran 50 inch)       |
+-----------------------------------------------------------------------------------------+
```

#### Alur & Validasi Modal Ganti / Pasang Hardware:
1. **Pemeriksaan Status Stasiun Real-Time:**
   - Jika `status == 'Digunakan'`, modal menampilkan peringatan warna merah:
     > **PERINGATAN:** Stasiun ini sedang memiliki sesi billing aktif! Anda tidak dapat mengubah atau melepas hardware saat pelanggan sedang bermain.
     Tombol simpan otomatis dinonaktifkan (`disabled`).
   - Jika `status == 'Tersedia'`, form aktif.
2. **Pilihan Perangkat yang Kompatibel:**
   - Dropdown hanya memuat perangkat yang belum aktif di stasiun lain (*Single Active Invariant*).
   - Stasiun PlayStation hanya memunculkan daftar Smart TV. Stasiun Biliar hanya memunculkan daftar Shelly Relay.
3. **Input Wajib: Catatan Alasan (Audit Reason):**
   - Wajib mengisi alasan penggantian (misal: "TV lama diservis, pasang unit cadangan").
4. **Konfirmasi Pelepasan (Soft-Detach):**
   - Dialog konfirmasi: *"Apakah Anda yakin ingin melepas hardware dari stasiun ini? Riwayat pemetaan akan tetap tersimpan sebagai audit trail."*
