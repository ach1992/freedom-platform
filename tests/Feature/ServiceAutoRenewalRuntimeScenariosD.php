<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Agents\Domain\AgentPricingAction;
use App\Modules\Orders\Application\AgentPurchaseCountService;
use App\Modules\Orders\Application\QuoteAgentPricingContext;
use App\Modules\Orders\Application\QuotePricingInput;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Application\ServicePackageQuoteContext;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use App\Modules\Payments\Application\PurchaseWalletPaymentService;
use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
use App\Modules\Provisioning\Application\ServiceAutoRenewalProcessor;
use Database\Seeders\WalletFinancialFoundationSeeder;
use Illuminate\Support\Facades\DB;

trait ServiceAutoRenewalRuntimeScenariosD
{
    public function test_agent_renewal_settlement_does_not_increment_initial_agent_purchase_count(): void
    {
        $suffix = 'agent-purchase-count-renewal';
        $scenario = $this->scenario($suffix);
        $this->enableWalletMethod($suffix);
        $this->seed(WalletFinancialFoundationSeeder::class);
        $walletAccountId = $this->fundWallet($scenario['user_id'], 700_000, $suffix);
        $this->enableScenarioAgentRenewPricing($scenario, 600_000, $suffix);

        $counts = $this->app->make(AgentPurchaseCountService::class);
        self::assertSame(0, $counts->forSelf($scenario['user_id'], $scenario['user_id']));

        $quote = $this->app->make(QuoteService::class)->create(
            'service.agent-count.renew.quote.000001',
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
            $this->purchaseOrderCorrelation('agent-count-renew-quote'),
            new QuoteAgentPricingContext($scenario['user_id'], AgentPricingAction::Renew),
            new ServicePackageQuoteContext($scenario['service_public_id'], 'aq-renew-30d'),
        );
        self::assertSame('renew', $quote->action->value);
        self::assertNotNull($quote->agentPricing);
        self::assertSame('renew', $quote->agentPricing->action->value);

        $decision = $this->app->make(PaymentMethodEligibilityService::class)->evaluate(
            'service.agent-count.renew.eligibility.000001',
            $scenario['user_id'],
            $quote->quotePublicId,
        );
        $intent = $this->app->make(PurchaseWalletPaymentService::class)->reserve(
            'service.agent-count.renew.intent.000001',
            $scenario['user_id'],
            $walletAccountId,
            $quote->quotePublicId,
            $decision->publicId,
            $this->purchaseOrderCorrelation('agent-count-renew-reserve'),
        );
        $order = $this->app->make(PurchaseWalletPaymentService::class)->capture(
            $intent->intentPublicId,
            $this->purchaseOrderCorrelation('agent-count-renew-capture'),
        );

        self::assertSame('purchase', DB::table('payment_intents')
            ->where('public_id', $order->paymentIntentPublicId)
            ->value('purpose'));
        self::assertSame('renew', DB::table('quotes')
            ->where('public_id', $order->sourceQuotePublicId)
            ->value('action_snapshot'));
        self::assertSame('renew', DB::table('quotes')
            ->where('public_id', $order->sourceQuotePublicId)
            ->value('agent_pricing_action_snapshot'));
        self::assertSame(0, $counts->forSelf($scenario['user_id'], $scenario['user_id']));
    }

    public function test_configuration_retry_after_pre_commit_quote_does_not_conflict_with_crashed_request(): void
    {
        $suffix = 'configuration-quote-crash';
        $scenario = $this->scenario($suffix);
        $requestKey = 'service.auto-renew.config.'.$suffix.'.000001';
        $requestHash = hash('sha256', $requestKey);
        $issuedAt = $this->purchaseOrderClock->value;
        $expiresAt = $issuedAt->modify('+15 minutes');
        $accountType = DB::table('users')->where('id', $scenario['user_id'])->value('account_type');
        $agentContext = $accountType === 'agent'
            ? QuoteAgentPricingContext::forRenewal($scenario['user_id'])
            : null;

        // Simulate a process that persisted its acceptance Quote and then crashed before the
        // configuration/history transaction committed. A later retry of the same business request
        // must create a new current Quote rather than reuse an idempotency key with a moving payload.
        $this->app->make(QuoteService::class)->create(
            'service.auto-renew.config.quote.'.$requestHash.'.'.$issuedAt->format('U.u'),
            $scenario['user_id'],
            $scenario['offering_id'],
            new QuotePricingInput(
                QuoteOverrideSource::None,
                null,
                null,
                null,
                0,
                $expiresAt,
            ),
            $this->purchaseOrderCorrelation('auto-renew-config-'.$suffix),
            $agentContext,
            new ServicePackageQuoteContext($scenario['service_public_id'], 'aq-renew-30d'),
        );
        self::assertSame(0, DB::table('service_auto_renew_configurations')->count());

        // A microsecond difference must be enough to request a fresh commercial snapshot.
        $this->purchaseOrderClock->value = $this->purchaseOrderClock->value->modify('+1 microsecond');
        $configuration = $this->enableAutoRenew($scenario, $suffix);

        self::assertTrue($configuration->enabled);
        self::assertSame(1, DB::table('service_auto_renew_configurations')->count());
        self::assertSame(2, DB::table('quotes')->where('action_snapshot', 'renew')->count());
    }

