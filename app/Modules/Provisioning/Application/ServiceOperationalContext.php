<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use InvalidArgumentException;

final readonly class ServiceOperationalContext
{
    public function __construct(
        public string $requestKey,
        public string $correlationId,
        public string $reasonCode,
        public string $reason,
        public int $actorAdministratorId,
    ) {
        if (strlen($requestKey) < 8 || strlen($requestKey) > 128
            || preg_match('/\A[A-Za-z0-9_.:-]+\z/', $requestKey) !== 1) {
            throw new InvalidArgumentException('Service operational request key is invalid.');
        }
        if (strlen($correlationId) < 8 || strlen($correlationId) > 64
            || preg_match('/\A[A-Za-z0-9_.:-]+\z/', $correlationId) !== 1) {
            throw new InvalidArgumentException('Service operational correlation ID is invalid.');
        }
        if (preg_match('/\A[a-z0-9_.-]{1,64}\z/', $reasonCode) !== 1) {
            throw new InvalidArgumentException('Service operational reason code is invalid.');
        }
        if (trim($reason) === '' || mb_strlen($reason) > 1000
            || preg_match('/[\x00-\x1F\x7F]/', $reason) === 1) {
            throw new InvalidArgumentException('Service operational reason is invalid.');
        }
        if ($actorAdministratorId < 1) {
            throw new InvalidArgumentException('Service operational administrator ID must be positive.');
        }
    }

    public function requestHash(): string
    {
        return hash('sha256', $this->requestKey);
    }
}
