<?php

declare(strict_types=1);

namespace App\Modules\Agents\Application;

final readonly class AgentMutationReceipt
{
    /**
     * @param  array<string, bool|int|string|null>  $before
     * @param  array<string, bool|int|string|null>  $after
     */
    public function __construct(
        public string $action,
        public int $targetId,
        public array $before,
        public array $after,
        public bool $changed,
        public bool $replayed = false,
    ) {}

    public function asReplay(): self
    {
        return new self(
            $this->action,
            $this->targetId,
            $this->before,
            $this->after,
            $this->changed,
            true,
        );
    }
}