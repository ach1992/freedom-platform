<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Application;

use InvalidArgumentException;

final readonly class AdministratorSensitiveApprovalSummary
{
    public function __construct(
        public string $selectionToken,
        public string $state,
        public string $action,
        public string $requesterUserPublicId,
        public string $reason,
        public string $expiresAt,
    ) {
        if (preg_match('/\A[0-9a-f]{40}\z/', $selectionToken) !== 1
            || preg_match('/\A[a-z][a-z0-9_-]{1,31}\z/', $state) !== 1
            || preg_match('/\A[a-z0-9_.-]{1,128}\z/', $action) !== 1
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $requesterUserPublicId) !== 1
            || trim($reason) === ''
            || mb_strlen($reason) > 1000
            || ! mb_check_encoding($reason, 'UTF-8')
            || trim($expiresAt) === '') {
            throw new InvalidArgumentException('Administrator sensitive approval summary is invalid.');
        }
    }
}
