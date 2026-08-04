<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

enum SmsDeliveryStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case DefinitiveFailure = 'definitive_failure';
    case Uncertain = 'uncertain';

    public function allowsFallback(): bool
    {
        return $this === self::DefinitiveFailure;
    }
}
