# Panduan Mapping Hardware & Pengujian Bertahap (Samsung Smart TV, Shelly, & PlayStation)

Dokumen ini menjelaskan cara mendaftarkan **Station** (Meja PS / Meja Billiard), memetakan perangkat **Samsung Smart TV** (`DISPLAY_TV`), **Shelly Smart Relay** (`LIGHT_RELAY`), serta protokol pengujian lapangan **8 Tahap secara bertahap (Progressive Hardware Testing)** di cabang **Sugeng Rawuh 2**.

---

## 1. Prinsip Keselamatan Utama (Safety-First Defaults)

### A. Status Awal Mode Aman (`Dry-Run`)
Saat pertama kali diinstal, file `C:\SugengRawuh\.env.production` memiliki pengaturan keselamatan berikut:
```env
HARDWARE_DRIVER=real
HARDWARE_REAL_EXECUTION_ENABLED=false
CONSOLE_REAL_EXECUTION_ENABLED=false
```
Artinya:
- Seluruh alur kasir, timer, peringatan 10 menit, dan pencatatan log hardware (`hardware_command_logs` & `hardware_events`) berjalan penuh, **tetapi sistem belum mengirim paket jaringan fisik** ke TV atau Shelly.
- Anda baru mengubah `HARDWARE_REAL_EXECUTION_ENABLED=true` setelah **daftar IP dan MAC Address perangkat di Sugeng Rawuh 2 selesai dipetakan dan diverifikasi**.

### B. Larangan Keras Pemutusan Arus Listrik Utama (Main AC Power Cut) pada TV & PlayStation
> **PERINGATAN KESELAMATAN HARDWARE**:
> - **JANGAN PERNAH** memasang Shelly Smart Relay untuk memutus arus listrik AC utama (stopkontak) ke **PlayStation (PS3/PS4/PS5)** atau **Samsung Smart TV**.
> - Memutus arus listrik AC secara mendadak saat konsol PlayStation menyala akan menyebabkan **kerusakan sistem penyimpanan (Corrupted Database / Harddisk / SSD)** dan merusak power supply konsol.
> - Selain itu, jika listrik AC ke Samsung TV diputus oleh relay, fitur **Wake-on-LAN (WOL)** pada TV Samsung **tidak akan pernah bisa menyalakan TV kembali**.
> - Oleh karena itu:
>   1. **Station PlayStation (PS3/PS4/PS5)** wajib dikontrol menggunakan **Samsung Smart TV Network Control (`DISPLAY_TV`)** melalui WebSocket (`KEY_POWER` / Standby) dan **Wake-on-LAN (WOL)**.
>   2. **Shelly Smart Relay (`LIGHT_RELAY`)** **HANYA** digunakan untuk mengontrol **Lampu Meja Billiard** (atau perangkat penerangan murni).

---

## 2. Persiapan Pengaturan pada Unit Samsung Smart TV

Sebelum memetakan TV ke dalam aplikasi POS, pastikan pengaturan berikut sudah aktif di **setiap unit Samsung Smart TV** (Tizen OS):
1. Hubungkan TV Samsung ke jaringan LAN MikroTik (disarankan menggunakan **kabel LAN RJ45**, atau Wi-Fi 5GHz dengan sinyal kuat).
2. Menggunakan remote fisik TV Samsung, buka **Settings (Pengaturan)** -> **General (Umum)** -> **Network (Jaringan)** -> **Expert Settings (Pengaturan Ahli)**:
   - **Power On with Mobile** / **Wake on LAN**: **ON (Aktif)**.
   - **IP Remote**: **ON (Aktif)**.
3. Buka **Settings** -> **General** -> **External Device Manager (Manajer Perangkat Eksternal)** -> **Device Connect Manager (Manajer Koneksi Perangkat)**:
   - **Access Notification (Notifikasi Akses)**: Pilih **First Time Only (Hanya Pertama Kali)**.
   - Buka **Device List (Daftar Perangkat)** dan pastikan tidak ada perangkat yang diblokir (*Denied*).
4. Catat **Alamat IPv4** dan **MAC Address** TV dari menu **Network Status** di TV, lalu kunci sebagai **Static Lease** di MikroTik (lihat `02-PANDUAN-JARINGAN-MIKROTIK.md`).

