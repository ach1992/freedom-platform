<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application\Contracts;

interface BackupArtifactCipher
{
    public function algorithm(): string;

    public function keyId(): string;

    /** @return array{bytes:int,sha256:string} */
    public function encryptFile(string $sourcePath, string $destinationPath): array;

    public function decryptFile(string $sourcePath, string $destinationPath): void;
}
