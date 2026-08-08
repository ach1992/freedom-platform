<?php

declare(strict_types=1);

use App\Modules\Installer\Presentation\Console\IssueInstallerTokenCommand;
use App\Modules\Installer\Presentation\Http\Middleware\EnsureInstallerAvailable;
use App\Modules\Installer\Presentation\Http\Middleware\EnsureInstallerHttps;
use App\Modules\Installer\Presentation\Http\Middleware\EnsureInstallerUnlocked;
use App\Modules\Operations\Presentation\Console\CheckWorkerHeartbeatsCommand;
use App\Modules\Operations\Presentation\Console\HealthCheckCommand;
use App\Modules\Operations\Presentation\Console\RecordWorkerHeartbeatCommand;
use App\Modules\Telegram\Presentation\Console\ConfigureTelegramWebhookCommand;
use App\Modules\Telegram\Presentation\Console\RequeueTelegramUpdatesCommand;
use App\Modules\Telegram\Presentation\Http\Middleware\VerifyTelegramWebhookRequest;
use App\Modules\Wallet\Presentation\Console\WalletMaintenanceCommand;
use App\Shared\Infrastructure\Http\CorrelationIdMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(CorrelationIdMiddleware::class);
        $middleware->alias([
            'installer.available' => EnsureInstallerAvailable::class,
            'installer.https' => EnsureInstallerHttps::class,
            'installer.unlocked' => EnsureInstallerUnlocked::class,
            'telegram.webhook' => VerifyTelegramWebhookRequest::class,
        ]);
    })
    ->withCommands([
        CheckWorkerHeartbeatsCommand::class,
        ConfigureTelegramWebhookCommand::class,
        RequeueTelegramUpdatesCommand::class,
        HealthCheckCommand::class,
        IssueInstallerTokenCommand::class,
        RecordWorkerHeartbeatCommand::class,
        WalletMaintenanceCommand::class,
    ])
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
