# SUGENG RAWUH — G5-A EXISTING SYSTEM AUDIT

**Project:** Sugeng Rawuh — Integrated Billing & POS Management System  
**Repository:** `sistem_kasir_PS-main`  
**Gate:** G5-A — Billing-to-Hardware Automation Architecture & Design  
**Previous Gate:** G4-B1 SOFTWARE COMPLETE — VERIFIED  
**Baseline Test Count:** 268 Automated Tests Passing (100% Green)  
**Target Hardware:** Samsung Smart TV 50U8500 & Shelly 1PM Mini Gen3  
**Status:** **AUDIT COMPLETE — READY FOR G5-A ARCHITECTURE DESIGN**  

---

## 1. TUJUAN AUDIT ARSITEKTUR G5-A

Audit ini dilakukan untuk memetakan secara faktual seluruh alur kerja, siklus hidup transaksi, mekanisme worker latar belakang, registri pemetaan perangkat keras, serta abstraksi driver yang ada pada repositori `sistem_kasir_PS-main`.

Tujuan utama audit adalah merancang integrasi otomatisasi hardware dari Billing Engine G2 ke Driver Hardware G4 tanpa:
1. Mengubah kontrak dan perhitungan keuangan pada Billing Engine G2 (`BillingService`).
2. Menimbulkan regresi pada 268 automated tests yang telah berstatus lulus.
3. Mengubah database produksi `billing-sugeng-rawuh` (22 tabel).
4. Menembus batas keamanan (*HardwareSafetyGuard*) yang telah diverifikasi pada G4-B1.

---

## 2. AUDIT BILLING ENGINE G2 (BILLINGSERVICE & TRANSAKSI)

### 2.1 File Sumber: `app/Services/BillingService.php`
`BillingService` merupakan satu-satunya sumber kebenaran (*single source of truth*) untuk mutasi sesi billing, timer, dan alur transaksi server-side.

Metode inti yang diaudit meliputi:
1. **`startSession(array $data, ?int $userId = null): Transaction`**
   - Menjalankan database transaction dengan `DB::transaction(...)`.
   - Mengambil baris stasiun `Device` dengan `lockForUpdate()` (pessimistic locking) untuk mencegah perlombaan (*race condition*).
   - Memvalidasi ketersediaan stasiun (`$device->status === 'Tersedia'`).
   - Memastikan tidak ada transaksi aktif dengan `billing_status = 'RUNNING'` pada stasiun tersebut.
   - Menentukan timestamp absolut server:
     - `started_at = Carbon::now()`
     - `waktu_mulai = $startedAt->format('H:i')`
   - **Untuk Sesi Prepaid:**
     - Menghitung `expires_at = $startedAt->copy()->addHours($jamMain)`.
     - `waktu_Selesai = $expiresAt->format('H:i')`.
     - `status_transaksi = 'sukses'`.
   - **Untuk Sesi Postpaid (Lost Time):**
     - `expires_at = null`.
     - `status_transaksi = 'berjalan'`.
     - `jam_main = '0 jam 0 menit'`.
   - Memperbarui status stasiun: `$device->update(['status' => 'Digunakan'])`.
   - Status awal billing: `billing_status = Transaction::BILLING_RUNNING`.

2. **`extendSession(int $transactionId, int $additionalUnits, bool $isMinutes = false): Transaction`**
   - Menjalankan `DB::transaction(...)` dengan mengunci baris transaksi via `lockForUpdate()`.
   - Memvalidasi bahwa sesi wajib berstatus `Transaction::BILLING_RUNNING`.
   - Menolak perpanjangan pada sesi `postpaid` (Lost Time).
   - Menghitung waktu expiry baru (`new_expires_at`):
     - Jika `expires_at` saat ini belum lewat: ditambahkan dari `expires_at` yang ada.
     - Jika `expires_at` sudah sedikit lewat: ditambahkan dari `Carbon::now()`.
   - Memperbarui transaksi:
     - `expires_at = $newExpiresAt`
     - `waktu_Selesai = $newExpiresAt->format('H:i')`
     - Perhitungan harga baru sesuai durasi total.
   - **Implikasi Kritis G5:** Perpanjangan sesi ini memajukan `expires_at`. Setiap rencana/antrean perintah pemadaman (`SWITCH_OFF`) yang telah dijadwalkan berdasarkan `expires_at` lama wajib dibatalkan atau dinyatakan kedaluwarsa (*stale*).

