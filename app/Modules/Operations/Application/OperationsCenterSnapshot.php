<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class OperationsCenterSnapshot
{
    /** @param list<OperationsCenterFact> $facts */
    public function __construct(
        public DateTimeImmutable $generatedAtUtc,
        public array $facts,
    ) {
        if ($facts === [] || count($facts) > 200) {
            throw new InvalidArgumentException('Operations Center snapshot fact count is invalid.');
        }

        $codes = [];
        foreach ($facts as $fact) {
            if (isset($codes[$fact->code])) {
                throw new InvalidArgumentException('Operations Center fact codes must be unique.');
            }
            $codes[$fact->code] = true;
        }
    }

    public function fact(string $code): ?OperationsCenterFact
    {
        foreach ($this->facts as $fact) {
            if ($fact->code === $code) {
                return $fact;
            }
        }

        return null;
    }

    public function fingerprint(): string
    {
        $facts = array_map(
            static fn (OperationsCenterFact $fact): array => $fact->safeArray(),
            $this->facts,
        );

        return hash('sha256', json_encode([
            'generated_at_utc' => $this->generatedAtUtc->format('Y-m-d\TH:i:s.uP'),
            'facts' => $facts,
        ], JSON_THROW_ON_ERROR));
    }
}
