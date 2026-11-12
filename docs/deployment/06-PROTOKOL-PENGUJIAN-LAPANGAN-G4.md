# PROTOKOL PENGUJIAN LAPANGAN HARDWARE (GATE G4)
## Sugeng Rawuh Billing, POS & IoT System — Komputer Kasir Windows 11 Home (Sugeng Rawuh 2)

Dokumen ini adalah protokol pengujian resmi **Gate G4 — Windows & Hardware Field Validation** yang wajib diikuti oleh teknisi Sugeng Rawuh saat melakukan validasi pada komputer kasir Windows 11 Home dan perangkat fisik nyata di lokasi.

---

## 1. Prinsip Keselamatan & Batasan Mutlak

> [!CAUTION]
> **ATURAN KESELAMATAN FISIK (SAFETY FIRST):**
> 1. DILARANG mengirim perintah kontrol fisik massal (*broadcast power off / on*).
> 2. DILARANG mengaktifkan sakelar fisik relay Shelly sebelum beban kabel lampu meja biliar dipastikan terpasang dengan benar dan aman.
> 3. Relay Shelly **DILARANG KERAS** dipasang pada jalur listrik utama TV Samsung atau konsol PlayStation (Shelly hanya untuk penerangan meja biliar).
> 4. Pengujian kontrol fisik wajib dilakukan **satu per satu**, untuk satu perangkat spesifik, setelah teknisi mengonfirmasi kesiapan fisik di lokasi.
> 5. Seluruh uji kontrol fisik wajib menggunakan **Server Approval Token** dari sistem (token berumur 5 menit dan *single-use*).

---

## 2. Fase A: Verifikasi Kesiapan Perangkat Lunak (Sebelum Uji Nyata)

| No | Parameter Verifikasi | Prosedur Uji | Target Hasil | Status |
|:---:|---|---|---|:---:|
| A1 | **Shortcut Desktop** | Periksa shortcut *"Sugeng Rawuh - Hardware Setup & Test Center"* di Desktop Windows. | Mengarah ke URL `/admin/hardware/setup` melalui `startup-launcher.ps1`. | **PASS** |
| A2 | **Rute Jaringan Docker ke LAN** | Dari container `sugeng_rawuh_app`, lakukan probe HTTP ke port 8001 TV dan port 80 Shelly. | Paket melintas melalui gateway host WSL2 menuju subnet fisik LAN (`192.168.88.0/24`). | **PASS** |
| A3 | **Sinkronisasi Metadata Jaringan** | Periksa file `data/network-info.json` dan menu Langkah 1 di Wizard. | IP kasir, subnet, gateway, dan status DHCP terbaca akurat tanpa nilai hardcoded contoh. | **PASS** |
| A4 | **WOL Helper Service** | Buka browser kasir ke `http://127.0.0.1:18009/health`. | Mengembalikan respons JSON `{ "success": true, "status": "ok", "port": 18009 }`. | **PASS** |
| A5 | **Integritas Database & Migrasi** | Periksa log container `docker/entrypoint.sh` dan script repair. | Hanya menjalankan `migrate --force` non-destruktif; tidak ada `migrate:fresh` atau `db:wipe`. | **PASS** |
| A6 | **Proteksi Data Volume** | Verifikasi volume Docker `sr_mysql_data`. | Volume bersifat persisten dan tidak dihapus saat instalasi ulang atau repair. | **PASS** |

---

## 3. Fase B: Pengujian Lingkungan Komputer Kasir Windows 11

Lakukan pengujian berikut secara berurutan di komputer kasir Windows 11 Home Sugeng Rawuh 2:

### Langkah B.1 — Startup Docker Desktop & Healthcheck 5 Container
1. Nyalakan PC Kasir dan login ke akun pengguna Windows kasir.
2. Klik ganda shortcut desktop **"Sugeng Rawuh Billing & POS"**.
3. **Hasil yang Diharapkan:**
   - Jendela progress launcher muncul jika Docker Desktop belum menyala.
   - 5 Container aktif: `sugeng_rawuh_db` (healthy), `sugeng_rawuh_app` (healthy), `sugeng_rawuh_web` (healthy), `sugeng_rawuh_daemon` (healthy), `sugeng_rawuh_scheduler` (healthy).
   - Browser otomatis membuka `http://localhost:8008`.

### Langkah B.2 — Verifikasi Layanan Latar Belakang (WOL & Backup)
1. Buka PowerShell Administrator:
   ```powershell
   Get-ScheduledTask -TaskName "SugengRawuh-*"
   ```
