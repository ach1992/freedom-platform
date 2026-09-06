<?php

declare(strict_types=1);

namespace App\Modules\Payments\GiftCard\Application;

use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseGiftCardPayment;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseOrder;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchasePaymentMethods;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseGiftCardSubmission;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseGiftCardType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use RuntimeException;
use stdClass;

final readonly class TelegramCustomerPurchaseGiftCardPaymentService implements TelegramCustomerPurchaseGiftCardPayment
{
    private const METHOD_CODE = 'gift_card';

    public function __construct(
        private DatabaseManager $database,
        private TelegramCustomerPurchaseOrder $orders,
        private TelegramCustomerPurchasePaymentMethods $paymentMethods,
        private GiftCardSubmissionService $submissions,
        private GiftCardPaymentService $payments,
    ) {}

    /**
     * @return list<TelegramCustomerPurchaseGiftCardType>
     * @requirement BUY-001 BUY-003 PAY-001 PAY-002 PRO-001 GFT-001 GFT-003 DAT-002 DAT-003 SEC-002 QUA-001
     */
    public function availableTypesForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $orderPublicId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionPublicId,
        string $decisionConfigurationHash,
    ): array {
        $this->assertSelf($actorUserId, $subjectUserId);

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $actorUserId,
            $subjectUserId,
            $orderPublicId,
            $quotePublicId,
            $quoteConfigurationHash,
            $decisionPublicId,
            $decisionConfigurationHash,
        ): array {
            $this->authorizeCheckout(
                $actorUserId,
                $subjectUserId,
                $orderPublicId,
                $quotePublicId,
                $quoteConfigurationHash,
                $decisionPublicId,
                $decisionConfigurationHash,
            );

            return $connection->table('gift_card_types')
                ->where('active', true)
                ->where('verification_mode', 'manual_only')
                ->whereIn('submission_mode', ['code_only', 'either'])
                ->orderBy('type_code')
                ->get([
                    'type_code', 'display_name', 'brand', 'region', 'face_currency', 'configuration_hash',
                ])
                ->map(static fn (stdClass $row): TelegramCustomerPurchaseGiftCardType => new TelegramCustomerPurchaseGiftCardType(
                    (string) $row->type_code,
                    (string) $row->display_name,
                    (string) $row->brand,
                    $row->region === null ? null : (string) $row->region,
                    (string) $row->face_currency,
                    strtolower((string) $row->configuration_hash),
                ))
                ->values()
                ->all();
        }, 3);
    }

    /** @requirement BUY-001 BUY-003 PAY-001 PAY-002 PRO-001 GFT-001 GFT-002 GFT-003 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 QUA-004 */
    public function submitCodeForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $orderPublicId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionPublicId,
        string $decisionConfigurationHash,
        string $typeCode,
        string $typeConfigurationHash,
        int $claimedFaceValue,
        string $code,
        string $operationKey,
    ): TelegramCustomerPurchaseGiftCardSubmission {
        $this->assertSelf($actorUserId, $subjectUserId);
        $this->assertOperationKey($operationKey);
        if ($claimedFaceValue < 1) {
            throw new AuthorizationException('Telegram Gift Card face value is unavailable.');
        }
        if (preg_match('/\A[0-9a-f]{64}\z/', $typeConfigurationHash) !== 1) {
            throw new AuthorizationException('Telegram Gift Card type configuration is unavailable.');
        }

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $actorUserId,
            $subjectUserId,
            $orderPublicId,
            $quotePublicId,
            $quoteConfigurationHash,
            $decisionPublicId,
            $decisionConfigurationHash,
            $typeCode,
            $typeConfigurationHash,
            $claimedFaceValue,
            $code,
            $operationKey,
        ): TelegramCustomerPurchaseGiftCardSubmission {
            $this->authorizeCheckout(
                $actorUserId,
                $subjectUserId,
                $orderPublicId,
                $quotePublicId,
                $quoteConfigurationHash,
                $decisionPublicId,
                $decisionConfigurationHash,
            );
            $type = $this->manualCodeType($connection, $typeCode, $typeConfigurationHash, true);

            $submission = $this->submissions->submit(
                'telegram-gift-card-submission:'.$orderPublicId,
                'telegram-gift-card-intent:'.$orderPublicId,
                $subjectUserId,
                $quotePublicId,
                $decisionPublicId,
                (string) $type->type_code,
                $claimedFaceValue,
                (string) $type->face_currency,
                (string) $type->brand,
                $type->region === null ? null : (string) $type->region,
                $code,
                null,
                null,
                null,
                null,
                $this->correlationId('submit', $operationKey),
            );
            $processed = $this->payments->routeManualOnly(
                $submission->publicId,
                $this->correlationId('review', $operationKey),
            );
            if ($processed->state !== 'pending_manual_review'
                || $processed->reviewPublicId === null
                || $processed->redemptionPublicId !== null
                || $processed->purchaseSettlementPublicId !== null
                || ! hash_equals($submission->publicId, $processed->submissionPublicId)) {
                throw new RuntimeException('Telegram Gift Card manual-review result is inconsistent.');
            }

            return new TelegramCustomerPurchaseGiftCardSubmission(
                $submission->publicId,
                $submission->paymentIntentPublicId,
                $processed->reviewPublicId,
                $submission->typeCode,
                $submission->maskedCode,
                $submission->claimedFaceValue,
                $submission->claimedCurrency,
                $processed->state,
                $submission->replayed || $processed->replayed,
            );
        }, 3);
    }

    private function authorizeCheckout(
        int $actorUserId,
        int $subjectUserId,
        string $orderPublicId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionPublicId,
        string $decisionConfigurationHash,
    ): void {
        $order = $this->orders->currentForSelf(
            $actorUserId,
            $subjectUserId,
            $orderPublicId,
            $quotePublicId,
            $quoteConfigurationHash,
        );
        $selection = $this->paymentMethods->selectForSelf(
            $actorUserId,
            $subjectUserId,
            $quotePublicId,
            $quoteConfigurationHash,
            $decisionPublicId,
            $decisionConfigurationHash,
            self::METHOD_CODE,
        );
        if (! hash_equals($order->orderPublicId, $orderPublicId)
            || ! hash_equals($order->sourceQuotePublicId, $quotePublicId)
            || ! hash_equals($selection->decisionPublicId, $decisionPublicId)
            || ! hash_equals($selection->sourceQuotePublicId, $quotePublicId)
            || $selection->methodCode !== self::METHOD_CODE) {
            throw new AuthorizationException('Telegram Gift Card checkout authority is unavailable.');
        }
    }

    private function manualCodeType(Connection $connection, string $typeCode, string $configurationHash, bool $lock): stdClass
    {
        if (preg_match('/\A[A-Za-z0-9:_.-]{2,64}\z/', $typeCode) !== 1) {
            throw new AuthorizationException('Telegram Gift Card type is unavailable.');
        }
        $query = $connection->table('gift_card_types')
            ->where('type_code', $typeCode)
            ->where('configuration_hash', strtolower($configurationHash));
        if ($lock) {
            $query->lockForUpdate();
        }
        $type = $query->first([
            'type_code', 'brand', 'region', 'face_currency', 'submission_mode', 'verification_mode', 'active',
        ]);
        if ($type === null
            || ! (bool) $type->active
            || $type->verification_mode !== 'manual_only'
            || ! in_array($type->submission_mode, ['code_only', 'either'], true)) {
            throw new AuthorizationException('Telegram Gift Card type is unavailable.');
        }

        return $type;
    }

    private function assertSelf(int $actorUserId, int $subjectUserId): void
    {
        if ($actorUserId < 1 || $subjectUserId < 1 || $actorUserId !== $subjectUserId) {
            throw new AuthorizationException('Telegram Gift Card payment self access denied.');
        }
    }

    private function assertOperationKey(string $operationKey): void
    {
        if (preg_match('/\A[0-9a-f]{64}\z/', $operationKey) !== 1) {
            throw new RuntimeException('Telegram Gift Card operation identity is invalid.');
        }
    }

    private function correlationId(string $operation, string $operationKey): string
    {
        return 'tg-gift-'.$operation.':'.substr($operationKey, 0, 40);
    }
}
