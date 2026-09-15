<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class ProtectedTelegramSendResult
{
    public function __construct(
        public ProtectedTelegramSendOutcome $outcome,
        public string $resultCode,
        public ?int $messageId = null,
        public ?int $retryAfterSeconds = null,
    ) {
        if (preg_match('/\A[a-z0-9_.:-]{1,64}\z/', $resultCode) !== 1) {
            throw new InvalidArgumentException('Protected Telegram result code is invalid.');
        }
        if ($outcome === ProtectedTelegramSendOutcome::Success && ($messageId === null || $messageId < 1)) {
            throw new InvalidArgumentException('Successful protected Telegram send requires a message ID.');
        }
        if ($outcome !== ProtectedTelegramSendOutcome::Success && $messageId !== null) {
            throw new InvalidArgumentException('Only successful protected Telegram sends may expose a message ID.');
        }
        if ($outcome === ProtectedTelegramSendOutcome::RetryAfter
            && ($retryAfterSeconds === null || $retryAfterSeconds < 1 || $retryAfterSeconds > 86_400)) {
            throw new InvalidArgumentException('Protected Telegram retry delay is invalid.');
        }
        if ($outcome !== ProtectedTelegramSendOutcome::RetryAfter && $retryAfterSeconds !== null) {
            throw new InvalidArgumentException('Retry delay is only valid for provider-directed retry results.');
        }
    }
}
