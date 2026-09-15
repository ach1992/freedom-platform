<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use GdImage;
use RuntimeException;
use SensitiveParameter;

final class TelegramImagePayloadDecoder
{
    private const MAX_DECODE_PIXELS = 25_000_000;

    private const MAX_ANIMATION_FRAMES = 240;

    private const MAX_ANIMATION_PIXEL_WORK = 100_000_000;

    public static function assertDecodable(#[SensitiveParameter] string $content): void
    {
        if (TelegramImagePayloadIntegrity::inspect($content) !== TelegramImagePayloadIntegrity::COMPLETE) {
            return;
        }

        $metadata = self::withoutWarnings(static fn (): array|false => getimagesizefromstring($content));
        if (! is_array($metadata)) {
            return;
        }

        $mime = is_string($metadata['mime'] ?? null) ? $metadata['mime'] : null;
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return;
        }

        $width = isset($metadata[0]) && is_int($metadata[0]) ? $metadata[0] : 0;
        $height = isset($metadata[1]) && is_int($metadata[1]) ? $metadata[1] : 0;
        if ($width < 1 || $height < 1) {
            return;
        }
        self::assertPixelBudget($width, $height);
        self::assertGdCapability($mime);

        if ($mime === 'image/webp' && self::webpHasAnimationFrames($content)) {
            if (! self::animatedWebpIsDecodable($content, $width, $height)) {
                throw new TelegramPrivateMediaRejected('malformed_image');
            }

            return;
        }

        if (! self::decodeMatches($content, $width, $height)) {
            throw new TelegramPrivateMediaRejected('malformed_image');
        }
    }

    private static function assertPixelBudget(int $width, int $height): void
    {
        if ($width > self::MAX_DECODE_PIXELS
            || $height > intdiv(self::MAX_DECODE_PIXELS, $width)) {
            throw new TelegramPrivateMediaRejected('file_too_large');
        }
    }

    private static function assertGdCapability(string $mime): void
    {
        if (! extension_loaded('gd')
            || ! function_exists('imagecreatefromstring')
            || ! function_exists('imagetypes')) {
            throw new RuntimeException('Required GD image decoder is unavailable.');
        }

        $requiredType = match ($mime) {
            'image/jpeg' => IMG_JPG,
            'image/png' => IMG_PNG,
            'image/webp' => IMG_WEBP,
            default => 0,
        };
        if ($requiredType === 0 || (imagetypes() & $requiredType) !== $requiredType) {
            throw new RuntimeException('Required GD image format decoder is unavailable.');
        }
    }

    private static function decodeMatches(
        #[SensitiveParameter] string $content,
        int $expectedWidth,
        int $expectedHeight,
    ): bool {
        $decoded = self::withoutWarnings(static fn (): GdImage|false => imagecreatefromstring($content));
        if (! $decoded instanceof GdImage) {
            return false;
        }

        try {
            return imagesx($decoded) === $expectedWidth && imagesy($decoded) === $expectedHeight;
        } finally {
            imagedestroy($decoded);
        }
    }

