<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

use App\Modules\Customers\Domain\CustomerTierCode;
use InvalidArgumentException;

final readonly class CustomPlanPolicyDefinition
{
    /** @var list<string> */
    public array $eligibleTierCodes;

    /** @var list<int> */
    public array $eligibleTagIds;

    /** @var list<string> */
    public array $allowedSeparators;

    /** @var list<string> */
    public array $reservedWords;

    /**
     * @param  list<string>  $eligibleTierCodes
     * @param  list<int>  $eligibleTagIds
     * @param  list<string>  $allowedSeparators
     * @param  list<string>  $reservedWords
     */
    public function __construct(
        public bool $enabled,
        public int $minimumDataGb,
        public int $maximumDataGb,
        public int $dataStepGb,
        public int $minimumDays,
        public int $maximumDays,
        public int $dayStep,
        public CustomPlanPricing $customerPricing,
        public CustomPlanPricing $agentPricing,
        public bool $discountEligible,
        public CustomPlanTagMatchMode $tagMatchMode,
        public CustomPlanUsernameMode $usernameMode,
        public int $usernameMinimumLength,
        public int $usernameMaximumLength,
        array $eligibleTierCodes,
        array $eligibleTagIds,
        array $allowedSeparators,
        array $reservedWords,
    ) {
        self::range($minimumDataGb, $maximumDataGb, $dataStepGb, 'Custom-plan data');
        self::range($minimumDays, $maximumDays, $dayStep, 'Custom-plan days');
        if ($usernameMinimumLength < 3 || $usernameMaximumLength > 64 || $usernameMaximumLength < $usernameMinimumLength) {
            throw new InvalidArgumentException('Custom-plan username length range is invalid.');
        }

        $validTiers = array_map(static fn (CustomerTierCode $tier): string => $tier->value, CustomerTierCode::cases());
        $tiers = [];
        foreach ($eligibleTierCodes as $tierCode) {
            $tier = strtolower(trim($tierCode));
            if (! in_array($tier, $validTiers, true)) {
                throw new InvalidArgumentException('Custom-plan tier code is invalid.');
            }
            $tiers[] = $tier;
        }
        $tiers = array_values(array_unique($tiers));
        sort($tiers, SORT_STRING);
        $this->eligibleTierCodes = $tiers;

        foreach ($eligibleTagIds as $tagId) {
            if ($tagId < 1) {
                throw new InvalidArgumentException('Custom-plan tag ID must be positive.');
            }
        }
        $tags = array_values(array_unique($eligibleTagIds));
        sort($tags, SORT_NUMERIC);
        $this->eligibleTagIds = $tags;

        $separatorAllowlist = ['-', '.', '_'];
        $separators = [];
        foreach ($allowedSeparators as $separator) {
            if (! in_array($separator, $separatorAllowlist, true)) {
                throw new InvalidArgumentException('Custom-plan username separator is invalid.');
            }
            $separators[] = $separator;
        }
        $separators = array_values(array_unique($separators));
        sort($separators, SORT_STRING);
        if ($usernameMode === CustomPlanUsernameMode::TelegramUserIdSuffix && ! in_array('_', $separators, true)) {
            throw new InvalidArgumentException('Telegram suffix username mode requires the underscore separator.');
        }
        $this->allowedSeparators = $separators;

        $words = [];
        foreach ($reservedWords as $word) {
            $normalized = strtolower(trim($word));
            if ($normalized === '' || strlen($normalized) > 64 || preg_match('/\A[a-z0-9_.-]+\z/', $normalized) !== 1) {
                throw new InvalidArgumentException('Custom-plan reserved username word is invalid.');
            }
            $words[] = $normalized;
        }
        $words = array_values(array_unique($words));
        sort($words, SORT_STRING);
        $this->reservedWords = $words;
    }

    /** @return array<string, bool|int|string|list<int>|list<string>> */
    public function payload(): array
    {
        return [
            'enabled' => $this->enabled,
            'minimum_data_gb' => $this->minimumDataGb,
            'maximum_data_gb' => $this->maximumDataGb,
            'data_step_gb' => $this->dataStepGb,
            'minimum_days' => $this->minimumDays,
            'maximum_days' => $this->maximumDays,
            'day_step' => $this->dayStep,
            ...$this->customerPricing->payload('customer'),
            ...$this->agentPricing->payload('agent'),
            'discount_eligible' => $this->discountEligible,
            'tag_match_mode' => $this->tagMatchMode->value,
            'username_mode' => $this->usernameMode->value,
            'username_minimum_length' => $this->usernameMinimumLength,
            'username_maximum_length' => $this->usernameMaximumLength,
            'eligible_tier_codes' => $this->eligibleTierCodes,
            'eligible_tag_ids' => $this->eligibleTagIds,
            'allowed_separators' => $this->allowedSeparators,
            'reserved_words' => $this->reservedWords,
        ];
    }

    private static function range(int $minimum, int $maximum, int $step, string $label): void
    {
        if ($minimum < 1 || $maximum < $minimum || $step < 1 || (($maximum - $minimum) % $step) !== 0) {
            throw new InvalidArgumentException($label.' range and step are invalid.');
        }
    }
}
