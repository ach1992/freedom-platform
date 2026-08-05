<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application;

use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use RuntimeException;

final readonly class PanelMutationExecutor
{
    public function __construct(
        private DatabaseManager $database,
        private AdministratorPermissionAuthorizer $authorizer,
        private PanelMutationAudit $audit,
    ) {}

    /**
     * @param list<string> $permissions
     * @param callable(Connection): PanelMutationReceipt $operation
     */
    public function execute(
        string $action,
        string $targetType,
        ?string $expectedTargetId,
        string $payloadHmac,
        array $permissions,
        PanelChangeContext $context,
        callable $operation,
    ): PanelMutationReceipt {
        $context->requireReason();
        $this->authorize($context->actorAdministratorId, $permissions);

        $existing = $this->audit->existing($action, $context->requestFingerprint);
        if ($existing !== null) {
            return $this->validateReplay($existing, $targetType, $expectedTargetId, $payloadHmac);
        }

        try {
            return $this->database->connection()->transaction(function (Connection $connection) use (
                $action,
                $targetType,
                $expectedTargetId,
                $payloadHmac,
                $permissions,
                $context,
                $operation,
            ): PanelMutationReceipt {
                /** @var object{status: string}|null $administrator */
                $administrator = $connection->table('administrators')
                    ->where('id', $context->actorAdministratorId)
                    ->lockForUpdate()
                    ->first(['status']);

                if ($administrator === null || $administrator->status !== 'active') {
                    throw new AuthorizationException('Administrator authorization failed.');
                }

                $this->authorize($context->actorAdministratorId, $permissions);

                $existing = $this->audit->existing($action, $context->requestFingerprint, true);
                if ($existing !== null) {
                    return $this->validateReplay($existing, $targetType, $expectedTargetId, $payloadHmac);
                }

                return $operation($connection);
            });
        } catch (QueryException $exception) {
            $existing = $this->audit->existing($action, $context->requestFingerprint);
            if ($existing !== null) {
                return $this->validateReplay($existing, $targetType, $expectedTargetId, $payloadHmac);
            }

            throw $exception;
        }
    }

    /** @param list<string> $permissions */
    private function authorize(int $administratorId, array $permissions): void
    {
        foreach ($permissions as $permission) {
            $this->authorizer->authorize($administratorId, $permission);
        }
    }

    /** @param array{payload_hmac: string, receipt: PanelMutationReceipt} $existing */
    private function validateReplay(
        array $existing,
        string $targetType,
        ?string $expectedTargetId,
        string $payloadHmac,
    ): PanelMutationReceipt {
        $receipt = $existing['receipt'];

        if (! hash_equals($existing['payload_hmac'], $payloadHmac)
            || $receipt->targetType !== $targetType
            || ($expectedTargetId !== null && $receipt->targetId !== $expectedTargetId)
        ) {
            throw new RuntimeException('Panel mutation fingerprint conflict.');
        }

        return $receipt;
    }
}
