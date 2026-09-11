<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use LogicException;

/**
 * Exact reveal authority for restricted material carried by transient protected
 * Telegram presentations. Durable/reference code must never reveal this data.
 */
final class TelegramProtectedPresentationProvenanceGuard
{
    public static function assertHttpsUrlRevealCaller(): void
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $directInvocation = $trace[1] ?? null;
        $callerFile = is_array($directInvocation) ? ($directInvocation['file'] ?? null) : null;
        $resolvedCaller = is_string($callerFile) ? realpath($callerFile) : false;
        $resolvedExpected = realpath(dirname(__DIR__).'/Infrastructure/HttpProtectedTelegramMessageSender.php');

        if ($resolvedCaller !== false
            && $resolvedExpected !== false
            && $resolvedCaller === $resolvedExpected) {
            return;
        }

        throw new LogicException('Protected Telegram HTTPS URL reveal is restricted to the exact provider gateway.');
    }
}
