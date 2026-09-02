<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application;

use App\Modules\Orders\Domain\QuoteOverrideSource;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseCatalog;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseQuote;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseQuotePreview;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use RuntimeException;
use Symfony\Component\Uid\Ulid;

final readonly class TelegramCustomerPurchaseQuoteService implements TelegramCustomerPurchaseQuote
{
    private const QUOTE_TTL_MINUTES = 15;

    public function __construct(
        private DatabaseManager $database,
        private QuoteService $quotes,
        private TelegramCustomerPurchaseCatalog $catalog,
    ) {}

    /** @requirement BUY-001 BUY-002 BUY-003 DAT-002 DAT-003 SEC-002 QUA-001 */
    public function quoteForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $offeringSelectionToken,
        DateTimeImmutable $acceptedAt,
        string $quoteKey,
        string $correlationId,
    ): TelegramCustomerPurchaseQuotePreview {
        if ($actorUserId < 1 || $subjectUserId < 1 || $actorUserId !== $subjectUserId) {
            throw new AuthorizationException('Telegram purchase Quote self access denied.');
        }

        $this->callbackPublicId($quoteKey, $correlationId);
        $expiresAt = $acceptedAt
            ->setTimezone(new DateTimeZone('UTC'))
            ->modify('+'.self::QUOTE_TTL_MINUTES.' minutes');

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $actorUserId,
            $subjectUserId,
            $offeringSelectionToken,
            $quoteKey,
            $correlationId,
            $expiresAt,
        ): TelegramCustomerPurchaseQuotePreview {
            $offering = $this->catalog->offeringForSelf(
                $actorUserId,
                $subjectUserId,
                $offeringSelectionToken,
            );

            $offeringId = $connection->table('plan_offerings')
                ->where('code', $offering->offeringCode)
                ->value('id');
            if (! is_int($offeringId) && ! is_string($offeringId)) {
                throw new AuthorizationException('Telegram purchase Quote offering is unavailable.');
            }

            $quote = $this->quotes->create(
                $quoteKey,
                $subjectUserId,
                $this->positiveDatabaseInt($offeringId, 'Telegram purchase Quote offering ID'),
                new QuotePricingInput(
                    QuoteOverrideSource::None,
                    null,
                    null,
                    null,
                    0,
                    $expiresAt,
                ),
                $correlationId,
            );

            $currentOffering = $this->catalog->offeringForSelf(
                $actorUserId,
                $subjectUserId,
                $offeringSelectionToken,
            );
            if (! hash_equals($quote->offeringCode, $currentOffering->offeringCode)
                || $quote->userId !== $subjectUserId
                || $quote->basePriceIrr !== $currentOffering->basePriceIrr
                || $quote->currency !== 'IRR') {
                throw new RuntimeException('Telegram purchase Quote does not match the current selected offering.');
            }

            return new TelegramCustomerPurchaseQuotePreview(
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
            );
        }, 3);
    }

    private function callbackPublicId(string $quoteKey, string $correlationId): string
    {
        if (preg_match('/\Atelegram-purchase-quote:([0-9A-HJKMNP-TV-Z]{26})\z/i', $quoteKey, $matches) !== 1) {
            throw new RuntimeException('Telegram purchase Quote key is invalid.');
        }
        $publicId = strtoupper($matches[1]);
        if (! Ulid::isValid($publicId) || $correlationId !== 'tg-purchase-quote:'.$publicId) {
            throw new RuntimeException('Telegram purchase Quote callback identity is invalid.');
        }

        return $publicId;
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
