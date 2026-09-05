<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use SensitiveParameter;

final class TelegramImagePayloadIntegrity
{
    public const COMPLETE = 'complete';

    public const INCOMPLETE = 'incomplete';

    public const MALFORMED = 'malformed';

    public const UNSUPPORTED = 'unsupported';

    /** @return 'complete'|'incomplete'|'malformed'|'unsupported' */
    public static function inspect(#[SensitiveParameter] string $content): string
    {
        $pngSignature = "\x89PNG\r\n\x1a\n";
        if (str_starts_with($content, $pngSignature)) {
            return self::inspectPng($content);
        }
        if ($content !== '' && strlen($content) < strlen($pngSignature) && str_starts_with($pngSignature, $content)) {
            return self::INCOMPLETE;
        }

        $jpegSignature = "\xff\xd8";
        if (str_starts_with($content, $jpegSignature)) {
            return self::inspectJpeg($content);
        }
        if ($content !== '' && strlen($content) < strlen($jpegSignature) && str_starts_with($jpegSignature, $content)) {
            return self::INCOMPLETE;
        }

        if (str_starts_with($content, 'RIFF')) {
            return self::inspectWebp($content);
        }
        if ($content !== '' && strlen($content) < 4 && str_starts_with('RIFF', $content)) {
            return self::INCOMPLETE;
        }

        return self::UNSUPPORTED;
    }

    /** @return 'complete'|'incomplete'|'malformed' */
    private static function inspectPng(#[SensitiveParameter] string $content): string
    {
        $length = strlen($content);
        if ($length < 8) {
            return self::INCOMPLETE;
        }

        $position = 8;
        $seenHeader = false;
        $seenImageData = false;
        while ($position < $length) {
            if ($length - $position < 12) {
                return self::INCOMPLETE;
            }

            $chunkLength = self::uint32BigEndian($content, $position);
            $chunkType = substr($content, $position + 4, 4);
            if (preg_match('/\A[A-Za-z]{4}\z/', $chunkType) !== 1) {
                return self::MALFORMED;
            }
            if ($chunkLength > $length - $position - 12) {
                return self::INCOMPLETE;
            }

            $chunkData = substr($content, $position + 8, $chunkLength);
            $expectedCrc = substr($content, $position + 8 + $chunkLength, 4);
            $actualCrc = hash('crc32b', $chunkType.$chunkData, true);
            if (! hash_equals($expectedCrc, $actualCrc)) {
                return self::MALFORMED;
            }

            if (! $seenHeader) {
                if ($chunkType !== 'IHDR' || $chunkLength !== 13) {
                    return self::MALFORMED;
                }
                $seenHeader = true;
            } elseif ($chunkType === 'IHDR') {
                return self::MALFORMED;
            }

            if ($chunkType === 'IDAT') {
                $seenImageData = true;
            }

            $position += 12 + $chunkLength;
            if ($chunkType === 'IEND') {
                if ($chunkLength !== 0 || ! $seenImageData || $position !== $length) {
                    return self::MALFORMED;
                }

                return self::COMPLETE;
            }
        }

        return self::INCOMPLETE;
    }

    /** @return 'complete'|'incomplete'|'malformed' */
    private static function inspectJpeg(#[SensitiveParameter] string $content): string
    {
        $length = strlen($content);
        if ($length < 2) {
            return self::INCOMPLETE;
        }

        $position = 2;
        $seenStartOfFrame = false;
        $seenScan = false;
        while ($position < $length) {
            if (ord($content[$position]) !== 0xFF) {
                return self::MALFORMED;
            }

            while ($position < $length && ord($content[$position]) === 0xFF) {
                $position++;
            }
            if ($position >= $length) {
                return self::INCOMPLETE;
            }

            $marker = ord($content[$position]);
            $position++;
            if ($marker === 0x00 || $marker === 0xD8 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                return self::MALFORMED;
            }
            if ($marker === 0xD9) {
                if (! $seenStartOfFrame || ! $seenScan || $position !== $length) {
                    return self::MALFORMED;
                }

                return self::COMPLETE;
            }
            if ($marker === 0x01) {
                continue;
            }
            if ($length - $position < 2) {
                return self::INCOMPLETE;
            }

            $segmentLength = self::uint16BigEndian($content, $position);
            if ($segmentLength < 2) {
                return self::MALFORMED;
            }
            if ($segmentLength > $length - $position) {
                return self::INCOMPLETE;
            }

            if (self::isStartOfFrameMarker($marker)) {
                $seenStartOfFrame = true;
            }
            if ($marker !== 0xDA) {
                $position += $segmentLength;

                continue;
            }

            $seenScan = true;
            $position += $segmentLength;
            while ($position < $length) {
                $markerStart = strpos($content, "\xff", $position);
                if ($markerStart === false) {
                    return self::INCOMPLETE;
                }

                $position = $markerStart;
                while ($position < $length && ord($content[$position]) === 0xFF) {
                    $position++;
                }
                if ($position >= $length) {
                    return self::INCOMPLETE;
                }

                $scanMarker = ord($content[$position]);
                if ($scanMarker === 0x00 || ($scanMarker >= 0xD0 && $scanMarker <= 0xD7)) {
                    $position++;

                    continue;
                }

                $position = $markerStart;
                break;
            }
        }

        return self::INCOMPLETE;
    }

    /** @return 'complete'|'incomplete'|'malformed' */
    private static function inspectWebp(#[SensitiveParameter] string $content): string
    {
        $length = strlen($content);
        if ($length < 12) {
            return self::INCOMPLETE;
        }
        if (substr($content, 8, 4) !== 'WEBP') {
            return self::MALFORMED;
        }

        $declaredLength = self::uint32LittleEndian($content, 4) + 8;
        if ($declaredLength > $length) {
            return self::INCOMPLETE;
        }
        if ($declaredLength !== $length) {
            return self::MALFORMED;
        }

        $position = 12;
        $chunkIndex = 0;
        $seenExtendedHeader = false;
        $animation = false;
        $seenAnimationControl = false;
        $seenStillBitstream = false;
        $seenAnimatedFrame = false;
        while ($position < $length) {
            if ($length - $position < 8) {
                return self::INCOMPLETE;
            }

            $chunkType = substr($content, $position, 4);
            $chunkLength = self::uint32LittleEndian($content, $position + 4);
            $dataOffset = $position + 8;
            $paddedLength = $chunkLength + ($chunkLength % 2);
            if ($paddedLength > $length - $dataOffset) {
                return self::INCOMPLETE;
            }
            if ($chunkLength % 2 === 1 && ord($content[$dataOffset + $chunkLength]) !== 0) {
                return self::MALFORMED;
            }

            if ($chunkType === 'VP8X') {
                if ($chunkIndex !== 0 || $seenExtendedHeader || $chunkLength !== 10) {
                    return self::MALFORMED;
                }

                $flags = ord($content[$dataOffset]);
                if (($flags & 0xC1) !== 0 || substr($content, $dataOffset + 1, 3) !== "\0\0\0") {
                    return self::MALFORMED;
                }
                $canvasWidth = self::uint24LittleEndian($content, $dataOffset + 4) + 1;
                $canvasHeight = self::uint24LittleEndian($content, $dataOffset + 7) + 1;
                if ($canvasWidth * $canvasHeight > 0xFFFFFFFF) {
                    return self::MALFORMED;
                }

                $seenExtendedHeader = true;
                $animation = ($flags & 0x02) !== 0;
            } elseif ($chunkType === 'ANIM') {
                if (! $seenExtendedHeader || ! $animation || $seenAnimationControl || $chunkLength !== 6) {
                    return self::MALFORMED;
                }
                $seenAnimationControl = true;
            } elseif ($chunkType === 'ANMF') {
                if (! $seenExtendedHeader || ! $animation || ! $seenAnimationControl || $chunkLength < 16) {
                    return self::MALFORMED;
                }
                if (! self::webpFrameHasCompleteBitstream($content, $dataOffset + 16, $chunkLength - 16)) {
                    return self::MALFORMED;
                }
                $seenAnimatedFrame = true;
            } elseif ($chunkType === 'VP8 ' || $chunkType === 'VP8L') {
                if ($animation || $seenStillBitstream) {
                    return self::MALFORMED;
                }
                if (! self::webpBitstreamHeaderIsValid($chunkType, $content, $dataOffset, $chunkLength)) {
                    return self::MALFORMED;
                }
                $seenStillBitstream = true;
            }

            $position = $dataOffset + $paddedLength;
            $chunkIndex++;
        }

        if ($position !== $length) {
            return self::MALFORMED;
        }
        if ($animation) {
            return $seenAnimationControl && $seenAnimatedFrame && ! $seenStillBitstream
                ? self::COMPLETE
                : self::MALFORMED;
        }

        return $seenStillBitstream && ! $seenAnimatedFrame
            ? self::COMPLETE
            : self::MALFORMED;
    }

    private static function webpFrameHasCompleteBitstream(#[SensitiveParameter] string $content, int $position, int $length): bool
    {
        $end = $position + $length;
        $seenBitstream = false;
        while ($position < $end) {
            if ($end - $position < 8) {
                return false;
            }

            $chunkType = substr($content, $position, 4);
            $chunkLength = self::uint32LittleEndian($content, $position + 4);
            $dataOffset = $position + 8;
            $paddedLength = $chunkLength + ($chunkLength % 2);
            if ($paddedLength > $end - $dataOffset) {
                return false;
            }
            if ($chunkLength % 2 === 1 && ord($content[$dataOffset + $chunkLength]) !== 0) {
                return false;
            }
            if ($chunkType === 'VP8 ' || $chunkType === 'VP8L') {
                if ($seenBitstream || ! self::webpBitstreamHeaderIsValid($chunkType, $content, $dataOffset, $chunkLength)) {
                    return false;
                }
                $seenBitstream = true;
            }

            $position = $dataOffset + $paddedLength;
        }

        return $position === $end && $seenBitstream;
    }

    private static function webpBitstreamHeaderIsValid(
        string $chunkType,
        #[SensitiveParameter] string $content,
        int $offset,
        int $length,
    ): bool {
        if ($chunkType === 'VP8L') {
            return $length >= 5 && ord($content[$offset]) === 0x2F;
        }
        if ($length < 10) {
            return false;
        }

        $frameTag = ord($content[$offset])
            | (ord($content[$offset + 1]) << 8)
            | (ord($content[$offset + 2]) << 16);
        if (($frameTag & 0x01) !== 0 || substr($content, $offset + 3, 3) !== "\x9d\x01\x2a") {
            return false;
        }

        $width = self::uint16LittleEndian($content, $offset + 6) & 0x3FFF;
        $height = self::uint16LittleEndian($content, $offset + 8) & 0x3FFF;

        return $width > 0 && $height > 0;
    }

    private static function isStartOfFrameMarker(int $marker): bool
    {
        return in_array($marker, [
            0xC0, 0xC1, 0xC2, 0xC3,
            0xC5, 0xC6, 0xC7,
            0xC9, 0xCA, 0xCB,
            0xCD, 0xCE, 0xCF,
        ], true);
    }

    private static function uint16BigEndian(#[SensitiveParameter] string $content, int $offset): int
    {
        return (ord($content[$offset]) << 8)
            | ord($content[$offset + 1]);
    }

    private static function uint32BigEndian(#[SensitiveParameter] string $content, int $offset): int
    {
        return (ord($content[$offset]) << 24)
            | (ord($content[$offset + 1]) << 16)
            | (ord($content[$offset + 2]) << 8)
            | ord($content[$offset + 3]);
    }

    private static function uint16LittleEndian(#[SensitiveParameter] string $content, int $offset): int
    {
        return ord($content[$offset])
            | (ord($content[$offset + 1]) << 8);
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
}
