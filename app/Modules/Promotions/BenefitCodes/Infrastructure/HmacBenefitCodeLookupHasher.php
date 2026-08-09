<?php

declare(strict_types=1);

namespace App\Modules\Promotions\BenefitCodes\Infrastructure;

use Illuminate\Contracts\Config\Repository;
use RuntimeException;

final readonly class HmacBenefitCodeLookupHasher
{
    private const CONTEXT = 'freedom-platform/benefit-code-lookup/v1';

    public function __construct(private Repository $config) {}

    public function hash(string $normalizedCode): string
    {
        return hash_hmac('sha256', $normalizedCode, $this->key());
    }

    public function keyVersion(): int
    {
        return 1;
    }

    private function key(): string
    {
        $applicationKey = $this->config->get('app.key');
        if (! is_string($applicationKey) || $applicationKey === '') {
            throw new RuntimeException('Benefit code lookup key material is unavailable.');
        }

        $key = hash_hkdf('sha256', $applicationKey, 32, self::CONTEXT);
        if (strlen($key) !== 32) {
            throw new RuntimeException('Benefit code lookup key derivation failed.');
        }

        return $key;
    }
}
