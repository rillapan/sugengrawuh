# SUGENG RAWUH — INDEKS DIAGRAM MERMAID & PANDUAN PRATINJAU VISUAL

**Project:** Sugeng Rawuh — Integrated Billing & POS Management System  
**Repository:** `sistem_kasir_PS-main`  
**Document Ref:** `docs/SUGENG-RAWUH-MERMAID-INDEX.md`  
**Target Viewer Tools:** [Mermaid Live Editor](https://mermaid.live/) & [Mermaid Official Docs](https://mermaid.js.org/)  
**Total Diagram Terindeks:** 17 Diagram Lengkap

---

## 1. PANDUAN MELIHAT & MEMVALIDASI DIAGRAM SECARA VISUAL

Seluruh diagram dalam dokumentasi Sugeng Rawuh telah diformat menggunakan sintaks standar **Mermaid GFM (GitHub Flavored Markdown)** yang valid dan dapat dirender langsung di editor Markdown modern (VS Code, PhpStorm, GitHub) atau melalui browser web.

### Langkah Membuka Diagram di Mermaid Live Editor:
1. Buka situs resmi **[https://mermaid.live/](https://mermaid.live/)** pada browser Anda.
2. Buka dokumen [SUGENG-RAWUH-COMPLETE-USER-FLOW.md](SUGENG-RAWUH-COMPLETE-USER-FLOW.md).
3. Salin (*copy*) teks kode yang berada di dalam blok ` ```mermaid ` hingga ` ``` `.
4. Tempel (*paste*) kode tersebut ke dalam kolom editor teks sebelah kiri di Mermaid Live Editor.
5. Panel sebelah kanan akan langsung menampilkan visualisasi grafis diagram yang tajam dan interaktif.
6. Anda dapat mengunduh diagram ke format gambar **PNG**, **SVG**, atau menyalin tautan pratinjau langsung.

---

## 2. TABEL INDEKS 17 DIAGRAM MERMAID

| No | Judul Diagram | Tipe Sintaks | Fungsi & Cakupan Arsitektur | Tautan Bagian Dokumen | Tautan Halaman Aplikasi Terkait | Status Verifikasi Sintaks |
|---|---|---|---|---|---|---|
| **01** | Arsitektur Sistem Lengkap | `flowchart TB` | Interaksi Host PC Kasir, Laravel, Database, Worker, Router, Samsung TV, PS, dan Meja Biliar | [Lihat Diagram 1](SUGENG-RAWUH-COMPLETE-USER-FLOW.md#diagram-1--arsitektur-sistem-lengkap-complete-system-architecture) | [Dasbor Utama](http://127.0.0.1:8008/home) | `VALIDATED & VERIFIED` |
| **02** | Login, Autentikasi & RBAC | `flowchart TD` | Autentikasi sesi, pembagian hak akses Owner, Admin, Kasir, dan penegakan aturan *no refund* | [Lihat Diagram 2](SUGENG-RAWUH-COMPLETE-USER-FLOW.md#diagram-2--login-autentikasi--rbac-multi-role) | [Halaman Login](http://127.0.0.1:8008/login) | `VALIDATED & VERIFIED` |
| **03** | Peta Situs & Navigasi | `flowchart LR` | Struktur hierarki 6 modul utama sidebar aplikasi web Sugeng Rawuh | [Lihat Diagram 3](SUGENG-RAWUH-COMPLETE-USER-FLOW.md#diagram-3--peta-situs--navigasi-antarmuka-website-sitemap) | [Dasbor Utama](http://127.0.0.1:8008/home) | `VALIDATED & VERIFIED` |
| **04** | ADMIN: Manajemen Stasiun PS | `flowchart TD` | Siklus CRUD stasiun rental, konsol PS3/PS4/PS5/Biliar, dan proteksi transaksi berjalan | [Lihat Diagram 4](SUGENG-RAWUH-COMPLETE-USER-FLOW.md#diagram-4--admin-manajemen-station-playstation) | [Data Perangkat](http://127.0.0.1:8008/device) | `VALIDATED & VERIFIED` |
| **05** | ADMIN: Manajemen Samsung TV | `flowchart TD` | Pendaftaran Samsung Smart TV 50U8500, validasi ketat brand (penolakan Coocaa), enkripsi token | [Lihat Diagram 5](SUGENG-RAWUH-COMPLETE-USER-FLOW.md#diagram-5--admin-manajemen-samsung-smart-tv) | [Device Registry](http://127.0.0.1:8008/admin/hardware/registry) | `VALIDATED & VERIFIED` |
| **06** | Sekuens Koneksi & Pairing TV | `sequenceDiagram` | Protokol REST port 8001 (probe), WebSocket port 8002 (pairing popup di layar TV), token capture | [Lihat Diagram 6](SUGENG-RAWUH-COMPLETE-USER-FLOW.md#diagram-6--sekuens-koneksi--pairing-samsung-smart-tv) | [Hardware Diagnostics](http://127.0.0.1:8008/admin/hardware/diagnostics) | `VALIDATED & VERIFIED` |
| **07** | KASIR: Billing Prepaid PS | `flowchart TD` | Pemesanan paket jam, penguncian server-side, timer hitung mundur, dan pencegahan sinyal bahaya TV | [Lihat Diagram 7](SUGENG-RAWUH-COMPLETE-USER-FLOW.md#diagram-7--kasir-billing-prepaid-playstation) | [Data Perangkat](http://127.0.0.1:8008/device) | `VALIDATED & VERIFIED` |
| **08** | KASIR: Billing Lost Time PS | `flowchart TD` | Sesi main sepuasnya tanpa batas waktu awal, stopwatch berjalan, dan kalkulasi tarif selesai | [Lihat Diagram 8](SUGENG-RAWUH-COMPLETE-USER-FLOW.md#diagram-8--kasir-billing-lost-time-postpaid-playstation) | [Data Perangkat](http://127.0.0.1:8008/device) | `VALIDATED & VERIFIED` |
| **09** | KASIR: Extend & Stop Billing | `flowchart TD` | Penambahan jam main, stop dini, peningkatan session generation, dan pembatalan stale command | [Lihat Diagram 9](SUGENG-RAWUH-COMPLETE-USER-FLOW.md#diagram-9--kasir-perpanjangan-waktu-extend--penghentian-dini-stop-session) | [Data Perangkat](http://127.0.0.1:8008/device) | `VALIDATED & VERIFIED` |
| **10** | Otomasi Biliar & Shelly Relay | `sequenceDiagram` | Otomatisasi lampu biliar: Start menyala, prepaid habis -> LANGSUNG MATI TANPA GRACE PERIOD | [Lihat Diagram 10](SUGENG-RAWUH-COMPLETE-USER-FLOW.md#diagram-10--otomatisasi-meja-biliar--shelly-relay) | [Log Otomasi](http://127.0.0.1:8008/admin/hardware/automation/logs) | `VALIDATED & VERIFIED` |
| **11** | Transaksi POS Makanan/Minuman | `flowchart TD` | Penjualan FnB terintegrasi billing / mandiri, pengecekan stok dengan lock FOR UPDATE, mutasi stok | [Lihat Diagram 11](SUGENG-RAWUH-COMPLETE-USER-FLOW.md#diagram-11--alur-transaksi-pos-makanan--minuman-fnb) | [Transaksi Baru](http://127.0.0.1:8008/transaction/create) | `VALIDATED & VERIFIED` |
| **12** | Pembayaran & Cetak Struk | `flowchart TD` | Checkout tagihan (Tunai, E-Wallet, Transfer), validasi nominal bayar, pencegahan bayar ganda, print thermal | [Lihat Diagram 12](SUGENG-RAWUH-COMPLETE-USER-FLOW.md#diagram-12--alur-pembayaran-checkout--cetak-struk-receipt) | [Layar Bayar](http://127.0.0.1:8008/transaction/1/payment) | `VALIDATED & VERIFIED` |
| **13** | Laporan Keuangan OWNER/ADMIN | `flowchart TD` | Filter transaksi rentang tanggal/shift, grafik analitik, ekspor laporan resmi format Excel dan PDF | [Lihat Diagram 13](SUGENG-RAWUH-COMPLETE-USER-FLOW.md#diagram-13--laporan-keuangan--audit-operasional-owner--admin) | [Laporan Penjualan](http://127.0.0.1:8008/report) | `VALIDATED & VERIFIED` |
| **14** | Recovery Kegagalan Hardware | `flowchart TD` | Penanganan TV offline, relay putus, worker mati, DB putus, dan prosedur manual fallback kasir | [Lihat Diagram 14](SUGENG-RAWUH-COMPLETE-USER-FLOW.md#diagram-14--penanganan-kegagalan-hardware--pemulihan-offline-failure-recovery) | [Log Telemetri](http://127.0.0.1:8008/admin/hardware/automation/logs) | `VALIDATED & VERIFIED` |
| **15** | State Machine Siklus Billing | `stateDiagram-v2` | Transisi status billing: IDLE -> RUNNING -> EXPIRED / STOPPED -> COMPLETED -> IDLE | [Lihat Diagram 15](SUGENG-RAWUH-COMPLETE-USER-FLOW.md#diagram-15--state-machine-siklus-hidup-billing-billing-lifecycle-state-machine) | [Data Perangkat](http://127.0.0.1:8008/device) | `VALIDATED & VERIFIED` |
| **16** | Sekuens Transactional Outbox | `sequenceDiagram` | Dekopling transaksi kasir ACID dengan background worker asinkronus via tabel automation_intents | [Lihat Diagram 16](SUGENG-RAWUH-COMPLETE-USER-FLOW.md#diagram-16--alur-transactional-outbox-pattern-transactional-outbox-sequence) | [Intent Otomasi](http://127.0.0.1:8008/admin/hardware/automation/intents) | `VALIDATED & VERIFIED` |
| **17** | Entity Relationship Diagram | `erDiagram` | Skema struktur tabel relasi users, devices, transactions, physical_devices, intents, fnbs | [Lihat Diagram 17](SUGENG-RAWUH-COMPLETE-USER-FLOW.md#diagram-17--entity-relationship-diagram-erd-relasi-billing-stasiun--iot) | [Dasbor Utama](http://127.0.0.1:8008/home) | `VALIDATED & VERIFIED` |

---

## 3. CATATAN STANDAR KUALITAS MERMAID

Seluruh diagram di atas mematuhi kriteria validasi berikut:
1. **Tidak Ada Tumpang Tindih Subgraph:** Setiap `subgraph` memiliki batas cakupan logis yang jelas.
2. **Konektivitas Lengkap:** Tidak ada node yang berdiri sendiri tanpa relasi.
3. **Pemberian Label Bersih:** Label node diapit tanda kutip ganda (`["..."]`) untuk mencegah parsing error akibat karakter khusus seperti kurung, garis miring, atau titik dua.
4. **Alur Logika Jelas:** Decision nodes (`{"..."}`) memiliki cabang kondisi eksplisit (*Ya / Tidak*, *Gagal / Berhasil*).
5. **Pemisahan Domain Konsisten:** Alur PlayStation dan Meja Biliar dipisahkan secara tegas agar tidak membingungkan operator rental.

---

*Indeks diagram ini merupakan bagian tak terpisahkan dari bundel dokumentasi resmi Sugeng Rawuh (sistem_kasir_PS-main).*
