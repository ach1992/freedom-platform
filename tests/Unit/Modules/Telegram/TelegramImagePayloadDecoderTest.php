<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Telegram;

use App\Modules\Telegram\Application\TelegramImagePayloadDecoder;
use App\Modules\Telegram\Application\TelegramImagePayloadIntegrity;
use App\Modules\Telegram\Application\TelegramPrivateMediaRejected;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TelegramImagePayloadDecoderTest extends TestCase
{
    public function test_valid_supported_images_are_decoder_accepted(): void
    {
        $this->assertDecoderRuntime();

        foreach ([
            $this->onePixelPng(),
            $this->onePixelJpeg(),
            $this->onePixelWebp(),
            $this->animatedOnePixelWebp(),
        ] as $content) {
            self::assertSame(TelegramImagePayloadIntegrity::COMPLETE, TelegramImagePayloadIntegrity::inspect($content));
            TelegramImagePayloadDecoder::assertDecodable($content);
        }

        self::assertTrue(true);
    }

    #[DataProvider('malformedCompressedImageProvider')]
    public function test_metadata_plausible_but_undecodable_images_are_rejected(string $content, string $mime): void
    {
        $this->assertDecoderRuntime();

        self::assertSame($mime, (new \finfo(FILEINFO_MIME_TYPE))->buffer($content));
        $metadata = getimagesizefromstring($content);
        self::assertIsArray($metadata);
        self::assertSame(1, $metadata[0]);
        self::assertSame(1, $metadata[1]);
        self::assertSame(TelegramImagePayloadIntegrity::COMPLETE, TelegramImagePayloadIntegrity::inspect($content));

        try {
            TelegramImagePayloadDecoder::assertDecodable($content);
            self::fail('Metadata-plausible undecodable image must be rejected.');
        } catch (TelegramPrivateMediaRejected $exception) {
            self::assertSame('malformed_image', $exception->reasonCode);
        }
    }

    /** @return iterable<string,array{0:string,1:string}> */
    public static function malformedCompressedImageProvider(): iterable
    {
        yield 'invalid PNG IDAT zlib stream' => [self::invalidIdatPng(), 'image/png'];
        yield 'insufficient JPEG entropy stream' => [self::insufficientJpeg(), 'image/jpeg'];
    }

    private function assertDecoderRuntime(): void
    {
        self::assertTrue(extension_loaded('gd'), 'GD must be part of the supported PHP runtime.');
        self::assertTrue(function_exists('imagecreatefromstring'));
        self::assertNotSame(0, imagetypes() & IMG_JPG, 'GD JPEG decoding support is required.');
        self::assertNotSame(0, imagetypes() & IMG_PNG, 'GD PNG decoding support is required.');
        self::assertNotSame(0, imagetypes() & IMG_WEBP, 'GD WebP decoding support is required.');
    }

    private static function invalidIdatPng(): string
    {
        return "\x89PNG\r\n\x1a\n"
            .self::pngChunk('IHDR', pack('NNCCCCC', 1, 1, 8, 2, 0, 0, 0))
            .self::pngChunk('IDAT', "\x00")
            .self::pngChunk('IEND', '');
    }

    private static function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data))
            .$type
            .$data
            .hash('crc32b', $type.$data, true);
    }

    private static function insufficientJpeg(): string
    {
        return "\xff\xd8"
            ."\xff\xc0\x00\x0b\x08\x00\x01\x00\x01\x01\x01\x11\x00"
            ."\xff\xda\x00\x08\x01\x01\x00\x00\x3f\x00"
            ."\x00"
            ."\xff\xd9";
    }

    private function onePixelPng(): string
    {
        return $this->decodeFixture(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9RYVFHYAAAAASUVORK5CYII=',
        );
    }

    private function onePixelJpeg(): string
    {
        return $this->decodeFixture(
            '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAP/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFAEBAAAAAAAAAAAAAAAAAAAAAP/EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAMAwEAAhEDEQA/AJgA/9k=',
        );
    }

    private function onePixelWebp(): string
    {
        return $this->decodeFixture(
            'UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEADsD+JaQAA3AAAAAA',
        );
    }

    private function animatedOnePixelWebp(): string
    {
        return $this->decodeFixture(
            'UklGRlIAAABXRUJQVlA4WAoAAAASAAAAAAAAAAAAQU5JTQYAAAD/////AABBTk1GJgAAAAAAAAAAAAAAAAAAAGQAAABWUDhMDQAAAC8AAAAQBxAREYiI/gcA',
        );
    }

    private function decodeFixture(string $encoded): string
    {
        $decoded = base64_decode($encoded, true);
        if (! is_string($decoded)) {
            throw new RuntimeException('Image fixture could not be decoded.');
        }

        return $decoded;
    }
}
