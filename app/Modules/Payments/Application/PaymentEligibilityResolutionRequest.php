<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Payments\Application\Contracts\ProviderHealth;
use App\Modules\Payments\Domain\PaymentEligibilityAction;
use InvalidArgumentException;

final readonly class PaymentEligibilityResolutionRequest
{
    /**
     * @param array<string, ProviderHealth> $healthByMethodCode
     */
    public function __construct(
        public string $decisionKey,
        public int $userId,
        public string $quotePublicId,
        public PaymentEligibilityAction $action,
        public array $healthByMethodCode,
    ) {
        if (preg_match('/\A[A-Za-z0-9:_.-]{8,128}\z/', $decisionKey) !== 1) {
            throw new InvalidArgumentException('Payment eligibility decision key is invalid.');
        }
        if ($userId < 1) {
            throw new InvalidArgumentException('Payment eligibility user ID must be positive.');
        }
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $quotePublicId) !== 1) {
            throw new InvalidArgumentException('Payment eligibility quote public ID is invalid.');
        }
        foreach ($healthByMethodCode as $methodCode => $health) {
            if (! is_string($methodCode) || preg_match('/\A[a-z0-9_.-]{1,64}\z/', $methodCode) !== 1) {
                throw new InvalidArgumentException('Payment eligibility health method code is invalid.');
            }
            if (! $health instanceof ProviderHealth) {
                throw new InvalidArgumentException('Payment eligibility health input must be normalized ProviderHealth.');
            }
        }
    }

    /** @return array<string, string> */
    public function normalizedHealth(): array
    {
        $normalized = [];
        foreach ($this->healthByMethodCode as $methodCode => $health) {
            $normalized[$methodCode] = $health->value;
        }
        ksort($normalized, SORT_STRING);

        return $normalized;
    }
}
