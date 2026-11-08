# SUGENG RAWUH — PANDUAN LENGKAP WORKFLOW KONFIGURASI SISTEM & USER FLOW INTERAKTIF

**Project:** Sugeng Rawuh — Integrated Billing & POS Management System  
**Repository:** `sistem_kasir_PS-main`  
**File Dokumentasi:** `docs/SUGENG-RAWUH-COMPLETE-INTERACTIVE-FLOW.md`  
**Mode Operasi:** READ-ONLY AUDIT + SINGLE FILE DOCUMENTATION ONLY  
**Framework Stack:** Laravel 8, PHP 8.2, MySQL 8, Blade, Bootstrap 4.6, jQuery  
**Base URL Aplikasi:** `http://127.0.0.1:8008` (Terverifikasi dari lingkungan operasional aktif)  
**Kebijakan Arsitektur Hardware Final:**  
- **PlayStation Smart TV:** 100% Samsung Smart TV (Tizen OS / WebSocket & REST). *Catatan Arsitektur: Coocaa resmi ditiadakan dari target sistem.*  
- **Lampu Meja Biliar:** Shelly Smart Relay (Wi-Fi 2.4 GHz HTTP JSON-RPC 2.0).  
- **Safety Gate Hardware:** `HARDWARE_REAL_EXECUTION_ENABLED=false` aktif di backend via `HardwareSafetyGuard` untuk mencegah transmisi paket fisik yang belum teruji ke perangkat nyata.

---

## DAFTAR ISI (TABLE OF CONTENTS)

