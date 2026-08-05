<?php

declare(strict_types=1);

namespace App\Modules\Panels\Domain;

use InvalidArgumentException;

final readonly class TlsConfiguration
{
    public TlsPolicy $policy;

    public ?string $customCaDisk;

    public ?string $customCaPath;

    public ?string $certificatePinSha256;

    public function __construct(
        TlsPolicy $policy,
        ?string $customCaDisk,
        ?string $customCaPath,
        ?string $certificatePinSha256,
    ) {
        $disk = self::optionalIdentifier($customCaDisk);
        $path = self::optionalPath($customCaPath);
        $pin = self::optionalPin($certificatePinSha256);

        if ($policy === TlsPolicy::SystemCa && ($disk !== null || $path !== null || $pin !== null)) {
            throw new InvalidArgumentException('System CA policy cannot include custom CA or certificate pin data.');
        }
        if ($policy === TlsPolicy::CustomCa && ($disk === null || $path === null || $pin !== null)) {
            throw new InvalidArgumentException('Custom CA policy requires a protected disk and path only.');
        }
        if ($policy === TlsPolicy::CertificatePin && ($pin === null || $disk !== null || $path !== null)) {
            throw new InvalidArgumentException('Certificate pin policy requires one SHA-256 pin only.');
        }

        $this->policy = $policy;
        $this->customCaDisk = $disk;
        $this->customCaPath = $path;
        $this->certificatePinSha256 = $pin;
    }

    private static function optionalIdentifier(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $normalized = trim($value);
        if (preg_match('/\A[a-z0-9_.-]{1,64}\z/', $normalized) !== 1) {
            throw new InvalidArgumentException('TLS storage disk is invalid.');
        }

        return $normalized;
    }

    private static function optionalPath(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $normalized = trim($value);
        if (mb_strlen($normalized) > 512
            || str_starts_with($normalized, '/')
            || str_contains($normalized, '..')
            || preg_match('/[\x00-\x1F\x7F]/', $normalized) === 1
        ) {
            throw new InvalidArgumentException('TLS custom CA path is invalid.');
        }

        return $normalized;
    }

    private static function optionalPin(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $normalized = strtolower(trim($value));
        if (preg_match('/\A[a-f0-9]{64}\z/', $normalized) !== 1) {
            throw new InvalidArgumentException('TLS certificate pin is invalid.');
        }

        return $normalized;
    }
}
