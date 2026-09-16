<?php

declare(strict_types=1);

namespace App\Modules\Support\Application;

final readonly class SupportTicketAttachmentSnapshot
{
    public function __construct(
        public string $publicId,
        public int $ticketId,
        public int $actorUserId,
        public string $kind,
        public string $detectedMime,
        public int $byteSize,
        public bool $customerVisible,
        public string $createdAt,
    ) {}
}
