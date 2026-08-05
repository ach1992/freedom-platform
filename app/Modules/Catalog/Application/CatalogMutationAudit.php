<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Shared\Application\Clock;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final readonly class CatalogMutationAudit
{
    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
    ) {}

    public function existing(string $action, string $requestFingerprint, bool $lock = false): ?CatalogMutationReceipt
    {
        $query = $this->database->connection()->table('audit_logs')
            ->where('action', $action)
            ->where('request_fingerprint', $requestFingerprint);

        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var object{target_type: ?string, target_id: ?string, before_safe_data: ?string, after_safe_data: ?string}|null $row */
        $row = $query->first(['target_type', 'target_id', 'before_safe_data', 'after_safe_data']);

        if ($row === null) {
            return null;
        }

        if ($row->target_type === null || $row->target_id === null || ! ctype_digit($row->target_id)) {
            throw new RuntimeException('Stored catalog audit target is invalid.');
        }

        $before = $this->decode($row->before_safe_data);
        $after = $this->decode($row->after_safe_data);

        return new CatalogMutationReceipt(
            $action,
            $row->target_type,
            (int) $row->target_id,
            $before,
            $after,
            $this->entityState($before) !== $this->entityState($after),
            true,
        );
    }

    /**
     * @param  array<string, bool|int|string|null>  $before
     * @param  array<string, bool|int|string|null>  $after
     */
    public function record(
        Connection $connection,
        string $action,
        string $targetType,
        int $targetId,
        CatalogChangeContext $context,
        array $before,
        array $after,
        bool $changed,
    ): CatalogMutationReceipt {
        $connection->table('audit_logs')->insert([
            'actor_type' => 'administrator',
            'actor_id' => (string) $context->actorAdministratorId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => (string) $targetId,
            'before_safe_data' => json_encode($before, JSON_THROW_ON_ERROR),
            'after_safe_data' => json_encode($after, JSON_THROW_ON_ERROR),
            'reason_code' => $context->reasonCode,
            'reason' => $context->reason,
            'correlation_id' => $context->correlationId,
            'request_fingerprint' => $context->requestFingerprint,
            'created_at' => $this->clock->now()->format('Y-m-d H:i:s.u'),
        ]);

        return new CatalogMutationReceipt($action, $targetType, $targetId, $before, $after, $changed);
    }

    /** @return array<string, bool|int|string|null> */
    private function decode(?string $encoded): array
    {
        if ($encoded === null) {
            return [];
        }

        $decoded = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException('Stored catalog audit data is invalid.');
        }

        $safe = [];
        foreach ($decoded as $key => $value) {
            if (! is_string($key) || (! is_bool($value) && ! is_int($value) && ! is_string($value) && $value !== null)) {
                throw new RuntimeException('Stored catalog audit data is invalid.');
            }
            $safe[$key] = $value;
        }

        return $safe;
    }

    /**
     * @param  array<string, bool|int|string|null>  $state
     * @return array<string, bool|int|string|null>
     */
    private function entityState(array $state): array
    {
        unset($state['request_payload_hash']);

        return $state;
    }
}