3. **`stopSession(int $transactionId, string $reason = 'manual_stop'): Transaction`**
   - Menjalankan `DB::transaction(...)` dengan `lockForUpdate()`.
   - Guard idempoten: jika `billing_status` sudah `COMPLETED` atau `STOPPED_EARLY`, transaksi langsung dikembalikan tanpa mutasi ulang.
   - Menetapkan `ended_at = Carbon::now()` dan `status_transaksi = 'selesai'`.
   - **Untuk Prepaid:**
     - Jika dihentikan sebelum expiry (`$endedAt->lt($transaction->expires_at)`): `billing_status = Transaction::BILLING_STOPPED_EARLY`.
     - Jika dihentikan saat/sesudah expiry: `billing_status = Transaction::BILLING_COMPLETED`.
   - **Untuk Postpaid (Lost Time):**
     - Menghitung durasi riil menit: `$startedAt->diffInMinutes($endedAt)`.
     - Menghitung tarif bertahap dan pembulatan Lost Time.
     - `billing_status = Transaction::BILLING_COMPLETED`.
   - Membebaskan stasiun: `$device->update(['status' => 'Tersedia'])`.

4. **`evaluateExpiration(?Carbon $now = null): array`**
   - Fungsi inti yang dipanggil oleh worker latar belakang (`php artisan billing:worker`).
   - Melakukan query terhadap transaksi aktif:
     ```php
     Transaction::where('billing_status', Transaction::BILLING_RUNNING)
         ->whereNotNull('expires_at')
         ->where('expires_at', '<=', $now)
         ->get();
     ```
   - Untuk setiap sesi yang kedaluwarsa, dilakukan sub-transaksi atomik dengan `lockForUpdate()`:
     - Re-verifikasi status: jika `billing_status !== BILLING_RUNNING` atau `expires_at > now`, evaluasi dibatalkan (*race condition protection*).
     - Mutasi state transaksi:
       - `ended_at = $lockedTx->expires_at`
       - `billing_status = Transaction::BILLING_EXPIRED_PENDING_ACTION`
       - `status_transaksi = 'selesai'`
     - Membebaskan stasiun: `$lockedTx->device->update(['status' => 'Tersedia'])`.
   - **Implikasi Kritis G5 (Decision SR-G5-BR-001):**
     Status `EXPIRED_PENDING_ACTION` menandakan waktu bermain telah habis secara sah pada server, namun pembayaran atau tindakan kasir masih tertunda.
     Sesuai keputusan bisnis final, **lampu biliar harus segera dimatikan pada saat transisi ini terjadi**, tanpa menunggu kasir menekan tombol apapun di frontend!

---

## 3. AUDIT TRANSACTION LIFECYCLE & STATUS MATRIX

Berikut adalah diagram state machine dan transisi status sesi billing G2:

```
                  +----------------------------------+
                  |               IDLE               |
                  +----------------------------------+
                                    |
                                    | startSession()
                                    v
                  +----------------------------------+
                  |             RUNNING              |
                  +----------------------------------+
                    /               |              \
      extendSession|                |stopSession()  | evaluateExpiration()
                   v                v               v
           [Updated expires_at]  [STOPPED_EARLY]  [EXPIRED_PENDING_ACTION]
                                 [  COMPLETED  ]            |
                                                            | Kasir bayar / tutup
                                                            v
                                                      [ COMPLETED ]
```

### Tabel Rangkuman State Transaksi G2:

| `billing_status` | Arti Operasional | Kondisi Stasiun | Peran Hardware G5 |
|---|---|---|---|
| `RUNNING` | Pelanggan sedang bermain aktif | Digunakan | Lampu Biliar ON / TV ON |
| `EXPIRED_PENDING_ACTION` | Waktu prepaid habis; pembayaran/checkout belum tuntas | Tersedia | **Lampu Biliar WAJIB MATI (OFF SEGERA)** / TV Standby |
| `STOPPED_EARLY` | Kasir/pelanggan menghentikan sesi sebelum waktu habis | Tersedia | Lampu Biliar OFF / TV Standby |
| `COMPLETED` | Sesi berhenti normal atau pembayaran lunas | Tersedia | Lampu Biliar OFF / TV Standby |

---

---

## 4. AUDIT CONTROLLER, ENDPOINTS & DIRECT MUTATION PATHS

