# Panduan Operasional: Backup, Restore, Update Aplikasi, & Pemulihan Listrik Padam

Dokumen ini adalah panduan perawatan rutin untuk **Sugeng Rawuh Billing & POS** di cabang **Sugeng Rawuh 2**, mencakup backup database otomatis maupun manual, prosedur restore saat darurat, cara update aplikasi tanpa kehilangan data transaksi, serta penanganan mati lampu (power outage) dan restart Windows.

---

## 1. Backup Database MySQL (Otomatis & Manual)

Seluruh data transaksi, sesi rental, stok FnB, akun pengguna, dan konfigurasi perangkat disimpan di dalam volume Docker **`sr_mysql_data`** dan di-backup secara berkala ke folder lokal:
```text
C:\SugengRawuh\data\backups\
```

### A. Backup Otomatis Harian (Scheduled Task)
Saat instalasi selesai, sistem mendaftarkan tugas terjadwal Windows bernama **`SugengRawuh-DailyBackup`**:
- Berjalan otomatis setiap hari pukul **03:30 WIB** (atau segera saat PC dinyalakan jika jadwal terlewat).
- Menghasilkan file terkompresi `sugeng_rawuh_backup_YYYYMMDD_HHMMSS_daily.sql.gz` menggunakan `mysqldump --single-transaction --routines --triggers --events`.
- **Retensi Otomatis**: Menyimpan backup selama **30 hari terakhir** dan membersihkan file backup yang lebih tua dari 30 hari agar SSD PC Kasir tidak penuh.

### B. Backup Manual Kapan Saja
Kasir atau Owner dapat melakukan backup manual kapan saja dengan salah satu cara berikut:
1. **Melalui Start Menu**: Klik **Start** -> **Sugeng Rawuh Billing & POS** -> **Backup Database Sekarang**.
2. **Melalui PowerShell**:
   ```powershell
   powershell -ExecutionPolicy Bypass -File C:\SugengRawuh\installer\scripts\backup-db.ps1 -InstallDir C:\SugengRawuh -Reason "manual"
   ```

