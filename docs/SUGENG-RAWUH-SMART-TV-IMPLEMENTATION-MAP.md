# SUGENG RAWUH — SMART TV IMPLEMENTATION TRACEABILITY MAP & GAP ANALYSIS

**Project:** Sugeng Rawuh — Integrated Billing & POS Management System  
**Repository:** `sistem_kasir_PS-main`  
**Document Ref:** `docs/SUGENG-RAWUH-SMART-TV-IMPLEMENTATION-MAP.md`  
**Current Phase:** Post G5-B Software Mock E2E Verification (310 Automated Tests Passing)  
**Safety Status:** `HARDWARE_REAL_EXECUTION_ENABLED=false` (Enforced by `HardwareSafetyGuard`)  
**Target Hardware:** Samsung Smart TV 50U8500 (Tizen OS) & Coocaa 43S3U Plus (Coolita OS)

---

## 1. PENDAHULUAN & STATUS KATEGORI IMPLEMENTASI

Dokumen ini memetakan seluruh komponen arsitektur, route, controller, service, driver, dan skema database yang berkaitan dengan manajemen Smart TV pada aplikasi Sugeng Rawuh. 

Setiap fitur diklasifikasikan ke dalam salah satu dari 5 status baku:
1. **`IMPLEMENTED`**: Kode logika, routing, UI, dan model telah terpasang dan berfungsi penuh pada lapisan software.
2. **`MOCK ONLY`**: Fitur telah diimplementasikan pada lapisan driver/service menggunakan transportasi simulasi (*Fake Transport* / Mock) dan lulus uji otomatis (*automated test suite*), namun belum terhubung ke perangkat keras fisik nyata.
3. **`PHYSICAL VERIFICATION PENDING`**: Fitur telah dirancang dan diimplementasikan untuk protokol fisik nyata (*Real Driver*), namun menunggu pengujian dan validasi langsung pada perangkat keras nyata di laboratorium uji fisik (Gate G4-B2).
4. **`NOT IMPLEMENTED`**: Fitur belum dibuat atau belum ada kode implementasinya di repository.
5. **`UNSUPPORTED / UNKNOWN`**: Perangkat keras atau protokol tidak didukung oleh spesifikasi teknis dan secara eksplisit ditolak oleh sistem validasi.

---

## 2. MATRIKS TRACEABILITY PROSES SMART TV MANAGEMENT

