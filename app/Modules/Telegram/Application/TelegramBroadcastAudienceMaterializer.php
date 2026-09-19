<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Application\Contracts\TelegramMembershipLookup;
use App\Modules\Wallet\Application\WalletSelfBalanceService;
use App\Shared\Application\Clock;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * @phpstan-type CampaignAudienceRow object{
 *     campaign_id:int|string,
 *     bot_id:int|string,
 *     state:string,
 *     state_version:int|string,
 *     current_audience_version:int|string,
 *     audience_materialized_at:?string,
 *     recipient_count:int|string,
 *     audience_id:int|string,
 *     audience_version:int|string,
 *     filter_snapshot:string,
 *     estimated_recipient_count:int|string
 * }
 */
final readonly class TelegramBroadcastAudienceMaterializer
{
    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private WalletSelfBalanceService $wallets,
        private TelegramMembershipLookup $membership,
    ) {}

    /** @requirement COM-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 */
    public function estimate(string $campaignPublicId, int $expectedAudienceVersion): int
    {
        $source = $this->campaignAudience($campaignPublicId, $expectedAudienceVersion);
        if ($source->audience_materialized_at !== null) {
            return $this->nonNegativeInt($source->recipient_count, 'Broadcast recipient count');
        }

        $definition = TelegramBroadcastAudienceDefinition::restore((string) $source->filter_snapshot);
        $count = count($this->eligibleRecipients((int) $source->bot_id, $definition));

        $this->database->connection()->transaction(function (Connection $connection) use (
            $source,
            $expectedAudienceVersion,
            $count,
        ): void {
            /** @var object{state:string,current_audience_version:int|string,audience_materialized_at:?string} $campaign */
            $campaign = $connection->table('broadcast_campaigns')
                ->where('id', (int) $source->campaign_id)
                ->lockForUpdate()
                ->first(['state', 'current_audience_version', 'audience_materialized_at']);
            if ($campaign === null
                || (string) $campaign->state !== 'draft'
                || (int) $campaign->current_audience_version !== $expectedAudienceVersion
                || $campaign->audience_materialized_at !== null
            ) {
                throw new DomainException('Broadcast audience changed while its estimate was being calculated.');
            }

            $updated = $connection->table('broadcast_audiences')
                ->where('id', (int) $source->audience_id)
                ->where('version', $expectedAudienceVersion)
                ->update(['estimated_recipient_count' => $count]);
            if ($updated !== 1 && (int) $source->estimated_recipient_count !== $count) {
                throw new RuntimeException('Broadcast audience estimate transition failed.');
            }
        });

        return $count;
    }

    /** @requirement COM-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 */
    public function materialize(
        string $campaignPublicId,
        int $expectedAudienceVersion,
        int $expectedStateVersion,
    ): int {
        if ($expectedStateVersion < 1) {
            throw new DomainException('Broadcast campaign state version is invalid.');
        }
        $source = $this->campaignAudience($campaignPublicId, $expectedAudienceVersion);
        if ((int) $source->state_version !== $expectedStateVersion) {
            throw new DomainException('Broadcast campaign changed before audience materialization.');
        }
        if ($source->audience_materialized_at !== null) {
            return $this->nonNegativeInt($source->recipient_count, 'Broadcast recipient count');
        }

        $definition = TelegramBroadcastAudienceDefinition::restore((string) $source->filter_snapshot);
        $recipients = $this->eligibleRecipients((int) $source->bot_id, $definition);
        $now = $this->timestamp();

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $source,
            $expectedAudienceVersion,
            $recipients,
            $now,
            $expectedStateVersion,
        ): int {
            /** @var object{state:string,current_audience_version:int|string,state_version:int|string,audience_materialized_at:?string,recipient_count:int|string} $campaign */
            $campaign = $connection->table('broadcast_campaigns')
                ->where('id', (int) $source->campaign_id)
                ->lockForUpdate()
                ->first([
                    'state',
                    'current_audience_version',
                    'state_version',
                    'audience_materialized_at',
                    'recipient_count',
                ]);
            if ($campaign === null) {
                throw new RuntimeException('Broadcast campaign disappeared during audience materialization.');
            }
            if ($campaign->audience_materialized_at !== null) {
                return $this->nonNegativeInt($campaign->recipient_count, 'Broadcast recipient count');
            }
            if ((string) $campaign->state !== 'draft'
                || (int) $campaign->current_audience_version !== $expectedAudienceVersion
                || (int) $campaign->state_version !== $expectedStateVersion
            ) {
                throw new DomainException('Broadcast audience changed before materialization completed.');
            }

            foreach (array_chunk($recipients, 500) as $chunk) {
                $rows = [];
                foreach ($chunk as $recipient) {
                    $rows[] = [
                        'public_id' => (string) Str::ulid(),
                        'broadcast_campaign_id' => (int) $source->campaign_id,
                        'user_id' => $recipient['user_id'],
                        'telegram_account_id' => $recipient['telegram_account_id'],
                        'telegram_user_id' => $recipient['telegram_user_id'],
                        'delivery_state' => 'queued',
                        'attempt_count' => 0,
                        'claim_token_hash' => null,
                        'claim_expires_at' => null,
                        'delivery_operation_public_id' => null,
                        'telegram_message_id' => null,
                        'failure_code' => null,
                        'sent_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                $connection->table('broadcast_recipients')->insert($rows);
            }

            $count = count($recipients);
            $updated = $connection->table('broadcast_campaigns')
                ->where('id', (int) $source->campaign_id)
                ->where('state', 'draft')
                ->whereNull('audience_materialized_at')
                ->where('current_audience_version', $expectedAudienceVersion)
                ->where('state_version', $expectedStateVersion)
                ->update([
                    'recipient_count' => $count,
                    'audience_materialized_at' => $now,
                    'updated_at' => $now,
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('Broadcast audience materialization transition failed.');
            }

            $connection->table('broadcast_audiences')
                ->where('id', (int) $source->audience_id)
                ->update(['estimated_recipient_count' => $count]);

            return $count;
        });
    }

    /** @return CampaignAudienceRow */
    private function campaignAudience(string $campaignPublicId, int $expectedAudienceVersion): object
    {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $campaignPublicId) !== 1 || $expectedAudienceVersion < 1) {
            throw new DomainException('Broadcast campaign audience reference is invalid.');
        }

        /** @var CampaignAudienceRow|null $row */
