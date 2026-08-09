<?php

declare(strict_types=1);

namespace App\Modules\Promotions\BenefitCodes\Infrastructure;

use Illuminate\Contracts\Config\Repository;
use RuntimeException;

final readonly class HmacBenefitCodeLookupHasher
{
    private const MINIMUM_KEY_BYTES = 32;

    public function __construct(private Repository $config) {}

    /** @return array{lookup_hash:string,key_version:int} */
    public function currentHash(string $normalizedCode): array
    {
        $version = $this->currentVersion();

        return [
            'lookup_hash' => $this->hashForVersion($normalizedCode, $version),
            'key_version' => $version,
        ];
    }

    public function currentVersion(): int
    {
        return $this->lookupConfiguration()['current_version'];
    }

    /** @return array<int,string> */
    public function supportedHashes(string $normalizedCode): array
    {
        $hashes = [];
        foreach ($this->lookupConfiguration()['keys'] as $version => $key) {
            $hashes[$version] = hash_hmac('sha256', $normalizedCode, $key);
        }

        return $hashes;
    }

    public function matches(string $normalizedCode, int $keyVersion, string $storedHash): bool
    {
        return hash_equals($storedHash, $this->hashForVersion($normalizedCode, $keyVersion));
    }

    public function hashForVersion(string $normalizedCode, int $keyVersion): string
    {
        $keys = $this->lookupConfiguration()['keys'];
        $key = $keys[$keyVersion] ?? null;
        if ($key === null) {
            throw new RuntimeException('Benefit code lookup key version is unsupported.');
        }

        return hash_hmac('sha256', $normalizedCode, $key);
    }

    /** @return array{current_version:int,keys:array<int,string>} */
    private function lookupConfiguration(): array
    {
        $currentVersion = $this->positiveVersion(
            $this->config->get('benefit_codes.lookup.current.version'),
            'Benefit code current lookup key version',
        );
        $currentKey = $this->keyMaterial(
            $this->config->get('benefit_codes.lookup.current.key'),
            'Benefit code current lookup key',
        );

        $keys = [$currentVersion => $currentKey];
        $previousVersionValue = $this->config->get('benefit_codes.lookup.previous.version');
        $previousKeyValue = $this->config->get('benefit_codes.lookup.previous.key');
        $previousVersionMissing = $previousVersionValue === null || $previousVersionValue === '';
        $previousKeyMissing = $previousKeyValue === null || $previousKeyValue === '';
        if ($previousVersionMissing !== $previousKeyMissing) {
            throw new RuntimeException('Benefit code previous lookup key configuration is incomplete.');
        }
        if (! $previousVersionMissing) {
            $previousVersion = $this->positiveVersion($previousVersionValue, 'Benefit code previous lookup key version');
            if ($previousVersion === $currentVersion) {
                throw new RuntimeException('Benefit code lookup key versions must be distinct.');
            }
            $keys[$previousVersion] = $this->keyMaterial($previousKeyValue, 'Benefit code previous lookup key');
        }

        return [
            'current_version' => $currentVersion,
            'keys' => $keys,
        ];
    }

    private function positiveVersion(mixed $value, string $label): int
    {
        if ((! is_int($value) && ! is_string($value)) || ! ctype_digit((string) $value) || (int) $value < 1) {
            throw new RuntimeException($label.' is invalid.');
        }

        return (int) $value;
    }

    private function keyMaterial(mixed $value, string $label): string
    {
        if (! is_string($value) || strlen($value) < self::MINIMUM_KEY_BYTES) {
            throw new RuntimeException($label.' material is unavailable.');
        }

        return $value;
    }
}
