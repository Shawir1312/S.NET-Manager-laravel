<?php

use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\Portal\PortalAuthController;
use App\Http\Controllers\Portal\PortalController;
use App\Http\Controllers\PublicController;
use App\Http\Middleware\PortalAuthenticate;
use Illuminate\Support\Facades\Route;

Route::prefix('portal')->name('portal.')->group(function () {
    Route::get('/login', [PortalAuthController::class, 'show'])->name('login');
    Route::post('/login', [PortalAuthController::class, 'login'])->middleware('throttle:20,1')->name('login.attempt');
    Route::post('/logout', [PortalAuthController::class, 'logout'])->name('logout');

    Route::middleware(PortalAuthenticate::class)->group(function () {
        Route::get('/', [PortalController::class, 'home'])->name('home');
        Route::post('/wifi', [PortalController::class, 'wifi'])->middleware('throttle:5,1')->name('wifi');
        Route::post('/reboot', [PortalController::class, 'reboot'])->middleware('throttle:3,10')->name('reboot');
        Route::post('/password', [PortalController::class, 'password'])->middleware('throttle:5,1')->name('password');
        Route::post('/pay', [PortalController::class, 'pay'])->middleware('throttle:10,1')->name('pay');
        Route::get('/pay/status', [PortalController::class, 'paymentStatus'])->middleware('throttle:60,1')->name('pay.status');
        Route::get('/traffic', [PortalController::class, 'traffic'])->middleware('throttle:60,1')->name('traffic');
    });
});

Route::get('/isolir', [PublicController::class, 'isolir'])->name('isolir');
Route::post('/isolir/pay', [PublicController::class, 'isolirPay'])->middleware('throttle:10,1')->name('isolir.pay');
Route::get('/voucher', [PublicController::class, 'voucher'])->middleware('throttle:30,1')->name('voucher.check');
Route::post('/voucher/extend', [PublicController::class, 'extend'])->middleware('throttle:10,1')->name('voucher.extend');
Route::post('/webhooks/payment', PaymentWebhookController::class)->middleware('throttle:120,1')->name('webhooks.payment');
