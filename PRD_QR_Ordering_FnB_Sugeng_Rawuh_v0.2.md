# PRODUCT REQUIREMENTS DOCUMENT (PRD)
## Sugeng Rawuh — QR Ordering & Integrasi F&B ke Billing PS/Biliar

| Atribut | Keterangan |
|---|---|
| Versi | 0.2 — Final Approved Business Requirements |
| Tanggal | 14 November 2026 |
| Status Dokumen | **LOCKED & APPROVED** — Seluruh OPEN-01 s/d OPEN-06 telah disepakati |
| Produk | Sugeng Rawuh Billing & POS |
| Platform existing | Laravel 8, MySQL, Docker/WSL2 pada PC kasir Windows 11 |
| Sumber kode | https://github.com/rillapan/sugengrawuh |

---

## 1. Latar Belakang & Ringkasan Perubahan

Pengembangan QR Ordering memungkinkan pelanggan memesan F&B langsung dari meja permainan PS/biliar, meja makan, maupun area tunggu. Seluruh pesanan bermuara pada satu antrean dapur terpadu. Seluruh pembayaran finansial dipusatkan secara manual melalui kasir utama (tanpa online gateway), menjaga konsistensi keuangan, stok, serta integritas sesi sewa hardware.

### Keputusan Kunci Versi 0.2 (14 November 2026)
1. **Transaksi PAKET:** Pembayaran biaya sewa permainan **WAJIB di awal** sebelum sesi diaktifkan dan sebelum hardware dinyalakan. Pesanan F&B tambahan susulan pada sesi paket **wajib dibayar di awal di kasir sebelum disiapkan dapur**, dan diterbitkan invoice F&B tersendiri. Invoice paket awal bersifat immutable.
2. **Transaksi LOST TIME:** Biaya sewa permainan dibayar di akhir berdasarkan durasi aktual. F&B selama sesi Lost Time dapat disiapkan dapur setelah diverifikasi, dan ditagihkan saat checkout akhir atau dilunasi lebih awal di kasir dengan invoice terpisah. Item yang sudah lunas tidak boleh ditagihkan ulang.
3. **Pelanggan Non-Bermain:** Pesanan F&B makan di tempat atau takeaway wajib dibayar di kasir sebelum pesanan diproses (*pay-first*).
4. **QR Statis & Token Sesi Dinamis:** QR Code fisik pada meja bersifat permanen. Setiap sesi rental baru menghasilkan token sesi unik. Pesanan via QR wajib terikat ke `transaction_id` dan token sesi aktif. Saat sesi berakhir, token dinonaktifkan seketika. Halaman QR lama tidak dapat memesan atau berpindah otomatis ke sesi berikutnya.
5. **RBAC Staf F&B:** Tetap mempertahankan 3 role: `OWNER`, `ADMIN`, `KASIR`. Staf F&B menggunakan akun kasir dengan flag izin terbatas (`is_fnb_staff`), tanpa akses pembayaran, laporan finansial, atau kontrol hardware.
6. **Stok & Kedaluwarsa:** Stok berkurang tepat satu kali saat pesanan dikonfirmasi diproses (`ACCEPTED`). Pesanan pending yang belum dibayar/dikonfirmasi kedaluwarsa setelah 30 menit. Pembatalan hanya merestorasi stok jika stok sudah dikurangi. Tidak ada fitur refund.

---

## 2. Aktor dan Hak Akses

| Aktor | Role Sistem | Wewenang Operasional |
|---|---|---|
| **Pelanggan** | Publik (Tanpa Login) | Scan QR meja, melihat katalog menu & harga real-time, membuat pesanan dengan token sesi valid, memantau status pesanan. |
| **Kasir Utama** | `KASIR` | Membuka/menutup sesi sewa, menerima pembayaran tunai/e-wallet/transfer, mencetak struk/invoice, mengonfirmasi pembayaran F&B. |
| **Staf F&B** | `KASIR` (Izin Terbatas) | Melihat antrean dapur satu pintu, memperbarui progres pesanan (`ACCEPTED`, `PREPARING`, `READY`, `DELIVERED`), input pesanan langsung. Dilarang mengakses pembayaran & laporan. |
| **Admin / Owner** | `ADMIN` / `OWNER` | Mengelola master barang F&B, kelompok harga, mutasi stok, QR Code meja/area, audit log finansial, laporan laba rugi. |

---

