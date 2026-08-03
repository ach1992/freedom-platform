<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Contracts;

use App\Shared\Domain\Money;

interface PaymentProvider
{
    /** @return list<string> */
    public function capabilities(): array;

    public function testConnection(): ProviderHealth;

    /** @param array<string, scalar|null> $validatedContext */
    public function createIntent(
        string $idempotencyKey,
        string $orderId,
        Money $amount,
        array $validatedContext,
    ): PaymentIntentResult;

    /** @param array<string, string> $headers */
    public function verifyWebhook(string $rawBody, array $headers): VerifiedPaymentEvent;

    public function fetchAuthoritativeStatus(string $providerTransactionId): PaymentEvidence;

    public function cancel(string $idempotencyKey, string $providerTransactionId): PaymentEvidence;

    public function refund(
        string $idempotencyKey,
        string $providerTransactionId,
        Money $amount,
    ): PaymentRefundResult;

    public function health(): ProviderHealth;
}
