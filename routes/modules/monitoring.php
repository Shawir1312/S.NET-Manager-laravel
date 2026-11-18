<?php

use App\Http\Controllers\MacApiController;
use App\Http\Controllers\MonitoringController;
use App\Http\Controllers\OntMonitorController;
use Illuminate\Support\Facades\Route;

Route::get('/monitoring/active', [MonitoringController::class, 'active'])->name('monitoring.active');
Route::post('/monitoring/active', [MonitoringController::class, 'activeAction'])->name('monitoring.active.action');
Route::post('/monitoring/disconnect', [MonitoringController::class, 'disconnect'])->name('monitoring.disconnect');
Route::get('/monitoring/mac', [MonitoringController::class, 'mac'])->name('monitoring.mac');
Route::match(['get', 'post'], '/monitoring/mac/api', MacApiController::class)->name('monitoring.mac.api');

Route::get('/ont-monitor', [OntMonitorController::class, 'index'])->name('ont-monitor.index');
Route::post('/ont-monitor/sync', [OntMonitorController::class, 'sync'])->middleware('throttle:6,1')->name('ont-monitor.sync');
Route::post('/ont-monitor/{device}/remote', [OntMonitorController::class, 'remote'])->name('ont-monitor.remote');
Route::delete('/ont-monitor/remote-all', [OntMonitorController::class, 'closeAllRemotes'])->name('ont-monitor.remote.close-all');
Route::delete('/ont-monitor/remote/{remote}', [OntMonitorController::class, 'closeRemote'])->name('ont-monitor.remote.close');
Route::post('/ont-monitor/{device}/reboot', [OntMonitorController::class, 'reboot'])->middleware('throttle:10,1')->name('ont-monitor.reboot');
