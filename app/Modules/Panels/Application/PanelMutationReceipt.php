<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application;

final readonly class PanelMutationReceipt
{
    /**
     * @param array<string, bool|int|string|null> $before
     * @param array<string, bool|int|string|null> $after
     */
    public function __construct(
        public string $action,
        public string $targetType,
        public string $targetId,
        public array $before,
        public array $after,
        public bool $changed,
        public bool $replayed = false,
    ) {}
}
