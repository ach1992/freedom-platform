<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Application;
use LogicException;

/**
 * Runtime authority for CONFIDENTIAL Telegram presentation construction/queueing.
 * The reviewed source set is deliberately distinct from generic non-restricted
 * presentation provenance and contains only exact reviewed private journeys.
 */
final class TelegramConfidentialPresentationProvenanceGuard
{
    /** @var list<string> */
    public const REVIEWED_SOURCE_FILES = [
        'app/Modules/Telegram/Application/TelegramCardToCardReceiptStatusDelivery.php',
        'app/Modules/Telegram/Application/TelegramNavigationHandler.php',
        'app/Modules/Telegram/Application/TelegramGiftCardNavigationHandler.php',
    ];

    /** @var list<string> */
    private const TRUSTED_TRAMPOLINE_FILES = [
        'vendor/laravel/framework/src/Illuminate/Database/Concerns/ManagesTransactions.php',
    ];

    /** @var list<string> */
    private const INTERNAL_AUTHORITY_FILES = [
        'app/Modules/Telegram/Application/ConfidentialTelegramPresentation.php',
        'app/Modules/Telegram/Application/TelegramConfidentialPresentationHasher.php',
        'app/Modules/Telegram/Application/ConfidentialTelegramPresentationFactory.php',
        'app/Modules/Telegram/Application/ConfidentialTelegramPresentationSource.php',
        'app/Modules/Telegram/Application/TelegramConfidentialPresentationProvenanceGuard.php',
        'app/Modules/Telegram/Application/TelegramDeliveryConfidentialPresentationDatabaseCapability.php',
        'app/Modules/Telegram/Application/TelegramDeliveryConfidentialPresentationService.php',
        'app/Modules/Telegram/Application/TelegramDeliveryDatabaseCapability.php',
        'app/Modules/Telegram/Application/TelegramDeliveryOperationExecutor.php',
        'app/Modules/Telegram/Application/TelegramDeliveryQueueService.php',
        'app/Modules/Telegram/Application/TelegramMutationRequest.php',
        'app/Modules/Telegram/Application/TelegramConfidentialDeliveryOutboxHandler.php',
        'app/Modules/Telegram/Application/TelegramConfidentialDeliveryQueue.php',
    ];

