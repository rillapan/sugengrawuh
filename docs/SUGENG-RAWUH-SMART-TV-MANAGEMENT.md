# SUGENG RAWUH — PANDUAN LENGKAP MANAJEMEN SAMSUNG SMART TV

**Project:** Sugeng Rawuh — Integrated Billing & POS Management System  
**Repository:** `sistem_kasir_PS-main`  
**Document Ref:** `docs/SUGENG-RAWUH-SMART-TV-MANAGEMENT.md`  
**Hardware Standar Toko:** Samsung Smart TV 50U8500 (Sistem Operasi Tizen)  
**Kebijakan Perangkat Target:**  
> **SELURUH TV PLAYSTATION MENGGUNAKAN SAMSUNG SMART TV.**  
> Tidak ada lagi televisi Coocaa dalam arsitektur target rental Sugeng Rawuh.  
> *(Catatan Audit: Setiap sisa kata atau referensi 'Coocaa' pada dokumentasi lama atau komentar kode diklasifikasikan sebagai **Legacy Reference / Cleanup Candidate** dan tidak digunakan dalam operasional target).*

---

## 1. PENDAHULUAN & ARSITEKTUR KONTROL SMART TV

Di rental Sugeng Rawuh, televisi Samsung Smart TV 50U8500 berfungsi sebagai layar utama (*Display Monitor*) untuk station konsol PlayStation (PS3, PS4, dan PS5).

### Prinsip Utama Integrasi:
1. **Identitas Jaringan Ganda (IP & MAC Address):**
   - **IP Address:** Digunakan sebagai alamat tujuan pengiriman instruksi protokol HTTP REST (port 8001) dan Secure WebSocket (port 8002).
   - **MAC Address:** Digunakan sebagai identitas perangkat keras unik untuk *DHCP Reservation* pada router toko dan persiapan transmisi paket *Wake-on-LAN* (WOL).
2. **Keamanan & Otorisasi Token:**
   - TV Samsung tidak dapat dikendalikan sembarangan hanya dengan mengetahui IP-nya.
   - TV mewajibkan proses *Pairing Handshake* satu kali, di mana layar TV menampilkan jendela konfirmasi izin akses remote. Token sesi yang diterima disimpan secara terenkripsi (`encrypted`) di database.
3. **Pemisahan Pengendalian Otomasi vs Remote Manual:**
   - Perintah daya otomatis (`POWER_ON` dan `STANDBY`) **belum diverifikasi secara fisik pada perangkat nyata** (menunggu Gate G4-B2).
   - `AutomationCapabilityPolicy` secara aktif mencegat perintah daya otomatis dan menandainya sebagai `SKIPPED_UNVERIFIED_CAPABILITY`. Hal ini dilakukan guna mencegah tombol daya Samsung yang bersifat *toggle* membalik status TV secara tidak sengaja.
   - **Remote fisik Samsung selalu disediakan di meja kasir sebagai kendali manual utama.**

---

## 2. KONEKTIVITAS JARINGAN LOKAL & ROUTER TOKO

Sebelum didaftarkan ke aplikasi, Samsung Smart TV harus terhubung dengan stabil ke jaringan lokal toko.

```
+------------------+         Kabel LAN / Wi-Fi 5GHz        +--------------------------+
|  PC Kasir Host   | <===================================> |   Router LAN / Wi-Fi     |
| (192.168.1.10)   |                                       | (Gateway: 192.168.1.1)   |
+------------------+                                       +--------------------------+
                                                                        ^
                                                                        | Kabel LAN / Wi-Fi
                                                                        v
                                                           +--------------------------+
                                                           | Samsung Smart TV 50U8500 |
                                                           | IP Statis: 192.168.1.50  |
                                                           | MAC: AA:BB:CC:DD:EE:11   |
                                                           +--------------------------+
```

