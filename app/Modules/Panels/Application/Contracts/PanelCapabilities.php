<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application\Contracts;

final readonly class PanelCapabilities
{
    /**
     * @param list<string> $operations
     * @param list<string> $protocolProfiles
     */
    public function __construct(
        public string $panelType,
        public string $panelVersion,
        public array $operations,
        public array $protocolProfiles,
    ) {}

    public function supports(string $operation): bool
    {
        return in_array($operation, $this->operations, true);
    }
}
