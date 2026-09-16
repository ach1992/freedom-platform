<?php

declare(strict_types=1);

namespace App\Modules\Support\Application;

use App\Shared\Application\RestrictedValue;

final readonly class SupportTicketAttachmentDeliveryGrant
{
    public function __construct(
        public SupportTicketAttachmentSnapshot $attachment,
        public RestrictedValue $privateMediaReference,
    ) {}

    /** @return array<string,mixed> */
    public function __debugInfo(): array
    {
        return [
            'attachment_public_id' => $this->attachment->publicId,
            'ticket_id' => $this->attachment->ticketId,
            'private_media_reference' => '[REDACTED]',
        ];
    }
}
