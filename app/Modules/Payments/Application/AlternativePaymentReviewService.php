<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\AccessControl\Application\AdministratorUserPermissionAuthorizer;
use App\Modules\Payments\CardToCard\Application\CardToCardReviewDecisionService;
use App\Modules\Payments\GiftCard\Application\Contracts\GiftCardProviderEvidence;
use App\Modules\Payments\GiftCard\Application\GiftCardReviewDecisionService;
use App\Modules\Payments\Usdt\Application\Contracts\UsdtBlockchainVerificationEvidence;
use App\Modules\Payments\Usdt\Application\UsdtManualReviewDecisionService;
use App\Modules\Telegram\Application\Contracts\TelegramAlternativePaymentReview;
use App\Modules\Telegram\Application\TelegramAlternativePaymentReviewCase;
use App\Modules\Telegram\Application\TelegramAlternativePaymentReviewEvidence;
use App\Shared\Application\RestrictedValue;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * @phpstan-type C2cReviewRow object{
 *     review_public_id:mixed,
 *     review_state:mixed,
 *     candidate_count:mixed,
 *     created_at:mixed,
 *     transaction_id:mixed,
 *     subject_public_id:mixed,
 *     provider_code:mixed,
 *     provider_transaction_id:mixed,
 *     amount_irr:mixed,
 *     currency:mixed,
 *     reference:mixed,
 *     c2c_destination_account_id:mixed,
 *     occurred_at:mixed
 * }
 * @phpstan-type GiftCardReviewRow object{
 *     review_public_id:mixed,
 *     review_state:mixed,
 *     created_at:mixed,
 *     subject_public_id:mixed,
 *     masked_code:mixed,
 *     private_image_reference:mixed,
 *     claimed_face_value:mixed,
 *     claimed_currency:mixed,
 *     provider_code:mixed
 * }
 * @phpstan-type UsdtReviewRow object{
 *     review_public_id:mixed,
 *     review_state:mixed,
 *     created_at:mixed,
 *     subject_public_id:mixed,
 *     txid:mixed,
 *     private_evidence_reference:mixed,
 *     minimum_confirmations:mixed,
 *     amount_irr:mixed
 * }
 */
