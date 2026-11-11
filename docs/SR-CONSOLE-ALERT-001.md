# SR-CONSOLE-ALERT-001: Implementasi Sistem Notifikasi PlayStation 3 & PlayStation 4 Terintegrasi Laravel

**Project:** Sugeng Rawuh — Integrated Billing & POS Management System  
**Framework:** Laravel 8 / PHP 8.2 / MySQL  
**Target Hardware:** Sony PlayStation 3 (HEN / webMAN MOD), Sony PlayStation 4 (HEN / GoldHEN)  
**Dokumen Referensi:** SR-CONSOLE-ALERT-001 / SR-TV-PS-IMPLEMENTATION-001  
**Tanggal Rilis:** 9 November 2026  
**Status Kode:** SELESAI & 100% TERUJI (447 Tests Passing)

---

## 1. Ringkasan Eksekutif & Tujuan Bisnis

Sistem Sugeng Rawuh mengelola station PlayStation dengan sesi billing aktif (Prepaid, Custom Package, dan Postpaid/Lost Time). Sebelum implementasi ini, peringatan durasi hanya ditampilkan pada layar Smart TV atau melalui pemberitahuan kasir langsung. 

Tujuan tugas **SR-CONSOLE-ALERT-001** adalah mewujudkan pengiriman pesan popup langsung ke layar konsol PlayStation 3 dan PlayStation 4 tanpa mengganggu alur gameplay pengguna (tanpa menutup game, tanpa reboot, dan tanpa memutus daya konsol).

### Pesan Peringatan Standar Bisnis:
1. **Waktu tersisa 10 menit (`TIME_REMAINING_10_MINUTES` / `REMAINING_10_MIN`):**  
   *"Waktu bermain tersisa 10 menit. Silakan hubungi kasir untuk memperpanjang waktu."*
2. **Waktu hampir habis (`ALMOST_FINISHED`):**  
   *"Waktu bermain hampir habis. Segera hubungi kasir."*
3. **Waktu habis (`TIME_EXPIRED`):**  
   *"Waktu bermain telah habis. Silakan hubungi kasir."*
4. **Peringatan volume (`LOWER_VOLUME`):**  
   *"Mohon kecilkan volume permainan. Terima kasih."*
5. **Pesan khusus ADMIN (`CUSTOM`):**  
   Pesan bebas hingga maksimal 120 karakter untuk konsol, yang dikirim oleh Admin/Owner melalui dashboard.

---

## 2. Arsitektur Komunikasi & Protokol Hardware

```
[ BillingService / Admin Dashboard ]
                 │
                 ▼
     [ ConsoleNotificationService ]
                 │
                 ▼
    [ ConsoleNotificationDispatcher ]
                 │
      ┌──────────┴─────────────────────────┐
      │                                    │
      ▼                                    ▼
[ Ps3WebmanDriver ]               [ Ps4GoldHenDriver ]
(Direct HTTP Client)             (Outbound Polling Model)
      │                                    ▲
      ▼                                    │ HTTP Polling (every 3s)
[ PS3 webMAN MOD ]                         │
GET /popup.ps3/{msg}              [ PS4 GoldHEN Plugin ]
(Port 80 LAN)                     sugengrawuh_notify.prx
                                  (sceKernelSendNotificationRequest)
```

### A. PlayStation 3: Direct HTTP Request via webMAN MOD
- **Protokol:** HTTP GET langsung dari Laravel ke daemon webMAN MOD pada konsol PS3.
- **Endpoint:** `GET http://{PS3_IP}:{PORT}/popup.ps3/{URL_ENCODED_MESSAGE}`
- **Karakteristik:**
  - Connect timeout: 2 detik; Request timeout: 5 detik.
  - Sanitasi pesan otomatis: menghapus tag HTML/script dan membatasi teks maksimal 120 karakter.
  - Respon dari webMAN MOD berstatus HTTP 200 dengan konfirmasi `HTTP_ACCEPTED`.

### B. PlayStation 4: Outbound Polling Model (Custom GoldHEN Plugin)
- **Larangan Keras Port 9090:** Port TCP 9090 pada PS4 GoldHEN adalah port *BinLoader* yang dirancang khusus untuk injeksi payload binary (ELF/BIN). Mengirimkan string teks mentah ke port 9090 akan memicu socket abort atau crash kernel konsol.
- **Solusi Outbound Polling:**
  - Plugin background ringan (`sugengrawuh_notify.prx`) berjalan di lingkungan Orbis OS konsol PS4.
  - Plugin melakukan polling periodik (default tiap 3 detik) ke endpoint Laravel:  
    `GET /api/console-agent/v1/events`
  - Autentikasi menggunakan token unik per perangkat (`X-Device-Token` atau `Bearer`), yang di-hash dengan SHA-256 pada database server.
  - Ketika menerima notifikasi, plugin memanggil API native Orbis OS:  
    `sceKernelSendNotificationRequest` untuk memunculkan popup notifikasi di pojok atas layar TV.
  - Plugin mengirimkan konfirmasi balik (ACK) ke server:  
    `POST /api/console-agent/v1/events/{id}/ack` dengan status `NOTIFICATION_API_CALLED`.
  - Plugin mengirimkan heartbeat periodik (tiap 30 detik):  
    `POST /api/console-agent/v1/heartbeat`.

