<?php

use App\Http\Controllers\RouterController;
use Illuminate\Support\Facades\Route;

Route::post('/routers/sync-radius', [RouterController::class, 'syncRadius'])->name('routers.sync-radius');
Route::resource('routers', RouterController::class)->except('show');
Route::post('/routers/{router}/test', [RouterController::class, 'test'])->name('routers.test');
