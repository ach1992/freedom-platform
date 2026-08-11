<?php

declare(strict_types=1);

namespace App\Modules\Payments\Eligibility\Domain;

use InvalidArgumentException;

final readonly class PaymentEligibilityRuleDefinition
{
    /**
     * @param  list<string>  $accountTypes
     * @param  list<string>  $tierCodes
     * @param  list<string>  $tagCodes
     * @param  list<string>  $offeringCodes
     * @param  list<int>  $productIds
     * @param  list<int>  $salesServerIds
     */
    public function __construct(
        public string $methodCode,
        public string $ruleCode,
        public bool $enabled,
        public PaymentEligibilityRuleEffect $effect,
        public int $priority,
        public ?int $subjectUserId = null,
        public array $accountTypes = [],
        public array $tierCodes = [],
        public array $tagCodes = [],
        public ?int $minimumAmountIrr = null,
        public ?int $maximumAmountIrr = null,
        public array $offeringCodes = [],
        public array $productIds = [],
        public array $salesServerIds = [],
        public ?string $requiredIdentityStatus = null,
        public ?string $requiredAgentStatus = null,
        public ?string $startsAtUtc = null,
        public ?string $endsAtUtc = null,
        public bool $requiresContactOtpProvenance = false,
        public bool $requiresPurchaseHistory = false,
        public bool $requiresDailyPaymentLimit = false,
    ) {
        self::assertCode($methodCode, 'Payment method code');
        self::assertCode($ruleCode, 'Payment rule code');
        if ($priority < 0 || $priority > 100_000 || ($subjectUserId !== null && $subjectUserId < 1)) {
            throw new InvalidArgumentException('Payment eligibility rule priority or subject is invalid.');
        }
        if (($minimumAmountIrr !== null && $minimumAmountIrr < 0)
            || ($maximumAmountIrr !== null && $maximumAmountIrr < 0)
            || ($minimumAmountIrr !== null && $maximumAmountIrr !== null && $minimumAmountIrr > $maximumAmountIrr)) {
            throw new InvalidArgumentException('Payment eligibility amount range is invalid.');
        }

        foreach ($accountTypes as $accountType) {
            if (! in_array($accountType, ['customer', 'agent'], true)) {
                throw new InvalidArgumentException('Payment eligibility account type is invalid.');
            }
        }
        foreach ($tierCodes as $tierCode) {
            self::assertCode($tierCode, 'Payment eligibility tier code');
        }
        foreach ($tagCodes as $tagCode) {
            self::assertCode($tagCode, 'Payment eligibility tag code');
        }
        foreach ($offeringCodes as $offeringCode) {
            self::assertCode($offeringCode, 'Payment eligibility offering code');
        }
        foreach ($productIds as $productId) {
            if ($productId < 1) {
                throw new InvalidArgumentException('Payment eligibility product scope is invalid.');
            }
        }
        foreach ($salesServerIds as $salesServerId) {
            if ($salesServerId < 1) {
                throw new InvalidArgumentException('Payment eligibility sales server scope is invalid.');
            }
        }
        if ($requiredIdentityStatus !== null && ! in_array($requiredIdentityStatus, ['unverified', 'pending', 'verified', 'rejected'], true)) {
            throw new InvalidArgumentException('Payment eligibility identity status is invalid.');
        }
        if ($requiredAgentStatus !== null && ! in_array($requiredAgentStatus, ['active', 'suspended'], true)) {
            throw new InvalidArgumentException('Payment eligibility agent status is invalid.');
        }
        self::assertTime($startsAtUtc);
        self::assertTime($endsAtUtc);
    }

    /** @return array<string, mixed> */
    public function configuration(): array
    {
        $configuration = [
            'account_types' => self::uniqueSortedStrings($this->accountTypes),
            'effect' => $this->effect->value,
            'enabled' => $this->enabled,
            'ends_at_utc' => $this->endsAtUtc,
            'formula_version' => 'pay-001-rule-v2',
            'maximum_amount_irr' => $this->maximumAmountIrr,
            'method_code' => $this->methodCode,
            'minimum_amount_irr' => $this->minimumAmountIrr,
            'offering_codes' => self::uniqueSortedStrings($this->offeringCodes),
            'priority' => $this->priority,
            'product_ids' => self::uniqueSortedInts($this->productIds),
            'required_agent_status' => $this->requiredAgentStatus,
            'required_identity_status' => $this->requiredIdentityStatus,
            'requires_contact_otp_provenance' => $this->requiresContactOtpProvenance,
            'requires_daily_payment_limit' => $this->requiresDailyPaymentLimit,
            'requires_purchase_history' => $this->requiresPurchaseHistory,
            'rule_code' => $this->ruleCode,
            'sales_server_ids' => self::uniqueSortedInts($this->salesServerIds),
            'starts_at_utc' => $this->startsAtUtc,
            'subject_user_id' => $this->subjectUserId,
            'tag_codes' => self::uniqueSortedStrings($this->tagCodes),
            'tier_codes' => self::uniqueSortedStrings($this->tierCodes),
        ];
        ksort($configuration, SORT_STRING);

        return $configuration;
    }

    private static function assertCode(string $value, string $label): void
    {
        if (preg_match('/\A[a-z][a-z0-9_.-]{1,63}\z/', $value) !== 1) {
            throw new InvalidArgumentException($label.' is invalid.');
        }
    }

    private static function assertTime(?string $value): void
    {
        if ($value !== null && preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/', $value) !== 1) {
            throw new InvalidArgumentException('Payment eligibility UTC time window is invalid.');
        }
    }

    /** @param list<string> $values
     * @return list<string>
     */
    private static function uniqueSortedStrings(array $values): array
    {
        $normalized = array_values(array_unique($values));
        sort($normalized, SORT_STRING);

        return $normalized;
    }

    /** @param list<int> $values
     * @return list<int>
     */
    private static function uniqueSortedInts(array $values): array
    {
        $normalized = array_values(array_unique($values));
        sort($normalized, SORT_NUMERIC);

        return $normalized;
    }
}
