<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application\Contracts;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class RemoteServiceSnapshot
{
    public string $remoteId;

    public string $username;

    public ?int $dataLimitBytes;

    public ?int $usedBytes;

    public string $canonicalHash;

    public function __construct(
        string $remoteId,
        string $username,
        public PanelServiceStatus $status,
        ?int $dataLimitBytes,
        ?int $usedBytes,
        public ?DateTimeImmutable $expiresAt,
        string $canonicalHash,
    ) {
        $this->remoteId = self::requiredValue($remoteId, 512, 'Remote service identifier');
        $this->username = self::requiredValue($username, 191, 'Remote service username');
        if ($dataLimitBytes !== null && $dataLimitBytes < 0) {
            throw new InvalidArgumentException('Remote data limit cannot be negative.');
        }
        if ($usedBytes !== null && $usedBytes < 0) {
            throw new InvalidArgumentException('Remote used bytes cannot be negative.');
        }

        $hash = strtolower(trim($canonicalHash));
        if (preg_match('/\A[a-f0-9]{64}\z/', $hash) !== 1) {
            throw new InvalidArgumentException('Remote canonical hash is invalid.');
        }

        $this->dataLimitBytes = $dataLimitBytes;
        $this->usedBytes = $usedBytes;
        $this->canonicalHash = $hash;
    }

    private static function requiredValue(string $value, int $maximumLength, string $field): string
    {
        if ($value === ''
            || $value !== trim($value)
            || mb_strlen($value) > $maximumLength
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
        ) {
            throw new InvalidArgumentException($field.' is invalid.');
        }

        return $value;
    }
}
