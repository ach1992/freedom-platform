<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use LogicException;

/**
 * Exact runtime provenance for resolving administrator direct-message private
 * media. This boundary is intentionally separate from the generic
 * non-restricted Telegram presentation authority.
 */
final class TelegramPrivateMediaDeliveryProvenanceGuard
{
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

    private static function assertExactInternalCaller(string $expectedClass, string $expectedFile): void
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $directInvocation = $trace[1] ?? null;
        $gatewayFrame = $trace[2] ?? null;
        $callerClass = is_array($gatewayFrame) ? ($gatewayFrame['class'] ?? null) : null;
        $callerFile = is_array($directInvocation) ? ($directInvocation['file'] ?? null) : null;
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
}
