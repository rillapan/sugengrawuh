# SUGENG RAWUH — G3-A HARDWARE & NETWORK ARCHITECTURE DESIGN (FINAL APPROVED REVISION)
**Document ID:** SR-G3A-ARCH-002-REV2  
**Project:** Sugeng Rawuh — Integrated Billing & POS Management System  
**Repository:** `sistem_kasir_PS-main`  
**Phase:** G3-A (Audit & Architecture Design — Final Approval Corrections)  
**Status Gate:** G2 APPROVED — ARCHITECTURE DESIGN ONLY (NO CODE MODIFICATIONS)  
**Date:** November 2026  

---

## 1. PRINSIP DASAR & BATASAN PHASE SYSTEM

Sugeng Rawuh beroperasi dengan arsitektur **Local-First, Zero Cloud Dependency, dan High Availability**. Seluruh kendali stasiun, penghitungan billing, dan interaksi hardware berlangsung di dalam Local Area Network (LAN) yang diorkestrasi oleh Router Lokal tanpa ketergantungan internet eksternal.

### 1.1 Batasan Tegas Antar-Gate (Phase Scope Boundaries)

```text
+---------------------------------------------------------------------------------------------------+
| GATE G3 (G3-A Design / G3-B Implementation):                                                      |
| • Physical Device Registry (CRUD Smart TV & Smart Relay)                                          |
| • Konfigurasi Identitas Jaringan (IP Address, Normalisasi MAC Address)                            |
| • Station Mapping Opsional & Aturan Kompatibilitas Stasiun                                        |
| • Validasi Format, Deteksi Konflik IP/MAC, Concurrency Lock, & Complete Audit Trail               |
| • Status Koneksi Awal: 'UNVERIFIED' | Status Pairing Awal: 'UNVERIFIED'                          |
| • Database Protection: ON DELETE RESTRICT pada station_id untuk menjaga audit trail               |
| • Database Uniqueness: Virtual Column Index untuk menjamin 1 Active Mapping per device & role     |
| • STRICTLY NO: Hardware Control, Network Probe/Ping, Active Pairing, Command Execution            |
+---------------------------------------------------------------------------------------------------+
                                                │
                                                ▼
+---------------------------------------------------------------------------------------------------+
| GATE G4 (Hardware Connectivity & Control PoC):                                                    |
| • Implementasi Test Connection / Network Probe (Socket/HTTP)                                      |
| • Implementasi Pairing Protokol (Samsung Tizen Token Handshake, Shelly RPC)            |
| • Pengujian Driver Fisik saat TV sedang aktif menampilkan input HDMI PlayStation                  |
| • Verifikasi Kemampuan Nyata Hardware: Power ON/OFF, Volume/Mute, Relay Switch ON/OFF             |
| • Validasi Jumlah Aktual Relay Lampu Biliar sesuai layout kelistrikan toko                        |
+---------------------------------------------------------------------------------------------------+
                                                │
                                                ▼
+---------------------------------------------------------------------------------------------------+
| GATE G5 (Billing-to-Hardware Automation):                                                         |
| • Otomatisasi Terintegrasi: Billing Mulai -> Lampu/TV Menyala Otomatis                            |
| • Billing Selesai / Expired -> TV Mute/Standby, Lampu Meja Biliar Padam Otomatis                  |
| • Penanganan Kegagalan Hardware Otomatis & Prosedur Manual Kasir                                  |
+---------------------------------------------------------------------------------------------------+
```

---

## 2. KEPUTUSAN ARSITEKTUR HARDWARE FINAL

### 2.1 Kategori Station PlayStation (PS4 / PS5)
- **Perangkat Fisik:** Smart TV Samsung (Tizen OS) terhubung langsung ke router lokal via Wi-Fi (5 GHz / 2.4 GHz) atau LAN Ethernet. Seluruh Smart TV toko menggunakan merek SAMSUNG (tidak ada TV Coocaa).
- **Kebijakan Smart Relay:** **STRICTLY PROHIBITED (DILARANG KERAS)**. Tidak boleh menggunakan smart relay untuk memutus daya listrik Smart TV karena merusak memori internal (eMMC), power supply unit (PSU), panel layar, serta memperlambat booting.
- **Protokol Vendor:**
  - Smart TV menggunakan protokol Samsung Tizen WebSocket / REST API lokal pada Gate G4.
- **Kontrol Saat HDMI Aktif:** Pada G4, kemampuan kontrol TV (Mute, Volume, Standby) wajib diuji saat TV sedang menerima sinyal HDMI dari konsol PS4/PS5.

