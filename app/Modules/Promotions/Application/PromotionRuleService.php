<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Application;

use App\Modules\AccessControl\Application\AccessChangeContext;
use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use App\Modules\Promotions\Domain\PromotionAction;
use App\Modules\Promotions\Domain\PromotionAudience;
use App\Modules\Promotions\Domain\PromotionDiscountType;
use App\Modules\Promotions\Domain\PromotionRuleDefinition;
use App\Modules\Promotions\Domain\PromotionRuleKind;
use App\Modules\Promotions\Domain\PromotionRuleState;
use App\Modules\Promotions\Domain\ReferralRewardPolicy;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * @phpstan-type RuleVersionRow object{
 *     id:int|string, pricing_rule_id:int|string, mutation_payload_hash:string, version:int|string,
 *     state:string, priority:int|string, discount_type:string, fixed_discount_irr:int|string|null,
 *     percentage_basis_points:int|string|null, minimum_order_irr:int|string, maximum_discount_irr:int|string|null,
 *     effective_from:string|null, effective_until:string|null, total_use_limit:int|string|null,
 *     per_user_use_limit:int|string|null, first_purchase_only:int|bool|string, audience:string,
 *     tier_code:string|null, customer_tag_id:int|string|null, plan_offering_id:int|string|null,
 *     product_id:int|string|null, sales_server_id:int|string|null, action:string|null,
 *     referral_source_code:string|null, allows_free_order:int|bool|string, configuration_snapshot:string,
 *     configuration_hash:string, rule_public_id:string, rule_code:string, kind:string
 * }
 * @phpstan-type ResolutionRow object{
 *     id:int|string, public_id:string, resolution_key:string, request_payload_hash:string,
 *     user_id:int|string, plan_offering_id:int|string, input_price_irr:int|string, discount_irr:int|string,
 *     rule_code_snapshot:string|null, rule_kind_snapshot:string|null, rule_version:int|string|null,
 *     rule_configuration_hash:string|null, configuration_snapshot_hash:string
 * }
 */
