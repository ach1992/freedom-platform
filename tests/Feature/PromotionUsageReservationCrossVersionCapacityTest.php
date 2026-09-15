<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\AccessControl\Application\AccessChangeContext;
use App\Modules\Orders\Application\QuoteReceipt;
use App\Modules\Promotions\Application\PromotionResolutionReceipt;
use App\Modules\Promotions\Application\PromotionRuleService;
use App\Modules\Promotions\Application\PromotionRuleVersionReceipt;
use App\Modules\Promotions\Application\PromotionUsageContext;
use App\Modules\Promotions\Application\PromotionUsageReservationService;
use App\Modules\Promotions\Domain\PromotionAction;
use App\Modules\Promotions\Domain\PromotionAudience;
use App\Modules\Promotions\Domain\PromotionDiscountType;
use App\Modules\Promotions\Domain\PromotionRuleDefinition;
use App\Modules\Promotions\Domain\PromotionRuleState;
use App\Modules\Promotions\Domain\PromotionUsageReservationState;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesPromotionUsageFixtures;
use Tests\TestCase;

/** @requirement PRO-001 BUY-002 DAT-003 DAT-004 SEC-001 QUA-001 */
final class PromotionUsageReservationCrossVersionCapacityTest extends TestCase
{
    use CreatesPromotionUsageFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_version_one_active_reservation_consumes_global_capacity_after_revision(): void
    {
        $firstUser = $this->usageUser();
        $secondUser = $this->usageUser();
        $offering = $this->usageOffering(suffix: 'cross-version-global');
        $ruleCode = 'promo.usage.crossglobal';
        $this->usageRule($offering['id'], $ruleCode, 100_000, 1, null);
        [$firstResolution, $firstQuote] = $this->pair($firstUser, $offering['id'], $ruleCode, 100_000, 'cross-global-v1');
        self::assertSame(1, $firstResolution->ruleVersion);
        $this->service()->reserve(
            'usage.reserve.crossglobal.v1.000001',
            $firstResolution->resolutionPublicId,
            $firstQuote->quotePublicId,
            new PromotionUsageContext($firstUser),
        );

        $revision = $this->reviseRule($offering['id'], $ruleCode, 'cross-global-v2', 100_000, 1, null);
        self::assertSame(2, $revision->version);
        [$secondResolution, $secondQuote] = $this->pair($secondUser, $offering['id'], $ruleCode, 100_000, 'cross-global-v2');
        self::assertSame(2, $secondResolution->ruleVersion);

        $this->expectDomain('Promotion global usage capacity is exhausted.', fn () => $this->service()->reserve(
            'usage.reserve.crossglobal.v2.000001',
            $secondResolution->resolutionPublicId,
            $secondQuote->quotePublicId,
            new PromotionUsageContext($secondUser),
        ));
        self::assertSame(1, $this->activeReservationsForRule($revision->ruleId));
    }

    public function test_version_one_active_reservation_consumes_per_user_capacity_after_revision(): void
    {
        $userId = $this->usageUser();
        $offering = $this->usageOffering(suffix: 'cross-version-user');
        $ruleCode = 'promo.usage.crossuser';
        $this->usageRule($offering['id'], $ruleCode, 90_000, 10, 1);
        [$firstResolution, $firstQuote] = $this->pair($userId, $offering['id'], $ruleCode, 90_000, 'cross-user-v1');
        $this->service()->reserve(
            'usage.reserve.crossuser.v1.000001',
            $firstResolution->resolutionPublicId,
            $firstQuote->quotePublicId,
            new PromotionUsageContext($userId),
        );

        $revision = $this->reviseRule($offering['id'], $ruleCode, 'cross-user-v2', 90_000, 10, 1);
        [$secondResolution, $secondQuote] = $this->pair($userId, $offering['id'], $ruleCode, 90_000, 'cross-user-v2');
        self::assertSame(2, $secondResolution->ruleVersion);

        $this->expectDomain('Promotion per-user usage capacity is exhausted.', fn () => $this->service()->reserve(
            'usage.reserve.crossuser.v2.000001',
            $secondResolution->resolutionPublicId,
            $secondQuote->quotePublicId,
            new PromotionUsageContext($userId),
        ));
        self::assertSame(1, $this->activeReservationsForRule($revision->ruleId));
    }

