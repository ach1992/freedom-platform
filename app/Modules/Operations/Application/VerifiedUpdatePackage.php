<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

final readonly class VerifiedUpdatePackage
{
    /**
     * @param  array<string, string>  $payloadChecksums
     * @param  list<string>  $rollbackCompatibleSchemaSha256
     */
    public function __construct(
        public string $packagePath,
        public string $packageSha256,
        public string $manifestSha256,
        public string $releaseId,
        public string $applicationVersion,
        public string $fromRelease,
        public string $fromApplicationVersion,
        public string $fromSchemaSha256,
        public string $toSchemaSha256,
        public array $rollbackCompatibleSchemaSha256,
        public string $composerLockSha256,
        public int $laravelMajor,
        public string $phpMinimum,
        public string $phpMaximumExclusive,
        public int $requiredFreeBytes,
        public int $payloadBytes,
        public string $checksumsSha256,
        public array $payloadChecksums,
    ) {}

    public function previousCodeCompatibleWith(string $schemaSha256): bool
    {
        foreach ($this->rollbackCompatibleSchemaSha256 as $compatible) {
            if (hash_equals($compatible, $schemaSha256)) {
                return true;
            }
        }

        return false;
    }
}
