<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Application;

use InvalidArgumentException;

final readonly class AdministratorOwnerTransferSummary
{
    public function __construct(
        public string $selectionToken,
        public string $state,
        public string $currentOwnerUserPublicId,
        public string $targetUserPublicId,
        public string $expiresAt,
        public bool $actorIsCurrentOwner,
        public bool $actorIsTarget,
    ) {
        if (preg_match('/\A[0-9a-f]{40}\z/', $selectionToken) !== 1
            || preg_match('/\A[a-z][a-z0-9_-]{1,31}\z/', $state) !== 1
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $currentOwnerUserPublicId) !== 1
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $targetUserPublicId) !== 1
            || trim($expiresAt) === ''
            || ($actorIsCurrentOwner === $actorIsTarget)) {
            throw new InvalidArgumentException('Administrator Owner transfer summary is invalid.');
        }
    }
}
