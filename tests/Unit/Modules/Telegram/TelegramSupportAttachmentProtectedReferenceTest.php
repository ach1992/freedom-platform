<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Telegram;

use App\Modules\Telegram\Application\TelegramProtectedPresentationReference;
use DomainException;
use LogicException;
use PHPUnit\Framework\TestCase;

/** @requirement SUP-001 SUP-002 DAT-003 SEC-002 SEC-003 SEC-008 QUA-001 */
final class TelegramSupportAttachmentProtectedReferenceTest extends TestCase
{
    public function test_support_attachment_reference_contains_only_safe_durable_identity(): void
    {
        $attachmentPublicId = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
        $reference = TelegramProtectedPresentationReference::supportAttachment(
            $attachmentPublicId,
            'customer',
            'fa',
        );

        $durable = $reference->durableText();
        self::assertSame(
            '[PROTECTED_TELEGRAM_REFERENCE:v3:support_attachment:customer:01ARZ3NDEKTSV4RRFFQ69G5FAV:fa]',
            $durable,
        );
        self::assertStringNotContainsString('telegram-private-media:', $durable);
        self::assertStringNotContainsString('receipts/', $durable);
        self::assertSame('[PROTECTED_TELEGRAM_REFERENCE]', (string) $reference);
        self::assertSame(
            ['redacted' => true, 'type' => 'protected_reference'],
            $reference->__debugInfo(),
        );

        $restored = TelegramProtectedPresentationReference::restore($durable);
        self::assertTrue($restored->isSupportAttachment());
        self::assertSame('customer', $restored->supportAttachmentAudience());
        self::assertSame($attachmentPublicId, $restored->publicId);
        self::assertSame('fa', $restored->locale);
    }

    public function test_support_attachment_reference_rejects_invalid_identity_audience_and_locale(): void
    {
        foreach ([
            static fn () => TelegramProtectedPresentationReference::supportAttachment('not-a-ulid', 'customer', 'fa'),
            static fn () => TelegramProtectedPresentationReference::supportAttachment('01ARZ3NDEKTSV4RRFFQ69G5FAV', 'internal', 'fa'),
            static fn () => TelegramProtectedPresentationReference::supportAttachment('01ARZ3NDEKTSV4RRFFQ69G5FAV', 'support', 'de'),
            static fn () => TelegramProtectedPresentationReference::restore('[PROTECTED_TELEGRAM_REFERENCE:v3:support_attachment:internal:01ARZ3NDEKTSV4RRFFQ69G5FAV:fa]'),
        ] as $operation) {
            try {
                $operation();
                self::fail('Invalid protected Support attachment reference must be rejected.');
            } catch (DomainException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_support_attachment_reference_cannot_be_serialized(): void
    {
        $reference = TelegramProtectedPresentationReference::supportAttachment(
            '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            'support',
            'en',
        );

        $this->expectException(LogicException::class);
        serialize($reference);
    }
}
