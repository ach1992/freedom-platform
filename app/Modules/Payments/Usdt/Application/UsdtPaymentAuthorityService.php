<?php

declare(strict_types=1);

namespace App\Modules\Payments\Usdt\Application;

use App\Modules\Payments\Application\PurchasePaymentIntentService;
use App\Modules\Payments\Domain\PaymentIntentState;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class UsdtPaymentAuthorityService
{
    public const METHOD_CODE = 'usdt_bep20';

    public function __construct(
        private DatabaseManager $database,
        private PurchasePaymentIntentService $purchaseIntents,
        private Clock $clock,
    ) {}

    /** @requirement USDT-003 PAY-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-004 */
    public function prepare(
        string $authorityKey,
        string $intentCreationKey,
        int $userId,
        string $sourceQuotePublicId,
        string $eligibilityDecisionPublicId,
        string $amountQuotePublicId,
        string $correlationId,
    ): UsdtPaymentAuthorityReceipt {
        $this->assertKey($authorityKey, 'USDT payment authority key');
        $this->assertKey($intentCreationKey, 'USDT purchase intent creation key');
        if ($userId < 1 || ! Str::isUlid($sourceQuotePublicId) || ! Str::isUlid($eligibilityDecisionPublicId) || ! Str::isUlid($amountQuotePublicId)) {
            throw new DomainException('USDT payment preparation identity is invalid.');
        }
        $this->assertCorrelation($correlationId);

        try {
            return $this->database->connection()->transaction(function (Connection $connection) use (
                $authorityKey,
                $intentCreationKey,
                $userId,
                $sourceQuotePublicId,
                $eligibilityDecisionPublicId,
                $amountQuotePublicId,
                $correlationId,
            ): UsdtPaymentAuthorityReceipt {
                $existing = $connection->table('usdt_payment_authorities')->where('authority_key', $authorityKey)->lockForUpdate()->first();
                if ($existing !== null) {
                    return $this->replayReceipt($connection, $existing, $userId, $sourceQuotePublicId, $amountQuotePublicId);
                }

                [$chainId, $tokenContract, $minimumConfirmations] = $this->trustedPolicy();
                $now = $this->timestamp();
                $amountQuote = $connection->table('usdt_amount_quotes')
                    ->where('public_id', $amountQuotePublicId)
                    ->lockForUpdate()
                    ->first();
                if ($amountQuote === null) {
                    throw new DomainException('USDT amount quote does not exist.');
                }
                if ((int) $amountQuote->user_id !== $userId
                    || $amountQuote->source_quote_public_id !== $sourceQuotePublicId
                    || $amountQuote->network !== 'BEP20'
                    || $this->storedDateTime((string) $amountQuote->expires_at) <= $this->clock->now()) {
                    throw new DomainException('USDT amount quote is not current for this purchase.');
                }

                $intentReceipt = $this->purchaseIntents->create(
                    $intentCreationKey,
                    $userId,
                    $sourceQuotePublicId,
                    $eligibilityDecisionPublicId,
                    self::METHOD_CODE,
                    $correlationId,
                );
                if ($intentReceipt->state !== PaymentIntentState::AwaitingUserAction
                    || $intentReceipt->amount->currency() !== 'IRR'
                    || $intentReceipt->amount->amount() !== (int) $amountQuote->order_amount_irr) {
                    throw new RuntimeException('USDT purchase intent does not match the locked amount quote.');
                }
                $intent = $connection->table('payment_intents')->where('public_id', $intentReceipt->intentPublicId)->lockForUpdate()->first();
                if ($intent === null) {
                    throw new RuntimeException('USDT purchase intent authority disappeared during preparation.');
                }

                $expectedBaseUnits = UsdtTokenAmount::toBaseUnits((string) $amountQuote->exact_usdt);
                $policyHash = hash('sha256', json_encode([
                    'formula_version' => 'usdt-bep20-payment-v1',
                    'method_code' => self::METHOD_CODE,
                    'network' => 'BEP20',
                    'chain_id' => $chainId,
                    'token_contract' => $tokenContract,
                    'minimum_confirmations' => $minimumConfirmations,
                    'token_decimals' => UsdtTokenAmount::DECIMALS,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

                $authorityId = (int) $connection->table('usdt_payment_authorities')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'authority_key' => $authorityKey,
                    'payment_intent_id' => (int) $intent->id,
                    'usdt_amount_quote_id' => (int) $amountQuote->id,
                    'user_id' => $userId,
                    'source_quote_id' => (int) $amountQuote->source_quote_id,
                    'source_quote_public_id' => (string) $amountQuote->source_quote_public_id,
                    'source_amount_irr' => (int) $amountQuote->order_amount_irr,
                    'destination_wallet_version_id' => (int) $amountQuote->destination_wallet_version_id,
                    'destination_address' => strtolower((string) $amountQuote->destination_address),
                    'network' => 'BEP20',
                    'chain_id' => $chainId,
                    'token_contract' => $tokenContract,
                    'expected_amount_base_units' => $expectedBaseUnits,
                    'minimum_confirmations' => $minimumConfirmations,
                    'quote_expires_at' => (string) $amountQuote->expires_at,
                    'destination_configuration_hash' => strtolower((string) $amountQuote->destination_configuration_hash),
                    'amount_quote_configuration_hash' => strtolower((string) $amountQuote->configuration_snapshot_hash),
                    'policy_snapshot_hash' => $policyHash,
                    'created_at' => $now,
                ]);
                $stored = $connection->table('usdt_payment_authorities')->where('id', $authorityId)->first();
                if ($stored === null) {
                    throw new RuntimeException('USDT payment authority persistence failed.');
                }

                return $this->receipt($connection, $stored, false);
            }, 3);
        } catch (QueryException $exception) {
            $connection = $this->database->connection();
            $existing = $connection->table('usdt_payment_authorities')->where('authority_key', $authorityKey)->first();
            if ($existing === null) {
                throw $exception;
            }

            return $this->replayReceipt($connection, $existing, $userId, $sourceQuotePublicId, $amountQuotePublicId);
        }
    }

    private function replayReceipt(
        Connection $connection,
        object $existing,
        int $userId,
        string $sourceQuotePublicId,
        string $amountQuotePublicId,
    ): UsdtPaymentAuthorityReceipt {
        if ((int) $existing->user_id !== $userId || $existing->source_quote_public_id !== $sourceQuotePublicId) {
            throw new RuntimeException('USDT payment authority key conflicts with accepted preparation.');
        }
        $amountQuote = $connection->table('usdt_amount_quotes')->where('id', $existing->usdt_amount_quote_id)->first(['public_id']);
        if ($amountQuote === null || $amountQuote->public_id !== $amountQuotePublicId) {
            throw new RuntimeException('USDT payment authority replay conflicts with amount quote.');
        }

        return $this->receipt($connection, $existing, true);
    }

    /** @return array{int,string,int} */
    private function trustedPolicy(): array
    {
        $chainId = filter_var(config('payments.usdt_bep20.chain_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $minimumConfirmations = filter_var(config('payments.usdt_bep20.minimum_confirmations'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        $tokenContract = config('payments.usdt_bep20.token_contract');
        if ($chainId !== 56 || $minimumConfirmations === false || ! is_string($tokenContract)) {
            throw new RuntimeException('Trusted USDT BEP20 verification policy is not configured.');
        }
        $tokenContract = strtolower(trim($tokenContract));
        if (preg_match('/\A0x[a-f0-9]{40}\z/', $tokenContract) !== 1) {
            throw new RuntimeException('Trusted USDT BEP20 token contract is not configured securely.');
        }

        return [(int) $chainId, $tokenContract, (int) $minimumConfirmations];
    }

    private function receipt(Connection $connection, object $row, bool $replayed): UsdtPaymentAuthorityReceipt
    {
        $intentPublicId = $connection->table('payment_intents')->where('id', $row->payment_intent_id)->value('public_id');
        $amountQuotePublicId = $connection->table('usdt_amount_quotes')->where('id', $row->usdt_amount_quote_id)->value('public_id');
        if (! is_string($intentPublicId) || ! is_string($amountQuotePublicId)) {
            throw new RuntimeException('USDT payment authority linked identity is unavailable.');
        }

        return new UsdtPaymentAuthorityReceipt(
            (int) $row->id,
            (string) $row->public_id,
            $intentPublicId,
            $amountQuotePublicId,
            (int) $row->user_id,
            (int) $row->source_amount_irr,
            (string) $row->network,
            (int) $row->chain_id,
            (string) $row->token_contract,
            (string) $row->destination_address,
            (int) $row->expected_amount_base_units,
            (int) $row->minimum_confirmations,
            $this->storedDateTime((string) $row->quote_expires_at),
            $replayed,
        );
    }

    private function storedDateTime(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if ($date === false) {
            throw new RuntimeException('Stored USDT payment authority timestamp is invalid.');
        }
        return $date;
    }

    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function assertKey(string $value, string $label): void
    {
        if (strlen($value) < 8 || strlen($value) > 128 || preg_match('/\A[A-Za-z0-9:_.-]+\z/', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function assertCorrelation(string $value): void
    {
        if (strlen($value) !== 64 || preg_match('/\A[a-fA-F0-9]{64}\z/', $value) !== 1) {
            throw new DomainException('USDT payment correlation ID must be a SHA-256 hex digest.');
        }
    }
}
