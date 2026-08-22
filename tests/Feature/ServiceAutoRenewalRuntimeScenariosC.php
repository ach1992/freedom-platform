<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Application\QuotePricingInput;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Application\ServicePackageQuoteContext;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use App\Modules\Payments\Application\PurchaseWalletPaymentService;
use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
use App\Modules\Provisioning\Application\ServiceAutoRenewalProcessor;
use App\Modules\Provisioning\Application\ServiceAutoRenewConfigurationService;
use Database\Seeders\WalletFinancialFoundationSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

    public function test_database_rejects_forged_commercial_generation_without_safe_reset(): void
    {
        $scenario = $this->scenario('db-commercial-generation');
        $this->enableWalletMethod('db-commercial-generation');
        $this->seed(WalletFinancialFoundationSeeder::class);
        $this->fundWallet($scenario['user_id'], 1, 'db-commercial-generation');
        $this->enableAutoRenew($scenario, 'db-commercial-generation');

        $this->app->make(ServiceAutoRenewalProcessor::class)->processDue(10);
        $attempt = DB::table('service_auto_renew_attempts')->first(['id', 'state', 'commercial_generation']);
        self::assertNotNull($attempt);
        self::assertSame('insufficient_wallet', $attempt->state);
        self::assertSame(0, (int) $attempt->commercial_generation);

        $this->expectException(QueryException::class);
        DB::table('service_auto_renew_attempts')
            ->where('id', (int) $attempt->id)
            ->update([
                'commercial_generation' => 1,
                'updated_at' => $this->purchaseOrderTimestamp(),
            ]);
    }

    public function test_reserved_wallet_hold_is_released_when_auto_renew_is_disabled_before_capture(): void
    {
        $scenario = $this->scenario('reserved-disabled');
        $this->enableWalletMethod('reserved-disabled');
        $this->seed(WalletFinancialFoundationSeeder::class);
        $walletAccountId = $this->fundWallet($scenario['user_id'], 600_000, 'reserved-disabled');
        $configuration = $this->enableAutoRenew($scenario, 'reserved-disabled');

        $quote = $this->app->make(QuoteService::class)->create(
            'service.auto-renew.reserved-disabled.quote.000001',
            $scenario['user_id'],
            $scenario['offering_id'],
            new QuotePricingInput(
                QuoteOverrideSource::None,
                null,
                null,
                null,
                0,
                $this->purchaseOrderClock->value->modify('+15 minutes'),
            ),
            $this->purchaseOrderCorrelation('auto-renew-reserved-disabled-quote'),
            null,
            new ServicePackageQuoteContext($scenario['service_public_id'], 'aq-renew-30d'),
        );
        $eligibility = $this->app->make(PaymentMethodEligibilityService::class)->evaluate(
            'service.auto-renew.reserved-disabled.eligibility.000001',
            $scenario['user_id'],
            $quote->quotePublicId,
        );
        $intent = $this->app->make(PurchaseWalletPaymentService::class)->reserve(
            'service.auto-renew.reserved-disabled.intent.000001',
            $scenario['user_id'],
            $walletAccountId,
            $quote->quotePublicId,
            $eligibility->publicId,
            $this->purchaseOrderCorrelation('auto-renew-reserved-disabled-reserve'),
        );

        $configRow = DB::table('service_auto_renew_configurations')
            ->where('id', $configuration->configurationId)
            ->first();
        self::assertNotNull($configRow);
        $cycleKey = hash('sha256', implode('|', [
            (string) $configuration->configurationId,
            (string) $scenario['service_id'],
            (string) $configuration->configurationVersion,
            (string) $configRow->observed_remote_identity_generation,
            (string) $configRow->observed_expires_at,
        ]));
        $attemptId = (int) DB::table('service_auto_renew_attempts')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'cycle_key' => $cycleKey,
            'auto_renew_configuration_id' => $configuration->configurationId,
            'service_subscription_id' => $scenario['service_id'],
            'configuration_version' => $configuration->configurationVersion,
            'remote_identity_generation' => (int) $configRow->observed_remote_identity_generation,
            'observed_expires_at' => (string) $configRow->observed_expires_at,
            'observed_expiry_evidence_hash' => (string) $configRow->observed_expiry_evidence_hash,
            'observed_expiry_source' => (string) $configRow->observed_expiry_source,
            'state' => 'pending',
            'reason_code' => null,
            'baseline_price_irr' => $configuration->acceptedPriceIrr,
            'current_price_irr' => null,
            'quote_id' => null,
            'payment_eligibility_decision_id' => null,
            'payment_intent_id' => null,
            'purchase_settlement_id' => null,
            'provisioning_operation_id' => null,
            'correlation_id' => $this->purchaseOrderCorrelation('auto-renew-reserved-disabled-attempt'),
            'completed_at' => null,
            'created_at' => $this->purchaseOrderTimestamp(),
            'updated_at' => $this->purchaseOrderTimestamp(),
        ]);
        $intentId = (int) DB::table('payment_intents')
            ->where('public_id', $intent->intentPublicId)
            ->value('id');
        DB::table('service_auto_renew_attempts')->where('id', $attemptId)->update([
            'quote_id' => $quote->quoteId,
            'payment_eligibility_decision_id' => $eligibility->decisionId,
            'payment_intent_id' => $intentId,
            'current_price_irr' => $quote->finalPriceIrr,
            'reason_code' => 'wallet_reserved',
            'updated_at' => $this->purchaseOrderTimestamp(),
        ]);

        $this->app->make(ServiceAutoRenewConfigurationService::class)->configure(
            'service.auto-renew.config.reserved-disabled.000002',
            $scenario['user_id'],
            $scenario['service_public_id'],
            'aq-renew-30d',
            false,
            $this->purchaseOrderCorrelation('auto-renew-reserved-disabled-disable'),
        );

        $this->app->make(ServiceAutoRenewalProcessor::class)->processDue(10);

        $attempt = DB::table('service_auto_renew_attempts')
            ->where('id', $attemptId)
            ->first(['state', 'reason_code']);
        self::assertNotNull($attempt);
        self::assertSame('failed', $attempt->state);
        self::assertSame('configuration_changed_after_reservation', $attempt->reason_code);
        self::assertSame(0, DB::table('purchase_settlements')->where('provider_code', 'wallet')->count());
        self::assertSame(
            'released',
            DB::table('purchase_wallet_reservations as reservation')
                ->join('wallet_holds as hold', 'hold.id', '=', 'reservation.wallet_hold_id')
                ->where('reservation.payment_intent_id', $intentId)
                ->value('hold.status'),
        );
    }

    public function test_expired_reserved_quote_is_requoted_before_capture_without_double_debit(): void
    {
        $scenario = $this->scenario('expired-reserved-quote');
        $this->enableWalletMethod('expired-reserved-quote');
        $this->seed(WalletFinancialFoundationSeeder::class);
        $walletAccountId = $this->fundWallet($scenario['user_id'], 600_000, 'expired-reserved-quote');
        $configuration = $this->enableAutoRenew($scenario, 'expired-reserved-quote');

        $quote = $this->app->make(QuoteService::class)->create(
            'service.auto-renew.expired-reserved.quote.000001',
            $scenario['user_id'],
            $scenario['offering_id'],
            new QuotePricingInput(
                QuoteOverrideSource::None,
                null,
                null,
                null,
                0,
                $this->purchaseOrderClock->value->modify('+1 minute'),
            ),
            $this->purchaseOrderCorrelation('auto-renew-expired-reserved-quote'),
            null,
            new ServicePackageQuoteContext($scenario['service_public_id'], 'aq-renew-30d'),
        );
        $eligibility = $this->app->make(PaymentMethodEligibilityService::class)->evaluate(
            'service.auto-renew.expired-reserved.eligibility.000001',
            $scenario['user_id'],
            $quote->quotePublicId,
        );
        $intent = $this->app->make(PurchaseWalletPaymentService::class)->reserve(
            'service.auto-renew.expired-reserved.intent.000001',
            $scenario['user_id'],
            $walletAccountId,
            $quote->quotePublicId,
            $eligibility->publicId,
            $this->purchaseOrderCorrelation('auto-renew-expired-reserved-reserve'),
        );

        $configRow = DB::table('service_auto_renew_configurations')
            ->where('id', $configuration->configurationId)
            ->first();
        self::assertNotNull($configRow);
        $cycleKey = hash('sha256', implode('|', [
            (string) $configuration->configurationId,
            (string) $scenario['service_id'],
            (string) $configuration->configurationVersion,
            (string) $configRow->observed_remote_identity_generation,
            (string) $configRow->observed_expires_at,
        ]));
        $attemptId = (int) DB::table('service_auto_renew_attempts')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'cycle_key' => $cycleKey,
            'auto_renew_configuration_id' => $configuration->configurationId,
            'service_subscription_id' => $scenario['service_id'],
            'configuration_version' => $configuration->configurationVersion,
            'remote_identity_generation' => (int) $configRow->observed_remote_identity_generation,
            'observed_expires_at' => (string) $configRow->observed_expires_at,
            'observed_expiry_evidence_hash' => (string) $configRow->observed_expiry_evidence_hash,
            'observed_expiry_source' => (string) $configRow->observed_expiry_source,
            'state' => 'pending',
            'reason_code' => null,
            'baseline_price_irr' => $configuration->acceptedPriceIrr,
            'current_price_irr' => null,
            'quote_id' => null,
            'payment_eligibility_decision_id' => null,
            'payment_intent_id' => null,
            'purchase_settlement_id' => null,
            'provisioning_operation_id' => null,
            'correlation_id' => $this->purchaseOrderCorrelation('auto-renew-expired-reserved-attempt'),
            'completed_at' => null,
            'created_at' => $this->purchaseOrderTimestamp(),
            'updated_at' => $this->purchaseOrderTimestamp(),
        ]);
        $oldIntentId = (int) DB::table('payment_intents')
            ->where('public_id', $intent->intentPublicId)
            ->value('id');
        DB::table('service_auto_renew_attempts')->where('id', $attemptId)->update([
            'quote_id' => $quote->quoteId,
            'payment_eligibility_decision_id' => $eligibility->decisionId,
            'payment_intent_id' => $oldIntentId,
            'current_price_irr' => $quote->finalPriceIrr,
            'reason_code' => 'wallet_reserved',
            'updated_at' => $this->purchaseOrderTimestamp(),
        ]);

        $ledgerCountBeforeRecovery = DB::table('ledger_transactions')->count();
        $this->purchaseOrderClock->value = $this->purchaseOrderClock->value->modify('+2 minutes');
        DB::statement('SET timestamp = '.$this->purchaseOrderClock->value->getTimestamp());

        $result = $this->app->make(ServiceAutoRenewalProcessor::class)->processDue(10);

        self::assertGreaterThanOrEqual(1, $result->queued);
        $attempt = DB::table('service_auto_renew_attempts')
            ->where('id', $attemptId)
            ->first(['state', 'quote_id', 'payment_intent_id']);
        self::assertNotNull($attempt);
        self::assertSame('mutation_queued', $attempt->state);
        self::assertNotSame($quote->quoteId, (int) $attempt->quote_id);
        self::assertNotSame($oldIntentId, (int) $attempt->payment_intent_id);
        self::assertSame('expired', DB::table('payment_intents')->where('id', $oldIntentId)->value('state'));
        self::assertSame(
            'released',
            DB::table('purchase_wallet_reservations as reservation')
                ->join('wallet_holds as hold', 'hold.id', '=', 'reservation.wallet_hold_id')
                ->where('reservation.payment_intent_id', $oldIntentId)
                ->value('hold.status'),
        );
        self::assertSame($ledgerCountBeforeRecovery + 1, DB::table('ledger_transactions')->count());
        self::assertSame(1, DB::table('purchase_settlements')->where('provider_code', 'wallet')->count());
        self::assertSame(1, DB::table('service_paid_mutation_authorities')->count());
        $resetEvent = DB::table('service_auto_renew_attempt_events')
            ->where('auto_renew_attempt_id', $attemptId)
            ->where('reason_code', 'commercial_authority_reset_for_requote')
            ->first(['payment_intent_id']);
        self::assertNotNull($resetEvent);
        self::assertSame($oldIntentId, (int) $resetEvent->payment_intent_id);
    }

    public function test_reserved_wallet_hold_is_requoted_and_blocked_when_price_changes_before_capture(): void
    {
        $scenario = $this->scenario('reserved-price-change');
        $this->enableWalletMethod('reserved-price-change');
        $this->seed(WalletFinancialFoundationSeeder::class);
        $walletAccountId = $this->fundWallet($scenario['user_id'], 700_000, 'reserved-price-change');
        $pricingAuthority = $this->enableScenarioAgentRenewPricing($scenario, 600_000, 'reserved-price-change');
        $configuration = $this->enableAutoRenew($scenario, 'reserved-price-change');

        $quote = $this->app->make(QuoteService::class)->create(
            'service.auto-renew.reserved-price-change.quote.000001',
            $scenario['user_id'],
            $scenario['offering_id'],
            new QuotePricingInput(
                QuoteOverrideSource::None,
                null,
                null,
                null,
                0,
                $this->purchaseOrderClock->value->modify('+15 minutes'),
            ),
            $this->purchaseOrderCorrelation('auto-renew-reserved-price-change-quote'),
            new \App\Modules\Orders\Application\QuoteAgentPricingContext(
                $scenario['user_id'],
                \App\Modules\Agents\Domain\AgentPricingAction::Renew,
            ),
            new ServicePackageQuoteContext($scenario['service_public_id'], 'aq-renew-30d'),
        );
        $eligibility = $this->app->make(PaymentMethodEligibilityService::class)->evaluate(
            'service.auto-renew.reserved-price-change.eligibility.000001',
            $scenario['user_id'],
            $quote->quotePublicId,
        );
        $intent = $this->app->make(PurchaseWalletPaymentService::class)->reserve(
            'service.auto-renew.reserved-price-change.intent.000001',
            $scenario['user_id'],
            $walletAccountId,
            $quote->quotePublicId,
            $eligibility->publicId,
            $this->purchaseOrderCorrelation('auto-renew-reserved-price-change-reserve'),
        );

        $configRow = DB::table('service_auto_renew_configurations')
            ->where('id', $configuration->configurationId)
            ->first();
        self::assertNotNull($configRow);
        $cycleKey = hash('sha256', implode('|', [
            (string) $configuration->configurationId,
            (string) $scenario['service_id'],
            (string) $configuration->configurationVersion,
            (string) $configRow->observed_remote_identity_generation,
            (string) $configRow->observed_expires_at,
        ]));
        $attemptId = (int) DB::table('service_auto_renew_attempts')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'cycle_key' => $cycleKey,
            'auto_renew_configuration_id' => $configuration->configurationId,
            'service_subscription_id' => $scenario['service_id'],
            'configuration_version' => $configuration->configurationVersion,
            'remote_identity_generation' => (int) $configRow->observed_remote_identity_generation,
            'observed_expires_at' => (string) $configRow->observed_expires_at,
            'observed_expiry_evidence_hash' => (string) $configRow->observed_expiry_evidence_hash,
            'observed_expiry_source' => (string) $configRow->observed_expiry_source,
            'state' => 'pending',
            'reason_code' => null,
            'baseline_price_irr' => $configuration->acceptedPriceIrr,
            'current_price_irr' => null,
            'quote_id' => null,
            'payment_eligibility_decision_id' => null,
            'payment_intent_id' => null,
            'purchase_settlement_id' => null,
            'provisioning_operation_id' => null,
            'correlation_id' => $this->purchaseOrderCorrelation('auto-renew-reserved-price-change-attempt'),
            'completed_at' => null,
            'created_at' => $this->purchaseOrderTimestamp(),
            'updated_at' => $this->purchaseOrderTimestamp(),
        ]);
        $oldIntentId = (int) DB::table('payment_intents')
            ->where('public_id', $intent->intentPublicId)
            ->value('id');
        DB::table('service_auto_renew_attempts')->where('id', $attemptId)->update([
            'quote_id' => $quote->quoteId,
            'payment_eligibility_decision_id' => $eligibility->decisionId,
            'payment_intent_id' => $oldIntentId,
            'current_price_irr' => $quote->finalPriceIrr,
            'reason_code' => 'wallet_reserved',
            'updated_at' => $this->purchaseOrderTimestamp(),
        ]);

        $this->reviseScenarioAgentRenewPricing($scenario, $pricingAuthority, 650_000, 'reserved-price-change-raised');

        $ledgerCountBeforeRecovery = DB::table('ledger_transactions')->count();
        $result = $this->app->make(ServiceAutoRenewalProcessor::class)->processDue(10);

        self::assertGreaterThanOrEqual(1, $result->blocked);
        $attempt = DB::table('service_auto_renew_attempts')
            ->where('id', $attemptId)
            ->first(['state', 'current_price_irr', 'quote_id', 'payment_intent_id']);
        self::assertNotNull($attempt);
        self::assertSame('price_change_blocked', $attempt->state);
        self::assertSame(650_000, (int) $attempt->current_price_irr);
        self::assertNotSame($quote->quoteId, (int) $attempt->quote_id);
        self::assertNull($attempt->payment_intent_id);
        self::assertSame('canceled', DB::table('payment_intents')->where('id', $oldIntentId)->value('state'));
        self::assertSame(
            'released',
            DB::table('purchase_wallet_reservations as reservation')
                ->join('wallet_holds as hold', 'hold.id', '=', 'reservation.wallet_hold_id')
                ->where('reservation.payment_intent_id', $oldIntentId)
                ->value('hold.status'),
        );
        self::assertSame($ledgerCountBeforeRecovery, DB::table('ledger_transactions')->count());
        self::assertSame(0, DB::table('purchase_settlements')->where('provider_code', 'wallet')->count());
        self::assertSame(0, DB::table('service_paid_mutation_authorities')->count());
        self::assertSame(
            1,
            DB::table('service_auto_renew_notification_intents')
                ->where('auto_renew_attempt_id', $attemptId)
                ->where('outcome', 'price_change_blocked')
                ->count(),
        );
    }

    public function test_commercial_guard_rollback_refuses_when_configuration_exists_without_attempt(): void
    {
        $scenario = $this->scenario('commercial-rollback-fence');
        $this->enableAutoRenew($scenario, 'commercial-rollback-fence');
        self::assertSame(0, DB::table('service_auto_renew_attempts')->count());
        self::assertSame(
            1,
            (int) DB::selectOne(
                "SELECT COUNT(*) AS aggregate FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = 'sara_commercial_binding_guard'",
            )->aggregate,
        );

        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_08_21_000230_guard_service_auto_renew_commercial_binding.php');
        try {
            $migration->down();
            self::fail('Commercial auto-renew guard rollback must refuse while configuration authority exists.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'Cannot remove Service auto-renew guards while auto-renew authority rows exist.',
                $exception->getMessage(),
            );
        }

        self::assertSame(
            1,
            (int) DB::selectOne(
                "SELECT COUNT(*) AS aggregate FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = 'sara_commercial_binding_guard'",
            )->aggregate,
        );
    }
}
