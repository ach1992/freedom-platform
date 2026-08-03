<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Contracts;

interface GiftCardVerificationProvider
{
    public function testConnection(): ProviderHealth;

    /** @return list<string> */
    public function capabilities(): array;

    public function validateCode(string $idempotencyKey, string $giftCardType, string $plaintextCode): GiftCardEvidence;

    public function validateImage(string $idempotencyKey, string $giftCardType, string $privateImageReference): GiftCardEvidence;

    public function reserve(string $idempotencyKey, string $providerTransactionId): GiftCardEvidence;

    public function redeem(string $idempotencyKey, string $providerTransactionId): GiftCardEvidence;

    public function release(string $idempotencyKey, string $providerTransactionId): GiftCardEvidence;

    public function queryStatus(string $providerTransactionId): GiftCardEvidence;

    /** @param array<string, string> $headers */
    public function verifyWebhook(string $rawBody, array $headers): GiftCardEvidence;

    /** @return list<GiftCardEvidence> */
    public function reconcile(): array;

    public function health(): ProviderHealth;
}
