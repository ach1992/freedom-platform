<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Logging;

use Illuminate\Log\Logger;
use LogicException;
use Monolog\Logger as MonologLogger;

final class RedactSensitiveLogs
{
    public function __invoke(Logger $logger): void
    {
        $underlying = $logger->getLogger();

        if (! $underlying instanceof MonologLogger) {
            throw new LogicException('Sensitive log redaction requires a Monolog channel.');
        }

        $underlying->pushProcessor(new RedactSensitiveDataProcessor);
    }
}
