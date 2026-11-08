# PRODUCT REQUIREMENTS DOCUMENT (PRD)
# SUGENG RAWUH — INTEGRATED BILLING & POS MANAGEMENT SYSTEM
## G1 — Existing System Stabilization, Database Integrity, RBAC & Security Hardening

**Document ID:** SR-PRD-G1-001  
**Version:** 1.0  
**Status:** Ready for Controlled Implementation  
**Tanggal:** 4 November 2026  
**Project:** Sugeng Rawuh — Integrated Billing & POS Management System  
**Lokasi Operasional:** Sugeng Rawuh 2, Perumahan Wira Putra Pratama  
**Development:** Rillapan Code  
**Client:** Sugeng Rawuh  
**Owner:** Pak Amin  
**Existing Repository:** `sistem_kasir_PS-main`  
**Existing Technology:** Laravel 8 + MySQL + Laravel Blade + Bootstrap  
**Target Deployment:** PC Kasir Lokal (Local-First)  
**Development Gate:** G1 — Existing System Stabilization

---

# 1. EXECUTIVE SUMMARY

## 1.1 Ringkasan Produk

Sugeng Rawuh — Integrated Billing & POS Management System merupakan aplikasi manajemen operasional yang mengintegrasikan penyewaan PlayStation, penyewaan meja biliar, penjualan makanan dan minuman, pengelolaan perangkat, transaksi, serta pelaporan keuangan.

Sistem dikembangkan berdasarkan aplikasi kasir Laravel existing yang telah memiliki sebagian besar fitur dasar operasional.

Pengembangan dilakukan secara bertahap untuk menghindari pembangunan ulang aplikasi, mempertahankan alur bisnis yang telah berjalan, serta meminimalkan risiko kehilangan data atau gangguan layanan.

Gate G1 berfokus pada stabilisasi sistem existing sebagai fondasi sebelum integrasi Billing Engine berbasis server, Samsung Smart TV, dan Shelly Smart Relay.

## 1.2 Permasalahan Utama

Berdasarkan laporan audit G0, ditemukan beberapa kelemahan:

1. Relasi Eloquent antara `TransactionFnb` dan `Transaction` berpotensi salah karena ketidaksesuaian primary key.
2. Nominal transaksi pada beberapa kolom database disimpan sebagai string.
3. Struktur waktu transaksi menggunakan tipe `TIME`, yang membutuhkan penanganan khusus untuk transaksi lintas tengah malam.
4. Hak akses OWNER, ADMIN, dan KASIR belum sepenuhnya memenuhi kebutuhan operasional.
5. Endpoint penghapusan seluruh transaksi memiliki risiko destruktif.
6. Belum terdapat audit trail yang memadai untuk tindakan sensitif.
7. Keamanan beberapa operasi masih membutuhkan pemeriksaan menyeluruh.
8. Pengujian regresi belum terbukti mencakup seluruh alur transaksi penting.

Temuan di atas adalah dasar pekerjaan G1 dan harus diverifikasi terhadap source code aktual sebelum perubahan.

## 1.3 Tujuan G1

Meningkatkan stabilitas, keamanan, konsistensi data, dan keandalan aplikasi existing tanpa mengubah alur utama operasional kasir.

Keberhasilan G1 berarti sistem siap menjadi fondasi pengembangan G2 dan gate berikutnya.

---

# 2. PRODUCT VISION & OBJECTIVES

## 2.1 Visi Produk

Membangun sistem billing dan POS terintegrasi yang:

- Stabil untuk operasional harian.
- Aman terhadap kesalahan akses pengguna.
- Menjaga integritas seluruh transaksi keuangan.
- Memiliki pengelolaan role yang jelas.
- Beroperasi secara lokal tanpa ketergantungan internet.
- Mendukung pengembangan integrasi perangkat secara bertahap.
- Mudah digunakan kasir dan dipantau pemilik usaha.
- Dapat dipelihara tanpa kebutuhan infrastruktur berlebihan.

## 2.2 Objectives Gate G1

**OBJ-01 — Database Integrity**

Memastikan relasi database benar, data transaksi tidak rusak, dan seluruh proses FnB tetap konsisten.

**OBJ-02 — Transaction Stability**

Menjamin perbaikan tidak mengubah perilaku transaksi prepaid, postpaid, custom package, pembayaran, dan laporan secara tidak disengaja.

