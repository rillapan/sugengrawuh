# SUGENG RAWUH — G3-A RISKS, TRADEOFFS & ARCHITECTURAL DECISION REGISTER (FINAL APPROVED REVISION)
**Document ID:** SR-G3A-RISK-006-REV2  
**Project:** Sugeng Rawuh — Integrated Billing & POS Management System  
**Repository:** `sistem_kasir_PS-main`  
**Phase:** G3-A (Audit & Architecture Design — Final Approval Corrections)  
**Status Gate:** G2 APPROVED — ARCHITECTURE EVALUATION (NO CODE MODIFICATIONS)  
**Date:** November 2026  

---

## 1. DAFTAR KEPUTUSAN ARSITEKTUR RESMI (UPDATED DECISION REGISTER)

| ID Keputusan | Judul Keputusan | Pilihan Terpilih | Justifikasi Teknis | Dampak Scope |
| :---: | :--- | :--- | :--- | :---: |
| **DEC-01** | **Pemisahan Entitas Database** | Alternatif B (Decoupled 3-Tier: Station ↔ Mapping ↔ Physical Registry) | Memisahkan operasional kasir dari hardware teknis IoT. Menjamin histori transaksi tidak rusak saat hardware diganti. Zero-impact pada skema `devices` G2. | Gate G3-A & G3-B |
| **DEC-02** | **Hardware Station PlayStation** | Smart TV Samsung via Local Network (Direct IP) | Dilarang keras menggunakan smart relay pada TV demi mencegah kerusakan eMMC, power supply, panel TV, dan booting lambat. Seluruh Smart TV toko menggunakan merek SAMSUNG. | Gate G3 s/d G5 |
| **DEC-03** | **Hardware Station Meja Biliar** | Shelly 1PM Gen3 Mini Relay pada Lampu Meja | Mengontrol aliran listrik lampu gantung meja biliar via REST RPC lokal tanpa cloud. Beban lampu (50W-300W) sangat aman pada kapasitas relay 8A. | Gate G3 s/d G5 |
| **DEC-04** | **Batasan Implementasi Gate G3** | Registrasi, Identitas Jaringan, Validasi & Mapping Saja | **TIDAK MEMASUKKAN** kontrol hardware, pairing aktif, atau network probe sebagai syarat G3. Semua otomasi kontrol masuk G4/G5. | Gate G3-B |
| **DEC-05** | **Penundaan Probe & Kontrol ke G4** | Pindahkan Test Connection, Pairing, dan Eksekusi Perintah ke Gate G4 | Driver fisik dan token handshake memerlukan unit fisik nyata di lokasi. Kontrak API G4 didokumentasikan sebagai future contract. | Gate G4 |
| **DEC-06** | **Status Koneksi Awal G3** | Default `'UNVERIFIED'` | Menjaga kejujuran sistem. Dilarang menampilkan `ONLINE` tanpa bukti probe jaringan nyata dari Gate G4. | Gate G3-B |
| **DEC-07** | **Status Pairing Awal G3** | Default `'UNVERIFIED'` | Tidak mengklaim `NOT_REQUIRED` sebelum diverifikasi modelnya pada PoC Gate G4. | Gate G3-B |
| **DEC-08** | **Fokus Protokol TV** | Samsung (Tizen) Khusus untuk Seluruh Smart TV | Standarisasi hardware pada Samsung Smart TV menyederhanakan arsitektur protokol ke Tizen WS/REST tanpa vendor driver ganda. | Gate G4 |
| **DEC-09** | **Normalisasi MAC & Konflik IP** | Normalisasi Huruf Kapital Titik Dua (`AA:BB:CC:DD:EE:FF`) Sebelum Validasi | Mencegah anomali duplikasi akibat perbedaan format input. Validasi IP menolak tabrakan IP dengan perangkat aktif lain di LAN. | Gate G3-B |
| **DEC-10** | **Invarian Concurrency Mapping** | Single Active Mapping & Running Billing Lock (Atomic) | Satu perangkat fisik dilarang aktif di 2 stasiun. Satu stasiun hanya boleh 1 perangkat aktif per role. Ditolak keras jika billing aktif. | Gate G3-B |
| **DEC-11** | **Database-Level Active Uniqueness** | Virtual Generated Columns (`uq_active_physical_device` & `uq_active_station_role`) | Menegakkan keunikan pemetaan aktif di level mesin MySQL (kompatibel 5.7 & 8.0) di samping proteksi lock aplikasi. | Gate G3-B |
| **DEC-12** | **Perlindungan Audit Trail Abadi** | `ON DELETE RESTRICT` pada `station_id` & `physical_device_id` | Menghapus `ON DELETE CASCADE`. Stasiun yang memiliki histori mapping hardware dilarang keras dihapus agar audit log tidak lenyap. | Gate G3-B |
| **DEC-13** | **Pemisahan Aktor & Alasan Audit** | `attached_by_user_id`, `detached_by_user_id`, `attach_notes`, `detach_notes` | Mencatat rantai mutasi hardware secara terperinci. Pergantian perangkat (*replacement*) tidak menghapus histori mapping lama. | Gate G3-B |
| **DEC-14** | **Optional Hardware Binding** | Stasiun Tetap Boleh Beroperasi Tanpa Mapping Hardware | Stasiun billing dapat dibuat dan dijalankan manual tanpa hardware. Ketika dipetakan, validasi peran stasiun diterapkan ketat. | Gate G3-B |
| **DEC-15** | **Standar Autentikasi & Stack** | Laravel Web Session + CSRF + RBAC Eksisting | Menggunakan stack eksisting tanpa Sanctum atau dependency eksternal baru. OWNER read-only, ADMIN konfigurasi, KASIR operasional. | Gate G3-B |
| **DEC-16** | **Kebijakan Hardware Offline** | Billing Tetap Berjalan dengan Peringatan Visual Kuning | Kasir tetap dapat melayani sewa meskipun hardware offline sementara, mencegah kerugian omset toko. | Gate G3 s/d G5 |