Audit menyeluruh terhadap seluruh jalur mutasi status billing dalam repository:

1. **`DeviceController::stopDevice($id)` (Line 565–635) — DIRECT MUTATION:**
   - Digunakan oleh kasir pada dashboard untuk menghentikan transaksi aktif hari ini (`POST /device/{id}/stop`).
   - **Temuan Kritis:** Method ini melakukan mutasi langsung pada model tanpa memanggil `BillingService::stopSession()`:
     ```php
     $activeTransaction->update($updatePayload);
     $device->update(['status' => 'Tersedia']);
     ```
   - **Implikasi G5:** Jika otomasi hanya dipasang di dalam `BillingService::stopSession()`, tombol "Stop" pada dashboard kasir tidak akan pernah mematikan lampu!
   - **Integrasi Wajib G5-B:** `DeviceController::stopDevice()` harus didelegasikan untuk memanggil `BillingService::stopSession()` atau memanggil `AutomationIntentService::recordIntent(...)` secara konsisten dalam satu batas integrasi (*single integration boundary*).

2. **`TransactionController` Direct Mutations:**
   - `store()` (Line 542): Memulai transaksi baru dengan `billing_status = Transaction::BILLING_RUNNING`.
   - `update()` (Line 781, 829): Memperbarui status sesi jika ada perubahan jam main.
   - `bayar()` / Checkout (Line 115, 1081): Mengubah status transaksi menjadi `BILLING_COMPLETED`.
   - **Integrasi Wajib G5-B:** Seluruh jalur start, extend, dan stop harus memicu pembuatan/pembatalan automation intent.

3. **`BillingWorkerCommand` (`php artisan billing:worker`):**
   - Command daemon dengan opsi `--once` dan `--sleep=2`.
   - Memanggil `billingService->evaluateExpiration(now)` secara berkala.
   - Memiliki sinyal POSIX handling (`SIGINT`, `SIGTERM`) untuk shutdown anggun (*graceful*).
   - **Implikasi Latensi:** Karena worker G2 memiliki delay `--sleep=2`, otomasi pemadaman lampu G5 **tidak boleh bergantung pada loop 2 detik ini**. Sebagai gantinya, intent pemadaman pra-terjadwal (*pre-scheduled intent*) pada `expires_at` akan langsung dieksekusi oleh worker otomasi 500ms.

---

## 5. AUDIT STATION MAPPING REGISTRY (GATE G3 SCHEMA AKTUAL)

Berdasarkan `SHOW CREATE TABLE` pada database testing aktual:

1. **`physical_devices` Table & Model (`App\Models\PhysicalDevice`):**
   - Primary Key: `id` (`BIGINT UNSIGNED AUTO_INCREMENT`).
   - `category`: `enum('SMART_TV','SMART_RELAY')`.
   - `brand`: `enum('SAMSUNG','SHELLY')`.
   - `mac_address`: `varchar(17)` UNIQUE.
   - `ip_address`: `varchar(45)`.
   - `connection_status`: `enum('UNVERIFIED','ONLINE','OFFLINE')`.
   - `pairing_status`: `enum('UNVERIFIED','NOT_REQUIRED','PENDING','PAIRED','FAILED')`.
   - `verified_capabilities`: JSON nullable.
   - `is_active`: `tinyint(1) NOT NULL DEFAULT '1'`.

2. **`station_device_mappings` Table & Model (`App\Models\StationDeviceMapping`):**
   - Primary Key: `id` (`BIGINT UNSIGNED AUTO_INCREMENT`).
   - `station_id`: `BIGINT UNSIGNED NOT NULL`, FK ke `devices(id)` dengan `ON DELETE RESTRICT`.
   - `physical_device_id`: `BIGINT UNSIGNED NOT NULL`, FK ke `physical_devices(id)` dengan `ON DELETE RESTRICT`.
   - `mapping_role`: `enum('DISPLAY_TV','LIGHT_RELAY')`.
   - `mapping_status`: `enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE'` (Bukan `is_active`).
   - `active_physical_device_id` & `active_station_role`: Kolom virtual dengan unique constraint untuk menjamin aturan invarian 1 active mapping per station/role.

