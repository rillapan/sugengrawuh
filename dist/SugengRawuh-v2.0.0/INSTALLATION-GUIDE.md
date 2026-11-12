x`# Sugeng Rawuh Billing & POS — Panduan Instalasi & Pengoperasian
**Target Rilis:** v2.0.0 (Release Candidate)
**Sistem Operasi Target:** Windows 11 Home Single Language 64-bit (x86_64 / AMD64)
**Lokasi Penerapan:** PC Kasir Sugeng Rawuh 2

---

## 1. Persyaratan Sistem & Lingkungan

Sebelum menjalankan instalasi di PC Kasir, pastikan spesifikasi perangkat keras dan jaringan telah memenuhi kriteria berikut:

| Komponen | Spesifikasi Minimum | Rekomendasi Lapangan (Sugeng Rawuh 2) |
| :--- | :--- | :--- |
| **Prosesor** | AMD64 / Intel 64-bit (2 Core) | Intel Core i3 / Ryzen 3 atau lebih tinggi (Virtualization Aktif di BIOS) |
| **Memori (RAM)** | 6 GB fisik | 8 GB fisik (minimal 4 GB bebas sebelum Docker berjalan) |
| **Penyimpanan (Disk)** | 10 GB ruang kosong pada target drive | 20 GB bebas di drive `D:\` (Aplikasi & Data) + 10 GB bebas di drive `C:\` (WSL2 VHDX) |
| **Sistem Operasi** | Windows 10 x64 Build 19041+ | Windows 11 Home Single Language 64-bit (Build 22000+) |
| **Virtualisasi BIOS** | VT-x (Intel) atau AMD-V (AMD) | Wajib berstatus **Enabled** pada UEFI/BIOS |
| **Subsystem & Engine** | WSL2 (Windows Subsystem for Linux) | WSL2 Linux Kernel Update + Docker Desktop 4.x (WSL2 Backend) |
| **Jaringan (LAN)** | Kabel Ethernet Cat5e/Cat6 ke Switch | Terkoneksi ke Router MikroTik Sugeng Rawuh 2 (Subnet 192.168.1.0/24) |

> [!IMPORTANT]
> **Catatan Khusus Windows 11 Home:**
> Windows 11 Home tidak mendukung Docker berjalan sebagai Windows System Service tanpa sesi login pengguna. Oleh karena itu, PC Kasir harus diatur agar login otomatis (*Auto-Logon*) menggunakan akun Windows kasir saat komputer dinyalakan.

---

## 2. Skenario A: Instalasi Baru (Fresh Installation)

Gunakan skenario ini jika PC Kasir belum pernah dipasang Sugeng Rawuh sebelumnya.

### Langkah 1: Persiapan File
1. Salin folder paket instalasi atau file `SugengRawuh-Setup-v2.0.0.exe` ke harddisk/flashdisk.
2. Jika komputer kasir belum memiliki koneksi internet, pastikan paket pendukung berikut sudah disiapkan di folder `installer\prereqs\` (opsional jika sudah diinstal sebelumnya):
   - `wsl_update_x64.msi`
   - `DockerDesktopInstaller.exe`

### Langkah 2: Menjalankan Installer
1. Klik kanan pada `SugengRawuh-Setup-v2.0.0.exe` dan pilih **Run as administrator**.
2. Pada layar pemilihan lokasi instalasi, pilih folder tujuan (misalnya: `D:\SugengRawuh` atau `C:\SugengRawuh`).
3. Wizard akan melakukan pra-pemeriksaan (*Preflight Check*):
   - Memeriksa arsitektur 64-bit, RAM, kapasitas disk drive `C:` dan drive target.
   - Memeriksa fitur virtualisasi BIOS (VT-x / AMD-V).
   - Memeriksa status WSL2 dan Docker Desktop.
4. Klik **Next** dan **Install**.
5. Installer akan secara otomatis:
   - Mengekstrak file aplikasi, konfigurasi Docker, dan skrip otomasi.
   - Mengaktifkan fitur Windows WSL2 & Virtual Machine Platform.
   - Memuat offline image Docker (`sugeng-rawuh-app:2.0.0`, `mysql:8.0`, `nginx:1.26-alpine`).
   - Membuat file konfigurasi aman `.env.production` dengan random cryptographic key.
   - Mengatur Windows Firewall untuk port aplikasi (8008), WOL (18009), dan MySQL (13306).
   - Mendaftarkan Scheduled Task untuk startup launcher dan backup database harian.
   - Membuat shortcut desktop: **"Sugeng Rawuh Billing & POS"** dan **"Sugeng Rawuh - Hardware Setup & Test Center"**.

### Langkah 3: Restart Windows (Jika Diminta)
Jika installer baru saja mengaktifkan fitur WSL2, Windows akan meminta restart.
1. Izinkan restart komputer.
2. Setelah login kembali ke Windows, installer akan **melanjutkan instalasi secara otomatis** melalui mekanisme `RunOnce` tanpa perlu memulai dari awal.

---

## 3. Skenario B: Perbaikan Instalasi (Repair Mode)

Gunakan skenario ini jika aplikasi mengalami kendala koneksi Docker, service terhenti, atau IP kasir berubah setelah restart router MikroTik.

### Prosedur Perbaikan 1-Click:
1. Pada desktop PC Kasir, klik dua kali shortcut **"Repair Layanan Sugeng Rawuh"** (atau buka menu Start -> Sugeng Rawuh -> Repair Layanan Sugeng Rawuh).
2. Skrip perbaikan (`setup-services.ps1 -Repair`) akan:
   - Menghidupkan Docker Desktop jika belum aktif.
   - Menunggu Docker Engine siap (*health check polling*).
   - Memastikan container `sugeng_rawuh_db`, `sugeng_rawuh_app`, `sugeng_rawuh_web`, `sugeng_rawuh_daemon`, dan `sugeng_rawuh_scheduler` berjalan normal.
   - Menjalankan `php artisan billing:reconcile` untuk memulihkan sesi billing yang tertunda.
   - Memperbaiki listener WOL Helper pada port 18009.
   - Memperbarui file deteksi jaringan `data/network-info.json`.
3. **Jaminan Keamanan:** Mode Repair **TIDAK PERNAH** menghapus volume database (`sr_mysql_data`), tidak pernah menjalankan `migrate:fresh`, dan tidak pernah menimpa file `.env.production`.

---

## 4. Skenario C: Upgrade dari Versi 1.0.0 ke Versi 2.0.0

Gunakan skenario ini untuk memperbarui sistem kasir yang saat ini menjalankan Sugeng Rawuh v1.0.0.

### Prosedur Upgrade Aman:
1. **Tutup Aplikasi Kasir:** Pastikan kasir tidak sedang melakukan transaksi aktif atau tutup sesi sementara.
2. **Jalankan Installer v2.0.0:** Klik kanan `SugengRawuh-Setup-v2.0.0.exe` -> **Run as administrator**.
3. **Deteksi Otomatis:** Installer Inno Setup akan mengenali instalasi v1.0.0 sebelumnya (berdasarkan AppId yang sama) dan secara otomatis mengunci folder tujuan pada folder instalasi yang sudah ada (misal `D:\SugengRawuh`).
4. **Proses Upgrade:**
   - Skrip `update-app.ps1` secara otomatis membuat snapshot cadangan database (`pre-update`) ke folder `data\backups\`.
   - File `.env.production` lama **dipertahankan sepenuhnya** sehingga seluruh kredensial database dan APP_KEY tetap utuh.
   - Offline bundle image `sugeng-rawuh-app:2.0.0` dimuat ke dalam Docker.
   - Container diperbarui dan dijalankan ulang dengan image baru.
   - Migrasi database inkremental non-destruktif (`php artisan migrate --force`) dijalankan.
   - Rekonsiliasi transaksi (`php artisan billing:reconcile`) dijalankan.
   - Shortcut baru *"Sugeng Rawuh - Hardware Setup & Test Center"* ditambahkan ke desktop.
5. Klik **Finish**. Selesai!

---

## 5. Alur Penggunaan Hardware Setup Wizard Setelah Instalasi

Setelah instalasi atau upgrade selesai, teknisi **WAJIB** membuka antarmuka wizard onboarding untuk menghubungkan Smart TV dan Shelly Relay:

1. Klik dua kali shortcut desktop **"Sugeng Rawuh - Hardware Setup & Test Center"**.
2. Browser akan otomatis membuka halaman `http://localhost:8008/admin/hardware/setup`.
3. Login menggunakan akun Administrator:
   - Email: `admin@sugengrawuh.com` (atau email admin yang telah ditentukan)
