<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\AccessControl\Application\AccessChangeContext;
use App\Modules\Orders\Application\QuotePricingInput;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use App\Modules\Payments\Application\Contracts\PaymentEvidence;
use App\Modules\Payments\Application\Contracts\PaymentEvidenceAuthority;
use App\Modules\Payments\Application\Contracts\PaymentTransactionStatus;
use App\Modules\Payments\Application\Contracts\ProviderOperationOutcome;
use App\Modules\Payments\Application\Contracts\VerifiedPaymentEvent;
use App\Modules\Payments\Application\PurchasePaymentIntentService;
use App\Modules\Payments\Application\PurchaseRefundReceipt;
use App\Modules\Payments\Application\PurchaseRefundService;
use App\Modules\Payments\Application\PurchaseSettlementReceipt;
use App\Modules\Payments\Application\PurchaseSettlementService;
use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
use App\Modules\Promotions\Application\PromotionRuleService;
use App\Modules\Promotions\Application\ReferralAttributionService;
use App\Modules\Promotions\Application\ReferralRewardAccrualService;
use App\Modules\Promotions\Domain\PromotionAction;
use App\Modules\Promotions\Domain\PromotionAudience;
use App\Modules\Promotions\Domain\PromotionDiscountType;
use App\Modules\Promotions\Domain\PromotionRuleDefinition;
use App\Modules\Promotions\Domain\PromotionRuleKind;
use App\Modules\Promotions\Domain\PromotionRuleState;
use App\Modules\Promotions\Domain\ReferralRewardRecipient;
use App\Shared\Domain\Money;
use Illuminate\Support\Facades\DB;
use RuntimeException;

trait ReferralRewardLifecycleTestSupport
{
    /**
     * @return array{settlement:PurchaseSettlementReceipt,provider_code:string,reward_public_id:string,recipient_user_id:int}
     */
    private function pendingReferralRewardFixture(string $suffix, int $pendingHours = 1): array
    {
        $inviter = $this->quoteUser('customer');
        $referred = $this->quoteUser('customer');
        $attribution = $this->app->make(ReferralAttributionService::class);
        $token = $attribution->identityForUser($inviter);
        $attribution->bind($referred, $token);

        $administratorId = $this->ownerAdministrator();
        $this->app->make(PromotionRuleService::class)->create(
            'referral.lifecycle.rule.'.$suffix,
            'referral-lifecycle-'.$suffix,
            PromotionRuleKind::Referral,
            new PromotionRuleDefinition(
                PromotionRuleState::Active,
                100,
                PromotionDiscountType::Fixed,
                100_000,
                null,
                0,
                null,
                null,
                null,
                null,
                null,
                false,
                PromotionAudience::Customers,
                null,
                null,
                null,
                null,
                null,
                PromotionAction::Purchase,
                $token,
                false,
                ReferralRewardRecipient::Inviter,
                $pendingHours,
                null,
                false,
                null,
            ),
            new AccessChangeContext(
                hash('sha256', 'referral-lifecycle-rule-request:'.$suffix),
                $this->lifecycleCorrelation('rule-'.$suffix),
                'referral_reward_lifecycle_test',
                'Referral reward lifecycle test rule.',
                $administratorId,
            ),
        );

        [$settlement, $providerCode] = $this->captureLifecyclePurchase($referred, $suffix);
        $accrual = $this->app->make(ReferralRewardAccrualService::class)->accrue(
            $settlement->settlementPublicId,
            $this->lifecycleCorrelation('accrual-'.$suffix),
        );
        if ($accrual === null || count($accrual->rewardPublicIds) !== 1) {
            throw new RuntimeException('Expected one pending referral reward fixture.');
        }

        return [
            'settlement' => $settlement,
            'provider_code' => $providerCode,
            'reward_public_id' => $accrual->rewardPublicIds[0],
            'recipient_user_id' => $inviter,
        ];
    }

