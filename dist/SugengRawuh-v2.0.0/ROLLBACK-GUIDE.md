# Sugeng Rawuh Billing & POS — Panduan Rollback & Pemulihan Sistem (Rollback Guide)
**Target Versi:** Prosedur Downgrade / Rollback dari v2.0.0 ke v1.0.0
**Target Lingkungan:** PC Kasir Windows 11 Home Single Language x64, Sugeng Rawuh 2

Dokumen ini menjelaskan langkah demi langkah prosedur pemulihan darurat (*Disaster Recovery*) dan *rollback* ke versi 1.0.0 apabila ditemukan kendala kritis selama pengujian atau operasional versi 2.0.0.

> [!CAUTION]
> **Prinsip Utama:** Data transaksi kasir, riwayat keuangan, dan sesi billing **TIDAK BOLEH HILANG** selama proses rollback. Selalu ikuti urutan pembuatan backup sebelum melakukan tindakan pemulihan.

---

## 1. Ringkasan Strategi Rollback

1. **Kompatibilitas Skema Database:** Skema database v2.0.0 bersifat aditif (menambahkan kolom dan tabel baru non-destruktif: `physical_devices`, audit token, dll.). Skema ini sepenuhnya kompatibel mundur (*backward compatible*) dengan kode backend v1.0.0.
2. **Snapshot Cadangan Otomatis:** Setiap kali upgrade atau perbaikan dijalankan, skrip `update-app.ps1` secara otomatis telah membuat snapshot cadangan bertanda `pre-update-*` di folder `data\backups\`.
3. **Persistensi Data:** Data database tersimpan di named volume Docker `sr_mysql_data`. Proses pengembalian container **TIDAK PERNAH** menggunakan perintah `docker compose down -v` agar volume data tetap utuh.

---

## 2. Skenario 1: Rollback Cepat via Gambar Docker (Fast Rollback)

Gunakan skenario ini jika data database tidak mengalami korupsi dan Anda hanya ingin mengembalikan aplikasi ke versi kode 1.0.0:

### Langkah 1: Buka PowerShell sebagai Administrator
Klik kanan tombol Start Windows -> pilih **Terminal (Admin)** atau **PowerShell (Admin)**.

### Langkah 2: Masuk ke Folder Instalasi
```powershell
# Ganti dengan drive instalasi Anda jika di D:\
Set-Location "D:\SugengRawuh"
```

### Langkah 3: Ambil Snapshot Keamanan Database
```powershell
& .\installer\scripts\backup-db.ps1 -InstallDir "D:\SugengRawuh" -Reason "pre-rollback"
```
Pastikan file backup baru terbentuk di folder `data\backups\sugeng_rawuh_pos_pre-rollback_*.sql.gz`.

### Langkah 4: Alihkan Tag Image Aplikasi ke Versi 1.0.0
Karena bundle image offline versi 2.0.0 juga telah memuat tag `sugeng-rawuh-app:1.0.0`, Anda dapat langsung mengganti versi image yang digunakan container tanpa perlu mengunduh ulang:

1. Buka file `docker-compose.yml` dengan Notepad:
   ```powershell
   notepad docker-compose.yml
   ```
2. Temukan baris layanan `app`, `daemon`, dan `scheduler`, pastikan menggunakan image:
   ```yaml
   image: sugeng-rawuh-app:1.0.0
   ```
3. Simpan file (`Ctrl + S`) dan tutup Notepad.

### Langkah 5: Jalankan Ulang Stack Layanan
```powershell
docker compose --env-file .env.production up -d --no-build
```

### Langkah 6: Jalankan Rekonsiliasi Transaksi
```powershell
docker compose --env-file .env.production exec -T app php artisan billing:reconcile
```
Aplikasi kasir v1.0.0 kini kembali aktif pada `http://localhost:8008`.

---

## 3. Skenario 2: Rollback Penuh Menggunakan Installer v1.0.0 (Clean Rollback)

Gunakan skenario ini jika terjadi kendala pada skrip Windows atau ingin memasang ulang v1.0.0 secara menyeluruh:

### Langkah 1: Buat Backup Manual Database
```powershell
Set-Location "D:\SugengRawuh"
& .\installer\scripts\backup-db.ps1 -InstallDir "D:\SugengRawuh" -Reason "pre-reinstall-v1"
```
Salin file backup `.sql.gz` dan file `.env.production` ke lokasi yang aman (misal Flashdisk).

### Langkah 2: Hentikan Layanan Berjalan
```powershell
docker compose --env-file .env.production stop
```
*(Jangan jalankan `docker compose down -v`!)*

### Langkah 3: Jalankan Installer SugengRawuh-Setup-v1.0.0.exe
1. Klik kanan pada installer versi sebelumnya (`SugengRawuh-Setup-v1.0.0.exe`) -> **Run as administrator**.
2. Pilih folder instalasi yang sama (`D:\SugengRawuh`).
3. Installer akan menimpa binary dan skrip dengan versi 1.0.0 tanpa merusak database dan file konfigurasi.

---

## 4. Prosedur Restore Database dari Cadangan (Jika Data Diperlukan Pulih)

Jika selama proses terjadi kesalahan atau ingin mengembalikan database ke titik waktu sebelum uji coba v2.0.0:

### Langkah 1: Identifikasi File Cadangan
Periksa daftar backup di folder `data\backups\`:
```powershell
Get-ChildItem -Path "D:\SugengRawuh\data\backups" -Filter "*.sql.gz" | Sort-Object LastWriteTime -Descending
```
Pilih file cadangan yang sesuai, misalnya:
`D:\SugengRawuh\data\backups\sugeng_rawuh_pos_pre-update_20261112_080000.sql.gz`

### Langkah 2: Jalankan Skrip Restore Resmi
```powershell
& .\installer\scripts\restore-db.ps1 `
    -InstallDir "D:\SugengRawuh" `
    -BackupFile "D:\SugengRawuh\data\backups\sugeng_rawuh_pos_pre-update_20261112_080000.sql.gz"
```

### Apa yang Dilakukan oleh Skrip Restore:
1. Memverifikasi keutuhan file backup melalui checksum `.sha256`.
2. Secara otomatis membuat snapshot keselamatan darurat (`pre-restore-safety-*.sql.gz`) sebelum restore dimulai.
3. Mengembalikan tabel dan data MySQL secara bersih ke database `sugeng_rawuh_pos`.
4. Menjalankan `php artisan migrate --force` untuk memastikan integritas skema.
5. Menjalankan `php artisan billing:reconcile` untuk menyelaraskan status stasiun dan timer kasir.

---

## 5. Verifikasi Keberhasilan Pasca-Rollback

Setelah proses rollback selesai, lakukan pengujian cepat berikut:
1. Buka browser ke `http://localhost:8008`.
2. Login sebagai Kasir atau Admin.
3. Periksa riwayat transaksi terakhir di menu **Laporan Transaksi**: Pastikan seluruh data transaksi hari ini ada dan tidak ada yang hilang.
4. Periksa stasiun permainan: Pastikan stasiun PS dan Meja Biliar berada pada status yang benar (Tersedia / Digunakan).
5. Uji mulai satu sesi billing baru dan pastikan perhitungan timer berjalan lancar.

