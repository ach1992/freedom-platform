<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use LogicException;

/**
 * Exact runtime provenance for queueing and resolving administrator
 * direct-message private media. This authority is intentionally separate from
 * the generic non-restricted Telegram presentation boundary.
 */
final class TelegramPrivateMediaDeliveryProvenanceGuard
{
    /** @var list<string> */
    private const QUEUE_REVIEWED_SOURCE_FILES = [
        'app/Modules/Telegram/Application/TelegramAdministratorDirectMediaMessageService.php',
    ];

    /** @var list<string> */
    private const QUEUE_INTERNAL_AUTHORITY_FILES = [
        'app/Modules/Telegram/Application/TelegramDeliveryDatabaseCapability.php',
        'app/Modules/Telegram/Application/TelegramDeliveryQueueService.php',
        'app/Modules/Telegram/Application/TelegramMutationRequest.php',
        'app/Modules/Telegram/Application/TelegramPrivateMediaDeliveryProvenanceGuard.php',
        'app/Modules/Telegram/Application/TelegramPrivateMediaDeliveryQueue.php',
    ];

    /** @var list<string> */
    private const TRUSTED_TRAMPOLINE_FILES = [
        'vendor/laravel/framework/src/Illuminate/Database/Concerns/ManagesTransactions.php',
    ];

    public static function assertQueueSource(): void
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $file = $frame['file'] ?? null;
            if (! is_string($file)) {
                continue;
            }

            $relativePath = self::repositoryRelativePath($file);
            if ($relativePath === null
                || in_array($relativePath, self::QUEUE_INTERNAL_AUTHORITY_FILES, true)
                || in_array($relativePath, self::TRUSTED_TRAMPOLINE_FILES, true)
            ) {
                continue;
            }

            if (in_array($relativePath, self::QUEUE_REVIEWED_SOURCE_FILES, true)) {
                return;
            }

            break;
        }

        throw new LogicException(
            'Private Telegram media delivery queue requires the exact administrator direct-media authority.',
        );
    }

    public static function assertExecutorCaller(): void
    {
        self::assertExactInternalCaller(
            TelegramDeliveryOperationExecutor::class,
            __DIR__.'/TelegramDeliveryOperationExecutor.php',
        );
    }

    public static function assertDirectMessageFacadeCaller(): void
    {
        self::assertExactInternalCaller(
            TelegramAdministratorDirectMessageService::class,
            __DIR__.'/TelegramAdministratorDirectMessageService.php',
        );
    }

    public static function assertDirectMediaAuthorityCaller(): void
    {
        self::assertExactInternalCaller(
            TelegramAdministratorDirectMediaMessageService::class,
            __DIR__.'/TelegramAdministratorDirectMediaMessageService.php',
        );
    }

    private static function assertExactInternalCaller(string $expectedClass, string $expectedFile): void
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4);
        $guardedFrame = $trace[2] ?? null;
        $callerFrame = $trace[3] ?? null;
        $callerClass = is_array($callerFrame) ? ($callerFrame['class'] ?? null) : null;
        $callerFile = is_array($guardedFrame) ? ($guardedFrame['file'] ?? null) : null;
        $resolvedExpected = realpath($expectedFile);
        $resolvedCaller = is_string($callerFile) ? realpath($callerFile) : false;

        if ($callerClass !== $expectedClass
            || $resolvedExpected === false
            || $resolvedCaller === false
            || $resolvedCaller !== $resolvedExpected
        ) {
            throw new LogicException(
                'Private Telegram media delivery resolution is restricted to its exact reviewed gateway.',
            );
        }
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
            throw new LogicException('Private Telegram media delivery repository root is unavailable.');
        }

        return $root;
    }
}
