<?php

declare(strict_types=1);

namespace App\Modules\Payments\Eligibility\Application;

use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use App\Modules\Payments\Eligibility\Domain\PaymentEligibilityRuleDefinition;
use App\Modules\Payments\Eligibility\Domain\PaymentEligibilityRuleEffect;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * @phpstan-type MethodRow object{id:int|string,method_code:string,version:int|string,enabled:bool|int|string,maintenance:bool|int|string,display_priority:int|string,configuration_snapshot_hash:string,request_payload_hash:string}
 * @phpstan-type RuleRow object{id:int|string,method_code:string,rule_code:string,version:int|string,enabled:bool|int|string,effect:string,priority:int|string,subject_user_id:int|string|null,account_types:string,tier_codes:string,minimum_amount_irr:int|string|null,maximum_amount_irr:int|string|null,offering_codes:string,product_ids:string,sales_server_ids:string,required_identity_status:string|null,required_agent_status:string|null,starts_at_utc:string|null,ends_at_utc:string|null,requires_contact_otp_provenance:bool|int|string,requires_purchase_history:bool|int|string,requires_daily_payment_limit:bool|int|string,configuration_snapshot_hash:string,request_payload_hash:string}
 * @phpstan-type HealthRow object{id:int|string,method_code:string,healthy:bool|int|string,observed_at:string,expires_at:string,configuration_snapshot_hash:string,request_payload_hash:string}
 * @phpstan-type Facts array{account_status:string,account_type:string,action:string,agent_status:?string,amount_irr:int,currency:string,identity_status:string,offering_code:string,product_id:int,quote_configuration_snapshot_hash:string,quote_id:int,sales_server_id:int,source_quote_public_id:string,tag_codes:list<string>,tier_code:?string,user_id:int}
 * @phpstan-type HealthSnapshot array{observation_id:?int,configuration_snapshot_hash:?string,healthy:?bool,observed_at:?string,expires_at:?string}
 * @phpstan-type EvaluationSnapshot array{health:HealthSnapshot,outcome:string}&array<string,mixed>
 * @phpstan-type Candidate array{configuration_snapshot_hash:string,evaluation_snapshot:EvaluationSnapshot,method_code:string,method_version_id:int,method_version:int,outcome:string,route_order:?int}
 * @phpstan-type RuleReceiptRow object{id:int|string,method_code:string,rule_code:string,version:int|string,configuration_snapshot_hash:string,request_payload_hash:string}
 * @phpstan-type HealthReceiptRow object{id:int|string,method_code:string,healthy:bool|int|string,observed_at:string,expires_at:string,request_payload_hash:string}
 * @phpstan-type DecisionRow object{id:int|string,public_id:string,decision_key:string,source_quote_public_id:string,user_id:int|string,configuration_snapshot_hash:string,request_payload_hash:string}
 * @phpstan-type DecisionMethodRow object{method_code:string,method_version:int|string,route_order:int|string,reason_code:string}
 */
