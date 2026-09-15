<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Localization\Application\LocalizationResolver;
use App\Modules\Telegram\Application\TelegramGiftCardNavigationHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

/** @requirement LOC-001 BUY-003 QUA-001 QUA-004 */
final class TelegramGiftCardLocalizationResolverTest extends TestCase
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

    public function test_gift_card_handler_uses_canonical_resolver_and_observes_placeholder_override(): void
    {
        $handler = $this->app->make(TelegramGiftCardNavigationHandler::class);
        $property = new ReflectionProperty($handler, 'localization');
        self::assertInstanceOf(LocalizationResolver::class, $property->getValue($handler));

        $translation = new ReflectionMethod($handler, 'translation');
        $key = 'telegram.navigation.purchase.payment_methods.gift_card_payment.type_button';
        $replace = ['name' => 'Amazon', 'currency' => 'USD'];

        self::assertSame(trans($key, $replace, 'fa'), $translation->invoke($handler, $key, 'fa', $replace));
        self::assertSame(trans($key, $replace, 'en'), $translation->invoke($handler, $key, 'en', $replace));

        $administratorId = $this->administrator();
        $now = now('UTC');
        DB::table('localization_overrides')->insert([
            'translation_key' => $key,
            'locale' => 'fa',
            'override_value' => 'کارت :name — ارز :currency',
            'version' => 1,
            'updated_by_administrator_id' => $administratorId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        self::assertSame('کارت Amazon — ارز USD', $translation->invoke($handler, $key, 'fa', $replace));
        self::assertSame(trans($key, $replace, 'en'), $translation->invoke($handler, $key, 'en', $replace));
    }

    public function test_gift_card_handler_rejects_missing_localization_instead_of_emitting_sentinel(): void
    {
        $handler = $this->app->make(TelegramGiftCardNavigationHandler::class);
        $translation = new ReflectionMethod($handler, 'translation');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Telegram Gift Card translation is unavailable.');

        $translation->invoke(
            $handler,
            'telegram.navigation.purchase.payment_methods.gift_card_payment.missing_copy',
            'fa',
            [],
        );
    }

    public function test_gift_card_handler_fails_closed_when_required_replacements_are_missing(): void
    {
        $handler = $this->app->make(TelegramGiftCardNavigationHandler::class);
        $translation = new ReflectionMethod($handler, 'translation');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Localization replacement is missing for placeholder:');

        $translation->invoke(
            $handler,
            'telegram.navigation.purchase.payment_methods.gift_card_payment.type_button',
            'fa',
            ['name' => 'Amazon'],
        );
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