4. Ikuti 6 tahapan wizard:
   - **Langkah 1 (Persiapan):** Pastikan Smart TV Samsung dan Shelly 1PM Mini telah terhubung ke WiFi/LAN MikroTik yang sama.
   - **Langkah 2 (Jaringan):** Sistem mendeteksi IP PC Kasir dan subnet LAN (misal: `192.168.1.0/24`). Verifikasi bahwa gateway terdeteksi normal.
   - **Langkah 3 (Penemuan Perangkat):** Klik tombol *"Pindai Jaringan"*. Sistem akan mendeteksi TV Samsung dan Shelly Mini secara otomatis. Jika perangkat tidak muncul, gunakan form input manual (masukkan IP dan MAC address).
   - **Langkah 4 (Pairing Perangkat):**
     - Untuk TV Samsung: Klik *"Mulai Pairing"*. Layar Smart TV Samsung di ruangan akan memunculkan popup otorisasi: *"Izinkan Sugeng Rawuh POS tersambung?"*. Gunakan remote fisik TV untuk menekan tombol **Allow / Izinkan**. Token otorisasi akan otomatis tersimpan aman di database server.
     - Untuk Shelly: Klik *"Verifikasi Status"*. Sistem membaca REST API Shelly Gen3 (`/rpc/Shelly.GetStatus`).
   - **Langkah 5 (Pemetaan Stasiun):**
     - Hubungkan Stasiun Playstation (PS3 / PS4 / PS5) ke TV Samsung yang bersangkutan.
     - Hubungkan Meja Biliar ke Shelly Relay yang bersangkutan.
   - **Langkah 6 (Diagnostik & Penyelesaian):** Jalankan pengujian read-only (`PROBE` & `GET_STATUS`). Setelah seluruh perangkat menampilkan status `ONLINE`, klik *"Selesaikan Setup"*. Sistem siap digunakan oleh kasir!

