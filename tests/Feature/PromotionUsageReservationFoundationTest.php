<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\AccessControl\Application\AccessChangeContext;
use App\Modules\Orders\Application\QuoteReceipt;
use App\Modules\Promotions\Application\PromotionResolutionReceipt;
use App\Modules\Promotions\Application\PromotionRuleService;
use App\Modules\Promotions\Application\PromotionUsageContext;
use App\Modules\Promotions\Application\PromotionUsageReservationService;
use App\Modules\Promotions\Domain\PromotionAction;
use App\Modules\Promotions\Domain\PromotionAudience;
use App\Modules\Promotions\Domain\PromotionDiscountType;
use App\Modules\Promotions\Domain\PromotionRuleDefinition;
use App\Modules\Promotions\Domain\PromotionRuleKind;
use App\Modules\Promotions\Domain\PromotionRuleState;
use App\Modules\Promotions\Domain\PromotionUsageReservationState;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\CreatesPromotionUsageFixtures;
use Tests\TestCase;

final class MutablePromotionUsageClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

/** @requirement PRO-001 BUY-002 DAT-002 DAT-003 DAT-004 SEC-001 SEC-002 QUA-001 */
final class PromotionUsageReservationFoundationTest extends TestCase
{
    use CreatesPromotionUsageFixtures;
    use RefreshDatabase;

    private MutablePromotionUsageClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->clock = new MutablePromotionUsageClock(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $this->app->instance(Clock::class, $this->clock);
    }

    public function test_reserve_binds_resolution_and_quote_with_exact_replay_and_conflict(): void
    {
        $userId = $this->usageUser();
        $offering = $this->usageOffering(suffix: 'basic');
        $rule = $this->usageRule($offering['id'], 'promo.usage.basic', 100_000, 5, 2);
        [$resolution, $quote] = $this->pair($userId, $offering['id'], 'promo.usage.basic', 100_000, 'basic');
        $service = $this->service();

        $created = $service->reserve('usage.reserve.basic.000001', $resolution->resolutionPublicId, $quote->quotePublicId, new PromotionUsageContext($userId));
        self::assertFalse($created->replayed);
        self::assertSame(PromotionUsageReservationState::Active, $created->state);
        self::assertSame($resolution->resolutionPublicId, $created->resolutionPublicId);
        self::assertSame($quote->quotePublicId, $created->quotePublicId);
        self::assertSame($rule->configurationHash, $created->ruleConfigurationHash);
        self::assertSame(100_000, $created->discountIrr);

        $replay = $service->reserve('usage.reserve.basic.000001', $resolution->resolutionPublicId, $quote->quotePublicId, new PromotionUsageContext($userId));
        self::assertTrue($replay->replayed);
        self::assertSame($created->reservationId, $replay->reservationId);

        $otherQuote = $this->usageQuote($userId, $offering['id'], 'promo.usage.basic', 100_000, $this->clock->value->modify('+30 minutes'), 'basic-other');
        $this->expectRuntime('Promotion reservation key conflict.', fn () => $service->reserve(
            'usage.reserve.basic.000001',
            $resolution->resolutionPublicId,
            $otherQuote->quotePublicId,
            new PromotionUsageContext($userId),
        ));
        self::assertSame(1, DB::table('promotion_usage_reservations')->count());
    }

    public function test_release_replays_exactly_rejects_second_transition_and_returns_capacity(): void
    {
        $userId = $this->usageUser();
        $offering = $this->usageOffering(suffix: 'release');
        $this->usageRule($offering['id'], 'promo.usage.release', 80_000, 1, 1);
        [$resolution, $quote] = $this->pair($userId, $offering['id'], 'promo.usage.release', 80_000, 'release-first');
        $service = $this->service();
        $reservation = $service->reserve('usage.reserve.release.000001', $resolution->resolutionPublicId, $quote->quotePublicId, new PromotionUsageContext($userId));

        $released = $service->release('usage.release.release.000001', $reservation->reservationPublicId, new PromotionUsageContext($userId));
        self::assertFalse($released->replayed);
        $replay = $service->release('usage.release.release.000001', $reservation->reservationPublicId, new PromotionUsageContext($userId));
        self::assertTrue($replay->replayed);
        self::assertSame($released->releaseId, $replay->releaseId);
        $this->expectDomain('Promotion usage reservation is already released.', fn () => $service->release(
            'usage.release.release.000002',
            $reservation->reservationPublicId,
            new PromotionUsageContext($userId),
        ));

        [$nextResolution, $nextQuote] = $this->pair($userId, $offering['id'], 'promo.usage.release', 80_000, 'release-next');
        $next = $service->reserve('usage.reserve.release.000002', $nextResolution->resolutionPublicId, $nextQuote->quotePublicId, new PromotionUsageContext($userId));
        self::assertSame(PromotionUsageReservationState::Active, $next->state);
        self::assertSame(1, $this->activeReservations());
    }

