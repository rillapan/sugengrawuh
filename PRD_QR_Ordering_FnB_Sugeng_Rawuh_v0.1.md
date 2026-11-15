# PRODUCT REQUIREMENTS DOCUMENT (PRD)
## Sugeng Rawuh — QR Ordering & Integrasi F&B ke Billing PS/Biliar

| Atribut | Keterangan |
|---|---|
| Versi | 0.1 — Draft untuk review |
| Tanggal | 14 November 2026 |
| Produk | Sugeng Rawuh Billing & POS |
| Platform existing | Laravel, MySQL, Docker/WSL2 pada PC kasir Windows 11 |
| Sumber kode | https://github.com/rillapan/sugengrawuh |
| Status | Aturan utama terdefinisi; keputusan yang belum disepakati ditandai **OPEN** |

> **Prioritas perubahan terbaru:** untuk transaksi **PAKET**, pembayaran permainan **WAJIB di awal**, sebelum permainan dimulai. Ketentuan terdahulu yang mengizinkan paket dibayar di akhir **tidak berlaku lagi**. Untuk **LOST TIME**, pembayaran permainan tetap dilakukan di akhir berdasarkan durasi aktual.

## 1. Latar belakang

Sugeng Rawuh sudah memiliki modul manajemen barang F&B, kelompok harga, stok, laporan penjualan, dan billing permainan PS/biliar. Pengembangan QR Ordering memungkinkan pelanggan memesan makanan/minuman dari meja mana pun menggunakan HP; pesanan yang dibuat di kasir atau oleh petugas F&B juga masuk dalam antrean yang sama. Invoice, status pelunasan, stok, dan laporan tidak boleh menjadi ganda atau tidak konsisten.

**Masalah utama yang diatasi:**
- Pelanggan harus bisa memesan dari meja PS, meja biliar, area makan, atau area tunggu tanpa turun ke kasir hanya untuk menyampaikan daftar menu.
- Setiap pesanan perlu mengetahui lokasi pengantaran dan terkait ke sesi permainan yang tepat jika ada.
- Pembayaran permainan dan F&B dapat berbeda waktunya, tetapi semua pembayaran diselesaikan **secara manual melalui kasir utama**, bukan dari HP pelanggan.
- Paket harus lunas di awal agar pelanggan tidak langsung meninggalkan lokasi ketika sesi paket habis dan TV berhenti.
- Pesanan F&B yang sudah dibayar terpisah tidak boleh muncul lagi sebagai piutang dalam checkout permainan.

## 2. Sasaran dan batasan

### Sasaran
1. Pelanggan dapat membuat pesanan F&B dari QR Code unik di semua area.
2. Staf F&B melihat pesanan dari QR, kasir, dan petugas kafe pada satu antrean.
3. Kasir dapat mencatat pembayaran F&B secara terpisah atau bersama invoice **LOST TIME** ketika pembayaran F&B belum dilakukan.
4. Harga dan stok mengikuti sumber data produk F&B existing.
5. Setiap item yang dibayar dihitung **sekali saja** dalam invoice, stok, dan laporan.
6. Alur pembayaran PAKET diawali dengan pembayaran sebelum aktivasi permainan.

### Di luar cakupan
- Pembayaran online mandiri melalui Midtrans, gateway, atau tombol bayar di HP pelanggan.
- Fitur refund.
- Pembuatan sistem katalog produk F&B kedua yang terpisah dari modul existing.
- Pengubahan sistem TV, konsol PS, relay, billing daemon, atau logika timer di luar adaptasi minimum yang disetujui untuk alur transaksi.
- Penyebaran perubahan ke komputer kasir secara otomatis tanpa review dan persetujuan.

## 3. Aktor dan kategori pelanggan

