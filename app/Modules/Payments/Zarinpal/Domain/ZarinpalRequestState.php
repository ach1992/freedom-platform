<?php

declare(strict_types=1);

namespace App\Modules\Payments\Zarinpal\Domain;

enum ZarinpalRequestState: string
{
    case Initiating = 'initiating';
    case Redirectable = 'redirectable';
    case Uncertain = 'uncertain';
    case Failed = 'failed';
    case Verified = 'verified';
    case ManualReview = 'manual_review';
}
