<?php

use App\Http\Controllers\Api\ConfigurationController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\GenieAcsController;
use App\Http\Controllers\Api\OntController;
use Illuminate\Support\Facades\Route;

/*
| Semua API memakai sesi login yang sama (Sanctum stateful) + CSRF,
| atau Bearer token Sanctum untuk integrasi.
*/
Route::middleware(['auth:sanctum', 'active', 'throttle:api', 'genie.server'])->group(function () {
    Route::get('/dashboard/stats', DashboardController::class)->name('api.dashboard.stats');

    Route::post('/genieacs/test-connection', [GenieAcsController::class, 'testConnection'])
        ->middleware(['role:admin', 'throttle:genieacs'])
        ->name('api.genieacs.test');

    Route::prefix('ont')->name('api.ont.')->group(function () {
        Route::post('/search', [OntController::class, 'search'])->middleware('throttle:genieacs')->name('search');
        Route::post('/sync', [OntController::class, 'sync'])->middleware('throttle:6,1')->name('sync');

        Route::get('/{device}', [OntController::class, 'show'])->whereNumber('device')->name('show');
        Route::get('/{device}/parameters', [OntController::class, 'parameters'])->whereNumber('device')->name('parameters');
        Route::get('/{device}/capabilities', [OntController::class, 'capabilities'])->whereNumber('device')->name('capabilities');
        Route::post('/{device}/check-online', [OntController::class, 'checkOnline'])->whereNumber('device')->middleware('throttle:genieacs')->name('check-online');
        Route::post('/{device}/refresh', [OntController::class, 'refresh'])->whereNumber('device')->middleware('throttle:6,1')->name('refresh');

        Route::get('/{device}/config-context', [ConfigurationController::class, 'context'])->whereNumber('device')->name('config-context');
        Route::post('/{device}/discover', [ConfigurationController::class, 'discover'])->whereNumber('device')->middleware('throttle:6,1')->name('discover');
        Route::post('/{device}/preview', [ConfigurationController::class, 'preview'])->whereNumber('device')->middleware('throttle:genieacs')->name('preview');
        Route::post('/configure', [ConfigurationController::class, 'configure'])->middleware('throttle:10,1')->name('configure');
    });

    Route::prefix('configuration')->name('api.configuration.')->group(function () {
        Route::get('/{job}/status', [ConfigurationController::class, 'status'])->name('status');
        Route::get('/{job}/history', [ConfigurationController::class, 'history'])->name('history');
        Route::post('/{job}/rollback', [ConfigurationController::class, 'rollback'])->middleware('throttle:6,1')->name('rollback');
    });

    Route::get('/templates', [ConfigurationController::class, 'templates'])->name('api.templates');
});
