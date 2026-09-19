<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use App\Modules\Telegram\Application\Contracts\TelegramDeliveryEffectGuard;
use App\Modules\Telegram\Application\Contracts\TelegramDeliveryRuntime;
use App\Modules\Telegram\Domain\TelegramBroadcastCampaignState;
use App\Modules\Telegram\Domain\TelegramDeliveryAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;

final readonly class TelegramBroadcastDeliveryEffectGuard implements TelegramDeliveryEffectGuard
{
    public const PAUSED_BEFORE_EFFECT = 'telegram_broadcast_paused_before_effect';

    public const CANCELLED_BEFORE_EFFECT = 'telegram_broadcast_cancelled_before_effect';

    public const AUTHORIZATION_LOST = 'telegram_broadcast_authorization_lost_before_effect';

    public const STALE_BEFORE_EFFECT = 'telegram_broadcast_stale_before_effect';

    public const INVALID_REFERENCE = 'telegram_broadcast_effect_reference_invalid';

    private const RECIPIENT_PREFIX = 'tgb:';

    private const OWNER_TEST_PREFIX = 'tgbt:';

    private const LIFECYCLE_PREFIX = 'tgbl:';

    public function __construct(
        private AdministratorPermissionAuthorizer $administrators,
        private TelegramDeliveryRuntime $runtime,
    ) {}

    /** @requirement COM-002 COM-003 ACL-002 SEC-002 SEC-008 QUA-001 QUA-004 */
    public function rejectionCode(
        Connection $connection,
        string $operationPublicId,
        string $correlationId,
        TelegramMutationRequest $request,
    ): ?string {
        if (str_starts_with($correlationId, self::RECIPIENT_PREFIX)) {
            return $this->recipientDecision(
                $connection,
                $this->reference($correlationId, self::RECIPIENT_PREFIX),
                $request,
            );
        }

        if (str_starts_with($correlationId, self::OWNER_TEST_PREFIX)) {
            return $this->ownerTestDecision(
                $connection,
                $this->reference($correlationId, self::OWNER_TEST_PREFIX),
                $request,
            );
        }

        if (str_starts_with($correlationId, self::LIFECYCLE_PREFIX)) {
            return $this->lifecycleDecision(
                $connection,
                $this->reference($correlationId, self::LIFECYCLE_PREFIX),
                $request,
            );
        }

        return null;
    }

    private function recipientDecision(
        Connection $connection,
        ?string $reference,
        TelegramMutationRequest $request,
    ): ?string {
        if ($reference === null) {
            return self::INVALID_REFERENCE;
        }

        /** @var object{
         *     action:string,
         *     state:string,
         *     requested_by_administrator_id:int|string|null,
         *     message_version:int|string|null,
         *     delivery_state:string,
         *     telegram_user_id:int|string,
         *     campaign_state:string,
         *     current_message_version:int|string,
         *     bot_id:string
         * }|null $row
         */
        $row = $connection->table('broadcast_recipient_messages as operation')
            ->join('broadcast_recipients as recipient', 'recipient.id', '=', 'operation.broadcast_recipient_id')
            ->join('broadcast_campaigns as campaign', 'campaign.id', '=', 'recipient.broadcast_campaign_id')
            ->leftJoin('broadcast_message_versions as message', 'message.id', '=', 'operation.broadcast_message_version_id')
            ->where('operation.public_id', $reference)
            ->first([
                'operation.action',
                'operation.state',
                'operation.requested_by_administrator_id',
                'message.version as message_version',
                'recipient.delivery_state',
                'recipient.telegram_user_id',
                'campaign.state as campaign_state',
                'campaign.current_message_version',
                'campaign.bot_id',
            ]);

        if ($row === null
            || ! in_array($row->action, ['send', 'retry'], true)
            || ! in_array($row->state, ['prepared', 'queued'], true)
            || (int) ($row->message_version ?? 0) !== (int) $row->current_message_version
            || (int) $row->telegram_user_id !== $request->recipientChatId
            || $request->action !== TelegramDeliveryAction::Send
            || $request->targetMessageId !== null
            || ! hash_equals($this->runtime->botId(), $row->bot_id)
        ) {
            return self::STALE_BEFORE_EFFECT;
        }

        if ($row->campaign_state === TelegramBroadcastCampaignState::Paused->value) {
            return self::PAUSED_BEFORE_EFFECT;
        }
        if ($row->campaign_state === TelegramBroadcastCampaignState::Cancelled->value) {
            return self::CANCELLED_BEFORE_EFFECT;
        }
        if ($row->campaign_state !== TelegramBroadcastCampaignState::Active->value
            || $row->delivery_state !== 'sending'
        ) {
            return self::STALE_BEFORE_EFFECT;
        }

        $administratorId = $this->positiveId($row->requested_by_administrator_id);
        if ($administratorId === null || ! $this->authorized($administratorId)) {
            return self::AUTHORIZATION_LOST;
        }

        return null;
    }

    private function ownerTestDecision(
        Connection $connection,
        ?string $reference,
        TelegramMutationRequest $request,
    ): ?string {
        if ($reference === null) {
            return self::INVALID_REFERENCE;
        }

        /** @var object{
         *     state:string,
         *     message_version:int|string,
         *     owner_administrator_id:int|string,
         *     creator_administrator_id:int|string,
         *     owner_status:string,
         *     owner_is_owner:int|bool,
         *     telegram_user_id:int|string,
         *     account_bot_id:string,
         *     campaign_state:string,
         *     current_message_version:int|string,
         *     campaign_bot_id:string
         * }|null $row
         */
        $row = $connection->table('broadcast_campaign_tests as test')
            ->join('broadcast_campaigns as campaign', 'campaign.id', '=', 'test.broadcast_campaign_id')
            ->join('broadcast_message_versions as message', 'message.id', '=', 'test.broadcast_message_version_id')
            ->join('administrators as owner', 'owner.id', '=', 'test.owner_administrator_id')
            ->join('telegram_accounts as account', 'account.id', '=', 'test.telegram_account_id')
            ->where('test.public_id', $reference)
            ->first([
                'test.state',
                'message.version as message_version',
                'test.owner_administrator_id',
                'campaign.actor_administrator_id as creator_administrator_id',
                'owner.status as owner_status',
                'owner.is_owner as owner_is_owner',
                'account.telegram_user_id',
                'account.bot_id as account_bot_id',
                'campaign.state as campaign_state',
                'campaign.current_message_version',
                'campaign.bot_id as campaign_bot_id',
            ]);

        if ($row === null
            || ! in_array($row->state, ['prepared', 'queued'], true)
            || (int) $row->message_version !== (int) $row->current_message_version
            || ! in_array($row->campaign_state, [
                TelegramBroadcastCampaignState::Draft->value,
                TelegramBroadcastCampaignState::Completed->value,
            ], true)
            || (int) $row->telegram_user_id !== $request->recipientChatId
            || $request->action !== TelegramDeliveryAction::Send
            || $request->targetMessageId !== null
            || ! hash_equals($this->runtime->botId(), $row->campaign_bot_id)
            || ! hash_equals($row->campaign_bot_id, $row->account_bot_id)
            || $row->owner_status !== 'active'
            || ! (bool) $row->owner_is_owner
        ) {
            return self::STALE_BEFORE_EFFECT;
        }

        if (! $this->authorized((int) $row->owner_administrator_id)
            || ! $this->authorized((int) $row->creator_administrator_id)
        ) {
            return self::AUTHORIZATION_LOST;
        }

        return null;
    }

    private function lifecycleDecision(
        Connection $connection,
        ?string $reference,
        TelegramMutationRequest $request,
    ): ?string {
        if ($reference === null) {
            return self::INVALID_REFERENCE;
        }

        /** @var object{
         *     action:string,
         *     state:string,
         *     requested_by_administrator_id:int|string|null,
         *     message_version:int|string|null,
         *     delivery_state:string,
         *     lifecycle_state:string,
         *     telegram_user_id:int|string,
         *     telegram_message_id:int|string|null,
         *     campaign_state:string,
         *     current_message_version:int|string,
         *     campaign_id:int|string,
         *     bot_id:string
         * }|null $row
         */
        $row = $connection->table('broadcast_recipient_messages as operation')
            ->join('broadcast_recipients as recipient', 'recipient.id', '=', 'operation.broadcast_recipient_id')
            ->join('broadcast_campaigns as campaign', 'campaign.id', '=', 'recipient.broadcast_campaign_id')
            ->leftJoin('broadcast_message_versions as message', 'message.id', '=', 'operation.broadcast_message_version_id')
            ->where('operation.public_id', $reference)
            ->first([
                'operation.action',
                'operation.state',
                'operation.requested_by_administrator_id',
                'message.version as message_version',
                'recipient.delivery_state',
                'recipient.lifecycle_state',
                'recipient.telegram_user_id',
                'recipient.telegram_message_id',
                'campaign.state as campaign_state',
                'campaign.current_message_version',
                'campaign.id as campaign_id',
                'campaign.bot_id',
            ]);

        if ($row === null
            || ! in_array($row->action, ['edit', 'buttons', 'delete'], true)
            || ! in_array($row->state, ['prepared', 'queued'], true)
            || (int) ($row->message_version ?? 0) !== (int) $row->current_message_version
            || $row->campaign_state !== TelegramBroadcastCampaignState::Completed->value
            || $row->delivery_state !== 'sent'
            || $row->lifecycle_state === 'deleted'
            || (int) $row->telegram_user_id !== $request->recipientChatId
            || (int) ($row->telegram_message_id ?? 0) !== (int) ($request->targetMessageId ?? 0)
            || ! hash_equals($this->runtime->botId(), $row->bot_id)
        ) {
            return self::STALE_BEFORE_EFFECT;
        }

        $requestMatches = match ($row->action) {
            'edit', 'buttons' => $request->action === TelegramDeliveryAction::Edit,
            'delete' => $request->action === TelegramDeliveryAction::Delete,
            default => false,
        };
        if (! $requestMatches) {
            return self::STALE_BEFORE_EFFECT;
        }

        $administratorId = $this->positiveId($row->requested_by_administrator_id);
        if ($administratorId === null || ! $this->authorized($administratorId)) {
            return self::AUTHORIZATION_LOST;
        }

        $ownerTestCurrent = $connection->table('broadcast_campaign_tests as test')
            ->join('broadcast_message_versions as tested_message', 'tested_message.id', '=', 'test.broadcast_message_version_id')
            ->join('administrators as owner', 'owner.id', '=', 'test.owner_administrator_id')
            ->where('test.broadcast_campaign_id', (int) $row->campaign_id)
            ->where('tested_message.version', (int) $row->current_message_version)
            ->where('test.state', 'succeeded')
            ->where('owner.status', 'active')
            ->where('owner.is_owner', true)
            ->exists();
        if (! $ownerTestCurrent) {
            return self::STALE_BEFORE_EFFECT;
        }

        return null;
    }

    private function authorized(int $administratorId): bool
    {
        try {
            $this->administrators->authorize(
                $administratorId,
                TelegramBroadcastCampaignService::PERMISSION,
            );

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }

    private function reference(string $correlationId, string $prefix): ?string
    {
        $reference = substr($correlationId, strlen($prefix));

        return preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $reference) === 1
            ? $reference
            : null;
    }

    private function positiveId(int|string|null $value): ?int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $validated === false ? null : $validated;
    }
}
