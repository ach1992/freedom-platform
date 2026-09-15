<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use RuntimeException;

final readonly class CatalogMutationExecutor
{
    private const MANAGE_PERMISSION = 'catalog.manage';

    public function __construct(
        private DatabaseManager $database,
        private AdministratorPermissionAuthorizer $authorizer,
        private CatalogMutationAudit $audit,
    ) {}

    /**
     * @param  callable(Connection): CatalogMutationReceipt  $operation
     */
    public function execute(
        string $action,
        string $targetType,
        ?int $expectedTargetId,
        string $payloadHash,
        CatalogChangeContext $context,
        callable $operation,
    ): CatalogMutationReceipt {
        $context->requireReason();
        $this->authorizer->authorize($context->actorAdministratorId, self::MANAGE_PERMISSION);

        $existing = $this->audit->existing($action, $context->requestFingerprint);
        if ($existing !== null) {
            return $this->validateReplay($existing, $targetType, $expectedTargetId, $payloadHash);
        }

        try {
            return $this->database->connection()->transaction(function (Connection $connection) use (
                $action,
                $targetType,
                $expectedTargetId,
                $payloadHash,
                $context,
                $operation,
            ): CatalogMutationReceipt {
                $this->authorizeInsideTransaction($connection, $context->actorAdministratorId);

                $existing = $this->audit->existing($action, $context->requestFingerprint, true);
                if ($existing !== null) {
                    return $this->validateReplay($existing, $targetType, $expectedTargetId, $payloadHash);
                }

                return $operation($connection);
            });
        } catch (QueryException $exception) {
            $existing = $this->audit->existing($action, $context->requestFingerprint);
            if ($existing !== null) {
                return $this->validateReplay($existing, $targetType, $expectedTargetId, $payloadHash);
            }

            throw $exception;
        }
    }

    private function authorizeInsideTransaction(Connection $connection, int $administratorId): void
    {
        /** @var object{status: string}|null $administrator */
        $administrator = $connection->table('administrators')
            ->where('id', $administratorId)
            ->lockForUpdate()
            ->first(['status']);

        if ($administrator === null || $administrator->status !== 'active') {
            throw new AuthorizationException('Administrator authorization failed.');
        }

        $this->authorizer->authorize($administratorId, self::MANAGE_PERMISSION);
    }

    private function validateReplay(
        CatalogMutationReceipt $receipt,
        string $targetType,
        ?int $expectedTargetId,
        string $payloadHash,
    ): CatalogMutationReceipt {
        if ($receipt->targetType !== $targetType
            || ($expectedTargetId !== null && $receipt->targetId !== $expectedTargetId)
            || ($receipt->after['request_payload_hash'] ?? null) !== $payloadHash
        ) {
            throw new RuntimeException('Catalog mutation fingerprint conflict.');
        }

        return $receipt;
    }
}
