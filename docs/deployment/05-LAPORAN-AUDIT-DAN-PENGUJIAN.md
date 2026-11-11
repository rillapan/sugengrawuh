# 05 — Laporan Audit Teknis, Pengujian E2E, dan Paket Distribusi Produksi
**Sugeng Rawuh Billing & POS v1.0.0 (Cabang Sugeng Rawuh 2)**

Dokumen ini merangkum hasil **Stage A (Repository Audit & Hardening)**, **Stage B–E (Infrastruktur Docker, Offline Bundle, Installer Windows, Startup Launcher & WOL Helper)**, serta **Stage F–G (Pengujian End-to-End Live Docker Stack `linux/amd64`, Verifikasi Kompilasi Installer `.exe`, dan Panduan Serah Terima Lapangan)**.

---

## 1. Hasil Audit Teknis Repositori & Hardening (Stage A)

| Komponen / Aspek | Hasil Audit Awal | Tindakan Hardening & Perbaikan Produksi | Status |
| :--- | :--- | :--- | :--- |
| **Runtime & Framework** | PHP `^8.0.2`, Laravel `8.83.27`, MySQL `8.0` | Dikunci pada image produksi `php:8.2-fpm-bookworm` (`linux/amd64`), Nginx `1.26-alpine`, dan MySQL `8.0` dengan timezone `Asia/Jakarta` (`WIB`). | **LULUS** |
| **Ekstensi PHP Wajib** | Membutuhkan `pdo_mysql`, `mbstring`, `exif`, `pcntl`, `bcmath`, `gd`, `zip`, `sockets`, `intl`, `opcache` | Seluruh 10 ekstensi wajib dikompilasi dan diaktifkan di `Dockerfile` + `docker/php/php-production.ini` (`memory_limit=512M`, OPcache aktif). | **LULUS** |
| **Kemandirian Aset Offline (Zero-CDN)** | Ditemukan referensi Google Fonts (`fonts.googleapis.com` / `fonts.gstatic.com`) di `public/css/app.css` dan 7 file Blade, serta CDN FontAwesome di `resources/views/panduan/index.blade.php` | Dibuat `public/css/offline-fonts.css` dan seluruh link eksternal diganti ke aset lokal (`public/vendor/fontawesome-free/css/all.min.css`). Tidak ada lagi dependensi internet saat LAN lokal beroperasi tanpa WAN. | **LULUS** |
| **Anti-Duplikasi Daemon vs Scheduler** | `billing:daemon` (loop tiap 3 detik) dan `schedule:run` (tiap menit) berpotensi mengeksekusi worker yang sama secara bersamaan | Ditambahkan mekanisme **Cache Heartbeat Lock** (`billing:daemon:heartbeat`, TTL 15 detik) di `BillingDaemonCommand` dan filter `->skip(...)` di `app/Console/Kernel.php` untuk `billing:worker`, `billing:tv-alert-worker`, dan `billing:automation-worker`, serta proteksi idempotency di `TvAlertService`. | **LULUS** |
| **Arsitektur Wake-on-LAN (WOL) Docker Desktop Windows** | Docker Desktop (WSL2 NAT) pada Windows mengisolasi paket broadcast UDP Layer-2 (`255.255.255.255:9`) sehingga tidak mencapai NIC LAN fisik | Dibangun `installer/scripts/wol-helper.ps1` di host Windows (`TCP 18009`, diautentikasi via header `X-WOL-Token` dengan perbandingan constant-time) dan integrasi `forwardWakeOnLanToHostHelper()` pada `SamsungTvDriver.php` melalui `host.docker.internal:18009`. | **LULUS** |
| **Inisialisasi Produksi Idempoten & Allowlist Migrasi** | `db:seed` standar berisiko menimpa akun/data jika dijalankan ulang; migrasi guard `2026_03_09_000001` sebelumnya hanya mengizinkan `laravel`/`sistem_kasir_ps` | Dibuat `php artisan app:production-init` yang hanya melakukan bootstrap awal bila tabel akun kosong, serta ditambahkan `sugeng_rawuh_pos` ke allowlist migrasi database produksi. | **LULUS** |
| **Endpoint Readiness & Health Check** | Belum tersedia endpoint JSON terpadu untuk memantau kesiapan DB, skema, daemon, dan status safety hardware | Dibuat `GET /api/health` (`HealthCheckController.php`) yang memverifikasi koneksi MySQL, kesiapan tabel utama, timestamp heartbeat daemon, dan status `real_execution_enabled`. | **LULUS** |

