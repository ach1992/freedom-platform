<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Payments\Application\Contracts\PaymentEvidence;
use App\Modules\Payments\Application\Contracts\PaymentEvidenceAuthority;
use App\Modules\Payments\Application\Contracts\PaymentTransactionStatus;
use App\Modules\Payments\Application\Contracts\ProviderOperationOutcome;
use App\Modules\Payments\Application\Contracts\VerifiedPaymentEvent;
use App\Modules\Payments\Application\PurchasePaymentIntentService;
use App\Modules\Payments\Application\PurchaseSettlementService;
use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
use App\Modules\Promotions\Application\PromotionUsageContext;
use App\Modules\Promotions\Application\PromotionUsageFinalizationService;
use App\Modules\Promotions\Application\PromotionUsageReservationService;
use App\Shared\Application\Clock;
use App\Shared\Domain\Money;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesPromotionUsageFixtures;
use Tests\TestCase;

final class PromotionFinalizationClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

/** @requirement PRO-001 PAY-003 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 */
final class PromotionUsageFinalizationTest extends TestCase
{
    use CreatesPromotionUsageFixtures;
    use RefreshDatabase;

    private PromotionFinalizationClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->clock = new PromotionFinalizationClock(new DateTimeImmutable('2026-08-13T03:00:00+00:00'));
        $this->app->instance(Clock::class, $this->clock);
    }

    public function test_authoritative_purchase_settlement_redeems_reserved_promotion_exactly_once(): void
    {
        [$reservation, $settlement, $userId] = $this->settledPromotionPurchase('redeem');
        $service = $this->app->make(PromotionUsageFinalizationService::class);

        $created = $service->finalize(
            'promotion.redeem.redeem.000001',
            $reservation->reservationPublicId,
            $settlement->settlementPublicId,
            new PromotionUsageContext($userId),
        );
        self::assertFalse($created->replayed);
        self::assertSame($reservation->discountIrr, $created->discountIrr);

        $replay = $service->finalize(
            'promotion.redeem.redeem.000001',
            $reservation->reservationPublicId,
            $settlement->settlementPublicId,
            new PromotionUsageContext($userId),
        );
        self::assertTrue($replay->replayed);
        self::assertSame($created->redemptionId, $replay->redemptionId);
        self::assertSame(1, DB::table('promotion_usage_redemptions')->count());
        self::assertSame(0, DB::table('promotion_usage_releases')->count());
    }

    public function test_released_reservation_and_cross_quote_settlement_fail_closed(): void
    {
        [$firstReservation, $firstSettlement, $firstUser] = $this->settledPromotionPurchase('first');
        [$secondReservation, , $secondUser] = $this->settledPromotionPurchase('second');
        $this->app->make(PromotionUsageReservationService::class)->release(
            'promotion.release.first.000001',
            $firstReservation->reservationPublicId,
            new PromotionUsageContext($firstUser),
        );
        $service = $this->app->make(PromotionUsageFinalizationService::class);

        $this->expectDomain('Released promotion usage cannot be redeemed.', fn () => $service->finalize(
            'promotion.redeem.first.000001',
            $firstReservation->reservationPublicId,
            $firstSettlement->settlementPublicId,
            new PromotionUsageContext($firstUser),
        ));
        $this->expectDomain('Purchase settlement does not match the promotion reservation Quote and user.', fn () => $service->finalize(
            'promotion.redeem.cross.000001',
            $secondReservation->reservationPublicId,
            $firstSettlement->settlementPublicId,
            new PromotionUsageContext($secondUser),
        ));
        self::assertSame(0, DB::table('promotion_usage_redemptions')->count());
    }

    public function test_redeemed_usage_cannot_release_and_database_redemption_is_immutable(): void
    {
        [$reservation, $settlement, $userId] = $this->settledPromotionPurchase('guards');
        $redemption = $this->app->make(PromotionUsageFinalizationService::class)->finalize(
            'promotion.redeem.guards.000001',
            $reservation->reservationPublicId,
            $settlement->settlementPublicId,
            new PromotionUsageContext($userId),
        );
        $snapshotHash = (string) DB::table('promotion_usage_reservations')
            ->where('id', $reservation->reservationId)
            ->value('configuration_snapshot_hash');

        $this->expectQuery(fn (): mixed => DB::table('promotion_usage_releases')->insert([
            'public_id' => (string) Str::ulid(),
            'release_key' => 'promotion.release.guards.000001',
            'request_payload_hash' => hash('sha256', 'release'),
            'promotion_usage_reservation_id' => $reservation->reservationId,
            'released_by_user_id' => $userId,
            'reservation_configuration_snapshot_hash' => $snapshotHash,
            'created_at' => $this->timestamp(),
        ]));
        $this->expectQuery(fn (): int => DB::table('promotion_usage_redemptions')->where('id', $redemption->redemptionId)->update(['discount_irr' => $redemption->discountIrr + 1]));
        $this->expectQuery(fn (): int => DB::table('promotion_usage_redemptions')->where('id', $redemption->redemptionId)->delete());
    }

    public function test_terminal_unsuccessful_purchase_releases_only_after_quote_expiry(): void
    {
        [$reservation, $intentPublicId, $userId, $quotePublicId] = $this->unsettledPromotionPurchase('terminal');
        DB::table('payment_intents')->where('public_id', $intentPublicId)->update([
            'state' => 'expired',
            'updated_at' => $this->timestamp(),
        ]);
        $service = $this->app->make(PromotionUsageFinalizationService::class);

        $this->expectDomain('Promotion usage remains reserved while the purchase Quote can still be used.', fn () => $service->releaseTerminalPurchase(
            'promotion.release.terminal.000001',
            $reservation->reservationPublicId,
            $intentPublicId,
            new PromotionUsageContext($userId),
        ));
        $expiresAt = (string) DB::table('quotes')->where('public_id', $quotePublicId)->value('expires_at');
        $this->clock->value = new DateTimeImmutable($expiresAt, new DateTimeZone('UTC'));

        $released = $service->releaseTerminalPurchase(
            'promotion.release.terminal.000001',
            $reservation->reservationPublicId,
            $intentPublicId,
            new PromotionUsageContext($userId),
        );
        self::assertFalse($released->replayed);
        self::assertSame(1, DB::table('promotion_usage_releases')->count());
    }

    /** @return array{0:object,1:object,2:int} */
    private function settledPromotionPurchase(string $suffix): array
    {
        [$reservation, $intentPublicId, $userId] = $this->unsettledPromotionPurchase($suffix);
        DB::table('payment_intents')->where('public_id', $intentPublicId)->update([
            'state' => 'submitted',
            'updated_at' => $this->timestamp(),
        ]);
        $settlement = $this->app->make(PurchaseSettlementService::class)->capture(
            $intentPublicId,
            'promotion_gateway',
            $this->event('promotion-event-'.$suffix, 'promotion-tx-'.$suffix, $this->intentAmount($intentPublicId)),
            $this->correlation('settlement-'.$suffix),
        );

        return [$reservation, $settlement, $userId];
    }

    /** @return array{0:object,1:string,2:int,3:string} */
    private function unsettledPromotionPurchase(string $suffix): array
    {
        $userId = $this->usageUser();
        $offering = $this->usageOffering(suffix: 'finalize-'.$suffix);
        $ruleCode = 'promo.finalize.'.$suffix;
        $this->usageRule($offering['id'], $ruleCode, 100_000, 10, 10);
        $resolution = $this->usageResolution($userId, $offering['id'], 1_000_000, 'finalize-'.$suffix);
        $quote = $this->usageQuote($userId, $offering['id'], $ruleCode, 100_000, $this->clock->value->modify('+30 minutes'), 'finalize-'.$suffix);
        $reservation = $this->app->make(PromotionUsageReservationService::class)->reserve(
            'promotion.reserve.'.$suffix.'.000001',
            $resolution->resolutionPublicId,
            $quote->quotePublicId,
            new PromotionUsageContext($userId),
        );

        $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $administratorId = $this->usageAdministrator();
        $eligibility->configureMethod(
            'promotion.method.'.$suffix,
            $administratorId,
            'promotion_gateway',
            true,
            false,
            1,
            'Promotion finalization payment method.',
            $this->correlation('method-'.$suffix),
        );
        $eligibility->recordHealth(
            'promotion.health.'.$suffix,
            $administratorId,
            'promotion_gateway',
            true,
            $this->clock->value->modify('+10 minutes'),
            'Healthy promotion finalization provider.',
            $this->correlation('health-'.$suffix),
        );
        $decision = $eligibility->evaluate('promotion.eligibility.'.$suffix, $userId, $quote->quotePublicId);
        $intent = $this->app->make(PurchasePaymentIntentService::class)->create(
            'promotion.purchase.intent.'.$suffix,
            $userId,
            $quote->quotePublicId,
            $decision->publicId,
            'promotion_gateway',
            $this->correlation('intent-'.$suffix),
        );

        return [$reservation, $intent->intentPublicId, $userId, $quote->quotePublicId];
    }

    private function event(string $eventId, string $transactionId, int $amountIrr): VerifiedPaymentEvent
    {
        return new VerifiedPaymentEvent(
            $eventId,
            hash('sha256', 'event:'.$eventId),
            new PaymentEvidence(
                ProviderOperationOutcome::Success,
                PaymentEvidenceAuthority::Authoritative,
                PaymentTransactionStatus::Settled,
                $transactionId,
                $eventId,
                Money::irr($amountIrr),
                $this->clock->value,
                $this->clock->value,
                hash('sha256', 'evidence:'.$transactionId),
                ['provider_reference' => $transactionId],
            ),
        );
    }

    private function intentAmount(string $intentPublicId): int
    {
        return (int) DB::table('payment_intents')->where('public_id', $intentPublicId)->value('amount_irr');
    }

    private function correlation(string $suffix): string
    {
        return hash('sha256', 'promotion-finalization:'.$suffix);
    }

    private function timestamp(): string
    {
        return $this->clock->value->format('Y-m-d H:i:s.u');
    }

    private function expectDomain(string $message, callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected DomainException.');
        } catch (\DomainException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }

    private function expectQuery(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected QueryException.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }
}