**OBJ-03 — RBAC Enforcement**

Memastikan setiap role hanya dapat mengakses menu dan menjalankan tindakan sesuai kewenangan.

**OBJ-04 — Security Hardening**

Mengurangi risiko penghapusan data, akses tanpa izin, dan manipulasi transaksi.

**OBJ-05 — Regression Prevention**

Membangun pengujian yang dapat mendeteksi perubahan perilaku sistem existing.

**OBJ-06 — Migration Readiness**

Menyiapkan strategi migrasi skema database yang aman untuk fase pengembangan selanjutnya.

---

# 3. PROJECT SCOPE

## 3.1 In Scope

Pekerjaan yang termasuk G1:

| ID | Modul | Cakupan |
|---|---|---|
| G1-001 | Database Audit | Struktur tabel, foreign key, primary key, tipe data |
| G1-002 | FnB Relationship | Perbaikan relasi dan konsistensi transaksi |
| G1-003 | Transaction Integrity | Validasi total, stok, status, dan pembayaran |
| G1-004 | RBAC | OWNER, ADMIN, KASIR |
| G1-005 | Route Authorization | Pembatasan akses endpoint |
| G1-006 | Security | Proteksi tindakan destruktif dan validasi |
| G1-007 | Data Profiling | Pemeriksaan nominal dan histori transaksi |
| G1-008 | Regression Testing | Pengujian alur operasional existing |
| G1-009 | Code Quality | Perbaikan minimal yang relevan |
| G1-010 | Technical Documentation | Dokumentasi perubahan dan pengujian |

## 3.2 Out of Scope

G1 tidak mencakup:

- Pembuatan Billing Engine baru.
- Implementasi background billing worker.
- Integrasi Samsung Smart TV.
- Implementasi Wake-on-LAN.
- Implementasi Samsung WebSocket.
- Integrasi Shelly Smart Relay.
- DHCP discovery.
- Auto device pairing.
- Pembuatan Device Control Adapter.
- Redesign total antarmuka.
- Migrasi ke Next.js atau NestJS.
- Migrasi ke PostgreSQL.
- Penggantian framework Laravel.
- Integrasi VPS/cloud reporting.
- Direct ESC/POS printing.
- Pembuatan fitur refund.
- Penambahan modul operasional yang tidak terkait stabilisasi.

## 3.3 Scope Protection

Setiap fitur atau perubahan yang ditemukan di luar lingkup G1 harus:

1. Didokumentasikan.
2. Diberi klasifikasi dampak.
3. Ditentukan prioritasnya.
4. Dialokasikan pada gate berikutnya.
5. Tidak otomatis diimplementasikan.

---

# 4. EXISTING SYSTEM BASELINE

## 4.1 Technology Stack

| Komponen | Existing |
|---|---|
| Backend | Laravel 8 |
| Programming Language | PHP |
| Database | MySQL |
| Frontend | Laravel Blade |
| UI Framework | Bootstrap |
| Asset Bundler | Laravel Mix |
| Authentication | Laravel session authentication |
| Reporting | PDF dan Excel |
| Deployment Development | MAMP / PHP lokal |
| Deployment Target | PC Kasir Lokal |

Versi dependency dan kemampuan runtime wajib dikonfirmasi melalui `composer.json`, `composer.lock`, serta pengujian environment.

## 4.2 Modul yang Harus Dipertahankan

- Dashboard.
- Manajemen pengguna.
- Manajemen jenis PlayStation/tarif.
- Manajemen perangkat.
- Prepaid billing.
- Postpaid/Lost Time.
- Custom Package.
- Perubahan durasi dan perangkat.
- Transaksi FnB.
- Manajemen stok.
- Pembayaran.
- Diskon.
- Cetak struk.
- Laporan transaksi.
- Laporan FnB.
- Pencatatan pengeluaran.
- Shift kerja.

G1 tidak boleh menghapus modul tersebut atau mengubah perilakunya tanpa kebutuhan perbaikan yang terbukti.

---

# 5. USER PERSONAS & RBAC REQUIREMENTS

## 5.1 OWNER

**Tujuan:** Mengawasi kondisi bisnis, memantau aktivitas kasir, dan memastikan integritas laporan keuangan.

Kebutuhan:

