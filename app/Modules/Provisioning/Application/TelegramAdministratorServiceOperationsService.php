<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\AccessControl\Application\AdministratorUserPermissionAuthorizer;
use App\Modules\Provisioning\Domain\ServiceMutationType;
use App\Modules\Telegram\Application\Contracts\TelegramAdministratorServiceOperations;
use App\Modules\Telegram\Application\TelegramAdministratorServiceOperationPreview;
use App\Modules\Telegram\Application\TelegramAdministratorServiceOperationResult;
use DomainException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

final readonly class TelegramAdministratorServiceOperationsService implements TelegramAdministratorServiceOperations
{
    /** @var list<string> */
    private const PERMISSIONS = [
        'services.operate',
        'services.rotate_link',
        'services.retire',
        'services.reconfigure',
        'services.import',
        'services.transfer_ownership',
        'services.repair',
        'services.grant_batch',
    ];

    public function __construct(
        private DatabaseManager $database,
        private AdministratorUserPermissionAuthorizer $administrators,
        private ServiceLifecycleCommandService $lifecycle,
        private ServiceReconfigurationPreviewService $reconfigurationPreviews,
        private ServiceReconfigurationNoChargeQueueService $reconfigurations,
        private ServiceImportService $imports,
        private ServiceOwnershipTransferService $transfers,
        private ServiceRepairService $repairs,
        private ServiceBatchGrantService $serviceGrants,
        private ServiceEntitlementGrantBatchService $entitlementGrants,
    ) {}

    public function availableFor(int $actorUserId): bool
    {
        foreach (self::PERMISSIONS as $permission) {
            if ($this->administrators->allowsUser($actorUserId, $permission)) {
                return true;
            }
        }

        return false;
    }

    public function prepareForUser(
        int $actorUserId,
        string $input,
        string $requestKey,
        string $correlationId,
    ): TelegramAdministratorServiceOperationPreview {
        $input = trim($input);
        if ($input === '' || mb_strlen($input) > 4096) {
            throw new DomainException('Telegram administrator Service operation command is invalid.');
        }

        [$command, $rest] = $this->splitHead($input);

        return match ($command) {
            'lifecycle' => $this->prepareLifecycle($actorUserId, $rest),
            'reconfigure' => $this->prepareReconfiguration(
                $actorUserId,
                $rest,
                $requestKey,
                $correlationId,
            ),
            'transfer' => $this->prepareTransfer($actorUserId, $rest),
            'repair' => $this->prepareRepair($actorUserId, $rest, $requestKey, $correlationId),
            'import' => $this->prepareImport($actorUserId, $rest, $requestKey, $correlationId),
            'grant-services' => $this->prepareServiceGrant($actorUserId, $rest),
            'service-batch' => $this->prepareServiceBatchControl($actorUserId, $rest),
            'grant-entitlement' => $this->prepareEntitlementGrant(
                $actorUserId,
                $rest,
                $requestKey,
                $correlationId,
                false,
            ),
            'grant-entitlement-server' => $this->prepareEntitlementGrant(
                $actorUserId,
                $rest,
                $requestKey,
                $correlationId,
                true,
            ),
            'entitlement-batch' => $this->prepareEntitlementBatchControl($actorUserId, $rest),
            default => throw new DomainException('Telegram administrator Service operation command is unsupported.'),
        };
    }

    /** @param array<string,mixed> $payload */
    public function executeForUser(
        int $actorUserId,
        array $payload,
        string $requestKey,
        string $correlationId,
    ): TelegramAdministratorServiceOperationResult {
        $kind = $payload['kind'] ?? null;
        if (! is_string($kind)) {
            throw new DomainException('Stored Telegram administrator Service operation is invalid.');
        }

        return match ($kind) {
            'lifecycle' => $this->executeLifecycle($actorUserId, $payload, $requestKey, $correlationId),
            'reconfiguration_confirm' => $this->executeReconfiguration($actorUserId, $payload),
            'transfer' => $this->executeTransfer($actorUserId, $payload, $requestKey, $correlationId),
            'repair_apply' => $this->executeRepairApply($actorUserId, $payload),
            'import_attach' => $this->executeImportAttach($actorUserId, $payload),
            'grant_services' => $this->executeServiceGrant($actorUserId, $payload, $requestKey, $correlationId),
            'service_batch_control' => $this->executeServiceBatchControl($actorUserId, $payload, $requestKey, $correlationId),
            'entitlement_confirm' => $this->executeEntitlementConfirm($actorUserId, $payload, $requestKey, $correlationId),
            'entitlement_batch_control' => $this->executeEntitlementBatchControl($actorUserId, $payload, $requestKey, $correlationId),
            default => throw new DomainException('Stored Telegram administrator Service operation kind is unsupported.'),
        };
    }

    private function prepareLifecycle(int $actorUserId, string $rest): TelegramAdministratorServiceOperationPreview
    {
        [$action, $servicePublicId, $reasonCode, $reason] = $this->fixedWithReason($rest, 3);
        $permission = match ($action) {
            'reset_usage', 'suspend', 'activate' => 'services.operate',
            'rotate_link' => 'services.rotate_link',
            'delete' => 'services.retire',
            default => throw new DomainException('Service lifecycle administrator action is invalid.'),
        };
        $this->administratorId($actorUserId, $permission);
        $this->assertUlid($servicePublicId, 'Service public ID');
        $service = $this->serviceSnapshot($servicePublicId);

        return new TelegramAdministratorServiceOperationPreview(
            sprintf(
                'Lifecycle %s on Service %s (current state: %s). Reason: %s / %s',
                $action,
                $servicePublicId,
                $service->lifecycle_state,
                $reasonCode,
                $reason,
            ),
            true,
            [
                'kind' => 'lifecycle',
                'action' => $action,
                'service' => $servicePublicId,
                'reason_code' => $reasonCode,
                'reason' => $reason,
            ],
        );
    }

    private function prepareReconfiguration(
        int $actorUserId,
        string $rest,
        string $requestKey,
        string $correlationId,
    ): TelegramAdministratorServiceOperationPreview {
        [$servicePublicId, $targetOfferingCode, $serverCode, $protocolCode, $reasonCode, $reason] =
            $this->fixedWithReason($rest, 5);
        $administratorId = $this->administratorId($actorUserId, 'services.reconfigure');
        $context = $this->operationalContext(
            $administratorId,
            $requestKey,
            $correlationId,
            $reasonCode,
            $reason,
        );
        $receipt = $this->reconfigurationPreviews->previewForAdministrator(
            $context,
            $servicePublicId,
            $targetOfferingCode,
            $serverCode === '-' ? null : $serverCode,
            $protocolCode === '-' ? null : $protocolCode,
        );
        if (! $receipt->isFree()) {
            throw new DomainException(
                'Paid administrator Service reconfiguration must use the canonical purchase path.',
            );
        }

        return new TelegramAdministratorServiceOperationPreview(
            sprintf(
                'Reconfiguration preview %s: service=%s, offering=%s, server=%s, protocol=%s, price=%d IRR, expires=%s. Reason: %s / %s',
                $receipt->previewPublicId,
                $receipt->servicePublicId,
                $receipt->targetOfferingCode,
                $receipt->targetSalesServerCode,
                $receipt->targetProtocolProfileCode,
                $receipt->totalPriceIrr,
                $receipt->expiresAt,
                $reasonCode,
                $reason,
            ),
            true,
            [
                'kind' => 'reconfiguration_confirm',
                'preview' => $receipt->previewPublicId,
                'request_key' => $requestKey,
                'correlation_id' => $correlationId,
                'reason_code' => $reasonCode,
                'reason' => $reason,
            ],
        );
    }

    private function prepareTransfer(int $actorUserId, string $rest): TelegramAdministratorServiceOperationPreview
    {
        [$servicePublicId, $targetUserPublicId, $reasonCode, $reason] = $this->fixedWithReason($rest, 3);
        $this->administratorId($actorUserId, 'services.transfer_ownership');
        $this->assertUlid($servicePublicId, 'Service public ID');
        $target = $this->user($targetUserPublicId);
        $service = $this->serviceSnapshot($servicePublicId);

        return new TelegramAdministratorServiceOperationPreview(
            sprintf(
                'Transfer Service %s from user #%d to %s. Reason: %s / %s',
                $servicePublicId,
                (int) $service->user_id,
                $targetUserPublicId,
                $reasonCode,
                $reason,
            ),
            true,
            [
                'kind' => 'transfer',
                'service' => $servicePublicId,
                'target_user' => $targetUserPublicId,
                'reason_code' => $reasonCode,
                'reason' => $reason,
            ],
        );
    }

    private function prepareRepair(
        int $actorUserId,
        string $rest,
        string $requestKey,
        string $correlationId,
    ): TelegramAdministratorServiceOperationPreview {
        [$servicePublicId, $remoteServiceId, $reasonCode, $reason] = $this->fixedWithReason($rest, 3);
        $administratorId = $this->administratorId($actorUserId, 'services.repair');
        $context = $this->operationalContext(
            $administratorId,
            $requestKey,
            $correlationId,
            $reasonCode,
            $reason,
        );
        $receipt = $this->repairs->previewRemoteIdentity($servicePublicId, $remoteServiceId, $context);
        $confirmable = $receipt->state === 'previewed' && $receipt->remoteDisposition === 'present';

        return new TelegramAdministratorServiceOperationPreview(
            sprintf(
                'Repair preview %s for Service %s: remote=%s, disposition=%s, state=%s.',
                $receipt->casePublicId,
                $receipt->serviceSubscriptionPublicId,
                $receipt->proposedRemoteServiceId ?? '-',
                $receipt->remoteDisposition,
                $receipt->state,
            ),
            $confirmable,
            $confirmable ? [
                'kind' => 'repair_apply',
                'case' => $receipt->casePublicId,
                'request_key' => $requestKey,
                'correlation_id' => $correlationId,
                'reason_code' => $reasonCode,
                'reason' => $reason,
            ] : [],
        );
    }

    private function prepareImport(
        int $actorUserId,
        string $rest,
        string $requestKey,
        string $correlationId,
    ): TelegramAdministratorServiceOperationPreview {
        [$subscriptionLink, $targetCode, $userPublicId, $offeringCode, $reasonCode, $reason] =
            $this->fixedWithReason($rest, 5);
        $administratorId = $this->administratorId($actorUserId, 'services.import');
        $user = $this->user($userPublicId);
        $targetId = $this->targetId($targetCode);
        $offeringId = $this->offeringId($offeringCode);
        $context = $this->operationalContext(
            $administratorId,
            $requestKey,
            $correlationId,
            $reasonCode,
            $reason,
        );
        $receipt = $this->imports->preview(
            $subscriptionLink,
            $targetId,
            (int) $user->id,
            $offeringId,
            $context,
        );

        return new TelegramAdministratorServiceOperationPreview(
            sprintf(
                'Import preview %s: user=%s, offering=%s, target=%s, remote=%s, username=%s.',
                $receipt->importPublicId,
                $userPublicId,
                $offeringCode,
                $targetCode,
                $receipt->remoteServiceId,
                $receipt->remoteUsername,
            ),
            $receipt->state === 'previewed',
            $receipt->state === 'previewed' ? [
                'kind' => 'import_attach',
                'import' => $receipt->importPublicId,
                'request_key' => $requestKey,
                'correlation_id' => $correlationId,
                'reason_code' => $reasonCode,
                'reason' => $reason,
            ] : [],
        );
    }

    private function prepareServiceGrant(int $actorUserId, string $rest): TelegramAdministratorServiceOperationPreview
    {
        [$targets, $reasonCode, $reason] = $this->fixedWithReason($rest, 2);
        $this->administratorId($actorUserId, 'services.grant_batch');
        $normalized = $this->serviceGrantTargets($targets);

        return new TelegramAdministratorServiceOperationPreview(
            sprintf(
                'Create and resume complimentary Service batch for %d target(s). Reason: %s / %s',
                count($normalized),
                $reasonCode,
                $reason,
            ),
            true,
            [
                'kind' => 'grant_services',
                'targets' => $normalized,
                'reason_code' => $reasonCode,
                'reason' => $reason,
            ],
        );
    }

    private function prepareServiceBatchControl(
        int $actorUserId,
        string $rest,
    ): TelegramAdministratorServiceOperationPreview {
        [$action, $tail] = $this->splitHead($rest);
        $this->administratorId($actorUserId, 'services.grant_batch');

        if ($action === 'status') {
            $batchPublicId = trim($tail);
            $this->assertUlid($batchPublicId, 'Service batch public ID');
            $batch = $this->serviceBatchSnapshot($batchPublicId);

            return new TelegramAdministratorServiceOperationPreview(
                $this->serviceBatchSummary($batch),
                false,
            );
        }

        [$batchPublicId, $reasonCode, $reason] = $this->fixedWithReason($tail, 2);
        if (! in_array($action, ['resume', 'pause', 'activate', 'cancel'], true)) {
            throw new DomainException('Service batch control action is invalid.');
        }
        $this->assertUlid($batchPublicId, 'Service batch public ID');
        $batch = $this->serviceBatchSnapshot($batchPublicId);

        return new TelegramAdministratorServiceOperationPreview(
            $this->serviceBatchSummary($batch).' Requested action: '.$action.'.',
            true,
            [
                'kind' => 'service_batch_control',
                'action' => $action,
                'batch' => $batchPublicId,
                'reason_code' => $reasonCode,
                'reason' => $reason,
            ],
        );
    }

    private function prepareEntitlementGrant(
        int $actorUserId,
        string $rest,
        string $requestKey,
        string $correlationId,
        bool $serverScope,
    ): TelegramAdministratorServiceOperationPreview {
        [$selection, $dataMb, $days, $notify, $reasonCode, $reason] = $this->fixedWithReason($rest, 5);
        $administratorId = $this->administratorId($actorUserId, 'services.grant_batch');
        [$dataBytes, $durationDays] = $this->entitlementPackage($dataMb, $days);
        $notifyCustomers = match ($notify) {
            'notify' => true,
            'silent' => false,
            default => throw new DomainException('Entitlement grant notification mode must be notify or silent.'),
        };
        $context = $this->operationalContext(
            $administratorId,
            $requestKey,
            $correlationId,
            $reasonCode,
            $reason,
        );

        if ($serverScope) {
            $serverId = $this->salesServerId($selection);
            $receipt = $this->entitlementGrants->previewServer(
                $context,
                $serverId,
                $dataBytes,
                $durationDays,
                'admin_grant',
                $notifyCustomers,
            );
        } else {
            $services = array_values(array_filter(array_map('trim', explode(',', $selection))));
            $receipt = $this->entitlementGrants->previewExplicit(
                $context,
                $services,
                $dataBytes,
                $durationDays,
                'admin_grant',
                $notifyCustomers,
            );
        }

        return new TelegramAdministratorServiceOperationPreview(
            $this->entitlementSummary($receipt),
            true,
            [
                'kind' => 'entitlement_confirm',
                'batch' => $receipt->batchPublicId,
                'reason_code' => $reasonCode,
                'reason' => $reason,
            ],
        );
    }

    private function prepareEntitlementBatchControl(
        int $actorUserId,
        string $rest,
    ): TelegramAdministratorServiceOperationPreview {
        [$action, $tail] = $this->splitHead($rest);
        $administratorId = $this->administratorId($actorUserId, 'services.grant_batch');

        if ($action === 'status') {
            [$batchPublicId, $reasonCode, $reason] = $this->fixedWithReason($tail, 2);
            $context = $this->operationalContext(
                $administratorId,
                'tg-entitlement-status:'.substr(hash('sha256', $batchPublicId.'|'.$reasonCode), 0, 48),
                'tg-entitlement-status:'.substr(hash('sha256', $batchPublicId.'|correlation'), 0, 40),
                $reasonCode,
                $reason,
            );
            $receipt = $this->entitlementGrants->status($batchPublicId, $context);

            return new TelegramAdministratorServiceOperationPreview(
                $this->entitlementSummary($receipt),
                false,
            );
        }

        [$batchPublicId, $reasonCode, $reason] = $this->fixedWithReason($tail, 2);
        if (! in_array($action, ['resume', 'pause', 'activate', 'cancel'], true)) {
            throw new DomainException('Entitlement batch control action is invalid.');
        }
        $this->assertUlid($batchPublicId, 'Entitlement batch public ID');

        return new TelegramAdministratorServiceOperationPreview(
            sprintf('Entitlement batch %s requested action: %s. Reason: %s / %s', $batchPublicId, $action, $reasonCode, $reason),
            true,
            [
                'kind' => 'entitlement_batch_control',
                'action' => $action,
                'batch' => $batchPublicId,
                'reason_code' => $reasonCode,
                'reason' => $reason,
            ],
        );
    }

    /** @param array<string,mixed> $payload */
    private function executeLifecycle(
        int $actorUserId,
        array $payload,
        string $requestKey,
        string $correlationId,
    ): TelegramAdministratorServiceOperationResult {
        [$action, $service, $reasonCode, $reason] = $this->operationPayload(
            $payload,
            ['action', 'service', 'reason_code', 'reason'],
        );
        $permission = match ($action) {
            'reset_usage', 'suspend', 'activate' => 'services.operate',
            'rotate_link' => 'services.rotate_link',
            'delete' => 'services.retire',
            default => throw new DomainException('Stored lifecycle action is invalid.'),
        };
        $administratorId = $this->administratorId($actorUserId, $permission);
        $context = new ServiceLifecycleCommandContext(
            $requestKey,
            $correlationId,
            $reasonCode,
            $reason,
            actorAdministratorId: $administratorId,
        );
        $type = match ($action) {
            'reset_usage' => ServiceMutationType::ResetUsage,
            'suspend' => ServiceMutationType::Suspend,
            'activate' => ServiceMutationType::Activate,
            'rotate_link' => ServiceMutationType::RotateSubscriptionLink,
            'delete' => ServiceMutationType::Delete,
        };
        $receipt = $this->lifecycle->execute($service, $type, $context);

        return new TelegramAdministratorServiceOperationResult(sprintf(
            'Lifecycle queued: service=%s operation=%s type=%s state=%s replayed=%s.',
            $receipt->mutation->servicePublicId,
            $receipt->mutation->operationPublicId,
            $receipt->mutation->type->value,
            $receipt->mutation->state->value,
            $receipt->mutation->replayed ? 'yes' : 'no',
        ));
    }

    /** @param array<string,mixed> $payload */
    private function executeReconfiguration(
        int $actorUserId,
        array $payload,
    ): TelegramAdministratorServiceOperationResult {
        [$preview, $storedRequestKey, $storedCorrelationId, $reasonCode, $reason] = $this->operationPayload(
            $payload,
            ['preview', 'request_key', 'correlation_id', 'reason_code', 'reason'],
        );
        $administratorId = $this->administratorId($actorUserId, 'services.reconfigure');
        $receipt = $this->reconfigurations->queueForAdministrator(
            $preview,
            $this->operationalContext(
                $administratorId,
                $storedRequestKey,
                $storedCorrelationId,
                $reasonCode,
                $reason,
            ),
        );

        return new TelegramAdministratorServiceOperationResult(sprintf(
            'Administrator reconfiguration queued: service=%s operation=%s state=%s replayed=%s.',
            $receipt->servicePublicId,
            $receipt->operationPublicId,
            $receipt->state->value,
            $receipt->replayed ? 'yes' : 'no',
        ));
    }

    /** @param array<string,mixed> $payload */
    private function executeTransfer(
        int $actorUserId,
        array $payload,
        string $requestKey,
        string $correlationId,
    ): TelegramAdministratorServiceOperationResult {
        [$service, $targetUserPublicId, $reasonCode, $reason] = $this->operationPayload(
            $payload,
            ['service', 'target_user', 'reason_code', 'reason'],
        );
        $administratorId = $this->administratorId($actorUserId, 'services.transfer_ownership');
        $target = $this->user($targetUserPublicId);
        $receipt = $this->transfers->transfer(
            $service,
            (int) $target->id,
            $this->operationalContext($administratorId, $requestKey, $correlationId, $reasonCode, $reason),
        );

        return new TelegramAdministratorServiceOperationResult(sprintf(
            'Ownership transferred: service=%s transfer=%s from=%d to=%d replayed=%s.',
            $receipt->serviceSubscriptionPublicId,
            $receipt->transferPublicId,
            $receipt->fromUserId,
            $receipt->toUserId,
            $receipt->replayed ? 'yes' : 'no',
        ));
    }

    /** @param array<string,mixed> $payload */
    private function executeRepairApply(
        int $actorUserId,
        array $payload,
    ): TelegramAdministratorServiceOperationResult {
        [$case, $storedRequestKey, $storedCorrelationId, $reasonCode, $reason] = $this->operationPayload(
            $payload,
            ['case', 'request_key', 'correlation_id', 'reason_code', 'reason'],
        );
        $administratorId = $this->administratorId($actorUserId, 'services.repair');
        $receipt = $this->repairs->apply(
            $case,
            $this->operationalContext(
                $administratorId,
                $storedRequestKey,
                $storedCorrelationId,
                $reasonCode,
                $reason,
            ),
        );

        return new TelegramAdministratorServiceOperationResult(sprintf(
            'Repair applied: case=%s service=%s remote=%s state=%s replayed=%s.',
            $receipt->casePublicId,
            $receipt->serviceSubscriptionPublicId,
            $receipt->proposedRemoteServiceId ?? '-',
            $receipt->state,
            $receipt->replayed ? 'yes' : 'no',
        ));
    }

    /** @param array<string,mixed> $payload */
    private function executeImportAttach(
        int $actorUserId,
        array $payload,
    ): TelegramAdministratorServiceOperationResult {
        [$import, $storedRequestKey, $storedCorrelationId, $reasonCode, $reason] = $this->operationPayload(
            $payload,
            ['import', 'request_key', 'correlation_id', 'reason_code', 'reason'],
        );
        $administratorId = $this->administratorId($actorUserId, 'services.import');
        $receipt = $this->imports->attach(
            $import,
            $this->operationalContext(
                $administratorId,
                $storedRequestKey,
                $storedCorrelationId,
                $reasonCode,
                $reason,
            ),
        );

        return new TelegramAdministratorServiceOperationResult(sprintf(
            'Import attached: import=%s service=%s order=%s state=%s replayed=%s.',
            $receipt->importPublicId,
            $receipt->serviceSubscriptionPublicId ?? '-',
            $receipt->orderPublicId ?? '-',
            $receipt->state,
            $receipt->replayed ? 'yes' : 'no',
        ));
    }

    /** @param array<string,mixed> $payload */
    private function executeServiceGrant(
        int $actorUserId,
        array $payload,
        string $requestKey,
        string $correlationId,
    ): TelegramAdministratorServiceOperationResult {
        $reasonCode = $this->payloadString($payload, 'reason_code');
        $reason = $this->payloadString($payload, 'reason');
        $targets = $payload['targets'] ?? null;
        if (! is_array($targets) || $targets === []) {
            throw new DomainException('Stored complimentary Service targets are invalid.');
        }
        $administratorId = $this->administratorId($actorUserId, 'services.grant_batch');
        $items = [];
        foreach ($targets as $target) {
            if (! is_array($target)
                || ! is_string($target['user'] ?? null)
                || ! is_string($target['offering'] ?? null)) {
                throw new DomainException('Stored complimentary Service target is invalid.');
            }
            $items[] = [
                'user_id' => (int) $this->user($target['user'])->id,
                'plan_offering_id' => $this->offeringId($target['offering']),
            ];
        }
        $context = $this->operationalContext(
            $administratorId,
            $requestKey,
            $correlationId,
            $reasonCode,
            $reason,
        );
        $created = $this->serviceGrants->create($context, $items);
        $receipt = $this->serviceGrants->resume($created->batchPublicId, $context);

        return new TelegramAdministratorServiceOperationResult(sprintf(
            'Complimentary Service batch %s: state=%s items=%d succeeded=%d failed=%d.',
            $receipt->batchPublicId,
            $receipt->state,
            $receipt->itemCount,
            $receipt->succeededCount,
            $receipt->failedCount,
        ));
    }

    /** @param array<string,mixed> $payload */
    private function executeServiceBatchControl(
        int $actorUserId,
        array $payload,
        string $requestKey,
        string $correlationId,
    ): TelegramAdministratorServiceOperationResult {
        [$action, $batch, $reasonCode, $reason] = $this->operationPayload(
            $payload,
            ['action', 'batch', 'reason_code', 'reason'],
        );
        $administratorId = $this->administratorId($actorUserId, 'services.grant_batch');
        $context = $this->operationalContext(
            $administratorId,
            $requestKey,
            $correlationId,
            $reasonCode,
            $reason,
        );
        $receipt = match ($action) {
            'resume' => $this->serviceGrants->resume($batch, $context),
            'pause' => $this->serviceGrants->pause($batch, $context),
            'activate' => $this->serviceGrants->activate($batch, $context),
            'cancel' => $this->serviceGrants->cancel($batch, $context),
            default => throw new DomainException('Stored Service batch control action is invalid.'),
        };

        return new TelegramAdministratorServiceOperationResult(sprintf(
            'Service batch %s: state=%s items=%d succeeded=%d failed=%d replayed=%s.',
            $receipt->batchPublicId,
            $receipt->state,
            $receipt->itemCount,
            $receipt->succeededCount,
            $receipt->failedCount,
            $receipt->replayed ? 'yes' : 'no',
        ));
    }

    /** @param array<string,mixed> $payload */
    private function executeEntitlementConfirm(
        int $actorUserId,
        array $payload,
        string $requestKey,
        string $correlationId,
    ): TelegramAdministratorServiceOperationResult {
        [$batch, $reasonCode, $reason] = $this->operationPayload(
            $payload,
            ['batch', 'reason_code', 'reason'],
        );
        $administratorId = $this->administratorId($actorUserId, 'services.grant_batch');
        $context = $this->operationalContext(
            $administratorId,
            $requestKey,
            $correlationId,
            $reasonCode,
            $reason,
        );
        $this->entitlementGrants->confirm($batch, $context);
        $receipt = $this->entitlementGrants->resume($batch, $context);

        return new TelegramAdministratorServiceOperationResult($this->entitlementSummary($receipt));
    }

    /** @param array<string,mixed> $payload */
    private function executeEntitlementBatchControl(
        int $actorUserId,
        array $payload,
        string $requestKey,
        string $correlationId,
    ): TelegramAdministratorServiceOperationResult {
        [$action, $batch, $reasonCode, $reason] = $this->operationPayload(
            $payload,
            ['action', 'batch', 'reason_code', 'reason'],
        );
        $administratorId = $this->administratorId($actorUserId, 'services.grant_batch');
        $context = $this->operationalContext(
            $administratorId,
            $requestKey,
            $correlationId,
            $reasonCode,
            $reason,
        );
        $receipt = match ($action) {
            'resume' => $this->entitlementGrants->resume($batch, $context),
            'pause' => $this->entitlementGrants->pause($batch, $context),
            'activate' => $this->entitlementGrants->activate($batch, $context),
            'cancel' => $this->entitlementGrants->cancel($batch, $context),
            default => throw new DomainException('Stored entitlement batch control action is invalid.'),
        };

        return new TelegramAdministratorServiceOperationResult($this->entitlementSummary($receipt));
    }

    private function administratorId(int $actorUserId, string $permission): int
    {
        return $this->administrators->authorizeUser($actorUserId, $permission);
    }

    private function operationalContext(
        int $administratorId,
        string $requestKey,
        string $correlationId,
        string $reasonCode,
        string $reason,
    ): ServiceOperationalContext {
        return new ServiceOperationalContext(
            $requestKey,
            $correlationId,
            $reasonCode,
            $reason,
            $administratorId,
        );
    }

    /** @return object{id:int|string,public_id:string,account_type:string,account_status:string} */
    private function user(string $publicId): object
    {
        $this->assertUlid($publicId, 'User public ID');
        /** @var object{id:int|string,public_id:string,account_type:string,account_status:string}|null $row */
        $row = $this->database->connection()->table('users')
            ->where('public_id', $publicId)
            ->first(['id', 'public_id', 'account_type', 'account_status']);
        if ($row === null
            || $row->account_status !== 'active'
            || ! in_array($row->account_type, ['customer', 'agent'], true)) {
            throw new DomainException('Target user is not an active customer or agent.');
        }

        return $row;
    }

    private function offeringId(string $code): int
    {
        $this->assertCode($code, 'Plan Offering code');
        $id = $this->database->connection()->table('plan_offerings')
            ->where('code', $code)
            ->where('state', 'active')
            ->value('id');
        if (! is_int($id) && ! is_string($id)) {
            throw new DomainException('Plan Offering does not exist or is inactive.');
        }

        return (int) $id;
    }

    private function targetId(string $code): int
    {
        $this->assertCode($code, 'Service target code');
        $id = $this->database->connection()->table('panel_service_targets')
            ->where('code', $code)
            ->where('state', 'active')
            ->value('id');
        if (! is_int($id) && ! is_string($id)) {
            throw new DomainException('Service target does not exist or is inactive.');
        }

        return (int) $id;
    }

    private function salesServerId(string $code): int
    {
        $this->assertCode($code, 'Sales Server code');
        $id = $this->database->connection()->table('sales_servers')
            ->where('code', $code)
            ->where('state', 'active')
            ->value('id');
        if (! is_int($id) && ! is_string($id)) {
            throw new DomainException('Sales Server does not exist or is inactive.');
        }

        return (int) $id;
    }

    /** @return object{id:int|string,user_id:int|string,lifecycle_state:string} */
    private function serviceSnapshot(string $publicId): object
    {
        /** @var object{id:int|string,user_id:int|string,lifecycle_state:string}|null $row */
        $row = $this->database->connection()->table('service_subscriptions')
            ->where('public_id', $publicId)
            ->first(['id', 'user_id', 'lifecycle_state']);
        if ($row === null) {
            throw new DomainException('Service Subscription does not exist.');
        }

        return $row;
    }

    /** @return object{public_id:string,state:string,item_count:int|string,succeeded_count:int|string,failed_count:int|string} */
    private function serviceBatchSnapshot(string $publicId): object
    {
        /** @var object{public_id:string,state:string,item_count:int|string,succeeded_count:int|string,failed_count:int|string}|null $row */
        $row = $this->database->connection()->table('service_batch_grants')
            ->where('public_id', $publicId)
            ->first(['public_id', 'state', 'item_count', 'succeeded_count', 'failed_count']);
        if ($row === null) {
            throw new DomainException('Service batch grant does not exist.');
        }

        return $row;
    }

    /** @param object{public_id:string,state:string,item_count:int|string,succeeded_count:int|string,failed_count:int|string} $batch */
    private function serviceBatchSummary(object $batch): string
    {
        return sprintf(
            'Service batch %s: state=%s items=%d succeeded=%d failed=%d.',
            $batch->public_id,
            $batch->state,
            (int) $batch->item_count,
            (int) $batch->succeeded_count,
            (int) $batch->failed_count,
        );
    }

    private function entitlementSummary(ServiceEntitlementGrantBatchReceipt $receipt): string
    {
        return sprintf(
            'Entitlement batch %s: state=%s scope=%s items=%d pending=%d queued=%d succeeded=%d failed=%d review=%d cancelled=%d data_bytes=%s days=%s notify=%s.',
            $receipt->batchPublicId,
            $receipt->state,
            $receipt->selectionMode,
            $receipt->itemCount,
            $receipt->pendingCount,
            $receipt->queuedCount,
            $receipt->succeededCount,
            $receipt->failedCount,
            $receipt->needsReviewCount,
            $receipt->cancelledCount,
            $receipt->dataBytes === null ? '-' : (string) $receipt->dataBytes,
            $receipt->durationDays === null ? '-' : (string) $receipt->durationDays,
            $receipt->notifyCustomers ? 'yes' : 'no',
        );
    }

    /**
     * @return list<array{user:string,offering:string}>
     */
    private function serviceGrantTargets(string $input): array
    {
        $targets = [];
        foreach (array_filter(array_map('trim', explode(',', $input))) as $entry) {
            $parts = explode(':', $entry, 2);
            if (count($parts) !== 2) {
                throw new DomainException('Complimentary Service target must be USER_PUBLIC_ID:OFFERING_CODE.');
            }
            [$userPublicId, $offeringCode] = $parts;
            $this->user($userPublicId);
            $this->offeringId($offeringCode);
            $targets[] = ['user' => $userPublicId, 'offering' => $offeringCode];
        }
        if ($targets === [] || count($targets) > 20) {
            throw new DomainException('Telegram complimentary Service batch must contain between 1 and 20 targets.');
        }

        return $targets;
    }

    /** @return array{0:?int,1:?int} */
    private function entitlementPackage(string $dataMb, string $days): array
    {
        $dataBytes = null;
        if ($dataMb !== '-') {
            if (preg_match('/\A[1-9][0-9]{0,6}\z/', $dataMb) !== 1) {
                throw new DomainException('Entitlement data amount must be an integer MB value or -.');
            }
            $dataBytes = (int) $dataMb * 1_048_576;
        }
        $durationDays = null;
        if ($days !== '-') {
            if (preg_match('/\A[1-9][0-9]{0,4}\z/', $days) !== 1) {
                throw new DomainException('Entitlement duration must be an integer day value or -.');
            }
            $durationDays = (int) $days;
        }
        if ($dataBytes === null && $durationDays === null) {
            throw new DomainException('Entitlement grant requires data, days, or both.');
        }

        return [$dataBytes, $durationDays];
    }

    /** @return array{0:string,1:string} */
    private function splitHead(string $input): array
    {
        $parts = preg_split('/\s+/', trim($input), 2);
        if (! is_array($parts) || $parts === [] || $parts[0] === '') {
            throw new DomainException('Telegram administrator Service operation command is incomplete.');
        }

        return [$parts[0], $parts[1] ?? ''];
    }

    /**
     * Parse N fixed whitespace-delimited fields followed by the free-form reason.
     *
     * @return list<string>
     */
    private function fixedWithReason(string $input, int $fixedCount): array
    {
        $parts = preg_split('/\s+/', trim($input), $fixedCount + 2);
        if (! is_array($parts) || count($parts) !== $fixedCount + 1) {
            throw new DomainException('Telegram administrator Service operation fields are incomplete.');
        }
        $reason = trim($parts[$fixedCount]);
        if ($reason === '') {
            throw new DomainException('Telegram administrator Service operation reason is required.');
        }

        return $parts;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function operationPayload(array $payload, array $keys): array
    {
        $values = [];
        foreach ($keys as $key) {
            $values[] = $this->payloadString($payload, $key);
        }

        return $values;
    }

    /** @param array<string,mixed> $payload */
    private function payloadString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw new DomainException('Stored Telegram administrator Service operation payload is invalid.');
        }

        return $value;
    }

    private function assertUlid(string $value, string $label): void
    {
        if (! Str::isUlid($value)) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function assertCode(string $value, string $label): void
    {
        if (preg_match('/\A[a-z0-9][a-z0-9._-]{1,63}\z/', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }
}
