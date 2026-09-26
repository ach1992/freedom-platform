<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application;

use InvalidArgumentException;

final readonly class ServiceReconfigurationQuoteContext
{
    public function __construct(public string $previewPublicId)
    {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $previewPublicId) !== 1) {
            throw new InvalidArgumentException('Service reconfiguration preview public ID is invalid.');
        }
    }
}