- Melihat dashboard.
- Melihat pendapatan.
- Melihat daftar transaksi.
- Melihat status perangkat dan sesi rental.
- Melihat laporan FnB.
- Melihat pengeluaran.
- Melihat laporan aktivitas operasional.
- Tidak menjalankan transaksi kasir melalui akses monitoring read-only.

## 5.2 ADMIN

**Tujuan:** Mengelola konfigurasi dan operasional usaha.

Kebutuhan:

- Mengelola master produk.
- Mengelola stok.
- Mengelola tarif.
- Mengelola perangkat.
- Mengelola pengguna sesuai kewenangan.
- Memantau dan membantu operasional kasir.
- Mengakses laporan.
- Menangani konfigurasi yang diizinkan.

## 5.3 KASIR

**Tujuan:** Melayani pelanggan dan memproses transaksi sehari-hari.

Kebutuhan:

- Membuka transaksi.
- Menambah pesanan.
- Mengedit transaksi sesuai aturan.
- Mengakhiri sesi.
- Menerima pembayaran.
- Mencetak struk.
- Melihat status perangkat.
- Mencatat pengeluaran operasional apabila diizinkan.

## 5.4 Matriks RBAC Target G1

Keterangan:

- **R:** Read.
- **W:** Write/Modify.
- **RW:** Read & Write.
- **—:** Tidak diizinkan.
- **Terbatas:** Hanya tindakan yang diatur secara khusus.

| Fitur | OWNER | ADMIN | KASIR |
|---|---|---|---|
| Dashboard | R | R | R terbatas |
| Monitoring perangkat | R | RW | R |
| Melihat transaksi | R | R | R |
| Membuat transaksi | — | W | W |
| Mengedit transaksi | — | W | W terbatas |
| Mengakhiri billing | — | W | W |
| Memproses pembayaran | — | W | W |
| Cetak struk | R terbatas | W | W |
| Master tarif | R | RW | — |
| Master perangkat | R | RW | — |
| Master FnB | R | RW | — |
| Manajemen stok | R | RW | — |
| Pengeluaran | R | RW | W terbatas |
| Laporan keuangan | R | R | — |
| Laporan FnB | R | R | — |
| Manajemen pengguna | R | RW terbatas | — |
| Reset seluruh transaksi | — | — | — |

Matriks ini menjadi target yang harus divalidasi terhadap kebutuhan bisnis dan implementasi aktual.

**Tidak ada fitur refund pada seluruh role.**

## 5.5 Security Requirement RBAC

- FR-RBAC-001: Pengguna yang belum login tidak boleh mengakses route internal.
- FR-RBAC-002: Backend wajib memverifikasi role setiap tindakan sensitif.
- FR-RBAC-003: OWNER dapat mengakses monitoring tanpa memperoleh hak mutasi transaksi.
- FR-RBAC-004: KASIR tidak boleh mengubah tarif master.
- FR-RBAC-005: KASIR tidak boleh mengelola role pengguna.
- FR-RBAC-006: Akses langsung menggunakan URL tidak boleh melewati otorisasi.
- FR-RBAC-007: Endpoint AJAX harus mengikuti aturan RBAC yang sama.
- FR-RBAC-008: Perubahan akses tidak boleh membuat endpoint destructive terbuka untuk role lain.
- FR-RBAC-009: Nama dan kapitalisasi role harus konsisten dengan nilai aktual database.
- FR-RBAC-010: Akses yang tidak diizinkan menghasilkan HTTP 403 atau respons keamanan yang sesuai.

---

# 6. FUNCTIONAL REQUIREMENTS — DATABASE

## 6.1 FR-DB-001 — Validasi Primary Key

Sistem wajib menggunakan primary key yang benar pada setiap model dan relasi.

Acceptance Criteria:

- Setiap model Eloquent memiliki primary key yang sesuai dengan skema.
- Seluruh relationship yang digunakan menghasilkan data benar.
- Tidak terdapat SQL error akibat kolom relasi yang tidak tersedia.
- Perbaikan tidak mengubah primary key historis.

## 6.2 FR-DB-002 — Perbaikan Relasi TransactionFnB

Existing:

`TransactionFnb` memiliki relasi menuju `Transaction`.

Audit menunjukkan kemungkinan penggunaan target key `id`, sedangkan primary key transaksi adalah `id_transaksi`.

Requirement:

