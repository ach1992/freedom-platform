<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use App\Modules\Orders\Application\NonPaidOrderService;
use App\Modules\Orders\Application\OrderSourceAuthorizationService;
use App\Modules\Panels\Application\Contracts\PanelServiceStatus;
use App\Modules\Panels\Application\Contracts\RemoteServiceSnapshot;
use App\Modules\Panels\Application\Exceptions\AuthoritativePanelLookupUnavailable;
use App\Shared\Application\Clock;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * @phpstan-type ImportRow object{id:int|string,public_id:string,request_key_hash:string,actor_administrator_id:int|string,user_id:int|string,plan_offering_id:int|string,service_target_id:int|string,subscription_link_hash:string,registered_host:string,remote_service_id:string,remote_username:string,remote_canonical_hash:string,remote_status:string,state:string,order_source_authorization_id:int|string|null,order_id:int|string|null,service_subscription_id:int|string|null,audit_log_id:int|string|null,correlation_id:string,attached_at:?string}
 */
final readonly class ServiceImportService
{
    private const PERMISSION = 'services.import';

    private const EVIDENCE_AUTHORITY = 'service_operational_evidence_v1';

    private const SERVICE_AUTHORITY = 'service_import_attach_v1';

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private AdministratorPermissionAuthorizer $authorizer,
        private ServiceOperationalAuthorityGuard $authority,
        private ServiceOperationalDatabaseCapability $databaseCapability,
        private ProvisioningPanelAdapterResolver $adapters,
        private OrderSourceAuthorizationService $sourceAuthorizations,
        private NonPaidOrderService $orders,
        private ServiceOperationalAudit $audit,
    ) {}

    /** @requirement SVC-008 PRV-003 SEC-002 SEC-005 SEC-008 DAT-003 QUA-004 */
    public function preview(
        string $subscriptionLink,
        int $serviceTargetId,
        int $userId,
        int $planOfferingId,
        ServiceOperationalContext $context,
    ): ServiceImportReceipt {
        $this->authority->assertFinalized();
        $this->authorizer->authorize($context->actorAdministratorId, self::PERMISSION);
        if ($serviceTargetId < 1 || $userId < 1 || $planOfferingId < 1) {
            throw new DomainException('Service import identity is invalid.');
        }

        [$registeredHost, $remoteServiceId, $linkHash] = $this->validatedLinkIdentity($subscriptionLink, $serviceTargetId);
        $snapshot = $this->lookupRemote($serviceTargetId, $remoteServiceId);
        $this->assertImportableSnapshot($snapshot, $remoteServiceId);

        $connection = $this->database->connection();
        /** @var ImportRow|null $existing */
        $existing = $connection->table('service_imports')->where('request_key_hash', $context->requestHash())->first();
        if ($existing !== null) {
            $this->assertReplayMatches(
                $existing,
                $context,
                $userId,
                $planOfferingId,
                $serviceTargetId,
                $registeredHost,
                $remoteServiceId,
                $snapshot,
                $linkHash,
            );

            return $this->receipt($existing, true);
        }

        $publicId = (string) Str::ulid();
        $this->setEvidenceAuthority($connection);
        try {
            $id = (int) $connection->table('service_imports')->insertGetId([
                'public_id' => $publicId,
                'request_key_hash' => $context->requestHash(),
                'actor_administrator_id' => $context->actorAdministratorId,
                'user_id' => $userId,
                'plan_offering_id' => $planOfferingId,
                'service_target_id' => $serviceTargetId,
                'subscription_link_hash' => $linkHash,
                'registered_host' => $registeredHost,
                'remote_service_id' => $remoteServiceId,
                'remote_username' => $snapshot->username,
                'remote_canonical_hash' => $snapshot->canonicalHash,
                'remote_status' => $snapshot->status->value,
                'state' => 'previewed',
                'order_source_authorization_id' => null,
                'order_id' => null,
                'service_subscription_id' => null,
                'audit_log_id' => null,
                'correlation_id' => $context->correlationId,
                'created_at' => $this->timestamp(),
                'attached_at' => null,
            ]);
        } finally {
            $this->clearEvidenceAuthority($connection);
        }

        /** @var ImportRow|null $row */
        $row = $connection->table('service_imports')->where('id', $id)->first();
        if ($row === null) {
            throw new RuntimeException('Service import preview disappeared after creation.');
        }

        return $this->receipt($row, false);
    }

    /** @requirement SVC-008 PRV-003 ARCH-003 ARCH-004 DAT-003 SEC-002 SEC-008 QUA-004 */
    public function attach(string $importPublicId, ServiceOperationalContext $context): ServiceImportReceipt
    {
        $this->authority->assertFinalized();
        $this->authorizer->authorize($context->actorAdministratorId, self::PERMISSION);
        if (! Str::isUlid($importPublicId)) {
            throw new DomainException('Service import public ID is invalid.');
        }

        /** @var ImportRow|null $preview */
        $preview = $this->database->connection()->table('service_imports')->where('public_id', $importPublicId)->first();
        if ($preview === null) {
            throw new DomainException('Service import preview does not exist.');
        }
        $this->assertContextMatches($preview, $context);
        if ($preview->state === 'attached') {
            return $this->receipt($preview, true);
        }
        if ($preview->state !== 'previewed') {
            throw new DomainException('Service import is not attachable from its current state.');
        }

        // Remote provider I/O is deliberately outside the DB transaction. The configured target
        // resolver retains the accepted endpoint/TLS/DNS-pinning boundary; the subscription link
        // itself is never fetched.
        $snapshot = $this->lookupRemote((int) $preview->service_target_id, $preview->remote_service_id);
        $this->assertImportableSnapshot($snapshot, $preview->remote_service_id);
        if (! hash_equals($preview->remote_canonical_hash, $snapshot->canonicalHash)
            || ! hash_equals($preview->remote_username, $snapshot->username)) {
            throw new DomainException('Remote Service changed after import preview.');
        }

        return $this->database->connection()->transaction(function (Connection $connection) use ($preview, $snapshot, $context): ServiceImportReceipt {
            /** @var ImportRow|null $locked */
            $locked = $connection->table('service_imports')->where('id', (int) $preview->id)->lockForUpdate()->first();
            if ($locked === null) {
                throw new RuntimeException('Service import preview disappeared before attach.');
            }
            $this->assertContextMatches($locked, $context);
            if ($locked->state === 'attached') {
                return $this->receipt($locked, true);
            }
            if ($locked->state !== 'previewed'
                || ! hash_equals($locked->remote_canonical_hash, $snapshot->canonicalHash)
                || ! hash_equals($locked->remote_username, $snapshot->username)) {
                throw new DomainException('Service import preview is stale.');
            }

            $source = $this->sourceAuthorizations->authorizeAdministratorGrant(
                'service-import:'.$locked->public_id,
                $context->actorAdministratorId,
                (int) $locked->user_id,
                (int) $locked->plan_offering_id,
                $context->reasonCode,
                $context->correlationId,
            );
            $order = $this->orders->materialize($source->publicId, $context->correlationId);

            /** @var object{id:int|string,public_id:string}|null $item */
            $item = $connection->table('order_items')
                ->where('order_id', $order->orderId)
                ->where('line_number', 1)
                ->first(['id', 'public_id']);
            if ($item === null) {
                throw new RuntimeException('Imported Service Order Item is unavailable.');
            }

            /** @var object{id:int|string,public_id:string,user_id:int|string,service_target_id:int|string|null,remote_service_id:?string}|null $service */
            $service = $connection->table('service_subscriptions')->where('order_item_id', (int) $item->id)->lockForUpdate()->first([
                'id', 'public_id', 'user_id', 'service_target_id', 'remote_service_id',
            ]);
            if ($service === null) {
                $servicePublicId = (string) Str::ulid();
                $serviceId = (int) $connection->table('service_subscriptions')->insertGetId([
                    'public_id' => $servicePublicId,
                    'order_id' => $order->orderId,
                    'order_item_id' => (int) $item->id,
                    'user_id' => $order->userId,
                    'creation_correlation_id' => $context->correlationId,
                    'created_at' => $this->timestamp(),
                    'updated_at' => $this->timestamp(),
                ]);
                $service = (object) [
                    'id' => $serviceId,
                    'public_id' => $servicePublicId,
                    'user_id' => $order->userId,
                    'service_target_id' => null,
                    'remote_service_id' => null,
                ];
            }
            if ((int) $service->user_id !== (int) $locked->user_id) {
                throw new RuntimeException('Imported Service owner is inconsistent with source authority.');
            }

            if ($service->service_target_id !== null || $service->remote_service_id !== null) {
                if ((int) $service->service_target_id === (int) $locked->service_target_id
                    && is_string($service->remote_service_id)
                    && hash_equals($service->remote_service_id, $locked->remote_service_id)) {
                    throw new RuntimeException('Service import evidence is incomplete for an already bound Service.');
                }
                throw new DomainException('Imported Service Order is already bound to another remote identity.');
            }

            $auditId = $this->audit->record(
                $connection,
                'service.operational.import.attached',
                'service_subscription',
                $service->public_id,
                $context,
                [
                    'user_id' => (int) $locked->user_id,
                    'service_target_id' => null,
                    'remote_service_id' => null,
                ],
                [
                    'user_id' => (int) $locked->user_id,
                    'service_target_id' => (int) $locked->service_target_id,
                    'remote_service_id_hash' => hash('sha256', $locked->remote_service_id),
                    'remote_canonical_hash' => $locked->remote_canonical_hash,
                ],
            );

            $this->setEvidenceAuthority($connection);
            try {
                $updatedImport = $connection->table('service_imports')
                    ->where('id', (int) $locked->id)
                    ->where('state', 'previewed')
                    ->update([
                        'state' => 'attaching',
                        'order_source_authorization_id' => $source->authorizationId,
                        'order_id' => $order->orderId,
                        'service_subscription_id' => (int) $service->id,
                        'audit_log_id' => $auditId,
                    ]);
            } finally {
                $this->clearEvidenceAuthority($connection);
            }
            if ($updatedImport !== 1) {
                throw new RuntimeException('Service import lost its authoritative preview state.');
            }

            $this->setServiceAuthority($connection, self::SERVICE_AUTHORITY, (int) $locked->id, $locked->request_key_hash, $locked->correlation_id);
            try {
                $updatedService = $connection->table('service_subscriptions')
                    ->where('id', (int) $service->id)
                    ->whereNull('service_target_id')
                    ->whereNull('remote_service_id')
                    ->update([
                        'service_target_id' => (int) $locked->service_target_id,
                        'remote_service_id' => $locked->remote_service_id,
                        'provisioned_at' => $this->timestamp(),
                        'updated_at' => $this->timestamp(),
                    ]);
            } finally {
                $this->clearServiceAuthority($connection);
            }
            if ($updatedService !== 1) {
                throw new RuntimeException('Service import remote binding lost its authoritative state.');
            }

            $this->setEvidenceAuthority($connection);
            try {
                $updatedImport = $connection->table('service_imports')
                    ->where('id', (int) $locked->id)
                    ->where('state', 'attaching')
                    ->update([
                        'state' => 'attached',
                        'attached_at' => $this->timestamp(),
                    ]);
            } finally {
                $this->clearEvidenceAuthority($connection);
            }
            if ($updatedImport !== 1) {
                throw new RuntimeException('Service import did not finalize its evidence.');
            }

            /** @var ImportRow|null $attached */
            $attached = $connection->table('service_imports')->where('id', (int) $locked->id)->first();
            if ($attached === null) {
                throw new RuntimeException('Service import evidence disappeared after attach.');
            }

            return $this->receipt($attached, false);
        }, 3);
    }

    /** @return array{string,string,string} */
    private function validatedLinkIdentity(string $link, int $serviceTargetId): array
    {
        if ($link !== trim($link) || strlen($link) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $link) === 1) {
            throw new DomainException('Service import subscription link is invalid.');
        }
        $parts = parse_url($link);
        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || isset($parts['port'])) {
            throw new DomainException('Service import subscription link is invalid.');
        }
        $host = strtolower(rtrim((string) $parts['host'], '.'));
        if (! $this->validPublicHostname($host) || ! isset($this->registeredTargetHosts($serviceTargetId)[$host])) {
            throw new DomainException('Service import subscription host is not registered for the selected target.');
        }
        $path = $parts['path'] ?? null;
        if (! is_string($path) || $path === '' || $path === '/') {
            throw new DomainException('Service import subscription link does not contain a remote identity.');
        }
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $part): bool => $part !== ''));
        $encodedRemoteId = $segments === [] ? '' : $segments[array_key_last($segments)];
        $remoteId = rawurldecode($encodedRemoteId);
        if ($remoteId === ''
            || rawurlencode($remoteId) !== $encodedRemoteId
            || strlen($remoteId) > 191
            || preg_match('/\A[A-Za-z0-9._:-]+\z/', $remoteId) !== 1) {
            throw new DomainException('Service import remote identity is invalid.');
        }

        return [$host, $remoteId, hash('sha256', $link)];
    }

    /** @return array<string, true> */
    private function registeredTargetHosts(int $serviceTargetId): array
    {
        /** @var object{target_state:string,connection_state:string,base_url:string}|null $target */
        $target = $this->database->connection()->table('panel_service_targets as target')
            ->join('panel_connections as connection', 'connection.id', '=', 'target.panel_connection_id')
            ->where('target.id', $serviceTargetId)
            ->first(['target.state as target_state', 'connection.state as connection_state', 'connection.base_url']);
        if ($target === null || $target->target_state !== 'active' || $target->connection_state !== 'active') {
            throw new DomainException('Service import target is not active.');
        }

        $hosts = [];
        $profileHosts = $this->database->connection()->table('panel_target_protocol_profiles as assignment')
            ->join('panel_protocol_profiles as profile', 'profile.id', '=', 'assignment.panel_protocol_profile_id')
            ->where('assignment.panel_service_target_id', $serviceTargetId)
            ->where('profile.state', 'active')
            ->get(['profile.host', 'profile.sni']);
        foreach ($profileHosts as $profile) {
            foreach ([$profile->host, $profile->sni] as $candidate) {
                if (is_string($candidate) && $this->validPublicHostname(strtolower(rtrim($candidate, '.')))) {
                    $hosts[strtolower(rtrim($candidate, '.'))] = true;
                }
            }
        }
        if ($hosts === []) {
            throw new DomainException('Service import target has no registered service domain.');
        }

        return $hosts;
    }

    private function validPublicHostname(string $host): bool
    {
        if ($host === '' || strlen($host) > 253 || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return false;
        }

        return filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    private function lookupRemote(int $serviceTargetId, string $remoteServiceId): RemoteServiceSnapshot
    {
        try {
            $snapshot = $this->adapters->resolve($serviceTargetId)->findByRemoteId($remoteServiceId);
        } catch (AuthoritativePanelLookupUnavailable $exception) {
            throw new DomainException('Authoritative remote Service lookup is unavailable.', 0, $exception);
        } catch (Throwable $exception) {
            throw new DomainException('Remote Service verification failed closed.', 0, $exception);
        }
        if ($snapshot === null) {
            throw new DomainException('Remote Service does not exist on the selected target.');
        }

        return $snapshot;
    }

    private function assertImportableSnapshot(RemoteServiceSnapshot $snapshot, string $remoteServiceId): void
    {
        if (! hash_equals($remoteServiceId, $snapshot->remoteId)
            || $snapshot->status !== PanelServiceStatus::Active) {
            throw new DomainException('Remote Service is not importable.');
        }
    }

    /** @param ImportRow $row */
    private function assertContextMatches(object $row, ServiceOperationalContext $context): void
    {
        if (! hash_equals($row->request_key_hash, $context->requestHash())
            || ! hash_equals($row->correlation_id, $context->correlationId)
            || (int) $row->actor_administrator_id !== $context->actorAdministratorId) {
            throw new DomainException('Service import request identity conflicts with existing evidence.');
        }
    }

    /** @param ImportRow $row */
    private function assertReplayMatches(
        object $row,
        ServiceOperationalContext $context,
        int $userId,
        int $planOfferingId,
        int $serviceTargetId,
        string $registeredHost,
        string $remoteServiceId,
        RemoteServiceSnapshot $snapshot,
        string $linkHash,
    ): void {
        $this->assertContextMatches($row, $context);
        if ((int) $row->user_id !== $userId
            || (int) $row->plan_offering_id !== $planOfferingId
            || (int) $row->service_target_id !== $serviceTargetId
            || ! hash_equals($row->registered_host, $registeredHost)
            || ! hash_equals($row->remote_service_id, $remoteServiceId)
            || ! hash_equals($row->remote_username, $snapshot->username)
            || ! hash_equals($row->remote_canonical_hash, $snapshot->canonicalHash)
            || ! hash_equals($row->subscription_link_hash, $linkHash)) {
            throw new DomainException('Service import request fingerprint conflicts with existing evidence.');
        }
    }

    /** @param ImportRow $row */
    private function receipt(object $row, bool $replayed): ServiceImportReceipt
    {
        $servicePublicId = null;
        $orderPublicId = null;
        if ($row->service_subscription_id !== null) {
            $value = $this->database->connection()->table('service_subscriptions')->where('id', (int) $row->service_subscription_id)->value('public_id');
            $servicePublicId = is_string($value) ? $value : null;
        }
        if ($row->order_id !== null) {
            $value = $this->database->connection()->table('orders')->where('id', (int) $row->order_id)->value('public_id');
            $orderPublicId = is_string($value) ? $value : null;
        }

        return new ServiceImportReceipt(
            (int) $row->id,
            $row->public_id,
            $row->state,
            (int) $row->user_id,
            (int) $row->plan_offering_id,
            (int) $row->service_target_id,
            $row->remote_service_id,
            $row->remote_username,
            $servicePublicId,
            $orderPublicId,
            $replayed,
        );
    }

    private function setEvidenceAuthority(Connection $connection): void
    {
        $this->databaseCapability->apply($connection);
        try {
            $connection->statement('SET @app_service_operational_evidence_authority = ?', [self::EVIDENCE_AUTHORITY]);
        } catch (Throwable $exception) {
            $this->databaseCapability->clear($connection);
            throw $exception;
        }
    }

    private function clearEvidenceAuthority(Connection $connection): void
    {
        try {
            $this->databaseCapability->clear($connection);
        } finally {
            $connection->statement('SET @app_service_operational_evidence_authority = NULL');
        }
    }

    private function setServiceAuthority(Connection $connection, string $authority, int $evidenceId, string $requestHash, string $correlationId): void
    {
        $this->databaseCapability->apply($connection);
        try {
            $connection->statement('SET @app_service_operational_authority = ?', [$authority]);
            $connection->statement('SET @app_service_operational_evidence_id = ?', [$evidenceId]);
            $connection->statement('SET @app_service_operational_request_hash = ?', [$requestHash]);
            $connection->statement('SET @app_service_operational_correlation_id = ?', [$correlationId]);
        } catch (Throwable $exception) {
            $this->databaseCapability->clear($connection);
            throw $exception;
        }
    }

    private function clearServiceAuthority(Connection $connection): void
    {
        try {
            $this->databaseCapability->clear($connection);
        } finally {
            $connection->statement(
                'SET @app_service_operational_authority = NULL, @app_service_operational_evidence_id = NULL, @app_service_operational_request_hash = NULL, @app_service_operational_correlation_id = NULL',
            );
        }
    }

    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
