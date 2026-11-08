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

Route::get('/get-harga', 'App\Http\Controllers\TransactionController@getHarga');
Route::get('/get-hourly-prices', 'App\Http\Controllers\TransactionController@getHourlyPrices');
Route::get('/get-fnbs-by-price-group', 'App\Http\Controllers\TransactionController@getFnbsByPriceGroup');
Route::get('/get-fnbs-by-price-groups', 'App\Http\Controllers\TransactionController@getFnbsByPriceGroups');
Route::get('/get-price-groups', 'App\Http\Controllers\TransactionController@getPriceGroups');

// SR-TV-INTEGRATION-001: Consumer TV Agent Polling & ACK API (Samsung Tizen Web App & TV Lab Simulator)
Route::get('/tv-agent/events', [App\Http\Controllers\TvAgentController::class, 'pollEvents'])->name('api.tv-agent.events.index');
Route::post('/tv-agent/events/{event}/ack', [App\Http\Controllers\TvAgentController::class, 'acknowledge'])->name('api.tv-agent.events.ack')->whereNumber('event');