---

## 2. MATRIKS RISIKO & STRATEGI MITIGASI FINAL

### 2.1 Analisis Risiko & Solusi Desain

#### 1. Risiko Kehilangan Histori Audit Akibat Cascade Delete Stasiun
- **Potensi Masalah:** Jika Admin menghapus stasiun di tabel `devices` dan foreign key menggunakan `CASCADE`, seluruh histori pemetaan Smart TV dan Shelly di masa lalu akan terhapus permanen.
- **Solusi Final:** Menggunakan `ON DELETE RESTRICT`. MySQL akan menolak penghapusan stasiun jika terdapat record pemetaan terkait. Stasiun hanya dapat dinonaktifkan atau diarsipkan.

#### 2. Risiko Race Condition & Double Active Mapping di MySQL
- **Potensi Masalah:** Dua request konkuren mencoba memetakan TV yang sama ke dua stasiun berbeda, atau memetakan dua TV ke stasiun yang sama.
- **Solusi Final:**
  - Di level MySQL: Kolom virtual `active_physical_device_id` dan `active_station_role` dengan indeks unik. Karena nilai `NULL` diabaikan oleh indeks unik MySQL namun nilai non-null diverifikasi keunikannya, MySQL menjamin integritas bahkan jika terjadi kegagalan logika aplikasi.
  - Di level Aplikasi: Transaksi atomic dengan `lockForUpdate()` pada baris stasiun dan perangkat fisik.

#### 3. Risiko Kerusakan Smart TV Akibat Smart Relay Listrik
- **Potensi Masalah:** Relay memutus listrik TV secara paksa, menyebabkan memori internal rusak dan umur lampu TV memendek.
- **Solusi Final:** Stasiun PlayStation hanya diizinkan menerima mapping `SMART_TV` dengan peran `DISPLAY_TV`. Validasi backend dan form UI menolak keras pemasangan `SMART_RELAY` ke stasiun PlayStation.

#### 4. Risiko Keselamatan Listrik Meja Biliar
- **Potensi Masalah:** Beban berlebih atau korsleting listrik AC 220V pada instalasi saklar lampu biliar.
- **Solusi Final:** Shelly 1PM Gen3 Mini memiliki proteksi internal terhadap panas dan beban berlebih. Instalasi fisik ditempatkan di dalam *junction box* tahan api dan terhubung ke MCB cabang.

---

## 3. STATUS KEPUTUSAN PENGGUNA (HUMAN APPROVAL STATUS)

1. **Kebijakan Hardware Offline:** **APPROVED (Opsi A)** — Billing kasir tetap berjalan normal dengan peringatan manual di kartu stasiun jika hardware terdeteksi offline.
2. **Kesesuaian Relay Biliar:** **PENDING CONFIRMATION FISIK** — Jumlah meja fisik di lokasi Sugeng Rawuh 2 akan dicatat sebelum pengadaan unit Shelly pada Gate G4.
3. **Inventaris TV Fisik:** **SAMSUNG ONLY** — Seluruh Smart TV distandarisasi ke merek Samsung (Tizen). Nomor model spesifik (e.g. AU7000) akan didaftarkan saat unit diinspeksi.
