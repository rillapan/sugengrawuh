# SUGENG RAWUH — PANDUAN ADMINISTRATOR & OWNER (PEMILIK TOKO)
## PANDUAN STRATEGIS & TEKNIS PENGELOLAAN SISTEM

**Project:** Sugeng Rawuh — Integrated Billing & POS Management System  
**Repository:** `sistem_kasir_PS-main`  
**Document Ref:** `docs/SUGENG-RAWUH-ADMIN-OWNER-GUIDE.md`  
**Alamat Akses Sistem:** `http://127.0.0.1:8008`

---

## BAGIAN 1: PANDUAN OPERASIONAL OWNER (PEMILIK BISNIS)

Sebagai **OWNER**, peran Anda adalah memantau jalannya bisnis rental secara transparan dan akurat tanpa terlibat langsung dalam pengisian billing kasir atau perubahan teknis database.

### 1.1 Hak Akses & Batasan Peran OWNER
Sistem menerapkan prinsip *Separation of Concerns* (Pemisahan Tanggung Jawab) berbasis RBAC:
* ✅ **YANG DAPAT DILAKUKAN OWNER:**
  - Melihat dasbor eksekutif: total omzet harian, transaksi aktif, grafik pendapatan per jam, dan komposisi pendapatan.
  - Membuka laporan transaksi detail dan laporan penjualan makanan/minuman (FnB).
  - Mengunduh rekapitulasi pembukuan ke format **Excel (.xlsx)** dan **PDF (.pdf)**.
  - Memantau stasiun PlayStation dan meja biliar secara *read-only*.
  - Melihat daftar perangkat keras IoT terdaftar dan status pemetaannya.
* ❌ **YANG DILARANG / TIDAK BISA DILAKUKAN OWNER:**
  - Memulai, memperpanjang, atau menghentikan billing sewa (khusus Kasir & Admin).
  - Menghapus transaksi atau mengubah stok barang kasir.
  - Mengubah konfigurasi router, IP perangkat, atau menjalankan uji coba diagnostik IoT.
  - **Melakukan Refund:** Sistem Sugeng Rawuh dirancang tanpa mekanisme refund untuk melindungi integritas kas operasional.

---

### 1.2 Cara Memantau Omzet & Keuangan Toko (Owner Workflow)

#### A. Membaca Dasbor Statistik Realtime ([http://127.0.0.1:8008/home](http://127.0.0.1:8008/home))
1. Login dengan email dan password Owner.
2. Dasbor utama langsung menampilkan 4 kartu indikator utama:
   - **Total Pendapatan Hari Ini:** Akumulasi uang masuk dari sewa rental dan penjualan FnB.
   - **Total Transaksi Berjalan:** Jumlah stasiun yang sedang aktif dimainkan saat ini.
   - **Grafik Pendapatan Per Jam:** Menunjukkan jam-jam sibuk (*peak hours*) rental toko.
   - **Diagram Lingkaran (Pie Chart):** Menampilkan proporsi omzet dari PlayStation, Meja Biliar, dan Makanan/Minuman.

#### B. Menganalisis Laporan Penjualan Transaksi ([http://127.0.0.1:8008/report](http://127.0.0.1:8008/report))
1. Klik menu **Laporan** -> **Laporan Penjualan** pada sidebar kiri.
2. Atur **Filter Tanggal**:
   - Pilih tanggal mulai (*Start Date*) dan tanggal selesai (*End Date*), misal: 1 bulan terakhir.
   - Filter berdasarkan **Shift Kasir** (Pagi / Siang / Malam) untuk mengevaluasi kinerja masing-masing petugas.
   - Filter berdasarkan **Metode Pembayaran** (Tunai, QRIS/E-Wallet, Transfer Bank) untuk mencocokkan uang fisik di laci kasir dengan mutasi bank.
3. Klik tombol **Filter Data**.

