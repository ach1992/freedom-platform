<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Contracts;

enum PaymentEvidenceAuthority: string
{
    case NonAuthoritative = 'non_authoritative';
    case Authoritative = 'authoritative';
}
