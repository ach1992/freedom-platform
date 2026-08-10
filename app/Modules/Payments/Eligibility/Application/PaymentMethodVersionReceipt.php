<?php

declare(strict_types=1);

namespace App\Modules\Payments\Eligibility\Application;

final readonly class PaymentMethodVersionReceipt
{
    public function __construct(
        public int $versionId,
        public string $methodCode,
        public int $version,
        public bool $enabled,
        public bool $maintenance,
        public int $displayPriority,
        public string $configurationSnapshotHash,
        public bool $replayed,
    ) {}
}
