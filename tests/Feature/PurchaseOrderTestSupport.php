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
use App\Modules\Provisioning\Application\ServiceOperationalDatabaseCapability;
use App\Shared\Application\Clock;
use App\Shared\Domain\Money;
use DateTimeImmutable;
use Illuminate\Database\Migrations\Migration;
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
            // DatabaseTruncation empties the immutable operational-capability singleton.
            // Re-enter its convergent migration before each fixture so historical migration
            // tests do not inherit a final DDL graph with an empty readiness anchor.
            /** @var Migration $serviceOperationalMigration */
            $serviceOperationalMigration = require database_path('migrations/2026_08_19_000140_enable_service_operational_authority.php');
            $serviceOperationalMigration->up();

            // Re-entering #140 deliberately restores its predecessor guard surface. On the
            // final schema, immediately reapply the paid successor so ordinary runtime
            // fixtures keep testing the authority contract that production installs.
            if (DB::getSchemaBuilder()->hasTable('service_paid_mutation_authorities')) {
                /** @var Migration $paidMutationAuthorityMigration */
                $paidMutationAuthorityMigration = require database_path('migrations/2026_08_20_000110_enable_paid_service_mutation_authority.php');
                $paidMutationAuthorityMigration->up();
            }

            DB::statement('SET timestamp = '.$this->purchaseOrderClock->value->getTimestamp());

            // Exact-authority upgrade tests intentionally exercise the historical 001165 schema.
            // Install that baseline explicitly because the final migrated database now contains
            // the successor source-aware #150 guards instead of relying on leaked DDL from another test.
            if (str_starts_with(static::class, __NAMESPACE__.'\\InitialProvisioningExactAuthorityUpgrade')) {
                /** @var Migration $legacyProvisioningAuthority */
                $legacyProvisioningAuthority = require database_path('migrations/2026_08_14_001165_activate_provisioning_queue_authority.php');
                $legacyProvisioningAuthority->up();
            }

            $this->beforeApplicationDestroyed(static function (): void {
                DB::statement('SET timestamp = DEFAULT');

                // MariaDB DDL implicitly commits. Historical migration fault-harness tests in this
                // support family may therefore replace the final source-aware provisioning guards
                // outside Laravel's row-level test isolation. Repair only when the live guards are
                // no longer the final composed form so later tests always start from migrated schema.
                $paidMutationAuthorityExists = DB::getSchemaBuilder()->hasTable('service_paid_mutation_authorities');
                /** @var object{base_ready:int|string|null,paid_ready:int|string|null}|null $authorityState */
                $authorityState = DB::selectOne(<<<'SQL'
SELECT
    SUM(CASE WHEN TRIGGER_NAME = 'service_subscriptions_insert_guard'
        AND LOCATE('zero-cost source authority shape is invalid', ACTION_STATEMENT) > 0
        AND LOCATE('clean local lifecycle and no remote binding', ACTION_STATEMENT) > 0 THEN 1 ELSE 0 END)
    + SUM(CASE WHEN TRIGGER_NAME = 'provisioning_operations_insert_guard'
        AND LOCATE('Initial Provisioning Operation zero-cost authority shape is invalid', ACTION_STATEMENT) > 0
        AND LOCATE('service_mutation_queue_v1', ACTION_STATEMENT) > 0 THEN 1 ELSE 0 END) AS base_ready,
    SUM(CASE WHEN TRIGGER_NAME = 'provisioning_operations_insert_guard'
        AND LOCATE('service_paid_mutation_queue_v1', ACTION_STATEMENT) > 0 THEN 1 ELSE 0 END)
    + SUM(CASE WHEN TRIGGER_NAME = 'purchase_refunds_provisioning_invalidation'
        AND LOCATE('service_paid_mutation_authorities', ACTION_STATEMENT) > 0 THEN 1 ELSE 0 END)
    + SUM(CASE WHEN TRIGGER_NAME = 'service_subscriptions_update_guard'
        AND LOCATE('service_paid_mutation_queue_v1', ACTION_STATEMENT) > 0
        AND LOCATE('service_operational_authority_capability', ACTION_STATEMENT) > 0 THEN 1 ELSE 0 END)
    + SUM(CASE WHEN TRIGGER_NAME = 'provisioning_operation_histories_insert_guard'
        AND LOCATE('add_data_days', ACTION_STATEMENT) > 0 THEN 1 ELSE 0 END)
    + SUM(CASE WHEN TRIGGER_NAME = 'provisioning_operations_update_guard'
        AND LOCATE('service_paid_mutation_authorities', ACTION_STATEMENT) > 0
        AND LOCATE('add_data_days', ACTION_STATEMENT) > 0 THEN 1 ELSE 0 END)
    + SUM(CASE WHEN TRIGGER_NAME = 'provisioning_remote_effect_events_insert_guard'
        AND LOCATE('add_data_days', ACTION_STATEMENT) > 0 THEN 1 ELSE 0 END)
    + SUM(CASE WHEN TRIGGER_NAME = 'provisioning_operations_delivery_effect_insert_guard'
        AND LOCATE('add_data_days', ACTION_STATEMENT) > 0 THEN 1 ELSE 0 END) AS paid_ready
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA = DATABASE()
  AND TRIGGER_NAME IN (
      'service_subscriptions_insert_guard', 'service_subscriptions_update_guard',
      'provisioning_operations_insert_guard', 'provisioning_operations_update_guard',
      'provisioning_operation_histories_insert_guard', 'provisioning_remote_effect_events_insert_guard',
      'provisioning_operations_delivery_effect_insert_guard', 'purchase_refunds_provisioning_invalidation'
  )
SQL);

                $operationalCapabilityReady = ! DB::getSchemaBuilder()->hasTable('service_operational_authority_capability')
                    || hash_equals(
                        (new ServiceOperationalDatabaseCapability)->expectedHash(),
                        (string) DB::table('service_operational_authority_capability')->where('id', 1)->value('capability_hash'),
                    );
                $paidChecksReady = true;
                if ($paidMutationAuthorityExists) {
                    /** @var object{aggregate:int|string|null}|null $checkState */
                    $checkState = DB::selectOne(<<<'SQL'
SELECT COUNT(*) AS aggregate
FROM information_schema.CHECK_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = DATABASE()
  AND TABLE_NAME = 'provisioning_operations'
  AND (
      (CONSTRAINT_NAME = 'provisioning_operations_type_chk' AND LOCATE('add_data_days', CHECK_CLAUSE) > 0)
      OR (CONSTRAINT_NAME = 'provisioning_operations_provisioning_exact_text_chk' AND LOCATE('add_data_days', CHECK_CLAUSE) > 0)
  )
SQL);
                    $paidChecksReady = $checkState !== null && (int) $checkState->aggregate === 2;
                }

                if ($authorityState !== null
                    && (int) $authorityState->base_ready === 2
                    && $operationalCapabilityReady
                    && (! $paidMutationAuthorityExists || ((int) $authorityState->paid_ready === 7 && $paidChecksReady))
                ) {
                    return;
                }

                // DatabaseTruncation empties the immutable operational-capability
                // singleton without firing its row triggers. Re-enter #152 first so
                // predecessor restoration selects the operational Service guard.
                /** @var Migration $serviceOperationalMigration */
                $serviceOperationalMigration = require database_path('migrations/2026_08_19_000140_enable_service_operational_authority.php');
                $serviceOperationalMigration->up();

                /** @var Migration|null $paidMutationAuthorityMigration */
                $paidMutationAuthorityMigration = null;
                if ($paidMutationAuthorityExists) {
                    $paidMutationAuthorityMigration = require database_path('migrations/2026_08_20_000110_enable_paid_service_mutation_authority.php');

                    // #120 validates predecessor non-paid DDL before it can reopen source
                    // authority. Restore that predecessor before re-entering #120 rather than
                    // running it while successor paid guards remain installed.
                    $paidMutationAuthorityMigration->down();
                }

                /** @var Migration $nonPaidInvalidationMigration */
                $nonPaidInvalidationMigration = require database_path('migrations/2026_08_19_000115_extend_provisioning_invalidation_to_non_paid_sources.php');
                /** @var Migration $nonPaidAuthorityMigration */
                $nonPaidAuthorityMigration = require database_path('migrations/2026_08_19_000120_activate_non_paid_order_authority.php');
                $nonPaidInvalidationMigration->up();
                $nonPaidAuthorityMigration->up();

                $paidMutationAuthorityMigration?->up();
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
        // Service creation and its initial provisioning operation are one immutable authority
        // envelope under the current schema and therefore share one correlation identity.
        if (str_starts_with($suffix, 'service-')) {
            $suffix = 'provisioning-'.substr($suffix, 8);
        } elseif (str_starts_with($suffix, 'operation-')) {
            $suffix = 'provisioning-'.substr($suffix, 10);
        }

        return hash('sha256', 'purchase-order:'.$suffix);
    }

    protected function purchaseOrderTimestamp(): string
    {
        return $this->purchaseOrderClock->value->format('Y-m-d H:i:s.u');
    }
}
