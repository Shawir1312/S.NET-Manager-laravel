<?php

use App\Http\Controllers\CustomerController;
use App\Http\Controllers\HotspotOntController;
use App\Models\ConfigurationJob;
use Illuminate\Support\Facades\Route;

// Aplikasi Lapangan: tampilan HP untuk teknisi, satu database & login dengan S.NET Manager.
// Halaman memakai controller yang sama; layout dipilih dari nama route (field.*).
Route::prefix('lapangan')->name('field.')->group(function () {
    Route::get('/', fn () => view('field.home', [
        'jobs' => ConfigurationJob::with('device:id,serial_number')->where('user_id', auth()->id())->latest('id')->limit(10)->get(),
    ]))->name('home');
    Route::get('/hotspot', [HotspotOntController::class, 'index'])->name('hotspot');
    Route::get('/pelanggan/baru', [CustomerController::class, 'create'])->name('customers.create');
});