- Verifikasi foreign key aktual.
- Perbaiki relationship yang tidak sesuai.
- Pertahankan foreign key existing.
- Pastikan relasi kompatibel dengan query controller.
- Tambahkan regression test.

Acceptance Criteria:

- Relasi FnB mengembalikan transaksi yang tepat.
- Eager loading berjalan.
- Detail transaksi tampil benar.
- Penghitungan FnB tidak berubah.
- Semua test terkait lulus.

## 6.3 FR-DB-003 — Data Integrity

Semua transaksi harus mempertahankan:

- Identifier transaksi.
- Nilai awal.
- Nilai total.
- Status pembayaran.
- Jenis transaksi.
- Hubungan perangkat.
- Item FnB.
- Riwayat stok yang relevan.
- Waktu transaksi.

Tidak boleh terjadi kehilangan data akibat perbaikan G1.

## 6.4 FR-DB-004 — Data Profiling Nominal

Lakukan analisis format kolom keuangan existing.

Kategori yang diperiksa:

- Integer.
- Decimal.
- Pemisah ribuan.
- Nilai kosong.
- NULL.
- Nilai negatif.
- Nilai tidak valid.
- Format mata uang.
- Format yang ambigu.

Output:

- Jumlah record.
- Distribusi format.
- Jumlah nilai tidak valid.
- Contoh nilai bermasalah yang telah disanitasi dari informasi sensitif.
- Strategi normalisasi.
- Risiko perubahan tipe data.

Perubahan tipe database yang berisiko tidak boleh langsung dieksekusi.

## 6.5 FR-DB-005 — Safe Migration

Migration harus memenuhi:

- Non-destructive by default.
- Menjaga data historis.
- Tidak mengubah primary key.
- Tidak menghapus kolom tanpa persetujuan.
- Diuji pada salinan database.
- Memiliki verifikasi sebelum dan sesudah.
- Memiliki prosedur recovery.
- Dilaksanakan hanya setelah persetujuan untuk operasi berisiko.

## 6.6 FR-DB-006 — Backup dan Recovery

Sebelum migration yang mengubah data:

1. Buat backup database.
2. Validasi file backup.
3. Lakukan restore test pada database terpisah.
4. Catat jumlah record dan checksum atau metrik konsistensi yang relevan.
5. Jalankan migration di lingkungan uji.
6. Bandingkan hasil.
7. Minta persetujuan sebelum melakukan migration pada database operasional.

---

# 7. FUNCTIONAL REQUIREMENTS — TRANSACTION STABILITY

## 7.1 FR-TRX-001 — Prepaid Billing

Alur existing harus tetap mendukung:

1. Kasir memilih perangkat.
2. Kasir memilih durasi atau paket.
3. Sistem menghitung biaya.
4. Transaksi disimpan.
5. Timer ditampilkan.
6. Transaksi dapat diproses sesuai kebijakan pembayaran existing.
7. Perangkat kembali tersedia sesuai aturan existing.

G1 tidak mengubah mekanisme timer menjadi server-side. Perubahan tersebut masuk G2.

## 7.2 FR-TRX-002 — Lost Time / Postpaid

Sistem harus mempertahankan:

- Pencatatan waktu mulai.
- Perhitungan durasi penggunaan.
- Pembulatan tarif existing.
- Penyelesaian sesi oleh kasir.
- Kalkulasi pembayaran.
- Pembuatan struk.

Formula perhitungan existing harus dijadikan baseline dan tidak diubah tanpa keputusan bisnis.

## 7.3 FR-TRX-003 — Custom Package

Paket gabungan rental dan FnB harus tetap dapat:

- Dipilih.
- Dihitung.
- Disimpan.
- Ditampilkan pada detail transaksi.
- Diproses pembayarannya.
- Dicetak pada struk.

## 7.4 FR-TRX-004 — Edit Transaction

Pertahankan kemampuan existing untuk:

- Perubahan durasi.
- Perpindahan perangkat.
- Penyesuaian item FnB.
- Rekalkulasi biaya sesuai aturan saat ini.

Validasi harus mencegah:

- Pemakaian satu perangkat oleh dua sesi aktif yang saling bertentangan.
- Perubahan nilai transaksi tidak sah.
- Manipulasi identifier.
- Perubahan terhadap transaksi yang tidak memenuhi syarat edit.

