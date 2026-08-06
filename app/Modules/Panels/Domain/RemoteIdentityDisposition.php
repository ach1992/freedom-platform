<?php

declare(strict_types=1);

namespace App\Modules\Panels\Domain;

enum RemoteIdentityDisposition: string
{
    case Absent = 'absent';
    case Adopt = 'adopt';
    case Conflict = 'conflict';
    case ManualReview = 'manual_review';
}
