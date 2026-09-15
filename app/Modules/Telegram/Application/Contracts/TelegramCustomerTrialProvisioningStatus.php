<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Application\TelegramCustomerTrialProvisioningStatusSnapshot;

interface TelegramCustomerTrialProvisioningStatus
{
    public function statusForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $provisioningPublicId,
        string $servicePublicId,
    ): TelegramCustomerTrialProvisioningStatusSnapshot;
}
