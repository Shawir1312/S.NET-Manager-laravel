<?php

use App\Http\Controllers\WireGuardController;
use Illuminate\Support\Facades\Route;

Route::resource('wireguard', WireGuardController::class);
Route::get('/wireguard/{wireguard}/conf', [WireGuardController::class, 'conf'])->name('wireguard.conf');
Route::match(['get', 'post'], '/wireguard-tools', [WireGuardController::class, 'tools'])->middleware('throttle:30,1')->name('wireguard.tools');
Route::post('/wireguard/{wireguard}/test', [WireGuardController::class, 'test'])->middleware('throttle:20,1')->name('wireguard.test');
Route::post('/wireguard-forwards', [WireGuardController::class, 'storeForward'])->name('wireguard.forwards.store');
Route::delete('/wireguard-forwards/{forward}', [WireGuardController::class, 'destroyForward'])->name('wireguard.forwards.destroy');
Route::put('/wireguard-settings', [WireGuardController::class, 'updateSettings'])->name('wireguard.settings');
