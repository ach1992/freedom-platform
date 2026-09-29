<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application\Contracts;

interface RestoreCriticalAuthorityIdentity
{
    public function fingerprint(): string;
}
