<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class TelegramChannelMembershipRuleDefinition
{
    public string $ruleKey;

    public ?string $tierCode;

    public ?string $effectiveFromUtc;

    public ?string $effectiveUntilUtc;

    /** @var list<int> */
    public array $requiredChannelIds;

    /**
     * @param  list<int>  $requiredChannelIds
     */
    public function __construct(
        string $ruleKey,
        public ?string $action,
        public string $audience,
        ?string $tierCode,
        public ?int $customerTagId,
        public ?int $planOfferingId,
        public string $matchMode,
        public string $failurePolicy,
        public int $priority,
        ?DateTimeImmutable $effectiveFrom,
        ?DateTimeImmutable $effectiveUntil,
        array $requiredChannelIds,
    ) {
        $key = strtolower(trim($ruleKey));
        if (preg_match('/\A[a-z][a-z0-9_.-]{2,63}\z/', $key) !== 1) {
            throw new InvalidArgumentException('Telegram membership-rule key is invalid.');
        }

        if ($action !== null && ! in_array($action, [
            'bot_entry',
            'trial',
            'purchase',
            'gift_code_use',
            'referral_reward',
            'ticket_creation',
            'service_view',
            'support_view',
        ], true)) {
            throw new InvalidArgumentException('Telegram membership-rule action is invalid.');
        }
        if (! in_array($audience, ['customers', 'agents', 'both'], true)) {
            throw new InvalidArgumentException('Telegram membership-rule audience is invalid.');
        }
        if (! in_array($matchMode, ['all', 'any'], true)) {
            throw new InvalidArgumentException('Telegram membership-rule match mode is invalid.');
        }
        if (! in_array($failurePolicy, ['fail_open', 'fail_closed', 'manual_review'], true)) {
            throw new InvalidArgumentException('Telegram membership-rule failure policy is invalid.');
        }
        if ($priority < 0 || $priority > 65_535) {
            throw new InvalidArgumentException('Telegram membership-rule priority is invalid.');
        }

        $tier = $tierCode === null ? null : strtolower(trim($tierCode));
        if ($tier !== null && preg_match('/\A[a-z][a-z0-9_.-]{1,31}\z/', $tier) !== 1) {
            throw new InvalidArgumentException('Telegram membership-rule tier code is invalid.');
        }
        if ($customerTagId !== null && $customerTagId < 1) {
            throw new InvalidArgumentException('Telegram membership-rule customer tag ID is invalid.');
        }
        if ($planOfferingId !== null && $planOfferingId < 1) {
            throw new InvalidArgumentException('Telegram membership-rule Plan Offering ID is invalid.');
        }
        if (($tier !== null || $customerTagId !== null) && $audience !== 'customers') {
            throw new InvalidArgumentException('Telegram membership-rule customer tier/tag requires customer-only audience.');
        }
        if ($planOfferingId !== null && ! in_array($action, ['trial', 'purchase'], true)) {
            throw new InvalidArgumentException('Telegram membership-rule Plan Offering requires trial or purchase action.');
        }

        $from = $effectiveFrom?->setTimezone(new DateTimeZone('UTC'));
        $until = $effectiveUntil?->setTimezone(new DateTimeZone('UTC'));
        if ($from !== null && $until !== null && $until <= $from) {
            throw new InvalidArgumentException('Telegram membership-rule effective-until must be after effective-from.');
        }

        if (count($requiredChannelIds) > 32) {
            throw new InvalidArgumentException('Telegram membership rule cannot contain more than 32 channels.');
        }
        $channels = [];
        foreach ($requiredChannelIds as $channelId) {
            if (! is_int($channelId) || $channelId < 1) {
                throw new InvalidArgumentException('Telegram membership-rule channel ID is invalid.');
            }
            if (in_array($channelId, $channels, true)) {
                throw new InvalidArgumentException('Telegram membership-rule channel IDs must be unique.');
            }
            $channels[] = $channelId;
        }

        $this->ruleKey = $key;
        $this->tierCode = $tier;
        $this->effectiveFromUtc = $from?->format('Y-m-d H:i:s.u');
        $this->effectiveUntilUtc = $until?->format('Y-m-d H:i:s.u');
        $this->requiredChannelIds = $channels;
    }
}