$row = $this->database->connection()->table('broadcast_campaigns as campaign')
            ->join('broadcast_audiences as audience', function ($join): void {
                $join->on('audience.broadcast_campaign_id', '=', 'campaign.id')
                    ->on('audience.version', '=', 'campaign.current_audience_version');
            })
            ->where('campaign.public_id', $campaignPublicId)
            ->first([
                'campaign.id as campaign_id',
                'campaign.bot_id',
                'campaign.state',
                'campaign.current_audience_version',
                'campaign.state_version',
                'campaign.audience_materialized_at',
                'campaign.recipient_count',
                'audience.id as audience_id',
                'audience.version as audience_version',
                'audience.filter_snapshot',
                'audience.estimated_recipient_count',
            ]);

        if ($row === null
            || (int) $row->current_audience_version !== $expectedAudienceVersion
            || (int) $row->audience_version !== $expectedAudienceVersion
        ) {
            throw new DomainException('Broadcast campaign audience is unavailable or stale.');
        }
        if ($row->audience_materialized_at === null && (string) $row->state !== 'draft') {
            throw new DomainException('Only a draft broadcast audience may be materialized.');
        }

        return $row;
    }

    /**
     * @return list<array{user_id:int,telegram_account_id:int,telegram_user_id:int}>
     */
    private function eligibleRecipients(int $botId, TelegramBroadcastAudienceDefinition $definition): array
    {
        if ($botId < 1) {
            throw new RuntimeException('Broadcast bot ID is invalid.');
        }

        $connection = $this->database->connection();
        $query = $connection->table('users as user')
            ->join('telegram_accounts as telegram', function ($join) use ($botId): void {
                $join->on('telegram.user_id', '=', 'user.id')
                    ->where('telegram.bot_id', '=', $botId)
                    ->where('telegram.is_bot', '=', false);
            })
            ->where('user.account_status', 'active')
            ->orderBy('user.id');

        if ($definition->accountTypes !== []) {
            $query->whereIn('user.account_type', $definition->accountTypes);
        }
        if ($definition->manualUserPublicIds !== []) {
            $query->whereIn('user.public_id', $definition->manualUserPublicIds);
        }
        if ($definition->tierCodes !== []) {
            $query->whereExists(function (Builder $tier) use ($definition): void {
                $tier->selectRaw('1')
                    ->from('customer_profiles as profile')
                    ->join('customer_tiers as tier', 'tier.id', '=', 'profile.current_tier_id')
                    ->whereColumn('profile.user_id', 'user.id')
                    ->where('tier.is_active', true)
                    ->whereIn('tier.code', $definition->tierCodes);
            });
        }
        if ($definition->tagCodes !== []) {
            $query->whereExists(function (Builder $tag) use ($definition): void {
                $tag->selectRaw('1')
                    ->from('customer_tag_assignments as assignment')
                    ->join('customer_tags as tag', 'tag.id', '=', 'assignment.tag_id')
                    ->whereColumn('assignment.user_id', 'user.id')
                    ->whereNull('assignment.removed_at')
                    ->where('tag.is_active', true)
                    ->whereIn('tag.code', $definition->tagCodes);
            });
        }

        if ($definition->purchaseState !== 'any') {
            $method = $definition->purchaseState === 'with_successful' ? 'whereExists' : 'whereNotExists';
            $query->{$method}(function (Builder $orders): void {
                $orders->selectRaw('1')
                    ->from('orders as purchase')
                    ->whereColumn('purchase.user_id', 'user.id')
                    ->whereNotNull('purchase.paid_at');
            });
        }

        $this->applyOwnedServiceFilters($query, $definition);

        /** @var iterable<int, object{id:int|string,telegram_account_id:int|string,telegram_user_id:int|string}> $rows */
        $rows = $query->get([
            'user.id',
            'telegram.id as telegram_account_id',
            'telegram.telegram_user_id',
        ]);

        $eligible = [];
        foreach ($rows as $row) {
            $userId = $this->positiveInt($row->id ?? null, 'Broadcast audience user ID');
            $telegramAccountId = $this->positiveInt($row->telegram_account_id ?? null, 'Broadcast Telegram account ID');
            $telegramUserId = $this->positiveInt($row->telegram_user_id ?? null, 'Broadcast Telegram user ID');

            if (! $this->matchesWalletRange($userId, $definition)) {
                continue;
            }
            if (! $this->matchesChannelMembership($telegramUserId, $definition)) {
                continue;
            }

            $eligible[] = [
                'user_id' => $userId,
                'telegram_account_id' => $telegramAccountId,
                'telegram_user_id' => $telegramUserId,
            ];
        }

        return $eligible;
    }

    private function applyOwnedServiceFilters(Builder $query, TelegramBroadcastAudienceDefinition $definition): void
    {
        if ($definition->offeringCodes === []
            && $definition->categoryCodes === []
            && $definition->serverCodes === []
            && $definition->serviceState === 'any'
        ) {
            return;
        }

        $now = $this->timestamp();
        $query->whereExists(function (Builder $service) use ($definition, $now): void {
            $service->selectRaw('1')
                ->from('service_subscriptions as service')
                ->join('order_items as item', 'item.id', '=', 'service.order_item_id')
                ->join('plan_offerings as offering', 'offering.id', '=', 'item.plan_offering_id')
                ->join('products as product', 'product.id', '=', 'offering.product_id')
                ->join('product_categories as category', 'category.id', '=', 'product.category_id')
                ->join('sales_servers as server', 'server.id', '=', 'offering.sales_server_id')
                ->whereColumn('service.user_id', 'user.id');

            if ($definition->offeringCodes !== []) {
                $service->whereIn('item.offering_code_snapshot', $definition->offeringCodes);
            }
            if ($definition->categoryCodes !== []) {
                $service->whereIn('category.code', $definition->categoryCodes);
            }
            if ($definition->serverCodes !== []) {
                $service->whereIn('server.code', $definition->serverCodes);
            }

            if ($definition->serviceState === 'any') {
                return;
            }

            if ($definition->serviceState === 'active') {
                $service->where('service.lifecycle_state', 'active')
                    ->whereNull('service.remote_deleted_at');
            }

            $service->whereExists(function (Builder $snapshot) use ($definition, $now): void {
                $snapshot->selectRaw('1')
                    ->from('service_sync_snapshots as snapshot')
                    ->whereColumn('snapshot.service_subscription_id', 'service.id')
                    ->whereColumn('snapshot.local_lifecycle_state', 'service.lifecycle_state')
                    ->whereColumn('snapshot.local_lifecycle_version', 'service.lifecycle_version')
                    ->whereColumn('snapshot.local_remote_identity_generation', 'service.remote_identity_generation')
                    ->whereColumn('snapshot.local_mutation_generation', 'service.mutation_generation')
                    ->where('snapshot.remote_disposition', 'present')
                    ->whereRaw(<<<'SQL'
snapshot.id = (
    SELECT latest.id
    FROM service_sync_snapshots AS latest
    WHERE latest.service_subscription_id = service.id
      AND latest.local_lifecycle_state = service.lifecycle_state
      AND latest.local_lifecycle_version = service.lifecycle_version
      AND latest.local_remote_identity_generation = service.remote_identity_generation
      AND latest.local_mutation_generation = service.mutation_generation
    ORDER BY latest.observed_at DESC, latest.id DESC
    LIMIT 1
)
SQL);

                if ($definition->serviceState === 'active') {
                    $snapshot->where('snapshot.remote_status', 'active')
                        ->where(function (Builder $expiry) use ($now): void {
                            $expiry->whereNull('snapshot.remote_expires_at')
                                ->orWhere('snapshot.remote_expires_at', '>', $now);
                        });
                } else {
                    $snapshot->where(function (Builder $expired) use ($now): void {
                        $expired->where('snapshot.remote_status', 'expired')
                            ->orWhere(function (Builder $byTime) use ($now): void {
                                $byTime->whereNotNull('snapshot.remote_expires_at')
                                    ->where('snapshot.remote_expires_at', '<=', $now);
                            });
                    });
                }
            });
        });
    }

    private function matchesWalletRange(int $userId, TelegramBroadcastAudienceDefinition $definition): bool
    {
        if ($definition->walletMinimumIrr === null && $definition->walletMaximumIrr === null) {
            return true;
        }

        $balance = $this->wallets->forSelf($userId, $userId);
        $available = $balance->cashAvailableBalanceIrr + $balance->promotionalAvailableBalanceIrr;

        return ($definition->walletMinimumIrr === null || $available >= $definition->walletMinimumIrr)
            && ($definition->walletMaximumIrr === null || $available <= $definition->walletMaximumIrr);
    }

    private function matchesChannelMembership(
        int $telegramUserId,
        TelegramBroadcastAudienceDefinition $definition,
    ): bool {
        if ($definition->channelChatIds === []) {
            return true;
        }

        $matches = 0;
        foreach ($definition->channelChatIds as $chatId) {
            $evidence = $this->membership->lookup($chatId, $telegramUserId)->evidence;
            $isMatch = match ($definition->channelMembershipState) {
                'member' => $evidence === TelegramMembershipEvidence::Member,
                'not_member' => $evidence === TelegramMembershipEvidence::NotMember,
                default => false,
            };
            if ($isMatch) {
                $matches++;
            }
        }

        return $definition->channelMembershipMode === 'all'
            ? $matches === count($definition->channelChatIds)
            : $matches > 0;
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }

    private function positiveInt(mixed $value, string $label): int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($validated === false) {
            throw new RuntimeException($label.' must be a positive integer.');
        }

        return $validated;
    }

    private function nonNegativeInt(mixed $value, string $label): int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($validated === false) {
            throw new RuntimeException($label.' must be a non-negative integer.');
        }

        return $validated;
    }
}
