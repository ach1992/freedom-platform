<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application\Contracts;

interface RestoreDatabaseRestorer
{
    public function restore(string $sqlPath): void;
}
