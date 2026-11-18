<?php

use App\Http\Controllers\WaInboxWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/wa-inbox', WaInboxWebhookController::class)->middleware('throttle:300,1')->name('webhooks.wa-inbox');
