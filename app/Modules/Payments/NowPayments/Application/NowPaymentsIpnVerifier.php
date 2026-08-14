<?php

declare(strict_types=1);

namespace App\Modules\Payments\NowPayments\Application;

use DomainException;
use RuntimeException;

final readonly class NowPaymentsIpnVerifier
{
    public function __construct(
        private string $secret,
        private int $maxBodyBytes = 262_144,
    ) {
        if (trim($this->secret) === '') {
            throw new DomainException('NOWPayments IPN secret is required.');
        }
        if ($this->maxBodyBytes < 1024 || $this->maxBodyBytes > 1_048_576) {
            throw new DomainException('NOWPayments IPN body limit is invalid.');
        }
    }

    /** @return array<string,mixed> */
    public function verify(string $rawBody, string $signature): array
    {
        if ($rawBody === '' || strlen($rawBody) > $this->maxBodyBytes) {
            throw new DomainException('NOWPayments IPN body is invalid.');
        }
        $signature = strtolower(trim($signature));
        if (preg_match('/\A[a-f0-9]{128}\z/', $signature) !== 1) {
            throw new DomainException('NOWPayments IPN signature shape is invalid.');
        }

        $payload = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($payload) || array_is_list($payload)) {
            throw new DomainException('NOWPayments IPN payload is invalid.');
        }
        $canonical = json_encode(
            $this->sortRecursively($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
        $expected = hash_hmac('sha512', $canonical, trim($this->secret));
        if (! hash_equals($expected, $signature)) {
            throw new DomainException('NOWPayments IPN signature verification failed.');
        }

        return $payload;
    }

    public function paymentId(array $payload): string
    {
        $value = $payload['payment_id'] ?? null;
        if (! is_int($value) && ! is_string($value)) {
            throw new RuntimeException('NOWPayments IPN payment ID is missing.');
        }
        $normalized = (string) $value;
        if (preg_match('/\A[1-9][0-9]{0,63}\z/', $normalized) !== 1) {
            throw new RuntimeException('NOWPayments IPN payment ID is invalid.');
        }

        return $normalized;
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortRecursively($item), $value);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->sortRecursively($item);
        }
        ksort($value, SORT_STRING);

        return $value;
    }
}
