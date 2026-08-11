<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Contracts;

use App\Modules\Identity\Application\OtpRateLimitBucket;

interface OtpAbuseLimiter
{
    /** @param non-empty-list<OtpRateLimitBucket> $buckets */
    public function consume(array $buckets): void;
}
