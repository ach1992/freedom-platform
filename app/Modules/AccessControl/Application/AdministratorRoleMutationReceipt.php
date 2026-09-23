<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Application;

final readonly class AdministratorRoleMutationReceipt
{
    /** @param array<string,bool|int|string|null> $before @param array<string,bool|int|string|null> $after */
    public function __construct(
        public string $action,
        public string $roleCode,
        public array $before,
        public array $after,
        public bool $changed,
        public bool $replayed = false,
    ) {}
}
