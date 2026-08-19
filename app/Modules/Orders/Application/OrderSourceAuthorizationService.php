<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application;

use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use App\Modules\Orders\Domain\OrderSourceType;
use App\Shared\Application\Clock;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

/**
 * @phpstan-type AuthorizationRow object{id:int|string,public_id:string,source_type:string,user_id:int|string,plan_offering_id:int|string,trial_reservation_id:int|string|null,trial_reservation_command_key:string|null,benefit_entitlement_id:int|string|null,benefit_entitlement_public_id:string|null,authorization_key:string,request_payload_hash:string,configuration_snapshot:string,configuration_snapshot_hash:string,actor_type:string,actor_id:int|string|null,reason_code:string,correlation_id:string}
 * @phpstan-type TrialReservationRow object{id:int|string,command_key:string,payload_hash:string,trial_policy_id:int|string,trial_policy_version:int|string,policy_configuration_hash:string,plan_offering_id:int|string,user_id:int|string,plan_offering_route_selection_id:int|string,state:string,version:int|string,data_bytes:int|string,duration_days:int|string,fallback_used_snapshot:int|bool,delivery_template_key_snapshot:string,eligibility_snapshot_hash:string}
 * @phpstan-type BenefitEntitlementRow object{id:int|string,public_id:string,user_id:int|string,plan_offering_id:int|string,configuration_snapshot:string,configuration_hash:string}
 * @phpstan-type TrialConfiguration array{data_bytes:int,delivery_template_key:string,duration_days:int,eligibility_snapshot_hash:string,fallback_used:bool,plan_offering_route_selection_id:int,policy_configuration_hash:string,trial_policy_id:int,trial_policy_version:int,trial_reservation_version:int}
 * @phpstan-type AdministratorGrantConfiguration array{data_allowance_bytes:int|null,device_limit:int|null,duration_days:int,offering_code:string,offering_version:int,protocol_selection_mode:string,sales_server_id:int,server_selection_mode:string,service_mode_code:string,service_target_id:int}
 */
