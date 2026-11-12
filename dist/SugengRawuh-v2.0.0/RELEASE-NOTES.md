# Sugeng Rawuh Billing & POS — Release Notes
**Versi 2.0.0 (Release Candidate - RC)**
**Tanggal Rilis:** 12 November 2026
**Target Deployment:** PC Kasir Sugeng Rawuh 2 (Windows 11 Home Single Language x64, RAM 8 GB, MikroTik LAN)
**Status Kesiapan:** **RELEASE CANDIDATE (RC)** — *Lulus pengujian kode & Docker stack (`linux/amd64`). Pengujian lapangan di PC Windows fisik dan perangkat keras TV/Relay di lokasi Sugeng Rawuh 2 berstatus PENDING.*

---

## 1. Ringkasan Rilis v2.0.0

Rilis **v2.0.0** merupakan evolusi besar dari versi 1.0.0, yang berfokus pada **stabilitas instalasi Windows**, **kemandirian operasional (zero manual CLI)**, **keamanan perangkat keras (Hardware Safety & Cryptographic Token)**, dan penyediaan **Hardware Setup & Onboarding Wizard** berbasis antarmuka grafis (UI Web Laravel).

Arsitektur inti tetap 100% dipertahankan tanpa perubahan destruktif:
- **Backend:** Laravel 8.83.27, PHP 8.2-FPM (`linux/amd64`)
- **Database:** MySQL 8.0 (`linux/amd64`)
- **Web Server:** Nginx 1.26-alpine
- **Background Processing:** Billing Daemon & Laravel Scheduler
- **Host Integration:** Windows Host WOL Helper (`port 18009`) & MikroTik DHCP Static Lease

---

## 2. Rincian Perubahan & Peningkatan per Gate (G1 – G4)

