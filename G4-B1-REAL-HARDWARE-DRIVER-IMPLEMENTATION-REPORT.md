# SUGENG RAWUH — G4-B1 REAL HARDWARE DRIVER IMPLEMENTATION REPORT

**Project:** Sugeng Rawuh — Integrated Billing & POS Management System  
**Repository:** `sistem_kasir_PS-main`  
**Gate:** G4-B1 — Real Hardware Driver Development & Protocol Testing  
**Status:** **G4-B1 SOFTWARE COMPLETE — VERIFIED**  
**Previous Gate:** G4-A MOCK IMPLEMENTATION COMPLETE — APPROVED  
**Baseline Test Count:** 191 Automated Tests Passing  
**Current Test Count:** **268 Automated Tests Passing (100% Green, 0 Failures, 1048 Assertions)**  
**Target Environment:** Local / Testing (`kasir_ps_test` MySQL database)  
**Production DB Status:** `billing-sugeng-rawuh` (22 tables, 100% Unmodified & Untouched)  
**Physical Hardware Safety Status:** **LOCKED / BLOCKED BY MULTI-TIER SAFETY GUARD (NO PHYSICAL CALLS EXECUTED)**  

---

## 1. AUDIT EXISTING IMPLEMENTATION

Sebelum penulisan kode dimulai, dilakukan audit menyeluruh terhadap arsitektur runtime, dependensi, dan skema basis data:

1. **Git State & Branch:**
   - Active Branch: `main`
   - Active Commit: `60cebb0 perubahan 2`
   - Baseline Automated Tests: 191 passing tests (G2, G3, dan G4-A).
2. **Database Separation Audit:**
   - Production Database: `billing-sugeng-rawuh` (22 tabel).
   - Test Database: `kasir_ps_test` (24 tabel — termasuk tabel `physical_devices` dan `station_device_mappings` yang dimigrasi pada G3/G4-A).
   - Seluruh automated tests dan manual browser QA dijalankan eksklusif pada database `kasir_ps_test`. Database `billing-sugeng-rawuh` tidak disentuh sama sekali.
3. **Composer Dependency Analysis:**
   - Dependensi komunikasi jaringan yang terpasang di `composer.json` mencakup `guzzlehttp/guzzle: ^7.0.1`.
   - Tidak ada library WebSocket pihak ketiga (seperti `ratchet/pawl` atau `textalk/websocket`).
   - Sesuai arahan audit Gate G4-B1, **tidak ada package Composer tambahan yang dipasang**. Protokol WebSocket Samsung Smart TV diimplementasikan secara native melalui PHP socket/stream RFC-6455 standar dengan pemisahan transport interface (`SamsungTransportInterface`). Hal ini menjamin zero maintenance risk, kompatibilitas penuh dengan PHP 8.2, dan isolasi total menggunakan fake transport.

---

## 2. RANCANGAN ARSITEKTUR HARDWARE DRIVER

Arsitektur driver dirancang dengan prinsip pemisahan transport dari domain driver (*Clean Architecture & Dependency Inversion*), mempertahankan kontrak `HardwareDriverInterface` dari G4-A:

```
                      +---------------------------------------+
                      |    Admin UI / Diagnostics Controller  |
                      +---------------------------------------+
                                          |
                                          v
                      +---------------------------------------+
                      |       HardwareDriverManager           |
                      +---------------------------------------+
                           /              |              \
                          /               |               \
                         v                v                v
                 Mode: 'none'       Mode: 'mock'      Mode: 'real' (Guarded)
                                    (G4-A Lab)                 |
                                                               v
                                              +--------------------------------+
                                              |      HardwareSafetyGuard       |
                                              +--------------------------------+
                                                    /                    \
                                                   /                      \
                                                  v                        v
                                  +----------------------+  +----------------------+
                                  |   SamsungTvDriver    |  |   ShellyRelayDriver  |
                                  +----------------------+  +----------------------+
                                             |                         |
                                             v                         v
                                  +----------------------+  +----------------------+
                                  |  SamsungTransport    |  |   ShellyTransport    |
                                  |     Interface        |  |      Interface       |
                                  +----------------------+  +----------------------+
                                       /            \            /            \
                                      v              v          v              v
                              [FakeTransport] [RealWebSocket] [FakeTransport] [RealHttpRpc]
```

