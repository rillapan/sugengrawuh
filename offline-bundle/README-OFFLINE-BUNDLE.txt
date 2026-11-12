================================================================================
SUGENG RAWUH BILLING & POS - PAKET DISTRIBUSI OFFLINE (v2.0.0 - Release Candidate)
Target Arsitektur: Windows 10/11 64-bit (x86_64 / AMD64) + Docker Desktop WSL2
================================================================================

STRUKTUR DIREKTORI PAKET OFFLINE:
---------------------------------
1. dist/SugengRawuh-v2.0.0/SugengRawuh-Setup-v2.0.0.exe
   Installer utama Windows 64-bit (Inno Setup 6). Sudah mencakup konfigurasi
   Docker Compose, skrip otomasi PowerShell (setup, launcher, repair, backup, WOL),
   dan arsip Docker Image linux/amd64.

2. offline-bundle/images/sugeng-rawuh-images-amd64.tar.gz
   Arsip terkompresi yang berisi Docker Image produksi (linux/amd64):
   - sugeng-rawuh-app:2.0.0   (PHP 8.2-FPM + Laravel 8 + Setup Wizard & Diagnostics)
   - sugeng-rawuh-app:1.0.0   (Tag kompatibilitas upgrade/rollback)
   - nginx:1.26-alpine        (Web Server Reverse Proxy)
   - mysql:8.0                (Database MySQL 8.0 Produksi)

3. offline-bundle/config/
   Salinan referensi konfigurasi produksi:
   - docker-compose.yml
   - .env.production.example
   - default.conf (Nginx)

4. offline-bundle/scripts/
   Salinan skrip operasional PowerShell untuk preflight, instalasi, launcher,
   service setup & repair, backup, restore, update, firewall LAN, dan WOL Helper.

5. offline-bundle/SHA256SUMS.txt & dist/SugengRawuh-v2.0.0/SHA256SUMS.txt
   Daftar checksum SHA-256 untuk memverifikasi keutuhan file setelah disalin
   melalui Flashdisk / Harddisk Eksternal.

CARA INSTALASI DI PC KASIR (SUGENG RAWUH 2):
--------------------------------------------
1. Pastikan WSL2 dan Docker Desktop Windows 64-bit sudah terpasang dan berjalan.
   (Jika belum ada internet di lokasi, pasang terlebih dahulu installer
   Docker Desktop Setup.exe dan wsl_update_x64.msi).
2. Klik kanan "SugengRawuh-Setup-v2.0.0.exe" -> "Run as administrator".
3. Ikuti panduan lengkap pada INSTALLATION-GUIDE.md atau docs/deployment/01-PANDUAN-INSTALASI-KASIR.md.
4. Setelah instalasi selesai, buka shortcut "Sugeng Rawuh - Hardware Setup & Test Center"
   untuk menyelesaikan onboarding Smart TV dan Shelly Relay.