| ID Proses | Nama Proses / Fitur | File Sumber & Method | Route & HTTP Verb | Middleware & RBAC | Tabel & Kolom Database Terkait | Status Implementasi | Catatan Teknis & Fakta Repository |
|---|---|---|---|---|---|---|---|
| **PRC-01** | Navigasi Menu Hardware | `resources/views/layouts/sidebar.blade.php`<br>(Lines 70–108) | `admin.hardware.*` | `auth`, `admin` | N/A | **`IMPLEMENTED`** | Menu sidebar hanya muncul untuk pengguna dengan peran ADMIN. Kasir dan Owner tidak melihat menu konfigurasi teknis ini. |
| **PRC-02** | Tampilan UI Device Registry | `app/Http/Controllers/Admin/PhysicalDeviceController.php`<br>`index()` | `GET /admin/hardware/registry`<br>`admin.hardware.registry` | `auth`, `admin` | `physical_devices` | **`IMPLEMENTED`** | Menampilkan daftar perangkat fisik terdaftar beserta status koneksi, pairing, dan mapping. |
| **PRC-03** | Auto-Discovery Jaringan (mDNS/SSDP) | N/A (Tidak ada modul background scanner) | N/A | N/A | N/A | **`NOT IMPLEMENTED`** | Tidak ada mekanisme pemindaian otomatis perangkat di jaringan lokal. Pendaftaran perangkat dilakukan 100% manual oleh Admin. |
| **PRC-04** | Validasi Input Pendaftaran Perangkat | `app/Http/Requests/StorePhysicalDeviceRequest.php`<br>`rules()` | `POST /admin/hardware/registry`<br>`admin.hardware.registry.store` | `auth`, `admin` | N/A | **`IMPLEMENTED`** | Wajib: `name`, `category`, `brand`, `mac_address`, `ip_address`. Brand dibatasi ketat: `Rule::in(['SAMSUNG', 'SHELLY'])`. Input Coocaa otomatis ditolak (HTTP 422). |
| **PRC-05** | Simpan Device Registry & Enkripsi Kredensial | `app/Models/PhysicalDevice.php`<br>`validateBrandCategory()`, `$casts` | `POST /admin/hardware/registry`<br>`admin.hardware.registry.store` | `auth`, `admin` | `physical_devices`<br>(kolom `pairing_payload`) | **`IMPLEMENTED`** | Atribut `pairing_payload` di-cast `encrypted`. Kolom ini tersembunyi (`$hidden`) dari output serialisasi JSON/Array. Brand `COOCAA` ditolak oleh model invariant. |
| **PRC-06** | Tampilan UI Station Hardware Mapping | `app/Http/Controllers/Admin/StationHardwareMappingController.php`<br>`mappingsView()` | `GET /admin/hardware/mappings`<br>`admin.hardware.mappings` | `auth`, `admin` | `devices`, `station_device_mappings`, `physical_devices` | **`IMPLEMENTED`** | Menampilkan daftar station (PlayStation & Billiard) dan perangkat fisik yang aktif atau belum dipetakan. Query perangkat TV secara eksplisit hanya memfilter `brand: SAMSUNG`. |
| **PRC-07** | Attach Hardware Mapping ke Station | `app/Services/Hardware/StationMappingService.php`<br>`attachHardware()` | `POST /admin/hardware/mappings/attach`<br>`admin.hardware.mappings.attach` | `auth`, `admin` | `station_device_mappings`<br>(status `ACTIVE`) | **`IMPLEMENTED`** | Menggunakan transaksi database dengan penguncian urut: lock station terlebih dahulu, kemudian lock physical device. Mencegah race condition dan multiple active mappings. |
| **PRC-08** | Validasi Kompatibilitas Station ↔ Hardware | `app/Services/Hardware/StationMappingService.php`<br>`validateCategoryMatch()` | Internal Service Call | Internal Service | N/A | **`IMPLEMENTED`** | Station PlayStation (`PLAYSTATION_3`, `PLAYSTATION_4`, `PLAYSTATION_5`) hanya dapat dipetakan ke hardware berkategori `SMART_TV` dengan peran `DISPLAY_TV`. Dilarang memetakan Smart Relay ke PlayStation. |
| **PRC-09** | Detach Hardware Mapping dari Station | `app/Services/Hardware/StationMappingService.php`<br>`detachHardware()` | `POST /admin/hardware/mappings/detach`<br>`admin.hardware.mappings.detach` | `auth`, `admin` | `station_device_mappings`<br>(status `INACTIVE`) | **`IMPLEMENTED`** | Melakukan deaktivasi mapping. Memeriksa apakah station sedang memiliki transaksi billing aktif (mencegah pelepasan mapping saat sesi berjalan). |
| **PRC-10** | Tampilan UI Hardware Diagnostics | `app/Http/Controllers/Admin/HardwareDiagnosticsController.php`<br>`index()` | `GET /admin/hardware/diagnostics`<br>`admin.hardware.diagnostics` | `auth`, `admin` | `physical_devices` | **`IMPLEMENTED`** | Halaman uji coba protokol hardware khusus Admin. Menampilkan status `realExecutionEnabled` dan daftar perangkat. |
| **PRC-11** | Diagnostic Network Probe (Simulasi) | `app/Services/Hardware/Drivers/MockSamsungTvDriver.php`<br>`probe()` | `POST /admin/hardware/diagnostics/probe`<br>`admin.hardware.diagnostics.probe` | `auth`, `admin` | N/A | **`MOCK ONLY`** | Menggunakan `FakeSamsungWebSocketTransport`. Menghasilkan respons deterministik sesuai skenario (`SUCCESS`, `TIMEOUT`, `AUTH_REJECTED`) untuk pengujian software. |
| **PRC-12** | Diagnostic Network Probe (Fisik Nyata) | `app/Services/Hardware/Drivers/SamsungTvDriver.php`<br>`probe()` | `POST /admin/hardware/diagnostics/probe`<br>`admin.hardware.diagnostics.probe` | `auth`, `admin` | `physical_devices`<br>(kolom `connection_status`) | **`PHYSICAL VERIFICATION PENDING`** | Melakukan HTTP GET ke endpoint REST Samsung TV `http://{ip}:8001/api/v2/`. Terkunci di belakang `HardwareSafetyGuard::assertRealExecutionAllowed()`. |
| **PRC-13** | Diagnostic Pairing Handshake (Simulasi) | `app/Services/Hardware/Drivers/MockSamsungTvDriver.php`<br>`pair()` | `POST /admin/hardware/diagnostics/pair`<br>`admin.hardware.diagnostics.pair` | `auth`, `admin` | N/A | **`MOCK ONLY`** | Mengembalikan token simulasi. Sesuai desain pengamanan G4-B1, hasil fake pairing tidak disimpan ke database permanen. |
| **PRC-14** | Diagnostic Pairing Handshake (Fisik Nyata) | `app/Services/Hardware/Drivers/SamsungTvDriver.php`<br>`pair()` | `POST /admin/hardware/diagnostics/pair`<br>`admin.hardware.diagnostics.pair` | `auth`, `admin` | `physical_devices`<br>(kolom `pairing_payload`, `pairing_status`) | **`PHYSICAL VERIFICATION PENDING`** | Membuka koneksi WebSocket ke `wss://{ip}:8002/api/v2/channels/samsung.remote.control`. Menunggu otorisasi pengguna pada layar TV, menangkap event `ms.channel.connect`, dan menyimpan token pairing terenkripsi. |
| **PRC-15** | Safety Fencing Real Execution Gate | `app/Services/Hardware/Security/HardwareSafetyGuard.php`<br>`assertRealExecutionAllowed()` | Internal Guard Call | Internal Service | N/A | **`IMPLEMENTED`** | Memeriksa konfigurasi `config('hardware.real_execution_enabled')`. Jika `false`, melempar `HardwareSafetyException` (HTTP 403 Forbidden). Melindungi jaringan produksi dari transmisi paket fisik yang belum diotorisasi. |
| **PRC-16** | Diagnostic Command Dispatch | `app/Http/Controllers/Admin/HardwareDiagnosticsController.php`<br>`sendCommand()` | `POST /admin/hardware/diagnostics/command`<br>`admin.hardware.diagnostics.command` | `auth`, `admin` | N/A | **`MOCK ONLY`** (Simulasi)<br>**`PHYSICAL VERIFICATION PENDING`** (Real) | Mendukung perintah `GET_STATUS`, `MUTE`, `UNMUTE`, `STANDBY`. Eksekusi riil terhambat oleh safety guard. |
| **PRC-17** | Billing Transaction Start (Prepaid & Lost Time) | `app/Services/BillingService.php`<br>`startPrepaidBilling()`, `startLostTimeBilling()` | `POST /device/start`<br>`device.start` | `auth`, `kasir` | `transactions`, `devices` | **`IMPLEMENTED`** | Kasir memulai billing. Transaksi database mencatat sesi billing dan mengubah status station menjadi `DIGUNAKAN`. |
| **PRC-18** | Pembuatan Intent Otomatisasi (Transactional Outbox) | `app/Services/Hardware/Automation/AutomationIntentService.php`<br>`recordSessionStarted()` | Internal Service Call di dalam `BillingService` | Internal Service | `automation_intents` | **`IMPLEMENTED`** | Membuat record intent `BILLING_STARTED` dengan status `PENDING` di dalam transaksi database yang sama dengan billing. Menyimpan `session_generation` untuk fencing. |
| **PRC-19** | Background Automation Worker Execution | `app/Console/Commands/BillingAutomationWorkerCommand.php`<br>`processIntent()` | CLI Command:<br>`php artisan billing:automation-worker` | Daemon Process / CLI | `automation_intents`, `automation_execution_logs` | **`IMPLEMENTED`** | Worker membaca intent yang eligible (`PENDING`), memvalidasi leasing/lock, mengevaluasi fencing guard dan capability policy, lalu mendispatch perintah ke driver hardware. |
| **PRC-20** | Safety Fencing Guard (Session Generation Check) | `app/Services/Hardware/Automation/AutomationFencingGuard.php`<br>`validateIntent()` | Internal Worker Call | Internal Service | `transactions`, `automation_intents` | **`IMPLEMENTED`** | Memeriksa apakah `session_generation` pada intent masih cocok dengan sesi transaksi aktif saat ini. Jika sesi telah diubah/dihentikan, intent ditandai `SKIPPED_STALE_SESSION`. |
| **PRC-21** | Evaluasi Kebijakan Kapabilitas Otomasi Smart TV | `app/Services/Hardware/Automation/AutomationCapabilityPolicy.php`<br>`evaluate()` | Internal Worker Call | Internal Service | `physical_devices`<br>(kolom `verified_capabilities`) | **`IMPLEMENTED`** | Menolak perintah daya otomatis (`POWER_ON`, `STANDBY`) untuk Samsung Smart TV karena sifat toggle key dan risiko desync fisik. Intent daya TV ditandai `SKIPPED_UNVERIFIED_CAPABILITY`. |
| **PRC-22** | Pencatatan Log Audit Eksekusi Otomasi | `app/Services/Hardware/Automation/AutomationDispatcherService.php`<br>`execute()` | Internal Worker Call | Internal Service | `automation_execution_logs` | **`IMPLEMENTED`** | Mencatat setiap upaya eksekusi hardware secara immutable, termasuk `station_id`, `physical_device_id`, status eksekusi (`SUCCESS`, `FAILED`, `SKIPPED`), latency ms, dan payload respons sanitasi. |
| **PRC-23** | Monitoring Status Otomasi di Dashboard Kasir | `app/Http/Controllers/Api/StationAutomationStatusController.php`<br>`show()` | `GET /api/stations/{id}/automation-status`<br>`api.stations.automation-status` | `auth` | `devices`, `station_device_mappings`, `automation_execution_logs` | **`IMPLEMENTED`** | Menyediakan API endpoint untuk polling widget status hardware di dashboard kasir. Mengembalikan status mapping, koneksi TV, dan eksekusi terakhir. |
| **PRC-24** | Driver Smart TV Coocaa | N/A (Tidak ada kelas `CoocaaTvDriver.php`) | N/A | N/A | N/A | **`UNSUPPORTED / NOT IMPLEMENTED`** | Tidak ada driver, protokol, atau opsi registrasi untuk merek Coocaa pada sistem. |