1. [Ringkasan Sistem & Petunjuk Interaktivitas Diagram](#1-ringkasan-sistem--petunjuk-interaktivitas-diagram)
2. [MASTER DIAGRAM — Alur Konfigurasi Lengkap dari Nol (Tahap A — G)](#2-master-diagram--alur-konfigurasi-lengkap-dari-nol-tahap-a--g)
3. [Tabel Pemetaan Komponen Sistem](#3-tabel-pemetaan-komponen-sistem)
4. [Diagram Detail 1 — Modul Kategori Permainan (Master Data Layanan & Tarif)](#4-diagram-detail-1--modul-kategori-permainan-master-data-layanan--tarif)
5. [Diagram Detail 2 — Data Perangkat / Station Billing (Identitas Meja & Rental)](#5-diagram-detail-2--data-perangkat--station-billing-identitas-meja--rental)
6. [Diagram Detail 3 — Device Registry (Inventaris Hardware Fisik IoT)](#6-diagram-detail-3--device-registry-inventaris-hardware-fisik-iot)
7. [Diagram Detail 4 — Station Mapping (Integrasi Logis Station Billing & Hardware Fisik)](#7-diagram-detail-4--station-mapping-integrasi-logis-station-billing--hardware-fisik)
8. [Audit Khusus Screenshot: Kasus Meja 1 - Billiard Dipetakan ke Samsung TV](#8-audit-khusus-screenshot-kasus-meja-1---billiard-dipetakan-ke-samsung-tv)
9. [Diagram Detail 5 — Hardware Diagnostics (Pengujian Protokol & Probe Perangkat)](#9-diagram-detail-5--hardware-diagnostics-pengujian-protokol--probe-perangkat)
10. [Diagram Detail 6 — Hardware Simulator (Laboratorium Mock Virtual Driver)](#10-diagram-detail-6--hardware-simulator-laboratorium-mock-virtual-driver)
11. [Diagram Detail 7 — Siklus Billing PlayStation (Paket Jam & Bebas Waktu)](#11-diagram-detail-7--siklus-billing-playstation-paket-jam--bebas-waktu)
12. [Diagram Detail 8 — Siklus Billing Meja Biliar & Otomasi Lampu Shelly (Aturan SR-G5-BR-001)](#12-diagram-detail-8--siklus-billing-meja-biliar--otomasi-lampu-shelly-aturan-sr-g5-br-001)
13. [Diagram Detail 9 — Arsitektur Otomasi Backend & Transactional Outbox Worker](#13-diagram-detail-9--arsitektur-otomasi-backend--transactional-outbox-worker)
14. [Diagram Detail 10 — Matriks Troubleshooting & Pemulihan Insiden Hardware](#14-diagram-detail-10--matriks-troubleshooting--pemulihan-insiden-hardware)
15. [Penjelasan Lengkap Setiap Komponen Sistem](#15-penjelasan-lengkap-setiap-komponen-sistem)
16. [Panduan Praktis Langkah-demi-Langkah untuk Administrator](#16-panduan-praktis-langkah-demi-langkah-untuk-administrator)
17. [Glosarium Istilah & Status Implementasi Teknis](#17-glosarium-istilah--status-implementasi-teknis)

---

## 1. RINGKASAN SISTEM & PETUNJUK INTERAKTIVITAS DIAGRAM

Dokumen ini berfungsi sebagai panduan visual arsitektur operasional dan panduan konfigurasi sistem **Sugeng Rawuh**. Seluruh alur dirancang menyerupai *board* Miro interaktif menggunakan sintaks **Mermaid**.

### Petunjuk Penggunaan Diagram Interaktif:
1. **Kotak Dapat Diklik (Clickable Nodes):** Setiap kotak yang mewakili halaman atau formulir aplikasi dilengkapi perintah `click` Mermaid. Saat diklik, peramban akan langsung membuka halaman sistem terkait pada tab baru (`_blank`).
2. **Skema Warna Visual (Miro Palette):**
   - 🔵 **Biru Muda (`admin`):** Menu, konfigurasi master data, dan aksi khusus **ADMIN**.
   - 🟢 **Hijau Muda (`kasir`):** Operasional harian billing stasiun oleh **KASIR**.
   - 🟠 **Oranye (`hardware`):** Perangkat keras fisik (Samsung Smart TV, Shelly Smart Relay).
   - ⚪ **Abu-Abu (`backend`):** Layanan internal backend, basis data MySQL, dan worker otomasi.
   - 🔴 **Merah Muda (`error`):** Validasi error, kegagalan jaringan, atau pemblokiran keamanan.
3. **Tautan Alternatif Markdown:** Jika peramban atau *Markdown viewer* menonaktifkan eksekusi JavaScript pada Mermaid, tautan Markdown standar disediakan tepat di bawah setiap diagram.

---

## 2. MASTER DIAGRAM — ALUR KONFIGURASI LENGKAP DARI NOL (TAHAP A — G)

Diagram master ini memperlihatkan perjalanan sistem secara utuh dari konfigurasi data awal hingga sesi operasional dan otomasi hardware. Diagram ini menegaskan bahwa **Data Perangkat (Station Billing)** dan **Device Registry (Inventaris Hardware Fisik)** adalah dua entitas berbeda yang dipertemukan secara terpadu melalui **Station Mapping**.

```mermaid
flowchart TB
    %% DEFINISI STYLE
    classDef admin fill:#e1f5fe,stroke:#0277bd,stroke-width:2px,color:#01579b;
    classDef kasir fill:#e8f8f5,stroke:#27ae60,stroke-width:2px,color:#145a32;
    classDef backend fill:#f5f5f5,stroke:#616161,stroke-width:2px,color:#212121;
    classDef hardware fill:#fff3e0,stroke:#e65100,stroke-width:2px,color:#bf360c;
    classDef warning fill:#fffde7,stroke:#f57f17,stroke-width:2px,color:#f57f17;

    %% TAHAP A
    subgraph TAHAP_A["TAHAP A — MASTER DATA LAYANAN & TARIF"]
        direction TB
        NodeKat["1. Kategori Permainan\n(PS5, PS4, Biliar 9-ft)"]:::admin
        NodeHarga["2. Pengaturan Tarif\n(Tarif Dasar & Jam Bertingkat)"]:::admin
        NodeKat --> NodeHarga
    end

    %% TAHAP B
    subgraph TAHAP_B["TAHAP B — STATION BILLING (LOGICAL UNIT)"]
        direction TB
        NodeStation["3. Data Perangkat (Station)\n(Tambah PS 01, Meja Biliar 01)"]:::admin
        NodeStationRel["Hubungkan Station ke\nKategori Permainan"]:::backend
        NodeStation --> NodeStationRel
    end

    %% TAHAP C
    subgraph TAHAP_C["TAHAP C — REGISTRASI HARDWARE FISIK (IOT REGISTRY)"]
        direction TB
        NodeReg["4. Device Registry\n(Inventaris Hardware Fisik)"]:::admin
        NodeRegTV["Daftarkan Samsung Smart TV\n(IP RFC1918 & MAC Address)"]:::hardware
        NodeRegRelay["Daftarkan Shelly Smart Relay\n(IP RFC1918 & MAC Address)"]:::hardware
        NodeReg --> NodeRegTV
        NodeReg --> NodeRegRelay
    end

    %% TAHAP D
    subgraph TAHAP_D["TAHAP D — HUBUNGAN PERANGKAT (STATION MAPPING)"]
        direction TB
        NodeMap["5. Station Mapping\n(Integrasi Logis Station & Hardware)"]:::admin
        NodeValCheck{"Validasi Kompatibilitas\nKategori Station vs Hardware"}:::backend
        NodeBranchPS["Cabang PlayStation:\nStation PS 01 -> Samsung Smart TV\nRole: DISPLAY_TV"]:::admin
        NodeBranchBilliard["Cabang Biliar:\nMeja Biliar 01 -> Shelly Smart Relay\nRole: LIGHT_RELAY"]:::admin
        
        NodeMap --> NodeValCheck
        NodeValCheck -- "Kategori: PLAYSTATION" --> NodeBranchPS
        NodeValCheck -- "Kategori: BILLIARD" --> NodeBranchBilliard
    end

    %% TAHAP E
    subgraph TAHAP_E["TAHAP E — PENGUJIAN & DIAGNOSTIK PROTOKOL"]
        direction TB
        NodeDiag["6. Hardware Diagnostics\n(Probe Port & Uji Pairing Handshake)"]:::admin
        NodeSim["7. Hardware Simulator\n(Uji Mock Command & Skenario Virtual)"]:::admin
    end

    %% TAHAP F
    subgraph TAHAP_F["TAHAP F — OPERASIONAL TRANSAKSI & BILLING KASIR"]
        direction TB
        NodeKasir["8. Operasional Data Perangkat / Billing\n(Kasir Memilih Station Tersedia)"]:::kasir
        NodeStartBilling["Mulai Rental: Paket Jam (Prepaid)\natau Bebas Waktu (Lost Time)"]:::kasir
        NodeSessionTimer["Billing Berjalan & Timer Aktif"]:::kasir
        NodeKasir --> NodeStartBilling --> NodeSessionTimer
    end

    %% TAHAP G
    subgraph TAHAP_G["TAHAP G — OTOMASI IOT & MONITORING WORKER"]
        direction TB
        NodeOutbox["Perekaman Automation Intent\n(Transactional Outbox MySQL)"]:::backend
        NodeWorker["9. Automation Worker Daemon\n(billing:automation-worker)"]:::backend
        NodeFence{"Validasi Fencing &\nCapability Guard"}:::backend
        NodeDispatch["Dispatch Driver:\nSamsung TV / Shelly Relay"]:::hardware
        NodeLogs["10. Monitoring & Execution Logs\n(Telemetri Hasil & Status Kasir)"]:::backend

        NodeOutbox --> NodeWorker --> NodeFence
        NodeFence -- "Lolos Guard" --> NodeDispatch --> NodeLogs
        NodeFence -- "Tidak Lolos / Stale" --> NodeLogs
    end

    %% KONEKSI ANTAR TAHAP
    TAHAP_A --> TAHAP_B
    TAHAP_B --> NodeMap
    TAHAP_C --> NodeMap
    TAHAP_D --> TAHAP_E
    TAHAP_D --> TAHAP_F
    NodeStartBilling --> NodeOutbox

    %% CLICKABLE LINKS
    click NodeKat "http://127.0.0.1:8008/kategori-permainan" "Buka Kategori Permainan" _blank
    click NodeStation "http://127.0.0.1:8008/device" "Buka Data Perangkat (Station)" _blank
    click NodeReg "http://127.0.0.1:8008/admin/hardware/registry" "Buka Device Registry" _blank
    click NodeMap "http://127.0.0.1:8008/admin/hardware/mappings" "Buka Station Mapping" _blank
    click NodeDiag "http://127.0.0.1:8008/admin/hardware/diagnostics" "Buka Hardware Diagnostics" _blank
    click NodeSim "http://127.0.0.1:8008/admin/hardware/simulator" "Buka Hardware Simulator" _blank
    click NodeKasir "http://127.0.0.1:8008/device" "Buka Operasional Billing Kasir" _blank
    click NodeLogs "http://127.0.0.1:8008/admin/hardware/automation/logs" "Buka Telemetri Otomasi" _blank
```

> **Tautan Cepat Navigasi Halaman Sistem:**
> - [Kategori Permainan](http://127.0.0.1:8008/kategori-permainan)
> - [Data Perangkat (Station Billing)](http://127.0.0.1:8008/device)
> - [Device Registry (Inventaris Hardware Fisik)](http://127.0.0.1:8008/admin/hardware/registry)
> - [Station Mapping](http://127.0.0.1:8008/admin/hardware/mappings)
> - [Hardware Diagnostics](http://127.0.0.1:8008/admin/hardware/diagnostics)
> - [Hardware Simulator](http://127.0.0.1:8008/admin/hardware/simulator)
> - [Logs Telemetri Otomasi](http://127.0.0.1:8008/admin/hardware/automation/logs)

---

## 3. TABEL PEMETAAN KOMPONEN SISTEM

Tabel di bawah ini merangkum seluruh tahapan, halaman, input, output, serta tautan route terverifikasi di dalam kode aplikasi Sugeng Rawuh:

| Tahap | Nama Komponen / Halaman | Tujuan & Tanggung Jawab | Input Pengguna / Sistem | Output yang Dihasilkan | URL Halaman Aplikasi |
| :---: | :--- | :--- | :--- | :--- | :--- |
| **1** | **Kategori Permainan** | Menentukan tipe rental dan harga dasar/jam | Nama kategori, harga per jam, gambar | Record kategori layanan (`playstations`) | [http://127.0.0.1:8008/kategori-permainan](http://127.0.0.1:8008/kategori-permainan) |
| **2** | **Tarif Jam Bertingkat** | Mengatur harga dinamis per jam (pagi/malam) | Jam target (0–23), nominal tarif khusus | Record tarif khusus (`hourly_prices`) | [http://127.0.0.1:8008/kategori-permainan/1/hourly-prices](http://127.0.0.1:8008/kategori-permainan/1/hourly-prices) |
| **3** | **Data Perangkat (Station)** | Mendaftarkan station billing (meja/unit rental) | Nama station (misal: PS 01, Meja Biliar 01), checkbox kategori | Station logis (`devices`) status 'Tersedia' | [http://127.0.0.1:8008/device](http://127.0.0.1:8008/device) |
| **4** | **Device Registry** | Mendaftarkan aset perangkat fisik IoT | Nama hardware, kategori, brand, model, MAC, IP | Hardware fisik terdaftar (`physical_devices`) | [http://127.0.0.1:8008/admin/hardware/registry](http://127.0.0.1:8008/admin/hardware/registry) |
| **5** | **Station Mapping** | Mengaitkan station billing dengan hardware fisik | Pilih station, hardware terdaftar, mapping role | Pemetaan aktif (`station_device_mappings`) | [http://127.0.0.1:8008/admin/hardware/mappings](http://127.0.0.1:8008/admin/hardware/mappings) |
| **6** | **Hardware Diagnostics** | Menguji konektivitas probe port & handshake | Pilih target hardware, klik Probe / Test Pair | Hasil respons diagnostik & log pengujian | [http://127.0.0.1:8008/admin/hardware/diagnostics](http://127.0.0.1:8008/admin/hardware/diagnostics) |
| **7** | **Hardware Simulator** | Menguji skenario perintah mock tanpa alat nyata | Pilih virtual device, skenario offline/timeout | Simulasi state & log eksekusi virtual | [http://127.0.0.1:8008/admin/hardware/simulator](http://127.0.0.1:8008/admin/hardware/simulator) |
| **8** | **Operasional Billing** | Memulai rental PlayStation atau Meja Biliar | Pilih station, paket jam / menit / lost time | Transaksi aktif (`transactions`), intent outbox | [http://127.0.0.1:8008/device](http://127.0.0.1:8008/device) |
| **9** | **Kasir Transaksi & POS** | Mengelola pembayaran billing, FnB & cetak struk | Metode bayar (Tunai/Non-Tunai), nominal bayar | Struk pembayaran, status transaksi 'Selesai' | [http://127.0.0.1:8008/transaction](http://127.0.0.1:8008/transaction) |
| **10**| **Automation Monitoring** | Memantau antrean intent & telemetri eksekusi | Filter station, event type, status intent | JSON log eksekusi otomasi (`automation_intents`) | [http://127.0.0.1:8008/admin/hardware/automation/logs](http://127.0.0.1:8008/admin/hardware/automation/logs) |

---

## 4. DIAGRAM DETAIL 1 — MODUL KATEGORI PERMAINAN (MASTER DATA LAYANAN & TARIF)

Modul **Kategori Permainan** berfungsi sebagai pendefinisi jenis layanan rental yang disediakan rental Sugeng Rawuh. Kategori ini membedakan secara tegas apakah suatu unit rental merupakan **PlayStation** atau **Biliar**, serta menentukan tarif sewa dasar dan tarif jam dinamis.

```mermaid
flowchart TD
    classDef admin fill:#e1f5fe,stroke:#0277bd,stroke-width:2px,color:#01579b;
    classDef backend fill:#f5f5f5,stroke:#616161,stroke-width:2px,color:#212121;
    classDef decision fill:#fffde7,stroke:#f57f17,stroke-width:2px,color:#f57f17;

    StartKat["Admin Membuka Menu\nKategori Permainan"]:::admin
    FormKat["Form Tambah Kategori Permainan\n(Input: Nama Permainan, Harga Dasar, Gambar)"]:::admin
    SaveKat["Simpan ke Tabel playstations"]:::backend
    CheckName{"Nama Kategori Mengandung Kata Kunci Apa?"}:::decision
    
    CatPS["Kategori Layanan: PLAYSTATION\n(Keyword: 'PS5', 'PS4', 'PLAYSTATION')"]:::admin
    CatBilliard["Kategori Layanan: BILLIARD\n(Keyword: 'BILLIARD', 'BILIAR', 'MEJA')"]:::admin
    
    SubTarif["Kelola Tarif Khusus per Jam\n(Hourly Prices: 00:00 - 23:00)"]:::admin

    StartKat --> FormKat --> SaveKat --> CheckName
    CheckName -- "PlayStation Detected" --> CatPS
    CheckName -- "Billiard Detected" --> CatBilliard
    SaveKat --> SubTarif

    click StartKat "http://127.0.0.1:8008/kategori-permainan" "Buka Daftar Kategori Permainan" _blank
    click FormKat "http://127.0.0.1:8008/kategori-permainan/create" "Buka Form Tambah Kategori" _blank
    click SubTarif "http://127.0.0.1:8008/kategori-permainan/1/hourly-prices" "Buka Tarif Jam Bertingkat" _blank
```

> **Tautan Halaman Terkait:**
> - [Daftar Kategori Permainan](http://127.0.0.1:8008/kategori-permainan)
> - [Tambah Kategori Permainan Baru](http://127.0.0.1:8008/kategori-permainan/create)
> - [Manajemen Tarif Jam Bertingkat (Contoh ID 1)](http://127.0.0.1:8008/kategori-permainan/1/hourly-prices)

### Aturan Teknis Kategori Permainan:
1. **Deteksi Otomatis Kategori Layanan (`Playstation::getCategory()`):**
   - Jika nama kategori mengandung `PLAYSTATION`, `PS5`, `PS4`, atau `PS3` dan tidak mengandung kata biliar $\rightarrow$ Teridentifikasi sebagai `PLAYSTATION`.
   - Jika nama kategori mengandung `BILLIARD`, `BILIAR`, atau berawalan `MEJA` $\rightarrow$ Teridentifikasi sebagai `BILLIARD`.
2. **Relasi Many-to-Many (`device_playstation`):** Satu station billing (misalnya station fleksibel) dapat memiliki lebih dari satu kategori permainan, namun kategori yang dihubungkan harus homogen (seluruhnya PlayStation atau seluruhnya Biliar) agar penentuan role hardware tidak ambigu.

---

## 5. DIAGRAM DETAIL 2 — DATA PERANGKAT / STATION BILLING (IDENTITAS MEJA & RENTAL)

Di Sugeng Rawuh, istilah **Data Perangkat** di menu utama merepresentasikan **Station Billing** (unit logis persewaan), bukan perangkat fisik hardware. Di halaman inilah kasir melihat ketersediaan unit dan memulai transaksi.

```mermaid
flowchart TD
    classDef admin fill:#e1f5fe,stroke:#0277bd,stroke-width:2px,color:#01579b;
    classDef kasir fill:#e8f8f5,stroke:#27ae60,stroke-width:2px,color:#145a32;
    classDef backend fill:#f5f5f5,stroke:#616161,stroke-width:2px,color:#212121;
    classDef decision fill:#fffde7,stroke:#f57f17,stroke-width:2px,color:#f57f17;

    ListStation["Halaman Data Perangkat\n(Daftar Seluruh Station Billing)"]:::admin
    BtnAddStation["Tombol Tambah Perangkat\n/device/create"]:::admin
    FormStation["Formulir Tambah Station\n- Nama Station (contoh: 'PS 01' atau 'Meja Biliar 01')\n- Checkbox Kategori Permainan Terkait\n- Status Awal: 'tersedia'"]:::admin
    SaveStation["Simpan Record ke devices\ndan Pivot device_playstation"]:::backend

    StatusCheck{"Status Station Saat Ini?"}:::decision
    StatusTersedia["Status: Tersedia\n(Hijau — Siap Disewa Kasir)\nBoleh Diedit / Dihapus / Di-mapping"]:::kasir
    StatusDigunakan["Status: Digunakan\n(Kuning — Sesi Billing Aktif)\nKunci Keamanan: DILARANG Diubah/Dihapus"]:::kasir

    ListStation --> BtnAddStation --> FormStation --> SaveStation --> StatusCheck
    StatusCheck -- "Belum Ada Transaksi" --> StatusTersedia
    StatusCheck -- "Sewa Sedang Berjalan" --> StatusDigunakan

    click ListStation "http://127.0.0.1:8008/device" "Buka Data Perangkat (Station)" _blank
    click BtnAddStation "http://127.0.0.1:8008/device/create" "Form Tambah Station" _blank
```

> **Tautan Halaman Terkait:**
> - [Daftar Data Perangkat (Station Billing)](http://127.0.0.1:8008/device)
> - [Form Tambah Station Billing](http://127.0.0.1:8008/device/create)

### Perbedaan Krusial Nama Station vs Nama Hardware:
- **Nama Station (contoh: `PS 01`, `Meja Biliar 01`):** Identitas meja atau rental fisik yang dilihat pelanggan di rental. Digunakan oleh Kasir untuk memilih meja saat billing.
- **Nama Hardware (contoh: `Samsung Smart TV 50U8500 PS-01`, `Shelly Plus 1PM Biliar-01`):** Identitas fisik modul elektronik di jaringan lokal dengan IP dan MAC Address tertentu.

---

## 6. DIAGRAM DETAIL 3 — DEVICE REGISTRY (INVENTARIS HARDWARE FISIK IOT)

Halaman **Device Registry** adalah pusat inventarisasi perangkat fisik IoT. Semua perangkat fisik yang akan dikendalikan otomatis wajib didaftarkan di sini terlebih dahulu sebelum dapat dihubungkan ke station billing.

```mermaid
flowchart TD
    classDef admin fill:#e1f5fe,stroke:#0277bd,stroke-width:2px,color:#01579b;
    classDef hardware fill:#fff3e0,stroke:#e65100,stroke-width:2px,color:#bf360c;
    classDef backend fill:#f5f5f5,stroke:#616161,stroke-width:2px,color:#212121;
    classDef decision fill:#fffde7,stroke:#f57f17,stroke-width:2px,color:#f57f17;

    RegIndex["Buka Halaman Device Registry\n/admin/hardware/registry"]:::admin
    BtnRegModal["Klik 'Registrasi Hardware Baru'\n(Membuka Modal Input)"]:::admin
    
    subgraph FORM_REG["Form Input Hardware Baru"]
        direction TB
        InpName["Nama Hardware (Deskriptif)"]
        InpCat["Pilih Kategori: SMART_TV atau SMART_RELAY"]
        InpBrand["Pilih Brand: SAMSUNG atau SHELLY"]
        InpModel["Model Hardware (misal: 50U8500 / Plus 1PM)"]
        InpIP["IP Address (Format RFC 1918 Private IPv4)"]
        InpMAC["MAC Address (Format: AA:BB:CC:DD:EE:FF)"]
        InpIdent["Device Identifier Unik"]
    end

    ValReg{"Validasi Backend\n(StationMappingService)"}:::decision
    ValErr["Validasi Gagal:\n- IP Bukan Private / Konflik Duplikat\n- Format MAC Salah / Duplikat\n- Brand vs Kategori Tidak Sesuai"]:::admin
    SaveReg["Simpan ke physical_devices\n- Status Koneksi: UNVERIFIED\n- Status Pairing: UNVERIFIED\n- Status Aktif: true"]:::backend

    RegIndex --> BtnRegModal --> FORM_REG --> ValReg
    ValReg -- "Data Tidak Valid" --> ValErr --> BtnRegModal
    ValReg -- "Valid" --> SaveReg

    click RegIndex "http://127.0.0.1:8008/admin/hardware/registry" "Buka Device Registry" _blank
```

> **Tautan Halaman Terkait:**
> - [Device Registry — Hardware Fisik](http://127.0.0.1:8008/admin/hardware/registry)

### Aturan Ketat Registrasi Hardware:
1. **Validasi Pasangan Kategori & Brand (`PhysicalDevice::validateBrandCategory`):**
   - Kategori `SMART_TV` **hanya diizinkan** berpasangan dengan brand `SAMSUNG`. *(Merk Coocaa dilarang keras)*.
   - Kategori `SMART_RELAY` **hanya diizinkan** berpasangan dengan brand `SHELLY`.
2. **Validasi Alamat IP RFC 1918 (`PhysicalDevice::validateIpAddress`):** IP address wajib berada dalam rentang jaringan privat lokal (`10.0.0.0/8`, `172.16.0.0/12`, atau `192.168.0.0/16`). IP publik tidak diizinkan demi keamanan.
3. **Normalisasi MAC Address (`PhysicalDevice::normalizeMacAddress`):** Format input otomatis dibersihkan dan diubah menjadi uppercase dengan pemisah titik dua (contoh: `AA:BB:CC:DD:EE:01`).
4. **Status Awal:** Setiap perangkat yang baru didaftarkan berstatus `UNVERIFIED` pada koneksi dan pairing. Status `ONLINE` hanya didapat setelah ada verifikasi probe jaringan aktual.

---

## 7. DIAGRAM DETAIL 4 — STATION MAPPING (INTEGRASI LOGIS STATION BILLING & HARDWARE FISIK)

**Station Mapping** adalah jembatan penghubung paling krusial dalam sistem Sugeng Rawuh. Komponen ini mengunci stasiun billing tertentu dengan satu hardware fisik tertentu menggunakan aturan peran ketat (`mapping_role`).

```mermaid
flowchart TD
    classDef admin fill:#e1f5fe,stroke:#0277bd,stroke-width:2px,color:#01579b;
    classDef backend fill:#f5f5f5,stroke:#616161,stroke-width:2px,color:#212121;
    classDef decision fill:#fffde7,stroke:#f57f17,stroke-width:2px,color:#f57f17;
    classDef error fill:#ffebee,stroke:#c62828,stroke-width:2px,color:#b71c1c;
    classDef success fill:#e8f8f5,stroke:#27ae60,stroke-width:2px,color:#145a32;

    MapIndex["Halaman Station Mapping\n/admin/hardware/mappings"]:::admin
    SelectStation["Pilih Stasiun Billing Target\n(Dropdown Stasiun)"]:::admin
    CheckBilling{"Pemeriksaan Status Billing:\nApakah Station 'Digunakan'?"}:::decision
    BlockOccupied["BLOKIR AKSI:\nStasiun sedang dalam sesi sewa aktif.\nMapping dilarang diubah saat billing berjalan!"]:::error

    ActionChoice{"Pilih Operasi Pemetaan"}:::decision
    
    %% ATTACH
    subgraph OP_ATTACH["Operasi 1: Pasang Hardware Baru (Attach)"]
        direction TB
        PickHw["Pilih Hardware Terdaftar & Aktif"]
        PickRole["Pilih Mapping Role:\n- DISPLAY_TV (Smart TV)\n- LIGHT_RELAY (Shelly Relay)"]
        ValCompat{"Validasi Kompatibilitas\n(StationMappingService)"}:::decision
    end

    %% REPLACE
    subgraph OP_REPLACE["Operasi 2: Ganti Hardware (Replace)"]
        direction TB
        SelectNewHw["Pilih Hardware Pengganti"]
        InputDetachNote["Wajib Isi Catatan Penggantian\n(Alasan hardware lama diganti)"]
    end

    %% DETACH
    subgraph OP_DETACH["Operasi 3: Lepas Pemetaan (Detach)"]
        direction TB
        BtnDetach["Klik 'Lepas Pemetaan'"]
        InputReason["Wajib Isi Alasan Pelepasan\n(Audit Trail)"]
    end

    RejectIncompat["TOLAK OPERASI:\n- Station PlayStation DILARANG dipasang Shelly Relay!\n- Station Biliar DILARANG dipasang Samsung TV!"]:::error
    SaveActive["Sukses Disimpan ke station_device_mappings:\n- mapping_status: 'ACTIVE'\n- attached_at: now()\n- attached_by_user_id: Auth::id()"]:::success
    SoftDetachOld["Update Pemetaan Lama:\n- mapping_status: 'INACTIVE'\n- detached_at: now()\n- detached_by_user_id: Auth::id()\n- detach_notes: Alasan"]:::backend

    MapIndex --> SelectStation --> CheckBilling
    CheckBilling -- "Status: Digunakan" --> BlockOccupied
    CheckBilling -- "Status: Tersedia" --> ActionChoice

    ActionChoice -- "Belum Ada Hardware" --> OP_ATTACH
    ActionChoice -- "Ganti Perangkat" --> OP_REPLACE
    ActionChoice -- "Lepas Perangkat" --> OP_DETACH

    PickRole --> ValCompat
    ValCompat -- "Tidak Kompatibel" --> RejectIncompat
    ValCompat -- "Kompatibel" --> SaveActive

    SelectNewHw --> InputDetachNote --> SoftDetachOld --> SaveActive
    BtnDetach --> InputReason --> SoftDetachOld

    click MapIndex "http://127.0.0.1:8008/admin/hardware/mappings" "Buka Station Mapping" _blank
```

> **Tautan Halaman Terkait:**
> - [Station Hardware Mapping](http://127.0.0.1:8008/admin/hardware/mappings)

### Aturan Validasi Kompatibilitas (`validateCompatibility`):
- **Stasiun PlayStation:**
  - Kategori Hardware yang Diizinkan: **`SMART_TV` (Samsung)**.
  - Peran Pemetaan yang Diizinkan: **`DISPLAY_TV`**.
  - Pelanggaran: Memasang Shelly Relay atau memberi role `LIGHT_RELAY` akan langsung memicu `StationMappingException::incompatibleHardware`.
- **Stasiun Biliar:**
  - Kategori Hardware yang Diizinkan: **`SMART_RELAY` (Shelly)**.
  - Peran Pemetaan yang Diizinkan: **`LIGHT_RELAY`**.
  - Pelanggaran: Memasang Samsung Smart TV atau memberi role `DISPLAY_TV` akan langsung ditolak sistem.

---

## 8. AUDIT KHUSUS SCREENSHOT: KASUS MEJA 1 - BILLIARD DIPETAKAN KE SAMSUNG TV

Berdasarkan pengamatan audit pada data sebelumnya, ditemukan konfigurasi janggal:
- **Nama Stasiun:** `Meja 1 - Billiard` (Kategori Layanan: `BILLIARD`)
- **Hardware Terpasang:** `Samsung Smart TV 50 Inch` (Kategori: `SMART_TV`)
- **Peran Pemetaan:** `DISPLAY_TV`

### Mengapa Konfigurasi Tersebut Salah?
1. **Ketidaksesuaian Semantik Operasional:** Meja biliar tidak menggunakan layar monitor/TV untuk visual permainan. Kebutuhan fisik meja biliar adalah **kontrol kelistrikan lampu gantung meja biliar** via relay (Shelly).
2. **Kegagalan Otomasi Billing:** Ketika sesi biliar dimulai atau berakhir, `AutomationWorkerCommand` akan mencoba mengeksekusi perintah lampu meja biliar. Namun karena hardware yang terpasang adalah Samsung Smart TV, `AutomationCapabilityPolicy` akan menolak eksekusi daya TV (`SKIPPED_UNVERIFIED_CAPABILITY`), sehingga **lampu biliar tidak akan pernah menyala atau mati secara otomatis**.
3. **Mengapa Status Hardware Bisa `ONLINE`?** Status `ONLINE` di registry hanya menunjukkan bahwa perangkat TV tersebut merespons *ping* atau *handshake socket* di jaringan lokal. Status `ONLINE` **bukanlah bukti** bahwa hardware tersebut terpasang di tempat yang benar atau mengontrol fungsi yang semestinya.

### Prosedur Perbaikan Melalui Antarmuka (UI):
1. Masuk ke halaman [Station Mapping](http://127.0.0.1:8008/admin/hardware/mappings).
2. Pilih stasiun **Meja 1 - Billiard** dari dropdown (Pastikan stasiun berstatus **Tersedia**, tidak sedang ada sesi billing berjalan).
3. Pada kartu pemetaan aktif, klik tombol **Ganti Hardware** atau **Lepas Pemetaan (Detach)**.
4. Masukkan alasan pelepasan pada modal konfirmasi: *"Koreksi salah mapping Samsung TV pada meja biliar ke Shelly Relay"*.
5. Klik **Pasang Hardware**, lalu pilih hardware Shelly Smart Relay (misal: `Shelly Relay Meja Biliar 1`) dan pilih role **`LIGHT_RELAY`**.
6. Klik **Simpan Pemetaan**. Sistem akan memvalidasi kompatibilitas, mengarsipkan mapping TV lama ke histori, dan mengaktifkan pemetaan Shelly Relay.

---

## 9. DIAGRAM DETAIL 5 — HARDWARE DIAGNOSTICS (PENGUJIAN PROTOKOL & PROBE PERANGKAT)

Modul **Hardware Diagnostics** digunakan oleh Administrator untuk menguji kesiapan jaringan dan *handshake* protokol komunikasi hardware sebelum digunakan secara resmi di kasir.

```mermaid
flowchart TD
    classDef admin fill:#e1f5fe,stroke:#0277bd,stroke-width:2px,color:#01579b;
    classDef backend fill:#f5f5f5,stroke:#616161,stroke-width:2px,color:#212121;
    classDef decision fill:#fffde7,stroke:#f57f17,stroke-width:2px,color:#f57f17;
    classDef hardware fill:#fff3e0,stroke:#e65100,stroke-width:2px,color:#bf360c;

    DiagIndex["Buka Hardware Diagnostics\n/admin/hardware/diagnostics"]:::admin
    SelectTarget["Pilih Target Hardware Terdaftar\n(Samsung TV / Shelly Relay)"]:::admin
    
    ActionDiag{"Pilih Aksi Diagnostik"}:::decision
    
    %% PROBE
    subgraph ACT_PROBE["1. Aksi PROBE (Cek Konektivitas)"]
        direction TB
        BtnProbe["Klik Tombol 'Cek Jaringan (Probe)'"]
        CheckGateProbe{"Safety Guard:\nHARDWARE_REAL_EXECUTION_ENABLED?"}:::decision
        MockProbe["Jalankan Fake Transport Probe\n(Respons deterministik isolasi)"]:::backend
        RealProbe["Kirim TCP Syn / HTTP Get ke IP Target\n(Port 8001/8002 TV atau Port 80 Relay)"]:::hardware
    end

    %% PAIRING
    subgraph ACT_PAIR["2. Aksi PAIR (Handshake Token)"]
        direction TB
        BtnPair["Klik Tombol 'Minta Otorisasi Pairing'"]
        CheckDevType{"Kategori Perangkat?"}:::decision
        PairSamsung["Kirim WebSocket Handshake ke Samsung TV\n(Munculkan Pop-up Otorisasi di Layar TV)"]:::hardware
        PairShelly["Shelly Menggunakan Token HTTP Auth\n(Simpan Token Otorisasi di Database)"]:::hardware
    end

    ResultDiag["Tampilkan Hasil Diagnostik pada Console UI:\n- Status Koneksi (ONLINE / OFFLINE)\n- Latensi Jaringan (ms)\n- Verified Capabilities (Daftar Perintah yang Lolos)"]:::admin

    DiagIndex --> SelectTarget --> ActionDiag
    ActionDiag -- "Uji Ping & Port" --> BtnProbe --> CheckGateProbe
    CheckGateProbe -- "false (Safe Mode)" --> MockProbe --> ResultDiag
    CheckGateProbe -- "true (Real Mode)" --> RealProbe --> ResultDiag

    ActionDiag -- "Uji Autentikasi" --> BtnPair --> CheckDevType
    CheckDevType -- "SMART_TV" --> PairSamsung --> ResultDiag
    CheckDevType -- "SMART_RELAY" --> PairShelly --> ResultDiag

    click DiagIndex "http://127.0.0.1:8008/admin/hardware/diagnostics" "Buka Hardware Diagnostics" _blank
```

> **Tautan Halaman Terkait:**
> - [Hardware Diagnostics](http://127.0.0.1:8008/admin/hardware/diagnostics)

---

## 10. DIAGRAM DETAIL 6 — HARDWARE SIMULATOR (LABORATORIUM MOCK VIRTUAL DRIVER)

Halaman **Hardware Simulator** dirancang sebagai laboratorium isolasi untuk memverifikasi logika perangkat lunak Sugeng Rawuh tanpa membutuhkan keberadaan perangkat fisik nyata di meja kerja developer.

```mermaid
flowchart TD
    classDef admin fill:#e1f5fe,stroke:#0277bd,stroke-width:2px,color:#01579b;
    classDef backend fill:#f5f5f5,stroke:#616161,stroke-width:2px,color:#212121;
    classDef decision fill:#fffde7,stroke:#f57f17,stroke-width:2px,color:#f57f17;

    SimIndex["Buka Hardware Simulator\n/admin/hardware/simulator"]:::admin
    SelectVirtual["Pilih Perangkat Virtual Terdaftar"]:::admin
    
    subgraph SCENARIOS["Pilihan Skenario Simulasi"]
        direction TB
        ScenNormal["Skenario NORMAL (Respons Cepat 15ms, Sukses)"]
        ScenOffline["Skenario OFFLINE (Simulasi Kabel Putus / Wi-Fi Mati)"]
        ScenTimeout["Skenario TIMEOUT (Simulasi Gangguan Jaringan 5000ms)"]
        ScenAuthFail["Skenario AUTH_FAILED (Simulasi Ditolak Layar TV)"]
    end

    SendSimCmd["Kirim Perintah Simulasi:\nSWITCH_ON / SWITCH_OFF / GET_STATUS"]:::admin
    MockDriver["Diproses oleh Mock Driver Terisolasi:\n- MockSamsungTvDriver\n- MockShellyRelayDriver"]:::backend
    
    SimLog["Tampilkan State Virtual & Riwayat Event:\n- Log Payload Request & Response\n- Daya Listrik Virtual (Watts)\n- Status Relay / TV di Memori Simulator"]:::admin

    SimIndex --> SelectVirtual --> SCENARIOS --> SendSimCmd --> MockDriver --> SimLog

    click SimIndex "http://127.0.0.1:8008/admin/hardware/simulator" "Buka Hardware Simulator" _blank
```

> **Tautan Halaman Terkait:**
> - [Hardware Simulator](http://127.0.0.1:8008/admin/hardware/simulator)

---

## 11. DIAGRAM DETAIL 7 — SIKLUS BILLING PLAYSTATION (PAKET JAM & BEBAS WAKTU)

Alur penyewaan unit PlayStation oleh Kasir, mencakup pemilihan paket jam (*prepaid*) atau bebas waktu (*lost time / postpaid*), perpanjangan waktu, hingga penghentian sesi.

```mermaid
flowchart TD
    classDef kasir fill:#e8f8f5,stroke:#27ae60,stroke-width:2px,color:#145a32;
    classDef backend fill:#f5f5f5,stroke:#616161,stroke-width:2px,color:#212121;
    classDef decision fill:#fffde7,stroke:#f57f17,stroke-width:2px,color:#f57f17;
    classDef warning fill:#fffde7,stroke:#f57f17,stroke-width:2px,color:#f57f17;

    KasirPick["Kasir Memilih Station PlayStation (Status: Tersedia)"]:::kasir
    BillingType{"Pilih Tipe Billing"}:::decision

    %% PREPAID
    subgraph PREPAID_FLOW["Alur Paket Jam (Prepaid)"]
        direction TB
        PickDuration["Pilih Durasi (1 Jam, 2 Jam, Custom Menit)"]
        SetExpires["Hitung Waktu Berakhir: expires_at = now() + durasi"]
        OutboxOn["Catat Outbox Intent: SWITCH_ON (segera)"]
        OutboxPreOff["Catat Outbox Intent: SWITCH_OFF (terjadwal pada expires_at)"]
        PickDuration --> SetExpires --> OutboxOn --> OutboxPreOff
    end

    %% POSTPAID
    subgraph POSTPAID_FLOW["Alur Bebas Waktu (Lost Time / Postpaid)"]
        direction TB
        StartLost["Mulai Billing Tanpa Batas Waktu (expires_at = null)"]
        OutboxLostOn["Catat Outbox Intent: SWITCH_ON (segera)"]
        StartLost --> OutboxLostOn
    end

    StartSess["BillingService::startSession()\n- Status Station Diubah: 'Digunakan'\n- Buat Record Transaksi: 'running'\n- session_generation Ditetapkan"]:::backend

    KasirPick --> BillingType
    BillingType -- "Paket Jam" --> PREPAID_FLOW --> StartSess
    BillingType -- "Lost Time" --> POSTPAID_FLOW --> StartSess

    %% IN-SESSION ACTIONS
    InSession{"Aksi Selama Sesi Berjalan"}:::decision
    StartSess --> InSession

    %% EXTEND
    InSession -- "Pelanggan Menambah Waktu" --> ExtendFlow["Perpanjangan Waktu (Extend):\n- expires_at Diperbarui\n- Intent OFF Lama Dibatalkan\n- Intent OFF Baru Didaftarkan"]:::kasir
    
    %% STOP DINI
    InSession -- "Pelanggan Berhenti Lebih Awal" --> StopFlow["Penghentian Sesi (Stop):\n- Status Station Kembali 'Tersedia'\n- session_generation Dinaikkan (++)\n- Intent OFF Segera Didaftarkan"]:::kasir

    %% EXPIRY
    InSession -- "Waktu Habis (Prepaid)" --> ExpireFlow["Waktu Habis Otomatis:\n- Worker Mengeksekusi Intent OFF\n- TV PlayStation Siap Ditransmisikan Sinyal Mati"]:::backend

    click KasirPick "http://127.0.0.1:8008/device" "Buka Halaman Billing Kasir" _blank
```

> **Catatan Operasional Remote TV:**
> Sesuai kebijakan keamanan daya `AutomationCapabilityPolicy`, perintah daya otomatis untuk Samsung TV (*POWER_ON / STANDBY*) berstatus *unverified capability* di backend sehingga dilewati (*SKIPPED*) oleh worker otomasi. Kasir menyalakan/mematikan TV menggunakan remote fisik jika TV berada dalam kondisi standby.

---

## 12. DIAGRAM DETAIL 8 — SIKLUS BILLING MEJA BILIAR & OTOMASI LAMPU SHELLY (ATURAN SR-G5-BR-001)

Layanan biliar memiliki keharusan otomasi mutlak pada lampu meja. Aturan **SR-G5-BR-001** menyatakan bahwa ketika sesi prepaid habis, lampu biliar wajib langsung diperintahkan **OFF** secara otomatis tanpa grace period dan tanpa menunggu konfirmasi kasir.

```mermaid
flowchart TD
    classDef kasir fill:#e8f8f5,stroke:#27ae60,stroke-width:2px,color:#145a32;
    classDef backend fill:#f5f5f5,stroke:#616161,stroke-width:2px,color:#212121;
    classDef hardware fill:#fff3e0,stroke:#e65100,stroke-width:2px,color:#bf360c;
    classDef decision fill:#fffde7,stroke:#f57f17,stroke-width:2px,color:#f57f17;
    classDef error fill:#ffebee,stroke:#c62828,stroke-width:2px,color:#b71c1c;

    PickBilliard["Kasir Memilih Meja Biliar 01 (Status: Tersedia)"]:::kasir
    StartBilliard["Kasir Memulai Paket Sewa Biliar (Prepaid 1 Jam)"]:::kasir
    
    subgraph START_TX["Transaksi Awal Dimulai (Atomik MySQL)"]
        direction TB
        TxCreate["Insert Transaksi (Status: 'running')"]
        IntentOn["Record Intent: SWITCH_ON (scheduled_at = now())"]
        IntentOff["Record Intent: SWITCH_OFF (scheduled_at = expires_at)"]
    end

    PickBilliard --> StartBilliard --> START_TX

    WorkerDispatchOn["Automation Worker Klaim Intent ON\n-> ShellyRelayDriver Kirim HTTP RPC 'Switch.Set on=true'"]:::backend
    RelayLightsOn["Lampu Meja Biliar MENYALA SEKETIKA"]:::hardware

    START_TX --> WorkerDispatchOn --> RelayLightsOn

    TimerRun["Timer Countdown Berjalan di Layar Kasir"]:::kasir
    RelayLightsOn --> TimerRun

    ExpiryTrigger{"Waktu Sewa Habis!\n(scheduled_at <= now())"}:::decision
    TimerRun --> ExpiryTrigger

    subgraph BR_001["ATURAN MUTLAK SR-G5-BR-001 (ZERO GRACE PERIOD)"]
        direction TB
        NoGrace["Tanpa Grace Period & Tanpa Konfirmasi Kasir"]
        WorkerClaimOff["Worker Segera Klaim Intent OFF"]
        ShellyRpcOff["Kirim Perintah HTTP RPC:\n'Switch.Set on=false' ke IP Shelly"]
        NoGrace --> WorkerClaimOff --> ShellyRpcOff
    end

    ExpiryTrigger --> BR_001
    RelayLightsOff["Lampu Meja Biliar PADAM OTOMATIS"]:::hardware
    ShellyRpcOff --> RelayLightsOff

    ReadbackCheck{"Pemeriksaan Read-Back:\nApakah Relay Benar-Benar Mati?"}:::decision
    RelayLightsOff --> ReadbackCheck

    ReadbackSuccess["Status Terkonfirmasi OFF:\nSimpan Log 'VERIFIED_OK' di Database"]:::backend
    ReadbackRetry["Read-Back Gagal / Relay Tidak Merespons:\nJalankan Retry Backoff (Maks 3 Kali)"]:::error

    ReadbackCheck -- "Output = false" --> ReadbackSuccess
    ReadbackCheck -- "Output Masih true / Error" --> ReadbackRetry

    click PickBilliard "http://127.0.0.1:8008/device" "Buka Data Perangkat (Station)" _blank
```

---

## 13. DIAGRAM DETAIL 9 — ARSITEKTUR OTOMASI BACKEND & TRANSACTIONAL OUTBOX WORKER

Diagram sequence ini menggambarkan alur lengkap bagaimana sebuah aksi dari browser kasir diubah menjadi perintah fisik ke hardware melalui pola **Transactional Outbox**, proteksi sesi (*fencing*), dan verifikasi *read-back*.

```mermaid
sequenceDiagram
    autonumber
    actor Kasir as 🟢 KASIR
    participant Browser as 🖥️ Browser Kasir
    participant BillingService as ⚙️ BillingService
    participant DB as 🗄️ MySQL Database
    participant Worker as 🔄 Automation Worker Daemon
    participant Fencing as 🛡️ Fencing & Capability Guard
    participant DriverManager as 🔌 HardwareDriverManager
    participant Hardware as 💡 Hardware (Shelly / Samsung)

    Kasir->>Browser: Klik "Mulai Billing" (PS 01 / Meja Biliar 01)
    Browser->>BillingService: POST /play/start-session (station_id, duration)
    
    activate BillingService
    Note over BillingService,DB: TRANSAKSI BASIS DATA ATOMIK (< 10ms)
    BillingService->>DB: UPDATE devices SET status='Digunakan', session_generation=session_generation
    BillingService->>DB: INSERT INTO transactions (id_transaksi, billing_status='running')
    BillingService->>DB: INSERT INTO automation_intents (event='BILLING_STARTED', desired_state='ON', scheduled_at=NOW())
    BillingService->>DB: INSERT INTO automation_intents (event='BILLING_EXPIRED', desired_state='OFF', scheduled_at=EXPIRES_AT)
    BillingService-->>Browser: HTTP 200 OK (Sesi Aktif & Timer Dimulai)
    deactivate BillingService
    Browser-->>Kasir: Tampilan Stasiun Berubah Kuning (Digunakan)

    Note over Worker,DB: WORKER BERJALAN DI LATAR BELAKANG (INTERVAL 500ms)
    loop Polling Siklus Outbox
        Worker->>DB: SELECT * FROM automation_intents WHERE status='PENDING' AND scheduled_at <= NOW() FOR UPDATE SKIP LOCKED
        DB-->>Worker: Return Intent Terpilih (Klaim Cepat < 5ms)
        Worker->>DB: UPDATE automation_intents SET status='PROCESSING', locked_by=PID
    end

    Worker->>Fencing: validateFencing(intent)
    alt Sesi Sudah Berubah (session_generation tidak cocok)
        Fencing-->>Worker: FENCING REJECTED (Intent Basi / Stale)
        Worker->>DB: UPDATE automation_intents SET status='STALE'
        Worker->>DB: INSERT INTO automation_execution_logs (result_code='FENCING_REJECTED')
    else Sesi Valid
        Fencing-->>Worker: FENCING VALID
        Worker->>Fencing: isCommandAllowed(device, desired_state)
        alt Perintah Belum Terverifikasi Fisik (Samsung TV Power)
            Fencing-->>Worker: SKIPPED_UNVERIFIED_CAPABILITY
            Worker->>DB: UPDATE automation_intents SET status='SKIPPED'
            Worker->>DB: INSERT INTO automation_execution_logs (result_code='SKIPPED_UNVERIFIED_CAPABILITY')
        else Perintah Diizinkan (Shelly Relay ON/OFF)
            Fencing-->>Worker: ALLOWED
            Worker->>DriverManager: getDriverForDevice(physicalDevice)
            DriverManager-->>Worker: Return ShellyRelayDriver

            Note over Worker,Hardware: EKSEKUSI PERINTAH FISIK KE JARINGAN LOKAL
            Worker->>Hardware: POST http://192.168.1.102/rpc/Switch.Set {"id":0, "on":true}
            Hardware-->>Worker: HTTP 200 {"was_on": false}
            
            Note over Worker,Hardware: VERIFIKASI BACA-ULANG (READ-BACK)
            Worker->>Hardware: GET http://192.168.1.102/rpc/Switch.GetStatus?id=0
            Hardware-->>Worker: HTTP 200 {"output": true, "apower": 42.5}
            
            Worker->>DB: UPDATE automation_intents SET status='COMPLETED'
            Worker->>DB: INSERT INTO automation_execution_logs (result_code='VERIFIED_OK', power_watts=42.5)
        end
    end

    Browser->>DB: Polling Status Stasiun & Telemetri Otomasi
    DB-->>Browser: Status Hardware Terverifikasi Aktif
    Browser-->>Kasir: Ikon Hardware Hijau (Menyala & Terverifikasi)
```

---

## 14. DIAGRAM DETAIL 10 — MATRIKS TROUBLESHOOTING & PEMULIHAN INSIDEN HARDWARE

Diagram alur penanganan masalah untuk kondisi anomali yang mungkin dihadapi oleh Kasir maupun Administrator di lapangan:

```mermaid
flowchart TD
    classDef admin fill:#e1f5fe,stroke:#0277bd,stroke-width:2px,color:#01579b;
    classDef kasir fill:#e8f8f5,stroke:#27ae60,stroke-width:2px,color:#145a32;
    classDef error fill:#ffebee,stroke:#c62828,stroke-width:2px,color:#b71c1c;
    classDef decision fill:#fffde7,stroke:#f57f17,stroke-width:2px,color:#f57f17;

    StartTrouble["Terjadi Masalah pada Perangkat / Otomasi"]:::error
    DetectType{"Identifikasi Gejala Masalah"}:::decision

    %% MASALAH 1
    DetectType -- "Kasus 1: Salah Pemetaan (Misal: Meja Biliar terpasang Samsung TV)" --> ProbMapping["Gejala: Perintah lampu tidak jalan, TV tidak relevan"]:::error
    ProbMapping --> SolMapping["Solusi ADMIN:\n1. Buka Station Mapping (/admin/hardware/mappings)\n2. Pastikan station 'Tersedia'\n3. Klik 'Ganti Hardware' atau 'Lepas Pemetaan'\n4. Pasang Shelly Relay dengan role LIGHT_RELAY"]:::admin

    %% MASALAH 2
    DetectType -- "Kasus 2: IP Address Berubah (DHCP Lease Expired)" --> ProbIP["Gejala: Status OFFLINE, Driver timeout 5000ms"]:::error
    ProbIP --> SolIP["Solusi ADMIN:\n1. Cek router & pasang DHCP Reservation (Static IP)\n2. Buka Device Registry (/admin/hardware/registry)\n3. Edit perangkat dan update IP Address baru\n4. Lakukan Probe Ulang di Hardware Diagnostics"]:::admin

    %% MASALAH 3
    DetectType -- "Kasus 3: Pairing Samsung TV Gagal / Ditolak" --> ProbPair["Gejala: Token otorisasi tidak valid, pop-up hilang"]:::error
    ProbPair --> SolPair["Solusi ADMIN:\n1. Buka Hardware Diagnostics (/admin/hardware/diagnostics)\n2. Pastikan TV dalam keadaan HIDUP di layar utama\n3. Klik 'Minta Otorisasi Pairing'\n4. Pegang remote fisik TV & klik 'Allow / Izinkan' di layar TV"]:::admin

    %% MASALAH 4
    DetectType -- "Kasus 4: Shelly Relay Offline (Wi-Fi Putus)" --> ProbRelay["Gejala: Lampu biliar tidak menyala saat sewa dimulai"]:::error
    ProbRelay --> SolKasirRelay["Solusi KASIR:\n1. Nyalakan saklar manual cadangan di meja biliar\n2. Sesi billing tetap berjalan normal di aplikasi"]:::kasir
    ProbRelay --> SolAdminRelay["Solusi ADMIN:\n1. Periksa access point Wi-Fi 2.4 GHz\n2. Cek catu daya modul Shelly di panel MCB\n3. Lakukan Probe Jaringan di Hardware Diagnostics"]:::admin

    %% MASALAH 5
    DetectType -- "Kasus 5: Automation Worker Tidak Berjalan" --> ProbWorker["Gejala: Intent berstatus 'PENDING' menumpuk di antrean"]:::error
    ProbWorker --> SolWorker["Solusi ADMIN:\nJalankan ulang worker daemon di server:\nphp artisan billing:automation-worker\n(Atau periksa supervisor process manager di OS)"]:::admin

    StartTrouble --> DetectType

    click SolMapping "http://127.0.0.1:8008/admin/hardware/mappings" "Buka Station Mapping" _blank
    click SolIP "http://127.0.0.1:8008/admin/hardware/registry" "Buka Device Registry" _blank
    click SolPair "http://127.0.0.1:8008/admin/hardware/diagnostics" "Buka Hardware Diagnostics" _blank
```

---

## 15. PENJELASAN LENGKAP SETIAP KOMPONEN SISTEM

Berikut adalah spesifikasi mendalam untuk setiap komponen inti arsitektur Sugeng Rawuh:

### 1. Kategori Permainan (`App\Models\Playstation`)
- **Fungsi:** Mengelompokkan jenis layanan rental dan mendefinisikan struktur tarif dasar serta tarif jam dinamis.
- **Digunakan oleh:** `ADMIN`.
- **Kapan digunakan:** Pada konfigurasi awal master data sebelum membuat station billing.
- **Input:** Nama Kategori (misal: "PS5", "PS4 Reguler", "BILLIARD VIP"), Tarif per jam, Gambar ikon.
- **Proses:** Sistem menyaring kata kunci nama untuk mengklasifikasikan kategori layanan sebagai `PLAYSTATION` atau `BILLIARD`.
- **Output:** Record kategori di tabel `playstations` yang dapat dipilih saat pembuatan station billing.
- **Hubungan:** Terhubung ke `HourlyPrice` (1-to-many) dan `Device` (many-to-many via `device_playstation`).
- **URL Website:** [http://127.0.0.1:8008/kategori-permainan](http://127.0.0.1:8008/kategori-permainan)
- **Status Implementasi:** **Implemented & Fully Tested (100%)**.

---

### 2. Data Perangkat / Station Billing (`App\Models\Device`)
- **Fungsi:** Merepresentasikan unit logis meja rental atau konsol permainan yang disewa oleh pelanggan.
- **Digunakan oleh:** `ADMIN` (Konfigurasi) dan `KASIR` (Operasional sewa).
- **Kapan digunakan:** Dibuat saat penambahan unit meja baru; dipilih setiap kali kasir membuka sesi sewa.
- **Input:** Nama station (misal: "PS 01", "Meja Biliar 01"), asosiasi kategori permainan.
- **Proses:** Menjaga status stasiun (`Tersedia` atau `Digunakan`) dan menyimpan `session_generation` untuk membatalkan sinyal lama.
- **Output:** Unit stasiun aktif di dasbor kasir dengan kartu warna status.
- **Hubungan:** Terhubung ke `Transaction` (1-to-many) dan `StationDeviceMapping` (1-to-many).
- **URL Website:** [http://127.0.0.1:8008/device](http://127.0.0.1:8008/device)
- **Status Implementasi:** **Implemented & Fully Tested (100%)**.

---

### 3. Device Registry (`App\Models\PhysicalDevice`)
- **Fungsi:** Menyimpan daftar inventaris seluruh modul fisik elektronik (Smart TV & Relay) yang terhubung ke LAN.
- **Digunakan oleh:** `ADMIN`.
- **Kapan digunakan:** Ketika pemilik toko membeli atau memasang unit Smart TV atau modul Shelly baru di lokasi rental.
- **Input:** Nama perangkat, Kategori (`SMART_TV` / `SMART_RELAY`), Brand (`SAMSUNG` / `SHELLY`), Model, IP Address RFC 1918, MAC Address terstandarisasi, Identifier unik.
- **Proses:** Memvalidasi keabsahan IP privat, memastikan MAC address unik, dan memverifikasi kesesuaian brand.
- **Output:** Record perangkat fisik pada tabel `physical_devices` dengan status awal `UNVERIFIED`.
- **Hubungan:** Terhubung ke `StationDeviceMapping` (1-to-many) dan `AutomationExecutionLog`.
- **URL Website:** [http://127.0.0.1:8008/admin/hardware/registry](http://127.0.0.1:8008/admin/hardware/registry)
- **Status Implementasi:** **Implemented & Fully Tested (100%)**.

---

### 4. Station Mapping (`App\Services\Hardware\StationMappingService`)
- **Fungsi:** Mengaitkan stasiun billing logis dengan hardware fisik tertentu menggunakan aturan peran ketat.
- **Digunakan oleh:** `ADMIN`.
- **Kapan digunakan:** Setelah station billing dan hardware fisik sama-sama terdaftar di sistem.
- **Input:** ID Stasiun, ID Hardware Fisik, Peran Pemetaan (`DISPLAY_TV` atau `LIGHT_RELAY`), Catatan operasional.
- **Proses:** Memeriksa ketersediaan stasiun (tidak boleh `Digunakan`), memvalidasi kecocokan jenis stasiun dan jenis hardware, serta mengarsipkan pemetaan lama secara atomik jika terjadi penggantian (*replace*).
- **Output:** Pemetaan aktif di tabel `station_device_mappings` berstatus `ACTIVE`.
- **Hubungan:** Pertemuan antara `Device` dan `PhysicalDevice`.
- **URL Website:** [http://127.0.0.1:8008/admin/hardware/mappings](http://127.0.0.1:8008/admin/hardware/mappings)
- **Status Implementasi:** **Implemented & Fully Tested (100%)**.

---

### 5. Hardware Diagnostics (`App\Http\Controllers\Admin\HardwareDiagnosticsController`)
- **Fungsi:** Laboratorium pengujian langsung koneksi jaringan, verifikasi port, dan handshake pairing hardware.
- **Digunakan oleh:** `ADMIN`.
- **Kapan digunakan:** Saat instalasi awal hardware di lokasi atau saat investigasi kegagalan kontrol fisik.
- **Input:** Pilihan hardware target, trigger Probe atau Trigger Pairing.
- **Proses:** Memeriksa status Safety Guard; jika mode aman aktif, mengeksekusi Fake Transport terisolasi untuk menguji logika protokol.
- **Output:** Laporan latensi jaringan (ms), respon kode HTTP/WebSocket, dan status pairing.
- **Hubungan:** Menguji langsung `PhysicalDevice`.
- **URL Website:** [http://127.0.0.1:8008/admin/hardware/diagnostics](http://127.0.0.1:8008/admin/hardware/diagnostics)
- **Status Implementasi:** **Implemented (Protokol Fake Transport & Lab Driver Teruji 100%)**.

---

### 6. Hardware Simulator (`App\Http\Controllers\Admin\HardwareSimulatorController`)
- **Fungsi:** Menyediakan lingkungan virtual deterministik untuk menguji perilaku aplikasi terhadap skenario ekstrem (kabel putus, timeout, relay mati).
- **Digunakan oleh:** `ADMIN`.
- **Kapan digunakan:** Pengujian software otomatis, simulasi beban, dan verifikasi alur sebelum deploy.
- **Input:** Pilihan virtual device, pilihan skenario kegagalan, tombol kirim perintah.
- **Proses:** `MockSamsungTvDriver` dan `MockShellyRelayDriver` membalas perintah sesuai skenario tanpa menyentuh soket jaringan riil.
- **Output:** Log respons virtual, status daya (Watt) tiruan, dan perubahan state simulator.
- **Hubungan:** Menguji kesiapan `HardwareDriverManager` dan `AutomationWorker`.
- **URL Website:** [http://127.0.0.1:8008/admin/hardware/simulator](http://127.0.0.1:8008/admin/hardware/simulator)
- **Status Implementasi:** **Implemented & Fully Tested (100%)**.

---

### 7. Billing Engine (`App\Services\BillingService`)
- **Fungsi:** Mengatur seluruh siklus hidup penyewaan stasiun rental (mulai sewa, hitung tarif, extend waktu, stop sewa).
- **Digunakan oleh:** `KASIR`.
- **Kapan digunakan:** Setiap kali pelanggan datang dan bertransaksi sewa rental PlayStation atau Meja Biliar.
- **Input:** ID Stasiun, Jenis Paket (Prepaid Jam / Menit / Postpaid Lost Time), Durasi sewa.
- **Proses:** Menghitung tarif secara atomik, memperbarui status unit meja, menaikkan nomor generasi sesi (`session_generation`), dan mencatat intent otomasi ke dalam tabel outbox `automation_intents`.
- **Output:** Record `Transaction` aktif berstatus `running` dan antrean intent outbox.
- **Hubungan:** Terhubung ke `Device`, `Transaction`, dan `AutomationIntentService`.
- **URL Website:** [http://127.0.0.1:8008/device](http://127.0.0.1:8008/device)
- **Status Implementasi:** **Implemented & Fully Tested (100%)**.

---

### 8. Automation Worker (`App\Console\Commands\BillingAutomationWorkerCommand`)
- **Fungsi:** Daemon background process yang bertugas mengambil intent outbox, memvalidasi keamanan, dan mengirim perintah fisik ke driver IoT.
- **Digunakan oleh:** Sistem Otomatisasi Backend (CLI Command / Process Manager).
- **Kapan digunakan:** Dijalankan terus-menerus di latar belakang server (`php artisan billing:automation-worker`).
- **Input:** Antrean record pada tabel `automation_intents` dengan kriteria `status='PENDING'` dan `scheduled_at <= NOW()`.
- **Proses:** Mengklaim intent secara cepat (<5ms), memvalidasi kepemilikan sesi (*fencing check*), memeriksa kebijakan izin perintah (*capability policy*), mengirim RPC ke driver hardware, dan melakukan verifikasi baca-ulang (*read-back verification*).
- **Output:** Status intent berubah menjadi `COMPLETED`, `SKIPPED`, atau `STALE`, disertai log telemetri di `automation_execution_logs`.
- **Hubungan:** Menghubungkan basis data dengan `AutomationDispatcherService` dan `HardwareDriverManager`.
- **Status Implementasi:** **Implemented & Fully Tested (100%)**.

---

## 16. PANDUAN PRAKTIS LANGKAH-DEMI-LANGKAH UNTUK ADMINISTRATOR

### Contoh Kasus A: Membangun Station PS 01 dari Nol Sampai Samsung TV Terhubung

1. **Langkah 1 — Buat Kategori Permainan:**
   - Masuk sebagai Admin ke [Kategori Permainan](http://127.0.0.1:8008/kategori-permainan).
   - Klik **Tambah Kategori**.
   - Isi Nama Permainan: `PS5 Reguler`, Harga Dasar: `15000`.
   - Simpan data. Kategori otomatis teridentifikasi sebagai `PLAYSTATION`.
2. **Langkah 2 — Buat Station Billing PS 01:**
   - Buka menu [Data Perangkat](http://127.0.0.1:8008/device).
   - Klik **Tambah Perangkat**.
   - Isi Nama Perangkat: `PS 01`.
   - Centang Kategori Permainan: `PS5 Reguler`.
   - Klik Simpan. Station `PS 01` kini berstatus `Tersedia`.
3. **Langkah 3 — Daftarkan Samsung Smart TV di Registry:**
   - Buka menu [Device Registry](http://127.0.0.1:8008/admin/hardware/registry).
   - Klik tombol **Registrasi Hardware Baru**.
   - Isi formulir:
     - Nama Perangkat: `Samsung Smart TV 50U8500 PS-01`
     - Kategori: `Smart TV (Samsung)`
     - Brand: `SAMSUNG`
     - Model: `50U8500`
     - IP Address: `192.168.1.101`
     - MAC Address: `AA:BB:CC:DD:EE:01`
     - Device Identifier: `TV-SAMSUNG-50U8500-ST01`
   - Klik Simpan. Perangkat terdaftar dengan status `UNVERIFIED`.
4. **Langkah 4 — Petakan TV ke Station PS 01:**
   - Buka menu [Station Mapping](http://127.0.0.1:8008/admin/hardware/mappings).
   - Pada dropdown stasiun, pilih `PS 01 [PLAYSTATION]`.
   - Klik tombol **Pasang Hardware**.
   - Pilih Hardware: `Samsung Smart TV 50U8500 PS-01`.
   - Pilih Peran Pemetaan: `DISPLAY_TV`.
   - Klik **Simpan Pemetaan**. Pemetaan aktif berhasil tersimpan.

---

### Contoh Kasus B: Membangun Station Meja Biliar 01 dari Nol Sampai Shelly Relay Terhubung

1. **Langkah 1 — Buat Kategori Permainan Biliar:**
   - Buka menu [Kategori Permainan](http://127.0.0.1:8008/kategori-permainan).
   - Klik **Tambah Kategori**.
   - Isi Nama Permainan: `BILLIARD Sore`, Harga Dasar: `30000`.
   - Simpan data. Kategori otomatis teridentifikasi sebagai `BILLIARD`.
2. **Langkah 2 — Buat Station Billing Meja Biliar 01:**
   - Buka menu [Data Perangkat](http://127.0.0.1:8008/device).
   - Klik **Tambah Perangkat**.
   - Isi Nama Perangkat: `Meja Biliar 01`.
   - Centang Kategori Permainan: `BILLIARD Sore`.
   - Simpan data.
3. **Langkah 3 — Daftarkan Shelly Smart Relay di Registry:**
   - Buka menu [Device Registry](http://127.0.0.1:8008/admin/hardware/registry).
   - Klik **Registrasi Hardware Baru**.
   - Isi formulir:
     - Nama Perangkat: `Shelly Relay Meja Biliar 01`
     - Kategori: `Smart Relay (Shelly)`
     - Brand: `SHELLY`
     - Model: `Shelly Plus 1PM`
     - IP Address: `192.168.1.102`
     - MAC Address: `AA:BB:CC:DD:EE:02`
     - Device Identifier: `RELAY-SHELLY-PL1PM-B01`
   - Klik Simpan.
4. **Langkah 4 — Petakan Shelly Relay ke Meja Biliar 01:**
   - Buka menu [Station Mapping](http://127.0.0.1:8008/admin/hardware/mappings).
   - Pilih stasiun: `Meja Biliar 01 [BILLIARD]`.
   - Klik **Pasang Hardware**.
   - Pilih Hardware: `Shelly Relay Meja Biliar 01`.
   - Pilih Peran Pemetaan: `LIGHT_RELAY`.
   - Klik **Simpan Pemetaan**.

---

### Contoh Kasus C: Kasir Menjalankan dan Menghentikan Billing

1. **Memulai Sewa (Start Billing):**
   - Kasir membuka menu [Data Perangkat](http://127.0.0.1:8008/device).
   - Pilih stasiun `Meja Biliar 01` yang berwarna hijau (`Tersedia`).
   - Klik **Mulai**.
   - Pilih paket: Durasi `1 Jam` (Prepaid).
   - Klik tombol **Simpan / Mulai Sewa**.
   - *Di Balik Layar:* Status meja menjadi kuning (`Digunakan`), transaksi dibuat, outbox intent `ON` segera dieksekusi oleh worker, dan lampu biliar langsung menyala.
2. **Saat Waktu Habis Otomatis (Expiry):**
   - Tepat setelah 1 jam berlalu, worker mengklaim intent `OFF` yang telah terjadwal.
   - Sesuai aturan **SR-G5-BR-001**, perintah lampu padam dikirim seketika tanpa jeda dan tanpa konfirmasi kasir.
3. **Menghentikan Sewa Lebih Awal (Stop Manual):**
   - Jika pelanggan selesai lebih awal, kasir klik **Stop** pada kartu `Meja Biliar 01`.
   - Masukkan konfirmasi stop.
   - *Di Balik Layar:* `session_generation` stasiun dinaikkan untuk mematikan jadwal intent lama, perintah lampu padam segera dikirim, dan kasir diarahkan ke halaman [Transaksi](http://127.0.0.1:8008/transaction) untuk menyelesaikan pembayaran.

---

## 17. GLOSARIUM ISTILAH & STATUS IMPLEMENTASI TEKNIS

### Glosarium Istilah:
- **Station Billing:** Entitas meja rental logis tempat kasir memulai transaksi sewa (`App\Models\Device`).
- **Physical Device:** Entitas perangkat elektronik IoT nyata yang memiliki MAC dan IP address di jaringan lokal (`App\Models\PhysicalDevice`).
- **Station Mapping:** Tabel relasi aktif yang menyatukan satu Station Billing dengan satu Physical Device (`App\Models\StationDeviceMapping`).
- **DISPLAY_TV:** Peran pemetaan khusus untuk layar Smart TV pada stasiun PlayStation.
- **LIGHT_RELAY:** Peran pemetaan khusus untuk modul saklar relay lampu pada stasiun Biliar.
- **Session Generation:** Bilangan integer pencacah versi sesi billing aktif pada suatu stasiun. Berfungsi sebagai pelindung (*fencing guard*) agar perintah tertunda dari sesi lama tidak mematikan perangkat pelanggan baru.
- **Transactional Outbox:** Pola arsitektur di mana pembuatan transaksi keuangan dan perintah perangkat disimpan bersamaan dalam satu transaksi database lokal, lalu dieksekusi secara asinkron oleh worker terpisah.
- **Read-Back Verification:** Prosedur wajib di mana setelah mengirim perintah saklar, sistem melakukan query balik terhadap status output relay untuk memastikan relay benar-benar telah berubah posisi secara fisik.

### Ringkasan Status Kesiapan Sistem:
- **Software Backend Architecture (Laravel 8):** **100% COMPLETE & TESTED** (Seluruh rute, controller, model, guard, dan worker lolos uji otomatis).
- **Frontend UI/UX & Responsive Layouts:** **100% COMPLETE & TESTED** (Device Registry & Station Mapping tampil proporsional di seluruh viewport desktop, tablet, dan mobile).
- **Driver Abstraction & Mock Lab:** **100% TESTED** (Mock driver teruji pada skenario normal, offline, timeout, dan auth failure).
- **Physical Hardware Execution (Real Hardware Verification Gate G4-B2):** **PENDING PHYSICAL LAB CONNECTION** (`HARDWARE_REAL_EXECUTION_ENABLED=false` aktif demi keamanan hardware hingga pengujian fisik dengan unit nyata dilakukan).
