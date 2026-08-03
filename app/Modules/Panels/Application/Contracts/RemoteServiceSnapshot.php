<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application\Contracts;

use DateTimeImmutable;

final readonly class RemoteServiceSnapshot
{
    public function __construct(
        public string $remoteId,
        public string $username,
        public PanelServiceStatus $status,
        public ?int $dataLimitBytes,
        public ?int $usedBytes,
        public ?DateTimeImmutable $expiresAt,
        public string $canonicalHash,
    ) {}
}
