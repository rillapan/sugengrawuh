# Sugeng Rawuh Billing & POS — Laporan Hasil Pengujian & Checklist Lapangan
**Versi Target:** 2.0.0 (Release Candidate)
**Tanggal Verifikasi:** 12 November 2026
**Lingkungan Verifikasi Kode:** macOS Developer Workstation / Docker Desktop 29.6.2 (`linux/amd64`)
**Target Pengujian Fisik:** PC Kasir Windows 11 Home, Router MikroTik, Smart TV Samsung, Shelly 1PM Mini Gen3 (Sugeng Rawuh 2)

---

## 1. Ringkasan Pengujian Otomatis (Test Suite)

Seluruh pengujian unit, fitur, integrasi, dan skrip otomasi deployment dijalankan menggunakan runner PHPUnit pada runtime Laravel 8.83.27 / PHP 8.2:

```text
Test Suite Summary:
--------------------------------------------------------------------------------
Tests Executed : 583 tests
Assertions     : 3.037 assertions
Failures       : 0 failed
Errors         : 0 errors
Status         : PASS (100% SUKSES)
Execution Time : 8.69 detik
--------------------------------------------------------------------------------
```

### Rincian Cakupan Pengujian per Domain:

1. **Deployment & Windows Installer Infrastructure:**
   - `DockerConfigurationTest` (5 tests, 28 assertions): Verifikasi .dockerignore, isolasi secret, user www-data, binding localhost:13306 MySQL, 5 container service, dan target `linux/amd64`.
   - `ProductionReadinessTest` (3 tests, 12 assertions): Verifikasi zero external CDN (kemandirian offline), driver Samsung TV WOL helper, dan cache heartbeat lock anti-duplikasi daemon vs scheduler.
   - `WindowsInstallerScriptsTest` (11 tests, 88 assertions): Verifikasi Inno Setup 6, PowerShell preflight (dual-disk, BIOS VT-x/AMD-V), installer prereqs (WSL2 resume), installer app (safety flags false), network configure (MikroTik DHCP static & IP change), WOL helper (HMAC token & broadcast), startup launcher, backup-db (mysqldump gzip), restore-db, update-app, setup-services repair, dan wizard shortcut (`/admin/hardware/setup`).

2. **Hardware Diagnostics & Safety Policy (Gate G2):**
   - `HardwareSafetyGuardTest` (14 tests, 52 assertions): Verifikasi isolasi operasi `PROBE` & `GET_STATUS` (diizinkan saat `real_execution_enabled=false`), pemisahan operasi `PAIR` dengan token aman, Server Approval Token untuk `PHYSICAL_CONTROL`, penolakan bypass via parameter boolean frontend, dan status `DRIVER_UNSUPPORTED` untuk Coocaa.
   - `HardwareDiagnosticsApiTest` (18 tests, 94 assertions): Verifikasi endpoint diagnostik Admin, rate-limiting, otorisasi RBAC (blokir Kasir & Owner dari modifikasi), sanitasi response, dan audit log.
   - `HardwareDiagnosticsFrontendTest` (9 tests, 41 assertions): Verifikasi Blade UI diagnostics, banner protokol keselamatan G2/G4-B1, tombol disabled saat execution disabled, dan modal approval token.

3. **Installation Wizard & Hardware Onboarding (Gate G3):**
   - `HardwareSetupWizardTest` (16 tests, 78 assertions): Verifikasi alur 6 langkah wizard, deteksi subnet LAN, network scanner rate-limit, pairing flow TV Samsung, status polling Shelly, pemetaan PS -> TV dan Biliar -> Shelly, soft-detach audit trail, dan final completion barrier.

4. **Core Billing, POS & Business Logic Regression:**
   - `ServerSideBillingEngineTest` (23 tests, 142 assertions): Verifikasi siklus prepaid, postpaid lost-time, custom package, midnight timer crossing, worker anti-duplikasi, pesimistik lock station, dan crash recovery reconciliation.
   - `StationMappingServiceTest` (19 tests, 98 assertions): Verifikasi integritas pemetaan stasiun, validasi MAC address, validasi IP lokal, pencegahan race condition saat stasiun aktif, dan foreign key cascade restriction.
   - `TransactionFnbManagementTest`, `KategoriPermainanTest`, `DurationPrecisionAndPricingTest`, dan suite lainnya: Seluruh fungsionalitas kasir, FnB, dan laporan keuangan berfungsi normal tanpa regresi.

---

## 2. Status Kualifikasi Gate (G1 – G5)

