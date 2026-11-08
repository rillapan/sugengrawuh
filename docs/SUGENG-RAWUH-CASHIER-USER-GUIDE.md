# PANDUAN PENGGUNAAN APLIKASI KASIR SUGENG RAWUH
## BUKU PANDUAN PRAKTIS OPERASIONAL KASIR RENTAL PLAYSTATION & BILIAR

**Target Pembaca:** Petugas Kasir Toko Sugeng Rawuh  
**Alamat Aplikasi:** Buka browser (Chrome / Edge / Safari), lalu buka alamat:  
👉 **[http://127.0.0.1:8008](http://127.0.0.1:8008)** (atau alamat IP komputer kasir yang ditentukan Admin).

---

## DAFTAR ISI

1. [Cara Masuk ke Aplikasi (Login)](#1-cara-masuk-ke-aplikasi-login)
2. [Mengenal Layar Dasbor Stasiun](#2-mengenal-layar-dasbor-stasiun)
3. [Cara Memulai Billing Paket Jam (Prepaid)](#3-cara-memulai-billing-paket-jam-prepaid)
4. [Cara Memulai Billing Main Sepuasnya (Lost Time)](#4-cara-memulai-billing-main-sepuasnya-lost-time)
5. [Cara Menambah Waktu Main (Perpanjangan / Extend)](#5-cara-menambah-waktu-main-perpanjangan--extend)
6. [Cara Menghentikan Sesi Main (Stop Billing)](#6-cara-menghentikan-sesi-main-stop-billing)
7. [Cara Menjual Makanan & Minuman (FnB)](#7-cara-menjual-makanan--minuman-fnb)
8. [Cara Menerima Pembayaran & Cetak Struk](#8-cara-menerima-pembayaran--cetak-struk)
9. [Aturan Penggunaan Remote TV Samsung](#9-aturan-penggunaan-remote-tv-samsung)
10. [Aturan Otomatisasi Meja Biliar](#10-aturan-otomatisasi-meja-biliar)
11. [Apa yang Harus Dilakukan Jika Ada Masalah?](#11-apa-yang-harus-dilakukan-jika-ada-masalah)
12. [Aturan Keselamatan Toko (Sangat Penting!)](#12-aturan-keselamatan-toko-sangat-penting)

---

## 1. CARA MASUK KE APLIKASI (LOGIN)

1. Buka browser di komputer kasir, ketik alamat: **[http://127.0.0.1:8008/login](http://127.0.0.1:8008/login)**.
2. Masukkan **Email Kasir** dan **Password** yang telah diberikan oleh Pak Amin (Admin).
3. Klik tombol biru **Login**.
4. Anda akan langsung diarahkan ke halaman utama (Dasbor).

---

## 2. MENGENAL LAYAR DASBOR STASIUN

Klik menu **Operasional** -> **Data Perangkat** di sebelah kiri (atau buka [http://127.0.0.1:8008/device](http://127.0.0.1:8008/device)).

Layar ini menampilkan kotak-kotak stasiun rental:
* 🟢 **KOTAK HIJAU (Tersedia):** Stasiun sedang kosong dan siap disewakan ke pelanggan.
* 🔴 **KOTAK MERAH (Digunakan):** Stasiun sedang dimainkan. Ada jam mulai, jam selesai, dan waktu hitung mundur (*countdown*).
* 🟡 **KOTAK KUNING (Waktu Habis):** Waktu sewa pelanggan sudah habis, menunggu pelunasan tagihan kasir.

---

## 3. CARA MEMULAI BILLING PAKET JAM (PREPAID)

Gunakan cara ini jika pelanggan meminta paket sewa berdurasi (contoh: main 1 jam atau 2 jam).

1. Pada halaman **Data Perangkat**, cari stasiun yang kosong (Kotak Hijau).
2. Klik tombol **Mulai Billing**.
3. Di jendela yang muncul:
   * **Jenis Billing:** Pilih **Paket Jam (Prepaid)**.
   * **Durasi Main:** Pilih durasi sewa (misal: 1 Jam, 2 Jam, 3 Jam).
   * **Nama Pelanggan:** Ketik nama panggilan pelanggan (opsional).
4. Klik tombol **Mulai Rental**.
5. **Tindakan Kasir pada TV Samsung:**
   * Ambil remote TV Samsung stasiun tersebut di laci kasir.
   * Tekan tombol **Power** pada remote jika TV belum menyala.
   * Pastikan layar TV menampilkan game PlayStation.
6. Stasiun sekarang berubah menjadi warna merah di komputer kasir dan waktu hitung mundur mulai berjalan otomatis.

---

## 4. CARA MEMULAI BILLING MAIN SEPUASNYA (LOST TIME)

Gunakan cara ini jika pelanggan berkata: *"Main dulu Mas/Mbak, nanti selesai kalau sudah capek"*.

1. Cari stasiun PlayStation yang kosong (Kotak Hijau).
2. Klik tombol **Mulai Billing**.
3. Di jendela yang muncul:
   * **Jenis Billing:** Pilih **Lost Time (Bebas / Catat Waktu)**.
   * **Nama Pelanggan:** Ketik nama pelanggan.
4. Klik tombol **Mulai Main**.
5. Nyalakan TV menggunakan remote fisik Samsung.
6. Layar kasir akan menampilkan waktu berjalan (*stopwatch*) yang menghitung menit main pelanggan dari nol.

---

## 5. CARA MENAMBAH WAKTU MAIN (PERPANJANGAN / EXTEND)

Jika pelanggan yang sedang main berteriak: *"Mas/Mbak, nambah 1 jam lagi ya!"*:

1. Cari kotak stasiun meja tersebut di layar kasir (yang sedang berwarna merah).
2. Klik tombol **Tambah Waktu** (atau ikon jam).
3. Masukkan tambahan waktu (contoh: `1` untuk nambah 1 jam).
4. Klik **Simpan**.
5. Komputer kasir akan otomatis memundurkan jam selesai dan menambahkan biaya sewa ke tagihan meja tersebut. TV pelanggan tidak akan mati atau berkedip.

---

## 6. CARA MENGHENTIKAN SESI MAIN (STOP BILLING)

### A. Untuk Paket Jam (Prepaid) yang Waktunya Habis Otomatis:
* Ketika waktu sewa habis di komputer kasir, kotak stasiun akan otomatis berganti status menjadi **Selesai / Waktu Habis**.
* Kasir mendatangi meja atau memanggil pelanggan, lalu mematikan TV menggunakan remote fisik Samsung.

### B. Untuk Pelanggan yang Minta Selesai Lebih Cepat (Stop Early) atau Selesai Lost Time:
1. Klik tombol **Stop** pada kotak stasiun meja tersebut.
2. Muncul jendela konfirmasi, klik **Ya, Hentikan Sesi**.
3. Komputer akan mengunci total waktu dan menghitung biaya main yang harus dibayar.
4. Matikan TV Samsung menggunakan remote fisik.
5. Komputer kasir akan langsung membuka layar pembayaran untuk menyelesaikan transaksi.

---

## 7. CARA MENJUAL MAKANAN & MINUMAN (FnB)

### A. Menambahkan Minuman/Makanan ke Meja yang Sedang Main:
1. Buka menu **Transaksi** ([http://127.0.0.1:8008/transaction](http://127.0.0.1:8008/transaction)).
2. Cari transaksi stasiun yang sedang aktif, klik tombol **Edit / Tambah Pesanan**.
3. Pilih menu (misal: *Teh Pucuk* atau *Indomie Goreng*).
4. Masukkan jumlah (Qty) pesanan.
5. Klik **Tambah ke Tagihan**. Tagihan akan otomatis bertambah ke meja tersebut dan stok barang di gudang kasir otomatis berkurang.

### B. Pelanggan Datang Hanya Beli Minuman (Tanpa Main Game):
1. Buka menu **Transaksi** -> Klik **+ Tambah Transaksi** ([http://127.0.0.1:8008/transaction/create](http://127.0.0.1:8008/transaction/create)).
2. Pilih barang yang dibeli pelanggan dan masukkan jumlahnya.
3. Klik **Simpan Transaksi** dan langsung lakukan pembayaran.

---

## 8. CARA MENERIMA PEMBAYARAN & CETAK STRUK

Setiap sesi main yang selesai harus diselesaikan pembayarannya di komputer kasir.

1. Buka halaman pembayaran untuk transaksi yang bersangkutan ([http://127.0.0.1:8008/transaction/{id}/payment](http://127.0.0.1:8008/transaction/1/payment)).
2. Layar menampilkan rincian total: sewa PlayStation + makanan/minuman yang dipesan.
3. Tanyakan ke pelanggan metode bayar yang diinginkan:
   * **Tunai (Cash):** Terima uang fisik, masukkan nominal uang di kolom *Jumlah Bayar*. Layar akan otomatis menghitung uang kembalian.
   * **E-Wallet:** Tunjukkan barcode QRIS toko, tunggu notifikasi uang masuk di HP toko, lalu pilih metode *e-wallet*.
   * **Transfer Bank:** Pastikan mutasi bank masuk, lalu pilih *transfer_bank*.
4. Klik tombol hijau **Proses Pembayaran**.
5. Sistem akan menyimpan transaksi dan otomatis membuka layar **Cetak Struk**.
6. Tekan tombol **Cetak / Print** (atau tekan `Ctrl + P` di keyboard) untuk mengeluarkan kertas struk dari printer kasir.
7. Berikan uang kembalian dan struk fisik kepada pelanggan.

---

## 9. ATURAN PENGGUNAAN REMOTE TV SAMSUNG

* **Kenapa Harus Pakai Remote Samsung?**  
  Sistem kasir kita menggunakan pengamanan pintar. Agar TV tidak mati atau menyala sendiri secara salah, perintah saklar TV sengaja tidak dikendalikan 100% oleh komputer.
* **Kapan Kasir Memakai Remote Fisik?**
  1. **Saat Main Dimulai:** Tekan tombol merah Power pada remote untuk menyalakan TV.
  2. **Saat Main Selesai:** Tekan tombol Power untuk mematikan TV ke posisi standby (layar hitam).
  3. **Jika Layar TV Biru / "No Signal":** Tekan tombol **Source** pada remote Samsung, lalu pilih **HDMI 1**.

---

## 10. ATURAN OTOMATISASI MEJA BILIAR

Berbeda dengan TV PlayStation, **lampu meja biliar dikendalikan secara otomatis oleh alat relay pintar (Shelly)**:

1. Saat kasir mengklik **Mulai Billing** untuk meja biliar, lampu meja biliar akan **otomatis menyala terang**.
2. **ATURAN MUTLAK WAKTU HABIS (Keputusan Toko):**  
   Begitu waktu paket biliar habis di komputer kasir, lampu meja biliar akan **LANGSUNG PADAM OTOMATIS** tanpa waktu tenggang.
3. Jika pelanggan ingin melanjutkan main, kasir harus segera menginput perpanjangan jam sewa di komputer kasir agar lampu otomatis menyala kembali.

---

## 11. APA YANG HARUS DILAKUKAN JIKA ADA MASALAH?

| Masalah yang Terjadi | Yang Harus Dilakukan Kasir |
|---|---|
| **Layar Kasir Muncul Tulisan "TV Offline"** | **Jangan panik!** Transaksi pelanggan tetap berjalan normal. Cukup gunakan remote fisik Samsung untuk menyalakan TV. Catat di buku kasir untuk dilaporkan ke Admin saat ganti shift. |
| **Salah Input Durasi Jam Sewa** | Jika pelanggan baru main dan jam sewa kelebihan/kekurangan, segera klik stasiun tersebut lalu lakukan penyesuaian waktu atau laporkan ke Admin toko. |
| **Kertas Printer Struk Habis** | Ganti gulungan kertas thermal di printer. Transaksi yang sudah selesai tidak akan hilang dan bisa dicetak ulang kapan saja dari menu **Transaksi** -> klik ikon **Print**. |
| **Koneksi Internet Toko Mati** | Komputer kasir Sugeng Rawuh tetap bisa bekerja normal karena sistem berjalan di komputer lokal toko (offline-ready). Kasir tetap bisa melayani rental seperti biasa. |

---

## 12. ATURAN KESELAMATAN TOKO (SANGAT PENTING!)

> ⚠️ **DILARANG KERAS MEMATIKAN TELEVISI ATAU PLAYSTATION DENGAN MENCABUT KABEL STOPKONTAK ATAU MEMATIKAN SAKLAR LISTRIK MEJA SECARA MENDADAK!**

1. **Alasan Bahaya:** Mematikan konsol PlayStation secara paksa tanpa prosedur normal (*Turn Off PS*) dapat menyebabkan kerusakan hard disk/SSD dan merusak sistem game PlayStation.
2. **Mematikan Peralatan:**  
   * TV Samsung: Selalu gunakan tombol Power pada remote fisik.  
   * Konsol PlayStation: Selalu matikan melalui menu di stik konsol (*Turn Off Console*).  
   * Komputer Kasir: Selalu lakukan *Shut Down* resmi dari menu Windows / Mac.

---

*Buku Panduan Kasir Sugeng Rawuh — Dibuat untuk kenyamanan kerja tim kasir dan kepuasan pelanggan.*