3. **Aturan Invarian Pemetaan (G3 Invariants):**
   - Stasiun `PLAYSTATION` hanya boleh dipetakan ke hardware `SMART_TV` (`SAMSUNG`) dengan peran `DISPLAY_TV`.
   - Stasiun `BILLIARD` hanya boleh dipetakan ke hardware `SMART_RELAY` (`SHELLY`) dengan peran `LIGHT_RELAY`.
   - Dilarang memetakan relay Shelly ke stasiun PlayStation/TV (`HardwareSafetyException`).
   - Dilarang mengubah mapping saat stasiun sedang aktif billing.

---

## 6. AUDIT HARDWARE DRIVERS & SECURITY (GATE G4-A & G4-B1)

1. **`HardwareDriverInterface`:**
   - `probe(PhysicalDevice $device): HardwareCommandResult`
   - `pair(PhysicalDevice $device, array $options = []): HardwareCommandResult`
   - `sendCommand(PhysicalDevice $device, string $command, array $payload = []): HardwareCommandResult`
   - `getStatus(PhysicalDevice $device): HardwareCommandResult`
   - `getSupportedCommands(): array`
   - `getDriverType(): string`

2. **`ShellyRelayDriver`:**
   - Perintah didukung: `SWITCH_ON`, `SWITCH_OFF`, `GET_STATUS`.
   - **Transport Contract:** Mendukung Metode A (`POST /rpc` dengan envelope JSON-RPC 2.0) dan Metode B (`POST /rpc/<method>`).
   - **Read-Back Verification:** Mengeksekusi `Switch.Set` diikuti langsung oleh `Switch.GetStatus {"id": 0}`. Mengembalikan status `STATE_MISMATCH` jika output fisik tidak sesuai dengan kondisi target.
   - Mengumpulkan telemetri kelistrikan: daya aktif (`apower`), tegangan (`voltage`), arus (`current`), temperatur.
   - Driver level guard membatasi operasi hanya untuk lampu biliar.

3. **`SamsungTvDriver`:**
   - Perintah didukung: `MUTE`, `UNMUTE`, `STANDBY`, `GET_STATUS`.
   - Perintah `POWER_ON` diblokir sebagai `CAPABILITY_UNSUPPORTED` kecuali secara eksplisit terdaftar di `verified_capabilities`.
   - Semantik `KEY_MUTE` adalah toggle fisik, bukan idempotent setter. Mengembalikan `ACK_TRANSPORT` dengan `audio_state = 'UNKNOWN_TOGGLE'`.
   - Transport ACK bukan bukti fisik perubahan layar.

4. **`HardwareSafetyGuard`:**
   - Master switch `config('hardware.real_execution_enabled')` bernilai `false` secara default.
   - Memblokir pengiriman perintah fisik riil sebelum Gate G4-B2.
   - Memeriksa otorisasi role ADMIN, allowlist target device ID, dan verifikasi kapabilitas.

---

## 7. IDENTIFIKASI GAP TEKNIS MENUJU OTOMASI G5

Berdasarkan audit di atas, diidentifikasi kesenjangan teknis yang harus dijembatani pada desain G5-A:

1. **Ketiadaan Asinkronitas & Decoupling:**
   Jika perintah hardware dipanggil langsung di dalam method `BillingService::startSession` atau `evaluateExpiration`:
   - Latensi jaringan perangkat (100ms – 3000ms) akan memperlambat database transaction dan UI kasir.
   - Jika hardware offline atau mengalami timeout, DB transaction berisiko rollback atau gagal commit, yang melanggar aturan: *kegagalan hardware tidak boleh membatalkan billing!*
2. **Ketiadaan Persistence untuk Automation Intents:**
   Belum ada tabel untuk melacak apakah suatu sesi billing telah menerbitkan perintah ON atau OFF, berapa kali percobaan retry dilakukan, dan apakah perintah tersebut berhasil diverifikasi.
3. **Ketiadaan Fencing Token / Anti-Stale Mechanism:**
   Jika kasir memperpanjang sesi pada detik ke-59, sementara worker expiry sedang mengambil daftar sesi kedaluwarsa, ada risiko perintah OFF lama tetap terkirim dan mematikan lampu yang baru saja diperpanjang. Diperlukan token generasi sesi (*session generation / fencing token*).

---

> [!NOTE]
> Audit ini mengonfirmasi bahwa seluruh fondasi G2, G3, dan G4 dalam kondisi stabil, konsisten, dan siap untuk integrasi otomasi G5 melalui arsitektur decoupled Transactional Outbox.
