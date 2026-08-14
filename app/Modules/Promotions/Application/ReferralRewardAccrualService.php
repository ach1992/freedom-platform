<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Application;

use App\Modules\Promotions\Domain\PromotionDiscountType;
use App\Modules\Promotions\Domain\ReferralRewardPolicy;
use App\Modules\Promotions\Domain\ReferralRewardRecipient;
use App\Shared\Application\Clock;
use App\Shared\Application\OutboxPublisher;
use App\Shared\Application\SafeOutboxPayload;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class ReferralRewardAccrualService
{
    public function __construct(
        private DatabaseManager $database,
        private OutboxPublisher $outbox,
        private Clock $clock,
    ) {}

    /** @requirement REF-001 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 QUA-004 */
    public function accrue(string $purchaseSettlementPublicId, string $correlationId): ?ReferralRewardAccrualReceipt
    {
        if (! Str::isUlid($purchaseSettlementPublicId)) {
            throw new DomainException('Purchase settlement public ID is invalid.');
        }
        if (preg_match('/\A[A-Za-z0-9:_.-]{8,64}\z/', $correlationId) !== 1) {
            throw new DomainException('Referral reward correlation ID is invalid.');
        }

        try {
            return $this->database->connection()->transaction(function (Connection $connection) use ($purchaseSettlementPublicId, $correlationId): ?ReferralRewardAccrualReceipt {
                $existing = $this->accrualBySettlementPublicId($connection, $purchaseSettlementPublicId, true);
                if ($existing !== null) {
                    return $this->receipt($connection, $existing, true);
                }

                /** @var object{id:int|string,public_id:string,user_id:int|string,source_quote_id:int|string,source_quote_public_id:string,amount_irr:int|string,currency:string,settled_at:string}|null $settlement */
                $settlement = $connection->table('purchase_settlements')
                    ->where('public_id', $purchaseSettlementPublicId)
                    ->lockForUpdate()
                    ->first(['id', 'public_id', 'user_id', 'source_quote_id', 'source_quote_public_id', 'amount_irr', 'currency', 'settled_at']);
                if ($settlement === null) {
                    throw new DomainException('Authoritative purchase settlement does not exist.');
                }
                if ($settlement->currency !== 'IRR') {
                    throw new RuntimeException('Purchase settlement currency is invalid.');
                }

                $settlementId = $this->positiveInt($settlement->id, 'Purchase settlement ID');
                $referredUserId = $this->positiveInt($settlement->user_id, 'Referred user ID');
                $sourceQuoteId = $this->positiveInt($settlement->source_quote_id, 'Source quote ID');
                $qualifyingAmountIrr = $this->positiveInt($settlement->amount_irr, 'Purchase settlement amount');
                $settledAt = $this->storedDateTime($settlement->settled_at);

                /** @var object{id:int|string,public_id:string,user_id:int|string,account_type_snapshot:string,plan_offering_id:int|string,final_price_irr:int|string,currency:string}|null $quote */
                $quote = $connection->table('quotes')->where('id', $sourceQuoteId)->lockForUpdate()->first([
                    'id', 'public_id', 'user_id', 'account_type_snapshot', 'plan_offering_id', 'final_price_irr', 'currency',
                ]);
                if ($quote === null
                    || $this->positiveInt($quote->user_id, 'Quote user ID') !== $referredUserId
                    || ! hash_equals($quote->public_id, $settlement->source_quote_public_id)
                    || $this->nonNegativeInt($quote->final_price_irr, 'Quote final price') !== $qualifyingAmountIrr
                    || $quote->currency !== 'IRR') {
                    throw new RuntimeException('Purchase settlement quote authority is inconsistent.');
                }

                /** @var object{id:int|string,referred_user_id:int|string,inviter_user_id:int|string,inviter_referral_identity_id:int|string,locked_purchase_settlement_id:int|string|null}|null $relationship */
                $relationship = $connection->table('referral_relationships')
                    ->where('referred_user_id', $referredUserId)
                    ->lockForUpdate()
                    ->first(['id', 'referred_user_id', 'inviter_user_id', 'inviter_referral_identity_id', 'locked_purchase_settlement_id']);
                if ($relationship === null || $relationship->locked_purchase_settlement_id === null) {
                    return null;
                }

                $relationshipId = $this->positiveInt($relationship->id, 'Referral relationship ID');
                $inviterUserId = $this->positiveInt($relationship->inviter_user_id, 'Inviter user ID');
                if ($inviterUserId === $referredUserId) {
                    throw new RuntimeException('Stored self-referral relationship is invalid.');
                }
                $lockedSettlementId = $this->positiveInt($relationship->locked_purchase_settlement_id, 'Referral lock settlement ID');

                $inviterToken = $connection->table('referral_identities')
                    ->where('id', $this->positiveInt($relationship->inviter_referral_identity_id, 'Referral identity ID'))
                    ->where('user_id', $inviterUserId)
                    ->value('token');
                if (! is_string($inviterToken) || preg_match('/\A[0-9a-f]{32}\z/', $inviterToken) !== 1) {
                    throw new RuntimeException('Stored referral source identity is invalid.');
                }

                if ($this->hasSharedVerifiedPhone($connection, $inviterUserId, $referredUserId)) {
                    return null;
                }

                /** @var object{product_id:int|string,sales_server_id:int|string}|null $offering */
                $offering = $connection->table('plan_offerings')
                    ->where('id', $this->positiveInt($quote->plan_offering_id, 'Quote offering ID'))
                    ->first(['product_id', 'sales_server_id']);
                if ($offering === null) {
                    throw new RuntimeException('Quote offering authority is unavailable.');
                }

                $rule = $this->selectRule(
                    $connection,
                    $settledAt,
                    $qualifyingAmountIrr,
                    $quote->account_type_snapshot,
                    $this->positiveInt($quote->plan_offering_id, 'Quote offering ID'),
                    $this->positiveInt($offering->product_id, 'Offering product ID'),
                    $this->positiveInt($offering->sales_server_id, 'Offering sales server ID'),
                    $inviterToken,
                    $referredUserId,
                    $relationshipId,
                    $settlementId,
                    $lockedSettlementId,
                );
                if ($rule === null) {
                    return null;
                }

                $ruleVersionId = $this->positiveInt($rule->id, 'Referral rule version ID');
                $lockedRule = $connection->table('pricing_rule_versions')->where('id', $ruleVersionId)->lockForUpdate()->first(['id']);
                if ($lockedRule === null) {
                    throw new RuntimeException('Referral rule version disappeared during accrual.');
                }

                if (! $this->capacityAvailable($connection, $rule, $referredUserId, $relationshipId)) {
                    return null;
                }

                $policy = ReferralRewardPolicy::fromConfigurationSnapshot($rule->configuration_snapshot)
                    ?? throw new RuntimeException('Selected referral rule has no reward policy.');
                $rewardAmountIrr = $this->calculateReward($rule, $qualifyingAmountIrr);
                $releaseAt = $settledAt->add(new DateInterval('PT'.$policy->effectivePendingHours().'H'));
                $expiresAt = $policy->expiryHours === null ? null : $releaseAt->add(new DateInterval('PT'.$policy->expiryHours.'H'));
                $createdAt = $this->databaseDateTime($this->clock->now());
                $accrualPublicId = (string) Str::ulid();

                $accrualId = (int) $connection->table('referral_reward_accruals')->insertGetId([
                    'public_id' => $accrualPublicId,
                    'purchase_settlement_id' => $settlementId,
                    'referral_relationship_id' => $relationshipId,
                    'referred_user_id' => $referredUserId,
                    'inviter_user_id' => $inviterUserId,
                    'pricing_rule_id' => $this->positiveInt($rule->pricing_rule_id, 'Referral rule ID'),
                    'pricing_rule_version_id' => $ruleVersionId,
                    'source_quote_id' => $sourceQuoteId,
                    'purchase_settlement_public_id' => $settlement->public_id,
                    'source_quote_public_id' => $quote->public_id,
                    'rule_code_snapshot' => $rule->rule_code,
                    'rule_version' => $this->positiveInt($rule->version, 'Referral rule version'),
                    'rule_configuration_hash' => $rule->configuration_hash,
                    'qualifying_amount_irr' => $qualifyingAmountIrr,
                    'reward_amount_irr' => $rewardAmountIrr,
                    'recipient_policy' => $policy->recipient->value,
                    'release_at' => $this->databaseDateTime($releaseAt),
                    'expires_at' => $expiresAt === null ? null : $this->databaseDateTime($expiresAt),
                    'transferable' => $policy->transferable,
                    'created_at' => $createdAt,
                ]);

                foreach ($this->recipientRows($policy->recipient, $inviterUserId, $referredUserId) as [$role, $recipientUserId]) {
                    $rewardPublicId = (string) Str::ulid();
                    $connection->table('referral_rewards')->insert([
                        'public_id' => $rewardPublicId,
                        'accrual_id' => $accrualId,
                        'purchase_settlement_id' => $settlementId,
                        'recipient_role' => $role,
                        'recipient_user_id' => $recipientUserId,
                        'amount_irr' => $rewardAmountIrr,
                        'state' => 'pending',
                        'release_at' => $this->databaseDateTime($releaseAt),
                        'expires_at' => $expiresAt === null ? null : $this->databaseDateTime($expiresAt),
                        'transferable' => $policy->transferable,
                        'created_at' => $createdAt,
                    ]);
                    $this->outbox->publish(
                        (string) Str::uuid(),
                        'referral.reward.pending:'.$rewardPublicId,
                        'referral.reward.pending',
                        'referral_reward',
                        $rewardPublicId,
                        new SafeOutboxPayload([
                            'accrual_public_id' => $accrualPublicId,
                            'expires_at' => $expiresAt?->format('Y-m-d\TH:i:s.u\Z'),
                            'recipient_role' => $role,
                            'release_at' => $releaseAt->format('Y-m-d\TH:i:s.u\Z'),
                            'reward_public_id' => $rewardPublicId,
                        ]),
                        $correlationId,
                    );
                }

                $created = $this->accrualBySettlementPublicId($connection, $purchaseSettlementPublicId);
                if ($created === null) {
                    throw new RuntimeException('Referral reward accrual persistence failed.');
                }

                return $this->receipt($connection, $created, false);
            }, 3);
        } catch (QueryException $exception) {
            $existing = $this->accrualBySettlementPublicId($this->database->connection(), $purchaseSettlementPublicId);
            if ($existing !== null) {
                return $this->receipt($this->database->connection(), $existing, true);
            }

            throw $exception;
        }
    }

    private function hasSharedVerifiedPhone(Connection $connection, int $inviterUserId, int $referredUserId): bool
    {
        return $connection->table('phone_numbers as referred_phone')
            ->join('phone_numbers as inviter_phone', 'inviter_phone.lookup_hash', '=', 'referred_phone.lookup_hash')
            ->where('referred_phone.user_id', $referredUserId)
            ->whereNotNull('referred_phone.verified_at')
            ->where('inviter_phone.user_id', $inviterUserId)
            ->whereNotNull('inviter_phone.verified_at')
            ->exists();
    }

    private function selectRule(
        Connection $connection,
        DateTimeImmutable $settledAt,
        int $amountIrr,
        string $accountType,
        int $planOfferingId,
        int $productId,
        int $salesServerId,
        string $referralSourceCode,
        int $referredUserId,
        int $relationshipId,
        int $settlementId,
        int $lockedSettlementId,
    ): ?object {
        $settledAtString = $this->databaseDateTime($settledAt);
        $latestByRule = [];
        $rows = $connection->table('pricing_rule_versions as v')
            ->join('pricing_rules as r', 'r.id', '=', 'v.pricing_rule_id')
            ->where('r.kind', 'referral')
            ->where('v.created_at', '<=', $settledAtString)
            ->get([
                'v.id', 'v.pricing_rule_id', 'v.version', 'v.state', 'v.priority', 'v.discount_type',
                'v.fixed_discount_irr', 'v.percentage_basis_points', 'v.minimum_order_irr', 'v.maximum_discount_irr',
                'v.effective_from', 'v.effective_until', 'v.total_use_limit', 'v.per_user_use_limit',
                'v.first_purchase_only', 'v.audience', 'v.tier_code', 'v.customer_tag_id', 'v.plan_offering_id',
                'v.product_id', 'v.sales_server_id', 'v.action', 'v.referral_source_code', 'v.configuration_snapshot',
                'v.configuration_hash', 'r.rule_code',
            ]);
        foreach ($rows as $row) {
            $ruleId = $this->positiveInt($row->pricing_rule_id, 'Referral rule ID');
            if (! isset($latestByRule[$ruleId]) || (int) $row->version > (int) $latestByRule[$ruleId]->version) {
                $latestByRule[$ruleId] = $row;
            }
        }

        $qualified = [];
        foreach ($latestByRule as $row) {
            $policy = ReferralRewardPolicy::fromConfigurationSnapshot($row->configuration_snapshot);
            if ($policy === null || $row->state !== 'active') {
                continue;
            }
            if (! $this->audienceAllows($row->audience, $accountType)) {
                continue;
            }
            if ($amountIrr < $this->nonNegativeInt($row->minimum_order_irr, 'Referral minimum order')) {
                continue;
            }
            if ($row->effective_from !== null && $settledAt < $this->storedDateTime($row->effective_from)) {
                continue;
            }
            if ($row->effective_until !== null && $settledAt >= $this->storedDateTime($row->effective_until)) {
                continue;
            }
            if ((bool) $row->first_purchase_only && $lockedSettlementId !== $settlementId) {
                continue;
            }
            if ($row->tier_code !== null || $row->customer_tag_id !== null) {
                continue;
            }
            if ($row->plan_offering_id !== null && (int) $row->plan_offering_id !== $planOfferingId) {
                continue;
            }
            if ($row->product_id !== null && (int) $row->product_id !== $productId) {
                continue;
            }
            if ($row->sales_server_id !== null && (int) $row->sales_server_id !== $salesServerId) {
                continue;
            }
            if ($row->action !== null && $row->action !== 'purchase') {
                continue;
            }
            if (! is_string($row->referral_source_code) || ! hash_equals($row->referral_source_code, $referralSourceCode)) {
                continue;
            }
            if (! hash_equals($row->configuration_hash, hash('sha256', $row->configuration_snapshot))) {
                throw new RuntimeException('Stored referral rule snapshot hash is invalid.');
            }
            if (! $this->capacityAvailable($connection, $row, $referredUserId, $relationshipId)) {
                continue;
            }
            $qualified[] = $row;
        }

        if ($qualified === []) {
            return null;
        }
        $highestPriority = max(array_map(static fn (object $row): int => (int) $row->priority, $qualified));
        $winners = array_values(array_filter($qualified, static fn (object $row): bool => (int) $row->priority === $highestPriority));
        if (count($winners) !== 1) {
            throw new RuntimeException('Referral reward rule resolution is ambiguous.');
        }

        return $winners[0];
    }

    private function capacityAvailable(Connection $connection, object $rule, int $referredUserId, int $relationshipId): bool
    {
        $versionId = $this->positiveInt($rule->id, 'Referral rule version ID');
        if ($rule->total_use_limit !== null
            && $connection->table('referral_reward_accruals')->where('pricing_rule_version_id', $versionId)->count() >= $this->positiveInt($rule->total_use_limit, 'Referral total limit')) {
            return false;
        }
        if ($rule->per_user_use_limit !== null
            && $connection->table('referral_reward_accruals')->where('pricing_rule_version_id', $versionId)->where('referred_user_id', $referredUserId)->count() >= $this->positiveInt($rule->per_user_use_limit, 'Referral per-user limit')) {
            return false;
        }
        $policy = ReferralRewardPolicy::fromConfigurationSnapshot($rule->configuration_snapshot);
        if ($policy?->perReferralUseLimit !== null
            && $connection->table('referral_reward_accruals')->where('pricing_rule_version_id', $versionId)->where('referral_relationship_id', $relationshipId)->count() >= $policy->perReferralUseLimit) {
            return false;
        }

        return true;
    }

    private function calculateReward(object $rule, int $amountIrr): int
    {
        $type = PromotionDiscountType::tryFrom($rule->discount_type)
            ?? throw new RuntimeException('Stored referral reward calculation type is invalid.');
        if ($type === PromotionDiscountType::Fixed) {
            $reward = $this->positiveInt($rule->fixed_discount_irr, 'Referral fixed reward');
        } else {
            $basisPoints = $this->positiveInt($rule->percentage_basis_points, 'Referral reward percentage');
            if ($basisPoints > 10000) {
                throw new RuntimeException('Stored referral reward percentage is invalid.');
            }
            $reward = intdiv($amountIrr, 10000) * $basisPoints
                + intdiv(($amountIrr % 10000) * $basisPoints, 10000);
        }
        if ($rule->maximum_discount_irr !== null) {
            $reward = min($reward, $this->positiveInt($rule->maximum_discount_irr, 'Referral reward cap'));
        }
        if ($reward < 1 || $reward > $amountIrr) {
            throw new DomainException('Referral reward amount is outside the accepted purchase boundary.');
        }

        return $reward;
    }

    /** @return list<array{0:string,1:int}> */
    private function recipientRows(ReferralRewardRecipient $recipient, int $inviterUserId, int $referredUserId): array
    {
        return match ($recipient) {
            ReferralRewardRecipient::Inviter => [['inviter', $inviterUserId]],
            ReferralRewardRecipient::Referred => [['referred', $referredUserId]],
            ReferralRewardRecipient::Both => [['inviter', $inviterUserId], ['referred', $referredUserId]],
        };
    }

    private function audienceAllows(string $audience, string $accountType): bool
    {
        return $audience === 'both'
            || ($audience === 'customers' && $accountType === 'customer')
            || ($audience === 'agents' && $accountType === 'agent');
    }

    private function accrualBySettlementPublicId(Connection $connection, string $publicId, bool $lock = false): ?object
    {
        $query = $connection->table('referral_reward_accruals')->where('purchase_settlement_public_id', $publicId);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first([
            'id', 'public_id', 'purchase_settlement_public_id', 'reward_amount_irr', 'release_at', 'expires_at',
        ]);
    }

    private function receipt(Connection $connection, object $row, bool $replayed): ReferralRewardAccrualReceipt
    {
        $rewardPublicIds = $connection->table('referral_rewards')
            ->where('accrual_id', $this->positiveInt($row->id, 'Referral reward accrual ID'))
            ->orderBy('recipient_role')
            ->pluck('public_id')
            ->map(static fn (mixed $value): string => (string) $value)
            ->all();

        return new ReferralRewardAccrualReceipt(
            $this->positiveInt($row->id, 'Referral reward accrual ID'),
            $row->public_id,
            $row->purchase_settlement_public_id,
            $this->positiveInt($row->reward_amount_irr, 'Referral reward amount'),
            $this->storedDateTime($row->release_at),
            $row->expires_at === null ? null : $this->storedDateTime($row->expires_at),
            $rewardPublicIds,
            $replayed,
        );
    }

    private function positiveInt(int|string|null $value, string $label): int
    {
        if ($value === null || ! is_numeric($value) || (int) $value < 1) {
            throw new RuntimeException($label.' is invalid.');
        }

        return (int) $value;
    }

    private function nonNegativeInt(int|string $value, string $label): int
    {
        if (! is_numeric($value) || (int) $value < 0) {
            throw new RuntimeException($label.' is invalid.');
        }

        return (int) $value;
    }

    private function storedDateTime(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if ($date === false) {
            throw new RuntimeException('Stored referral reward date is invalid.');
        }

        return $date;
    }

    private function databaseDateTime(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
