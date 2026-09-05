<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Telegram;

use App\Modules\Telegram\Application\TelegramImagePayloadIntegrity;
use App\Modules\Telegram\Application\TelegramPrivateMediaIngestor;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use SensitiveParameter;

final class TelegramImagePayloadIntegrityTest extends TestCase
{
    public function test_complete_supported_images_pass_and_truncated_variants_are_incomplete(): void
    {
        foreach ([
            'png' => $this->onePixelPng(),
            'jpeg' => $this->onePixelJpeg(),
            'webp' => $this->onePixelWebp(),
        ] as $format => $content) {
            self::assertSame(
                TelegramImagePayloadIntegrity::COMPLETE,
                TelegramImagePayloadIntegrity::inspect($content),
                $format.' fixture must be structurally complete.',
            );
            self::assertSame(
                TelegramImagePayloadIntegrity::INCOMPLETE,
                TelegramImagePayloadIntegrity::inspect(substr($content, 0, -1)),
                $format.' truncation must not be accepted as complete.',
            );
        }
    }

    public function test_animated_webp_with_complete_frame_bitstream_is_accepted(): void
    {
        self::assertSame(
            TelegramImagePayloadIntegrity::COMPLETE,
            TelegramImagePayloadIntegrity::inspect($this->animatedOnePixelWebp()),
        );
    }

    public function test_vp8x_header_without_image_data_is_malformed_even_when_legacy_inspection_accepts_it(): void
    {
        $vp8x = str_repeat("\0", 10);
        $chunk = 'VP8X'.pack('V', strlen($vp8x)).$vp8x;
        $content = 'RIFF'.pack('V', 4 + strlen($chunk)).'WEBP'.$chunk;

        self::assertSame('image/webp', (new \finfo(FILEINFO_MIME_TYPE))->buffer($content));
        self::assertIsArray(@getimagesizefromstring($content));
        self::assertSame(
            TelegramImagePayloadIntegrity::MALFORMED,
            TelegramImagePayloadIntegrity::inspect($content),
        );
    }

    public function test_restricted_content_parameters_are_marked_sensitive(): void
    {
        $integrity = new ReflectionClass(TelegramImagePayloadIntegrity::class);
        foreach ($integrity->getMethods() as $method) {
            foreach ($method->getParameters() as $parameter) {
                if ($parameter->getName() !== 'content') {
                    continue;
                }

                self::assertNotSame(
                    [],
                    $parameter->getAttributes(SensitiveParameter::class),
                    $method->getName().' must redact restricted image bytes from exception traces.',
                );
            }
        }

        $ingestor = new ReflectionClass(TelegramPrivateMediaIngestor::class);
        $content = $ingestor->getMethod('validatedContent')->getParameters()[0];
        self::assertNotSame([], $content->getAttributes(SensitiveParameter::class));
    }

    public function test_partial_supported_signatures_are_incomplete_not_unsupported(): void
    {
        $fixtures = [
            $this->onePixelPng(),
            $this->onePixelJpeg(),
            $this->onePixelWebp(),
        ];

        foreach ($fixtures as $content) {
            $signatureLength = str_starts_with($content, "\x89PNG") ? 8 : (str_starts_with($content, "\xff\xd8") ? 2 : 4);
            for ($length = 1; $length < $signatureLength; $length++) {
                self::assertSame(
                    TelegramImagePayloadIntegrity::INCOMPLETE,
                    TelegramImagePayloadIntegrity::inspect(substr($content, 0, $length)),
                );
            }
        }
    }

    public function test_png_crc_corruption_is_malformed(): void
    {
        $png = $this->onePixelPng();
        $corrupted = substr_replace($png, "\x00\x00\x00\x00", 52, 4);

        self::assertSame(
            TelegramImagePayloadIntegrity::MALFORMED,
            TelegramImagePayloadIntegrity::inspect($corrupted),
        );
    }

    public function test_non_supported_payload_is_left_to_the_existing_mime_gate(): void
    {
        self::assertSame(
            TelegramImagePayloadIntegrity::UNSUPPORTED,
            TelegramImagePayloadIntegrity::inspect('not-an-image'),
        );
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

    private function animatedOnePixelWebp(): string
    {
        return $this->decodeFixture(
            'UklGRlIAAABXRUJQVlA4WAoAAAASAAAAAAAAAAAAQU5JTQYAAAD/////AABBTk1GJgAAAAAAAAAAAAAAAAAAAGQAAABWUDhMDQAAAC8AAAAQBxAREYiI/gcA',
        );
    }

    private function onePixelWebp(): string
    {
        return $this->decodeFixture(
            'UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEADsD+JaQAA3AAAAAA',
        );
    }

    private function decodeFixture(string $encoded): string
    {
        $decoded = base64_decode($encoded, true);
        if (! is_string($decoded)) {
            throw new RuntimeException('Image integrity test fixture could not be decoded.');
        }

        return $decoded;
    }
}