### Prosedur Konfigurasi di Televisi Samsung:
1. Nyalakan TV menggunakan remote fisik Samsung.
2. Buka menu **Pengaturan (Settings)** -> **Umum (General)** -> **Jaringan (Network)** -> **Buka Pengaturan Jaringan**.
3. Pilih **Kabel (LAN)** untuk stabilitas optimal, atau pilih **Nirkabel (Wi-Fi)** dan sambungkan ke SSID Wi-Fi toko (contoh: `SugengRawuh_LAN`).
4. Masuk ke **Status Jaringan** -> **Pengaturan IP**.
5. Catat **Alamat IP** (misal: `192.168.1.50`) dan **Alamat MAC** (misal: `AA:BB:CC:DD:EE:11`).
6. Masuk ke menu **Pengaturan Ahli (Expert Settings)** -> Aktifkan opsi **Nyalakan dengan Ponsel (Power On with Mobile)**. Opsi ini penting agar antarmuka jaringan TV tetap siaga saat TV berada dalam mode standby.

### Konfigurasi Router Toko (Wajib oleh Teknisi / Admin):
- Buat aturan **DHCP Static Lease / DHCP Reservation** pada router toko dengan mengikat MAC Address TV ke IP Address tetap (misal: `AA:BB:CC:DD:EE:11` -> `192.168.1.50`).
- Hal ini mencegah IP Address TV berubah saat router di-restart.

---

## 3. PANDUAN ADMIN: REGISTRASI SAMSUNG SMART TV

