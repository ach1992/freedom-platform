<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Payments\Application\Contracts\ProviderHealth;
use App\Modules\Payments\Domain\PaymentConfigurationState;
use App\Modules\Payments\Domain\PaymentEligibilityEffect;
use App\Modules\Payments\Domain\PaymentEligibilityOutcome;
use DateTimeZone;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * @phpstan-type MethodVersionRow object{id:int|string,payment_method_id:int|string,mutation_payload_hash:string,version:int|string,state:string,display_priority:int|string,minimum_amount_irr:int|string|null,maximum_amount_irr:int|string|null,allow_degraded_health:int|bool|string,configuration_snapshot:string,configuration_hash:string,method_public_id:string,method_code:string,kind:string,provider_code:string|null}
 * @phpstan-type RuleVersionRow object{id:int|string,payment_eligibility_rule_id:int|string,payment_method_id:int|string,mutation_payload_hash:string,version:int|string,state:string,effect:string,priority:int|string,is_override:int|bool|string,account_type:string|null,tier_code:string|null,identity_status:string|null,customer_tag_id:int|string|null,minimum_amount_irr:int|string|null,maximum_amount_irr:int|string|null,action:string|null,plan_offering_id:int|string|null,product_id:int|string|null,sales_server_id:int|string|null,effective_from:string|null,effective_until:string|null,configuration_snapshot:string,configuration_hash:string,rule_public_id:string,rule_code:string,method_code:string}
 * @phpstan-type DecisionRow object{id:int|string,public_id:string,decision_key:string,request_payload_hash:string,user_id:int|string,quote_id:int|string,quote_public_id_snapshot:string,action:string,amount_irr:int|string,currency:string,eligible_count:int|string,configuration_snapshot:string,configuration_snapshot_hash:string}
 * @phpstan-type DecisionItemRow object{payment_method_id:int|string,method_public_id_snapshot:string,method_code_snapshot:string,kind_snapshot:string,provider_code_snapshot:string|null,method_version:int|string,method_configuration_hash:string,display_priority:int|string,eligible:int|bool|string,payment_eligibility_rule_id:int|string|null,rule_public_id_snapshot:string|null,rule_code_snapshot:string|null,rule_version:int|string|null,rule_configuration_hash:string|null}
 */
