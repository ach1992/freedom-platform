<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application;

use App\Modules\Agents\Domain\AgentPricingAction;
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

    /** @requirement AGT-003 AGT-005 BUY-001 BUY-002 BUY-003 DAT-002 DAT-003 SEC-002 QUA-001 */
    public function previewForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $offeringSelectionToken,
        string $quotePublicId,
        string $quoteConfigurationHash,
    ): TelegramCustomerPurchaseQuotePreview {
        if ($actorUserId < 1 || $subjectUserId < 1 || $actorUserId !== $subjectUserId) {
            throw new AuthorizationException('Telegram purchase Quote self access denied.');
        }
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $quotePublicId) !== 1
            || preg_match('/\A[0-9a-f]{64}\z/', $quoteConfigurationHash) !== 1) {
            throw new RuntimeException('Telegram purchase Quote preview identity is invalid.');
        }

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $actorUserId,
            $subjectUserId,
            $offeringSelectionToken,
            $quotePublicId,
            $quoteConfigurationHash,
        ): TelegramCustomerPurchaseQuotePreview {
            $offering = $this->catalog->offeringForSelf($actorUserId, $subjectUserId, $offeringSelectionToken);
            $offeringIdentity = $this->currentOfferingIdentity($connection, $offering->offeringCode);
            try {
                $quote = $this->quotes->current($quotePublicId);
            } catch (RuntimeException $exception) {
                if ($exception->getMessage() === 'Quote has expired.') {
                    throw new AuthorizationException('Telegram purchase Quote preview is unavailable.', previous: $exception);
                }

                throw $exception;
            }
            if ($quote->userId !== $subjectUserId
                || $quote->accountType !== $offering->accountType
                || ! $this->pricingBindingMatchesAccountType($quote)
                || ! hash_equals($quote->configurationSnapshotHash, $quoteConfigurationHash)
                || ! hash_equals($quote->offeringCode, $offering->offeringCode)
                || $quote->offeringVersion !== $offeringIdentity['version']
                || ! hash_equals($quote->offeringConfigurationHash, $offeringIdentity['configuration_hash'])
                || $quote->basePriceIrr !== $offeringIdentity['base_price_irr']
                || $quote->basePriceIrr !== $offering->basePriceIrr
                || $quote->currency !== 'IRR') {
                throw new AuthorizationException('Telegram purchase Quote preview is unavailable.');
            }

            return new TelegramCustomerPurchaseQuotePreview(
                $offering,
                $quote->quotePublicId,
                $quote->configurationSnapshotHash,
                $quote->basePriceIrr,
                $quote->effectivePriceIrr,
                $quote->discountIrr,
                $quote->finalPriceIrr,
                $quote->currency,
                $quote->validFrom,
                $quote->expiresAt,
                true,
                $quote->accountType,
            );
        }, 3);
    }

    /** @requirement AGT-003 AGT-005 BUY-001 BUY-002 BUY-003 DAT-002 DAT-003 SEC-002 QUA-001 */
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
            $offeringIdentity = $this->currentOfferingIdentity($connection, $offering->offeringCode);
            $agentPricingContext = $offering->accountType === 'agent'
                ? new QuoteAgentPricingContext($subjectUserId, AgentPricingAction::Purchase)
                : null;

            $quote = $this->quotes->create(
                $quoteKey,
                $subjectUserId,
                $offeringIdentity['id'],
                new QuotePricingInput(
                    QuoteOverrideSource::None,
                    null,
                    null,
                    null,
                    0,
                    $expiresAt,
                ),
                $correlationId,
                $agentPricingContext,
            );

            $currentOffering = $this->catalog->offeringForSelf(
                $actorUserId,
                $subjectUserId,
                $offeringSelectionToken,
            );
            if (! hash_equals($quote->offeringCode, $currentOffering->offeringCode)
                || $quote->userId !== $subjectUserId
                || $quote->accountType !== $currentOffering->accountType
                || ! $this->pricingBindingMatchesAccountType($quote)
                || $quote->offeringVersion !== $offeringIdentity['version']
                || ! hash_equals($quote->offeringConfigurationHash, $offeringIdentity['configuration_hash'])
                || $quote->basePriceIrr !== $offeringIdentity['base_price_irr']
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
                $quote->accountType,
            );
        }, 3);
    }

    private function pricingBindingMatchesAccountType(QuoteReceipt $quote): bool
    {
        return match ($quote->accountType) {
            'agent' => $quote->agentPricing !== null,
            'customer' => $quote->agentPricing === null,
            default => false,
        };
    }

    /** @return array{id:int,version:int,configuration_hash:string,base_price_irr:int} */
    private function currentOfferingIdentity(Connection $connection, string $offeringCode): array
    {
        /** @var object{id:int|string,version:int|string,base_price_irr:int|string}|null $row */
        $row = $connection->table('plan_offerings')
            ->where('code', $offeringCode)
            ->lockForUpdate()
            ->first(['id', 'version', 'base_price_irr']);
        if ($row === null) {
            throw new AuthorizationException('Telegram purchase Quote offering is unavailable.');
        }

        $offeringId = $this->positiveDatabaseInt($row->id, 'Telegram purchase Quote offering ID');
        $version = $this->positiveDatabaseInt($row->version, 'Telegram purchase Quote offering version');
        $basePriceIrr = $this->nonNegativeDatabaseInt($row->base_price_irr, 'Telegram purchase Quote offering base price');
        $configurationHash = $connection->table('plan_offering_histories')
            ->where('plan_offering_id', $offeringId)
            ->where('version', $version)
            ->lockForUpdate()
            ->value('to_configuration_hash');
        if (! is_string($configurationHash) || preg_match('/\A[0-9a-f]{64}\z/', $configurationHash) !== 1) {
            throw new AuthorizationException('Telegram purchase Quote offering is unavailable.');
        }

        return [
            'id' => $offeringId,
            'version' => $version,
            'configuration_hash' => $configurationHash,
            'base_price_irr' => $basePriceIrr,
        ];
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

    private function nonNegativeDatabaseInt(mixed $value, string $label): int
    {
        $normalized = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($normalized === false) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $normalized;
    }
}
