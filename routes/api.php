<?php

declare(strict_types=1);

use App\Modules\Payments\NowPayments\Presentation\Http\NowPaymentsIpnController;
use App\Modules\Telegram\Presentation\Http\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/telegram/webhook', TelegramWebhookController::class)
    ->middleware('telegram.webhook')
    ->name('telegram.webhook');

Route::post('/payments/nowpayments/ipn', NowPaymentsIpnController::class)
    ->name('payments.nowpayments.ipn');