## 3. Aturan Bisnis (Business Rules BR-01 – BR-12)

- **BR-01 — PAKET Wajib Bayar di Awal:** Biaya rental paket dibayarkan lunas sebelum sesi diaktifkan dan hardware TV/Shelly ON.
- **BR-02 — LOST TIME Bayar di Akhir:** Biaya rental permainan dihitung berdasarkan durasi aktual melalui pricing engine existing saat kasir mengakhiri sesi.
- **BR-03 — F&B Boleh Dibayar Lebih Awal:** Item F&B pada sesi Lost Time dapat dilunasi kapan saja di kasir dengan invoice F&B terpisah. Item yang sudah lunas otomatis dikeluarkan dari invoice akhir checkout.
- **BR-04 — Invoice Paket Immutable:** Invoice paket yang sudah lunas tidak boleh diubah nilainya. F&B susulan dicatat dalam order baru dan diterbitkan invoice F&B terpisah saat dibayar.
- **BR-05 — Satu Meja, Satu Penanggung Jawab Sesi Aktif:** Pesanan QR dari station permainan otomatis mengambil nama penanggung jawab tagihan dari sesi aktif (`billing_name`), namun mencatat nama pemesan individual (`customer_name`) untuk kebutuhan pengantaran.
- **BR-06 — Semua Pembayaran Melalui Kasir Utama:** QR Ordering tidak menyediakan tombol pembayaran online atau gateway. Seluruh pelunasan dilakukan kasir.
- **BR-07 — Pelanggan Non-Bermain Memiliki Invoice F&B Mandiri:** Pemesanan tanpa sesi permainan diterbitkan invoice F&B mandiri tanpa kaitan `transaction_id`.
- **BR-08 — Tiga Asal Pesanan Berbagi Satu Antrean F&B:** Pesanan dari QR pelanggan, kasir, dan staf F&B masuk ke antrean dapur yang sama (`fnb_orders`).
- **BR-09 — Pemisahan Status Pesanan, Pembayaran, dan Sesi:** Status masak pesanan terpisah dari status pelunasan finansial dan status timer sewa perangkat.
- **BR-10 — Larangan Mutlak Pembayaran Ganda:** Setiap item F&B yang telah tercatat dalam invoice berstatus lunas tidak boleh muncul kembali pada penagihan mana pun.
- **BR-11 — QR Meja Statis dengan Token Sesi Dinamis:** QR fisik permanen mewakili lokasi fisik. Hak penambahan tagihan ke sesi rental diatur oleh token sesi unik yang aktif.
- **BR-12 — Tidak Ada Fitur Refund:** Kesalahan pesanan hanya dapat dibatalkan sebelum diproses, dengan pencatatan audit log pembatalan.

---

## 4. Matriks Skenario Transaksi

| ID | Jenis Pelanggan | Kanal Pesanan | Alur Pembayaran | Alur Dapur & Stok |
|---|---|---|---|---|
| **A1** | Paket + F&B Awal | Kasir | Bayar paket + F&B di awal; terbit 1 invoice gabungan lunas. Permainan aktif setelah lunas. | Dapur menerima order; stok berkurang 1x saat dikonfirmasi. |
| **A2** | Paket Aktif + F&B Susulan | QR Meja | Bayar F&B di kasir sebelum diproses. Terbit invoice F&B terpisah. Invoice paket tetap. | Dapur memproses setelah kasir konfirmasi bayar. Stok berkurang 1x. |
| **A3** | Lost Time + F&B QR | QR Meja | Belum bayar saat pesan. Ditagihkan saat checkout akhir (Rental + F&B belum lunas). | Dapur langsung memproses setelah verifikasi sesi. Stok berkurang 1x saat diterima. |
| **A4** | Lost Time + F&B Bertahap | QR Meja | F&B #1 dibayar lebih awal (terbit invoice F&B). F&B #2 dibayar saat checkout akhir. | Dapur memproses masing-masing order. Tidak ada item ditagih ulang. |
| **A5** | Teman Pemain (Riko di meja Budi) | QR Meja | Tagihan diikatkan ke sesi Budi via token sesi aktif. Pemesan = Riko, Penanggung jawab = Budi. | Dapur melihat nama pemesan dan nomor meja pengantaran. |
| **B1** | Non-Bermain (Dine-in / Takeaway) | Kasir / Staf | Wajib bayar di kasir sebelum diproses (*pay-first*). Terbit invoice F&B mandiri. | Dapur memproses setelah status bayar lunas. |

