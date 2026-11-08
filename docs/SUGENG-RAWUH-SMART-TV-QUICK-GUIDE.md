# PANDUAN PRAKTIS OPERASIONAL SMART TV & BILLING PLAYSTATION
## SUGENG RAWUH — PANDUAN UNTUK PAK AMIN & TIM KASIR

**Tujuan Dokumen:**  
Panduan ringkas ini dibuat khusus untuk mempermudah **Pak Amin (Owner/Admin)** dan **Petugas Kasir** dalam mengoperasikan televisi pintar (Smart TV) dan sistem billing PlayStation sehari-hari di rental Sugeng Rawuh.

---

## 1. PENGELOMPOKAN TELEVISI DI TOKO KITA

Rental kita menggunakan dua merek Smart TV dengan perlakuan operasional yang **berbeda**:

| Merek & Tipe TV | Lokasi / Penggunaan | Status di Aplikasi | Cara Operasional Sehari-hari |
|---|---|---|---|
| **Samsung Smart TV 50U8500** | Station PlayStation Utama | **Terdaftar di Aplikasi** | Terhubung ke sistem kasir. Tetap siapkan remote fisik sebagai cadangan. |
| **Coocaa Smart TV 43S3U Plus** | Station PlayStation | **Tidak Masuk Aplikasi** | **100% Manual.** Wajib dinyalakan dan dimatikan menggunakan remote fisik Coocaa. |

> **PENTING UNTUK DIINGAT:**  
> TV Coocaa menggunakan sistem bawaan (*Coolita OS*) yang tidak bisa dikendalikan oleh kabel jaringan/komputer kasir. Jangan mencoba mendaftarkan TV Coocaa ke aplikasi karena sistem akan otomatis menolak.

---

## 2. LANGKAH AWAL: MENGHUBUNGKAN TV KE JARINGAN TOKO (ADMIN)

Agar TV Samsung dapat berkomunikasi dengan komputer kasir, TV harus terhubung ke Wi-Fi atau kabel LAN yang sama dengan komputer kasir.

### Langkah Menghubungkan TV Samsung:
1. Nyalakan TV Samsung menggunakan remote fisik.
2. Tekan tombol **Home / Menu** pada remote, pilih **Pengaturan (Settings)** -> **Umum (General)** -> **Jaringan (Network)**.
3. Pilih **Buka Pengaturan Jaringan (Open Network Settings)**.
4. Pilih **Kabel (LAN)** jika menggunakan kabel, atau pilih **Nirkabel (Wi-Fi)** lalu sambungkan ke Wi-Fi toko kita (misal: `SugengRawuh_LAN`).
5. Setelah terhubung, buka **Status Jaringan (Network Status)** -> **Pengaturan IP (IP Settings)**.
6. **Catat 2 hal penting ini:**
   * **Alamat IP (IP Address)**, contoh: `192.168.1.50`
   * **Alamat MAC (MAC Address)**, contoh: `AA:BB:CC:DD:EE:11`

*(Tips: Minta teknisi jaringan/router toko untuk mengatur "IP Statis" atau "DHCP Reservation" agar IP TV Samsung tidak pernah berubah).*

---

## 3. PANDUAN ADMIN (PAK AMIN): MENDAFTARKAN TV SAMSUNG

Lakukan langkah ini hanya sekali saat awal pemasangan TV baru atau jika TV berpindah tempat.

### A. Mendaftarkan TV ke Sistem
1. Buka browser di komputer kasir, masuk ke aplikasi Sugeng Rawuh menggunakan akun **ADMIN**.
2. Pada menu sebelah kiri, klik menu **Hardware** -> pilih **Device Registry**.
3. Klik tombol hijau **+ Tambah Perangkat**.
4. Isi data pada jendela yang muncul:
   * **Nama Perangkat:** Beri nama yang jelas, contoh: `TV Samsung PS 01`.
   * **Kategori Perangkat:** Pilih **Smart TV**.
   * **Merek (Brand):** Pilih **SAMSUNG**.
   * **Model Perangkat:** Ketik `50U8500`.
   * **MAC Address:** Masukkan MAC Address yang tadi dicatat (format: `AA:BB:CC:DD:EE:11`).
   * **IP Address:** Masukkan IP Address yang tadi dicatat (format: `192.168.1.50`).
