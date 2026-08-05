<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application;

use App\Modules\AccessControl\Application\AccessChangeContext;
use InvalidArgumentException;

final readonly class PanelChangeContext
{
    public function __construct(
        public string $requestFingerprint,
        public string $correlationId,
        public string $reasonCode,
        public ?string $reason,
        public int $actorAdministratorId,
    ) {
        if (preg_match('/\A[A-Za-z0-9:_-]{16,128}\z/', $requestFingerprint) !== 1) {
            throw new InvalidArgumentException('Panel request fingerprint is invalid.');
        }
        if (preg_match('/\A[A-Za-z0-9:_-]{16,64}\z/', $correlationId) !== 1) {
            throw new InvalidArgumentException('Panel correlation ID is invalid.');
        }
        if (preg_match('/\A[a-z0-9_.-]{1,64}\z/', $reasonCode) !== 1) {
            throw new InvalidArgumentException('Panel reason code is invalid.');
        }
        if ($reason !== null && (mb_strlen($reason) > 1000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason) === 1)) {
            throw new InvalidArgumentException('Panel reason is invalid.');
        }
        if ($actorAdministratorId < 1) {
            throw new InvalidArgumentException('Panel administrator ID must be positive.');
        }
    }

    public function requireReason(): string
    {
        $reason = $this->reason === null ? '' : trim($this->reason);
        if ($reason === '') {
            throw new InvalidArgumentException('An explanatory reason is required.');
        }

        return $reason;
    }

    public function accessContext(): AccessChangeContext
    {
        return new AccessChangeContext(
            $this->requestFingerprint,
            $this->correlationId,
            $this->reasonCode,
            $this->reason,
            $this->actorAdministratorId,
        );
    }
}