## 7.5 FR-TRX-005 — Financial Consistency

Nilai transaksi harus konsisten di:

- Form transaksi.
- Detail transaksi.
- Pembayaran.
- Cetak struk.
- Laporan omzet.
- Export PDF.
- Export Excel.

Perbedaan perhitungan harus dianggap defect yang memerlukan investigasi.

---

# 8. FUNCTIONAL REQUIREMENTS — FNB & INVENTORY

## 8.1 FR-FNB-001 — Item FnB

Sistem harus dapat:

- Memuat katalog FnB.
- Menambahkan item ke transaksi.
- Menampilkan kuantitas dan subtotal.
- Mengedit item sesuai aturan.
- Menghapus item sebelum penyelesaian apabila diizinkan.

## 8.2 FR-FNB-002 — Stock Mutation

Ketika terjadi penjualan FnB:

- Kuantitas stok berkurang sesuai item terjual.
- Mutasi stok tercatat.
- Penambahan item pada transaksi tidak boleh terhitung dua kali.
- Pengubahan kuantitas harus menghasilkan perubahan stok yang benar.
- Penghapusan item mengikuti mekanisme restore stok existing.

## 8.3 FR-FNB-003 — Stock Validation

- Stok tidak boleh menjadi negatif jika produk dibatasi stok.
- Item dengan mekanisme unlimited stock tetap mengikuti aturan existing.
- Permintaan melebihi stok harus ditolak.
- Validasi dilakukan backend.

## 8.4 FR-FNB-004 — Transaction Atomicity

Untuk operasi yang memengaruhi transaksi dan stok, gunakan mekanisme transaksi database sesuai kebutuhan agar perubahan tidak menghasilkan keadaan parsial.

Contoh:

Jika proses menyimpan transaksi gagal, mutasi stok yang merupakan bagian dari proses tersebut tidak boleh tertinggal tanpa transaksi yang sah.

## 8.5 Acceptance Criteria FnB

- Penambahan item berhasil.
- Edit kuantitas berhasil.
- Penghapusan item sesuai aturan berhasil.
- Stok konsisten.
- Subtotal konsisten.
- Tidak ada mutasi ganda yang tidak sah.
- Relasi FnB dan transaksi valid.
- Seluruh regression test relevan lulus.

---

# 9. FUNCTIONAL REQUIREMENTS — SECURITY

## 9.1 FR-SEC-001 — Proteksi Reset Transactions

Endpoint:

`/settings/reset-transactions`

Requirement:

- Operasi hapus seluruh transaksi tidak boleh tersedia pada alur operasional normal.
- Backend harus menolak pemanggilan destruktif meskipun URL diakses langsung.
- UI tidak boleh menawarkan tindakan destruktif yang sudah dinonaktifkan.
- Tidak boleh terjadi penghapusan data selama implementasi G1.

Acceptance Criteria:

- Request reset tidak menghapus transaksi.
- Hak akses tidak dapat dilewati menggunakan direct request.
- Jumlah data transaksi tetap.
- Regression test membuktikan proteksi endpoint.

## 9.2 FR-SEC-002 — Authentication

- Setiap route privat membutuhkan autentikasi.
- Session handling tetap aman.
- Password tidak disimpan plaintext.
- Login tidak membuka data internal untuk pengguna yang belum diautentikasi.

## 9.3 FR-SEC-003 — Authorization

Semua tindakan sensitif harus memiliki otorisasi backend.

Khususnya:

- Edit transaksi.
- Pembayaran.
- Pengelolaan master data.
- Manajemen pengguna.
- Pengubahan pengaturan.
- Penghapusan data.

## 9.4 FR-SEC-004 — Input Validation

Validasi minimal:

- Identifier transaksi valid.
- Nominal pembayaran valid.
- Kuantitas FnB valid.
- Durasi rental valid.
- Status transaksi valid.
- Role pengguna valid.
- Data master sesuai constraint.

## 9.5 FR-SEC-005 — Mass Assignment Protection

Periksa apakah input request dapat mengubah atribut sensitif secara tidak sah.

Contoh atribut yang memerlukan pemeriksaan:

- `role`.
- `payment_status`.
- `total`.
- `device_id`.
- Status transaksi.

## 9.6 FR-SEC-006 — Sensitive Data

