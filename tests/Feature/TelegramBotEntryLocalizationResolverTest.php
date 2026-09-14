<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Localization\Application\LocalizationResolver;
use App\Modules\Telegram\Application\TelegramNavigationEntryGateway;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionProperty;
use Tests\TestCase;

/** @requirement LOC-001 ONB-003 QUA-001 QUA-004 */
final class TelegramBotEntryLocalizationResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_bot_entry_gateway_wires_the_canonical_resolver_and_observes_active_override(): void
    {
        $gateway = $this->app->make(TelegramNavigationEntryGateway::class);
        $property = new ReflectionProperty($gateway, 'localization');
        $factory = $property->getValue($gateway);

        self::assertInstanceOf(Closure::class, $factory);

        $resolver = $factory();
        self::assertInstanceOf(LocalizationResolver::class, $resolver);
        self::assertSame(
            trans('telegram_membership.entry_unavailable', locale: 'fa'),
            $resolver->resolve('telegram_membership.entry_unavailable', [], 'fa'),
        );

        $administratorId = $this->administrator();
        $now = now('UTC');
        DB::table('localization_overrides')->insert([
            'translation_key' => 'telegram_membership.entry_unavailable',
            'locale' => 'fa',
            'override_value' => 'پیام سفارشی عضویت',
            'version' => 1,
            'updated_by_administrator_id' => $administratorId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        self::assertSame(
            'پیام سفارشی عضویت',
            $resolver->resolve('telegram_membership.entry_unavailable', [], 'fa'),
        );
        self::assertSame(
            trans('telegram_membership.entry_unavailable', locale: 'en'),
            $resolver->resolve('telegram_membership.entry_unavailable', [], 'en'),
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
