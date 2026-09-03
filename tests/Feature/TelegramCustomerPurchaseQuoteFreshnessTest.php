<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Application\CatalogChangeContext;
use App\Modules\Catalog\Application\PlanOfferingService;
use App\Modules\Catalog\Domain\ProductVisibility;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseCatalog;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseQuote;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesBenefitCodeFixtures;
use Tests\TestCase;

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

        $userId = $this->benefitUser('customer');
        $tierId = DB::table('customer_tiers')->where('code', 'normal')->value('id');
        self::assertIsNumeric($tierId);
        $now = now('UTC');
        DB::table('customer_profiles')->insert([
            'user_id' => $userId,
            'current_tier_id' => (int) $tierId,
            'tier_locked' => false,
            'tier_lock_reason_code' => null,
            'phone_verification_status' => 'verified',
            'identity_verification_status' => 'verified',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $tagId = DB::table('plan_offering_tags')
            ->where('plan_offering_id', $offering['id'])
            ->value('customer_tag_id');
        self::assertIsNumeric($tagId);
        DB::table('customer_tag_assignments')->insert([
            'user_id' => $userId,
            'tag_id' => (int) $tagId,
            'assigned_by_administrator_id' => $this->benefitOwner(),
            'assigned_at' => $now,
            'removed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $offeringCode = DB::table('plan_offerings')->where('id', $offering['id'])->value('code');
        self::assertIsString($offeringCode);
        $selectionToken = substr(hash('sha256', "telegram-purchase-offering-v1:{$userId}:{$offeringCode}"), 0, 40);
        $catalog = $this->app->make(TelegramCustomerPurchaseCatalog::class);
        $selected = $catalog->offeringForSelf($userId, $userId, $selectionToken);
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
        self::assertSame($selected->offeringCode, $storedQuote->offeringCode);
        self::assertSame($selected->basePriceIrr, $storedQuote->basePriceIrr);

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
        $nextHash = hash('sha256', 'telegram-purchase-quote-config-drift:'.$offering['id'].':'.$nextVersion);
        DB::table('plan_offerings')->where('id', $offering['id'])->update([
            'duration_days' => (int) $row->duration_days + 15,
            'version' => $nextVersion,
            'updated_at' => $now,
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
            'after_safe_data' => json_encode(['duration_days' => (int) $row->duration_days + 15], JSON_THROW_ON_ERROR),
            'actor_administrator_id' => $this->benefitOwner(),
            'reason_code' => 'telegram_quote_freshness_test',
            'reason' => 'Simulate same-code same-price authoritative Offering configuration drift.',
            'correlation_id' => 'telegram-quote-freshness-drift',
            'created_at' => $now,
        ]);

        $current = $catalog->offeringForSelf($userId, $userId, $selectionToken);
        self::assertSame($selectionToken, $current->selectionToken);
        self::assertSame($selected->offeringCode, $current->offeringCode);
        self::assertSame($selected->basePriceIrr, $current->basePriceIrr);
        self::assertNotSame($selected->durationDays, $current->durationDays);
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