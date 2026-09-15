<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application;

use InvalidArgumentException;

final readonly class CapacityOperationContext
{
    public string $commandKey;

    public string $sourceCode;

    public string $purposeCode;

    public string $reasonCode;

    public function __construct(
        string $commandKey,
        public string $correlationId,
        string $sourceCode,
        string $purposeCode,
        string $reasonCode,
    ) {
        $this->commandKey = CapacityInput::commandKey($commandKey);
        $this->sourceCode = CapacityInput::code($sourceCode, 'Capacity source code');
        $this->purposeCode = CapacityInput::code($purposeCode, 'Capacity purpose code');
        $this->reasonCode = CapacityInput::code($reasonCode, 'Capacity reason code');

        if (trim($correlationId) === '' || strlen($correlationId) > 64) {
            throw new InvalidArgumentException('Capacity correlation ID is invalid.');
        }
    }
}