---

## 2. Hasil Pengujian Live Stack Docker `linux/amd64` (Stage F)

Seluruh pengujian di bawah ini telah dieksekusi secara nyata terhadap stack kontainer produksi `linux/amd64` (`sugeng_rawuh_db`, `sugeng_rawuh_app`, `sugeng_rawuh_web`, `sugeng_rawuh_daemon`, `sugeng_rawuh_scheduler`) dan log lengkapnya terekam di `.superpowers/sdd/deployment-sugeng-rawuh/task-4-live-test-logs.txt`.

### 2.1. Health Check & Keamanan Port Jaringan
- **`GET http://localhost:8008/api/health`**: Mengembalikan `HTTP/1.1 200 OK` beserta header keamanan (`X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`):
  ```json
  {
    "status": "ok",
    "app": "Sugeng Rawuh Billing & POS",
    "environment": "production",
    "database": "connected",
    "schema_ready": true,
    "daemon_heartbeat": {
      "worker_id": "1@35ccd8ce10d4",
      "timestamp": 1794390966,
      "updated_at": "2026-11-11T16:56:06+07:00"
    },
    "hardware": {
      "driver": "real",
      "real_execution_enabled": false,
      "console_real_execution_enabled": false,
      "automation_enabled": true
    }
  }
  ```
- **Isolasi Port MySQL**: `docker port sugeng_rawuh_db` terverifikasi `3306/tcp -> 127.0.0.1:13306` (tidak terekspos ke `0.0.0.0`/LAN luar).
- **Verifikasi Aset Offline**: `GET /login`, `GET /css/offline-fonts.css`, dan `GET /css/app.css` mengembalikan `HTTP 200` dengan **0 referensi CDN eksternal** (`fonts.googleapis.com`, `fonts.gstatic.com`, `cdnjs`, `unpkg`, `jsdelivr`).

### 2.2. Autentikasi Role-Based (Admin, Owner, Kasir)
Ketiga akun bawaan berhasil login (`POST /login` -> `HTTP 302` redirect ke `/home`, `GET /home` -> `HTTP 200` dengan 0 referensi CDN eksternal):
- **Administrator** (`admin@gmail.com`) — HTTP 302 -> `/home` (HTTP 200)
- **Owner** (`owner@gmail.com`) — HTTP 302 -> `/home` (HTTP 200)
- **Operator Kasir** (`operator1@gmail.com`) — HTTP 302 -> `/home` (HTTP 200)

### 2.3. Siklus Sesi Billing, POS F&B, Auto-Expiration Daemon & Anti-Duplikasi Scheduler
1. **Pembuatan Sesi Billing + POS F&B**: Sesi prepaid + pesanan F&B berhasil dibuat (`Session ID: 1 | Billing Status: RUNNING | Device Status: Digunakan | Total: Rp 44.000 | FnB Stock After: 48`).
2. **Eksekusi Otomatis oleh Daemon (`sugeng_rawuh_daemon`)**: Saat `expires_at` melewati waktu berjalan, dalam 1 siklus tick (3 detik) daemon otomatis mentransisikan transaksi ke `EXPIRED_PENDING_ACTION` (`Status Transaksi: selesai`) dan memulihkan status perangkat ke `Tersedia`.
3. **Verifikasi Anti-Duplikasi Scheduler**: Saat `sugeng_rawuh_daemon` aktif memperbarui heartbeat cache (`billing:daemon:heartbeat`), eksekusi `php artisan schedule:run` menghasilkan `No scheduled commands are ready to run`, membuktikan tidak terjadi tabrakan worker antara daemon dan scheduler.

### 2.4. Persistensi Data Saat Restart & Idempotensi `app:production-init`
1. Nama akun Admin diubah menjadi `Administrator Sugeng Rawuh Custom` dengan `Tx Count: 1`.
2. Kontainer `db`, `app`, dan `daemon` di-restart (`docker compose restart db app daemon`) dan `php artisan app:production-init --reconcile` dijalankan ulang.
3. Output mengonfirmasi:
   ```text
   Nothing to migrate.
   Existing Admin/Owner account(s) detected. Skipping initial account/shift seeding to preserve production data.
   Billing reconciliation complete: 0 expired transitioned, 0 postpaid retained, 0 devices healed.
   After Restart & production-init -> Admin Name: Administrator Sugeng Rawuh Custom | Tx Count: 1
   ```

