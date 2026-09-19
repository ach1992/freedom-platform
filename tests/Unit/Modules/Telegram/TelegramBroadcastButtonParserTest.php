<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Telegram;

use App\Modules\Telegram\Application\TelegramBroadcastButtonParser;
use DomainException;
use PHPUnit\Framework\TestCase;

final class TelegramBroadcastButtonParserTest extends TestCase
{
    public function test_none_removes_authored_keyboard(): void
    {
        self::assertNull((new TelegramBroadcastButtonParser)->parse('none'));
    }

    public function test_support_contact_button_reuses_existing_https_policy(): void
    {
        $keyboard = (new TelegramBroadcastButtonParser)->parse(
            'support_contact | پشتیبانی | https://t.me/example_support',
        );

        self::assertNotNull($keyboard);
        self::assertSame([], $keyboard->callbackPublicIds());
        self::assertStringContainsString('https://t.me/example_support', $keyboard->json());
    }

    public function test_unknown_purpose_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('not supported');

        (new TelegramBroadcastButtonParser)->parse(
            'unknown | Example | https://example.com',
        );
    }
}
