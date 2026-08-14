<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Application\QuotePricingInput;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
use App\Modules\Payments\GiftCard\Application\GiftCardSubmissionService;
use App\Modules\Payments\GiftCard\Application\GiftCardTypeService;
use App\Shared\Application\Clock;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class GiftCardRotationClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

/** @requirement GFT-001 DAT-002 DAT-003 SEC-002 QUA-004 */
final class GiftCardLookupKeyRotationTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use RefreshDatabase;

    private GiftCardRotationClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->clock = new GiftCardRotationClock(new DateTimeImmutable('2026-08-14T17:00:00+00:00'));
        $this->app->instance(Clock::class, $this->clock);
        $this->configureMethod();
        $this->app->make(GiftCardTypeService::class)->register(
            'gift-rotation',
            'Gift rotation type',
            'Steam',
            'GLOBAL',
            'IRR',
            'code_only',
            'automatic_only',
            null,
            'fake_gift_card',
        );
    }

    public function test_historical_lookup_keyring_prevents_code_reuse_after_multiple_rotations(): void
    {
        $oldKey = str_repeat('o', 32);
        $newKey = str_repeat('n', 32);
        $thirdKey = str_repeat('t', 32);
        config()->set('payments.gift_card.code_lookup_key', $oldKey);
        config()->set('payments.gift_card.code_lookup_key_version', 1);
        config()->set('payments.gift_card.code_lookup_previous_key', null);
        config()->set('payments.gift_card.code_lookup_previous_key_version', null);
        config()->set('payments.gift_card.code_lookup_historical_keys', null);

        $first = $this->purchase('first');
        $code = 'ROTATION-CARD-0001';
        $this->submit($first, 'first', $code);
        self::assertSame(hash_hmac('sha256', $code, $oldKey), DB::table('gift_card_submissions')->value('code_lookup_hash'));
        self::assertSame(1, (int) DB::table('gift_card_submissions')->value('code_lookup_key_version'));

        config()->set('payments.gift_card.code_lookup_key', $newKey);
        config()->set('payments.gift_card.code_lookup_key_version', 2);
        config()->set('payments.gift_card.code_lookup_previous_key', $oldKey);
        config()->set('payments.gift_card.code_lookup_previous_key_version', 1);

        $this->submit($first, 'first', $code);
        self::assertSame(1, DB::table('gift_card_submissions')->count());

        config()->set('payments.gift_card.code_lookup_key', $thirdKey);
        config()->set('payments.gift_card.code_lookup_key_version', 3);
        config()->set('payments.gift_card.code_lookup_previous_key', $newKey);
        config()->set('payments.gift_card.code_lookup_previous_key_version', 2);
        config()->set('payments.gift_card.code_lookup_historical_keys', [1 => $oldKey]);

        $this->submit($first, 'first', $code);
        self::assertSame(1, DB::table('gift_card_submissions')->count());

        $second = $this->purchase('second');

        try {
            $this->submit($second, 'second', $code);
            self::fail('Expected historical gift-card code reuse to be rejected after multiple key rotations.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('already bound to another purchase', $exception->getMessage());
        }

        self::assertSame(1, DB::table('gift_card_submissions')->count());
        self::assertSame(1, DB::table('payment_intents')->where('payment_method_code', 'gift_card')->count());
        self::assertSame(1, DB::table('gift_card_reconciliation_findings')
            ->where('finding_type', 'duplicate_submission_evidence')
            ->where('severity', 'high')
            ->count());
    }

    /** @return array{user_id:int,quote_public_id:string,eligibility_public_id:string,amount:int} */
    private function purchase(string $suffix): array
    {
        $user = $this->quoteUser('customer');
        $offering = $this->quoteOffering(1_000_000);
        $quote = $this->app->make(QuoteService::class)->create(
            'gift.rotation.quote.'.$suffix,
            $user,
            $offering['id'],
            new QuotePricingInput(QuoteOverrideSource::None, null, null, null, 0, $this->clock->value->modify('+30 minutes')),
            $this->correlation('quote-'.$suffix),
        );
        $decision = $this->app->make(PaymentMethodEligibilityService::class)->evaluate(
            'gift.rotation.eligibility.'.$suffix,
            $user,
            $quote->quotePublicId,
        );

        return [
            'user_id' => $user,
            'quote_public_id' => $quote->quotePublicId,
            'eligibility_public_id' => $decision->publicId,
            'amount' => $quote->finalPriceIrr,
        ];
    }

    private function submit(array $purchase, string $suffix, string $code): void
    {
        $this->app->make(GiftCardSubmissionService::class)->submit(
            'gift.rotation.submission.'.$suffix,
            'gift.rotation.intent.'.$suffix,
            $purchase['user_id'],
            $purchase['quote_public_id'],
            $purchase['eligibility_public_id'],
            'gift-rotation',
            $purchase['amount'],
            'IRR',
            'Steam',
            'GLOBAL',
            $code,
            null,
            null,
            null,
            null,
            $this->correlation('submit-'.$suffix),
        );
    }

    private function configureMethod(): void
    {
        $administratorId = $this->ownerAdministrator();
        $service = $this->app->make(PaymentMethodEligibilityService::class);
        $service->configureMethod(
            'gift.rotation.method',
            $administratorId,
            'gift_card',
            true,
            false,
            1,
            'Gift-card lookup-key rotation test method.',
            $this->correlation('method'),
        );
        $service->recordHealth(
            'gift.rotation.health',
            $administratorId,
            'gift_card',
            true,
            $this->clock->value->modify('+20 minutes'),
            'Gift-card rotation test provider is healthy.',
            $this->correlation('health'),
        );
    }

    private function correlation(string $suffix): string
    {
        return hash('sha256', 'gift-card-rotation-test:'.$suffix);
    }
}