    public function test_active_reservation_is_authoritative_capacity_not_observed_resolution_counters(): void
    {
        $firstUser = $this->usageUser();
        $secondUser = $this->usageUser();
        $offering = $this->usageOffering(suffix: 'capacity');
        $this->usageRule($offering['id'], 'promo.usage.capacity', 75_000, 1, null);
        [$firstResolution, $firstQuote] = $this->pair($firstUser, $offering['id'], 'promo.usage.capacity', 75_000, 'capacity-first');
        $this->service()->reserve('usage.reserve.capacity.000001', $firstResolution->resolutionPublicId, $firstQuote->quotePublicId, new PromotionUsageContext($firstUser));

        [$secondResolution, $secondQuote] = $this->pair($secondUser, $offering['id'], 'promo.usage.capacity', 75_000, 'capacity-second');
        self::assertTrue($secondResolution->matched());
        $this->expectDomain('Promotion global usage capacity is exhausted.', fn () => $this->service()->reserve(
            'usage.reserve.capacity.000002',
            $secondResolution->resolutionPublicId,
            $secondQuote->quotePublicId,
            new PromotionUsageContext($secondUser),
        ));
        self::assertSame(1, $this->activeReservations());
    }

    public function test_cross_user_and_inactive_subject_attempts_fail_before_effect(): void
    {
        $owner = $this->usageUser();
        $other = $this->usageUser();
        $offering = $this->usageOffering(suffix: 'authorization');
        $this->usageRule($offering['id'], 'promo.usage.auth', 60_000, 3, 1);
        [$resolution, $quote] = $this->pair($owner, $offering['id'], 'promo.usage.auth', 60_000, 'auth');

        $this->expectAuthorization(fn () => $this->service()->reserve(
            'usage.reserve.auth.000001',
            $resolution->resolutionPublicId,
            $quote->quotePublicId,
            new PromotionUsageContext($other),
        ));
        self::assertSame(0, DB::table('promotion_usage_reservations')->count());

        DB::table('users')->where('id', $owner)->update(['account_status' => 'suspended']);
        $this->expectDomain('Promotion usage reservation requires an active customer or agent.', fn () => $this->service()->reserve(
            'usage.reserve.auth.000001',
            $resolution->resolutionPublicId,
            $quote->quotePublicId,
            new PromotionUsageContext($owner),
        ));
    }

    public function test_cross_user_release_fails_before_replay_or_effect(): void
    {
        $owner = $this->usageUser();
        $other = $this->usageUser();
        $offering = $this->usageOffering(suffix: 'release-auth');
        $this->usageRule($offering['id'], 'promo.usage.releaseauth', 50_000, 3, 2);
        [$resolution, $quote] = $this->pair($owner, $offering['id'], 'promo.usage.releaseauth', 50_000, 'release-auth');
        $reservation = $this->service()->reserve('usage.reserve.releaseauth.000001', $resolution->resolutionPublicId, $quote->quotePublicId, new PromotionUsageContext($owner));

        $this->expectAuthorization(fn () => $this->service()->release(
            'usage.release.releaseauth.000001',
            $reservation->reservationPublicId,
            new PromotionUsageContext($other),
        ));
        self::assertSame(0, DB::table('promotion_usage_releases')->count());
    }

    public function test_no_match_and_referral_resolution_cannot_reserve_promotion_capacity(): void
    {
        $userId = $this->usageUser();
        $offering = $this->usageOffering(suffix: 'kind');
        $noMatch = $this->usageResolution($userId, $offering['id'], 1_000_000, 'kind-nomatch');
        $quote = $this->usageQuote($userId, $offering['id'], 'manual.discount', 1, $this->clock->value->modify('+30 minutes'), 'kind-nomatch');
        $this->expectDomain('Promotion usage reservation requires a matched positive-discount resolution.', fn () => $this->service()->reserve(
            'usage.reserve.kind.000001',
            $noMatch->resolutionPublicId,
            $quote->quotePublicId,
            new PromotionUsageContext($userId),
        ));

        $this->usageRule($offering['id'], 'ref.usage.rule', 50_000, 5, 2, PromotionRuleKind::Referral, 'ref-source');
        $referral = $this->usageResolution($userId, $offering['id'], 1_000_000, 'kind-referral', 'ref-source');
        $referralQuote = $this->usageQuote($userId, $offering['id'], 'ref.usage.rule', 50_000, $this->clock->value->modify('+30 minutes'), 'kind-referral');
        self::assertSame(PromotionRuleKind::Referral, $referral->ruleKind);
        $this->expectDomain('Promotion usage reservation requires a promotion resolution.', fn () => $this->service()->reserve(
            'usage.reserve.kind.000002',
            $referral->resolutionPublicId,
            $referralQuote->quotePublicId,
            new PromotionUsageContext($userId),
        ));
    }

