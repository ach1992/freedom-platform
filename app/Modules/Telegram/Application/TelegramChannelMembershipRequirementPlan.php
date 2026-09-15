<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramChannelMembershipRequirementPlan
{
    /**
     * @param  list<TelegramChannelMembershipRequirementChannel>  $channels
     */
    public function __construct(
        public int $userId,
        public string $subjectAccountType,
        public string $action,
        public ?int $planOfferingId,
        public bool $required,
        public ?int $ruleId,
        public ?string $ruleKey,
        public ?int $ruleVersion,
        public ?string $matchMode,
        public ?string $failurePolicy,
        public ?int $priority,
        public ?string $effectiveFromUtc,
        public ?string $effectiveUntilUtc,
        public array $channels,
        public string $configurationHash,
    ) {
        if ($userId < 1 || ! in_array($subjectAccountType, ['customer', 'agent'], true)) {
            throw new InvalidArgumentException('Telegram membership requirement subject is invalid.');
        }
        if (preg_match('/\A[0-9a-f]{64}\z/', $configurationHash) !== 1) {
            throw new InvalidArgumentException('Telegram membership requirement configuration hash is invalid.');
        }

        if (! $required) {
            if ($ruleId !== null || $ruleKey !== null || $ruleVersion !== null || $matchMode !== null
                || $failurePolicy !== null || $priority !== null || $effectiveFromUtc !== null
                || $effectiveUntilUtc !== null || $channels !== []) {
                throw new InvalidArgumentException('No-requirement Telegram membership plan cannot contain rule configuration.');
            }

            return;
        }

        if ($ruleId === null || $ruleId < 1 || $ruleKey === null || $ruleVersion === null || $ruleVersion < 1
            || ! in_array($matchMode, ['all', 'any'], true)
            || ! in_array($failurePolicy, ['fail_open', 'fail_closed', 'manual_review'], true)
            || $priority === null || $priority < 0 || $channels === []) {
            throw new InvalidArgumentException('Telegram membership requirement plan is incomplete.');
        }
    }
}
