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

    public function test_webp_bitstream_header_semantics_are_enforced_when_legacy_inspection_accepts_them(): void
    {
        $lossy = $this->onePixelWebp();
        $lossyChunk = strpos($lossy, 'VP8 ');
        self::assertNotFalse($lossyChunk);
        $lossyData = $lossyChunk + 8;

        $reservedVersion = $lossy;
        $reservedVersion[$lossyData] = chr((ord($reservedVersion[$lossyData]) & ~0x0E) | (4 << 1));
        self::assertIsArray(@getimagesizefromstring($reservedVersion));
        self::assertSame(
            TelegramImagePayloadIntegrity::MALFORMED,
            TelegramImagePayloadIntegrity::inspect($reservedVersion),
        );

        $oversizedPartition = $lossy;
        $frameTag = ord($oversizedPartition[$lossyData])
            | (ord($oversizedPartition[$lossyData + 1]) << 8)
            | (ord($oversizedPartition[$lossyData + 2]) << 16);
        $frameTag = ($frameTag & 0x1F) | (0x7FFFF << 5);
        $oversizedPartition[$lossyData] = chr($frameTag & 0xFF);
        $oversizedPartition[$lossyData + 1] = chr(($frameTag >> 8) & 0xFF);
        $oversizedPartition[$lossyData + 2] = chr(($frameTag >> 16) & 0xFF);
        self::assertIsArray(@getimagesizefromstring($oversizedPartition));
        self::assertSame(
            TelegramImagePayloadIntegrity::MALFORMED,
            TelegramImagePayloadIntegrity::inspect($oversizedPartition),
        );

        $lossless = $this->animatedOnePixelWebp();
        $losslessChunk = strpos($lossless, 'VP8L');
        self::assertNotFalse($losslessChunk);
        $losslessData = $losslessChunk + 8;
        $reservedLosslessVersion = $lossless;
        $reservedLosslessVersion[$losslessData + 4] = chr(ord($reservedLosslessVersion[$losslessData + 4]) | 0x20);
        self::assertIsArray(@getimagesizefromstring($reservedLosslessVersion));
        self::assertSame(
            TelegramImagePayloadIntegrity::MALFORMED,
            TelegramImagePayloadIntegrity::inspect($reservedLosslessVersion),
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

    public function test_jpeg_frame_and_scan_header_semantics_are_enforced_when_legacy_inspection_accepts_them(): void
    {
        $jpeg = $this->onePixelJpeg();
        $sof = strpos($jpeg, "\xff\xc0");
        $sos = strpos($jpeg, "\xff\xda");
        self::assertNotFalse($sof);
        self::assertNotFalse($sos);

        foreach ([
            'precision' => [$sof + 4, 0],
            'frame_components' => [$sof + 9, 1],
            'scan_components' => [$sos + 4, 1],
            'scan_selector' => [$sos + 5, 99],
        ] as $name => [$offset, $value]) {
            $mutated = $jpeg;
            $mutated[$offset] = chr($value);

            self::assertIsArray(@getimagesizefromstring($mutated), $name.' mutation must reproduce legacy acceptance.');
            self::assertSame(
                TelegramImagePayloadIntegrity::MALFORMED,
                TelegramImagePayloadIntegrity::inspect($mutated),
                $name.' mutation must fail structural validation.',
            );
        }
    }

    public function test_jpeg_scan_requires_entropy_data_even_when_legacy_inspection_accepts_empty_scan(): void
    {
        $jpeg = $this->entropyEmptyJpeg();

        self::assertSame('image/jpeg', (new \finfo(FILEINFO_MIME_TYPE))->buffer($jpeg));
        $legacyInspection = @getimagesizefromstring($jpeg);
        self::assertIsArray($legacyInspection);
        self::assertSame([1, 1], [$legacyInspection[0], $legacyInspection[1]]);
        self::assertSame('image/jpeg', image_type_to_mime_type($legacyInspection[2]));
        self::assertSame(
            TelegramImagePayloadIntegrity::MALFORMED,
            TelegramImagePayloadIntegrity::inspect($jpeg),
        );
    }

    public function test_valid_progressive_and_multi_scan_jpegs_remain_complete(): void
    {
        foreach ([
            'progressive-restart' => $this->progressiveRestartJpeg(),
            'multi-scan' => $this->multiScanJpeg(),
        ] as $name => $jpeg) {
            $legacyInspection = @getimagesizefromstring($jpeg);
            self::assertIsArray($legacyInspection, $name.' fixture must remain a valid JPEG.');
            self::assertSame('image/jpeg', image_type_to_mime_type($legacyInspection[2]));
            self::assertSame(
                TelegramImagePayloadIntegrity::COMPLETE,
                TelegramImagePayloadIntegrity::inspect($jpeg),
                $name.' fixture must remain structurally complete.',
            );
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

    private function entropyEmptyJpeg(): string
    {
        return "\xff\xd8"
            ."\xff\xc0\x00\x0b\x08\x00\x01\x00\x01\x01\x01\x11\x00"
            ."\xff\xda\x00\x08\x01\x01\x00\x00\x3f\x00"
            ."\xff\xd9";
    }

    private function progressiveRestartJpeg(): string
    {
        // CC0 fixture: imazen/codec-corpus jpeg-conformance/valid/progressive_rst_420.jpg.
        return $this->decodeFixture(
            '/9j/2wDFAAMEBAYEBgYGBgYHBgYGBwcHBwcHBwgHCAcIBwgICQgJCQgJCAkICgoKCAkJCgoKCgkKDAwMCgwLCwwNDA0LCwkBAgQEBwYHCAcHCAcICAgHCgsNDQsKDQsLDAsLDRUYEwwMExgVEEkSDRJJEBIVCwsVEh0LFQsdKiAgKgoKCgoKUAIDAwMFBAUFBQUFBgQFBAYFBQUFBQUGBQUEBQUGBwYFBgYFBgcGBgYEBgYGBgYHBwYGBwUGBQcHBwcHCgsKCgpS/8IAEQgAEAAQAwEiAAIRAQMRAv/EABcAAQADAAAAAAAAAAAAAAAAAAcCBQj/3QAEAAT/2gAIAQEAAAAAyqzMTN//2gAIAQIAAAAAsP/aAAgBAwAAAACP/8QAHxAAAQQCAgMAAAAAAAAAAAAAAAUGITEH8SBhocHh/9oACAEBAAECAElqJLUSWoktT//aAAgBAgABAgBZeH//2gAIAQMAAQIAw9lH/9oACAEBAAM/AuH/2gAIAQIAAz8CP//aAAgBAwADPwKpP//aAAgBAQADPyGoOioKg//aAAgBAgADPyHs/9oACAEDAAM/IfEf/9oACAEBAAM/ENJXqaTSf//aAAgBAgADPxCz/9oACAEDAAM/EPqP/9k=',
        );
    }

    private function multiScanJpeg(): string
    {
        // MIT fixture: imazen/codec-corpus jpeg-conformance/valid/non-interleaved-mcu.jpg.
        return $this->decodeFixture(
            '/9j/4AAQSkZJRgABAQEBLAEsAAD/2wBDAAMCAgMCAgMDAwMEAwMEBQgFBQQEBQoHBwYIDAoMDAsKCwsNDhIQDQ4RDgsLEBYQERMUFRUVDA8XGBYUGBIUFRT/2wBDAQMEBAUEBQkFBQkUDQsNFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBT/wgARCAAQAEADASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAj/xAAUAQEAAAAAAAAAAAAAAAAAAAAA/90ABAAE/9oADAMBAAIQAxAAAAGVwAAAf//EABQQAQAAAAAAAAAAAAAAAAAAADD/3QAEAAj/2gAIAQEAAQUCD//QD//EABQRAQAAAAAAAAAAAAAAAAAAACD/3QAEAAT/2gAIAQMBAT8BH//EABQRAQAAAAAAAAAAAAAAAAAAACD/2gAIAQIBAT8BH//EABQQAQAAAAAAAAAAAAAAAAAAADD/3QAEAAj/2gAIAQEABj8CD//QD//EABQQAQAAAAAAAAAAAAAAAAAAADD/2gAIAQEAAT8hD//QD//dAAQABP/aAAwDAQACAAMAAAAQAAAA/8QAFBEBAAAAAAAAAAAAAAAAAAAAIP/aAAgBAwEBPxAf/8QAFBEBAAAAAAAAAAAAAAAAAAAAIP/aAAgBAgEBPxAf/8QAFBABAAAAAAAAAAAAAAAAAAAAMP/dAAQACP/aAAgBAQABPxAP/9AP/9k=',
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
