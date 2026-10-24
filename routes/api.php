<?php

use App\Http\Controllers\PaymentController;
use App\Http\Controllers\SettlementController;
use App\Http\Controllers\SnippeWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/snippe/webhook', SnippeWebhookController::class)->middleware('throttle:120,1')->name('api.snippe.webhook');
Route::post('/v1/payment-callback/{token}', [PaymentController::class, 'callback'])->middleware('throttle:120,1')->name('api.payments.callback');
Route::post('/p/{token}', [PaymentController::class, 'callback'])->middleware('throttle:120,1')->name('api.payments.callback.short');
Route::post('/o/{token}', [SettlementController::class, 'callback'])->middleware('throttle:120,1')->name('api.settlements.callback');
