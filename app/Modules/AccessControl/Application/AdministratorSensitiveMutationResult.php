<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Application;

final readonly class AdministratorSensitiveMutationResult
{
    public function __construct(
        public string $operation,
        public bool $changed,
        public bool $replayed,
    ) {}
}
