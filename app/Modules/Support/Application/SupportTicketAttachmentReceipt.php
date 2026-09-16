<?php

declare(strict_types=1);

namespace App\Modules\Support\Application;

final readonly class SupportTicketAttachmentReceipt
{
    public function __construct(
        public SupportTicketAttachmentSnapshot $attachment,
        public bool $replayed,
    ) {}
}
