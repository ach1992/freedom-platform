<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Telegram;

use App\Modules\Telegram\Application\TelegramBroadcastSourceClassifier;
use App\Modules\Telegram\Domain\TelegramBroadcastSourceKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TelegramBroadcastSourceClassifierTest extends TestCase
{
    /**
     * @param  array<string,mixed>  $message
     */
    #[DataProvider('supportedSourceProvider')]
    public function test_classifies_each_supported_canonical_source(
        TelegramBroadcastSourceKind $expected,
        array $message,
    ): void {
        self::assertSame($expected, (new TelegramBroadcastSourceClassifier)->classify($message));
    }

    /** @return iterable<string,array{TelegramBroadcastSourceKind,array<string,mixed>}> */
    public static function supportedSourceProvider(): iterable
    {
        yield 'text' => [TelegramBroadcastSourceKind::Text, ['text' => 'broadcast text']];
        yield 'photo' => [TelegramBroadcastSourceKind::Photo, ['photo' => [self::photoSize('photo')]]];
        yield 'video' => [
            TelegramBroadcastSourceKind::Video,
            ['video' => self::media('video', dimensions: true, duration: true)],
        ];
        yield 'animation with Telegram document compatibility field' => [
            TelegramBroadcastSourceKind::Animation,
            [
                'animation' => self::media('animation', dimensions: true, duration: true),
                'document' => self::media('animation-document'),
            ],
        ];
        yield 'audio' => [
            TelegramBroadcastSourceKind::Audio,
            ['audio' => self::media('audio', duration: true)],
        ];
        yield 'document' => [
            TelegramBroadcastSourceKind::Document,
            ['document' => self::media('document')],
        ];
    }

    /**
     * @param  array<string,mixed>  $message
     */
    #[DataProvider('invalidSourceProvider')]
    public function test_rejects_unsupported_malformed_and_ambiguous_source_shapes(array $message): void
    {
        self::assertNull((new TelegramBroadcastSourceClassifier)->classify($message));
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function invalidSourceProvider(): iterable
    {
        yield 'live photo with Telegram photo compatibility field' => [[
            'live_photo' => self::media('live-photo', dimensions: true, duration: true),
            'photo' => [self::photoSize('live-photo-static')],
        ]];

        yield 'animation without required document compatibility field' => [[
            'animation' => self::media('animation', dimensions: true, duration: true),
        ]];

        yield 'animation with malformed compatibility document' => [[
            'animation' => self::media('animation', dimensions: true, duration: true),
            'document' => ['file_id' => 'only-one-id'],
        ]];

        yield 'malformed photo identity' => [[
            'photo' => [[
                'file_id' => 'photo-file',
                'width' => 640,
                'height' => 480,
            ]],
        ]];

        yield 'malformed video dimensions' => [[
            'video' => [
                ...self::media('video', duration: true),
                'width' => 0,
                'height' => 480,
            ],
        ]];

        yield 'malformed audio identity' => [[
            'audio' => [
                'file_id' => 'bad id',
                'file_unique_id' => 'audio-unique',
                'duration' => 12,
            ],
        ]];

        yield 'malformed document size' => [[
            'document' => [
                ...self::media('document'),
                'file_size' => 0,
            ],
        ]];

        yield 'mixed photo and video' => [[
            'photo' => [self::photoSize('photo')],
            'video' => self::media('video', dimensions: true, duration: true),
        ]];

        yield 'mixed text and document' => [[
            'text' => 'text',
            'document' => self::media('document'),
        ]];

        yield 'unsupported sticker' => [[
            'sticker' => self::media('sticker'),
        ]];
    }

    /** @return array<string,mixed> */
    private static function photoSize(string $prefix): array
    {
        return [
            'file_id' => $prefix.'-file',
            'file_unique_id' => $prefix.'-unique',
            'width' => 640,
            'height' => 480,
            'file_size' => 12_345,
        ];
    }

    /** @return array<string,mixed> */
    private static function media(
        string $prefix,
        bool $dimensions = false,
        bool $duration = false,
    ): array {
        $media = [
            'file_id' => $prefix.'-file',
            'file_unique_id' => $prefix.'-unique',
            'file_size' => 12_345,
        ];
        if ($dimensions) {
            $media['width'] = 640;
            $media['height'] = 480;
        }
        if ($duration) {
            $media['duration'] = 12;
        }

        return $media;
    }
}
