# Sugeng Rawuh Billing & POS — Batasan Sistem & Limitasi Operasional (Known Limitations)
**Versi Rilis:** 2.0.0 (Release Candidate)
**Target Lingkungan:** PC Kasir Windows 11 Home Single Language x64, Sugeng Rawuh 2

Dokumen ini mendokumentasikan batasan teknis yang diketahui (*Known Limitations*), karakteristik arsitektur sistem operasi, dan skenario khusus yang memerlukan pemahaman teknisi selama pengoperasian sistem Sugeng Rawuh Billing & POS.

---

## 1. Keterbatasan Windows 11 Home Single Language

1. **Ketiadaan Mode Background Headless Tanpa Login (Service-Only Mode):**
   - **Deskripsi:** Windows 11 Home tidak memiliki fitur untuk menjalankan Docker Desktop atau WSL2 sebagai Windows Service murni sebelum pengguna melakukan login ke sistem.
   - **Dampak Operasional:** Jika komputer kasir dinyalakan ulang (*reboot*), layanan container Docker **belum akan berjalan** sampai ada pengguna yang login ke desktop Windows.
   - **Mitigasi Resmi:** 
     - Konfigurasikan Windows agar melakukan login otomatis (*Auto-Logon*) ke akun pengguna kasir menggunakan alat resmi `netplwiz` atau registri Windows.
     - Scheduled Task `SugengRawuh-Startup` akan otomatis memicu `startup-launcher.ps1` segera setelah login berhasil.

