# Sugeng Rawuh — PS4 GoldHEN Popup Notification Plugin (`sugengrawuh_notify.prx`)

Dokumentasi resmi modul ekstensi konsol PlayStation 4 untuk sistem **Sugeng Rawuh Billing & POS**.

---

## 1. Arsitektur Komunikasi

Sistem notifikasi PlayStation 4 menggunakan model **Outbound Polling** dari plugin GoldHEN ke API server Laravel:
1. **Bukan Direct Port 9090**: Port TCP 9090 pada PS4 GoldHEN adalah *BinLoader* (khusus injeksi payload binary ELF/BIN). Mengirim string teks mentah ke port 9090 akan memicu socket abort atau crash.
2. **Outbound Polling**: Plugin berjalan di background thread Orbis OS, melakukan polling periodik ke endpoint Laravel:
   `GET /api/console-agent/v1/events`
3. **Autentikasi Token Aman**: Menggunakan `X-Device-Token` / `Bearer` token unik per konsol yang di-hash dengan SHA-256 pada database server.
4. **Eksekusi Notifikasi Asli**: Memanggil API native Orbis OS (`sceKernelSendNotificationRequest`) untuk memunculkan popup di pojok atas layar tanpa mengganggu game yang sedang berjalan.
5. **Acknowledge (ACK)**: Melaporkan status konfirmasi balik ke server:
   `POST /api/console-agent/v1/events/{id}/ack` dengan status `NOTIFICATION_API_CALLED`.

---

## 2. Struktur Berkas

```
integrations/ps4-goldhen/
├── Makefile                # Skrip kompilasi OpenOrbis / Host
├── README.md               # Panduan instalasi dan operasional
├── config.ini              # Templat konfigurasi server & token
└── src/
    ├── http_client.c       # Klien HTTP socket ringan
    ├── http_client.h
    ├── main.c              # Lifecycle plugin GoldHEN & thread worker
    ├── notify.c            # Wrapper API native sceNotificationRequest
    └── notify.h
```

---

## 3. Cara Kompilasi

### A. Kompilasi untuk PS4 (`sugengrawuh_notify.prx`)
Membutuhkan [OpenOrbis Toolchain](https://github.com/OpenOrbis/OpenOrbis-PS4-Toolchain):
```bash
export OO_PS4_TOOLCHAIN=/opt/OpenOrbis/PS4Toolchain
cd integrations/ps4-goldhen
make clean && make
```
Hasil build: `sugengrawuh_notify.prx`

### B. Kompilasi untuk Testing Host (macOS / Linux)
```bash
cd integrations/ps4-goldhen
make clean && make
```
Hasil build: `sugengrawuh_notify_host`

---

## 4. Panduan Pemasangan pada Konsol PS4

### Langkah 1: Siapkan Device Token pada Kasir Sugeng Rawuh
1. Buka menu **Hardware Registry** di kasir: `http://<SERVER_IP>:8000/admin/hardware/registry`.
2. Daftarkan konsol PS4:
   - Kategori: `GAME_CONSOLE`
   - Brand: `SONY`
   - Model: `PS4_HEN`
   - IP Address: misal `192.168.1.52`
   - Driver: `PS4_GOLDHEN`
3. Salin **Device Token** yang dibuat otomatis (contoh: `sr_dev_a1b2c3d4...`).

### Langkah 2: Transfer Berkas ke PS4 via FTP
Gunakan FTP client (FileZilla) ke PS4 (Port 2121 / 1337):
1. Buat folder `/data/GoldHEN/plugins/sugengrawuh/`
2. Unggah `sugengrawuh_notify.prx` ke `/data/GoldHEN/plugins/sugengrawuh_notify.prx`
3. Edit `config.ini` dengan IP server dan Device Token:
   ```ini
   [server]
   server_url = http://192.168.1.100:8000
   device_token = sr_dev_a1b2c3d4e5f6...
   poll_interval = 3
   heartbeat_interval = 30
   ```
   Unggah berkas tersebut ke `/data/GoldHEN/plugins/sugengrawuh/config.ini`.

### Langkah 3: Aktifkan pada `plugins.ini`
Buka atau buat berkas `/data/GoldHEN/plugins.ini` di PS4, lalu tambahkan:
```ini
[default]
/data/GoldHEN/plugins/sugengrawuh_notify.prx=1
```

### Langkah 4: Muat Ulang Plugin
Restart konsol atau buka menu GoldHEN Settings > Plugin Manager > Reload Plugins.
Popup "Sugeng Rawuh Console Agent connected." akan muncul di layar TV Anda.

---

## 5. Cara Menghapus (Uninstall)
1. Hapus baris `/data/GoldHEN/plugins/sugengrawuh_notify.prx=1` dari `/data/GoldHEN/plugins.ini`.
2. Hapus berkas `/data/GoldHEN/plugins/sugengrawuh_notify.prx`.
3. Hapus folder `/data/GoldHEN/plugins/sugengrawuh/`.