    private static function webpHasAnimationFrames(#[SensitiveParameter] string $content): bool
    {
        $position = 12;
        $length = strlen($content);
        while ($position + 8 <= $length) {
            $chunkType = substr($content, $position, 4);
            $chunkLength = self::uint32LittleEndian($content, $position + 4);
            $paddedLength = $chunkLength + ($chunkLength % 2);
            if ($paddedLength > $length - $position - 8) {
                return false;
            }
            if ($chunkType === 'ANMF') {
                return true;
            }

            $position += 8 + $paddedLength;
        }

        return false;
    }

    private static function animatedWebpIsDecodable(
        #[SensitiveParameter] string $content,
        int $canvasWidth,
        int $canvasHeight,
    ): bool {
        $position = 12;
        $length = strlen($content);
        $frameCount = 0;
        $pixelWork = 0;

        while ($position + 8 <= $length) {
            $chunkType = substr($content, $position, 4);
            $chunkLength = self::uint32LittleEndian($content, $position + 4);
            $dataOffset = $position + 8;
            $paddedLength = $chunkLength + ($chunkLength % 2);
            if ($paddedLength > $length - $dataOffset) {
                return false;
            }

            if ($chunkType === 'ANMF') {
                if ($chunkLength < 16 || ++$frameCount > self::MAX_ANIMATION_FRAMES) {
                    return false;
                }

                $x = self::uint24LittleEndian($content, $dataOffset) * 2;
                $y = self::uint24LittleEndian($content, $dataOffset + 3) * 2;
                $width = self::uint24LittleEndian($content, $dataOffset + 6) + 1;
                $height = self::uint24LittleEndian($content, $dataOffset + 9) + 1;
                if ($width < 1
                    || $height < 1
                    || $x > $canvasWidth - $width
                    || $y > $canvasHeight - $height) {
                    return false;
                }
                if ($width > self::MAX_DECODE_PIXELS
                    || $height > intdiv(self::MAX_DECODE_PIXELS, $width)) {
                    return false;
                }

                $framePixels = $width * $height;
                if ($framePixels > self::MAX_ANIMATION_PIXEL_WORK - $pixelWork) {
                    return false;
                }
                $pixelWork += $framePixels;

                $frameChunks = substr($content, $dataOffset + 16, $chunkLength - 16);
                $standaloneFrame = self::standaloneWebpFrame($frameChunks, $width, $height);
                if ($standaloneFrame === null || ! self::decodeMatches($standaloneFrame, $width, $height)) {
                    return false;
                }
            }

            $position = $dataOffset + $paddedLength;
        }

        return $position === $length && $frameCount > 0;
    }

    private static function standaloneWebpFrame(
        #[SensitiveParameter] string $frameChunks,
        int $width,
        int $height,
    ): ?string {
        $position = 0;
        $length = strlen($frameChunks);
        $seenAlpha = false;
        $bitstreamType = null;
        while ($position + 8 <= $length) {
            $chunkType = substr($frameChunks, $position, 4);
            $chunkLength = self::uint32LittleEndian($frameChunks, $position + 4);
            $paddedLength = $chunkLength + ($chunkLength % 2);
            if ($paddedLength > $length - $position - 8) {
                return null;
            }

            if ($chunkType === 'ALPH') {
                if ($seenAlpha || $bitstreamType !== null) {
                    return null;
                }
                $seenAlpha = true;
            } elseif ($chunkType === 'VP8 ' || $chunkType === 'VP8L') {
                if ($bitstreamType !== null || ($chunkType === 'VP8L' && $seenAlpha)) {
                    return null;
                }
                $bitstreamType = $chunkType;
            } else {
                return null;
            }

            $position += 8 + $paddedLength;
        }

        if ($position !== $length || $bitstreamType === null) {
            return null;
        }

        $chunks = $frameChunks;
        if ($seenAlpha) {
            $extendedHeader = "\x10\0\0\0"
                .self::uint24LittleEndianBytes($width - 1)
                .self::uint24LittleEndianBytes($height - 1);
            $chunks = self::webpChunk('VP8X', $extendedHeader).$chunks;
        }

        $riffPayload = 'WEBP'.$chunks;

        return 'RIFF'.pack('V', strlen($riffPayload)).$riffPayload;
    }

    private static function webpChunk(string $type, string $data): string
    {
        $chunk = $type.pack('V', strlen($data)).$data;

        return strlen($data) % 2 === 1 ? $chunk."\0" : $chunk;
    }

    private static function uint24LittleEndian(#[SensitiveParameter] string $content, int $offset): int
    {
        return ord($content[$offset])
            | (ord($content[$offset + 1]) << 8)
            | (ord($content[$offset + 2]) << 16);
    }

    private static function uint32LittleEndian(#[SensitiveParameter] string $content, int $offset): int
    {
        return ord($content[$offset])
            | (ord($content[$offset + 1]) << 8)
            | (ord($content[$offset + 2]) << 16)
            | (ord($content[$offset + 3]) << 24);
    }

    private static function uint24LittleEndianBytes(int $value): string
    {
        return chr($value & 0xFF)
            .chr(($value >> 8) & 0xFF)
            .chr(($value >> 16) & 0xFF);
    }

    private static function withoutWarnings(callable $callback): mixed
    {
        set_error_handler(static fn (): bool => true);
        try {
            return $callback();
        } finally {
            restore_error_handler();
        }
    }
}
