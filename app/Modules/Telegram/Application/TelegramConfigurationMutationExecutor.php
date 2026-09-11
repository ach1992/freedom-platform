<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use RuntimeException;

final readonly class TelegramConfigurationMutationExecutor
{
    private const MANAGE_PERMISSION = 'telegram.membership.manage';

    public function __construct(
        private DatabaseManager $database,
        private AdministratorPermissionAuthorizer $authorizer,
        private TelegramConfigurationMutationAudit $audit,
    ) {}

    public function authorize(TelegramConfigurationChangeContext $context): void
    {
        $context->requireReason();
        $this->authorizer->authorize($context->actorAdministratorId, self::MANAGE_PERMISSION);
    }

    public function replayIfExists(
        string $action,
        string $targetType,
        ?int $expectedTargetId,
        string $payloadHash,
        TelegramConfigurationChangeContext $context,
    ): ?TelegramConfigurationMutationReceipt {
        $this->authorize($context);
        $existing = $this->audit->existing($action, $context->requestFingerprint);
        if ($existing === null) {
            return null;
        }

        return $this->validateReplay($existing, $targetType, $expectedTargetId, $payloadHash);
    }

    /** @param callable(Connection): TelegramConfigurationMutationReceipt $operation */
    public function execute(
        string $action,
        string $targetType,
        ?int $expectedTargetId,
        string $payloadHash,
        TelegramConfigurationChangeContext $context,
        callable $operation,
    ): TelegramConfigurationMutationReceipt {
        $this->authorize($context);

        $existing = $this->audit->existing($action, $context->requestFingerprint);
        if ($existing !== null) {
            return $this->validateReplay($existing, $targetType, $expectedTargetId, $payloadHash);
        }

        try {
            return $this->database->connection()->transaction(function (Connection $connection) use ($action, $targetType, $expectedTargetId, $payloadHash, $context, $operation): TelegramConfigurationMutationReceipt {
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
        /** @var object{status:string}|null $administrator */
        $administrator = $connection->table('administrators')->where('id', $administratorId)->lockForUpdate()->first(['status']);
        if ($administrator === null || $administrator->status !== 'active') {
            throw new AuthorizationException('Administrator authorization failed.');
        }
        $this->authorizer->authorize($administratorId, self::MANAGE_PERMISSION);
    }

    private function validateReplay(
        TelegramConfigurationMutationReceipt $receipt,
        string $targetType,
        ?int $expectedTargetId,
        string $payloadHash,
    ): TelegramConfigurationMutationReceipt {
        if ($receipt->targetType !== $targetType
            || ($expectedTargetId !== null && $receipt->targetId !== $expectedTargetId)
            || ($receipt->after['request_payload_hash'] ?? null) !== $payloadHash
        ) {
            throw new RuntimeException('Telegram configuration mutation fingerprint conflict.');
        }

        return $receipt;
    }
}