5. Klik **Simpan Perangkat**.
6. Perangkat TV Samsung kini sudah resmi terdaftar di database rental.

---

## 4. PANDUAN ADMIN (PAK AMIN): MEMASANGKAN TV KE PLAYSTATION

Setelah TV terdaftar, kita harus memberi tahu komputer bahwa TV tersebut adalah layar milik meja PlayStation tertentu.

1. Pada menu sebelah kiri, klik **Hardware** -> pilih **Station Mapping**.
2. Pada dropdown **Pilih Station**, pilih meja PlayStation yang dituju (contoh: `PlayStation 01`).
3. Pada bagian **Pasangkan Hardware Baru**:
   * **Perangkat Fisik:** Pilih TV yang tadi didaftarkan (contoh: `TV Samsung PS 01`).
   * **Peran Perangkat:** Pilih **DISPLAY_TV** (Layar Utama TV).
4. Klik tombol biru **Pasangkan ke Station**.
5. Sistem akan menyimpan hubungan tersebut. Sekarang komputer kasir tahu bahwa jika ada pelanggan main di `PlayStation 01`, TV yang bersangkutan adalah `TV Samsung PS 01`.

---

## 5. PANDUAN PETUGAS KASIR: MENJALANKAN BILLING PLAYSTATION

Petugas kasir cukup fokus melayani pelanggan seperti biasa. Sistem kasir akan bekerja normal tanpa bergantung penuh pada respon TV.

### A. Memulai Main (Paket Jam / Prepaid)
1. Buka menu **Dashboard** atau **Manajemen Station**.
2. Cari meja PlayStation yang dipilih pelanggan (contoh: `PlayStation 01`).
3. Klik tombol **Mulai Billing**.
4. Pilih **Jenis Billing: Paket Jam (Prepaid)**.
5. Masukkan durasi sewa (misal: `2 Jam`) dan nama pelanggan (opsional).
6. Masukkan pembayaran pelanggan dan selesaikan transaksi.
7. Lampu indikator station di layar kasir akan berubah warna menjadi **Merah (DIGUNAKAN)** dan waktu mundur mulai berjalan.
8. **Tindakan Kasir pada TV:**
   * Jika station menggunakan **TV Samsung**: Komputer akan mencatat otomatisasi. Jika TV belum menyala sendiri, kasir cukup menekan tombol Power pada remote TV Samsung.
   * Jika station menggunakan **TV Coocaa**: Kasir langsung menekan tombol Power pada remote TV Coocaa dan mengarahkan input ke PlayStation (HDMI 1).

### B. Memulai Main (Main Sepuasnya / Lost Time)
1. Pada meja PlayStation, klik **Mulai Billing**.
2. Pilih **Jenis Billing: Lost Time (Bebas / Catat Waktu)**.
3. Selesaikan konfirmasi mulai.
4. Jam mulai dan durasi berjalan akan tercatat di komputer kasir.

### C. Menambah Waktu / Perpanjangan (Extend)
1. Klik tombol **Tambah Waktu** pada meja yang sedang main.
2. Masukkan tambahan jam (misal: `1 Jam`).
3. Selesaikan pembayaran tambahan. Waktu selesai otomatis bertambah di layar kasir.

### D. Menghentikan Sesi Main (Stop Billing)
1. Jika waktu paket jam habis otomatis, status meja akan berganti menjadi **KOSONG / WAKTU HABIS**.
2. Jika pelanggan minta selesai lebih awal, kasir klik tombol **Stop** pada meja tersebut, lalu lakukan pelunasan jika ada sisa tagihan.
3. **Tindakan Kasir pada TV:**
   * Matikan TV menggunakan remote fisik masing-masing (atau biarkan standby jika pelanggan berikutnya langsung menyambung).

---

## 6. PANDUAN PENGGUNAAN REMOTE FISIK TV (ATURAN EMAS KASIR)