final readonly class OrderSourceAuthorizationService
{
    private const ADMIN_GRANT_PERMISSION = 'services.grant_single';

    private const DEADLOCK_RETRY_ATTEMPTS = 3;

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private AdministratorPermissionAuthorizer $authorizer,
    ) {}

    /** @requirement BUY-001 BUY-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-004 */
    public function authorizeTrial(string $trialCommandKey, string $correlationId): OrderSourceAuthorizationReceipt
    {
        $this->assertToken($trialCommandKey, 'Trial reservation command key', 8, 128);
        $this->assertToken($correlationId, 'Order source authorization correlation ID', 8, 64);
        $authorizationKey = 'trial:'.hash('sha256', $trialCommandKey);

        try {
            return $this->database->connection()->transaction(function (Connection $connection) use ($trialCommandKey, $correlationId, $authorizationKey): OrderSourceAuthorizationReceipt {
                $reservation = $this->trialReservationByCommandKey($connection, $trialCommandKey, true);
                if ($reservation === null) {
                    throw new DomainException('Trial reservation does not exist.');
                }
                if ($reservation->state !== 'committed') {
                    throw new DomainException('Trial reservation is not committed.');
                }

                $userId = $this->positiveDatabaseInt($reservation->user_id, 'Trial reservation user ID');
                $offeringId = $this->positiveDatabaseInt($reservation->plan_offering_id, 'Trial reservation offering ID');
                $configurationJson = $this->canonicalJson($this->trialConfiguration($reservation));
                $configurationHash = hash('sha256', $configurationJson);
                $requestHash = $this->hashPayload([
                    'payload_hash' => $this->storedSha256($reservation->payload_hash, 'Trial reservation payload hash'),
                    'plan_offering_id' => $offeringId,
                    'policy_configuration_hash' => $this->storedSha256($reservation->policy_configuration_hash, 'Trial policy configuration hash'),
                    'trial_reservation_id' => $this->positiveDatabaseInt($reservation->id, 'Trial reservation ID'),
                    'trial_reservation_command_key' => $trialCommandKey,
                    'user_id' => $userId,
                ]);

                $existing = $this->authorizationByKey($connection, $authorizationKey, true);
                if ($existing !== null) {
                    return $this->replayReceipt(
                        $existing,
                        OrderSourceType::Trial,
                        $userId,
                        $offeringId,
                        $authorizationKey,
                        $requestHash,
                        'system',
                        null,
                        'trial_committed',
                        $configurationHash,
                    );
                }

                return $this->insertAuthorization(
                    $connection,
                    OrderSourceType::Trial,
                    $userId,
                    $offeringId,
                    $authorizationKey,
                    $requestHash,
                    $configurationJson,
                    $configurationHash,
                    'system',
                    null,
                    'trial_committed',
                    $correlationId,
                    $this->positiveDatabaseInt($reservation->id, 'Trial reservation ID'),
                    $trialCommandKey,
                    null,
                    null,
                );
            }, self::DEADLOCK_RETRY_ATTEMPTS);
        } catch (QueryException $exception) {
            $replay = $this->trialReplayAfterUniqueRace($trialCommandKey, $authorizationKey);
            if ($replay !== null) {
                return $replay;
            }

            throw $exception;
        }
    }

    /** @requirement BUY-001 BUY-002 PRO-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-004 */
    public function authorizeBenefitCode(string $entitlementPublicId, string $correlationId): OrderSourceAuthorizationReceipt
    {
        $this->assertUlid($entitlementPublicId, 'Benefit entitlement public ID');
        $this->assertToken($correlationId, 'Order source authorization correlation ID', 8, 64);
        $authorizationKey = 'benefit_code:'.$entitlementPublicId;

        try {
            return $this->database->connection()->transaction(function (Connection $connection) use ($entitlementPublicId, $correlationId, $authorizationKey): OrderSourceAuthorizationReceipt {
                $entitlement = $this->benefitEntitlementByPublicId($connection, $entitlementPublicId, true);
                if ($entitlement === null) {
                    throw new DomainException('Benefit-code free-service entitlement does not exist.');
                }

                $configurationJson = $this->validatedStoredJsonObject(
                    $entitlement->configuration_snapshot,
                    $entitlement->configuration_hash,
                    'Benefit entitlement configuration',
                );
                $configurationHash = $this->storedSha256($entitlement->configuration_hash, 'Benefit entitlement configuration hash');
                $userId = $this->positiveDatabaseInt($entitlement->user_id, 'Benefit entitlement user ID');
                $offeringId = $this->positiveDatabaseInt($entitlement->plan_offering_id, 'Benefit entitlement offering ID');
                $entitlementId = $this->positiveDatabaseInt($entitlement->id, 'Benefit entitlement ID');
                $requestHash = $this->hashPayload([
                    'benefit_entitlement_id' => $entitlementId,
                    'benefit_entitlement_public_id' => $entitlementPublicId,
                    'configuration_snapshot_hash' => $configurationHash,
                    'plan_offering_id' => $offeringId,
                    'user_id' => $userId,
                ]);

                $existing = $this->authorizationByKey($connection, $authorizationKey, true);
                if ($existing !== null) {
                    return $this->replayReceipt(
                        $existing,
                        OrderSourceType::BenefitCode,
                        $userId,
                        $offeringId,
                        $authorizationKey,
                        $requestHash,
                        'system',
                        null,
                        'benefit_code_free_service',
                        $configurationHash,
                    );
                }

                return $this->insertAuthorization(
                    $connection,
                    OrderSourceType::BenefitCode,
                    $userId,
                    $offeringId,
                    $authorizationKey,
                    $requestHash,
                    $configurationJson,
                    $configurationHash,
                    'system',
                    null,
                    'benefit_code_free_service',
                    $correlationId,
                    null,
                    null,
                    $entitlementId,
                    $entitlementPublicId,
                );
            }, self::DEADLOCK_RETRY_ATTEMPTS);
        } catch (QueryException $exception) {
            $replay = $this->benefitReplayAfterUniqueRace($entitlementPublicId, $authorizationKey);
            if ($replay !== null) {
                return $replay;
            }

            throw $exception;
        }
    }

    /** @requirement BUY-001 BUY-002 ADM-002 ACL-001 ACL-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-004 */
    public function authorizeAdministratorGrant(
        string $authorizationKey,
        int $administratorId,
        int $userId,
        int $planOfferingId,
        string $reasonCode,
        string $correlationId,
    ): OrderSourceAuthorizationReceipt {
        $this->assertToken($authorizationKey, 'Administrator grant authorization key', 8, 128);
        if ($administratorId < 1 || $userId < 1 || $planOfferingId < 1) {
            throw new DomainException('Administrator grant identity is invalid.');
        }
        $this->assertToken($reasonCode, 'Administrator grant reason code', 3, 64);
        $this->assertToken($correlationId, 'Order source authorization correlation ID', 8, 64);
        $this->authorizer->authorize($administratorId, self::ADMIN_GRANT_PERMISSION);

        $requestHash = $this->hashPayload([
            'actor_administrator_id' => $administratorId,
            'plan_offering_id' => $planOfferingId,
            'reason_code' => $reasonCode,
            'user_id' => $userId,
        ]);
        $connection = $this->database->connection();
        $existing = $this->authorizationByKey($connection, $authorizationKey);
        if ($existing !== null) {
            return $this->replayReceipt(
                $existing,
                OrderSourceType::AdminGrant,
                $userId,
                $planOfferingId,
                $authorizationKey,
                $requestHash,
                'administrator',
                $administratorId,
                $reasonCode,
                null,
            );
        }

        try {
            return $connection->transaction(function (Connection $connection) use ($authorizationKey, $administratorId, $userId, $planOfferingId, $reasonCode, $correlationId, $requestHash): OrderSourceAuthorizationReceipt {
                $existing = $this->authorizationByKey($connection, $authorizationKey, true);
                if ($existing !== null) {
                    return $this->replayReceipt(
                        $existing,
                        OrderSourceType::AdminGrant,
                        $userId,
                        $planOfferingId,
                        $authorizationKey,
                        $requestHash,
                        'administrator',
                        $administratorId,
                        $reasonCode,
                        null,
                    );
                }

                $this->assertActiveGrantSubject($connection, $userId);
                $offering = $this->activeOffering($connection, $planOfferingId, true);
                if ($offering === null) {
                    throw new DomainException('Administrator grant Plan Offering is not active.');
                }
                $configurationJson = $this->canonicalJson($this->administratorGrantConfiguration($offering));
                $configurationHash = hash('sha256', $configurationJson);

                return $this->insertAuthorization(
                    $connection,
                    OrderSourceType::AdminGrant,
                    $userId,
                    $planOfferingId,
                    $authorizationKey,
                    $requestHash,
                    $configurationJson,
                    $configurationHash,
                    'administrator',
                    $administratorId,
                    $reasonCode,
                    $correlationId,
                    null,
                    null,
                    null,
                    null,
                );
            }, self::DEADLOCK_RETRY_ATTEMPTS);
        } catch (QueryException $exception) {
            $existing = $this->authorizationByKey($this->database->connection(), $authorizationKey);
            if ($existing !== null) {
                return $this->replayReceipt(
                    $existing,
                    OrderSourceType::AdminGrant,
                    $userId,
                    $planOfferingId,
                    $authorizationKey,
                    $requestHash,
                    'administrator',
                    $administratorId,
                    $reasonCode,
                    null,
                );
            }

            throw $exception;
        }
    }

    /**
     * @param  TrialReservationRow  $reservation
     * @return TrialConfiguration
     */
    private function trialConfiguration(object $reservation): array
    {
        return [
            'data_bytes' => $this->positiveDatabaseInt($reservation->data_bytes, 'Trial data allowance'),
            'delivery_template_key' => $reservation->delivery_template_key_snapshot,
            'duration_days' => $this->positiveDatabaseInt($reservation->duration_days, 'Trial duration'),
            'eligibility_snapshot_hash' => $this->storedSha256($reservation->eligibility_snapshot_hash, 'Trial eligibility snapshot hash'),
            'fallback_used' => (bool) $reservation->fallback_used_snapshot,
            'plan_offering_route_selection_id' => $this->positiveDatabaseInt($reservation->plan_offering_route_selection_id, 'Trial route selection ID'),
            'policy_configuration_hash' => $this->storedSha256($reservation->policy_configuration_hash, 'Trial policy configuration hash'),
            'trial_policy_id' => $this->positiveDatabaseInt($reservation->trial_policy_id, 'Trial policy ID'),
            'trial_policy_version' => $this->positiveDatabaseInt($reservation->trial_policy_version, 'Trial policy version'),
            'trial_reservation_version' => $this->positiveDatabaseInt($reservation->version, 'Trial reservation version'),
        ];
    }

    /**
     * @param  object{code:string,sales_server_id:int|string,panel_service_target_id:int|string,service_mode_code:string,server_selection_mode:string,protocol_selection_mode:string,duration_days:int|string,data_allowance_bytes:int|string|null,device_limit:int|string|null,version:int|string}  $offering
     * @return AdministratorGrantConfiguration
     */
    private function administratorGrantConfiguration(object $offering): array
    {
        return [
            'data_allowance_bytes' => $this->nullablePositiveDatabaseInt($offering->data_allowance_bytes, 'Plan Offering data allowance'),
            'device_limit' => $this->nullablePositiveDatabaseInt($offering->device_limit, 'Plan Offering device limit'),
            'duration_days' => $this->positiveDatabaseInt($offering->duration_days, 'Plan Offering duration'),
            'offering_code' => $offering->code,
            'offering_version' => $this->positiveDatabaseInt($offering->version, 'Plan Offering version'),
            'protocol_selection_mode' => $offering->protocol_selection_mode,
            'sales_server_id' => $this->positiveDatabaseInt($offering->sales_server_id, 'Plan Offering sales server ID'),
            'server_selection_mode' => $offering->server_selection_mode,
            'service_mode_code' => $offering->service_mode_code,
            'service_target_id' => $this->positiveDatabaseInt($offering->panel_service_target_id, 'Plan Offering service target ID'),
        ];
    }

    private function assertActiveGrantSubject(Connection $connection, int $userId): void
    {
        /** @var object{account_type:string,account_status:string}|null $user */
        $user = $connection->table('users')->where('id', $userId)->lockForUpdate()->first(['account_type', 'account_status']);
        if ($user === null || $user->account_status !== 'active' || ! in_array($user->account_type, ['customer', 'agent'], true)) {
            throw new DomainException('Administrator grant subject is not an active customer or agent.');
        }
    }

    /** @return object{code:string,sales_server_id:int|string,panel_service_target_id:int|string,service_mode_code:string,server_selection_mode:string,protocol_selection_mode:string,duration_days:int|string,data_allowance_bytes:int|string|null,device_limit:int|string|null,version:int|string}|null */
    private function activeOffering(Connection $connection, int $planOfferingId, bool $lock): ?object
    {
        $query = $connection->table('plan_offerings')->where('id', $planOfferingId)->where('state', 'active');
        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var object{code:string,sales_server_id:int|string,panel_service_target_id:int|string,service_mode_code:string,server_selection_mode:string,protocol_selection_mode:string,duration_days:int|string,data_allowance_bytes:int|string|null,device_limit:int|string|null,version:int|string}|null $row */
        $row = $query->first([
            'code',
            'sales_server_id',
            'panel_service_target_id',
            'service_mode_code',
            'server_selection_mode',
            'protocol_selection_mode',
            'duration_days',
            'data_allowance_bytes',
            'device_limit',
            'version',
        ]);

        return $row;
    }

    private function insertAuthorization(
        Connection $connection,
        OrderSourceType $sourceType,
        int $userId,
        int $planOfferingId,
        string $authorizationKey,
        string $requestHash,
        string $configurationJson,
        string $configurationHash,
        string $actorType,
        ?int $actorId,
        string $reasonCode,
        string $correlationId,
        ?int $trialReservationId,
        ?string $trialReservationCommandKey,
        ?int $benefitEntitlementId,
        ?string $benefitEntitlementPublicId,
    ): OrderSourceAuthorizationReceipt {
        $publicId = (string) Str::ulid();
        $authorizationId = (int) $connection->table('order_source_authorizations')->insertGetId([
            'public_id' => $publicId,
            'source_type' => $sourceType->value,
            'user_id' => $userId,
            'plan_offering_id' => $planOfferingId,
            'trial_reservation_id' => $trialReservationId,
            'trial_reservation_command_key' => $trialReservationCommandKey,
            'benefit_entitlement_id' => $benefitEntitlementId,
            'benefit_entitlement_public_id' => $benefitEntitlementPublicId,
            'authorization_key' => $authorizationKey,
            'request_payload_hash' => $requestHash,
            'configuration_snapshot' => $configurationJson,
            'configuration_snapshot_hash' => $configurationHash,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'reason_code' => $reasonCode,
            'correlation_id' => $correlationId,
            'created_at' => $this->timestamp(),
        ]);

        return new OrderSourceAuthorizationReceipt(
            $authorizationId,
            $publicId,
            $sourceType,
            $userId,
            $planOfferingId,
            $authorizationKey,
            $configurationHash,
            $actorType,
            $actorId,
            $reasonCode,
            $correlationId,
            false,
        );
    }

    /** @param AuthorizationRow $row */
    private function replayReceipt(
        object $row,
        OrderSourceType $sourceType,
        int $userId,
        int $planOfferingId,
        string $authorizationKey,
        string $requestHash,
        string $actorType,
        ?int $actorId,
        string $reasonCode,
        ?string $expectedConfigurationHash,
    ): OrderSourceAuthorizationReceipt {
        $storedActorId = $row->actor_id === null ? null : $this->positiveDatabaseInt($row->actor_id, 'Stored Order source authorization actor ID');
        $storedConfigurationHash = $this->storedSha256($row->configuration_snapshot_hash, 'Stored Order source authorization configuration hash');
        if ($row->source_type !== $sourceType->value
            || $this->positiveDatabaseInt($row->user_id, 'Stored Order source authorization user ID') !== $userId
            || $this->positiveDatabaseInt($row->plan_offering_id, 'Stored Order source authorization offering ID') !== $planOfferingId
            || ! hash_equals($row->authorization_key, $authorizationKey)
            || ! hash_equals($this->storedSha256($row->request_payload_hash, 'Stored Order source authorization payload hash'), $requestHash)
            || $row->actor_type !== $actorType
            || $storedActorId !== $actorId
            || $row->reason_code !== $reasonCode
            || ($expectedConfigurationHash !== null && ! hash_equals($storedConfigurationHash, $expectedConfigurationHash))) {
            throw new DomainException('Order source authorization key conflicts with another request.');
        }

        return new OrderSourceAuthorizationReceipt(
            $this->positiveDatabaseInt($row->id, 'Stored Order source authorization ID'),
            $row->public_id,
            $sourceType,
            $userId,
            $planOfferingId,
            $authorizationKey,
            $storedConfigurationHash,
            $actorType,
            $actorId,
            $reasonCode,
            $row->correlation_id,
            true,
        );
    }

    private function trialReplayAfterUniqueRace(string $trialCommandKey, string $authorizationKey): ?OrderSourceAuthorizationReceipt
    {
        $connection = $this->database->connection();
        $reservation = $this->trialReservationByCommandKey($connection, $trialCommandKey, false);
        $existing = $this->authorizationByKey($connection, $authorizationKey);
        if ($reservation === null || $existing === null || $reservation->state !== 'committed') {
            return null;
        }

        try {
            $userId = $this->positiveDatabaseInt($reservation->user_id, 'Trial reservation user ID');
            $offeringId = $this->positiveDatabaseInt($reservation->plan_offering_id, 'Trial reservation offering ID');
            $configurationHash = hash('sha256', $this->canonicalJson($this->trialConfiguration($reservation)));
            $requestHash = $this->hashPayload([
                'payload_hash' => $this->storedSha256($reservation->payload_hash, 'Trial reservation payload hash'),
                'plan_offering_id' => $offeringId,
                'policy_configuration_hash' => $this->storedSha256($reservation->policy_configuration_hash, 'Trial policy configuration hash'),
                'trial_reservation_id' => $this->positiveDatabaseInt($reservation->id, 'Trial reservation ID'),
                'trial_reservation_command_key' => $trialCommandKey,
                'user_id' => $userId,
            ]);

            return $this->replayReceipt($existing, OrderSourceType::Trial, $userId, $offeringId, $authorizationKey, $requestHash, 'system', null, 'trial_committed', $configurationHash);
        } catch (DomainException|RuntimeException) {
            return null;
        }
    }

    private function benefitReplayAfterUniqueRace(string $entitlementPublicId, string $authorizationKey): ?OrderSourceAuthorizationReceipt
    {
        $connection = $this->database->connection();
        $entitlement = $this->benefitEntitlementByPublicId($connection, $entitlementPublicId, false);
        $existing = $this->authorizationByKey($connection, $authorizationKey);
        if ($entitlement === null || $existing === null) {
            return null;
        }

        try {
            $configurationHash = $this->storedSha256($entitlement->configuration_hash, 'Benefit entitlement configuration hash');
            $this->validatedStoredJsonObject($entitlement->configuration_snapshot, $configurationHash, 'Benefit entitlement configuration');
            $userId = $this->positiveDatabaseInt($entitlement->user_id, 'Benefit entitlement user ID');
            $offeringId = $this->positiveDatabaseInt($entitlement->plan_offering_id, 'Benefit entitlement offering ID');
            $requestHash = $this->hashPayload([
                'benefit_entitlement_id' => $this->positiveDatabaseInt($entitlement->id, 'Benefit entitlement ID'),
                'benefit_entitlement_public_id' => $entitlementPublicId,
                'configuration_snapshot_hash' => $configurationHash,
                'plan_offering_id' => $offeringId,
                'user_id' => $userId,
            ]);

            return $this->replayReceipt($existing, OrderSourceType::BenefitCode, $userId, $offeringId, $authorizationKey, $requestHash, 'system', null, 'benefit_code_free_service', $configurationHash);
        } catch (DomainException|RuntimeException) {
            return null;
        }
    }

    /** @return TrialReservationRow|null */
    private function trialReservationByCommandKey(Connection $connection, string $commandKey, bool $lock): ?object
    {
        $query = $connection->table('trial_reservations')->where('command_key', $commandKey);
        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var TrialReservationRow|null $row */
        $row = $query->first([
            'id',
            'command_key',
            'payload_hash',
            'trial_policy_id',
            'trial_policy_version',
            'policy_configuration_hash',
            'plan_offering_id',
            'user_id',
            'plan_offering_route_selection_id',
            'state',
            'version',
            'data_bytes',
            'duration_days',
            'fallback_used_snapshot',
            'delivery_template_key_snapshot',
            'eligibility_snapshot_hash',
        ]);

        return $row;
    }

    /** @return BenefitEntitlementRow|null */
    private function benefitEntitlementByPublicId(Connection $connection, string $publicId, bool $lock): ?object
    {
        $query = $connection->table('benefit_code_free_service_entitlements')->where('public_id', $publicId);
        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var BenefitEntitlementRow|null $row */
        $row = $query->first(['id', 'public_id', 'user_id', 'plan_offering_id', 'configuration_snapshot', 'configuration_hash']);

        return $row;
    }

    /** @return AuthorizationRow|null */
    private function authorizationByKey(Connection $connection, string $authorizationKey, bool $lock = false): ?object
    {
        $query = $connection->table('order_source_authorizations')->where('authorization_key', $authorizationKey);
        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var AuthorizationRow|null $row */
        $row = $query->first([
            'id',
            'public_id',
            'source_type',
            'user_id',
            'plan_offering_id',
            'trial_reservation_id',
            'trial_reservation_command_key',
            'benefit_entitlement_id',
            'benefit_entitlement_public_id',
            'authorization_key',
            'request_payload_hash',
            'configuration_snapshot',
            'configuration_snapshot_hash',
            'actor_type',
            'actor_id',
            'reason_code',
            'correlation_id',
        ]);

        return $row;
    }

    /** @param array<string,mixed> $payload */
    private function hashPayload(array $payload): string
    {
        return hash('sha256', $this->canonicalJson($payload));
    }

    /** @param array<string,mixed> $value */
    private function canonicalJson(array $value): string
    {
        try {
            return json_encode($this->normalizeJson($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new RuntimeException('Order source authorization snapshot cannot be encoded.', previous: $exception);
        }
    }

    private function normalizeJson(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalizeJson($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeJson($item);
        }

        return $value;
    }

    private function validatedStoredJsonObject(string $json, string $expectedHash, string $label): string
    {
        $hash = $this->storedSha256($expectedHash, $label.' hash');
        if (! hash_equals($hash, hash('sha256', $json))) {
            throw new RuntimeException($label.' hash does not match its stored snapshot.');
        }
        if (strlen($json) > 8192 || ! str_starts_with(ltrim($json), '{')) {
            throw new RuntimeException($label.' is not a bounded JSON object.');
        }
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException($label.' is invalid JSON.', previous: $exception);
        }
        if (! is_array($decoded)) {
            throw new RuntimeException($label.' is not a JSON object.');
        }

        return $json;
    }

    private function storedSha256(string $value, string $label): string
    {
        $normalized = strtolower($value);
        if (preg_match('/\A[0-9a-f]{64}\z/', $normalized) !== 1) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $normalized;
    }

    private function assertUlid(string $value, string $label): void
    {
        if (strlen($value) !== 26 || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function assertToken(string $value, string $label, int $minimum, int $maximum): void
    {
        $length = strlen($value);
        if ($length < $minimum || $length > $maximum || preg_match('/\A[A-Za-z0-9._:-]+\z/', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function positiveDatabaseInt(int|string|null $value, string $label): int
    {
        if ($value === null || (is_string($value) && preg_match('/\A[0-9]+\z/', $value) !== 1)) {
            throw new RuntimeException($label.' is invalid.');
        }
        $integer = (int) $value;
        if ($integer < 1) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $integer;
    }

    private function nullablePositiveDatabaseInt(int|string|null $value, string $label): ?int
    {
        if ($value === null) {
            return null;
        }

        return $this->positiveDatabaseInt($value, $label);
    }

    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
