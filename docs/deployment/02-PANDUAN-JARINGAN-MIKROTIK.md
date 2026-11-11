# Panduan Jaringan LAN & Konfigurasi MikroTik — Sugeng Rawuh Billing & POS

Dokumen ini menjelaskan cara menemukan alamat IP dan MAC Address **PC Kasir**, membedakannya dari alamat IP **Samsung Smart TV** maupun **Shelly Smart Relay**, mengunci IP PC Kasir di router **MikroTik** (Static DHCP Lease), mengatur akses dari perangkat lain di LAN, serta cara kerja arsitektur **Windows Host Wake-on-LAN (WOL) Helper**.

---

## 1. Mengetahui IPv4 LAN & MAC Address PC Kasir

Saat instalasi selesai, sistem secara otomatis mendeteksi kartu jaringan (NIC) fisik yang aktif dan menuliskan laporannya ke:
```text
C:\SugengRawuh\data\INFORMASI-JARINGAN-MIKROTIK.txt
```
Anda juga dapat membukanya dari **Start Menu** -> **Sugeng Rawuh Billing & POS** -> **Panduan Jaringan & MikroTik**.

Jika ingin memeriksa secara manual melalui PowerShell di PC Kasir, jalankan:
```powershell
Get-NetIPConfiguration | Where-Object { $_.IPv4DefaultGateway -ne $null } | ForEach-Object {
    $adapter = Get-NetAdapter -InterfaceIndex $_.InterfaceIndex
    [PSCustomObject]@{
        AdapterName = $_.InterfaceAlias
        IPv4Address = $_.IPv4Address.IPAddress
        MACAddress  = ($adapter.MacAddress -replace '-', ':')
        Gateway     = $_.IPv4DefaultGateway.NextHop
    }
}
```

### Penting: Membedakan IP di Jaringan Sugeng Rawuh 2
Jangan sampai tertukar antara 3 jenis alamat IP berikut di jaringan LAN MikroTik:

| Perangkat | Fungsi | Contoh IP LAN | Di Mana Dikonfigurasi? |
| :--- | :--- | :--- | :--- |
| **1. PC Kasir (Host Server)** | Menjalankan aplikasi web POS, MySQL, dan Billing Daemon (`:8008`) | `192.168.88.10` | Dikunci di DHCP Lease MikroTik; digunakan oleh HP/Tablet Owner untuk membuka `http://192.168.88.10:8008`. |
| **2. Samsung Smart TV** (`DISPLAY_TV`) | Layar TV tiap meja/station PS (dikontrol lewat WebSocket port `8001`/`8002` & Wake-on-LAN) | `192.168.88.101` s/d `192.168.88.120` | Dikunci di DHCP Lease MikroTik; dimasukkan ke menu **Hardware Devices** di dalam aplikasi POS (beserta MAC Address TV). |
| **3. Shelly Smart Relay** (`LIGHT_RELAY`) | Saklar pintar lampu meja Billiard (dikontrol lewat HTTP RPC `/rpc/Switch.Set`) | `192.168.88.201` s/d `192.168.88.210` | Dikunci di DHCP Lease MikroTik; dimasukkan ke menu **Hardware Devices** di dalam aplikasi POS. |

> **Peringatan**: Jangan pernah memasukkan IP PC Kasir ke dalam kolom IP Samsung TV atau Shelly di menu Hardware Devices.

---

## 2. Mengunci IP Statis (DHCP Reservation) di MikroTik

Agar alamat IP PC Kasir, Samsung Smart TV, dan Shelly Smart Relay **tidak berubah-ubah** saat listrik padam atau router MikroTik di-restart, wajib melakukan **Static DHCP Reservation** di MikroTik.

### Cara A: Menggunakan Aplikasi WinBox (GUI)
1. Buka **WinBox** dan login ke Router MikroTik cabang Sugeng Rawuh 2.
2. Buka menu **IP** -> **DHCP Server** -> pilih tab **Leases**.
3. Cari baris perangkat **PC Kasir** berdasarkan **Address** (IP saat ini) atau **MAC Address** maupun **Active Host Name**.
4. Klik dua kali pada baris tersebut, lalu klik tombol **Make Static** di sebelah kanan.
5. Tutup jendela, lalu buka kembali baris yang kini berhuruf tebal (tanpa huruf `D` / Dynamic):
   - Anda dapat mengubah kolom **Address** ke IP tetap yang rapi (misalnya `192.168.88.10` untuk PC Kasir).
   - Klik tombol **Comment** dan beri label jelas, contoh: `PC-KASIR-SUGENG-RAWUH-2`.
   - Klik **OK**.
6. Lakukan langkah yang sama (**Make Static** + **Comment**) untuk setiap unit **Samsung Smart TV** (`TV-PS-01`, `TV-PS-02`, dst.) dan **Shelly Smart Relay** (`SHELLY-BILLIARD-01`, dst.).

### Cara B: Menggunakan Terminal / CLI RouterOS MikroTik
Buka menu **New Terminal** di WinBox dan jalankan perintah yang sudah dibuat otomatis di `C:\SugengRawuh\data\INFORMASI-JARINGAN-MIKROTIK.txt`. Contoh:
```routeros
/ip dhcp-server lease
add address=192.168.88.10 mac-address=AA:BB:CC:DD:EE:FF server=defconf comment="PC-KASIR-SUGENG-RAWUH-2"
```
*(Jika lease dinamis sudah ada sebelumnya, Anda juga bisa mengubahnya menjadi statis dengan perintah)*:
```routeros
/ip dhcp-server lease make-static [find mac-address="AA:BB:CC:DD:EE:FF"]
/ip dhcp-server lease set [find mac-address="AA:BB:CC:DD:EE:FF"] comment="PC-KASIR-SUGENG-RAWUH-2"
```

