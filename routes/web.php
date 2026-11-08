<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return redirect('/home');
});

Auth::routes();

// Universal Authenticated Routes
Route::middleware(['auth'])->group(function () {
    Route::get('/home', [App\Http\Controllers\HomeController::class, 'index'])->name('home');
    
    Route::get('/chart-pie-data', [App\Http\Controllers\HomeController::class, 'pieCartData2']);
    Route::get('/chart-area-data', 'App\Http\Controllers\HomeController@areaCartData');
    Route::get('/hourly-revenue-data', 'App\Http\Controllers\HomeController@hourlyRevenueData');
    
    Route::get('/profile', 'App\Http\Controllers\HomeController@profile')->name('profile');
    Route::post('/device/{id}/update-status', [App\Http\Controllers\DeviceController::class, 'updateStatus'])->name('device.updateStatus');
    Route::post('/device/update-all-statuses', [App\Http\Controllers\DeviceController::class, 'updateDeviceStatusesFromTimers'])->name('device.updateAllStatuses');
    Route::get('/device/{id}/test-status', [App\Http\Controllers\DeviceController::class, 'testStatus'])->name('device.testStatus');
    Route::put('/profile/{id}', 'App\Http\Controllers\HomeController@update');
    
    Route::get('/server-time', function() {
        return response()->json([
            'server_time' => now()->toISOString(),
            'server_timestamp' => now()->timestamp * 1000
        ]);
    });
    Route::get('/device/timers', [App\Http\Controllers\DeviceController::class, 'getTimerSyncData'])->name('device.timers');
});

// Read-Only Monitoring Routes (Admin + Kasir + Owner)
Route::middleware(['auth', 'check_role:admin,kasir,owner'])->group(function () {
    // Transaction Read-Only
    Route::get('/transaction', [App\Http\Controllers\TransactionController::class, 'index'])->name('transaction.index');
    Route::get('/transaction/{transaction}', [App\Http\Controllers\TransactionController::class, 'show'])->name('transaction.show')->whereNumber('transaction');
    Route::get('/transaction/{id}/print', [App\Http\Controllers\TransactionController::class, 'printReceipt'])->name('transaction.print');

    // Device Read-Only Monitoring
    Route::get('/device', [App\Http\Controllers\DeviceController::class, 'index'])->name('device.index');
    Route::get('/device/by-playstation/{id}', [App\Http\Controllers\DeviceController::class, 'byPlaystation'])->name('device.byPlaystation');
    Route::get('/device/{device}', [App\Http\Controllers\DeviceController::class, 'show'])->name('device.show')->whereNumber('device');

    // Expense Read-Only Monitoring
    Route::get('/expense', [App\Http\Controllers\ExpenseController::class, 'index'])->name('expense.index');
    Route::get('/expense/{expense}', [App\Http\Controllers\ExpenseController::class, 'show'])->name('expense.show')->whereNumber('expense');

    // Station Hardware Mapping Operational View (Admin + Kasir + Owner)
    Route::get('/admin/stations/{stationId}/mappings', [App\Http\Controllers\Admin\StationHardwareMappingController::class, 'index'])->name('admin.stations.mappings.index');
});

