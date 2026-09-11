<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Shared\Application\Clock;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use RuntimeException;
use stdClass;

/**
 * @phpstan-type MembershipRuleRow object{id:int|string,rule_key:string,action:?string,audience:string,tier_code:?string,customer_tag_id:int|string|null,plan_offering_id:int|string|null,match_mode:string,failure_policy:string,priority:int|string,effective_from:?string,effective_until:?string,state:string,version:int|string}
 * @phpstan-type MembershipChannelRow object{required_channel_id:int|string,sort_order:int|string,channel_key:string,telegram_chat_id:int|string,chat_type:string,visibility:string,display_title:string,state:string,version:int|string}
 */
final readonly class TelegramChannelMembershipRuleResolver
{
    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
    ) {}

    /** @requirement ONB-003 CHN-001 SEC-001 DAT-003 QUA-001 */
    public function resolve(TelegramChannelMembershipResolutionRequest $request): TelegramChannelMembershipRequirementPlan
    {
        $connection = $this->database->connection();

        return $connection->transaction(function () use ($connection, $request): TelegramChannelMembershipRequirementPlan {
            $subject = $this->subjectContext($connection, $request->userId);
            $this->assertCurrentPlanOffering($connection, $request->planOfferingId);
            $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
            $rules = $this->candidateRules($connection, $request, $subject['account_type'], $now);

            $applicable = [];
            foreach ($rules as $rule) {
                if ($this->matchesSubjectSelectors($rule, $subject, $request->planOfferingId)) {
                    $applicable[] = $rule;
                }
            }

            if ($applicable === []) {
                return new TelegramChannelMembershipRequirementPlan(
                    $request->userId,
                    $subject['account_type'],
                    $request->action,
                    $request->planOfferingId,
                    false,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    [],
                    $this->hashPayload([
                        'schema' => 1,
                        'required' => false,
                        'action' => $request->action,
                        'plan_offering_id' => $request->planOfferingId,
                    ]),
                );
            }

            $highestPriority = (int) $applicable[0]->priority;
            $highest = array_values(array_filter(
                $applicable,
                static fn (stdClass $rule): bool => (int) $rule->priority === $highestPriority,
            ));
            if (count($highest) !== 1) {
                throw new DomainException('Telegram membership-rule configuration is ambiguous at the highest priority.');
            }

            $selected = $highest[0];
            $channels = $this->channels($connection, (int) $selected->id);
            $configurationHash = $this->configurationHash($selected, $channels);

            return new TelegramChannelMembershipRequirementPlan(
                $request->userId,
                $subject['account_type'],
                $request->action,
                $request->planOfferingId,
                true,
                (int) $selected->id,
                (string) $selected->rule_key,
                (int) $selected->version,
                (string) $selected->match_mode,
                (string) $selected->failure_policy,
                (int) $selected->priority,
                $selected->effective_from === null ? null : (string) $selected->effective_from,
                $selected->effective_until === null ? null : (string) $selected->effective_until,
                $channels,
                $configurationHash,
            );
        }, 1);
    }

    /**
     * @return array{account_type:'customer'|'agent',tier_code:?string,active_tag_ids:list<int>}
     */
    private function subjectContext(Connection $connection, int $userId): array
    {
        /** @var object{account_type:string,account_status:string}|null $user */
        $user = $connection->table('users')->where('id', $userId)->first(['account_type', 'account_status']);
        if ($user === null || $user->account_status !== 'active' || ! in_array($user->account_type, ['customer', 'agent'], true)) {
            throw new DomainException('Telegram membership resolution requires an active customer or agent.');
        }

        if ($user->account_type === 'agent') {
            return [
                'account_type' => 'agent',
                'tier_code' => null,
                'active_tag_ids' => [],
            ];
        }

        /** @var object{current_tier_id:int|string|null}|null $profile */
        $profile = $connection->table('customer_profiles')->where('user_id', $userId)->first(['current_tier_id']);
        if ($profile === null) {
            throw new DomainException('Telegram membership resolution customer profile is unavailable.');
        }

        $tierCode = null;
        if ($profile->current_tier_id !== null) {
            $tier = $connection->table('customer_tiers')
                ->where('id', (int) $profile->current_tier_id)
                ->where('is_active', true)
                ->value('code');
            $tierCode = is_string($tier) ? $tier : null;
        }

        /** @var list<int|string> $tagIds */
        $tagIds = $connection->table('customer_tag_assignments as assignment')
            ->join('customer_tags as tag', 'tag.id', '=', 'assignment.tag_id')
            ->where('assignment.user_id', $userId)
            ->whereNull('assignment.removed_at')
            ->where('tag.is_active', true)
            ->orderBy('assignment.tag_id')
            ->pluck('assignment.tag_id')
            ->all();

        return [
            'account_type' => 'customer',
            'tier_code' => $tierCode,
            'active_tag_ids' => array_map(static fn (int|string $id): int => (int) $id, $tagIds),
        ];
    }

    private function assertCurrentPlanOffering(Connection $connection, ?int $planOfferingId): void
    {
        if ($planOfferingId === null) {
            return;
        }

        if (! $connection->table('plan_offerings')
            ->where('id', $planOfferingId)
            ->where('state', 'active')
            ->exists()
        ) {
            throw new DomainException('Telegram membership resolution Plan Offering is unavailable.');
        }
    }

    /**
     * @param  'customer'|'agent'  $accountType
     * @return list<stdClass>
     */
    private function candidateRules(
        Connection $connection,
        TelegramChannelMembershipResolutionRequest $request,
        string $accountType,
        string $now,
    ): array {
        $audience = $accountType === 'customer' ? 'customers' : 'agents';
        $rows = $connection->table('channel_membership_rules')
            ->where('state', 'active')
            ->where(static function ($query) use ($request): void {
                $query->whereNull('action')->orWhere('action', $request->action);
            })
            ->where(static function ($query) use ($audience): void {
                $query->where('audience', 'both')->orWhere('audience', $audience);
            })
            ->where(static function ($query) use ($now): void {
                $query->whereNull('effective_from')->orWhere('effective_from', '<=', $now);
            })
            ->where(static function ($query) use ($now): void {
                $query->whereNull('effective_until')->orWhere('effective_until', '>', $now);
            })
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get([
                'id', 'rule_key', 'action', 'audience', 'tier_code', 'customer_tag_id', 'plan_offering_id',
                'match_mode', 'failure_policy', 'priority', 'effective_from', 'effective_until', 'state', 'version',
            ])
            ->all();

        /** @var list<stdClass> $rows */
        return $rows;
    }

    /**
     * @param  array{account_type:'customer'|'agent',tier_code:?string,active_tag_ids:list<int>}  $subject
     */
    private function matchesSubjectSelectors(stdClass $rule, array $subject, ?int $planOfferingId): bool
    {
        if ($rule->tier_code !== null
            && ($subject['tier_code'] === null || ! hash_equals((string) $rule->tier_code, $subject['tier_code']))) {
            return false;
        }
        if ($rule->customer_tag_id !== null && ! in_array((int) $rule->customer_tag_id, $subject['active_tag_ids'], true)) {
            return false;
        }
        if ($rule->plan_offering_id !== null
            && ($planOfferingId === null || (int) $rule->plan_offering_id !== $planOfferingId)) {
            return false;
        }

        return true;
    }

    /** @return list<TelegramChannelMembershipRequirementChannel> */
    private function channels(Connection $connection, int $ruleId): array
    {
        $rows = $connection->table('channel_membership_rule_channels as association')
            ->join('required_channels as channel', 'channel.id', '=', 'association.required_channel_id')
            ->where('association.channel_membership_rule_id', $ruleId)
            ->orderBy('association.sort_order')
            ->orderBy('association.id')
            ->get([
                'association.required_channel_id', 'association.sort_order', 'channel.channel_key',
                'channel.telegram_chat_id', 'channel.chat_type', 'channel.visibility', 'channel.display_title',
                'channel.state', 'channel.version',
            ])
            ->all();

        if ($rows === []) {
            throw new RuntimeException('Active Telegram membership rule has no configured channels.');
        }

        $channels = [];
        foreach ($rows as $index => $row) {
            /** @var MembershipChannelRow $row */
            if ((int) $row->sort_order !== $index) {
                throw new RuntimeException('Active Telegram membership-rule channel ordering is invalid.');
            }
            $channels[] = new TelegramChannelMembershipRequirementChannel(
                (int) $row->required_channel_id,
                (string) $row->channel_key,
                (int) $row->telegram_chat_id,
                (string) $row->chat_type,
                (string) $row->visibility,
                (string) $row->display_title,
                (string) $row->state,
                (int) $row->version,
            );
        }

        return $channels;
    }

    /**
     * @param  list<TelegramChannelMembershipRequirementChannel>  $channels
     */
    private function configurationHash(stdClass $rule, array $channels): string
    {
        return $this->hashPayload([
            'schema' => 1,
            'required' => true,
            'rule' => [
                'id' => (int) $rule->id,
                'key' => (string) $rule->rule_key,
                'version' => (int) $rule->version,
                'action' => $rule->action === null ? null : (string) $rule->action,
                'audience' => (string) $rule->audience,
                'tier_code' => $rule->tier_code === null ? null : (string) $rule->tier_code,
                'customer_tag_id' => $rule->customer_tag_id === null ? null : (int) $rule->customer_tag_id,
                'plan_offering_id' => $rule->plan_offering_id === null ? null : (int) $rule->plan_offering_id,
                'match_mode' => (string) $rule->match_mode,
                'failure_policy' => (string) $rule->failure_policy,
                'priority' => (int) $rule->priority,
                'effective_from' => $rule->effective_from === null ? null : (string) $rule->effective_from,
                'effective_until' => $rule->effective_until === null ? null : (string) $rule->effective_until,
                'state' => (string) $rule->state,
            ],
            'channels' => array_map(
                static fn (TelegramChannelMembershipRequirementChannel $channel): array => $channel->hashPayload(),
                $channels,
            ),
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function hashPayload(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