### 2.2 Kategori Station Meja Biliar
- **Perangkat Fisik:** Shelly 1PM Gen3 Mini Smart Relay untuk mengontrol aliran listrik lampu meja biliar.
- **Jalur Jaringan:** Wi-Fi 2.4 GHz terhubung ke router lokal.
- **Jumlah Relay Aktual:** Jumlah unit relay aktual dikonfirmasi berdasarkan jumlah meja dan instalasi kelistrikan (1 relay per 1 lampu meja biliar mandiri atau instalasi grup).
- **Protokol:** Shelly Gen3 RPC over HTTP (`/rpc/Switch.Set`) pada jaringan lokal tanpa cloud.
- **Keselamatan Listrik:** Batas beban Shelly 1PM Gen3 Mini adalah 8A (~1700 Watt pada 220V). Lampu biliar berkisar 50W – 300W (sangat aman). Pemasangan fisik wajib di dalam box tahan api dan terhubung ke MCB.

---

## 3. INVARIAN BISNIS & ATURAN PEMETAAN (MAPPING INVARIANTS)

Sistem memberlakukan aturan keras (*strict invariants*) pada tingkat database dan aplikasi:

1. **Station Beroperasi Opsional Tanpa Hardware (Optional Hardware Binding):**
   - Stasiun billing (`devices`) **tetap dapat dibuat dan beroperasi normal** tanpa harus memiliki pemetaan hardware. Jika hardware belum dipetakan, alur billing kasir berjalan 100% manual seperti pada G2.
2. **Aturan Kompatibilitas Stasiun Saat Mapping Dibuat:**
   - Stasiun yang melayani tipe PlayStation (`playstations.nama` mengandung 'PS4' atau 'PS5') **hanya dapat dipetakan** ke perangkat fisik berkategori `SMART_TV` dengan peran `mapping_role = 'DISPLAY_TV'`.
   - Stasiun yang melayani tipe Biliar (`playstations.nama` mengandung 'BILLIARD') **hanya dapat dipetakan** ke perangkat fisik berkategori `SMART_RELAY` dengan peran `mapping_role = 'LIGHT_RELAY'`.
   - Pelanggaran kompatibilitas ditolak keras oleh validator sistem.
3. **Aturan Eksklusivitas Perangkat Fisik (Single Active Mapping):**
   - Satu perangkat fisik **TIDAK BOLEH** memiliki lebih dari satu pemetaan berstatus `ACTIVE` di seluruh sistem pada waktu yang sama.
   - Ditegakkan secara ganda melalui indeks virtual unik basis data (`uq_active_physical_device`) dan verifikasi `lockForUpdate`.
4. **Aturan Eksklusivitas Role Stasiun (Single Active Role per Station):**
   - Satu stasiun **TIDAK BOLEH** memiliki lebih dari satu pemetaan berstatus `ACTIVE` untuk `mapping_role` yang sama (misal tidak boleh ada 2 Smart TV aktif di Station 01).
   - Ditegakkan secara ganda melalui indeks virtual unik basis data (`uq_active_station_role`).
5. **Proteksi Concurrency Transaksi Aktif:**
   - Pemetaan stasiun **DILARANG KERAS diubah, diganti, atau dilepas** jika stasiun tersebut sedang memiliki sesi billing aktif (`devices.status == 'Digunakan'` atau transaksi dengan `billing_status == 'RUNNING'`).
6. **Perlindungan Audit Trail Abadi (Historical Mapping Protection):**
   - Foreign key `station_device_mappings.station_id` menggunakan **`ON DELETE RESTRICT`**.
   - Penghapusan stasiun ditolak keras jika stasiun memiliki histori mapping hardware.
   - Pelepasan perangkat menggunakan mekanisme **soft-detach**: `mapping_status = 'INACTIVE'`, `detached_at = now()`, mencatat `detached_by_user_id` dan `detach_notes`.
   - Pemasangan mencatat `attached_by_user_id` dan `attach_notes`. Penggantian perangkat (*replacement*) tidak menghapus histori lama.
7. **Status Awal Jujur Tanpa Klaim Fiktif:**
   - Status koneksi awal: `'UNVERIFIED'` (bukan `ONLINE`).
   - Status pairing awal: `'UNVERIFIED'` (tidak mengklaim `NOT_REQUIRED` sebelum model diverifikasi di G4).
8. **Kebijakan Hardware Offline (Human Approved Decision):**
   - Jika hardware terdeteksi offline / belum terverifikasi, **sesi billing kasir tetap diizinkan berjalan** dengan menampilkan peringatan visual kuning di kartu stasiun (*"Hardware offline, kontrol TV/Lampu dilakukan manual oleh kasir"*), sehingga operasional kasir tidak terblokir.
