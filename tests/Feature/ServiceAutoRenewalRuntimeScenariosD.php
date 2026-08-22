<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Application\QuoteAgentPricingContext;
use App\Modules\Orders\Application\QuotePricingInput;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Application\ServicePackageQuoteContext;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use Illuminate\Support\Facades\DB;

trait ServiceAutoRenewalRuntimeScenariosD
{
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
}