### Gate G1: Stabilitas Installer & Ketahanan Sistem Windows
- **Dukungan Drive Dinamis:** Installer Inno Setup dan seluruh rangkaian skrip PowerShell (`installer/scripts/*.ps1`) kini mendukung penempatan pada drive kustom (`D:\SugengRawuh`, `C:\SugengRawuh`, dll.) melalui helper `Get-SRInstallDir` dan registri `installed-location.json`.
- **Pemeriksaan Kapasitas Dual-Disk:** `preflight-check.ps1` memeriksa ketersediaan ruang penyimpanan secara terpisah antara drive target aplikasi (minimal 10 GB fresh / 4 GB upgrade) dan drive sistem `C:\` (minimal 6 GB untuk virtual disk WSL2/Docker).
- **Pemisahan Reboot Pending:** Membedakan antara reboot akibat update Windows berkala dan reboot akibat instalasi fitur WSL2. Instalasi tidak lagi berhenti tanpa alasan jika WSL2 sudah siap.
- **Skrip Perbaikan Layanan Non-Destruktif:** Disediakan `setup-services.ps1` dengan opsi `-Repair` dan shortcut desktop *"Repair Layanan Sugeng Rawuh"* untuk memulihkan container Docker, menyelaraskan firewall, dan menjalankan `billing:reconcile` tanpa menghapus volume `sr_mysql_data` atau menimpa `.env.production`.
- **WOL Helper Single-Instance:** Mencegah duplikasi listener pada port 18009 dengan mekanisme deteksi HTTP `/health` dan parameter `/F /FI "WINDOWTITLE eq SugengRawuh-WOLHelper*"`.

### Gate G2: Hardware Diagnostics & Granular Safety Policy
- **Pemisahan Kebijakan Keamanan Hardware:** Memisahkan operasi menjadi 4 kategori terisolasi: `PROBE`, `GET_STATUS`, `PAIR`, dan `PHYSICAL_CONTROL`.
- **Read-Only Diagnostics Bebas Risiko:** Admin dapat menjalankan `PROBE` dan `GET_STATUS` untuk memeriksa konektivitas Smart TV Samsung dan Shelly 1PM Mini Gen3 melalui UI tanpa perlu mengaktifkan kontrol fisik global (`HARDWARE_REAL_EXECUTION_ENABLED=false`).
- **Server Approval Token untuk Perintah Fisik:** Kontrol fisik (`POWER_ON`, `POWER_OFF`, `REBOOT`, `SET_VOLUME`) dilindungi oleh cryptographic token berbasis SHA-256 (`approvePhysicalControl`) yang terikat pada ID perangkat, timestamp (TTL 30 detik), dan role Admin.
- **Dukungan Status Diagnostik Kanonikal:** Mengadopsi 7 status standar industri: `ONLINE`, `OFFLINE`, `UNCONFIGURED`, `UNPAIRED`, `PAIRING_PENDING`, `DRIVER_UNSUPPORTED`, dan `ERROR`.
- **Penanganan Merek Coocaa:** Ditandai secara eksplisit sebagai `DRIVER_UNSUPPORTED` (bukan error sistem) karena protokol remote jaringan belum terverifikasi secara resmi.

### Gate G3: Installation Wizard & Hardware Onboarding
- **UI Wizard Terpadu (`/admin/hardware/setup`):** 6 tahapan onboarding berbasis antarmuka grafis yang ramah teknisi:
  1. *Preparation:* Ringkasan prasyarat jaringan & perangkat.
  2. *Network:* Deteksi otomatis subnet LAN MikroTik (`network-info.json`).
  3. *Discovery:* Pemindaian otomatis TV Samsung dan Shelly di subnet lokal dengan timeout dan rate-limit yang aman.
  4. *Pairing:* Registrasi IP/MAC dan alur pairing TV Samsung (tampilan prompt otorisasi remote fisik di layar TV).
  5. *Mapping:* Pemetaan stasiun PS ke TV Samsung dan meja biliar ke Shelly Relay dengan validasi tipe permainan.
  6. *Diagnostics & Finish:* Pengujian konektivitas read-only sebelum sistem diserahterimakan ke kasir.
- **Shortcut Langsung:** Disediakan shortcut desktop & Start Menu khusus: *"Sugeng Rawuh - Hardware Setup & Test Center"* yang langsung membuka wizard onboarding.

### Gate G4: Windows & Hardware Field Validation Protocol
- **Protokol Validasi Lapangan Resmi:** Dokumen `docs/deployment/06-PROTOKOL-PENGUJIAN-LAPANGAN-G4.md` yang memuat lembar kerja langkah demi langkah untuk teknisi di lokasi kasir Sugeng Rawuh 2.
- **Audit Tanpa Risiko Selesai:** Verifikasi rute LAN outbound Docker, persistensi folder `./data`, keutuhan database, dan seluruh integrasi service production telah divalidasi 100% lulus.

---

## 3. Matriks Komparasi Fitur: v1.0.0 vs v2.0.0

| Fitur / Kemampuan | Versi 1.0.0 | Versi 2.0.0 (Release Candidate) |
| :--- | :--- | :--- |
| **Instalasi pada Drive D:** | Memerlukan intervensi manual | Otomatis & terdeteksi penuh |
| **Penyelarasan IP Berubah (DHCP)** | Manual edit konfigurasi | Otomatis via `startup-launcher.ps1` |
| **Pemeriksaan Kesehatan TV/Relay** | Harus via CLI / curl | Langsung dari UI Admin (`/admin/hardware/diagnostics`) |
| **Onboarding Perangkat Baru** | Edit `.env.production` manual | Melalui Wizard Web 6-Langkah (`/admin/hardware/setup`) |
| **Shortcut Hardware Setup** | Belum tersedia | Tersedia di Desktop & Start Menu |
| **Perbaikan Otomatis Layanan** | Manual restart docker | 1-Click via shortcut *"Repair Layanan Sugeng Rawuh"* |
| **Proteksi Perintah Fisik** | Flag boolean global | Token Kriptografis Server-Side + RBAC Admin |
| **Status Driver Coocaa** | Mengambang / error | Jelas: `DRIVER_UNSUPPORTED` |

---

## 4. Status Pengujian & Catatan Lapangan

- **Uji Otomatis (PHPUnit / Laravel Test Suite):** **583 tests passed (3.037 assertions)**, 0 failed, 0 errors.
- **Uji Kompilasi Installer Inno Setup:** **LULUS** (`SugengRawuh-Setup-v2.0.0.exe`, 476 MB, PE32 GUI Executable).
- **Uji Ekspor Offline Docker Bundle:** **LULUS** (`sugeng-rawuh-images-amd64.tar.gz`, 474 MB).
- **Pengujian Lapangan Windows 11 PC Kasir:** **PENDING** (Menunggu eksekusi teknisi di lokasi Sugeng Rawuh 2).
- **Pengujian Perangkat Fisik (TV Samsung & Shelly Relay):** **PENDING** (Menunggu eksekusi teknisi di lokasi Sugeng Rawuh 2).

