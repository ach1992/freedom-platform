<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application\Contracts;

use Stringable;

final readonly class SensitiveDeliveryArtifacts implements Stringable
{
    /** @var list<string> */
    private array $subscriptionLinks;

    /** @var list<string> */
    private array $qrSources;

    /**
     * @param list<string> $subscriptionLinks
     * @param list<string>|null $qrSources
     */
    public function __construct(array $subscriptionLinks, ?array $qrSources = null)
    {
        $this->subscriptionLinks = $subscriptionLinks;
        $this->qrSources = $qrSources ?? $subscriptionLinks;
    }

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