---

## 3. Mengakses Aplikasi POS dari Perangkat Lain di LAN

Setelah PC Kasir terhubung ke MikroTik:
1. **Dari PC Kasir sendiri**:
   Buka browser Chrome/Edge ke:
   ```text
   http://localhost:8008
   ```
2. **Dari Tablet / HP / Laptop Owner di Jaringan Wi-Fi/LAN MikroTik yang Sama**:
   Buka browser ke alamat IP LAN PC Kasir pada port `8008`, contoh:
   ```text
   http://192.168.88.10:8008
   ```
3. **Jika variabel `APP_URL` perlu disesuaikan dengan IP LAN**:
   Skrip `configure-network.ps1` otomatis memperbarui `APP_URL=http://<IP-LAN-KASIR>:8008` di dalam `C:\SugengRawuh\.env.production`. Jika IP PC Kasir diubah di MikroTik, jalankan ulang:
   ```powershell
   powershell -ExecutionPolicy Bypass -File C:\SugengRawuh\installer\scripts\configure-network.ps1 -InstallDir C:\SugengRawuh
   ```

---

## 4. Keamanan Port & Aturan Windows Defender Firewall

Sistem dirancang dengan prinsip keamanan berlapis (*Defense-in-Depth*):
1. **Port Database MySQL (`13306`) Terkunci di Loopback Lokal**:
   Pada `docker-compose.yml`, port MySQL diikat secara ketat ke `127.0.0.1:13306:3306`. Artinya, database **tidak dapat diakses dari jaringan LAN luar**, melainkan hanya dari dalam PC Kasir sendiri untuk keperluan backup/administrasi lokal.
2. **Port Web POS (`8008`) Dibatasi pada Jaringan Lokal (`Private` / `LocalSubnet`)**:
   Skrip `configure-network.ps1` membuat aturan firewall bernama `SugengRawuh-POS-HTTP-8008` yang hanya mengizinkan koneksi masuk dari subnet lokal (`LocalSubnet`) pada profil jaringan `Private`.
3. **Port WOL Helper (`18009`) Dilindungi Token Rahasia (`X-WOL-Token`)**:
   Layanan helper Wake-on-LAN hanya menerima permintaan `POST /wol` yang menyertakan header autentikasi `X-WOL-Token` yang cocok dengan nilai `HARDWARE_WOL_HELPER_TOKEN` di `.env.production`.

---

## 5. Penjelasan Teknis: Mengapa Menggunakan Windows Host WOL Helper untuk Wake-on-LAN?

### Keterbatasan Docker Desktop pada Windows (WSL2 NAT Bridge)
Untuk menyalakan **Samsung Smart TV** dari kondisi *Standby* (mati remote), sistem mengirimkan paket **Wake-on-LAN (WOL) Magic Packet** menggunakan protokol **UDP Broadcast** ke alamat `255.255.255.255:9` (atau broadcast subnet LAN seperti `192.168.88.255:9`).

Pada Windows 10/11, Docker Desktop berjalan di dalam mesin virtual ringan **WSL2** yang terhubung melalui jaringan **Hyper-V / WSL2 NAT Bridge** (`172.x.x.x`). Secara arsitektur jaringan:
- Paket **TCP/HTTP/WebSocket** biasa (seperti perintah matikan TV ke `192.168.88.101:8002` atau perintah Shelly ke `192.168.88.201:80`) **dapat melewati NAT** tanpa masalah.
- Namun, paket **UDP Layer-2 Broadcast (`255.255.255.255`) TIDAK diteruskan melewati batas NAT WSL2** ke kartu jaringan Ethernet fisik Windows.

### Solusi Produksi: Authenticated Windows Host WOL Helper (`wol-helper.ps1`)
Untuk mengatasi keterbatasan tersebut secara elegan dan andal:
1. Container Laravel (`sugeng_rawuh_app` dan `sugeng_rawuh_daemon`) membaca konfigurasi:
   - `HARDWARE_WOL_HELPER_ENABLED=true`
   - `HARDWARE_WOL_HELPER_URL=http://host.docker.internal:18009/wol`
   - `HARDWARE_WOL_HELPER_TOKEN=<token-rahasia>`
2. Saat `SamsungTvDriver::wakeOnLan()` dipanggil, container mengirim permintaan HTTP POST ke `http://host.docker.internal:18009/wol` berisi JSON `{"mac":"AA:BB:CC:DD:EE:FF","broadcast":"255.255.255.255","port":9}` beserta header `X-WOL-Token`.
3. Layanan `wol-helper.ps1` yang berjalan langsung di **Host Windows 11** memvalidasi token, lalu menembakkan **6+16×MAC Magic Packet UDP** keluar melalui **seluruh interface IPv4 Ethernet fisik Windows** langsung ke switch MikroTik, sehingga TV Samsung langsung menyala.

### Memeriksa Status WOL Helper di PC Kasir
Untuk memastikan layanan WOL Helper berjalan sehat di Windows, buka browser atau PowerShell di PC Kasir:
```powershell
Invoke-RestMethod -Uri "http://127.0.0.1:18009/health"
```
Respons yang diharapkan:
```json
{
  "status": "ok",
  "service": "sugeng-rawuh-wol-helper"
}
```
Log aktivitas pengiriman paket WOL dapat dipantau di `C:\SugengRawuh\data\logs\wol-helper.log`.

