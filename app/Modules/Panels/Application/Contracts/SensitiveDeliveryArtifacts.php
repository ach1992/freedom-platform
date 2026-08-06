<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application\Contracts;

use Stringable;

final readonly class SensitiveDeliveryArtifacts implements Stringable
{
    /**
     * @param list<string> $subscriptionLinks
     * @param list<string> $qrSources
     */
    public function __construct(
        private array $subscriptionLinks,
        private array $qrSources = [],
    ) {}

    /**
     * Compatibility accessor for authorized subscription delivery.
     *
     * @return list<string>
     */
    public function revealForAuthorizedDelivery(): array
    {
        return $this->subscriptionLinks;
    }

    /** @return list<string> */
    public function revealQrSourcesForAuthorizedDelivery(): array
    {
        return $this->qrSources;
    }

    /** @return array{redacted: true} */
    public function __debugInfo(): array
    {
        return ['redacted' => true];
    }

    public function __toString(): string
    {
        return '[SENSITIVE_DELIVERY_ARTIFACTS]';
    }
}