---

## 3. ANALISIS DETAIL SPESIFIKASI PERANGKAT KERAS

### 3.1 Samsung Smart TV 50U8500 (Tizen OS)

* **Arsitektur Driver:** `app/Services/Hardware/Drivers/SamsungTvDriver.php`
* **Port Komunikasi Jaringan:**
  * Port 8001 (HTTP REST API): Digunakan untuk network probe, pemeriksaan status dasar, dan informasi aplikasi (`/api/v2/`).
  * Port 8002 (Secure WebSocket wss): Digunakan untuk pairing handshake dan pengiriman perintah remote key (`/api/v2/channels/samsung.remote.control`). Fallback ke port 8001 (insecure WebSocket ws) didukung jika enkripsi TLS tidak aktif.
* **Mekanisme Pairing:**
  * Aplikasi membuka koneksi WebSocket dengan menyertakan parameter `name` yang telah di-encode Base64.
  * TV menampilkan prompt popup di layar: *"Izinkan perangkat Sugeng Rawuh terhubung?"*.
  * TV mengirimkan frame JSON `ms.channel.connect` yang berisi token sesi (`data.token`).
  * Driver menangkap token tersebut dan menyerahkannya ke `DeviceStatusManagementService::recordSuccessfulPairing()` untuk disimpan secara terenkripsi di kolom `physical_devices.pairing_payload`.
