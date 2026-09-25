<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Localization\Application\LocalizationResolver;
use App\Modules\Support\Application\SupportTicketAttachmentService;
use App\Modules\Telegram\Application\Contracts\TelegramAlternativePaymentReview;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseCardToCardPayment;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use RuntimeException;

final readonly class TelegramProtectedPresentationResolver
{
    public function __construct(
        private TelegramCustomerPurchaseCardToCardPayment $cardToCardPayments,
        private LocalizationResolver $localization,
        private ?TelegramMembershipJoinPresentationResolver $membershipJoinPresentations = null,
        private ?SupportTicketAttachmentService $supportAttachments = null,
        private ?TelegramSupportMembershipFreshnessGuard $supportMembership = null,
        private ?TelegramPrivateMediaDeliveryResolver $privateMedia = null,
        private ?TelegramAlternativePaymentReview $paymentReviews = null,
    ) {}

    public function resolveForSelf(
        int $userId,
        TelegramProtectedPresentationReference $reference,
    ): ProtectedTelegramPresentation {
        if ($userId < 1) {
            throw new RuntimeException('Protected Telegram presentation reference is unavailable.');
        }
        if ($reference->isMembershipJoinPrompt()) {
            if ($this->membershipJoinPresentations === null) {
                throw new RuntimeException('Protected Telegram membership presentation resolver is unavailable.');
            }

            return $this->membershipJoinPresentations->resolveForSelf($userId, $reference);
        }
        if ($reference->isSupportAttachment()) {
            return $this->supportAttachmentForSelf($userId, $reference);
        }
        if ($reference->isPaymentReviewEvidence()) {
            return $this->paymentReviewEvidenceForSelf($userId, $reference);
        }
        if (! $reference->isCardToCardDestination()) {
            throw new RuntimeException('Protected Telegram presentation reference is unavailable.');
        }

        try {
            $destination = $this->cardToCardPayments->destinationForSelf(
                $userId,
                $userId,
                $reference->publicId,
            );
        } catch (AuthorizationException $exception) {
            throw new DomainException('Protected Telegram presentation is no longer authorized.', previous: $exception);
        }
        $cardNumber = $destination->cardNumber->reveal();
        $text = $this->translation(
            'telegram.navigation.purchase.payment_methods.card_to_card_payment.protected_instructions',
            $reference->locale,
            [
                'card_number' => $this->formatCardNumber($cardNumber),
                'amount' => number_format($destination->payableAmountIrr, 0, '.', ','),
                'currency' => 'IRR',
                'expires_at' => $destination->expiresAt->setTimezone(new \DateTimeZone('Asia/Tehran'))->format('Y-m-d H:i:s'),
            ],
        );
        $text .= "\n\n".$this->translation(
            'telegram_c2c_receipt.upload_prompt',
            $reference->locale,
        );
        $copyLabel = $this->translation(
            'telegram.navigation.purchase.payment_methods.card_to_card_payment.copy_card',
            $reference->locale,
        );

        return ProtectedTelegramPresentation::plainTextWithCopyButton($text, $copyLabel, $cardNumber);
    }

    private function supportAttachmentForSelf(
        int $userId,
        TelegramProtectedPresentationReference $reference,
    ): ProtectedTelegramPresentation {
        if ($this->supportAttachments === null
            || $this->supportMembership === null
            || $this->privateMedia === null) {
            throw new RuntimeException('Protected Telegram Support attachment dependencies are unavailable.');
        }

        try {
            $this->supportMembership->assertSupportView($userId);
            $grant = $reference->supportAttachmentAudience() === 'support'
                ? $this->supportAttachments->deliveryForSupport($userId, $reference->publicId)
                : $this->supportAttachments->deliveryForCustomer($reference->publicId, $userId);
        } catch (AuthorizationException $exception) {
            throw new DomainException('Protected Telegram Support attachment is no longer authorized.', previous: $exception);
        }

        $payload = $this->privateMedia->resolveSupportAttachment(
            $grant->privateMediaReference,
            $grant->attachment->publicId,
        );
        $contents = $payload->bytes();
        $contentSha256 = $grant->contentSha256->reveal();
        if ($payload->detectedMime !== $grant->attachment->detectedMime
            || $payload->byteSize !== $grant->attachment->byteSize
            || preg_match('/\A[0-9a-f]{64}\z/', $contentSha256) !== 1
            || ! hash_equals($contentSha256, hash('sha256', $contents))) {
            throw new RuntimeException('Protected Telegram Support attachment metadata failed integrity verification.');
        }

        $caption = $this->translation(
            'telegram_support.attachment_delivery_caption',
            $reference->locale,
            [
                'attachment' => $grant->attachment->publicId,
                'kind' => $grant->attachment->kind,
                'mime' => $grant->attachment->detectedMime,
                'size' => $grant->attachment->byteSize,
            ],
        );

        return ProtectedTelegramPresentation::binaryDocument(
            $contents,
            'support-attachment-'.$grant->attachment->publicId.'.'.$this->extensionForMime($grant->attachment->detectedMime),
            $caption,
        );
    }

    private function paymentReviewEvidenceForSelf(
        int $userId,
        TelegramProtectedPresentationReference $reference,
    ): ProtectedTelegramPresentation {
        if ($this->paymentReviews === null || $this->privateMedia === null) {
            throw new RuntimeException('Protected Telegram payment-review evidence dependencies are unavailable.');
        }

        try {
            $grant = $this->paymentReviews->privateEvidence(
                $userId,
                $reference->paymentReviewKind(),
                $reference->publicId,
            );
        } catch (AuthorizationException|DomainException $exception) {
            throw new DomainException(
                'Protected Telegram payment-review evidence is no longer authorized or pending.',
                previous: $exception,
            );
        }

        if (! hash_equals($reference->paymentReviewKind(), $grant->kind)
            || ! hash_equals($reference->publicId, $grant->reviewPublicId)) {
            throw new RuntimeException('Protected Telegram payment-review evidence identity changed.');
        }

        $payload = $this->privateMedia->resolvePaymentReviewEvidence(
            $grant->privateMediaReference,
            $grant->associationType,
            $grant->associationPublicId,
        );
        $contents = $payload->bytes();
        $expectedHash = $grant->contentSha256->reveal();
        if (preg_match('/\A[0-9a-f]{64}\z/', $expectedHash) !== 1
            || ! hash_equals($expectedHash, hash('sha256', $contents))) {
            throw new RuntimeException('Protected Telegram payment-review evidence hash binding failed.');
        }

        $caption = $this->translation(
            'telegram.navigation.admin.payment_reviews.evidence_caption',
            $reference->locale,
            [
                'kind' => $this->translation(
                    'telegram.navigation.admin.payment_reviews.kinds.'.$grant->kind,
                    $reference->locale,
                ),
                'review' => $grant->reviewPublicId,
                'submission' => $grant->associationPublicId,
            ],
        );

        return ProtectedTelegramPresentation::binaryDocument(
            $contents,
            'payment-review-evidence-'.$grant->associationPublicId.'.'.$this->extensionForMime($payload->detectedMime),
            $caption,
        );
    }

    /** @param array<string,int|string> $replace */
    private function translation(string $key, string $locale, array $replace = []): string
    {
        $text = $this->localization->resolve($key, $replace, $locale);
        if ($text === '' || $text === '['.$key.']') {
            throw new RuntimeException('Protected Telegram translation is unavailable.');
        }

        return $text;
    }

    private function extensionForMime(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'application/pdf' => 'pdf',
            'text/plain' => 'txt',
            default => throw new RuntimeException('Protected Telegram document MIME type is invalid.'),
        };
    }

    private function formatCardNumber(string $cardNumber): string
    {
        if (preg_match('/\A[0-9]{16}\z/', $cardNumber) !== 1) {
            throw new RuntimeException('Protected card-to-card destination is invalid.');
        }

        return implode(' ', str_split($cardNumber, 4));
    }
}
