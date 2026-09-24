<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\AccessControl\Application\AdministratorUserPermissionAuthorizer;
use App\Modules\Payments\CardToCard\Application\CardToCardReviewDecisionService;
use App\Modules\Payments\GiftCard\Application\Contracts\GiftCardProviderEvidence;
use App\Modules\Payments\GiftCard\Application\GiftCardReviewDecisionService;
use App\Modules\Payments\Usdt\Application\Contracts\UsdtBlockchainVerificationEvidence;
use App\Modules\Payments\Usdt\Application\UsdtManualReviewDecisionService;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final readonly class AlternativePaymentReviewService
{
    public const PERMISSION = 'access.sensitive_actions.approve';

    private const USDT_MANUAL_PROVIDER = 'manual-review';

    public function __construct(
        private DatabaseManager $database,
        private AdministratorUserPermissionAuthorizer $administratorUsers,
        private CardToCardReviewDecisionService $cardToCard,
        private GiftCardReviewDecisionService $giftCards,
        private UsdtManualReviewDecisionService $usdt,
        private Clock $clock,
    ) {}

    public function availableFor(int $actorUserId): bool
    {
        return $this->administratorUsers->allowsUser($actorUserId, self::PERMISSION);
    }

    /**
     * @return list<AlternativePaymentReviewCase>
     */
    public function pending(int $actorUserId, int $limit = 15): array
    {
        $this->administratorUsers->authorizeUser($actorUserId, self::PERMISSION);
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
            static fn (AlternativePaymentReviewCase $left, AlternativePaymentReviewCase $right): int =>
                strcmp($left->createdAt, $right->createdAt),
        );

        return array_slice($cases, 0, $limit);
    }

    public function find(int $actorUserId, string $kind, string $reviewPublicId): AlternativePaymentReviewCase
    {
        $this->administratorUsers->authorizeUser($actorUserId, self::PERMISSION);
        $this->assertIdentity($kind, $reviewPublicId);

        return match ($kind) {
            'c2c' => $this->findC2c($reviewPublicId),
            'gift_card' => $this->findGiftCard($reviewPublicId),
            'usdt' => $this->findUsdt($reviewPublicId),
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
        $administratorId = $this->administratorUsers->authorizeUser($actorUserId, self::PERMISSION);
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
        $administratorId = $this->administratorUsers->authorizeUser($actorUserId, self::PERMISSION);
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
        $administratorId = $this->administratorUsers->authorizeUser($actorUserId, self::PERMISSION);
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
        $administratorId = $this->administratorUsers->authorizeUser($actorUserId, self::PERMISSION);
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

    private function findC2c(string $reviewPublicId): AlternativePaymentReviewCase
    {
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

    private function findGiftCard(string $reviewPublicId): AlternativePaymentReviewCase
    {
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

    private function findUsdt(string $reviewPublicId): AlternativePaymentReviewCase
    {
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

    /** @return list<object> */
    private function c2cRows(int $limit): array
    {
        return $this->database->connection()->table('c2c_match_reviews as review')
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
                'transaction.amount_irr',
                'transaction.currency',
                'transaction.reference',
                'transaction.c2c_destination_account_id',
                'transaction.occurred_at',
            ])
            ->all();
    }

    /** @return list<object> */
    private function giftCardRows(int $limit): array
    {
        return $this->database->connection()->table('gift_card_reviews as review')
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
            ->all();
    }

    /** @return list<object> */
    private function usdtRows(int $limit): array
    {
        return $this->database->connection()->table('usdt_manual_reviews as review')
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
            ->all();
    }

    private function c2cCase(object $row, bool $includeCandidates): AlternativePaymentReviewCase
    {
        $candidates = $includeCandidates ? $this->c2cCandidates($row) : [];

        return new AlternativePaymentReviewCase(
            'c2c',
            (string) $row->review_public_id,
            (string) $row->subject_public_id,
            (string) $row->review_state,
            (string) $row->provider_code,
            $row->reference === null ? null : $this->boundedReference((string) $row->reference),
            (int) $row->amount_irr,
            (string) $row->currency,
            str_starts_with((string) $row->provider_code, 'manual-receipt'),
            (int) $row->candidate_count,
            $candidates,
            null,
            (string) $row->created_at,
        );
    }

    private function giftCardCase(object $row): AlternativePaymentReviewCase
    {
        return new AlternativePaymentReviewCase(
            'gift_card',
            (string) $row->review_public_id,
            (string) $row->subject_public_id,
            (string) $row->review_state,
            (string) $row->provider_code,
            $row->masked_code === null ? null : $this->boundedReference((string) $row->masked_code),
            (int) $row->claimed_face_value,
            (string) $row->claimed_currency,
            $row->private_image_reference !== null,
            1,
            [],
            null,
            (string) $row->created_at,
        );
    }

    private function usdtCase(object $row): AlternativePaymentReviewCase
    {
        $txid = strtolower((string) $row->txid);
        $reference = strlen($txid) > 20
            ? substr($txid, 0, 10).'…'.substr($txid, -8)
            : $txid;

        return new AlternativePaymentReviewCase(
            'usdt',
            (string) $row->review_public_id,
            (string) $row->subject_public_id,
            (string) $row->review_state,
            self::USDT_MANUAL_PROVIDER,
            $reference,
            (int) $row->amount_irr,
            'IRR',
            $row->private_evidence_reference !== null,
            1,
            [],
            (int) $row->minimum_confirmations,
            (string) $row->created_at,
        );
    }

    /** @return list<string> */
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
