<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramChannelMembershipResolutionRequest
{
    private const ACTIONS = [
        'bot_entry',
        'trial',
        'purchase',
        'gift_code_use',
        'referral_reward',
        'ticket_creation',
        'service_view',
        'support_view',
    ];

    public function __construct(
        public int $userId,
        public string $action,
        public ?int $planOfferingId = null,
    ) {
        if ($userId < 1) {
            throw new InvalidArgumentException('Telegram membership resolution user ID must be positive.');
        }
        if (! in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException('Telegram membership resolution action is invalid.');
        }

        $offeringAction = in_array($action, ['trial', 'purchase'], true);
        if ($offeringAction && ($planOfferingId === null || $planOfferingId < 1)) {
            throw new InvalidArgumentException('Telegram membership resolution requires a positive Plan Offering ID for trial or purchase.');
        }
        if (! $offeringAction && $planOfferingId !== null) {
            throw new InvalidArgumentException('Telegram membership resolution Plan Offering is only valid for trial or purchase.');
        }
    }
}
