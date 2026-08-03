<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Contracts;

use DateTimeImmutable;

interface BankTransactionVerificationProvider
{
    public function testConnection(): ProviderHealth;

    /** @return list<string> */
    public function capabilities(): array;

    /**
     * @return array{transactions: list<BankTransactionEvidence>, next_cursor: ?string}
     */
    public function pullTransactions(?string $cursor, DateTimeImmutable $from, DateTimeImmutable $to): array;

    public function fetchTransaction(string $providerTransactionId): BankTransactionEvidence;

    /** @param array<string, mixed> $providerPayload */
    public function normalizeTransaction(array $providerPayload, string $mappingVersion): BankTransactionEvidence;

    /** @param array<string, string> $headers */
    public function verifyWebhook(string $rawBody, array $headers): BankTransactionEvidence;

    /** @return list<BankTransactionEvidence> */
    public function reconcile(DateTimeImmutable $from, DateTimeImmutable $to): array;

    public function health(): ProviderHealth;
}
