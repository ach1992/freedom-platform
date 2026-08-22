<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Orders\Application\QuoteAgentPricingContext;
use App\Modules\Orders\Application\QuotePricingInput;
use App\Modules\Orders\Application\QuoteReceipt;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Application\ServicePackageQuoteContext;
use App\Modules\Orders\Domain\QuoteAction;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use App\Modules\Panels\Application\Contracts\PanelServiceStatus;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * @phpstan-type ServiceFacts object{
 *     id:int|string, public_id:string, user_id:int|string, account_type:string, plan_offering_id:int|string,
 *     service_target_id:int|string|null, remote_service_id:string|null, provisioned_at:string|null,
 *     lifecycle_state:string, lifecycle_version:int|string, remote_identity_generation:int|string,
 *     remote_deleted_at:string|null, offering_state:string, auto_renew_allowed:int|bool|string
 * }
 */
/** @requirement SVC-007 BUY-002 DAT-002 DAT-003 SEC-002 QUA-001 */
final readonly class ServiceAutoRenewConfigurationService
{
    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private QuoteService $quotes,
        private ProvisioningPanelAdapterResolver $panelAdapters,
    ) {}

    public function configure(
        string $requestKey,
        int $actorUserId,
        string $servicePublicId,
        string $packageCode,
        bool $enabled,
        string $correlationId,
    ): ServiceAutoRenewConfigurationReceipt {
        $this->assertToken($requestKey, 'Auto-renew configuration request key', 8, 128);
        if ($actorUserId < 1) {
            throw new DomainException('Auto-renew configuration actor user ID must be positive.');
        }
        $this->assertUlid($servicePublicId, 'Auto-renew Service public ID');
        $this->assertToken($packageCode, 'Auto-renew package code', 2, 64);
        $this->assertToken($correlationId, 'Auto-renew configuration correlation ID', 8, 64);

        $requestHash = hash('sha256', $requestKey);
        $payloadHash = hash('sha256', json_encode([
            'actor_user_id' => $actorUserId,
            'service_public_id' => $servicePublicId,
            'package_code' => $packageCode,
            'enabled' => $enabled,
        ], JSON_THROW_ON_ERROR));

        $replay = $this->replay($requestHash, $payloadHash, $servicePublicId);
        if ($replay !== null) {
            return $replay;
        }

        $facts = $this->serviceFacts($servicePublicId);
        $this->assertOwnedService($facts, $actorUserId);

        if (! $enabled) {
            try {
                return $this->disableExisting(
                    $requestHash,
                    $payloadHash,
                    $actorUserId,
                    $facts,
                    $packageCode,
                    $correlationId,
                );
            } catch (QueryException $exception) {
                $replay = $this->replay($requestHash, $payloadHash, $servicePublicId);
                if ($replay !== null) {
                    return $replay;
                }

                throw $exception;
            }
        }

        $this->assertEligibleService($facts);
        if ($facts->service_target_id === null || ! is_string($facts->remote_service_id) || $facts->remote_service_id === '') {
            throw new DomainException('Auto-renew requires a provisioned remote Service identity.');
        }

        // Provider I/O is intentionally outside any database transaction.
        $adapter = $this->panelAdapters->resolve((int) $facts->service_target_id);
        $remote = $adapter->findByRemoteId($facts->remote_service_id);
        if ($remote === null || ! hash_equals($facts->remote_service_id, $remote->remoteId)) {
            throw new DomainException('Auto-renew could not verify the current remote Service identity.');
        }
        if ($remote->expiresAt === null) {
            throw new DomainException('Auto-renew requires a finite authoritative remote expiry.');
        }
        if (in_array($remote->status, [PanelServiceStatus::Unknown, PanelServiceStatus::Disabled], true)) {
            throw new DomainException('Auto-renew requires a remotely renewable Service state.');
        }

        $quote = $this->acceptanceQuote($requestHash, $actorUserId, $facts, $packageCode, $servicePublicId, $correlationId);
        if ($quote->action !== QuoteAction::Renew || $quote->servicePackage === null || $quote->finalPriceIrr < 1) {
            throw new DomainException('Auto-renew requires a positive renewal package Quote.');
        }

        try {
            return $this->database->connection()->transaction(function (Connection $connection) use (
                $requestHash,
                $payloadHash,
                $actorUserId,
                $servicePublicId,
                $packageCode,
                $facts,
                $remote,
                $quote,
                $correlationId,
            ): ServiceAutoRenewConfigurationReceipt {
                $replay = $this->replayOn($connection, $requestHash, $payloadHash, $servicePublicId);
                if ($replay !== null) {
                    return $replay;
                }

                $lockedFacts = $this->serviceFactsOn($connection, $servicePublicId, true);
                $this->assertOwnedService($lockedFacts, $actorUserId);
                $this->assertEligibleService($lockedFacts);
                if ((int) $lockedFacts->id !== (int) $facts->id
                    || (int) $lockedFacts->remote_identity_generation !== (int) $facts->remote_identity_generation
                    || $lockedFacts->service_target_id === null
                    || (int) $lockedFacts->service_target_id !== (int) $facts->service_target_id
                    || ! is_string($lockedFacts->remote_service_id)
                    || ! hash_equals($lockedFacts->remote_service_id, $remote->remoteId)) {
                    throw new DomainException('Service changed while auto-renew configuration was being accepted.');
                }

                $snapshot = $quote->servicePackage;
                if ($snapshot === null
                    || $snapshot->action !== QuoteAction::Renew
                    || $snapshot->serviceSubscriptionId !== (int) $lockedFacts->id
                    || ! hash_equals($snapshot->serviceSubscriptionPublicId, $servicePublicId)
                    || $snapshot->serviceTargetId !== (int) $lockedFacts->service_target_id
                    || $snapshot->remoteIdentityGeneration !== (int) $lockedFacts->remote_identity_generation
                    || $snapshot->lifecycleVersion !== (int) $lockedFacts->lifecycle_version
                    || ! hash_equals($snapshot->packageCode, $packageCode)) {
                    throw new DomainException('Auto-renew acceptance Quote is stale for the current Service or package.');
                }

                ServiceAutoRenewDatabaseAuthority::configuration(
                    $connection,
                    $actorUserId,
                    (int) $lockedFacts->id,
                    $quote->quoteId,
                    $correlationId,
                );
                try {
                    $config = $connection->table('service_auto_renew_configurations')
                        ->where('service_subscription_id', (int) $lockedFacts->id)
                        ->lockForUpdate()
                        ->first([
                            'id', 'renewal_package_id', 'enabled', 'accepted_price_irr', 'last_settled_price_irr',
                            'configuration_version',
                        ]);
                    $timestamp = $this->timestamp();
                    $evidenceHash = strtolower($remote->canonicalHash);
                    if ($config === null) {
                        $configurationVersion = 1;
                        $configurationId = (int) $connection->table('service_auto_renew_configurations')->insertGetId([
                            'service_subscription_id' => (int) $lockedFacts->id,
                            'renewal_package_id' => $snapshot->packageId,
                            'enabled' => true,
                            'accepted_price_irr' => $quote->finalPriceIrr,
                            'last_settled_price_irr' => null,
                            'observed_expires_at' => $this->databaseDateTime($remote->expiresAt),
                            'expiry_observed_at' => $timestamp,
                            'observed_expiry_evidence_hash' => $evidenceHash,
                            'observed_expiry_source' => 'remote_snapshot',
                            'observed_remote_identity_generation' => (int) $lockedFacts->remote_identity_generation,
                            'configuration_version' => $configurationVersion,
                            'last_correlation_id' => $correlationId,
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ]);
                    } else {
                        $configurationId = (int) $config->id;
                        // Every non-replayed enable/configure request is an explicit commercial re-acceptance.
                        // Advance the version even when the package and price happen to be unchanged so a
                        // previously blocked renewal cycle can be retried only after an explicit user action.
                        $configurationVersion = (int) $config->configuration_version + 1;
                        $connection->table('service_auto_renew_configurations')->where('id', $configurationId)->update([
                            'renewal_package_id' => $snapshot->packageId,
                            'enabled' => true,
                            'accepted_price_irr' => $quote->finalPriceIrr,
                            'last_settled_price_irr' => null,
                            'observed_expires_at' => $this->databaseDateTime($remote->expiresAt),
                            'expiry_observed_at' => $timestamp,
                            'observed_expiry_evidence_hash' => $evidenceHash,
                            'observed_expiry_source' => 'remote_snapshot',
                            'observed_remote_identity_generation' => (int) $lockedFacts->remote_identity_generation,
                            'configuration_version' => $configurationVersion,
                            'last_correlation_id' => $correlationId,
                            'updated_at' => $timestamp,
                        ]);
                    }

                    $this->recordHistory(
                        $connection,
                        $configurationId,
                        $configurationVersion,
                        $actorUserId,
                        true,
                        $snapshot->packageId,
                        $quote->finalPriceIrr,
                        $remote->expiresAt,
                        $evidenceHash,
                        'remote_snapshot',
                        (int) $lockedFacts->remote_identity_generation,
                        $requestHash,
                        $payloadHash,
                        $correlationId,
                    );

                    return new ServiceAutoRenewConfigurationReceipt(
                        $configurationId,
                        $servicePublicId,
                        true,
                        $packageCode,
                        $quote->finalPriceIrr,
                        $remote->expiresAt,
                        $configurationVersion,
                        false,
                    );
                } finally {
                    ServiceAutoRenewDatabaseAuthority::clear($connection);
                }
            }, 3);
        } catch (QueryException $exception) {
            $replay = $this->replay($requestHash, $payloadHash, $servicePublicId);
            if ($replay !== null) {
                return $replay;
            }

            throw $exception;
        }
    }

    /** @param ServiceFacts $facts */
    private function disableExisting(
        string $requestHash,
        string $payloadHash,
        int $actorUserId,
        object $facts,
        string $packageCode,
        string $correlationId,
    ): ServiceAutoRenewConfigurationReceipt {
        return $this->database->connection()->transaction(function (Connection $connection) use (
            $requestHash,
            $payloadHash,
            $actorUserId,
            $facts,
            $packageCode,
            $correlationId,
        ): ServiceAutoRenewConfigurationReceipt {
            $replay = $this->replayOn($connection, $requestHash, $payloadHash, (string) $facts->public_id);
            if ($replay !== null) {
                return $replay;
            }

            $lockedFacts = $this->serviceFactsOn($connection, (string) $facts->public_id, true);
            if ((int) $lockedFacts->user_id !== $actorUserId) {
                throw new DomainException('Auto-renew configuration is allowed only for the Service owner.');
            }
            $config = $connection->table('service_auto_renew_configurations')
                ->where('service_subscription_id', (int) $lockedFacts->id)
                ->lockForUpdate()
                ->first([
                    'id', 'renewal_package_id', 'enabled', 'accepted_price_irr', 'configuration_version',
                    'observed_expires_at', 'observed_expiry_evidence_hash', 'observed_expiry_source',
                    'observed_remote_identity_generation',
                ]);
            if ($config === null) {
                throw new DomainException('Auto-renew is not configured for this Service.');
            }
            $storedPackageCode = $connection->table('plan_offering_packages')
                ->where('id', (int) $config->renewal_package_id)
                ->value('code');
            if (! is_string($storedPackageCode) || ! hash_equals($storedPackageCode, $packageCode)) {
                throw new DomainException('Disable request must reference the currently configured renewal package.');
            }

            ServiceAutoRenewDatabaseAuthority::configuration(
                $connection,
                $actorUserId,
                (int) $lockedFacts->id,
                null,
                $correlationId,
            );
            try {
                $configurationVersion = (int) $config->configuration_version + ((bool) $config->enabled ? 1 : 0);
                $timestamp = $this->timestamp();
                if ((bool) $config->enabled) {
                    $connection->table('service_auto_renew_configurations')->where('id', (int) $config->id)->update([
                        'enabled' => false,
                        'configuration_version' => $configurationVersion,
                        'last_correlation_id' => $correlationId,
                        'updated_at' => $timestamp,
                    ]);
                }

                $observedExpiry = $config->observed_expires_at === null
                    ? null
                    : $this->storedDateTime((string) $config->observed_expires_at);
                $this->recordHistory(
                    $connection,
                    (int) $config->id,
                    $configurationVersion,
                    $actorUserId,
                    false,
                    (int) $config->renewal_package_id,
                    (int) $config->accepted_price_irr,
                    $observedExpiry,
                    $config->observed_expiry_evidence_hash === null ? null : (string) $config->observed_expiry_evidence_hash,
                    $config->observed_expiry_source === null ? null : (string) $config->observed_expiry_source,
                    $config->observed_remote_identity_generation === null ? null : (int) $config->observed_remote_identity_generation,
                    $requestHash,
                    $payloadHash,
                    $correlationId,
                );

                return new ServiceAutoRenewConfigurationReceipt(
                    (int) $config->id,
                    (string) $lockedFacts->public_id,
                    false,
                    $storedPackageCode,
                    (int) $config->accepted_price_irr,
                    $observedExpiry,
                    $configurationVersion,
                    false,
                );
            } finally {
                ServiceAutoRenewDatabaseAuthority::clear($connection);
            }
        }, 3);
    }

    /** @param ServiceFacts $facts */
    private function acceptanceQuote(
        string $requestHash,
        int $actorUserId,
        object $facts,
        string $packageCode,
        string $servicePublicId,
        string $correlationId,
    ): QuoteReceipt {
        $ttlMinutes = $this->boundedConfigInt('auto_renew.quote_ttl_minutes', 15, 1, 120);
        $agentContext = $facts->account_type === 'agent'
            ? QuoteAgentPricingContext::forRenewal($actorUserId)
            : null;

        return $this->quotes->create(
            'service.auto-renew.config.quote.'.substr($requestHash, 0, 64),
            $actorUserId,
            (int) $facts->plan_offering_id,
            new QuotePricingInput(
                QuoteOverrideSource::None,
                null,
                null,
                null,
                0,
                $this->clock->now()->modify('+'.$ttlMinutes.' minutes'),
            ),
            $correlationId,
            $agentContext,
            new ServicePackageQuoteContext($servicePublicId, $packageCode),
        );
    }

    private function replay(string $requestHash, string $payloadHash, string $servicePublicId): ?ServiceAutoRenewConfigurationReceipt
    {
        return $this->replayOn($this->database->connection(), $requestHash, $payloadHash, $servicePublicId);
    }

    private function replayOn(
        Connection $connection,
        string $requestHash,
        string $payloadHash,
        string $servicePublicId,
    ): ?ServiceAutoRenewConfigurationReceipt {
        $history = $connection->table('service_auto_renew_configuration_histories as h')
            ->join('service_auto_renew_configurations as c', 'c.id', '=', 'h.auto_renew_configuration_id')
            ->join('service_subscriptions as s', 's.id', '=', 'c.service_subscription_id')
            ->join('plan_offering_packages as p', 'p.id', '=', 'h.renewal_package_id')
            ->where('h.request_key_hash', $requestHash)
            ->first([
                'h.auto_renew_configuration_id', 'h.configuration_version', 'h.enabled', 'h.accepted_price_irr',
                'h.observed_expires_at', 'h.payload_hash', 's.public_id as service_public_id', 'p.code as package_code',
            ]);
        if ($history === null) {
            return null;
        }
        if (! hash_equals((string) $history->payload_hash, $payloadHash)
            || ! hash_equals((string) $history->service_public_id, $servicePublicId)) {
            throw new DomainException('Auto-renew configuration request key conflicts with an accepted request.');
        }

        return new ServiceAutoRenewConfigurationReceipt(
            (int) $history->auto_renew_configuration_id,
            $servicePublicId,
            (bool) $history->enabled,
            (string) $history->package_code,
            (int) $history->accepted_price_irr,
            $history->observed_expires_at === null ? null : $this->storedDateTime((string) $history->observed_expires_at),
            (int) $history->configuration_version,
            true,
        );
    }

    /** @return ServiceFacts */
    private function serviceFacts(string $servicePublicId): object
    {
        return $this->serviceFactsOn($this->database->connection(), $servicePublicId, false);
    }

    /** @return ServiceFacts */
    private function serviceFactsOn(Connection $connection, string $servicePublicId, bool $lock): object
    {
        $query = $connection->table('service_subscriptions as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->join('order_items as oi', 'oi.id', '=', 's.order_item_id')
            ->join('plan_offerings as o', 'o.id', '=', 'oi.plan_offering_id')
            ->where('s.public_id', $servicePublicId);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var ServiceFacts|null $row */
        $row = $query->first([
            's.id', 's.public_id', 's.user_id', 'u.account_type', 'oi.plan_offering_id as plan_offering_id', 's.service_target_id',
            's.remote_service_id', 's.provisioned_at', 's.lifecycle_state', 's.lifecycle_version',
            's.remote_identity_generation', 's.remote_deleted_at', 'o.state as offering_state', 'o.auto_renew_allowed',
        ]);
        if ($row === null) {
            throw new DomainException('Auto-renew Service does not exist.');
        }

        return $row;
    }

    /** @param ServiceFacts $facts */
    private function assertOwnedService(object $facts, int $actorUserId): void
    {
        if ((int) $facts->user_id !== $actorUserId) {
            throw new DomainException('Auto-renew configuration is allowed only for the Service owner.');
        }
    }

    /** @param ServiceFacts $facts */
    private function assertEligibleService(object $facts): void
    {
        if (! in_array($facts->account_type, ['customer', 'agent'], true)
            || ! in_array($facts->lifecycle_state, ['active', 'suspended'], true)
            || $facts->remote_deleted_at !== null
            || $facts->provisioned_at === null
            || $facts->service_target_id === null
            || ! is_string($facts->remote_service_id) || $facts->remote_service_id === ''
            || $facts->offering_state !== 'active'
            || ! (bool) $facts->auto_renew_allowed
            || (int) $facts->remote_identity_generation < 1) {
            throw new DomainException('Service is not eligible for auto-renew configuration.');
        }
    }

    private function recordHistory(
        Connection $connection,
        int $configurationId,
        int $configurationVersion,
        int $actorUserId,
        bool $enabled,
        int $renewalPackageId,
        int $acceptedPriceIrr,
        ?DateTimeImmutable $observedExpiry,
        ?string $evidenceHash,
        ?string $evidenceSource,
        ?int $remoteGeneration,
        string $requestHash,
        string $payloadHash,
        string $correlationId,
    ): void {
        $connection->table('service_auto_renew_configuration_histories')->insert([
            'auto_renew_configuration_id' => $configurationId,
            'configuration_version' => $configurationVersion,
            'actor_user_id' => $actorUserId,
            'enabled' => $enabled,
            'renewal_package_id' => $renewalPackageId,
            'accepted_price_irr' => $acceptedPriceIrr,
            'observed_expires_at' => $observedExpiry === null ? null : $this->databaseDateTime($observedExpiry),
            'observed_expiry_evidence_hash' => $evidenceHash,
            'observed_expiry_source' => $evidenceSource,
            'observed_remote_identity_generation' => $remoteGeneration,
            'request_key_hash' => $requestHash,
            'payload_hash' => $payloadHash,
            'correlation_id' => $correlationId,
            'created_at' => $this->timestamp(),
        ]);
    }

    private function boundedConfigInt(string $key, int $default, int $minimum, int $maximum): int
    {
        $value = config($key, $default);
        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum, 'max_range' => $maximum]]);
        if ($validated === false) {
            throw new RuntimeException($key.' configuration is invalid.');
        }

        return (int) $validated;
    }

    private function timestamp(): string
    {
        return $this->databaseDateTime($this->clock->now());
    }

    private function databaseDateTime(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function storedDateTime(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private function assertUlid(string $value, string $label): void
    {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function assertToken(string $value, string $label, int $minimum, int $maximum): void
    {
        if (strlen($value) < $minimum || strlen($value) > $maximum || preg_match('/\A[a-zA-Z0-9_.:-]+\z/', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }
}
