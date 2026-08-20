<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application;

use App\Modules\Agents\Domain\AgentPricingAction;
use App\Modules\Orders\Domain\QuoteAction;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use DateTimeZone;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use RuntimeException;

trait QuoteServiceCreatesQuotes
{
    /** @requirement AGT-005 BUY-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 */
    public function create(
        string $quoteKey,
        int $userId,
        int $planOfferingId,
        QuotePricingInput $pricing,
        string $correlationId,
        ?QuoteAgentPricingContext $agentPricingContext = null,
        ?ServicePackageQuoteContext $servicePackageContext = null,
    ): QuoteReceipt {
        $this->assertToken($quoteKey, 'Quote key', 8, 128);
        $this->assertPositiveId($userId, 'Quote user ID');
        $this->assertPositiveId($planOfferingId, 'Quote plan offering ID');
        $this->assertToken($correlationId, 'Quote correlation ID', 8, 64);

        if ($agentPricingContext !== null && $agentPricingContext->actorUserId !== $userId) {
            throw new AuthorizationException('Quote agent pricing actor is not authorized for this subject.');
        }

        $expiresAt = $pricing->expiresAt->setTimezone(new DateTimeZone('UTC'));
        $requestPayloadHash = $this->requestPayloadHash(
            $userId,
            $planOfferingId,
            $pricing,
            $expiresAt,
            $agentPricingContext,
            $servicePackageContext,
        );

        try {
            return $this->database->connection()->transaction(function (Connection $connection) use (
                $quoteKey,
                $userId,
                $planOfferingId,
                $pricing,
                $correlationId,
                $expiresAt,
                $requestPayloadHash,
                $agentPricingContext,
                $servicePackageContext,
            ): QuoteReceipt {
                $existing = $this->quoteByKey($connection, $quoteKey, true);
                if ($existing !== null) {
                    $receipt = $this->quoteReceipt($existing, $requestPayloadHash, true);
                    $this->assertAgentQuoteReplayAuthorized($connection, $receipt, $agentPricingContext);

                    return $receipt;
                }

                $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
                if ($expiresAt <= $now) {
                    throw new DomainException('Quote expiry must be in the future.');
                }

                /** @var object{account_type:string,account_status:string}|null $user */
                $user = $connection->table('users')
                    ->where('id', $userId)
                    ->lockForUpdate()
                    ->first(['account_type', 'account_status']);
                if ($user === null
                    || $user->account_status !== 'active'
                    || ! in_array($user->account_type, ['customer', 'agent'], true)) {
                    throw new DomainException('Quote requires an active customer or agent account.');
                }

                /** @var object{id:int|string,code:string,version:int|string,base_price_irr:int|string,discount_eligible:int|bool,state:string,visibility:string}|null $offering */
                $offering = $connection->table('plan_offerings')
                    ->where('id', $planOfferingId)
                    ->lockForUpdate()
                    ->first(['id', 'code', 'version', 'base_price_irr', 'discount_eligible', 'state', 'visibility']);
                if ($offering === null) {
                    throw new DomainException('Quote plan offering does not exist.');
                }

                $offeringVersion = $this->positiveDatabaseInt($offering->version, 'Quote offering version');
                $basePriceIrr = $this->nonNegativeDatabaseInt($offering->base_price_irr, 'Quote base price');
                $offeringDiscountEligible = (bool) $offering->discount_eligible;
                $offeringConfigurationHash = $this->offeringConfigurationHash(
                    $connection,
                    $planOfferingId,
                    $offeringVersion,
                );
                $servicePackage = $servicePackageContext === null
                    ? null
                    : $this->servicePackageFacts($connection, $userId, $planOfferingId, $servicePackageContext);
                $action = $servicePackage === null ? QuoteAction::Purchase : $servicePackage['action'];
                if ($servicePackage !== null) {
                    $basePriceIrr = $servicePackage['price_irr'];
                    $offeringDiscountEligible = $offeringDiscountEligible && $servicePackage['discount_eligible'];
                }

                $expectedAgentAction = $servicePackage === null ? AgentPricingAction::Purchase : $servicePackage['agent_action'];
                if ($user->account_type === 'agent') {
                    if ($agentPricingContext === null || $agentPricingContext->action !== $expectedAgentAction) {
                        throw new AuthorizationException('Agent quote creation requires the matching authorized pricing action.');
                    }
                } elseif ($agentPricingContext !== null) {
                    throw new AuthorizationException('Agent pricing context is not authorized for this quote subject.');
                }

                $agentResolution = null;
                $overrideSource = $pricing->overrideSource;
                $overrideReferenceCode = $pricing->overrideReferenceCode;
                $overridePriceIrr = $pricing->overridePriceIrr;

                if ($user->account_type === 'agent') {
                    if ($pricing->overrideSource !== QuoteOverrideSource::None
                        || $pricing->overrideReferenceCode !== null
                        || $pricing->overridePriceIrr !== null) {
                        throw new DomainException('Agent quote pricing cannot accept a caller-supplied override.');
                    }
                    if ($agentPricingContext === null) {
                        throw new RuntimeException('Agent quote pricing context is unavailable.');
                    }

                    $agentResolution = $this->resolveAgentPricing(
                        $connection,
                        $quoteKey,
                        $userId,
                        $planOfferingId,
                        $agentPricingContext,
                    );
                    if ($pricing->discountIrr > 0 && ! $agentResolution->discountCombinationAllowed) {
                        throw new DomainException('Agent pricing profile does not allow discount combination.');
                    }
                    if ($agentResolution->matched()) {
                        $overrideSource = QuoteOverrideSource::Agent;
                        $overrideReferenceCode = $agentResolution->pricingProfileCode;
                        $overridePriceIrr = $agentResolution->overridePriceIrr;
                    } else {
                        $overrideSource = QuoteOverrideSource::None;
                        $overrideReferenceCode = null;
                        $overridePriceIrr = null;
                    }
                } else {
                    $this->validateOverrideReference(
                        $connection,
                        $userId,
                        $user->account_type,
                        $pricing,
                    );
                }

                if ($pricing->discountIrr > 0 && ! $offeringDiscountEligible) {
                    throw new DomainException('Plan offering does not allow a quote discount.');
                }

                $effectivePriceIrr = $overridePriceIrr ?? $basePriceIrr;
                if ($pricing->discountIrr > $effectivePriceIrr) {
                    throw new DomainException('Quote discount cannot exceed the effective price.');
                }
                $finalPriceIrr = $effectivePriceIrr - $pricing->discountIrr;

                $snapshot = [
                    'account_type' => $user->account_type,
                    'action' => $action->value,
                    'base_price_irr' => $basePriceIrr,
                    'currency' => 'IRR',
                    'discount_irr' => $pricing->discountIrr,
                    'discount_reference_code' => $pricing->discountReferenceCode,
                    'effective_price_irr' => $effectivePriceIrr,
                    'final_price_irr' => $finalPriceIrr,
                    'formula_version' => $agentResolution === null ? self::FORMULA_VERSION : self::AGENT_FORMULA_VERSION,
                    'offering_code' => $offering->code,
                    'offering_configuration_hash' => $offeringConfigurationHash,
                    'offering_discount_eligible' => $offeringDiscountEligible,
                    'offering_id' => $planOfferingId,
                    'offering_state' => $offering->state,
                    'offering_version' => $offeringVersion,
                    'offering_visibility' => $offering->visibility,
                    'override_price_irr' => $overridePriceIrr,
                    'override_reference_code' => $overrideReferenceCode,
                    'override_source' => $overrideSource->value,
                ];
                if ($agentResolution !== null) {
                    $snapshot['agent_pricing'] = $this->agentPricingSnapshotArray($agentResolution);
                }
                if ($servicePackage !== null) {
                    $snapshot['service_package'] = [
                        'action' => $action->value,
                        'data_bytes' => $servicePackage['data_bytes'],
                        'duration_days' => $servicePackage['duration_days'],
                        'lifecycle_version' => $servicePackage['lifecycle_version'],
                        'package_code' => $servicePackage['package_code'],
                        'package_id' => $servicePackage['package_id'],
                        'package_type' => $servicePackage['package_type'],
                        'remote_identity_generation' => $servicePackage['remote_identity_generation'],
                        'required_capability_code' => $servicePackage['required_capability_code'],
                        'service_public_id' => $servicePackage['service_subscription_public_id'],
                        'service_subscription_id' => $servicePackage['service_subscription_id'],
                        'service_target_id' => $servicePackage['service_target_id'],
                    ];
                }
                ksort($snapshot, SORT_STRING);
                $snapshotJson = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                if (strlen($snapshotJson) > 8192) {
                    throw new RuntimeException('Quote configuration snapshot exceeds the storage boundary.');
                }
                $snapshotHash = hash('sha256', $snapshotJson);
                $nowString = $this->databaseDateTime($now);

                $insert = [
                    'public_id' => (string) Str::ulid(),
                    'quote_key' => $quoteKey,
                    'request_payload_hash' => $requestPayloadHash,
                    'user_id' => $userId,
                    'account_type_snapshot' => $user->account_type,
                    'action_snapshot' => $action->value,
                    'plan_offering_id' => $planOfferingId,
                    'offering_code_snapshot' => $offering->code,
                    'offering_version' => $offeringVersion,
                    'offering_configuration_hash' => $offeringConfigurationHash,
                    'offering_discount_eligible' => $offeringDiscountEligible,
                    'base_price_irr' => $basePriceIrr,
                    'override_source' => $overrideSource->value,
                    'override_reference_code' => $overrideReferenceCode,
                    'override_price_irr' => $overridePriceIrr,
                    'effective_price_irr' => $effectivePriceIrr,
                    'discount_reference_code' => $pricing->discountReferenceCode,
                    'discount_irr' => $pricing->discountIrr,
                    'final_price_irr' => $finalPriceIrr,
                    'currency' => 'IRR',
                    'configuration_snapshot' => $snapshotJson,
                    'configuration_snapshot_hash' => $snapshotHash,
                    'creation_correlation_id' => $correlationId,
                    'valid_from' => $nowString,
                    'expires_at' => $this->databaseDateTime($expiresAt),
                    'created_at' => $nowString,
                ];
                if ($agentResolution !== null) {
                    $insert += $this->agentPricingColumns($agentResolution);
                }
                if ($servicePackage !== null) {
                    $insert += [
                        'service_subscription_id' => $servicePackage['service_subscription_id'],
                        'service_subscription_public_id' => $servicePackage['service_subscription_public_id'],
                        'service_target_id_snapshot' => $servicePackage['service_target_id'],
                        'service_remote_identity_generation_snapshot' => $servicePackage['remote_identity_generation'],
                        'service_lifecycle_version_snapshot' => $servicePackage['lifecycle_version'],
                        'service_package_id_snapshot' => $servicePackage['package_id'],
                        'service_package_code_snapshot' => $servicePackage['package_code'],
                        'service_package_type_snapshot' => $servicePackage['package_type'],
                        'service_package_duration_days_snapshot' => $servicePackage['duration_days'],
                        'service_package_data_bytes_snapshot' => $servicePackage['data_bytes'],
                        'service_required_capability_code_snapshot' => $servicePackage['required_capability_code'],
                    ];
                }

                $quoteId = (int) $connection->table('quotes')->insertGetId($insert);

                $created = $this->quoteById($connection, $quoteId);
                if ($created === null) {
                    throw new RuntimeException('Quote persistence failed.');
                }

                return $this->quoteReceipt($created, $requestPayloadHash, false);
            });
        } catch (QueryException $exception) {
            return $this->database->connection()->transaction(function (Connection $connection) use (
                $quoteKey,
                $requestPayloadHash,
                $agentPricingContext,
                $exception,
            ): QuoteReceipt {
                $existing = $this->quoteByKey($connection, $quoteKey, true);
                if ($existing !== null) {
                    $receipt = $this->quoteReceipt($existing, $requestPayloadHash, true);
                    $this->assertAgentQuoteReplayAuthorized($connection, $receipt, $agentPricingContext);

                    return $receipt;
                }

                throw $exception;
            });
        }
    }
}