    private function createLifecyclePromotionalWallet(int $userId, string $suffix): int
    {
        $now = $this->app->make(\App\Shared\Application\Clock::class)->now()->format('Y-m-d H:i:s.u');

        return (int) DB::table('ledger_accounts')->insertGetId([
            'code' => 'wallet.promotional.referral.'.$userId.'.'.$suffix,
            'account_class' => 'liability',
            'owner_user_id' => $userId,
            'wallet_bucket' => 'promotional',
            'currency' => 'IRR',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function recordLifecycleRefund(
        PurchaseSettlementReceipt $settlement,
        string $providerCode,
        string $suffix,
        ?int $amountIrr = null,
    ): PurchaseRefundReceipt {
        $amount = $amountIrr ?? $settlement->amount->amount();
        $eventId = 'referral-lifecycle-refund-event-'.$suffix;
        $now = $this->app->make(\App\Shared\Application\Clock::class)->now();

        return $this->app->make(PurchaseRefundService::class)->record(
            'referral.lifecycle.refund.'.$suffix,
            $settlement->settlementPublicId,
            $providerCode,
            new VerifiedPaymentEvent(
                $eventId,
                hash('sha256', 'referral-lifecycle-refund-event-payload:'.$suffix),
                new PaymentEvidence(
                    ProviderOperationOutcome::Success,
                    PaymentEvidenceAuthority::Authoritative,
                    PaymentTransactionStatus::Refunded,
                    'referral-lifecycle-refund-transaction-'.$suffix,
                    $eventId,
                    Money::irr($amount),
                    $now,
                    null,
                    hash('sha256', 'referral-lifecycle-refund-evidence:'.$suffix),
                    ['provider_reference' => 'referral-lifecycle-refund-transaction-'.$suffix],
                ),
            ),
            $this->lifecycleCorrelation('refund-'.$suffix),
        );
    }

    /** @return array{0:PurchaseSettlementReceipt,1:string} */
    private function captureLifecyclePurchase(int $userId, string $suffix): array
    {
        $clock = $this->app->make(\App\Shared\Application\Clock::class);
        $administratorId = $this->ownerAdministrator();
        $offering = $this->quoteOffering();
        $quote = $this->app->make(QuoteService::class)->create(
            'referral.lifecycle.quote.'.$suffix,
            $userId,
            $offering['id'],
            new QuotePricingInput(QuoteOverrideSource::None, null, null, null, 0, $clock->now()->modify('+30 minutes')),
            $this->lifecycleCorrelation('quote-'.$suffix),
        );
        $methodCode = 'referral_lifecycle_gateway_'.$suffix;
        $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $eligibility->configureMethod(
            'referral.lifecycle.method.'.$suffix,
            $administratorId,
            $methodCode,
            true,
            false,
            1,
            'Referral reward lifecycle settlement configuration.',
            $this->lifecycleCorrelation('method-'.$suffix),
        );
        $eligibility->recordHealth(
            'referral.lifecycle.health.'.$suffix,
            $administratorId,
            $methodCode,
            true,
            $clock->now()->modify('+10 minutes'),
            'Healthy referral reward lifecycle observation.',
            $this->lifecycleCorrelation('health-'.$suffix),
        );
        $decision = $eligibility->evaluate('referral.lifecycle.eligibility.'.$suffix, $userId, $quote->quotePublicId);
        $purchase = $this->app->make(PurchasePaymentIntentService::class)->create(
            'referral.lifecycle.intent.'.$suffix,
            $userId,
            $quote->quotePublicId,
            $decision->publicId,
            $methodCode,
            $this->lifecycleCorrelation('intent-'.$suffix),
        );
        DB::table('payment_intents')->where('public_id', $purchase->intentPublicId)->update([
            'state' => 'submitted',
            'updated_at' => $clock->now()->format('Y-m-d H:i:s.u'),
        ]);
        $eventId = 'referral-lifecycle-capture-event-'.$suffix;
        $settlement = $this->app->make(PurchaseSettlementService::class)->capture(
            $purchase->intentPublicId,
            $methodCode,
            new VerifiedPaymentEvent(
                $eventId,
                hash('sha256', 'referral-lifecycle-capture-event-payload:'.$suffix),
                new PaymentEvidence(
                    ProviderOperationOutcome::Success,
                    PaymentEvidenceAuthority::Authoritative,
                    PaymentTransactionStatus::Settled,
                    'referral-lifecycle-capture-transaction-'.$suffix,
                    $eventId,
                    Money::irr($purchase->amount->amount()),
                    $clock->now(),
                    $clock->now(),
                    hash('sha256', 'referral-lifecycle-capture-evidence:'.$suffix),
                    ['provider_reference' => 'referral-lifecycle-capture-transaction-'.$suffix],
                ),
            ),
            $this->lifecycleCorrelation('capture-'.$suffix),
        );

        return [$settlement, $methodCode];
    }

    private function lifecycleCorrelation(string $suffix): string
    {
        return hash('sha256', 'referral-lifecycle:'.$suffix);
    }
}