- Jangan menampilkan credential dalam log.
- Jangan commit `.env`.
- Jangan menampilkan password dalam laporan.
- Jangan mengekspos detail error internal pada production.
- Jangan menyimpan data sensitif baru tanpa kebutuhan.

## 9.7 FR-SEC-007 — Audit Trail

Rancang audit trail untuk tindakan sensitif.

Minimal mencakup:

- Actor/user.
- Jenis tindakan.
- Target record.
- Timestamp.
- Perubahan penting.
- Hasil tindakan.

Implementasi audit log penuh dapat dipisahkan ke gate berikutnya jika membutuhkan perluasan skema signifikan. Namun, celah keamanan kritis harus ditangani di G1.

---

# 10. NON-FUNCTIONAL REQUIREMENTS

## 10.1 Reliability

NFR-001: Perubahan G1 tidak menyebabkan kehilangan transaksi.

NFR-002: Seluruh proses kasir existing tetap berjalan.

NFR-003: Kesalahan validasi harus menghasilkan pesan yang dapat ditindaklanjuti.

NFR-004: Kegagalan penyimpanan tidak boleh meninggalkan data parsial yang tidak konsisten.

## 10.2 Performance

NFR-005: Perubahan authorization tidak boleh menyebabkan query berlebihan.

NFR-006: Perbaikan relasi tidak boleh menimbulkan masalah N+1 query yang signifikan pada daftar transaksi.

NFR-007: Performa transaksi tidak boleh mengalami penurunan yang terbukti signifikan dibanding baseline.

Target numerik harus ditentukan setelah pengukuran awal.

## 10.3 Security

NFR-008: Semua endpoint sensitif menerapkan otorisasi server-side.

NFR-009: Operasi destruktif dinonaktifkan atau dibatasi sesuai keputusan G1.

NFR-010: Tidak ada credential baru yang disimpan di repository.

## 10.4 Maintainability

NFR-011: Perubahan memiliki scope minimal.

NFR-012: Perubahan dapat ditelusuri melalui diff.

NFR-013: Bug yang diperbaiki memiliki regression test apabila memungkinkan.

NFR-014: Dokumentasi mencatat alasan perubahan.

## 10.5 Compatibility

NFR-015: Kompatibel dengan stack Laravel existing.

NFR-016: Tidak mengubah route publik/internal yang digunakan frontend tanpa kebutuhan.

NFR-017: Tidak mengganti framework dalam G1.

NFR-018: Database existing tetap menjadi sumber kebenaran transaksi.

---

# 11. TEST STRATEGY

## 11.1 Baseline Tests

Sebelum coding:

- Dokumentasikan versi PHP dan Composer.
- Verifikasi dependency.
- Verifikasi status repository.
- Periksa environment testing.
- Jalankan tes yang aman.
- Catat kegagalan yang sudah ada.

## 11.2 Automated Testing

Kelompok tes:

**A. Model & Database Tests**
- Primary key.
- Relationship FnB.
- Query transaksi.
- Eager loading.

**B. Transaction Tests**
- Prepaid.
- Postpaid.
- Custom package.
- Edit transaksi.
- Pembayaran.
- Total transaksi.

**C. Inventory Tests**
- Pengurangan stok.
- Pengembalian stok sesuai alur existing.
- Validasi stok habis.
- Mutasi stok.

**D. Authorization Tests**
- OWNER read-only.
- ADMIN management.
- KASIR operational access.
- Guest access denied.
- Unauthorized mutation denied.

**E. Security Tests**
- Direct request ke reset endpoint.
- Invalid identifier.
- Invalid amount.
- Unauthorized action.
- Mass assignment.

## 11.3 Manual Testing

Manual QA wajib menggunakan database testing atau staging terisolasi.

Skenario minimum:

