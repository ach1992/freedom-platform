<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Application;

use App\Modules\Promotions\Domain\ReferralRewardState;
use App\Modules\Wallet\Application\ReferralRewardWalletService;
use App\Modules\Wallet\Domain\IrrMoney;
use App\Shared\Application\Clock;
use App\Shared\Application\OutboxPublisher;
use App\Shared\Application\SafeOutboxPayload;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;

final readonly class ReferralRewardLifecycleService
{
    private const OUTBOX_CONTRACT_VERSION = 1;

    public function __construct(
        private DatabaseManager $database,
        private ReferralRewardWalletService $wallet,
        private OutboxPublisher $outbox,
        private Clock $clock,
    ) {}

    /** @requirement REF-001 WAL-002 DAT-002 DAT-003 DAT-004 QUA-001 QUA-004 */
    public function process(string $rewardPublicId, string $correlationId): ReferralRewardLifecycleReceipt
    {
        $this->assertUlid($rewardPublicId, 'Referral reward public ID');
        $this->assertToken($correlationId, 'Referral reward lifecycle correlation ID', 8, 64);

        return $this->database->connection()->transaction(function (Connection $connection) use ($rewardPublicId, $correlationId): ReferralRewardLifecycleReceipt {
            $reward = $this->lockRewardByPublicId($connection, $rewardPublicId);
            $state = $this->state($reward->state);
            if (in_array($state, [ReferralRewardState::Canceled, ReferralRewardState::Reversed], true)) {
                return $this->receipt($reward, false, true);
            }

            $refund = $this->firstRefundForSettlement(
                $connection,
                $this->positiveInt($reward->purchase_settlement_id, 'Referral reward purchase settlement ID'),
            );
            if ($refund !== null) {
                return $this->applyRefundToLockedReward($connection, $reward, $refund, $correlationId);
            }

            if ($state === ReferralRewardState::Released) {
                return $this->receipt($reward, false, true);
            }

            if ($this->clock->now() < $this->storedDateTime($reward->release_at)) {
                return $this->receipt($reward, false, false);
            }

            return $this->releaseLocked($connection, $reward, $correlationId);
        }, 3);
    }

    /**
     * Apply one accepted #109 purchase refund to every reward created from the same settlement.
     *
     * @return list<ReferralRewardLifecycleReceipt>
     *
     * @requirement REF-001 WAL-002 DAT-002 DAT-003 DAT-004 QUA-001 QUA-004
     */
    public function applyPurchaseRefund(string $purchaseRefundPublicId, string $correlationId): array
    {
        $this->assertUlid($purchaseRefundPublicId, 'Purchase refund public ID');
        $this->assertToken($correlationId, 'Referral reward refund correlation ID', 8, 64);

        return $this->database->connection()->transaction(function (Connection $connection) use ($purchaseRefundPublicId, $correlationId): array {
            /** @var stdClass|null $refund */
            $refund = $connection->table('purchase_refunds')
                ->where('public_id', $purchaseRefundPublicId)
                ->first(['id', 'public_id', 'purchase_settlement_id']);
            if ($refund === null) {
                throw new DomainException('Authoritative purchase refund does not exist.');
            }

            $settlementId = $this->positiveInt($refund->purchase_settlement_id, 'Purchase refund settlement ID');
            $this->lockSettlement($connection, $settlementId);

            /** @var list<stdClass> $rewards */
            $rewards = $connection->table('referral_rewards')
                ->where('purchase_settlement_id', $settlementId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get($this->rewardColumns())
                ->all();

            $receipts = [];
            foreach ($rewards as $reward) {
                $state = $this->state($reward->state);
                if (in_array($state, [ReferralRewardState::Canceled, ReferralRewardState::Reversed], true)) {
                    $receipts[] = $this->receipt($reward, false, true);

                    continue;
                }
                $receipts[] = $this->applyRefundToLockedReward($connection, $reward, $refund, $correlationId);
            }

            return $receipts;
        }, 3);
    }

    private function releaseLocked(
        Connection $connection,
        stdClass $reward,
        string $correlationId,
    ): ReferralRewardLifecycleReceipt {
        if ($this->state($reward->state) !== ReferralRewardState::Pending) {
            throw new RuntimeException('Referral reward release requires pending state.');
        }

        $rewardId = $this->positiveInt($reward->id, 'Referral reward ID');
        $recipientUserId = $this->positiveInt($reward->recipient_user_id, 'Referral reward recipient user ID');
        $amount = IrrMoney::positive($this->positiveInt($reward->amount_irr, 'Referral reward amount'));
        $ledger = $this->wallet->release(
            'referral.reward.release.'.$reward->public_id,
            $recipientUserId,
            $reward->public_id,
            $amount,
            $correlationId,
        );
        $occurredAt = $this->databaseDateTime($this->clock->now());

        $connection->table('referral_reward_lifecycle_events')->insert([
            'referral_reward_id' => $rewardId,
            'event_type' => ReferralRewardState::Released->value,
            'ledger_transaction_id' => $ledger->transactionId,
            'purchase_refund_id' => null,
            'occurred_at' => $occurredAt,
            'correlation_id' => $correlationId,
            'created_at' => $occurredAt,
        ]);
        $updated = $connection->table('referral_rewards')
            ->where('id', $rewardId)
            ->where('state', ReferralRewardState::Pending->value)
            ->update([
                'state' => ReferralRewardState::Released->value,
                'release_ledger_transaction_id' => $ledger->transactionId,
                'released_at' => $occurredAt,
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('Referral reward release state changed concurrently.');
        }

        $this->outbox->publish(
            (string) Str::uuid(),
            'referral.reward.released:'.$reward->public_id,
            'referral.reward.released',
            'referral_reward',
            $reward->public_id,
            new SafeOutboxPayload([
                'recipient_role' => $reward->recipient_role,
                'reward_public_id' => $reward->public_id,
                'state' => ReferralRewardState::Released->value,
            ]),
            $correlationId,
            self::OUTBOX_CONTRACT_VERSION,
        );

        $current = $this->rewardById($connection, $rewardId);
        if ($current === null) {
            throw new RuntimeException('Released referral reward disappeared.');
        }

        return $this->receipt($current, true, $ledger->replayed);
    }

    private function applyRefundToLockedReward(
        Connection $connection,
        stdClass $reward,
        stdClass $refund,
        string $correlationId,
    ): ReferralRewardLifecycleReceipt {
        $state = $this->state($reward->state);
        if ($this->positiveInt($refund->purchase_settlement_id, 'Purchase refund settlement ID')
            !== $this->positiveInt($reward->purchase_settlement_id, 'Referral reward settlement ID')) {
            throw new RuntimeException('Purchase refund and referral reward settlement authority do not match.');
        }

        return match ($state) {
            ReferralRewardState::Pending => $this->cancelLocked($connection, $reward, $refund, $correlationId),
            ReferralRewardState::Released => $this->reverseLocked($connection, $reward, $refund, $correlationId),
            ReferralRewardState::Canceled, ReferralRewardState::Reversed => $this->receipt($reward, false, true),
        };
    }

    private function cancelLocked(
        Connection $connection,
        stdClass $reward,
        stdClass $refund,
        string $correlationId,
    ): ReferralRewardLifecycleReceipt {
        $rewardId = $this->positiveInt($reward->id, 'Referral reward ID');
        $refundId = $this->positiveInt($refund->id, 'Purchase refund ID');
        $occurredAt = $this->databaseDateTime($this->clock->now());

        $connection->table('referral_reward_lifecycle_events')->insert([
            'referral_reward_id' => $rewardId,
            'event_type' => ReferralRewardState::Canceled->value,
            'ledger_transaction_id' => null,
            'purchase_refund_id' => $refundId,
            'occurred_at' => $occurredAt,
            'correlation_id' => $correlationId,
            'created_at' => $occurredAt,
        ]);
        $updated = $connection->table('referral_rewards')
            ->where('id', $rewardId)
            ->where('state', ReferralRewardState::Pending->value)
            ->update([
                'state' => ReferralRewardState::Canceled->value,
                'purchase_refund_id' => $refundId,
                'canceled_at' => $occurredAt,
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('Referral reward cancellation state changed concurrently.');
        }

        $current = $this->rewardById($connection, $rewardId);
        if ($current === null) {
            throw new RuntimeException('Canceled referral reward disappeared.');
        }

        return $this->receipt($current, true, false);
    }

    private function reverseLocked(
        Connection $connection,
        stdClass $reward,
        stdClass $refund,
        string $correlationId,
    ): ReferralRewardLifecycleReceipt {
        $releaseLedgerId = $this->positiveInt($reward->release_ledger_transaction_id, 'Referral reward release ledger transaction ID');
        $refundId = $this->positiveInt($refund->id, 'Purchase refund ID');
        $amount = IrrMoney::positive($this->positiveInt($reward->amount_irr, 'Referral reward amount'));
        $ledger = $this->wallet->reverse(
            'referral.reward.reversal.'.$reward->public_id,
            $this->positiveInt($reward->recipient_user_id, 'Referral reward recipient user ID'),
            $reward->public_id,
            $releaseLedgerId,
            $amount,
            $correlationId,
        );
        $rewardId = $this->positiveInt($reward->id, 'Referral reward ID');
        $occurredAt = $this->databaseDateTime($this->clock->now());

        $connection->table('referral_reward_lifecycle_events')->insert([
            'referral_reward_id' => $rewardId,
            'event_type' => ReferralRewardState::Reversed->value,
            'ledger_transaction_id' => $ledger->transactionId,
            'purchase_refund_id' => $refundId,
            'occurred_at' => $occurredAt,
            'correlation_id' => $correlationId,
            'created_at' => $occurredAt,
        ]);
        $updated = $connection->table('referral_rewards')
            ->where('id', $rewardId)
            ->where('state', ReferralRewardState::Released->value)
            ->update([
                'state' => ReferralRewardState::Reversed->value,
                'reversal_ledger_transaction_id' => $ledger->transactionId,
                'purchase_refund_id' => $refundId,
                'reversed_at' => $occurredAt,
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('Referral reward reversal state changed concurrently.');
        }

        $this->outbox->publish(
            (string) Str::uuid(),
            'referral.reward.reversed:'.$reward->public_id,
            'referral.reward.reversed',
            'referral_reward',
            $reward->public_id,
            new SafeOutboxPayload([
                'purchase_refund_public_id' => $refund->public_id,
                'recipient_role' => $reward->recipient_role,
                'reward_public_id' => $reward->public_id,
                'state' => ReferralRewardState::Reversed->value,
            ]),
            $correlationId,
            self::OUTBOX_CONTRACT_VERSION,
        );

        $current = $this->rewardById($connection, $rewardId);
        if ($current === null) {
            throw new RuntimeException('Reversed referral reward disappeared.');
        }

        return $this->receipt($current, true, $ledger->replayed);
    }

    private function lockRewardByPublicId(Connection $connection, string $rewardPublicId): stdClass
    {
        /** @var object{id:int|string,purchase_settlement_id:int|string}|null $candidate */
        $candidate = $connection->table('referral_rewards')
            ->where('public_id', $rewardPublicId)
            ->first(['id', 'purchase_settlement_id']);
        if ($candidate === null) {
            throw new DomainException('Referral reward does not exist.');
        }

        $this->lockSettlement(
            $connection,
            $this->positiveInt($candidate->purchase_settlement_id, 'Referral reward purchase settlement ID'),
        );
        /** @var stdClass|null $reward */
        $reward = $connection->table('referral_rewards')
            ->where('id', $this->positiveInt($candidate->id, 'Referral reward ID'))
            ->lockForUpdate()
            ->first($this->rewardColumns());
        if ($reward === null || ! hash_equals($reward->public_id, $rewardPublicId)) {
            throw new RuntimeException('Referral reward identity changed while acquiring lifecycle authority.');
        }

        return $reward;
    }

    private function lockSettlement(Connection $connection, int $settlementId): void
    {
        $locked = $connection->table('purchase_settlements')
            ->where('id', $settlementId)
            ->lockForUpdate()
            ->value('id');
        if ($locked === null) {
            throw new RuntimeException('Referral reward purchase settlement authority is unavailable.');
        }
    }

    private function firstRefundForSettlement(Connection $connection, int $settlementId): ?stdClass
    {
        return $connection->table('purchase_refunds')
            ->where('purchase_settlement_id', $settlementId)
            ->orderBy('id')
            ->first(['id', 'public_id', 'purchase_settlement_id']);
    }

    private function rewardById(Connection $connection, int $rewardId): ?stdClass
    {
        return $connection->table('referral_rewards')->where('id', $rewardId)->first($this->rewardColumns());
    }

    /** @return list<string> */
    private function rewardColumns(): array
    {
        return [
            'id', 'public_id', 'accrual_id', 'purchase_settlement_id', 'recipient_role', 'recipient_user_id',
            'amount_irr', 'state', 'release_at', 'expires_at', 'transferable', 'release_ledger_transaction_id',
            'reversal_ledger_transaction_id', 'purchase_refund_id', 'released_at', 'canceled_at', 'reversed_at',
            'created_at',
        ];
    }

    private function receipt(stdClass $reward, bool $changed, bool $replayed): ReferralRewardLifecycleReceipt
    {
        return new ReferralRewardLifecycleReceipt(
            $this->positiveInt($reward->id, 'Referral reward ID'),
            $reward->public_id,
            $this->state($reward->state),
            $this->nullablePositiveInt($reward->release_ledger_transaction_id, 'Referral reward release ledger transaction ID'),
            $this->nullablePositiveInt($reward->reversal_ledger_transaction_id, 'Referral reward reversal ledger transaction ID'),
            $this->nullablePositiveInt($reward->purchase_refund_id, 'Referral reward purchase refund ID'),
            $changed,
            $replayed,
        );
    }

    private function state(string $value): ReferralRewardState
    {
        return ReferralRewardState::tryFrom($value)
            ?? throw new RuntimeException('Stored referral reward state is invalid.');
    }

    private function assertUlid(string $value, string $label): void
    {
        if (! Str::isUlid($value)) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function assertToken(string $value, string $label, int $min, int $max): void
    {
        $length = strlen($value);
        if ($length < $min || $length > $max || preg_match('/\A[A-Za-z0-9:_.-]+\z/', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function positiveInt(mixed $value, string $label): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($integer === false) {
            throw new RuntimeException($label.' must be a positive integer.');
        }

        return $integer;
    }

    private function nullablePositiveInt(mixed $value, string $label): ?int
    {
        if ($value === null) {
            return null;
        }

        return $this->positiveInt($value, $label);
    }

    private function storedDateTime(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if ($date === false) {
            throw new RuntimeException('Stored referral reward timestamp is invalid.');
        }

        return $date;
    }

    private function databaseDateTime(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