    public function test_releasing_older_version_reservation_returns_capacity_to_later_version(): void
    {
        $firstUser = $this->usageUser();
        $secondUser = $this->usageUser();
        $offering = $this->usageOffering(suffix: 'cross-version-release');
        $ruleCode = 'promo.usage.crossrelease';
        $this->usageRule($offering['id'], $ruleCode, 80_000, 1, null);
        [$firstResolution, $firstQuote] = $this->pair($firstUser, $offering['id'], $ruleCode, 80_000, 'cross-release-v1');
        $service = $this->service();
        $olderReservation = $service->reserve(
            'usage.reserve.crossrelease.v1.000001',
            $firstResolution->resolutionPublicId,
            $firstQuote->quotePublicId,
            new PromotionUsageContext($firstUser),
        );
        self::assertSame(1, $olderReservation->ruleVersion);

        $revision = $this->reviseRule($offering['id'], $ruleCode, 'cross-release-v2', 80_000, 1, null);
        [$secondResolution, $secondQuote] = $this->pair($secondUser, $offering['id'], $ruleCode, 80_000, 'cross-release-v2');
        $this->expectDomain('Promotion global usage capacity is exhausted.', fn () => $service->reserve(
            'usage.reserve.crossrelease.v2.000001',
            $secondResolution->resolutionPublicId,
            $secondQuote->quotePublicId,
            new PromotionUsageContext($secondUser),
        ));

        $service->release(
            'usage.release.crossrelease.v1.000001',
            $olderReservation->reservationPublicId,
            new PromotionUsageContext($firstUser),
        );
        $laterReservation = $service->reserve(
            'usage.reserve.crossrelease.v2.000001',
            $secondResolution->resolutionPublicId,
            $secondQuote->quotePublicId,
            new PromotionUsageContext($secondUser),
        );

        self::assertSame(2, $laterReservation->ruleVersion);
        self::assertSame(PromotionUsageReservationState::Active, $laterReservation->state);
        self::assertSame(1, $this->activeReservationsForRule($revision->ruleId));
        self::assertSame(1, DB::table('promotion_usage_releases')->count());
    }

    /** @return array{0:PromotionResolutionReceipt,1:QuoteReceipt} */
    private function pair(int $userId, int $offeringId, string $ruleCode, int $discountIrr, string $suffix): array
    {
        return [
            $this->usageResolution($userId, $offeringId, 1_000_000, $suffix),
            $this->usageQuote(
                $userId,
                $offeringId,
                $ruleCode,
                $discountIrr,
                new DateTimeImmutable('+30 minutes', new DateTimeZone('UTC')),
                $suffix,
            ),
        ];
    }

    private function reviseRule(
        int $offeringId,
        string $ruleCode,
        string $suffix,
        int $discountIrr,
        ?int $totalUseLimit,
        ?int $perUserUseLimit,
    ): PromotionRuleVersionReceipt {
        return $this->app->make(PromotionRuleService::class)->revise(
            'usage.rule.revise.'.substr(hash('sha256', $ruleCode.':'.$suffix), 0, 24),
            $ruleCode,
            new PromotionRuleDefinition(
                PromotionRuleState::Active,
                10,
                PromotionDiscountType::Fixed,
                $discountIrr,
                null,
                0,
                null,
                null,
                null,
                $totalUseLimit,
                $perUserUseLimit,
                false,
                PromotionAudience::Both,
                null,
                null,
                $offeringId,
                null,
                null,
                PromotionAction::Purchase,
            ),
            new AccessChangeContext(
                hash('sha256', 'usage-rule-revise-request:'.$ruleCode.':'.$suffix),
                substr(hash('sha256', 'usage-rule-revise-correlation:'.$ruleCode.':'.$suffix), 0, 64),
                'promotion_usage_test',
                'Revise stable promotion rule for cross-version capacity verification.',
                $this->usageAdministrator(),
            ),
        );
    }

    private function service(): PromotionUsageReservationService
    {
        return $this->app->make(PromotionUsageReservationService::class);
    }

    private function activeReservationsForRule(int $ruleId): int
    {
        return (int) DB::table('promotion_usage_reservations as reservation')
            ->leftJoin('promotion_usage_releases as release', 'release.promotion_usage_reservation_id', '=', 'reservation.id')
            ->where('reservation.pricing_rule_id', $ruleId)
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
}
