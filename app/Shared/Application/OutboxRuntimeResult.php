<?php

declare(strict_types=1);

namespace App\Shared\Application;

use InvalidArgumentException;

final readonly class OutboxRuntimeResult
{
    public function __construct(
        public int $examined,
        public int $success,
        public int $retryableFailure,
        public int $definitiveFailure,
        public int $uncertainResult,
        public int $dueBacklog,
        public ?int $oldestDueAgeSeconds,
        public int $reviewRequired,
    ) {
        foreach ([
            $examined,
            $success,
            $retryableFailure,
            $definitiveFailure,
            $uncertainResult,
            $dueBacklog,
            $reviewRequired,
        ] as $count) {
            if ($count < 0) {
                throw new InvalidArgumentException('Outbox runtime counts must not be negative.');
            }
        }

        if ($oldestDueAgeSeconds !== null && $oldestDueAgeSeconds < 0) {
            throw new InvalidArgumentException('Outbox oldest due age must not be negative.');
        }

        if ($examined !== $success + $retryableFailure + $definitiveFailure + $uncertainResult) {
            throw new InvalidArgumentException('Outbox runtime outcome counts must equal examined count.');
        }
    }

    public function status(): string
    {
        return $this->reviewRequired > 0 ? 'review_required' : 'healthy';
    }

    /**
     * @return array{
     *     status: string,
     *     dispatch: array{examined: int, success: int, retryable_failure: int, definitive_failure: int, uncertain_result: int},
     *     backlog: array{due: int, oldest_due_age_seconds: int|null, review_required: int}
     * }
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status(),
            'dispatch' => [
                'examined' => $this->examined,
                'success' => $this->success,
                'retryable_failure' => $this->retryableFailure,
                'definitive_failure' => $this->definitiveFailure,
                'uncertain_result' => $this->uncertainResult,
            ],
            'backlog' => [
                'due' => $this->dueBacklog,
                'oldest_due_age_seconds' => $this->oldestDueAgeSeconds,
                'review_required' => $this->reviewRequired,
            ],
        ];
    }
}