| Aktor | Kebutuhan |
|---|---|
| Pelanggan aktif bermain | Memiliki sesi PS/biliar aktif; bisa memesan F&B dan mengaitkannya ke sesi setelah verifikasi |
| Pelanggan non-bermain | Tidak memiliki sesi permainan; memesan F&B makan di tempat/takeaway dengan tagihan F&B mandiri |
| Kasir | Memulai dan menagih permainan, memasukkan order F&B, menerima pembayaran, mencetak invoice, menyelesaikan tagihan |
| Staf F&B | Menerima order, melihat lokasi/penerima, menyiapkan, mengantar, serta bisa mencatat pesanan langsung dengan hak akses sesuai ketentuan |
| Admin/Owner | Mengelola master barang, harga, stok, QR meja, antrean, dan laporan sesuai hak akses existing |

**Klasifikasi pelanggan:**
- **Aktif bermain:** ada sesi rental yang benar-benar sedang berjalan pada meja/station. Jenis transaksi: **PAKET** atau **LOST TIME**.
- **Non-bermain:** tidak memiliki sesi permainan yang bisa ditagih; membuat order F&B mandiri, baik dari QR, kasir, maupun petugas kafe.

## 4. Aturan bisnis yang sudah diputuskan (LOCKED)

**BR-01 — PAKET wajib dibayar di awal.** Harga paket berdasarkan durasi tetap (misal 1, 2, 3 jam) dibayarkan sebelum sesi diaktifkan. Jika F&B juga dipilih pada transaksi awal, F&B tersebut dibayar dalam transaksi awal dan dapat dicetak bersama paket pada satu invoice.

**BR-02 — LOST TIME dibayar di akhir.** Harga permainan dihitung dari durasi aktual sesuai mesin billing existing. F&B yang masih belum lunas dan sah terkait sesi dapat dimasukkan ke invoice akhir.

**BR-03 — F&B boleh dibayar lebih awal.** Meskipun LOST TIME belum dibayar, item F&B boleh dilunasi di kasir lebih dahulu melalui invoice F&B tersendiri. Item lunas ini dikeluarkan dari invoice akhir permainan.

**BR-04 — Paket yang sudah lunas tidak boleh diubah.** F&B yang dipesan sesudah transaksi paket lunas masuk sebagai order tambahan yang ditautkan ke sesi untuk tracking, dengan invoice F&B tersendiri saat dibayar; invoice paket awal tetap immutable.

