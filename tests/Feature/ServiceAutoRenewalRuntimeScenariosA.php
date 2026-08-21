<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Provisioning\Application\ServiceAutoRenewalProcessor;
use App\Modules\Provisioning\Application\ServiceMutationExecutor;
use App\Modules\Provisioning\Domain\AutoRenewAttemptState;
use App\Modules\Provisioning\Domain\ProvisioningState;
use Database\Seeders\WalletFinancialFoundationSeeder;
use Illuminate\Support\Facades\DB;

trait ServiceAutoRenewalRuntimeScenariosA
{
    public function test_successful_wallet_auto_renew_uses_one_settlement_and_one_generation_safe_mutation(): void
    {
        $scenario = $this->scenario('success');
        $this->enableWalletMethod('success');
        $this->seed(WalletFinancialFoundationSeeder::class);
        $this->fundWallet($scenario['user_id'], 600_000, 'success');
        $this->enableAutoRenew($scenario, 'success');

        $processor = $this->app->make(ServiceAutoRenewalProcessor::class);
        $first = $processor->processDue(10);
        self::assertSame(1, $first->queued);
        self::assertSame(1, DB::table('purchase_settlements')->where('provider_code', 'wallet')->count());
        self::assertSame(1, DB::table('service_paid_mutation_authorities')->count());
        self::assertSame(1, DB::table('provisioning_operations')->where('operation_type', 'renew')->count());
        self::assertSame(1, (int) DB::table('service_subscriptions')->where('id', $scenario['service_id'])->value('mutation_generation'));

        $processor->processDue(10);
        self::assertSame(1, DB::table('purchase_settlements')->where('provider_code', 'wallet')->count());
        self::assertSame(1, DB::table('service_paid_mutation_authorities')->count());
        self::assertSame(1, DB::table('provisioning_operations')->where('operation_type', 'renew')->count());

        $operationPublicId = (string) DB::table('provisioning_operations')->where('operation_type', 'renew')->value('public_id');
        $executed = $this->app->make(ServiceMutationExecutor::class)->execute($operationPublicId);
        self::assertSame(ProvisioningState::Succeeded, $executed->state);
        self::assertContains('lookup_remote_id', $scenario['adapter']->calls);
        self::assertSame('update_expiry', $scenario['adapter']->calls[array_key_last($scenario['adapter']->calls)]);
        self::assertNotEmpty($scenario['adapter']->transactionLevels);
        self::assertSame([0], array_values(array_unique($scenario['adapter']->transactionLevels)));

        $final = $processor->processDue(10);
        self::assertGreaterThanOrEqual(1, $final->succeeded);
        self::assertSame(AutoRenewAttemptState::Succeeded->value, DB::table('service_auto_renew_attempts')->value('state'));
        self::assertSame(1, DB::table('service_auto_renew_notification_intents')->where('outcome', 'success')->count());
        self::assertSame(1, DB::table('purchase_settlements')->where('provider_code', 'wallet')->count());
    }

    public function test_insufficient_wallet_is_durable_and_creates_no_partial_purchase_effect(): void
    {
        $scenario = $this->scenario('insufficient');
        $this->enableWalletMethod('insufficient');
        $this->seed(WalletFinancialFoundationSeeder::class);
        $this->fundWallet($scenario['user_id'], 1, 'insufficient');
        $this->enableAutoRenew($scenario, 'insufficient');

        $processor = $this->app->make(ServiceAutoRenewalProcessor::class);
        $result = $processor->processDue(10);

        self::assertSame(1, $result->insufficientWallet);
        self::assertSame(AutoRenewAttemptState::InsufficientWallet->value, DB::table('service_auto_renew_attempts')->value('state'));
        self::assertSame(1, DB::table('service_auto_renew_notification_intents')->where('outcome', 'insufficient_wallet')->count());
        $attempt = DB::table('service_auto_renew_attempts')->first(['state', 'retry_count', 'next_retry_at']);
        self::assertNotNull($attempt);
        self::assertSame('insufficient_wallet', $attempt->state);
        self::assertSame(1, (int) $attempt->retry_count);
        self::assertNotNull($attempt->next_retry_at);

        $immediate = $processor->processDue(10);
        self::assertSame(0, $immediate->attempted);
        self::assertSame(1, (int) DB::table('service_auto_renew_attempts')->value('retry_count'));
        self::assertSame(0, DB::table('purchase_settlements')->where('provider_code', 'wallet')->count());
        self::assertSame(0, DB::table('purchase_wallet_reservations')->count());
        self::assertSame(0, DB::table('service_paid_mutation_authorities')->count());
        self::assertSame(1, DB::table('ledger_transactions')->where('transaction_type', 'auto_renew_test_funding')->count());
    }

