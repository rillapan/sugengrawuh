# G5-A RISKS AND DECISIONS REGISTER

**Document ID:** SR-G5-RISK-001  
**Project:** Sugeng Rawuh — Integrated Billing & POS Management System  
**Gate:** G5-A — Billing-to-Hardware Automation Architecture & Design  
**Status:** DRAFT / PROPOSAL FOR REVIEW  
**Date:** 2026-11-05  
**Author:** AI Systems Architect & Core Team  

---

## 1. FORMAL DECISION REGISTER

| Decision ID | Decision Title | Classification | Approval Source | Summary & Architectural Rationale |
|---|---|---|---|---|
| **SR-G5-BR-001** | **Automatic Billiard Light Shutdown on Prepaid Expiration** | **APPROVED** | User Decision (SR-G5-BR-001) | Lampu meja biliar langsung dimatikan otomatis ketika waktu prepaid habis tanpa masa tenggang dan tanpa konfirmasi kasir. Server `expires_at` adalah sumber kebenaran tunggal. Keputusan ini final dan tidak dapat diubah kembali. |
| **SR-G5-DR-002** | **Transactional Outbox Pattern Architecture** | **APPROVED (DIRECTION)** | User Review Prompt G5-A | Menggunakan pola Transactional Outbox (`automation_intents` table + worker polling) daripada queue broker eksternal (Redis). User menyetujui arah arsitektur Transactional Outbox sebagai dasar rancangan. |
| **SR-G5-DR-003** | **Session Generation Fencing Strategy** | **PROPOSED** | Engineering Proposal | Menggunakan token `session_generation` pada station dan transaction untuk mengeliminasi race condition antara perpanjangan (extend) vs kedaluwarsa (expire) serta mencegah replay perintah lama ke sesi baru. |
| **SR-G5-DR-004** | **Restricted Policy on Samsung Smart TV Power Commands** | **PROPOSED** | Safety Protocol | Perintah daya otomatis (`POWER_ON`, `STANDBY`) untuk Samsung TV dilarang dieksekusi secara otomatis kecuali telah terverifikasi secara fisik (`verified_capabilities`) di G4-B2. Menghindari risiko accidental power toggle di tengah permainan. |
| **SR-G5-DR-005** | **Strict Decoupling of Hardware from Financials** | **APPROVED** | User Mandatory Rule #9 | Kegagalan koneksi IoT, timeout, atau perangkat offline tidak boleh membatalkan penyelesaian transaksi billing atau memengaruhi perhitungan biaya sewa. Tidak ada fitur refund otomatis karena masalah hardware. |
| **SR-G5-DR-006** | **Physical Verification of Hardware Protocols** | **DEFERRED UNTIL G4-B2** | Gate Protocol | Pengujian driver pada G4-B1 dan G5-A menggunakan fake transport/mock server. Pengujian kontak relay fisik Shelly dan protokol Samsung TV di lokasi fisik ditunda sampai Gate G4-B2. Fake transport tidak boleh diklaim sebagai physical verification. |
| **SR-G5-DR-007** | **Audible Warning Chime Prior to Expiry** | **NEEDS USER APPROVAL** | Pending User Decision | Pertanyaan terbuka: Apakah perlu menambahkan bel/chime audio (melalui browser kasir atau buzzer hardware) 1 menit sebelum waktu habis? Tidak masuk MVP sebelum disetujui pengguna. |
| **SR-G5-DR-008** | **Batch Reconciliation Schedule & Frequency** | **PROPOSED** | Engineering Proposal | Proposal: Command `php artisan billing:reconcile` dijalankan secara berkala setiap hari pukul 08:30 WIB (sebelum jam buka kasir) dan saat startup worker untuk merekonsiliasi status fisik relay dengan database. |
| **SR-G5-DR-009** | **Cashier Manual Override Guardrail** | **PROPOSED** | Security Guardrail | Kasir hanya diizinkan memicu Coba Lagi untuk mematikan lampu yang gagal padam, atau mengulang perintah ON untuk sesi yang sedang aktif. Kasir dilarang keras menyalakan lampu tanpa sesi billing aktif. |