**BR-05 — Satu meja, satu penanggung jawab tagihan untuk sesi aktif.** Misalnya TV 01 digunakan Budi (sesi #1111); Riko memesan dari QR TV 01. Data pemesan = Riko, penanggung jawab billing = Budi, **hanya setelah verifikasi bahwa order memang diotorisasi untuk sesi itu**. Nama pelanggan utama diambil dari sesi, bukan diisi sendiri oleh pengunjung.

**BR-06 — Semua pembayaran melalui kasir utama.** QR Ordering hanya mencatat pesanan; **tidak menyediakan pembayaran online**. Kasir melakukan konfirmasi pembayaran dan mencatat metode yang dipakai di kasir.

**BR-07 — Pelanggan tanpa sesi permainan mempunyai invoice F&B mandiri.** Dapat berupa makan di tempat atau takeaway.

**BR-08 — Tiga asal pesanan berbagi satu antrean F&B:** input kasir, scan QR pelanggan, dan input petugas kafe.

**BR-09 — Status pesanan, pembayaran, dan sesi permainan terpisah.** Pesanan sudah diantar tidak otomatis berarti lunas; sesi selesai tidak otomatis berarti seluruh F&B lunas.

**BR-10 — Tidak ada pembayaran ganda.** Item F&B yang sudah dilunasi dalam invoice terpisah tidak boleh muncul kembali sebagai nilai tagihan akhir LOST TIME atau transaksi lainnya.

**BR-11 — QR ditetapkan untuk lokasi, bukan untuk mengidentifikasi pemilik tagihan secara otomatis.** Setiap penambahan tagihan ke sesi aktif harus melalui otorisasi/verifikasi; QR permanen yang dapat difoto publik saja tidak cukup.

**BR-12 — Tidak ada fitur refund.** Kesalahan order/pembatalan hanya ditangani sebelum pembayaran atau melalui prosedur manual terpisah di luar cakupan fitur.

## 5. Matriks alur transaksi

| ID | Skenario | Aturan pembayaran dan invoice | Hasil untuk staf F&B |
|---|---|---|---|
| A1 | Budi mulai **PAKET 2 jam + Pop Mie + Es Teh** di kasir | Bayar semua di **awal**, 1 invoice gabungan awal yang lunas; permainan baru aktif setelah pembayaran terkonfirmasi | Pesanan F&B dari kasir masuk antrean staf |
| A2 | Budi mulai **PAKET 2 jam** dan sudah membayar; saat main membeli F&B lewat QR | Paket tetap lunas. F&B baru menjadi order/invoice F&B terpisah, tidak menambah invoice paket sebelumnya | Masuk antrean dengan lokasi TV 01 |
| A3 | Budi bermain **LOST TIME**, pesan F&B lewat QR, belum bayar F&B | Saat selesai: hitung durasi + F&B belum lunas dalam satu invoice akhir | Masuk antrean dengan lokasi dan sesi terkait |
| A4 | Budi bermain LOST TIME; F&B pertama dibayar lebih awal, F&B kedua belum | Invoice F&B pertama lunas terpisah. Invoice akhir hanya durasi permainan + F&B kedua | Kedua order tetap terlihat pada antrean/riwayat |
| A5 | Riko (teman Budi) memesan F&B dari meja TV 01 | Order ditautkan ke sesi Budi **setelah validasi otorisasi**; nama pemesan Riko, nama pemilik tagihan Budi | Staf melihat meja pengantaran dan nama pemesan |
| B1 | Staf kafe memasukkan order untuk meja yang sedang bermain | Pilih station; sistem mengonfirmasi sesi aktif dan mengikuti aturan A1–A5 | Order masuk ke antrean F&B yang sama |
| B2 | Pelanggan tidak bermain dan memesan di kafe atau QR | Tagihan F&B mandiri; bayar di kasir | Order masuk antrean dengan lokasi atau takeaway |

> **Perubahan dari rancangan sebelumnya:** skenario paket **bayar akhir** dihapus karena tidak sesuai keputusan terbaru bahwa paket wajib dibayar di awal.

## 6. Alur fungsional

### 6.1 QR untuk semua area
1. Admin mengatur lokasi (PS, biliar, meja makan, area tunggu) dan membuat QR Code ber-token lokasi unik.
2. Pelanggan scan QR dan membuka halaman menu mobile yang sederhana tanpa akun.
3. Laravel membaca lokasi dari QR dan mengecek sesi yang sah bila ada.
4. Pelanggan memilih barang, kuantitas, serta catatan dan dapat mengisi **nama pemesan** untuk keperluan pengantaran.
5. Pada saat submit, server memvalidasi barang, harga, stok, batas jumlah, idempotency, dan token QR.
6. Pelanggan menerima **nomor pesanan** dan informasi status; **bukan link pembayaran**.
7. Order muncul di antrean kasir/staf F&B dengan sumber `QR`, lokasi, dan status pembayaran.

### 6.2 Kasir memulai sesi PAKET
1. Kasir memilih station dan durasi paket.
2. Kasir dapat menambahkan F&B awal.
3. Sistem menghitung harga paket + F&B awal memakai harga valid existing.
4. **Kasir menerima dan mengonfirmasi pembayaran awal.**
5. Sistem menyimpan transaksi/invoice lunas secara atomik, lalu mengaktifkan paket dan mengantrekan F&B awal untuk staf.
6. Jika pembayaran tidak berhasil/terkonfirmasi, sesi paket **tidak** diaktifkan.
7. F&B yang dipesan **setelah** sesi paket mulai tetap ditautkan ke station/sesi untuk operasional, tetapi tidak mengubah invoice paket lunas.

### 6.3 Kasir memulai dan mengakhiri LOST TIME
1. Kasir membuka sesi LOST TIME; timer berjalan seperti sekarang.
2. Pesanan F&B dari QR, kasir, atau petugas kafe ditautkan ke ID sesi setelah validasi.
3. Kasir boleh melunasi item F&B kapan saja lebih awal; terbit invoice F&B terpisah dan item ditandai **paid**.
4. Saat pelanggan menyudahi permainan, kasir mengunci perhitungan durasi dan menghitung hanya F&B yang belum dibayar.
5. Kasir mengonfirmasi pembayaran akhir sebelum invoice ditandai lunas.
6. Pastikan selisih tarif LOST TIME mengikuti backend billing existing; tidak dihitung ulang di frontend QR.

### 6.4 F&B tanpa permainan
1. Pesanan dibuat melalui QR area umum atau input kasir/staf F&B.
2. Sistem membuat order mandiri (tidak memaksakan ID sesi permainan).
3. Kasir menerima pembayaran; staff F&B melihat dan menyelesaikan order sesuai kebijakan proses yang ditetapkan.
4. Invoice berisi F&B saja.

### 6.5 Status operasional
- **Order F&B:** `PENDING` → `ACCEPTED` → `PREPARING` → `READY` → `DELIVERED`; `CANCELLED` sebelum pembayaran sesuai wewenang.
- **Pembayaran:** `UNPAID` → `PAID`; operasi konfirmasi pembayaran harus idempotent.
- **Sesi permainan:** mengikuti status existing; jangan menduplikasi status dan mesin perhitungan timer.
- Status internal di atas bersifat **konseptual**, bukan instruksi menambah enum/tabel sebelum audit kode.

## 7. Integrasi dengan modul Laravel existing

| Halaman existing | Fungsi | Kebutuhan QR Ordering |
|---|---|---|
| `/fnb` | Kelola barang: nama, kelompok harga, harga jual, stok, deskripsi | Sumber katalog menu publik; tambah pengaturan tampil/tidak tampil jika diperlukan setelah audit |
| `/price-group` | Kelompok harga | Ambil harga berdasarkan kelompok existing, bukan tabel harga duplikat |
| `/stock` | Manajemen stok | Gunakan mekanisme mutasi stok existing; lindungi stok dari double decrement |
| `/fnb/laporan-penjualan` | Laporan F&B | Order F&B dari semua kanal dihitung satu kali, termasuk yang tergabung dalam invoice permainan |
| Modul billing existing | Sesi paket/lost time, kalkulasi tarif, invoice | Tautkan order ke sesi dan filter item lunas/belum lunas tanpa merusak alur lama |

**Catatan:** nama class, model, tabel, kolom, route, controller, service dan struktur database **belum diaudit langsung** dalam PRD ini. Nama entitas berikut adalah rancangan konseptual dan wajib dipetakan ke entitas existing sebelum implementasi.

### 7.1 Konsep data minimum
- **Location/Station:** identitas meja/area dan token QR publik non-sekuensial.
- **Billing Session:** ID sesi tetap, jenis paket/lost time, penyewa utama, masa aktif, status.
- **F&B Order:** nomor order, asal (`QR`, `CASHIER`, `FNB_STAFF`), lokasi, ID sesi **nullable**, nama pemesan, penerima tagihan dari sesi, status proses.
- **F&B Order Item:** produk existing, kuantitas, *snapshot* harga saat order, catatan, nilai subtotal, status alokasi tagihan.
- **Invoice/Invoice Line & Payment:** hubungan item yang ditagih, status pelunasan, pencatatan kasir dan waktu pembayaran; item tidak boleh masuk ke dua invoice yang menimbulkan dua penagihan.
- **Stock Movement:** catatan mutasi stok mengikuti model existing.
- **Audit log:** siapa mengonfirmasi pembayaran/pembatalan, kapan, dan item mana yang terdampak.

> Implementasi boleh mengadaptasi model/tabel saat ini; tidak boleh membuat skema paralel yang meniru POS existing tanpa alasan teknis kuat dan persetujuan.

## 8. Keamanan dan integritas finansial

1. **Tidak boleh menagih hanya berdasarkan QR statis.** Untuk mengaitkan order ke sesi aktif, backend melakukan validasi sesi dan otorisasi (contoh: kode sesi yang disampaikan penyewa/konfirmasi kasir); metode final perlu diputuskan.
2. **Isolasi antar-sesi:** apabila meja berganti dari Budi ke pelanggan lain, order lama tidak berpindah penanggung jawab.
3. **Race condition checkout:** penguncian transaksi/row yang tepat saat kasir menutup sesi dan pelanggan membuat order bersamaan. Order yang datang sesudah checkout terkunci harus dipisahkan menjadi invoice F&B baru atau ditahan sesuai kebijakan yang terverifikasi; tidak boleh diam-diam masuk invoice lunas.
4. **Item invoice immutable setelah lunas.** Pembayaran dan pencetakan ulang tidak menciptakan charge baru.
5. **Idempotency:** retry jaringan, klik ganda pelanggan atau kasir, dan proses webhook yang tidak ada (karena tanpa gateway) tidak menyebabkan order/pembayaran ganda.
6. **Harga harus dihitung server** dari barang dan kelompok harga existing pada saat order diterima; simpan snapshot nilai harga.
7. **Stok divalidasi saat submit**; pengurangan/pengembalian stok mengikuti satu mekanisme existing; pastikan transaksi gagal tidak meninggalkan stok berkurang.
8. **Endpoint publik dibatasi** pada halaman QR dan API minimal yang dibutuhkan. Dashboard admin/kasir, login internal, jaringan Docker/DB, serta kontrol TV/Shelly tidak boleh terekspos publik.
9. **Akses Internet:** bila memakai Cloudflare Tunnel untuk HP dengan kuota pribadi, PC kasir harus online. Operasional kasir lokal tetap harus berfungsi tanpa internet.
10. **Privasi:** jangan menampilkan nama lengkap penyewa utama hanya karena pengunjung memindai QR meja.
11. **Kontrol penyalahgunaan:** rate limit, CAPTCHA/konfirmasi jika dibutuhkan, proteksi CSRF sesuai desain akses, pembatasan order anonim, dan audit order mencurigakan.

## 9. Skenario pengujian wajib (Acceptance Criteria)

| ID | Kondisi uji | Hasil yang diharapkan |
|---|---|---|
| AC-01 | Paket 2 jam + F&B awal, kasir belum mengonfirmasi pembayaran | Sesi paket belum aktif; F&B tidak dianggap lunas |
| AC-02 | Paket 2 jam + F&B awal sudah dibayar | Sesi aktif; satu invoice awal lunas; F&B masuk antrean; stok dan laporan sesuai |
| AC-03 | Paket sudah lunas lalu pelanggan pesan lewat QR | Invoice paket lama tetap sama; ada order F&B tambahan dan invoice terpisah saat dibayar |
| AC-04 | LOST TIME + dua item F&B, satu sudah dibayar di awal | Checkout akhir hanya menagih durasi + item F&B yang belum lunas |
| AC-05 | Riko memesan dari meja Budi dan berhasil diverifikasi | Nama pemesan Riko, penerima tagihan Budi, ID sesi sama, tidak membuat invoice sesi baru |
| AC-06 | QR lama dibuka setelah sesi Budi selesai dan meja digunakan orang lain | Pesanan tidak dibebankan otomatis ke sesi pelanggan baru |
| AC-07 | Kasir menutup sesi tepat ketika order QR dikirim | Tidak ada item hilang atau tertagih dua kali; hasil akhir deterministik |
| AC-08 | Dua HP mengirim order hampir bersamaan | Dua order valid tercatat satu kali masing-masing, stok benar |
| AC-09 | Stok habis atau harga berubah antara membuka menu dan submit | Server menolak item tidak tersedia/menegaskan harga valid sebelum order disetujui |
| AC-10 | Non-bermain makan di tempat atau takeaway | F&B invoice mandiri, tanpa ID sesi permainan |
| AC-11 | Pesanan dari kasir, QR, dan staf | Muncul di satu antrean F&B; laporan sales item tidak berganda |
| AC-12 | Internet putus | QR via internet gagal secara informatif; input kasir LAN tetap berjalan |
| AC-13 | Petugas mencoba membuka kontrol TV dari URL publik QR | Tidak dapat diakses oleh pengunjung anonim |
| AC-14 | Petugas batal order yang belum dibayar sesuai wewenang | Tidak timbul invoice lunas atau mutasi stok ganda; tercatat audit log |

## 10. Kasus pengecualian yang wajib ditangani

- Meja kosong tetapi QR dipindai: F&B mandiri, tidak dikaitkan ke sesi lama.
- Sesi sudah berakhir tetapi F&B sedang dimasak: tampilkan statusnya di kasir; jangan hilangkan kewajiban pembayaran.
- Banyak pemesan pada satu station: semua order terkait ke satu sesi yang sama apabila terotorisasi; nama pemesan berbeda diperbolehkan.
- Pelanggan salah scan meja: konfirmasi identitas lokasi yang jelas dan mekanisme koreksi order sebelum diproses.
- Produk tidak tersedia, harga berubah, jaringan terputus, atau permintaan berulang: hasil tidak boleh menggandakan stok/transaksi.
- Kasir dan staf mengubah order bersamaan: gunakan aturan transisi status dan audit yang jelas.
- Order sudah lunas tetapi invoice ingin dicetak ulang: cetak invoice existing, bukan membuat transaksi baru.
- Tamu takeaway tidak mengambil makanan: tetap ada nomor order dan status penyelesaian yang dapat ditelusuri.

## 11. UI minimum

### Pelanggan (mobile web)
- Halaman identitas lokasi/meja.
- Katalog menu dari master `/fnb` dan harga `/price-group`; tampilkan item tidak tersedia sesuai aturan stok.
- Keranjang, kuantitas, catatan, nama pemesan (untuk pengantaran), konfirmasi.
- Nomor order dan status; instruksi **pembayaran di kasir**.
- **Tidak ada:** tombol bayar online, Midtrans, QRIS gateway publik, saldo pelanggan.

### Kasir
- Order QR masuk dan antrian pembayaran F&B.
- Pencarian order berdasarkan nomor order/meja/sesi.
- Tampilan rincian **sudah lunas vs belum lunas** pada sesi permainan.
- Konfirmasi pembayaran F&B lebih awal (invoice F&B terpisah).
- Checkout akhir LOST TIME dengan item F&B belum lunas saja.
- Peringatan order F&B tertunda sebelum menutup sesi dan pengalihan meja.

### Staf F&B
- Antrean satu pintu berdasarkan status, waktu, lokasi, dan sumber.
- Detail produk, jumlah, catatan, nama pemesan, meja.
- Aksi menerima/menyiapkan/siap/diantar sesuai izin yang dibatasi.
- Input pesanan langsung ke meja aktif atau pesanan mandiri tanpa sesi.

### Admin/Owner
- Kelola QR per lokasi dan status pengaktifan QR.
- Gunakan manajemen barang, harga, stok, dan laporan existing.
- Monitoring audit invoice dan order bermasalah.

## 12. Keputusan yang **masih OPEN** (jangan dibuat final diam-diam)

| ID | Pertanyaan | Dampak |
|---|---|---|
| OPEN-01 | **Paket sudah lunas lalu memesan F&B tambahan:** harus membayar F&B segera sebelum disiapkan, atau boleh menagih F&B baru di akhir walau TV sudah mati? | Risiko pelanggan meninggalkan lokasi tanpa membayar F&B |
| OPEN-02 | Pelanggan tanpa sesi permainan: order boleh diproses sebelum bayar, atau wajib bayar sebelum dapur mulai? | Risiko order tidak dibayar |
| OPEN-03 | Metode verifikasi QR untuk memasukkan biaya ke billing aktif: kode sesi dari kasir, persetujuan kasir, atau cara lain? | Keamanan agar QR statis tidak dipakai siapa saja untuk membebani tagihan |
| OPEN-04 | Jika ada F&B belum selesai saat sesi PS/biliar berakhir, apakah kasir boleh tutup sesi, atau wajib selesaikan/pindahkan order? | Pencegahan piutang atau pesanan tertinggal |
| OPEN-05 | Staf F&B memakai akun KASIR dengan izin terbatas atau perlu model izin baru? | RBAC tanpa memperluas hak akses tidak perlu |
| OPEN-06 | Sebelum payment dikonfirmasi, kapan stok di-reserve/dikurangi dan bagaimana expiry order? | Pengendalian overselling dan pembatalan |

## 13. Strategi implementasi aman

**Gate 0 — Audit read-only.** Tinjau repositori Laravel, model transaksi, billing, invoice, pembayaran, stok, F&B, RBAC, route, dan kode UI. Petakan BR-01–BR-12 ke perilaku existing; identifikasi gap dan risiko regresi. Jangan mulai mengubah kode sebelum review.

**Gate 1 — Desain teknis.** Definisikan alur invoice yang tidak mengulang tagihan, kontrak data, relasi ke ID sesi, state transition, verifikasi QR, dan strategi sinkronisasi stok. Finalisasi OPEN-01 sampai OPEN-06.

**Gate 2 — Implementasi terisolasi.** Gunakan cabang/worktree yang secara eksplisit disetujui; pertahankan alur Billing & POS existing. Tidak mengubah layanan perangkat keras yang sudah berjalan. Tidak melakukan commit/push otomatis.

**Gate 3 — Pengujian.** Unit/integration tests untuk BR/AC di atas, simulasi concurrency checkout, uji stok, laporan, pemulihan setelah restart, dan E2E pada database uji tanpa data riil.

**Gate 4 — UAT & deployment dengan persetujuan.** Cadangkan database dan konfigurasi, tinjau migration non-destruktif, uji di PC kasir sesuai jadwal, pastikan rollback siap, dan baru lanjut setelah disetujui. Jangan melakukan `migrate:fresh`, `db:wipe`, `docker compose down -v`, atau menghapus volume transaksi.

---

## 14. Ringkasan keputusan

1. Ada **dua kategori pelanggan**: aktif bermain dan tidak aktif bermain.
2. Yang aktif bermain terdiri dari **PAKET** (permainan **WAJIB bayar awal**) dan **LOST TIME** (permainan **WAJIB bayar akhir**).
3. F&B boleh dipesan melalui **QR, kasir, atau staf F&B** pada semua area.
4. Seluruh **pembayaran dilakukan di kasir utama**, tanpa pembayaran online/gateway pelanggan.
5. F&B boleh lunas lebih awal dengan invoice terpisah dan **tidak boleh ditagih ulang** pada invoice akhir.
6. Pelanggan berbeda dalam satu meja dapat ditautkan ke tagihan penyewa utama **hanya sesudah otorisasi sesi terverifikasi**.
7. Integrasi harus menggunakan **data barang, kelompok harga, stok, dan laporan existing**.
8. Hal yang masih OPEN wajib diputuskan sebelum implementasi produksi.