    public static function assertReviewedSourceCaller(): void
    {
        self::assertReviewedProductionSourceInTrace(
            debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS),
            'Confidential Telegram presentation source is not an exact reviewed production gateway.',
        );
    }

    public static function assertQueueSource(Connection $connection): void
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        if (self::isExactAutomatedTestQueueGateway($connection, $trace)) {
            return;
        }

        self::assertReviewedProductionSourceInTrace(
            $trace,
            'Confidential Telegram delivery queue authority requires an exact reviewed production source gateway.',
        );
    }

    public static function assertExactInternalCaller(string $expectedClass, string $expectedFile): void
    {
        self::assertExactInternalCallerFromTrace(
            debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3),
            [['class' => $expectedClass, 'file' => $expectedFile]],
        );
    }

    public static function assertConfidentialPlaintextRevealCaller(): void
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $directInvocation = $trace[1] ?? null;
        $callerFile = is_array($directInvocation) ? ($directInvocation['file'] ?? null) : null;
        $resolvedCaller = is_string($callerFile) ? realpath($callerFile) : false;
        $expectedFiles = [
            __DIR__.'/TelegramDeliveryConfidentialPresentationService.php',
            dirname(__DIR__).'/Infrastructure/HttpTelegramMutationTransport.php',
        ];

        foreach ($expectedFiles as $expectedFile) {
            $resolvedExpected = realpath($expectedFile);
            if ($resolvedExpected !== false
                && $resolvedCaller !== false
                && $resolvedCaller === $resolvedExpected
            ) {
                return;
            }
        }

        throw new LogicException('Confidential Telegram plaintext reveal is restricted to exact storage/provider gateways.');
    }

    /**
     * @param  list<array<string,mixed>>  $trace
     * @param  non-empty-list<array{class:string,file:string}>  $expectedGateways
     */
    private static function assertExactInternalCallerFromTrace(array $trace, array $expectedGateways): void
    {
        $directInvocation = $trace[1] ?? null;
        $gatewayFrame = $trace[2] ?? null;
        $callerClass = is_array($gatewayFrame) ? ($gatewayFrame['class'] ?? null) : null;
        $callerFile = is_array($directInvocation) ? ($directInvocation['file'] ?? null) : null;
        $resolvedCaller = is_string($callerFile) ? realpath($callerFile) : false;

        foreach ($expectedGateways as $expectedGateway) {
            $resolvedExpected = realpath($expectedGateway['file']);
            if ($callerClass === $expectedGateway['class']
                && $resolvedExpected !== false
                && $resolvedCaller !== false
                && $resolvedCaller === $resolvedExpected
            ) {
                return;
            }
        }

        throw new LogicException('Confidential Telegram internal authority is restricted to its exact reviewed gateway.');
    }

    /** @param list<array<string,mixed>> $trace */
    private static function assertReviewedProductionSourceInTrace(array $trace, string $message): void
    {
        foreach ($trace as $frame) {
            $file = $frame['file'] ?? null;
            if (! is_string($file)) {
                continue;
            }
            $relativePath = self::repositoryRelativePath($file);
            if ($relativePath === null
                || in_array($relativePath, self::INTERNAL_AUTHORITY_FILES, true)
                || in_array($relativePath, self::TRUSTED_TRAMPOLINE_FILES, true)
            ) {
                continue;
            }
            if (in_array($relativePath, self::reviewedSourceFiles(), true)) {
                return;
            }

            break;
        }

        throw new LogicException($message);
    }

    /** @param list<array<string,mixed>> $trace */
    private static function isExactAutomatedTestQueueGateway(Connection $connection, array $trace): bool
    {
        if (! self::isTestingEnvironment()
            || $connection->getDatabaseName() !== 'freedom_platform_ci'
            || ! self::traceContainsExactFile(
                $trace,
                self::repositoryRoot().'/tests/Support/ConfidentialTelegramPresentationTestFactory.php',
            )
        ) {
            return false;
        }

        try {
            $identity = $connection->selectOne(<<<'SQL'
SELECT SUBSTRING_INDEX(USER(), '@', 1) AS session_username
SQL, [], false);
        } catch (\Throwable) {
            return false;
        }

        return $identity !== null
            && hash_equals('freedom_ci', (string) ($identity->session_username ?? ''));
    }

    /** @return list<string> */
    private static function reviewedSourceFiles(): array
    {
        return self::REVIEWED_SOURCE_FILES;
    }

    private static function isTestingEnvironment(): bool
    {
        $container = Container::getInstance();

        return $container instanceof Application
            && $container->bound('env')
            && $container->environment('testing');
    }

    /** @param list<array<string,mixed>> $trace */
    private static function traceContainsExactFile(array $trace, string $expectedFile): bool
    {
        $resolvedExpected = realpath($expectedFile);
        if ($resolvedExpected === false) {
            return false;
        }
        foreach ($trace as $frame) {
            $file = $frame['file'] ?? null;
            if (! is_string($file)) {
                continue;
            }
            $resolved = realpath($file);
            if ($resolved !== false && $resolved === $resolvedExpected) {
                return true;
            }
        }

        return false;
    }

    private static function repositoryRelativePath(string $file): ?string
    {
        $root = self::repositoryRoot();
        $resolved = realpath($file);
        if ($resolved === false) {
            return null;
        }
        $prefix = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (! str_starts_with($resolved, $prefix)) {
            return null;
        }

        return str_replace('\\', '/', substr($resolved, strlen($prefix)));
    }

    private static function repositoryRoot(): string
    {
        $root = realpath(dirname(__DIR__, 4));
        if ($root === false) {
            throw new LogicException('Telegram presentation repository root is unavailable.');
        }

        return $root;
    }
}
