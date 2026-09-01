<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

final readonly class TelegramResolvedConfidentialPresentation
{
    public function __construct(
        public ConfidentialTelegramPresentation $presentation,
        public string $presentationHash,
    ) {}

    /** @return array{redacted:true,type:string} */
    public function __debugInfo(): array
    {
        return ['redacted' => true, 'type' => 'resolved_confidential_presentation'];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('Resolved confidential Telegram presentations cannot be serialized.');
    }

    /** @param array<array-key,mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Resolved confidential Telegram presentations cannot be unserialized.');
    }
}
