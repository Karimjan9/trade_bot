<?php

use App\Http\Controllers\GatewayController;
use App\Http\Controllers\MarketController;
use App\Http\Middleware\ServiceToken;
use Illuminate\Support\Facades\Route;

Route::prefix('gateway')->middleware(ServiceToken::class.':gateway')->group(function () {
    Route::get('channels', [GatewayController::class, 'channels']);
    Route::patch('channels/{channel}', [GatewayController::class, 'updateChannel']);
    Route::post('events', [GatewayController::class, 'event']);
    Route::post('heartbeat', [GatewayController::class, 'heartbeat']);
});
Route::post('market/snapshots', [MarketController::class, 'store'])->middleware(ServiceToken::class.':market');
