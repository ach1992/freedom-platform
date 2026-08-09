<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Promotions\Application\PromotionUsageContext;
use App\Modules\Promotions\Application\PromotionUsageReservationService;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\CreatesPromotionUsageFixtures;
use Tests\TestCase;

final class PromotionUsagePolicyClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

/** @requirement PRO-001 BUY-002 DAT-003 DAT-004 SEC-001 QUA-001 */
final class PromotionUsageReservationPolicyTest extends TestCase
{
    use CreatesPromotionUsageFixtures;
    use RefreshDatabase;

    private PromotionUsagePolicyClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->clock = new PromotionUsagePolicyClock(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $this->app->instance(Clock::class, $this->clock);
    }

    public function test_subject_that_becomes_inactive_after_resolution_cannot_reserve(): void
    {
        $userId = $this->usageUser();
        $offering = $this->usageOffering(suffix: 'inactive-subject');
        $this->usageRule($offering['id'], 'promo.usage.inactive', 40_000, 2, 1);
        $resolution = $this->usageResolution($userId, $offering['id'], 1_000_000, 'inactive-subject');
        $quote = $this->usageQuote(
            $userId,
            $offering['id'],
            'promo.usage.inactive',
            40_000,
            $this->clock->value->modify('+30 minutes'),
            'inactive-subject',
        );
        DB::table('users')->where('id', $userId)->update(['account_status' => 'suspended']);

        $this->assertDomainMessage(
            'Promotion usage reservation requires an active customer or agent.',
            fn (): mixed => $this->app->make(PromotionUsageReservationService::class)->reserve(
                'usage.reserve.inactive.000001',
                $resolution->resolutionPublicId,
                $quote->quotePublicId,
                new PromotionUsageContext($userId),
            ),
        );
        self::assertSame(0, DB::table('promotion_usage_reservations')->count());
    }

    public function test_release_key_cannot_be_reused_for_another_reservation(): void
    {
        $userId = $this->usageUser();
        $offering = $this->usageOffering(suffix: 'release-key-conflict');
        $this->usageRule($offering['id'], 'promo.usage.releasekey', 45_000, 3, 3);
        $service = $this->app->make(PromotionUsageReservationService::class);

        [$firstResolution, $firstQuote] = $this->resolutionAndQuote($userId, $offering['id'], 'promo.usage.releasekey', 45_000, 'release-key-first');
        [$secondResolution, $secondQuote] = $this->resolutionAndQuote($userId, $offering['id'], 'promo.usage.releasekey', 45_000, 'release-key-second');
        $first = $service->reserve('usage.reserve.releasekey.000001', $firstResolution->resolutionPublicId, $firstQuote->quotePublicId, new PromotionUsageContext($userId));
        $second = $service->reserve('usage.reserve.releasekey.000002', $secondResolution->resolutionPublicId, $secondQuote->quotePublicId, new PromotionUsageContext($userId));
        $service->release('usage.release.sharedkey.000001', $first->reservationPublicId, new PromotionUsageContext($userId));

        $this->assertRuntimeMessage(
            'Promotion release key conflict.',
            fn (): mixed => $service->release('usage.release.sharedkey.000001', $second->reservationPublicId, new PromotionUsageContext($userId)),
        );
        self::assertSame(1, DB::table('promotion_usage_releases')->count());
    }

    public function test_database_rejects_forged_resolution_identity_duplicate_claim_and_snapshot_hash(): void
    {
        $firstUser = $this->usageUser();
        $secondUser = $this->usageUser();
        $offering = $this->usageOffering(suffix: 'policy-db');
        $this->usageRule($offering['id'], 'promo.usage.policydb', 50_000, 5, 2);
        $service = $this->app->make(PromotionUsageReservationService::class);
        [$firstResolution, $firstQuote] = $this->resolutionAndQuote($firstUser, $offering['id'], 'promo.usage.policydb', 50_000, 'policy-db-first');
        $reservation = $service->reserve('usage.reserve.policydb.000001', $firstResolution->resolutionPublicId, $firstQuote->quotePublicId, new PromotionUsageContext($firstUser));
        [$secondResolution, $secondQuote] = $this->resolutionAndQuote($secondUser, $offering['id'], 'promo.usage.policydb', 50_000, 'policy-db-second');

        $stored = DB::table('promotion_usage_reservations')->where('id', $reservation->reservationId)->first();
        $secondResolutionRow = DB::table('pricing_rule_resolutions')->where('public_id', $secondResolution->resolutionPublicId)->first();
        $secondQuoteRow = DB::table('quotes')->where('public_id', $secondQuote->quotePublicId)->first();
        self::assertNotNull($stored);
        self::assertNotNull($secondResolutionRow);
        self::assertNotNull($secondQuoteRow);

        /** @var array<string, mixed> $forgedIdentity */
        $forgedIdentity = (array) $stored;
        unset($forgedIdentity['id']);
        $forgedIdentity['public_id'] = (string) Str::ulid();
        $forgedIdentity['reservation_key'] = 'usage.reserve.policydb.forged.000001';
        $forgedIdentity['request_payload_hash'] = hash('sha256', 'policy-db-forged-identity');
        $forgedIdentity['pricing_rule_resolution_id'] = $secondResolutionRow->id;
        $forgedIdentity['resolution_public_id_snapshot'] = $firstResolution->resolutionPublicId;
        $forgedIdentity['resolution_configuration_snapshot_hash'] = $secondResolutionRow->configuration_snapshot_hash;
        $forgedIdentity['quote_id'] = $secondQuoteRow->id;
        $forgedIdentity['quote_public_id_snapshot'] = $secondQuoteRow->public_id;
        $forgedIdentity['quote_configuration_snapshot_hash'] = $secondQuoteRow->configuration_snapshot_hash;
        $forgedIdentity['user_id'] = $secondUser;
        $this->assertQueryRejected(static fn (): bool => DB::table('promotion_usage_reservations')->insert($forgedIdentity));

        /** @var array<string, mixed> $duplicate */
        $duplicate = (array) $stored;
        unset($duplicate['id']);
        $duplicate['public_id'] = (string) Str::ulid();
        $duplicate['reservation_key'] = 'usage.reserve.policydb.duplicate.000001';
        $duplicate['request_payload_hash'] = hash('sha256', 'policy-db-duplicate');
        $this->assertQueryRejected(static fn (): bool => DB::table('promotion_usage_reservations')->insert($duplicate));

        /** @var array<string, mixed> $invalidHash */
        $invalidHash = $forgedIdentity;
        $invalidHash['public_id'] = (string) Str::ulid();
        $invalidHash['reservation_key'] = 'usage.reserve.policydb.hash.000001';
        $invalidHash['resolution_public_id_snapshot'] = $secondResolutionRow->public_id;
        $invalidHash['configuration_snapshot_hash'] = str_repeat('0', 64);
        $this->assertQueryRejected(static fn (): bool => DB::table('promotion_usage_reservations')->insert($invalidHash));

        self::assertSame(1, DB::table('promotion_usage_reservations')->count());
    }

    /** @return array{0:\App\Modules\Promotions\Application\PromotionResolutionReceipt,1:\App\Modules\Orders\Application\QuoteReceipt} */
    private function resolutionAndQuote(int $userId, int $offeringId, string $ruleCode, int $discountIrr, string $suffix): array
    {
        $resolution = $this->usageResolution($userId, $offeringId, 1_000_000, $suffix);
        $quote = $this->usageQuote(
            $userId,
            $offeringId,
            $ruleCode,
            $discountIrr,
            $this->clock->value->modify('+30 minutes'),
            $suffix,
        );

        return [$resolution, $quote];
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
