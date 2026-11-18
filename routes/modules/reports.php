<?php

use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::get('/reports/sales', [ReportController::class, 'sales'])->name('reports.sales');
Route::get('/reports/sales/export', [ReportController::class, 'salesExport'])->name('reports.sales.export');
Route::delete('/reports/sales/{sale}', [ReportController::class, 'salesDestroy'])->name('reports.sales.destroy');
Route::post('/reports/sales/rebuild', [ReportController::class, 'salesRebuild'])->name('reports.sales.rebuild');
Route::get('/reports/usage', [ReportController::class, 'usage'])->name('reports.usage');
Route::get('/reports/usage/export', [ReportController::class, 'usageExport'])->name('reports.usage.export');
Route::delete('/reports/usage', [ReportController::class, 'usageDestroy'])->name('reports.usage.destroy');
Route::get('/reports/penagihan', [ReportController::class, 'penagihan'])->name('reports.penagihan');
Route::post('/reports/penagihan', [ReportController::class, 'penagihanStore'])->name('reports.penagihan.store');
Route::get('/reports/penagihan/resellers', [ReportController::class, 'penagihanResellers'])->name('reports.penagihan.resellers');
Route::get('/reports/penagihan/export', [ReportController::class, 'penagihanExport'])->name('reports.penagihan.export');
Route::post('/reports/penagihan/baseline', [ReportController::class, 'penagihanBaseline'])->name('reports.penagihan.baseline');
Route::delete('/reports/penagihan/{penagihan}', [ReportController::class, 'penagihanDestroy'])->name('reports.penagihan.destroy');
