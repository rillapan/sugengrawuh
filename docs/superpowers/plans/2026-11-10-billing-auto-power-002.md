# SR-BILLING-AUTO-POWER-002 — Otomatisasi ON/OFF Perangkat

**Project:** Sugeng Rawuh — Laravel 8

**Halaman:** `/transaction`, `/transaction/create`, `/device`

## Tujuan

Integrasikan transaksi billing dengan kontrol otomatis Samsung Smart TV dan Shelly Smart Relay.

**Kondisi saat ini:** ON/OFF manual melalui `/device` sudah berhasil. Jangan membuat ulang driver atau mengubah integrasi hardware yang sudah berfungsi.

## 1. Aturan Otomatisasi

| Jenis transaksi | Transaksi aktif | Waktu habis | Stop manual |
|---|---|---|---|
| Paket | ON | OFF | OFF |
| Lost Time | ON | Tidak otomatis OFF | OFF |
| Custom Paket | ON | OFF sesuai durasi | OFF |

Ketentuan:
- ON hanya ketika transaksi berhasil dan sesi billing ACTIVE.
- OFF ketika sesi benar-benar selesai.
- Extend tidak boleh memicu ON/OFF.
- Transaksi gagal/draft tidak boleh memicu perangkat.
- Berlaku untuk PlayStation (Samsung TV) dan Biliar (Shelly).
- Gunakan Station Mapping untuk menentukan perangkat.

## 2. Implementasi Laravel

1. Audit `TransactionController`, `BillingService`, `DeviceController`, `AutomationIntent`, Dispatcher, dan Worker.
2. Temukan penyebab transaksi belum memicu ON/OFF otomatis.
3. Hubungkan lifecycle billing dengan Automation Intent:
   - `SESSION_STARTED` → ON
   - `SESSION_STOPPED` → OFF
   - `SESSION_EXPIRED` → OFF
   - `SESSION_EXTENDED` → tetap ON
4. Gunakan driver dan service kontrol manual yang sudah berhasil.
5. Jalankan perintah hardware setelah database commit melalui worker, tanpa memblokir transaksi.
6. Pastikan Scheduler memproses expiry meskipun browser kasir ditutup.
7. Cegah duplikasi, race condition, dan perintah OFF dari sesi lama.

## 3. Sinkronisasi /device

- Billing aktif → station **DIGUNAKAN**.
- Billing selesai → station **TERSEDIA**.
- Status hardware ON/OFF diperbarui berdasarkan hasil eksekusi nyata.
- Tampilan diperbarui otomatis tanpa refresh manual.
- Jika hardware gagal, transaksi tetap berhasil dan error dicatat.

## 4. Pengujian Wajib

Uji ketiga jenis transaksi pada Samsung dan Shelly:

- ON setelah sesi aktif.
- OFF setelah sesi berakhir sesuai jenis transaksi.
- Lost Time tidak otomatis OFF sebelum Stop.
- Extend tidak mengirim ON/OFF.
- Transaksi gagal tidak mengaktifkan perangkat.
- Station lain tidak terpengaruh.
- Kontrol ON/OFF manual tetap berfungsi.

Gunakan database testing dan mock hardware terlebih dahulu.

## 5. Batasan

- Jangan membuat driver baru.
- Jangan mengubah IP, MAC, mapping, atau konfigurasi hardware yang sudah berfungsi.
- Jangan mengubah aturan bisnis billing.
- Jangan mengaktifkan eksekusi hardware nyata atau menguji perangkat pelanggan tanpa persetujuan.
- Jangan commit, push, atau deploy tanpa persetujuan.

**Eksekusi:** Audit akar masalah → Perbaiki integrasi → Jalankan pengujian → Laporkan hasil.

**Target akhir:** Setiap transaksi Paket, Lost Time, dan Custom Paket otomatis menyalakan perangkat ketika sesi aktif serta mematikannya ketika sesi selesai sesuai aturan billing, tanpa tindakan manual dari kasir.

---

## Global Constraints

- `HARDWARE_DRIVER=mock` and `HARDWARE_REAL_EXECUTION_ENABLED=false` must remain default in config
- Do not create new hardware drivers
- Do not change IP, MAC, mapping, or hardware config that already works
- Do not change billing business rules
- Do not activate real hardware execution or test customer devices without approval
- Do not commit, push, or deploy without approval
- Tests must run on `kasir_ps_test` database with `Config::set('hardware.driver', 'mock')` and `Config::set('hardware.real_execution_enabled', false)`
- Hardware failures must not roll back billing transactions