### Komponen Kunci Arsitektur:
1. **Interface Contract:** [HardwareDriverInterface.php](file:///Users/mohammadkhairilaffan/Rillapancode/billing%20sugeng%20rawuh/sistem_kasir_PS-main/app/Services/Hardware/Contracts/HardwareDriverInterface.php) dipertahankan tanpa perubahan:
   - `probe(PhysicalDevice $device): HardwareCommandResult`
   - `pair(PhysicalDevice $device, array $options = []): HardwareCommandResult`
   - `sendCommand(PhysicalDevice $device, string $command, array $payload = []): HardwareCommandResult`
   - `getStatus(PhysicalDevice $device): HardwareCommandResult`
   - `getSupportedCommands(): array`
   - `getDriverType(): string`
2. **Transport Decoupling:**
   - [SamsungTransportInterface.php](file:///Users/mohammadkhairilaffan/Rillapancode/billing%20sugeng%20rawuh/sistem_kasir_PS-main/app/Services/Hardware/Transports/SamsungTransportInterface.php)
   - [ShellyTransportInterface.php](file:///Users/mohammadkhairilaffan/Rillapancode/billing%20sugeng%20rawuh/sistem_kasir_PS-main/app/Services/Hardware/Transports/ShellyTransportInterface.php)
3. **Multi-Mode Driver Resolution:**
   - `none`: Mode default (fail-safe). Melempar `HardwareSimulationException::simulationDisabled()`.
   - `mock`: Menjalankan simulator memori G4-A (`MockSamsungTvDriver`, `MockShellyRelayDriver`).
   - `real`: Menjalankan driver fisik (`SamsungTvDriver`, `ShellyRelayDriver`) **hanya jika lolos pemeriksaan `HardwareSafetyGuard`**.

---

## 3. SAMSUNG TRANSPORT & PROTOCOL IMPLEMENTATION

Driver Samsung Smart TV dikhususkan untuk model target **Samsung 50U8500** (Tizen OS 2016+):

### 3.1 Protokol Komunikasi WebSocket & REST
- **REST Discovery/Probe:** `http://{ip}:8001/api/v2/` membaca metadata perangkat (`modelName`, `PowerState`, `networkType`).
- **WebSocket Control:** 
  - Plain WebSocket: `ws://{ip}:8001/api/v2/channels/samsung.remote.control?name={base64_app_name}`
  - Secure WebSocket: `wss://{ip}:8002/api/v2/channels/samsung.remote.control?name={base64_app_name}&token={token}`
- **RFC-6455 Native Framing:** Diimplementasikan pada [SamsungWebSocketTransport.php](file:///Users/mohammadkhairilaffan/Rillapancode/billing%20sugeng%20rawuh/sistem_kasir_PS-main/app/Services/Hardware/Transports/SamsungWebSocketTransport.php) menggunakan PHP `stream_socket_client` dengan masking 4-byte acak pada client frames dan decoding frame server.
- **Strict TLS Security:** Port 8002 wajib memverifikasi sertifikat SSL (`verify_peer => true`, `verify_peer_name => true`, `allow_self_signed => false`). Tidak ada bypass TLS global.
- **Handling Handshake & Token:** Handshake event `ms.channel.connect` mengekstrak token pairing; event `ms.channel.unauthorized` dideteksi sebagai penolakan otorisasi.
- **Format Payload Remote Control:**
  ```json
  {
    "method": "ms.remote.control",
    "params": {
      "Cmd": "Click",
      "DataOfCmd": "KEY_MUTE",
      "Option": "false",
      "TypeOfRemote": "SendRemoteKey"
    }
  }
  ```

### 3.2 Audit Semantik Samsung MUTE / UNMUTE (Toggle vs Idempotent)
Sesuai hasil audit teknis mendalam terhadap protokol remote control Samsung Tizen:
1. **Perilaku Fisik Remote Key:** Pada TV Samsung fisik, kode remote `KEY_MUTE` adalah **tombol toggle**, bukan *idempotent state setter*. Menekan tombol mute saat TV bersuara akan mematikan audio; menekan tombol yang sama saat TV dalam kondisi hening akan menyalakan kembali audio (*unmute*).
2. **Ketiadaan Telemetri Audio:** REST API Samsung `/api/v2/` hanya melaporkan `PowerState` (ON/STANDBY) dan metadata perangkat; API tersebut **tidak menyediakan pembacaan status mute fisik maupun volume saat ini**.
3. **Pemisahan Transport Acknowledged vs Physical State Verified:**
   - Transport acknowledgment (`ACK_TRANSPORT`) hanya membuktikan frame WebSocket berhasil dikirim dan diakui oleh listener TV.
   - Driver **dilarang keras** mengubah status basis data atau memori lokal menjadi `MUTED` atau `UNMUTED` hanya berdasarkan respons transport ini.
   - Hasil eksekusi `MUTE` dan `UNMUTE` secara eksplisit melampirkan telemetri:
     `'audio_state' => 'UNKNOWN_TOGGLE'`, `'is_idempotent' => false`, dan `'physical_state_verified' => false`.
   - Pesan hasil diverifikasi: `"Frame remote 'UNMUTE' (KEY_MUTE toggle) terkirim (ACK_TRANSPORT). Status audio fisik TV tidak dapat dibaca dari transport dan belum terverifikasi (PHYSICAL_STATE_UNVERIFIED)."`.
4. **Perlindungan Otomatisasi G5:** Seluruh otomatisasi billing yang bergantung pada kepastian status audio diblokir sampai pengujian fisik interaktif pada Gate G4-B2 membuktikan ada/tidaknya discrete key code (misal: `KEY_VOLMUTE` vs toggle `KEY_MUTE`).
5. **Regression Test Suite:** Pengujian `repeated_mute_and_unmute_acts_as_toggle_without_mutating_local_audio_status` pada [SamsungTvDriverTest.php](file:///Users/mohammadkhairilaffan/Rillapancode/billing%20sugeng%20rawuh/sistem_kasir_PS-main/tests/Unit/SamsungTvDriverTest.php) memverifikasi transmisi berulang tanpa memutasi status lokal.

---

## 4. SHELLY RPC TRANSPORT IMPLEMENTATION & KONTRAK RESMI

Driver Shelly Smart Relay dikhususkan untuk **Shelly 1PM Mini Gen3** melalui protokol lokal HTTP JSON-RPC 2.0 yang divalidasi terhadap dokumentasi resmi [Shelly RPC Channels](https://shelly-api-docs.shelly.cloud/gen2/General/RPCChannels/):

### 4.1 Rekonsiliasi & Dukungan Dua Format RPC Resmi
Sebelumnya terdapat ketidakcocokan antara endpoint path `/rpc/{method}` dan body payload JSON-RPC 2.0. Hal ini telah dikoreksi sepenuhnya pada [ShellyHttpRpcTransport.php](file:///Users/mohammadkhairilaffan/Rillapancode/billing%20sugeng%20rawuh/sistem_kasir_PS-main/app/Services/Hardware/Transports/ShellyHttpRpcTransport.php) dengan mendukung dua format resmi:

- **Metode A (Standard JSON-RPC 2.0 — Default Proyek):**
  - **Endpoint:** `POST /rpc`
  - **Request Body Frame:**
    ```json
    {
      "id": 1,
      "src": "sugeng_rawuh_pos",
      "method": "Switch.Set",
      "params": {
        "id": 0,
        "on": true
      }
    }
    ```
  - **Parser Response:** Memeriksa field `error` terstruktur (`code`, `message`). Jika sukses, mengekstrak data dari `result` (contoh: `{"id": 1, "src": "...", "result": {"was_on": false}}`).
- **Metode B (Short Endpoint-per-Method):**
  - **Endpoint:** `POST /rpc/Switch.Set` (atau `/rpc/Switch.GetStatus`)
  - **Request Body (Params Only):**
    ```json
    {
      "id": 0,
      "on": true
    }
    ```
  - **Parser Response:** Memeriksa apakah response mengembalikan objek error langsung (`code`, `message`). Jika sukses, response JSON merupakan isi hasil perintah secara langsung (contoh: `{"was_on": false}`).

Transport menyediakan metode `setRpcFormat(ShellyHttpRpcTransport::FORMAT_STANDARD)` atau `FORMAT_SHORT` dan secara default menggunakan **Metode A** (`POST /rpc`) sesuai standar baku JSON-RPC 2.0.

### 4.2 Read-Back Verification State Sinkron
Setelah pengiriman perintah perubahan sakelar relay (`Switch.Set`), driver [ShellyRelayDriver.php](file:///Users/mohammadkhairilaffan/Rillapancode/billing%20sugeng%20rawuh/sistem_kasir_PS-main/app/Services/Hardware/Drivers/ShellyRelayDriver.php) tidak langsung mempercayai kode sukses HTTP semata, melainkan langsung melakukan panggilan read-back:
1. Mengirim `Switch.GetStatus` dengan parameter `{"id": 0}`.
2. Memeriksa nilai boolean `output` pada respons read-back.
3. Jika nilai `output` tidak sama dengan kondisi yang diperintahkan (`targetOn`), driver mengembalikan hasil gagal `STATE_MISMATCH`.
4. Jika sesuai, driver mengembalikan status `SUCCESS` beserta telemetri kelistrikan: daya aktif (`apower`), tegangan (`voltage`), arus (`current`), dan temperatur (`temperature.tC`).

### 4.3 Transport Contract Testing
Seluruh kontrak HTTP RPC Shelly diuji secara ketat pada [ShellyHttpRpcTransportContractTest.php](file:///Users/mohammadkhairilaffan/Rillapancode/billing%20sugeng%20rawuh/sistem_kasir_PS-main/tests/Unit/ShellyHttpRpcTransportContractTest.php) (7 automated tests) menggunakan Guzzle `MockHandler` untuk memverifikasi kesesuaian URI path, payload request, parser response, penanganan error JSON-RPC, digest auth 401, timeout, serta siklus read-back driver.

### 4.4 Hardware Safety Rule: Isolasi Meja Biliar
- Shelly 1PM Mini Gen3 dalam sistem ini **HANYA diizinkan dipetakan ke station biliar** dengan kategori `BILLIARD` dan peran mapping `LIGHT_RELAY`.
- Upaya memetakan atau mengirim perintah Shelly ke station PlayStation atau display TV akan ditolak seketika melalui [HardwareSafetyException](file:///Users/mohammadkhairilaffan/Rillapancode/billing%20sugeng%20rawuh/sistem_kasir_PS-main/app/Exceptions/HardwareSafetyException.php).
- Dilarang memutus aliran listrik utama TV atau konsol PS menggunakan sakelar relay Shelly.

---

## 5. DRIVERMANAGER INTEGRATION & SAFETY SWITCH

[HardwareDriverManager.php](file:///Users/mohammadkhairilaffan/Rillapancode/billing%20sugeng%20rawuh/sistem_kasir_PS-main/app/Services/Hardware/HardwareDriverManager.php) diperbarui untuk memfasilitasi integrasi fail-safe:

1. **Mode Selection:**
   - Nilai default konfigurasi `config('hardware.driver')` tetap `'none'`.
   - Jika mode `'real'` dipilih, driver manager memanggil `HardwareSafetyGuard::isRealExecutionEnabled()`. Jika safety guard nonaktif (default G4-B1), eksekusi digagalkan dengan aman melempar [HardwareSafetyException](file:///Users/mohammadkhairilaffan/Rillapancode/billing%20sugeng%20rawuh/sistem_kasir_PS-main/app/Exceptions/HardwareSafetyException.php).
2. **Pluggable Transports for Testing:**
   - Menyediakan method `setSamsungTransport(SamsungTransportInterface $transport)` dan `setShellyTransport(ShellyTransportInterface $transport)`.
   - Hal ini memungkinkan seluruh pengujian otomatis dan laboratorium diagnostik menginjeksi [FakeSamsungWebSocketTransport](file:///Users/mohammadkhairilaffan/Rillapancode/billing%20sugeng%20rawuh/sistem_kasir_PS-main/app/Services/Hardware/Transports/FakeSamsungWebSocketTransport.php) dan [FakeShellyHttpRpcTransport](file:///Users/mohammadkhairilaffan/Rillapancode/billing%20sugeng%20rawuh/sistem_kasir_PS-main/app/Services/Hardware/Transports/FakeShellyHttpRpcTransport.php).

---

## 6. DAFTAR PERINTAH YANG DIDUKUNG

| Perangkat Target | Perintah | Mapping Protokol Fisik | Arti / Efek | Status G4-B1 |
|---|---|---|---|---|
| **Samsung Smart TV** | `MUTE` | Remote Key: `KEY_MUTE` | Mematikan audio TV | Supported (Software Stub) |
| **Samsung Smart TV** | `UNMUTE` | Remote Key: `KEY_MUTE` | Mengaktifkan audio TV | Supported (Software Stub) |
| **Samsung Smart TV** | `STANDBY` | Remote Key: `KEY_POWER` | Mematikan layar TV ke standby | Supported (Software Stub) |
| **Samsung Smart TV** | `GET_STATUS` | REST: `GET /api/v2/` | Mengambil status daya & model | Supported (Software Stub) |
| **Shelly 1PM Mini Gen3** | `SWITCH_ON` | RPC: `Switch.Set {"id":0,"on":true}` | Menyalakan relay lampu biliar | Supported (Software Stub) |
| **Shelly 1PM Mini Gen3** | `SWITCH_OFF` | RPC: `Switch.Set {"id":0,"on":false}` | Mematikan relay lampu biliar | Supported (Software Stub) |
| **Shelly 1PM Mini Gen3** | `GET_STATUS` | RPC: `Switch.GetStatus {"id":0}` | Membaca output & telemetri daya | Supported (Software Stub) |

---

## 7. DAFTAR KEMAMPUAN BELUM TERVERIFIKASI (UNVERIFIED CAPABILITIES)

1. **`POWER_ON` dari Standby melalui WebSocket (Samsung TV):**
   - **Status:** **UNVERIFIED / BLOCKED.**
   - **Alasan Teknis:** Sebagian besar model Samsung Smart TV (termasuk lini target Samsung 50U8500) menonaktifkan kartu jaringan nirkabel/Ethernet dan menutup WebSocket listener saat berada dalam mode Deep Standby.
   - **Enforcement Driver:** Driver `SamsungTvDriver` mengembalikan hasil terstruktur `CAPABILITY_UNSUPPORTED` jika `POWER_ON` dipanggil tanpa verifikasi eksplisit di kolom `verified_capabilities`.
   - **Wake-on-LAN (WOL):** Tidak diasumsikan secara universal pada G4-B1; memerlukan audit konfigurasi ARP, broadcast subnet, dan Wake-on-Wireless LAN fisik pada G4-B2.
2. **Volumetrik Telemetri Daya Akurat (Shelly 1PM Mini):**
   - Nilai pembacaan watt pada perangkat nyata bervariasi bergantung pada beban ballast lampu meja biliar; kalibrasi sensor daya belum dapat diverifikasi sebelum G4-B2.
3. **Kompatibilitas Token Pairing Lintas Firmware Samsung:**
   - Algoritma token persistence telah disiapkan, namun masa berlaku token (expiry/revocation) pada model spesifik 50U8500 memerlukan verifikasi perangkat fisik.

---

## 8. PAIRING & CREDENTIAL SECURITY

Mekanisme keamanan kredensial dirancang dengan proteksi ketat (*Defense-in-Depth*):

1. **Enkripsi Data At-Rest:**
   - Token pairing disimpan pada kolom `pairing_payload` model [PhysicalDevice.php](file:///Users/mohammadkhairilaffan/Rillapancode/billing%20sugeng%20rawuh/sistem_kasir_PS-main/app/Models/PhysicalDevice.php).
   - Menggunakan cast bawaan Laravel `'pairing_payload' => 'encrypted'` (AES-256-CBC dengan APP_KEY).
2. **Proteksi Frontend & Logging:**
   - Kolom `pairing_payload` terdaftar pada `$hidden = ['pairing_payload']` sehingga tidak pernah diekspos dalam serialisasi JSON ke browser.
   - [HardwareNetworkSecurity::sanitizeLog()](file:///Users/mohammadkhairilaffan/Rillapancode/billing%20sugeng%20rawuh/sistem_kasir_PS-main/app/Services/Hardware/Security/HardwareNetworkSecurity.php) secara rekursif mereduksi key sensitif (`token`, `password`, `pairing_payload`, `auth`, `authorization`) menjadi `[REDACTED]`.
   - Token tidak pernah dilewatkan melalui query parameter URL browser.
3. **Otorisasi Ketat:**
   - Pairing hanya dapat diinisiasi melalui aksi manual pengguna dengan peran `ADMIN`. Kasir dan Owner dilarang keras.
   - Sistem tidak melakukan auto-pairing tanpa persetujuan Admin.

---

## 9. NETWORK SAFETY & SSRF PROTECTION

Layanan [HardwareNetworkSecurity.php](file:///Users/mohammadkhairilaffan/Rillapancode/billing%20sugeng%20rawuh/sistem_kasir_PS-main/app/Services/Hardware/Security/HardwareNetworkSecurity.php) mengimplementasikan 11 aturan perlindungan jaringan:

1. **Target IP Source:** Alamat IP target hanya diambil dari `physical_devices` registry resmi, bukan dari input bebas form request.
2. **Validasi IPv4 Privat RFC 1918:** Hanya menerima range `10.0.0.0/8`, `172.16.0.0/12`, dan `192.168.0.0/16`.
3. **Pemberantasan SSRF:**
   - Menolak loopback `127.0.0.0/8`.
   - Menolak link-local / cloud metadata `169.254.0.0/16` (misal `169.254.169.254`).
   - Menolak multicast `224.0.0.0/4`.
   - Menolak broadcast `255.255.255.255` dan wildcard `0.0.0.0/8`.
   - Menolak seluruh IP publik internet.
4. **Port Allowlist Ketat & Rekonsiliasi Konfigurasi:**
   - **Samsung Smart TV:** Port `8001` (WebSocket plain / REST API) dan `8002` (Secure WebSocket SSL).
   - **Shelly Smart Relay:** Port `80` (HTTP RPC default) dan `8080` (Alternative HTTP RPC port).
   - *Catatan Rekonsiliasi:* Penyebutan port 443 pada ringkasan percakapan sebelumnya merupakan kekeliruan ketik verbal. Konfigurasi resmi dan implementasi keamanan pada `config/hardware.php` dan `HardwareNetworkSecurity` secara konsisten hanya mengizinkan port `80` dan `8080` untuk Shelly. Port selain yang terdaftar (seperti SSH 22, HTTPS 443, MySQL 3306) ditolak seketika oleh `HardwareSafetyException`.
5. **Anti-Redirection SSRF:** Guzzle client pada Shelly transport dikonfigurasi `'allow_redirects' => false` dan `'http_errors' => false`.
6. **Limitasi Buffer & Timeout:**
   - Timeout koneksi maksimal 3000ms.
   - Batas ukuran respons payload maksimal 16KB (Samsung) dan 64KB (Shelly).

---

## 10. REAL DRIVER SAFETY GUARD & DIAGNOSTICS UI

Layanan [HardwareSafetyGuard.php](file:///Users/mohammadkhairilaffan/Rillapancode/billing%20sugeng%20rawuh/sistem_kasir_PS-main/app/Services/Hardware/Security/HardwareSafetyGuard.php) memastikan transmisi soket fisik ke jaringan nyata diblokir 100% pada Gate G4-B1:

- Konfigurasi `config('hardware.real_execution_enabled')` bernilai `false` secara default.
- Panggilan dengan parameter `mode: 'real'` langsung memicu respons HTTP 403 Forbidden:
  `"SAFETY GUARD ACTIVE: Eksekusi jaringan ke hardware fisik dinonaktifkan (HARDWARE_REAL_EXECUTION_ENABLED = false). Perangkat fisik belum diverifikasi (G4-B1)."`

### 10.1 Pemisahan Otorisasi Diagnostik vs Operational Command
Pengamanan bertingkat diterapkan pada controller [HardwareDiagnosticsController.php](file:///Users/mohammadkhairilaffan/Rillapancode/billing%20sugeng%20rawuh/sistem_kasir_PS-main/app/Http/Controllers/Admin/HardwareDiagnosticsController.php):
1. **Probe dan Pairing (`isDiagnostic = true`):** Dapat dijalankan untuk mendeteksi reachability perangkat dan melakukan pertukaran token otorisasi meskipun kapabilitas perangkat (`verified_capabilities`) masih kosong/belum terverifikasi.
2. **Operational Commands (`isDiagnostic = false`):** Pengiriman perintah fisik (`SWITCH_ON`, `STANDBY`, dll.) melalui `sendCommand()` **wajib** lolos verifikasi kapabilitas (`verified_capabilities`). Perintah yang belum diverifikasi ditolak secara instan dengan `CAPABILITY_NOT_VERIFIED`.

### 10.2 Antarmuka Diagnostik Blade UI:
- **Lokasi View:** [resources/views/admin/hardware/diagnostics.blade.php](file:///Users/mohammadkhairilaffan/Rillapancode/billing%20sugeng%20rawuh/sistem_kasir_PS-main/resources/views/admin/hardware/diagnostics.blade.php)
- **Tema & Desain:** Bootstrap 4.6 & SB Admin 2 terintegrasi penuh.
- **Elemen Antarmuka:**
  - Banner peringatan isolasi G4-B1 dan badge pengaman fisik.
  - Tombol **Real Network terkunci / disabled** dengan badge proteksi safety guard.
  - Radio selector **Fake Transport aktif** dengan pilihan skenario (`SUCCESS`, `OFFLINE`, `TIMEOUT`, `AUTH_REJECTED`, `INVALID_JSON`).
  - Panel kontrol dinamis sesuai perangkat:
    - Samsung TV: Probe, Pair, Mute, Unmute, Standby, Power On (Unverified).
    - Shelly Relay: Probe, Pair, Relay ON, Relay OFF, Read Telemetry.
  - Terminal respons JSON live dengan badge status dan pesan disclaimer.
  - Tabel riwayat event diagnostik real-time.

---

## 11. HARDWARE SAFETY & STATION MAPPING ENFORCEMENT

Diimplementasikan guard keselamatan hardware spesifik pada [ShellyRelayDriver.php](file:///Users/mohammadkhairilaffan/Rillapancode/billing%20sugeng%20rawuh/sistem_kasir_PS-main/app/Services/Hardware/Drivers/ShellyRelayDriver.php):

> [!CAUTION]
> **ATURAN KESELAMATAN MUTLAK:**  
> Shelly Smart Relay dalam sistem Sugeng Rawuh **hanya diizinkan untuk lampu meja biliar**.  
> **DILARANG KERAS** menggunakan Shelly untuk memutus aliran daya utama Samsung Smart TV atau PlayStation!

Jika terdapat kesalahan pemetaan di mana Shelly dipetakan ke station kategori `PLAYSTATION` atau memiliki peran mapping selain `LIGHT_RELAY`, driver langsung melempar [HardwareSafetyException](file:///Users/mohammadkhairilaffan/Rillapancode/billing%20sugeng%20rawuh/sistem_kasir_PS-main/app/Exceptions/HardwareSafetyException.php):
`"PELANGGARAN KESELAMATAN HARDWARE: Shelly Smart Relay dipetakan ke station PlayStation. DILARANG menggunakan relay untuk memutus daya TV/PlayStation! Shelly hanya boleh digunakan untuk lampu meja biliar."`

---

## 12. BILLING ENGINE PROTECTION (ZERO REGRESSION)

Gate G4-B1 tidak mengubah atau mengganggu alur billing existing:
- File `app/Services/BillingService.php`, timer logic, Lost Time, worker scheduler, dan lifecycle transaksi **tidak dimodifikasi**.
- Kontrol hardware pada Gate G4-B1 masih berupa operasi diagnostik manual oleh ADMIN.
- **TIDAK ADA integrasi otomatis** antara mulai billing dengan TV ON / Shelly ON (pekerjaan ini direservasi secara ketat untuk Gate G5).
- Station tanpa hardware fisik yang dipetakan tetap dapat menjalankan sesi billing tanpa hambatan.

---

## 13. DAFTAR FILE BARU DAN DIUBAH

### File Baru (17 File):
1. `app/Exceptions/HardwareSafetyException.php` — Exception domain untuk pelanggaran safety guard & network security.
2. `app/Services/Hardware/Security/HardwareNetworkSecurity.php` — Validasi IP privat RFC 1918, SSRF prevention, port allowlists, sanitasi log.
3. `app/Services/Hardware/Security/HardwareSafetyGuard.php` — Master safety guard pemblokir transmisi jaringan fisik.
4. `app/Services/Hardware/DeviceStatusManagementService.php` — Layanan pembaruan status & kredensial terlindungi.
5. `app/Services/Hardware/Transports/SamsungTransportInterface.php` — Kontrak transport Samsung TV.
6. `app/Services/Hardware/Transports/SamsungWebSocketTransport.php` — Implementasi transport native RFC-6455 WebSocket & REST Samsung.
7. `app/Services/Hardware/Transports/FakeSamsungWebSocketTransport.php` — Fake transport tiruan untuk automated testing Samsung.
8. `app/Services/Hardware/Transports/ShellyTransportInterface.php` — Kontrak transport Shelly Gen3.
9. `app/Services/Hardware/Transports/ShellyHttpRpcTransport.php` — Implementasi transport HTTP JSON-RPC 2.0 Shelly.
10. `app/Services/Hardware/Transports/FakeShellyHttpRpcTransport.php` — Fake transport tiruan untuk automated testing Shelly.
11. `app/Services/Hardware/Drivers/SamsungTvDriver.php` — Real driver Samsung Smart TV (software protocol implementation).
12. `app/Services/Hardware/Drivers/ShellyRelayDriver.php` — Real driver Shelly 1PM Mini Gen3 (software protocol implementation).
13. `app/Http/Requests/DiagnosticProbeRequest.php` — Validasi request probe diagnostik.
14. `app/Http/Requests/DiagnosticPairRequest.php` — Validasi request pairing diagnostik.
15. `app/Http/Requests/DiagnosticCommandRequest.php` — Validasi request perintah diagnostik.
16. `app/Http/Controllers/Admin/HardwareDiagnosticsController.php` — Controller API & Blade Hardware Diagnostics.
17. `resources/views/admin/hardware/diagnostics.blade.php` — Antarmuka Blade UI Hardware Diagnostics.

### File Diubah (6 File):
1. `config/hardware.php` — Menambahkan konfigurasi driver real, safety switch, port allowlists, application identity.
2. `app/Services/Hardware/DTO/HardwareCommandResult.php` — Menambahkan helper factory `createRealResult`, `createUnsupportedCapability`, `createAuthRequired`, serta alias properti `$telemetry`.
3. `app/Services/Hardware/HardwareDriverManager.php` — Integrasi mode `'real'`, safety guard check, dan dependency injection transport.
4. `routes/web.php` — Menambahkan route group `/admin/hardware/diagnostics/*` terproteksi Admin & CSRF.
5. `resources/views/layouts/sidebar.blade.php` — Menambahkan link navigasi Hardware Diagnostics untuk Admin.
6. `.env.testing` — Mengonfigurasi `SESSION_DRIVER=file`, `HARDWARE_DRIVER=real`, `HARDWARE_REAL_EXECUTION_ENABLED=false`.

### Test Files Baru (8 File Test Suite — 77 Tests):
1. `tests/Unit/HardwareNetworkSecurityTest.php` (9 tests)
2. `tests/Unit/HardwareSafetyGuardTest.php` (7 tests)
3. `tests/Unit/SamsungTvDriverTest.php` (17 tests — termasuk uji toggle mute & status audio unknown)
4. `tests/Unit/ShellyRelayDriverTest.php` (14 tests)
5. `tests/Unit/ShellyHttpRpcTransportContractTest.php` (7 tests — verifikasi kontrak resmi Metode A, Metode B, dan read-back)
6. `tests/Feature/HardwareDiagnosticsApiTest.php` (11 tests)
7. `tests/Feature/HardwareDiagnosticsFrontendTest.php` (7 tests)
8. `tests/Feature/HardwareRealDriverSafetyTest.php` (5 tests)

---

## 14. HASIL AUTOMATED TESTS & REKONSILIASI PENUH

### 14.1 Penjelasan Rekonsiliasi Distribusi Angka Uji
Pada draf awal laporan sebelum audit, tercantum angka 260 tests tetapi daftar rincian kelas jika dijumlahkan menghasilkan 269 tests. Berikut adalah penjelasan rekonsiliasi faktual:
1. **MockShellyRelayDriverTest:** Jumlah sebenarnya adalah **9 tests** (sesuai baseline G4-A). Pada draf awal salah ketik tertulis `12 tests` (+3).
2. **TransactionFnbRelationTest:** Jumlah sebenarnya adalah **2 tests** (sesuai baseline G2). Pada draf awal salah ketik tertulis `6 tests` (+4).
3. **BillingWorkflowRegressionTest:** Jumlah sebenarnya adalah **13 tests** (sesuai baseline G2/G3). Pada draf awal salah ketik tertulis `16 tests` (+3).
4. **Tests\Unit\ExampleTest:** Jumlah sebenarnya adalah **1 test**, yang sempat terlewat tidak dicantumkan dalam daftar markdown (-1).
5. **Selisih Net Klerikal:** (+3 + 4 + 3 - 1) = +9 kesalahan ketik tabel. Total pengujian fisik yang dijalankan PHPUnit saat itu adalah 269 - 9 = **260 tests**.
6. **Penambahan Test Audit G4-B1:**
   - Ditambahkan 1 regression test pada `SamsungTvDriverTest` (menguji toggle mute). Total: 17 tests.
   - Ditambahkan 7 contract tests pada `ShellyHttpRpcTransportContractTest` (menguji kontrak resmi Shelly Metode A dan B). Total: 7 tests.
7. **Total Akhir Faktual:**
   - **Baseline G4-A:** 191 tests (100% utuh, 0 skipped, 0 deleted).
   - **G4-B1 Hardware Driver Tests:** 77 tests baru.
   - **Total Suite:** **268 automated tests (100% GREEN, 1048 assertions)**.

### 14.2 Rincian Faktual per Test Class (28 Kelas)
```
Tests\Unit\Example                                         : 1
Tests\Unit\HardwareDriverAbstraction                       : 5
Tests\Unit\HardwareNetworkSecurity                         : 9
Tests\Unit\HardwareSafetyGuard                             : 7
Tests\Unit\HardwareSimulationManager                       : 10
Tests\Unit\MockSamsungTvDriver                             : 12
Tests\Unit\MockShellyRelayDriver                           : 9
Tests\Unit\RbacAndSecurity                                 : 7
Tests\Unit\SamsungTvDriver                                 : 17
Tests\Unit\ShellyHttpRpcTransportContract                  : 7
Tests\Unit\ShellyRelayDriver                               : 14
Tests\Unit\TransactionFnbRelation                          : 2
Tests\Feature\BillingWorkflowRegression                    : 13
Tests\Feature\DeviceStopEndpoint                           : 3
Tests\Feature\Example                                      : 1
Tests\Feature\HardwareApiIntegration                       : 21
Tests\Feature\HardwareDiagnosticsApi                       : 11
Tests\Feature\HardwareDiagnosticsFrontend                  : 7
Tests\Feature\HardwareFinalIntegration                     : 8
Tests\Feature\HardwareFrontendSimulator                    : 7
Tests\Feature\HardwareFrontend                             : 15
Tests\Feature\HardwareRealDriverSafety                     : 5
Tests\Feature\HardwareSimulatorApi                         : 11
Tests\Feature\HardwareSimulatorSecurity                    : 6
Tests\Feature\MidnightTimerCrossing                        : 7
Tests\Feature\ServerSideBillingEngine                      : 23
Tests\Feature\StationMappingService                        : 19
Tests\Feature\TransactionFnbManagement                     : 11
--------------------------------------------------------------------
TOTAL AUTOMATED TESTS                                      : 268 passed (0 failed, 1048 assertions)
```

Raw PHPUnit Execution Summary:
```
PHPUnit 9.6.3 by Sebastian Bergmann and contributors.

...............................................................  63 / 268 ( 23%)
............................................................... 126 / 268 ( 47%)
............................................................... 189 / 268 ( 70%)
............................................................... 252 / 268 ( 94%)
................                                                268 / 268 (100%)

Time: 00:03.334, Memory: 58.50 MB

OK (268 tests, 1048 assertions)
```

---

## 15. HASIL MANUAL BROWSER QA

Pengujian manual antarmuka dilakukan menggunakan subagent browser otomatis pada server pengujian terisolasi (`http://127.0.0.1:8089` dengan database `kasir_ps_test`):

1. **Autentikasi & Navigasi Admin:** Admin berhasil login dan mengakses halaman `/admin/hardware/diagnostics`.
2. **Verifikasi Safety Guard Banner:** Banner `GATE G4-B1 SAFETY PROTOCOL & ISOLATION GUARD` dan badge `SAFETY GUARD: PHYSICAL BLOCKED` tampil dengan jelas.
3. **Pemeriksaan Tombol Real Network:** Tombol `Real Network` berstatus disabled/terkunci; radio button otomatis terpilih pada `Fake Transport`.
4. **Pengujian Samsung Smart TV (Fake Transport):**
   - Probe berhasil mengembalikan status `ONLINE` dengan latensi tiruan 4ms dan disclaimer `[G4-B1 TEST PROTOCOL: Fake Transport response]`.
   - Pairing berhasil menghasilkan status `PAIRED` dengan event `ms.channel.connect`.
   - Perintah `MUTE` dan `STANDBY` menghasilkan kode `ACK_TRANSPORT`.
   - Perintah `POWER_ON` mengembalikan penolakan `CAPABILITY_UNSUPPORTED` karena kemampuan wake-on-standby via WebSocket belum diverifikasi untuk model 50U8500.
5. **Pengujian Shelly 1PM Mini Gen3 (Fake Transport):**
   - Probe berhasil mengidentifikasi detail Gen3 `Plus1PMMini`.
   - Perintah `Relay ON` menyalakan output, melaporkan daya 45.2W, dan diverifikasi melalui read-back `Switch.GetStatus`.
   - Perintah `Relay OFF` mematikan output, melaporkan daya 0.0W.
6. **Pengujian Skenario Error Protokol:**
   - Skenario `OFFLINE`: Mengembalikan respons kegagalan host unreachable.
   - Skenario `TIMEOUT`: Mengembalikan respons timeout threshold habis.
   - Skenario `AUTH_REJECTED`: Mengembalikan respons penolakan otorisasi.
7. **Tabel Riwayat Diagnostik:** Seluruh riwayat operasi tercatat pada tabel diagnostik dengan label mode `fake` tanpa memutasi data fisik perangkat.
8. **Rekaman Sesi Browser:** Sesi browser tersimpan pada [hw_diag_qa_1793860082628.webp](file:///Users/mohammadkhairilaffan/.gemini/antigravity-ide/brain/d1fc05e6-6312-4abf-ae31-0377739a00d5/hw_diag_qa_1793860082628.webp).

---

## 16. BUKTI DATABASE UTAMA AMAN

Verifikasi integritas database dilakukan secara langsung melalui PDO query:

```php
$prodPdo = new PDO("mysql:host=127.0.0.1;port=8889;dbname=billing-sugeng-rawuh", "root", "root");
$prodTables = $prodPdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo "PROD table count: " . count($prodTables); // Output: 22

$testPdo = new PDO("mysql:host=127.0.0.1;port=8889;dbname=kasir_ps_test", "root", "root");
$testTables = $testPdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo "TEST table count: " . count($testTables); // Output: 24
```

- Database produksi `billing-sugeng-rawuh` tetap memiliki **22 tabel asli**, tanpa migrasi atau perubahan skema apapun.
- Database pengujian `kasir_ps_test` memiliki **24 tabel** dan terisolasi secara menyeluruh.
- Tidak ada query destruktif (`migrate:fresh`, `drop table`, atau mutasi data transaksi riil).

---

## 17. RISIKO FISIK DAN PROTOKOL YANG TERSISA

1. **Perilaku Standby Samsung 50U8500:** Apakah kartu jaringan TV memutus koneksi IP sepenuhnya saat dimatikan via remote fisik atau tetap merespons ping ARP.
2. **Kebutuhan Wake-on-LAN (WOL):** Jika TV memutus WiFi saat standby, sistem memerlukan paket siaran Wake-on-LAN (UDP port 9) dengan MAC address fisik pada subnet yang sama.
3. **Fluktuasi Jaringan Wi-Fi Lokal:** Shelly 1PM Mini yang menggunakan Wi-Fi 2.4GHz rentan terhadap interferensi sinyal di area rental; toleransi timeout 3000ms perlu divalidasi pada lingkungan fisik.
4. **Kompatibilitas Versi Firmware Shelly Gen3:** Respons RPC dapat mengalami perubahan minor format field JSON pada firmware versi tertentu.

---

## 18. CHECKLIST PENGUJIAN GATE G4-B2 (PHYSICAL VERIFICATION)

Ketika perangkat keras fisik sudah terpasang di lokasi rental, pengujian G4-B2 akan memverifikasi:

- [ ] Menghubungkan Samsung 50U8500 ke subnet IP privat yang telah ditentukan.
- [ ] Menghubungkan Shelly 1PM Mini Gen3 ke jaringan Wi-Fi lokal dan menghubungkan terminal relay ke lampu meja biliar.
- [ ] Mengaktifkan safety guard `HARDWARE_REAL_EXECUTION_ENABLED=true` pada `.env` staging/lapangan.
- [ ] Mendaftarkan ID perangkat fisik ke dalam `HARDWARE_REAL_ALLOWED_DEVICES`.
- [ ] Menjalankan real probe fisik untuk verifikasi respons latensi riil (< 50ms).
- [ ] Menjalankan real pairing Samsung TV dan menyetujui popup otorisasi pada layar kaca TV.
- [ ] Menguji perintah `MUTE`, `UNMUTE`, dan `STANDBY` pada Samsung TV dan mengamati perubahan fisik layar.
- [ ] Menguji apakah TV dapat dinyalakan kembali dari standby (uji kelayakan WOL).
- [ ] Menguji perintah `SWITCH_ON` dan `SWITCH_OFF` pada Shelly relay dan memverifikasi sakelar fisik lampu biliar menyala/padam secara sinkron.
- [ ] Memverifikasi telemetri pembacaan watt riil saat lampu biliar menyala.
- [ ] Menguji penanganan kabel LAN / router dicabut saat perintah dikirim.

---

## 19. TINDAKAN MANUAL PENGGUNA & KEPUTUSAN AKHIR

Tidak ada tindakan manual atau modifikasi skema yang diperlukan saat ini. Seluruh audit, rekonsiliasi protokol, koreksi kontrak transport Shelly, dan semantic safety Samsung telah diselesaikan dengan 268 automated tests lulus 100%.

> [!IMPORTANT]
> **KEPUTUSAN AKHIR G4-B1:**  
> **G4-B1 SOFTWARE COMPLETE — VERIFIED**  
> *(Software Driver & Testing Rig Completed — Physical Execution Strictly Locked Until G4-B2)*  
> Real network execution tetap terkunci (*disabled*) hingga pengujian fisik dengan perangkat keras nyata pada Gate G4-B2.
