# Implementation Plan: F&B Product Images & Station Player Identity

**Date:** 2026-11-15  
**Document Reference:** User Request — F&B Product Images & Station Player Identity  
**Approach:** Test-Driven Development (TDD) via Subagent-Driven Development (SDD)

---

## Task Breakdown

### Task 1: F&B Product Images (Schema, Model, Controller & Admin Views)
- **Goal:** Support uploading, previewing, replacing, and displaying F&B product images across `/fnb/create`, `/fnb/edit`, and `/fnb/index`.
- **Files to create/modify:**
  - Create migration: `database/migrations/2026_11_15_000007_add_image_to_fnbs_table.php`
  - Create placeholder asset: `public/img/fnb-placeholder.svg`
  - Modify model: `app/Models/Fnb.php` (add `'image'` to fillable, add `image_url` accessor)
  - Modify controller: `app/Http/Controllers/FnbController.php` (store, update, destroy image management)
  - Modify views:
    - `resources/views/fnb/create.blade.php`
    - `resources/views/fnb/edit.blade.php`
    - `resources/views/fnb/index.blade.php`
  - Create test: `tests/Feature/Fnb/FnbProductImageTest.php`
- **Verification:**
  - Upload JPG/PNG validation (max 2MB, mime types).
  - Storage deletion on replace and delete.
  - Image preview script and offline placeholder rendering.

---

### Task 2: Station Player Identity on Station Page
- **Goal:** Show **Nama Pemain** on `/station/{code}`. Display active player's name when session is running (Paket / Lost Time) and **Belum Ada Pemain** when no active session.
- **Files to modify:**
  - Modify: `app/Services/Fnb/QrOrderService.php` & `app/Http/Controllers/PublicQrOrderController.php`
  - Modify: `resources/views/qr_order/menu.blade.php` (show `Nama Pemain: {{ $playerName }}`)
  - Modify: `resources/views/qr_order/no_session.blade.php` (show `Nama Pemain: Belum Ada Pemain`)
  - Update: `tests/Feature/QrOrdering/PublicQrOrderSubmissionTest.php` (align privacy test with explicit station player identity display)
  - Create test: `tests/Feature/QrOrdering/StationPlayerIdentityTest.php`
- **Verification:**
  - Active Lost Time displays player name.
  - Active Paket displays player name.
  - Ended/stopped session switches immediately to "Belum Ada Pemain".
  - Rollover to new session displays new player name.

---

### Task 3: Product Image Display on Station & QR Ordering Menu
- **Goal:** Display responsive product image thumbnails with offline placeholders on `/station/{code}` F&B menu cards.
- **Files to modify:**
  - Modify: `resources/views/qr_order/menu.blade.php` (add responsive product image thumbnail with placeholder)
  - Modify: `resources/views/fnb/orders_queue.blade.php` (display product thumbnail in cashier order selection if applicable)
  - Create test: `tests/Feature/QrOrdering/QrMenuImageDisplayTest.php`
- **Verification:**
  - Thumbnail displays uploaded image when present.
  - Placeholder displays when image is null.
  - Responsive layout, zero layout overflow.
  - Offline compliance: `ProductionReadinessTest` pass (0 external CDN/fonts).
  - Full suite: `php artisan test` (all tests pass).

