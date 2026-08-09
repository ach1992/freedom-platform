<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\AccessControl\Application\AccessChangeContext;
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

    public function test_reserve_binds_immutable_resolution_and_quote_and_exact_replay_conflicts_on_changed_payload(): void
    {
        $userId = $this->usageUser();
        $offering = $this->usageOffering(suffix: 'reserve-basic');
        $rule = $this->usageRule($offering['id'], 'promo.usage.basic', 100_000, 5, 2);
        $resolution = $this->usageResolution($userId, $offering['id'], 1_000_000, 'reserve-basic');
        $quote = $this->usageQuote($userId, $offering['id'], 'promo.usage.basic', 100_000, $this->clock->value->modify('+30 minutes'), 'reserve-basic');
        $service = $this->app->make(PromotionUsageReservationService::class);

        $created = $service->reserve('usage.reserve.basic.000001', $resolution->resolutionPublicId, $quote->quotePublicId, new PromotionUsageContext($userId));
        self::assertFalse($created->replayed);
        self::assertSame(PromotionUsageReservationState::Active, $created->state);
        self::assertSame($resolution->resolutionPublicId, $created->resolutionPublicId);
        self::assertSame($quote->quotePublicId, $created->quotePublicId);
        self::assertSame($rule->configurationHash, $created->ruleConfigurationHash);
        self::assertSame(100_000, $created->discountIrr);
        self::assertSame(1, DB::table('promotion_usage_reservations')->count());
        self::assertSame(0, DB::table('promotion_usage_releases')->count());

        $replay = $service->reserve('usage.reserve.basic.000001', $resolution->resolutionPublicId, $quote->quotePublicId, new PromotionUsageContext($userId));
        self::assertTrue($replay->replayed);
        self::assertSame($created->reservationId, $replay->reservationId);
        self::assertSame($created->reservationPublicId, $replay->reservationPublicId);

        $otherQuote = $this->usageQuote($userId, $offering['id'], 'promo.usage.basic', 100_000, $this->clock->value->modify('+30 minutes'), 'reserve-basic-other');
        $this->assertRuntimeMessage(
            'Promotion reservation key conflict.',
            fn (): mixed => $service->reserve('usage.reserve.basic.000001', $resolution->resolutionPublicId, $otherQuote->quotePublicId, new PromotionUsageContext($userId)),
        );
        self::assertSame(1, DB::table('promotion_usage_reservations')->count());
    }

    public function test_release_is_exact_idempotent_returns_capacity_and_conflicting_transition_fails_closed(): void
    {
        $userId = $this->usageUser();
        $offering = $this->usageOffering(suffix: 'release');
        $this->usageRule($offering['id'], 'promo.usage.release', 80_000, 1, 1);
        $service = $this->app->make(PromotionUsageReservationService::class);
        [$firstResolution, $firstQuote] = $this->resolutionAndQuote($userId, $offering['id'], 'promo.usage.release', 80_000, 'release-first');
        $reservation = $service->reserve('usage.reserve.release.000001', $firstResolution->resolutionPublicId, $firstQuote->quotePublicId, new PromotionUsageContext($userId));

        $released = $service->release('usage.release.release.000001', $reservation->reservationPublicId, new PromotionUsageContext($userId));
        self::assertFalse($released->replayed);
        self::assertSame($reservation->reservationPublicId, $released->reservationPublicId);
        self::assertSame(1, DB::table('promotion_usage_releases')->count());

        $replay = $service->release('usage.release.release.000001', $reservation->reservationPublicId, new PromotionUsageContext($userId));
        self::assertTrue($replay->replayed);
        self::assertSame($released->releaseId, $replay->releaseId);

        $this->assertDomainMessage(
            'Promotion usage reservation is already released.',
            fn (): mixed => $service->release('usage.release.release.000002', $reservation->reservationPublicId, new PromotionUsageContext($userId)),
        );

        [$secondResolution, $secondQuote] = $this->resolutionAndQuote($userId, $offering['id'], 'promo.usage.release', 80_000, 'release-second');
        $second = $service->reserve('usage.reserve.release.000002', $secondResolution->resolutionPublicId, $secondQuote->quotePublicId, new PromotionUsageContext($userId));
        self::assertSame(PromotionUsageReservationState::Active, $second->state);
        self::assertSame(2, DB::table('promotion_usage_reservations')->count());
        self::assertSame(1, DB::table('promotion_usage_releases')->count());
    }

    public function test_active_reservation_uses_authoritative_global_capacity_even_when_new_resolution_observed_counters_are_zero(): void
    {
        $firstUser = $this->usageUser();
        $secondUser = $this->usageUser();
        $offering = $this->usageOffering(suffix: 'global-authority');
        $this->usageRule($offering['id'], 'promo.usage.global', 75_000, 1, null);
        $service = $this->app->make(PromotionUsageReservationService::class);
        [$firstResolution, $firstQuote] = $this->resolutionAndQuote($firstUser, $offering['id'], 'promo.usage.global', 75_000, 'global-first');
        $service->reserve('usage.reserve.global.000001', $firstResolution->resolutionPublicId, $firstQuote->quotePublicId, new PromotionUsageContext($firstUser));

        [$secondResolution, $secondQuote] = $this->resolutionAndQuote($secondUser, $offering['id'], 'promo.usage.global', 75_000, 'global-second');
        self::assertTrue($secondResolution->matched());
        $this->assertDomainMessage(
            'Promotion global usage capacity is exhausted.',
            fn (): mixed => $service->reserve('usage.reserve.global.000002', $secondResolution->resolutionPublicId, $secondQuote->quotePublicId, new PromotionUsageContext($secondUser)),
        );
        self::assertSame(1, $this->activeReservationCount());
    }

    public function test_cross_user_reserve_and_release_fail_before_replay_or_effect(): void
    {
        $owner = $this->usageUser();
        $other = $this->usageUser();
        $offering = $this->usageOffering(suffix: 'cross-user');
        $this->usageRule($offering['id'], 'promo.usage.cross', 60_000, 2, 1);
        [$resolution, $quote] = $this->resolutionAndQuote($owner, $offering['id'], 'promo.usage.cross', 60_000, 'cross-user');
        $service = $this->app->make(PromotionUsageReservationService::class);

        $this->assertAuthorizationDenied(
            fn (): mixed => $service->reserve('usage.reserve.cross.000001', $resolution->resolutionPublicId, $quote->quotePublicId, new PromotionUsageContext($other)),
        );
        self::assertSame(0, DB::table('promotion_usage_reservations')->count());

        $reservation = $service->reserve('usage.reserve.cross.000001', $resolution->resolutionPublicId, $quote->quotePublicId, new PromotionUsageContext($owner));
        $this->assertAuthorizationDenied(
            fn (): mixed => $service->release('usage.release.cross.000001', $reservation->reservationPublicId, new PromotionUsageContext($other)),
        );
        self::assertSame(0, DB::table('promotion_usage_releases')->count());
    }

    public function test_no_match_and_referral_resolutions_cannot_reserve_promotion_capacity(): void
    {
        $userId = $this->usageUser();
        $offering = $this->usageOffering(suffix: 'invalid-kinds');
        $noMatch = $this->usageResolution($userId, $offering['id'], 1_000_000, 'no-match');
        self::assertFalse($noMatch->matched());
        $manualQuote = $this->usageQuote($userId, $offering['id'], 'manual.discount', 1, $this->clock->value->modify('+30 minutes'), 'no-match');
        $service = $this->app->make(PromotionUsageReservationService::class);
        $this->assertDomainMessage(
            'Promotion usage reservation requires a matched positive-discount resolution.',
            fn (): mixed => $service->reserve('usage.reserve.nomatch.000001', $noMatch->resolutionPublicId, $manualQuote->quotePublicId, new PromotionUsageContext($userId)),
        );

        $this->usageRule($offering['id'], 'ref.usage.rule', 50_000, 5, 2, PromotionRuleKind::Referral, 'ref-source');
        $referral = $this->usageResolution($userId, $offering['id'], 1_000_000, 'referral', 'ref-source');
        self::assertSame(PromotionRuleKind::Referral, $referral->ruleKind);
        $referralQuote = $this->usageQuote($userId, $offering['id'], 'ref.usage.rule', 50_000, $this->clock->value->modify('+30 minutes'), 'referral');
        $this->assertDomainMessage(
            'Promotion usage reservation requires a promotion resolution.',
            fn (): mixed => $service->reserve('usage.reserve.referral.000001', $referral->resolutionPublicId, $referralQuote->quotePublicId, new PromotionUsageContext($userId)),
        );
        self::assertSame(0, DB::table('promotion_usage_reservations')->count());
    }

    public function test_quote_subject_discount_context_and_expiry_fail_closed(): void
    {
        $userId = $this->usageUser();
        $otherUser = $this->usageUser();
        $offering = $this->usageOffering(suffix: 'quote-binding');
        $this->usageRule($offering['id'], 'promo.usage.quote', 90_000, 5, 2);
        $resolution = $this->usageResolution($userId, $offering['id'], 1_000_000, 'quote-binding');
        $service = $this->app->make(PromotionUsageReservationService::class);

        $wrongSubject = $this->usageQuote($otherUser, $offering['id'], 'promo.usage.quote', 90_000, $this->clock->value->modify('+30 minutes'), 'wrong-subject');
        $this->assertDomainMessage(
            'Quote does not match the immutable promotion resolution.',
            fn (): mixed => $service->reserve('usage.reserve.quote.000001', $resolution->resolutionPublicId, $wrongSubject->quotePublicId, new PromotionUsageContext($userId)),
        );

        $wrongDiscount = $this->usageQuote($userId, $offering['id'], 'manual.other', 90_000, $this->clock->value->modify('+30 minutes'), 'wrong-discount');
        $this->assertDomainMessage(
            'Quote does not match the immutable promotion resolution.',
            fn (): mixed => $service->reserve('usage.reserve.quote.000002', $resolution->resolutionPublicId, $wrongDiscount->quotePublicId, new PromotionUsageContext($userId)),
        );

        $expiring = $this->usageQuote($userId, $offering['id'], 'promo.usage.quote', 90_000, $this->clock->value->modify('+1 minute'), 'expired');
        $this->clock->value = $this->clock->value->modify('+2 minutes');
        $this->assertDomainMessage(
            'Promotion reservation requires a currently valid quote.',
            fn (): mixed => $service->reserve('usage.reserve.quote.000003', $resolution->resolutionPublicId, $expiring->quotePublicId, new PromotionUsageContext($userId)),
        );
        self::assertSame(0, DB::table('promotion_usage_reservations')->count());
    }

    public function test_reservation_remains_interpretable_after_rule_disable_without_mutable_reresolution(): void
    {
        $userId = $this->usageUser();
        $offering = $this->usageOffering(suffix: 'history');
        $createdRule = $this->usageRule($offering['id'], 'promo.usage.history', 70_000, 4, 2);
        [$resolution, $quote] = $this->resolutionAndQuote($userId, $offering['id'], 'promo.usage.history', 70_000, 'history');
        $service = $this->app->make(PromotionUsageReservationService::class);
        $reservation = $service->reserve('usage.reserve.history.000001', $resolution->resolutionPublicId, $quote->quotePublicId, new PromotionUsageContext($userId));

        $owner = $this->usageAdministrator();
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
                $owner,
            ),
        );

        $replay = $service->reserve('usage.reserve.history.000001', $resolution->resolutionPublicId, $quote->quotePublicId, new PromotionUsageContext($userId));
        self::assertTrue($replay->replayed);
        self::assertSame($reservation->reservationId, $replay->reservationId);
        self::assertSame(1, $replay->ruleVersion);
        self::assertSame($createdRule->configurationHash, $replay->ruleConfigurationHash);
        self::assertSame(2, DB::table('pricing_rule_versions')->where('pricing_rule_id', $createdRule->ruleId)->count());
    }

    public function test_database_guards_reject_mutation_forged_snapshot_duplicate_identity_and_cross_user_release(): void
    {
        $firstUser = $this->usageUser();
        $secondUser = $this->usageUser();
        $offering = $this->usageOffering(suffix: 'db-guards');
        $this->usageRule($offering['id'], 'promo.usage.db', 55_000, 5, 2);
        $service = $this->app->make(PromotionUsageReservationService::class);
        [$firstResolution, $firstQuote] = $this->resolutionAndQuote($firstUser, $offering['id'], 'promo.usage.db', 55_000, 'db-first');
        $reservation = $service->reserve('usage.reserve.db.000001', $firstResolution->resolutionPublicId, $firstQuote->quotePublicId, new PromotionUsageContext($firstUser));

        $this->assertQueryRejected(static fn (): int => DB::table('promotion_usage_reservations')->where('id', $reservation->reservationId)->update(['discount_irr' => 1]));
        $this->assertQueryRejected(static fn (): int => DB::table('promotion_usage_reservations')->where('id', $reservation->reservationId)->delete());

        [$secondResolution, $secondQuote] = $this->resolutionAndQuote($secondUser, $offering['id'], 'promo.usage.db', 55_000, 'db-second');
        $stored = DB::table('promotion_usage_reservations')->where('id', $reservation->reservationId)->first();
        self::assertNotNull($stored);
        /** @var array<string, mixed> $forged */
        $forged = (array) $stored;
        unset($forged['id']);
        $secondResolutionRow = DB::table('pricing_rule_resolutions')->where('public_id', $secondResolution->resolutionPublicId)->first();
        $secondQuoteRow = DB::table('quotes')->where('public_id', $secondQuote->quotePublicId)->first();
        self::assertNotNull($secondResolutionRow);
        self::assertNotNull($secondQuoteRow);
        $forged['public_id'] = (string) Str::ulid();
        $forged['reservation_key'] = 'usage.reserve.db.forged.000001';
        $forged['request_payload_hash'] = hash('sha256', 'forged-request');
        $forged['pricing_rule_resolution_id'] = $secondResolutionRow->id;
        $forged['resolution_public_id_snapshot'] = $secondResolutionRow->public_id;
        $forged['resolution_configuration_snapshot_hash'] = $secondResolutionRow->configuration_snapshot_hash;
        $forged['quote_id'] = $secondQuoteRow->id;
        $forged['quote_public_id_snapshot'] = $secondQuoteRow->public_id;
        $forged['quote_configuration_snapshot_hash'] = $secondQuoteRow->configuration_snapshot_hash;
        $forged['user_id'] = $secondUser;
        $forged['configuration_snapshot_hash'] = str_repeat('0', 64);
        $this->assertQueryRejected(static fn (): bool => DB::table('promotion_usage_reservations')->insert($forged));

        $this->assertQueryRejected(static fn (): bool => DB::table('promotion_usage_releases')->insert([
            'public_id' => (string) Str::ulid(),
            'release_key' => 'usage.release.db.forged.000001',
            'request_payload_hash' => hash('sha256', 'forged-release'),
            'promotion_usage_reservation_id' => $reservation->reservationId,
            'released_by_user_id' => $secondUser,
            'reservation_configuration_snapshot_hash' => $stored->configuration_snapshot_hash,
            'created_at' => now('UTC'),
        ]));

        $service->release('usage.release.db.000001', $reservation->reservationPublicId, new PromotionUsageContext($firstUser));
        $this->assertQueryRejected(static fn (): int => DB::table('promotion_usage_releases')->update(['request_payload_hash' => hash('sha256', 'changed')]));
        $this->assertQueryRejected(static fn (): int => DB::table('promotion_usage_releases')->delete());
    }

    /** @return array{0:\App\Modules\Promotions\Application\PromotionResolutionReceipt,1:\App\Modules\Orders\Application\QuoteReceipt} */
    private function resolutionAndQuote(int $userId, int $offeringId, string $ruleCode, int $discountIrr, string $suffix): array
    {
        $resolution = $this->usageResolution($userId, $offeringId, 1_000_000, $suffix);
        $quote = $this->usageQuote($userId, $offeringId, $ruleCode, $discountIrr, $this->clock->value->modify('+30 minutes'), $suffix);

        return [$resolution, $quote];
    }

    private function activeReservationCount(): int
    {
        return (int) DB::table('promotion_usage_reservations as reservation')
            ->leftJoin('promotion_usage_releases as release', 'release.promotion_usage_reservation_id', '=', 'reservation.id')
            ->whereNull('release.id')
            ->count();
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

    private function assertRuntimeMessage(string $message, callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected runtime exception.');
        } catch (RuntimeException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }

    private function assertAuthorizationDenied(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected authorization exception.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
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
