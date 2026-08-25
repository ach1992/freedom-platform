<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramMutationResult
{
    public function __construct(
        public TelegramMutationOutcome $outcome,
        public string $resultCode,
        public ?int $messageId = null,
        public ?int $retryAfterSeconds = null,
    ) {
        if (preg_match('/\A[a-z0-9_.:-]{1,64}\z/', $resultCode) !== 1) {
            throw new InvalidArgumentException('Telegram mutation result code is invalid.');
        }

        if ($messageId !== null && $messageId < 1) {
            throw new InvalidArgumentException('Telegram mutation result message identity is invalid.');
        }

        if ($outcome !== TelegramMutationOutcome::Success && $messageId !== null) {
            throw new InvalidArgumentException('Only successful Telegram mutations may expose a message identity.');
        }

        if ($outcome === TelegramMutationOutcome::RetryAfter
            && ($retryAfterSeconds === null || $retryAfterSeconds < 1 || $retryAfterSeconds > 86_400)) {
            throw new InvalidArgumentException('Telegram provider-directed retry delay is invalid.');
        }

        if ($outcome !== TelegramMutationOutcome::RetryAfter && $retryAfterSeconds !== null) {
            throw new InvalidArgumentException('Retry delay is valid only for provider-directed retry results.');
        }
    }
}
