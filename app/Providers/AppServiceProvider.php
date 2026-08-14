<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Payments\CardToCard\Application\Contracts\CardToCardAdjustmentGenerator;
use App\Modules\Payments\CardToCard\Infrastructure\SecureCardToCardAdjustmentGenerator;
use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalTransport;
use App\Modules\Payments\Zarinpal\Infrastructure\HttpZarinpalTransport;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(CardToCardAdjustmentGenerator::class, SecureCardToCardAdjustmentGenerator::class);
        $this->app->bind(ZarinpalTransport::class, HttpZarinpalTransport::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