* **Matriks Perintah Driver:**

| Perintah Driver | Key / Payload TV | Status Software | Status Lab Fisik | Kebijakan Otomasi Worker | Keterangan Teknis |
|---|---|---|---|---|---|
| `GET_STATUS` | REST GET `/api/v2/` | **`IMPLEMENTED`** | **`PHYSICAL VERIFICATION PENDING`** | Diizinkan (Read-Only) | Mengembalikan status power dasar (`PowerState: on`) jika TV merespons. |
| `MUTE` | Remote Key `KEY_MUTE` | **`IMPLEMENTED`** | **`PHYSICAL VERIFICATION PENDING`** | Diizinkan (Kondisional) | Mengirimkan sinyal mute audio via WebSocket. Bersifat toggle. |
| `UNMUTE` | Remote Key `KEY_MUTE` | **`IMPLEMENTED`** | **`PHYSICAL VERIFICATION PENDING`** | Diizinkan (Kondisional) | Karena Samsung TV tidak memiliki key diskrit `UNMUTE`, driver mengirimkan `KEY_MUTE` kembali. |
| `STANDBY` | Remote Key `KEY_POWER` | **`IMPLEMENTED`** | **`PHYSICAL VERIFICATION PENDING`** | **`DISABLED / SKIPPED`** | Samsung TV menggunakan `KEY_POWER` yang bersifat toggle. Jika dikirim saat TV sudah mati, TV justru menyala. Dinonaktifkan otomatis oleh `AutomationCapabilityPolicy`. |
| `POWER_ON` | Wake-on-LAN / Magic Packet | **`NOT IMPLEMENTED`** | **`UNSUPPORTED / UNVERIFIED`** | **`DISABLED / SKIPPED`** | Saat standby, antarmuka WebSocket Samsung TV mati. Menghidupkan TV membutuhkan paket Wake-on-LAN (WOL) atau HDMI-CEC dari PlayStation, yang belum diimplementasikan di driver. |

