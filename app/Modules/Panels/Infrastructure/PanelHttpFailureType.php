<?php

declare(strict_types=1);

namespace App\Modules\Panels\Infrastructure;

enum PanelHttpFailureType: string
{
    case DnsResolution = 'dns_resolution';
    case DestinationPolicy = 'destination_policy';
    case Network = 'network';
    case Timeout = 'timeout';
    case Tls = 'tls';
    case Protocol = 'protocol';
    case ResponseTooLarge = 'response_too_large';
}
