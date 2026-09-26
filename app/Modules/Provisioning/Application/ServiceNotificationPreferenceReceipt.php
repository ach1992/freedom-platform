<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

final readonly class ServiceNotificationPreferenceReceipt
{
    public function __construct(
        public string $preferencePublicId,
        public int $ownerUserId,
        public ?string $servicePublicId,
        public string $notificationType,
        public string $thresholdCode,
        public bool $enabled,
        public int $version,
        public bool $replayed,
    ) {}
}
