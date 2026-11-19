<?php

use App\Http\Controllers\PublicMapController;
use Illuminate\Support\Facades\Route;

// Peta jaringan tanpa login (link rahasia dari menu Peta Jaringan).
Route::get('/peta/{token}', [PublicMapController::class, 'show'])->middleware('throttle:30,1')->name('public-map.show');
Route::get('/peta/{token}/{router}/data', [PublicMapController::class, 'data'])->whereNumber('router')->middleware('throttle:60,1')->name('public-map.data');
