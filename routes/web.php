<?php

declare(strict_types=1);

use App\Modules\Operations\Presentation\Http\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health/live', [HealthController::class, 'live'])->name('health.live');
Route::get('/health/ready', [HealthController::class, 'ready'])->name('health.ready');

require __DIR__.'/installer.php';