#### C. Mengunduh Rekapitulasi Laporan Finansial
* **Unduh Excel:** Klik tombol hijau **Export Excel** ([http://127.0.0.1:8008/generate-excel](http://127.0.0.1:8008/generate-excel)). File `.xlsx` rapi akan terunduh dan siap diolah di Microsoft Excel / Google Sheets.
* **Unduh PDF:** Klik tombol merah **Export PDF** ([http://127.0.0.1:8008/generate-pdf](http://127.0.0.1:8008/generate-pdf)). Menghasilkan berkas dokumen resmi untuk arsip fisik pembukuan toko.

---

## BAGIAN 2: PANDUAN TEKNIS ADMINISTRATOR (ADMIN / PAK AMIN)

Sebagai **ADMIN**, Anda memegang kendali penuh atas master data, tarif rental, inventaris barang, pengelolaan akun staf kasir, serta konfigurasi perangkat keras IoT.

---

### 2.1 Manajemen User & Hak Akses Kasir ([http://127.0.0.1:8008/users](http://127.0.0.1:8008/users))

1. Buka menu **Admin Menu** -> **Manajemen User**.
2. **Menambah Akun Kasir Baru:**
   - Klik **+ Tambah User**.
   - Masukkan Nama Kasir, Email, Password awal, pilih Role `kasir`, dan tentukan shift kerja kasir.
   - Klik **Simpan**.
3. **Mereset Password Kasir:**
   - Jika petugas kasir lupa password, Admin dapat mengklik tombol **Reset Password** pada baris kasir tersebut tanpa perlu menghapus akun.

---

### 2.2 Manajemen Shift Kerja Kasir ([http://127.0.0.1:8008/work_shifts](http://127.0.0.1:8008/work_shifts))

1. Buka menu **Manajemen Jam Kerja**.
2. Anda dapat mendefinisikan jam operasional shift:
   - **Shift Pagi:** 08:00 - 16:00
   - **Shift Siang / Sore:** 16:00 - 00:00
   - **Shift Malam:** 00:00 - 08:00
3. Setiap transaksi kasir otomatis dikaitkan ke shift yang aktif, mempermudah audit setoran uang pergantian shift.

---

### 2.3 Manajemen Jenis PlayStation & Tarif Jam Bertingkat ([http://127.0.0.1:8008/playstation](http://127.0.0.1:8008/playstation))

1. Buka menu **Jenis Playstation**.
2. Klik **+ Tambah Jenis** untuk menambah tipe konsol baru (cth: `PlayStation 5`, `PlayStation 4`, `Meja Biliar`).
3. Masukkan tarif dasar per jam (misal: `Rp 15.000 / jam`).
4. **Mengatur Tarif Bertingkat (Hourly Pricing):**
   - Klik tombol **Tarif Jam** pada konsol tersebut ([http://127.0.0.1:8008/playstation/{id}/hourly-prices](http://127.0.0.1:8008/playstation/1/hourly-prices)).
   - Anda dapat membuat harga promosi makin lama makin murah:
     * Main 1 Jam: Rp 15.000
     * Main 2 Jam: Rp 28.000 (diskon Rp 2.000)
     * Main 3 Jam: Rp 40.000 (diskon Rp 5.000)
   - Sistem billing kasir akan otomatis menerapkan tarif bertingkat ini saat kasir menginput durasi sewa.

---

### 2.4 Manajemen Paket Kustom (Custom Package) ([http://127.0.0.1:8008/custom-package](http://127.0.0.1:8008/custom-package))

1. Buka menu **Custom Paket**.
2. Anda dapat menggabungkan sewa rental dengan makanan/minuman menjadi satu harga paket hemat:
   - Contoh Paket: *"Paket Begadang PS5 (3 Jam Main + 2 Indomie + 2 Teh Pucuk) = Rp 65.000"*.
3. Tentukan durasi menit main dan pilih produk FnB yang termasuk di dalamnya.
4. Paket ini langsung muncul sebagai opsi pilihan di form kasir.

---

### 2.5 Manajemen FnB, Kelompok Harga & Inventaris Stok

* **Master Barang FnB ([http://127.0.0.1:8008/fnb](http://127.0.0.1:8008/fnb)):**  
  Menambah produk makanan, minuman, atau rokok, menentukan harga beli (modal), harga jual ke pelanggan, serta ambang batas minimum stok.
* **Kelompok Harga ([http://127.0.0.1:8008/price-group](http://127.0.0.1:8008/price-group)):**  
  Mengatur harga barang yang berbeda untuk area reguler dan area VIP/PlayStation 5 jika diinginkan.
* **Manajemen Stok ([http://127.0.0.1:8008/stock](http://127.0.0.1:8008/stock)):**  
  - Mencatat barang masuk kulakan baru (**+ Tambah Stok**).
  - Melakukan penyesuaian stok jika ada barang rusak atau kadaluarsa (**- Kurangi Stok**).
  - Melihat kartu mutasi riwayat stok barang secara transparan (**Riwayat Stok**).

---

### 2.6 Konfigurasi Hardware IoT (Device Registry & Station Mapping)

#### A. Pendaftaran Samsung Smart TV & Shelly Relay
1. Buka menu **Hardware IoT** -> **Device Registry** ([http://127.0.0.1:8008/admin/hardware/registry](http://127.0.0.1:8008/admin/hardware/registry)).
2. Klik **+ Tambah Perangkat**:
   - Untuk TV: Pilih Kategori `Smart TV`, Brand `SAMSUNG`, Model `50U8500`, masukkan IP statis dan MAC Address.
   - Untuk Meja Biliar: Pilih Kategori `Smart Relay`, Brand `SHELLY`, Model `1PM Mini Gen3`, masukkan IP statis dan MAC Address.
3. Klik **Simpan**.

#### B. Pemetaan ke Meja Rental (Station Mapping)
1. Buka menu **Hardware IoT** -> **Station Mapping** ([http://127.0.0.1:8008/admin/hardware/mappings](http://127.0.0.1:8008/admin/hardware/mappings)).
2. Pilih stasiun meja PlayStation atau Biliar.
3. Hubungkan perangkat fisik:
   - Stasiun PlayStation dipetakan ke **Samsung Smart TV** dengan peran `DISPLAY_TV`.
   - Stasiun Biliar dipetakan ke **Shelly Relay** dengan peran `LIGHT_RELAY`.
4. Klik **Pasangkan ke Station**.

#### C. Memantau Telemetri Otomasi & Audit Log
* **Log Intent Otomasi:** [http://127.0.0.1:8008/admin/hardware/automation/intents](http://127.0.0.1:8008/admin/hardware/automation/intents)  
  Melihat antrean instruksi perangkat yang sedang diproses oleh background worker.
* **Log Eksekusi Hardware:** [http://127.0.0.1:8008/admin/hardware/automation/logs](http://127.0.0.1:8008/admin/hardware/automation/logs)  
  Melihat catatan riwayat setiap perintah yang berhasil dikirim ke perangkat beserta catatan latensi jaringan (ms).

---

### 2.7 Pemeliharaan Sistem & Pengaturan Aplikasi ([http://127.0.0.1:8008/settings](http://127.0.0.1:8008/settings))

1. Buka menu **Pengaturan Aplikasi**.
2. Halaman ini digunakan untuk mengatur nama toko di struk belanja, alamat toko, nomor kontak kasir, dan footer ucapan terima kasih pada kertas cetak printer thermal.
3. **Tombol Reset Transaksi:**  
   *Digunakan hanya pada saat awal instalasi toko baru (Factory Clean State).*  
   ⚠️ Dilarang menekan tombol ini saat toko sudah berjalan, karena akan menghapus seluruh data transaksi sebelumnya.

---

*Panduan Administrator & Owner Sugeng Rawuh — Dokumen acuan tata kelola dan konfigurasi resmi sistem.*
