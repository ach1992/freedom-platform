<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Telegram;

use App\Modules\Telegram\Application\TelegramPrivateMediaContentValidator;
use App\Modules\Telegram\Application\TelegramPrivateMediaRejected;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** @requirement SUP-001 SUP-002 C2C-002 DAT-002 SEC-002 SEC-003 SEC-009 QUA-001 */
final class TelegramPrivateMediaContentValidatorTest extends TestCase
{
    #[DataProvider('approvedGeneralMediaProvider')]
    public function test_approved_general_media_is_identified_from_bytes(
        string $content,
        string $expectedMime,
        string $expectedKind,
    ): void {
        [$mime, $hash, $size] = TelegramPrivateMediaContentValidator::validate($content, 20_000_000);

        self::assertSame($expectedMime, $mime);
        self::assertSame($expectedKind, TelegramPrivateMediaContentValidator::kindForMime($mime));
        self::assertSame(hash('sha256', $content), $hash);
        self::assertSame(strlen($content), $size);
    }

    #[DataProvider('unsafeMediaProvider')]
    public function test_unsafe_or_malformed_general_media_is_rejected(
        string $content,
        string $reasonCode,
    ): void {
        try {
            TelegramPrivateMediaContentValidator::validate($content, 20_000_000);
            self::fail('Unsafe or malformed private media must be rejected.');
        } catch (TelegramPrivateMediaRejected $exception) {
            self::assertSame($reasonCode, $exception->reasonCode);
        }
    }

    public function test_size_bound_is_enforced_before_content_acceptance(): void
    {
        $content = str_repeat('a', 65);

        try {
            TelegramPrivateMediaContentValidator::validate($content, 64);
            self::fail('Private media larger than the configured bound must be rejected.');
        } catch (TelegramPrivateMediaRejected $exception) {
            self::assertSame('file_too_large', $exception->reasonCode);
        }
    }

    /** @return iterable<string,array{0:string,1:string,2:string}> */
    public static function approvedGeneralMediaProvider(): iterable
    {
        yield 'plain text' => ["Support attachment\nPlain text body.\n", 'text/plain', 'file'];
        yield 'passive PDF' => [self::passivePdf(), 'application/pdf', 'file'];
        yield 'bounded MP4 container' => [self::minimalMp4(), 'video/mp4', 'video'];
    }

    /** @return iterable<string,array{0:string,1:string}> */
    public static function unsafeMediaProvider(): iterable
    {
        yield 'PHP' => ["<?php echo 'unsafe';", 'unsafe_media_type'];
        yield 'HTML script' => ['<html><script>alert(1)</script></html>', 'unsafe_media_type'];
        yield 'active PDF JavaScript' => [
            "%PDF-1.4\n1 0 obj\n<< /OpenAction 2 0 R /JavaScript (alert) >>\nendobj\n%%EOF\n",
            'unsafe_document',
        ];
        yield 'malformed MP4' => [pack('N', 24).'ftypisom'.pack('N', 0).'isomiso2'.pack('N', 20).'mdat', 'malformed_video'];
        yield 'binary executable' => ["\x7fELF".str_repeat("\0", 32), 'unsafe_media_type'];
    }

    private static function passivePdf(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    private static function minimalMp4(): string
    {
        $ftyp = pack('N', 24).'ftyp'.'isom'.pack('N', 0).'isomiso2';
        $mdat = pack('N', 8).'mdat';

        return $ftyp.$mdat;
    }
}
