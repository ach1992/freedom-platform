<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

final readonly class CustomerMutationReceipt
{
    /**
     * @param  array<string, bool|int|string|null>  $before
     * @param  array<string, bool|int|string|null>  $after
     */
    public function __construct(
        public string $action,
        public int $userId,
        public array $before,
        public array $after,
        public bool $changed,
        public bool $replayed = false,
    ) {}

    public function asReplay(): self
    {
        return new self(
            $this->action,
            $this->userId,
            $this->before,
            $this->after,
            $this->changed,
            true,
        );
    }
}
