<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application;

use App\Shared\Application\Clock;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final readonly class PanelMutationAudit
{
    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
    ) {}

    /** @return array{payload_hmac: string, receipt: PanelMutationReceipt}|null */
    public function existing(string $action, string $requestFingerprint, bool $lock = false): ?array
    {
        $query = $this->database->connection()->table('panel_mutation_receipts')
            ->where('action', $action)
            ->where('request_fingerprint', $requestFingerprint);

        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var object{target_type: string, target_id: string, request_payload_hmac: string, before_safe_data: ?string, after_safe_data: string}|null $row */
        $row = $query->first([
            'target_type',
            'target_id',
            'request_payload_hmac',
            'before_safe_data',
            'after_safe_data',
        ]);

        if ($row === null) {
            return null;
        }

        $before = $this->decode($row->before_safe_data);
        $after = $this->decode($row->after_safe_data);

        return [
            'payload_hmac' => $row->request_payload_hmac,
            'receipt' => new PanelMutationReceipt(
                $action,
                $row->target_type,
                $row->target_id,
                $before,
                $after,
                $before !== $after,
                true,
            ),
        ];
    }

    /**
     * @param  array<string, bool|int|string|null>  $before
     * @param  array<string, bool|int|string|null>  $after
     */
    public function record(
        Connection $connection,
        string $action,
        string $targetType,
        string $targetId,
        string $payloadHmac,
        PanelChangeContext $context,
        array $before,
        array $after,
        bool $changed,
    ): PanelMutationReceipt {
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');

        $connection->table('panel_mutation_receipts')->insert([
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'request_fingerprint' => $context->requestFingerprint,
            'request_payload_hmac' => $payloadHmac,
            'before_safe_data' => $before === [] ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_safe_data' => json_encode($after, JSON_THROW_ON_ERROR),
            'created_at' => $now,
        ]);

        $connection->table('audit_logs')->insert([
            'actor_type' => 'administrator',
            'actor_id' => (string) $context->actorAdministratorId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'before_safe_data' => $before === [] ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_safe_data' => json_encode($after, JSON_THROW_ON_ERROR),
            'reason_code' => $context->reasonCode,
            'reason' => $context->reason,
            'correlation_id' => $context->correlationId,
            'request_fingerprint' => $context->requestFingerprint,
            'created_at' => $now,
        ]);

        return new PanelMutationReceipt(
            $action,
            $targetType,
            $targetId,
            $before,
            $after,
            $changed,
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
            throw new RuntimeException('Stored panel mutation receipt is invalid.');
        }

        $safe = [];
        foreach ($decoded as $key => $value) {
            if (! is_string($key)
                || (! is_bool($value) && ! is_int($value) && ! is_string($value) && $value !== null)
            ) {
                throw new RuntimeException('Stored panel mutation receipt is invalid.');
            }
            $safe[$key] = $value;
        }

        return $safe;
    }
}
