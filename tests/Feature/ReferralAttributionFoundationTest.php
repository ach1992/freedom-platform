<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Application\QuotePricingInput;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use App\Modules\Payments\Application\Contracts\PaymentEvidence;
use App\Modules\Payments\Application\Contracts\PaymentEvidenceAuthority;
use App\Modules\Payments\Application\Contracts\PaymentTransactionStatus;
use App\Modules\Payments\Application\Contracts\ProviderOperationOutcome;
use App\Modules\Payments\Application\Contracts\VerifiedPaymentEvent;
use App\Modules\Payments\Application\PurchasePaymentIntentService;
use App\Modules\Payments\Application\PurchaseSettlementService;
use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
use App\Modules\Promotions\Application\ReferralAttributionService;
use App\Shared\Application\Clock;
use App\Shared\Domain\Money;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use DateTimeImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ReferralAttributionClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

/** @requirement REF-001 ONB-002 ACL-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 */
final class ReferralAttributionFoundationTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use RefreshDatabase;

    private ReferralAttributionClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->clock = new ReferralAttributionClock(new DateTimeImmutable('2026-08-13T04:30:00+00:00'));
        $this->app->instance(Clock::class, $this->clock);
    }

    public function test_every_user_gets_stable_identity_and_first_binding_is_replay_safe_and_self_referral_safe(): void
    {
        $service = $this->app->make(ReferralAttributionService::class);
        $inviter = $this->quoteUser('customer');
        $otherInviter = $this->quoteUser('customer');
        $referred = $this->quoteUser('customer');

        $token = $service->identityForUser($inviter);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $token);
        self::assertSame($token, $service->identityForUser($inviter));
        self::assertSame(1, DB::table('referral_identities')->where('user_id', $inviter)->count());

        $created = $service->bind($referred, $token);
        self::assertFalse($created->replayed);
        self::assertSame($inviter, $created->inviterUserId);

        $replay = $service->bind($referred, $token);
        self::assertTrue($replay->replayed);
        self::assertSame($created->relationshipId, $replay->relationshipId);
        self::assertSame(1, DB::table('referral_relationships')->where('referred_user_id', $referred)->count());
        self::assertSame(1, DB::table('referral_attribution_events')->where('relationship_id', $created->relationshipId)->count());

        $this->assertDomainMessage(
            'Referral inviter is already bound.',
            fn (): mixed => $service->bind($referred, $service->identityForUser($otherInviter)),
        );
        $this->assertDomainMessage(
            'Self-referral is not allowed.',
            fn (): mixed => $service->bind($inviter, $token),
        );
    }

    public function test_only_current_owner_can_correct_inviter_before_successful_purchase(): void
    {
        $service = $this->app->make(ReferralAttributionService::class);
        $ownerId = $this->ownerAdministrator();
        $nonOwnerId = (int) DB::table('administrators')->insertGetId([
            'user_id' => $this->quoteUser('customer'),
            'status' => 'active',
            'is_owner' => false,
            'permission_version' => 1,
            'last_authenticated_at' => now('UTC'),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $firstInviter = $this->quoteUser('customer');
        $secondInviter = $this->quoteUser('customer');
        $referred = $this->quoteUser('customer');
        $binding = $service->bind($referred, $service->identityForUser($firstInviter));

        try {
            $service->correctByOwner($nonOwnerId, $referred, $service->identityForUser($secondInviter));
            self::fail('Expected owner-only authorization rejection.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }

        $corrected = $service->correctByOwner($ownerId, $referred, $service->identityForUser($secondInviter));
        self::assertFalse($corrected->replayed);
        self::assertSame($secondInviter, $corrected->inviterUserId);
        self::assertSame($binding->relationshipId, $corrected->relationshipId);

        $replay = $service->correctByOwner($ownerId, $referred, $service->identityForUser($secondInviter));
        self::assertTrue($replay->replayed);
        self::assertSame(2, DB::table('referral_attribution_events')->where('relationship_id', $binding->relationshipId)->count());
        self::assertSame(['bound', 'corrected'], DB::table('referral_attribution_events')
            ->where('relationship_id', $binding->relationshipId)
            ->orderBy('id')
            ->pluck('event_type')
            ->all());
    }

    public function test_first_authoritative_purchase_locks_inviter_and_database_guards_preserve_history(): void
    {
        $service = $this->app->make(ReferralAttributionService::class);
        $ownerId = $this->ownerAdministrator();
        $inviter = $this->quoteUser('customer');
        $replacementInviter = $this->quoteUser('customer');
        $referred = $this->quoteUser('customer');
        $binding = $service->bind($referred, $service->identityForUser($inviter));

        $settlementId = $this->capturePurchaseFor($referred, 'lock');
        $relationship = DB::table('referral_relationships')->where('id', $binding->relationshipId)->first();
        self::assertNotNull($relationship);
        self::assertSame($settlementId, (int) $relationship->locked_purchase_settlement_id);
        self::assertNotNull($relationship->locked_at);
        self::assertSame(['bound', 'locked'], DB::table('referral_attribution_events')
            ->where('relationship_id', $binding->relationshipId)
            ->orderBy('id')
            ->pluck('event_type')
            ->all());

        $this->assertDomainMessage(
            'Referral inviter is locked after successful purchase.',
            fn (): mixed => $service->correctByOwner($ownerId, $referred, $service->identityForUser($replacementInviter)),
        );

        $replacementIdentityId = (int) DB::table('referral_identities')->where('user_id', $replacementInviter)->value('id');
        $this->assertQueryRejected(static fn (): int => DB::table('referral_relationships')
            ->where('id', $binding->relationshipId)
            ->update([
                'inviter_user_id' => $replacementInviter,
                'inviter_referral_identity_id' => $replacementIdentityId,
                'updated_at' => now('UTC'),
            ]));
        $this->assertQueryRejected(static fn (): int => DB::table('referral_relationships')
            ->where('id', $binding->relationshipId)
            ->delete());
        $this->assertQueryRejected(static fn (): int => DB::table('referral_attribution_events')
            ->where('relationship_id', $binding->relationshipId)
            ->limit(1)
            ->update(['event_type' => 'corrected']));
        $this->assertQueryRejected(static fn (): int => DB::table('referral_attribution_events')
            ->where('relationship_id', $binding->relationshipId)
            ->limit(1)
            ->delete());

        $lateReferred = $this->quoteUser('customer');
        $this->capturePurchaseFor($lateReferred, 'late');
        $this->assertDomainMessage(
            'Referral inviter cannot be bound after a successful purchase.',
            fn (): mixed => $service->bind($lateReferred, $service->identityForUser($inviter)),
        );
    }

    public function test_database_rejects_cross_user_inviter_identity_and_identity_mutation(): void
    {
        $service = $this->app->make(ReferralAttributionService::class);
        $inviter = $this->quoteUser('customer');
        $otherInviter = $this->quoteUser('customer');
        $referred = $this->quoteUser('customer');
        $identityId = (int) DB::table('referral_identities')->where('user_id', $inviter)->value('id');

        $this->assertQueryRejected(static fn (): bool => DB::table('referral_relationships')->insert([
            'referred_user_id' => $referred,
            'inviter_user_id' => $otherInviter,
            'inviter_referral_identity_id' => $identityId,
            'locked_purchase_settlement_id' => null,
            'bound_at' => now('UTC'),
            'locked_at' => null,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]));

        $this->assertQueryRejected(static fn (): int => DB::table('referral_identities')
            ->where('user_id', $inviter)
            ->update(['token' => str_repeat('a', 32)]));
        $this->assertQueryRejected(static fn (): int => DB::table('referral_identities')
            ->where('user_id', $inviter)
            ->delete());

        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $service->identityForUser($inviter));
    }

    private function capturePurchaseFor(int $userId, string $suffix): int
    {
        $administratorId = $this->ownerAdministrator();
        $quote = $this->quoteForUser($userId, $suffix);
        $methodCode = 'referral_gateway_'.$suffix;
        $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $eligibility->configureMethod(
            'referral.method.'.$suffix,
            $administratorId,
            $methodCode,
            true,
            false,
            1,
            'Referral attribution settlement test configuration.',
            $this->correlation('method-'.$suffix),
        );
        $eligibility->recordHealth(
            'referral.health.'.$suffix,
            $administratorId,
            $methodCode,
            true,
            $this->clock->value->modify('+10 minutes'),
            'Healthy referral attribution test observation.',
            $this->correlation('health-'.$suffix),
        );
        $decision = $eligibility->evaluate('referral.eligibility.'.$suffix, $userId, $quote->quotePublicId);
        $purchase = $this->app->make(PurchasePaymentIntentService::class)->create(
            'referral.purchase.intent.'.$suffix,
            $userId,
            $quote->quotePublicId,
            $decision->publicId,
            $methodCode,
            $this->correlation('intent-'.$suffix),
        );
        DB::table('payment_intents')->where('public_id', $purchase->intentPublicId)->update([
            'state' => 'submitted',
            'updated_at' => $this->timestamp(),
        ]);

        $settlement = $this->app->make(PurchaseSettlementService::class)->capture(
            $purchase->intentPublicId,
            $methodCode,
            new VerifiedPaymentEvent(
                'referral-event-'.$suffix,
                hash('sha256', 'referral-provider-event:'.$suffix),
                new PaymentEvidence(
                    ProviderOperationOutcome::Success,
                    PaymentEvidenceAuthority::Authoritative,
                    PaymentTransactionStatus::Settled,
                    'referral-transaction-'.$suffix,
                    'referral-event-'.$suffix,
                    Money::irr($purchase->amount->amount()),
                    $this->clock->value,
                    $this->clock->value,
                    hash('sha256', 'referral-provider-evidence:'.$suffix),
                    ['provider_reference' => 'referral-transaction-'.$suffix],
                ),
            ),
            $this->correlation('capture-'.$suffix),
        );

        return $settlement->settlementId;
    }

    private function quoteForUser(int $userId, string $suffix): object
    {
        $offering = $this->quoteOffering();

        return $this->app->make(QuoteService::class)->create(
            'referral.quote.'.$suffix,
            $userId,
            $offering['id'],
            new QuotePricingInput(
                QuoteOverrideSource::None,
                null,
                null,
                null,
                0,
                $this->clock->value->modify('+30 minutes'),
            ),
            $this->correlation('quote-'.$suffix),
        );
    }

    private function correlation(string $suffix): string
    {
        return hash('sha256', 'referral-attribution:'.$suffix);
    }

    private function timestamp(): string
    {
        return $this->clock->value->format('Y-m-d H:i:s.u');
    }

    private function assertDomainMessage(string $message, callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected domain exception.');
        } catch (DomainException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }

    private function assertQueryRejected(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected database guard rejection.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }
}