trait PaymentEligibilityDecisionResolution
{
    public function resolve(PaymentEligibilityResolutionRequest $request, PaymentEligibilityResolutionContext $context): PaymentEligibilityDecisionReceipt
    {
        if ($context->actorUserId !== $request->userId) {
            throw new AuthorizationException('Payment eligibility actor is not authorized for this subject.');
        }
        $requestHash = $this->hash([
            'action' => $request->action->value,
            'health' => $request->normalizedHealth(),
            'quote_public_id' => $request->quotePublicId,
            'user_id' => $request->userId,
        ]);

        try {
            return $this->database->connection()->transaction(function (Connection $db) use ($request, $requestHash): PaymentEligibilityDecisionReceipt {
                $existing = $this->decisionByKey($db, $request->decisionKey, true);
                if ($existing !== null) {
                    return $this->decisionReceipt($db, $existing, $requestHash, true);
                }

                $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
                /** @var object{account_type:string,account_status:string}|null $user */
                $user = $db->table('users')->where('id', $request->userId)->lockForUpdate()->first(['account_type', 'account_status']);
                if ($user === null || $user->account_status !== 'active' || ! in_array($user->account_type, ['customer', 'agent'], true)) {
                    throw new DomainException('Payment eligibility requires an active customer or agent.');
                }

                /** @var object{id:int|string,user_id:int|string,account_type_snapshot:string,plan_offering_id:int|string,final_price_irr:int|string,currency:string,configuration_snapshot:string,configuration_snapshot_hash:string,valid_from:string,expires_at:string}|null $quote */
                $quote = $db->table('quotes')->where('public_id', $request->quotePublicId)->lockForUpdate()->first([
                    'id', 'user_id', 'account_type_snapshot', 'plan_offering_id', 'final_price_irr', 'currency',
                    'configuration_snapshot', 'configuration_snapshot_hash', 'valid_from', 'expires_at',
                ]);
                if ($quote === null || $this->positive($quote->user_id, 'Quote user ID') !== $request->userId) {
                    throw new DomainException('Payment eligibility Quote does not belong to subject.');
                }
                if (! hash_equals($quote->account_type_snapshot, $user->account_type)) {
                    throw new DomainException('Payment eligibility subject changed after Quote creation.');
                }
                if (! hash_equals($quote->currency, 'IRR')) {
                    throw new DomainException('Payment eligibility requires an IRR Quote.');
                }
                if (! hash_equals(hash('sha256', $quote->configuration_snapshot), $quote->configuration_snapshot_hash)) {
                    throw new RuntimeException('Payment eligibility Quote snapshot hash is invalid.');
                }
                $validFrom = $this->dateFromDatabase($quote->valid_from, 'Quote valid-from');
                $expiresAt = $this->dateFromDatabase($quote->expires_at, 'Quote expiry');
                if ($validFrom > $now || $expiresAt <= $now) {
                    throw new DomainException('Payment eligibility requires a currently valid Quote.');
                }
                $quoteId = $this->positive($quote->id, 'Quote ID');
                $offeringId = $this->positive($quote->plan_offering_id, 'Quote offering ID');
                $amountIrr = $this->nonNegative($quote->final_price_irr, 'Quote final price');

                /** @var object{product_id:int|string,sales_server_id:int|string}|null $offering */
                $offering = $db->table('plan_offerings')->where('id', $offeringId)->lockForUpdate()->first(['product_id', 'sales_server_id']);
                if ($offering === null) {
                    throw new DomainException('Payment eligibility Quote offering no longer exists.');
                }
                $productId = $this->positive($offering->product_id, 'Payment eligibility product ID');
                $serverId = $this->positive($offering->sales_server_id, 'Payment eligibility sales server ID');

                $tierCode = $this->currentTierCode($db, $request->userId);
                $identityStatus = $this->currentIdentityStatus($db, $request->userId);
                $tagIds = $this->currentTagIds($db, $request->userId);
                $methods = $this->latestMethodVersions($db);
                $knownMethodCodes = [];
                foreach ($methods as $methodRow) {
                    $knownMethodCodes[$methodRow->method_code] = true;
                }
                foreach (array_keys($request->healthByMethodCode) as $healthMethodCode) {
                    if (! isset($knownMethodCodes[$healthMethodCode])) {
                        throw new DomainException('Payment eligibility health input references an unknown payment method.');
                    }
                }

                /** @var list<array{method:MethodVersionRow,health:string,outcome:string,eligible:bool,rule:RuleVersionRow|null}> $evaluated */
                $evaluated = [];
                foreach ($methods as $method) {
                    $this->verifyMethodVersion($method);
                    $health = $request->healthByMethodCode[$method->method_code] ?? null;
                    $winner = null;
                    $eligible = false;
                    $outcome = PaymentEligibilityOutcome::NoMatchingRule;
                    if ($method->state !== PaymentConfigurationState::Active->value) {
                        $outcome = PaymentEligibilityOutcome::MethodDisabled;
                    } elseif (! $this->healthAllowsMethod($health, (bool) $method->allow_degraded_health)) {
                        $outcome = PaymentEligibilityOutcome::HealthUnavailable;
                    } elseif (! $this->amountWithinLimits($amountIrr, $method->minimum_amount_irr, $method->maximum_amount_irr)) {
                        $outcome = PaymentEligibilityOutcome::AmountOutsideMethodLimit;
                    } else {
                        $winner = $this->selectRule($db, $this->positive($method->payment_method_id, 'Payment method ID'), $request, $amountIrr, $offeringId, $productId, $serverId, $user->account_type, $tierCode, $identityStatus, $tagIds, $now);
                        if ($winner !== null) {
                            $effect = PaymentEligibilityEffect::tryFrom($winner->effect)
                                ?? throw new RuntimeException('Stored payment eligibility effect is invalid.');
                            if ($effect === PaymentEligibilityEffect::Allow) {
                                $eligible = true;
                                $outcome = PaymentEligibilityOutcome::Eligible;
                            } else {
                                $outcome = PaymentEligibilityOutcome::RuleDenied;
                            }
                        }
                    }
                    $evaluated[] = [
                        'method' => $method,
                        'health' => $health instanceof ProviderHealth ? $health->value : 'missing',
                        'outcome' => $outcome->value,
                        'eligible' => $eligible,
                        'rule' => $winner,
                    ];
                }

                usort($evaluated, static function (array $left, array $right): int {
                    $priority = (int) $right['method']->display_priority <=> (int) $left['method']->display_priority;
                    if ($priority !== 0) {
                        return $priority;
                    }

                    return strcmp($left['method']->method_code, $right['method']->method_code);
                });
                $eligibleCount = count(array_filter($evaluated, static fn (array $item): bool => $item['eligible']));
                $snapshot = [
                    'account_status' => $user->account_status,
                    'account_type' => $user->account_type,
                    'action' => $request->action->value,
                    'amount_irr' => $amountIrr,
                    'currency' => 'IRR',
                    'formula_version' => self::FORMULA_VERSION,
                    'health' => $request->normalizedHealth(),
                    'methods' => array_map(fn (array $item): array => $this->decisionItemSnapshot($item), $evaluated),
                    'plan_offering_id' => $offeringId,
                    'product_id' => $productId,
                    'quote_configuration_hash' => $quote->configuration_snapshot_hash,
                    'quote_public_id' => $request->quotePublicId,
                    'sales_server_id' => $serverId,
                    'subject_identity_status' => $identityStatus,
                    'subject_tag_ids' => $tagIds,
                    'subject_tier_code' => $tierCode,
                    'user_id' => $request->userId,
                ];
                $snapshotJson = $this->json($snapshot);
                if (strlen($snapshotJson) > 16384) {
                    throw new RuntimeException('Payment eligibility decision snapshot exceeds storage boundary.');
                }
                $createdAt = $this->timestamp();
                $decisionId = (int) $db->table('payment_eligibility_decisions')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'decision_key' => $request->decisionKey,
                    'request_payload_hash' => $requestHash,
                    'user_id' => $request->userId,
                    'quote_id' => $quoteId,
                    'quote_public_id_snapshot' => $request->quotePublicId,
                    'action' => $request->action->value,
                    'amount_irr' => $amountIrr,
                    'currency' => 'IRR',
                    'account_type_snapshot' => $user->account_type,
                    'account_status_snapshot' => $user->account_status,
                    'tier_code_snapshot' => $tierCode,
                    'identity_status_snapshot' => $identityStatus,
                    'subject_tag_ids_snapshot' => $this->json($tagIds),
                    'plan_offering_id' => $offeringId,
                    'product_id_snapshot' => $productId,
                    'sales_server_id_snapshot' => $serverId,
                    'eligible_count' => $eligibleCount,
                    'configuration_snapshot' => $snapshotJson,
                    'configuration_snapshot_hash' => hash('sha256', $snapshotJson),
                    'created_at' => $createdAt,
                ]);
                foreach ($evaluated as $item) {
                    $method = $item['method'];
                    $rule = $item['rule'];
                    $db->table('payment_eligibility_decision_items')->insert([
                        'decision_id' => $decisionId,
                        'payment_method_id' => $this->positive($method->payment_method_id, 'Payment method ID'),
                        'payment_method_version_id' => $this->positive($method->id, 'Payment method version ID'),
                        'method_public_id_snapshot' => $method->method_public_id,
                        'method_code_snapshot' => $method->method_code,
                        'kind_snapshot' => $method->kind,
                        'provider_code_snapshot' => $method->provider_code,
                        'method_version' => $this->positive($method->version, 'Payment method version'),
                        'method_configuration_hash' => $method->configuration_hash,
                        'display_priority' => $this->nonNegative($method->display_priority, 'Payment method display priority'),
                        'health_snapshot' => $item['health'],
                        'outcome' => $item['outcome'],
                        'eligible' => $item['eligible'],
                        'payment_eligibility_rule_id' => $rule === null ? null : $this->positive($rule->payment_eligibility_rule_id, 'Payment eligibility rule ID'),
                        'payment_eligibility_rule_version_id' => $rule === null ? null : $this->positive($rule->id, 'Payment eligibility rule version ID'),
                        'rule_public_id_snapshot' => $rule?->rule_public_id,
                        'rule_code_snapshot' => $rule?->rule_code,
                        'rule_version' => $rule === null ? null : $this->positive($rule->version, 'Payment eligibility rule version'),
                        'rule_configuration_hash' => $rule?->configuration_hash,
                        'created_at' => $createdAt,
                    ]);
                }
                $created = $this->decisionById($db, $decisionId);
                if ($created === null) {
                    throw new RuntimeException('Payment eligibility decision persistence failed.');
                }

                return $this->decisionReceipt($db, $created, $requestHash, false);
            });
        } catch (QueryException $exception) {
            $existing = $this->decisionByKey($this->database->connection(), $request->decisionKey);
            if ($existing !== null) {
                return $this->decisionReceipt($this->database->connection(), $existing, $requestHash, true);
            }
            throw $exception;
        }
    }
}
