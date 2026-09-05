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

    public function test_png_invalid_ihdr_semantics_are_malformed_even_when_legacy_inspection_accepts_them(): void
    {
        $png = $this->onePixelPng();
        foreach ([
            24 => 3,
            25 => 1,
            26 => 1,
            27 => 1,
            28 => 2,
        ] as $offset => $value) {
            $mutated = $png;
            $mutated[$offset] = chr($value);
            $crc = hash('crc32b', substr($mutated, 12, 4).substr($mutated, 16, 13), true);
            $mutated = substr_replace($mutated, $crc, 29, 4);

            self::assertIsArray(@getimagesizefromstring($mutated));
            self::assertSame(
                TelegramImagePayloadIntegrity::MALFORMED,
                TelegramImagePayloadIntegrity::inspect($mutated),
            );
        }
    }

    public function test_png_unknown_critical_or_reserved_bit_chunk_is_malformed(): void
    {
        $png = $this->onePixelPng();
        foreach (['ABCD', 'abca'] as $chunkType) {
            $mutated = substr($png, 0, 33).$this->pngChunk($chunkType, '').substr($png, 33);

            self::assertIsArray(@getimagesizefromstring($mutated));
            self::assertSame(
                TelegramImagePayloadIntegrity::MALFORMED,
                TelegramImagePayloadIntegrity::inspect($mutated),
            );
        }
    }

    public function test_png_idat_chunks_must_remain_consecutive(): void
    {
        $png = $this->onePixelPng();
        $idat = substr($png, 41, 11);
        $mutated = substr($png, 0, 33)
            .$this->pngChunk('IDAT', substr($idat, 0, 5))
            .$this->pngChunk('vpAg', '')
            .$this->pngChunk('IDAT', substr($idat, 5))
            .substr($png, 56);

        self::assertIsArray(@getimagesizefromstring($mutated));
        self::assertSame(
            TelegramImagePayloadIntegrity::MALFORMED,
            TelegramImagePayloadIntegrity::inspect($mutated),
        );
    }

    public function test_png_palette_rules_are_structurally_enforced(): void
    {
        $signature = "\x89PNG\r\n\x1a\n";
        $ihdr = pack('NNCCCCC', 1, 1, 8, 3, 0, 0, 0);
        $idat = gzcompress("\x00\x00");
        self::assertIsString($idat);

        $indexedWithoutPalette = $signature
            .$this->pngChunk('IHDR', $ihdr)
            .$this->pngChunk('IDAT', $idat)
            .$this->pngChunk('IEND', '');
        self::assertIsArray(@getimagesizefromstring($indexedWithoutPalette));
        self::assertSame(
            TelegramImagePayloadIntegrity::MALFORMED,
            TelegramImagePayloadIntegrity::inspect($indexedWithoutPalette),
        );

        $validIndexed = $signature
            .$this->pngChunk('IHDR', $ihdr)
            .$this->pngChunk('PLTE', "\x00\x00\x00")
            .$this->pngChunk('IDAT', $idat)
            .$this->pngChunk('IEND', '');
        self::assertIsArray(@getimagesizefromstring($validIndexed));
        self::assertSame(
            TelegramImagePayloadIntegrity::COMPLETE,
            TelegramImagePayloadIntegrity::inspect($validIndexed),
        );

        $grayscaleAlphaWithPalette = substr($this->onePixelPng(), 0, 33)
            .$this->pngChunk('PLTE', "\x00\x00\x00")
            .substr($this->onePixelPng(), 33);
        self::assertIsArray(@getimagesizefromstring($grayscaleAlphaWithPalette));
        self::assertSame(
            TelegramImagePayloadIntegrity::MALFORMED,
            TelegramImagePayloadIntegrity::inspect($grayscaleAlphaWithPalette),
        );
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

    private function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.hash('crc32b', $type.$data, true);
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
