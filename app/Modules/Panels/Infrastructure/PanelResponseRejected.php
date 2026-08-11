<?php

declare(strict_types=1);

namespace App\Modules\Panels\Infrastructure;

use RuntimeException;

final class PanelResponseRejected extends RuntimeException
{
    public function __construct(
        public readonly PanelHttpFailureType $failure,
        string $safeMessage,
    ) {
        parent::__construct($safeMessage);
    }
}