---

## Tasks

### Task 1: Audit & Root Cause — Why billing lifecycle does not trigger AutomationIntents

Audit the following files and document the missing integration points:
- `app/Http/Controllers/TransactionController.php`
- `app/Services/BillingService.php` (or equivalent billing service)
- `app/Models/AutomationIntent.php`
- `app/Services/Hardware/Automation/AutomationIntentService.php`
- `app/Console/Commands/` (scheduler / worker commands)
- Routing: `routes/web.php` for transaction endpoints

Deliverables:
1. List of lifecycle hooks in BillingService (session start, stop, extend, expire)
2. Identify whether AutomationIntentService is called from any billing lifecycle hook
3. Document the gap: what is NOT wired up
4. No code changes in this task — audit only
5. Produce a written report at the report file path

### Task 2: Wire SESSION_STARTED → AutomationIntent ON

After a billing session becomes ACTIVE:
- Call `AutomationIntentService` to create a PENDING intent with `desired_state = 'ON'`
- Use `session_generation` to fence the intent against stale sessions
- Do NOT block the billing transaction — intent creation is best-effort after DB commit
- Do NOT fire on extend, only on new session start
- Covers: Paket, Lost Time, Custom Paket

Integration point: `BillingService` (or `TransactionController`) after session is committed as ACTIVE.

Tests:
- Paket start → AutomationIntent created with desired_state ON
- Lost Time start → AutomationIntent created with desired_state ON  
- Custom Paket start → AutomationIntent created with desired_state ON
- Extend → no new AutomationIntent created
- Failed transaction → no AutomationIntent created
- Station without hardware mapping → no error, billing succeeds

### Task 3: Wire SESSION_STOPPED / SESSION_EXPIRED → AutomationIntent OFF

When a session ends (manual stop or time expiry):
- Call `AutomationIntentService` to create a PENDING intent with `desired_state = 'OFF'`
- Use `session_generation` fence: OFF intent must match the current generation
- Cancel/supersede any PENDING ON intent for the same station+generation
- Scheduler-driven expiry must work even when browser is closed

Rules by type:
- Paket: OFF on time expiry AND on manual stop
- Lost Time: OFF only on manual stop (no auto-expiry OFF)
- Custom Paket: OFF on duration expiry AND on manual stop
- Extend: does NOT trigger OFF (only the final stop does)

Tests:
- Paket expiry → AutomationIntent OFF created
- Paket manual stop → AutomationIntent OFF created
- Lost Time manual stop → AutomationIntent OFF created
- Lost Time expiry → no AutomationIntent OFF (Lost Time has no auto-expiry)
- Custom Paket expiry → AutomationIntent OFF created
- Extend → no OFF intent

### Task 4: Scheduler integration — ensure expiry worker runs headlessly

Verify and configure:
- `app/Console/Kernel.php` has a scheduled command that processes billing expiry
- The expiry command calls the same OFF logic as manual stop (Task 3 wiring)
- `AutomationDispatcherService::dispatchBatch()` is scheduled or triggered after intents are created
- Confirm: closing the browser does not leave sessions running indefinitely

Deliverables:
- Kernel.php schedule entries verified/added
- Integration test: create a session, advance time past expiry, run scheduler, assert OFF intent dispatched

### Task 5: Regression test suite — full lifecycle coverage

Write a Feature test class `AutomationBillingLifecycleTest` covering:

1. `test_paket_session_start_creates_on_intent()`
2. `test_paket_expiry_creates_off_intent()`
3. `test_paket_manual_stop_creates_off_intent()`
4. `test_lost_time_start_creates_on_intent()`
5. `test_lost_time_no_off_on_expiry()`
6. `test_lost_time_manual_stop_creates_off_intent()`
7. `test_custom_paket_start_creates_on_intent()`
8. `test_custom_paket_expiry_creates_off_intent()`
9. `test_extend_does_not_create_intent()`
10. `test_failed_transaction_does_not_create_intent()`
11. `test_station_without_mapping_billing_succeeds()`
12. `test_manual_power_control_still_works_during_active_session()`

All tests use `kasir_ps_test` DB, mock hardware driver, DatabaseTransactions.