---

### 3.2 Coocaa Smart TV 43S3U Plus (Coolita OS)

* **Status Dukungan Sistem:** **`UNSUPPORTED / NOT IMPLEMENTED`**
* **Bukti Validasi Repositori:**
  1. File `app/Http/Requests/StorePhysicalDeviceRequest.php` (Line 38):
     ```php
     'brand' => ['required', 'string', Rule::in(['SAMSUNG', 'SHELLY'])],
     ```
     Input brand `COOCAA` secara eksplisit ditolak dengan kode respon HTTP 422 Unprocessable Entity.
  2. File `app/Models/PhysicalDevice.php` (Lines 87–95):
     ```php
     if ($this->category === self::CATEGORY_SMART_TV && $this->brand !== self::BRAND_SAMSUNG) {
         throw new \InvalidArgumentException("Kategori SMART_TV hanya mendukung brand SAMSUNG.");
     }
     ```
  3. File `app/Http/Controllers/Admin/StationHardwareMappingController.php` (Line 63):
     Query perangkat TV yang dapat dipetakan secara eksplisit memfilter:
     ```php
     ->where('brand', PhysicalDevice::BRAND_SAMSUNG)
     ```
* **Karakteristik Sistem Operasi Coolita OS:**
  * Coolita OS adalah sistem operasi berbasis Linux tertutup (*proprietary*) yang dikembangkan oleh Coocaa / Skyworth untuk TV segmen ekonomis.
  * Tidak menyediakan API kendali jaringan publik yang terstandarisasi (berbeda dengan Samsung Tizen atau Android TV).
  * Tidak ada SDK remote control via jaringan lokal yang didokumentasikan secara terbuka.
