<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Modules\Orders\Application\QuotePricingInput;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use App\Modules\Panels\Application\TargetCapacityAllocator;
use App\Modules\Payments\Application\Contracts\PaymentEvidence;
use App\Modules\Payments\Application\Contracts\PaymentEvidenceAuthority;
use App\Modules\Payments\Application\Contracts\PaymentTransactionStatus;
use App\Modules\Payments\Application\Contracts\ProviderOperationOutcome;
use App\Modules\Payments\Application\Contracts\VerifiedPaymentEvent;
use App\Modules\Payments\Application\PurchasePaymentIntentService;
use App\Modules\Payments\Application\PurchaseSettlementReceipt;
use App\Modules\Payments\Application\PurchaseSettlementService;
use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
use App\Shared\Application\Clock;
use App\Shared\Domain\Money;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final class PurchaseOrderTestClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

trait PurchaseOrderTestSupport
{
    protected PurchaseOrderTestClock $purchaseOrderClock;

    protected function bootPurchaseOrderClock(): void
    {
        $this->purchaseOrderClock = new PurchaseOrderTestClock(new DateTimeImmutable('2026-08-14T19:00:00+00:00'));
        $this->app->instance(Clock::class, $this->purchaseOrderClock);

        // MariaDB safety triggers intentionally use CURRENT_TIMESTAMP as an authority the
        // application cannot spoof. Tests that replace the application Clock must therefore pin
        // the database session to the same deterministic instant rather than weakening those
        // production guards or comparing two different clocks.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('SET timestamp = '.$this->purchaseOrderClock->value->getTimestamp());
            $this->beforeApplicationDestroyed(static function (): void {
                DB::statement('SET timestamp = DEFAULT');

                // MariaDB DDL implicitly commits. Historical migration fault-harness tests in this
                // support family may therefore replace the final source-aware provisioning guards
                // outside Laravel's row-level test isolation. Repair only when the live guard is
                // no longer the final #150 form so later tests always start from migrated schema.
                $guard = DB::selectOne(<<<'SQL'
SELECT ACTION_STATEMENT AS action_statement
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA = DATABASE()
  AND TRIGGER_NAME = 'service_subscriptions_insert_guard'
LIMIT 1
SQL);
                if ($guard !== null
                    && isset($guard->action_statement)
                    && is_string($guard->action_statement)
                    && str_contains($guard->action_statement, 'zero-cost source authority shape is invalid')
                ) {
                    return;
                }

                /** @var \Illuminate\Database\Migrations\Migration $nonPaidInvalidationMigration */
                $nonPaidInvalidationMigration = require database_path('migrations/2026_08_19_000115_extend_provisioning_invalidation_to_non_paid_sources.php');
                /** @var \Illuminate\Database\Migrations\Migration $nonPaidAuthorityMigration */
                $nonPaidAuthorityMigration = require database_path('migrations/2026_08_19_000120_activate_non_paid_order_authority.php');
                $nonPaidInvalidationMigration->up();
                $nonPaidAuthorityMigration->up();
            });
        }

        // TargetCapacityAllocator is a singleton and may already have captured SystemClock during
        // seed/setup work. Re-resolve it after the test clock override so capacity expiry checks
        // observe the same authoritative test time as route selection and provisioning.
        $this->app->forgetInstance(TargetCapacityAllocator::class);
    }

    protected function createPurchaseOrderSettlement(string $suffix): PurchaseSettlementReceipt
    {
        $methodCode = 'order_gateway_'.$suffix;
        $administratorId = $this->ownerAdministrator();
        $userId = $this->quoteUser('customer');
        $offering = $this->quoteOffering();
        $quote = $this->app->make(QuoteService::class)->create(
            'purchase.order.quote.'.$suffix,
            $userId,
            $offering['id'],
            new QuotePricingInput(
                QuoteOverrideSource::None,
                null,
                null,
                null,
                0,
                $this->purchaseOrderClock->value->modify('+30 minutes'),
            ),
            $this->purchaseOrderCorrelation('quote-'.$suffix),
        );

        $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $eligibility->configureMethod(
            'purchase.order.method.'.$suffix,
            $administratorId,
            $methodCode,
            true,
            false,
            1,
            'Purchase Order authority test method.',
            $this->purchaseOrderCorrelation('method-'.$suffix),
        );
        $eligibility->recordHealth(
            'purchase.order.health.'.$suffix,
            $administratorId,
            $methodCode,
            true,
            $this->purchaseOrderClock->value->modify('+10 minutes'),
            'Healthy Purchase Order authority test observation.',
            $this->purchaseOrderCorrelation('health-'.$suffix),
        );
        $decision = $eligibility->evaluate(
            'purchase.order.eligibility.'.$suffix,
            $userId,
            $quote->quotePublicId,
        );
        $intent = $this->app->make(PurchasePaymentIntentService::class)->create(
            'purchase.order.intent.'.$suffix,
            $userId,
            $quote->quotePublicId,
            $decision->publicId,
            $methodCode,
            $this->purchaseOrderCorrelation('intent-'.$suffix),
        );

        DB::table('payment_intents')->where('public_id', $intent->intentPublicId)->update([
            'state' => 'submitted',
            'updated_at' => $this->purchaseOrderTimestamp(),
        ]);

        return $this->app->make(PurchaseSettlementService::class)->capture(
            $intent->intentPublicId,
            $methodCode,
            new VerifiedPaymentEvent(
                'evt-order-'.$suffix,
                hash('sha256', 'purchase-order-provider-event:'.$suffix),
                new PaymentEvidence(
                    ProviderOperationOutcome::Success,
                    PaymentEvidenceAuthority::Authoritative,
                    PaymentTransactionStatus::Settled,
                    'txn-order-'.$suffix,
                    'evt-order-'.$suffix,
                    Money::irr($intent->amount->amount()),
                    $this->purchaseOrderClock->value,
                    $this->purchaseOrderClock->value,
                    hash('sha256', 'purchase-order-provider-evidence:'.$suffix),
                    ['provider_reference' => 'txn-order-'.$suffix],
                ),
            ),
            $this->purchaseOrderCorrelation('settlement-'.$suffix),
        );
    }

    protected function purchaseOrderCorrelation(string $suffix): string
    {
        return hash('sha256', 'purchase-order:'.$suffix);
    }

    protected function purchaseOrderTimestamp(): string
    {
        return $this->purchaseOrderClock->value->format('Y-m-d H:i:s.u');
    }
}