### 2.5. Uji Round-Trip Backup (`mysqldump`) & Restore Database
1. Transaksi baru dibuat (`Tx ID: 2 | Customer: Budi Santoso Backup Test | Total: 17000`).
2. Backup dieksekusi menggunakan `mysqldump --single-transaction --routines --triggers --default-character-set=utf8mb4` menghasilkan dump utuh (`53 KB`).
3. Dilakukan penghapusan transaksi simulasi kehilangan data (`Tx Count After Simulated Deletion: 0`).
4. Dump di-restore kembali ke `sugeng_rawuh_db` diikuti `php artisan billing:reconcile`, dan data pulih 100% (`Tx Count After Restore: 1 | Restored Customer: Budi Santoso Backup Test | Total: 17000`).

### 2.6. Profil Penggunaan Sumber Daya Kontainer (`docker stats`)
Pengukuran konsumsi resource kelima kontainer pada arsitektur `linux/amd64`:

| Nama Kontainer | Peran | CPU % | Penggunaan RAM | Persentase RAM (dari 7.75 GiB) | PIDs |
| :--- | :--- | :---: | :---: | :---: | :---: |
| `sugeng_rawuh_db` | MySQL 8.0 Server | 2.13% | `416.7 MiB` | 5.25% | 40 |
| `sugeng_rawuh_app` | PHP-FPM 8.2 Worker Pool | 0.01% | `64.6 MiB` | 0.81% | 3 |
| `sugeng_rawuh_daemon` | Real-Time Billing Daemon | 0.60% | `48.59 MiB` | 0.61% | 1 |
| `sugeng_rawuh_web` | Nginx 1.26 Reverse Proxy | 0.00% | `26.3 MiB` | 0.33% | 11 |
| `sugeng_rawuh_scheduler` | Laravel Cron Scheduler | 2.38% | `7.42 MiB` | 0.09% | 2 |
| **TOTAL STACK** | **5 Kontainer Aktif** | **~5.12%** | **~563.6 MiB** | **~7.09%** | **57** |

> Total penggunaan memori seluruh stack produksi hanya **~563.6 MiB**, sangat ringan dan aman untuk PC Kasir Windows 10/11 dengan RAM 8 GB.

---

## 3. Batasan Verifikasi & Daftar Uji Serah Terima Lapangan (Stage G)

Untuk transparansi rekayasa dan keselamatan operasional di cabang **Sugeng Rawuh 2**, status verifikasi dibagi secara tegas menjadi tiga kategori:

### Kategori 1 — Telah Diverifikasi Secara Otomatis & Live Docker `linux/amd64` (TERVERIFIKASI PENUH)
- Seluruh **549 pengujian otomatis PHPUnit** (`2.855 assertions`) lulus 100% tanpa error.
- Build image produksi `linux/amd64` (`sugeng-rawuh-app:1.0.0`) dan uji fungsional live 5 kontainer (`db`, `app`, `web`, `daemon`, `scheduler`) lulus 100%.
- Ekspor bundel image offline `offline-bundle/images/sugeng-rawuh-images-amd64.tar.gz` (`474 MB`) berisi ketiga image penuh `linux/amd64` (`sugeng-rawuh-app:1.0.0`, `nginx:1.26-alpine`, `mysql:8.0`).

### Kategori 2 — Kompilasi Installer Windows `.exe` (TERVERIFIKASI PENUH)
- Script `installer/SugengRawuh.iss` berhasil dikompilasi menggunakan **Inno Setup 6.7.1** (`amake/innosetup:latest`) menjadi binary installer tunggal `dist/SugengRawuh-Setup-v1.0.0.exe` (`476 MB`, format `PE32 executable (GUI) Intel 80386, for MS Windows`).
- Arsip Docker image disimpan dengan flag `nocompression` di dalam installer `.exe` sehingga proses ekstraksi pada PC Kasir berlangsung cepat tanpa dekompresi ganda.