    public function test_retryable_insufficient_wallet_exhausts_configured_retry_budget(): void
    {
        config()->set('auto_renew.max_retry_count', 1);
        config()->set('auto_renew.retry_initial_delay_minutes', 5);
        config()->set('auto_renew.retry_max_delay_minutes', 5);
        $scenario = $this->scenario('retry-exhausted');
        $this->enableWalletMethod('retry-exhausted');
        $this->seed(WalletFinancialFoundationSeeder::class);
        $this->fundWallet($scenario['user_id'], 1, 'retry-exhausted');
        $this->enableAutoRenew($scenario, 'retry-exhausted');

        $processor = $this->app->make(ServiceAutoRenewalProcessor::class);
        $first = $processor->processDue(10);
        self::assertSame(1, $first->insufficientWallet);
        self::assertSame(1, (int) DB::table('service_auto_renew_attempts')->value('retry_count'));

        $this->purchaseOrderClock->value = $this->purchaseOrderClock->value->modify('+6 minutes');
        $second = $processor->processDue(10);

        self::assertGreaterThanOrEqual(1, $second->failed);
        $attempt = DB::table('service_auto_renew_attempts')->first(['state', 'reason_code', 'retry_count', 'next_retry_at']);
        self::assertNotNull($attempt);
        self::assertSame(AutoRenewAttemptState::Failed->value, $attempt->state);
        self::assertSame('retry_exhausted', $attempt->reason_code);
        self::assertSame(2, (int) $attempt->retry_count);
        self::assertNull($attempt->next_retry_at);
        self::assertSame(1, DB::table('service_auto_renew_notification_intents')->where('outcome', 'failure')->count());
        self::assertSame(0, DB::table('purchase_settlements')->where('provider_code', 'wallet')->count());
    }

    public function test_stop_policy_blocks_changed_price_before_wallet_reservation(): void
    {
        $scenario = $this->scenario('price-stop');
        $this->enableWalletMethod('price-stop');
        $this->seed(WalletFinancialFoundationSeeder::class);
        $this->fundWallet($scenario['user_id'], 1_000_000, 'price-stop');
        $configuration = $this->enableAutoRenew($scenario, 'price-stop');

        // Simulate a previously settled renewal at a lower accepted commercial price. The
        // production processor owns this field after capture; the fixture only establishes
        // the prior-cycle baseline needed to exercise the stop policy against a fresh Quote.
        DB::table('service_auto_renew_configurations')->where('id', $configuration->configurationId)->update([
            'last_settled_price_irr' => 500_000,
            'updated_at' => $this->purchaseOrderTimestamp(),
        ]);

        $result = $this->app->make(ServiceAutoRenewalProcessor::class)->processDue(10);

        self::assertSame(1, $result->blocked);
        self::assertSame(AutoRenewAttemptState::PriceChangeBlocked->value, DB::table('service_auto_renew_attempts')->value('state'));
        self::assertSame(600_000, (int) DB::table('service_auto_renew_attempts')->value('current_price_irr'));
        self::assertSame(0, DB::table('purchase_wallet_reservations')->count());
        self::assertSame(0, DB::table('purchase_settlements')->where('provider_code', 'wallet')->count());
        self::assertSame(1, DB::table('service_auto_renew_notification_intents')->where('outcome', 'price_change_blocked')->count());
    }

    public function test_database_rejects_forged_attempt_that_does_not_match_configuration_cycle_authority(): void
    {
        $scenario = $this->scenario('forged-attempt');
        $configuration = $this->enableAutoRenew($scenario, 'forged-attempt');
        $configRow = DB::table('service_auto_renew_configurations')->where('id', $configuration->configurationId)->first();
        self::assertNotNull($configRow);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('service_auto_renew_attempts')->insert([
            'public_id' => (string) \Illuminate\Support\Str::ulid(),
            'cycle_key' => hash('sha256', 'forged-auto-renew-cycle'),
            'auto_renew_configuration_id' => $configuration->configurationId,
            'service_subscription_id' => $scenario['service_id'],
            'configuration_version' => $configuration->configurationVersion,
            'remote_identity_generation' => (int) $configRow->observed_remote_identity_generation,
            'observed_expires_at' => (string) $configRow->observed_expires_at,
            'observed_expiry_evidence_hash' => (string) $configRow->observed_expiry_evidence_hash,
            'observed_expiry_source' => (string) $configRow->observed_expiry_source,
            'state' => AutoRenewAttemptState::Pending->value,
            'reason_code' => null,
            'baseline_price_irr' => $configuration->acceptedPriceIrr + 1,
            'current_price_irr' => null,
            'quote_id' => null,
            'payment_eligibility_decision_id' => null,
            'payment_intent_id' => null,
            'purchase_settlement_id' => null,
            'provisioning_operation_id' => null,
            'correlation_id' => $this->purchaseOrderCorrelation('auto-renew-forged-attempt'),
            'completed_at' => null,
            'created_at' => $this->purchaseOrderTimestamp(),
            'updated_at' => $this->purchaseOrderTimestamp(),
        ]);
    }

}
