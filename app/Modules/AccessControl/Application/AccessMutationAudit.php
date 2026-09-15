<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Application;

use App\Shared\Application\Clock;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final readonly class AccessMutationAudit
{
    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
    ) {}

    public function existing(
        string $action,
        int $targetAdministratorId,
        string $requestFingerprint,
        bool $lock = false,
    ): ?AccessMutationReceipt {
        $query = $this->database->connection()->table('audit_logs')
            ->where('action', $action)
            ->where('request_fingerprint', $requestFingerprint);

        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var object{target_id: ?string, before_safe_data: ?string, after_safe_data: ?string}|null $row */
        $row = $query->first(['target_id', 'before_safe_data', 'after_safe_data']);

        if ($row === null) {
            return null;
        }

        if ($row->target_id !== (string) $targetAdministratorId) {
            throw new RuntimeException('Access mutation fingerprint conflict.');
        }

        $before = $this->decode($row->before_safe_data);
        $after = $this->decode($row->after_safe_data);

        return new AccessMutationReceipt(
            $action,
            $targetAdministratorId,
            $before,
            $after,
            $before !== $after,
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
        int $targetAdministratorId,
        AccessChangeContext $context,
        array $before,
        array $after,
    ): AccessMutationReceipt {
        $connection->table('audit_logs')->insert([
            'actor_type' => 'administrator',
            'actor_id' => (string) $context->actorAdministratorId,
            'action' => $action,
            'target_type' => 'administrator',
            'target_id' => (string) $targetAdministratorId,
            'before_safe_data' => json_encode($before, JSON_THROW_ON_ERROR),
            'after_safe_data' => json_encode($after, JSON_THROW_ON_ERROR),
            'reason_code' => $context->reasonCode,
            'reason' => $context->reason,
            'correlation_id' => $context->correlationId,
            'request_fingerprint' => $context->requestFingerprint,
            'created_at' => $this->clock->now()->format('Y-m-d H:i:s.u'),
        ]);

        return new AccessMutationReceipt(
            $action,
            $targetAdministratorId,
            $before,
            $after,
            $before !== $after,
        );
    }

    /** @return array<string, bool|int|string|null> */
    private function decode(?string $encoded): array
    {
        if ($encoded === null) {
            return [];
        }

        $decoded = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException('Stored access audit data is invalid.');
        }

        $safe = [];

        foreach ($decoded as $key => $value) {
            if (! is_string($key) || (! is_bool($value) && ! is_int($value) && ! is_string($value) && $value !== null)) {
                throw new RuntimeException('Stored access audit data is invalid.');
            }

            $safe[$key] = $value;
        }

        return $safe;
    }
}