| Gate | Deskripsi Pekerjaan | Status Kode / Build | Status Fisik Lapangan |
| :---: | :--- | :---: | :---: |
| **G1** | Stabilitas Installer Windows, Dual-Disk Check, Service Repair | **PASS** | PENDING Uji Lapangan |
| **G2** | Hardware Diagnostics, Read-Only Probe, Server Approval Token | **PASS** | PENDING Uji Lapangan |
| **G3** | Installation Wizard, LAN Discovery, Device Onboarding UI | **PASS** | PENDING Uji Lapangan |
| **G4** | Windows Target Audit & Field Validation Protocol | **PASS** | PENDING Uji Lapangan |
| **G5** | Final Installer Packaging (`SugengRawuh-Setup-v2.0.0.exe`) | **PASS** | PENDING Uji Lapangan |

---

## 3. Lembar Kerja & Checklist Teknisi di Sugeng Rawuh 2

Checklist ini **WAJIB diisi secara fisik oleh teknisi** saat melakukan pengujian atau instalasi di PC Kasir Sugeng Rawuh 2 sebelum sistem dinyatakan siap produksi (*Final Production Sign-off*):

### Bagian A: Lingkungan Komputer Kasir
- [ ] PC Kasir menyala dan login otomatis ke Windows 11 Home.
- [ ] BIOS Virtualization (VT-x / AMD-V) terdeteksi *Enabled* di Task Manager -> Performance -> CPU.
- [ ] Ruang kosong disk: Drive `D:\` $\ge$ 20 GB dan Drive `C:\` $\ge$ 10 GB.
- [ ] Docker Desktop berjalan dengan status *Engine running* (ikon paus hijau di system tray).
- [ ] Menjalankan `SugengRawuh-Setup-v2.0.0.exe` dengan hak akses Administrator selesai tanpa error dialog.
- [ ] Shortcut *"Sugeng Rawuh Billing & POS"* dan *"Sugeng Rawuh - Hardware Setup & Test Center"* muncul di Desktop.

### Bagian B: Layanan & Container
- [ ] Buka PowerShell, jalankan `docker ps`: Kelima container (`sugeng_rawuh_app`, `sugeng_rawuh_db`, `sugeng_rawuh_web`, `sugeng_rawuh_daemon`, `sugeng_rawuh_scheduler`) berstatus *Up (healthy)*.
- [ ] Buka browser ke `http://localhost:8008/api/health`, respon JSON menampilkan status `healthy`.
- [ ] Listener WOL Helper aktif di `http://127.0.0.1:18009/health`.

### Bagian C: Jaringan MikroTik & Onboarding Perangkat
- [ ] Buka shortcut *"Sugeng Rawuh - Hardware Setup & Test Center"*, login sebagai Admin.
- [ ] Langkah 2 Wizard: Subnet LAN terdeteksi sesuai IP router MikroTik (misal `192.168.1.0/24`).
- [ ] Langkah 3 Wizard: Pemindaian jaringan mendeteksi seluruh Smart TV Samsung dan Shelly 1PM Mini.
- [ ] Langkah 4 Wizard: 
  - [ ] TV Samsung menampilkan popup otorisasi, tombol *Allow/Izinkan* ditekan dengan remote fisik.
  - [ ] Status pairing TV Samsung berubah menjadi `PAIRED / VERIFIED`.
  - [ ] Shelly 1PM Mini merespons status HTTP GET `/rpc/Shelly.GetStatus` dengan `ONLINE`.
- [ ] Langkah 5 Wizard: Setiap stasiun PS berhasil dipetakan ke TV Samsung, dan meja biliar ke Shelly Relay.
- [ ] Langkah 6 Wizard: Seluruh pengujian read-only (`PROBE` & `GET_STATUS`) menampilkan status hijau `ONLINE`.

### Bagian D: Verifikasi Ketahanan & Pemulihan
- [ ] Restart Windows 11 Kasir dari menu Start -> Restart.
- [ ] Setelah Windows menyala kembali dan kasir login:
  - [ ] Startup launcher membuka aplikasi dalam waktu kurang dari 60 detik.
  - [ ] Layanan Docker otomatis pulih ke status *healthy*.
  - [ ] Tidak ada data transaksi atau stasiun yang hilang.
- [ ] Buat 1 sesi billing percobaan selama 5 menit:
  - [ ] Timer billing berjalan normal di layar kasir.
  - [ ] Sesi otomatis selesai dan melepaskan stasiun saat waktu habis.

---

**Tanda Tangan Teknisi Lapangan:**

Nama Teknisi : __________________________________  
Tanggal Uji  : __________________________________  
Status Akhir : [  ] LULUS TAHAP RC    [  ] PERLU PERBAIKAN

