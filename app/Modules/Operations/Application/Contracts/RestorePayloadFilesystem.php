<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application\Contracts;

interface RestorePayloadFilesystem
{
    public function move(string $source, string $destination): bool;

    public function remove(string $path): void;
}