    public function test_quote_subject_discount_and_expiry_mismatches_fail_closed(): void
    {
        $userId = $this->usageUser();
        $otherUser = $this->usageUser();
        $offering = $this->usageOffering(suffix: 'quote');
        $this->usageRule($offering['id'], 'promo.usage.quote', 90_000, 5, 2);
        $resolution = $this->usageResolution($userId, $offering['id'], 1_000_000, 'quote');

        $wrongSubject = $this->usageQuote($otherUser, $offering['id'], 'promo.usage.quote', 90_000, $this->clock->value->modify('+30 minutes'), 'quote-subject');
        $this->expectDomain('Quote does not match the immutable promotion resolution.', fn () => $this->service()->reserve(
            'usage.reserve.quote.000001', $resolution->resolutionPublicId, $wrongSubject->quotePublicId, new PromotionUsageContext($userId),
        ));
        $wrongDiscount = $this->usageQuote($userId, $offering['id'], 'other.discount', 90_000, $this->clock->value->modify('+30 minutes'), 'quote-discount');
        $this->expectDomain('Quote does not match the immutable promotion resolution.', fn () => $this->service()->reserve(
            'usage.reserve.quote.000002', $resolution->resolutionPublicId, $wrongDiscount->quotePublicId, new PromotionUsageContext($userId),
        ));
        $expiring = $this->usageQuote($userId, $offering['id'], 'promo.usage.quote', 90_000, $this->clock->value->modify('+1 minute'), 'quote-expired');
        $this->clock->value = $this->clock->value->modify('+2 minutes');
        $this->expectDomain('Promotion reservation requires a currently valid quote.', fn () => $this->service()->reserve(
            'usage.reserve.quote.000003', $resolution->resolutionPublicId, $expiring->quotePublicId, new PromotionUsageContext($userId),
        ));
    }

    public function test_reservation_replay_preserves_historical_rule_identity_after_disable(): void
    {
        $userId = $this->usageUser();
        $offering = $this->usageOffering(suffix: 'history');
        $created = $this->usageRule($offering['id'], 'promo.usage.history', 70_000, 4, 2);
        [$resolution, $quote] = $this->pair($userId, $offering['id'], 'promo.usage.history', 70_000, 'history');
        $reservation = $this->service()->reserve('usage.reserve.history.000001', $resolution->resolutionPublicId, $quote->quotePublicId, new PromotionUsageContext($userId));

        $this->app->make(PromotionRuleService::class)->revise(
            'usage.rule.history.disable.000001',
            'promo.usage.history',
            new PromotionRuleDefinition(
                PromotionRuleState::Disabled,
                10,
                PromotionDiscountType::Fixed,
                70_000,
                null,
                0,
                null,
                null,
                null,
                4,
                2,
                false,
                PromotionAudience::Both,
                null,
                null,
                $offering['id'],
                null,
                null,
                PromotionAction::Purchase,
            ),
            new AccessChangeContext(
                hash('sha256', 'usage-history-disable'),
                substr(hash('sha256', 'usage-history-disable-correlation'), 0, 64),
                'promotion_usage_test',
                'Disable after reservation to prove historical stability.',
                $this->usageAdministrator(),
            ),
        );

        $replay = $this->service()->reserve('usage.reserve.history.000001', $resolution->resolutionPublicId, $quote->quotePublicId, new PromotionUsageContext($userId));
        self::assertTrue($replay->replayed);
        self::assertSame($reservation->reservationId, $replay->reservationId);
        self::assertSame(1, $replay->ruleVersion);
        self::assertSame($created->configurationHash, $replay->ruleConfigurationHash);
    }

