<?php

declare(strict_types=1);

use App\Modules\Customers\Presentation\Console\RecalculateCustomerTiersCommand;
use App\Modules\Installer\Presentation\Console\IssueInstallerTokenCommand;
use App\Modules\Installer\Presentation\Http\Middleware\EnsureInstallerAvailable;
use App\Modules\Installer\Presentation\Http\Middleware\EnsureInstallerHttps;
use App\Modules\Installer\Presentation\Http\Middleware\EnsureInstallerUnlocked;
use App\Modules\Operations\Presentation\Console\ApplyUpdateCommand;
use App\Modules\Operations\Presentation\Console\CheckOutboxContractRetirementCommand;
use App\Modules\Operations\Presentation\Console\CheckWorkerHeartbeatsCommand;
use App\Modules\Operations\Presentation\Console\CreateBackupCommand;
use App\Modules\Operations\Presentation\Console\DeliverOperationalAlertsCommand;
use App\Modules\Operations\Presentation\Console\DispatchOutboxCommand;
use App\Modules\Operations\Presentation\Console\HealthCheckCommand;
use App\Modules\Operations\Presentation\Console\QueueBackupTelegramExportCommand;
use App\Modules\Operations\Presentation\Console\RecordWorkerHeartbeatCommand;
use App\Modules\Operations\Presentation\Console\RecoverUpdateCommand;
use App\Modules\Operations\Presentation\Console\RestoreBackupCommand;
use App\Modules\Operations\Presentation\Console\RestoreRuntimeAttestCommand;
use App\Modules\Operations\Presentation\Console\RollbackUpdateCommand;
use App\Modules\Payments\Presentation\Console\AlternativePaymentMaintenanceCommand;
use App\Modules\Payments\Presentation\Console\PurchasePaymentMaintenanceCommand;
use App\Modules\Promotions\Presentation\Console\ProcessReferralRewardsCommand;
use App\Modules\Provisioning\Presentation\Console\ProcessServiceAutoRenewalsCommand;
use App\Modules\Provisioning\Presentation\Console\ProcessServiceNotificationsCommand;
use App\Modules\Provisioning\Presentation\Console\ProcessServiceSynchronizationsCommand;
use App\Modules\Reporting\Presentation\Console\RunReportSchedulesCommand;
use App\Modules\Support\Presentation\Console\ScanSupportAlertsCommand;
use App\Modules\Telegram\Presentation\Console\ApplyTelegramUpdateRetentionCommand;
use App\Modules\Telegram\Presentation\Console\ConfigureTelegramWebhookCommand;
use App\Modules\Telegram\Presentation\Console\ProcessTelegramBroadcastsCommand;
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
        ApplyUpdateCommand::class,
        CheckOutboxContractRetirementCommand::class,
        CheckWorkerHeartbeatsCommand::class,
        CreateBackupCommand::class,
        DeliverOperationalAlertsCommand::class,
        QueueBackupTelegramExportCommand::class,
        RecalculateCustomerTiersCommand::class,
        ConfigureTelegramWebhookCommand::class,
        DispatchOutboxCommand::class,
        RequeueTelegramUpdatesCommand::class,
        ApplyTelegramUpdateRetentionCommand::class,
        HealthCheckCommand::class,
        IssueInstallerTokenCommand::class,
        ProcessServiceAutoRenewalsCommand::class,
        ProcessServiceNotificationsCommand::class,
        ProcessServiceSynchronizationsCommand::class,
        ScanSupportAlertsCommand::class,
        ProcessReferralRewardsCommand::class,
        RunReportSchedulesCommand::class,
        RecordWorkerHeartbeatCommand::class,
        RecoverUpdateCommand::class,
        RestoreBackupCommand::class,
        RestoreRuntimeAttestCommand::class,
        RollbackUpdateCommand::class,
        AlternativePaymentMaintenanceCommand::class,
        PurchasePaymentMaintenanceCommand::class,
        ProcessTelegramBroadcastsCommand::class,
        WalletMaintenanceCommand::class,
    ])
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash('lifecycle_database_password');
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
