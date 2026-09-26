<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use App\Modules\Customers\Application\Contracts\CustomerTierPurchaseMetricsSource;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class CustomerTierAutomaticRecalculationService
{
    public function __construct(
        private DatabaseManager $database,
        private CustomerTierPurchaseMetricsSource $purchaseMetrics,
        private CustomerTierService $tiers,
        private Clock $clock,
    ) {}

    /** @requirement USR-002 DAT-002 DAT-003 QUA-001 QUA-004 */
    public function afterPurchase(
        int $userId,
        string $purchaseSettlementPublicId,
        string $correlationId,
    ): ?CustomerMutationReceipt {
        if ($userId < 1 || ! Str::isUlid($purchaseSettlementPublicId)) {
            throw new DomainException('Customer tier purchase trigger identity is invalid.');
        }

        return $this->recalculate(
            $userId,
            'tier:purchase:'.$purchaseSettlementPublicId,
            'successful_purchase',
            $correlationId,
        );
    }

    /** @requirement USR-002 DAT-002 DAT-003 QUA-001 */
    public function daily(int $userId, string $dateKey): ?CustomerMutationReceipt
    {
        if ($userId < 1 || preg_match('/\\A[0-9]{8}\\z/', $dateKey) !== 1) {
            throw new DomainException('Customer tier daily trigger identity is invalid.');
        }

        return $this->recalculate(
            $userId,
            'tier:daily:'.$dateKey.':'.$userId,
            'daily_recalculation',
            'tier-daily-'.$dateKey.'-'.$userId,
        );
    }

    private function recalculate(
        int $userId,
        string $requestFingerprint,
        string $reasonCode,
        string $correlationId,
    ): ?CustomerMutationReceipt {
        $user = $this->database->connection()->table('users')
            ->where('id', $userId)
            ->first(['account_type', 'first_seen_at', 'created_at']);
        if ($user === null) {
            throw new DomainException('Customer tier purchase trigger user does not exist.');
        }
        if ((string) $user->account_type !== 'customer') {
            return null;
        }
        if (! $this->database->connection()->table('customer_profiles')->where('user_id', $userId)->exists()) {
            throw new DomainException('Customer tier purchase trigger profile does not exist.');
        }

        $purchase = $this->purchaseMetrics->metricsFor($userId);
        $firstSeen = $user->first_seen_at ?? $user->created_at;
        if (! is_string($firstSeen) || $firstSeen === '') {
            throw new RuntimeException('Customer membership start timestamp is unavailable.');
        }

        return $this->tiers->recalculate(
            $userId,
            new CustomerTierMetrics(
                $purchase->successfulPurchaseCount,
                $this->membershipDays($firstSeen),
                $purchase->totalSpendIrr,
            ),
            new CustomerChangeContext(
                $requestFingerprint,
                $this->customerCorrelationId($correlationId),
                $reasonCode,
            ),
        );
    }

    private function membershipDays(string $firstSeen): int
    {
        $timezone = new DateTimeZone('UTC');
        $startedAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $firstSeen, $timezone);
        if ($startedAt === false) {
            $startedAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $firstSeen, $timezone);
        }
        if ($startedAt === false) {
            throw new RuntimeException('Customer membership start timestamp is invalid.');
        }

        $now = $this->clock->now()->setTimezone($timezone);
        if ($startedAt > $now) {
            throw new RuntimeException('Customer membership start timestamp cannot be in the future.');
        }

        return intdiv($now->getTimestamp() - $startedAt->getTimestamp(), 86400);
    }

    private function customerCorrelationId(string $correlationId): string
    {
        if (preg_match('/\A[A-Za-z0-9:_-]{16,64}\z/', $correlationId) === 1) {
            return $correlationId;
        }

        return 'tier:'.substr(hash('sha256', $correlationId), 0, 48);
    }
}