* **Instruksi Operasional:**
  * Coocaa 43S3U Plus **tidak dapat didaftarkan** pada Device Registry Sugeng Rawuh.
  * Coocaa 43S3U Plus **tidak dapat dipetakan** ke station PlayStation.
  * Operasional TV Coocaa di rental **wajib dilakukan 100% secara manual** menggunakan remote control inframerah fisik oleh Kasir atau Pemain.

---

## 4. TEMUAN GAP IMPLEMENTASI (GAP ANALYSIS)

Berdasarkan audit komparatif antara desain arsitektur, kode aktual, dan kesiapan perangkat fisik, diidentifikasi 5 gap utama:

### Gap 1: Ketiadaan Driver dan Penolakan Eksplisit untuk Smart TV Coocaa
* **Deskripsi:** Perangkat Coocaa 43S3U Plus yang ada di lokasi rental tidak memiliki representasi driver atau controller di dalam kode aplikasi. Validasi form pendaftaran dan model secara aktif menolak pendaftaran brand selain `SAMSUNG`.
* **Dampak:** Admin tidak dapat mendaftarkan TV Coocaa ke sistem. Tidak ada otomatisasi yang dapat dilakukan terhadap station yang menggunakan TV Coocaa.
* **Mitigasi Operasional Saat Ini:** Station PlayStation yang menggunakan TV Coocaa dijalankan dalam mode *Manual Fallback*. Kasir mengoperasikan billing melalui aplikasi, namun menyalakan dan mematikan TV menggunakan remote fisik.

### Gap 2: Sifat Toggle pada Key Samsung Smart TV (`KEY_POWER` & `KEY_MUTE`)
* **Deskripsi:** Protokol remote control Samsung Smart TV via WebSocket mengirimkan penekanan tombol (*key emulation*), bukan instruksi status absolut (*discrete power state*). `KEY_POWER` membalik status daya (On menjadi Off, Off menjadi On).
* **Dampak:** Jika TV secara fisik telah dimatikan manual oleh pelanggan, dan sistem otomasi mengirimkan perintah `STANDBY`, TV justru akan menyala kembali.
* **Mitigasi Saat Ini:** `AutomationCapabilityPolicy` secara cerdas mengintersepsi perintah `STANDBY` dan menandainya sebagai `SKIPPED_UNVERIFIED_CAPABILITY` sebelum dikirimkan ke driver, mencegah anomali di ruang rental.

### Gap 3: Keterbatasan Transpor WebSocket Saat TV dalam Kondisi Standby (Power Off)
* **Deskripsi:** Ketika Samsung TV berada dalam kondisi standby (mati), subsistem operasi Tizen memutus koneksi server WebSocket pada port 8001 dan 8002 untuk menghemat daya.
* **Dampak:** Perintah `POWER_ON` tidak dapat dikirimkan melalui WebSocket yang terputus.
* **Kebutuhan Teknis:** Menghidupkan TV Samsung dari kondisi standby memerlukan transmisi paket Wake-on-LAN (WOL UDP port 9) ke MAC Address TV, dengan catatan TV telah diatur ke mode *Power On with Mobile* pada menu pengaturan Tizen. Fitur WOL ini belum diimplementasikan di dalam `SamsungTvDriver.php`.

