<?php

declare(strict_types=1);

namespace App\Modules\Installer\Infrastructure;

use App\Modules\Installer\Application\InstallerAccessTokenStore;
use App\Modules\Installer\Application\InstallerBootstrapJournal;
use App\Modules\Installer\Application\InstallerBootstrapOrchestrator;
use App\Modules\Installer\Application\InstallerEnvironmentBootstrapper;
use App\Modules\Installer\Application\InstallerEnvironmentWriter;
use App\Modules\Installer\Application\InstallerLock;
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

        $this->app->singleton(
            InstallerBootstrapJournal::class,
            fn (): InstallerBootstrapJournal => new InstallerBootstrapJournal(
                (string) config('installer.bootstrap_journal_path'),
            ),
        );

        $this->app->singleton(
            InstallerLock::class,
            fn (): InstallerLock => new InstallerLock((string) config('installer.lock_path')),
        );

        $this->app->singleton(
            InstallerEnvironmentWriter::class,
            fn (Application $application): InstallerEnvironmentWriter => new InstallerEnvironmentWriter(
                $application->make(RandomGenerator::class),
                (string) config('installer.environment.file_path'),
                (string) config('installer.environment.snapshot_path'),
                $this->stringList(config('installer.environment.allowed_keys', [])),
            ),
        );

        $this->app->singleton(
            InstallerBootstrapOrchestrator::class,
            fn (Application $application): InstallerBootstrapOrchestrator => new InstallerBootstrapOrchestrator(
                $application->make(InstallerBootstrapJournal::class),
                $application->make(InstallerLock::class),
            ),
        );

        $this->app->singleton(
            InstallerEnvironmentBootstrapper::class,
            fn (Application $application): InstallerEnvironmentBootstrapper => new InstallerEnvironmentBootstrapper(
                $application->make(InstallerEnvironmentWriter::class),
                $application->make(InstallerBootstrapOrchestrator::class),
                $application->make(InstallerBootstrapJournal::class),
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

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $items[] = $item;
            }
        }

        return array_values(array_unique($items));
    }
}
