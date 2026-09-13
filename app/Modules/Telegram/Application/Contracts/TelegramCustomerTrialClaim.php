<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Application\TelegramCustomerTrialClaimOptions;
use App\Modules\Telegram\Application\TelegramCustomerTrialClaimReceipt;
use DateTimeImmutable;

interface TelegramCustomerTrialClaim
{
    public function optionsForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $offeringSelectionToken,
        ?string $routeSelectionToken,
    ): TelegramCustomerTrialClaimOptions;

    public function claimForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $offeringSelectionToken,
        ?string $routeSelectionToken,
        ?string $protocolSelectionToken,
        string $operationKey,
        DateTimeImmutable $acceptedAt,
    ): TelegramCustomerTrialClaimReceipt;
}