2. **Penggunaan Virtual Hard Disk WSL2 (`ext4.vhdx`) pada Drive C:**
   - **Deskripsi:** Docker Desktop WSL2 secara default menyimpan virtual disk distribusi Linux di `C:\Users\<User>\AppData\Local\Docker\wsl\data\ext4.vhdx`.
   - **Dampak Operasional:** Meskipun aplikasi diinstal pada drive `D:\SugengRawuh`, drive `C:\` tetap memerlukan ruang kosong minimal 6–10 GB.
   - **Mitigasi Resmi:**
     - `preflight-check.ps1` memeriksa ketersediaan ruang disk di drive `C:` dan `D:` secara terpisah.
     - Jika drive `C:` sangat terbatas, gunakan menu resmi Docker Desktop: *Settings -> Resources -> Advanced -> Disk image location* untuk memindahkan image disk ke drive `D:`. Dilarang memindahkan file `.vhdx` secara manual melalui File Explorer saat Docker aktif.

---

## 2. Keterbatasan Integrasi Smart TV Samsung & Protokol Pairing

1. **Kebutuhan Konfirmasi Fisik Remote TV pada Pairing Pertama:**
   - **Deskripsi:** Protokol keamanan Samsung Tizen OS (WebSocket port 8002/8001) mengharuskan otorisasi manual dari pemilik TV saat aplikasi baru pertama kali mencoba terhubung.
   - **Dampak Operasional:** Sistem **tidak dapat melakukan pairing 100% otomatis tanpa interaksi manusia**. Ketika tombol *"Mulai Pairing"* ditekan di wizard, layar Smart TV akan memunculkan jendela dialog: *"Izinkan perangkat Sugeng Rawuh POS tersambung?"*.
   - **Mitigasi Resmi:**
     - Teknisi atau staf kasir wajib memegang remote fisik Smart TV dan menekan tombol **Allow / Izinkan** dalam waktu 30 detik.
     - Setelah diizinkan, token enkripsi pairing disimpan secara permanen di database server (`physical_devices.pairing_payload`), sehingga pairing tidak perlu diulang kembali selama TV tidak di-reset pabrik.

2. **Keterbatasan Pengaktifan dari Kondisi Mati Total (Cold Power-On):**
   - **Deskripsi:** Smart TV Samsung yang terputus dari sumber listrik (kabel dicabut) atau berada dalam mode hemat daya ultra-rendah tidak dapat menerima perintah WebSocket.
   - **Mitigasi Resmi:**
     - Sistem menggunakan mekanisme **Wake-on-LAN (WOL)** melalui Windows Host WOL Helper (`wol-helper.ps1` port 18009) untuk mengirim Magic Packet broadcast ke MAC Address TV sebelum membuka koneksi WebSocket.
     - TV Samsung harus diatur pada menu TV: *Settings -> General -> Network -> Expert Settings -> Power On with Mobile = Enabled*.

3. **Status Smart TV Coocaa / Android TV Non-Samsung:**
   - **Deskripsi:** Driver resmi Sugeng Rawuh v2.0.0 saat ini hanya mendukung Samsung Tizen Smart TV dan Shelly Relay.
   - **Dampak:** Perangkat Coocaa ditandai sebagai `DRIVER_UNSUPPORTED` pada diagnostik. Otomasi daya untuk stasiun Coocaa harus dikendalikan melalui smart relay Shelly 1PM Mini pada colokan listrik TV, bukan melalui remote jaringan IP TV.

---

## 3. Keterbatasan Penemuan Jaringan (Network Discovery & L2 Broadcast)

1. **Isolasi Layer 2 vs Layer 3 pada Router MikroTik:**
   - **Deskripsi:** Protokol penemuan otomatis (mDNS, SSDP UPnP, dan ARP ping sweep) beroperasi secara optimal pada subnet Layer 2 (L2) yang sama.
   - **Dampak Operasional:** Jika Smart TV atau Shelly berada pada VLAN atau subnet yang berbeda (misal TV di `192.168.2.0/24` sedangkan PC Kasir di `192.168.1.0/24`), paket broadcast UDP penemuan otomatis tidak akan melintasi router tanpa konfigurasi IGMP Proxy / UDP Helper di MikroTik.
   - **Mitigasi Resmi:**
     - Seluruh perangkat kasir, Smart TV, dan smart relay di Sugeng Rawuh 2 **wajib berada dalam subnet IP yang sama** (misal: `192.168.1.0/24`).
     - Jika penemuan otomatis gagal menemukan perangkat tertentu, wizard menyediakan fitur **"Input Perangkat Manual"** berbasis IP dan MAC address statis.

2. **Docker Bridge NAT Outbound:**
   - **Deskripsi:** Container backend Laravel berjalan di dalam Docker Bridge network (`sr_internal`), mengakses jaringan fisik melalui IP host Windows (`host.docker.internal`).
   - **Dampak:** Container dapat mengirim paket TCP/HTTP/WebSocket ke perangkat LAN fisik secara outbound tanpa kendala, namun paket UDP broadcast mDNS dari TV lokal tidak dapat langsung diterima container tanpa bantuan host Windows.
   - **Mitigasi Resmi:**
     - Penemuan IP dilakukan via ARP sweep dan validasi port TCP terbuka (`8002` untuk Samsung TV, `80` untuk Shelly Mini).
     - Pengiriman Magic Packet Wake-on-LAN dialihkan ke skrip host Windows `wol-helper.ps1` yang mendengarkan pada `http://host.docker.internal:18009/wol`.

---

## 4. Batasan Validasi yang Belum Dilakukan di Lokasi Nyata

1. **Pengujian Fisik Perangkat Keras Aktual:**
   - **Kondisi Saat Ini:** Seluruh validasi v2.0.0 dilakukan di lingkungan pengembangan macOS dan Linux AMD64 container dengan unit/feature mock and static analysis.
   - **Implikasi:** Kecepatan respon TV Samsung nyata terhadap payload WebSocket, variasi firmware Shelly Gen3 di lokasi, dan latensi switch MikroTik fisik di Sugeng Rawuh 2 baru dapat dipastikan saat teknisi menjalankan protokol pengujian lapangan G4 (`docs/deployment/06-PROTOKOL-PENGUJIAN-LAPANGAN-G4.md`).
   - **Status Build:** Atas dasar ini, rilis v2.0.0 secara resmi diberi label **RELEASE CANDIDATE (RC)** sampai lembar observasi lapangan ditandatangani.