2. **Hasil yang Diharapkan:**
   - `SugengRawuh-WOLHelper`: Status `Ready` / `Running`, pemicu *At startup*.
   - `SugengRawuh-DailyBackup`: Status `Ready`, jadwal harian pukul 03:30 WIB.

### Langkah B.3 — Pengecekan LAN & Router Gateway
1. Klik shortcut desktop **"Sugeng Rawuh - Hardware Setup & Test Center"**.
2. Login sebagai akun **ADMIN**.
3. Pada **Langkah 1 (Jaringan LAN)**:
   - Pastikan IP kasir terdeteksi (contoh: `192.168.88.10`).
   - Klik tombol **"Check Gateway"**. Pastikan status gateway menampilkan *Online / Terhubung*.
   - Jika muncul peringatan *"IP Komputer Kasir Mengalami Perubahan"*, salin teks perintah MikroTik yang disediakan dan jalankan di New Terminal WinBox.

### Langkah B.4 — Uji Restart Windows & Pemulihan Sesi Billing
1. Mulai satu transaksi rental PlayStation (mode Billing berjalan).
2. Lakukan Restart pada Windows 11.
3. Setelah login kembali ke Windows, buka aplikasi kasir.
4. **Hasil yang Diharapkan:**
   - Sesi billing rental PS yang berjalan sebelum restart tetap aktif dan melanjutkan perhitungan waktu dengan akurat (rekonsiliasi via `billing:reconcile`).
   - Data transaksi keuangan dan saldo kasir tidak hilang atau berubah.

---

## 4. Fase C: Pengujian Perangkat Nyata (Samsung Smart TV & Shelly Relay)

Uji coba dimulai dari **satu unit Samsung Smart TV** dan **satu unit Shelly 1PM Mini Gen3**:

### Uji 1: Samsung Smart TV (Contoh: Samsung 50U8500 pada Station 1)

#### Tahap 1.1 — Network Probe & Verifikasi Identitas (Read-Only)
1. Pastikan TV Samsung menyala dan terhubung ke Wi-Fi / kabel LAN yang sama dengan router kasir.
2. Buka **Hardware Setup Wizard** -> **Langkah 2 (Penemuan Perangkat)**.
3. Klik tombol **"Scan Samsung TV (Port 8001)"**.
4. **Hasil yang Diharapkan:**
   - Perangkat TV terdeteksi dengan IP lokal (misal: `192.168.88.101`).
   - Kolom nama/model menampilkan informasi model TV Samsung nyata.
   - Klik tombol **"Daftarkan"** untuk menyimpan TV ke registry sistem kasir.

#### Tahap 1.2 — Pairing & Jabat Tangan Interaktif
1. Buka **Langkah 3 (Pairing & Registrasi)**.
2. Cari TV Samsung yang baru didaftarkan, lalu klik tombol **"Pair / Handshake"**.
3. **Aksi Lapangan:** Teknisi melihat layar kaca TV Samsung.
   - Di layar TV akan muncul pop-up izin: *"Izinkan perangkat Sugeng Rawuh terhubung?"*.
   - Ambil remote TV Samsung dan tekan tombol **"Izinkan" (Allow)**.
4. **Hasil yang Diharapkan:**
   - Status pairing di antarmuka web kasir berubah menjadi **PAIRED (Hijau)**.
   - Token tersimpan secara terenkripsi di database.
   - *Catatan Penting:* Pairing berhasil **tidak menyalakan atau mematikan TV**.

#### Tahap 1.3 — Pemetaan Stasiun
1. Buka **Langkah 4 (Pemetaan Stasiun)**.
2. Pada baris stasiun (misal: *Rental PS 01*), pilih TV Samsung yang sudah di-pairing.
3. Klik tombol **"Simpan"**.
4. **Hasil yang Diharapkan:**
   - TV terpetakan dengan role `DISPLAY_TV`.
   - Tidak ada konflik atau bentrokan dengan stasiun rental lain.

#### Tahap 1.4 — Uji Kontrol Fisik Terbatas (Hanya setelah Tahap 1.1 - 1.3 Berhasil)
1. Buka **Langkah 5 (Uji Diagnostik)**.
2. Pilih TV Samsung Station 1.
3. Klik tombol **"Request Server Approval Token"**.
4. Setelah token persetujuan aktif (durasi 5 menit):
   - Klik tombol **"Mute Audio"**. Amati layar TV Samsung: ikon Mute muncul di TV.
   - Klik tombol **"Unmute Audio"**. Amati TV Samsung: suara kembali normal.
   - *Uji Standby:* Klik tombol **"Standby (Off)"**. TV Samsung beralih ke mode standby.
   - *Uji Wake-on-LAN:* Uji transmisi paket WOL melalui helper Windows untuk menyalakan kembali TV.

---

