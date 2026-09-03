<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Application;

use App\Modules\Orders\Application\Contracts\QuoteDiscountAuthority;
use App\Modules\Orders\Application\QuoteDiscountAuthorization;
use App\Modules\Orders\Application\QuoteDiscountAuthorizationRequest;
use App\Modules\Orders\Application\QuoteDiscountConsumptionReceipt;
use App\Modules\Orders\Application\QuoteDiscountConsumptionRequest;
use App\Modules\Orders\Application\QuoteDiscountSourceQuoteUnavailable;
use App\Modules\Promotions\BenefitCodes\Application\BenefitCodeRedemptionContext;
use App\Modules\Promotions\BenefitCodes\Application\BenefitCodeRedemptionRequest;
use App\Modules\Promotions\BenefitCodes\Application\BenefitCodeService;
use App\Modules\Promotions\BenefitCodes\Domain\BenefitCodeType;
use App\Modules\Promotions\Domain\PromotionAction;
use App\Modules\Promotions\Domain\PromotionRuleKind;
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

/** @phpstan-type ConsumptionRow object{id:int|string,public_id:string,request_payload_hash:string,pricing_rule_resolution_id:int|string,discounted_quote_id:int|string,configuration_hash:string} */
final readonly class BenefitCodeDiscountQuoteAuthority implements QuoteDiscountAuthority
{
    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private BenefitCodeService $benefitCodes,
        private PromotionRuleService $promotions,
    ) {}

    /** @requirement PRO-001 PRO-002 BUY-002 DAT-002 DAT-003 DAT-004 SEC-001 SEC-002 */
    public function authorize(QuoteDiscountAuthorizationRequest $request): QuoteDiscountAuthorization
    {
        return $this->database->connection()->transaction(function (Connection $connection) use ($request): QuoteDiscountAuthorization {
            $sourceQuote = $this->sourceQuote($connection, $request);
            $offeringId = $this->positiveInt($sourceQuote->plan_offering_id, 'Discount source Quote offering ID');

            $redemption = $this->benefitCodes->redeem(
                new BenefitCodeRedemptionRequest(
                    'discount-quote-redemption:'.substr(hash('sha256', $request->authorizationKey), 0, 64),
                    $request->code,
                    $request->actorUserId,
                    $offeringId,
                    null,
                    $request->correlationId,
                ),
                new BenefitCodeRedemptionContext($request->actorUserId),
            );
            if ($redemption->type !== BenefitCodeType::DiscountGrant || $redemption->discountGrantPublicId === null) {
                throw new DomainException('Benefit code is not a discount grant.');
            }

            /** @var object{id:int|string,user_id:int|string,pricing_rule_id:int|string,pricing_rule_version_id:int|string,rule_code_snapshot:string,rule_version:int|string,rule_configuration_hash:string,configuration_hash:string}|null $grant */
            $grant = $connection->table('benefit_code_discount_grants')
                ->where('public_id', $redemption->discountGrantPublicId)
                ->lockForUpdate()
                ->first([
                    'id', 'user_id', 'pricing_rule_id', 'pricing_rule_version_id', 'rule_code_snapshot',
                    'rule_version', 'rule_configuration_hash', 'configuration_hash',
                ]);
            if ($grant === null || (int) $grant->user_id !== $request->actorUserId) {
                throw new AuthorizationException('Discount grant does not belong to the current customer.');
            }

            $resolution = $this->promotions->resolveSpecific(
                new PromotionSpecificResolutionRequest(
                    'discount-grant-resolution:'.substr(hash('sha256', $request->authorizationKey), 0, 64),
                    $request->actorUserId,
                    $offeringId,
                    PromotionAction::Purchase,
                    $this->positiveInt($sourceQuote->effective_price_irr, 'Discount source Quote effective price'),
                    $this->positiveInt($grant->pricing_rule_version_id, 'Discount grant rule version ID'),
                    $grant->rule_configuration_hash,
                ),
                new PromotionResolutionContext($request->actorUserId),
            );
            if (! $resolution->matched()
                || $resolution->ruleKind !== PromotionRuleKind::Promotion
                || ! hash_equals((string) $resolution->ruleCode, $grant->rule_code_snapshot)
                || $resolution->ruleVersion !== $this->positiveInt($grant->rule_version, 'Discount grant rule version')
                || ! hash_equals((string) $resolution->ruleConfigurationHash, $grant->rule_configuration_hash)
                || $resolution->discountIrr < 1) {
                throw new RuntimeException('Discount grant promotion resolution identity mismatch.');
            }

            return new QuoteDiscountAuthorization(
                $redemption->discountGrantPublicId,
                $grant->configuration_hash,
                $resolution->resolutionPublicId,
                $resolution->configurationSnapshotHash,
                $request->sourceQuotePublicId,
                $request->sourceQuoteConfigurationHash,
                (string) $resolution->ruleCode,
                $resolution->discountIrr,
                $redemption->replayed && $resolution->replayed,
            );
        }, 3);
    }

    /** @requirement PRO-001 PRO-002 BUY-002 DAT-002 DAT-003 DAT-004 SEC-001 SEC-002 */
    public function consume(QuoteDiscountConsumptionRequest $request): QuoteDiscountConsumptionReceipt
    {
        $payloadHash = $this->consumptionRequestHash($request);

        try {
            return $this->database->connection()->transaction(function (Connection $connection) use ($request, $payloadHash): QuoteDiscountConsumptionReceipt {
                $existing = $this->consumptionByKey($connection, $request->consumptionKey, true);
                if ($existing !== null) {
                    return $this->consumptionReceipt($connection, $existing, $payloadHash, true);
                }

                $authorization = $request->authorization;
                /** @var object{id:int|string,user_id:int|string}|null $grant */
                $grant = $connection->table('benefit_code_discount_grants')
                    ->where('public_id', $authorization->grantPublicId)
                    ->lockForUpdate()
                    ->first(['id', 'user_id']);
                /** @var object{id:int|string,user_id:int|string}|null $resolution */
                $resolution = $connection->table('pricing_rule_resolutions')
                    ->where('public_id', $authorization->resolutionPublicId)
                    ->lockForUpdate()
                    ->first(['id', 'user_id']);
                /** @var object{id:int|string,user_id:int|string}|null $sourceQuote */
                $sourceQuote = $connection->table('quotes')
                    ->where('public_id', $authorization->sourceQuotePublicId)
                    ->lockForUpdate()
                    ->first(['id', 'user_id']);
                /** @var object{id:int|string,user_id:int|string}|null $discountedQuote */
                $discountedQuote = $connection->table('quotes')
                    ->where('public_id', $request->discountedQuotePublicId)
                    ->lockForUpdate()
                    ->first(['id', 'user_id']);
                foreach ([$grant, $resolution, $sourceQuote, $discountedQuote] as $row) {
                    if ($row === null || (int) $row->user_id !== $request->actorUserId) {
                        throw new AuthorizationException('Discount Quote consumption ownership mismatch.');
                    }
                }

                $snapshot = [
                    'discount_irr' => $authorization->discountIrr,
                    'discounted_quote_configuration_hash' => $request->discountedQuoteConfigurationHash,
                    'discounted_quote_public_id' => $request->discountedQuotePublicId,
                    'grant_configuration_hash' => $authorization->grantConfigurationHash,
                    'grant_public_id' => $authorization->grantPublicId,
                    'resolution_configuration_hash' => $authorization->resolutionConfigurationHash,
                    'resolution_public_id' => $authorization->resolutionPublicId,
                    'rule_code' => $authorization->ruleCode,
                    'source_quote_configuration_hash' => $authorization->sourceQuoteConfigurationHash,
                    'source_quote_public_id' => $authorization->sourceQuotePublicId,
                ];
                ksort($snapshot, SORT_STRING);
                $snapshotJson = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                $snapshotHash = hash('sha256', $snapshotJson);
                $id = (int) $connection->table('benefit_code_discount_quote_consumptions')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'consumption_key' => $request->consumptionKey,
                    'request_payload_hash' => $payloadHash,
                    'user_id' => $request->actorUserId,
                    'benefit_code_discount_grant_id' => $this->positiveInt($grant->id, 'Discount grant ID'),
                    'pricing_rule_resolution_id' => $this->positiveInt($resolution->id, 'Promotion resolution ID'),
                    'source_quote_id' => $this->positiveInt($sourceQuote->id, 'Source Quote ID'),
                    'discounted_quote_id' => $this->positiveInt($discountedQuote->id, 'Discounted Quote ID'),
                    'rule_code_snapshot' => $authorization->ruleCode,
                    'discount_irr' => $authorization->discountIrr,
                    'grant_configuration_hash' => $authorization->grantConfigurationHash,
                    'pricing_rule_resolution_configuration_hash' => $authorization->resolutionConfigurationHash,
                    'source_quote_configuration_hash' => $authorization->sourceQuoteConfigurationHash,
                    'discounted_quote_configuration_hash' => $request->discountedQuoteConfigurationHash,
                    'configuration_snapshot' => $snapshotJson,
                    'configuration_hash' => $snapshotHash,
                    'correlation_id' => $request->correlationId,
                    'created_at' => $this->databaseDateTime($this->clock->now()),
                ]);

                $created = $this->consumptionById($connection, $id);
                if ($created === null) {
                    throw new RuntimeException('Discount Quote consumption persistence failed.');
                }

                return $this->consumptionReceipt($connection, $created, $payloadHash, false);
            }, 3);
        } catch (QueryException $exception) {
            $connection = $this->database->connection();
            $existing = $this->consumptionByKey($connection, $request->consumptionKey);
            if ($existing !== null) {
                return $this->consumptionReceipt($connection, $existing, $payloadHash, true);
            }

            throw $exception;
        }
    }

    /** @return object{id:int|string,public_id:string,user_id:int|string,plan_offering_id:int|string,offering_version:int|string,offering_configuration_hash:string,base_price_irr:int|string,effective_price_irr:int|string,discount_reference_code:string|null,discount_irr:int|string,currency:string,configuration_snapshot_hash:string,valid_from:string,expires_at:string} */
    private function sourceQuote(Connection $connection, QuoteDiscountAuthorizationRequest $request): object
    {
        /** @var object{id:int|string,public_id:string,user_id:int|string,account_type_snapshot:string,action_snapshot:string,plan_offering_id:int|string,offering_version:int|string,offering_configuration_hash:string,base_price_irr:int|string,override_source:string,override_reference_code:string|null,override_price_irr:int|string|null,effective_price_irr:int|string,discount_reference_code:string|null,discount_irr:int|string,currency:string,configuration_snapshot_hash:string,valid_from:string,expires_at:string}|null $quote */
        $quote = $connection->table('quotes')
            ->where('public_id', $request->sourceQuotePublicId)
            ->lockForUpdate()
            ->first([
                'id', 'public_id', 'user_id', 'account_type_snapshot', 'action_snapshot', 'plan_offering_id',
                'offering_version', 'offering_configuration_hash', 'base_price_irr', 'override_source',
                'override_reference_code', 'override_price_irr', 'effective_price_irr', 'discount_reference_code',
                'discount_irr', 'currency', 'configuration_snapshot_hash', 'valid_from', 'expires_at',
            ]);
        if ($quote === null
            || (int) $quote->user_id !== $request->actorUserId
            || $quote->account_type_snapshot !== 'customer'
            || $quote->action_snapshot !== 'purchase'
            || $quote->override_source !== 'none'
            || $quote->override_reference_code !== null
            || $quote->override_price_irr !== null
            || $quote->discount_reference_code !== null
            || (int) $quote->discount_irr !== 0
            || $quote->currency !== 'IRR'
            || ! hash_equals($quote->configuration_snapshot_hash, $request->sourceQuoteConfigurationHash)) {
            throw new QuoteDiscountSourceQuoteUnavailable('Source Quote is not eligible for a discount grant.');
        }
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        if ($now < $this->storedDateTime($quote->valid_from) || $now >= $this->storedDateTime($quote->expires_at)) {
            throw new QuoteDiscountSourceQuoteUnavailable('Source Quote has expired.');
        }
        $offeringId = $this->positiveInt($quote->plan_offering_id, 'Discount source Quote offering ID');
        $offeringVersion = $this->positiveInt($quote->offering_version, 'Discount source Quote offering version');
        /** @var object{version:int|string,base_price_irr:int|string,discount_eligible:int|bool|string,state:string,visibility:string}|null $offering */
        $offering = $connection->table('plan_offerings')
            ->where('id', $offeringId)
            ->lockForUpdate()
            ->first(['version', 'base_price_irr', 'discount_eligible', 'state', 'visibility']);
        $currentHash = $connection->table('plan_offering_histories')
            ->where('plan_offering_id', $offeringId)
            ->where('version', $offeringVersion)
            ->lockForUpdate()
            ->value('to_configuration_hash');
        if ($offering === null
            || (int) $offering->version !== $offeringVersion
            || ! is_string($currentHash)
            || ! hash_equals($currentHash, $quote->offering_configuration_hash)
            || (int) $offering->base_price_irr !== (int) $quote->base_price_irr
            || (int) $quote->effective_price_irr !== (int) $quote->base_price_irr
            || ! (bool) $offering->discount_eligible
            || $offering->state !== 'active'
            || $offering->visibility !== 'visible') {
            throw new QuoteDiscountSourceQuoteUnavailable('Source Quote commercial terms are stale.');
        }

        return $quote;
    }

    /** @return object{id:int|string,public_id:string,request_payload_hash:string,pricing_rule_resolution_id:int|string,discounted_quote_id:int|string,configuration_hash:string} */
    private function consumptionByKey(Connection $connection, string $key, bool $lock = false): ?object
    {
        $query = $connection->table('benefit_code_discount_quote_consumptions')->where('consumption_key', $key);
        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var ConsumptionRow|null $row */
        $row = $query->first(['id', 'public_id', 'request_payload_hash', 'pricing_rule_resolution_id', 'discounted_quote_id', 'configuration_hash']);

        return $row;
    }

    /** @return object{id:int|string,public_id:string,request_payload_hash:string,pricing_rule_resolution_id:int|string,discounted_quote_id:int|string,configuration_hash:string} */
    private function consumptionById(Connection $connection, int $id): ?object
    {
        /** @var ConsumptionRow|null $row */
        $row = $connection->table('benefit_code_discount_quote_consumptions')
            ->where('id', $id)
            ->first(['id', 'public_id', 'request_payload_hash', 'pricing_rule_resolution_id', 'discounted_quote_id', 'configuration_hash']);

        return $row;
    }

    /** @param ConsumptionRow $row */
    private function consumptionReceipt(Connection $connection, object $row, string $payloadHash, bool $replayed): QuoteDiscountConsumptionReceipt
    {
        if (! hash_equals((string) $row->request_payload_hash, $payloadHash)) {
            throw new RuntimeException('Discount Quote consumption key conflict.');
        }
        $resolutionPublicId = $connection->table('pricing_rule_resolutions')
            ->where('id', (int) $row->pricing_rule_resolution_id)
            ->value('public_id');
        $quotePublicId = $connection->table('quotes')
            ->where('id', (int) $row->discounted_quote_id)
            ->value('public_id');
        if (! is_string($resolutionPublicId) || ! is_string($quotePublicId)) {
            throw new RuntimeException('Discount Quote consumption public identity is unavailable.');
        }

        return new QuoteDiscountConsumptionReceipt(
            (string) $row->public_id,
            $resolutionPublicId,
            $quotePublicId,
            (string) $row->configuration_hash,
            $replayed,
        );
    }

    private function consumptionRequestHash(QuoteDiscountConsumptionRequest $request): string
    {
        $authorization = $request->authorization;

        return hash('sha256', json_encode([
            'actor_user_id' => $request->actorUserId,
            'discount_irr' => $authorization->discountIrr,
            'discounted_quote_configuration_hash' => $request->discountedQuoteConfigurationHash,
            'discounted_quote_public_id' => $request->discountedQuotePublicId,
            'grant_configuration_hash' => $authorization->grantConfigurationHash,
            'grant_public_id' => $authorization->grantPublicId,
            'resolution_configuration_hash' => $authorization->resolutionConfigurationHash,
            'resolution_public_id' => $authorization->resolutionPublicId,
            'rule_code' => $authorization->ruleCode,
            'source_quote_configuration_hash' => $authorization->sourceQuoteConfigurationHash,
            'source_quote_public_id' => $authorization->sourceQuotePublicId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function positiveInt(int|string $value, string $label): int
    {
        if (is_string($value) && preg_match('/\A[0-9]+\z/', $value) !== 1) {
            throw new RuntimeException($label.' is invalid.');
        }
        $integer = (int) $value;
        if ($integer < 1) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $integer;
    }

    private function storedDateTime(string $value): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if ($parsed === false) {
            throw new RuntimeException('Stored discount Quote timestamp is invalid.');
        }

        return $parsed;
    }

    private function databaseDateTime(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
