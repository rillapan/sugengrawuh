<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::get('/health', [App\Http\Controllers\Api\HealthCheckController::class, 'index'])->name('api.health');

Route::get('/get-harga', 'App\Http\Controllers\TransactionController@getHarga');
Route::get('/get-hourly-prices', 'App\Http\Controllers\TransactionController@getHourlyPrices');
Route::match(['get', 'post'], '/calculate-duration-price', 'App\Http\Controllers\TransactionController@calculateDurationPrice');
Route::get('/get-fnbs-by-price-group', 'App\Http\Controllers\TransactionController@getFnbsByPriceGroup');
Route::get('/get-fnbs-by-price-groups', 'App\Http\Controllers\TransactionController@getFnbsByPriceGroups');
Route::get('/get-price-groups', 'App\Http\Controllers\TransactionController@getPriceGroups');

// SR-TV-INTEGRATION-001: Consumer TV Agent Polling & ACK API (Samsung Tizen Web App & TV Lab Simulator)
Route::get('/tv-agent/events', [App\Http\Controllers\TvAgentController::class, 'pollEvents'])->name('api.tv-agent.events.index');
Route::post('/tv-agent/events/{event}/ack', [App\Http\Controllers\TvAgentController::class, 'acknowledge'])->name('api.tv-agent.events.ack')->whereNumber('event');
Route::post('/tv-agent/events/volume-enforcement', [App\Http\Controllers\TvAgentController::class, 'recordVolumeEnforcement'])->name('api.tv-agent.events.volume-enforcement');

// SR-CONSOLE-ALERT-001: PlayStation Console Agent Polling, ACK & Heartbeat API (PS4 GoldHEN Outbound Polling)
Route::prefix('console-agent/v1')->group(function () {
    Route::get('/events', [App\Http\Controllers\Api\ConsoleAgentController::class, 'pollEvents'])->name('api.console-agent.events');
    Route::post('/events/{event}/ack', [App\Http\Controllers\Api\ConsoleAgentController::class, 'acknowledge'])->name('api.console-agent.ack')->whereNumber('event');
    Route::post('/heartbeat', [App\Http\Controllers\Api\ConsoleAgentController::class, 'heartbeat'])->name('api.console-agent.heartbeat');
});
