<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Telegram;

use App\Modules\Telegram\Application\ProtectedTelegramPresentation;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

/** @requirement SUP-001 SUP-002 SEC-002 SEC-008 QUA-001 */
final class ProtectedTelegramBinaryDocumentTest extends TestCase
{
    public function test_bounded_binary_document_is_redacted_and_provider_ready(): void
    {
        $contents = "private attachment bytes\n";
        $presentation = ProtectedTelegramPresentation::binaryDocument(
            $contents,
            'support-attachment-01ARZ3NDEKTSV4RRFFQ69G5FAV.txt',
            'Support attachment',
        );

        self::assertFalse($presentation->isText());
        self::assertSame($contents, $presentation->documentContents());
        self::assertSame('support-attachment-01ARZ3NDEKTSV4RRFFQ69G5FAV.txt', $presentation->documentFilename());
        self::assertSame('Support attachment', $presentation->caption());
        self::assertSame('[PROTECTED_TELEGRAM_PRESENTATION]', (string) $presentation);
        self::assertSame(['redacted' => true, 'type' => 'document'], $presentation->__debugInfo());
    }

    public function test_binary_document_rejects_unsafe_filename_and_over_20mb_payload(): void
    {
        foreach ([
            static fn () => ProtectedTelegramPresentation::binaryDocument('x', '../attachment.txt', 'caption'),
            static fn () => ProtectedTelegramPresentation::binaryDocument(str_repeat('x', 20_000_001), 'attachment.txt', 'caption'),
        ] as $operation) {
            try {
                $operation();
                self::fail('Unsafe protected binary document must be rejected.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_binary_document_cannot_be_serialized(): void
    {
        $presentation = ProtectedTelegramPresentation::binaryDocument('x', 'attachment.txt', 'caption');

        $this->expectException(LogicException::class);
        serialize($presentation);
    }
}
