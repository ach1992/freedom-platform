<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;
use SensitiveParameter;

final readonly class TelegramConfidentialPresentationHasher
{
    private const FINGERPRINT_CONTEXT = 'telegram-confidential-presentation-fingerprint-v1';

    private const INTEGRITY_CONTEXT = 'telegram-confidential-presentation-integrity-v1';

    /** @var non-empty-list<string> */
    private array $keyMaterials;

    /** @param array<array-key,mixed> $keyMaterials */
    public function __construct(#[SensitiveParameter] array $keyMaterials)
    {
        $normalized = [];
        foreach ($keyMaterials as $keyMaterial) {
            if (! is_string($keyMaterial) || $keyMaterial === '') {
                throw new InvalidArgumentException('Confidential Telegram presentation keyring is invalid.');
            }
            if (! in_array($keyMaterial, $normalized, true)) {
                $normalized[] = $keyMaterial;
            }
        }
        if ($normalized === []) {
            throw new InvalidArgumentException('Confidential Telegram presentation keyring is unavailable.');
        }

        $this->keyMaterials = $normalized;
    }

    public function fingerprintHash(ConfidentialTelegramPresentation $presentation): string
    {
        return $this->fingerprintHashCandidates($presentation)[0];
    }

    /** @return non-empty-list<string> */
    public function fingerprintHashCandidates(ConfidentialTelegramPresentation $presentation): array
    {
        return $this->hashCandidates($presentation, self::FINGERPRINT_CONTEXT);
    }

    public function integrityHash(
        ConfidentialTelegramPresentation $presentation,
        string $operationPublicId,
    ): string {
        return $this->integrityHashCandidates($presentation, $operationPublicId)[0];
    }

    /** @return non-empty-list<string> */
    public function integrityHashCandidates(
        ConfidentialTelegramPresentation $presentation,
        string $operationPublicId,
    ): array {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $operationPublicId) !== 1) {
            throw new InvalidArgumentException('Telegram delivery operation identity is invalid.');
        }

        return $this->hashCandidates($presentation, self::INTEGRITY_CONTEXT.':'.$operationPublicId);
    }

    /** @return non-empty-list<string> */
    private function hashCandidates(ConfidentialTelegramPresentation $presentation, string $context): array
    {
        $hashes = [];
        foreach ($this->keyMaterials as $keyMaterial) {
            $hash = $presentation->keyedHash($context, $keyMaterial);
            if (! in_array($hash, $hashes, true)) {
                $hashes[] = $hash;
            }
        }

        /** @var non-empty-list<string> $hashes */
        return $hashes;
    }
}
