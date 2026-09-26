<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Application\TelegramCustomerPurchaseQuotePreview;
use App\Modules\Telegram\Application\TelegramServiceReconfigurationExecution;
use App\Modules\Telegram\Application\TelegramServiceReconfigurationOptions;
use App\Modules\Telegram\Application\TelegramServiceReconfigurationPreview;
use DateTimeImmutable;

interface TelegramOwnedServiceReconfigurationManager
{
    public function optionsForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $servicePublicId,
        string $offeringSelectionToken,
        ?string $routeSelectionToken,
    ): TelegramServiceReconfigurationOptions;

    public function previewForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $servicePublicId,
        string $offeringSelectionToken,
        ?string $routeSelectionToken,
        ?string $protocolSelectionToken,
        string $requestKey,
        string $correlationId,
    ): TelegramServiceReconfigurationPreview;

    public function executeNoChargeForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $previewPublicId,
        string $requestKey,
        string $correlationId,
    ): TelegramServiceReconfigurationExecution;

    public function quoteForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $offeringSelectionToken,
        string $previewPublicId,
        DateTimeImmutable $acceptedAt,
        string $quoteKey,
        string $correlationId,
    ): TelegramCustomerPurchaseQuotePreview;
}
