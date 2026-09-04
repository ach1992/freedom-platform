<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Catalog\Application\TelegramCustomerPurchaseCatalogService;
use App\Modules\Customers\Application\CustomerIdentityProfilePersistence;
use App\Modules\Identity\Application\Contracts\CustomerIdentityProfileWriter;
use App\Modules\Orders\Application\Contracts\QuoteDiscountAuthority;
use App\Modules\Orders\Application\TelegramCustomerPurchaseDiscountQuoteService;
use App\Modules\Orders\Application\TelegramCustomerPurchaseOrderService;
use App\Modules\Orders\Application\TelegramCustomerPurchaseQuoteService;
use App\Modules\Payments\Application\Contracts\PurchasePromotionUsageAuthority;
use App\Modules\Payments\Application\TelegramCustomerPurchaseWalletPaymentService;
use App\Modules\Payments\CardToCard\Application\Contracts\CardToCardAdjustmentGenerator;
use App\Modules\Payments\CardToCard\Infrastructure\SecureCardToCardAdjustmentGenerator;
use App\Modules\Payments\Eligibility\Application\TelegramCustomerPurchasePaymentMethodsService;
use App\Modules\Payments\NowPayments\Application\Contracts\NowPaymentsTransport;
use App\Modules\Payments\NowPayments\Infrastructure\HttpNowPaymentsTransport;
use App\Modules\Payments\Usdt\Application\TelegramManagedUsdtRateSettingsService;
use App\Modules\Payments\Usdt\Application\UsdtRateResolver;
use App\Modules\Payments\Usdt\Infrastructure\UsdtRuntimeFactory;
use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalTransport;
use App\Modules\Payments\Zarinpal\Infrastructure\HttpZarinpalTransport;
use App\Modules\Promotions\Application\BenefitCodeDiscountQuoteAuthority;
use App\Modules\Promotions\Application\PurchasePromotionUsageAuthorityService;
use App\Modules\Provisioning\Application\TelegramOwnedServiceDeliveryResendService;
use App\Modules\Provisioning\Application\TelegramOwnedServiceProjectionService;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseCatalog;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseDiscountQuote;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseOrder;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchasePaymentMethods;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseQuote;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseWalletPayment;
use App\Modules\Telegram\Application\Contracts\TelegramManagedUsdtRateSettings;
use App\Modules\Telegram\Application\Contracts\TelegramOwnedServiceDeliveryResender;
use App\Modules\Telegram\Application\Contracts\TelegramOwnedServiceProjection;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(CustomerIdentityProfileWriter::class, CustomerIdentityProfilePersistence::class);
        $this->app->bind(TelegramCustomerPurchaseCatalog::class, TelegramCustomerPurchaseCatalogService::class);
        $this->app->bind(QuoteDiscountAuthority::class, BenefitCodeDiscountQuoteAuthority::class);
        $this->app->bind(PurchasePromotionUsageAuthority::class, PurchasePromotionUsageAuthorityService::class);
        $this->app->bind(TelegramCustomerPurchaseDiscountQuote::class, TelegramCustomerPurchaseDiscountQuoteService::class);
        $this->app->bind(TelegramCustomerPurchaseQuote::class, TelegramCustomerPurchaseQuoteService::class);
        $this->app->bind(TelegramCustomerPurchasePaymentMethods::class, TelegramCustomerPurchasePaymentMethodsService::class);
        $this->app->bind(TelegramCustomerPurchaseWalletPayment::class, TelegramCustomerPurchaseWalletPaymentService::class);
        $this->app->bind(TelegramCustomerPurchaseOrder::class, TelegramCustomerPurchaseOrderService::class);
        $this->app->bind(TelegramOwnedServiceProjection::class, TelegramOwnedServiceProjectionService::class);
        $this->app->bind(TelegramOwnedServiceDeliveryResender::class, TelegramOwnedServiceDeliveryResendService::class);
        $this->app->bind(TelegramManagedUsdtRateSettings::class, TelegramManagedUsdtRateSettingsService::class);
        $this->app->bind(CardToCardAdjustmentGenerator::class, SecureCardToCardAdjustmentGenerator::class);
        $this->app->bind(ZarinpalTransport::class, HttpZarinpalTransport::class);
        $this->app->bind(
            UsdtRateResolver::class,
            fn ($app): UsdtRateResolver => $app->make(UsdtRuntimeFactory::class)->rateResolver(),
        );
        $this->app->bind(NowPaymentsTransport::class, function ($app): NowPaymentsTransport {
            $config = $app['config'];
            $apiKey = $config->get('services.nowpayments.api_key');
            if (! is_string($apiKey) || trim($apiKey) === '') {
                throw new RuntimeException('NOWPayments API key configuration is invalid.');
            }

            return new HttpNowPaymentsTransport(
                $app->make(HttpFactory::class),
                $apiKey,
                $this->positiveIntegerConfig($config->get('services.nowpayments.connect_timeout_seconds'), 1, 30, 'NOWPayments connect timeout'),
                $this->positiveIntegerConfig($config->get('services.nowpayments.timeout_seconds'), 1, 120, 'NOWPayments timeout'),
                $this->positiveIntegerConfig($config->get('services.nowpayments.max_response_bytes'), 1024, 1_048_576, 'NOWPayments response limit'),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    private function positiveIntegerConfig(mixed $value, int $minimum, int $maximum, string $label): int
    {
        $validated = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => $minimum, 'max_range' => $maximum]],
        );
        if ($validated === false) {
            throw new RuntimeException($label.' configuration is invalid.');
        }

        return $validated;
    }
}
