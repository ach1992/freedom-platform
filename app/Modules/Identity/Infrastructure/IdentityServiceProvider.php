<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure;

use App\Modules\Identity\Application\Contracts\PhoneLookupHasher;
use App\Modules\Identity\Application\Contracts\SmsDeliveryAttemptRecorder;
use App\Shared\Application\Clock;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            PhoneLookupHasher::class,
            function (Application $application): PhoneLookupHasher {
                $repository = $application->make(Repository::class);
                $configuration = $repository->get('identity');
                $applicationKey = $repository->get('app.key');
                $configuredKey = is_array($configuration) ? ($configuration['phone_lookup_key'] ?? null) : null;
                $configuredVersion = is_array($configuration)
                    ? ($configuration['phone_lookup_key_version'] ?? 1)
                    : 1;

                return new HmacPhoneLookupHasher(
                    self::resolveLookupKey($configuredKey, $applicationKey),
                    is_numeric($configuredVersion) ? (int) $configuredVersion : 1,
                );
            },
        );

        $this->app->singleton(
            SmsDeliveryAttemptRecorder::class,
            fn (Application $application): SmsDeliveryAttemptRecorder => new DatabaseSmsDeliveryAttemptRecorder(
                $application->make(DatabaseManager::class),
                $application->make(StringEncrypter::class),
                $application->make(PhoneLookupHasher::class),
                $application->make(Clock::class),
            ),
        );
    }

    private static function resolveLookupKey(mixed $configuredKey, mixed $applicationKey): string
    {
        if (is_string($configuredKey) && trim($configuredKey) !== '') {
            $key = trim($configuredKey);

            if (str_starts_with($key, 'base64:')) {
                $decoded = base64_decode(substr($key, 7), true);

                if (! is_string($decoded)) {
                    throw new RuntimeException('Configured phone lookup key is not valid base64.');
                }

                return $decoded;
            }

            return $key;
        }

        if (! is_string($applicationKey) || $applicationKey === '') {
            throw new RuntimeException('Application key is required to derive the phone lookup key.');
        }

        return hash_hkdf('sha256', $applicationKey, 32, 'freedom-platform/phone-lookup/v1');
    }
}
