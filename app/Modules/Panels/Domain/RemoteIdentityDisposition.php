<?php

declare(strict_types=1);

namespace App\Modules\Panels\Domain;

/** @requirement PRV-001 SEC-002 QUA-001 */
enum RemoteIdentityDisposition: string
{
    case Absent = 'absent';
    case Adopt = 'adopt';
    case Conflict = 'conflict';
    case ManualReview = 'manual_review';
}
