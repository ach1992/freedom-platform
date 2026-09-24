<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Application;

use InvalidArgumentException;

final readonly class AdministratorAccessTargetSearchResult
{
    public function __construct(
        public AdministratorAccessTargetSearchDisposition $disposition,
        public ?AdministratorAccessTarget $target,
    ) {
        if (($disposition === AdministratorAccessTargetSearchDisposition::Matched) !== ($target !== null)) {
            throw new InvalidArgumentException('Administrator access target search result is invalid.');
        }
    }

    public static function matched(AdministratorAccessTarget $target): self
    {
        return new self(AdministratorAccessTargetSearchDisposition::Matched, $target);
    }

    public static function notFound(): self
    {
        return new self(AdministratorAccessTargetSearchDisposition::NotFound, null);
    }

    public static function ambiguous(): self
    {
        return new self(AdministratorAccessTargetSearchDisposition::Ambiguous, null);
    }
}
