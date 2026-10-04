<?php

declare(strict_types=1);

namespace App\Modules\Installer\Application\Contracts;

interface InstallerFinalizationRunner
{
    public function clearConfiguration(): void;

    public function migrate(?string $lifecycleDatabasePassword = null): void;

    public function seed(): void;

    public function bootstrapOwner(): void;

    public function cacheConfiguration(): void;

    public function configureTelegramWebhook(): void;

    public function verifyTelegramReportChannel(): void;

    public function verifyHealth(): void;

    public function verifyScheduler(): void;

    public function writeInstallationReport(): void;
}
