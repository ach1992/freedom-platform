<?php

declare(strict_types=1);

namespace App\Modules\Promotions\BenefitCodes\Infrastructure;

use App\Shared\Application\RandomGenerator;
use InvalidArgumentException;

final readonly class BenefitCodeCodec
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private RandomGenerator $random) {}

    public function generate(): string
    {
        $bytes = $this->random->bytes(24);
        if (strlen($bytes) !== 24) {
            throw new InvalidArgumentException('Benefit code random source returned an invalid byte length.');
        }
        $normalized = '';
        for ($index = 0; $index < 24; $index++) {
            $normalized .= self::ALPHABET[ord($bytes[$index]) & 31];
        }

        return $this->format($normalized);
    }

    public function normalize(string $code): string
    {
        $normalized = strtoupper(preg_replace('/[-\s]+/', '', trim($code)) ?? '');
        if (preg_match('/\A[A-HJ-NP-Z2-9]{20,64}\z/', $normalized) !== 1) {
            throw new InvalidArgumentException('Benefit code format is invalid.');
        }

        return $normalized;
    }

    public function assertOwnerChosenStrength(string $normalized): void
    {
        $letters = (int) preg_match_all('/[A-HJ-NP-Z]/', $normalized);
        $digits = (int) preg_match_all('/[2-9]/', $normalized);
        $distinct = count(array_unique(str_split($normalized)));
        if ($letters < 4 || $digits < 4 || $distinct < 10 || preg_match('/(.)\1{3}/', $normalized) === 1) {
            throw new InvalidArgumentException('Owner-chosen benefit code does not meet the strength policy.');
        }
    }

    public function mask(string $normalized): string
    {
        return substr($normalized, 0, 4).'-****-'.substr($normalized, -4);
    }

    public function format(string $normalized): string
    {
        return implode('-', str_split($normalized, 4));
    }
}
