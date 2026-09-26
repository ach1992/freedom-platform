<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use RuntimeException;

/** @requirement SVC-013 DAT-002 DAT-003 SEC-002 QUA-001 QUA-004 */
final readonly class ServiceNotificationPreferenceService
{
    /** @var list<string> */
    private const TYPES = ['expiry', 'usage', 'low_balance', 'renewal_failure', 'service_state', 'sync_issue'];

    public function __construct(
        private DatabaseManager $database,
        private ServiceNotificationPreferenceDatabaseAuthority $databaseAuthority,
    ) {}

    public function configureForSelf(
        string $requestKey,
        int $actorUserId,
        ?string $servicePublicId,
        string $notificationType,
        string $thresholdCode,
        bool $enabled,
        string $correlationId,
    ): ServiceNotificationPreferenceReceipt {
        $this->assertToken($requestKey, 'Service notification preference request key', 8, 128);
        if ($actorUserId < 1) {
            throw new DomainException('Service notification preference actor must be positive.');
        }
        if (! in_array($notificationType, self::TYPES, true)) {
            throw new DomainException('Service notification preference type is invalid.');
        }
        if ($thresholdCode !== '*' && preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/', $thresholdCode) !== 1) {
            throw new DomainException('Service notification preference threshold is invalid.');
        }
        $this->assertToken($correlationId, 'Service notification preference correlation ID', 8, 64);
        if ($servicePublicId !== null && ! Str::isUlid($servicePublicId)) {
            throw new DomainException('Service notification preference Service public ID is invalid.');
        }

        $requestHash = hash('sha256', $requestKey);
        $payloadHash = hash('sha256', json_encode([
            'actor_user_id' => $actorUserId,
            'service_public_id' => $servicePublicId,
            'notification_type' => $notificationType,
            'threshold_code' => $thresholdCode,
            'enabled' => $enabled,
            'audience' => 'customer',
            'destination' => 'telegram',
        ], JSON_THROW_ON_ERROR));

        $replay = $this->replay($requestHash, $payloadHash);
        if ($replay !== null) {
            return $replay;
        }

        try {
            return $this->database->connection()->transaction(function (Connection $connection) use (
                $requestHash,
                $payloadHash,
                $actorUserId,
                $servicePublicId,
                $notificationType,
                $thresholdCode,
                $enabled,
                $correlationId,
            ): ServiceNotificationPreferenceReceipt {
                $replay = $this->replayOn($connection, $requestHash, $payloadHash);
                if ($replay !== null) {
                    return $replay;
                }

                /** @var object{id:int|string,account_status:string,account_type:string}|null $user */
                $user = $connection->table('users')->where('id', $actorUserId)->lockForUpdate()->first(['id', 'account_status', 'account_type']);
                if ($user === null || $user->account_status !== 'active' || ! in_array($user->account_type, ['customer', 'agent'], true)) {
                    throw new DomainException('Service notification preferences require an active customer or agent account.');
                }

                $serviceId = null;
                if ($servicePublicId !== null) {
                    /** @var object{id:int|string,user_id:int|string}|null $service */
                    $service = $connection->table('service_subscriptions')
                        ->where('public_id', $servicePublicId)
                        ->lockForUpdate()
                        ->first(['id', 'user_id']);
                    if ($service === null || (int) $service->user_id !== $actorUserId) {
                        throw new DomainException('Service notification preference is allowed only for the Service owner.');
                    }
                    $serviceId = (int) $service->id;
                }

                $scopeHash = $serviceId === null
                    ? ServiceNotificationPreferenceResolver::globalScopeHash($actorUserId)
                    : ServiceNotificationPreferenceResolver::serviceScopeHash($actorUserId, $serviceId);
                /** @var object{id:int|string,public_id:string,enabled:int|bool,version:int|string}|null $current */
                $current = $connection->table('service_notification_preferences')
                    ->where('scope_key_hash', $scopeHash)
                    ->where('notification_type', $notificationType)
                    ->where('threshold_code', $thresholdCode)
                    ->where('audience', 'customer')
                    ->where('destination', 'telegram')
                    ->lockForUpdate()
                    ->first(['id', 'public_id', 'enabled', 'version']);
                $timestamp = now('UTC')->format('Y-m-d H:i:s.u');
                $this->databaseAuthority->apply(
                    $connection,
                    $actorUserId,
                    $serviceId,
                    $scopeHash,
                    $notificationType,
                    $thresholdCode,
                    $enabled,
                    $requestHash,
                    $payloadHash,
                    $correlationId,
                    $timestamp,
                );
                try {
                    if ($current === null) {
                        $publicId = (string) Str::ulid();
                        $version = 1;
                        $preferenceId = (int) $connection->table('service_notification_preferences')->insertGetId([
                            'public_id' => $publicId,
                            'owner_user_id' => $actorUserId,
                            'service_subscription_id' => $serviceId,
                            'scope_key_hash' => $scopeHash,
                            'notification_type' => $notificationType,
                            'threshold_code' => $thresholdCode,
                            'audience' => 'customer',
                            'destination' => 'telegram',
                            'enabled' => $enabled,
                            'version' => $version,
                            'last_request_key_hash' => $requestHash,
                            'last_correlation_id' => $correlationId,
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ]);
                    } else {
                        $preferenceId = (int) $current->id;
                        $publicId = $current->public_id;
                        $version = (int) $current->version + 1;
                        $connection->table('service_notification_preferences')->where('id', $preferenceId)->update([
                            'enabled' => $enabled,
                            'version' => $version,
                            'last_request_key_hash' => $requestHash,
                            'last_correlation_id' => $correlationId,
                            'updated_at' => $timestamp,
                        ]);
                    }

                    $connection->table('service_notification_preference_histories')->insert([
                        'service_notification_preference_id' => $preferenceId,
                        'version' => $version,
                        'actor_user_id' => $actorUserId,
                        'enabled' => $enabled,
                        'request_key_hash' => $requestHash,
                        'payload_hash' => $payloadHash,
                        'correlation_id' => $correlationId,
                        'created_at' => $timestamp,
                    ]);
                } finally {
                    $this->databaseAuthority->clear($connection);
                }

                return new ServiceNotificationPreferenceReceipt(
                    $publicId,
                    $actorUserId,
                    $servicePublicId,
                    $notificationType,
                    $thresholdCode,
                    $enabled,
                    $version,
                    false,
                );
            }, 3);
        } catch (QueryException $exception) {
            $replay = $this->replay($requestHash, $payloadHash);
            if ($replay !== null) {
                return $replay;
            }
            throw $exception;
        }
    }

    private function replay(string $requestHash, string $payloadHash): ?ServiceNotificationPreferenceReceipt
    {
        return $this->replayOn($this->database->connection(), $requestHash, $payloadHash);
    }

    private function replayOn(Connection $connection, string $requestHash, string $payloadHash): ?ServiceNotificationPreferenceReceipt
    {
        /** @var object{service_notification_preference_id:int|string,version:int|string,enabled:int|bool,payload_hash:string,actor_user_id:int|string}|null $history */
        $history = $connection->table('service_notification_preference_histories')
            ->where('request_key_hash', $requestHash)
            ->first(['service_notification_preference_id', 'version', 'enabled', 'payload_hash', 'actor_user_id']);
        if ($history === null) {
            return null;
        }
        if (! hash_equals($history->payload_hash, $payloadHash)) {
            throw new DomainException('Service notification preference request conflicts with accepted evidence.');
        }
        /** @var object{public_id:string,owner_user_id:int|string,service_subscription_id:int|string|null,notification_type:string,threshold_code:string}|null $preference */
        $preference = $connection->table('service_notification_preferences')
            ->where('id', (int) $history->service_notification_preference_id)
            ->first(['public_id', 'owner_user_id', 'service_subscription_id', 'notification_type', 'threshold_code']);
        if ($preference === null || (int) $preference->owner_user_id !== (int) $history->actor_user_id) {
            throw new RuntimeException('Service notification preference replay authority is inconsistent.');
        }
        $servicePublicId = null;
        if ($preference->service_subscription_id !== null) {
            $servicePublicId = $connection->table('service_subscriptions')
                ->where('id', (int) $preference->service_subscription_id)
                ->value('public_id');
            if (! is_string($servicePublicId) || ! Str::isUlid($servicePublicId)) {
                throw new RuntimeException('Service notification preference replay Service identity is invalid.');
            }
        }

        return new ServiceNotificationPreferenceReceipt(
            $preference->public_id,
            (int) $preference->owner_user_id,
            $servicePublicId,
            $preference->notification_type,
            $preference->threshold_code,
            (bool) $history->enabled,
            (int) $history->version,
            true,
        );
    }

    private function assertToken(string $value, string $label, int $minimum, int $maximum): void
    {
        if (strlen($value) < $minimum || strlen($value) > $maximum
            || preg_match('/\A[A-Za-z0-9_.:-]+\z/', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }
}
