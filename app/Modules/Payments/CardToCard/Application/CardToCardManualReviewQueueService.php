<?php

declare(strict_types=1);

namespace App\Modules\Payments\CardToCard\Application;

use App\Modules\Payments\CardToCard\Application\Contracts\BankTransactionObservation;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class CardToCardManualReviewQueueService
{
    private const PROVIDER_CODE = 'manual-receipt';

    public function __construct(
        private DatabaseManager $database,
        private StringEncrypter $encrypter,
        private CardToCardBankTransactionService $transactions,
        private CardToCardMatchingService $matching,
    ) {}

    /** @requirement C2C-002 C2C-004 C2C-005 PAY-002 PAY-003 DAT-002 DAT-003 DAT-004 SEC-002 QUA-004 */
    public function queue(string $manualSubmissionPublicId, string $correlationId): CardToCardMatchReceipt
    {
        if (! Str::isUlid($manualSubmissionPublicId)) {
            throw new DomainException('C2C manual-review submission public ID is invalid.');
        }
        if (strlen($correlationId) < 8
            || strlen($correlationId) > 64
            || preg_match('/\A[A-Za-z0-9:_.-]+\z/', $correlationId) !== 1) {
            throw new DomainException('C2C manual-review correlation ID is invalid.');
        }

        $authority = $this->database->connection()
            ->table('c2c_manual_submissions as submission')
            ->join('c2c_amount_reservations as reservation', 'reservation.id', '=', 'submission.c2c_amount_reservation_id')
            ->join('c2c_destination_accounts as destination', 'destination.id', '=', 'submission.c2c_destination_account_id')
            ->where('submission.public_id', strtoupper($manualSubmissionPublicId))
            ->first([
                'submission.public_id',
                'submission.claimed_amount_irr',
                'submission.claimed_paid_at',
                'submission.reference',
                'submission.evidence_hash',
                'reservation.public_id as reservation_public_id',
                'destination.encrypted_card_number',
            ]);
        if ($authority === null) {
            throw new DomainException('C2C manual-review submission does not exist.');
        }

        $destinationCard = $this->encrypter->decryptString((string) $authority->encrypted_card_number);
        if (preg_match('/\A[0-9]{16}\z/', $destinationCard) !== 1) {
            throw new RuntimeException('C2C manual-review destination card authority is invalid.');
        }

        $occurredAt = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s.u',
            (string) $authority->claimed_paid_at,
            new DateTimeZone('UTC'),
        );
        if (! $occurredAt instanceof DateTimeImmutable) {
            throw new RuntimeException('C2C manual-review claimed payment timestamp is invalid.');
        }

        $submissionPublicId = strtoupper((string) $authority->public_id);
        $evidenceHash = strtolower((string) $authority->evidence_hash);
        $observation = new BankTransactionObservation(
            'receipt:'.$submissionPublicId,
            'receipt:'.$evidenceHash,
            $destinationCard,
            (int) $authority->claimed_amount_irr,
            'settled',
            $occurredAt,
            null,
            null,
            $authority->reference === null ? null : (string) $authority->reference,
            $evidenceHash,
        );

        $transaction = $this->transactions->ingest(
            self::PROVIDER_CODE,
            $observation,
            'manual',
            $correlationId,
        );

        return $this->matching->queueManualReview(
            $transaction->publicId,
            strtoupper((string) $authority->reservation_public_id),
            $correlationId,
        );
    }
}
