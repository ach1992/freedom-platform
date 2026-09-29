<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

final readonly class BackupTelegramExportReceipt
{
    /** @param list<string> $deliveryOperationPublicIds */
    public function __construct(
        public string $backupId,
        public int $partCount,
        public int $partBytes,
        public string $manifestSha256,
        public array $deliveryOperationPublicIds,
    ) {}
}
