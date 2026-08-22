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
use App\Modules\Provisioning\Application\ServiceAutoRenewDatabaseAuthority;
use App\Modules\Provisioning\Application\ServiceMutationExecutor;
use App\Modules\Provisioning\Domain\AutoRenewAttemptState;
use App\Modules\Provisioning\Domain\ProvisioningState;
use Database\Seeders\WalletFinancialFoundationSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait ServiceAutoRenewalRuntimeScenariosB
{
    public function test_reconfiguration_supersedes_unfinancialized_old_cycle_before_new_attempt(): void
    {
        $scenario = $this->scenario('config-superseded');
        $this->enableWalletMethod('config-superseded');
        $this->seed(WalletFinancialFoundationSeeder::class);
        $this->fundWallet($scenario['user_id'], 1, 'config-superseded');
        $firstConfiguration = $this->enableAutoRenew($scenario, 'config-superseded');

        $processor = $this->app->make(ServiceAutoRenewalProcessor::class);
        $processor->processDue(10);
        $oldAttemptId = (int) DB::table('service_auto_renew_attempts')
            ->where('configuration_version', $firstConfiguration->configurationVersion)
            ->value('id');
        self::assertGreaterThan(0, $oldAttemptId);
        self::assertSame(AutoRenewAttemptState::InsufficientWallet->value, DB::table('service_auto_renew_attempts')->where('id', $oldAttemptId)->value('state'));

        $secondConfiguration = $this->app->make(ServiceAutoRenewConfigurationService::class)->configure(
            'service.auto-renew.config.config-superseded.000002',
            $scenario['user_id'],
            $scenario['service_public_id'],
            'aq-renew-30d',
            true,
            $this->purchaseOrderCorrelation('auto-renew-config-superseded-second'),
        );
        self::assertSame($firstConfiguration->configurationVersion + 1, $secondConfiguration->configurationVersion);

        $reconfigured = $processor->processDue(10);

        self::assertSame(0, $reconfigured->failed, 'Routine supersession cleanup must not raise scheduler attention.');
        $oldAttempt = DB::table('service_auto_renew_attempts')->where('id', $oldAttemptId)->first(['state', 'reason_code']);
        self::assertNotNull($oldAttempt);
        self::assertSame(AutoRenewAttemptState::Failed->value, $oldAttempt->state);
        self::assertSame('configuration_superseded', $oldAttempt->reason_code);
        self::assertSame(
            0,
            DB::table('service_auto_renew_notification_intents')
                ->where('auto_renew_attempt_id', $oldAttemptId)
                ->where('outcome', 'failure')
                ->count(),
            'Superseded unfinancialized cycles are audit retirement, not renewal failure notifications.',
        );

        $connection = DB::connection();
        ServiceAutoRenewDatabaseAuthority::beginRuntime($connection);
        try {
            try {
                $connection->table('service_auto_renew_notification_intents')->insert([
                    'public_id' => (string) Str::ulid(),
                    'auto_renew_attempt_id' => $oldAttemptId,
                    'outcome' => 'failure',
                    'reason_code' => 'configuration_superseded',
                    'created_at' => $this->purchaseOrderTimestamp(),
                ]);
                self::fail('Runtime capability must not forge a failure notification for supersession retirement.');
            } catch (QueryException $exception) {
                self::assertStringContainsString(
                    'Auto-renew notification outcome does not match current attempt authority.',
                    $exception->getMessage(),
                );
            }
        } finally {
            ServiceAutoRenewDatabaseAuthority::endRuntime($connection);
        }

        self::assertSame(0, DB::table('purchase_settlements')->where('provider_code', 'wallet')->count());
        self::assertSame(0, DB::table('purchase_wallet_reservations')->count());
        self::assertSame(1, DB::table('service_auto_renew_attempts')->where('configuration_version', $secondConfiguration->configurationVersion)->count());
    }

    public function test_provider_uncertainty_preserves_captured_settlement_and_requires_reconciliation(): void
    {
        $scenario = $this->scenario('provider-uncertain');
        $this->enableWalletMethod('provider-uncertain');
        $this->seed(WalletFinancialFoundationSeeder::class);
        $this->fundWallet($scenario['user_id'], 600_000, 'provider-uncertain');
        $this->enableAutoRenew($scenario, 'provider-uncertain');

        $processor = $this->app->make(ServiceAutoRenewalProcessor::class);
        $queued = $processor->processDue(10);
        self::assertSame(1, $queued->queued);
        $operationPublicId = (string) DB::table('provisioning_operations')->where('operation_type', 'renew')->value('public_id');
        self::assertNotSame('', $operationPublicId);
        $scenario['adapter']->throwOnUpdateExpiry = true;

        $execution = $this->app->make(ServiceMutationExecutor::class)->execute($operationPublicId);
        self::assertSame(ProvisioningState::UncertainRemoteResult, $execution->state);

        $reconciliation = $processor->processDue(10);
        self::assertGreaterThanOrEqual(1, $reconciliation->failed, 'Uncertain remote mutation must keep scheduler attention active.');
        $attempt = DB::table('service_auto_renew_attempts')->first(['state', 'reason_code', 'purchase_settlement_id', 'provisioning_operation_id']);
        self::assertNotNull($attempt);
        self::assertSame(AutoRenewAttemptState::MutationQueued->value, $attempt->state);
        self::assertSame('mutation_reconciliation_required', $attempt->reason_code);
        self::assertNotNull($attempt->purchase_settlement_id);
        self::assertNotNull($attempt->provisioning_operation_id);
        self::assertSame(1, DB::table('purchase_settlements')->where('provider_code', 'wallet')->count());
        self::assertSame(
            0,
            DB::table('service_auto_renew_notification_intents')->where('outcome', 'failure')->count(),
            'Provider uncertainty requires operational attention but is not a definitive renewal failure.',
        );
    }

    public function test_definitive_downstream_mutation_failure_preserves_captured_settlement_and_terminalizes_attempt(): void
    {
        $scenario = $this->scenario('provider-definitive-failure');
        $this->enableWalletMethod('provider-definitive-failure');
        $this->seed(WalletFinancialFoundationSeeder::class);
        $this->fundWallet($scenario['user_id'], 600_000, 'provider-definitive-failure');
        $this->enableAutoRenew($scenario, 'provider-definitive-failure');

        $processor = $this->app->make(ServiceAutoRenewalProcessor::class);
        $queued = $processor->processDue(10);
        self::assertSame(1, $queued->queued);
        self::assertSame(1, DB::table('purchase_settlements')->where('provider_code', 'wallet')->count());
        $ledgerCountAfterCapture = DB::table('ledger_transactions')->count();

        $operationPublicId = (string) DB::table('provisioning_operations')->where('operation_type', 'renew')->value('public_id');
        self::assertNotSame('', $operationPublicId);
        $scenario['adapter']->definitiveFailureOnUpdateExpiry = true;

        $execution = $this->app->make(ServiceMutationExecutor::class)->execute($operationPublicId);
        self::assertSame(ProvisioningState::FailedFinal, $execution->state);

        $reconciled = $processor->processDue(10);
        self::assertGreaterThanOrEqual(1, $reconciled->failed);
        $attempt = DB::table('service_auto_renew_attempts')->first([
            'state', 'reason_code', 'purchase_settlement_id', 'provisioning_operation_id',
        ]);
        self::assertNotNull($attempt);
        self::assertSame(AutoRenewAttemptState::Failed->value, $attempt->state);
        self::assertSame('renewal_mutation_failed', $attempt->reason_code);
        self::assertNotNull($attempt->purchase_settlement_id);
        self::assertNotNull($attempt->provisioning_operation_id);
        self::assertSame($ledgerCountAfterCapture, DB::table('ledger_transactions')->count());
        self::assertSame(1, DB::table('purchase_settlements')->where('provider_code', 'wallet')->count());
        self::assertSame(1, DB::table('service_paid_mutation_authorities')->count());
        self::assertSame(1, DB::table('service_auto_renew_notification_intents')->where('outcome', 'failure')->count());
    }

    public function test_auto_renew_migration_rollback_refuses_to_delete_authority_rows(): void
    {
        $scenario = $this->scenario('rollback-fence');
        $this->enableAutoRenew($scenario, 'rollback-fence');
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_08_21_000200_enable_service_auto_renew_authority.php');

        try {
            $migration->down();
            self::fail('Auto-renew authority rollback must refuse to delete persisted authority rows.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'Cannot roll back Service auto-renew authority while auto-renew authority rows exist.',
                $exception->getMessage(),
            );
        }

        self::assertTrue(DB::getSchemaBuilder()->hasTable('service_auto_renew_attempts'));
        self::assertSame(1, DB::table('service_auto_renew_configurations')->count());
    }

    public function test_captured_settlement_resume_queues_same_financial_authority_without_second_debit(): void
    {
        $scenario = $this->scenario('capture-resume');
        $this->enableWalletMethod('capture-resume');
        $this->seed(WalletFinancialFoundationSeeder::class);
        $walletAccountId = $this->fundWallet($scenario['user_id'], 600_000, 'capture-resume');
        $configuration = $this->enableAutoRenew($scenario, 'capture-resume');

        $quote = $this->app->make(QuoteService::class)->create(
            'service.auto-renew.crash.quote.000001',
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
            $this->purchaseOrderCorrelation('auto-renew-crash-quote'),
            null,
            new ServicePackageQuoteContext($scenario['service_public_id'], 'aq-renew-30d'),
        );
        $eligibility = $this->app->make(PaymentMethodEligibilityService::class)->evaluate(
            'service.auto-renew.crash.eligibility.000001',
            $scenario['user_id'],
            $quote->quotePublicId,
        );
        $wallet = $this->app->make(PurchaseWalletPaymentService::class);
        $intent = $wallet->reserve(
            'service.auto-renew.crash.intent.000001',
            $scenario['user_id'],
            $walletAccountId,
            $quote->quotePublicId,
            $eligibility->publicId,
            $this->purchaseOrderCorrelation('auto-renew-crash-reserve'),
        );
        $order = $wallet->capture($intent->intentPublicId, $this->purchaseOrderCorrelation('auto-renew-crash-capture'));

        $configRow = DB::table('service_auto_renew_configurations')->where('id', $configuration->configurationId)->first();
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
            'state' => AutoRenewAttemptState::Pending->value,
            'reason_code' => null,
            'baseline_price_irr' => 600_000,
            'current_price_irr' => null,
            'quote_id' => null,
            'payment_eligibility_decision_id' => null,
            'payment_intent_id' => null,
            'purchase_settlement_id' => null,
            'provisioning_operation_id' => null,
            'correlation_id' => $this->purchaseOrderCorrelation('auto-renew-crash-attempt'),
            'completed_at' => null,
            'created_at' => $this->purchaseOrderTimestamp(),
            'updated_at' => $this->purchaseOrderTimestamp(),
        ]);
        $this->recordAutoRenewCycleClaimedEvent($attemptId);
        $intentId = (int) DB::table('payment_intents')->where('public_id', $intent->intentPublicId)->value('id');
        $settlementId = (int) DB::table('purchase_settlements')->where('public_id', $order->purchaseSettlementPublicId)->value('id');
        DB::table('service_auto_renew_attempts')->where('id', $attemptId)->update([
            'quote_id' => $quote->quoteId,
            'payment_eligibility_decision_id' => $eligibility->decisionId,
            'payment_intent_id' => $intentId,
            'purchase_settlement_id' => $settlementId,
            'current_price_irr' => $quote->finalPriceIrr,
            'state' => AutoRenewAttemptState::Settled->value,
            'reason_code' => 'simulated_crash_after_capture',
            'updated_at' => $this->purchaseOrderTimestamp(),
        ]);
        $settledAttempt = DB::table('service_auto_renew_attempts')->where('id', $attemptId)->first([
            'quote_id', 'payment_intent_id', 'purchase_settlement_id', 'provisioning_operation_id', 'correlation_id',
        ]);
        self::assertNotNull($settledAttempt);
        DB::table('service_auto_renew_attempt_events')->insert([
            'auto_renew_attempt_id' => $attemptId,
            'sequence' => 2,
            'from_state' => AutoRenewAttemptState::Pending->value,
            'to_state' => AutoRenewAttemptState::Settled->value,
            'reason_code' => 'simulated_crash_after_capture',
            'quote_id' => $settledAttempt->quote_id,
            'payment_intent_id' => $settledAttempt->payment_intent_id,
            'purchase_settlement_id' => $settledAttempt->purchase_settlement_id,
            'provisioning_operation_id' => $settledAttempt->provisioning_operation_id,
            'correlation_id' => (string) $settledAttempt->correlation_id,
            'created_at' => $this->purchaseOrderTimestamp(),
        ]);

        $capturedLedgerCount = DB::table('ledger_transactions')->count();
        $result = $this->app->make(ServiceAutoRenewalProcessor::class)->processDue(10);

        self::assertGreaterThanOrEqual(1, $result->queued);
        self::assertSame($capturedLedgerCount, DB::table('ledger_transactions')->count());
        self::assertSame(1, DB::table('purchase_settlements')->where('provider_code', 'wallet')->count());
        self::assertSame(1, DB::table('service_paid_mutation_authorities')->count());
        self::assertSame(AutoRenewAttemptState::MutationQueued->value, DB::table('service_auto_renew_attempts')->where('id', $attemptId)->value('state'));
    }
}