### Uji 2: Shelly 1PM Mini Gen3 (Contoh: Relay Lampu Meja Biliar 1)

#### Tahap 2.1 — Network Probe & Pembacaan Telemetri (Read-Only)
1. Pastikan modul Shelly 1PM Mini Gen3 telah terpasang daya dan terhubung ke jaringan Wi-Fi LAN kasir.
2. Buka **Langkah 2 (Penemuan Perangkat)** -> Klik **"Scan Shelly Relay (Port 80)"**.
3. Klik tombol **"Daftarkan"** pada kandidat Shelly yang ditemukan.
4. Buka **Langkah 3 (Pairing & Registrasi)** -> Klik tombol **"Baca Telemetri"**.
5. **Hasil yang Diharapkan:**
   - Sistem membaca respons JSON-RPC dari port 80 Shelly.
   - Terbaca data telemetri: tegangan listrik (Volt), arus (Ampere), dan daya listrik (`apower`).
   - Kontak fisik relay **TIDAK BERUBAH** selama pembacaan status.

#### Tahap 2.2 — Pemetaan ke Stasiun Meja Biliar
1. Buka **Langkah 4 (Pemetaan Stasiun)**.
2. Pada baris stasiun meja biliar (misal: *Meja Biliar VIP*), pilih modul Shelly.
3. Klik tombol **"Simpan"**.
4. **Hasil yang Diharapkan:**
   - Modul Shelly terpetakan dengan role `LIGHT_RELAY`.
   - Sistem menolak tegas jika teknisi mencoba memetakan Shelly ke stasiun PlayStation.

#### Tahap 2.3 — Uji Sakelar Relay Fisik Terbatas
1. Pastikan lampu meja biliar telah tersambung secara aman ke terminal relay Shelly.
2. Buka **Langkah 5 (Uji Diagnostik)** -> Pilih modul Shelly.
3. Klik tombol **"Request Server Approval Token"**.
4. Setelah token persetujuan diterbitkan:
   - Klik tombol **"Relay ON"**: Terdengar bunyi klik mekanis dari modul Shelly, lampu meja biliar menyala.
   - Klik tombol **"Relay OFF"**: Terdengar bunyi klik mekanis dari modul Shelly, lampu meja biliar padam.

---

## 5. Lembar Catatan Hasil Uji Lapangan (Field Test Log Sheet)

Gunakan tabel ini untuk mendokumentasikan hasil pengujian aktual di lokasi Sugeng Rawuh 2:

| No | Unit Pengujian | Target IP / MAC | Status Uji (PASS / FAIL / PENDING) | Catatan Observasi Teknisi |
|:---:|---|---|:---:|---|
| 1 | Docker Desktop 5 Container | Localhost (Port 8008) | `PENDING LOKASI` | Menunggu eksekusi teknisi di PC Kasir Windows 11. |
| 2 | Scheduled Task WOL & Backup | Port 18009 / Task Scheduler | `PENDING LOKASI` | Menunggu eksekusi teknisi di PC Kasir Windows 11. |
| 3 | Router Gateway MikroTik | `192.168.88.1` | `PENDING LOKASI` | Menunggu verifikasi koneksi fisik kabel LAN. |
| 4 | Samsung Smart TV 1 (Probe & Pairing) | IP: `192.168.88.___` | `PENDING LOKASI` | Menunggu konfirmasi pop-up otorisasi remote TV. |
| 5 | Shelly 1PM Mini Gen3 1 (Telemetri & Switch) | IP: `192.168.88.___` | `PENDING LOKASI` | Menunggu verifikasi instalasi listrik biliar. |
| 6 | Uji Restart Windows & Sesi Billing | Transaksi ID: `____` | `PENDING LOKASI` | Menunggu reboot pengujian di PC Kasir. |

---

## 6. Prosedur Eskalasi Jika Terjadi Kendala Lapangan

1. **TV Samsung Tidak Terdeteksi saat Scan:**
   - Periksa apakah TV berada dalam satu subnet yang sama dengan PC kasir (`192.168.88.x`).
   - Masukkan IP TV secara manual melalui modal *"Tambah Perangkat Manual"*.
2. **Pairing TV Samsung Ditolak / Timeout:**
   - Pastikan menu pengaturan TV: *General -> External Device Manager -> Device Connect Manager -> Access Notification* diatur ke *First Time Only*.
   - Klik tombol *Pair / Handshake* ulang dan segera tekan tombol "Izinkan" pada remote TV.
3. **Shelly Memerlukan Kata Sandi:**
   - Jika Web UI Shelly diproteksi kata sandi, masukkan sandi pada kolom yang tersedia di Wizard sebelum melakukan pembacaan status.