### Kategori 3 — Wajib Diuji Langsung pada PC Kasir Windows 11 & Perangkat Fisik di Sugeng Rawuh 2 (UJI PENERIMAAN LAPANGAN / ON-SITE UAT)
Sebelum mengubah `HARDWARE_REAL_EXECUTION_ENABLED=false` menjadi `true` pada file `C:\SugengRawuh\.env.production`, teknisi di lokasi cabang **Sugeng Rawuh 2** wajib memverifikasi:
1. **Eksekusi GUI Installer `SugengRawuh-Setup-v1.0.0.exe` pada Windows 10/11 Fisik**: Memastikan UAC, preflight check VT-x/AMD-V, WSL2 + Docker Desktop readiness, pembuatan Scheduled Task (`SugengRawuh-WOLHelper` & `SugengRawuh-DailyBackup`), dan shortcut Desktop berjalan mulus.
2. **Konektivitas MikroTik RouterOS Fisik**: Memastikan DHCP Static Lease sudah aktif dan uji tombol **Test Connection** MikroTik di halaman Pengaturan Hardware berhasil.
3. **Pairing & Kontrol Samsung Smart TV Fisik**: Memastikan pop-up *"Allow Device"* di layar TV sudah disetujui (**Allow**), token tersimpan, serta uji Wake-on-LAN melalui `wol-helper.ps1` (`127.0.0.1:18009`) mampu menyalakan TV dari kondisi standby.
4. **Kontrol Relay / Smart Plug Shelly (Bila Digunakan)**: Memastikan alamat IP statis Shelly merespons ping dan switch on/off sesuai meja PS3/PS4.

---

## 4. Artefak Rilis & Checksum SHA-256

| Artefak Distribusi | Lokasi File | Ukuran | SHA-256 Checksum |
| :--- | :--- | :---: | :--- |
| **Windows Setup Installer (`.exe`)** | `dist/SugengRawuh-Setup-v1.0.0.exe` | `476 MB` | `609f03803d58c688a27d5a54ec934c34c267c8c3cf89f8aad96a6258f5c8ae5c` |
| **Offline Docker Images (`linux/amd64`)** | `offline-bundle/images/sugeng-rawuh-images-amd64.tar.gz` | `474 MB` | `c67eec5390cd92e86502594f97c83d81b07417dbe1ec5bd66025aa4f2b31b75f` |
| **Manifest Checksum Installer** | `dist/SHA256SUMS.txt` | `99 B` | Lihat isi file `dist/SHA256SUMS.txt` |
| **Manifest Checksum Offline Bundle** | `offline-bundle/SHA256SUMS.txt` | `1.48 KB` | Lihat isi file `offline-bundle/SHA256SUMS.txt` |

Verifikasi integritas file di Windows PowerShell sebelum instalasi:
```powershell
Get-FileHash .\SugengRawuh-Setup-v1.0.0.exe -Algorithm SHA256
```

---

## 5. Panduan Troubleshooting Cepat di Lokasi Cabang

1. **Aplikasi Tidak Terbuka Saat Klik Shortcut Desktop**:
   - Klik ganda kembali shortcut **Sugeng Rawuh Billing & POS** di Desktop. Launcher otomatis menyalakan Docker Desktop dan kontainer jika belum berjalan, serta menjalankan `billing:reconcile`.
   - Periksa log pemulihan di `C:\SugengRawuh\data\logs\`.
2. **Muncul Pesan Virtualisasi BIOS (VT-x / AMD-V) Belum Aktif Saat Instalasi**:
   - Restart PC Kasir, tekan `F2` / `Del` untuk masuk ke BIOS, aktifkan **Intel Virtualization Technology (VT-x)** atau **AMD SVM Mode**, simpan (`F10`), lalu jalankan kembali installer.
3. **Samsung Smart TV Tidak Menyala Otomatis Saat Sesi Dimulai**:
   - Pastikan kabel LAN TV terhubung ke switch/MikroTik yang sama dengan PC Kasir dan fitur **Wake on LAN / Power On with Mobile** di menu General -> Network TV dalam keadaan aktif.
   - Pastikan task `SugengRawuh-WOLHelper` berstatus `Running` di Windows Task Scheduler (`TCP 18009`).
4. **Sesi Billing Tidak Memutus Koneksi / TV Secara Fisik**:
   - Periksa `GET http://localhost:8008/api/health`. Jika `"real_execution_enabled": false`, sistem masih berada dalam **Mode Simulasi Aman**. Ikuti `docs/deployment/03-PANDUAN-MAPPING-HARDWARE.md` untuk mengaktifkan eksekusi hardware fisik setelah semua IP dan MAC address tervalidasi.