### C. Rekomendasi Keamanan Data (Salin ke Flashdisk Mingguan)
Disarankan setiap akhir pekan, Owner menyalin file `.sql.gz` terbaru dari `C:\SugengRawuh\data\backups\` beserta file **`C:\SugengRawuh\.env.production`** ke Flashdisk eksternal sebagai cadangan di luar PC Kasir (*off-host backup*).

> **PENTING — Simpan File `.env.production` / `APP_KEY`**:
> Nilai `APP_KEY` di dalam `C:\SugengRawuh\.env.production` digunakan oleh Laravel untuk mengenkripsi data sensitif (seperti token perangkat di database). Jangan pernah menghapus atau mengganti `APP_KEY` saat memindahkan atau me-restore database ke PC baru.

---

## 2. Prosedur Restore Database (`restore-db.ps1`)

Apabila terjadi kerusakan PC atau Anda perlu memulihkan data dari file backup `.sql.gz` / `.sql`:

1. Buka **PowerShell sebagai Administrator**.
2. Jalankan skrip `restore-db.ps1` dengan menunjuk ke file backup yang ingin dipulihkan:
   ```powershell
   powershell -ExecutionPolicy Bypass -File C:\SugengRawuh\installer\scripts\restore-db.ps1 `
       -InstallDir C:\SugengRawuh `
       -BackupFile "C:\SugengRawuh\data\backups\sugeng_rawuh_backup_20261111_033000_daily.sql.gz"
   ```
   *(Atau tambahkan parameter `-Force` jika dijalankan tanpa prompt konfirmasi interaktif).*
3. **Apa yang dilakukan `restore-db.ps1` secara otomatis?**
   - Membuat **Pre-Restore Safety Backup** terlebih dahulu dari kondisi database saat ini (sehingga Anda selalu bisa membatalkan restore jika salah memilih file).
   - Menghentikan sementara `sugeng_rawuh_daemon` dan `sugeng_rawuh_scheduler` agar tidak ada proses yang menulis ke database saat pemulihan berlangsung.
   - Mengimpor seluruh tabel dan data dari file `.sql.gz` ke `sugeng_rawuh_db`.
   - Menjalankan `php artisan migrate --force` dan `php artisan billing:reconcile` untuk menyinkronkan status meja/station.
   - Menyalakan kembali seluruh container dan memverifikasi kesehatan sistem (`/api/health`).

---

## 3. Prosedur Update Aplikasi Tanpa Kehilangan Data (`update-app.ps1`)

Ketika tim pengembang merilis versi pembaruan (misalnya file image baru `sugeng-rawuh-images-amd64.tar.gz` atau installer baru):

### Jaminan Keamanan Data Saat Update
Sistem pembaruan Sugeng Rawuh dirancang **100% Non-Destruktif**:
1. File **`C:\SugengRawuh\.env.production`** (beserta `APP_KEY` dan password database) **TIDAK PERNAH** ditimpa.
2. Volume database **`sr_mysql_data`** **TIDAK PERNAH** dihapus.
3. Perintah migrasi menggunakan `php artisan migrate --force` (hanya menambah tabel/kolom baru, **bukan** `migrate:fresh`).
4. Perintah inisialisasi `php artisan app:production-init --reconcile` memeriksa apakah tabel `users` sudah memiliki akun. Karena akun Admin/Owner/Kasir sudah ada, perintah tersebut **melewati (skip) pembuatan ulang user** sehingga password maupun data transaksi yang sudah berjalan **tidak akan pernah ter-reset**.

### Langkah Menjalankan Update
1. Salin file `sugeng-rawuh-images-amd64.tar.gz` versi baru ke `C:\SugengRawuh\offline-bundle\images\`.
2. Buka **PowerShell sebagai Administrator** dan jalankan:
   ```powershell
   powershell -ExecutionPolicy Bypass -File C:\SugengRawuh\installer\scripts\update-app.ps1 `
       -InstallDir C:\SugengRawuh `
       -ImageArchive "C:\SugengRawuh\offline-bundle\images\sugeng-rawuh-images-amd64.tar.gz"
   ```
3. Skrip `update-app.ps1` akan otomatis:
   - Membuat **Backup Database Pra-Update** (`pre-update`) secara otomatis.
   - Memuat Docker image versi terbaru.
   - Memperbarui container (`docker compose up -d`).
   - Menjalankan migrasi database incremental (`migrate --force`), rekonsiliasi sesi (`billing:reconcile`), dan menyegarkan cache (`config:cache`, `view:cache`).
   - Memverifikasi endpoint `/api/health`.

---

## 4. Pemulihan Otomatis Saat Mati Lampu (Power Outage) & Restart PC Kasir

Cabang rental PlayStation dan Billiard sering menghadapi kondisi mati lampu mendadak atau PC Kasir yang di-restart. Sistem Sugeng Rawuh memiliki mekanisme perlindungan berlapis:

### A. Bagaimana Sesi Rental Menangani Mati Lampu (`billing:reconcile`)?
Setiap kali container `sugeng_rawuh_app` atau `sugeng_rawuh_daemon` menyala kembali setelah mati lampu, perintah `php artisan billing:reconcile` dijalankan secara otomatis:
1. **Sesi Open Billing (Postpaid / Main Dulu Bayar Belakang)**:
   - Tetap dipertahankan berstatus **Aktif (`berjalan`)** dan waktu mulai (`waktu_mulai` / `started_at`) tetap tercatat akurat di MySQL sehingga perhitungan durasi dan tagihan tidak hilang.
2. **Sesi Paket (Prepaid) yang Waktunya Sudah Habis Saat Listrik Padam**:
   - Jika `expires_at <= waktu sekarang`, rekonsiliasi dan daemon otomatis menyelesaikan sesi tersebut, memulihkan status meja menjadi **Tersedia**, dan menjadwalkan perintah penutupan perangkat.
3. **Sesi Paket (Prepaid) yang Waktu Mainnya Masih Tersisa**:
   - Sesi tetap berlanjut dari sisa waktu yang sebenarnya (`expires_at` disimpan sebagai timestamp absolut di database MySQL, bukan di memori RAM yang hilang saat mati lampu).

### B. Startup Launcher Otomatis (`startup-launcher.ps1`)
Saat pengguna login ke Windows 11 di PC Kasir:
1. Shortcut startup di folder Windows Startup otomatis menjalankan `startup-launcher.ps1`.
2. Sebuah jendela status bergaya modern akan tampil di layar Kasir:
   - Memeriksa apakah **Docker Desktop** sudah berjalan; jika belum, skrip otomatis menyalakan `Docker Desktop.exe` dan menunggu hingga mesin Docker siap.
   - Menjalankan `docker compose --env-file .env.production up -d` untuk memastikan ke-5 container (`db`, `app`, `web`, `daemon`, `scheduler`) menyala.
   - Menjalankan `billing:reconcile`.
   - Menunggu hingga `http://localhost:8008/api/health` mengembalikan `"status": "ok"`.
   - Membuka browser kasir secara otomatis ke `http://localhost:8008`.

### C. Catatan Arsitektur Penting: Auto-Logon Windows 11 untuk Pemulihan Tanpa Sentuhan
> **Penjelasan Teknis Jujur (Windows 11 + Docker Desktop)**:
> Pada sistem operasi Windows 10/11, **Docker Desktop** dirancang oleh Docker untuk berjalan dalam konteks sesi pengguna (*User Session* setelah login Windows), bukan sebagai layanan pra-logon murni sebelum layar kunci Windows dilewati.
>
> **Cara Mengaktifkan Pemulihan Otomatis 100% Tanpa Input Password Windows (Windows Auto-Logon)**:
> Agar ketika listrik menyala kembali (dan fitur *Restore on AC/Power Loss* di BIOS aktif) PC Kasir langsung masuk ke Desktop Windows dan menyalakan Docker + Sugeng Rawuh tanpa perlu mengetik PIN/Password Windows:
> 1. Tekan **`Win + R`**, ketik **`netplwiz`**, lalu tekan **Enter**.
> 2. Hapus centang pada **"Users must enter a user name and password to use this computer"** (atau gunakan utilitas resmi Microsoft **Sysinternals Autologon**).
> 3. Masukkan password akun Windows Kasir dan klik **OK**.
>
> *(Catatan Pengembangan Masa Depan: Apabila di masa mendatang Sugeng Rawuh menginginkan server headless tanpa layar/login pengguna sama sekali, seluruh arsitektur `docker-compose.yml` ini sudah 100% kompatibel untuk dijalankan langsung di atas **Ubuntu Server 24.04 LTS** dengan daemon `systemd` Docker native yang otomatis menyala pada saat boot kernel).*

