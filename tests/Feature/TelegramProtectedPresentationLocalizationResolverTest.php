<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Localization\Application\LocalizationResolver;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseCardToCardPayment;
use App\Modules\Telegram\Application\TelegramProtectedPresentationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

/** @requirement LOC-001 PAY-003 SEC-003 QUA-001 QUA-004 */
final class TelegramProtectedPresentationLocalizationResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_protected_resolver_uses_canonical_localization_and_observes_placeholder_override(): void
    {
        $resolver = $this->resolver();
        $property = new ReflectionProperty($resolver, 'localization');
        self::assertInstanceOf(LocalizationResolver::class, $property->getValue($resolver));

        $translation = new ReflectionMethod($resolver, 'translation');
        $key = 'telegram.navigation.purchase.payment_methods.card_to_card_payment.protected_instructions';
        $replace = [
            'card_number' => '6037 9912 3456 7890',
            'amount' => '1,000,000',
            'currency' => 'IRR',
            'expires_at' => '2026-09-15 12:00:00',
        ];

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
            'translation_key' => implode('.', [
                'telegram',
                'navigation',
                'purchase',
                'payment_methods',
                'card_to_card_payment',
                'protected_instructions',
            ]),
            'locale' => 'fa',
            'override_value' => 'کارت :card_number | مبلغ :amount :currency | اعتبار تا :expires_at',
            'version' => 1,
            'updated_by_administrator_id' => $administratorId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        self::assertSame(
            'کارت 6037 9912 3456 7890 | مبلغ 1,000,000 IRR | اعتبار تا 2026-09-15 12:00:00',
            $translation->invoke($resolver, $key, 'fa', $replace),
        );
        self::assertSame(
            trans($key, $replace, 'en'),
            $translation->invoke($resolver, $key, 'en', $replace),
        );
    }

    public function test_protected_resolver_rejects_missing_localization_instead_of_emitting_sentinel(): void
    {
        $resolver = $this->resolver();
        $translation = new ReflectionMethod($resolver, 'translation');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Protected Telegram translation is unavailable.');

        $translation->invoke($resolver, 'telegram.missing_protected_copy', 'fa', []);
    }

    public function test_protected_resolver_fails_closed_when_required_replacements_are_missing(): void
    {
        $resolver = $this->resolver();
        $translation = new ReflectionMethod($resolver, 'translation');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Localization replacement is missing for placeholder:');

        $translation->invoke(
            $resolver,
            'telegram.navigation.purchase.payment_methods.card_to_card_payment.protected_instructions',
            'fa',
            ['card_number' => '6037 9912 3456 7890'],
        );
    }

    private function resolver(): TelegramProtectedPresentationResolver
    {
        return new TelegramProtectedPresentationResolver(
            $this->app->make(TelegramCustomerPurchaseCardToCardPayment::class),
            $this->app->make(LocalizationResolver::class),
            null,
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
