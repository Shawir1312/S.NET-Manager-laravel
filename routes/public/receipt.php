<?php

use App\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

Route::get('/receipt/{payment}', [PaymentController::class, 'receipt'])->middleware('throttle:60,1')->name('receipt.show');
