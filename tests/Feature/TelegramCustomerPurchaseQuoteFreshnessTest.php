<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Application\CatalogChangeContext;
use App\Modules\Catalog\Application\PlanOfferingService;
use App\Modules\Catalog\Domain\ProductVisibility;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseCatalog;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseQuote;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseCatalogPage;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseOffering;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\CreatesBenefitCodeFixtures;
use Tests\TestCase;

final class PurchaseQuoteFreshnessCatalog implements TelegramCustomerPurchaseCatalog
{
    public function __construct(
        private readonly string $selectionToken,
        private readonly string $offeringCode,
        private readonly int $basePriceIrr,
        public int $durationDays,
    ) {}

    public function pageForSelf(int $actorUserId, int $subjectUserId, int $page, int $pageSize): TelegramCustomerPurchaseCatalogPage
    {
        throw new RuntimeException('Purchase Quote freshness verification does not request a catalog page.');
    }

    public function offeringForSelf(int $actorUserId, int $subjectUserId, string $selectionToken): TelegramCustomerPurchaseOffering
    {
        if ($actorUserId !== $subjectUserId || ! hash_equals($this->selectionToken, $selectionToken)) {
            throw new RuntimeException('Unexpected purchase Quote freshness catalog request.');
        }

        return new TelegramCustomerPurchaseOffering(
            $this->selectionToken,
            $this->offeringCode,
            'دسته خرید',
            'Purchase category',
            'پلن خرید',
            'Purchase plan',
            null,
            null,
            'استاندارد',
            'Standard',
            $this->basePriceIrr,
            $this->durationDays,
            null,
            2,
        );
    }
}

/** @requirement BUY-001 BUY-002 BUY-003 CAT-002 CAT-003 DAT-002 DAT-003 SEC-002 QUA-001 */
final class TelegramCustomerPurchaseQuoteFreshnessTest extends TestCase
{
    use CreatesBenefitCodeFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_preview_rejects_same_code_same_price_quote_after_offering_configuration_version_drift(): void
    {
        $offering = $this->activeBenefitOffering('telegram-quote-freshness');
        $currentVersion = (int) DB::table('plan_offerings')->where('id', $offering['id'])->value('version');
        $this->app->make(PlanOfferingService::class)->setVisibility(
            $offering['id'],
            $currentVersion,
            ProductVisibility::Visible,
            new CatalogChangeContext(
                'telegram-quote-freshness-visible',
                'telegram-quote-freshness-visible-correlation',
                'telegram_quote_freshness_test',
                'Expose the verified Offering for purchase Quote freshness verification.',
                $this->benefitOwner(),
            ),
        );

        /** @var object{code:string,base_price_irr:int|string,duration_days:int|string}|null $configured */
        $configured = DB::table('plan_offerings')
            ->where('id', $offering['id'])
            ->first(['code', 'base_price_irr', 'duration_days']);
        self::assertNotNull($configured);
        $selectionToken = str_repeat('c', 40);
        $catalog = new PurchaseQuoteFreshnessCatalog(
            $selectionToken,
            $configured->code,
            (int) $configured->base_price_irr,
            (int) $configured->duration_days,
        );
        $this->app->instance(TelegramCustomerPurchaseCatalog::class, $catalog);

        $userId = $this->benefitUser('customer');
        $quotes = $this->app->make(TelegramCustomerPurchaseQuote::class);
        $callbackPublicId = (string) Str::ulid();
        $acceptedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $preview = $quotes->quoteForSelf(
            $userId,
            $userId,
            $selectionToken,
            $acceptedAt,
            'telegram-purchase-quote:'.$callbackPublicId,
            'tg-purchase-quote:'.$callbackPublicId,
        );
        $storedQuote = $this->app->make(QuoteService::class)->current($preview->quotePublicId);
        self::assertSame($configured->code, $storedQuote->offeringCode);
        self::assertSame((int) $configured->base_price_irr, $storedQuote->basePriceIrr);

        /** @var object{version:int|string,duration_days:int|string,base_price_irr:int|string,state:string,visibility:string}|null $row */
        $row = DB::table('plan_offerings')
            ->where('id', $offering['id'])
            ->first(['version', 'duration_days', 'base_price_irr', 'state', 'visibility']);
        self::assertNotNull($row);
        $fromHash = DB::table('plan_offering_histories')
            ->where('plan_offering_id', $offering['id'])
            ->where('version', (int) $row->version)
            ->value('to_configuration_hash');
        self::assertIsString($fromHash);
        $nextVersion = (int) $row->version + 1;
        $nextDuration = (int) $row->duration_days + 15;
        $nextHash = hash('sha256', 'telegram-purchase-quote-config-drift:'.$offering['id'].':'.$nextVersion);
        DB::table('plan_offerings')->where('id', $offering['id'])->update([
            'duration_days' => $nextDuration,
            'version' => $nextVersion,
            'updated_at' => now('UTC'),
        ]);
        DB::table('plan_offering_histories')->insert([
            'plan_offering_id' => $offering['id'],
            'version' => $nextVersion,
            'action' => 'catalog.plan_offering.update',
            'from_state' => (string) $row->state,
            'to_state' => (string) $row->state,
            'from_visibility' => (string) $row->visibility,
            'to_visibility' => (string) $row->visibility,
            'from_configuration_hash' => $fromHash,
            'to_configuration_hash' => $nextHash,
            'before_safe_data' => json_encode(['duration_days' => (int) $row->duration_days], JSON_THROW_ON_ERROR),
            'after_safe_data' => json_encode(['duration_days' => $nextDuration], JSON_THROW_ON_ERROR),
            'actor_administrator_id' => $this->benefitOwner(),
            'reason_code' => 'telegram_quote_freshness_test',
            'reason' => 'Simulate same-code same-price authoritative Offering configuration drift.',
            'correlation_id' => 'telegram-quote-freshness-drift',
            'created_at' => now('UTC'),
        ]);
        $catalog->durationDays = $nextDuration;
        $current = $catalog->offeringForSelf($userId, $userId, $selectionToken);
        self::assertSame($selectionToken, $current->selectionToken);
        self::assertSame($storedQuote->offeringCode, $current->offeringCode);
        self::assertSame($storedQuote->basePriceIrr, $current->basePriceIrr);
        self::assertSame($nextDuration, $current->durationDays);
        self::assertNotSame($storedQuote->offeringVersion, $nextVersion);
        self::assertNotSame($storedQuote->offeringConfigurationHash, $nextHash);

        try {
            $quotes->previewForSelf(
                $userId,
                $userId,
                $selectionToken,
                $preview->quotePublicId,
                $preview->configurationSnapshotHash,
            );
            self::fail('Expected the stale purchase Quote preview to fail after exact Offering configuration/version drift.');
        } catch (AuthorizationException $exception) {
            self::assertSame('Telegram purchase Quote preview is unavailable.', $exception->getMessage());
        }

        self::assertSame(1, DB::table('quotes')->count());
        self::assertSame(0, DB::table('orders')->count());
        self::assertSame(0, DB::table('payment_intents')->count());
    }
}