// Operational Mutation Routes (Admin + Kasir)
Route::middleware(['auth', 'check_role:admin,kasir'])->group(function () {
    // Debug endpoint for device testing
    Route::get('/debug/devices', [App\Http\Controllers\DebugController::class, 'deviceDebug']);
    
    // Transaction Mutations
    Route::get('/transaction/create', [App\Http\Controllers\TransactionController::class, 'create'])->name('transaction.create');
    Route::post('/transaction', [App\Http\Controllers\TransactionController::class, 'store'])->name('transaction.store');
    Route::get('/transaction/{transaction}/edit', [App\Http\Controllers\TransactionController::class, 'edit'])->name('transaction.edit');
    Route::put('/transaction/{transaction}', [App\Http\Controllers\TransactionController::class, 'update'])->name('transaction.update');
    Route::get('/transaction/{id}/payment', [App\Http\Controllers\TransactionController::class, 'showPayment'])->name('transaction.showPayment');
    Route::post('/transaction/{id}/process-payment', [App\Http\Controllers\TransactionController::class, 'processPayment'])->name('transaction.processPayment');
    Route::put('/transaction/{id}/update', [App\Http\Controllers\TransactionController::class, 'updateStatus']);
    Route::post('/transaction/{id}/end', [App\Http\Controllers\TransactionController::class, 'endTransaction'])->name('transaction.end');
    Route::get('/transaction/{id}/add-order', [App\Http\Controllers\TransactionController::class, 'addOrder'])->name('transaction.add-order');
    Route::post('/transaction/{id}/store-order', [App\Http\Controllers\TransactionController::class, 'storeOrder'])->name('transaction.store-order');
    
    // FnB Management in Transaction Edit
    Route::post('/transaction/{id}/fnb/add', [App\Http\Controllers\TransactionController::class, 'addFnbToTransaction'])->name('transaction.fnb.add');
    Route::put('/transaction/{id}/fnb/{fnbId}/update', [App\Http\Controllers\TransactionController::class, 'updateTransactionFnb'])->name('transaction.fnb.update');
    Route::delete('/transaction/{id}/fnb/{fnbId}/delete', [App\Http\Controllers\TransactionController::class, 'deleteTransactionFnb'])->name('transaction.fnb.delete');

    // Device Operations
    Route::get('/booking/{id}/add', [App\Http\Controllers\DeviceController::class, 'bookingAdd']);
    Route::get('/booking/{id}', [App\Http\Controllers\DeviceController::class, 'booking']);
    Route::post('/device/{id}/stop', [App\Http\Controllers\DeviceController::class, 'stopDevice'])->name('device.stop');
    Route::get('/device/{id}/debug', [App\Http\Controllers\DeviceController::class, 'debugDevice'])->name('device.debug');
    Route::get('/device/{id}/test-stop', [App\Http\Controllers\DeviceController::class, 'testStopDevice'])->name('device.testStop');

    // Expense (Kasir creates)
    Route::get('/expense/create', [App\Http\Controllers\ExpenseController::class, 'create'])->name('expense.create');
    Route::post('/expense', [App\Http\Controllers\ExpenseController::class, 'store'])->name('expense.store');

    // G5-B: Cashier Station Automation Live Status & Manual Action
    Route::get('/api/stations/{id}/automation-status', [App\Http\Controllers\StationAutomationController::class, 'getStatus'])->name('api.stations.automation-status');
    Route::post('/api/stations/{id}/automation/manual-action', [App\Http\Controllers\StationAutomationController::class, 'manualAction'])->name('api.stations.automation-manual-action');
});

// Admin + Owner Routes
Route::middleware(['auth', 'check_role:admin,owner'])->group(function () {
    // Reports
    Route::get('/report', 'App\Http\Controllers\ReportController@index')->name('report');
    Route::get('/report/transaction', 'App\Http\Controllers\ReportController@transactionReport')->name('transaction.report');
    Route::get('/generate-pdf', 'App\Http\Controllers\ReportController@generatePDF');
    Route::get('/generate-excel', 'App\Http\Controllers\ReportController@generateExcel')->name('laporan.excel');

    // FnB Reports
    Route::get('/fnb/laporan-penjualan', [App\Http\Controllers\FnbController::class, 'laporanPenjualan'])->name('fnb.laporan');
    Route::get('/fnb/laporan-penjualan/excel', [App\Http\Controllers\FnbController::class, 'exportExcel'])->name('fnb.laporan.excel');
    Route::get('/fnb/laporan-penjualan/pdf', [App\Http\Controllers\FnbController::class, 'exportPdf'])->name('fnb.laporan.pdf');

    // Physical Device Registry Read-Only (Admin + Owner)
    Route::get('/admin/hardware/devices', [App\Http\Controllers\Admin\PhysicalDeviceController::class, 'index'])->name('admin.hardware.devices.index');
    Route::get('/admin/hardware/devices/{id}', [App\Http\Controllers\Admin\PhysicalDeviceController::class, 'show'])->name('admin.hardware.devices.show');

    // Hardware IoT Blade Views (Admin + Owner)
    Route::get('/admin/hardware/registry', [App\Http\Controllers\Admin\PhysicalDeviceController::class, 'registryView'])->name('admin.hardware.registry');
    Route::get('/admin/hardware/mappings', [App\Http\Controllers\Admin\StationHardwareMappingController::class, 'mappingsView'])->name('admin.hardware.mappings');
});

