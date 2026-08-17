<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use InvalidArgumentException;

final readonly class ServiceLifecycleCommandContext
{
    public function __construct(
        public string $requestKey,
        public string $correlationId,
        public string $reasonCode,
        public string $reason,
        public ?int $actorAdministratorId = null,
        public ?int $actorUserId = null,
    ) {
        if (strlen($requestKey) < 8 || strlen($requestKey) > 128
            || preg_match('/\A[A-Za-z0-9_.:-]+\z/', $requestKey) !== 1) {
            throw new InvalidArgumentException('Service lifecycle request key is invalid.');
        }

        if (strlen($correlationId) < 8 || strlen($correlationId) > 64
            || preg_match('/\A[A-Za-z0-9_.:-]+\z/', $correlationId) !== 1) {
            throw new InvalidArgumentException('Service lifecycle correlation ID is invalid.');
        }

        if (preg_match('/\A[a-z0-9_.-]{1,64}\z/', $reasonCode) !== 1) {
            throw new InvalidArgumentException('Service lifecycle reason code is invalid.');
        }

        if (trim($reason) === '' || mb_strlen($reason) > 1000
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason) === 1) {
            throw new InvalidArgumentException('Service lifecycle reason is invalid.');
        }

        if ($actorAdministratorId !== null && $actorAdministratorId < 1) {
            throw new InvalidArgumentException('Service lifecycle administrator ID must be positive.');
        }
        if ($actorUserId !== null && $actorUserId < 1) {
            throw new InvalidArgumentException('Service lifecycle user ID must be positive.');
        }
        if (($actorAdministratorId === null) === ($actorUserId === null)) {
            throw new InvalidArgumentException('Exactly one Service lifecycle actor is required.');
        }
    }

    public function actorType(): string
    {
        return $this->actorAdministratorId === null ? 'user' : 'administrator';
    }

    public function actorId(): int
    {
        return $this->actorAdministratorId ?? $this->actorUserId
            ?? throw new InvalidArgumentException('Service lifecycle actor is unavailable.');
    }

    public function requestHash(): string
    {
        return hash('sha256', $this->requestKey);
    }
}
