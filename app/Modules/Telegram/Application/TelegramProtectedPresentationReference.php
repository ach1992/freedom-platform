<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use DomainException;
use LogicException;
use Stringable;

final readonly class TelegramProtectedPresentationReference implements Stringable
{
    private const PREFIX_V1 = '[PROTECTED_TELEGRAM_REFERENCE:v1:';

    private const PREFIX_V2 = '[PROTECTED_TELEGRAM_REFERENCE:v2:';

    private const PREFIX_V3 = '[PROTECTED_TELEGRAM_REFERENCE:v3:';

    private const PREFIX_V4 = '[PROTECTED_TELEGRAM_REFERENCE:v4:';

    private const PURPOSE_CARD_TO_CARD_DESTINATION = 'card_to_card_destination';

    private const PURPOSE_MEMBERSHIP_JOIN_PROMPT = 'membership_join_prompt';

    private const PURPOSE_SUPPORT_ATTACHMENT = 'support_attachment';

    private const PURPOSE_PAYMENT_REVIEW_EVIDENCE = 'payment_review_evidence';

    private function __construct(
        public string $purpose,
        public string $publicId,
        public string $locale,
        public ?string $action = null,
        public ?int $planOfferingId = null,
        public ?string $configurationHash = null,
        public ?string $audience = null,
        public ?string $kind = null,
    ) {}

    public static function cardToCardDestination(string $reservationPublicId, string $locale): self
    {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $reservationPublicId) !== 1) {
            throw new DomainException('Protected Telegram card-to-card reference identity is invalid.');
        }
        self::assertLocale($locale);

        return new self(self::PURPOSE_CARD_TO_CARD_DESTINATION, strtoupper($reservationPublicId), $locale);
    }

    public static function membershipJoinPrompt(
        string $action,
        ?int $planOfferingId,
        string $configurationHash,
        string $locale,
    ): self {
        self::assertLocale($locale);
        if (preg_match('/\A[0-9a-f]{64}\z/', $configurationHash) !== 1) {
            throw new DomainException('Protected Telegram membership configuration hash is invalid.');
        }

        try {
            new TelegramChannelMembershipResolutionRequest(1, $action, $planOfferingId);
        } catch (\InvalidArgumentException $exception) {
            throw new DomainException('Protected Telegram membership request identity is invalid.', previous: $exception);
        }

        return new self(
            self::PURPOSE_MEMBERSHIP_JOIN_PROMPT,
            '',
            $locale,
            $action,
            $planOfferingId,
            $configurationHash,
        );
    }

    public static function supportAttachment(
        string $attachmentPublicId,
        string $audience,
        string $locale,
    ): self {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $attachmentPublicId) !== 1) {
            throw new DomainException('Protected Telegram Support attachment identity is invalid.');
        }
        if (! in_array($audience, ['customer', 'support'], true)) {
            throw new DomainException('Protected Telegram Support attachment audience is invalid.');
        }
        self::assertLocale($locale);

        return new self(
            self::PURPOSE_SUPPORT_ATTACHMENT,
            strtoupper($attachmentPublicId),
            $locale,
            audience: $audience,
        );
    }

    public static function paymentReviewEvidence(string $kind, string $reviewPublicId, string $locale): self
    {
        if (! in_array($kind, ['c2c', 'gift_card', 'usdt'], true)
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $reviewPublicId) !== 1) {
            throw new DomainException('Protected Telegram payment-review evidence identity is invalid.');
        }
        self::assertLocale($locale);

        return new self(
            self::PURPOSE_PAYMENT_REVIEW_EVIDENCE,
            strtoupper($reviewPublicId),
            $locale,
            kind: $kind,
        );
    }

    public static function restore(string $value): self
    {
        if (preg_match(
            '/\A\[PROTECTED_TELEGRAM_REFERENCE:v1:([a-z0-9_]+):([0-9A-HJKMNP-TV-Z]{26}):(fa|en)\]\z/',
            $value,
            $matches,
        ) === 1) {
            if ($matches[1] !== self::PURPOSE_CARD_TO_CARD_DESTINATION) {
                throw new DomainException('Stored protected Telegram reference purpose is unsupported.');
            }

            return self::cardToCardDestination($matches[2], $matches[3]);
        }

        if (preg_match(
            '/\A\[PROTECTED_TELEGRAM_REFERENCE:v2:membership_join_prompt:([a-z_]+):(-|[1-9][0-9]*):([0-9a-f]{64}):(fa|en)\]\z/',
            $value,
            $matches,
        ) === 1) {
            $offeringId = $matches[2] === '-' ? null : (int) $matches[2];

            return self::membershipJoinPrompt($matches[1], $offeringId, $matches[3], $matches[4]);
        }

        if (preg_match(
            '/\A\[PROTECTED_TELEGRAM_REFERENCE:v3:support_attachment:(customer|support):([0-9A-HJKMNP-TV-Z]{26}):(fa|en)\]\z/',
            $value,
            $matches,
        ) === 1) {
            return self::supportAttachment($matches[2], $matches[1], $matches[3]);
        }

        if (preg_match(
            '/\A\[PROTECTED_TELEGRAM_REFERENCE:v4:payment_review_evidence:(c2c|gift_card|usdt):([0-9A-HJKMNP-TV-Z]{26}):(fa|en)\]\z/',
            $value,
            $matches,
        ) === 1) {
            return self::paymentReviewEvidence($matches[1], $matches[2], $matches[3]);
        }

        throw new DomainException('Stored protected Telegram reference is invalid.');
    }

    public function isCardToCardDestination(): bool
    {
        return $this->purpose === self::PURPOSE_CARD_TO_CARD_DESTINATION;
    }

    public function isMembershipJoinPrompt(): bool
    {
        return $this->purpose === self::PURPOSE_MEMBERSHIP_JOIN_PROMPT;
    }

    public function isSupportAttachment(): bool
    {
        return $this->purpose === self::PURPOSE_SUPPORT_ATTACHMENT;
    }

    public function isPaymentReviewEvidence(): bool
    {
        return $this->purpose === self::PURPOSE_PAYMENT_REVIEW_EVIDENCE;
    }

    public function paymentReviewKind(): string
    {
        if (! $this->isPaymentReviewEvidence() || $this->kind === null) {
            throw new LogicException('Protected Telegram payment-review evidence kind is unavailable.');
        }

        return $this->kind;
    }

    public function supportAttachmentAudience(): string
    {
        if (! $this->isSupportAttachment() || $this->audience === null) {
            throw new LogicException('Protected Telegram Support attachment audience is unavailable.');
        }

        return $this->audience;
    }

    public function durableText(): string
    {
        if ($this->isCardToCardDestination()) {
            return self::PREFIX_V1.$this->purpose.':'.$this->publicId.':'.$this->locale.']';
        }
        if ($this->isMembershipJoinPrompt()) {
            if ($this->action === null || $this->configurationHash === null) {
                throw new LogicException('Protected Telegram membership reference is incomplete.');
            }

            return self::PREFIX_V2.$this->purpose.':'.$this->action.':'
                .($this->planOfferingId === null ? '-' : (string) $this->planOfferingId).':'
                .$this->configurationHash.':'.$this->locale.']';
        }
        if ($this->isSupportAttachment() && $this->audience !== null) {
            return self::PREFIX_V3.$this->purpose.':'.$this->audience.':'.$this->publicId.':'.$this->locale.']';
        }
        if ($this->isPaymentReviewEvidence() && $this->kind !== null) {
            return self::PREFIX_V4.$this->purpose.':'.$this->kind.':'.$this->publicId.':'.$this->locale.']';
        }

        throw new LogicException('Protected Telegram reference is incomplete.');
    }

    public function __toString(): string
    {
        return '[PROTECTED_TELEGRAM_REFERENCE]';
    }

    /** @return array{redacted:true,type:string} */
    public function __debugInfo(): array
    {
        return ['redacted' => true, 'type' => 'protected_reference'];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Protected Telegram references cannot be serialized.');
    }

    /** @param array<array-key,mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Protected Telegram references cannot be unserialized.');
    }

    private static function assertLocale(string $locale): void
    {
        if (! in_array($locale, ['fa', 'en'], true)) {
            throw new DomainException('Protected Telegram reference locale is invalid.');
        }
    }
}
