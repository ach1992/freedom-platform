<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure;

use App\Modules\Identity\Application\Contracts\SmsProvider;
use App\Modules\Identity\Application\SmsDeliveryResult;
use App\Modules\Identity\Application\SmsOtpMessage;
use InvalidArgumentException;

final class FakeSmsProvider implements SmsProvider
{
    /** @var list<SmsDeliveryResult> */
    private array $results;

    /** @var list<array{destination_hash: string, code_hash: string, idempotency_hash: string}> */
    private array $safeRequests = [];

    /** @param list<SmsDeliveryResult> $results */
    public function __construct(
        private readonly string $providerCode,
        array $results = [],
    ) {
        if (preg_match('/\A[a-z0-9_-]{2,64}\z/', $providerCode) !== 1) {
            throw new InvalidArgumentException('Fake SMS provider code is invalid.');
        }

        $this->results = array_values($results);
    }

    public function code(): string
    {
        return $this->providerCode;
    }

    public function sendOtp(SmsOtpMessage $message): SmsDeliveryResult
    {
        $this->safeRequests[] = [
            'destination_hash' => hash('sha256', $message->destination->e164()),
            'code_hash' => hash('sha256', $message->code),
            'idempotency_hash' => hash('sha256', $message->idempotencyKey),
        ];

        return array_shift($this->results)
            ?? SmsDeliveryResult::accepted('fake-'.$this->providerCode.'-'.count($this->safeRequests));
    }

    public function sentCount(): int
    {
        return count($this->safeRequests);
    }

    /** @return list<array{destination_hash: string, code_hash: string, idempotency_hash: string}> */
    public function safeRequests(): array
    {
        return $this->safeRequests;
    }
}
