<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Shared\Application\Clock;
use DomainException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\Connection;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * @phpstan-type ConfidentialRow object{delivery_operation_public_id:string,presentation_ciphertext:string,presentation_hash:string,created_at:string}
 */
final readonly class TelegramDeliveryConfidentialPresentationService
{
    public const DURABLE_MARKER = '[CONFIDENTIAL_TELEGRAM_PRESENTATION]';

    public function __construct(
        private Clock $clock,
        private StringEncrypter $encrypter,
        private TelegramDeliveryConfidentialPresentationDatabaseCapability $databaseCapability,
        private TelegramConfidentialPresentationHasher $hasher,
    ) {}

    /** @return non-empty-list<string> */
    public function fingerprintHashCandidates(ConfidentialTelegramPresentation $presentation): array
    {
        return $this->hasher->fingerprintHashCandidates($presentation);
    }

    public function store(
        Connection $connection,
        string $operationPublicId,
        ConfidentialTelegramPresentation $presentation,
        string $requestFingerprint,
    ): void {
        TelegramConfidentialPresentationProvenanceGuard::assertExactInternalCaller(
            TelegramDeliveryQueueService::class,
            __DIR__.'/TelegramDeliveryQueueService.php',
        );
        $this->assertOperationPublicId($operationPublicId);
        $this->assertHash($requestFingerprint, 'Telegram confidential presentation request fingerprint');
        if ($connection->transactionLevel() < 1) {
            throw new RuntimeException('Telegram confidential presentation requires the delivery queue transaction.');
        }

        $plaintext = $presentation->revealConfidentialText();
        $presentationHash = $this->hasher->integrityHash($presentation, $operationPublicId);
        try {
            $ciphertext = $this->encrypter->encryptString($plaintext);
        } catch (Throwable) {
            throw new RuntimeException('Telegram confidential presentation could not be encrypted.');
        }
        if ($ciphertext === '' || strlen($ciphertext) > 65_536 || hash_equals($plaintext, $ciphertext)) {
            throw new RuntimeException('Telegram confidential presentation ciphertext is invalid.');
        }
        $ciphertextHash = hash('sha256', $ciphertext);
        $timestamp = $this->clock->now()->format('Y-m-d H:i:s.u');

        $stored = $this->databaseCapability->runStore(
            $connection,
            $operationPublicId,
            $presentationHash,
            $ciphertextHash,
            $requestFingerprint,
            fn (): bool => $connection->table('telegram_delivery_confidential_presentations')->insert([
                'delivery_operation_public_id' => $operationPublicId,
                'presentation_ciphertext' => $ciphertext,
                'presentation_hash' => $presentationHash,
                'created_at' => $timestamp,
            ]),
        );
        if ($stored !== true) {
            throw new RuntimeException('Telegram confidential presentation was not persisted.');
        }
    }

    public function resolve(Connection $connection, string $operationPublicId): TelegramResolvedConfidentialPresentation
    {
        TelegramConfidentialPresentationProvenanceGuard::assertExactInternalCaller(
            TelegramDeliveryOperationExecutor::class,
            __DIR__.'/TelegramDeliveryOperationExecutor.php',
        );
        $this->assertOperationPublicId($operationPublicId);
        if ($connection->transactionLevel() < 1) {
            throw new RuntimeException('Telegram confidential presentation resolution requires the provider-boundary transaction.');
        }
        $this->pinReadySurface($connection);

        /** @var ConfidentialRow|null $row */
        $row = $connection->table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)
            ->where('delivery_operation_public_id', $operationPublicId)
            ->first([
                'delivery_operation_public_id',
                'presentation_ciphertext',
                'presentation_hash',
                'created_at',
            ]);
        if ($row === null) {
            throw new DomainException('Telegram confidential presentation does not exist.');
        }
        $ciphertext = (string) $row->presentation_ciphertext;
        $storedHash = (string) $row->presentation_hash;
        $this->assertHash($storedHash, 'Stored Telegram confidential presentation hash');
        try {
            $plaintext = $this->encrypter->decryptString($ciphertext);
        } catch (Throwable) {
            throw new DomainException('Telegram confidential presentation integrity validation failed.');
        }
        $presentation = ConfidentialTelegramPresentation::restoreDecrypted($plaintext);
        $integrityMatches = false;
        foreach ($this->hasher->integrityHashCandidates($presentation, $operationPublicId) as $candidateHash) {
            if (hash_equals($storedHash, $candidateHash)) {
                $integrityMatches = true;
                break;
            }
        }
        if (! $integrityMatches) {
            throw new DomainException('Telegram confidential presentation integrity validation failed.');
        }

        return new TelegramResolvedConfidentialPresentation($presentation, $storedHash);
    }

    private function pinReadySurface(Connection $connection): void
    {
        try {
            $connection->selectOne(
                'SELECT 1 AS surface_pin FROM '.TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE.' LIMIT 1',
                [],
                false,
            );
        } catch (Throwable $exception) {
            throw new RuntimeException('Telegram confidential presentation database surface is unavailable.', 0, $exception);
        }
        if (! (new TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1)->isReady($connection)) {
            throw new RuntimeException('Telegram confidential presentation database authority is not ready.');
        }
    }

    private function assertOperationPublicId(string $operationPublicId): void
    {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $operationPublicId) !== 1) {
            throw new InvalidArgumentException('Telegram delivery operation identity is invalid.');
        }
    }

    private function assertHash(string $hash, string $label): void
    {
        if (preg_match('/\A[0-9a-f]{64}\z/', $hash) !== 1) {
            throw new InvalidArgumentException($label.' is invalid.');
        }
    }
}
