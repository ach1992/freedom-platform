<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\IdentityItemType;
use App\Modules\Identity\Domain\VerificationStatus;
use RuntimeException;

final readonly class IdentityItemRecord
{
    public function __construct(
        public int $id,
        public int $userId,
        public IdentityItemType $type,
        public string $lookupHash,
        public string $maskedValue,
        public VerificationStatus $state,
        public bool $ownershipCheckRequired,
        public string $ownershipCheckStatus,
        public int $version,
    ) {}

    /**
     * @param object{
     *     id: int|string,
     *     user_id: int|string,
     *     type: string,
     *     lookup_hash: string,
     *     masked_value: string,
     *     state: string,
     *     ownership_check_required: int|bool,
     *     ownership_check_status: string,
     *     version: int|string
     * } $row
     */
    public static function fromRow(object $row): self
    {
        $type = IdentityItemType::tryFrom($row->type);
        $state = VerificationStatus::tryFrom($row->state);
        if ($type === null || $state === null) {
            throw new RuntimeException('Stored identity item state is invalid.');
        }

        return new self(
            (int) $row->id,
            (int) $row->user_id,
            $type,
            $row->lookup_hash,
            $row->masked_value,
            $state,
            (bool) $row->ownership_check_required,
            $row->ownership_check_status,
            (int) $row->version,
        );
    }
}
