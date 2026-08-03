<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application\Contracts;

use Stringable;

final readonly class SensitiveDeliveryArtifacts implements Stringable
{
    /** @param list<string> $subscriptionLinks */
    public function __construct(private array $subscriptionLinks) {}

    /** @return list<string> */
    public function revealForAuthorizedDelivery(): array
    {
        return $this->subscriptionLinks;
    }

    public function __toString(): string
    {
        return '[SENSITIVE_DELIVERY_ARTIFACTS]';
    }
}