    public function test_release_key_conflict_fails_closed(): void
    {
        $userId = $this->usageUser();
        $offering = $this->usageOffering(suffix: 'release-key');
        $this->usageRule($offering['id'], 'promo.usage.releasekey', 45_000, 3, 3);
        [$firstResolution, $firstQuote] = $this->pair($userId, $offering['id'], 'promo.usage.releasekey', 45_000, 'release-key-first');
        [$secondResolution, $secondQuote] = $this->pair($userId, $offering['id'], 'promo.usage.releasekey', 45_000, 'release-key-second');
        $first = $this->service()->reserve('usage.reserve.releasekey.000001', $firstResolution->resolutionPublicId, $firstQuote->quotePublicId, new PromotionUsageContext($userId));
        $second = $this->service()->reserve('usage.reserve.releasekey.000002', $secondResolution->resolutionPublicId, $secondQuote->quotePublicId, new PromotionUsageContext($userId));
        $this->service()->release('usage.release.sharedkey.000001', $first->reservationPublicId, new PromotionUsageContext($userId));
        $this->expectRuntime('Promotion release key conflict.', fn () => $this->service()->release(
            'usage.release.sharedkey.000001', $second->reservationPublicId, new PromotionUsageContext($userId),
        ));
        self::assertSame(1, DB::table('promotion_usage_releases')->count());
    }

    public function test_database_guards_reject_mutation_forged_resolution_and_release_identity(): void
    {
        $firstUser = $this->usageUser();
        $secondUser = $this->usageUser();
        $offering = $this->usageOffering(suffix: 'guards');
        $this->usageRule($offering['id'], 'promo.usage.guards', 55_000, 5, 2);
        [$resolution, $quote] = $this->pair($firstUser, $offering['id'], 'promo.usage.guards', 55_000, 'guards-first');
        $reservation = $this->service()->reserve('usage.reserve.guards.000001', $resolution->resolutionPublicId, $quote->quotePublicId, new PromotionUsageContext($firstUser));

        $this->expectQuery(fn () => DB::table('promotion_usage_reservations')->where('id', $reservation->reservationId)->update(['discount_irr' => 1]));
        $this->expectQuery(fn () => DB::table('promotion_usage_reservations')->where('id', $reservation->reservationId)->delete());

        $resolutionRow = DB::table('pricing_rule_resolutions')->where('public_id', $resolution->resolutionPublicId)->first();
        self::assertNotNull($resolutionRow);
        $forgedResolution = (array) $resolutionRow;
        unset($forgedResolution['id']);
        $forgedResolution['public_id'] = (string) Str::ulid();
        $forgedResolution['resolution_key'] = 'usage.resolve.forged.'.Str::lower(Str::random(12));
        $forgedResolution['request_payload_hash'] = hash('sha256', 'forged-resolution');
        $forgedResolution['rule_version'] = (int) $resolutionRow->rule_version + 1;
        $this->expectQuery(fn () => DB::table('pricing_rule_resolutions')->insert($forgedResolution));

        $stored = DB::table('promotion_usage_reservations')->where('id', $reservation->reservationId)->first();
        self::assertNotNull($stored);
        $this->expectQuery(fn () => DB::table('promotion_usage_releases')->insert([
            'public_id' => (string) Str::ulid(),
            'release_key' => 'usage.release.guards.forged.000001',
            'request_payload_hash' => hash('sha256', 'forged-release'),
            'promotion_usage_reservation_id' => $reservation->reservationId,
            'released_by_user_id' => $secondUser,
            'reservation_configuration_snapshot_hash' => $stored->configuration_snapshot_hash,
            'created_at' => now('UTC'),
        ]));

        $this->service()->release('usage.release.guards.000001', $reservation->reservationPublicId, new PromotionUsageContext($firstUser));
        $this->expectQuery(fn () => DB::table('promotion_usage_releases')->update(['request_payload_hash' => hash('sha256', 'changed')]));
        $this->expectQuery(fn () => DB::table('promotion_usage_releases')->delete());
    }

    /** @return array{0:PromotionResolutionReceipt,1:QuoteReceipt} */
    private function pair(int $userId, int $offeringId, string $ruleCode, int $discountIrr, string $suffix): array
    {
        return [
            $this->usageResolution($userId, $offeringId, 1_000_000, $suffix),
            $this->usageQuote($userId, $offeringId, $ruleCode, $discountIrr, $this->clock->value->modify('+30 minutes'), $suffix),
        ];
    }

    private function service(): PromotionUsageReservationService
    {
        return $this->app->make(PromotionUsageReservationService::class);
    }

    private function activeReservations(): int
    {
        return (int) DB::table('promotion_usage_reservations as reservation')
            ->leftJoin('promotion_usage_releases as release', 'release.promotion_usage_reservation_id', '=', 'reservation.id')
            ->whereNull('release.id')
            ->count();
    }

    private function expectDomain(string $message, callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected domain exception.');
        } catch (DomainException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }

    private function expectRuntime(string $message, callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected runtime exception.');
        } catch (RuntimeException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }

    private function expectAuthorization(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected authorization exception.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }
    }

    private function expectQuery(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected database guard rejection.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }
}
