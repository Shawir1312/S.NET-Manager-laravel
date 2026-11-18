<?php

use App\Http\Controllers\SettingController;
use Illuminate\Support\Facades\Route;

Route::prefix('settings')->name('settings.')->group(function () {
    Route::get('/general', [SettingController::class, 'general'])->name('general');
    Route::post('/radius/restart', [SettingController::class, 'restartRadius'])->middleware('throttle:5,1')->name('radius.restart');
    Route::post('/announcements', [SettingController::class, 'storeAnnouncement'])->name('announcements.store');
    Route::post('/announcements/{announcement}/toggle', [SettingController::class, 'toggleAnnouncement'])->name('announcements.toggle');
    Route::delete('/announcements/{announcement}', [SettingController::class, 'destroyAnnouncement'])->name('announcements.destroy');
    Route::get('/audit', [SettingController::class, 'audit'])->name('audit');
    Route::get('/backup', [SettingController::class, 'backup'])->name('backup');
    Route::post('/backup', [SettingController::class, 'createBackup'])->middleware('throttle:5,1')->name('backup.create');
    Route::get('/backup/{file}', [SettingController::class, 'downloadBackup'])->where('file', '[A-Za-z0-9.\-]+')->name('backup.download');
});
