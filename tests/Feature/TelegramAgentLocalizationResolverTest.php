<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Localization\Application\LocalizationResolver;
use App\Modules\Telegram\Application\TelegramAgentNavigationHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

/** @requirement LOC-001 AGT-006 QUA-001 QUA-004 */
final class TelegramAgentLocalizationResolverTest extends TestCase
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

    public function test_agent_handler_uses_canonical_resolver_and_observes_placeholder_override(): void
    {
        $handler = $this->app->make(TelegramAgentNavigationHandler::class);
        $property = new ReflectionProperty($handler, 'localization');
        self::assertInstanceOf(LocalizationResolver::class, $property->getValue($handler));

        $translation = new ReflectionMethod($handler, 'translation');
        $replace = [
            'status' => 'active',
            'joined_at' => '2026-09-01 10:00:00',
            'approved_at' => '2026-09-02 11:00:00',
            'purchase_count' => 7,
        ];

        self::assertSame(
            trans('telegram_agent.agent.status', $replace, 'fa'),
            $translation->invoke($handler, 'telegram_agent.agent.status', 'fa', $replace),
        );
        self::assertSame(
            trans('telegram_agent.agent.status', $replace, 'en'),
            $translation->invoke($handler, 'telegram_agent.agent.status', 'en', $replace),
        );

        $administratorId = $this->administrator();
        $now = now('UTC');
        DB::table('localization_overrides')->insert([
            'translation_key' => 'telegram_agent.agent.status',
            'locale' => 'fa',
            'override_value' => 'نماینده :status | عضویت :joined_at | تأیید :approved_at | خرید :purchase_count',
            'version' => 1,
            'updated_by_administrator_id' => $administratorId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        self::assertSame(
            'نماینده active | عضویت 2026-09-01 10:00:00 | تأیید 2026-09-02 11:00:00 | خرید 7',
            $translation->invoke($handler, 'telegram_agent.agent.status', 'fa', $replace),
        );
        self::assertSame(
            trans('telegram_agent.agent.status', $replace, 'en'),
            $translation->invoke($handler, 'telegram_agent.agent.status', 'en', $replace),
        );
    }

    public function test_agent_handler_rejects_missing_localization_instead_of_emitting_raw_key(): void
    {
        $handler = $this->app->make(TelegramAgentNavigationHandler::class);
        $translation = new ReflectionMethod($handler, 'translation');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Telegram Agent translation is unavailable.');

        $translation->invoke($handler, 'telegram_agent.missing_copy', 'fa', []);
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
