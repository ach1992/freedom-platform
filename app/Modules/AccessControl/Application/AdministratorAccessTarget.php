<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Application;

use InvalidArgumentException;

final readonly class AdministratorAccessTarget
{
    public function __construct(
        public string $selectionToken,
        public string $userPublicId,
        public string $accountType,
        public string $accountStatus,
        public ?string $maskedUsername,
        public ?string $administratorStatus,
        public bool $isOwner,
    ) {
        if (preg_match('/\A[0-9a-f]{40}\z/', $selectionToken) !== 1
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $userPublicId) !== 1
            || preg_match('/\A[a-z][a-z0-9_-]{1,31}\z/', $accountType) !== 1
            || preg_match('/\A[a-z][a-z0-9_-]{1,31}\z/', $accountStatus) !== 1
            || ($maskedUsername !== null
                && ($maskedUsername === ''
                    || mb_strlen($maskedUsername) > 40
                    || ! mb_check_encoding($maskedUsername, 'UTF-8')))
            || ($administratorStatus !== null
                && preg_match('/\A[a-z][a-z0-9_-]{1,31}\z/', $administratorStatus) !== 1)
            || ($administratorStatus === null && $isOwner)) {
            throw new InvalidArgumentException('Administrator access target is invalid.');
        }
    }

    public function hasAdministrator(): bool
    {
        return $this->administratorStatus !== null;
    }
}
