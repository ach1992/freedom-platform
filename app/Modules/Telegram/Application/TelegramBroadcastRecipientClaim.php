<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;
use LogicException;

final readonly class TelegramBroadcastRecipientClaim
{
    public function __construct(
        public string $campaignPublicId,
        public string $recipientPublicId,
        public string $recipientMessagePublicId,
        private string $claimToken,
    ) {
        foreach ([
            $campaignPublicId => 'campaign',
            $recipientPublicId => 'recipient',
            $recipientMessagePublicId => 'recipient message',
        ] as $publicId => $label) {
            if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $publicId) !== 1) {
                throw new InvalidArgumentException('Broadcast '.$label.' public ID is invalid.');
            }
        }
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $claimToken) !== 1) {
            throw new InvalidArgumentException('Broadcast recipient claim token is invalid.');
        }
    }

    public function claimTokenHash(): string
    {
        return hash('sha256', $this->claimToken);
    }

    public function __toString(): string
    {
        return '[TELEGRAM_BROADCAST_RECIPIENT_CLAIM]';
    }

    /** @return array{redacted:true,type:string} */
    public function __debugInfo(): array
    {
        return ['redacted' => true, 'type' => 'broadcast_recipient_claim'];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Broadcast recipient claims cannot be serialized.');
    }

    /** @param array<array-key,mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Broadcast recipient claims cannot be unserialized.');
    }
}
