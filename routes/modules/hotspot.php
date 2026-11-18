<?php

use App\Http\Controllers\HotspotProfileController;
use App\Http\Controllers\VoucherController;
use Illuminate\Support\Facades\Route;

Route::post('/hotspot-profiles/sync-all', [HotspotProfileController::class, 'syncAll'])->name('hotspot-profiles.sync-all');
Route::resource('hotspot-profiles', HotspotProfileController::class)->except('show');
Route::get('/vouchers', [VoucherController::class, 'index'])->name('vouchers.index');
Route::get('/vouchers/create', [VoucherController::class, 'create'])->name('vouchers.create');
Route::post('/vouchers', [VoucherController::class, 'store'])->middleware('throttle:20,1')->name('vouchers.store');
Route::get('/vouchers/print', [VoucherController::class, 'print'])->name('vouchers.print');
Route::delete('/vouchers', [VoucherController::class, 'destroy'])->name('vouchers.destroy');
Route::post('/vouchers/bulk-delete', [VoucherController::class, 'bulkDestroy'])->name('vouchers.bulk-destroy');
Route::post('/vouchers/reactivate', [VoucherController::class, 'reactivate'])->name('vouchers.reactivate');
