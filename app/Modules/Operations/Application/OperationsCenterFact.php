<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class OperationsCenterFact
{
    /** @var list<string> */
    private const STATES = [
        'healthy',
        'observed',
        'empty',
        'unknown',
        'degraded',
        'manual_review',
    ];

    public function __construct(
        public string $category,
        public string $code,
        public string $state,
        public int $value,
        public ?string $detail = null,
        public ?DateTimeImmutable $observedAtUtc = null,
    ) {
        if (preg_match('/\A[a-z][a-z0-9_.-]{1,63}\z/', $category) !== 1
            || preg_match('/\A[a-z][a-z0-9_.-]{2,127}\z/', $code) !== 1
            || ! in_array($state, self::STATES, true)
            || $value < 0
            || ($detail !== null
                && (mb_strlen($detail) > 191
                    || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $detail) === 1))
        ) {
            throw new InvalidArgumentException('Operations Center fact is invalid.');
        }
    }

    /** @return array{category:string,code:string,state:string,value:int,detail:?string,observed_at_utc:?string} */
    public function safeArray(): array
    {
        return [
            'category' => $this->category,
            'code' => $this->code,
            'state' => $this->state,
            'value' => $this->value,
            'detail' => $this->detail,
            'observed_at_utc' => $this->observedAtUtc?->format('Y-m-d\TH:i:s.uP'),
        ];
    }
}