| ID | Skenario | Expected Result |
|---|---|---|
| MT-01 | Login OWNER | Dashboard dan monitoring dapat diakses |
| MT-02 | OWNER mencoba edit transaksi | Ditolak |
| MT-03 | Login ADMIN | Menu manajemen sesuai hak akses |
| MT-04 | Login KASIR | Operasional kasir tersedia |
| MT-05 | Buat transaksi prepaid | Berhasil |
| MT-06 | Buat transaksi Lost Time | Berhasil |
| MT-07 | Buat custom package | Berhasil |
| MT-08 | Edit transaksi | Sesuai aturan existing |
| MT-09 | Tambah FnB | Total dan stok benar |
| MT-10 | Edit kuantitas FnB | Mutasi stok benar |
| MT-11 | Pembayaran | Nominal dan kembalian benar |
| MT-12 | Cetak struk | Rincian sesuai transaksi |
| MT-13 | Buka laporan | Data benar |
| MT-14 | Export PDF | Berhasil |
| MT-15 | Export Excel | Berhasil |
| MT-16 | Akses reset transactions | Tidak dapat menghapus data |
| MT-17 | Akses route tanpa login | Ditolak |
| MT-18 | Transaksi melewati tengah malam | Durasi dihitung sesuai aturan existing |

## 11.4 Regression Policy

G1 tidak boleh dianggap berhasil jika:

- Pembayaran menghasilkan nominal yang berbeda tanpa alasan.
- Stok berubah secara salah.
- Relasi FnB gagal.
- Ada role yang memperoleh privilege baru tanpa keputusan.
- Histori transaksi hilang.
- Endpoint destruktif masih dapat dipanggil tanpa perlindungan.
- Tes kritis gagal tanpa mitigasi.

---

# 12. IMPLEMENTATION PHASES

## Phase G1.0 — Baseline & Verification

Deliverables:

- Repository inventory.
- Dependency compatibility result.
- Database schema findings.
- Test baseline.
- Confirmed defect list.

Exit Criteria:

- Lingkungan kerja teridentifikasi.
- Tidak ada perubahan milik developer lain yang tertimpa.
- Temuan prioritas diverifikasi.

## Phase G1.1 — FnB Relationship Fix

Deliverables:

- Perbaikan relasi yang terbukti salah.
- Regression tests relasi.
- Verifikasi transaksi dan stok.

Exit Criteria:

- Query relasi valid.
- Test relevan lulus.
- Tidak ada perubahan perilaku FnB yang tidak disengaja.

## Phase G1.2 — RBAC Hardening

Deliverables:

- Perubahan route authorization.
- Perubahan controller authorization.
- Penyesuaian menu bila diperlukan.
- RBAC matrix hasil implementasi.
- Authorization tests.

Exit Criteria:

- Tiga role memenuhi akses target yang telah disetujui.
- Tidak ada privilege escalation.

## Phase G1.3 — Security Hardening

Deliverables:

- Proteksi reset transaksi.
- Perbaikan temuan keamanan kritis dalam scope.
- Security regression tests.

Exit Criteria:

- Endpoint destruktif aman.
- Pengujian akses tidak sah lulus.

## Phase G1.4 — Database Migration Readiness

Deliverables:

- Data profiling report.
- Financial normalization strategy.
- Migration specification.
- Backup/restore procedure.
- Recovery plan.

Exit Criteria:

- Risiko migrasi terdokumentasi.
- Tidak ada migrasi berisiko yang dijalankan tanpa approval.

## Phase G1.5 — Regression Testing & Final Review

Deliverables:

- Automated test report.
- Manual QA checklist.
- Final code diff.
- Risk register.
- G1 implementation report.

Exit Criteria:

- Semua skenario kritis diuji.
- Kegagalan tidak disembunyikan.
- Perubahan siap direview.

---

# 13. DEPENDENCIES

## 13.1 Technical Dependencies

- Repository source code.
- PHP runtime kompatibel.
- Composer dependencies.
- MySQL lokal.
- Database test terisolasi.
- Existing migrations.
- Existing test suite.
- Laravel authentication.
- Existing route/controller/model.

## 13.2 Human Dependencies

Intervensi manusia wajib untuk:

- Approval perubahan skema database berisiko.
- Persetujuan perubahan kebijakan bisnis.
- Verifikasi hasil manual testing.
- Otorisasi penggunaan data transaksi operasional.
- Persetujuan merge/deploy.
- Penutupan gate G1.

AI Coding Agent tidak boleh mengklaim intervensi tersebut telah dilakukan.

---

# 14. RISK REGISTER

