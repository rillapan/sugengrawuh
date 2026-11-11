# Panduan Instalasi Lapangan PC Kasir — Sugeng Rawuh Billing & POS (Cabang Sugeng Rawuh 2)

Dokumen ini adalah panduan operasional langkah-demi-langkah (11 Tahap) bagi teknisi lapangan maupun pemilik untuk memasang sistem **Sugeng Rawuh Billing & POS** di PC Kasir Windows 10/11 64-bit pada cabang **Sugeng Rawuh 2** hingga siap digunakan secara offline di jaringan lokal (LAN MikroTik).

---

## Persyaratan Minimum PC Kasir
| Komponen | Spesifikasi Minimum | Spesifikasi Rekomendasi |
| :--- | :--- | :--- |
| **Sistem Operasi** | Windows 10 64-bit (Build 19041 / 20H1+) | Windows 11 Pro / Home 64-bit |
| **Prosesor (CPU)** | Intel Core i3 / AMD Ryzen 3 (64-bit, Virtualization `VT-x`/`AMD-V` Aktif di BIOS) | Intel Core i5 / AMD Ryzen 5 |
| **Memori (RAM)** | 8 GB RAM | 16 GB RAM |
| **Penyimpanan (SSD)** | Sisa ruang kosong minimal 15 GB pada Drive `C:` | SSD NVMe (sisa ruang > 30 GB) |
| **Jaringan** | Port Ethernet RJ45 terhubung ke Switch/Router MikroTik | Kabel LAN Cat6 ke MikroTik |

---

## Tahap 1: Persiapan Fisik & Koneksi LAN MikroTik
1. Nyalakan PC Kasir dan pastikan kabel LAN (Ethernet) terpasang dari port LAN PC Kasir menuju port LAN Router/Switch **MikroTik** cabang Sugeng Rawuh 2.
2. Pastikan profil jaringan Ethernet di Windows diatur sebagai **Private Network** (bukan *Public*), agar aturan Windows Defender Firewall khusus jaringan lokal (`Private`) aktif dengan benar:
   - Buka **Settings** -> **Network & internet** -> **Ethernet**.
   - Pilih **Network profile type**: **Private network**.
3. Pastikan fitur Virtualisasi CPU (**Intel VT-x** atau **AMD SVM**) sudah berstatus **Enabled** (cek melalui **Task Manager** -> tab **Performance** -> **CPU** -> *Virtualization: Enabled*).

---

## Tahap 2: Menyalin Paket Installer dari Flashdisk & Verifikasi Keutuhan File
1. Tancapkan Flashdisk distribusi resmi Sugeng Rawuh ke PC Kasir.
2. Salin folder distribusi atau file installer `SugengRawuh-Setup-v1.0.0.exe` ke Desktop PC Kasir.
3. *(Opsional namun disarankan)* Verifikasi checksum SHA-256 melalui PowerShell untuk memastikan file tidak rusak saat disalin:
   ```powershell
   Get-FileHash .\SugengRawuh-Setup-v1.0.0.exe -Algorithm SHA256
   ```
   Cocokkan nilai `Hash` dengan yang tertera pada file `SHA256SUMS.txt`.

---

## Tahap 3: Menjalankan Installer (`SugengRawuh-Setup-v1.0.0.exe`) sebagai Administrator
1. Klik kanan pada file **`SugengRawuh-Setup-v1.0.0.exe`** lalu pilih **Run as administrator**.
2. Jika muncul jendela konfirmasi *User Account Control (UAC)*, klik **Yes**.
3. Pada wizard instalasi **Sugeng Rawuh Billing & POS**:
   - Biarkan lokasi instalasi default pada **`C:\SugengRawuh`**.
   - Klik **Next** -> **Install**.
4. Installer akan mengekstrak seluruh file sistem ke `C:\SugengRawuh` dan secara otomatis menjalankan **Master Installation Orchestrator** (`install-orchestrator.ps1`).

---