---

## 3. Mendaftarkan Station & Perangkat Hardware di Aplikasi POS

1. Login ke aplikasi POS (`http://localhost:8008`) menggunakan akun **Admin** (`admin@gmail.com`) atau **Owner** (`owner@gmail.com`).
2. **Daftarkan Kategori / Tarif & Station**:
   - Buka menu **Data PS / Station** dan tambahkan daftar meja/station sesuai kondisi nyata di cabang Sugeng Rawuh 2 (contoh: `PS4-01`, `PS4-02`, `BILLIARD-01`).
3. **Daftarkan Perangkat Hardware (`Hardware Devices`)**:
   - Untuk **Samsung Smart TV (`DISPLAY_TV`)**:
     - **Device Type**: `DISPLAY_TV`
     - **Vendor / Driver**: `samsung_ws`
     - **IP Address**: IP statis TV di MikroTik (contoh: `192.168.88.101`)
     - **Port**: `8002` (WSS TLS — direkomendasikan untuk Samsung TV Tizen 2016+) atau `8001` (WS non-TLS untuk model lama)
     - **MAC Address**: MAC Address kartu jaringan TV (contoh: `AA:BB:CC:11:22:33`) — **Wajib diisi untuk fitur Wake-on-LAN!**
     - **Station Mapping**: Hubungkan dengan Station terkait (misal `PS4-01` sebagai peran `DISPLAY_TV`).
   - Untuk **Shelly Smart Relay (`LIGHT_RELAY` - Khusus Meja Billiard)**:
     - **Device Type**: `LIGHT_RELAY`
     - **Vendor / Driver**: `shelly_gen2` (untuk Shelly Plus/Pro Gen2/Gen3 RPC `/rpc/Switch.Set`) atau `shelly_gen1` (`/relay/0`)
     - **IP Address**: IP statis Shelly di MikroTik (contoh: `192.168.88.201`)
     - **Channel / Switch ID**: `0`
     - **Station Mapping**: Hubungkan dengan Station Billiard terkait (misal `BILLIARD-01`).

---

## 4. Protokol Pengujian Hardware 8 Tahap (Wajib Dilakukan Bertahap!)

Jangan langsung mengaktifkan seluruh station sekaligus. Uji **1 Station (Station Pertama)** terlebih dahulu menggunakan protokol 8 tahap berikut:

### Tahap 1: Uji Koneksi Fisik & Tabel ARP dari PC Kasir ke MikroTik
Buka PowerShell di PC Kasir dan pastikan PC Kasir dapat menjangkau IP Samsung TV #1 (`192.168.88.101`) dan Shelly #1 (`192.168.88.201`):
```powershell
Test-NetConnection -ComputerName 192.168.88.101 -Port 8002
arp -a | findstr 192.168.88
```
Pastikan `TcpTestSucceeded : True` dan alamat MAC yang tampil di tabel ARP sesuai dengan perangkat fisik.

### Tahap 2: Mengaktifkan Eksekusi Hardware Hanya untuk 1 Perangkat Uji & Pairing Token TV
1. Buka file `C:\SugengRawuh\.env.production` menggunakan Notepad.
2. Aktifkan eksekusi nyata dan batasi hanya ke IP perangkat uji pertama (opsional dengan `HARDWARE_REAL_ALLOWED_DEVICES`):
   ```env
   HARDWARE_REAL_EXECUTION_ENABLED=true
   HARDWARE_REAL_ALLOWED_DEVICES=192.168.88.101,192.168.88.201
   ```
3. Terapkan perubahan konfigurasi ke container:
   ```powershell
   Set-Location C:\SugengRawuh
   docker compose --env-file .env.production up -d
   docker exec sugeng_rawuh_app php artisan config:cache
   ```
