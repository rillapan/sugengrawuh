# SUGENG RAWUH — COMPLETE WEBSITE ROUTE MAP & URL AUDIT

**Project:** Sugeng Rawuh — Integrated Billing & POS Management System  
**Repository:** `sistem_kasir_PS-main`  
**Document Ref:** `docs/SUGENG-RAWUH-WEBSITE-ROUTE-MAP.md`  
**Application Base URL:** `http://127.0.0.1:8008` (Verifikasi `.env`: `APP_URL=http://127.0.0.1:8008`)  
**Laravel Framework:** Version 8.x  
**PHP Version:** 8.2  
**Total Routes Audited:** 68 Web Routes & 6 API Endpoints

---

## 1. PANDUAN PENGGUNAAN PETA RUTE WEBSITE

Seluruh rute pada dokumen ini merupakan rute faktual yang dihasilkan dari eksekusi `php artisan route:list` dan analisis langsung terhadap `routes/web.php` serta `routes/api.php`.

### Kategori Status Implementasi:
- **`IMPLEMENTED & VERIFIED`**: Halaman/endpoint telah terimplementasi penuh dengan Blade view atau respons JSON, serta telah lolos uji otomatis (310 automated tests passing).
- **`MOCK TESTED`**: Endpoint IoT yang bekerja pada lapisan simulasi software (Fake Driver / Mock Transport).
- **`PHYSICAL VERIFICATION PENDING`**: Endpoint driver nyata yang memerlukan kehadiran perangkat fisik di lab (Gate G4-B2).
- **`NOT IMPLEMENTED`**: Fitur belum dibuat kodenya.

---

## 2. DAFTAR LENGKAP RUTE WEBSITE (WEB ROUTES)

### 2.1 Autentikasi & Rute Publik

