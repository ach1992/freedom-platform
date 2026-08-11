<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

final readonly class TrialActorSnapshot
{
    /** @param list<int> $tagIds */
    public function __construct(
        public int $userId,
        public ?string $tierCode,
        public array $tagIds,
        public ?int $phoneNumberId,
        public bool $telegramContactVerified,
        public bool $smsOtpVerified,
        public string $eligibilityHash,
    ) {}
}
