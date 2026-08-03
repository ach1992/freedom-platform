<?php

declare(strict_types=1);

use App\Modules\Installer\Presentation\Http\InstallerAccessController;
use Illuminate\Support\Facades\Route;

Route::middleware(['installer.https', 'installer.available', 'throttle:installer'])->prefix('installer')->group(function (): void {
    Route::get('/unlock', [InstallerAccessController::class, 'showUnlock'])->name('installer.unlock');
    Route::post('/unlock', [InstallerAccessController::class, 'unlock'])->name('installer.unlock.submit');

    Route::middleware('installer.unlocked')->group(function (): void {
        Route::get('/preflight', [InstallerAccessController::class, 'preflight'])->name('installer.preflight');
    });
});
