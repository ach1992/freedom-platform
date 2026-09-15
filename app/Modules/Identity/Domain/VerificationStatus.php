<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

enum VerificationStatus: string
{
    case Unverified = 'unverified';
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';
}
