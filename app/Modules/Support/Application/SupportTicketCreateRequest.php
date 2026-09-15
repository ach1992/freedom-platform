<?php

declare(strict_types=1);

namespace App\Modules\Support\Application;

use App\Modules\Support\Domain\SupportTicketPriority;
use InvalidArgumentException;

final readonly class SupportTicketCreateRequest
{
    public function __construct(
        public int $requesterUserId,
        public string $categoryCode,
        public string $title,
        public string $description,
        public string $idempotencyKey,
        public SupportTicketPriority $priority = SupportTicketPriority::Normal,
        public ?int $orderId = null,
        public ?int $paymentIntentId = null,
        public ?int $serviceSubscriptionId = null,
    ) {
        if ($requesterUserId < 1) {
            throw new InvalidArgumentException('Support ticket requester is invalid.');
        }
        if (preg_match('/\A[a-z][a-z0-9_]{1,63}\z/', $categoryCode) !== 1) {
            throw new InvalidArgumentException('Support ticket category code is invalid.');
        }
        if (trim($title) === '' || mb_strlen($title) > 200) {
            throw new InvalidArgumentException('Support ticket title is invalid.');
        }
        if (trim($description) === '' || mb_strlen($description) > 8000) {
            throw new InvalidArgumentException('Support ticket description is invalid.');
        }
        if (preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/', $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('Support ticket idempotency key is invalid.');
        }

        foreach ([$orderId, $paymentIntentId, $serviceSubscriptionId] as $reference) {
            if ($reference !== null && $reference < 1) {
                throw new InvalidArgumentException('Support ticket business reference is invalid.');
            }
        }
    }
}