| Situasi di Meja Rental | Yang Harus Dilakukan Kasir |
|---|---|
| **Meja dengan TV Coocaa** | **Selalu gunakan remote Coocaa** untuk menyalakan TV saat main mulai, dan mematikan TV saat main selesai. TV Coocaa tidak terhubung ke otomatisasi komputer. |
| **Meja dengan TV Samsung** | Jika TV Samsung tidak menyala otomatis saat billing dimulai, **jangan panik**. Langsung gunakan remote TV Samsung untuk menyalakan TV. Transaksi kasir tetap aman dan tercatat 100%. |
| **Pelanggan Ingin Mengecilkan Suara** | Berikan remote fisik TV atau atur tombol volume pada remote fisik. |
| **Layar TV Salah Input (Layar Biru / Semut)** | Tekan tombol **Source / Input** pada remote fisik, lalu pilih **HDMI 1** (port kabel PlayStation). |

---

## 7. MENGATASI GANGGUAN (TROUBLESHOOTING MUDAH)

### 1. Komputer Kasir Menampilkan Peringatan "TV Offline"
* **Artinya:** Komputer kasir sedang tidak bisa mengirim sinyal ke TV Samsung lewat kabel jaringan/Wi-Fi.
* **Tindakan Kasir:**
  1. Jangan batalkan transaksi pelanggan! Biarkan billing kasir tetap berjalan normal.
  2. Nyalakan TV secara manual dengan remote fisik.
  3. Beritahu Pak Amin (Admin) saat pergantian shift bahwa jaringan TV meja tersebut perlu diperiksa.

### 2. Layar TV Muncul Tulisan "Izinkan Koneksi / Allow Connection?"
* **Artinya:** Komputer kasir sedang mencoba mengenalkan diri (pairing) ke TV Samsung.
* **Tindakan:** Ambil remote TV Samsung, tekan tombol arah ke **Izinkan (Allow)**, lalu tekan tombol **OK / Enter**.

### 3. TV Samsung Tidak Mau Menyala
* **Pemeriksaan:**
  1. Periksa apakah lampu indikator merah kecil di bawah TV menyala (tanda TV dapat aliran listrik).
  2. Periksa baterai remote TV.
  3. Tekan tombol power fisik kecil di bawah rangka layar TV.

---

## 8. PERINGATAN KESELAMATAN & ASET TOKO (SANGAT PENTING!)

> ⚠️ **DILARANG KERAS MEMATIKAN TELEVISI ATAU PLAYSTATION DENGAN MENCABUT STOPKONTAK ATAU MEMATIKAN SAKLAR LISTRIK MEJA SECARA MENDADAK!**

* **Alasan Bahaya:**
  1. Memutus aliran listrik mendadak pada PlayStation dapat merusak hard disk / SSD dan merusak sistem game PlayStation.
  2. Memutus aliran listrik mendadak pada Smart TV dapat merusak komponen kapasitor dan layar motherboard TV.
* **Aturan Toko:**
  * Smart Relay (saklar otomatis listrik) di toko kita **hanya dipasang untuk lampu meja biliar**, bukan untuk PlayStation atau Smart TV!
  * Matikan PlayStation selalu melalui menu stik konsol (*Turn Off PS*), dan matikan TV selalu menggunakan tombol Power remote fisik.

---

## 9. RINGKASAN TUGAS HARIAN KASIR

1. **Buka Toko:** Nyalakan komputer kasir, login dengan akun kasir, pastikan jaringan Wi-Fi toko aktif.
2. **Saat Ada Pelanggan Datang:** Input billing di komputer kasir, pastikan TV meja menyala dan menampilkan layar PlayStation.
3. **Saat Pelanggan Selesai:** Pastikan transaksi selesai di komputer kasir, ambil stik PlayStation untuk disimpan rapi, dan matikan TV dengan remote fisik jika station tidak langsung digunakan.
4. **Tutup Toko:** Logout aplikasi kasir, pastikan semua stik PS dicas, dan semua TV sudah dalam kondisi mati/standby.

---

*Panduan Operasional Sugeng Rawuh — Dibuat untuk kenyamanan tim kasir dan keamanan aset toko.*
