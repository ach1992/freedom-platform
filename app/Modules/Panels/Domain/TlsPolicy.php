<?php

declare(strict_types=1);

namespace App\Modules\Panels\Domain;

enum TlsPolicy: string
{
    case SystemCa = 'system_ca';
    case CustomCa = 'custom_ca';
    case CertificatePin = 'certificate_pin';
}
