# SUGENG RAWUH — COMPLETE APPLICATION USER FLOW & ARCHITECTURE MANUAL

**Project:** Sugeng Rawuh — Integrated Billing & POS Management System  
**Repository:** `sistem_kasir_PS-main`  
**Document Ref:** `docs/SUGENG-RAWUH-COMPLETE-USER-FLOW.md`  
**Application Stack:** Laravel 8, PHP 8.2, MySQL 8, Blade, Bootstrap 4.6, jQuery  
**Local Base URL:** `http://127.0.0.1:8008` (Verifikasi file `.env`: `APP_URL=http://127.0.0.1:8008`)  
**Target Hardware Policy:**  
- **PlayStation Smart TV:** 100% Samsung Smart TV 50U8500 (Tizen OS). *Coocaa telah sepenuhnya dihapus dari arsitektur target.*  
- **Billiard Table Lighting:** Shelly 1PM Mini Gen3 Smart Relay (Wi-Fi HTTP JSON-RPC 2.0).  
- **Safety Gate:** `HARDWARE_REAL_EXECUTION_ENABLED=false` aktif di level backend via `HardwareSafetyGuard`.

---

## DAFTAR ISI

1. [Ringkasan Sistem & Prinsip Utama](#1-ringkasan-sistem--prinsip-utama)
2. [Diagram 1 — Arsitektur Sistem Lengkap (Complete System Architecture)](#diagram-1--arsitektur-sistem-lengkap-complete-system-architecture)
3. [Diagram 2 — Login, Autentikasi & RBAC Multi-Role](#diagram-2--login-autentikasi--rbac-multi-role)
4. [Diagram 3 — Peta Situs & Navigasi Antarmuka (Website Sitemap)](#diagram-3--peta-situs--navigasi-antarmuka-website-sitemap)
5. [Diagram 4 — ADMIN: Manajemen Station PlayStation](#diagram-4--admin-manajemen-station-playstation)
6. [Diagram 5 — ADMIN: Manajemen Samsung Smart TV](#diagram-5--admin-manajemen-samsung-smart-tv)
7. [Diagram 6 — Sekuens Koneksi & Pairing Samsung Smart TV](#diagram-6--sekuens-koneksi--pairing-samsung-smart-tv)
8. [Diagram 7 — KASIR: Billing Prepaid PlayStation](#diagram-7--kasir-billing-prepaid-playstation)
9. [Diagram 8 — KASIR: Billing Lost Time (Postpaid) PlayStation](#diagram-8--kasir-billing-lost-time-postpaid-playstation)
10. [Diagram 9 — KASIR: Perpanjangan Waktu (Extend) & Penghentian Dini (Stop Session)](#diagram-9--kasir-perpanjangan-waktu-extend--penghentian-dini-stop-session)
11. [Diagram 10 — Otomatisasi Meja Biliar & Shelly Relay](#diagram-10--otomatisasi-meja-biliar--shelly-relay)
12. [Diagram 11 — Alur Transaksi POS Makanan & Minuman (FnB)](#diagram-11--alur-transaksi-pos-makanan--minuman-fnb)
13. [Diagram 12 — Alur Pembayaran, Checkout, & Cetak Struk (Receipt)](#diagram-12--alur-pembayaran-checkout--cetak-struk-receipt)
14. [Diagram 13 — Laporan Keuangan & Audit Operasional (OWNER & ADMIN)](#diagram-13--laporan-keuangan--audit-operasional-owner--admin)
15. [Diagram 14 — Penanganan Kegagalan Hardware & Pemulihan Offline (Failure Recovery)](#diagram-14--penanganan-kegagalan-hardware--pemulihan-offline-failure-recovery)
16. [Diagram Tambahan (Billing State Machine, Outbox Sequence, & ERD)](#diagram-tambahan)

---

## 1. RINGKASAN SISTEM & PRINSIP UTAMA

Sistem Kasir dan Billing **Sugeng Rawuh** adalah solusi terintegrasi untuk bisnis rental PlayStation dan meja biliar yang dilengkapi modul Point of Sales (POS) FnB, manajemen shift kasir, dan otomasi IoT perangkat keras.

### Tiga Pilar Bisnis Faktual:
1. **Billing Engine Server-Side (G2):** Waktu sewa dikunci secara absolut di server (`started_at`, `expires_at`, `ended_at`). Kasir tidak dapat memanipulasi waktu timer lokal browser.
2. **Transactional Outbox Hardware Automation (G5):** Transaksi kasir tidak pernah dibatalkan oleh kendala jaringan IoT. Setiap perubahan sesi mencatat *intent* ke tabel `automation_intents`, yang diproses oleh *background worker* (`php artisan billing:automation-worker`).
3. **Hardware Safety Fencing (G3/G4):**
   - **PlayStation Smart TV:** Menggunakan Samsung Smart TV 50U8500. Otomatisasi daya (`POWER_ON` / `STANDBY`) dicegat oleh `AutomationCapabilityPolicy` (`SKIPPED_UNVERIFIED_CAPABILITY`) karena sifat tombol daya Samsung yang *toggle*, sehingga kasir tetap menggunakan remote fisik sebagai *fallback*.
   - **Billiard Table:** Menggunakan Shelly 1PM Mini Gen3 Smart Relay. Mematuhi **Keputusan Bisnis SR-G5-BR-001**: Lampu meja biliar mati seketika saat paket prepaid habis tanpa masa tenggang dan tanpa konfirmasi kasir.
   - **Larangan Keras:** Smart relay dilarang keras dipasang untuk memutus daya listrik konsol PlayStation atau Smart TV.

---

## DIAGRAM 1 — ARSITEKTUR SISTEM LENGKAP (COMPLETE SYSTEM ARCHITECTURE)

### Nama Proses
Arsitektur Sistem Terintegrasi Sugeng Rawuh

### Tujuan
Menggambarkan interaksi menyeluruh antara antarmuka pengguna (Browser Owner, Admin, Kasir), aplikasi web Laravel di PC Kasir, database MySQL, background worker, router lokal, konsol PlayStation, Samsung Smart TV, dan Smart Relay meja biliar.

### Diagram Mermaid

```mermaid
flowchart TB
    subgraph Users_Layer["Lapisan Pengguna & Browser (LAN Toko)"]
        OwnerBrowser["Browser OWNER\nMonitoring & Laporan Keuangan"]
        AdminBrowser["Browser ADMIN\nMaster Data & IoT Config"]
        KasirBrowser["Browser KASIR\nBilling & POS FnB Kasir"]
    end

    subgraph Host_Server["PC Kasir Lokal (Host Server: 127.0.0.1:8008)"]
        subgraph Laravel_App["Aplikasi Web Laravel 8"]
            RouterWeb["Laravel Web Router\nroutes/web.php"]
            AuthEngine["Auth & RBAC Middleware\nCheckRole: admin, kasir, owner"]
            
            subgraph Controllers_Domain["Domain Controller"]
                HomeController["HomeController\nDashboard & Chart"]
                DeviceCtrl["DeviceController\nStation & Billing View"]
                TxCtrl["TransactionController\nBilling & POS FnB Payment"]
                AdminHwCtrl["Admin Hardware Controllers\nRegistry, Mapping, Diagnostics"]
            end
            
            subgraph Core_Services["Domain Service & Engine"]
                BillingSvc["BillingService\nLifecycle & Session Generation"]
                IntentSvc["AutomationIntentService\nTransactional Outbox Creator"]
                HwSafety["HardwareSafetyGuard\nReal Execution Fencing Gate"]
            end
        end

        subgraph Background_Workers["Daemon Process CLI"]
            AutoWorker["BillingAutomationWorkerCommand\nphp artisan billing:automation-worker"]
            Dispatcher["AutomationDispatcherService\nLock, Lease, Policy, Exec"]
            CapabilityPolicy["AutomationCapabilityPolicy\nSamsung Toggle & Relay Fencing"]
            DriverManager["HardwareDriverManager\nSamsungTvDriver & ShellyDriver"]
        end

        subgraph Storage_Layer["MySQL 8 Database Engine"]
            DB_Core[("Tabel Operasional:\nusers, devices, transactions,\nfnbs, stocks, expenses")]
            DB_IoT[("Tabel Hardware & Otomasi:\nphysical_devices, station_device_mappings,\nautomation_intents, automation_execution_logs")]
        end
    end

    subgraph Network_Infrastructure["Infrastruktur Jaringan Lokal (Router LAN / Wi-Fi)"]
        LocalRouter{{"Router LAN / Wi-Fi Toko\nSubnet: 192.168.1.0/24\nDHCP Reservation"}}
    end

    subgraph Physical_Hardware["Perangkat Keras Fisik Rental"]
        subgraph Station_PS["Area Station PlayStation"]
            PSConsole["Konsol PlayStation\n(PS3 / PS4 / PS5)\nOperasional Mandiri"]
            SamsungTV["Samsung Smart TV 50U8500\nIP: 192.168.1.50\nPort 8001 (REST) / 8002 (WSS)"]
            RemoteSamsung["Remote Fisik Samsung\nFallback Operasional Manual"]
        end

        subgraph Station_Billiard["Area Meja Biliar"]
            ShellyRelay["Shelly 1PM Mini Gen3\nIP: 192.168.1.60\nPort 80 (HTTP JSON-RPC 2.0)"]
            BilliardLight["Lampu Penerangan Meja Biliar\n(AC 220V Kontak Relay)"]
        end
    end

    %% Relasi Pengguna ke Web Server
    OwnerBrowser -->|HTTP GET /home, /report| RouterWeb
    AdminBrowser -->|HTTP /admin/hardware/*| RouterWeb
    KasirBrowser -->|HTTP /device, /transaction/*| RouterWeb

    %% Routing ke Controller & Service
    RouterWeb --> AuthEngine
    AuthEngine --> Controllers_Domain
    DeviceCtrl --> BillingSvc
    TxCtrl --> BillingSvc
    AdminHwCtrl --> Storage_Layer

    %% Billing Service ke Database & Intent
    BillingSvc -->|ACID Transaction| DB_Core
    BillingSvc -->|Transactional Outbox| IntentSvc
    IntentSvc -->|Insert PENDING Intent| DB_IoT

    %% Worker memproses Intent
    AutoWorker --> Dispatcher
    Dispatcher -->|Poll PENDING & Lock Lease| DB_IoT
    Dispatcher --> CapabilityPolicy
    CapabilityPolicy --> DriverManager
    DriverManager --> HwSafety

    %% Komunikasi Jaringan Fisik
    HwSafety -.->|Jika REAL ENABLED| LocalRouter
    LocalRouter -.->|WebSocket / REST| SamsungTV
    LocalRouter -.->|HTTP JSON-RPC| ShellyRelay
    ShellyRelay ===|Kontak Arus Listrik AC| BilliardLight

    %% Telemetri & Log Audit
    Dispatcher -->|Write Audit Telemetry| DB_IoT
    Controllers_Domain -->|Read Active Data| Storage_Layer

    classDef host fill:#f8f9fa,stroke:#343a40,stroke-width:2px;
    classDef hardware fill:#e3f2fd,stroke:#1565c0,stroke-width:2px;
    classDef db fill:#fff3e0,stroke:#e65100,stroke-width:2px;
    class Host_Server host;
    class Physical_Hardware hardware;
    class Storage_Layer db;
```

### Penjelasan Alur
1. Komputer kasir lokal bertindak sebagai host server mandiri yang menjalankan Laravel 8 dan MySQL 8 pada port `8008`.
2. Browser kasir, admin, dan owner mengakses alamat lokal `http://127.0.0.1:8008` (atau IP LAN PC kasir) melalui jaringan switch LAN/Wi-Fi toko.
3. Transaksi kasir (memulai, memperpanjang, atau menghentikan billing) diproses oleh `BillingService` dengan proteksi *pessimistic locking* (`FOR UPDATE`) di MySQL.
4. `BillingService` menyimpan sesi transaksi ke tabel `transactions` dan secara bersamaan mencatat perintah perangkat ke tabel `automation_intents` (*Transactional Outbox*).
5. Background worker `BillingAutomationWorkerCommand` berjalan terus-menerus membaca *intent* yang berstatus `PENDING` dan telah mencapai waktu eksekusi (`scheduled_at <= now()`).
6. `AutomationDispatcherService` mengevaluasi validitas generasi sesi (*fencing guard*) dan batasan kapabilitas perangkat (*capability policy*).
7. Untuk Samsung Smart TV, perintah WebSocket dikirimkan ke IP TV terdaftar. Jika belum terverifikasi fisik, policy otomatis menandai intent sebagai `SKIPPED_UNVERIFIED_CAPABILITY` agar kasir memakai remote fisik.
8. Untuk Meja Biliar, perintah HTTP RPC dikirimkan ke relay Shelly untuk menyalakan/mematikan lampu meja biliar.

### Role Pengguna
- **OWNER:** Memantau dasbor dan laporan omzet secara *read-only*.
- **ADMIN:** Mengelola data master, mendaftarkan hardware, dan mengkonfigurasi stasiun.
- **KASIR:** Mengoperasikan billing harian dan pembayaran POS FnB.

### Link Website
- Dasbor Utama: [http://127.0.0.1:8008/home](http://127.0.0.1:8008/home)
- Monitoring Dasbor Station: [http://127.0.0.1:8008/device](http://127.0.0.1:8008/device)

### Data yang Digunakan
- Model: `Device`, `Transaction`, `PhysicalDevice`, `StationDeviceMapping`, `AutomationIntent`, `AutomationExecutionLog`.
- Tabel: `devices`, `transactions`, `physical_devices`, `station_device_mappings`, `automation_intents`, `automation_execution_logs`.

### Proses Backend
- `App\Services\BillingService::startSession()`
- `App\Services\Hardware\Automation\AutomationIntentService::recordIntent()`
- `App\Console\Commands\BillingAutomationWorkerCommand::handle()`
- `App\Services\Hardware\Automation\AutomationDispatcherService::dispatchEligibleIntents()`

### Hasil Akhir
- Transaksi billing tersimpan aman di database.
- Perangkat keras fisik dikendalikan sesuai status sesi.
- Catatan audit eksekusi tersimpan secara permanen di `automation_execution_logs`.

### Kondisi Gagal
- Jika router lokal mati atau TV offline, worker mencatat error di log audit, namun transaksi kasir tetap sukses (tidak terjadi *rollback* billing).

### Status Implementasi
**IMPLEMENTED & VERIFIED** (Software & Mock E2E terverifikasi 310 tes lulus; integrasi fisik menunggu Gate G4-B2).

---

## DIAGRAM 2 — LOGIN, AUTENTIKASI & RBAC MULTI-ROLE

### Nama Proses
Autentikasi Pengguna & Penegakan Hak Akses Berbasis Peran (RBAC)

### Tujuan
Menggambarkan proses masuk pengguna ke sistem, validasi kredensial, verifikasi status peran (`owner`, `admin`, `kasir`), pembatasan akses menu, serta penegakan aturan bisnis bahwa **tidak ada fitur refund** di dalam sistem.

### Diagram Mermaid

```mermaid
flowchart TD
    StartLogin(["Pengguna Mengakses Website\nhttp://127.0.0.1:8008/login"]) --> InputForm["Form Login:\nMasukkan Email & Password"]
    InputForm --> SubmitLogin{"Klik Tombol 'Login'\nPOST /login"}

    SubmitLogin --> CheckAuth{"Validasi Kredensial\n(Hash Bcrypt)"}
    CheckAuth -- "Kredensial Salah" --> LoginFail["Kembali ke /login\nPesan Error: Kredensial tidak cocok"]
    
    CheckAuth -- "Kredensial Benar" --> EstablishSession["Buat HTTP Session Auth\nRegenerasi Session ID"]
    EstablishSession --> IdentifyRole{"Identifikasi Kolom\nusers.role"}

    %% Jalur OWNER
    IdentifyRole -- "role == 'owner'" --> OwnerDashboard["Redirect ke /home\nDasbor Eksekutif OWNER"]
    subgraph Owner_Scope["Hak Akses OWNER (Read-Only)"]
        OwnerDashboard --> OwnerM1["Melihat Omzet & Statistik Penjualan"]
        OwnerDashboard --> OwnerM2["Laporan Transaksi & Ekspor Excel/PDF"]
        OwnerDashboard --> OwnerM3["Melihat Data Perangkat / Stasiun (Read-Only)"]
        OwnerDashboard --> OwnerM4["Melihat Device Registry & Station Mapping (Read-Only)"]
        OwnerDashboard -.-> OwnerDeny["DILARANG:\n- Memulai / Menghentikan Billing\n- Mengubah Master Data / Tarif\n- Menjalankan Diagnostik IoT\n- Melakukan Refund Transaksi"]
    end

    %% Jalur ADMIN
    IdentifyRole -- "role == 'admin'" --> AdminDashboard["Redirect ke /home\nDasbor Penuh ADMIN"]
    subgraph Admin_Scope["Hak Akses ADMIN (Full Configuration)"]
        AdminDashboard --> AdminM1["Manajemen User & Shift Kasir"]
        AdminDashboard --> AdminM2["Master Playstation & Meja Biliar"]
        AdminDashboard --> AdminM3["Pengaturan Tarif Jam & Custom Package"]
        AdminDashboard --> AdminM4["Kelola FnB, Harga Kelompok & Stok"]
        AdminDashboard --> AdminM5["Device Registry, Station Mapping, Diagnostik"]
        AdminDashboard --> AdminM6["Hapus Transaksi (Hanya jika belum bayar)"]
        AdminDashboard -.-> AdminDeny["DILARANG:\n- Melakukan Refund Uang Sesi Berjalan"]
    end

    %% Jalur KASIR
    IdentifyRole -- "role == 'kasir'" --> KasirDashboard["Redirect ke /home\nDasbor Operasional KASIR"]
    subgraph Kasir_Scope["Hak Akses KASIR (Operasional Kasir)"]
        KasirDashboard --> KasirM1["Operasional Billing (Start, Extend, Stop)"]
        KasirDashboard --> KasirM2["Penjualan POS FnB & Input Pesanan"]
        KasirDashboard --> KasirM3["Proses Pembayaran (Tunai, E-Wallet, Transfer)"]
        KasirDashboard --> KasirM4["Cetak Struk Transaksi (Print Receipt)"]
        KasirDashboard --> KasirM5["Input Pengeluaran Kasir (Expense)"]
        KasirDashboard -.-> KasirDeny["DILARANG:\n- Menghapus Transaksi\n- Mengubah Master Tarif / Stok\n- Mengakses Registry & Mapping IoT\n- Melakukan Refund Transaksi"]
    end

    classDef owner fill:#e8f5e9,stroke:#2e7d32,stroke-width:1.5px;
    classDef admin fill:#e1f5fe,stroke:#0277bd,stroke-width:1.5px;
    classDef kasir fill:#fff8e1,stroke:#f57f17,stroke-width:1.5px;
    classDef deny fill:#ffebee,stroke:#c62828,stroke-width:1px;

    class OwnerDashboard,OwnerM1,OwnerM2,OwnerM3,OwnerM4 owner;
    class AdminDashboard,AdminM1,AdminM2,AdminM3,AdminM4,AdminM5,AdminM6 admin;
    class KasirDashboard,KasirM1,KasirM2,KasirM3,KasirM4,KasirM5 kasir;
    class OwnerDeny,AdminDeny,KasirDeny deny;
```

### Penjelasan Alur
1. Pengguna membuka halaman login pada URL `http://127.0.0.1:8008/login` dan memasukkan email serta password terdaftar.
2. Controller `LoginController` memvalidasi kredensial pengguna terhadap tabel `users`.
3. Setelah login berhasil, middleware `CheckRole` memeriksa peran pengguna yang tersimpan di kolom `users.role`.
4. Pengguna diarahkan ke dasbor utama `http://127.0.0.1:8008/home`. Menu pada sidebar disesuaikan secara dinamis menggunakan direktif Blade `@if(auth()->user()->isAdmin())`, `@if(auth()->user()->isKasir())`, dan `@if(auth()->user()->isOwner())`.
5. **Kebijakan Refund:** Seluruh transaksi yang telah lunas bertatus `payment_status = 'paid'` bersifat mengikat dan final. Tidak ada endpoint refund di dalam aplikasi.

### Role Pengguna
- **Semua Pengguna Terdaftar:** OWNER, ADMIN, KASIR.

### Link Website
- Halaman Login: [http://127.0.0.1:8008/login](http://127.0.0.1:8008/login)
- Dasbor Utama: [http://127.0.0.1:8008/home](http://127.0.0.1:8008/home)

### Data yang Digunakan
- Model: `App\Models\User`
- Kolom: `users.email`, `users.password`, `users.role`, `users.work_shift_id`.

### Proses Backend
- `App\Http\Controllers\Auth\LoginController::login()`
- `App\Http\Middleware\CheckRole::handle()`
- `App\Models\User::isAdmin()`, `isOwner()`, `isKasir()`.

### Hasil Akhir
- Pengguna terotentikasi memiliki sesi web aktif dan dapat melihat menu sidebar sesuai hak akses perannya.

### Kondisi Gagal
- Jika pengguna mencoba mengakses route di luar hak perannya (misal Kasir membuka `/users`), middleware melempar HTTP 403 (*Unauthorized action*).

### Status Implementasi
**IMPLEMENTED & VERIFIED**

---

## DIAGRAM 3 — PETA SITUS & NAVIGASI ANTARMUKA (WEBSITE SITEMAP)

### Nama Proses
Hierarki Navigasi Antarmuka Pengguna & Struktur Menu Website

### Tujuan
Menampilkan visualisasi seluruh menu dan sub-menu yang tersedia di antarmuka aplikasi Sugeng Rawuh berdasarkan file layout `resources/views/layouts/sidebar.blade.php` dan `routes/web.php`.

### Diagram Mermaid

```mermaid
flowchart LR
    Root["Sistem Kasir Sugeng Rawuh\nhttp://127.0.0.1:8008"] --> LoginNode["/login (Masuk Akun)"]
    LoginNode --> HomeNode["/home (Dasbor Statistik)"]

    HomeNode --> Modul_Operasional["Modul Operasional\n(Admin + Kasir + Owner)"]
    HomeNode --> Modul_AdminMenu["Modul Admin Menu\n(Khusus Admin)"]
    HomeNode --> Modul_Hardware["Modul Hardware IoT\n(Admin + Owner)"]
    HomeNode --> Modul_FnB["Modul Kelola FnB\n(Admin + Owner)"]
    HomeNode --> Modul_Expense["Modul Pengeluaran\n(Admin + Kasir + Owner)"]
    HomeNode --> Modul_Laporan["Modul Laporan\n(Admin + Owner)"]

    subgraph Sub_Operasional["Operasional Harian"]
        Modul_Operasional --> OP1["/device\nData Perangkat / Stasiun"]
        Modul_Operasional --> OP2["/transaction\nDaftar Transaksi Billing & FnB"]
        Modul_Operasional --> OP3["/transaction/create\nBuka Billing / Transaksi Baru"]
        Modul_Operasional --> OP4["/transaction/{id}/payment\nKasir Checkout & Bayar"]
    end

    subgraph Sub_AdminMenu["Konfigurasi & Master Data"]
        Modul_AdminMenu --> AD1["/users\nManajemen User & Akun"]
        Modul_AdminMenu --> AD2["/work_shifts\nManajemen Jam Kerja Shift"]
        Modul_AdminMenu --> AD3["/playstation\nJenis Playstation & Tarif"]
        Modul_AdminMenu --> AD4["/custom-package\nPaket Main Kustom"]
        Modul_AdminMenu --> AD5["/settings\nPengaturan Aplikasi & Reset"]
    end

    subgraph Sub_Hardware["Manajemen Hardware IoT"]
        Modul_Hardware --> HW1["/admin/hardware/registry\nDevice Registry (Samsung TV & Shelly)"]
        Modul_Hardware --> HW2["/admin/hardware/mappings\nStation Hardware Mapping"]
        Modul_Hardware --> HW3["/admin/hardware/simulator\nIoT Hardware Simulator (Testing)"]
        Modul_Hardware --> HW4["/admin/hardware/diagnostics\nIoT Protocol Diagnostics (Lab)"]
    end

    subgraph Sub_FnB["Point of Sales FnB"]
        Modul_FnB --> FB1["/fnb\nKelola Barang Makanan/Minuman"]
        Modul_FnB --> FB2["/price-group\nKelompok Harga Multi-Tier"]
        Modul_FnB --> FB3["/stock\nManajemen Stok Masuk/Keluar"]
        Modul_FnB --> FB4["/fnb/laporan-penjualan\nLaporan Penjualan FnB"]
    end

    subgraph Sub_Expense["Biaya Operasional"]
        Modul_Expense --> EX1["/expense\nDaftar Pengeluaran Toko"]
        Modul_Expense --> EX2["/expense/create\nInput Catatan Pengeluaran Kasir"]
        Modul_Expense --> EX3["/expense-category\nKategori Pengeluaran (Admin Only)"]
    end

    subgraph Sub_Laporan["Audit & Analisis"]
        Modul_Laporan --> LP1["/report\nLaporan Penjualan & Grafik"]
        Modul_Laporan --> LP2["/report/transaction\nHistori Detail Transaksi"]
        Modul_Laporan --> LP3["/generate-excel\nEkspor Laporan Format Excel"]
        Modul_Laporan --> LP4["/generate-pdf\nEkspor Laporan Format PDF"]
    end
```

### Penjelasan Alur
1. Setelah login, pengguna diarahkan ke dasbor pusat di URL `http://127.0.0.1:8008/home`.
2. Dari dasbor, sidebar membagi navigasi menjadi 6 modul utama:
   - **Operasional:** Diakses oleh Kasir, Admin, dan Owner untuk memonitor station dan transaksi berjalan.
   - **Admin Menu:** Khusus Admin untuk pengaturan pengguna, jam kerja, jenis konsol, dan custom package.
   - **Hardware IoT:** Untuk mengelola fisik TV Samsung dan Relay Shelly, pemetaan ke stasiun, serta pengujian diagnostik.
   - **Kelola FnB:** Untuk master produk, kelompok harga, mutasi stok, dan laporan penjualan FnB.
   - **Pengeluaran:** Untuk mencatat pengeluaran kas operasional rental.
   - **Laporan:** Khusus Admin dan Owner untuk mengevaluasi omzet, tren jam sibuk, dan mengekspor dokumen laporan.

### Role Pengguna
- **OWNER, ADMIN, KASIR** (visibilitas menu disesuaikan dengan RBAC).

### Link Website
- Rangkuman Route Map: [http://127.0.0.1:8008/home](http://127.0.0.1:8008/home)

### Data yang Digunakan
- Layout Blade: `resources/views/layouts/sidebar.blade.php`, `resources/views/layouts/app.blade.php`.

### Proses Backend
- Evaluasi middleware `auth` dan `check_role` di setiap rute web.

### Hasil Akhir
- Pengguna dapat bernavigasi ke seluruh modul yang menjadi hak aksesnya secara mulus.

### Kondisi Gagal
- Klik pada menu yang dibatasi menghasilkan pesan HTTP 403 jika URL diketik manual di browser.

### Status Implementasi
**IMPLEMENTED & VERIFIED**

---

## DIAGRAM 4 — ADMIN: MANAJEMEN STATION PLAYSTATION

### Nama Proses
Pengelolaan Master Data Station PlayStation & Konfigurasi Meja

### Tujuan
Menggambarkan alur kerja ADMIN dalam membuat stasiun rental baru, menentukan jenis konsol PlayStation (PS3, PS4, PS5) atau Meja Biliar, menentukan tarif sewa per jam, serta mengatur status kesiapan perangkat.

### Diagram Mermaid

```mermaid
flowchart TD
    AdminStart(["ADMIN Membuka Menu Data Perangkat\nhttp://127.0.0.1:8008/device"]) --> ViewStationList["Tampilan Tabel Data Perangkat\nMenampilkan Nama, Jenis PS, Status, & Aksi"]
    
    ViewStationList --> ActionChoice{"Pilih Tindakan ADMIN"}

    %% Tambah Station Baru
    ActionChoice -- "Tambah Station" --> OpenCreateForm["Klik '+ Tambah Perangkat'\nGET /device/create"]
    OpenCreateForm --> InputStationData["Form Tambah Stasiun:\n- Nama Stasiun (cth: 'PlayStation 01')\n- Jenis PlayStation (Pilih dari Master)\n- Status Awal ('Tersedia')"]
    InputStationData --> SubmitCreate{"Klik 'Simpan'\nPOST /device"}
    SubmitCreate --> ValidateInput{"Validasi Form:\n- nama: required|unique\n- playstation_id: required"}
    
    ValidateInput -- "Validasi Gagal" --> ShowCreateError["Kembali ke Form dengan Pesan Error"]
    ValidateInput -- "Validasi Berhasil" --> SaveStationDB["Simpan ke Tabel 'devices'\nSinkronkan pivot 'device_playstation'"]
    SaveStationDB --> FlashCreateSuccess["Flash Notifikasi Sukses:\n'Perangkat berhasil ditambahkan'"]
    FlashCreateSuccess --> ViewStationList

    %% Edit Station
    ActionChoice -- "Ubah Data Stasiun" --> OpenEditForm["Klik Tombol 'Edit'\nGET /device/{id}/edit"]
    OpenEditForm --> UpdateStationData["Ubah Nama atau Kategori PlayStation"]
    UpdateStationData --> SubmitUpdate{"Klik 'Update'\nPUT /device/{id}"}
    SubmitUpdate --> SaveStationUpdate["Update Database 'devices'"]
    SaveStationUpdate --> FlashUpdateSuccess["Flash Notifikasi Sukses:\n'Perangkat berhasil diperbarui'"]
    FlashUpdateSuccess --> ViewStationList

    %% Hapus Station
    ActionChoice -- "Hapus Stasiun" --> CheckActiveTx{"Periksa Apakah Stasiun Memiliki\nTransaksi Aktif?"}
    CheckActiveTx -- "Ada Transaksi Berjalan" --> DenyDelete["Gagal Dihapus!\nStasiun sedang digunakan dalam transaksi"]
    CheckActiveTx -- "Stasiun Bebas" --> ConfirmDelete{"Konfirmasi Dialog Hapus\nDELETE /device/{id}"}
    ConfirmDelete --> DeleteStationDB["Hapus Record dari 'devices'\n& Relasi Pivot Terkait"]
    DeleteStationDB --> FlashDeleteSuccess["Flash Notifikasi: 'Perangkat dihapus'"]
    FlashDeleteSuccess --> ViewStationList
```

### Penjelasan Alur
1. ADMIN membuka halaman **Data Perangkat** melalui URL `http://127.0.0.1:8008/device`.
2. Untuk membuat stasiun baru, klik tombol **+ Tambah Perangkat** (`/device/create`).
3. Masukkan nama stasiun (misal `PlayStation 01`), pilih konsol dari master data jenis PlayStation (misal `PS5`), dan simpan.
4. Data stasiun disimpan ke tabel `devices` dan tabel pivot `device_playstation`.
5. Jika stasiun ingin dihapus, sistem memastikan stasiun tidak sedang memiliki transaksi billing aktif bertatus `RUNNING` untuk menjaga integritas data keuangan.

### Role Pengguna
- **ADMIN** (Penuh CRUD: Create, Read, Update, Delete).
- **KASIR & OWNER** (Read-Only: hanya melihat daftar stasiun).

### Link Website
- Daftar Stasiun: [http://127.0.0.1:8008/device](http://127.0.0.1:8008/device)
- Tambah Stasiun: [http://127.0.0.1:8008/device/create](http://127.0.0.1:8008/device/create)

### Data yang Digunakan
- Model: `App\Models\Device`, `App\Models\Playstation`.
- Tabel: `devices`, `playstations`, `device_playstation`.

### Proses Backend
- `App\Http\Controllers\DeviceController::create()`
- `App\Http\Controllers\DeviceController::store()`
- `App\Http\Controllers\DeviceController::edit()`
- `App\Http\Controllers\DeviceController::update()`
- `App\Http\Controllers\DeviceController::destroy()`

### Hasil Akhir
- Stasiun terdaftar dalam sistem dan muncul di dasbor operasional kasir untuk siap disewa.

### Kondisi Gagal
- Jika nama stasiun duplikat atau konsol tidak dipilih, validasi form mengembalikan pesan error HTTP 422.

### Status Implementasi
**IMPLEMENTED & VERIFIED**

---

## DIAGRAM 5 — ADMIN: MANAJEMEN SAMSUNG SMART TV

### Nama Proses
Pendaftaran & Registrasi Samsung Smart TV pada Device Registry

### Tujuan
Menggambarkan alur kerja ADMIN dalam mendaftarkan unit Samsung Smart TV 50U8500 ke dalam Device Registry sistem, validasi ketat brand dan MAC address, enkripsi token, hingga siap dipasangkan ke stasiun PlayStation. *(Catatan: Perangkat Coocaa ditolak otomatis oleh sistem).*

### Diagram Mermaid

```mermaid
flowchart TD
    AdminNav(["ADMIN Membuka Menu Hardware\nhttp://127.0.0.1:8008/admin/hardware/registry"]) --> ViewRegistry["Tampilan Halaman Device Registry\nMenampilkan Daftar Perangkat IoT Terdaftar"]
    
    ViewRegistry --> ClickAdd["Klik Tombol '+ Tambah Perangkat'\n(Buka Modal Form Registrasi)"]
    ClickAdd --> InputData["Input Data Perangkat:\n- Nama: 'Samsung Smart TV PS 01'\n- Kategori: 'SMART_TV'\n- Brand: 'SAMSUNG'\n- Model: '50U8500'\n- MAC Address: 'AA:BB:CC:DD:EE:11'\n- IP Address: '192.168.1.50'"]
    
    InputData --> SubmitRegistry{"Klik Tombol 'Simpan Perangkat'\nPOST /admin/hardware/devices"}
    
    SubmitRegistry --> ValidateReq{"Validasi Form Backend\nStorePhysicalDeviceRequest"}
    
    %% Validasi Brand (Coocaa Rejection)
    ValidateReq -- "Brand != 'SAMSUNG' / 'SHELLY'\n(Misal Coocaa)" --> RejectBrand["VALIDASI GAGAL (HTTP 422):\n'Brand tidak didukung.\nHanya SAMSUNG dan SHELLY yang diizinkan'"]
    RejectBrand --> ReturnModal["Kembali ke Form Modal"]

    ValidateReq -- "Format IP / MAC Tidak Valid" --> RejectNet["VALIDASI GAGAL (HTTP 422):\n'Format IP Address atau MAC Address salah'"]
    RejectNet --> ReturnModal

    ValidateReq -- "Validasi Lolos" --> CheckModelInvariant{"Validasi Invarian Model\nPhysicalDevice::validateBrandCategory()"}
    CheckModelInvariant -- "Kategori SMART_TV bukan SAMSUNG" --> ThrowModelErr["Exception: Kategori SMART_TV\nhanya mendukung brand SAMSUNG"]

    CheckModelInvariant -- "Invarian Terpenuhi" --> SaveDeviceDB["Simpan Record ke Tabel 'physical_devices':\n- connection_status = 'UNVERIFIED'\n- pairing_status = 'UNPAIRED'\n- is_active = true\n- verified_capabilities = ['GET_STATUS', 'MUTE', 'UNMUTE']\n- pairing_payload = null (cast: encrypted)"]

    SaveDeviceDB --> FlashSuccess["Respons JSON Sukses (201 Created):\n'Perangkat fisik berhasil didaftarkan'"]
    FlashSuccess --> ReloadTable["Tabel Device Registry Diperbarui:\nPerangkat Muncul dengan Status UNVERIFIED"]
    
    ReloadTable --> NextStepChoice{"Tindakan Lanjutan ADMIN"}
    NextStepChoice --> StepA["Jalankan Uji Diagnostik Probe\n(/admin/hardware/diagnostics)"]
    NextStepChoice --> StepB["Petakan ke Stasiun PlayStation\n(/admin/hardware/mappings)"]
```

### Penjelasan Alur
1. ADMIN membuka halaman **Device Registry** di URL `http://127.0.0.1:8008/admin/hardware/registry`.
2. Klik tombol **+ Tambah Perangkat** untuk membuka form modal pendaftaran perangkat keras fisik.
3. ADMIN memasukkan nama perangkat, memilih kategori `SMART_TV`, memilih brand `SAMSUNG`, model `50U8500`, memasukkan MAC Address dari stiker/pengaturan TV, serta IP Address lokal TV.
4. Request divalidasi oleh `StorePhysicalDeviceRequest`. Jika pengguna mencoba memasukkan brand selain `SAMSUNG` atau `SHELLY` (misal Coocaa), backend langsung menolak dengan status HTTP 422.
5. Data disimpan ke tabel `physical_devices`. Status awal perangkat adalah `UNVERIFIED` dan `UNPAIRED`.
6. Kolom `pairing_payload` dilindungi dengan enkripsi AES-256 bawaan Laravel dan tersembunyi dari output JSON (`$hidden`).

### Role Pengguna
- **ADMIN** (Pendaftaran, Perubahan, Penghapusan).
- **OWNER** (Read-Only: hanya melihat daftar perangkat).

### Link Website
- Device Registry: [http://127.0.0.1:8008/admin/hardware/registry](http://127.0.0.1:8008/admin/hardware/registry)

### Data yang Digunakan
- Model: `App\Models\PhysicalDevice`.
- Tabel: `physical_devices`.
- Kolom: `name`, `category`, `brand`, `model`, `mac_address`, `ip_address`, `connection_status`, `pairing_status`, `verified_capabilities`, `pairing_payload`.

### Proses Backend
- `App\Http\Controllers\Admin\PhysicalDeviceController::store()`
- `App\Http\Requests\StorePhysicalDeviceRequest::rules()`
- `App\Models\PhysicalDevice::validateBrandCategory()`

### Hasil Akhir
- Samsung Smart TV resmi terdaftar dalam registry dan siap untuk dipasangkan ke station PlayStation.

### Kondisi Gagal
- Jika IP Address atau MAC Address sudah terdaftar untuk perangkat lain, validasi *unique* menggagalkan penyimpanan.

### Status Implementasi
**IMPLEMENTED & VERIFIED**

---

## DIAGRAM 6 — SEKUEN KONEKSI & PAIRING SAMSUNG SMART TV

### Nama Proses
Protokol Komunikasi Jaringan, Probe, & Pairing Handshake Samsung Smart TV

### Tujuan
Menggambarkan urutan pertukaran pesan antara antarmuka Admin, backend Laravel, driver Samsung, router lokal, dan televisi fisik Samsung melalui protokol HTTP REST (port 8001) dan Secure WebSocket (port 8002).

### Diagram Mermaid

```mermaid
sequenceDiagram
    autonumber
    actor Admin as ADMIN
    participant Browser as Browser Admin (UI Diagnostics)
    participant Backend as Laravel Backend (DiagnosticsCtrl)
    participant Guard as HardwareSafetyGuard
    participant Driver as SamsungTvDriver
    participant Router as Router Jaringan Lokal
    participant TV as Samsung Smart TV 50U8500
    participant DB as MySQL (physical_devices)

    Note over Admin,Browser: Tahap 1: Pengujian Koneksi Jaringan (Probe)
    Admin->>Browser: Klik tombol "Probe Jaringan" (Device ID: 1)
    Browser->>Backend: POST /admin/hardware/diagnostics/probe {device_id: 1, mode: 'real'}
    Backend->>Guard: assertRealExecutionAllowed(device, 'PROBE')
    
    alt Guard Memblokir (HARDWARE_REAL_EXECUTION_ENABLED = false)
        Guard-->>Backend: Lempar HardwareSafetyException (HTTP 403 Forbidden)
        Backend-->>Browser: JSON: {success: false, message: "Real execution disabled by safety guard"}
        Browser-->>Admin: Tampilkan Badge Merah: "Koneksi Fisik Terkunci Safety Gate"
    else Guard Mengizinkan (Mode Lab / Real Execution Aktif)
        Guard-->>Backend: Diizinkan
        Backend->>Driver: probe(device)
        Driver->>Router: HTTP GET http://192.168.1.50:8001/api/v2/ (Timeout: 2s)
        Router->>TV: Teruskan Paket HTTP REST
        
        alt TV Tidak Menanggapi / Mati Total
            TV--xRouter: Tidak Ada Respon (Connection Refused / Timeout)
            Driver-->>Backend: ProbeResult(success: false, OFFLINE)
            Backend->>DB: UPDATE physical_devices SET connection_status = 'OFFLINE'
            Backend-->>Browser: JSON: {status: 'OFFLINE', message: 'TV tidak terdeteksi di jaringan'}
        else TV Hidup & Terkoneksi
            TV-->>Router: HTTP 200 OK {name: "Samsung 50U8500", PowerState: "on"}
            Router-->>Driver: HTTP Response
            Driver-->>Backend: ProbeResult(success: true, ONLINE)
            Backend->>DB: UPDATE physical_devices SET connection_status = 'ONLINE', last_seen_at = now()
            Backend-->>Browser: JSON: {status: 'ONLINE', message: 'TV terhubung normal'}
            Browser-->>Admin: Tampilkan Badge Hijau: "ONLINE"
        end
    end

    Note over Admin,Browser: Tahap 2: Otorisasi & Pairing Token (Handshake)
    Admin->>Browser: Klik tombol "Mulai Pairing Handshake"
    Browser->>Backend: POST /admin/hardware/diagnostics/pair {device_id: 1, mode: 'real'}
    Backend->>Driver: pair(device)
    Driver->>Router: Buka WebSocket wss://192.168.1.50:8002/api/v2/channels/samsung.remote.control?name=U3VnZW5nUmF3dWg=
    Router->>TV: Handshake WSS Connection
    
    TV-->>TV: Tampilkan Popup di Layar TV:\n"Izinkan Sugeng Rawuh Mengontrol TV?"
    
    alt Pemilik Rental / Kasir Menekan "TOLAK" pada Remote Fisik TV
        TV-->>Driver: Frame WebSocket: ms.channel.unauthorized
        Driver-->>Backend: PairResult(success: false, error: 'AUTH_REJECTED')
        Backend->>DB: UPDATE physical_devices SET pairing_status = 'FAILED'
        Backend-->>Browser: Tampilkan Peringatan: "Otorisasi ditolak di layar TV"
    else Pemilik Rental Menekan "IZINKAN" (Allow) pada Remote Fisik TV
        TV-->>Driver: Frame WebSocket: ms.channel.connect {data: {token: "samsung_enc_jwt_token_9988"}}
        Driver-->>Backend: PairResult(success: true, token: "samsung_enc_jwt_token_9988")
        Backend->>DB: UPDATE physical_devices SET pairing_status = 'PAIRED', pairing_payload = encrypt(token)
        Backend-->>Browser: JSON: {pairing: 'PAIRED', message: 'Pairing TV berhasil! Token tersimpan aman'}
        Browser-->>Admin: Tampilkan Badge Biru: "PAIRED & TEROTORISASI"
    end
```

### Penjelasan Alur
1. **Probe (Pemeriksaan Jaringan):** Admin menguji keterjangkauan TV melalui REST API port 8001. Driver mengirim HTTP GET ke endpoint `/api/v2/`. Jika TV merespons dalam 2 detik, status di database diperbarui menjadi `ONLINE`.
2. **Pairing Handshake:** Admin memicu proses pairing melalui antarmuka diagnostik.
3. Backend membuka koneksi aman WebSocket (*Secure WebSocket* wss) ke port 8002 TV Samsung dengan menyertakan nama aplikasi yang telah di-encode Base64 (`SugengRawuh`).
4. Di layar TV Samsung, muncul prompt pop-up perizinan akses remote.
5. Admin menekan tombol **Izinkan (Allow)** menggunakan remote fisik bawaan TV.
6. TV mengirimkan frame JSON `ms.channel.connect` yang memuat token otentikasi permanen.
7. Backend menangkap token tersebut, mengenkripsinya dengan `Crypt::encryptString()`, dan menyimpannya di kolom `physical_devices.pairing_payload`.
8. Status pairing berubah menjadi `PAIRED`.

### Role Pengguna
- **ADMIN** (Menjalankan probe dan pairing di lab diagnostik).

### Link Website
- Hardware Diagnostics: [http://127.0.0.1:8008/admin/hardware/diagnostics](http://127.0.0.1:8008/admin/hardware/diagnostics)

### Data yang Digunakan
- Model: `App\Models\PhysicalDevice`.
- Endpoint TV: `http://{ip}:8001/api/v2/` dan `wss://{ip}:8002/api/v2/channels/samsung.remote.control`.

### Proses Backend
- `App\Http\Controllers\Admin\HardwareDiagnosticsController::probe()`
- `App\Http\Controllers\Admin\HardwareDiagnosticsController::pair()`
- `App\Services\Hardware\Drivers\SamsungTvDriver::probe()`
- `App\Services\Hardware\Drivers\SamsungTvDriver::pair()`

### Hasil Akhir
- Token tersimpan aman di database terenkripsi, status koneksi TV tercatat `ONLINE`, dan pairing tercatat `PAIRED`.

### Kondisi Gagal
- Jika TV dimatikan dari saklar atau IP berubah, probe menghasilkan timeout dan status otomatis menjadi `OFFLINE`.

### Status Implementasi
**PHYSICAL VERIFICATION PENDING** (Software driver riil dan fake mock selesai; pengujian fisik menunggu Gate G4-B2).

---

## DIAGRAM 7 — KASIR: BILLING PREPAID PLAYSTATION

### Nama Proses
Alur Operasional Billing Paket Jam (Prepaid) PlayStation oleh Kasir

### Tujuan
Menggambarkan alur kerja petugas kasir saat melayani pelanggan rental PlayStation dengan sistem bayar di muka (prepaid), pembuatan sesi server-side, penjadwalan otomatisasi outbox, penanganan kemampuan TV Samsung, hingga masa sewa habis.

### Diagram Mermaid

```mermaid
flowchart TD
    KasirStart(["Pelanggan Datang Memesan PlayStation"]) --> KasirNav["Kasir Membuka Dasbor Stasiun\nhttp://127.0.0.1:8008/device"]
    
    KasirNav --> SelectStation["Kasir Memilih Stasiun Tersedia\n(Contoh: 'PlayStation 01' - Status: 'Tersedia')"]
    SelectStation --> ClickStart["Klik Tombol 'Mulai Billing'\n(Buka Form Transaksi Rental)"]
    
    ClickStart --> InputPrepaid["Pilih Jenis: 'Paket Jam (Prepaid)'\n- Pilih Durasi: 1 Jam, 2 Jam, dst.\n- Input Nama Pelanggan (Opsional)\n- Input Tambahan FnB (Opsional)"]
    
    InputPrepaid --> SubmitBilling{"Kasir Klik 'Mulai Rental'\nPOST /device/start"}
    
    SubmitBilling --> DB_Lock["BillingService: Lock Stasiun 'devices'\nSELECT ... FOR UPDATE"]
    DB_Lock --> CheckAvail{"Cek Ketersediaan Stasiun\n(status == 'Tersedia')"}
    
    CheckAvail -- "Stasiun Tidak Tersedia" --> AbortStart["Batalkan Transaksi!\nKembalikan Error: Stasiun sedang digunakan"]
    
    CheckAvail -- "Stasiun Tersedia" --> CalcTime["Kalkulasi Waktu Absolut Server:\n- started_at = now()\n- expires_at = now() + durasi\n- waktu_Selesai = expires_at.format('H:i')\n- total = tarif_per_jam * durasi"]
    
    CalcTime --> AtomicTx["Simpan Transaksi ke 'transactions':\n- billing_status = 'RUNNING'\n- tipe_transaksi = 'prepaid'\n- payment_status = 'unpaid'\nUpdate Stasiun: status = 'Digunakan'\nIncrement: session_generation += 1"]
    
    AtomicTx --> CreateOutbox["AutomationIntentService: Catat Outbox Intent\n1. Intent STARTED (Desired: ON, Scheduled: now())\n2. Intent EXPIRED (Desired: OFF, Scheduled: expires_at)"]
    
    CreateOutbox --> KasirView["Layar Kasir Menampilkan Timer Aktif:\n- Status: DIGUNAKAN (Warna Merah)\n- Hitung Mundur Sisa Waktu Berjalan"]

    %% Jalur Otomasi Background
    CreateOutbox -.-> WorkerLoop["Background Worker Memproses Intent STARTED"]
    WorkerLoop --> EvalPolicy{"Evaluasi AutomationCapabilityPolicy\nuntuk Samsung Smart TV"}
    EvalPolicy -- "Perintah Daya Belum Terverifikasi Fisik" --> SkipPower["Tandai Intent: SKIPPED_UNVERIFIED_CAPABILITY\nCatat Audit Log (Tidak Mengirim Sinyal Bahaya)"]
    SkipPower --> KasirManualAction["KASIR MENYALAKAN TV:\nKasir Menekan Tombol Power pada Remote Fisik Samsung"]

    %% Siklus Waktu Berjalan
    KasirView --> TimeElapses["Pelanggan Bermain Game...\nWaktu Bergerak Menuju Expiration"]
    TimeElapses --> TimeExpired{"Waktu Server Mencapai expires_at\n(evaluated by worker)"}

    TimeExpired --> ProcessExpiry["BillingService::evaluateExpiration():\n- Update Transaksi: billing_status = 'EXPIRED_PENDING_ACTION'\n- Update Transaksi: status_transaksi = 'selesai'\n- Update Stasiun: status = 'Tersedia'"]
    
    ProcessExpiry --> AlertKasir["Layar Kasir Berbunyi / Menampilkan Notifikasi:\n'WAKTU HABIS - STASIUN PLAYSTATION 01'"]
    AlertKasir --> KasirRemoteOff["Kasir Mematikan TV Menggunakan Remote Fisik Samsung\n(Atau Menawarkan Perpanjangan Waktu)"]
```

### Penjelasan Alur
1. Kasir membuka menu **Data Perangkat** di URL `http://127.0.0.1:8008/device`.
2. Kasir memilih stasiun PlayStation yang berstatus **Tersedia** (berwarna hijau).
3. Pada form transaksi, kasir memilih jenis transaksi **prepaid**, menentukan durasi main (misal 2 jam), dan klik **Mulai Rental**.
4. `BillingService::startSession()` mengunci baris stasiun dengan *pessimistic lock*, menghitung `started_at` dan `expires_at` berdasarkan jam server, menyimpan transaksi dengan status `RUNNING`, serta menaikkan nilai `session_generation` stasiun.
5. Dua intent outbox dibuat secara atomik: `BILLING_STARTED` (segera) dan `BILLING_EXPIRED` (dijadwalkan pada `expires_at`).
6. Worker membaca intent `BILLING_STARTED`. Karena TV Samsung memiliki sifat remote *toggle*, `AutomationCapabilityPolicy` menandai intent sebagai `SKIPPED_UNVERIFIED_CAPABILITY`. Kasir menyalakan TV menggunakan remote fisik.
7. Ketika waktu sewa habis di server, worker mengubah status sesi menjadi `EXPIRED_PENDING_ACTION`. Stasiun menjadi `Tersedia` dan kasir menagih pembayaran jika belum lunas.

### Role Pengguna
- **KASIR** (Melakukan input dan konfirmasi billing).

### Link Website
- Dasbor Operasional Stasiun: [http://127.0.0.1:8008/device](http://127.0.0.1:8008/device)

### Data yang Digunakan
- Model: `Device`, `Transaction`, `AutomationIntent`.
- Kolom: `transactions.billing_status`, `transactions.started_at`, `transactions.expires_at`, `devices.session_generation`.

### Proses Backend
- `App\Http\Controllers\DeviceController::index()`
- `App\Services\BillingService::startSession()`
- `App\Services\Hardware\Automation\AutomationIntentService::recordIntent()`
- `App\Services\BillingService::evaluateExpiration()`

### Hasil Akhir
- Sesi sewa terdaftar resmi, jam mulai dan jam selesai tercatat akurat, stasiun berstatus merah (Digunakan).

### Kondisi Gagal
- Jika kasir lain mencoba memulai stasiun yang sama secara bersamaan, *pessimistic lock* menggagalkan transaksi kedua dengan notifikasi stasiun tidak tersedia.

### Status Implementasi
**IMPLEMENTED & VERIFIED**

---

## DIAGRAM 8 — KASIR: BILLING LOST TIME (POSTPAID) PLAYSTATION

### Nama Proses
Alur Operasional Billing Bebas Waktu (Lost Time / Postpaid) PlayStation

### Tujuan
Menggambarkan alur sewa tanpa batasan durasi di awal, di mana durasi berjalan dihitung secara progresif oleh server hingga pelanggan memutuskan selesai main, lalu kasir menekan tombol Stop untuk kalkulasi biaya akhir.

### Diagram Mermaid

```mermaid
flowchart TD
    StartLostTime(["Pelanggan Datang Meminta 'Main Sepuasnya' (Lost Time)"]) --> KasirNav["Kasir Membuka Dasbor Stasiun\nhttp://127.0.0.1:8008/device"]
    
    KasirNav --> SelectStation["Kasir Memilih Stasiun Tersedia\n(Contoh: 'PlayStation 02')"]
    SelectStation --> ChoosePostpaid["Pilih Jenis Billing: 'Lost Time (Postpaid)'\n- Input Nama Pelanggan\n- Tarif Dasar Per Jam Ditampilkan"]
    
    ChoosePostpaid --> SubmitStart{"Kasir Klik 'Mulai Main'\nPOST /device/start"}
    
    SubmitStart --> DB_Lock["BillingService: Lock Stasiun 'devices'\nSELECT ... FOR UPDATE"]
    DB_Lock --> SavePostpaid["Simpan Transaksi 'transactions':\n- tipe_transaksi = 'postpaid'\n- billing_status = 'RUNNING'\n- started_at = now()\n- lost_time_start = now()\n- expires_at = NULL (Tidak Ada Expiry)\n- status_transaksi = 'berjalan'\nUpdate Stasiun: status = 'Digunakan'"]
    
    SavePostpaid --> StartIntent["AutomationIntentService: Catat Intent\nEvent: BILLING_STARTED (State: ON)"]
    
    StartIntent --> KasirTimer["Layar Kasir Menampilkan Stopwatch Berjalan:\n- Durasi Bertambah: 00:01, 00:02, dst.\n- Indikator: LOST TIME AKTIF"]

    KasirTimer --> PlayingSession["Pelanggan Bermain Bebas...\n(Bisa Pesan FnB Tambahan Kapan Saja)"]

    PlayingSession --> CustomerDone(["Pelanggan Datang ke Kasir: 'Selesai Main'"])
    CustomerDone --> ClickStop["Kasir Klik Tombol 'Stop'\nPOST /device/{id}/stop"]

    ClickStop --> LockStopSession["BillingService::stopSession():\nLock Transaksi & Stasiun"]
    LockStopSession --> CalcDuration["Hitung Durasi Aktual:\nduration_minutes = diffInMinutes(started_at, now())"]
    
    CalcDuration --> CalcPrice["Kalkulasi Tarif Lost Time:\n- Hitung Biaya Proporsional Per Menit\n- Terapkan Pembulatan Tarif (Lost Time Rounding)\n- Tambahkan Total Pesanan FnB\n- Format: 'X jam Y menit'"]

    CalcPrice --> FinalizeTx["Update Transaksi:\n- billing_status = 'COMPLETED'\n- status_transaksi = 'selesai'\n- ended_at = now()\n- total = tarif_ps + total_fnb\nUpdate Stasiun: status = 'Tersedia'"]

    FinalizeTx --> StopIntent["AutomationIntentService: Catat Intent\nEvent: BILLING_STOPPED (State: OFF)"]
    
    StopIntent --> RedirectPayment["Redirect Kasir ke Halaman Pembayaran:\nGET /transaction/{id}/payment\nMenampilkan Rincian Tagihan Akhir"]
```

### Penjelasan Alur
1. Kasir memilih stasiun yang tersedia dan memilih opsi billing **Lost Time (Postpaid)**.
2. `BillingService::startSession()` menginisialisasi transaksi dengan nilai `expires_at = null`, `status_transaksi = 'berjalan'`, dan mencatat `lost_time_start`.
3. Timer di dasbor kasir bertindak sebagai penghitung maju (*stopwatch*).
4. Pelanggan dapat bermain tanpa batas waktu dan memesan makanan/minuman yang langsung ditambahkan ke tagihan sesi berjalan.
5. Ketika pelanggan selesai, kasir menekan tombol **Stop** pada stasiun tersebut.
6. `BillingService::stopSession()` menghitung selisih menit antara `started_at` dan waktu selesai saat ini, mengalikan dengan tarif per menit, menerapkan aturan pembulatan resmi rental, menambahkan tagihan FnB, dan menandai transaksi `COMPLETED`.
7. Sistem langsung mengarahkan kasir ke halaman pembayaran (`/transaction/{id}/payment`).

### Role Pengguna
- **KASIR** (Memulai dan menghentikan sesi Lost Time).

### Link Website
- Dasbor Stasiun: [http://127.0.0.1:8008/device](http://127.0.0.1:8008/device)
- Halaman Pembayaran: [http://127.0.0.1:8008/transaction/{id}/payment](http://127.0.0.1:8008/transaction/{id}/payment)

### Data yang Digunakan
- Model: `Transaction`, `Device`, `Playstation`.
- Kolom: `transactions.lost_time_start`, `transactions.started_at`, `transactions.ended_at`, `transactions.total`.

### Proses Backend
- `App\Services\BillingService::startSession()`
- `App\Services\BillingService::stopSession()`
- `App\Services\BillingService::calculateLostTimePrice()`
- `App\Services\BillingService::applyLostTimeRounding()`

### Hasil Akhir
- Durasi total dan tagihan final terhitung otomatis tanpa risiko kesalahan hitung manual oleh kasir.

### Kondisi Gagal
- Jika kasir menekan Stop dua kali berturut-turut, proteksi idempoten di `stopSession()` memastikan kalkulasi biaya tidak terduplikasi.

### Status Implementasi
**IMPLEMENTED & VERIFIED**

---

## DIAGRAM 9 — KASIR: PERPANJANGAN WAKTU (EXTEND) & PENGHENTIAN DINI (STOP SESSION)

### Nama Proses
Manajemen Sesi Berjalan: Perpanjangan Waktu (Extend) & Selesai Lebih Awal (Stop Early)

### Tujuan
Menggambarkan logika internal sistem saat kasir menambah waktu sewa (extend) atau menghentikan sesi sewa sebelum waktunya habis (stop early), pembatalan intent lama, peningkatan *session generation*, dan pencegahan *stale commands* agar stasiun tidak mati tiba-tiba pada pelanggan berikutnya.

### Diagram Mermaid

```mermaid
flowchart TD
    ActiveSession["Sesi Billing Stasiun Sedang Berjalan\n(billing_status == 'RUNNING', session_generation = N)"] --> KasirDecision{"Keputusan Pelanggan di Meja"}

    %% CABANG PERPANJANGAN (EXTEND)
    KasirDecision -- "Pelanggan Minta Tambah Waktu (Extend)" --> KasirExtendClick["Kasir Klik Tombol 'Tambah Waktu'\nPOST /device/{id}/booking-add"]
    KasirExtendClick --> InputUnits["Input Tambahan Durasi (Contoh: +1 Jam)"]
    InputUnits --> SubmitExtend{"Klik 'Simpan Tambahan'\nPOST /device/{id}/add"}
    
    SubmitExtend --> LockTxExtend["BillingService::extendSession():\nLock Transaksi & Stasiun (FOR UPDATE)"]
    LockTxExtend --> CalcNewExpiry["Hitung Expiry Baru:\nnew_expires_at = old_expires_at + tambahan_waktu\nUpdate total biaya transaksi"]
    
    CalcNewExpiry --> BumpGenExtend["PROTEKSI FENCING G5:\n1. Stasiun: session_generation += 1 (Menjadi N+1)\n2. Transaksi: session_generation = N+1"]
    
    BumpGenExtend --> CancelOldIntent["AutomationIntentService:\nBatalkan (CANCEL) Intent OFF Lama yang Terjadwal"]
    CancelOldIntent --> ScheduleNewIntent["Jadwalkan Intent OFF Baru:\nEvent: BILLING_EXPIRED (Scheduled: new_expires_at)\nGeneration: N+1"]
    
    ScheduleNewIntent --> ExtendSuccess["Perpanjangan Selesai!\nTimer di Kasir Otomatis Bertambah"]

    %% CABANG STOP DINI (STOP EARLY)
    KasirDecision -- "Pelanggan Minta Sudahi Lebih Awal (Stop Early)" --> KasirStopClick["Kasir Klik Tombol 'Stop'\nPOST /device/{id}/stop"]
    
    KasirStopClick --> LockTxStop["BillingService::stopSession():\nLock Transaksi & Stasiun (FOR UPDATE)"]
    LockTxStop --> VerifyEarly{"Cek Waktu Selesai vs expires_at\n(now() < expires_at?)"}
    
    VerifyEarly -- "Ya (Stop Early)" --> SetStopEarlyStatus["Set billing_status = 'STOPPED_EARLY'\nended_at = now()"]
    VerifyEarly -- "Tidak (Sudah Pas / Lewat)" --> SetCompletedStatus["Set billing_status = 'COMPLETED'\nended_at = now()"]
    
    SetStopEarlyStatus --> ReleaseStation["Bebaskan Stasiun:\nUpdate devices SET status = 'Tersedia'"]
    SetCompletedStatus --> ReleaseStation

    ReleaseStation --> BumpGenStop["PROTEKSI FENCING G5:\nStasiun: session_generation += 1 (Menjadi N+1)"]
    
    BumpGenStop --> CancelPreScheduled["Batalkan Intent EXPIRED Lama yang Masih PENDING"]
    CancelPreScheduled --> DispatchImmediateOff["Buat Intent OFF Segera (Event: BILLING_STOPPED)"]
    
    DispatchImmediateOff --> RedirectPay["Arahkan Kasir ke Pembayaran / Selesai"]

    %% KOTAK PENJELASAN PENCEGAHAN BAHAYA
    subgraph Fencing_Protection["Keunggulan Arsitektur: Session Generation Fencing"]
        FencingNote["Jika ada worker yang terlambat bangun membaca Intent OFF lama (Gen N),\nFencing Guard memeriksa session_generation aktif stasiun (sudah N+1).\nPerintah lama DITOLAK SEKETIKA (SKIPPED_STALE_SESSION).\nPerangkat pelanggan baru TIDAK AKAN PERNAH mati mendadak!"]
    end
    BumpGenExtend -.-> FencingNote
    BumpGenStop -.-> FencingNote
```

### Penjelasan Alur
1. **Perpanjangan Waktu (Extend):**
   - Kasir memilih stasiun aktif dan menginput tambahan jam sewa.
   - `BillingService::extendSession()` menghitung waktu berakhir baru (`new_expires_at = old_expires_at + tambahan`).
   - Sistem menaikkan *session generation* stasiun secara atomik (`session_generation = N + 1`).
   - Intent matikan perangkat lama yang berstatus `PENDING` otomatis dibatalkan (`CANCELLED`).
   - Dibuat intent matikan baru yang dijadwalkan pada waktu kedaluwarsa baru (`new_expires_at`).
2. **Penghentian Dini (Stop Early):**
   - Jika pelanggan berhenti sebelum paket habis, kasir menekan tombol **Stop**.
   - `BillingService::stopSession()` menetapkan status sesi menjadi `STOPPED_EARLY`, menaikkan *session generation* stasiun, dan membatalkan jadwal mati lama.
3. **Pencegahan Perintah Basi (*Stale Command Protection*):**
   - Setiap generasi stasiun dijamin unik. Jika worker mengalami *lag* dan mencoba mengeksekusi instruksi dari sesi sebelumnya, `AutomationFencingGuard` membandingkan generasi sesi dan membatalkan eksekusi, menjamin pelanggan berikutnya tidak terganggu.

### Role Pengguna
- **KASIR** (Melakukan extend dan stop manual).

### Link Website
- Dasbor Stasiun: [http://127.0.0.1:8008/device](http://127.0.0.1:8008/device)

### Data yang Digunakan
- Model: `Transaction`, `Device`, `AutomationIntent`.
- Kolom: `devices.session_generation`, `transactions.session_generation`, `automation_intents.session_generation`.

### Proses Backend
- `App\Services\BillingService::extendSession()`
- `App\Services\BillingService::stopSession()`
- `App\Services\Hardware\Automation\AutomationFencingGuard::validateIntent()`

### Hasil Akhir
- Transaksi diperbarui secara akurat, intent lama dibersihkan, dan kontinuitas daya listrik stasiun terproteksi 100%.

### Kondisi Gagal
- Sesi yang sudah bertatus `COMPLETED` atau `STOPPED_EARLY` tidak dapat diperpanjang kembali.

### Status Implementasi
**IMPLEMENTED & VERIFIED**

---

## DIAGRAM 10 — OTOMATISASI MEJA BILIAR & SHELLY RELAY

### Nama Proses
Otomatisasi Lampu Meja Biliar Berdasarkan Keputusan Bisnis SR-G5-BR-001

### Tujuan
Menggambarkan integrasi antara sesi rental meja biliar dan Smart Relay Shelly 1PM Mini Gen3, di mana lampu meja biliar otomatis menyala saat mulai dan **langsung mati seketika tanpa masa tenggang saat waktu habis**.

### Diagram Mermaid

```mermaid
sequenceDiagram
    autonumber
    actor Kasir as Petugas Kasir
    participant Web as Kasir Browser
    participant Billing as BillingService
    participant DB as MySQL Database
    participant Worker as Automation Worker
    participant Dispatcher as AutomationDispatcherService
    participant Guard as AutomationFencingGuard
    participant ShellyDriver as ShellyRelayDriver
    participant Relay as Shelly 1PM Mini Gen3 (Wi-Fi)
    participant Lampu as Lampu Meja Biliar (220V)

    Note over Kasir,Lampu: Fase 1: Memulai Sesi Biliar (Start Billing)
    Kasir->>Web: Pilih Meja Biliar 01 -> Paket 1 Jam -> Klik "Mulai Billing"
    Web->>Billing: POST /device/start {device_id: 10, jam_main: 1, tipe: 'prepaid'}
    
    activate Billing
    Billing->>DB: Lock Station & Simpan Transaksi (RUNNING, Gen: 1)
    Billing->>DB: Simpan Outbox Intent:\n1. BILLING_STARTED (State: ON, Scheduled: now())\n2. BILLING_EXPIRED (State: OFF, Scheduled: now() + 1 Jam)
    Billing-->>Web: Konfirmasi Sukses (Timer Berjalan)
    deactivate Billing

    Worker->>DB: Poll Intent PENDING (Event: BILLING_STARTED)
    Worker->>Dispatcher: dispatch(intent)
    Dispatcher->>Guard: validateIntent(intent)
    Guard-->>Dispatcher: Intent Valid (Generation Sesuai)
    Dispatcher->>ShellyDriver: execute(device, 'SWITCH_ON')
    ShellyDriver->>Relay: HTTP POST http://192.168.1.60/rpc/Switch.Set {id: 0, on: true}
    Relay->>Lampu: Tutup Kontak Relay -> Arus Listrik Mengalir
    Lampu-->>Lampu: LAMPU MEJA BILIAR MENYALA (TERANG)
    Relay-->>ShellyDriver: HTTP 200 OK {ison: true, power: 85.4}
    ShellyDriver-->>Dispatcher: DriverResponse(SUCCESS)
    Dispatcher->>DB: Update Intent COMPLETED & Catat Execution Log

    Note over Kasir,Lampu: Fase 2: Waktu Habis (Keputusan Bisnis SR-G5-BR-001)
    Note over Worker,Lampu: ATURAN MUTLAK: Tidak ada grace period & Tidak ada konfirmasi kasir!
    
    Worker->>DB: Waktu server mencapai expires_at (Scheduled_at <= now())
    Worker->>DB: Fetch Intent PENDING (Event: BILLING_EXPIRED, State: OFF)
    Worker->>Dispatcher: dispatch(intent)
    Dispatcher->>Guard: validateIntent(intent)
    Guard-->>Dispatcher: Intent Valid (Generation Masih Sesuai)
    Dispatcher->>ShellyDriver: execute(device, 'SWITCH_OFF')
    ShellyDriver->>Relay: HTTP POST http://192.168.1.60/rpc/Switch.Set {id: 0, on: false}
    Relay->>Lampu: Buka Kontak Relay -> Arus Listrik Terputus
    Lampu-->>Lampu: LAMPU MEJA BILIAR MATI SEKETIKA (GELAP)
    Relay-->>ShellyDriver: HTTP 200 OK {ison: false, power: 0.0}
    ShellyDriver-->>Dispatcher: DriverResponse(SUCCESS)
    Dispatcher->>DB: Update Intent COMPLETED & Catat Execution Log
    
    Worker->>Web: Update Widget Dasbor Kasir via Polling API
    Web-->>Kasir: Lampu Meja Biliar 01 Berhasil Dimatikan Otomatis
```

### Penjelasan Alur
1. Kasir memulai sesi prepaid meja biliar.
2. `BillingService` membuat transaksi dan dua intent outbox: `BILLING_STARTED` (segera) dan `BILLING_EXPIRED` (pada saat waktu sewa habis).
3. Background worker membaca intent `BILLING_STARTED`, memanggil `ShellyRelayDriver`, dan mengirimkan request HTTP JSON-RPC 2.0 ke modul Shelly di IP `192.168.1.60` (`Switch.Set` with `on: true`).
4. Relay Shelly menutup kontak saklar listrik dan lampu meja biliar menyala terang.
5. **Penegakan Keputusan Bisnis SR-G5-BR-001:** Saat jam server mencapai waktu kedaluwarsa, worker mengambil intent `BILLING_EXPIRED` dan mengirimkan `Switch.Set` with `on: false`.
6. Lampu meja biliar **langsung mati seketika**, memastikan tidak ada pelanggan yang bermain melebihi batas waktu yang telah dibayar.
7. Kasir melihat status stasiun kembali `Tersedia` pada dasbor.

### Role Pengguna
- **KASIR** (Memulai billing meja biliar).
- **AUTOMATION WORKER** (Mengeksekusi perintah ON/OFF relay secara otonom).

### Link Website
- Dasbor Stasiun Biliar: [http://127.0.0.1:8008/device](http://127.0.0.1:8008/device)
- Telemetri Log Otomasi: [http://127.0.0.1:8008/admin/hardware/automation/logs](http://127.0.0.1:8008/admin/hardware/automation/logs)

### Data yang Digunakan
- Model: `Device`, `StationDeviceMapping`, `PhysicalDevice`, `AutomationIntent`.
- Protokol: Shelly Gen3 HTTP JSON-RPC 2.0 (`/rpc/Switch.Set`).

### Proses Backend
- `App\Services\Hardware\Drivers\ShellyRelayDriver::switchOn()`
- `App\Services\Hardware\Drivers\ShellyRelayDriver::switchOff()`
- `App\Services\Hardware\Automation\AutomationDispatcherService::execute()`

### Hasil Akhir
- Pengendalian lampu meja biliar berlangsung otomatis 100% tepat waktu tanpa intervensi manual kasir.

### Kondisi Gagal
- Jika Shelly relay kehilangan sinyal Wi-Fi, worker mencoba retry hingga batas `max_attempts` dan mencatat status `FAILED` di log audit tanpa menghentikan billing kasir.

### Status Implementasi
**IMPLEMENTED & VERIFIED (SOFTWARE MOCK)**; Integrasi perangkat keras fisik nyata menunggu Gate G4-B2.

---

## DIAGRAM 11 — POS F&B TRANSACTION FLOW

### Nama Proses
Alur Transaksi Penjualan Makanan & Minuman (Point of Sales FnB)

### Tujuan
Menggambarkan alur kasir dalam menginput pesanan makanan/minuman, memilih produk, memeriksa ketersediaan stok secara atomik, mencatat mutasi stok keluar, serta menggabungkan pesanan ke dalam transaksi billing atau sebagai pesanan tersendiri.

### Diagram Mermaid

```mermaid
flowchart TD
    KasirPOS(["Kasir Membuka Modul Transaksi"]) --> ChooseEntry{"Pilih Jalur Pemesanan FnB"}

    %% Jalur 1: Digabung ke Billing Berjalan
    ChooseEntry -- "Digabung ke Billing PS / Biliar" --> OpenBillingDetail["Buka Detail Transaksi Berjalan\nGET /transaction/{id}/edit atau add-order"]
    OpenBillingDetail --> SelectFnB1["Pilih Produk FnB dari Daftar Menu"]
    SelectFnB1 --> InputQty1["Input Jumlah Pesanan (Qty)"]
    InputQty1 --> SubmitAddFnB{"Klik 'Tambah FnB'\nPOST /transaction/{id}/fnb/add"}

    %% Jalur 2: Transaksi Mandiri (FnB Only)
    ChooseEntry -- "Pesanan Langsung / FnB Saja" --> OpenDirectPOS["Buka Form Transaksi Baru\nGET /transaction/create"]
    OpenDirectPOS --> SelectFnB2["Pilih Menu FnB & Tentukan Qty"]
    SelectFnB2 --> SubmitDirect{"Simpan Transaksi Baru\nPOST /transaction"}

    SubmitAddFnB --> LockFnbStock["DB::transaction: Lock Baris 'fnbs'\nSELECT ... FOR UPDATE"]
    SubmitDirect --> LockFnbStock

    LockFnbStock --> CheckStock{"Periksa Stok Barang\n(fnb.stok >= qty atau stok == -1?)"}
    
    CheckStock -- "Stok Tidak Cukup" --> RollbackStock["BATALKAN TRANSAKSI (Rollback)!\nKembalikan Pesan: 'Stok tidak mencukupi'"]
    
    CheckStock -- "Stok Cukup / Unlimited (-1)" --> DeductStock["Kurangi Stok Fisik:\nUPDATE fnbs SET stok = stok - qty"]
    
    DeductStock --> CreateStockMutation["Catat Riwayat ke 'stock_mutations':\n- type = 'out'\n- qty = pesanan\n- note = 'Penjualan transaksi #ID'"]

    CreateStockMutation --> CreateTxFnbRecord["Simpan Item ke 'transaction_fnbs':\n- transaction_id = ID\n- fnb_id = ID_Produk\n- qty = Jumlah\n- harga_jual = Harga Satuan"]

    CreateTxFnbRecord --> RecalculateTotal["Hitung Ulang Tagihan Transaksi:\nTotal Baru = Total Rental + SUM(qty * harga_jual)"]

    RecalculateTotal --> SuccessPOS["Pesanan FnB Sukses Tersimpan!\nStok Berkurang Akurat & Tagihan Terupdate"]
```

### Penjelasan Alur
1. Kasir dapat menjual makanan/minuman melalui dua jalur:
   - **Jalur Terintegrasi:** Menambahkan FnB ke sesi billing yang sedang berjalan (`POST /transaction/{id}/fnb/add`).
   - **Jalur Langsung:** Menjual FnB sebagai transaksi kasir tersendiri (`POST /transaction`).
2. Backend mengunci baris produk di tabel `fnbs` dengan *pessimistic lock* (`SELECT ... FOR UPDATE`).
3. Sistem memeriksa apakah stok mencukupi (jika stok `-1`, artinya barang *unlimited* atau tidak dibatasi kuantitasnya).
4. Jika stok cukup, kuantitas stok dikurangi secara atomik di tabel `fnbs`.
5. Tercatat rekaman audit mutasi stok keluar di tabel `stock_mutations`.
6. Record pesanan disimpan di tabel `transaction_fnbs` dan total tagihan transaksi dihitung ulang.

### Role Pengguna
- **KASIR & ADMIN** (Input pesanan dan transaksi).

### Link Website
- Transaksi Baru: [http://127.0.0.1:8008/transaction/create](http://127.0.0.1:8008/transaction/create)
- Manajemen FnB: [http://127.0.0.1:8008/fnb](http://127.0.0.1:8008/fnb)
- Manajemen Stok: [http://127.0.0.1:8008/stock](http://127.0.0.1:8008/stock)

### Data yang Digunakan
- Model: `Fnb`, `TransactionFnb`, `StockMutation`, `Transaction`.
- Tabel: `fnbs`, `transaction_fnbs`, `stock_mutations`, `transactions`.

### Proses Backend
- `App\Http\Controllers\TransactionController::addFnbToTransaction()`
- `App\Http\Controllers\TransactionController::storeOrder()`
- `App\Models\StockMutation::create()`

### Hasil Akhir
- Item FnB masuk ke rincian struk, total tagihan terakumulasi, dan inventaris barang toko langsung terpotong.

### Kondisi Gagal
- Jika kuantitas pesanan melebihi sisa stok di database, transaksi otomatis dibatalkan (*rollback*) dengan pesan peringatan.

### Status Implementasi
**IMPLEMENTED & VERIFIED**

---

## DIAGRAM 12 — ALUR PEMBAYARAN, CHECKOUT, & CETAK STRUK (RECEIPT)

### Nama Proses
Checkout Tagihan, Validasi Pembayaran Multi-Metode, & Pencetakan Struk Transaksi

### Tujuan
Menggambarkan alur kasir dalam memproses pembayaran akhir pelanggan (sewa konsol + FnB), validasi nominal bayar, pencatatan metode pembayaran (`tunai`, `e-wallet`, `transfer_bank`), dan pencetakan struk transaksi kasir.

### Diagram Mermaid

```mermaid
flowchart TD
    CheckoutStart(["Pelanggan Melakukan Pembayaran"]) --> OpenPayView["Kasir Membuka Layar Pembayaran\nGET /transaction/{id}/payment"]
    
    OpenPayView --> ViewSummary["Rincian Tagihan Ditampilkan:\n- Biaya Sewa Rental PS / Biliar\n- Rincian Makanan & Minuman (FnB)\n- Diskon (Jika Ada Member)\n- Total Akhir yang Harus Dibayar"]

    ViewSummary --> SelectPayMethod["Kasir Memilih Metode Pembayaran:\n1. Tunai (Cash)\n2. E-Wallet (QRIS / DANA / Gopay)\n3. Transfer Bank (BCA / Mandiri / BRI)"]

    SelectPayMethod --> InputAmount["Kasir Memasukkan Nominal Bayar (amount_paid)"]

    InputAmount --> ClickProcess{"Kasir Klik 'Proses Pembayaran'\nPOST /transaction/{id}/process-payment"}

    ClickProcess --> LockTxPayment["DB::transaction: Lock Transaksi\nSELECT ... FOR UPDATE"]

    LockTxPayment --> CheckAlreadyPaid{"Cek Status Pembayaran\n(payment_status == 'paid'?)"}
    CheckAlreadyPaid -- "Sudah Dibayar Sebelumnya" --> DenyDoublePay["GAGAL! Mencegah Pembayaran Ganda\nRedirect ke Cetak Struk"]

    CheckAlreadyPaid -- "Belum Dibayar ('unpaid')" --> ValidateMethod{"Validasi Input Metode:\nMust in: 'tunai', 'e-wallet', 'transfer_bank'"}
    
    ValidateMethod -- "Metode Tidak Valid" --> ErrorMethod["Kembalikan Pesan Error Validasi"]

    ValidateMethod -- "Metode Valid" --> CheckAmount{"Cek Nominal Bayar:\namount_paid >= final_total?"}

    CheckAmount -- "Kurang Bayar" --> ErrorAmount["Peringatan: Nominal pembayaran kurang!\nTransaksi tidak dapat diselesaikan"]

    CheckAmount -- "Uang Pas / Lebih" --> FinalizePayment["Proses Transaksi Selesai:\n1. payment_status = 'paid'\n2. payment_method = metode_terpilih\n3. paid_at = now()\n4. Hitung Kembalian: change = amount_paid - total"]

    FinalizePayment --> CommitPayment["Commit Transaksi Database"]

    CommitPayment --> RedirectReceipt["Redirect Otomatis ke Cetak Struk:\nGET /transaction/{id}/print"]

    RedirectReceipt --> PrintLayout["Tampilan Struk Bersih (Clean Thermal View):\n- Nama Rental: Sugeng Rawuh\n- ID Transaksi & Nama Kasir\n- Rincian Jam Main & FnB\n- Total Bayar, Uang Diterima, Kembalian\n- Pesan Penutup / Terima Kasih"]

    PrintLayout --> TriggerPrintDialog["Browser Menjalankan:\nwindow.print() (Cetak Printer Thermal 58mm/80mm)"]
```

### Penjelasan Alur
1. Kasir membuka halaman pembayaran di URL `http://127.0.0.1:8008/transaction/{id}/payment`.
2. Halaman menampilkan rincian biaya: durasi sewa, rincian makanan/minuman yang dikonsumsi, diskon member (jika ada), dan total tagihan.
3. Kasir memilih salah satu dari 3 metode pembayaran resmi: **tunai**, **e-wallet**, atau **transfer_bank**.
4. Kasir memasukkan nominal uang yang diterima dari pelanggan.
5. `TransactionController::processPayment()` mengunci baris transaksi, memastikan transaksi belum pernah dibayar sebelumnya, memvalidasi kecukupan uang, dan menghitung nominal kembalian (*change*).
6. Status transaksi diubah menjadi `payment_status = 'paid'` dan `paid_at = now()`.
7. Setelah commit database berhasil, kasir otomatis diarahkan ke URL `http://127.0.0.1:8008/transaction/{id}/print`.
8. Tampilan struk bersih (*thermal-friendly*) langsung memicu dialog pencetakan browser (`window.print()`) ke printer kasir (58mm / 80mm).

### Role Pengguna
- **KASIR & ADMIN** (Memproses pembayaran dan mencetak struk).

### Link Website
- Form Pembayaran: [http://127.0.0.1:8008/transaction/{id}/payment](http://127.0.0.1:8008/transaction/{id}/payment)
- Cetak Struk: [http://127.0.0.1:8008/transaction/{id}/print](http://127.0.0.1:8008/transaction/{id}/print)

### Data yang Digunakan
- Model: `Transaction`, `TransactionFnb`.
- Kolom: `transactions.payment_status`, `transactions.payment_method`, `transactions.paid_at`, `transactions.total`.

### Proses Backend
- `App\Http\Controllers\TransactionController::showPayment()`
- `App\Http\Controllers\TransactionController::processPayment()`
- `App\Http\Controllers\TransactionController::printReceipt()`

### Hasil Akhir
- Pembayaran tercatat lunas di pembukuan dan struk belanja fisik berhasil dicetak untuk pelanggan.

### Kondisi Gagal
- Jika jumlah uang yang dimasukkan kurang dari total tagihan, sistem menolak dan menampilkan peringatan nominal pembayaran kurang.

### Status Implementasi
**IMPLEMENTED & VERIFIED**

---

## DIAGRAM 13 — LAPORAN KEUANGAN & AUDIT OPERASIONAL (OWNER & ADMIN)

### Nama Proses
Pemantauan Pendapatan, Laporan Penjualan, & Ekspor Data Keuangan (OWNER & ADMIN)

### Tujuan
Menggambarkan bagaimana OWNER dan ADMIN memantau performa bisnis rental, melihat grafik pendapatan per jam, mengevaluasi histori transaksi, memantau utilisasi stasiun, serta mencetak laporan ke format PDF dan Excel.

### Diagram Mermaid

```mermaid
flowchart TD
    UserStart(["OWNER atau ADMIN Membuka Menu Laporan"]) --> ReportMenuChoice{"Pilih Tampilan Laporan"}

    %% Jalur Dasbor Utama
    ReportMenuChoice -- "Dasbor Grafik Utama" --> ViewHome["Buka /home\n(Dasbor Ringkasan Eksekutif)"]
    ViewHome --> QueryHomeStats["Query Statistik Realtime:\n- Total Pendapatan Hari Ini\n- Total Transaksi Aktif\n- Grafik Garis Pendapatan Per Jam\n- Grafik Pie Komposisi Sewa vs FnB"]
    QueryHomeStats --> DisplayHomeCards["Tampilkan Card Ringkasan & Grafik Chart.js"]

    %% Jalur Laporan Penjualan Detail
    ReportMenuChoice -- "Laporan Transaksi Lengkap" --> ViewReportIndex["Buka /report atau /report/transaction\n(Tabel Audit Finansial)"]
    ViewReportIndex --> FilterReport["Filter Laporan Berdasarkan:\n- Rentang Tanggal (Start Date s/d End Date)\n- Shift Kasir (Pagi / Siang / Malam)\n- Jenis Konsol (PS3, PS4, PS5, Biliar)\n- Metode Pembayaran (Tunai, E-Wallet, Transfer)"]
    
    FilterReport --> ExecuteReportQuery["Query Agregasi Database:\nSELECT SUM(total), COUNT(id_transaksi) FROM transactions\nWHERE payment_status = 'paid' AND date BETWEEN ..."]
    
    ExecuteReportQuery --> DisplayReportTable["Tampilkan Rekapitulasi Pendapatan di Tabel Web"]

    %% Jalur Ekspor Data
    DisplayReportTable --> ExportChoice{"Pilih Format Ekspor Dokumen"}
    
    ExportChoice -- "Ekspor Excel (.xlsx)" --> ClickExcel["Klik 'Export Excel'\nGET /generate-excel?start_date=...&end_date=..."]
    ClickExcel --> BuildExcelFile["ReportController::generateExcel():\nGenerate Spreadsheet menggunakan Maatwebsite Excel"]
    BuildExcelFile --> DownloadExcelFile["Download Otomatis File Excel Laporan"]

    ExportChoice -- "Ekspor PDF (.pdf)" --> ClickPdf["Klik 'Export PDF'\nGET /generate-pdf?start_date=...&end_date=..."]
    ClickPdf --> BuildPdfFile["ReportController::generatePDF():\nRender Dokumen PDF via DomPDF"]
    BuildPdfFile --> DownloadPdfFile["Download Otomatis File PDF Siap Cetak"]

    %% Jalur Laporan Khusus FnB
    ReportMenuChoice -- "Laporan Penjualan FnB Saja" --> ViewFnbReport["Buka /fnb/laporan-penjualan\n(Rekap Kuantitas & Margin Makanan/Minuman)"]
    ViewFnbReport --> ExportFnbReport["Ekspor Excel / PDF Khusus FnB\n(/fnb/laporan-penjualan/excel | /pdf)"]
```

### Penjelasan Alur
1. OWNER atau ADMIN mengakses menu laporan melalui dasbor `http://127.0.0.1:8008/home` atau menu laporan di `http://127.0.0.1:8008/report`.
2. Dasbor menyajikan ringkasan omzet harian, jumlah stasiun terpakai, grafik pendapatan per jam, dan perbandingan omzet rental vs FnB.
3. Pada halaman detail laporan transaksi (`/report/transaction`), pengguna dapat memfilter transaksi berdasarkan rentang tanggal, shift kerja kasir, dan metode pembayaran.
4. Data laporan dapat diunduh kapan saja ke dalam format **Excel** (`/generate-excel`) atau **PDF** (`/generate-pdf`).
5. Modul FnB juga menyediakan laporan penjualan mandiri (`/fnb/laporan-penjualan`) untuk melihat barang terlaris dan mutasi sisa stok.

### Role Pengguna
- **OWNER & ADMIN** (Diberikan akses penuh melihat dan mengekspor seluruh laporan keuangan).
- **KASIR** (Tidak memiliki akses ke menu laporan keseluruhan omzet toko).

### Link Website
- Dasbor Omzet: [http://127.0.0.1:8008/home](http://127.0.0.1:8008/home)
- Laporan Penjualan: [http://127.0.0.1:8008/report](http://127.0.0.1:8008/report)
- Laporan FnB: [http://127.0.0.1:8008/fnb/laporan-penjualan](http://127.0.0.1:8008/fnb/laporan-penjualan)

### Data yang Digunakan
- Model: `Transaction`, `TransactionFnb`, `Expense`, `WorkShift`.
- Tabel: `transactions`, `transaction_fnbs`, `expenses`, `work_shifts`.

### Proses Backend
- `App\Http\Controllers\HomeController::index()`
- `App\Http\Controllers\HomeController::hourlyRevenueData()`
- `App\Http\Controllers\ReportController::index()`
- `App\Http\Controllers\ReportController::generateExcel()`
- `App\Http\Controllers\ReportController::generatePDF()`

### Hasil Akhir
- Transparansi pembukuan kasir terjamin dan pemilik bisnis (Owner) dapat memantau performa usaha kapan saja.

### Kondisi Gagal
- Jika rentang tanggal filter terbalik (tanggal akhir lebih kecil dari tanggal mulai), sistem menampilkan pesan validasi filter tidak valid.

### Status Implementasi
**IMPLEMENTED & VERIFIED**

---

## DIAGRAM 14 — PENANGANAN KEGAGALAN HARDWARE & PEMULIHAN OFFLINE (FAILURE RECOVERY)

### Nama Proses
Prosedur Penanganan Error Jaringan, Kegagalan Hardware, & Pemulihan Operasional Kasir

### Tujuan
Menggambarkan bagaimana sistem Sugeng Rawuh menangani berbagai skenario kegagalan: Samsung TV offline, koneksi WebSocket timeout, relay biliar mati, worker terhenti, atau database error, serta prosedur pemulihan (*failover*) agar operasional kasir tetap berjalan lancar.

### Diagram Mermaid

```mermaid
flowchart TD
    AnomaliStart(["Deteksi Kegagalan / Masalah Jaringan"]) --> ClassifyIssue{"Klasifikasi Jenis Masalah"}

    %% KASUS 1: SAMSUNG TV OFFLINE / TIMEOUT
    ClassifyIssue -- "Samsung TV Offline / Jaringan Putus" --> CaseTV["Gejala: TV Tidak Mau Menyala / Status OFFLINE"]
    CaseTV --> TVHandling["Dampak ke Sistem:\n- Worker menandai intent 'FAILED' atau 'SKIPPED'\n- Transaksi kasir TETAP BERJALAN NORMAL (TIDAK ROLLBACK)"]
    TVHandling --> TVActionKasir["TINDAKAN KASIR (Manual Fallback):\n1. Nyalakan TV secara manual dengan Remote Fisik Samsung\n2. Pastikan Input Layar pada HDMI 1 (PlayStation)\n3. Layani pelanggan seperti biasa"]
    TVActionKasir --> TVActionAdmin["TINDAKAN ADMIN (Saat Pergantian Shift):\n1. Periksa kabel LAN / Wi-Fi TV Samsung\n2. Jalankan Probe di /admin/hardware/diagnostics\n3. Pastikan IP TV tidak berubah di Router"]

    %% KASUS 2: SHELLY RELAY BILIAR TIDAK RESPON
    ClassifyIssue -- "Shelly Relay Mati / Wi-Fi Biliar Lemah" --> CaseShelly["Gejala: Lampu Meja Biliar Tidak Mau Nyala/Mati"]
    CaseShelly --> ShellyHandling["Dampak ke Sistem:\n- Worker mencoba retry hingga max_attempts (3x)\n- Intent ditandai FAILED setelah timeout"]
    ShellyHandling --> ShellyActionKasir["TINDAKAN KASIR:\n1. Buka saklar manual cadangan di panel biliar (Bypass)\n2. Catat jam main secara manual di aplikasi"]
    ShellyActionKasir --> ShellyActionAdmin["TINDAKAN ADMIN:\n1. Periksa catu daya modul Shelly 1PM Mini\n2. Uji ping IP Shelly dari PC Kasir\n3. Restart router Wi-Fi lokal jika perlu"]

    %% KASUS 3: WORKER OTOMASI MATI (DAEMON CRASH)
    ClassifyIssue -- "Background Worker Mati / Tidak Jalan" --> CaseWorker["Gejala: Intent Menumpuk di Status PENDING"]
    CaseWorker --> WorkerHandling["Dampak ke Sistem:\n- Aplikasi web kasir tetap lancar\n- Perangkat IoT tidak menerima perintah otomatis"]
    WorkerHandling --> WorkerRecovery["TINDAKAN PEMULIHAN (ADMIN):\n1. Buka Terminal di PC Kasir\n2. Jalankan Perintah:\n   php artisan billing:automation-worker\n3. Worker otomatis menjalankan Crash Recovery:\n   Memulihkan intent yang terkunci (Recover Stale Locked)"]

    %% KASUS 4: KONEKSI DATABASE PUTUS
    ClassifyIssue -- "MySQL Server Mati / Gagal Konek" --> CaseDB["Gejala: Layar Kasir Menampilkan Pesan Error Koneksi"]
    CaseDB --> DBRecovery["TINDAKAN DARURAT:\n1. Restart layanan MySQL pada PC Kasir (MAMP / XAMPP / Homebrew)\n2. Periksa kapasitas ruang penyimpanan hard disk PC Kasir"]

    classDef issue fill:#ffebee,stroke:#c62828,stroke-width:1.5px;
    classDef fix fill:#e8f5e9,stroke:#2e7d32,stroke-width:1.5px;
    class CaseTV,CaseShelly,CaseWorker,CaseDB issue;
    class TVActionKasir,TVActionAdmin,ShellyActionKasir,ShellyActionAdmin,WorkerRecovery,DBRecovery fix;
```

### Penjelasan Alur
1. **Kegagalan TV Samsung:** Karena TV dikendalikan melalui jaringan IP lokal, jika TV offline, transaksi kasir **tidak pernah dibatalkan**. Kasir langsung menyalakan TV secara manual dengan remote fisik. Admin kemudian memeriksa kabel LAN/Wi-Fi dan status router saat pergantian shift.
2. **Kegagalan Relay Shelly Meja Biliar:** Jika relay offline, worker mencoba mengirim ulang hingga 3 kali (*retry backoff*). Jika tetap gagal, error dicatat di log audit. Kasir dapat menyalakan saklar manual cadangan di panel meja biliar.
3. **Kegagalan Background Worker:** Jika proses daemon worker di terminal PC kasir terhenti, aplikasi kasir tetap bisa beroperasi normal. Admin cukup menjalankan kembali perintah `php artisan billing:automation-worker`. Saat *booting*, worker otomatis mengeksekusi mekanisme *Crash Recovery* untuk memulihkan intent yang tertunda.
4. **Prinsip Keselamatan Utama:** Sistem tidak pernah memutus listrik konsol PlayStation atau TV menggunakan relay daya darurat guna melindungi mesin dari kerusakan internal.

### Role Pengguna
- **KASIR & ADMIN** (Menjalankan prosedur *recovery* operasional).

### Link Website
- Log Audit Error Otomasi: [http://127.0.0.1:8008/admin/hardware/automation/logs](http://127.0.0.1:8008/admin/hardware/automation/logs)
- Halaman Diagnostik: [http://127.0.0.1:8008/admin/hardware/diagnostics](http://127.0.0.1:8008/admin/hardware/diagnostics)

### Data yang Digunakan
- Tabel: `automation_execution_logs`, `automation_intents`.
- Kolom: `automation_execution_logs.error_category`, `automation_execution_logs.error_details`.

### Proses Backend
- `App\Services\Hardware\Automation\AutomationDispatcherService::recoverStaleLockedIntents()`
- `App\Services\Hardware\Security\HardwareSafetyGuard`

### Hasil Akhir
- Operasional rental tetap berjalan lancar tanpa kehilangan data transaksi meskipun perangkat keras mengalami gangguan jaringan.

### Kondisi Gagal
- Jika seluruh jaringan LAN toko mati total, kasir tetap dapat mengoperasikan billing dari browser lokal `http://127.0.0.1:8008` di PC kasir.

### Status Implementasi
**IMPLEMENTED & VERIFIED**

---

## DIAGRAM TAMBAHAN

### Diagram 15 — State Machine Siklus Hidup Billing (Billing Lifecycle State Machine)

```mermaid
stateDiagram-v2
    [*] --> IDLE : Stasiun Kosong / Tersedia

    IDLE --> RUNNING : startSession() (Kasir Mulai Sewa)
    
    state RUNNING {
        [*] --> ActiveTimer
        ActiveTimer --> ActiveTimer : Detik Berjalan (Server Time)
        ActiveTimer --> ExtendedTimer : extendSession() (+Jam Sewa)
        ExtendedTimer --> ActiveTimer
    }

    RUNNING --> EXPIRED_PENDING_ACTION : evaluateExpiration() (Waktu Server Habis)
    RUNNING --> STOPPED_EARLY : stopSession() (Kasir Stop Sebelum Habis)
    RUNNING --> COMPLETED : stopSession() (Sesi Lost Time Selesai)

    EXPIRED_PENDING_ACTION --> COMPLETED : processPayment() (Pelunasan Pembayaran Kasir)
    STOPPED_EARLY --> COMPLETED : processPayment() (Pelunasan Pembayaran Kasir)

    COMPLETED --> IDLE : Stasiun Dibebaskan (Status: Tersedia)
    IDLE --> [*]
```

---

### Diagram 16 — Alur Transactional Outbox Pattern (Transactional Outbox Sequence)

```mermaid
sequenceDiagram
    autonumber
    actor Kasir as Kasir
    participant Billing as BillingService
    participant TxTable as Tabel 'transactions'
    participant Outbox as Tabel 'automation_intents'
    participant Worker as Background Worker
    participant Telemetry as Tabel 'automation_execution_logs'
    participant Hardware as Perangkat Fisik (TV / Relay)

    Kasir->>Billing: Klik "Mulai Billing"
    activate Billing
    Note over Billing,Outbox: Dalam 1 Transaksi Database Bersama (ACID)
    Billing->>TxTable: INSERT Transaksi (Status: RUNNING)
    Billing->>Outbox: INSERT Intent (Status: PENDING, Desired: ON)
    Billing-->>Kasir: Response HTTP 200 OK (Sesi Berhasil Dimulai)
    deactivate Billing

    Note over Worker,Outbox: Asynchronous Background Processing
    loop Polling Setiap 500ms
        Worker->>Outbox: SELECT WHERE status = 'PENDING' AND scheduled_at <= now() FOR UPDATE SKIP LOCKED
        Outbox-->>Worker: Return Intent #101
        Worker->>Outbox: UPDATE status = 'PROCESSING', locked_at = now()
        
        Worker->>Hardware: Kirim Perintah Kontrol Sesuai Driver
        Hardware-->>Worker: Respons Status / ACK
        
        Worker->>Telemetry: INSERT Log Eksekusi (Status: SUCCESS / SKIPPED / FAILED)
        Worker->>Outbox: UPDATE status = 'COMPLETED', completed_at = now()
    end
```

---

### Diagram 17 — Entity Relationship Diagram (ERD Relasi Billing, Stasiun & IoT)

```mermaid
erDiagram
    users ||--o{ transactions : "melayani"
    users ||--o{ work_shifts : "memiliki"
    
    devices ||--o{ transactions : "disewa_dalam"
    devices ||--o{ device_playstation : "memiliki_tipe"
    playstations ||--o{ device_playstation : "dimiliki_oleh"
    
    devices ||--o{ station_device_mappings : "dipasangkan_dengan"
    physical_devices ||--o{ station_device_mappings : "memetakan_ke"
    
    devices ||--o{ automation_intents : "menerima_intent"
    transactions ||--o{ automation_intents : "memicu_intent"
    
    automation_intents ||--o{ automation_execution_logs : "menghasilkan_log"
    devices ||--o{ automation_execution_logs : "dicatat_dalam"
    
    transactions ||--o{ transaction_fnbs : "memuat_item"
    fnbs ||--o{ transaction_fnbs : "dijual_dalam"
    fnbs ||--o{ stock_mutations : "mengalami_mutasi"

    devices {
        bigint id PK
        string nama
        string status
        int session_generation
        datetime last_hardware_verified_at
    }

    physical_devices {
        bigint id PK
        string name
        enum category "SMART_TV, SMART_RELAY"
        enum brand "SAMSUNG, SHELLY"
        string model
        string mac_address
        string ip_address
        enum connection_status "ONLINE, OFFLINE, UNVERIFIED"
        enum pairing_status "PAIRED, UNPAIRED, FAILED"
        json verified_capabilities
        text pairing_payload "encrypted"
    }

    station_device_mappings {
        bigint id PK
        bigint station_id FK
        bigint physical_device_id FK
        enum mapping_role "DISPLAY_TV, LIGHT_RELAY"
        enum mapping_status "ACTIVE, INACTIVE"
        datetime attached_at
    }

    transactions {
        bigint id_transaksi PK
        bigint device_id FK
        bigint user_id FK
        enum tipe_transaksi "prepaid, postpaid, custom_package"
        enum billing_status "IDLE, RUNNING, EXPIRED_PENDING_ACTION, STOPPED_EARLY, COMPLETED"
        enum payment_status "paid, unpaid"
        string payment_method "tunai, e-wallet, transfer_bank"
        datetime started_at
        datetime expires_at
        datetime ended_at
        decimal total
        int session_generation
    }

    automation_intents {
        bigint id PK
        bigint transaction_id FK
        bigint station_id FK
        int session_generation
        string event_type "BILLING_STARTED, BILLING_EXPIRED, BILLING_STOPPED"
        string desired_state "ON, OFF, STANDBY"
        enum status "PENDING, PROCESSING, COMPLETED, FAILED, CANCELLED, SKIPPED"
        datetime scheduled_at
    }

    automation_execution_logs {
        bigint id PK
        bigint intent_id FK
        bigint station_id FK
        string driver_type "SAMSUNG_TV, SHELLY_RELAY"
        string command_sent
        enum execution_status "SUCCESS, FAILED, SKIPPED"
        int latency_ms
        datetime executed_at
    }
```

---

*Dokumen ini merupakan panduan alur pengguna dan referensi arsitektur resmi aplikasi Sugeng Rawuh berdasarkan source code aktual sistem_kasir_PS-main.*