---

## 3. Perubahan Skema Database (Additive Migrations)

Semua migrasi dirancang aditif tanpa menghapus atau merusak data yang telah ada:

1. **`2026_11_09_000003_add_console_agent_fields_to_physical_devices.php`:**
   - Menambahkan kolom `device_token_hash` (`VARCHAR(64)`, indexed) untuk autentikasi agen PS4.
   - Menambahkan `firmware_version` (`VARCHAR(50)`, nullable).
   - Menambahkan `homebrew_type` (`VARCHAR(50)`, nullable, misal `GOLDHEN`, `HEN`).
   - Menambahkan `homebrew_version` (`VARCHAR(50)`, nullable).
   - Menambahkan `driver_type` (`VARCHAR(50)`, nullable, misal `PS3_WEBMAN`, `PS4_GOLDHEN`).
   - Menambahkan `transport_config` (`JSON`, nullable).
   - Mengubah `mac_address` menjadi nullable agar pendaftaran konsol yang belum diketahui MAC fisiknya tetap dapat diproses.
   - Menambahkan kolom `agent_response_payload` (`TEXT`, nullable) pada tabel `tv_control_events` untuk mencatat payload respons agen.

---

## 4. Modul Backend yang Telah Diimplementasikan

### 1. Driver Konsol
- **`App\Services\Hardware\Drivers\Ps3WebmanDriver`**: Driver untuk PS3 webMAN MOD dengan penanganan koneksi HTTP, sanitasi panjang <= 120 karakter, timeout guard, dan pelaporan status `HTTP_ACCEPTED`.
- **`App\Services\Hardware\Drivers\Ps4GoldHenDriver`**: Driver untuk PS4 GoldHEN berbasis antrean outbound polling, pembangkitan token autentikasi konsol, dan pengecekan konektivitas via heartbeat.
- **`App\Services\Hardware\Drivers\MockConsoleDriver`**: Driver mock simulasi konsol untuk pengujian terisolasi tanpa perangkat fisik.

### 2. Layanan & Dispatcher
- **`App\Services\Hardware\ConsoleNotificationDispatcher`**: Mengalihkan perintah ke driver yang tepat berdasarkan tipe perangkat dan mode konfigurasi (`mock` vs `real`).
- **`App\Services\Hardware\ConsoleNotificationService`**: Menangani otorisasi peran (Admin/Owner), validasi stasiun, manajemen preset pesan, fencing generasi sesi (`session_generation`), dan pembatalan otomatis saat extend/stop sesi.

### 3. API Agen Konsol PS4
- **`GET /api/console-agent/v1/events`**: Mengambil pesan notifikasi yang berstatus `PENDING` untuk konsol yang terautentikasi dan menandainya sebagai `DELIVERED_TO_APP`.
- **`POST /api/console-agent/v1/events/{event}/ack`**: Menerima konfirmasi eksekusi (`NOTIFICATION_API_CALLED`) atau kegagalan dari plugin PS4.
- **`POST /api/console-agent/v1/heartbeat`**: Menerima laporan detak jantung konsol untuk memperbarui timestamp `last_seen_at` dan status `ONLINE`.

### 4. Endpoint Kontrol Admin & Owner
- **`POST /admin/stations/{station}/console-notifications`**: Mengirim notifikasi manual dari dashboard ke konsol stasiun PlayStation.
- **`GET /admin/stations/{station}/console-notifications`**: Menampilkan riwayat notifikasi konsol pada stasiun.
- **`GET /admin/stations/{station}/console-status`**: Memeriksa status koneksi, model konsol, dan token agen konsol.

---

## 5. Sumber Daya Plugin PS4 GoldHEN (`integrations/ps4-goldhen/`)

Kode sumber lengkap untuk plugin PS4 telah disertakan di repositori:

- **`src/main.c`**: Inisialisasi plugin GoldHEN, pemuatan konfigurasi `config.ini`, dan worker loop thread untuk polling periodik dan heartbeat.
- **`src/notify.h` & `src/notify.c`**: Wrapper pemanggilan API native `sceKernelSendNotificationRequest`.
- **`src/http_client.h` & `src/http_client.c`**: Klien socket HTTP ringan tanpa dependensi eksternal berat.
- **`config.ini`**: Templat konfigurasi server URL, device token, interval polling, dan interval heartbeat.
- **`Makefile`**: Skrip build ganda untuk OpenOrbis Toolchain (`sugengrawuh_notify.prx`) dan host runner testing (`sugengrawuh_notify_host`).
- **`README.md`**: Panduan instalasi FTP ke direktori `/data/GoldHEN/plugins/` pada PS4 dan konfigurasi `plugins.ini`.

---

## 6. Integrasi Antarmuka (UI) Dashboard

