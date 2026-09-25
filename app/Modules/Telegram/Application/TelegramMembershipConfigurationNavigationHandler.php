<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\AccessControl\Application\AdministratorUserPermissionAuthorizer;
use App\Modules\Customers\Application\CustomerAccountSummaryService;
use App\Modules\Localization\Application\LocalizationResolver;
use App\Modules\Telegram\Domain\TelegramInteractionActionKind;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use RuntimeException;

final readonly class TelegramMembershipConfigurationNavigationHandler
{
    public const ACTION_ENTRY = 'navigation.admin.membership';

    private const STATE_LIST = 'admin_membership_configuration';

    private const STATE_EDIT = 'admin_membership_configuration_edit';

    private const ACTION_EDIT = 'navigation.admin.membership.edit';

    private const ACTION_REFRESH = 'navigation.admin.membership.refresh';

    private const ACTION_BACK = 'navigation.back';

    private const LIST_LIMIT = 12;

    public function __construct(
        private LocalizationResolver $localization,
        private ConfidentialTelegramPresentationFactory $presentations,
        private TelegramConfidentialDeliveryQueue $delivery,
        private TelegramInteractionSessionService $sessions,
        private TelegramInteractionCallbackService $callbacks,
        private TelegramRequiredChannelService $channels,
        private TelegramChannelMembershipRuleService $rules,
        private TelegramMembershipConfigurationAuthoringParser $parser,
        private AdministratorUserPermissionAuthorizer $administrators,
        private CustomerAccountSummaryService $customers,
        private TelegramNavigationHandler $navigation,
    ) {}

    public function supports(TelegramInteractionAction $action): bool
    {
        return ($action->sessionState === 'admin_control'
                && $action->kind === TelegramInteractionActionKind::Callback
                && $action->callbackAction === self::ACTION_ENTRY)
            || in_array($action->sessionState, [self::STATE_LIST, self::STATE_EDIT], true);
    }

    /** @requirement CHN-001 ACL-002 DAT-003 SEC-001 SEC-002 QUA-001 QUA-004 */
    public function handle(TelegramInteractionAction $action): void
    {
        if ($action->sessionState === 'admin_control') {
            if ($action->callbackPayload !== []) {
                throw new RuntimeException('Telegram membership administrator entry payload is invalid.');
            }
            $this->showList($action);

            return;
        }

        if ($action->sessionState === self::STATE_LIST) {
            $this->handleList($action);

            return;
        }
        if ($action->sessionState === self::STATE_EDIT) {
            $this->handleEdit($action);

            return;
        }

        throw new RuntimeException('Telegram membership administrator state is unsupported.');
    }

    private function handleList(TelegramInteractionAction $action): void
    {
        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction === self::ACTION_EDIT && $action->callbackPayload === []) {
                $this->showEditor($action);

                return;
            }
            if ($action->callbackAction === self::ACTION_REFRESH && $action->callbackPayload === []) {
                $this->showList($action);

                return;
            }
            if ($action->callbackAction === self::ACTION_BACK && $action->callbackPayload === []) {
                $this->returnAdminControl($action);

                return;
            }

            throw new RuntimeException('Telegram membership administrator list callback is unsupported.');
        }

        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->returnAdminControl($action);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);
        }
    }

    private function handleEdit(TelegramInteractionAction $action): void
    {
        if ($action->kind === TelegramInteractionActionKind::Callback
            && $action->callbackAction === self::ACTION_BACK
            && $action->callbackPayload === []) {
            $this->showList($action);

            return;
        }
        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->showList($action);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);

            return;
        }
        if ($action->kind !== TelegramInteractionActionKind::Message || $action->messageText === null) {
            return;
        }

        try {
            $administratorId = $this->assertPermission($action);
            $command = $this->parser->parse($action->messageText);
            $receipt = $this->execute($command, $administratorId, $action);
        } catch (AuthorizationException) {
            $this->returnHome($action);

            return;
        } catch (InvalidArgumentException|DomainException $exception) {
            $this->renderEditor($action, $action->sessionVersion, true);

            return;
        } catch (RuntimeException $exception) {
            if (! $this->isExpectedConfigurationFailure($exception)) {
                throw $exception;
            }
            $this->renderEditor($action, $action->sessionVersion, true);

            return;
        }

        $this->showList($action, $receipt);
    }

    private function execute(
        TelegramMembershipConfigurationCommand $command,
        int $administratorId,
        TelegramInteractionAction $action,
    ): TelegramConfigurationMutationReceipt {
        $context = new TelegramConfigurationChangeContext(
            'tg-membership-'.substr(hash('sha256', $action->requestKey.':'.$command->operation), 0, 56),
            'tg-membership-'.substr(hash('sha256', $action->requestKey.':correlation'), 0, 48),
            'telegram_membership_configuration',
            $command->reason,
            $administratorId,
        );

        return match ($command->operation) {
            'channel.create' => $this->channels->create(
                $command->channel ?? throw new RuntimeException('Telegram membership channel definition is unavailable.'),
                $context,
            ),
            'channel.update' => $this->channels->update(
                $command->targetId ?? 0,
                $command->expectedVersion ?? 0,
                $command->channel ?? throw new RuntimeException('Telegram membership channel definition is unavailable.'),
                $context,
            ),
            'channel.activate' => $this->channels->activate($command->targetId ?? 0, $command->expectedVersion ?? 0, $context),
            'channel.disable' => $this->channels->disable($command->targetId ?? 0, $command->expectedVersion ?? 0, $context),
            'rule.create' => $this->rules->create(
                $command->rule ?? throw new RuntimeException('Telegram membership rule definition is unavailable.'),
                $context,
            ),
            'rule.update' => $this->rules->update(
                $command->targetId ?? 0,
                $command->expectedVersion ?? 0,
                $command->rule ?? throw new RuntimeException('Telegram membership rule definition is unavailable.'),
                $context,
            ),
            'rule.activate' => $this->rules->activate($command->targetId ?? 0, $command->expectedVersion ?? 0, $context),
            'rule.disable' => $this->rules->disable($command->targetId ?? 0, $command->expectedVersion ?? 0, $context),
            default => throw new RuntimeException('Telegram membership configuration operation is unsupported.'),
        };
    }

    private function showList(
        TelegramInteractionAction $action,
        ?TelegramConfigurationMutationReceipt $receipt = null,
    ): void {
        try {
            $this->assertPermission($action);
        } catch (AuthorizationException) {
            $this->returnHome($action);

            return;
        }

        $session = $this->transition($action, self::STATE_LIST, [], 'list');
        if ($session === null) {
            return;
        }

        $this->renderList($action, $session->version, $receipt);
    }

    private function renderList(
        TelegramInteractionAction $action,
        int $sessionVersion,
        ?TelegramConfigurationMutationReceipt $receipt,
    ): void {
        $this->assertPermission($action);
        $locale = $this->locale($action->userId);
        $channels = $this->channels->listSafe();
        $rules = $this->rules->listSafe();

        $channelLines = [];
        foreach (array_slice($channels, 0, self::LIST_LIMIT) as $channel) {
            $channelLines[] = $this->translation('telegram.navigation.admin.membership.channel_item', $locale, [
                'id' => (int) $channel['id'],
                'key' => (string) $channel['channel_key'],
                'title' => (string) $channel['display_title'],
                'state' => (string) $channel['state'],
                'version' => (int) $channel['version'],
                'chat_id' => (int) $channel['telegram_chat_id'],
            ]);
        }

        $ruleLines = [];
        foreach (array_slice($rules, 0, self::LIST_LIMIT) as $rule) {
            $ruleLines[] = $this->translation('telegram.navigation.admin.membership.rule_item', $locale, [
                'id' => (int) $rule['id'],
                'key' => (string) $rule['rule_key'],
                'action' => $rule['action'] === null ? '*' : (string) $rule['action'],
                'audience' => (string) $rule['audience'],
                'mode' => (string) $rule['match_mode'],
                'failure' => (string) $rule['failure_policy'],
                'state' => (string) $rule['state'],
                'version' => (int) $rule['version'],
                'channels' => (string) $rule['channel_ids_json'],
            ]);
        }

        $text = $this->translation('telegram.navigation.admin.membership.list', $locale, [
            'channels' => $channelLines === [] ? $this->translation('telegram.navigation.admin.membership.none', $locale) : implode("\n", $channelLines),
            'rules' => $ruleLines === [] ? $this->translation('telegram.navigation.admin.membership.none', $locale) : implode("\n", $ruleLines),
            'channel_count' => count($channels),
            'rule_count' => count($rules),
        ]);
        if ($receipt !== null) {
            $text .= "\n\n".$this->translation(
                $receipt->replayed
                    ? 'telegram.navigation.admin.membership.replayed'
                    : ($receipt->changed
                        ? 'telegram.navigation.admin.membership.changed'
                        : 'telegram.navigation.admin.membership.unchanged'),
                $locale,
                ['id' => $receipt->targetId],
            );
        }
        if (count($channels) > self::LIST_LIMIT || count($rules) > self::LIST_LIMIT) {
            $text .= "\n\n".$this->translation('telegram.navigation.admin.membership.list_truncated', $locale, [
                'limit' => self::LIST_LIMIT,
            ]);
        }

        $edit = $this->callbackButton($action, $sessionVersion, self::ACTION_EDIT, 'edit', $this->translation('telegram.navigation.admin.membership.edit_button', $locale));
        $refresh = $this->callbackButton($action, $sessionVersion, self::ACTION_REFRESH, 'refresh', $this->translation('telegram.navigation.admin.membership.refresh_button', $locale));
        $back = $this->callbackButton($action, $sessionVersion, self::ACTION_BACK, 'back', $this->translation('telegram.navigation.buttons.back', $locale));

        $this->queueConfidential(
            $action,
            $text,
            'tg-membership-list:'.hash('sha256', $action->requestKey),
            'list',
            new TelegramInlineKeyboardSnapshot([[$edit], [$refresh], [$back]]),
        );
    }

    private function showEditor(TelegramInteractionAction $action): void
    {
        try {
            $this->assertPermission($action);
        } catch (AuthorizationException) {
            $this->returnHome($action);

            return;
        }

        $session = $this->transition($action, self::STATE_EDIT, [], 'editor');
        if ($session === null) {
            return;
        }

        $this->renderEditor($action, $session->version, false);
    }

    private function renderEditor(TelegramInteractionAction $action, int $sessionVersion, bool $invalid): void
    {
        $this->assertPermission($action);
        $locale = $this->locale($action->userId);
        $text = $this->translation('telegram.navigation.admin.membership.editor', $locale, [
            'example' => $this->authoringExample(),
        ]);
        if ($invalid) {
            $text .= "\n\n".$this->translation('telegram.navigation.admin.membership.invalid', $locale);
        }

        $back = $this->callbackButton($action, $sessionVersion, self::ACTION_BACK, 'editor-back', $this->translation('telegram.navigation.buttons.back', $locale));
        $this->queueConfidential(
            $action,
            $text,
            'tg-membership-editor:'.hash('sha256', $action->requestKey.':'.($invalid ? 'invalid' : 'normal')),
            'editor',
            new TelegramInlineKeyboardSnapshot([[$back]]),
        );
    }

    private function authoringExample(): string
    {
        return <<<'TEXT'
channel.create:
operation=channel.create
channel_key=main_channel
chat_id=-1001234567890
chat_type=channel
visibility=public
title=Main Channel
join_url=https://t.me/example_channel
sort_order=10
reason=membership setup

channel.update uses the same fields plus id=... and version=...
channel.activate / channel.disable:
operation=channel.activate
id=1
version=1
reason=verified configuration

rule.create:
operation=rule.create
rule_key=purchase_default
action=purchase
audience=customers
tier_code=-
customer_tag_id=-
plan_offering_id=-
match_mode=all
failure_policy=fail_closed
priority=100
effective_from=-
effective_until=-
channel_ids=1,2
reason=membership policy

rule.update uses the same fields plus id=... and version=...
rule.activate / rule.disable use operation,id,version,reason.
Allowed actions: bot_entry, trial, purchase, gift_code_use, referral_reward, ticket_creation, service_view, support_view (use action=- for any action).
Times, when used, must include timezone, e.g. 2026-09-25T08:00:00+03:30.
TEXT;
    }

    private function returnAdminControl(TelegramInteractionAction $action): void
    {
        try {
            $this->assertPermission($action);
        } catch (AuthorizationException) {
            $this->returnHome($action);

            return;
        }

        $session = $this->transition($action, 'admin_control', [], 'admin-control');
        if ($session === null) {
            return;
        }
        $this->navigation->renderCurrentAdminControl($action, $session->version);
    }

    private function returnHome(TelegramInteractionAction $action): void
    {
        $session = $this->transition($action, TelegramNavigationEntryGateway::STATE, [], 'home');
        if ($session === null) {
            return;
        }

        $this->navigation->handle(new TelegramInteractionAction(
            TelegramInteractionActionKind::Message,
            $action->requestKey.':membership-return',
            $action->botId,
            $action->updateId,
            $action->telegramAccountId,
            $action->userId,
            $action->telegramUserId,
            $session->publicId,
            $session->flow,
            $session->state,
            $session->version,
            $session->payload,
            '/menu',
            null,
            null,
            [],
            $action->replayed,
            null,
            $action->messageAcceptedAt,
        ));
    }

    private function assertPermission(TelegramInteractionAction $action): int
    {
        return $this->administrators->authorizeUser(
            $action->userId,
            TelegramConfigurationMutationExecutor::MANAGE_PERMISSION,
        );
    }

    private function callbackButton(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $callbackAction,
        string $surface,
        string $label,
    ): TelegramInlineCallbackButton {
        $callback = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            $callbackAction,
            [],
            'tg-membership-button:'.hash('sha256', $action->requestKey.':'.$surface),
        );

        return new TelegramInlineCallbackButton($label, $callback->publicId, TelegramInlineButtonStyle::Primary);
    }

    /** @param array<string,mixed> $payload */
    private function transition(
        TelegramInteractionAction $action,
        string $state,
        array $payload,
        string $surface,
    ): ?TelegramInteractionSessionReceipt {
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                $state,
                $payload,
                'tg-membership-transition:'.hash('sha256', $action->requestKey.':'.$surface),
            );
        } catch (DomainException) {
            return null;
        }

        if ($session->userId !== $action->userId) {
            throw new RuntimeException('Telegram membership configuration actor binding changed.');
        }

        return $session;
    }

    private function queueConfidential(
        TelegramInteractionAction $action,
        string $text,
        string $requestKey,
        string $surface,
        ?TelegramInlineKeyboardSnapshot $keyboard = null,
    ): void {
        $source = new readonly class($text) implements ConfidentialTelegramPresentationSource
        {
            public function __construct(private string $text) {}

            public function confidentialTelegramText(): string
            {
                return $this->text;
            }
        };
        $this->delivery->send(
            $action->telegramUserId,
            $this->presentations->fromSource($source),
            $requestKey,
            'tg-membership:'.substr(hash('sha256', $action->botId.':'.$action->updateId.':'.$surface), 0, 40),
            $keyboard,
        );
    }

    private function locale(int $userId): string
    {
        return $this->customers->forSelf($userId, $userId)->locale === 'en' ? 'en' : 'fa';
    }

    /** @param array<string,int|string> $replace */
    private function translation(string $key, string $locale, array $replace = []): string
    {
        $value = $this->localization->resolve($key, $replace, $locale);
        if ($value === '' || $value === '['.$key.']' || preg_match('/:[A-Za-z_][A-Za-z0-9_]*/', $value) === 1) {
            throw new RuntimeException('Telegram membership configuration translation is unavailable.');
        }

        return $value;
    }

    private function isExpectedConfigurationFailure(RuntimeException $exception): bool
    {
        $message = $exception->getMessage();

        return str_starts_with($message, 'Telegram configuration')
            || str_starts_with($message, 'Telegram required')
            || str_starts_with($message, 'Telegram membership')
            || str_starts_with($message, 'Active Telegram')
            || str_starts_with($message, 'Stored Telegram');
    }

    private function isEntryCommand(?string $text): bool
    {
        if ($text === null) {
            return false;
        }
        $trimmed = trim($text);

        return preg_match('/\A\/menu(?:@[A-Za-z0-9_]+)?\z/u', $trimmed) === 1
            || preg_match('/\A\/start(?:@[A-Za-z0-9_]+)?(?:\s+[A-Za-z0-9_-]{1,64})?\z/u', $trimmed) === 1;
    }
}
