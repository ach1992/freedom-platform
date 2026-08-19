<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application;

use App\Modules\Orders\Domain\OrderSourceType;
use App\Modules\Orders\Domain\OrderState;
use App\Shared\Application\Clock;
use App\Shared\Domain\Money;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * @phpstan-type SourceAuthorizationRow object{id:int|string,public_id:string,source_type:string,user_id:int|string,plan_offering_id:int|string,authorization_key:string,request_payload_hash:string,configuration_snapshot:string,configuration_snapshot_hash:string,actor_type:string,actor_id:int|string|null,reason_code:string,correlation_id:string}
 * @phpstan-type SubjectRow object{id:int|string,account_type:string,account_status:string}
 * @phpstan-type OfferingRow object{id:int|string,code:string,base_price_irr:int|string,state:string,version:int|string}
 * @phpstan-type OfferingHistoryRow object{to_configuration_hash:string}
 * @phpstan-type OrderRow object{id:int|string,public_id:string,source_type:string,order_source_authorization_id:int|string|null,order_source_authorization_public_id:string|null,user_id:int|string,state:string,state_version:int|string,total_amount_irr:int|string,settled_amount_irr:int|string|null,currency:string,paid_at:string|null}
 * @phpstan-type OrderItemRow object{id:int|string,public_id:string,order_id:int|string,line_number:int|string,source_quote_id:int|string|null,source_quote_public_id:string|null,order_source_authorization_id:int|string|null,order_source_authorization_public_id:string|null,account_type_snapshot:string,plan_offering_id:int|string,offering_code_snapshot:string,offering_version:int|string,offering_configuration_hash:string,base_price_irr:int|string,override_source:string,override_reference_code:string|null,override_price_irr:int|string|null,effective_price_irr:int|string,discount_reference_code:string|null,discount_irr:int|string,final_price_irr:int|string,currency:string,configuration_snapshot:string,configuration_snapshot_hash:string}
 */
