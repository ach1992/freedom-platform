<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure;

use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use App\Modules\Identity\Application\Contracts\OtpAbuseLimiter;
use App\Modules\Identity\Application\Contracts\OtpCodeHasher;
use App\Modules\Identity\Application\Contracts\PhoneLookupHasher;
use App\Modules\Identity\Application\Contracts\SmsDeliveryAttemptRecorder;
use App\Modules\Identity\Application\Contracts\SmsOtpMessageRenderer;
use App\Modules\Identity\Application\Contracts\SmsProvider;
use App\Modules\Identity\Application\FallbackSmsDispatcher;
use App\Modules\Identity\Application\IdentityItemService;
use App\Modules\Identity\Application\IdentityMutationAudit;
use App\Modules\Identity\Application\OtpChallengeIssuer;
use App\Modules\Identity\Application\OtpChallengeVerifier;
use App\Shared\Application\Clock;
use App\Shared\Application\RandomGenerator;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Client\Factory;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerHashers();

        $this->app->singleton(
            IdentityMutationAudit::class,
            fn (Application $application): IdentityMutationAudit => new IdentityMutationAudit(
                $application->make(DatabaseManager::class),
                $application->make(Clock::class),
            ),
        );

        $this->app->singleton(
            IdentityItemService::class,
            function (Application $application): IdentityItemService {
                $configuration = self::identityConfiguration($application);
                $items = is_array($configuration['identity_items'] ?? null)
                    ? $configuration['identity_items']
                    : [];

                return new IdentityItemService(
                    $application->make(DatabaseManager::class),
                    $application->make(StringEncrypter::class),
                    $application->make(PhoneLookupHasher::class),
                    $application->make(AdministratorPermissionAuthorizer::class),
                    $application->make(IdentityMutationAudit::class),
                    $application->make(Clock::class),
                    self::positiveInteger($items['hash_key_version'] ?? 1, 1),
                    self::stringList($items['required_types'] ?? null, ['national_id', 'full_name']),
                );
            },
        );

        $this->app->singleton(
            OtpAbuseLimiter::class,
            function (Application $application): OtpAbuseLimiter {
                $configuration = self::identityConfiguration($application);
                $otp = is_array($configuration['otp'] ?? null) ? $configuration['otp'] : [];
                $prefix = $otp['redis_prefix'] ?? 'freedom:otp-limit:';

                return new RedisOtpAbuseLimiter(
                    $application->make(RedisManager::class),
                    is_string($prefix) ? $prefix : 'freedom:otp-limit:',
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

        $this->app->singleton(
            SmsOtpMessageRenderer::class,
            fn (Application $application): SmsOtpMessageRenderer => new LocalizedSmsOtpMessageRenderer(
                $application->make(Translator::class),
            ),
        );

        $this->app->singleton(
            FallbackSmsDispatcher::class,
            function (Application $application): FallbackSmsDispatcher {
                $configuration = self::identityConfiguration($application);
                $sms = is_array($configuration['sms'] ?? null) ? $configuration['sms'] : [];
                $providerDailyLimit = self::positiveInteger($sms['provider_daily_limit'] ?? 10000, 10000);
                $primaryCode = self::providerCode($sms['primary_provider'] ?? 'fake_primary', 'fake_primary');
                $fallbackCode = self::providerCode($sms['fallback_provider'] ?? 'fake_fallback', 'fake_fallback');
                $limiter = $application->make(OtpAbuseLimiter::class);

                return new FallbackSmsDispatcher(
                    new RateLimitedSmsProvider(
                        self::smsProvider($application, $sms, $primaryCode),
                        $limiter,
                        $providerDailyLimit,
                    ),
                    new RateLimitedSmsProvider(
                        self::smsProvider($application, $sms, $fallbackCode),
                        $limiter,
                        $providerDailyLimit,
                    ),
                    $application->make(SmsDeliveryAttemptRecorder::class),
                );
            },
        );

        $this->app->singleton(
            OtpChallengeIssuer::class,
            function (Application $application): OtpChallengeIssuer {
                $configuration = self::identityConfiguration($application);
                $otp = is_array($configuration['otp'] ?? null) ? $configuration['otp'] : [];

                return new OtpChallengeIssuer(
                    $application->make(DatabaseManager::class),
                    $application->make(StringEncrypter::class),
                    $application->make(PhoneLookupHasher::class),
                    $application->make(OtpCodeHasher::class),
                    $application->make(OtpAbuseLimiter::class),
                    $application->make(FallbackSmsDispatcher::class),
                    $application->make(RandomGenerator::class),
                    $application->make(Clock::class),
                    self::positiveInteger($otp['ttl_seconds'] ?? 120, 120),
                    self::positiveInteger($otp['resend_cooldown_seconds'] ?? 60, 60),
                    self::positiveInteger($otp['maximum_attempts'] ?? 5, 5),
                    self::positiveInteger($otp['daily_phone_limit'] ?? 10, 10),
                    self::positiveInteger($otp['daily_telegram_account_limit'] ?? 10, 10),
                    self::positiveInteger($otp['daily_ip_limit'] ?? 20, 20),
                );
            },
        );

        $this->app->singleton(
            OtpChallengeVerifier::class,
            fn (Application $application): OtpChallengeVerifier => new OtpChallengeVerifier(
                $application->make(DatabaseManager::class),
                $application->make(OtpCodeHasher::class),
                $application->make(Clock::class),
            ),
        );
    }

    private function registerHashers(): void
    {
        $this->app->singleton(
            PhoneLookupHasher::class,
            function (Application $application): PhoneLookupHasher {
                $configuration = self::identityConfiguration($application);

                return new HmacPhoneLookupHasher(
                    self::resolveKey(
                        $configuration['phone_lookup_key'] ?? null,
                        self::applicationKey($application),
                        'freedom-platform/phone-lookup/v1',
                    ),
                    self::positiveInteger($configuration['phone_lookup_key_version'] ?? 1, 1),
                );
            },
        );

        $this->app->singleton(
            OtpCodeHasher::class,
            function (Application $application): OtpCodeHasher {
                $configuration = self::identityConfiguration($application);
                $otp = is_array($configuration['otp'] ?? null) ? $configuration['otp'] : [];

                return new HmacOtpCodeHasher(
                    self::resolveKey(
                        $otp['hash_key'] ?? null,
                        self::applicationKey($application),
                        'freedom-platform/otp-code/v1',
                    ),
                    self::positiveInteger($otp['hash_key_version'] ?? 1, 1),
                );
            },
        );
    }

    /** @param array<string, mixed> $sms */
    private static function smsProvider(Application $application, array $sms, string $providerCode): SmsProvider
    {
        if ($providerCode === 'fake_primary' || $providerCode === 'fake_fallback') {
            return new FakeSmsProvider($providerCode);
        }

        $providers = is_array($sms['providers'] ?? null) ? $sms['providers'] : [];
        $providerConfiguration = $providers[$providerCode] ?? null;
        if (! is_array($providerConfiguration)) {
            throw new RuntimeException('Selected SMS provider configuration is missing.');
        }
        if (! self::boolean($providerConfiguration['enabled'] ?? false)) {
            throw new RuntimeException('Selected SMS provider is disabled.');
        }

        $timeoutSeconds = self::positiveInteger($sms['timeout_seconds'] ?? 15, 15);
        $http = $application->make(Factory::class);
        $renderer = $application->make(SmsOtpMessageRenderer::class);

        return match ($providerCode) {
            'melli_payamak' => new MelliPayamakSmsProvider(
                $http,
                MelliPayamakSmsConfiguration::fromArray($providerConfiguration, $timeoutSeconds),
                $renderer,
            ),
            'kavenegar' => new KavenegarSmsProvider(
                $http,
                KavenegarSmsConfiguration::fromArray($providerConfiguration, $timeoutSeconds),
                $renderer,
            ),
            default => throw new RuntimeException('Selected SMS provider is unsupported.'),
        };
    }

    /** @return array<string, mixed> */
    private static function identityConfiguration(Application $application): array
    {
        $configuration = $application->make(Repository::class)->get('identity');

        return is_array($configuration) ? $configuration : [];
    }

    private static function applicationKey(Application $application): string
    {
        $key = $application->make(Repository::class)->get('app.key');
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('Application key is required for identity hashing.');
        }

        return $key;
    }

    private static function resolveKey(mixed $configuredKey, string $applicationKey, string $context): string
    {
        if (is_string($configuredKey) && trim($configuredKey) !== '') {
            $key = trim($configuredKey);
            if (str_starts_with($key, 'base64:')) {
                $decoded = base64_decode(substr($key, 7), true);
                if (! is_string($decoded)) {
                    throw new RuntimeException('Configured identity key is not valid base64.');
                }

                return $decoded;
            }

            return $key;
        }

        return hash_hkdf('sha256', $applicationKey, 32, $context);
    }

    private static function providerCode(mixed $value, string $default): string
    {
        $providerCode = is_string($value) ? trim($value) : $default;
        if (preg_match('/\A[a-z0-9_-]{2,64}\z/', $providerCode) !== 1) {
            throw new RuntimeException('Configured SMS provider code is invalid.');
        }

        return $providerCode;
    }

    private static function boolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }

        return is_string($value) && in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function positiveInteger(mixed $value, int $default): int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }

    /**
     * @param  list<string>  $default
     * @return list<string>
     */
    private static function stringList(mixed $value, array $default): array
    {
        if (! is_array($value)) {
            return $default;
        }

        $result = [];
        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                throw new RuntimeException('Configured identity item type list is invalid.');
            }
            $result[] = trim($item);
        }

        return $result;
    }
}