    public function test_wallet_reservation_and_attempt_binding_roll_back_as_one_authority_unit(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            self::markTestSkipped('Wallet reservation atomicity verification requires MariaDB/MySQL triggers.');
        }

        $suffix = 'reservation-bind-rollback';
        $scenario = $this->scenario($suffix);
        $this->enableWalletMethod($suffix);
        $this->seed(WalletFinancialFoundationSeeder::class);
        $this->fundWallet($scenario['user_id'], 600_000, $suffix);
        $this->enableAutoRenew($scenario, $suffix);

        $paymentIntentCount = DB::table('payment_intents')->count();
        $reservationCount = DB::table('purchase_wallet_reservations')->count();
        $holdCount = DB::table('wallet_holds')->count();
        $ledgerCount = DB::table('ledger_transactions')->count();
        $walletSettlementCount = DB::table('purchase_settlements')->where('provider_code', 'wallet')->count();
        $mutationAuthorityCount = DB::table('service_paid_mutation_authorities')->count();

        DB::unprepared('DROP TRIGGER IF EXISTS auto_renew_test_fail_intent_bind');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER auto_renew_test_fail_intent_bind
BEFORE UPDATE ON service_auto_renew_attempts
FOR EACH ROW
BEGIN
    IF OLD.payment_intent_id IS NULL AND NEW.payment_intent_id IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected auto-renew intent bind failure.';
    END IF;
END
SQL);

        try {
            $result = $this->app->make(ServiceAutoRenewalProcessor::class)->processDue(10);

            self::assertGreaterThanOrEqual(1, $result->failed);
            $attempt = DB::table('service_auto_renew_attempts')->first([
                'state', 'reason_code', 'payment_intent_id', 'purchase_settlement_id', 'provisioning_operation_id',
            ]);
            self::assertNotNull($attempt);
            self::assertSame('retry_pending', $attempt->state);
            self::assertSame('auto_renew_unexpected_failure', $attempt->reason_code);
            self::assertNull($attempt->payment_intent_id);
            self::assertNull($attempt->purchase_settlement_id);
            self::assertNull($attempt->provisioning_operation_id);

            // The bind failure happens after reserve() has created its nested PaymentIntent/hold
            // authority. The enclosing auto-renew transaction must roll all of it back together.
            self::assertSame($paymentIntentCount, DB::table('payment_intents')->count());
            self::assertSame($reservationCount, DB::table('purchase_wallet_reservations')->count());
            self::assertSame($holdCount, DB::table('wallet_holds')->count());
            self::assertSame($ledgerCount, DB::table('ledger_transactions')->count());
            self::assertSame($walletSettlementCount, DB::table('purchase_settlements')->where('provider_code', 'wallet')->count());
            self::assertSame($mutationAuthorityCount, DB::table('service_paid_mutation_authorities')->count());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS auto_renew_test_fail_intent_bind');
        }

        // Once the injected failure is gone and the bounded retry becomes due, the same cycle must
        // recover from its rolled-back pre-financial state and create exactly one financial/mutation
        // authority chain rather than becoming stuck or duplicating the prior failed reservation.
        $this->purchaseOrderClock->value = $this->purchaseOrderClock->value->modify('+16 minutes');
        DB::statement('SET timestamp = '.$this->purchaseOrderClock->value->getTimestamp());
        $recovered = $this->app->make(ServiceAutoRenewalProcessor::class)->processDue(10);

        self::assertGreaterThanOrEqual(1, $recovered->queued);
        $attempt = DB::table('service_auto_renew_attempts')->first([
            'state', 'payment_intent_id', 'purchase_settlement_id', 'provisioning_operation_id',
        ]);
        self::assertNotNull($attempt);
        self::assertSame('mutation_queued', $attempt->state);
        self::assertNotNull($attempt->payment_intent_id);
        self::assertNotNull($attempt->purchase_settlement_id);
        self::assertNotNull($attempt->provisioning_operation_id);
        self::assertSame($paymentIntentCount + 1, DB::table('payment_intents')->count());
        self::assertSame($reservationCount + 1, DB::table('purchase_wallet_reservations')->count());
        self::assertSame($holdCount + 1, DB::table('wallet_holds')->count());
        self::assertSame($ledgerCount + 1, DB::table('ledger_transactions')->count());
        self::assertSame($walletSettlementCount + 1, DB::table('purchase_settlements')->where('provider_code', 'wallet')->count());
        self::assertSame($mutationAuthorityCount + 1, DB::table('service_paid_mutation_authorities')->count());
        self::assertSame(
            'captured',
            DB::table('payment_intents')->where('id', (int) $attempt->payment_intent_id)->value('state'),
        );
        self::assertSame(
            'captured',
            DB::table('purchase_wallet_reservations as reservation')
                ->join('wallet_holds as hold', 'hold.id', '=', 'reservation.wallet_hold_id')
                ->where('reservation.payment_intent_id', (int) $attempt->payment_intent_id)
                ->value('hold.status'),
        );
    }
}
