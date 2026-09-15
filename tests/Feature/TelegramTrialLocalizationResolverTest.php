<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Localization\Application\LocalizationResolver;
use App\Modules\Telegram\Application\TelegramTrialNavigationHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

/** @requirement LOC-001 CAT-006 QUA-001 QUA-004 */
final class TelegramTrialLocalizationResolverTest extends TestCase
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

    public function test_trial_handler_uses_canonical_resolver_and_observes_placeholder_override(): void
    {
        $handler = $this->app->make(TelegramTrialNavigationHandler::class);
        $property = new ReflectionProperty($handler, 'localization');
        self::assertInstanceOf(LocalizationResolver::class, $property->getValue($handler));

        $translation = new ReflectionMethod($handler, 'translation');
        $replace = [
            'plan' => 'Starter',
            'category' => 'Trial',
            'duration' => '7 days',
            'data' => '10 GB',
            'mode' => 'automatic',
            'membership' => 'not required',
            'phone' => 'not required',
        ];

        self::assertSame(
            trans('telegram_trial.detail', $replace, 'fa'),
            $translation->invoke($handler, 'telegram_trial.detail', 'fa', $replace),
        );
        self::assertSame(
            trans('telegram_trial.detail', $replace, 'en'),
            $translation->invoke($handler, 'telegram_trial.detail', 'en', $replace),
        );

        $administratorId = $this->administrator();
        $now = now('UTC');
        DB::table('localization_overrides')->insert([
            'translation_key' => 'telegram_trial.detail',
            'locale' => 'fa',
            'override_value' => 'پلن :plan | دسته :category | مدت :duration | حجم :data | حالت :mode | عضویت :membership | تلفن :phone',
            'version' => 1,
            'updated_by_administrator_id' => $administratorId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        self::assertSame(
            'پلن Starter | دسته Trial | مدت 7 days | حجم 10 GB | حالت automatic | عضویت not required | تلفن not required',
            $translation->invoke($handler, 'telegram_trial.detail', 'fa', $replace),
        );
        self::assertSame(
            trans('telegram_trial.detail', $replace, 'en'),
            $translation->invoke($handler, 'telegram_trial.detail', 'en', $replace),
        );
    }

    public function test_trial_handler_rejects_missing_localization_instead_of_emitting_raw_key(): void
    {
        $handler = $this->app->make(TelegramTrialNavigationHandler::class);
        $translation = new ReflectionMethod($handler, 'translation');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Telegram Trial translation is unavailable.');

        $translation->invoke($handler, 'telegram_trial.missing_copy', 'fa', []);
    }

    public function test_trial_handler_fails_closed_when_required_replacements_are_missing(): void
    {
        $handler = $this->app->make(TelegramTrialNavigationHandler::class);
        $translation = new ReflectionMethod($handler, 'translation');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Localization replacement is missing for placeholder:');

        $translation->invoke($handler, 'telegram_trial.detail', 'fa', ['plan' => 'Starter']);
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