## Tahap 4: Pemeriksaan Prasyarat Otomatis (Preflight Check)
Skrip instalasi akan melakukan pemeriksaan otomatis (`preflight-check.ps1`) yang dicatat ke `C:\SugengRawuh\data\logs\preflight-check.log`:
- Memastikan hak akses **Administrator** aktif.
- Memastikan arsitektur OS adalah **64-bit (AMD64)** dan versi Windows memenuhi syarat WSL2.
- Memastikan kapasitas kosong Drive `C:` mencukupi (minimal 8 GB bebas).
- Memeriksa ketersediaan port **8008** (Web POS), **13306** (MySQL Lokal), dan **18009** (WOL Helper).

---

## Tahap 5: Instalasi WSL2 & Docker Desktop (Beserta Penanganan Reboot)
1. Apabila **WSL2** atau **Virtual Machine Platform** belum aktif di PC Kasir:
   - Skrip `install-prereqs.ps1` akan mengaktifkan fitur Windows yang dibutuhkan dan memasang paket kernel WSL2 (`wsl_update_x64.msi` jika tersedia di `C:\SugengRawuh\installer\prereqs\`).
   - Skrip juga memasang **Docker Desktop** secara otomatis jika file `Docker Desktop Installer.exe` disertakan di folder `prereqs\`.
2. **Penanganan Reboot Otomatis (Resume Setelah Restart)**:
   - Jika Windows membutuhkan **Restart** untuk mengaktifkan WSL2/Hypervisor, installer akan mendaftarkan tugas resume satu-kali (*RunOnce*) dan memberi tahu Anda untuk me-restart PC Kasir.
   - Setelah PC Kasir menyala kembali dan Anda login ke Windows, proses instalasi akan **melanjutkan secara otomatis** dari tahap berikutnya tanpa perlu mengulang dari awal.

---

## Tahap 6: Impor Offline Docker Images (`linux/amd64`) & Inisialisasi Database
Ketika Docker Engine sudah berstatus *Running*, skrip `install-app.ps1` akan bekerja secara otomatis:
1. **Membuat File `.env.production` Secara Aman**:
   - Menyalin dari `.env.production.example` jika `.env.production` belum ada.
   - Menghasilkan kunci enkripsi `APP_KEY` unik, `DB_PASSWORD`, `DB_ROOT_PASSWORD`, dan `HARDWARE_WOL_HELPER_TOKEN` secara acak dan aman.
   - **Penting**: Jika `.env.production` sudah ada (misalnya saat instalasi ulang/update), file tersebut **tidak akan ditimpa** sehingga data dan kunci enkripsi tetap aman.
2. **Memuat Docker Images Secara Offline (Tanpa Internet)**:
   - Mengekstrak dan memuat `C:\SugengRawuh\offline-bundle\images\sugeng-rawuh-images-amd64.tar.gz` (`sugeng-rawuh-app:1.0.0`, `nginx:1.26-alpine`, `mysql:8.0`) langsung ke dalam Docker lokal.
3. **Menjalankan 5 Container Produksi**:
   - `sugeng_rawuh_db` (MySQL 8.0, hanya terikat ke `127.0.0.1:13306`)
   - `sugeng_rawuh_app` (PHP 8.2-FPM + Laravel)
   - `sugeng_rawuh_web` (Nginx Reverse Proxy pada port `8008`)
   - `sugeng_rawuh_daemon` (Worker Billing Real-Time Sub-Menit dengan interval 1 detik)
   - `sugeng_rawuh_scheduler` (Penjadwal tugas periodik Laravel)
4. **Migrasi & Seeding Baseline Produksi (`app:production-init`)**:
   - Menjalankan migrasi tabel secara incremental (`migrate --force`).
   - Memastikan akun default tersedia jika tabel `users` masih kosong:
     - **Admin**: `admin@gmail.com` / Password: `password`
     - **Owner**: `owner@gmail.com` / Password: `password`
     - **Kasir / Operator**: `operator1@gmail.com` / Password: `password`
   - Menjalankan rekonsiliasi sesi (`billing:reconcile`).

---

## Tahap 7: Konfigurasi Jaringan LAN, Firewall, & Windows Host WOL Helper
Skrip `configure-network.ps1` otomatis mengonfigurasi:
1. **Windows Defender Firewall**:
   - Membuka port **TCP 8008** khusus profil **Private / LocalSubnet** agar tablet/HP Owner di jaringan Wi-Fi MikroTik yang sama dapat mengakses POS, sambil memblokir jaringan publik.
   - Membuka port **TCP 18009** untuk layanan **Windows Host WOL Helper**.
2. **Layanan Windows Host WOL Helper (`wol-helper.ps1`)**:
   - Mendaftarkan Scheduled Task `SugengRawuh-WOLHelper` yang berjalan di latar belakang setiap kali pengguna login untuk meneruskan paket **Wake-on-LAN (UDP Magic Packet)** dari container Docker langsung ke kartu jaringan fisik (Ethernet LAN) menuju TV Samsung.
3. **File Informasi Jaringan MikroTik**:
   - Membuat laporan otomatis di `C:\SugengRawuh\data\INFORMASI-JARINGAN-MIKROTIK.txt` yang berisi alamat **IPv4 LAN PC Kasir**, **MAC Address PC Kasir**, dan perintah siap-salin untuk membuat DHCP Static Lease di MikroTik.
   - Ikuti panduan lengkap di **`02-PANDUAN-JARINGAN-MIKROTIK.md`** untuk mengunci IP PC Kasir di MikroTik.

---

## Tahap 8: Login Pertama & Perubahan Password Standar
1. Di akhir instalasi, browser akan otomatis terbuka ke **`http://localhost:8008`** (atau klik dua kali shortcut **Sugeng Rawuh Billing & POS** di Desktop).
2. Pastikan halaman login tampil sempurna dengan font dan ikon lengkap **meskipun tanpa koneksi internet**.
3. Login menggunakan akun **Admin** (`admin@gmail.com` / `password`) dan **Owner** (`owner@gmail.com` / `password`), lalu segera ubah password standar melalui menu pengaturan akun/user untuk keamanan operasional.

---

## Tahap 9: Registrasi Station & Mapping Hardware Bertahap
Secara default, sistem dipasang dalam **Mode Aman**:
- `HARDWARE_REAL_EXECUTION_ENABLED=false`
- `CONSOLE_REAL_EXECUTION_ENABLED=false`

Hal ini menjamin sistem tidak akan mengirim sinyal matikan/nyalakan TV atau lampu ke alamat IP yang salah sebelum teknisi memetakan IP dan MAC Address perangkat di cabang Sugeng Rawuh 2.
- Ikuti panduan lengkap di **`03-PANDUAN-MAPPING-HARDWARE.md`** untuk mendaftarkan Station dan menguji 1 unit TV Samsung / Shelly terlebih dahulu.

---

## Tahap 10: Uji Coba Operasional 1 Station (End-to-End)
Sebelum serah terima ke kasir:
1. Login sebagai **Kasir** (`operator1@gmail.com`).
2. Mulai 1 sesi **Rental (Paket / Prepaid)** berdurasi singkat dan tambahkan item **FnB (Makanan/Minuman)**.
3. Pastikan status station berubah menjadi **Digunakan**, hitung mundur berjalan, peringatan 10 menit muncul tepat waktu, dan saat waktu habis status sesi otomatis berpindah serta station kembali **Tersedia**.

---

## Tahap 11: Uji Restart PC Kasir, Backup Database, & Serah Terima
1. **Uji Backup Manual**:
   - Klik shortcut **Backup Database Sekarang** di Start Menu (atau jalankan `C:\SugengRawuh\installer\scripts\backup-db.ps1`).
   - Pastikan file `.sql.gz` terbentuk di `C:\SugengRawuh\data\backups\`.
2. **Uji Restart PC Kasir**:
   - Lakukan **Restart** pada Windows 11 PC Kasir.
   - Setelah login ke Windows, pastikan **Startup Launcher** otomatis menyalakan Docker Desktop (jika belum berjalan), menghidupkan ke-5 container Sugeng Rawuh, menjalankan rekonsiliasi sesi (`billing:reconcile`), dan membuka browser ke `http://localhost:8008`.
   - Pastikan seluruh transaksi dan akun yang baru dibuat tetap utuh 100%.

