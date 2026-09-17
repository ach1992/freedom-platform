<?php

declare(strict_types=1);

namespace App\Modules\Support\Application;

final readonly class SupportTicketCategoryManagementSnapshot
{
    public function __construct(
        public int $id,
        public string $code,
        public string $nameFa,
        public string $nameEn,
        public ?string $routeRoleCode,
        public int $sortOrder,
        public bool $isActive,
        public string $updatedAt,
    ) {}
}