| ID | Risiko | Dampak | Mitigasi |
|---|---|---|---|
| R01 | Perubahan relasi memengaruhi query lain | Tinggi | Audit penggunaan dan regression test |
| R02 | Konversi nominal mengubah nilai historis | Kritis | Profiling dan migrasi terkontrol |
| R03 | Perubahan RBAC membuka endpoint mutation | Tinggi | Test per method/role |
| R04 | Reset transaksi menghapus data | Kritis | Nonaktifkan endpoint operasional |
| R05 | Testing menulis database asli | Kritis | Isolasi database testing |
| R06 | Perubahan controller merusak billing | Tinggi | Minimal diff dan regression testing |
| R07 | Dependency PHP tidak kompatibel | Sedang | Verifikasi composer.lock dan runtime |
| R08 | Format waktu lintas tengah malam ambigu | Tinggi | Pertahankan data asli, audit sebelum migrasi |
| R09 | Data backup tidak dapat dipulihkan | Kritis | Restore test |
| R10 | Scope berkembang ke G2–G5 | Sedang | Gate control dan approval |

---

# 15. SUCCESS METRICS

Keberhasilan G1 dinilai dari:

| Metric | Target |
|---|---|
| Confirmed FnB relationship defects | 0 tersisa |
| Unauthorized sensitive actions | 0 berhasil |
| Reset transaction destructive access | 0 berhasil |
| Critical regression tests | 100% pass |
| Historical transaction data loss | 0 |
| Unapproved destructive migrations | 0 |
| Unexpected business flow changes | 0 |
| Critical security defects dalam scope | 0 terbuka saat approval |
| Manual QA critical scenarios | 100% diuji dan lulus sebelum gate ditutup |

Status blocked harus dilaporkan terpisah; tidak boleh dihitung sebagai pass.

---

# 16. DEFINITION OF DONE

G1 dapat dinyatakan selesai jika:

- [ ] Baseline audit selesai.
- [ ] Bug relasi FnB yang terkonfirmasi diperbaiki.
- [ ] Validasi transaksi dan stok lulus.
- [ ] Matriks RBAC diterapkan dan diuji.
- [ ] OWNER memperoleh akses monitoring read-only sesuai kebijakan.
- [ ] ADMIN memiliki akses operasional yang benar.
- [ ] KASIR memiliki hak akses sesuai tugas.
- [ ] Reset transaksi tidak dapat menghapus data dalam operasional normal.
- [ ] Pemeriksaan keamanan kritis selesai.
- [ ] Data profiling nominal selesai.
- [ ] Migrasi berisiko tidak dijalankan tanpa approval.
- [ ] Regression test otomatis relevan lulus.
- [ ] Manual QA kritis lulus.
- [ ] Final diff direview.
- [ ] Tidak terdapat kehilangan data historis.
- [ ] Implementation Report tersedia.
- [ ] Approval pemilik proyek diberikan.

---

# 17. HANDOFF TO G2

Setelah G1 diterima, pengembangan dapat dilanjutkan ke:

**G2 — Server-Side Billing Engine**

Target G2:

- Memindahkan pengendalian timer dari browser ke backend.
- Menambahkan timestamp absolut.
- Menangani sesi lintas tengah malam secara konsisten.
- Menjalankan monitoring expiration secara independen dari browser.
- Menangani restart PC kasir.
- Memisahkan billing lifecycle dari payment lifecycle.
- Menyiapkan event untuk otomatisasi perangkat pada G5.

G2 tidak boleh dimulai sebelum G1 mendapatkan approval.

---

# 18. FINAL IMPLEMENTATION INSTRUCTION

Dokumen ini merupakan spesifikasi resmi G1 untuk pengembangan Sugeng Rawuh.

AI Coding Agent diperbolehkan:

- Melakukan audit.
- Memperbaiki kode yang termasuk scope.
- Menambahkan regression test.
- Memperketat authorization.
- Memperbaiki bug FnB.
- Menonaktifkan operasi destruktif.
- Membuat laporan implementasi.

AI Coding Agent tidak diperbolehkan:

- Menghapus data transaksi.
- Melakukan migrasi destruktif tanpa persetujuan.
- Mengubah aturan bisnis tanpa persetujuan.
- Menjalankan implementasi G2–G7.
- Melakukan merge, push, atau deployment otomatis.

**GATE G1 HARUS BERHENTI PADA REVIEW DAN PERSETUJUAN SEBELUM PENGEMBANGAN G2.**

---

**Document Status:** READY FOR CONTROLLED IMPLEMENTATION  
**Next Action:** Execute G1A — Existing System Stabilization  
**Approval Required:** Manual QA, Database Migration Approval, G1 Gate Closure
