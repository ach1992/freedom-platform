<?php

declare(strict_types=1);

use App\Modules\Operations\Presentation\Http\HealthController;
use App\Modules\Payments\Zarinpal\Presentation\Http\ZarinpalCallbackController;
use Illuminate\Support\Facades\Route;

Route::get('/health/live', [HealthController::class, 'live'])->name('health.live');
Route::get('/health/ready', [HealthController::class, 'ready'])->name('health.ready');
Route::get('/payments/zarinpal/callback', ZarinpalCallbackController::class)->name('payments.zarinpal.callback');

require __DIR__.'/installer.php';
