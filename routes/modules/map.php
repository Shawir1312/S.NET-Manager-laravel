<?php

use App\Http\Controllers\NetworkMapController;
use Illuminate\Support\Facades\Route;

// Peta jaringan: server (router/cabang) → ODP → pelanggan.
Route::get('/peta-jaringan', [NetworkMapController::class, 'index'])->name('map.index');
Route::get('/peta-jaringan/{router}/data', [NetworkMapController::class, 'data'])->name('map.data');
Route::post('/peta-jaringan/odp', [NetworkMapController::class, 'storeOdp'])->name('map.odp.store');
Route::put('/peta-jaringan/odp/{odp}', [NetworkMapController::class, 'updateOdp'])->name('map.odp.update');
Route::delete('/peta-jaringan/odp/{odp}', [NetworkMapController::class, 'destroyOdp'])->name('map.odp.destroy');
Route::put('/peta-jaringan/pelanggan/{customer}', [NetworkMapController::class, 'updateCustomer'])->name('map.customer.update');
Route::put('/peta-jaringan/server/{router}', [NetworkMapController::class, 'updateServer'])->name('map.server.update');
Route::post('/peta-jaringan/link-publik', [NetworkMapController::class, 'regenerateToken'])->name('map.token');