final readonly class AlternativePaymentReviewService implements TelegramAlternativePaymentReview
{
    private const USDT_MANUAL_PROVIDER = 'manual-review';

    public function __construct(
        private DatabaseManager $database,
        private AdministratorUserPermissionAuthorizer $administratorUsers,
        private CardToCardReviewDecisionService $cardToCard,
        private GiftCardReviewDecisionService $giftCards,
        private UsdtManualReviewDecisionService $usdt,
    ) {}

    public function availableFor(int $actorUserId): bool
    {
        return $this->administratorUsers->allowsUser($actorUserId, TelegramAlternativePaymentReview::PERMISSION);
    }

    /**
     * @return list<TelegramAlternativePaymentReviewCase>
     */
    public function pending(int $actorUserId, int $limit = 15): array
    {
        $this->administratorUsers->authorizeUser($actorUserId, TelegramAlternativePaymentReview::PERMISSION);
        if ($limit < 1 || $limit > 30) {
            throw new DomainException('Alternative-payment review list limit is invalid.');
        }

        $cases = [];
        foreach ($this->c2cRows($limit) as $row) {
            $cases[] = $this->c2cCase($row, false);
        }
        foreach ($this->giftCardRows($limit) as $row) {
            $cases[] = $this->giftCardCase($row);
        }
        foreach ($this->usdtRows($limit) as $row) {
            $cases[] = $this->usdtCase($row);
        }

        usort(
            $cases,
            static fn (TelegramAlternativePaymentReviewCase $left, TelegramAlternativePaymentReviewCase $right): int => strcmp($left->createdAt, $right->createdAt),
        );

        return array_slice($cases, 0, $limit);
    }

    public function find(int $actorUserId, string $kind, string $reviewPublicId): TelegramAlternativePaymentReviewCase
    {
        $this->administratorUsers->authorizeUser($actorUserId, TelegramAlternativePaymentReview::PERMISSION);
        $this->assertIdentity($kind, $reviewPublicId);

        return match ($kind) {
            'c2c' => $this->findC2c($reviewPublicId),
            'gift_card' => $this->findGiftCard($reviewPublicId),
            'usdt' => $this->findUsdt($reviewPublicId),
            default => throw new DomainException('Alternative-payment review kind is invalid.'),
        };
    }

    public function privateEvidence(
        int $actorUserId,
        string $kind,
        string $reviewPublicId,
    ): TelegramAlternativePaymentReviewEvidence {
        $this->administratorUsers->authorizeUser($actorUserId, TelegramAlternativePaymentReview::PERMISSION);
        $this->assertIdentity($kind, $reviewPublicId);

        return match ($kind) {
            'c2c' => $this->c2cPrivateEvidence($reviewPublicId),
            'gift_card' => $this->giftCardPrivateEvidence($reviewPublicId),
            'usdt' => $this->usdtPrivateEvidence($reviewPublicId),
            default => throw new DomainException('Alternative-payment review kind is invalid.'),
        };
    }

    public function approveC2c(
        int $actorUserId,
        string $reviewPublicId,
        string $reservationPublicId,
        string $reason,
        string $requestKey,
    ): void {
        $administratorId = $this->administratorUsers->authorizeUser($actorUserId, TelegramAlternativePaymentReview::PERMISSION);
        $this->assertIdentity('c2c', $reviewPublicId);
        if (! Str::isUlid($reservationPublicId)) {
            throw new DomainException('C2C review reservation public ID is invalid.');
        }

        $this->cardToCard->approve(
            $reviewPublicId,
            $reservationPublicId,
            $administratorId,
            $reason,
            $this->correlation('c2c-approve', $requestKey),
        );
    }

    public function approveGiftCard(
        int $actorUserId,
        string $reviewPublicId,
        string $externalRedemptionId,
        string $reason,
        string $requestKey,
    ): void {
        $administratorId = $this->administratorUsers->authorizeUser($actorUserId, TelegramAlternativePaymentReview::PERMISSION);
        $this->assertIdentity('gift_card', $reviewPublicId);
        $externalRedemptionId = trim($externalRedemptionId);
        if (preg_match('/\A[\x21-\x7E]{1,191}\z/', $externalRedemptionId) !== 1) {
            throw new DomainException('Gift-card external redemption ID is invalid.');
        }

        $authority = $this->database->connection()->table('gift_card_reviews as review')
            ->join('gift_card_submissions as submission', 'submission.id', '=', 'review.gift_card_submission_id')
            ->join('gift_card_types as type', 'type.id', '=', 'submission.gift_card_type_id')
            ->where('review.public_id', strtoupper($reviewPublicId))
            ->first([
                'review.public_id as review_public_id',
                'review.created_at as review_created_at',
                'submission.public_id as submission_public_id',
                'submission.claimed_face_value',
                'submission.claimed_currency',
                'submission.claimed_brand',
                'submission.claimed_region',
                'type.provider_code',
            ]);
        if ($authority === null) {
            throw new DomainException('Gift-card review does not exist.');
        }

        $occurredAt = $this->storedDateTime((string) $authority->review_created_at, 'Gift-card review timestamp');
        $providerCode = (string) $authority->provider_code;
        $providerEventId = 'manual-review:'.substr(hash(
            'sha256',
            strtoupper($reviewPublicId)."\0".$externalRedemptionId,
        ), 0, 64);
        $evidenceHash = hash('sha256', json_encode([
            'source' => 'telegram_administrator',
            'review_public_id' => strtoupper($reviewPublicId),
            'submission_public_id' => (string) $authority->submission_public_id,
            'provider_code' => $providerCode,
            'provider_event_id' => $providerEventId,
            'provider_transaction_id' => $externalRedemptionId,
            'face_value' => (int) $authority->claimed_face_value,
            'currency' => (string) $authority->claimed_currency,
            'brand' => (string) $authority->claimed_brand,
            'region' => $authority->claimed_region === null ? null : (string) $authority->claimed_region,
            'occurred_at' => $occurredAt->format(DATE_ATOM),
        ], JSON_THROW_ON_ERROR));

        $evidence = new GiftCardProviderEvidence(
            'redeem',
            'success',
            'redeemed',
            $providerEventId,
            $externalRedemptionId,
            (int) $authority->claimed_face_value,
            (string) $authority->claimed_currency,
            (string) $authority->claimed_brand,
            $authority->claimed_region === null ? null : (string) $authority->claimed_region,
            $occurredAt,
            $evidenceHash,
            [
                'source' => 'telegram_administrator',
                'review_public_id' => strtoupper($reviewPublicId),
            ],
        );

        $this->giftCards->approveRedeemed(
            $reviewPublicId,
            $administratorId,
            $reason,
            $providerCode,
            $evidence,
            $this->correlation('gift-card-approve', $requestKey),
        );
    }

    public function approveUsdt(
        int $actorUserId,
        string $reviewPublicId,
        int $confirmations,
        string $transactionAt,
        string $reason,
        string $requestKey,
    ): void {
        $administratorId = $this->administratorUsers->authorizeUser($actorUserId, TelegramAlternativePaymentReview::PERMISSION);
        $this->assertIdentity('usdt', $reviewPublicId);
        if ($confirmations < 1 || $confirmations > 10_000_000) {
            throw new DomainException('USDT confirmation count is invalid.');
        }

        try {
            $transactionTime = (new DateTimeImmutable(trim($transactionAt)))->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable $exception) {
            throw new DomainException('USDT transaction time is invalid.', previous: $exception);
        }

        $authority = $this->database->connection()->table('usdt_manual_reviews as review')
            ->join('usdt_txid_submissions as submission', 'submission.id', '=', 'review.usdt_txid_submission_id')
            ->join('usdt_payment_authorities as authority', 'authority.id', '=', 'submission.usdt_payment_authority_id')
            ->where('review.public_id', strtoupper($reviewPublicId))
            ->first([
                'review.public_id as review_public_id',
                'review.created_at as review_created_at',
                'submission.public_id as submission_public_id',
                'submission.txid',
                'authority.network',
                'authority.chain_id',
                'authority.token_contract',
                'authority.token_decimals',
                'authority.destination_address',
                'authority.expected_amount_base_units',
            ]);
        if ($authority === null) {
            throw new DomainException('USDT manual review does not exist.');
        }

        $reviewCreatedAt = $this->storedDateTime((string) $authority->review_created_at, 'USDT review timestamp');
        $observedAt = $transactionTime > $reviewCreatedAt ? $transactionTime : $reviewCreatedAt;
        $providerEventId = 'manual-review:'.substr(hash(
            'sha256',
            strtoupper($reviewPublicId)."\0".strtolower((string) $authority->txid)."\0".$confirmations."\0".$transactionTime->format(DATE_ATOM),
        ), 0, 64);
        $evidenceHash = hash('sha256', json_encode([
            'source' => 'telegram_administrator',
            'review_public_id' => strtoupper($reviewPublicId),
            'txid' => strtolower((string) $authority->txid),
            'network' => (string) $authority->network,
            'chain_id' => (int) $authority->chain_id,
            'token_contract' => strtolower((string) $authority->token_contract),
            'destination_address' => strtolower((string) $authority->destination_address),
            'amount_base_units' => (string) $authority->expected_amount_base_units,
            'token_decimals' => (int) $authority->token_decimals,
            'confirmations' => $confirmations,
            'transaction_at' => $transactionTime->format(DATE_ATOM),
            'provider_event_id' => $providerEventId,
        ], JSON_THROW_ON_ERROR));

        $evidence = new UsdtBlockchainVerificationEvidence(
            'success',
            'success',
            $providerEventId,
            strtolower((string) $authority->txid),
            (string) $authority->network,
            (int) $authority->chain_id,
            strtolower((string) $authority->token_contract),
            strtolower((string) $authority->destination_address),
            (string) $authority->expected_amount_base_units,
            (int) $authority->token_decimals,
            $confirmations,
            null,
            $transactionTime,
            $observedAt,
            $evidenceHash,
            [
                'source' => 'telegram_administrator',
                'review_public_id' => strtoupper($reviewPublicId),
            ],
        );

        $this->usdt->approveVerified(
            $reviewPublicId,
            $administratorId,
            $reason,
            self::USDT_MANUAL_PROVIDER,
            $evidence,
            $this->correlation('usdt-approve', $requestKey),
        );
    }

    public function reject(
        int $actorUserId,
        string $kind,
        string $reviewPublicId,
        string $reason,
        string $requestKey,
    ): void {
        $administratorId = $this->administratorUsers->authorizeUser($actorUserId, TelegramAlternativePaymentReview::PERMISSION);
        $this->assertIdentity($kind, $reviewPublicId);

        match ($kind) {
            'c2c' => $this->cardToCard->reject($reviewPublicId, $administratorId, $reason),
            'gift_card' => $this->giftCards->reject(
                $reviewPublicId,
                $administratorId,
                $reason,
                $this->correlation('gift-card-reject', $requestKey),
            ),
            'usdt' => $this->usdt->reject(
                $reviewPublicId,
                $administratorId,
                $reason,
                $this->correlation('usdt-reject', $requestKey),
            ),
            default => throw new DomainException('Alternative-payment review kind is invalid.'),
        };
    }

    private function c2cPrivateEvidence(string $reviewPublicId): TelegramAlternativePaymentReviewEvidence
    {
        $transaction = $this->database->connection()->table('c2c_match_reviews as review')
            ->join('c2c_bank_transactions as transaction', 'transaction.id', '=', 'review.c2c_bank_transaction_id')
            ->where('review.public_id', strtoupper($reviewPublicId))
            ->where('review.state', 'pending')
            ->first(['transaction.provider_code', 'transaction.provider_transaction_id']);
        if ($transaction === null) {
            throw new DomainException('Pending C2C review evidence does not exist.');
        }

        $submissionPublicId = $this->c2cManualSubmissionPublicId(
            (string) $transaction->provider_code,
            (string) $transaction->provider_transaction_id,
        );
        if ($submissionPublicId === null) {
            throw new DomainException('Pending C2C review has no deliverable private evidence.');
        }

        $submission = $this->database->connection()->table('c2c_manual_submissions')
            ->where('public_id', $submissionPublicId)
            ->first(['public_id', 'private_receipt_reference', 'evidence_hash']);
        if ($submission === null) {
            throw new DomainException('Pending C2C review private evidence is unavailable.');
        }

        return $this->paymentEvidence(
            'c2c',
            $reviewPublicId,
            'c2c_manual_submission',
            (string) $submission->public_id,
            $submission->private_receipt_reference,
            $submission->evidence_hash,
        );
    }

    private function giftCardPrivateEvidence(string $reviewPublicId): TelegramAlternativePaymentReviewEvidence
    {
        $submission = $this->database->connection()->table('gift_card_reviews as review')
            ->join('gift_card_submissions as submission', 'submission.id', '=', 'review.gift_card_submission_id')
            ->where('review.public_id', strtoupper($reviewPublicId))
            ->where('review.state', 'pending')
            ->first(['submission.public_id', 'submission.private_image_reference', 'submission.image_content_hash']);
        if ($submission === null) {
            throw new DomainException('Pending Gift Card review private evidence does not exist.');
        }

        return $this->paymentEvidence(
            'gift_card',
            $reviewPublicId,
            'gift_card_submission',
            (string) $submission->public_id,
            $submission->private_image_reference,
            $submission->image_content_hash,
        );
    }

    private function usdtPrivateEvidence(string $reviewPublicId): TelegramAlternativePaymentReviewEvidence
    {
        $submission = $this->database->connection()->table('usdt_manual_reviews as review')
            ->join('usdt_txid_submissions as submission', 'submission.id', '=', 'review.usdt_txid_submission_id')
            ->where('review.public_id', strtoupper($reviewPublicId))
            ->where('review.state', 'pending')
            ->first(['submission.public_id', 'submission.private_evidence_reference', 'submission.evidence_content_hash']);
        if ($submission === null) {
            throw new DomainException('Pending USDT review private evidence does not exist.');
        }

        return $this->paymentEvidence(
            'usdt',
            $reviewPublicId,
            'usdt_txid_submission',
            (string) $submission->public_id,
            $submission->private_evidence_reference,
            $submission->evidence_content_hash,
        );
    }

    private function paymentEvidence(
        string $kind,
        string $reviewPublicId,
        string $associationType,
        string $associationPublicId,
        mixed $privateReference,
        mixed $contentSha256,
    ): TelegramAlternativePaymentReviewEvidence {
        if (! is_string($privateReference)
            || preg_match('/\Atelegram-private-media:([0-9A-HJKMNP-TV-Z]{26})\z/i', $privateReference, $matches) !== 1
            || ! is_string($contentSha256)
            || preg_match('/\A[0-9a-f]{64}\z/', strtolower($contentSha256)) !== 1) {
            throw new DomainException('Alternative-payment review private evidence is unavailable.');
        }

        return new TelegramAlternativePaymentReviewEvidence(
            $kind,
            strtoupper($reviewPublicId),
            $associationType,
            strtoupper($associationPublicId),
            RestrictedValue::fromString('telegram-private-media:'.strtoupper($matches[1])),
            RestrictedValue::fromString(strtolower($contentSha256)),
        );
    }

    private function c2cManualSubmissionPublicId(string $providerCode, string $providerTransactionId): ?string
    {
        if ($providerCode !== 'manual-receipt'
            || preg_match('/\Areceipt:([0-9A-HJKMNP-TV-Z]{26})\z/i', $providerTransactionId, $matches) !== 1) {
            return null;
        }

        return strtoupper($matches[1]);
    }

    /** @param C2cReviewRow $row */
    private function c2cPrivateEvidenceAvailable(object $row): bool
    {
        $submissionPublicId = $this->c2cManualSubmissionPublicId(
            (string) $row->provider_code,
            (string) $row->provider_transaction_id,
        );
        if ($submissionPublicId === null) {
            return false;
        }

        return $this->isTelegramPrivateMediaReference(
            $this->database->connection()->table('c2c_manual_submissions')
                ->where('public_id', $submissionPublicId)
                ->value('private_receipt_reference'),
        );
    }

    private function isTelegramPrivateMediaReference(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\Atelegram-private-media:[0-9A-HJKMNP-TV-Z]{26}\z/i', $value) === 1;
    }

    private function findC2c(string $reviewPublicId): TelegramAlternativePaymentReviewCase
    {
        /** @var C2cReviewRow|null $row */
        $row = $this->database->connection()->table('c2c_match_reviews as review')
            ->join('c2c_bank_transactions as transaction', 'transaction.id', '=', 'review.c2c_bank_transaction_id')
            ->where('review.public_id', strtoupper($reviewPublicId))
            ->where('review.state', 'pending')
            ->first([
                'review.public_id as review_public_id',
                'review.state as review_state',
                'review.candidate_count',
                'review.created_at',
                'transaction.id as transaction_id',
                'transaction.public_id as subject_public_id',
                'transaction.provider_code',
                'transaction.provider_transaction_id',
                'transaction.amount_irr',
                'transaction.currency',
                'transaction.reference',
                'transaction.c2c_destination_account_id',
                'transaction.occurred_at',
            ]);
        if ($row === null) {
            throw new DomainException('Pending C2C review does not exist.');
        }

        return $this->c2cCase($row, true);
    }

    private function findGiftCard(string $reviewPublicId): TelegramAlternativePaymentReviewCase
    {
        /** @var GiftCardReviewRow|null $row */
        $row = $this->database->connection()->table('gift_card_reviews as review')
            ->join('gift_card_submissions as submission', 'submission.id', '=', 'review.gift_card_submission_id')
            ->join('gift_card_types as type', 'type.id', '=', 'submission.gift_card_type_id')
            ->where('review.public_id', strtoupper($reviewPublicId))
            ->where('review.state', 'pending')
            ->first([
                'review.public_id as review_public_id',
                'review.state as review_state',
                'review.created_at',
                'submission.public_id as subject_public_id',
                'submission.masked_code',
                'submission.private_image_reference',
                'submission.claimed_face_value',
                'submission.claimed_currency',
                'type.provider_code',
            ]);
        if ($row === null) {
            throw new DomainException('Pending Gift Card review does not exist.');
        }

        return $this->giftCardCase($row);
    }

    private function findUsdt(string $reviewPublicId): TelegramAlternativePaymentReviewCase
    {
        /** @var UsdtReviewRow|null $row */
        $row = $this->database->connection()->table('usdt_manual_reviews as review')
            ->join('usdt_txid_submissions as submission', 'submission.id', '=', 'review.usdt_txid_submission_id')
            ->join('usdt_payment_authorities as authority', 'authority.id', '=', 'submission.usdt_payment_authority_id')
            ->join('payment_intents as intent', 'intent.id', '=', 'submission.payment_intent_id')
            ->where('review.public_id', strtoupper($reviewPublicId))
            ->where('review.state', 'pending')
            ->first([
                'review.public_id as review_public_id',
                'review.state as review_state',
                'review.created_at',
                'submission.public_id as subject_public_id',
                'submission.txid',
                'submission.private_evidence_reference',
                'authority.minimum_confirmations',
                'intent.amount_irr',
            ]);
        if ($row === null) {
            throw new DomainException('Pending USDT review does not exist.');
        }

        return $this->usdtCase($row);
    }

    /** @return list<C2cReviewRow> */
    private function c2cRows(int $limit): array
    {
        /** @var list<C2cReviewRow> $rows */
        $rows = array_values($this->database->connection()->table('c2c_match_reviews as review')
            ->join('c2c_bank_transactions as transaction', 'transaction.id', '=', 'review.c2c_bank_transaction_id')
            ->where('review.state', 'pending')
            ->orderBy('review.created_at')
            ->limit($limit)
            ->get([
                'review.public_id as review_public_id',
                'review.state as review_state',
                'review.candidate_count',
                'review.created_at',
                'transaction.id as transaction_id',
                'transaction.public_id as subject_public_id',
                'transaction.provider_code',
                'transaction.provider_transaction_id',
                'transaction.amount_irr',
                'transaction.currency',
                'transaction.reference',
                'transaction.c2c_destination_account_id',
                'transaction.occurred_at',
            ])
            ->all());

        return $rows;
    }

    /** @return list<GiftCardReviewRow> */
    private function giftCardRows(int $limit): array
    {
        /** @var list<GiftCardReviewRow> $rows */
        $rows = array_values($this->database->connection()->table('gift_card_reviews as review')
            ->join('gift_card_submissions as submission', 'submission.id', '=', 'review.gift_card_submission_id')
            ->join('gift_card_types as type', 'type.id', '=', 'submission.gift_card_type_id')
            ->where('review.state', 'pending')
            ->orderBy('review.created_at')
            ->limit($limit)
            ->get([
                'review.public_id as review_public_id',
                'review.state as review_state',
                'review.created_at',
                'submission.public_id as subject_public_id',
                'submission.masked_code',
                'submission.private_image_reference',
                'submission.claimed_face_value',
                'submission.claimed_currency',
                'type.provider_code',
            ])
            ->all());

        return $rows;
    }

    /** @return list<UsdtReviewRow> */
    private function usdtRows(int $limit): array
    {
        /** @var list<UsdtReviewRow> $rows */
        $rows = array_values($this->database->connection()->table('usdt_manual_reviews as review')
            ->join('usdt_txid_submissions as submission', 'submission.id', '=', 'review.usdt_txid_submission_id')
            ->join('usdt_payment_authorities as authority', 'authority.id', '=', 'submission.usdt_payment_authority_id')
            ->join('payment_intents as intent', 'intent.id', '=', 'submission.payment_intent_id')
            ->where('review.state', 'pending')
            ->orderBy('review.created_at')
            ->limit($limit)
            ->get([
                'review.public_id as review_public_id',
                'review.state as review_state',
                'review.created_at',
                'submission.public_id as subject_public_id',
                'submission.txid',
                'submission.private_evidence_reference',
                'authority.minimum_confirmations',
                'intent.amount_irr',
            ])
            ->all());

        return $rows;
    }

    /** @param C2cReviewRow $row */
    private function c2cCase(object $row, bool $includeCandidates): TelegramAlternativePaymentReviewCase
    {
        $candidates = $includeCandidates ? $this->c2cCandidates($row) : [];

        return new TelegramAlternativePaymentReviewCase(
            'c2c',
            (string) $row->review_public_id,
            (string) $row->subject_public_id,
            (string) $row->review_state,
            (string) $row->provider_code,
            $row->reference === null ? null : $this->boundedReference((string) $row->reference),
            (int) $row->amount_irr,
            (string) $row->currency,
            $this->c2cPrivateEvidenceAvailable($row),
            (int) $row->candidate_count,
            $candidates,
            null,
            (string) $row->created_at,
        );
    }

    /** @param GiftCardReviewRow $row */
    private function giftCardCase(object $row): TelegramAlternativePaymentReviewCase
    {
        return new TelegramAlternativePaymentReviewCase(
            'gift_card',
            (string) $row->review_public_id,
            (string) $row->subject_public_id,
            (string) $row->review_state,
            (string) $row->provider_code,
            $row->masked_code === null ? null : $this->boundedReference((string) $row->masked_code),
            (int) $row->claimed_face_value,
            (string) $row->claimed_currency,
            $this->isTelegramPrivateMediaReference($row->private_image_reference),
            1,
            [],
            null,
            (string) $row->created_at,
        );
    }

    /** @param UsdtReviewRow $row */
    private function usdtCase(object $row): TelegramAlternativePaymentReviewCase
    {
        $txid = strtolower((string) $row->txid);
        $reference = strlen($txid) > 20
            ? substr($txid, 0, 10).'…'.substr($txid, -8)
            : $txid;

        return new TelegramAlternativePaymentReviewCase(
            'usdt',
            (string) $row->review_public_id,
            (string) $row->subject_public_id,
            (string) $row->review_state,
            self::USDT_MANUAL_PROVIDER,
            $reference,
            (int) $row->amount_irr,
            'IRR',
            $this->isTelegramPrivateMediaReference($row->private_evidence_reference),
            1,
            [],
            (int) $row->minimum_confirmations,
            (string) $row->created_at,
        );
    }

    /**
     * @param  C2cReviewRow  $row
     * @return list<string>
     */
    private function c2cCandidates(object $row): array
    {
        return array_values($this->database->connection()->table('c2c_amount_reservations as reservation')
            ->join('payment_intents as intent', 'intent.id', '=', 'reservation.payment_intent_id')
            ->where('reservation.c2c_destination_account_id', $row->c2c_destination_account_id)
            ->where('reservation.payable_amount_irr', $row->amount_irr)
            ->where('reservation.reserved_at', '<=', $row->occurred_at)
            ->where('reservation.late_review_until', '>=', $row->occurred_at)
            ->where('intent.purpose', 'purchase')
            ->where('intent.payment_method_code', 'card_to_card')
            ->where('intent.provider_code', 'card_to_card')
            ->whereIn('intent.state', ['awaiting_user_action', 'submitted'])
            ->whereNull('intent.captured_at')
            ->orderBy('reservation.id')
            ->limit(8)
            ->pluck('reservation.public_id')
            ->map(static fn (mixed $value): string => strtoupper((string) $value))
            ->all());
    }

    private function boundedReference(string $value): string
    {
        $value = trim($value);

        return mb_strlen($value) <= 64 ? $value : mb_substr($value, 0, 61).'...';
    }

    private function assertIdentity(string $kind, string $reviewPublicId): void
    {
        if (! in_array($kind, ['c2c', 'gift_card', 'usdt'], true) || ! Str::isUlid($reviewPublicId)) {
            throw new DomainException('Alternative-payment review identity is invalid.');
        }
    }

    private function storedDateTime(string $value, string $label): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (! $date instanceof DateTimeImmutable) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $date;
    }

    private function correlation(string $operation, string $requestKey): string
    {
        if ($requestKey === '' || strlen($requestKey) > 512) {
            throw new DomainException('Alternative-payment review request key is invalid.');
        }

        return hash('sha256', 'alternative-payment-review'."\0".$operation."\0".$requestKey);
    }
}