---

## 5. Decision Log (OPEN-01 – OPEN-06 Resolved)

| ID | Topik | Status | Keputusan Disepakati |
|---|---|---|---|
| **OPEN-01** | F&B Susulan pada Paket | **RESOLVED / APPROVED** | Wajib dibayar di kasir sebelum diproses dapur. Terbit invoice F&B terpisah; invoice paket immutable. |
| **OPEN-02** | Pelanggan Non-Bermain | **RESOLVED / APPROVED** | Pesanan langsung via kasir/staf dengan sistem *pay-first* sebelum dapur memproses. |
| **OPEN-03** | Validasi QR & Token Sesi | **RESOLVED / APPROVED** | QR fisik statis per meja. Sesi aktif menghasilkan token dinamis. Validasi token wajib atomik saat submit. Sesi selesai = token nonaktif seketika. |
| **OPEN-04** | Sesi Berakhir F&B Masih Diproses | **RESOLVED / APPROVED** | Sesi sewa boleh ditutup setelah sisa tagihan lunas. Pesanan dapur tetap diselesaikan dan diantar ke area tunggu/pickup. |
| **OPEN-05** | RBAC Staf F&B | **RESOLVED / APPROVED** | Tetap 3 role (`OWNER`, `ADMIN`, `KASIR`). Akun staf dapur diberi flag `is_fnb_staff` dengan izin terbatas pada antrean F&B saja. |
| **OPEN-06** | Timing Mutasi Stok & Expiry | **RESOLVED / APPROVED** | Stok dikurangi tepat 1x saat pesanan berstatus `ACCEPTED`. Pesanan unpaid pending kedaluwarsa 30 menit. Pembatalan merestorasi stok hanya jika sudah dikurangi. |

---

## 6. Kriteria Penerimaan (Acceptance Criteria AC-01 – AC-14)

- **AC-01:** Paket 2 jam + F&B awal yang belum dibayar kasir tidak mengaktifkan timer dan hardware TV/Shelly tetap OFF.
- **AC-02:** Paket 2 jam + F&B awal yang sudah dibayar kasir mengaktifkan timer, menyalakan TV, mencatat 1 invoice lunas, dan mengurangi stok F&B 1x.
- **AC-03:** Pesanan F&B QR pada paket yang sudah berjalan tidak mengubah total invoice paket lama dan menghasilkan invoice F&B terpisah saat dibayar.
- **AC-04:** Pada sesi Lost Time dengan 2 pesanan F&B (1 lunas lebih awal, 1 belum), checkout akhir hanya menagih durasi rental + pesanan yang belum lunas.
- **AC-05:** Pesanan QR teman (Riko) pada meja penyewa (Budi) dengan token aktif mencatat `customer_name: Riko`, `billing_name: Budi`, dan `transaction_id` yang sama.
- **AC-06:** Scan QR menggunakan URL/token sesi yang sudah selesai ditolak dan tidak membebankan pesanan ke sesi pelanggan berikutnya di meja tersebut.
- **AC-07:** Submit pesanan QR yang terjadi bersamaan dengan checkout kasir ditangani secara deterministik menggunakan database row lock; pesanan tidak hilang dan tidak masuk ke invoice yang sudah lunas.
- **AC-08:** Dua perangkat HP yang mengirim pesanan bersamaan pada station yang sama tercatat sebagai dua order terpisah tanpa korupsi stok.
- **AC-09:** Jika stok barang habis saat submit, transaksi ditolak server-side secara atomik dengan pesan informatif.
- **AC-10:** Pesanan pelanggan non-bermain menghasilkan invoice F&B mandiri tanpa kaitan `transaction_id`.
- **AC-11:** Seluruh pesanan dari QR, kasir, dan staf muncul dalam satu antrean dapur dan tercatat dalam laporan penjualan F&B tanpa duplikasi.
- **AC-12:** Jika internet putus, endpoint QR publik memberikan respons kegagalan informatif, sementara operasional kasir LAN lokal tetap berjalan 100%.
- **AC-13:** Pengguna publik pada endpoint QR tidak dapat mengakses endpoint internal hardware, kontrol TV, atau dashboard admin/kasir.
- **AC-14:** Pembatalan pesanan oleh petugas sebelum diproses tidak menimbulkan invoice lunas dan merestorasi stok hanya jika stok telah dikurangi.

