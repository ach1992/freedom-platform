<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

use App\Modules\Customers\Domain\CustomerTierCode;
use App\Modules\Identity\Domain\PhoneVerificationPolicy;
use InvalidArgumentException;

final readonly class TrialPolicyDefinition
{
    /** @var list<string> */
    public array $eligibleTierCodes;

    /** @var list<int> */
    public array $eligibleTagIds;

    public string $deliveryTemplateKey;

    /**
     * @param  list<string>  $eligibleTierCodes
     * @param  list<int>  $eligibleTagIds
     */
    public function __construct(
        public bool $enabled,
        public int $dataBytes,
        public int $durationDays,
        public int $dailyCapacity,
        public PhoneVerificationPolicy $phoneVerificationPolicy,
        public bool $membershipRequired,
        public bool $onePerUser,
        public bool $onePerPhone,
        public bool $administratorRegrantAllowed,
        public bool $fallbackAllowed,
        public PlanOfferingTagMatchMode $tagMatchMode,
        string $deliveryTemplateKey,
        array $eligibleTierCodes,
        array $eligibleTagIds,
    ) {
        if ($dataBytes < 1 || $durationDays < 1 || $dailyCapacity < 1) {
            throw new InvalidArgumentException('Trial data, duration and daily capacity must be positive.');
        }
        if (! $onePerUser && ! $onePerPhone) {
            throw new InvalidArgumentException('Trial policy requires at least one anti-abuse uniqueness rule.');
        }
        if (! $onePerUser && $phoneVerificationPolicy === PhoneVerificationPolicy::None) {
            throw new InvalidArgumentException('Phone-only trial uniqueness requires verified-phone eligibility.');
        }

        $templateKey = strtolower(trim($deliveryTemplateKey));
        if (preg_match('/\A[a-z][a-z0-9_.-]{2,190}\z/', $templateKey) !== 1) {
            throw new InvalidArgumentException('Trial delivery template key is invalid.');
        }

        $this->deliveryTemplateKey = $templateKey;
        $this->eligibleTierCodes = self::normalizeTiers($eligibleTierCodes);
        $this->eligibleTagIds = self::normalizePositiveIds($eligibleTagIds);
    }

    /** @return array<string, bool|int|string|list<int>|list<string>> */
    public function payload(): array
    {
        return [
            'enabled' => $this->enabled,
            'data_bytes' => $this->dataBytes,
            'duration_days' => $this->durationDays,
            'daily_capacity' => $this->dailyCapacity,
            'phone_verification_policy' => $this->phoneVerificationPolicy->value,
            'membership_required' => $this->membershipRequired,
            'one_per_user' => $this->onePerUser,
            'one_per_phone' => $this->onePerPhone,
            'administrator_regrant_allowed' => $this->administratorRegrantAllowed,
            'fallback_allowed' => $this->fallbackAllowed,
            'tag_match_mode' => $this->tagMatchMode->value,
            'delivery_template_key' => $this->deliveryTemplateKey,
            'eligible_tier_codes' => $this->eligibleTierCodes,
            'eligible_tag_ids' => $this->eligibleTagIds,
        ];
    }

    /**
     * @param  list<string>  $tierCodes
     * @return list<string>
     */
    private static function normalizeTiers(array $tierCodes): array
    {
        $valid = array_map(static fn (CustomerTierCode $tier): string => $tier->value, CustomerTierCode::cases());
        $normalized = [];
        foreach ($tierCodes as $tierCode) {
            $tier = strtolower(trim($tierCode));
            if (! in_array($tier, $valid, true)) {
                throw new InvalidArgumentException('Trial eligibility tier code is invalid.');
            }
            $normalized[] = $tier;
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized, SORT_STRING);

        return $normalized;
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private static function normalizePositiveIds(array $ids): array
    {
        foreach ($ids as $id) {
            if ($id < 1) {
                throw new InvalidArgumentException('Trial eligibility tag ID must be positive.');
            }
        }

        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }
}
