<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Shared\Application\Clock;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use RuntimeException;
use stdClass;

final readonly class TelegramChannelMembershipRuleService
{
    private const TARGET_TYPE = 'telegram_channel_membership_rule';

    public function __construct(
        private DatabaseManager $database,
        private TelegramConfigurationMutationExecutor $executor,
        private TelegramConfigurationMutationAudit $audit,
        private Clock $clock,
    ) {}

    /** @requirement ONB-003 CHN-001 ACL-002 SEC-001 DAT-003 */
    public function create(
        TelegramChannelMembershipRuleDefinition $definition,
        TelegramConfigurationChangeContext $context,
    ): TelegramConfigurationMutationReceipt {
        $payloadHash = $this->payloadHash($this->definitionSafePayload($definition));

        return $this->executor->execute(
            'telegram.membership_rule.create',
            self::TARGET_TYPE,
            null,
            $payloadHash,
            $context,
            function (Connection $connection) use ($definition, $context, $payloadHash): TelegramConfigurationMutationReceipt {
                $this->validateDefinitionReferences($connection, $definition);
                $this->assertChannelsExist($connection, $definition->requiredChannelIds);
                $now = $this->timestamp();
                $id = (int) $connection->table('channel_membership_rules')->insertGetId([
                    'rule_key' => $definition->ruleKey,
                    'action' => $definition->action,
                    'audience' => $definition->audience,
                    'tier_code' => $definition->tierCode,
                    'customer_tag_id' => $definition->customerTagId,
                    'plan_offering_id' => $definition->planOfferingId,
                    'match_mode' => $definition->matchMode,
                    'failure_policy' => $definition->failurePolicy,
                    'priority' => $definition->priority,
                    'effective_from' => $definition->effectiveFromUtc,
                    'effective_until' => $definition->effectiveUntilUtc,
                    'state' => 'draft',
                    'version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->replaceChannels($connection, $id, $definition->requiredChannelIds, $now);
                $after = $this->safeStateFromDefinition($id, $definition, 'draft', 1, $payloadHash);

                return $this->audit->record(
                    $connection,
                    'telegram.membership_rule.create',
                    self::TARGET_TYPE,
                    $id,
                    $context,
                    [],
                    $after,
                    true,
                );
            },
        );
    }

    public function update(
        int $ruleId,
        int $expectedVersion,
        TelegramChannelMembershipRuleDefinition $definition,
        TelegramConfigurationChangeContext $context,
    ): TelegramConfigurationMutationReceipt {
        $this->assertPositiveIdentity($ruleId, $expectedVersion);
        $payloadHash = $this->payloadHash([
            'rule_id' => $ruleId,
            'expected_version' => $expectedVersion,
            ...$this->definitionSafePayload($definition),
        ]);

        return $this->executor->execute(
            'telegram.membership_rule.update',
            self::TARGET_TYPE,
            $ruleId,
            $payloadHash,
            $context,
            function (Connection $connection) use ($ruleId, $expectedVersion, $definition, $context, $payloadHash): TelegramConfigurationMutationReceipt {
                $row = $this->lockedRow($connection, $ruleId);
                $this->assertVersion($row, $expectedVersion);
                if ($row->state === 'active') {
                    throw new DomainException('Active Telegram membership rule must be disabled before editing.');
                }

                $this->validateDefinitionReferences($connection, $definition);
                $this->assertChannelsExist($connection, $definition->requiredChannelIds);
                $before = $this->safeState($connection, $row, $payloadHash, true);
                $candidate = $this->safeStateFromDefinition(
                    $ruleId,
                    $definition,
                    (string) $row->state,
                    (int) $row->version,
                    $payloadHash,
                );
                $beforeComparable = $before;
                $candidateComparable = $candidate;
                unset($beforeComparable['request_payload_hash'], $candidateComparable['request_payload_hash']);
                if ($beforeComparable === $candidateComparable) {
                    return $this->audit->record(
                        $connection,
                        'telegram.membership_rule.update',
                        self::TARGET_TYPE,
                        $ruleId,
                        $context,
                        $before,
                        $before,
                        false,
                    );
                }

                $newVersion = $expectedVersion + 1;
                $now = $this->timestamp();
                $connection->table('channel_membership_rules')->where('id', $ruleId)->update([
                    'rule_key' => $definition->ruleKey,
                    'action' => $definition->action,
                    'audience' => $definition->audience,
                    'tier_code' => $definition->tierCode,
                    'customer_tag_id' => $definition->customerTagId,
                    'plan_offering_id' => $definition->planOfferingId,
                    'match_mode' => $definition->matchMode,
                    'failure_policy' => $definition->failurePolicy,
                    'priority' => $definition->priority,
                    'effective_from' => $definition->effectiveFromUtc,
                    'effective_until' => $definition->effectiveUntilUtc,
                    'version' => $newVersion,
                    'updated_at' => $now,
                ]);
                $this->replaceChannels($connection, $ruleId, $definition->requiredChannelIds, $now);
                $after = $this->safeStateFromDefinition(
                    $ruleId,
                    $definition,
                    (string) $row->state,
                    $newVersion,
                    $payloadHash,
                );

                return $this->audit->record(
                    $connection,
                    'telegram.membership_rule.update',
                    self::TARGET_TYPE,
                    $ruleId,
                    $context,
                    $before,
                    $after,
                    true,
                );
            },
        );
    }

    public function activate(
        int $ruleId,
        int $expectedVersion,
        TelegramConfigurationChangeContext $context,
    ): TelegramConfigurationMutationReceipt {
        $this->assertPositiveIdentity($ruleId, $expectedVersion);
        $payloadHash = $this->payloadHash(['rule_id' => $ruleId, 'expected_version' => $expectedVersion]);

        return $this->executor->execute(
            'telegram.membership_rule.activate',
            self::TARGET_TYPE,
            $ruleId,
            $payloadHash,
            $context,
            function (Connection $connection) use ($ruleId, $expectedVersion, $context, $payloadHash): TelegramConfigurationMutationReceipt {
                $row = $this->lockedRow($connection, $ruleId);
                $this->assertVersion($row, $expectedVersion);
                if ($row->state === 'active') {
                    throw new DomainException('Telegram membership rule is already active.');
                }

                $this->validateStoredReferences($connection, $row);
                $channelIds = $this->channelIds($connection, $ruleId, true);
                if ($channelIds === []) {
                    throw new DomainException('Telegram membership rule requires at least one channel before activation.');
                }
                $this->assertChannelsActive($connection, $channelIds);

                $before = $this->safeStateWithChannelIds($row, $channelIds, $payloadHash);
                $newVersion = $expectedVersion + 1;
                $connection->table('channel_membership_rules')->where('id', $ruleId)->update([
                    'state' => 'active',
                    'version' => $newVersion,
                    'updated_at' => $this->timestamp(),
                ]);
                $after = $before;
                $after['state'] = 'active';
                $after['version'] = $newVersion;

                return $this->audit->record(
                    $connection,
                    'telegram.membership_rule.activate',
                    self::TARGET_TYPE,
                    $ruleId,
                    $context,
                    $before,
                    $after,
                    true,
                );
            },
        );
    }

    public function disable(
        int $ruleId,
        int $expectedVersion,
        TelegramConfigurationChangeContext $context,
    ): TelegramConfigurationMutationReceipt {
        $this->assertPositiveIdentity($ruleId, $expectedVersion);
        $payloadHash = $this->payloadHash(['rule_id' => $ruleId, 'expected_version' => $expectedVersion]);

        return $this->executor->execute(
            'telegram.membership_rule.disable',
            self::TARGET_TYPE,
            $ruleId,
            $payloadHash,
            $context,
            function (Connection $connection) use ($ruleId, $expectedVersion, $context, $payloadHash): TelegramConfigurationMutationReceipt {
                $row = $this->lockedRow($connection, $ruleId);
                $this->assertVersion($row, $expectedVersion);
                $before = $this->safeState($connection, $row, $payloadHash, true);
                if ($row->state === 'disabled') {
                    return $this->audit->record(
                        $connection,
                        'telegram.membership_rule.disable',
                        self::TARGET_TYPE,
                        $ruleId,
                        $context,
                        $before,
                        $before,
                        false,
                    );
                }

                $newVersion = $expectedVersion + 1;
                $connection->table('channel_membership_rules')->where('id', $ruleId)->update([
                    'state' => 'disabled',
                    'version' => $newVersion,
                    'updated_at' => $this->timestamp(),
                ]);
                $after = $before;
                $after['state'] = 'disabled';
                $after['version'] = $newVersion;

                return $this->audit->record(
                    $connection,
                    'telegram.membership_rule.disable',
                    self::TARGET_TYPE,
                    $ruleId,
                    $context,
                    $before,
                    $after,
                    true,
                );
            },
        );
    }

    /** @return array<string, bool|int|string|null> */
    public function findSafe(int $ruleId): array
    {
        if ($ruleId < 1) {
            throw new RuntimeException('Telegram membership-rule ID must be positive.');
        }
        $connection = $this->database->connection();
        $row = $connection->table('channel_membership_rules')->where('id', $ruleId)->first();
        if ($row === null) {
            throw new RuntimeException('Telegram membership rule does not exist.');
        }

        return $this->safeState($connection, $row, null, false);
    }

    /** @return list<array<string, bool|int|string|null>> */
    public function listSafe(): array
    {
        $connection = $this->database->connection();
        $rows = $connection->table('channel_membership_rules')
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get();
        $safe = [];
        foreach ($rows as $row) {
            $safe[] = $this->safeState($connection, $row, null, false);
        }

        return $safe;
    }

    /** @param list<int> $channelIds */
    private function replaceChannels(Connection $connection, int $ruleId, array $channelIds, string $createdAt): void
    {
        $connection->table('channel_membership_rule_channels')
            ->where('channel_membership_rule_id', $ruleId)
            ->delete();

        foreach ($channelIds as $sortOrder => $channelId) {
            $connection->table('channel_membership_rule_channels')->insert([
                'channel_membership_rule_id' => $ruleId,
                'required_channel_id' => $channelId,
                'sort_order' => $sortOrder,
                'created_at' => $createdAt,
            ]);
        }
    }

    private function validateDefinitionReferences(Connection $connection, TelegramChannelMembershipRuleDefinition $definition): void
    {
        if ($definition->tierCode !== null
            && ! $connection->table('customer_tiers')->where('code', $definition->tierCode)->where('is_active', true)->exists()) {
            throw new DomainException('Telegram membership-rule tier does not exist or is inactive.');
        }
        if ($definition->customerTagId !== null
            && ! $connection->table('customer_tags')->where('id', $definition->customerTagId)->where('is_active', true)->exists()) {
            throw new DomainException('Telegram membership-rule customer tag does not exist or is inactive.');
        }
        if ($definition->planOfferingId !== null
            && ! $connection->table('plan_offerings')->where('id', $definition->planOfferingId)->where('state', 'active')->exists()) {
            throw new DomainException('Telegram membership-rule Plan Offering does not exist or is inactive.');
        }
    }

    private function validateStoredReferences(Connection $connection, stdClass $row): void
    {
        if ($row->tier_code !== null
            && ! $connection->table('customer_tiers')->where('code', (string) $row->tier_code)->where('is_active', true)->exists()) {
            throw new DomainException('Telegram membership-rule tier is unavailable for activation.');
        }
        if ($row->customer_tag_id !== null
            && ! $connection->table('customer_tags')->where('id', (int) $row->customer_tag_id)->where('is_active', true)->exists()) {
            throw new DomainException('Telegram membership-rule customer tag is unavailable for activation.');
        }
        if ($row->plan_offering_id !== null
            && ! $connection->table('plan_offerings')->where('id', (int) $row->plan_offering_id)->where('state', 'active')->exists()) {
            throw new DomainException('Telegram membership-rule Plan Offering is unavailable for activation.');
        }
    }

    /** @param list<int> $channelIds */
    private function assertChannelsExist(Connection $connection, array $channelIds): void
    {
        if ($channelIds === []) {
            return;
        }
        $found = $connection->table('required_channels')->whereIn('id', $channelIds)->count();
        if ($found !== count($channelIds)) {
            throw new DomainException('One or more Telegram membership-rule channels do not exist.');
        }
    }

    /** @param list<int> $channelIds */
    private function assertChannelsActive(Connection $connection, array $channelIds): void
    {
        $rows = $connection->table('required_channels')
            ->whereIn('id', $channelIds)
            ->lockForUpdate()
            ->get(['id', 'state']);
        if ($rows->count() !== count($channelIds)) {
            throw new RuntimeException('Telegram membership-rule channel set changed during activation.');
        }
        foreach ($rows as $row) {
            if ((string) $row->state !== 'active') {
                throw new DomainException('Telegram membership rule requires active channels before activation.');
            }
        }
    }

    /** @return list<int> */
    private function channelIds(Connection $connection, int $ruleId, bool $lock): array
    {
        $query = $connection->table('channel_membership_rule_channels')
            ->where('channel_membership_rule_id', $ruleId)
            ->orderBy('sort_order')
            ->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $rows = $query->get(['required_channel_id', 'sort_order']);
        $ids = [];
        foreach ($rows as $index => $row) {
            if ((int) $row->sort_order !== $index) {
                throw new RuntimeException('Stored Telegram membership-rule channel ordering is invalid.');
            }
            $id = (int) $row->required_channel_id;
            if ($id < 1 || in_array($id, $ids, true)) {
                throw new RuntimeException('Stored Telegram membership-rule channel identity is invalid.');
            }
            $ids[] = $id;
        }

        return $ids;
    }

    private function assertPositiveIdentity(int $ruleId, int $expectedVersion): void
    {
        if ($ruleId < 1 || $expectedVersion < 1) {
            throw new RuntimeException('Telegram membership-rule ID and version must be positive.');
        }
    }

    private function assertVersion(stdClass $row, int $expectedVersion): void
    {
        if ((int) $row->version !== $expectedVersion) {
            throw new RuntimeException('Telegram membership-rule version conflict.');
        }
    }

    private function lockedRow(Connection $connection, int $ruleId): stdClass
    {
        $row = $connection->table('channel_membership_rules')->where('id', $ruleId)->lockForUpdate()->first();
        if ($row === null) {
            throw new RuntimeException('Telegram membership rule does not exist.');
        }

        return $row;
    }

    /** @return array<string, bool|int|string|null> */
    private function definitionSafePayload(TelegramChannelMembershipRuleDefinition $definition): array
    {
        $channelIdsJson = $this->channelIdsJson($definition->requiredChannelIds);

        return [
            'rule_key' => $definition->ruleKey,
            'action' => $definition->action,
            'audience' => $definition->audience,
            'tier_code' => $definition->tierCode,
            'customer_tag_id' => $definition->customerTagId,
            'plan_offering_id' => $definition->planOfferingId,
            'match_mode' => $definition->matchMode,
            'failure_policy' => $definition->failurePolicy,
            'priority' => $definition->priority,
            'effective_from' => $definition->effectiveFromUtc,
            'effective_until' => $definition->effectiveUntilUtc,
            'channel_ids_json' => $channelIdsJson,
            'channel_set_hash' => hash('sha256', $channelIdsJson),
            'channel_count' => count($definition->requiredChannelIds),
        ];
    }

    /** @return array<string, bool|int|string|null> */
    private function safeStateFromDefinition(
        int $id,
        TelegramChannelMembershipRuleDefinition $definition,
        string $state,
        int $version,
        ?string $payloadHash,
    ): array {
        return [
            'id' => $id,
            ...$this->definitionSafePayload($definition),
            'state' => $state,
            'version' => $version,
            'request_payload_hash' => $payloadHash,
        ];
    }

    /** @return array<string, bool|int|string|null> */
    private function safeState(Connection $connection, stdClass $row, ?string $payloadHash, bool $lockChannels): array
    {
        return $this->safeStateWithChannelIds(
            $row,
            $this->channelIds($connection, (int) $row->id, $lockChannels),
            $payloadHash,
        );
    }

    /**
     * @param  list<int>  $channelIds
     * @return array<string, bool|int|string|null>
     */
    private function safeStateWithChannelIds(stdClass $row, array $channelIds, ?string $payloadHash): array
    {
        $channelIdsJson = $this->channelIdsJson($channelIds);

        return [
            'id' => (int) $row->id,
            'rule_key' => (string) $row->rule_key,
            'action' => $row->action === null ? null : (string) $row->action,
            'audience' => (string) $row->audience,
            'tier_code' => $row->tier_code === null ? null : (string) $row->tier_code,
            'customer_tag_id' => $row->customer_tag_id === null ? null : (int) $row->customer_tag_id,
            'plan_offering_id' => $row->plan_offering_id === null ? null : (int) $row->plan_offering_id,
            'match_mode' => (string) $row->match_mode,
            'failure_policy' => (string) $row->failure_policy,
            'priority' => (int) $row->priority,
            'effective_from' => $row->effective_from === null ? null : (string) $row->effective_from,
            'effective_until' => $row->effective_until === null ? null : (string) $row->effective_until,
            'channel_ids_json' => $channelIdsJson,
            'channel_set_hash' => hash('sha256', $channelIdsJson),
            'channel_count' => count($channelIds),
            'state' => (string) $row->state,
            'version' => (int) $row->version,
            'request_payload_hash' => $payloadHash,
        ];
    }

    /** @param list<int> $channelIds */
    private function channelIdsJson(array $channelIds): string
    {
        return json_encode($channelIds, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, bool|int|string|null> $payload */
    private function payloadHash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
