<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application\Contracts;

use InvalidArgumentException;
use Stringable;

final readonly class SensitiveDeliveryArtifacts implements Stringable
{
    /** @var list<string> */
    private array $subscriptionLinks;

    /** @var list<string> */
    private array $qrSources;

    /**
     * @param array<array-key, mixed> $subscriptionLinks
     * @param array<array-key, mixed>|null $qrSources
     */
    public function __construct(array $subscriptionLinks, ?array $qrSources = null)
    {
        $links = self::normalizeList($subscriptionLinks);
        $sources = self::normalizeList($qrSources ?? $subscriptionLinks);
        if ($links === [] && $sources === []) {
            throw new InvalidArgumentException('Delivery artifacts require a subscription link or QR source.');
        }

        $this->subscriptionLinks = $links;
        $this->qrSources = $sources;
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

    /**
     * @param array<array-key, mixed> $values
     * @return list<string>
     */
    private static function normalizeList(array $values): array
    {
        if (! array_is_list($values)) {
            throw new InvalidArgumentException('Delivery artifacts must be lists.');
        }

        $normalized = [];
        foreach ($values as $value) {
            if (! is_string($value)
                || $value === ''
                || $value !== trim($value)
                || mb_strlen($value) > 8192
                || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
            ) {
                throw new InvalidArgumentException('Delivery artifact value is invalid.');
            }
            $normalized[] = $value;
        }

        return $normalized;
    }
}
