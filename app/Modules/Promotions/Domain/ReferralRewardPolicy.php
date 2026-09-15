<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Domain;

use JsonException;
use RuntimeException;

final readonly class ReferralRewardPolicy
{
    private const DEFAULT_PENDING_HOURS = 24;

    public function __construct(
        public ReferralRewardRecipient $recipient,
        public ?int $pendingHours,
        public ?int $expiryHours,
        public bool $transferable,
        public ?int $perReferralUseLimit,
    ) {}

    public function effectivePendingHours(): int
    {
        return $this->pendingHours ?? self::DEFAULT_PENDING_HOURS;
    }

    /** @param array<string, mixed> $snapshot */
    public static function fromSnapshot(array $snapshot): ?self
    {
        $policyKeys = [
            'referral_reward_recipient',
            'referral_pending_hours',
            'referral_expiry_hours',
            'referral_transferable',
            'per_referral_use_limit',
        ];
        $hasRecipient = array_key_exists('referral_reward_recipient', $snapshot);
        if (! $hasRecipient) {
            foreach (array_slice($policyKeys, 1) as $key) {
                if (array_key_exists($key, $snapshot)) {
                    throw new RuntimeException('Stored referral reward policy is invalid.');
                }
            }

            return null;
        }

        $recipientValue = $snapshot['referral_reward_recipient'];
        $recipient = is_string($recipientValue) ? ReferralRewardRecipient::tryFrom($recipientValue) : null;
        if ($recipient === null
            || ! array_key_exists('referral_transferable', $snapshot)
            || ! is_bool($snapshot['referral_transferable'])) {
            throw new RuntimeException('Stored referral reward policy is invalid.');
        }

        return new self(
            $recipient,
            self::nullablePositiveInt($snapshot, 'referral_pending_hours'),
            self::nullablePositiveInt($snapshot, 'referral_expiry_hours'),
            $snapshot['referral_transferable'],
            self::nullablePositiveInt($snapshot, 'per_referral_use_limit'),
        );
    }

    public static function fromConfigurationSnapshot(string $snapshotJson): ?self
    {
        try {
            $decoded = json_decode($snapshotJson, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Stored referral reward policy snapshot is invalid.', previous: $exception);
        }
        if (! is_array($decoded)) {
            throw new RuntimeException('Stored referral reward policy snapshot is invalid.');
        }

        /** @var array<string, mixed> $decoded */
        return self::fromSnapshot($decoded);
    }

    /** @param array<string, mixed> $snapshot */
    private static function nullablePositiveInt(array $snapshot, string $key): ?int
    {
        if (! array_key_exists($key, $snapshot) || $snapshot[$key] === null) {
            return null;
        }
        if (! is_int($snapshot[$key]) || $snapshot[$key] < 1) {
            throw new RuntimeException('Stored referral reward policy is invalid.');
        }

        return $snapshot[$key];
    }
}
