<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application;

use App\Modules\Orders\Application\Contracts\QuoteDiscountAuthority;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseCatalog;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseDiscountQuote;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseDiscountQuotePreview;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseQuotePreview;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseQuoteRefreshRequired;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final readonly class TelegramCustomerPurchaseDiscountQuoteService implements TelegramCustomerPurchaseDiscountQuote
{
    private const QUOTE_TTL_MINUTES = 15;

    public function __construct(
        private DatabaseManager $database,
        private QuoteService $quotes,
        private QuoteDiscountAuthority $discounts,
        private TelegramCustomerPurchaseCatalog $catalog,
    ) {}

    /** @requirement BUY-001 BUY-002 BUY-003 PRO-001 PRO-002 DAT-002 DAT-003 SEC-001 SEC-002 QUA-001 */
    public function requoteForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $offeringSelectionToken,
        string $sourceQuotePublicId,
        string $sourceQuoteConfigurationHash,
        string $code,
        DateTimeImmutable $acceptedAt,
        string $operationKey,
    ): TelegramCustomerPurchaseDiscountQuotePreview {
        if ($actorUserId < 1 || $subjectUserId < 1 || $actorUserId !== $subjectUserId) {
            throw new AuthorizationException('Telegram discounted Quote self access denied.');
        }
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $sourceQuotePublicId) !== 1
            || preg_match('/\A[0-9a-f]{64}\z/', $sourceQuoteConfigurationHash) !== 1
            || preg_match('/\A[0-9a-f]{64}\z/', $operationKey) !== 1) {
            throw new RuntimeException('Telegram discounted Quote operation identity is invalid.');
        }
        $expiresAt = $acceptedAt->setTimezone(new DateTimeZone('UTC'))->modify('+'.self::QUOTE_TTL_MINUTES.' minutes');
        $correlationId = 'tg-discount:'.substr($operationKey, 0, 48);

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $actorUserId,
            $subjectUserId,
            $offeringSelectionToken,
            $sourceQuotePublicId,
            $sourceQuoteConfigurationHash,
            $code,
            $operationKey,
            $correlationId,
            $expiresAt,
        ): TelegramCustomerPurchaseDiscountQuotePreview {
            try {
                $offering = $this->catalog->offeringForSelf($actorUserId, $subjectUserId, $offeringSelectionToken);
            } catch (AuthorizationException $exception) {
                throw new TelegramCustomerPurchaseQuoteRefreshRequired(
                    'Telegram discount source Offering requires refresh.',
                    previous: $exception,
                );
            }
            try {
                $sourceQuote = $this->quotes->current($sourceQuotePublicId);
            } catch (\DomainException $exception) {
                throw new TelegramCustomerPurchaseQuoteRefreshRequired(
                    'Telegram discount source Quote requires refresh.',
                    previous: $exception,
                );
            } catch (RuntimeException $exception) {
                if ($exception->getMessage() !== 'Quote has expired.') {
                    throw $exception;
                }
                throw new TelegramCustomerPurchaseQuoteRefreshRequired(
                    'Telegram discount source Quote requires refresh.',
                    previous: $exception,
                );
            }
            if ($sourceQuote->accountType === 'agent' || $sourceQuote->agentPricing !== null) {
                throw new AuthorizationException('Benefit codes are unavailable for Agent purchase Quotes.');
            }
            if ($sourceQuote->accountType !== 'customer'
                || $sourceQuote->userId !== $subjectUserId
                || ! hash_equals($sourceQuote->configurationSnapshotHash, $sourceQuoteConfigurationHash)
                || ! hash_equals($sourceQuote->offeringCode, $offering->offeringCode)
                || $sourceQuote->basePriceIrr !== $offering->basePriceIrr
                || $sourceQuote->effectivePriceIrr !== $sourceQuote->basePriceIrr
                || $sourceQuote->discountIrr !== 0
                || $sourceQuote->discountReferenceCode !== null
                || $sourceQuote->currency !== 'IRR') {
                throw new TelegramCustomerPurchaseQuoteRefreshRequired('Telegram discount source Quote requires refresh.');
            }

            try {
                $authorization = $this->discounts->authorize(new QuoteDiscountAuthorizationRequest(
                    'telegram-discount-auth:'.$operationKey,
                    $actorUserId,
                    $sourceQuotePublicId,
                    $sourceQuoteConfigurationHash,
                    $code,
                    $correlationId,
                ));
            } catch (QuoteDiscountSourceQuoteUnavailable $exception) {
                throw new TelegramCustomerPurchaseQuoteRefreshRequired(
                    'Telegram discount source Quote requires refresh.',
                    previous: $exception,
                );
            }

            $offeringId = $connection->table('plan_offerings')->where('code', $offering->offeringCode)->value('id');
            if (! is_int($offeringId) && ! is_string($offeringId)) {
                throw new TelegramCustomerPurchaseQuoteRefreshRequired('Telegram discounted Quote Offering requires refresh.');
            }
            $quote = $this->quotes->create(
                'telegram-discount-quote:'.$operationKey,
                $subjectUserId,
                $this->positiveDatabaseInt($offeringId, 'Telegram discounted Quote offering ID'),
                new QuotePricingInput(
                    QuoteOverrideSource::None,
                    null,
                    null,
                    $authorization->ruleCode,
                    $authorization->discountIrr,
                    $expiresAt,
                ),
                $correlationId,
            );
            if ($quote->accountType !== 'customer'
                || $quote->agentPricing !== null
                || ! hash_equals($quote->offeringCode, $sourceQuote->offeringCode)
                || $quote->offeringVersion !== $sourceQuote->offeringVersion
                || ! hash_equals($quote->offeringConfigurationHash, $sourceQuote->offeringConfigurationHash)
                || $quote->basePriceIrr !== $sourceQuote->basePriceIrr
                || $quote->effectivePriceIrr !== $sourceQuote->effectivePriceIrr
                || $quote->discountIrr !== $authorization->discountIrr
                || ! hash_equals((string) $quote->discountReferenceCode, $authorization->ruleCode)
                || $quote->currency !== 'IRR') {
                throw new RuntimeException('Telegram discounted Quote does not match its discount authorization.');
            }

            $consumption = $this->discounts->consume(new QuoteDiscountConsumptionRequest(
                'telegram-discount-consume:'.$operationKey,
                $actorUserId,
                $authorization,
                $quote->quotePublicId,
                $quote->configurationSnapshotHash,
                $correlationId,
            ));
            try {
                $currentOffering = $this->catalog->offeringForSelf($actorUserId, $subjectUserId, $offeringSelectionToken);
            } catch (AuthorizationException $exception) {
                throw new TelegramCustomerPurchaseQuoteRefreshRequired(
                    'Telegram discounted Quote Offering requires refresh.',
                    previous: $exception,
                );
            }
            if ($currentOffering->accountType !== 'customer'
                || ! hash_equals($currentOffering->offeringCode, $quote->offeringCode)
                || $currentOffering->basePriceIrr !== $quote->basePriceIrr) {
                throw new TelegramCustomerPurchaseQuoteRefreshRequired(
                    'Telegram discounted Quote Offering changed before completion.',
                );
            }

            return new TelegramCustomerPurchaseDiscountQuotePreview(
                new TelegramCustomerPurchaseQuotePreview(
                    $currentOffering,
                    $quote->quotePublicId,
                    $quote->configurationSnapshotHash,
                    $quote->basePriceIrr,
                    $quote->effectivePriceIrr,
                    $quote->discountIrr,
                    $quote->finalPriceIrr,
                    $quote->currency,
                    $quote->validFrom,
                    $quote->expiresAt,
                    $quote->replayed,
                    $quote->accountType,
                ),
                $consumption->consumptionPublicId,
                $consumption->configurationHash,
                $consumption->resolutionPublicId,
                $quote->replayed && $consumption->replayed,
            );
        }, 3);
    }

    private function positiveDatabaseInt(mixed $value, string $label): int
    {
        $normalized = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($normalized === false) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $normalized;
    }
}