final readonly class NonPaidOrderService
{
    private const DEADLOCK_RETRY_ATTEMPTS = 3;

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
    ) {}

    /** @requirement BUY-001 BUY-002 CAT-006 ADM-002 ACL-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-004 */
    public function materialize(string $sourceAuthorizationPublicId, string $correlationId): NonPaidOrderReceipt
    {
        $this->assertUlid($sourceAuthorizationPublicId, 'Order source authorization public ID');
        $this->assertToken($correlationId, 'Order creation correlation ID', 8, 64);
        $this->assertSourceAuthorityFinalized();

        try {
            return $this->database->connection()->transaction(
                fn (Connection $connection): NonPaidOrderReceipt => $this->materializeLocked(
                    $connection,
                    $sourceAuthorizationPublicId,
                    $correlationId,
                ),
                self::DEADLOCK_RETRY_ATTEMPTS,
            );
        } catch (QueryException $exception) {
            $replay = $this->replayAfterUniqueRace($sourceAuthorizationPublicId);
            if ($replay !== null) {
                return $replay;
            }

            throw $exception;
        }
    }

    private function assertSourceAuthorityFinalized(): void
    {
        /** @var object{ready_count:int|string|null,blocked_count:int|string|null}|null $row */
        $row = $this->database->connection()->selectOne(<<<'SQL'
SELECT
    SUM(CONSTRAINT_NAME = 'order_source_authorizations_authority_ready_v2_chk') AS ready_count,
    SUM(CONSTRAINT_NAME = 'order_source_authorizations_bootstrap_block_chk') AS blocked_count
FROM information_schema.TABLE_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = DATABASE()
  AND TABLE_NAME = 'order_source_authorizations'
  AND CONSTRAINT_TYPE = 'CHECK'
  AND CONSTRAINT_NAME IN (
      'order_source_authorizations_authority_ready_v2_chk',
      'order_source_authorizations_bootstrap_block_chk'
  )
SQL);
        if ($row === null || (int) $row->ready_count !== 1 || (int) $row->blocked_count !== 0) {
            throw new RuntimeException('Order source authorization authority is not finalized.');
        }
    }

    private function materializeLocked(
        Connection $connection,
        string $sourceAuthorizationPublicId,
        string $correlationId,
    ): NonPaidOrderReceipt {
        $authorization = $this->authorizationByPublicId($connection, $sourceAuthorizationPublicId, true);
        if ($authorization === null) {
            throw new DomainException('Order source authorization does not exist.');
        }

        $sourceType = OrderSourceType::tryFrom($authorization->source_type);
        if ($sourceType === null || ! $this->isSupportedNonPaidSource($sourceType)) {
            throw new DomainException('Order source authorization is not enabled for zero-cost Order creation.');
        }

        $authorizationId = $this->positiveDatabaseInt($authorization->id, 'Order source authorization ID');
        $existing = $this->orderByAuthorizationId($connection, $authorizationId, true);
        if ($existing !== null) {
            return $this->replayReceipt($connection, $existing, $authorization, $sourceType);
        }

        $userId = $this->positiveDatabaseInt($authorization->user_id, 'Order source authorization user ID');
        $subject = $this->activeSubject($connection, $userId);
        $offeringId = $this->positiveDatabaseInt($authorization->plan_offering_id, 'Order source authorization Plan Offering ID');
        $offering = $this->activeOffering($connection, $offeringId);
        $offeringVersion = $this->positiveDatabaseInt($offering->version, 'Plan Offering version');
        $offeringConfigurationHash = $this->offeringConfigurationHash($connection, $offeringId, $offeringVersion);
        $snapshotHash = $this->storedSha256($authorization->configuration_snapshot_hash, 'Order source configuration snapshot hash');
        if (! hash_equals($snapshotHash, hash('sha256', $authorization->configuration_snapshot))) {
            throw new RuntimeException('Stored Order source configuration snapshot hash is invalid.');
        }

        $timestamp = $this->timestamp();
        $orderPublicId = (string) Str::ulid();
        $orderId = (int) $connection->table('orders')->insertGetId([
            'public_id' => $orderPublicId,
            'source_type' => $sourceType->value,
            'purchase_settlement_id' => null,
            'purchase_settlement_public_id' => null,
            'payment_intent_id' => null,
            'payment_intent_public_id' => null,
            'user_id' => $userId,
            'source_quote_id' => null,
            'source_quote_public_id' => null,
            'source_quote_configuration_hash' => null,
            'state' => OrderState::Authorized->value,
            'state_version' => 0,
            'total_amount_irr' => 0,
            'settled_amount_irr' => null,
            'currency' => 'IRR',
            'paid_at' => null,
            'creation_correlation_id' => $correlationId,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
            'order_source_authorization_id' => $authorizationId,
            'order_source_authorization_public_id' => $authorization->public_id,
        ]);

        $itemPublicId = (string) Str::ulid();
        $connection->table('order_items')->insert([
            'public_id' => $itemPublicId,
            'order_id' => $orderId,
            'line_number' => 1,
            'source_quote_id' => null,
            'source_quote_public_id' => null,
            'account_type_snapshot' => $subject->account_type,
            'plan_offering_id' => $offeringId,
            'offering_code_snapshot' => $offering->code,
            'offering_version' => $offeringVersion,
            'offering_configuration_hash' => $offeringConfigurationHash,
            'base_price_irr' => $this->nonNegativeDatabaseInt($offering->base_price_irr, 'Plan Offering base price'),
            'override_source' => 'source',
            'override_reference_code' => $sourceType->value,
            'override_price_irr' => 0,
            'effective_price_irr' => 0,
            'discount_reference_code' => null,
            'discount_irr' => 0,
            'final_price_irr' => 0,
            'currency' => 'IRR',
            'configuration_snapshot' => $authorization->configuration_snapshot,
            'configuration_snapshot_hash' => $snapshotHash,
            'created_at' => $timestamp,
            'order_source_authorization_id' => $authorizationId,
            'order_source_authorization_public_id' => $authorization->public_id,
        ]);

        $this->recordAudit($connection, $orderPublicId, $authorization, $sourceType, $correlationId);

        return new NonPaidOrderReceipt(
            $orderId,
            $orderPublicId,
            $itemPublicId,
            $authorizationId,
            $authorization->public_id,
            $sourceType,
            $userId,
            $offeringId,
            OrderState::Authorized,
            0,
            Money::irr(0),
            false,
        );
    }

    /** @return SourceAuthorizationRow|null */
    private function authorizationByPublicId(Connection $connection, string $publicId, bool $lock = false): ?object
    {
        $query = $connection->table('order_source_authorizations')->where('public_id', $publicId);
        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var SourceAuthorizationRow|null $row */
        $row = $query->first([
            'id', 'public_id', 'source_type', 'user_id', 'plan_offering_id', 'authorization_key',
            'request_payload_hash', 'configuration_snapshot', 'configuration_snapshot_hash',
            'actor_type', 'actor_id', 'reason_code', 'correlation_id',
        ]);

        return $row;
    }

    /** @return OrderRow|null */
    private function orderByAuthorizationId(Connection $connection, int $authorizationId, bool $lock = false): ?object
    {
        $query = $connection->table('orders')->where('order_source_authorization_id', $authorizationId);
        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var OrderRow|null $row */
        $row = $query->first([
            'id', 'public_id', 'source_type', 'order_source_authorization_id', 'order_source_authorization_public_id',
            'user_id', 'state', 'state_version', 'total_amount_irr', 'settled_amount_irr', 'currency', 'paid_at',
        ]);

        return $row;
    }

    /** @return SubjectRow */
    private function activeSubject(Connection $connection, int $userId): object
    {
        /** @var SubjectRow|null $row */
        $row = $connection->table('users')->where('id', $userId)->first(['id', 'account_type', 'account_status']);
        if ($row === null || $row->account_status !== 'active' || ! in_array($row->account_type, ['customer', 'agent'], true)) {
            throw new DomainException('Zero-cost Order creation requires one active customer or agent subject.');
        }

        return $row;
    }

    /** @return OfferingRow */
    private function activeOffering(Connection $connection, int $offeringId): object
    {
        /** @var OfferingRow|null $row */
        $row = $connection->table('plan_offerings')->where('id', $offeringId)->first([
            'id', 'code', 'base_price_irr', 'state', 'version',
        ]);
        if ($row === null || $row->state !== 'active') {
            throw new DomainException('Zero-cost Order creation requires an active Plan Offering.');
        }

        return $row;
    }

    private function offeringConfigurationHash(Connection $connection, int $offeringId, int $version): string
    {
        /** @var OfferingHistoryRow|null $row */
        $row = $connection->table('plan_offering_histories')
            ->where('plan_offering_id', $offeringId)
            ->where('version', $version)
            ->first(['to_configuration_hash']);
        if ($row === null) {
            throw new RuntimeException('Plan Offering immutable configuration history is unavailable.');
        }

        return $this->storedSha256($row->to_configuration_hash, 'Plan Offering configuration hash');
    }

    /** @return OrderItemRow */
    private function orderItem(Connection $connection, int $orderId): object
    {
        /** @var OrderItemRow|null $row */
        $row = $connection->table('order_items')
            ->where('order_id', $orderId)
            ->where('line_number', 1)
            ->first([
                'id', 'public_id', 'order_id', 'line_number', 'source_quote_id', 'source_quote_public_id',
                'order_source_authorization_id', 'order_source_authorization_public_id', 'account_type_snapshot',
                'plan_offering_id', 'offering_code_snapshot', 'offering_version', 'offering_configuration_hash',
                'base_price_irr', 'override_source', 'override_reference_code', 'override_price_irr',
                'effective_price_irr', 'discount_reference_code', 'discount_irr', 'final_price_irr',
                'currency', 'configuration_snapshot', 'configuration_snapshot_hash',
            ]);
        if ($row === null || $connection->table('order_items')->where('order_id', $orderId)->count() !== 1) {
            throw new RuntimeException('Stored zero-cost Order item integrity check failed.');
        }

        return $row;
    }

    /**
     * @param  OrderRow  $order
     * @param  SourceAuthorizationRow  $authorization
     */
    private function replayReceipt(
        Connection $connection,
        object $order,
        object $authorization,
        OrderSourceType $sourceType,
    ): NonPaidOrderReceipt {
        $orderId = $this->positiveDatabaseInt($order->id, 'Order ID');
        $authorizationId = $this->positiveDatabaseInt($authorization->id, 'Order source authorization ID');
        $userId = $this->positiveDatabaseInt($authorization->user_id, 'Order source authorization user ID');
        $offeringId = $this->positiveDatabaseInt($authorization->plan_offering_id, 'Order source authorization Plan Offering ID');
        $item = $this->orderItem($connection, $orderId);

        if ($order->source_type !== $sourceType->value
            || $order->order_source_authorization_id === null
            || (int) $order->order_source_authorization_id !== $authorizationId
            || $order->order_source_authorization_public_id === null
            || ! hash_equals($order->order_source_authorization_public_id, $authorization->public_id)
            || (int) $order->user_id !== $userId
            || (int) $order->total_amount_irr !== 0
            || $order->settled_amount_irr !== null
            || $order->currency !== 'IRR'
            || $order->paid_at !== null
            || $item->source_quote_id !== null
            || $item->source_quote_public_id !== null
            || $item->order_source_authorization_id === null
            || (int) $item->order_source_authorization_id !== $authorizationId
            || $item->order_source_authorization_public_id === null
            || ! hash_equals($item->order_source_authorization_public_id, $authorization->public_id)
            || (int) $item->plan_offering_id !== $offeringId
            || $item->override_source !== 'source'
            || $item->override_reference_code !== $sourceType->value
            || $item->override_price_irr === null
            || (int) $item->override_price_irr !== 0
            || (int) $item->effective_price_irr !== 0
            || (int) $item->discount_irr !== 0
            || (int) $item->final_price_irr !== 0
            || $item->currency !== 'IRR'
            || ! hash_equals(strtolower($item->configuration_snapshot_hash), strtolower($authorization->configuration_snapshot_hash))
            || ! hash_equals($item->configuration_snapshot, $authorization->configuration_snapshot)) {
            throw new RuntimeException('Stored zero-cost Order does not match immutable source authorization.');
        }

        $state = OrderState::tryFrom($order->state);
        if ($state === null) {
            throw new RuntimeException('Stored zero-cost Order state is invalid.');
        }

        return new NonPaidOrderReceipt(
            $orderId,
            $order->public_id,
            $item->public_id,
            $authorizationId,
            $authorization->public_id,
            $sourceType,
            $userId,
            $offeringId,
            $state,
            $this->nonNegativeDatabaseInt($order->state_version, 'Order state version'),
            Money::irr(0),
            true,
        );
    }

    private function replayAfterUniqueRace(string $sourceAuthorizationPublicId): ?NonPaidOrderReceipt
    {
        $connection = $this->database->connection();
        $authorization = $this->authorizationByPublicId($connection, $sourceAuthorizationPublicId);
        if ($authorization === null) {
            return null;
        }
        $sourceType = OrderSourceType::tryFrom($authorization->source_type);
        if ($sourceType === null || ! $this->isSupportedNonPaidSource($sourceType)) {
            return null;
        }
        $order = $this->orderByAuthorizationId(
            $connection,
            $this->positiveDatabaseInt($authorization->id, 'Order source authorization ID'),
        );
        if ($order === null) {
            return null;
        }

        try {
            return $this->replayReceipt($connection, $order, $authorization, $sourceType);
        } catch (DomainException|RuntimeException) {
            return null;
        }
    }

    /** @param SourceAuthorizationRow $authorization */
    private function recordAudit(
        Connection $connection,
        string $orderPublicId,
        object $authorization,
        OrderSourceType $sourceType,
        string $correlationId,
    ): void {
        $connection->table('audit_logs')->insert([
            'actor_type' => $authorization->actor_type,
            'actor_id' => $authorization->actor_id === null ? null : $this->positiveDatabaseInt($authorization->actor_id, 'Order source actor ID'),
            'action' => 'order.non_paid.authorized',
            'target_type' => 'order',
            'target_id' => $orderPublicId,
            'before_safe_data' => null,
            'after_safe_data' => json_encode([
                'source_authorization_public_id' => $authorization->public_id,
                'source_type' => $sourceType->value,
                'user_id' => $this->positiveDatabaseInt($authorization->user_id, 'Order source authorization user ID'),
                'plan_offering_id' => $this->positiveDatabaseInt($authorization->plan_offering_id, 'Order source authorization Plan Offering ID'),
                'state' => OrderState::Authorized->value,
                'state_version' => 0,
                'commercial_amount_irr' => 0,
                'currency' => 'IRR',
            ], JSON_THROW_ON_ERROR),
            'reason_code' => 'authoritative_non_paid_source',
            'reason' => null,
            'correlation_id' => $correlationId,
            'request_fingerprint' => $authorization->request_payload_hash,
            'created_at' => $this->timestamp(),
        ]);
    }

    private function isSupportedNonPaidSource(OrderSourceType $sourceType): bool
    {
        return in_array($sourceType, [
            OrderSourceType::Trial,
            OrderSourceType::BenefitCode,
            OrderSourceType::AdministratorGrant,
        ], true);
    }

    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function assertUlid(string $value, string $label): void
    {
        if (! Str::isUlid($value)) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function assertToken(string $value, string $label, int $minLength, int $maxLength): void
    {
        $length = strlen($value);
        if ($length < $minLength || $length > $maxLength || preg_match('/\A[A-Za-z0-9._:-]+\z/D', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function storedSha256(string $value, string $label): string
    {
        $normalized = strtolower($value);
        if (preg_match('/\A[0-9a-f]{64}\z/D', $normalized) !== 1) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $normalized;
    }

    private function positiveDatabaseInt(int|string $value, string $label): int
    {
        $int = (int) $value;
        if ($int < 1 || (string) $int !== (string) $value) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $int;
    }

    private function nonNegativeDatabaseInt(int|string $value, string $label): int
    {
        $int = (int) $value;
        if ($int < 0 || (string) $int !== (string) $value) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $int;
    }
}
