<?php

declare(strict_types=1);

namespace App\Modules\Agents\Application;

use InvalidArgumentException;

final readonly class AgentChangeContext
{
    public function __construct(
        public string $requestFingerprint,
        public string $correlationId,
        public string $reasonCode,
        public ?string $reason = null,
        public ?int $actorAdministratorId = null,
        public ?int $actorUserId = null,
    ) {
        if (preg_match('/\A[A-Za-z0-9:_-]{16,128}\z/', $requestFingerprint) !== 1) {
            throw new InvalidArgumentException('Agent request fingerprint is invalid.');
        }

        if (preg_match('/\A[A-Za-z0-9:_-]{16,64}\z/', $correlationId) !== 1) {
            throw new InvalidArgumentException('Agent correlation ID is invalid.');
        }

        if (preg_match('/\A[a-z0-9_.-]{1,64}\z/', $reasonCode) !== 1) {
            throw new InvalidArgumentException('Agent reason code is invalid.');
        }

        if ($reason !== null && (mb_strlen($reason) > 1000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason) === 1)) {
            throw new InvalidArgumentException('Agent reason is invalid.');
        }

        if ($actorAdministratorId !== null && $actorAdministratorId < 1) {
            throw new InvalidArgumentException('Agent administrator ID must be positive.');
        }

        if ($actorUserId !== null && $actorUserId < 1) {
            throw new InvalidArgumentException('Agent user ID must be positive.');
        }

        if (($actorAdministratorId === null) === ($actorUserId === null)) {
            throw new InvalidArgumentException('Exactly one agent mutation actor is required.');
        }
    }

    public function requireAdministrator(): int
    {
        if ($this->actorAdministratorId === null) {
            throw new InvalidArgumentException('An administrator actor is required.');
        }

        return $this->actorAdministratorId;
    }

    public function requireUser(): int
    {
        if ($this->actorUserId === null) {
            throw new InvalidArgumentException('A user actor is required.');
        }

        return $this->actorUserId;
    }

    public function requireReason(): string
    {
        $reason = $this->reason === null ? '' : trim($this->reason);

        if ($reason === '') {
            throw new InvalidArgumentException('An explanatory reason is required.');
        }

        return $reason;
    }
}
