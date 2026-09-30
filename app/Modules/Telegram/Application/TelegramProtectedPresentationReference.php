<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use DateTimeImmutable;
use DomainException;
use LogicException;
use Stringable;

final readonly class TelegramProtectedPresentationReference implements Stringable
{
    private const PREFIX_V1 = '[PROTECTED_TELEGRAM_REFERENCE:v1:';

    private const PREFIX_V2 = '[PROTECTED_TELEGRAM_REFERENCE:v2:';

    private const PREFIX_V3 = '[PROTECTED_TELEGRAM_REFERENCE:v3:';

    private const PREFIX_V4 = '[PROTECTED_TELEGRAM_REFERENCE:v4:';

    private const PREFIX_V5 = '[PROTECTED_TELEGRAM_REFERENCE:v5:';

    private const PREFIX_V6 = '[PROTECTED_TELEGRAM_REFERENCE:v6:';

    private const PURPOSE_CARD_TO_CARD_DESTINATION = 'card_to_card_destination';

    private const PURPOSE_MEMBERSHIP_JOIN_PROMPT = 'membership_join_prompt';

    private const PURPOSE_SUPPORT_ATTACHMENT = 'support_attachment';

    private const PURPOSE_PAYMENT_REVIEW_EVIDENCE = 'payment_review_evidence';

    private const PURPOSE_BACKUP_EXPORT = 'backup_export';

    private const PURPOSE_REPORT_EXPORT = 'report_export';

    private function __construct(
        public string $purpose,
        public string $publicId,
        public string $locale,
        public ?string $action = null,
        public ?int $planOfferingId = null,
        public ?string $configurationHash = null,
        public ?string $audience = null,
        public ?string $kind = null,
        public ?int $partIndex = null,
        public ?int $partCount = null,
        public ?int $contentBytes = null,
        public ?string $contentSha256 = null,
        public ?string $format = null,
        public ?int $rangeStartEpoch = null,
        public ?int $rangeEndEpoch = null,
        public ?string $period = null,
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

    public static function backupExport(
        string $backupId,
        string $item,
        int $partIndex,
        int $partCount,
        int $contentBytes,
        string $contentSha256,
    ): self {
        if (preg_match('/\A[0-9]{8}T[0-9]{6}Z-[a-f0-9]{16}\z/', $backupId) !== 1
            || ! in_array($item, ['manifest', 'part'], true)
            || $partCount < 1
            || $partCount > 10_000
            || $contentBytes < 1
            || $contentBytes > 20_000_000
            || preg_match('/\A[0-9a-f]{64}\z/', $contentSha256) !== 1
        ) {
            throw new DomainException('Protected Telegram backup-export reference is invalid.');
        }

        if (($item === 'manifest' && $partIndex !== 0)
            || ($item === 'part' && ($partIndex < 1 || $partIndex > $partCount))
        ) {
            throw new DomainException('Protected Telegram backup-export part identity is invalid.');
        }

        return new self(
            self::PURPOSE_BACKUP_EXPORT,
            $backupId,
            'en',
            kind: $item,
            partIndex: $partIndex,
            partCount: $partCount,
            contentBytes: $contentBytes,
            contentSha256: $contentSha256,
        );
    }

    public static function reportExport(
        string $format,
        string $period,
        ?DateTimeImmutable $startsAtUtc,
        DateTimeImmutable $endsBeforeUtc,
        string $locale,
    ): self {
        self::assertLocale($locale);
        if (! in_array($format, ['csv', 'xlsx'], true)
            || preg_match('/\A[a-z0-9_]{1,32}\z/', $period) !== 1
        ) {
            throw new DomainException('Protected Telegram report-export reference is invalid.');
        }

        $startEpoch = $startsAtUtc?->getTimestamp();
        $endEpoch = $endsBeforeUtc->getTimestamp();
        if ($endEpoch < 1 || ($startEpoch !== null && ($startEpoch < 0 || $startEpoch >= $endEpoch))) {
            throw new DomainException('Protected Telegram report-export range is invalid.');
        }

        $identity = implode(':', [
            $format,
            $period,
            $startEpoch === null ? '-' : (string) $startEpoch,
            (string) $endEpoch,
            $locale,
        ]);

        return new self(
            self::PURPOSE_REPORT_EXPORT,
            hash('sha256', $identity),
            $locale,
            format: $format,
            rangeStartEpoch: $startEpoch,
            rangeEndEpoch: $endEpoch,
            period: $period,
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

        if (preg_match(
            '/\A\[PROTECTED_TELEGRAM_REFERENCE:v5:backup_export:([0-9]{8}T[0-9]{6}Z-[a-f0-9]{16}):(manifest|part):([0-9]+):([1-9][0-9]*):([1-9][0-9]*):([0-9a-f]{64})\]\z/',
            $value,
            $matches,
        ) === 1) {
            return self::backupExport(
                $matches[1],
                $matches[2],
                (int) $matches[3],
                (int) $matches[4],
                (int) $matches[5],
                $matches[6],
            );
        }

        if (preg_match(
            '/\A\[PROTECTED_TELEGRAM_REFERENCE:v6:report_export:(csv|xlsx):([a-z0-9_]{1,32}):(-|[0-9]+):([1-9][0-9]*):(fa|en)\]\z/',
            $value,
            $matches,
        ) === 1) {
            return self::reportExport(
                $matches[1],
                $matches[2],
                $matches[3] === '-' ? null : new DateTimeImmutable('@'.$matches[3]),
                new DateTimeImmutable('@'.$matches[4]),
                $matches[5],
            );
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

    public function isBackupExport(): bool
    {
        return $this->purpose === self::PURPOSE_BACKUP_EXPORT;
    }

    public function isReportExport(): bool
    {
        return $this->purpose === self::PURPOSE_REPORT_EXPORT;
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

    /** @return array{item:string,index:int,count:int,bytes:int,sha256:string} */
    public function backupExportIdentity(): array
    {
        if (! $this->isBackupExport()
            || $this->kind === null
            || $this->partIndex === null
            || $this->partCount === null
            || $this->contentBytes === null
            || $this->contentSha256 === null
        ) {
            throw new LogicException('Protected Telegram backup-export identity is unavailable.');
        }

        return [
            'item' => $this->kind,
            'index' => $this->partIndex,
            'count' => $this->partCount,
            'bytes' => $this->contentBytes,
            'sha256' => $this->contentSha256,
        ];
    }

    /** @return array{format:string,period:string,start_epoch:int|null,end_epoch:int} */
    public function reportExportIdentity(): array
    {
        if (! $this->isReportExport()
            || $this->format === null
            || $this->rangeEndEpoch === null
            || $this->period === null
        ) {
            throw new LogicException('Protected Telegram report-export identity is unavailable.');
        }

        return [
            'format' => $this->format,
            'period' => $this->period,
            'start_epoch' => $this->rangeStartEpoch,
            'end_epoch' => $this->rangeEndEpoch,
        ];
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
        if ($this->isBackupExport()) {
            $identity = $this->backupExportIdentity();

            return self::PREFIX_V5.$this->purpose.':'.$this->publicId.':'.$identity['item'].':'
                .$identity['index'].':'.$identity['count'].':'.$identity['bytes'].':'.$identity['sha256'].']';
        }

        if ($this->isReportExport()) {
            $identity = $this->reportExportIdentity();

            return self::PREFIX_V6.$this->purpose.':'.$identity['format'].':'.$identity['period'].':'
                .($identity['start_epoch'] === null ? '-' : (string) $identity['start_epoch']).':'
                .$identity['end_epoch'].':'.$this->locale.']';
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