4. **Pairing Pertama Kali di Layar TV Samsung**:
   - Pastikan TV Samsung #1 dalam keadaan **Menyala**.
   - Dari halaman **Hardware / Kontrol Perangkat** di aplikasi POS, klik tombol **Test Connection / Pair** atau **Mute / Volume** pada TV #1.
   - **Perhatikan layar fisik TV Samsung #1**: akan muncul pop-up **"Allow 'Sugeng Rawuh POS' to connect?"**.
   - Gunakan remote fisik TV untuk memilih **Allow (Izinkan)** dalam waktu 15 detik.
   - Setelah diizinkan, aplikasi POS akan menerima dan menyimpan **Token Pairing (`auth_token`)** secara otomatis di database, sehingga perintah berikutnya tidak akan meminta konfirmasi lagi.

### Tahap 3: Uji Perintah Volume, Mute, & Standby (`KEY_POWER`) ke Samsung TV
1. Dari dashboard kontrol perangkat di POS, kirim perintah uji **Volume Up / Mute** -> pastikan indikator volume di layar TV Samsung #1 bergerak.
2. Kirim perintah **Power Off / Standby** -> pastikan layar TV Samsung #1 mati (masuk ke mode *Standby*).

### Tahap 4: Uji Wake-on-LAN (Menyalakan TV dari Kondisi Standby)
1. Tunggu sekitar 10–15 detik setelah TV Samsung #1 mati (*Standby*).
2. Dari aplikasi POS, kirim perintah **Power On (Wake-on-LAN)** atau mulai sesi baru pada Station #1.
3. Pastikan layanan `wol-helper.ps1` mencatat pengiriman paket di `C:\SugengRawuh\data\logs\wol-helper.log` dan layar TV Samsung #1 **menyala kembali secara otomatis** dalam 3–8 detik.

### Tahap 5: Uji Shelly Smart Relay (`Switch.Set` & Verifikasi `Switch.GetStatus`)
1. Pada Station Billiard #1 yang terhubung ke Shelly (`192.168.88.201`), kirim perintah **Turn ON** -> pastikan lampu meja Billiard menyala dan status balikan (`Switch.GetStatus` -> `"output": true`) tercatat `APPLIED` di log hardware.
2. Kirim perintah **Turn OFF** -> pastikan lampu meja Billiard mati dan status balikan tercatat `"output": false`.

### Tahap 6: Uji Peringatan Sisa Waktu 10 Menit (10-Minute Alert)
1. Mulai sesi **Paket / Prepaid** pada Station #1.
2. Saat sisa waktu mencapai **10 menit** (atau uji melalui simulasi sisa waktu 10 menit), pastikan:
   - Daemon (`sugeng_rawuh_daemon`) mendeteksi ambang batas 10 menit dalam ≤ 2 detik.
   - `tv_alert_10m_sent_at` tercatat tepat satu kali (tidak berulang-ulang).
   - Notifikasi pengingat sisa waktu 10 menit terkirim sesuai konfigurasi perangkat pada station tersebut.

### Tahap 7: Uji Sesi Habis Waktu (Auto-Shutdown saat Expired)
1. Biarkan sesi Prepaid pada Station #1 berjalan hingga sisa waktu mencapai **00:00 (Habis)**.
2. Pastikan dalam waktu **1–2 detik** setelah waktu habis:
   - `sugeng_rawuh_daemon` mengubah status sesi menjadi selesai / `EXPIRED_PENDING_ACTION`.
   - Perintah **Standby TV** (atau matikan lampu Shelly untuk Billiard) langsung dieksekusi otomatis.
   - Status meja/station di dashboard Kasir kembali menjadi **Tersedia**.

### Tahap 8: Membuka Akses untuk Seluruh Station di Cabang Sugeng Rawuh 2
Setelah Station #1 lulus Tahap 1 s/d 7 dengan sempurna:
1. Ubah `HARDWARE_REAL_ALLOWED_DEVICES=*` di dalam `C:\SugengRawuh\.env.production`:
   ```env
   HARDWARE_REAL_EXECUTION_ENABLED=true
   HARDWARE_REAL_ALLOWED_DEVICES=*
   ```
2. Jalankan:
   ```powershell
   Set-Location C:\SugengRawuh
   docker compose --env-file .env.production up -d
   docker exec sugeng_rawuh_app php artisan config:cache
   ```
3. Lakukan pairing satu-kali (`Allow` di layar TV) untuk setiap unit TV Samsung berikutnya (Meja 2, Meja 3, dst.).