### Alur Registrasi Melalui Antarmuka Web:
1. Login sebagai **ADMIN** di [http://127.0.0.1:8008/login](http://127.0.0.1:8008/login).
2. Pada menu sidebar, pilih **Hardware IoT** -> klik **Device Registry** ([http://127.0.0.1:8008/admin/hardware/registry](http://127.0.0.1:8008/admin/hardware/registry)).
3. Klik tombol hijau **+ Tambah Perangkat**.
4. Isi formulir pendaftaran:
   - **Nama Perangkat:** Beri nama deskriptif, contoh: `Samsung Smart TV PS 01`.
   - **Kategori Perangkat:** Pilih `Smart TV`.
   - **Merek Perangkat (Brand):** Pilih `SAMSUNG`. *(Catatan: Pilihan lain selain SAMSUNG dan SHELLY akan ditolak oleh validator backend).*
   - **Model Perangkat:** Masukkan `50U8500`.
   - **MAC Address:** Masukkan format 12 karakter hex, contoh: `AA:BB:CC:DD:EE:11`.
   - **IP Address:** Masukkan IP lokal TV, contoh: `192.168.1.50`.
5. Klik **Simpan Perangkat**.

### Status Perangkat yang Tersimpan:
- `connection_status`: `UNVERIFIED` (Menunggu pengujian koneksi jaringan).
- `pairing_status`: `UNPAIRED` (Menunggu otorisasi token).
- `verified_capabilities`: `["GET_STATUS", "MUTE", "UNMUTE"]`.
- `pairing_payload`: `NULL` (Terenkripsi AES-256).

---

## 4. PANDUAN ADMIN: MEMASANGKAN (MAPPING) TV KE STASIUN PLAYSTATION

Setelah TV terdaftar dalam registry, TV harus dipetakan ke stasiun PlayStation agar sistem mengetahui televisi mana yang berasosiasi dengan meja tertentu.

### Prosedur Mapping:
1. Buka menu **Hardware IoT** -> pilih **Station Mapping** ([http://127.0.0.1:8008/admin/hardware/mappings](http://127.0.0.1:8008/admin/hardware/mappings)).
2. Pada dropdown **Pilih Stasiun**, pilih konsol PlayStation yang diinginkan (contoh: `PlayStation 01`).
3. Pada panel **Pasangkan Hardware Baru**:
   - **Perangkat Fisik:** Pilih `Samsung Smart TV PS 01`.
   - **Peran Perangkat (Role):** Pilih `DISPLAY_TV`.
4. Klik tombol biru **Pasangkan ke Station** (`POST /admin/stations/{id}/mappings`).
5. **Validasi Invarian Sistem:**
   - Stasiun PlayStation **hanya dapat dipasangkan dengan perangkat kategori SMART_TV** dengan peran `DISPLAY_TV`.
   - Satu stasiun hanya boleh memiliki tepat 1 Smart TV aktif.
   - Pemasangan Smart Relay (Shelly) pada stasiun PlayStation dilarang keras dan akan ditolak oleh `StationMappingService`.

---

## 5. PENGUJIAN DIAGNOSTIK & PROSES PAIRING TOKEN

Untuk menguji apakah aplikasi dapat berkomunikasi dengan TV Samsung di laboratorium, Admin menggunakan modul **Hardware Diagnostics**.

### A. Pengujian Keterjangkauan Jaringan (Probe)
1. Buka [http://127.0.0.1:8008/admin/hardware/diagnostics](http://127.0.0.1:8008/admin/hardware/diagnostics).
2. Cari perangkat `Samsung Smart TV PS 01`.
3. Klik tombol **Probe Network**.
4. Driver mengirimkan HTTP GET ke `http://192.168.1.50:8001/api/v2/`.
5. Jika TV merespons dalam 2 detik, badge status koneksi berubah menjadi hijau (`ONLINE`).

### B. Otorisasi Layar TV & Penyimpanan Token (Pairing)
1. Pastikan TV dalam kondisi menyala dan layar menampilkan siaran/game.
2. Pada halaman diagnostik, klik tombol **Pair Device**.
3. Driver membuka koneksi WebSocket aman ke `wss://192.168.1.50:8002/api/v2/channels/samsung.remote.control?name=U3VnZW5nUmF3dWg=`.
4. Di layar TV Samsung, jendela pop-up akan muncul:
   > *"Sugeng Rawuh ingin menghubungkan ke TV Anda. Izinkan?"*
5. Gunakan remote fisik Samsung, arahkan kursor ke tombol **Izinkan (Allow)**, lalu tekan **OK / Enter**.
6. TV mengirimkan frame JSON konfirmasi:
   ```json
   {
       "event": "ms.channel.connect",
       "data": {
           "token": "samsung_auth_token_string_here"
       }
   }
   ```
7. `SamsungTvDriver` menangkap token tersebut dan menyimpannya di kolom `physical_devices.pairing_payload` menggunakan enkripsi Laravel.
8. Status pairing berubah menjadi **PAIRED**.

---

## 6. SIKLUS OTOMASI TV DALAM TRANSAKSI KASIR

Bagaimana TV Samsung merespons aktivitas transaksi kasir sehari-hari?

| Kejadian Billing Kasir | Aksi Backend & Outbox Worker | Perilaku Samsung Smart TV | Tindakan Petugas Kasir |
|---|---|---|---|
| **Kasir Mulai Billing (Prepaid / Lost Time)** | `BillingService` membuat intent `BILLING_STARTED`. | Worker membaca intent. `AutomationCapabilityPolicy` mencegat perintah daya (`POWER_ON` dilewati/skipped). | **Kasir menyalakan TV secara manual** menggunakan remote fisik Samsung jika TV masih mati. |
| **Sesi Main Berlangsung** | Kasir memantau timer di dasbor `/device`. | TV menampilkan gameplay dari kabel HDMI PlayStation tanpa gangguan. | Kasir melayani pelanggan atau pesanan FnB. |
| **Pelanggan Minta Tambah Jam (Extend)** | `BillingService` membatalkan jadwal mati lama dan membuat jadwal baru. | Tidak ada kedipan atau gangguan sinyal pada TV Samsung. | Transaksi perpanjangan tercatat aman. |
| **Paket Prepaid Habis / Kasir Klik Stop** | Status transaksi menjadi `EXPIRED_PENDING_ACTION` atau `COMPLETED`. Intent `BILLING_EXPIRED` diproses worker. | `AutomationCapabilityPolicy` mencegat perintah `STANDBY` (`SKIPPED_UNVERIFIED_CAPABILITY`). TV **TIDAK DIMATIKAN TIBA-TIBA** lewat software. | **Kasir mematikan TV secara manual** dengan remote fisik Samsung, atau membiarkannya standby jika ada pelanggan berikutnya. |

> **Rasional Kebijakan Desain:**  
> Tombol daya remote Samsung (`KEY_POWER`) bersifat bolak-balik (*toggle*). Jika pelanggan sudah mematikan TV secara manual lalu komputer mengirim perintah `STANDBY`, TV justru akan menyala kembali di ruang kosong. Oleh karena itu, proteksi keselamatan melarang pengiriman daya otomatis hingga verifikasi fisik G4-B2 tuntas.

---

## 7. PANDUAN TROUBLESHOOTING & GANGGUAN KONEKSI

### Masalah 1: Status TV Tetap "OFFLINE" di Dasbor
* **Penyebab:**
  1. TV Samsung dalam kondisi mati total (dicabut dari stopkontak).
  2. Kabel LAN terlepas atau koneksi Wi-Fi TV terputus.
  3. IP Address TV berubah karena router tidak mengunci DHCP reservation.
* **Solusi Perbaikan:**
  1. Periksa lampu indikator merah kecil di bawah TV; pastikan menyala.
  2. Buka menu jaringan di TV dan periksa kembali IP Address-nya.
  3. Jika IP berubah, Admin memperbarui IP di halaman [Device Registry](http://127.0.0.1:8008/admin/hardware/registry).
  4. Lakukan ping dari komputer kasir: buka terminal lalu ketik `ping 192.168.1.50`.

### Masalah 2: Otorisasi Pairing Ditolak (Status: FAILED)
* **Penyebab:**
  - Kasir atau pelanggan tidak sengaja menekan tombol "Tolak" (*Deny*) pada layar pop-up TV saat proses pairing dimulai.
* **Solusi Perbaikan:**
  1. Buka menu TV Samsung: **Pengaturan** -> **Umum** -> **Manajer Perangkat Eksternal** -> **Manajer Koneksi Perangkat**.
  2. Pilih **Daftar Perangkat (Device Connection List)**.
  3. Cari entri bernama `Sugeng Rawuh` yang berstatus ditolak, lalu ubah menjadi **Izinkan (Allow)** atau hapus entri tersebut.
  4. Ulangi proses pairing dari menu [Hardware Diagnostics](http://127.0.0.1:8008/admin/hardware/diagnostics).

### Masalah 3: Layar TV Menampilkan "Tidak Ada Sinyal" (No Signal / Layar Biru)
* **Penyebab:**
  - Input port HDMI di TV belum mengarah ke konsol PlayStation yang aktif.
* **Solusi Perbaikan:**
  - Tekan tombol **Source** atau **Input** pada remote fisik Samsung, lalu pilih **HDMI 1** (atau port HDMI yang terpasang kabel PlayStation).

---

## 8. PERINGATAN KESELAMATAN PERANGKAT KERAS

> ⚠️ **PERINGATAN PENTING UNTUK SELURUH STAF KASIR DAN ADMIN:**  
> 1. **DILARANG MEMUTUS DAYA LISTRIK TV MENGGUNAKAN RELAY SAKLAR LISTRIK!**  
>    Smart Relay (Shelly) di rental kita secara mutlak HANYA dipasang untuk lampu meja biliar. Memutus aliran listrik TV Samsung atau konsol PlayStation secara mendadak dapat membakar komponen inverter TV dan merusak SSD konsol game.  
> 2. **PENGGUNAAN REMOTE FISIK:**  
>    Remote fisik bawaan Samsung TV adalah instrumen operasional resmi rental. Selalu simpan remote di laci meja kasir dalam kondisi baterai terisi.

---

*Dokumen ini merupakan panduan resmi manajemen Samsung Smart TV rental Sugeng Rawuh dan menggantikan seluruh panduan TV lama.*
