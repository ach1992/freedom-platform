<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Domain\TelegramDeliveryOperationState;
use InvalidArgumentException;

final readonly class TelegramAdministratorDirectMessageDeliveryResult
{
    public function __construct(
        public string $directMessagePublicId,
        public string $deliveryOperationPublicId,
        public TelegramDeliveryOperationState $state,
        public ?int $messageId,
        public ?string $resultCode,
        public string $correlationId,
    ) {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $directMessagePublicId) !== 1
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $deliveryOperationPublicId) !== 1
            || ($messageId !== null && $messageId < 1)
            || ($resultCode !== null && preg_match('/\A[A-Za-z0-9_.:-]{1,64}\z/', $resultCode) !== 1)
            || preg_match('/\A[A-Za-z0-9_.:-]{8,64}\z/', $correlationId) !== 1) {
            throw new InvalidArgumentException('Telegram administrator direct-message delivery result is invalid.');
        }
    }
}
