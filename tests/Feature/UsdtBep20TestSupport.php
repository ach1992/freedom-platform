<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Application\QuotePricingInput;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
use App\Modules\Payments\Usdt\Application\UsdtAmountQuoteService;
use App\Modules\Payments\Usdt\Application\UsdtCircuitBreaker;
use App\Modules\Payments\Usdt\Application\UsdtDestinationWalletService;
use App\Modules\Payments\Usdt\Application\UsdtPaymentAuthorityReceipt;
use App\Modules\Payments\Usdt\Application\UsdtPaymentAuthorityService;
use App\Modules\Payments\Usdt\Application\UsdtRateResolver;
use App\Modules\Payments\Usdt\Application\UsdtTokenAmount;
use App\Modules\Payments\Usdt\Application\UsdtTxidSubmissionReceipt;
use App\Modules\Payments\Usdt\Application\UsdtTxidSubmissionService;
use App\Modules\Payments\Usdt\Domain\UsdtRate;
use App\Modules\Payments\Usdt\Domain\UsdtRatePolicy;
use App\Modules\Payments\Usdt\Domain\UsdtRateProvider;
use App\Modules\Payments\Usdt\Domain\UsdtRateSide;
use App\Shared\Application\Clock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;

final class ReusableUsdtBep20RateProvider implements UsdtRateProvider
{
    public function __construct(
        private readonly string $providerCode,
        private readonly string $rateIrr,
        private readonly \DateTimeImmutable $fetchedAt,
    ) {}

    public function code(): string
    {
        return $this->providerCode;
    }

    public function fetch(UsdtRateSide $side): UsdtRate
    {
        return new UsdtRate(
            $this->providerCode,
            $this->rateIrr,
            $this->fetchedAt,
            hash('sha256', $this->providerCode."\0".$this->rateIrr."\0".$side->value),
        );
    }
}

trait UsdtBep20TestSupport
{
    use AgentPricingQuoteIntegrationTestSupport;

    /** @return array{authority:UsdtPaymentAuthorityReceipt,submission:UsdtTxidSubmissionReceipt,amount_irr:int,amount_base_units:int,transaction_at:\DateTimeImmutable} */
    private function prepareUsdtBep20Submission(string $suffix, Clock $clock, string $txid): array
    {
        $user = $this->quoteUser('customer');
        $offering = $this->quoteOffering(1_000_000);
        $quote = $this->app->make(QuoteService::class)->create(
            'usdt.support.quote.'.$suffix,
            $user,
            $offering['id'],
            new QuotePricingInput(QuoteOverrideSource::None, null, null, null, 0, $clock->now()->modify('+30 minutes')),
            $this->usdtSupportCorrelation('quote-'.$suffix),
        );
        $this->app->make(UsdtDestinationWalletService::class)->configure(
            'usdt.support.wallet.'.$suffix,
            $this->ownerAdministrator(),
            'primary',
            '0x'.str_repeat('bb', 20),
            true,
            'Reusable USDT BEP20 test destination.',
            $this->usdtSupportCorrelation('wallet-'.$suffix),
        );
        $amountQuote = $this->usdtSupportAmountService($clock)->create('usdt.support.amount.'.$suffix, $quote->quotePublicId);
        $decision = $this->app->make(PaymentMethodEligibilityService::class)->evaluate(
            'usdt.support.eligibility.'.$suffix,
            $user,
            $quote->quotePublicId,
        );
        $authority = $this->app->make(UsdtPaymentAuthorityService::class)->prepare(
            'usdt.support.authority.'.$suffix,
            'usdt.support.intent.'.$suffix,
            $user,
            $quote->quotePublicId,
            $decision->publicId,
            $amountQuote->publicId,
            $this->usdtSupportCorrelation('authority-'.$suffix),
        );
        $submission = $this->app->make(UsdtTxidSubmissionService::class)->submit(
            'usdt.support.txid.'.$suffix,
            $authority->publicId,
            $user,
            $txid,
            null,
            null,
            $this->usdtSupportCorrelation('txid-'.$suffix),
        );
        $authorityCreatedAt = DB::table('usdt_payment_authorities')->where('id', $authority->authorityId)->value('created_at');
        if (! is_string($authorityCreatedAt)) {
            throw new \RuntimeException('USDT test support authority timestamp is unavailable.');
        }

        return [
            'authority' => $authority,
            'submission' => $submission,
            'amount_irr' => $quote->finalPriceIrr,
            'amount_base_units' => UsdtTokenAmount::toBaseUnits($amountQuote->exactUsdt),
            'transaction_at' => new \DateTimeImmutable($authorityCreatedAt, new \DateTimeZone('UTC')),
        ];
    }

    private function configureUsdtBep20Method(Clock $clock, string $suffix): void
    {
        $owner = $this->ownerAdministrator();
        $service = $this->app->make(PaymentMethodEligibilityService::class);
        $service->configureMethod(
            'usdt.support.method.'.$suffix,
            $owner,
            'usdt_bep20',
            true,
            false,
            1,
            'Reusable USDT BEP20 test method.',
            $this->usdtSupportCorrelation('method-'.$suffix),
        );
        $service->recordHealth(
            'usdt.support.health.'.$suffix,
            $owner,
            'usdt_bep20',
            true,
            $clock->now()->modify('+20 minutes'),
            'Reusable USDT BEP20 verification provider healthy.',
            $this->usdtSupportCorrelation('health-'.$suffix),
        );
    }

    private function usdtSupportAmountService(Clock $clock): UsdtAmountQuoteService
    {
        $primary = new ReusableUsdtBep20RateProvider('nobitex', '1000000', $clock->now());
        $secondary = new ReusableUsdtBep20RateProvider('secondary', '1005000', $clock->now());
        $policy = new UsdtRatePolicy(['nobitex', 'secondary'], UsdtRateSide::Buy, 120, '100000', '10000000', 500, false, 3, 60);

        return new UsdtAmountQuoteService(
            $this->app->make(DatabaseManager::class),
            $this->app->make(QuoteService::class),
            $this->app->make(UsdtDestinationWalletService::class),
            new UsdtRateResolver(
                [$primary, $secondary],
                $policy,
                new UsdtCircuitBreaker(new Repository(new ArrayStore), $clock, 3, 60),
                $clock,
            ),
            $clock,
            0,
            6,
            120,
            120,
            'primary',
        );
    }

    private function usdtSupportCorrelation(string $suffix): string
    {
        return hash('sha256', 'usdt-bep20-support:'.$suffix);
    }
}
