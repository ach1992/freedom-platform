<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\Contracts\TelegramReportChannelVerifier;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\TestCase;

final class VerifyTelegramReportChannelCommandTest extends TestCase
{
    public function test_it_verifies_a_negative_channel_id_without_echoing_the_destination(): void
    {
        config()->set('reporting.telegram.report_channel_chat_id', -1001234567890);

        $fake = new class implements TelegramReportChannelVerifier
        {
            public ?int $chatId = null;

            public function verifyReportChannel(int $chatId): void
            {
                $this->chatId = $chatId;
            }
        };
        $this->app->instance(TelegramReportChannelVerifier::class, $fake);

        $exit = Artisan::call('telegram:report-channel:verify', ['--json' => true]);

        self::assertSame(0, $exit);
        self::assertSame(-1001234567890, $fake->chatId);
        self::assertJsonStringEqualsJsonString('{"status":"verified"}', trim(Artisan::output()));
        self::assertStringNotContainsString('-1001234567890', Artisan::output());
    }

    public function test_it_fails_closed_when_the_report_destination_is_missing(): void
    {
        config()->set('reporting.telegram.report_channel_chat_id', 0);

        $fake = new class implements TelegramReportChannelVerifier
        {
            public bool $called = false;

            public function verifyReportChannel(int $chatId): void
            {
                $this->called = true;
            }
        };
        $this->app->instance(TelegramReportChannelVerifier::class, $fake);

        $exit = Artisan::call('telegram:report-channel:verify', ['--json' => true]);

        self::assertSame(1, $exit);
        self::assertFalse($fake->called);
    }

    public function test_it_redacts_provider_failure_details(): void
    {
        config()->set('reporting.telegram.report_channel_chat_id', -1001234567890);

        $fake = new class implements TelegramReportChannelVerifier
        {
            public function verifyReportChannel(int $chatId): void
            {
                throw new RuntimeException('provider-sensitive-marker');
            }
        };
        $this->app->instance(TelegramReportChannelVerifier::class, $fake);

        $exit = Artisan::call('telegram:report-channel:verify', ['--json' => true]);

        self::assertSame(1, $exit);
        self::assertStringNotContainsString('provider-sensitive-marker', Artisan::output());
        self::assertStringNotContainsString('-1001234567890', Artisan::output());
    }
}