// Admin Only Routes
Route::middleware(['auth', 'check_role:admin'])->group(function () {
    // Transaction Master Deletion
    Route::delete('/transaction/{transaction}', [App\Http\Controllers\TransactionController::class, 'destroy'])->name('transaction.destroy');

    // Device Master CRUD
    Route::get('/device/create', [App\Http\Controllers\DeviceController::class, 'create'])->name('device.create');
    Route::post('/device', [App\Http\Controllers\DeviceController::class, 'store'])->name('device.store');
    Route::get('/device/{device}/edit', [App\Http\Controllers\DeviceController::class, 'edit'])->name('device.edit');
    Route::put('/device/{device}', [App\Http\Controllers\DeviceController::class, 'update'])->name('device.update');
    Route::delete('/device/{device}', [App\Http\Controllers\DeviceController::class, 'destroy'])->name('device.destroy');

    // Expense Master Management
    Route::get('/expense/{expense}/edit', [App\Http\Controllers\ExpenseController::class, 'edit'])->name('expense.edit');
    Route::put('/expense/{expense}', [App\Http\Controllers\ExpenseController::class, 'update'])->name('expense.update');
    Route::delete('/expense/{expense}', [App\Http\Controllers\ExpenseController::class, 'destroy'])->name('expense.destroy');

    // User Management
    Route::resource('/users', App\Http\Controllers\UserManagementController::class);
    Route::post('/users/{id}/reset-password', [App\Http\Controllers\UserManagementController::class, 'resetPassword'])->name('users.reset-password');

    // Master Data - Kategori Permainan
    Route::resource('/kategori-permainan', App\Http\Controllers\KategoriPermainanController::class, [
        'parameters' => [
            'kategori-permainan' => 'kategori_permainan'
        ]
    ]);

    // Backward compatibility: GET /playstation redirects to /kategori-permainan
    Route::get('/playstation', function () {
        return redirect()->route('kategori-permainan.index');
    });

    // Backward compatibility for internal route('playstation.*') calls
    Route::name('playstation.')->prefix('playstation-compat')->group(function () {
        Route::get('/', function () { return redirect()->route('kategori-permainan.index'); })->name('index');
        Route::get('/create', function () { return redirect()->route('kategori-permainan.create'); })->name('create');
        Route::post('/store', [App\Http\Controllers\KategoriPermainanController::class, 'store'])->name('store');
        Route::get('/{kategori_permainan}/edit', [App\Http\Controllers\KategoriPermainanController::class, 'edit'])->name('edit');
        Route::put('/{kategori_permainan}', [App\Http\Controllers\KategoriPermainanController::class, 'update'])->name('update');
        Route::delete('/{kategori_permainan}', [App\Http\Controllers\KategoriPermainanController::class, 'destroy'])->name('destroy');
    });

    Route::resource('/fnb', App\Http\Controllers\FnbController::class);
    Route::resource('/price-group', App\Http\Controllers\PriceGroupController::class);
    Route::get('/price-group/{id}/fnbs', [App\Http\Controllers\PriceGroupController::class, 'showFnbs'])->name('price-group.fnbs');

    // Hourly Prices for PlayStation / Kategori Permainan
    Route::prefix('/playstation/{playstationId}')->name('hourly-prices.')->group(function () {
        Route::resource('/hourly-prices', 'App\Http\Controllers\HourlyPriceController');
    });
    Route::prefix('/kategori-permainan/{playstationId}')->group(function () {
        Route::resource('/hourly-prices', 'App\Http\Controllers\HourlyPriceController');
    });
    
    // Stock
    Route::get('/stock', [App\Http\Controllers\StockController::class, 'index'])->name('stock.index');
    Route::get('/stock/{id}/add', [App\Http\Controllers\StockController::class, 'addForm'])->name('stock.add');
    Route::post('/stock/{id}/add', [App\Http\Controllers\StockController::class, 'addStock']);
    Route::get('/stock/{id}/reduce', [App\Http\Controllers\StockController::class, 'reduceForm'])->name('stock.reduce');
    Route::post('/stock/{id}/reduce', [App\Http\Controllers\StockController::class, 'reduceStock']);
    Route::get('/stock/{id}/history', [App\Http\Controllers\StockController::class, 'history'])->name('stock.history');

    // Settings / Configs
    Route::resource('/expense-category', App\Http\Controllers\ExpenseCategoryController::class);
    Route::resource('/custom-package', App\Http\Controllers\CustomPackageController::class);
    Route::put('/custom-package/{id}/toggle-status', [App\Http\Controllers\CustomPackageController::class, 'toggleStatus'])->name('custom-package.toggle-status');
    
    // App Settings (Admin only)
    Route::get('/settings', [App\Http\Controllers\AppSettingsController::class, 'index'])->name('settings.index');
    Route::post('/settings/reset-transactions', [App\Http\Controllers\AppSettingsController::class, 'resetTransactions'])->name('settings.reset-transactions');
    
    // Work Shift Management
    Route::resource('/work_shifts', App\Http\Controllers\WorkShiftController::class);

    // Physical Device Registry Mutations (Admin Only)
    Route::post('/admin/hardware/devices', [App\Http\Controllers\Admin\PhysicalDeviceController::class, 'store'])->name('admin.hardware.devices.store');
    Route::put('/admin/hardware/devices/{id}', [App\Http\Controllers\Admin\PhysicalDeviceController::class, 'update'])->name('admin.hardware.devices.update');
    Route::delete('/admin/hardware/devices/{id}', [App\Http\Controllers\Admin\PhysicalDeviceController::class, 'destroy'])->name('admin.hardware.devices.destroy');

    // Station Hardware Mapping Mutations (Admin Only)
    Route::post('/admin/stations/{stationId}/mappings', [App\Http\Controllers\Admin\StationHardwareMappingController::class, 'store'])->name('admin.stations.mappings.store');
    Route::post('/admin/stations/{stationId}/mappings/{mappingId}/detach', [App\Http\Controllers\Admin\StationHardwareMappingController::class, 'detach'])->name('admin.stations.mappings.detach');

    // G4-A: Hardware Simulator Routes (Admin Only)
    Route::prefix('/admin/hardware/simulator')->name('admin.hardware.simulator.')->group(function () {
        Route::get('/', [App\Http\Controllers\Admin\HardwareSimulatorController::class, 'index'])->name('index');
        Route::get('/devices', [App\Http\Controllers\Admin\HardwareSimulatorController::class, 'devices'])->name('devices');
        Route::post('/scenario', [App\Http\Controllers\Admin\HardwareSimulatorController::class, 'setScenario'])->name('scenario');
        Route::post('/command', [App\Http\Controllers\Admin\HardwareSimulatorController::class, 'sendCommand'])->name('command');
        Route::get('/status/{id}', [App\Http\Controllers\Admin\HardwareSimulatorController::class, 'getStatus'])->name('status');
        Route::get('/events/{id}', [App\Http\Controllers\Admin\HardwareSimulatorController::class, 'getEvents'])->name('events');
        Route::post('/reset', [App\Http\Controllers\Admin\HardwareSimulatorController::class, 'reset'])->name('reset');
    });

    // G4-B: Hardware Diagnostics Routes (Admin Only)
    Route::prefix('/admin/hardware/diagnostics')->name('admin.hardware.diagnostics.')->group(function () {
        Route::get('/', [App\Http\Controllers\Admin\HardwareDiagnosticsController::class, 'index'])->name('index');
        Route::get('/devices', [App\Http\Controllers\Admin\HardwareDiagnosticsController::class, 'devices'])->name('devices');
        Route::post('/probe', [App\Http\Controllers\Admin\HardwareDiagnosticsController::class, 'probe'])->name('probe');
        Route::post('/pair', [App\Http\Controllers\Admin\HardwareDiagnosticsController::class, 'pair'])->name('pair');
        Route::post('/command', [App\Http\Controllers\Admin\HardwareDiagnosticsController::class, 'sendCommand'])->name('command');
        Route::get('/status/{id}', [App\Http\Controllers\Admin\HardwareDiagnosticsController::class, 'getStatus'])->name('status');
        Route::get('/events/{id}', [App\Http\Controllers\Admin\HardwareDiagnosticsController::class, 'getEvents'])->name('events');
    });

    // G5-B: Admin Automation Monitoring & Audit Telemetry Logs
    Route::prefix('/admin/hardware/automation')->name('admin.hardware.automation.')->group(function () {
        Route::get('/intents', [App\Http\Controllers\Admin\AutomationMonitoringController::class, 'intents'])->name('intents');
        Route::get('/logs', [App\Http\Controllers\Admin\AutomationMonitoringController::class, 'logs'])->name('logs');
    });

    // SR-TV-INTEGRATION-001: Admin Samsung TV Alert & Volume Control + TV Simulator Lab
    Route::post('/admin/stations/{station}/tv-alerts', [App\Http\Controllers\Admin\TvControlController::class, 'sendAlert'])->name('admin.stations.tv-alerts.store')->whereNumber('station');
    Route::post('/admin/stations/{station}/tv-volume', [App\Http\Controllers\Admin\TvControlController::class, 'sendVolume'])->name('admin.stations.tv-volume.store')->whereNumber('station');
    Route::get('/admin/stations/{station}/tv-control-status', [App\Http\Controllers\Admin\TvControlController::class, 'status'])->name('admin.stations.tv-control.status')->whereNumber('station');
    Route::get('/admin/tv-lab', [App\Http\Controllers\Admin\TvControlController::class, 'labIndex'])->name('admin.tv-lab.index');
});