### Gap 4: Pendaftaran Perangkat Masih Bersifat Manual Penuh (No Auto-Discovery)
* **Deskripsi:** Sistem belum memiliki modul background discovery (seperti mDNS / Bonjour / SSDP UPnP) untuk memindai perangkat yang aktif di jaringan Wi-Fi/LAN rental.
* **Dampak:** Admin harus secara manual mencari IP Address dan MAC Address TV melalui menu pengaturan TV atau antarmuka router, lalu mengetikkannya satu per satu pada form registrasi. Kesalahan pengetikan IP/MAC dapat menyebabkan kegagalan koneksi.

### Gap 5: Fencing Keamanan Menolak Eksekusi Fisik Sebelum Verifikasi Lab (Gate G4-B2)
* **Deskripsi:** Konfigurasi `config('hardware.real_execution_enabled')` bernilai `false`. Seluruh panggilan diagnostik atau worker yang mencoba mengirimkan paket nyata ke jaringan akan diintersepsi oleh `HardwareSafetyGuard` dan menghasilkan HTTP 403 / Log Blocked.
* **Dampak:** Meskipun kode driver fisik nyata (`SamsungTvDriver.php`) telah selesai ditulis, driver tersebut belum dapat berkomunikasi dengan TV nyata hingga gate verifikasi fisik disetujui.

---

## 5. REKOMENDASI TEKNIS UNTUK GERBANG VERIFIKASI FISIK (GATE G4-B2)

Berikut adalah rekomendasi teknis yang harus dipersiapkan pada tahap implementasi berikutnya (G4-B2) **tanpa melakukan perubahan kode saat ini**:

1. **Implementasi Wake-on-LAN (WOL) pada `SamsungTvDriver`:**
   * Tambahkan metode `sendWakeOnLan(string $macAddress): bool` menggunakan socket UDP PHP (`socket_create(AF_INET, SOCK_DGRAM, SOL_UDP)`) untuk memancarkan *Magic Packet* (6 byte `0xFF` diikuti 16 repetisi MAC address TV).
   * Konfigurasikan Samsung TV 50U8500: *Settings -> General -> Network -> Expert Settings -> Power On with Mobile = ON*.

2. **Diferensiasi Discrete Power vs Toggle Key:**
   * Sebelum mengirimkan perintah daya, panggil probe HTTP REST `http://{ip}:8001/api/v2/`.
   * Jika REST merespons (TV sedang hidup) dan perintah adalah `STANDBY`, baru kirimkan `KEY_POWER`.
   * Jika REST timeout (TV sudah mati), batalkan pengiriman `KEY_POWER` untuk mencegah TV menyala kembali secara tidak sengaja.

3. **Protokol Verifikasi Fisik Samsung TV di Laboratorium:**
   * Siapkan router lab terisolasi dengan DHCP Reservation untuk Samsung TV 50U8500.
   * Aktifkan sementara flag testing: `HARDWARE_REAL_EXECUTION_ENABLED=true` hanya di environment lab.
   * Lakukan uji pairing fisik nyata melalui menu Diagnostics, setujui popup di layar TV, dan pastikan token berhasil disimpan di kolom `pairing_payload`.
   * Catat response time dan kestabilan WebSocket selama 50 siklus pengujian.

4. **Klarifikasi Kebijakan Operasional Coocaa:**
   * Mengingat Coocaa 43S3U Plus menggunakan Coolita OS yang tidak memiliki remote API terbuka, rekomendasikan kepada manajemen untuk mempertahankan kontrol manual remote fisik untuk unit Coocaa, ATAU memanfaatkan fitur HDMI-CEC dari konsol PlayStation (PlayStation hidup -> TV Coocaa otomatis hidup via sinyal HDMI).
   * Hindari penambahan driver palsu atau pemaksaan protokol yang tidak didukung secara resmi oleh pabrikan.

---

*Dokumen ini merupakan hasil audit resmi kode sumber Sugeng Rawuh (sistem_kasir_PS-main) dan merepresentasikan kondisi faktual implementasi software.*
