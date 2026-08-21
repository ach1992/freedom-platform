<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Application\QuotePricingInput;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Application\ServicePackageQuoteContext;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use App\Modules\Provisioning\Application\ServiceAutoRenewalProcessor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

trait ServiceAutoRenewalRuntimeScenariosC
{
    public function test_auto_renew_check_constraint_migration_repairs_partial_application(): void
    {
        DB::statement('ALTER TABLE service_auto_renew_attempts DROP CONSTRAINT sara_price_chk');

        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_08_21_000205_add_service_auto_renew_constraints.php');
        $migration->up();
        $migration->up();

        $constraint = DB::selectOne(
            "SELECT COUNT(*) AS aggregate FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'service_auto_renew_attempts' AND CONSTRAINT_NAME = 'sara_price_chk' AND CONSTRAINT_TYPE = 'CHECK'",
        );

        self::assertNotNull($constraint);
        self::assertSame(1, (int) $constraint->aggregate);
    }

    public function test_database_rejects_foreign_service_quote_binding_before_wallet_capture(): void
    {
        $primary = $this->scenario('db-quote-primary');
        $this->enableAutoRenew($primary, 'db-quote-primary');

        $this->app->make(ServiceAutoRenewalProcessor::class)->processDue(10);

        $attempt = DB::table('service_auto_renew_attempts')
            ->where('service_subscription_id', $primary['service_id'])
            ->first(['id', 'quote_id', 'payment_intent_id']);
        self::assertNotNull($attempt);
        self::assertNotNull($attempt->quote_id);
        self::assertNull($attempt->payment_intent_id);

        $foreign = $this->scenario('db-quote-foreign');
        $foreignQuote = $this->app->make(QuoteService::class)->create(
            'service.auto-renew.foreign.quote.000001',
            $foreign['user_id'],
            $foreign['offering_id'],
            new QuotePricingInput(
                QuoteOverrideSource::None,
                null,
                null,
                null,
                0,
                $this->purchaseOrderClock->value->modify('+15 minutes'),
            ),
            $this->purchaseOrderCorrelation('auto-renew-foreign-quote'),
            null,
            new ServicePackageQuoteContext($foreign['service_public_id'], 'aq-renew-30d'),
        );

        $this->expectException(QueryException::class);
        DB::table('service_auto_renew_attempts')
            ->where('id', (int) $attempt->id)
            ->update([
                'quote_id' => $foreignQuote->quoteId,
                'current_price_irr' => $foreignQuote->finalPriceIrr,
                'updated_at' => $this->purchaseOrderTimestamp(),
            ]);
    }
}