final readonly class PromotionRuleService
{
    private const MANAGE_PERMISSION = 'promotions.rules.manage';

    private const RESOLUTION_FORMULA_VERSION = 'pro-ref-resolution-v1';

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private AdministratorPermissionAuthorizer $authorizer,
    ) {}

    /** @requirement PRO-001 REF-001 DAT-002 DAT-003 SEC-001 SEC-002 QUA-001 */
    public function create(
        string $mutationKey,
        string $ruleCode,
        PromotionRuleKind $kind,
        PromotionRuleDefinition $definition,
        AccessChangeContext $context,
    ): PromotionRuleVersionReceipt {
        $this->assertMutationKey($mutationKey);
        $this->assertRuleCode($ruleCode);
        $reason = $context->requireReason();
        $this->authorizer->authorize($context->actorAdministratorId, self::MANAGE_PERMISSION);
        $this->assertKindShape($kind, $definition);
        $payloadHash = $this->mutationPayloadHash('create', $ruleCode, $kind, $definition, $context->actorAdministratorId);

        try {
            return $this->database->connection()->transaction(function (Connection $connection) use (
                $mutationKey,
                $ruleCode,
                $kind,
                $definition,
                $context,
                $reason,
                $payloadHash,
            ): PromotionRuleVersionReceipt {
                $existing = $this->versionByMutationKey($connection, $mutationKey, true);
                if ($existing !== null) {
                    return $this->versionReceipt($existing, $payloadHash, true);
                }
                if ($connection->table('pricing_rules')->where('rule_code', $ruleCode)->exists()) {
                    throw new DomainException('Promotion rule code already exists.');
                }

                $this->validateDefinitionReferences($connection, $definition);
                $createdAt = $this->timestamp();
                $ruleId = (int) $connection->table('pricing_rules')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'rule_code' => $ruleCode,
                    'kind' => $kind->value,
                    'created_at' => $createdAt,
                ]);
                $this->insertVersion($connection, $ruleId, $mutationKey, $payloadHash, 1, $ruleCode, $kind, $definition, $context, $reason, $createdAt);

                $created = $this->versionByMutationKey($connection, $mutationKey);
                if ($created === null) {
                    throw new RuntimeException('Promotion rule version persistence failed.');
                }

                return $this->versionReceipt($created, $payloadHash, false);
            });
        } catch (QueryException $exception) {
            $existing = $this->versionByMutationKey($this->database->connection(), $mutationKey);
            if ($existing !== null) {
                return $this->versionReceipt($existing, $payloadHash, true);
            }

            throw $exception;
        }
    }

    /** @requirement PRO-001 REF-001 DAT-002 DAT-003 SEC-001 SEC-002 QUA-001 */
    public function revise(
        string $mutationKey,
        string $ruleCode,
        PromotionRuleDefinition $definition,
        AccessChangeContext $context,
    ): PromotionRuleVersionReceipt {
        $this->assertMutationKey($mutationKey);
        $this->assertRuleCode($ruleCode);
        $reason = $context->requireReason();
        $this->authorizer->authorize($context->actorAdministratorId, self::MANAGE_PERMISSION);

        $kindValue = $this->database->connection()->table('pricing_rules')->where('rule_code', $ruleCode)->value('kind');
        $kind = is_string($kindValue) ? PromotionRuleKind::tryFrom($kindValue) : null;
        if ($kind === null) {
            throw new DomainException('Promotion rule does not exist or has invalid identity.');
        }
        $this->assertKindShape($kind, $definition);
        $payloadHash = $this->mutationPayloadHash('revise', $ruleCode, $kind, $definition, $context->actorAdministratorId);

        try {
            return $this->database->connection()->transaction(function (Connection $connection) use (
                $mutationKey,
                $ruleCode,
                $kind,
                $definition,
                $context,
                $reason,
                $payloadHash,
            ): PromotionRuleVersionReceipt {
                $existing = $this->versionByMutationKey($connection, $mutationKey, true);
                if ($existing !== null) {
                    return $this->versionReceipt($existing, $payloadHash, true);
                }

                /** @var object{id:int|string,kind:string}|null $rule */
                $rule = $connection->table('pricing_rules')->where('rule_code', $ruleCode)->lockForUpdate()->first(['id', 'kind']);
                if ($rule === null || ! hash_equals($rule->kind, $kind->value)) {
                    throw new DomainException('Promotion rule does not exist or has invalid identity.');
                }

                /** @var object{version:int|string,state:string}|null $latest */
                $latest = $connection->table('pricing_rule_versions')
                    ->where('pricing_rule_id', (int) $rule->id)
                    ->orderByDesc('version')
                    ->lockForUpdate()
                    ->first(['version', 'state']);
                if ($latest === null) {
                    throw new RuntimeException('Promotion rule has no stored configuration.');
                }
                $latestState = PromotionRuleState::tryFrom($latest->state);
                if ($latestState === null) {
                    throw new RuntimeException('Stored promotion rule state is invalid.');
                }
                if ($latestState === PromotionRuleState::Archived) {
                    throw new DomainException('Archived promotion rule cannot be revised.');
                }

                $this->validateDefinitionReferences($connection, $definition);
                $version = $this->positiveDatabaseInt($latest->version, 'Promotion rule version') + 1;
                $createdAt = $this->timestamp();
                $this->insertVersion(
                    $connection,
                    (int) $rule->id,
                    $mutationKey,
                    $payloadHash,
                    $version,
                    $ruleCode,
                    $kind,
                    $definition,
                    $context,
                    $reason,
                    $createdAt,
                );

                $created = $this->versionByMutationKey($connection, $mutationKey);
                if ($created === null) {
                    throw new RuntimeException('Promotion rule revision persistence failed.');
                }

                return $this->versionReceipt($created, $payloadHash, false);
            });
        } catch (QueryException $exception) {
            $existing = $this->versionByMutationKey($this->database->connection(), $mutationKey);
            if ($existing !== null) {
                return $this->versionReceipt($existing, $payloadHash, true);
            }

            throw $exception;
        }
    }

    /** @requirement PRO-001 REF-001 BUY-002 DAT-002 DAT-003 SEC-001 SEC-002 QUA-001 */
    public function resolve(PromotionResolutionRequest $request, PromotionResolutionContext $context): PromotionResolutionReceipt
    {
        if ($context->actorUserId !== $request->userId) {
            throw new AuthorizationException('Promotion resolution actor is not authorized for this subject.');
        }
        $requestPayloadHash = $this->resolutionRequestHash($request);

        try {
            return $this->database->connection()->transaction(function (Connection $connection) use (
                $request,
                $requestPayloadHash,
            ): PromotionResolutionReceipt {
                $existing = $this->resolutionByKey($connection, $request->resolutionKey, true);
                if ($existing !== null) {
                    return $this->resolutionReceipt($existing, $requestPayloadHash, true);
                }

                /** @var object{account_type:string,account_status:string}|null $user */
                $user = $connection->table('users')->where('id', $request->userId)->lockForUpdate()->first(['account_type', 'account_status']);
                if ($user === null || $user->account_status !== 'active' || ! in_array($user->account_type, ['customer', 'agent'], true)) {
                    throw new DomainException('Promotion resolution requires an active customer or agent.');
                }

                /** @var object{id:int|string,product_id:int|string,sales_server_id:int|string,discount_eligible:int|bool|string}|null $offering */
                $offering = $connection->table('plan_offerings')
                    ->where('id', $request->planOfferingId)
                    ->lockForUpdate()
                    ->first(['id', 'product_id', 'sales_server_id', 'discount_eligible']);
                if ($offering === null) {
                    throw new DomainException('Promotion resolution offering does not exist.');
                }

                $selected = null;
                if ($request->inputPriceIrr > 0 && (bool) $offering->discount_eligible) {
                    $selected = $this->selectRule($connection, $request, $user->account_type, $offering);
                }

                $discountIrr = 0;
                $ruleId = null;
                $versionId = null;
                $ruleCode = null;
                $ruleKind = null;
                $ruleVersion = null;
                $ruleConfigurationHash = null;
                $snapshot = [
                    'formula_version' => self::RESOLUTION_FORMULA_VERSION,
                    'matched' => false,
                ];

                if ($selected !== null) {
                    $discountIrr = $this->calculateDiscount($selected, $request->inputPriceIrr);
                    $ruleId = $this->positiveDatabaseInt($selected->pricing_rule_id, 'Promotion rule ID');
                    $versionId = $this->positiveDatabaseInt($selected->id, 'Promotion rule version ID');
                    $ruleCode = $selected->rule_code;
                    $ruleKind = PromotionRuleKind::tryFrom($selected->kind)
                        ?? throw new RuntimeException('Stored promotion rule kind is invalid.');
                    $ruleVersion = $this->positiveDatabaseInt($selected->version, 'Promotion rule version');
                    $ruleConfigurationHash = $selected->configuration_hash;
                    /** @var array<string, mixed> $ruleConfiguration */
                    $ruleConfiguration = json_decode($selected->configuration_snapshot, true, flags: JSON_THROW_ON_ERROR);
                    $snapshot = [
                        'formula_version' => self::RESOLUTION_FORMULA_VERSION,
                        'matched' => true,
                        'rule_code' => $ruleCode,
                        'rule_configuration' => $ruleConfiguration,
                        'rule_configuration_hash' => $ruleConfigurationHash,
                        'rule_kind' => $ruleKind->value,
                        'rule_version' => $ruleVersion,
                    ];
                }

                ksort($snapshot, SORT_STRING);
                $snapshotJson = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                if (strlen($snapshotJson) > 8192) {
                    throw new RuntimeException('Promotion resolution snapshot exceeds the storage boundary.');
                }
                $snapshotHash = hash('sha256', $snapshotJson);
                $createdAt = $this->timestamp();
                $resolutionId = (int) $connection->table('pricing_rule_resolutions')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'resolution_key' => $request->resolutionKey,
                    'request_payload_hash' => $requestPayloadHash,
                    'user_id' => $request->userId,
                    'plan_offering_id' => $request->planOfferingId,
                    'action' => $request->action->value,
                    'input_price_irr' => $request->inputPriceIrr,
                    'observed_total_uses' => $request->observedTotalUses,
                    'observed_user_uses' => $request->observedUserUses,
                    'had_prior_successful_purchase' => $request->hasPriorSuccessfulPurchase,
                    'referral_source_code' => $request->referralSourceCode,
                    'pricing_rule_id' => $ruleId,
                    'pricing_rule_version_id' => $versionId,
                    'rule_code_snapshot' => $ruleCode,
                    'rule_kind_snapshot' => $ruleKind?->value,
                    'rule_version' => $ruleVersion,
                    'rule_configuration_hash' => $ruleConfigurationHash,
                    'discount_irr' => $discountIrr,
                    'configuration_snapshot' => $snapshotJson,
                    'configuration_snapshot_hash' => $snapshotHash,
                    'created_at' => $createdAt,
                ]);

                $created = $this->resolutionById($connection, $resolutionId);
                if ($created === null) {
                    throw new RuntimeException('Promotion resolution persistence failed.');
                }

                return $this->resolutionReceipt($created, $requestPayloadHash, false);
            });
        } catch (QueryException $exception) {
            $existing = $this->resolutionByKey($this->database->connection(), $request->resolutionKey);
            if ($existing !== null) {
                return $this->resolutionReceipt($existing, $requestPayloadHash, true);
            }

            throw $exception;
        }
    }

    private function insertVersion(
        Connection $connection,
        int $ruleId,
        string $mutationKey,
        string $payloadHash,
        int $version,
        string $ruleCode,
        PromotionRuleKind $kind,
        PromotionRuleDefinition $definition,
        AccessChangeContext $context,
        string $reason,
        string $createdAt,
    ): void {
        $snapshot = $this->configurationSnapshot($ruleCode, $kind, $definition);
        $snapshotJson = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($snapshotJson) > 8192) {
            throw new DomainException('Promotion rule configuration exceeds the storage boundary.');
        }

        $connection->table('pricing_rule_versions')->insert([
            'pricing_rule_id' => $ruleId,
            'mutation_key' => $mutationKey,
            'mutation_payload_hash' => $payloadHash,
            'version' => $version,
            'state' => $definition->state->value,
            'priority' => $definition->priority,
            'discount_type' => $definition->discountType->value,
            'fixed_discount_irr' => $definition->fixedDiscountIrr,
            'percentage_basis_points' => $definition->percentageBasisPoints,
            'minimum_order_irr' => $definition->minimumOrderIrr,
            'maximum_discount_irr' => $definition->maximumDiscountIrr,
            'effective_from' => $this->databaseDateTime($definition->effectiveFrom),
            'effective_until' => $this->databaseDateTime($definition->effectiveUntil),
            'total_use_limit' => $definition->totalUseLimit,
            'per_user_use_limit' => $definition->perUserUseLimit,
            'first_purchase_only' => $definition->firstPurchaseOnly,
            'audience' => $definition->audience->value,
            'tier_code' => $definition->tierCode,
            'customer_tag_id' => $definition->customerTagId,
            'plan_offering_id' => $definition->planOfferingId,
            'product_id' => $definition->productId,
            'sales_server_id' => $definition->salesServerId,
            'action' => $definition->action?->value,
            'referral_source_code' => $definition->referralSourceCode,
            'allows_free_order' => $definition->allowsFreeOrder,
            'configuration_snapshot' => $snapshotJson,
            'configuration_hash' => hash('sha256', $snapshotJson),
            'actor_administrator_id' => $context->actorAdministratorId,
            'reason_code' => $context->reasonCode,
            'reason' => $reason,
            'correlation_id' => $context->correlationId,
            'created_at' => $createdAt,
        ]);
    }

    /** @return RuleVersionRow|null */
    private function versionByMutationKey(Connection $connection, string $mutationKey, bool $lock = false): ?object
    {
        $query = $connection->table('pricing_rule_versions as v')
            ->join('pricing_rules as r', 'r.id', '=', 'v.pricing_rule_id')
            ->where('v.mutation_key', $mutationKey);
        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var RuleVersionRow|null $row */
        $row = $query->first($this->versionColumns());

        return $row;
    }

    /** @return list<string> */
    private function versionColumns(): array
    {
        return [
            'v.id', 'v.pricing_rule_id', 'v.mutation_payload_hash', 'v.version', 'v.state', 'v.priority',
            'v.discount_type', 'v.fixed_discount_irr', 'v.percentage_basis_points', 'v.minimum_order_irr',
            'v.maximum_discount_irr', 'v.effective_from', 'v.effective_until', 'v.total_use_limit',
            'v.per_user_use_limit', 'v.first_purchase_only', 'v.audience', 'v.tier_code', 'v.customer_tag_id',
            'v.plan_offering_id', 'v.product_id', 'v.sales_server_id', 'v.action', 'v.referral_source_code',
            'v.allows_free_order', 'v.configuration_snapshot', 'v.configuration_hash',
            'r.public_id as rule_public_id', 'r.rule_code', 'r.kind',
        ];
    }

    /** @param RuleVersionRow $row */
    private function versionReceipt(object $row, string $expectedPayloadHash, bool $replayed): PromotionRuleVersionReceipt
    {
        if (! hash_equals($row->mutation_payload_hash, $expectedPayloadHash)) {
            throw new RuntimeException('Promotion mutation key conflict.');
        }
        $this->assertStoredConfiguration($row);
        $kind = PromotionRuleKind::tryFrom($row->kind) ?? throw new RuntimeException('Stored promotion rule kind is invalid.');
        $state = PromotionRuleState::tryFrom($row->state) ?? throw new RuntimeException('Stored promotion rule state is invalid.');

        return new PromotionRuleVersionReceipt(
            $this->positiveDatabaseInt($row->pricing_rule_id, 'Promotion rule ID'),
            $row->rule_public_id,
            $row->rule_code,
            $kind,
            $this->positiveDatabaseInt($row->id, 'Promotion rule version ID'),
            $this->positiveDatabaseInt($row->version, 'Promotion rule version'),
            $state,
            $row->configuration_hash,
            $replayed,
        );
    }

    /**
     * @param  object{product_id:int|string,sales_server_id:int|string,discount_eligible:int|bool|string}  $offering
     * @return RuleVersionRow|null
     */
    private function selectRule(Connection $connection, PromotionResolutionRequest $request, string $accountType, object $offering): ?object
    {
        /** @var array<int, RuleVersionRow> $latestByRule */
        $latestByRule = [];
        /** @var list<RuleVersionRow> $versions */
        $versions = $connection->table('pricing_rule_versions as v')
            ->join('pricing_rules as r', 'r.id', '=', 'v.pricing_rule_id')
            ->get($this->versionColumns())
            ->all();
        foreach ($versions as $row) {
            $ruleId = $this->positiveDatabaseInt($row->pricing_rule_id, 'Promotion rule ID');
            $version = $this->positiveDatabaseInt($row->version, 'Promotion rule version');
            if (! isset($latestByRule[$ruleId]) || $version > (int) $latestByRule[$ruleId]->version) {
                $latestByRule[$ruleId] = $row;
            }
        }

        $tierCode = $connection->table('customer_profiles as p')
            ->join('customer_tiers as t', 't.id', '=', 'p.current_tier_id')
            ->where('p.user_id', $request->userId)
            ->where('t.is_active', true)
            ->value('t.code');
        $tierCode = is_string($tierCode) ? $tierCode : null;
        /** @var list<int> $tagIds */
        $tagIds = $connection->table('customer_tag_assignments as a')
            ->join('customer_tags as t', 't.id', '=', 'a.tag_id')
            ->where('a.user_id', $request->userId)
            ->whereNull('a.removed_at')
            ->where('t.is_active', true)
            ->pluck('a.tag_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        /** @var list<RuleVersionRow> $qualified */
        $qualified = [];
        foreach ($latestByRule as $row) {
            $this->assertStoredConfiguration($row);
            $state = PromotionRuleState::tryFrom($row->state) ?? throw new RuntimeException('Stored promotion rule state is invalid.');
            if ($state !== PromotionRuleState::Active) {
                continue;
            }
            $audience = PromotionAudience::tryFrom($row->audience) ?? throw new RuntimeException('Stored promotion audience is invalid.');
            if (! $audience->allows($accountType)) {
                continue;
            }
            if ($request->inputPriceIrr < $this->nonNegativeDatabaseInt($row->minimum_order_irr, 'Promotion minimum order')) {
                continue;
            }
            if ($row->effective_from !== null && $now < $this->storedDateTime($row->effective_from)) {
                continue;
            }
            if ($row->effective_until !== null && $now >= $this->storedDateTime($row->effective_until)) {
                continue;
            }
            if ($row->total_use_limit !== null && $request->observedTotalUses >= $this->positiveDatabaseInt($row->total_use_limit, 'Promotion total use limit')) {
                continue;
            }
            if ($row->per_user_use_limit !== null && $request->observedUserUses >= $this->positiveDatabaseInt($row->per_user_use_limit, 'Promotion per-user use limit')) {
                continue;
            }
            if ((bool) $row->first_purchase_only && $request->hasPriorSuccessfulPurchase) {
                continue;
            }
            if ($row->tier_code !== null && ! hash_equals($row->tier_code, $tierCode ?? '')) {
                continue;
            }
            if ($row->customer_tag_id !== null && ! in_array((int) $row->customer_tag_id, $tagIds, true)) {
                continue;
            }
            if ($row->plan_offering_id !== null && (int) $row->plan_offering_id !== $request->planOfferingId) {
                continue;
            }
            if ($row->product_id !== null && (int) $row->product_id !== (int) $offering->product_id) {
                continue;
            }
            if ($row->sales_server_id !== null && (int) $row->sales_server_id !== (int) $offering->sales_server_id) {
                continue;
            }
            if ($row->action !== null && ! hash_equals($row->action, $request->action->value)) {
                continue;
            }

            $kind = PromotionRuleKind::tryFrom($row->kind) ?? throw new RuntimeException('Stored promotion rule kind is invalid.');
            if ($kind === PromotionRuleKind::Referral) {
                if ($request->referralSourceCode === null || ! hash_equals((string) $row->referral_source_code, $request->referralSourceCode)) {
                    continue;
                }
            } elseif ($request->referralSourceCode !== null && $row->referral_source_code !== null) {
                throw new RuntimeException('Stored promotion referral shape is invalid.');
            }

            $qualified[] = $row;
        }

        if ($qualified === []) {
            return null;
        }
        $highestPriority = max(array_map(static fn (object $row): int => (int) $row->priority, $qualified));
        $winners = array_values(array_filter($qualified, static fn (object $row): bool => (int) $row->priority === $highestPriority));
        if (count($winners) !== 1) {
            throw new RuntimeException('Promotion rule resolution is ambiguous.');
        }

        return $winners[0];
    }

    /** @param RuleVersionRow $row */
    private function calculateDiscount(object $row, int $inputPriceIrr): int
    {
        $type = PromotionDiscountType::tryFrom($row->discount_type)
            ?? throw new RuntimeException('Stored promotion discount type is invalid.');
        if ($type === PromotionDiscountType::Fixed) {
            $discount = $this->positiveDatabaseInt($row->fixed_discount_irr, 'Promotion fixed discount');
        } else {
            $basisPoints = $this->positiveDatabaseInt($row->percentage_basis_points, 'Promotion percentage');
            if ($basisPoints > 10000) {
                throw new RuntimeException('Stored promotion percentage is invalid.');
            }
            $discount = intdiv($inputPriceIrr, 10000) * $basisPoints
                + intdiv(($inputPriceIrr % 10000) * $basisPoints, 10000);
        }

        if ($row->maximum_discount_irr !== null) {
            $discount = min($discount, $this->positiveDatabaseInt($row->maximum_discount_irr, 'Promotion maximum discount'));
        }
        if ($discount >= $inputPriceIrr && $inputPriceIrr > 0) {
            if (! (bool) $row->allows_free_order) {
                throw new DomainException('Promotion rule would make the price fully free without explicit permission.');
            }
            $discount = $inputPriceIrr;
        }

        return $discount;
    }

    private function validateDefinitionReferences(Connection $connection, PromotionRuleDefinition $definition): void
    {
        if ($definition->tierCode !== null && ! $connection->table('customer_tiers')->where('code', $definition->tierCode)->where('is_active', true)->exists()) {
            throw new DomainException('Promotion rule tier does not exist or is inactive.');
        }
        if ($definition->customerTagId !== null && ! $connection->table('customer_tags')->where('id', $definition->customerTagId)->where('is_active', true)->exists()) {
            throw new DomainException('Promotion rule customer tag does not exist or is inactive.');
        }
        if ($definition->productId !== null && ! $connection->table('products')->where('id', $definition->productId)->exists()) {
            throw new DomainException('Promotion rule product does not exist.');
        }
        if ($definition->salesServerId !== null && ! $connection->table('sales_servers')->where('id', $definition->salesServerId)->exists()) {
            throw new DomainException('Promotion rule sales server does not exist.');
        }
        if ($definition->planOfferingId !== null) {
            /** @var object{product_id:int|string,sales_server_id:int|string}|null $offering */
            $offering = $connection->table('plan_offerings')->where('id', $definition->planOfferingId)->first(['product_id', 'sales_server_id']);
            if ($offering === null) {
                throw new DomainException('Promotion rule plan offering does not exist.');
            }
            if ($definition->productId !== null && $definition->productId !== (int) $offering->product_id) {
                throw new DomainException('Promotion rule offering and product scope conflict.');
            }
            if ($definition->salesServerId !== null && $definition->salesServerId !== (int) $offering->sales_server_id) {
                throw new DomainException('Promotion rule offering and sales-server scope conflict.');
            }
        }
    }

    private function assertKindShape(PromotionRuleKind $kind, PromotionRuleDefinition $definition): void
    {
        if ($kind === PromotionRuleKind::Referral && $definition->referralSourceCode === null) {
            throw new DomainException('Referral pricing rule requires a referral source identity.');
        }
        if ($kind === PromotionRuleKind::Promotion && $definition->referralSourceCode !== null) {
            throw new DomainException('Promotion pricing rule cannot contain referral source identity.');
        }
        if ($kind !== PromotionRuleKind::Referral && $definition->referralRewardRecipient !== null) {
            throw new DomainException('Promotion pricing rule cannot contain referral reward policy.');
        }
    }

    /** @param RuleVersionRow $row */
    private function assertStoredConfiguration(object $row): void
    {
        $kind = PromotionRuleKind::tryFrom($row->kind) ?? throw new RuntimeException('Stored promotion rule kind is invalid.');
        $state = PromotionRuleState::tryFrom($row->state) ?? throw new RuntimeException('Stored promotion rule state is invalid.');
        $discountType = PromotionDiscountType::tryFrom($row->discount_type) ?? throw new RuntimeException('Stored promotion discount type is invalid.');
        $audience = PromotionAudience::tryFrom($row->audience) ?? throw new RuntimeException('Stored promotion audience is invalid.');
        $action = $row->action === null ? null : PromotionAction::tryFrom($row->action);
        if ($row->action !== null && $action === null) {
            throw new RuntimeException('Stored promotion action is invalid.');
        }
        $rewardPolicy = ReferralRewardPolicy::fromConfigurationSnapshot($row->configuration_snapshot);

        try {
            $definition = new PromotionRuleDefinition(
                $state,
                (int) $row->priority,
                $discountType,
                $row->fixed_discount_irr === null ? null : (int) $row->fixed_discount_irr,
                $row->percentage_basis_points === null ? null : (int) $row->percentage_basis_points,
                (int) $row->minimum_order_irr,
                $row->maximum_discount_irr === null ? null : (int) $row->maximum_discount_irr,
                $row->effective_from === null ? null : $this->storedDateTime($row->effective_from),
                $row->effective_until === null ? null : $this->storedDateTime($row->effective_until),
                $row->total_use_limit === null ? null : (int) $row->total_use_limit,
                $row->per_user_use_limit === null ? null : (int) $row->per_user_use_limit,
                (bool) $row->first_purchase_only,
                $audience,
                $row->tier_code,
                $row->customer_tag_id === null ? null : (int) $row->customer_tag_id,
                $row->plan_offering_id === null ? null : (int) $row->plan_offering_id,
                $row->product_id === null ? null : (int) $row->product_id,
                $row->sales_server_id === null ? null : (int) $row->sales_server_id,
                $action,
                $row->referral_source_code,
                (bool) $row->allows_free_order,
                $rewardPolicy?->recipient,
                $rewardPolicy?->pendingHours,
                $rewardPolicy?->expiryHours,
                $rewardPolicy?->transferable,
                $rewardPolicy?->perReferralUseLimit,
            );
        } catch (\InvalidArgumentException $exception) {
            throw new RuntimeException('Stored promotion rule configuration is invalid.', previous: $exception);
        }
        $this->assertKindShape($kind, $definition);
        $snapshotJson = json_encode($this->configurationSnapshot($row->rule_code, $kind, $definition), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (! hash_equals($row->configuration_hash, hash('sha256', $snapshotJson))) {
            throw new RuntimeException('Stored promotion rule configuration identity is invalid.');
        }
        if (! hash_equals($row->configuration_hash, hash('sha256', $row->configuration_snapshot))) {
            throw new RuntimeException('Stored promotion rule snapshot hash is invalid.');
        }
    }

    /** @return array<string, bool|int|string|null> */
    private function configurationSnapshot(string $ruleCode, PromotionRuleKind $kind, PromotionRuleDefinition $definition): array
    {
        $snapshot = $definition->snapshot();
        $snapshot['kind'] = $kind->value;
        $snapshot['rule_code'] = $ruleCode;
        ksort($snapshot, SORT_STRING);

        return $snapshot;
    }

    private function mutationPayloadHash(
        string $operation,
        string $ruleCode,
        PromotionRuleKind $kind,
        PromotionRuleDefinition $definition,
        int $administratorId,
    ): string {
        return hash('sha256', json_encode([
            'administrator_id' => $administratorId,
            'configuration' => $this->configurationSnapshot($ruleCode, $kind, $definition),
            'operation' => $operation,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function resolutionRequestHash(PromotionResolutionRequest $request): string
    {
        return hash('sha256', json_encode([
            'action' => $request->action->value,
            'had_prior_successful_purchase' => $request->hasPriorSuccessfulPurchase,
            'input_price_irr' => $request->inputPriceIrr,
            'observed_total_uses' => $request->observedTotalUses,
            'observed_user_uses' => $request->observedUserUses,
            'plan_offering_id' => $request->planOfferingId,
            'referral_source_code' => $request->referralSourceCode,
            'user_id' => $request->userId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @return ResolutionRow|null */
    private function resolutionByKey(Connection $connection, string $key, bool $lock = false): ?object
    {
        $query = $connection->table('pricing_rule_resolutions')->where('resolution_key', $key);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var ResolutionRow|null $row */
        $row = $query->first($this->resolutionColumns());

        return $row;
    }

    /** @return ResolutionRow|null */
    private function resolutionById(Connection $connection, int $id): ?object
    {
        /** @var ResolutionRow|null $row */
        $row = $connection->table('pricing_rule_resolutions')->where('id', $id)->first($this->resolutionColumns());

        return $row;
    }

    /** @return list<string> */
    private function resolutionColumns(): array
    {
        return [
            'id', 'public_id', 'resolution_key', 'request_payload_hash', 'user_id', 'plan_offering_id',
            'input_price_irr', 'discount_irr', 'rule_code_snapshot', 'rule_kind_snapshot', 'rule_version',
            'rule_configuration_hash', 'configuration_snapshot_hash',
        ];
    }

    /** @param ResolutionRow $row */
    private function resolutionReceipt(object $row, string $expectedRequestHash, bool $replayed): PromotionResolutionReceipt
    {
        if (! hash_equals($row->request_payload_hash, $expectedRequestHash)) {
            throw new RuntimeException('Promotion resolution key conflict.');
        }
        $kind = $row->rule_kind_snapshot === null ? null : PromotionRuleKind::tryFrom($row->rule_kind_snapshot);
        if ($row->rule_kind_snapshot !== null && $kind === null) {
            throw new RuntimeException('Stored promotion resolution kind is invalid.');
        }

        return new PromotionResolutionReceipt(
            $this->positiveDatabaseInt($row->id, 'Promotion resolution ID'),
            $row->public_id,
            $row->resolution_key,
            $this->positiveDatabaseInt($row->user_id, 'Promotion resolution user ID'),
            $this->positiveDatabaseInt($row->plan_offering_id, 'Promotion resolution offering ID'),
            $this->nonNegativeDatabaseInt($row->input_price_irr, 'Promotion resolution input price'),
            $this->nonNegativeDatabaseInt($row->discount_irr, 'Promotion resolution discount'),
            $row->rule_code_snapshot,
            $kind,
            $row->rule_version === null ? null : $this->positiveDatabaseInt($row->rule_version, 'Promotion resolution rule version'),
            $row->rule_configuration_hash,
            $row->configuration_snapshot_hash,
            $replayed,
        );
    }

    private function assertMutationKey(string $value): void
    {
        if (preg_match('/\A[A-Za-z0-9:_.-]{8,128}\z/', $value) !== 1) {
            throw new DomainException('Promotion mutation key is invalid.');
        }
    }

    private function assertRuleCode(string $value): void
    {
        if (preg_match('/\A[A-Za-z0-9:_.-]{3,128}\z/', $value) !== 1) {
            throw new DomainException('Promotion rule code is invalid.');
        }
    }

    private function positiveDatabaseInt(int|string|null $value, string $label): int
    {
        if ($value === null || ! is_numeric($value)) {
            throw new RuntimeException($label.' is invalid.');
        }
        $int = (int) $value;
        if ($int < 1) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $int;
    }

    private function nonNegativeDatabaseInt(int|string $value, string $label): int
    {
        if (! is_numeric($value)) {
            throw new RuntimeException($label.' is invalid.');
        }
        $int = (int) $value;
        if ($int < 0) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $int;
    }

    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function databaseDateTime(?DateTimeImmutable $value): ?string
    {
        return $value?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function storedDateTime(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if ($date === false) {
            throw new RuntimeException('Stored promotion date is invalid.');
        }

        return $date;
    }
}