---

## 2. OPERATIONAL RISK ANALYSIS & MITIGATIONS

### 2.1 Risk 1: Sudden Room Blackout
- **Description:** If the billiard table lamp is the primary illumination source in the playing area, an abrupt automated cutoff at `expires_at` could plunge players into total darkness, creating physical safety hazards (e.g. bumping into tables, tripping over cues).
- **Severity:** High
- **Likelihood:** Medium
- **Mitigation (BR-OPS-001):**
  - Venue policy mandates independent general ambient room lighting (wall sconces, ceiling downlights) that remain powered on separate, non-automated electrical circuits 24/7 during business hours.
  - The automated Shelly relay strictly controls only the drop light directly suspended over the playing surface.

---

### 2.2 Risk 2: Player Disputes on Mid-Shot Cutoff
- **Description:** A player is in the middle of executing a critical shot when the light abruptly turns off at the exact second `expires_at` is reached.
- **Severity:** Medium
- **Likelihood:** High
- **Mitigation:**
  - Staff provides clear verbal notifications when 5 minutes and 1 minute remain, as indicated on the POS screen.
  - Venue house rules explicitly declare that all games are strictly timed to the second with zero grace period.
  - Cashier screen displays real-time countdown synchronized with the server clock to prove exact expiration.

---

### 2.3 Risk 3: Relay Contact Welding / Wear from Inrush Current
- **Description:** LED driver ballasts in commercial billiard drop lamps can generate high inrush currents upon ignition, potentially degrading or welding standard mechanical relay contacts over thousands of cycles.
- **Severity:** Medium
- **Likelihood:** Low
- **Mitigation:**
  - The Shelly 1PM Mini Gen3 features internal zero-cross switching detection to minimize contact arcing.
  - Typical billiard LED fixtures consume between 50W and 150W, well below the 16A (3680W) rated capacity of the Shelly 1PM Mini.
  - Hardware execution logs monitor `observed_power_watts` to detect anomalous power draw.

---

### 2.4 Risk 4: Stale Command Replay After Network Recovery
- **Description:** A Shelly relay is offline when a prepaid session expires. The device reconnects 30 minutes later, by which time a new customer has rented the table. If the old `SWITCH_OFF` command is replayed, the new customer's game will be abruptly cut off.
- **Severity:** High
- **Likelihood:** Medium
- **Mitigation (BR-PRE-006 & BR-MAP-004):**
  - Pre-execution fencing explicitly checks `intent.session_generation == station.session_generation`.
  - The start of a new session increments the station's generation token.
  - When the offline intent wakes up, the worker detects the generation mismatch, marks the intent `STALE_SESSION_CLOSED`, and discards the packet without activating the relay.

---

### 2.5 Risk 5: Consoles Damaged by Abrupt Power Cut
- **Description:** Attempting to cut PlayStation console power directly using a smart relay causes file system corruption, lost game saves, and potential NAND storage degradation.
- **Severity:** Critical
- **Likelihood:** Low (Prevented by Design)
- **Mitigation:**
  - Strict architectural invariant: Shelly relays are strictly prohibited from being installed on PlayStation console AC power cords.
  - Relays are reserved exclusively for billiard lighting.
  - PlayStation stations are controlled solely via video display inputs or software billing timers.

---

## 3. OPEN OPERATIONAL QUESTIONS (NEEDS USER APPROVAL)

The following operational questions require explicit user approval before implementation in G5-B or post-G5:
1. **Audible Warning Chime (Decision SR-G5-DR-007):**
   - *Option A:* Audio chime via browser kasir (HTML5 audio) 1 menit sebelum expiry.
   - *Option B:* Buzzer fisik terhubung ke Shelly Gen3 Add-on.
   - *Option C (Recommended):* Tanpa chime tambahan (mengandalkan visual countdown kasir dan verbal staff).
2. **Reconciliation Frequency (Decision SR-G5-DR-008):**
   - *Option A (Recommended):* Sekali sehari saat opening venue (08:30 WIB) + saat restart worker.
   - *Option B:* Setiap 6 jam via Laravel Scheduler cron.

