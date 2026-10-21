<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\MonitoringIssueController;
use App\Http\Controllers\PlatformController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::view('/login', 'login')->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
});
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/', [PlatformController::class, 'dashboard'])->name('dashboard');
    Route::get('/channels', [PlatformController::class, 'channels'])->name('channels');
    Route::post('/channels', [PlatformController::class, 'addChannel'])->name('channels.store');
    Route::get('/channels/{channel}', [PlatformController::class, 'channel'])->name('channels.show');
    Route::post('/channels/{channel}/toggle', [PlatformController::class, 'toggleChannel'])->name('channels.toggle');
    Route::post('/channels/{channel}/research', [PlatformController::class, 'researchChannel'])->name('channels.research');
    Route::post('/channels/{channel}/learn', [PlatformController::class, 'learnChannel'])->name('channels.learn');
    Route::post('/channels/{channel}/risk', [PlatformController::class, 'channelRisk'])->name('channels.risk');
    Route::get('/signals', [PlatformController::class, 'signals'])->name('signals');
    Route::get('/signals/{signal}', [PlatformController::class, 'signal'])->name('signals.show');
    Route::post('/signals/{signal}/evaluate', [PlatformController::class, 'reevaluate'])->name('signals.evaluate');
    Route::get('/trades', [PlatformController::class, 'trades'])->name('trades');
    Route::get('/agents', [PlatformController::class, 'agents'])->name('agents');
    Route::get('/risk', [PlatformController::class, 'risk'])->name('risk');
    Route::post('/risk', [PlatformController::class, 'saveRisk'])->name('risk.save');
    Route::post('/risk/emergency', [PlatformController::class, 'emergency'])->name('risk.emergency');
    Route::get('/lab', [PlatformController::class, 'lab'])->name('lab');
    Route::post('/lab/message', [PlatformController::class, 'manualMessage'])->name('lab.message');
    Route::post('/lab/quote', [PlatformController::class, 'manualQuote'])->name('lab.quote');
    Route::get('/backtests', [PlatformController::class, 'backtests'])->name('backtests');
    Route::post('/backtests', [PlatformController::class, 'runBacktest'])->name('backtests.run');
    Route::post('/backtests/import', [PlatformController::class, 'importTicks'])->name('backtests.import');
    Route::get('/audit', [PlatformController::class, 'audit'])->name('audit');
    Route::get('/issues', [MonitoringIssueController::class, 'index'])->name('issues');
    Route::post('/issues', [MonitoringIssueController::class, 'store'])->name('issues.store');
    Route::get('/issues/{issue}', [MonitoringIssueController::class, 'show'])->name('issues.show');
    Route::post('/issues/{issue}/comments', [MonitoringIssueController::class, 'comment'])->name('issues.comment');
    Route::post('/issues/{issue}/status', [MonitoringIssueController::class, 'status'])->name('issues.status');
    Route::get('/integrations', [PlatformController::class, 'integrations'])->name('integrations');
});
