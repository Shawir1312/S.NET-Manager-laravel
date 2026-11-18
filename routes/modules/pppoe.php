<?php

use App\Http\Controllers\CustomerController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PppoeProfileController;
use App\Http\Controllers\PppoeSettingController;
use App\Http\Controllers\WhatsAppAjaxController;
use App\Http\Controllers\WhatsAppController;
use Illuminate\Support\Facades\Route;

Route::post('/customers/sync-mikrotik', [CustomerController::class, 'syncFromMikrotik'])->middleware('throttle:6,1')->name('customers.sync');
Route::resource('customers', CustomerController::class);
Route::post('/customers/{customer}/wifi', [CustomerController::class, 'quickWifi'])->middleware('throttle:10,1')->name('customers.wifi');
Route::post('/customers/{customer}/portal', [CustomerController::class, 'quickPortal'])->name('customers.portal');
Route::post('/customers/{customer}/status', [CustomerController::class, 'status'])->name('customers.status');
Route::post('/customers/{customer}/push-ont', [CustomerController::class, 'pushOnt'])->middleware('throttle:10,1')->name('customers.push-ont');

Route::get('/payments', [PaymentController::class, 'index'])->name('payments.index');
Route::get('/payments/export', [PaymentController::class, 'export'])->name('payments.export');
Route::post('/payments', [PaymentController::class, 'store'])->name('payments.store');
Route::post('/payments/{payment}/send-wa', [PaymentController::class, 'sendReceipt'])->middleware('throttle:20,1')->name('payments.send-wa');
Route::delete('/payments/{payment}', [PaymentController::class, 'destroy'])->name('payments.destroy');

Route::get('/pppoe-profiles', [PppoeProfileController::class, 'index'])->name('pppoe-profiles.index');
Route::get('/pppoe-profiles/form', [PppoeProfileController::class, 'form'])->name('pppoe-profiles.form');
Route::post('/pppoe-profiles', [PppoeProfileController::class, 'store'])->name('pppoe-profiles.store');
Route::delete('/pppoe-profiles', [PppoeProfileController::class, 'destroy'])->name('pppoe-profiles.destroy');

Route::get('/pppoe-settings', [PppoeSettingController::class, 'edit'])->name('pppoe-settings.edit');
Route::put('/pppoe-settings', [PppoeSettingController::class, 'update'])->name('pppoe-settings.update');

Route::get('/whatsapp', [WhatsAppController::class, 'index'])->name('whatsapp.index');
Route::match(['get', 'post'], '/whatsapp/ajax', WhatsAppAjaxController::class)->middleware('throttle:120,1')->name('whatsapp.ajax');
Route::get('/whatsapp/service', [WhatsAppController::class, 'service'])->name('whatsapp.service');
Route::post('/whatsapp/service', [WhatsAppController::class, 'service']);
Route::put('/whatsapp/config', [WhatsAppController::class, 'updateConfig'])->name('whatsapp.config');
Route::post('/whatsapp/templates', [WhatsAppController::class, 'saveTemplate'])->name('whatsapp.templates.save');
Route::put('/whatsapp/templates/{template}', [WhatsAppController::class, 'updateTemplate'])->name('whatsapp.templates.update');
Route::post('/whatsapp/send', [WhatsAppController::class, 'send'])->middleware('throttle:30,1')->name('whatsapp.send');
Route::get('/whatsapp/thread/{phone}', [WhatsAppController::class, 'thread'])->where('phone', '[0-9]+')->name('whatsapp.thread');
