<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use InvalidArgumentException;

final readonly class CustomerChangeContext
{
    public function __construct(
        public string $requestFingerprint,
        public string $correlationId,
        public string $reasonCode,
        public ?string $reason = null,
        public ?int $actorAdministratorId = null,
    ) {
        if (preg_match('/\A[A-Za-z0-9:_-]{16,128}\z/', $requestFingerprint) !== 1) {
            throw new InvalidArgumentException('Customer change request fingerprint is invalid.');
        }

        if (preg_match('/\A[A-Za-z0-9:_-]{16,64}\z/', $correlationId) !== 1) {
            throw new InvalidArgumentException('Customer change correlation ID is invalid.');
        }

        if (preg_match('/\A[a-z0-9_.-]{1,64}\z/', $reasonCode) !== 1) {
            throw new InvalidArgumentException('Customer change reason code is invalid.');
        }

        if ($reason !== null && (mb_strlen($reason) > 1000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason) === 1)) {
            throw new InvalidArgumentException('Customer change reason is invalid.');
        }

        if ($actorAdministratorId !== null && $actorAdministratorId < 1) {
            throw new InvalidArgumentException('Customer change administrator ID must be positive.');
        }
    }

    public function requireAdministrator(): int
    {
        if ($this->actorAdministratorId === null) {
            throw new InvalidArgumentException('An administrator is required for this customer change.');
        }

        return $this->actorAdministratorId;
    }
}
