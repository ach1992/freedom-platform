<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Localization\Application\LocalizationResolver;
use App\Modules\Telegram\Application\TelegramCardToCardReceiptStatusDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

/** @requirement LOC-001 C2C-004 QUA-001 QUA-004 */
final class TelegramCardToCardReceiptStatusLocalizationResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://bot.example.test',
            'telegram.bot_token' => '123456789:abcdefghijklmnopqrstuvwxyz_ABCDE',
            'telegram.webhook_secret' => 'telegram_webhook_secret_1234567890_safe',
            'telegram.webhook_path' => 'api/telegram/webhook',
            'telegram.max_body_bytes' => 1_048_576,
            'telegram.queue' => 'critical',
            'telegram.processing_lease_seconds' => 120,
            'telegram.api_base_url' => 'https://api.telegram.org',
            'telegram.api_timeout_seconds' => 15,
        ]);
    }

    public function test_status_delivery_uses_canonical_resolver_and_observes_requested_locale_override(): void
    {
        $delivery = $this->app->make(TelegramCardToCardReceiptStatusDelivery::class);
        $property = new ReflectionProperty($delivery, 'localization');
        self::assertInstanceOf(LocalizationResolver::class, $property->getValue($delivery));

        $translation = new ReflectionMethod($delivery, 'translation');
        $translationKey = 'telegram_c2c_receipt'.'.received';

        self::assertSame(
            trans($translationKey, [], 'fa'),
            $translation->invoke($delivery, $translationKey, 'fa'),
        );
        self::assertSame(
            trans($translationKey, [], 'en'),
            $translation->invoke($delivery, $translationKey, 'en'),
        );

        $administratorId = $this->administrator();
        $now = now('UTC');
        DB::table('localization_overrides')->insert([
            'translation_key' => $translationKey,
            'locale' => 'fa',
            'override_value' => 'رسید شما ثبت شد و برای بررسی در صف قرار گرفت.',
            'version' => 1,
            'updated_by_administrator_id' => $administratorId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        self::assertSame(
            'رسید شما ثبت شد و برای بررسی در صف قرار گرفت.',
            $translation->invoke($delivery, $translationKey, 'fa'),
        );
        self::assertSame(
            trans($translationKey, [], 'en'),
            $translation->invoke($delivery, $translationKey, 'en'),
        );
    }

    public function test_status_delivery_rejects_missing_localization_instead_of_emitting_sentinel(): void
    {
        $delivery = $this->app->make(TelegramCardToCardReceiptStatusDelivery::class);
        $translation = new ReflectionMethod($delivery, 'translation');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Telegram card-to-card receipt translation is unavailable.');

        $translation->invoke($delivery, 'telegram_c2c_receipt.missing_copy', 'fa');
    }

    public function test_status_allowlist_still_fails_before_any_delivery_attempt(): void
    {
        $delivery = $this->app->make(TelegramCardToCardReceiptStatusDelivery::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Telegram card-to-card receipt status delivery identity is invalid.');

        $delivery->queue(123456789, 'receipt-request', 'fa', 'unsupported');
    }

    private function administrator(): int
    {
        $now = now('UTC');

        return (int) DB::table('administrators')->insertGetId([
            'user_id' => DB::table('users')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'account_type' => 'customer',
                'account_status' => 'active',
                'locale' => 'fa',
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]),
            'status' => 'active',
            'is_owner' => true,
            'permission_version' => 1,
            'last_authenticated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
