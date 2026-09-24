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
     *
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

            return array_values($connection->table('gift_card_types')
                ->where('active', true)
                ->orderBy('type_code')
                ->get([
                    'type_code', 'display_name', 'brand', 'region', 'face_currency',
                    'submission_mode', 'verification_mode', 'configuration_hash',
                ])
                ->map(static fn (stdClass $row): TelegramCustomerPurchaseGiftCardType => new TelegramCustomerPurchaseGiftCardType(
                    (string) $row->type_code,
                    (string) $row->display_name,
                    (string) $row->brand,
                    $row->region === null ? null : (string) $row->region,
                    (string) $row->face_currency,
                    (string) $row->submission_mode,
                    (string) $row->verification_mode,
                    strtolower((string) $row->configuration_hash),
                ))
                ->all());
        }, 3);
    }

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
        return $this->submitEvidenceForSelf(
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
            null,
            null,
            null,
            null,
            $operationKey,
        );
    }

    /** @requirement BUY-001 BUY-003 PAY-001 PAY-002 PAY-003 PRO-001 GFT-001 GFT-002 GFT-003 GFT-004 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 QUA-004 */
    public function submitEvidenceForSelf(
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
        ?string $code,
        ?string $privateImageReference,
        ?string $telegramFileId,
        ?string $telegramFileUniqueId,
        ?string $imageContentHash,
        string $operationKey,
    ): TelegramCustomerPurchaseGiftCardSubmission {
        $this->assertSelf($actorUserId, $subjectUserId);
        $this->assertOperationKey($operationKey);
        if ($claimedFaceValue < 1
            || preg_match('/\A[0-9a-f]{64}\z/', $typeConfigurationHash) !== 1) {
            throw new AuthorizationException('Telegram Gift Card evidence authority is unavailable.');
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
            $privateImageReference,
            $telegramFileId,
            $telegramFileUniqueId,
            $imageContentHash,
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
            $type = $this->configuredType($connection, $typeCode, $typeConfigurationHash, true);
            $this->assertEvidenceMode((string) $type->submission_mode, $code, $privateImageReference);

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
                $privateImageReference,
                $telegramFileId,
                $telegramFileUniqueId,
                $imageContentHash,
                $this->correlationId('submit', $operationKey),
            );

            $processed = null;
            if ($type->verification_mode === 'manual_only') {
                $processed = $this->payments->routeManualOnly(
                    $submission->publicId,
                    $this->correlationId('review', $operationKey),
                );
            } elseif ($code === null) {
                // The current Generic REST provider deliberately never exports private image
                // content. Image-only evidence therefore enters the canonical human review
                // authority instead of pretending OCR/image presence is financial proof.
                $processed = $this->payments->routeManualReview(
                    $submission->publicId,
                    'private_image_requires_manual_review',
                    $this->correlationId('image-review', $operationKey),
                );
            }

            $state = $processed->state ?? $submission->state;
            $reviewPublicId = $processed?->reviewPublicId;
            if (! in_array($state, ['submitted', 'pending_manual_review'], true)
                || ($state === 'pending_manual_review' && $reviewPublicId === null)
                || ($state === 'submitted' && $reviewPublicId !== null)
                || ($processed !== null
                    && ($processed->redemptionPublicId !== null
                        || $processed->purchaseSettlementPublicId !== null
                        || ! hash_equals($submission->publicId, $processed->submissionPublicId)))) {
                throw new RuntimeException('Telegram Gift Card evidence result is inconsistent.');
            }

            return new TelegramCustomerPurchaseGiftCardSubmission(
                $submission->publicId,
                $submission->paymentIntentPublicId,
                $reviewPublicId,
                $submission->typeCode,
                $submission->maskedCode,
                $submission->claimedFaceValue,
                $submission->claimedCurrency,
                $state,
                $submission->replayed || ($processed->replayed ?? false),
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

    private function configuredType(Connection $connection, string $typeCode, string $configurationHash, bool $lock): stdClass
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
        if ($type === null || ! (bool) $type->active) {
            throw new AuthorizationException('Telegram Gift Card type is unavailable.');
        }

        return $type;
    }

    private function assertEvidenceMode(string $submissionMode, ?string $code, ?string $privateImageReference): void
    {
        $hasCode = is_string($code) && trim($code) !== '';
        $hasImage = is_string($privateImageReference) && trim($privateImageReference) !== '';
        $valid = match ($submissionMode) {
            'image_only' => ! $hasCode && $hasImage,
            'code_only' => $hasCode && ! $hasImage,
            'either' => $hasCode || $hasImage,
            'both' => $hasCode && $hasImage,
            default => false,
        };
        if (! $valid) {
            throw new AuthorizationException('Telegram Gift Card evidence does not satisfy the configured submission mode.');
        }
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