final readonly class PaymentMethodEligibilityService
{
    public const MANAGE_PERMISSION = 'payment_providers.manage';

    public function __construct(
        private DatabaseManager $database,
        private AdministratorPermissionAuthorizer $authorizer,
        private Clock $clock,
    ) {}

    /** @requirement PAY-001 ACL-002 DAT-003 DAT-004 SEC-002 QUA-001 */
    public function configureMethod(
        string $mutationKey,
        int $administratorId,
        string $methodCode,
        bool $enabled,
        bool $maintenance,
        int $displayPriority,
        string $reason,
        string $correlationId,
    ): PaymentMethodVersionReceipt {
        $this->assertKey($mutationKey, 'Payment method mutation key');
        $this->assertCode($methodCode, 'Payment method code');
        if ($displayPriority < 0 || $displayPriority > 100_000) {
            throw new InvalidArgumentException('Payment method display priority is invalid.');
        }
        $this->assertChangeMetadata($reason, $correlationId);
        $requestHash = $this->hash([
            'display_priority' => $displayPriority,
            'enabled' => $enabled,
            'maintenance' => $maintenance,
            'method_code' => $methodCode,
            'reason' => $reason,
        ]);

        return $this->database->connection()->transaction(function (Connection $connection) use ($mutationKey, $administratorId, $methodCode, $enabled, $maintenance, $displayPriority, $reason, $correlationId, $requestHash): PaymentMethodVersionReceipt {
            $this->assertActiveAuthorizedAdministrator($connection, $administratorId);
            /** @var MethodRow|null $existing */
            $existing = $connection->table('payment_method_versions')->where('mutation_key', $mutationKey)->lockForUpdate()->first([
                'id', 'method_code', 'version', 'enabled', 'maintenance', 'display_priority',
                'configuration_snapshot_hash', 'request_payload_hash',
            ]);
            if ($existing !== null) {
                if (! hash_equals((string) $existing->request_payload_hash, $requestHash)) {
                    throw new RuntimeException('Payment method mutation key conflict.');
                }

                return $this->methodReceipt($existing, true);
            }

            /** @var object{version:int|string}|null $latest */
            $latest = $connection->table('payment_method_versions')
                ->where('method_code', $methodCode)
                ->orderByDesc('version')
                ->lockForUpdate()
                ->first(['version']);
            $version = $latest === null ? 1 : ((int) $latest->version + 1);
            $configuration = [
                'display_priority' => $displayPriority,
                'enabled' => $enabled,
                'formula_version' => 'pay-001-method-v2',
                'maintenance' => $maintenance,
                'method_code' => $methodCode,
                'version' => $version,
            ];
            $snapshot = $this->json($configuration);
            $now = $this->databaseDateTime($this->clock->now());

            $id = (int) $connection->table('payment_method_versions')->insertGetId([
                'method_code' => $methodCode,
                'version' => $version,
                'enabled' => $enabled,
                'maintenance' => $maintenance,
                'display_priority' => $displayPriority,
                'mutation_key' => $mutationKey,
                'request_payload_hash' => $requestHash,
                'configuration_snapshot' => $snapshot,
                'configuration_snapshot_hash' => hash('sha256', $snapshot),
                'changed_by_administrator_id' => $administratorId,
                'change_reason' => $reason,
                'correlation_id' => $correlationId,
                'created_at' => $now,
            ]);
            /** @var MethodRow|null $created */
            $created = $connection->table('payment_method_versions')->where('id', $id)->first([
                'id', 'method_code', 'version', 'enabled', 'maintenance', 'display_priority',
                'configuration_snapshot_hash', 'request_payload_hash',
            ]);
            if ($created === null) {
                throw new RuntimeException('Payment method configuration could not be loaded.');
            }

            return $this->methodReceipt($created, false);
        });
    }

    /** @requirement PAY-001 ACL-002 DAT-003 DAT-004 SEC-002 QUA-001 */
    public function configureRule(
        string $mutationKey,
        int $administratorId,
        PaymentEligibilityRuleDefinition $definition,
        string $reason,
        string $correlationId,
    ): PaymentEligibilityRuleVersionReceipt {
        $this->assertKey($mutationKey, 'Payment eligibility rule mutation key');
        $this->assertChangeMetadata($reason, $correlationId);
        $configuration = $definition->configuration();
        $requestHash = $this->hash($configuration + ['reason' => $reason]);

        return $this->database->connection()->transaction(function (Connection $connection) use ($mutationKey, $administratorId, $definition, $reason, $correlationId, $configuration, $requestHash): PaymentEligibilityRuleVersionReceipt {
            $this->assertActiveAuthorizedAdministrator($connection, $administratorId);
            /** @var RuleReceiptRow|null $existing */
            $existing = $connection->table('payment_method_rule_versions')->where('mutation_key', $mutationKey)->lockForUpdate()->first([
                'id', 'method_code', 'rule_code', 'version', 'configuration_snapshot_hash', 'request_payload_hash',
            ]);
            if ($existing !== null) {
                if (! hash_equals((string) $existing->request_payload_hash, $requestHash)) {
                    throw new RuntimeException('Payment eligibility rule mutation key conflict.');
                }

                return $this->ruleReceipt($existing, true);
            }

            $methodExists = $connection->table('payment_method_versions')->where('method_code', $definition->methodCode)->lockForUpdate()->exists();
            if (! $methodExists) {
                throw new RuntimeException('Payment eligibility rule requires a configured payment method.');
            }

            /** @var object{version:int|string}|null $latest */
            $latest = $connection->table('payment_method_rule_versions')
                ->where('method_code', $definition->methodCode)
                ->where('rule_code', $definition->ruleCode)
                ->orderByDesc('version')
                ->lockForUpdate()
                ->first(['version']);
            $version = $latest === null ? 1 : ((int) $latest->version + 1);
            $configuration['version'] = $version;
            ksort($configuration, SORT_STRING);
            $snapshot = $this->json($configuration);
            $now = $this->databaseDateTime($this->clock->now());

            $id = (int) $connection->table('payment_method_rule_versions')->insertGetId([
                'method_code' => $definition->methodCode,
                'rule_code' => $definition->ruleCode,
                'version' => $version,
                'enabled' => $definition->enabled,
                'effect' => $definition->effect->value,
                'priority' => $definition->priority,
                'subject_user_id' => $definition->subjectUserId,
                'account_types' => $this->json($configuration['account_types']),
                'tier_codes' => $this->json($configuration['tier_codes']),
                'minimum_amount_irr' => $definition->minimumAmountIrr,
                'maximum_amount_irr' => $definition->maximumAmountIrr,
                'offering_codes' => $this->json($configuration['offering_codes']),
                'product_ids' => $this->json($configuration['product_ids']),
                'sales_server_ids' => $this->json($configuration['sales_server_ids']),
                'required_identity_status' => $definition->requiredIdentityStatus,
                'required_agent_status' => $definition->requiredAgentStatus,
                'starts_at_utc' => $definition->startsAtUtc,
                'ends_at_utc' => $definition->endsAtUtc,
                'requires_contact_otp_provenance' => $definition->requiresContactOtpProvenance,
                'requires_purchase_history' => $definition->requiresPurchaseHistory,
                'requires_daily_payment_limit' => $definition->requiresDailyPaymentLimit,
                'mutation_key' => $mutationKey,
                'request_payload_hash' => $requestHash,
                'configuration_snapshot' => $snapshot,
                'configuration_snapshot_hash' => hash('sha256', $snapshot),
                'changed_by_administrator_id' => $administratorId,
                'change_reason' => $reason,
                'correlation_id' => $correlationId,
                'created_at' => $now,
            ]);
            foreach ($configuration['tag_codes'] as $tagCode) {
                $connection->table('payment_method_rule_version_tags')->insert([
                    'payment_method_rule_version_id' => $id,
                    'tag_code' => $tagCode,
                    'created_at' => $now,
                ]);
            }

            /** @var RuleReceiptRow|null $created */
            $created = $connection->table('payment_method_rule_versions')->where('id', $id)->first([
                'id', 'method_code', 'rule_code', 'version', 'configuration_snapshot_hash', 'request_payload_hash',
            ]);
            if ($created === null) {
                throw new RuntimeException('Payment eligibility rule configuration could not be loaded.');
            }

            return $this->ruleReceipt($created, false);
        });
    }

    /** @requirement PAY-001 ACL-002 DAT-003 DAT-004 SEC-002 QUA-001 */
    public function recordHealth(
        string $observationKey,
        int $administratorId,
        string $methodCode,
        bool $healthy,
        DateTimeImmutable $expiresAt,
        string $reason,
        string $correlationId,
    ): PaymentMethodHealthReceipt {
        $this->assertKey($observationKey, 'Payment method health observation key');
        $this->assertCode($methodCode, 'Payment method code');
        $this->assertChangeMetadata($reason, $correlationId);
        $observedAt = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $expiresAt = $expiresAt->setTimezone(new DateTimeZone('UTC'));
        if ($expiresAt <= $observedAt) {
            throw new InvalidArgumentException('Payment method health expiry must be in the future.');
        }
        $requestHash = $this->hash([
            'expires_at' => $this->databaseDateTime($expiresAt),
            'healthy' => $healthy,
            'method_code' => $methodCode,
            'reason' => $reason,
        ]);

        return $this->database->connection()->transaction(function (Connection $connection) use ($observationKey, $administratorId, $methodCode, $healthy, $expiresAt, $reason, $correlationId, $observedAt, $requestHash): PaymentMethodHealthReceipt {
            $this->assertActiveAuthorizedAdministrator($connection, $administratorId);
            /** @var HealthReceiptRow|null $existing */
            $existing = $connection->table('payment_method_health_observations')->where('observation_key', $observationKey)->lockForUpdate()->first([
                'id', 'method_code', 'healthy', 'observed_at', 'expires_at', 'request_payload_hash',
            ]);
            if ($existing !== null) {
                if (! hash_equals((string) $existing->request_payload_hash, $requestHash)) {
                    throw new RuntimeException('Payment method health observation key conflict.');
                }

                return $this->healthReceipt($existing, true);
            }

            $methodExists = $connection->table('payment_method_versions')->where('method_code', $methodCode)->lockForUpdate()->exists();
            if (! $methodExists) {
                throw new RuntimeException('Payment method health requires a configured payment method.');
            }

            $snapshot = $this->json([
                'expires_at' => $this->databaseDateTime($expiresAt),
                'formula_version' => 'pay-001-health-v2',
                'healthy' => $healthy,
                'method_code' => $methodCode,
                'observed_at' => $this->databaseDateTime($observedAt),
            ]);
            $id = (int) $connection->table('payment_method_health_observations')->insertGetId([
                'method_code' => $methodCode,
                'observation_key' => $observationKey,
                'request_payload_hash' => $requestHash,
                'healthy' => $healthy,
                'observed_at' => $this->databaseDateTime($observedAt),
                'expires_at' => $this->databaseDateTime($expiresAt),
                'configuration_snapshot' => $snapshot,
                'configuration_snapshot_hash' => hash('sha256', $snapshot),
                'recorded_by_administrator_id' => $administratorId,
                'change_reason' => $reason,
                'correlation_id' => $correlationId,
                'created_at' => $this->databaseDateTime($observedAt),
            ]);
            /** @var HealthReceiptRow|null $created */
            $created = $connection->table('payment_method_health_observations')->where('id', $id)->first([
                'id', 'method_code', 'healthy', 'observed_at', 'expires_at', 'request_payload_hash',
            ]);
            if ($created === null) {
                throw new RuntimeException('Payment method health observation could not be loaded.');
            }

            return $this->healthReceipt($created, false);
        });
    }

    /** @requirement PAY-001 BUY-002 ACL-002 DAT-002 DAT-003 DAT-004 SEC-002 SEC-003 QUA-001 */
    public function evaluate(string $decisionKey, int $actorUserId, string $sourceQuotePublicId): PaymentEligibilityDecisionReceipt
    {
        $this->assertKey($decisionKey, 'Payment eligibility decision key');
        if ($actorUserId < 1 || ! Str::isUlid($sourceQuotePublicId)) {
            throw new InvalidArgumentException('Payment eligibility decision input is invalid.');
        }
        $requestHash = $this->hash([
            'actor_user_id' => $actorUserId,
            'source_quote_public_id' => $sourceQuotePublicId,
        ]);
        $connection = $this->database->connection();

        try {
            return $connection->transaction(function (Connection $connection) use ($decisionKey, $actorUserId, $sourceQuotePublicId, $requestHash): PaymentEligibilityDecisionReceipt {
                $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
                $facts = $this->loadFacts($connection, $actorUserId, $sourceQuotePublicId, $now);
                $existing = $this->decisionByKey($connection, $decisionKey, true);
                if ($existing !== null) {
                    $this->assertReplayAccess($facts);

                    return $this->replayDecision($connection, $existing, $requestHash);
                }
                $methods = $this->currentMethods($connection);
                $rules = $this->currentRules($connection);
                /** @var array<string,list<RuleRow>> $rulesByMethod */
                $rulesByMethod = [];
                foreach ($rules as $rule) {
                    $rulesByMethod[$rule->method_code][] = $rule;
                }

                /** @var array<string,Candidate> $candidates */
                $candidates = [];
                /** @var list<array{display_priority:int,method_code:string}> $selected */
                $selected = [];
                /** @var list<array<string,mixed>> $methodSnapshots */
                $methodSnapshots = [];
                foreach ($methods as $method) {
                    [$outcome, $snapshot] = $this->evaluateMethod(
                        $connection,
                        $method,
                        $rulesByMethod[$method->method_code] ?? [],
                        $facts,
                        $now,
                    );
                    $methodSnapshots[] = $snapshot;
                    /** @var Candidate $candidate */
                    $candidate = [
                        'configuration_snapshot_hash' => (string) $method->configuration_snapshot_hash,
                        'evaluation_snapshot' => $snapshot,
                        'method_code' => (string) $method->method_code,
                        'method_version_id' => (int) $method->id,
                        'method_version' => (int) $method->version,
                        'outcome' => $snapshot['outcome'],
                        'route_order' => null,
                    ];
                    $candidates[(string) $method->method_code] = $candidate;
                    if ($outcome === 'eligible') {
                        $selected[] = [
                            'display_priority' => (int) $method->display_priority,
                            'method_code' => (string) $method->method_code,
                        ];
                    }
                }
                usort($selected, static fn (array $left, array $right): int => [$left['display_priority'], $left['method_code']] <=> [$right['display_priority'], $right['method_code']]);
                foreach ($selected as $index => $method) {
                    if (! array_key_exists($method['method_code'], $candidates)) {
                        throw new RuntimeException('Payment eligibility candidate could not be loaded.');
                    }
                    /** @var Candidate $candidate */
                    $candidate = $candidates[$method['method_code']];
                    $candidate['route_order'] = $index + 1;
                    $candidates[$method['method_code']] = $candidate;
                }
                $selectedMethods = [];
                foreach ($selected as $method) {
                    if (! array_key_exists($method['method_code'], $candidates)) {
                        throw new RuntimeException('Payment eligibility candidate could not be loaded.');
                    }
                    $candidate = $candidates[$method['method_code']];
                    $selectedMethods[] = [
                        'method_code' => $candidate['method_code'],
                        'method_version' => $candidate['method_version'],
                        'reason' => 'eligible',
                        'route_order' => $candidate['route_order'],
                    ];
                }
                ksort($candidates, SORT_STRING);
                $snapshot = [
                    'action' => $facts['action'],
                    'formula_version' => 'pay-001-eligibility-v2',
                    'methods' => $methodSnapshots,
                    'source_quote' => [
                        'account_type' => $facts['account_type'],
                        'configuration_snapshot_hash' => $facts['quote_configuration_snapshot_hash'],
                        'currency' => $facts['currency'],
                        'final_price_irr' => $facts['amount_irr'],
                        'offering_code' => $facts['offering_code'],
                        'product_id' => $facts['product_id'],
                        'public_id' => $facts['source_quote_public_id'],
                        'sales_server_id' => $facts['sales_server_id'],
                    ],
                    'subject' => [
                        'account_status' => $facts['account_status'],
                        'account_type' => $facts['account_type'],
                        'agent_status' => $facts['agent_status'],
                        'identity_status' => $facts['identity_status'],
                        'tag_codes' => $facts['tag_codes'],
                        'tier_code' => $facts['tier_code'],
                        'user_id' => $facts['user_id'],
                    ],
                ];
                $snapshotJson = $this->json($snapshot);
                $createdAt = $this->databaseDateTime($now);
                $decisionId = (int) $connection->table('payment_method_eligibility_decisions')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'decision_key' => $decisionKey,
                    'request_payload_hash' => $requestHash,
                    'source_quote_id' => $facts['quote_id'],
                    'source_quote_public_id' => $facts['source_quote_public_id'],
                    'user_id' => $facts['user_id'],
                    'action_snapshot' => $facts['action'],
                    'currency_snapshot' => $facts['currency'],
                    'amount_irr_snapshot' => $facts['amount_irr'],
                    'configuration_snapshot' => $snapshotJson,
                    'configuration_snapshot_hash' => hash('sha256', $snapshotJson),
                    'created_at' => $createdAt,
                ]);
                foreach ($candidates as $candidate) {
                    /** @var Candidate $candidate */
                    $methodSnapshot = $this->json([
                        'candidate_outcome' => $candidate['outcome'],
                        'configuration_snapshot_hash' => $candidate['configuration_snapshot_hash'],
                        'formula_version' => 'pay-001-decision-method-v2',
                        'health' => $candidate['evaluation_snapshot']['health'],
                        'method_code' => $candidate['method_code'],
                        'method_version' => $candidate['method_version'],
                        'route_order' => $candidate['route_order'],
                    ]);
                    $connection->table('payment_method_eligibility_decision_methods')->insert([
                        'payment_method_eligibility_decision_id' => $decisionId,
                        'payment_method_version_id' => $candidate['method_version_id'],
                        'method_code' => $candidate['method_code'],
                        'method_version' => $candidate['method_version'],
                        'route_order' => $candidate['route_order'],
                        'reason_code' => $candidate['outcome'],
                        'configuration_snapshot' => $methodSnapshot,
                        'configuration_snapshot_hash' => hash('sha256', $methodSnapshot),
                        'created_at' => $createdAt,
                    ]);
                }

                $stored = $this->decisionById($connection, $decisionId);
                if ($stored === null) {
                    throw new RuntimeException('Payment eligibility decision could not be loaded.');
                }

                return $this->decisionReceipt($connection, $stored, false);
            });
        } catch (QueryException $exception) {
            return $connection->transaction(function (Connection $connection) use ($decisionKey, $actorUserId, $sourceQuotePublicId, $requestHash, $exception): PaymentEligibilityDecisionReceipt {
                $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
                $facts = $this->loadFacts($connection, $actorUserId, $sourceQuotePublicId, $now);
                $existing = $this->decisionByKey($connection, $decisionKey, true);
                if ($existing !== null) {
                    $this->assertReplayAccess($facts);

                    return $this->replayDecision($connection, $existing, $requestHash);
                }

                throw $exception;
            });
        }
    }

    /**
     * @param  MethodRow  $method
     * @param  list<RuleRow>  $rules
     * @param  Facts  $facts
     * @return array{0:string,1:EvaluationSnapshot}
     */
    private function evaluateMethod(Connection $connection, object $method, array $rules, array $facts, DateTimeImmutable $now): array
    {
        $snapshot = [
            'configuration_snapshot_hash' => (string) $method->configuration_snapshot_hash,
            'display_priority' => (int) $method->display_priority,
            'method_code' => (string) $method->method_code,
            'method_version' => (int) $method->version,
            'rule_evaluations' => [],
        ];
        [$hardReason, $healthSnapshot] = $this->hardBlock($connection, $method, $facts, $now);
        $snapshot['health'] = $healthSnapshot;
        if ($hardReason !== null) {
            $snapshot['outcome'] = $hardReason;

            return ['ineligible', $snapshot];
        }

        $matchingUserAllow = false;
        $matchingUserDeny = false;
        $unavailableRequiredFact = false;
        $normal = [];
        foreach ($rules as $rule) {
            [$matched, $reason] = $this->ruleMatches($connection, $rule, $facts, $now);
            $snapshot['rule_evaluations'][] = [
                'configuration_snapshot_hash' => (string) $rule->configuration_snapshot_hash,
                'effect' => (string) $rule->effect,
                'matched' => $matched,
                'priority' => (int) $rule->priority,
                'reason' => $reason,
                'rule_code' => (string) $rule->rule_code,
                'version' => (int) $rule->version,
            ];
            if (! $matched) {
                if (in_array($reason, [
                    'contact_otp_provenance_unavailable',
                    'purchase_history_unavailable',
                    'daily_payment_limit_unavailable',
                ], true)) {
                    $unavailableRequiredFact = true;
                }

                continue;
            }

            if ($rule->subject_user_id !== null) {
                if ($rule->effect === PaymentEligibilityRuleEffect::Deny->value) {
                    $matchingUserDeny = true;
                } else {
                    $matchingUserAllow = true;
                }

                continue;
            }
            $normal[] = $rule;
        }

        if ($unavailableRequiredFact) {
            $snapshot['outcome'] = 'required_fact_unavailable';

            return ['ineligible', $snapshot];
        }
        if ($matchingUserDeny) {
            $snapshot['outcome'] = 'user_denied';

            return ['ineligible', $snapshot];
        }
        if ($matchingUserAllow) {
            $snapshot['outcome'] = 'eligible';

            return ['eligible', $snapshot];
        }
        if ($normal === []) {
            $snapshot['outcome'] = 'eligible';

            return ['eligible', $snapshot];
        }

        $topPriority = max(array_map(static fn (object $rule): int => (int) $rule->priority, $normal));
        $topEffects = [];
        foreach ($normal as $rule) {
            if ((int) $rule->priority === $topPriority) {
                $topEffects[(string) $rule->effect] = true;
            }
        }
        if (count($topEffects) !== 1) {
            $snapshot['outcome'] = 'ambiguous_rule_set';

            return ['ineligible', $snapshot];
        }
        if (isset($topEffects[PaymentEligibilityRuleEffect::Deny->value])) {
            $snapshot['outcome'] = 'rule_denied';

            return ['ineligible', $snapshot];
        }
        $snapshot['outcome'] = 'eligible';

        return ['eligible', $snapshot];
    }

    /**
     * @param  MethodRow  $method
     * @param  Facts  $facts
     * @return array{0:?string,1:HealthSnapshot}
     */
    private function hardBlock(Connection $connection, object $method, array $facts, DateTimeImmutable $now): array
    {
        /** @var object{id:int|string, healthy:int|bool|string, observed_at:string, expires_at:string, configuration_snapshot_hash:string}|null $health */
        $health = $connection->table('payment_method_health_observations')
            ->where('method_code', $method->method_code)
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->first(['id', 'healthy', 'observed_at', 'expires_at', 'configuration_snapshot_hash']);
        $healthSnapshot = [
            'observation_id' => $health === null ? null : (int) $health->id,
            'configuration_snapshot_hash' => $health === null ? null : (string) $health->configuration_snapshot_hash,
            'healthy' => $health === null ? null : (bool) $health->healthy,
            'observed_at' => $health === null ? null : (string) $health->observed_at,
            'expires_at' => $health === null ? null : (string) $health->expires_at,
        ];

        if (! (bool) $method->enabled || (bool) $method->maintenance) {
            return ['method_unavailable', $healthSnapshot];
        }
        if ($facts['account_status'] !== 'active' || ($facts['account_type'] === 'agent' && $facts['agent_status'] !== 'active')) {
            return ['subject_blocked', $healthSnapshot];
        }
        if ($health === null) {
            return ['health_unknown', $healthSnapshot];
        }
        if (! (bool) $health->healthy) {
            return ['health_unhealthy', $healthSnapshot];
        }
        if ($this->databaseDateTimeFromString((string) $health->expires_at) <= $now) {
            return ['health_expired', $healthSnapshot];
        }

        return [null, $healthSnapshot];
    }

    /**
     * @param  RuleRow  $rule
     * @param  Facts  $facts
     * @return array{0:bool,1:string}
     */
    private function ruleMatches(Connection $connection, object $rule, array $facts, DateTimeImmutable $now): array
    {
        if (! (bool) $rule->enabled) {
            return [false, 'rule_disabled'];
        }
        if ($rule->subject_user_id !== null && (int) $rule->subject_user_id !== $facts['user_id']) {
            return [false, 'subject_mismatch'];
        }
        $accountTypes = $this->jsonArray((string) $rule->account_types);
        if ($accountTypes !== [] && ! in_array($facts['account_type'], $accountTypes, true)) {
            return [false, 'account_type_mismatch'];
        }
        $tierCodes = $this->jsonArray((string) $rule->tier_codes);
        if ($tierCodes !== [] && ! in_array($facts['tier_code'], $tierCodes, true)) {
            return [false, 'tier_mismatch'];
        }
        $tagCodes = $this->ruleTags($connection, (int) $rule->id);
        foreach ($tagCodes as $tagCode) {
            if (! in_array($tagCode, $facts['tag_codes'], true)) {
                return [false, 'tag_mismatch'];
            }
        }
        if (($rule->minimum_amount_irr !== null && $facts['amount_irr'] < (int) $rule->minimum_amount_irr)
            || ($rule->maximum_amount_irr !== null && $facts['amount_irr'] > (int) $rule->maximum_amount_irr)) {
            return [false, 'amount_mismatch'];
        }
        $offeringCodes = $this->jsonArray((string) $rule->offering_codes);
        if ($offeringCodes !== [] && ! in_array($facts['offering_code'], $offeringCodes, true)) {
            return [false, 'offering_mismatch'];
        }
        $productIds = $this->jsonArray((string) $rule->product_ids);
        if ($productIds !== [] && ! in_array($facts['product_id'], array_map('intval', $productIds), true)) {
            return [false, 'product_mismatch'];
        }
        $salesServerIds = $this->jsonArray((string) $rule->sales_server_ids);
        if ($salesServerIds !== [] && ! in_array($facts['sales_server_id'], array_map('intval', $salesServerIds), true)) {
            return [false, 'sales_server_mismatch'];
        }
        if ($rule->required_identity_status !== null && $facts['identity_status'] !== (string) $rule->required_identity_status) {
            return [false, 'identity_mismatch'];
        }
        if ($rule->required_agent_status !== null && $facts['agent_status'] !== (string) $rule->required_agent_status) {
            return [false, 'agent_status_mismatch'];
        }
        if (! $this->withinTimeWindow($rule->starts_at_utc, $rule->ends_at_utc, $now)) {
            return [false, 'time_window_mismatch'];
        }
        if ((bool) $rule->requires_contact_otp_provenance) {
            return [false, 'contact_otp_provenance_unavailable'];
        }
        if ((bool) $rule->requires_purchase_history) {
            return [false, 'purchase_history_unavailable'];
        }
        if ((bool) $rule->requires_daily_payment_limit) {
            return [false, 'daily_payment_limit_unavailable'];
        }

        return [true, 'matched'];
    }

    /**
     * @return Facts
     */
    private function loadFacts(Connection $connection, int $actorUserId, string $quotePublicId, DateTimeImmutable $now): array
    {
        /** @var object{quote_id:int|string,quote_public_id:string,user_id:int|string,action_snapshot:string,currency:string,final_price_irr:int|string,expires_at:string,configuration_snapshot_hash:string,account_type:string,account_status:string,offering_code:string,product_id:int|string,sales_server_id:int|string,identity_verification_status:?string,tier_code:?string,agent_status:?string}|null $row */
        $row = $connection->table('quotes as quotes')
            ->join('users as users', 'users.id', '=', 'quotes.user_id')
            ->join('plan_offerings as offerings', 'offerings.id', '=', 'quotes.plan_offering_id')
            ->leftJoin('customer_profiles as profiles', 'profiles.user_id', '=', 'users.id')
            ->leftJoin('customer_tiers as tiers', 'tiers.id', '=', 'profiles.current_tier_id')
            ->leftJoin('agent_profiles as agents', 'agents.user_id', '=', 'users.id')
            ->where('quotes.public_id', $quotePublicId)
            ->lockForUpdate()
            ->first([
                'quotes.id as quote_id', 'quotes.public_id as quote_public_id', 'quotes.user_id', 'quotes.action_snapshot',
                'quotes.currency', 'quotes.final_price_irr', 'quotes.expires_at', 'quotes.configuration_snapshot_hash',
                'users.account_type', 'users.account_status', 'offerings.code as offering_code',
                'offerings.product_id', 'offerings.sales_server_id', 'profiles.identity_verification_status',
                'tiers.code as tier_code', 'agents.status as agent_status',
            ]);
        if ($row === null || (int) $row->user_id !== $actorUserId) {
            throw new AuthorizationException('Payment eligibility decision access denied.');
        }
        if ((string) $row->currency !== 'IRR' || (int) $row->final_price_irr < 0) {
            throw new RuntimeException('Payment eligibility requires an IRR Quote.');
        }
        if (! in_array((string) $row->account_type, ['customer', 'agent'], true)
            || $this->databaseDateTimeFromString((string) $row->expires_at) <= $now) {
            throw new RuntimeException('Payment eligibility requires a current commercial Quote.');
        }

        /** @var list<string> $tags */
        $tags = $connection->table('customer_tag_assignments as assignments')
            ->join('customer_tags as tags', 'tags.id', '=', 'assignments.tag_id')
            ->where('assignments.user_id', $actorUserId)
            ->whereNull('assignments.removed_at')
            ->where('tags.is_active', true)
            ->orderBy('tags.code')
            ->pluck('tags.code')
            ->all();

        return [
            'account_status' => (string) $row->account_status,
            'account_type' => (string) $row->account_type,
            'action' => (string) $row->action_snapshot,
            'agent_status' => $row->agent_status === null ? null : (string) $row->agent_status,
            'amount_irr' => (int) $row->final_price_irr,
            'currency' => (string) $row->currency,
            'identity_status' => $row->identity_verification_status === null ? 'unverified' : (string) $row->identity_verification_status,
            'offering_code' => (string) $row->offering_code,
            'product_id' => (int) $row->product_id,
            'quote_configuration_snapshot_hash' => (string) $row->configuration_snapshot_hash,
            'quote_id' => (int) $row->quote_id,
            'sales_server_id' => (int) $row->sales_server_id,
            'source_quote_public_id' => (string) $row->quote_public_id,
            'tag_codes' => $tags,
            'tier_code' => $row->tier_code === null ? null : (string) $row->tier_code,
            'user_id' => (int) $row->user_id,
        ];
    }

    /** @return list<MethodRow> */
    private function currentMethods(Connection $connection): array
    {
        /** @var list<MethodRow> $methods */
        $methods = $connection->table('payment_method_versions as methods')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('payment_method_versions as newer')
                    ->whereColumn('newer.method_code', 'methods.method_code')
                    ->whereColumn('newer.version', '>', 'methods.version');
            })
            ->orderBy('methods.display_priority')
            ->orderBy('methods.method_code')
            ->lockForUpdate()
            ->get()
            ->all();

        return $methods;
    }

    /** @return list<RuleRow> */
    private function currentRules(Connection $connection): array
    {
        /** @var list<RuleRow> $rules */
        $rules = $connection->table('payment_method_rule_versions as rules')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('payment_method_rule_versions as newer')
                    ->whereColumn('newer.method_code', 'rules.method_code')
                    ->whereColumn('newer.rule_code', 'rules.rule_code')
                    ->whereColumn('newer.version', '>', 'rules.version');
            })
            ->orderBy('rules.method_code')
            ->orderByDesc('rules.priority')
            ->orderBy('rules.rule_code')
            ->lockForUpdate()
            ->get()
            ->all();

        return $rules;
    }

    /** @return list<string> */
    private function ruleTags(Connection $connection, int $ruleVersionId): array
    {
        /** @var list<string> $tags */
        $tags = $connection->table('payment_method_rule_version_tags')
            ->where('payment_method_rule_version_id', $ruleVersionId)
            ->orderBy('tag_code')
            ->pluck('tag_code')
            ->all();

        return $tags;
    }

    private function assertActiveAuthorizedAdministrator(Connection $connection, int $administratorId): void
    {
        if ($administratorId < 1 || ! $connection->table('administrators')->where('id', $administratorId)->where('status', 'active')->lockForUpdate()->exists()) {
            throw new AuthorizationException('Administrator authorization failed.');
        }
        $this->authorizer->authorize($administratorId, self::MANAGE_PERMISSION);
    }

    /** @param Facts $facts */
    private function assertReplayAccess(array $facts): void
    {
        if ($facts['account_status'] !== 'active'
            || ($facts['account_type'] === 'agent' && $facts['agent_status'] !== 'active')) {
            throw new AuthorizationException('Payment eligibility decision access denied.');
        }
    }

    /** @param DecisionRow $row */
    private function replayDecision(Connection $connection, object $row, string $requestHash): PaymentEligibilityDecisionReceipt
    {
        if (! hash_equals((string) $row->request_payload_hash, $requestHash)) {
            throw new RuntimeException('Payment eligibility decision key conflict.');
        }

        return $this->decisionReceipt($connection, $row, true);
    }

    /** @return DecisionRow|null */
    private function decisionByKey(Connection $connection, string $decisionKey, bool $lock): ?object
    {
        $query = $connection->table('payment_method_eligibility_decisions')->where('decision_key', $decisionKey);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var DecisionRow|null $row */
        $row = $query->first([
            'id', 'public_id', 'decision_key', 'source_quote_public_id', 'user_id',
            'configuration_snapshot_hash', 'request_payload_hash',
        ]);

        return $row;
    }

    /** @return DecisionRow|null */
    private function decisionById(Connection $connection, int $decisionId): ?object
    {
        /** @var DecisionRow|null $row */
        $row = $connection->table('payment_method_eligibility_decisions')->where('id', $decisionId)->first([
            'id', 'public_id', 'decision_key', 'source_quote_public_id', 'user_id',
            'configuration_snapshot_hash', 'request_payload_hash',
        ]);

        return $row;
    }

    /** @param MethodRow $row */
    private function methodReceipt(object $row, bool $replayed): PaymentMethodVersionReceipt
    {
        return new PaymentMethodVersionReceipt(
            (int) $row->id,
            (string) $row->method_code,
            (int) $row->version,
            (bool) $row->enabled,
            (bool) $row->maintenance,
            (int) $row->display_priority,
            (string) $row->configuration_snapshot_hash,
            $replayed,
        );
    }

    /** @param RuleReceiptRow $row */
    private function ruleReceipt(object $row, bool $replayed): PaymentEligibilityRuleVersionReceipt
    {
        return new PaymentEligibilityRuleVersionReceipt(
            (int) $row->id,
            (string) $row->method_code,
            (string) $row->rule_code,
            (int) $row->version,
            (string) $row->configuration_snapshot_hash,
            $replayed,
        );
    }

    /** @param HealthReceiptRow $row */
    private function healthReceipt(object $row, bool $replayed): PaymentMethodHealthReceipt
    {
        return new PaymentMethodHealthReceipt(
            (int) $row->id,
            (string) $row->method_code,
            (bool) $row->healthy,
            $this->databaseDateTimeFromString((string) $row->observed_at),
            $this->databaseDateTimeFromString((string) $row->expires_at),
            $replayed,
        );
    }

    /** @param DecisionRow $row */
    private function decisionReceipt(Connection $connection, object $row, bool $replayed): PaymentEligibilityDecisionReceipt
    {
        /** @var list<DecisionMethodRow> $methods */
        $methods = $connection->table('payment_method_eligibility_decision_methods')
            ->where('payment_method_eligibility_decision_id', $row->id)
            ->whereNotNull('route_order')
            ->orderBy('route_order')
            ->get(['method_code', 'method_version', 'route_order', 'reason_code'])
            ->all();
        $result = [];
        foreach ($methods as $method) {
            $result[] = [
                'method_code' => (string) $method->method_code,
                'method_version' => (int) $method->method_version,
                'route_order' => (int) $method->route_order,
                'reason' => (string) $method->reason_code,
            ];
        }

        return new PaymentEligibilityDecisionReceipt(
            (int) $row->id,
            (string) $row->public_id,
            (string) $row->decision_key,
            (string) $row->source_quote_public_id,
            (int) $row->user_id,
            $result,
            (string) $row->configuration_snapshot_hash,
            $replayed,
        );
    }

    private function withinTimeWindow(mixed $startsAt, mixed $endsAt, DateTimeImmutable $now): bool
    {
        if ($startsAt === null && $endsAt === null) {
            return true;
        }
        if (! is_string($startsAt) || ! is_string($endsAt)) {
            return false;
        }
        $current = $now->format('H:i');
        if ($startsAt <= $endsAt) {
            return $current >= $startsAt && $current <= $endsAt;
        }

        return $current >= $startsAt || $current <= $endsAt;
    }

    /** @return list<mixed> */
    private function jsonArray(string $value): array
    {
        $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? array_values($decoded) : throw new RuntimeException('Stored payment eligibility rule configuration is invalid.');
    }

    /** @param array<string,mixed> $value */
    private function hash(array $value): string
    {
        return hash('sha256', $this->json($value));
    }

    /** @param array<string,mixed>|list<mixed> $value */
    private function json(array $value): string
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function assertKey(string $value, string $label): void
    {
        if (strlen($value) < 8 || strlen($value) > 128 || preg_match('/\A[a-zA-Z0-9._:-]+\z/', $value) !== 1) {
            throw new InvalidArgumentException($label.' is invalid.');
        }
    }

    private function assertCode(string $value, string $label): void
    {
        if (preg_match('/\A[a-z][a-z0-9_.-]{1,63}\z/', $value) !== 1) {
            throw new InvalidArgumentException($label.' is invalid.');
        }
    }

    private function assertChangeMetadata(string $reason, string $correlationId): void
    {
        if ($reason === '' || strlen($reason) > 255 || preg_match('/\A[a-f0-9]{64}\z/', $correlationId) !== 1) {
            throw new InvalidArgumentException('Payment eligibility change metadata is invalid.');
        }
    }

    private function databaseDateTime(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function databaseDateTimeFromString(string $value): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if ($parsed === false) {
            throw new RuntimeException('Stored payment eligibility timestamp is invalid.');
        }

        return $parsed;
    }
}