1. **Halaman Monitoring Perangkat (`/device`)**:
   - Kartu stasiun menampilkan status konsol PlayStation (nama konsol, model, IP, status pemetaan).
   - Tombol **Kirim Pesan** memunculkan modal kirim peringatan dengan pemilih channel (`AUTO`, `CONSOLE`, `TV`, `BOTH`).
   - Counter karakter otomatis (maksimal 120 karakter untuk konsol).
   - Respon AJAX dilengkapi loading state dan notifikasi toast (sukses/gagal).
2. **Halaman Hardware Registry (`/admin/hardware/registry`)**:
   - Menambahkan opsi pendaftaran kategori `GAME_CONSOLE`.
   - Brand otomatis `SONY` / `PLAYSTATION`.
   - MAC address bersifat opsional untuk Game Console.
   - Pembangkitan token perangkat secara otomatis untuk autentikasi agen PS4.
3. **Halaman Station Hardware Mapping (`/admin/hardware/mappings`)**:
   - Stasiun PlayStation dapat dipetakan ke Smart TV (`DISPLAY_TV`) sekaligus Game Console (`GAME_CONSOLE`).
   - Pemetaan Game Console ke meja biliar diblokir secara otomatis oleh sistem validasi kompatibilitas.

---

## 7. Kebijakan Keamanan Hardware (Safety Flags)

Sesuai instruksi isolasi pengujian:
```env
CONSOLE_NOTIFICATIONS_ENABLED=true
CONSOLE_NOTIFICATIONS_TRANSPORT=mock
CONSOLE_REAL_EXECUTION_ENABLED=false
```
Dalam mode `mock`, semua pengiriman notifikasi disimulasikan secara aman dalam memori dan database lokal tanpa mengirim paket soket jaringan ke konsol fisik sungguhan.

---

## 8. Verifikasi Pengujian Otomatis

Seluruh modul telah diverifikasi menggunakan pengujian unit dan integrasi terisolasi:

```bash
php artisan test
```

### Hasil Uji:
- **Total Pengujian:** 447 Tests (100% Passed)
- **Waktu Eksekusi:** ~6.19 detik
- **Cakupan Skenario Utama:**
  - **Skenario A:** Sanitasi string PS3 webMAN MOD, penghapusan tag HTML/script, dan pemotongan teks > 120 karakter.
  - **Skenario B:** Verifikasi respon status `HTTP_ACCEPTED`, penanganan timeout, dan kondisi offline pada PS3.
  - **Skenario C:** Pemblokiran ketat port 9090 (BinLoader) untuk mencegah crash konsol PS4.
  - **Skenario D:** Validasi autentikasi token Bearer/X-Device-Token pada endpoint polling `/api/console-agent/v1/events`.
  - **Skenario E:** Transisi siklus hidup status event (`PENDING` -> `DELIVERED_TO_APP` -> `ACKED_BY_SIMULATOR`/`FAILED`).
  - **Skenario F:** Otomasi billing (peringatan 10 menit, waktu habis, dan pembatalan otomatis saat extend atau stop sesi).
  - **Skenario G:** Isolasi stasiun (notifikasi PS 01 tidak bocor ke PS 02; penolakan pemetaan pada meja biliar).
  - **Skenario H:** Penegakan RBAC (Admin & Owner diizinkan; Kasir dilarang dengan kode 403 Forbidden).
  - **Skenario I:** Hardware Safety Guard (memastikan mode mock aktif dan tidak ada paket fisik liar).

---

## 9. Panduan Pengujian Fisik Lapangan (Ketika Perangkat Siap)

Ketika pengembang telah memiliki akses langsung ke konsol fisik di outlet:

### A. Pengujian PlayStation 3 (webMAN MOD)
1. Nyalakan PS3, pastikan HEN telah diaktifkan (*Enable HEN*).
2. Pastikan webMAN MOD aktif dan dapat diakses dari browser PC: `http://{PS3_IP}/cpursx.ps3`.
3. Buka menu Hardware Registry di kasir, daftarkan konsol dengan IP PS3 dan Driver `PS3_WEBMAN`.
4. Petakan konsol ke stasiun (misal PS 01).
5. Ubah konfigurasi `.env`:
   ```env
   CONSOLE_NOTIFICATIONS_TRANSPORT=real
   CONSOLE_REAL_EXECUTION_ENABLED=true
   ```
6. Buka halaman `/device`, klik **Kirim Pesan**, pilih channel `CONSOLE`, lalu kirim.
7. Amati popup di pojok kanan atas TV PS3: *"Waktu bermain tersisa 10 menit."*

### B. Pengujian PlayStation 4 (GoldHEN)
1. Nyalakan PS4 dengan GoldHEN aktif (v2.2+).
2. Salin `sugengrawuh_notify.prx` ke `/data/GoldHEN/plugins/` via FTP.
3. Konfigurasikan token perangkat dari kasir ke dalam `/data/GoldHEN/plugins/sugengrawuh/config.ini`.
4. Tambahkan plugin ke `/data/GoldHEN/plugins.ini`.
5. Muat ulang plugin melalui menu GoldHEN.
6. Mulai sesi bermain di kasir, kirim pesan notifikasi.
7. Amati popup native Orbis OS muncul di layar PS4 saat game sedang berjalan, dan periksa respon ACK tercatat di log server.
