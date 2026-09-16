<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;
use SensitiveParameter;

final class TelegramPrivateMediaContentValidator
{
    /** @var array<string,string> */
    private const MIME_KINDS = [
        'image/jpeg' => 'image',
        'image/png' => 'image',
        'image/webp' => 'image',
        'video/mp4' => 'video',
        'application/pdf' => 'file',
        'text/plain' => 'file',
    ];

    /** @return array{0:string,1:string,2:int} */
    public static function validate(#[SensitiveParameter] string $content, int $maximumBytes): array
    {
        $size = strlen($content);
        if ($size < 1) {
            throw new TelegramPrivateMediaRejected('empty_file');
        }
        if ($maximumBytes < 1 || $maximumBytes > 20_000_000 || $size > $maximumBytes) {
            throw new TelegramPrivateMediaRejected('file_too_large');
        }

        self::assertNotExecutableOrScript($content);
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($content);
        if (! is_string($mime) || ! array_key_exists($mime, self::MIME_KINDS)) {
            throw new TelegramPrivateMediaRejected('unsupported_media_type');
        }

        match ($mime) {
            'image/jpeg', 'image/png', 'image/webp' => self::assertImage($content, $mime),
            'video/mp4' => self::assertMp4($content),
            'application/pdf' => self::assertPdf($content),
            'text/plain' => self::assertPlainText($content),
        };

        return [$mime, hash('sha256', $content), $size];
    }

    public static function kindForMime(string $mime): string
    {
        return self::MIME_KINDS[$mime]
            ?? throw new InvalidArgumentException('Telegram private-media MIME type is not approved.');
    }

    public static function isImageMime(string $mime): bool
    {
        return (self::MIME_KINDS[$mime] ?? null) === 'image';
    }

    private static function assertImage(#[SensitiveParameter] string $content, string $mime): void
    {
        $image = @getimagesizefromstring($content);
        if (! is_array($image)
            || $image[0] < 1
            || $image[1] < 1
            || $image[0] > 50_000
            || $image[1] > 50_000
            || image_type_to_mime_type($image[2]) !== $mime
            || TelegramImagePayloadIntegrity::inspect($content) !== TelegramImagePayloadIntegrity::COMPLETE) {
            throw new TelegramPrivateMediaRejected('malformed_image');
        }

        TelegramImagePayloadDecoder::assertDecodable($content);
    }

    private static function assertMp4(#[SensitiveParameter] string $content): void
    {
        $length = strlen($content);
        if ($length < 24 || substr($content, 4, 4) !== 'ftyp') {
            throw new TelegramPrivateMediaRejected('malformed_video');
        }

        $offset = 0;
        $boxCount = 0;
        $sawFtyp = false;
        $sawMediaBox = false;
        while ($offset < $length) {
            if ($length - $offset < 8) {
                throw new TelegramPrivateMediaRejected('malformed_video');
            }
            $size32 = unpack('Nsize', substr($content, $offset, 4));
            $boxSize = (int) ($size32['size'] ?? 0);
            $type = substr($content, $offset + 4, 4);
            $headerSize = 8;
            if (preg_match('/\A[\x20-\x7E]{4}\z/', $type) !== 1) {
                throw new TelegramPrivateMediaRejected('malformed_video');
            }

            if ($boxSize === 1) {
                if ($length - $offset < 16) {
                    throw new TelegramPrivateMediaRejected('malformed_video');
                }
                $extended = unpack('Nhigh/Nlow', substr($content, $offset + 8, 8));
                if ((int) ($extended['high'] ?? 1) !== 0) {
                    throw new TelegramPrivateMediaRejected('malformed_video');
                }
                $boxSize = (int) ($extended['low'] ?? 0);
                $headerSize = 16;
            } elseif ($boxSize === 0) {
                $boxSize = $length - $offset;
            }

            if ($boxSize < $headerSize || $boxSize > $length - $offset) {
                throw new TelegramPrivateMediaRejected('malformed_video');
            }
            if ($boxCount === 0 && $type !== 'ftyp') {
                throw new TelegramPrivateMediaRejected('malformed_video');
            }
            $sawFtyp = $sawFtyp || $type === 'ftyp';
            $sawMediaBox = $sawMediaBox || in_array($type, ['moov', 'mdat'], true);
            $boxCount++;
            if ($boxCount > 4096) {
                throw new TelegramPrivateMediaRejected('malformed_video');
            }
            $offset += $boxSize;
        }

        if ($offset !== $length || ! $sawFtyp || ! $sawMediaBox) {
            throw new TelegramPrivateMediaRejected('malformed_video');
        }
    }

    private static function assertPdf(#[SensitiveParameter] string $content): void
    {
        if (preg_match('/\A%PDF-(?:1\.[0-9]|2\.[0-9])/', $content) !== 1
            || preg_match('/%%EOF[\x00-\x20]*\z/', $content) !== 1) {
            throw new TelegramPrivateMediaRejected('malformed_document');
        }
        if (preg_match('/\/(?:JavaScript|JS|OpenAction|AA|Launch|EmbeddedFile|RichMedia)\b/i', $content) === 1) {
            throw new TelegramPrivateMediaRejected('unsafe_document');
        }
    }

    private static function assertPlainText(#[SensitiveParameter] string $content): void
    {
        if (! mb_check_encoding($content, 'UTF-8')
            || trim($content) === ''
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $content) === 1) {
            throw new TelegramPrivateMediaRejected('malformed_document');
        }
        if (preg_match('/<\?(?:php|=)?|<script\b|<!doctype\s+html|<html\b/i', $content) === 1) {
            throw new TelegramPrivateMediaRejected('unsafe_document');
        }
    }

    private static function assertNotExecutableOrScript(#[SensitiveParameter] string $content): void
    {
        $prefix = substr($content, 0, 8192);
        if (str_starts_with($content, "\x7FELF")
            || str_starts_with($content, 'MZ')
            || str_starts_with($content, '#!')
            || preg_match('/<\?(?:php|=)?|<script\b|<!doctype\s+html|<html\b/i', $prefix) === 1) {
            throw new TelegramPrivateMediaRejected('unsafe_media_type');
        }
    }
}
