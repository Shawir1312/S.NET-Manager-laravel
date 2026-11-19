<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\AutoConfigController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\DeviceModelController;
use App\Http\Controllers\GenieAcsSettingController;
use App\Http\Controllers\HistoryController;
use App\Http\Controllers\HotspotOntController;
use App\Http\Controllers\SystemController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:20,1')->name('login.attempt');
});

// Halaman publik S.NET (kwitansi, portal pelanggan, cek voucher, webhook).
foreach (glob(__DIR__.'/public/*.php') as $public) {
    require $public;
}

Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

    Route::get('/', fn () => redirect()->route('dashboard'));
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/auto-config', [AutoConfigController::class, 'index'])->name('auto-config');
    Route::get('/ont-hotspot', [HotspotOntController::class, 'index'])->name('ont-hotspot.index');
    Route::post('/ont-hotspot/apply', [HotspotOntController::class, 'apply'])->middleware('throttle:10,1')->name('ont-hotspot.apply');
    Route::get('/devices', [DeviceController::class, 'index'])->name('devices.index');
    Route::get('/devices/{device}', [DeviceController::class, 'show'])->whereNumber('device')->name('devices.show');

    Route::get('/history', [HistoryController::class, 'index'])->name('history.index');
    Route::get('/history/{job}', [HistoryController::class, 'show'])->name('history.show');

    Route::get('/templates', [TemplateController::class, 'index'])->name('templates.index');
    Route::middleware('role:admin')->group(function () {
        Route::get('/templates/create', [TemplateController::class, 'create'])->name('templates.create');
        Route::post('/templates', [TemplateController::class, 'store'])->name('templates.store');
        Route::get('/templates/{template}/edit', [TemplateController::class, 'edit'])->name('templates.edit');
        Route::put('/templates/{template}', [TemplateController::class, 'update'])->name('templates.update');
        Route::delete('/templates/{template}', [TemplateController::class, 'destroy'])->name('templates.destroy');
        Route::post('/templates/{template}/copy', [TemplateController::class, 'copy'])->name('templates.copy');
        Route::post('/templates/{template}/default', [TemplateController::class, 'makeDefault'])->name('templates.default');

        Route::get('/models', [DeviceModelController::class, 'index'])->name('models.index');
        Route::get('/models/create', [DeviceModelController::class, 'create'])->name('models.create');
        Route::post('/models', [DeviceModelController::class, 'store'])->name('models.store');
        Route::get('/models/{model}/edit', [DeviceModelController::class, 'edit'])->name('models.edit');
        Route::put('/models/{model}', [DeviceModelController::class, 'update'])->name('models.update');
        Route::post('/models/{model}/mappings', [DeviceModelController::class, 'saveMapping'])->name('models.mappings.save');
        Route::delete('/models/{model}/mappings/{mapping}', [DeviceModelController::class, 'deleteMapping'])->name('models.mappings.delete');

        Route::resource('users', UserController::class)->except('show');
        Route::get('/system', [SystemController::class, 'index'])->name('system.index');
    });

    // Modul S.NET (otorisasi per aksi lewat Gate di controller).
    foreach (glob(__DIR__.'/modules/*.php') as $module) {
        require $module;
    }

    Route::middleware('role:admin')->prefix('settings')->name('settings.')->group(function () {
        Route::get('/genieacs', [GenieAcsSettingController::class, 'edit'])->name('genieacs.edit');
        Route::put('/genieacs', [GenieAcsSettingController::class, 'update'])->name('genieacs.update');
        Route::delete('/genieacs/{server}', [GenieAcsSettingController::class, 'destroy'])->name('genieacs.destroy');
    });
});