| No | Nama Fitur | Role Akses | HTTP Method & URI | Nama Route | URL Website Lengkap | Controller & Method | Middleware | Status Implementasi |
|---|---|---|---|---|---|---|---|---|
| 1 | Root Redirect | Publik / Tamu | `GET /` | N/A | [http://127.0.0.1:8008/](http://127.0.0.1:8008/) | `Closure` (Redirect to `/home`) | `web` | `IMPLEMENTED & VERIFIED` |
| 2 | Halaman Login | Publik / Tamu | `GET /login` | `login` | [http://127.0.0.1:8008/login](http://127.0.0.1:8008/login) | `Auth\LoginController@showLoginForm` | `web`, `guest` | `IMPLEMENTED & VERIFIED` |
| 3 | Proses Login | Publik / Tamu | `POST /login` | N/A | [http://127.0.0.1:8008/login](http://127.0.0.1:8008/login) | `Auth\LoginController@login` | `web`, `guest` | `IMPLEMENTED & VERIFIED` |
| 4 | Logout | Terotentikasi | `POST /logout` | `logout` | [http://127.0.0.1:8008/logout](http://127.0.0.1:8008/logout) | `Auth\LoginController@logout` | `web` | `IMPLEMENTED & VERIFIED` |

---

### 2.2 Rute Universal Terotentikasi (`auth`)

| No | Nama Fitur | Role Akses | HTTP Method & URI | Nama Route | URL Website Lengkap | Controller & Method | Middleware | Status Implementasi |
|---|---|---|---|---|---|---|---|---|
| 5 | Dasbor Pusat | OWNER, ADMIN, KASIR | `GET /home` | `home` | [http://127.0.0.1:8008/home](http://127.0.0.1:8008/home) | `HomeController@index` | `web`, `auth` | `IMPLEMENTED & VERIFIED` |
| 6 | Profil Pengguna | OWNER, ADMIN, KASIR | `GET /profile` | `profile` | [http://127.0.0.1:8008/profile](http://127.0.0.1:8008/profile) | `HomeController@profile` | `web`, `auth` | `IMPLEMENTED & VERIFIED` |
| 7 | Update Profil | OWNER, ADMIN, KASIR | `PUT /profile/{id}` | N/A | [http://127.0.0.1:8008/profile/{id}](http://127.0.0.1:8008/profile/1) | `HomeController@update` | `web`, `auth` | `IMPLEMENTED & VERIFIED` |
| 8 | Waktu Server | OWNER, ADMIN, KASIR | `GET /server-time` | N/A | [http://127.0.0.1:8008/server-time](http://127.0.0.1:8008/server-time) | `Closure` (JSON ISO & Timestamp) | `web`, `auth` | `IMPLEMENTED & VERIFIED` |
| 9 | Sinkronisasi Timer | OWNER, ADMIN, KASIR | `GET /device/timers` | `device.timers` | [http://127.0.0.1:8008/device/timers](http://127.0.0.1:8008/device/timers) | `DeviceController@getTimerSyncData` | `web`, `auth` | `IMPLEMENTED & VERIFIED` |
| 10 | Update Status Stasiun | OWNER, ADMIN, KASIR | `POST /device/{id}/update-status` | `device.updateStatus` | [http://127.0.0.1:8008/device/1/update-status](http://127.0.0.1:8008/device/1/update-status) | `DeviceController@updateStatus` | `web`, `auth` | `IMPLEMENTED & VERIFIED` |
| 11 | Update Semua Status | OWNER, ADMIN, KASIR | `POST /device/update-all-statuses` | `device.updateAllStatuses` | [http://127.0.0.1:8008/device/update-all-statuses](http://127.0.0.1:8008/device/update-all-statuses) | `DeviceController@updateDeviceStatusesFromTimers` | `web`, `auth` | `IMPLEMENTED & VERIFIED` |
| 12 | Data Grafik Area | OWNER, ADMIN, KASIR | `GET /chart-area-data` | N/A | [http://127.0.0.1:8008/chart-area-data](http://127.0.0.1:8008/chart-area-data) | `HomeController@areaCartData` | `web`, `auth` | `IMPLEMENTED & VERIFIED` |
| 13 | Data Grafik Pie | OWNER, ADMIN, KASIR | `GET /chart-pie-data` | N/A | [http://127.0.0.1:8008/chart-pie-data](http://127.0.0.1:8008/chart-pie-data) | `HomeController@pieCartData2` | `web`, `auth` | `IMPLEMENTED & VERIFIED` |
| 14 | Pendapatan Per Jam | OWNER, ADMIN, KASIR | `GET /hourly-revenue-data` | N/A | [http://127.0.0.1:8008/hourly-revenue-data](http://127.0.0.1:8008/hourly-revenue-data) | `HomeController@hourlyRevenueData` | `web`, `auth` | `IMPLEMENTED & VERIFIED` |

---

### 2.3 Rute Pemantauan Read-Only (`check_role:admin,kasir,owner`)

| No | Nama Fitur | Role Akses | HTTP Method & URI | Nama Route | URL Website Lengkap | Controller & Method | Middleware | Status Implementasi |
|---|---|---|---|---|---|---|---|---|
| 15 | Daftar Transaksi | OWNER, ADMIN, KASIR | `GET /transaction` | `transaction.index` | [http://127.0.0.1:8008/transaction](http://127.0.0.1:8008/transaction) | `TransactionController@index` | `web`, `auth`, `check_role:admin,kasir,owner` | `IMPLEMENTED & VERIFIED` |
| 16 | Detail Transaksi | OWNER, ADMIN, KASIR | `GET /transaction/{transaction}` | `transaction.show` | [http://127.0.0.1:8008/transaction/1](http://127.0.0.1:8008/transaction/1) | `TransactionController@show` | `web`, `auth`, `check_role:admin,kasir,owner` | `IMPLEMENTED & VERIFIED` |
| 17 | Cetak Struk Transaksi | OWNER, ADMIN, KASIR | `GET /transaction/{id}/print` | `transaction.print` | [http://127.0.0.1:8008/transaction/1/print](http://127.0.0.1:8008/transaction/1/print) | `TransactionController@printReceipt` | `web`, `auth`, `check_role:admin,kasir,owner` | `IMPLEMENTED & VERIFIED` |
| 18 | Data Perangkat Stasiun | OWNER, ADMIN, KASIR | `GET /device` | `device.index` | [http://127.0.0.1:8008/device](http://127.0.0.1:8008/device) | `DeviceController@index` | `web`, `auth`, `check_role:admin,kasir,owner` | `IMPLEMENTED & VERIFIED` |
| 19 | Stasiun Per Kategori PS | OWNER, ADMIN, KASIR | `GET /device/by-playstation/{id}` | `device.byPlaystation` | [http://127.0.0.1:8008/device/by-playstation/1](http://127.0.0.1:8008/device/by-playstation/1) | `DeviceController@byPlaystation` | `web`, `auth`, `check_role:admin,kasir,owner` | `IMPLEMENTED & VERIFIED` |
| 20 | Detail Stasiun | OWNER, ADMIN, KASIR | `GET /device/{device}` | `device.show` | [http://127.0.0.1:8008/device/1](http://127.0.0.1:8008/device/1) | `DeviceController@show` | `web`, `auth`, `check_role:admin,kasir,owner` | `IMPLEMENTED & VERIFIED` |
| 21 | Daftar Pengeluaran | OWNER, ADMIN, KASIR | `GET /expense` | `expense.index` | [http://127.0.0.1:8008/expense](http://127.0.0.1:8008/expense) | `ExpenseController@index` | `web`, `auth`, `check_role:admin,kasir,owner` | `IMPLEMENTED & VERIFIED` |
| 22 | Detail Pengeluaran | OWNER, ADMIN, KASIR | `GET /expense/{expense}` | `expense.show` | [http://127.0.0.1:8008/expense/1](http://127.0.0.1:8008/expense/1) | `ExpenseController@show` | `web`, `auth`, `check_role:admin,kasir,owner` | `IMPLEMENTED & VERIFIED` |
| 23 | Mapping Stasiun Read-Only | OWNER, ADMIN, KASIR | `GET /admin/stations/{stationId}/mappings` | `admin.stations.mappings.index` | [http://127.0.0.1:8008/admin/stations/1/mappings](http://127.0.0.1:8008/admin/stations/1/mappings) | `StationHardwareMappingController@index` | `web`, `auth`, `check_role:admin,kasir,owner` | `IMPLEMENTED & VERIFIED` |

---

### 2.4 Rute Operasional Mutasi Kasir (`check_role:admin,kasir`)

| No | Nama Fitur | Role Akses | HTTP Method & URI | Nama Route | URL Website Lengkap | Controller & Method | Middleware | Status Implementasi |
|---|---|---|---|---|---|---|---|---|
| 24 | Form Transaksi Baru | ADMIN, KASIR | `GET /transaction/create` | `transaction.create` | [http://127.0.0.1:8008/transaction/create](http://127.0.0.1:8008/transaction/create) | `TransactionController@create` | `web`, `auth`, `check_role:admin,kasir` | `IMPLEMENTED & VERIFIED` |
| 25 | Simpan Transaksi Baru | ADMIN, KASIR | `POST /transaction` | `transaction.store` | [http://127.0.0.1:8008/transaction](http://127.0.0.1:8008/transaction) | `TransactionController@store` | `web`, `auth`, `check_role:admin,kasir` | `IMPLEMENTED & VERIFIED` |
| 26 | Edit Transaksi | ADMIN, KASIR | `GET /transaction/{transaction}/edit` | `transaction.edit` | [http://127.0.0.1:8008/transaction/1/edit](http://127.0.0.1:8008/transaction/1/edit) | `TransactionController@edit` | `web`, `auth`, `check_role:admin,kasir` | `IMPLEMENTED & VERIFIED` |
| 27 | Update Transaksi | ADMIN, KASIR | `PUT /transaction/{transaction}` | `transaction.update` | [http://127.0.0.1:8008/transaction/1](http://127.0.0.1:8008/transaction/1) | `TransactionController@update` | `web`, `auth`, `check_role:admin,kasir` | `IMPLEMENTED & VERIFIED` |
| 28 | Layar Pembayaran | ADMIN, KASIR | `GET /transaction/{id}/payment` | `transaction.showPayment` | [http://127.0.0.1:8008/transaction/1/payment](http://127.0.0.1:8008/transaction/1/payment) | `TransactionController@showPayment` | `web`, `auth`, `check_role:admin,kasir` | `IMPLEMENTED & VERIFIED` |
| 29 | Proses Pembayaran | ADMIN, KASIR | `POST /transaction/{id}/process-payment` | `transaction.processPayment` | [http://127.0.0.1:8008/transaction/1/process-payment](http://127.0.0.1:8008/transaction/1/process-payment) | `TransactionController@processPayment` | `web`, `auth`, `check_role:admin,kasir` | `IMPLEMENTED & VERIFIED` |
| 30 | Selesaikan Transaksi | ADMIN, KASIR | `POST /transaction/{id}/end` | `transaction.end` | [http://127.0.0.1:8008/transaction/1/end](http://127.0.0.1:8008/transaction/1/end) | `TransactionController@endTransaction` | `web`, `auth`, `check_role:admin,kasir` | `IMPLEMENTED & VERIFIED` |
| 31 | Tambah FnB ke Transaksi | ADMIN, KASIR | `POST /transaction/{id}/fnb/add` | `transaction.fnb.add` | [http://127.0.0.1:8008/transaction/1/fnb/add](http://127.0.0.1:8008/transaction/1/fnb/add) | `TransactionController@addFnbToTransaction` | `web`, `auth`, `check_role:admin,kasir` | `IMPLEMENTED & VERIFIED` |
| 32 | Update Item FnB | ADMIN, KASIR | `PUT /transaction/{id}/fnb/{fnbId}/update` | `transaction.fnb.update` | [http://127.0.0.1:8008/transaction/1/fnb/1/update](http://127.0.0.1:8008/transaction/1/fnb/1/update) | `TransactionController@updateTransactionFnb` | `web`, `auth`, `check_role:admin,kasir` | `IMPLEMENTED & VERIFIED` |
| 33 | Hapus Item FnB | ADMIN, KASIR | `DELETE /transaction/{id}/fnb/{fnbId}/delete` | `transaction.fnb.delete` | [http://127.0.0.1:8008/transaction/1/fnb/1/delete](http://127.0.0.1:8008/transaction/1/fnb/1/delete) | `TransactionController@deleteTransactionFnb` | `web`, `auth`, `check_role:admin,kasir` | `IMPLEMENTED & VERIFIED` |
| 34 | Stop Billing Stasiun | ADMIN, KASIR | `POST /device/{id}/stop` | `device.stop` | [http://127.0.0.1:8008/device/1/stop](http://127.0.0.1:8008/device/1/stop) | `DeviceController@stopDevice` | `web`, `auth`, `check_role:admin,kasir` | `IMPLEMENTED & VERIFIED` |
| 35 | Form Tambah Biaya | ADMIN, KASIR | `GET /expense/create` | `expense.create` | [http://127.0.0.1:8008/expense/create](http://127.0.0.1:8008/expense/create) | `ExpenseController@create` | `web`, `auth`, `check_role:admin,kasir` | `IMPLEMENTED & VERIFIED` |
| 36 | Simpan Pengeluaran | ADMIN, KASIR | `POST /expense` | `expense.store` | [http://127.0.0.1:8008/expense](http://127.0.0.1:8008/expense) | `ExpenseController@store` | `web`, `auth`, `check_role:admin,kasir` | `IMPLEMENTED & VERIFIED` |
| 37 | Status Live Otomasi | ADMIN, KASIR | `GET /api/stations/{id}/automation-status` | `api.stations.automation-status` | [http://127.0.0.1:8008/api/stations/1/automation-status](http://127.0.0.1:8008/api/stations/1/automation-status) | `StationAutomationController@getStatus` | `web`, `auth`, `check_role:admin,kasir` | `IMPLEMENTED & VERIFIED` |
| 38 | Override Manual Otomasi | ADMIN, KASIR | `POST /api/stations/{id}/automation/manual-action` | `api.stations.automation-manual-action` | [http://127.0.0.1:8008/api/stations/1/automation/manual-action](http://127.0.0.1:8008/api/stations/1/automation/manual-action) | `StationAutomationController@manualAction` | `web`, `auth`, `check_role:admin,kasir` | `IMPLEMENTED & VERIFIED` |

---

### 2.5 Rute Laporan & Audit (`check_role:admin,owner`)

| No | Nama Fitur | Role Akses | HTTP Method & URI | Nama Route | URL Website Lengkap | Controller & Method | Middleware | Status Implementasi |
|---|---|---|---|---|---|---|---|---|
| 39 | Ringkasan Laporan | OWNER, ADMIN | `GET /report` | `report` | [http://127.0.0.1:8008/report](http://127.0.0.1:8008/report) | `ReportController@index` | `web`, `auth`, `check_role:admin,owner` | `IMPLEMENTED & VERIFIED` |
| 40 | Laporan Detail Transaksi | OWNER, ADMIN | `GET /report/transaction` | `transaction.report` | [http://127.0.0.1:8008/report/transaction](http://127.0.0.1:8008/report/transaction) | `ReportController@transactionReport` | `web`, `auth`, `check_role:admin,owner` | `IMPLEMENTED & VERIFIED` |
| 41 | Ekspor Laporan Excel | OWNER, ADMIN | `GET /generate-excel` | `laporan.excel` | [http://127.0.0.1:8008/generate-excel](http://127.0.0.1:8008/generate-excel) | `ReportController@generateExcel` | `web`, `auth`, `check_role:admin,owner` | `IMPLEMENTED & VERIFIED` |
| 42 | Ekspor Laporan PDF | OWNER, ADMIN | `GET /generate-pdf` | N/A | [http://127.0.0.1:8008/generate-pdf](http://127.0.0.1:8008/generate-pdf) | `ReportController@generatePDF` | `web`, `auth`, `check_role:admin,owner` | `IMPLEMENTED & VERIFIED` |
| 43 | Laporan Penjualan FnB | OWNER, ADMIN | `GET /fnb/laporan-penjualan` | `fnb.laporan` | [http://127.0.0.1:8008/fnb/laporan-penjualan](http://127.0.0.1:8008/fnb/laporan-penjualan) | `FnbController@laporanPenjualan` | `web`, `auth`, `check_role:admin,owner` | `IMPLEMENTED & VERIFIED` |
| 44 | Ekspor Excel FnB | OWNER, ADMIN | `GET /fnb/laporan-penjualan/excel` | `fnb.laporan.excel` | [http://127.0.0.1:8008/fnb/laporan-penjualan/excel](http://127.0.0.1:8008/fnb/laporan-penjualan/excel) | `FnbController@exportExcel` | `web`, `auth`, `check_role:admin,owner` | `IMPLEMENTED & VERIFIED` |
| 45 | Ekspor PDF FnB | OWNER, ADMIN | `GET /fnb/laporan-penjualan/pdf` | `fnb.laporan.pdf` | [http://127.0.0.1:8008/fnb/laporan-penjualan/pdf](http://127.0.0.1:8008/fnb/laporan-penjualan/pdf) | `FnbController@exportPdf` | `web`, `auth`, `check_role:admin,owner` | `IMPLEMENTED & VERIFIED` |
| 46 | View Device Registry | OWNER, ADMIN | `GET /admin/hardware/registry` | `admin.hardware.registry` | [http://127.0.0.1:8008/admin/hardware/registry](http://127.0.0.1:8008/admin/hardware/registry) | `PhysicalDeviceController@registryView` | `web`, `auth`, `check_role:admin,owner` | `IMPLEMENTED & VERIFIED` |
| 47 | View Station Mapping | OWNER, ADMIN | `GET /admin/hardware/mappings` | `admin.hardware.mappings` | [http://127.0.0.1:8008/admin/hardware/mappings](http://127.0.0.1:8008/admin/hardware/mappings) | `StationHardwareMappingController@mappingsView` | `web`, `auth`, `check_role:admin,owner` | `IMPLEMENTED & VERIFIED` |

---

### 2.6 Rute Master Data & Konfigurasi Teknis (`check_role:admin`)

| No | Nama Fitur | Role Akses | HTTP Method & URI | Nama Route | URL Website Lengkap | Controller & Method | Middleware | Status Implementasi |
|---|---|---|---|---|---|---|---|---|
| 48 | Manajemen User | ADMIN | `GET /users` | `users.index` | [http://127.0.0.1:8008/users](http://127.0.0.1:8008/users) | `UserManagementController@index` | `web`, `auth`, `check_role:admin` | `IMPLEMENTED & VERIFIED` |
| 49 | Reset Password User | ADMIN | `POST /users/{id}/reset-password` | `users.reset-password` | [http://127.0.0.1:8008/users/1/reset-password](http://127.0.0.1:8008/users/1/reset-password) | `UserManagementController@resetPassword` | `web`, `auth`, `check_role:admin` | `IMPLEMENTED & VERIFIED` |
| 50 | Shift Jam Kerja | ADMIN | `GET /work_shifts` | `work_shifts.index` | [http://127.0.0.1:8008/work_shifts](http://127.0.0.1:8008/work_shifts) | `WorkShiftController@index` | `web`, `auth`, `check_role:admin` | `IMPLEMENTED & VERIFIED` |
| 51 | Jenis PlayStation | ADMIN | `GET /playstation` | `playstation.index` | [http://127.0.0.1:8008/playstation](http://127.0.0.1:8008/playstation) | `PlayController@index` | `web`, `auth`, `check_role:admin` | `IMPLEMENTED & VERIFIED` |
| 52 | Tarif Jam Rental PS | ADMIN | `GET /playstation/{id}/hourly-prices` | `hourly-prices.hourly-prices.index` | [http://127.0.0.1:8008/playstation/1/hourly-prices](http://127.0.0.1:8008/playstation/1/hourly-prices) | `HourlyPriceController@index` | `web`, `auth`, `check_role:admin` | `IMPLEMENTED & VERIFIED` |
| 53 | Custom Package | ADMIN | `GET /custom-package` | `custom-package.index` | [http://127.0.0.1:8008/custom-package](http://127.0.0.1:8008/custom-package) | `CustomPackageController@index` | `web`, `auth`, `check_role:admin` | `IMPLEMENTED & VERIFIED` |
| 54 | Kelola Barang FnB | ADMIN | `GET /fnb` | `fnb.index` | [http://127.0.0.1:8008/fnb](http://127.0.0.1:8008/fnb) | `FnbController@index` | `web`, `auth`, `check_role:admin` | `IMPLEMENTED & VERIFIED` |
| 55 | Kelompok Harga FnB | ADMIN | `GET /price-group` | `price-group.index` | [http://127.0.0.1:8008/price-group](http://127.0.0.1:8008/price-group) | `PriceGroupController@index` | `web`, `auth`, `check_role:admin` | `IMPLEMENTED & VERIFIED` |
| 56 | Manajemen Stok | ADMIN | `GET /stock` | `stock.index` | [http://127.0.0.1:8008/stock](http://127.0.0.1:8008/stock) | `StockController@index` | `web`, `auth`, `check_role:admin` | `IMPLEMENTED & VERIFIED` |
| 57 | Tambah Stok Barang | ADMIN | `GET /stock/{id}/add` | `stock.add` | [http://127.0.0.1:8008/stock/1/add](http://127.0.0.1:8008/stock/1/add) | `StockController@addForm` | `web`, `auth`, `check_role:admin` | `IMPLEMENTED & VERIFIED` |
| 58 | Riwayat Mutasi Stok | ADMIN | `GET /stock/{id}/history` | `stock.history` | [http://127.0.0.1:8008/stock/1/history](http://127.0.0.1:8008/stock/1/history) | `StockController@history` | `web`, `auth`, `check_role:admin` | `IMPLEMENTED & VERIFIED` |
| 59 | Kategori Pengeluaran | ADMIN | `GET /expense-category` | `expense-category.index` | [http://127.0.0.1:8008/expense-category](http://127.0.0.1:8008/expense-category) | `ExpenseCategoryController@index` | `web`, `auth`, `check_role:admin` | `IMPLEMENTED & VERIFIED` |
| 60 | Pengaturan Aplikasi | ADMIN | `GET /settings` | `settings.index` | [http://127.0.0.1:8008/settings](http://127.0.0.1:8008/settings) | `AppSettingsController@index` | `web`, `auth`, `check_role:admin` | `IMPLEMENTED & VERIFIED` |
| 61 | Reset Transaksi Toko | ADMIN | `POST /settings/reset-transactions` | `settings.reset-transactions` | [http://127.0.0.1:8008/settings/reset-transactions](http://127.0.0.1:8008/settings/reset-transactions) | `AppSettingsController@resetTransactions` | `web`, `auth`, `check_role:admin` | `IMPLEMENTED & VERIFIED` |
| 62 | Simpan Device Fisik | ADMIN | `POST /admin/hardware/devices` | `admin.hardware.devices.store` | [http://127.0.0.1:8008/admin/hardware/devices](http://127.0.0.1:8008/admin/hardware/devices) | `PhysicalDeviceController@store` | `web`, `auth`, `check_role:admin` | `IMPLEMENTED & VERIFIED` |
| 63 | Attach Mapping Stasiun | ADMIN | `POST /admin/stations/{stationId}/mappings` | `admin.stations.mappings.store` | [http://127.0.0.1:8008/admin/stations/1/mappings](http://127.0.0.1:8008/admin/stations/1/mappings) | `StationHardwareMappingController@store` | `web`, `auth`, `check_role:admin` | `IMPLEMENTED & VERIFIED` |
| 64 | Detach Mapping Stasiun | ADMIN | `POST /admin/stations/{stationId}/mappings/{id}/detach` | `admin.stations.mappings.detach` | [http://127.0.0.1:8008/admin/stations/1/mappings/1/detach](http://127.0.0.1:8008/admin/stations/1/mappings/1/detach) | `StationHardwareMappingController@detach` | `web`, `auth`, `check_role:admin` | `IMPLEMENTED & VERIFIED` |
| 65 | Lab Hardware Simulator | ADMIN | `GET /admin/hardware/simulator` | `admin.hardware.simulator.index` | [http://127.0.0.1:8008/admin/hardware/simulator](http://127.0.0.1:8008/admin/hardware/simulator) | `HardwareSimulatorController@index` | `web`, `auth`, `check_role:admin` | `MOCK TESTED` |
| 66 | Lab Hardware Diagnostics | ADMIN | `GET /admin/hardware/diagnostics` | `admin.hardware.diagnostics.index` | [http://127.0.0.1:8008/admin/hardware/diagnostics](http://127.0.0.1:8008/admin/hardware/diagnostics) | `HardwareDiagnosticsController@index` | `web`, `auth`, `check_role:admin` | `PHYSICAL VERIFICATION PENDING` |
| 67 | Monitoring Intent Otomasi | ADMIN | `GET /admin/hardware/automation/intents` | `admin.hardware.automation.intents` | [http://127.0.0.1:8008/admin/hardware/automation/intents](http://127.0.0.1:8008/admin/hardware/automation/intents) | `AutomationMonitoringController@intents` | `web`, `auth`, `check_role:admin` | `IMPLEMENTED & VERIFIED` |
| 68 | Monitoring Log Audit IoT | ADMIN | `GET /admin/hardware/automation/logs` | `admin.hardware.automation.logs` | [http://127.0.0.1:8008/admin/hardware/automation/logs](http://127.0.0.1:8008/admin/hardware/automation/logs) | `AutomationMonitoringController@logs` | `web`, `auth`, `check_role:admin` | `IMPLEMENTED & VERIFIED` |

---

## 3. DAFTAR RUTE API SISTEM (API ROUTES)

| No | Endpoint URI | HTTP Method | Middleware | Controller & Method | Deskripsi Fungsi & Penggunaan | Status |
|---|---|---|---|---|---|---|
| 1 | `/api/user` | `GET` | `auth:sanctum` | `Closure` | Mengembalikan data pengguna terotentikasi via token Sanctum | `IMPLEMENTED & VERIFIED` |
| 2 | `/api/get-harga` | `GET` | `api` | `TransactionController@getHarga` | Mengambil tarif dasar PlayStation berdasarkan ID konsol | `IMPLEMENTED & VERIFIED` |
| 3 | `/api/get-hourly-prices` | `GET` | `api` | `TransactionController@getHourlyPrices` | Mengambil matriks harga bertingkat durasi jam sewa | `IMPLEMENTED & VERIFIED` |
| 4 | `/api/get-fnbs-by-price-group` | `GET` | `api` | `TransactionController@getFnbsByPriceGroup` | Mengambil produk FnB berdasarkan kelompok harga stasiun | `IMPLEMENTED & VERIFIED` |
| 5 | `/api/get-fnbs-by-price-groups`| `GET` | `api` | `TransactionController@getFnbsByPriceGroups` | Mengambil multi-kelompok harga untuk paket custom | `IMPLEMENTED & VERIFIED` |
| 6 | `/api/get-price-groups` | `GET` | `api` | `TransactionController@getPriceGroups` | Mengambil seluruh master data kelompok harga aktif | `IMPLEMENTED & VERIFIED` |

---

*Peta rute ini telah diaudit dan diverifikasi 100% konsisten terhadap routing table Laravel pada commit baseline G5-B.*
