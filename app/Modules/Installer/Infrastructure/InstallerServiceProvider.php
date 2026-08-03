<?php

declare(strict_types=1);

namespace App\Modules\Installer\Infrastructure;

use App\Modules\Installer\Application\InstallerAccessTokenStore;
use App\Shared\Application\Clock;
use App\Shared\Application\RandomGenerator;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class InstallerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            InstallerAccessTokenStore::class,
            fn (Application $application): InstallerAccessTokenStore => new InstallerAccessTokenStore(
                $application->make(Clock::class),
                $application->make(RandomGenerator::class),
                (string) config('installer.access_file'),
            ),
        );
    }

    public function boot(): void
    {
        RateLimiter::for(
            'installer',
            fn (Request $request): Limit => Limit::perMinute(5)->by($request->ip() ?? 'unknown'),
        );
    }
}
