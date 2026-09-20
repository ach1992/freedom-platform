<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Domain\TelegramBroadcastSourceKind;

/**
 * Classifies the exact Telegram Message content shape used as a broadcast source.
 *
 * Telegram exposes compatibility fields for some message classes (for example
 * Animation also sets document, and Live Photo also sets photo). This classifier
 * treats those shapes canonically instead of counting compatibility fields as
 * independent media types.
 */
final readonly class TelegramBroadcastSourceClassifier
{
    /** @var list<string> */
    private const UNSUPPORTED_SOURCE_FIELDS = [
        'live_photo',
        'voice',
        'video_note',
        'sticker',
        'story',
        'contact',
        'dice',
        'game',
        'poll',
        'venue',
        'location',
        'invoice',
        'successful_payment',
        'refunded_payment',
        'paid_media',
    ];

    /** @param array<string,mixed> $message */
    public function classify(array $message): ?TelegramBroadcastSourceKind
    {
        foreach (self::UNSUPPORTED_SOURCE_FIELDS as $field) {
            if (array_key_exists($field, $message)) {
                return null;
            }
        }

        $textPresent = array_key_exists('text', $message);
        $photoPresent = array_key_exists('photo', $message);
        $videoPresent = array_key_exists('video', $message);
        $animationPresent = array_key_exists('animation', $message);
        $audioPresent = array_key_exists('audio', $message);
        $documentPresent = array_key_exists('document', $message);

        if ($animationPresent) {
            if ($textPresent || $photoPresent || $videoPresent || $audioPresent || ! $documentPresent) {
                return null;
            }
            if (! $this->validMediaObject($message['animation'], dimensions: true, duration: true)
                || ! $this->validMediaObject($message['document'])
            ) {
                return null;
            }

            return TelegramBroadcastSourceKind::Animation;
        }

        $candidates = [];

        if ($textPresent) {
            if (! $this->validText($message['text'])) {
                return null;
            }
            $candidates[] = TelegramBroadcastSourceKind::Text;
        }

        if ($photoPresent) {
            if (! $this->validPhotoSizes($message['photo'])) {
                return null;
            }
            $candidates[] = TelegramBroadcastSourceKind::Photo;
        }

        if ($videoPresent) {
            if (! $this->validMediaObject($message['video'], dimensions: true, duration: true)) {
                return null;
            }
            $candidates[] = TelegramBroadcastSourceKind::Video;
        }

        if ($audioPresent) {
            if (! $this->validMediaObject($message['audio'], duration: true)) {
                return null;
            }
            $candidates[] = TelegramBroadcastSourceKind::Audio;
        }

        if ($documentPresent) {
            if (! $this->validMediaObject($message['document'])) {
                return null;
            }
            $candidates[] = TelegramBroadcastSourceKind::Document;
        }

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    private function validText(mixed $value): bool
    {
        return is_string($value)
            && $value !== ''
            && mb_check_encoding($value, 'UTF-8')
            && ! str_contains($value, "\0");
    }

    private function validPhotoSizes(mixed $value): bool
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            return false;
        }

        foreach ($value as $photo) {
            if (! is_array($photo)
                || array_is_list($photo)
                || ! $this->validFileIdentity($photo)
                || ! $this->validPositiveInt($photo['width'] ?? null)
                || ! $this->validPositiveInt($photo['height'] ?? null)
                || ! $this->validOptionalFileSize($photo['file_size'] ?? null)
            ) {
                return false;
            }
        }

        return true;
    }

    private function validMediaObject(
        mixed $value,
        bool $dimensions = false,
        bool $duration = false,
    ): bool {
        if (! is_array($value) || array_is_list($value) || ! $this->validFileIdentity($value)) {
            return false;
        }
        if ($dimensions
            && (! $this->validPositiveInt($value['width'] ?? null)
                || ! $this->validPositiveInt($value['height'] ?? null))
        ) {
            return false;
        }
        if ($duration && (! is_int($value['duration'] ?? null) || $value['duration'] < 0)) {
            return false;
        }

        return $this->validOptionalFileSize($value['file_size'] ?? null);
    }

    /** @param array<string,mixed> $file */
    private function validFileIdentity(array $file): bool
    {
        return $this->safeProviderFileIdentity($file['file_id'] ?? null)
            && $this->safeProviderFileIdentity($file['file_unique_id'] ?? null);
    }

    private function safeProviderFileIdentity(mixed $value): bool
    {
        return is_string($value)
            && $value !== ''
            && strlen($value) <= 2048
            && preg_match('/[\x00-\x20\x7F]/', $value) !== 1;
    }

    private function validPositiveInt(mixed $value): bool
    {
        return is_int($value) && $value > 0;
    }

    private function validOptionalFileSize(mixed $value): bool
    {
        return $value === null || (is_int($value) && $value > 0);
    }
}
