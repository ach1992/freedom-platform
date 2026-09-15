<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Localization\Application\LocalizationResolver;
use App\Modules\Telegram\Application\TelegramMembershipJoinPresentationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

/** @requirement LOC-001 CHN-001 SEC-003 QUA-001 QUA-004 */
final class TelegramMembershipJoinLocalizationResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_membership_join_copy_uses_canonical_resolver_and_observes_requested_locale_override(): void
    {
        $resolver = $this->app->make(TelegramMembershipJoinPresentationResolver::class);
        $property = new ReflectionProperty($resolver, 'localization');
        self::assertInstanceOf(LocalizationResolver::class, $property->getValue($resolver));

        $translation = new ReflectionMethod($resolver, 'translation');
        $key = 'telegram_membership.join_button';
        $replace = ['channel' => 'دانشگاه تهران'];

        self::assertSame(
            trans($key, $replace, 'fa'),
            $translation->invoke($resolver, $key, 'fa', $replace),
        );
        self::assertSame(
            trans($key, $replace, 'en'),
            $translation->invoke($resolver, $key, 'en', $replace),
        );

        $administratorId = $this->administrator();
        $now = now('UTC');
        DB::table('localization_overrides')->insert([
            'translation_key' => $key,
            'locale' => 'fa',
            'override_value' => 'ورود به :channel',
            'version' => 1,
            'updated_by_administrator_id' => $administratorId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        self::assertSame(
            'ورود به دانشگاه تهران',
            $translation->invoke($resolver, $key, 'fa', $replace),
        );
        self::assertSame(
            trans($key, $replace, 'en'),
            $translation->invoke($resolver, $key, 'en', $replace),
        );
    }

    public function test_membership_join_copy_rejects_missing_localization_instead_of_emitting_sentinel(): void
    {
        $resolver = $this->app->make(TelegramMembershipJoinPresentationResolver::class);
        $translation = new ReflectionMethod($resolver, 'translation');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Protected Telegram membership translation is unavailable.');

        $translation->invoke($resolver, 'telegram_membership.missing_copy', 'fa', []);
    }

    public function test_membership_join_copy_fails_closed_when_required_replacement_is_missing(): void
    {
        $resolver = $this->app->make(TelegramMembershipJoinPresentationResolver::class);
        $translation = new ReflectionMethod($resolver, 'translation');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Localization replacement is missing for placeholder: channel');

        $translation->invoke($resolver, 'telegram_membership.join_button', 'fa', []);
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
