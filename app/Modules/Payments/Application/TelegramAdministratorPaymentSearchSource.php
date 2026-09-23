<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\AccessControl\Application\AdministratorUserPermissionAuthorizer;
use App\Modules\Telegram\Application\Contracts\TelegramAdministratorSearchSource;
use App\Modules\Telegram\Application\TelegramAdministratorSearchItem;
use Database\Seeders\AdministratorSearchAccessFoundationSeeder;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final readonly class TelegramAdministratorPaymentSearchSource implements TelegramAdministratorSearchSource
{
    public function __construct(
        private DatabaseManager $database,
        private AdministratorUserPermissionAuthorizer $administrators,
    ) {}

    public function availableFor(int $actorUserId): bool
    {
        return $this->administrators->allowsUser(
            $actorUserId,
            AdministratorSearchPermissions::PAYMENT_PERMISSION,
        );
    }

    /** @return list<TelegramAdministratorSearchItem> */
    public function search(int $actorUserId, string $botId, string $query): array
    {
        $this->administrators->authorizeUser(
            $actorUserId,
            AdministratorSearchPermissions::PAYMENT_PERMISSION,
        );
        $mayViewEvidence = $this->administrators->allowsUser(
            $actorUserId,
            AdministratorSearchPermissions::PAYMENT_EVIDENCE_PERMISSION,
        );

        $connection = $this->database->connection();
        $items = [];
        $ulid = preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $query) === 1
            ? strtoupper($query)
            : null;

        if ($ulid !== null) {
            $intent = $connection->table('payment_intents as intent')
                ->join('users as user', 'user.id', '=', 'intent.user_id')
                ->where('intent.public_id', $ulid)
                ->first([
                    'intent.public_id',
                    'intent.state',
                    'intent.provider_code',
                    'intent.amount_irr',
                    'user.public_id as owner_public_id',
                ]);
            if ($intent !== null) {
                $items[] = new TelegramAdministratorSearchItem(
                    'payment_intent',
                    $this->databaseUlid($intent->public_id ?? null, 'Administrator search Payment Intent public ID'),
                    $this->databaseToken($intent->state ?? null, 'Administrator search Payment Intent state'),
                    $this->databaseUlid($intent->owner_public_id ?? null, 'Administrator search Payment Intent owner public ID'),
                    $this->databaseProvider($intent->provider_code ?? null),
                    amountIrr: $this->databaseNonNegativeInt($intent->amount_irr ?? null, 'Administrator search Payment Intent amount'),
                );
            }

            $settlement = $connection->table('purchase_settlements as settlement')
                ->join('users as user', 'user.id', '=', 'settlement.user_id')
                ->where('settlement.public_id', $ulid)
                ->first([
                    'settlement.public_id',
                    'settlement.provider_code',
                    'settlement.provider_transaction_id',
                    'settlement.amount_irr',
                    'user.public_id as owner_public_id',
                ]);
            if ($settlement !== null) {
                $reference = $this->databaseReference(
                    $settlement->provider_transaction_id ?? null,
                    'Administrator search settlement provider transaction ID',
                );
                $items[] = new TelegramAdministratorSearchItem(
                    'purchase_settlement',
                    $this->databaseUlid($settlement->public_id ?? null, 'Administrator search settlement public ID'),
                    'captured',
                    $this->databaseUlid($settlement->owner_public_id ?? null, 'Administrator search settlement owner public ID'),
                    $this->databaseProvider($settlement->provider_code ?? null),
                    $mayViewEvidence ? $reference : $this->maskReference($reference),
                    ! $mayViewEvidence,
                    $this->databaseNonNegativeInt($settlement->amount_irr ?? null, 'Administrator search settlement amount'),
                );
            }

            $receipt = $connection->table('c2c_manual_submissions as receipt')
                ->join('users as user', 'user.id', '=', 'receipt.submitted_by_user_id')
                ->where('receipt.public_id', $ulid)
                ->first([
                    'receipt.public_id',
                    'receipt.reference',
                    'receipt.claimed_amount_irr',
                    'user.public_id as owner_public_id',
                ]);
            if ($receipt !== null) {
                $reference = $receipt->reference === null
                    ? null
                    : $this->databaseReference($receipt->reference, 'Administrator search card receipt reference');
                $items[] = new TelegramAdministratorSearchItem(
                    'card_receipt',
                    $this->databaseUlid($receipt->public_id ?? null, 'Administrator search card receipt public ID'),
                    'submitted',
                    $this->databaseUlid($receipt->owner_public_id ?? null, 'Administrator search card receipt owner public ID'),
                    'card_to_card',
                    $reference === null ? null : ($mayViewEvidence ? $reference : $this->maskReference($reference)),
                    $reference !== null && ! $mayViewEvidence,
                    $this->databaseNonNegativeInt($receipt->claimed_amount_irr ?? null, 'Administrator search card receipt amount'),
                );
            }

            $gift = $connection->table('gift_card_submissions as gift')
                ->join('users as user', 'user.id', '=', 'gift.user_id')
                ->join('gift_card_types as type', 'type.id', '=', 'gift.gift_card_type_id')
                ->where('gift.public_id', $ulid)
                ->first([
                    'gift.public_id',
                    'gift.state',
                    'gift.masked_code',
                    'gift.claimed_face_value',
                    'type.provider_code',
                    'user.public_id as owner_public_id',
                ]);
            if ($gift !== null) {
                $maskedCode = $gift->masked_code;
                if ($maskedCode !== null && (! is_string($maskedCode) || $maskedCode === '' || mb_strlen($maskedCode) > 64)) {
                    throw new RuntimeException('Administrator search gift-card masked code is invalid.');
                }
                $items[] = new TelegramAdministratorSearchItem(
                    'gift_submission',
                    $this->databaseUlid($gift->public_id ?? null, 'Administrator search gift submission public ID'),
                    $this->databaseToken($gift->state ?? null, 'Administrator search gift submission state'),
                    $this->databaseUlid($gift->owner_public_id ?? null, 'Administrator search gift submission owner public ID'),
                    $this->databaseProvider($gift->provider_code ?? null),
                    $maskedCode,
                    $maskedCode !== null,
                    $this->databaseNonNegativeInt($gift->claimed_face_value ?? null, 'Administrator search gift face value'),
                );
            }
        }

        if ($this->canUseReferenceQuery($query)) {
            $providerRows = $connection->table('payment_provider_transactions as provider_tx')
                ->join('payment_intents as intent', 'intent.id', '=', 'provider_tx.payment_intent_id')
                ->join('users as user', 'user.id', '=', 'intent.user_id')
                ->where('provider_tx.provider_transaction_id', $query)
                ->orderByDesc('provider_tx.id')
                ->limit(3)
                ->get([
                    'intent.public_id as intent_public_id',
                    'intent.state',
                    'intent.amount_irr',
                    'provider_tx.provider_code',
                    'provider_tx.provider_transaction_id',
                    'user.public_id as owner_public_id',
                ]);
            foreach ($providerRows as $providerRow) {
                $reference = $this->databaseReference(
                    $providerRow->provider_transaction_id ?? null,
                    'Administrator search provider transaction ID',
                );
                $items[] = new TelegramAdministratorSearchItem(
                    'provider_transaction',
                    $this->databaseUlid($providerRow->intent_public_id ?? null, 'Administrator search provider Payment Intent public ID'),
                    $this->databaseToken($providerRow->state ?? null, 'Administrator search provider Payment Intent state'),
                    $this->databaseUlid($providerRow->owner_public_id ?? null, 'Administrator search provider owner public ID'),
                    $this->databaseProvider($providerRow->provider_code ?? null),
                    $mayViewEvidence ? $reference : $this->maskReference($reference),
                    ! $mayViewEvidence,
                    $this->databaseNonNegativeInt($providerRow->amount_irr ?? null, 'Administrator search provider Payment Intent amount'),
                );
            }

            $receiptRows = $connection->table('c2c_manual_submissions as receipt')
                ->join('users as user', 'user.id', '=', 'receipt.submitted_by_user_id')
                ->where('receipt.reference', $query)
                ->orderByDesc('receipt.id')
                ->limit(3)
                ->get([
                    'receipt.public_id',
                    'receipt.reference',
                    'receipt.claimed_amount_irr',
                    'user.public_id as owner_public_id',
                ]);
            foreach ($receiptRows as $receiptRow) {
                $reference = $this->databaseReference($receiptRow->reference ?? null, 'Administrator search card receipt reference');
                $items[] = new TelegramAdministratorSearchItem(
                    'card_receipt',
                    $this->databaseUlid($receiptRow->public_id ?? null, 'Administrator search card receipt public ID'),
                    'submitted',
                    $this->databaseUlid($receiptRow->owner_public_id ?? null, 'Administrator search card receipt owner public ID'),
                    'card_to_card',
                    $mayViewEvidence ? $reference : $this->maskReference($reference),
                    ! $mayViewEvidence,
                    $this->databaseNonNegativeInt($receiptRow->claimed_amount_irr ?? null, 'Administrator search card receipt amount'),
                );
            }
        }

        return $items;
    }

    private function canUseReferenceQuery(string $query): bool
    {
        return strlen($query) >= 3
            && strlen($query) <= 191
            && mb_check_encoding($query, 'UTF-8')
            && ! str_contains($query, "\0");
    }

    private function maskReference(string $reference): string
    {
        $length = mb_strlen($reference);
        if ($length <= 6) {
            return mb_substr($reference, 0, 1).str_repeat('*', max(1, $length - 2)).mb_substr($reference, -1);
        }

        return mb_substr($reference, 0, 3).str_repeat('*', max(3, $length - 6)).mb_substr($reference, -3);
    }

    private function databaseUlid(mixed $value, string $label): string
    {
        if (! is_string($value) || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $value) !== 1) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $value;
    }

    private function databaseToken(mixed $value, string $label): string
    {
        if (! is_string($value) || preg_match('/\A[a-z][a-z0-9_-]{1,63}\z/', $value) !== 1) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $value;
    }

    private function databaseProvider(mixed $value): string
    {
        if (! is_string($value) || preg_match('/\A[a-z0-9][a-z0-9_.-]{0,63}\z/', $value) !== 1) {
            throw new RuntimeException('Administrator search provider code is invalid.');
        }

        return $value;
    }

    private function databaseReference(mixed $value, string $label): string
    {
        if (! is_string($value) || $value === '' || mb_strlen($value) > 191 || ! mb_check_encoding($value, 'UTF-8')) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $value;
    }

    private function databaseNonNegativeInt(mixed $value, string $label): int
    {
        $normalized = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($normalized === false) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $normalized;
    }
}
