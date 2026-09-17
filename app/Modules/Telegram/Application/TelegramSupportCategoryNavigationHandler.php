<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Localization\Application\LocalizationResolver;
use App\Modules\Support\Application\SupportTicketCategoryManagementService;
use App\Modules\Support\Application\SupportTicketCategoryManagementSnapshot;
use App\Modules\Telegram\Domain\TelegramInteractionActionKind;
use DomainException;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * Bounded operator presentation for Support category management. Support owns
 * category mutation/routing semantics; Telegram owns only restart-safe command
 * interaction and confidential presentation.
 */
final readonly class TelegramSupportCategoryNavigationHandler
{
    private const STATE_QUEUE = 'support_queue';

    private const STATE_MUTATING = 'support_mutating';

    private const ACTION_BACK = 'navigation.back';

    public function __construct(
        private LocalizationResolver $localization,
        private ConfidentialTelegramPresentationFactory $presentations,
        private TelegramConfidentialDeliveryQueue $delivery,
        private TelegramInteractionSessionService $sessions,
        private TelegramInteractionCallbackService $callbacks,
        private SupportTicketCategoryManagementService $categories,
        private DatabaseManager $database,
    ) {}

    public function supports(TelegramInteractionAction $action): bool
    {
        if ($action->sessionState !== self::STATE_QUEUE
            || $action->kind !== TelegramInteractionActionKind::Message
            || $action->messageText === null) {
            return false;
        }

        $text = trim($action->messageText);

        return preg_match('/\A\/support-categories(?:@[A-Za-z0-9_]+)?\z/u', $text) === 1
            || str_starts_with($text, '/support-category');
    }

    public function handle(TelegramInteractionAction $action): void
    {
        if ($action->kind !== TelegramInteractionActionKind::Message || $action->messageText === null) {
            throw new RuntimeException('Telegram Support category management expects a message command.');
        }

        $text = trim($action->messageText);
        if (preg_match('/\A\/support-categories(?:@[A-Za-z0-9_]+)?\z/u', $text) === 1) {
            $this->renderCategories($action, $action->sessionVersion);

            return;
        }

        [$code, $field, $value] = $this->mutation($text);
        if ($action->replayed && $this->recoverAdvancedMutation($action, $code)) {
            return;
        }

        $committed = $this->commitMutation($action, $code, $field, $value);
        if ($committed === null) {
            return;
        }
        [$session, $category] = $committed;
        $this->queueCategoryConfirmation($action, $session->version, $category);
    }

    /** @return array{0:string,1:string,2:string} */
    private function mutation(string $text): array
    {
        if (preg_match(
            '/\A\/support-category(?:@[A-Za-z0-9_]+)?\s+([a-z0-9_]{1,64})\s+(name-fa|name-en|sort|active|route)\s+(.+)\z/u',
            $text,
            $matches,
        ) !== 1) {
            throw new DomainException('Telegram Support category command is invalid.');
        }

        return [$matches[1], $matches[2], trim($matches[3])];
    }

    /** @return array{0:TelegramInteractionSessionReceipt,1:SupportTicketCategoryManagementSnapshot}|null */
    private function commitMutation(
        TelegramInteractionAction $action,
        string $code,
        string $field,
        string $value,
    ): ?array {
        try {
            return $this->database->connection()->transaction(function () use ($action, $code, $field, $value): array {
                $claim = $this->claimSession($action, $code);
                $category = $this->applyMutation($action->userId, $code, $field, $value);
                $completed = $this->completeSession($action, $claim);

                return [$completed, $category];
            }, 3);
        } catch (TelegramSupportCategorySessionRace) {
            return null;
        }
    }

    private function applyMutation(
        int $actorUserId,
        string $code,
        string $field,
        string $value,
    ): SupportTicketCategoryManagementSnapshot {
        return match ($field) {
            'name-fa' => $this->categories->setName($actorUserId, $code, 'fa', $value),
            'name-en' => $this->categories->setName($actorUserId, $code, 'en', $value),
            'sort' => $this->categories->setSortOrder($actorUserId, $code, $this->sortOrder($value)),
            'active' => $this->categories->setActive($actorUserId, $code, $this->active($value)),
            'route' => $this->categories->setRouteRole($actorUserId, $code, $value === 'none' ? null : $value),
            default => throw new DomainException('Telegram Support category field is invalid.'),
        };
    }

    private function sortOrder(string $value): int
    {
        if (preg_match('/\A[0-9]{1,10}\z/', $value) !== 1) {
            throw new DomainException('Telegram Support category sort order is invalid.');
        }
        $sortOrder = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if (! is_int($sortOrder) || $sortOrder > 4_294_967_295) {
            throw new DomainException('Telegram Support category sort order is invalid.');
        }

        return $sortOrder;
    }

    private function active(string $value): bool
    {
        return match ($value) {
            'on' => true,
            'off' => false,
            default => throw new DomainException('Telegram Support category active value is invalid.'),
        };
    }

    private function claimSession(
        TelegramInteractionAction $action,
        string $code,
    ): TelegramInteractionSessionReceipt {
        try {
            $claim = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_MUTATING,
                ['operation' => 'support-category', 'category' => $code],
                'tg-support-category-claim:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException $exception) {
            throw new TelegramSupportCategorySessionRace('Telegram Support category mutation lost the session claim race.', 0, $exception);
        }
        $this->assertSessionActor($action, $claim);

        return $claim;
    }

    private function completeSession(
        TelegramInteractionAction $action,
        TelegramInteractionSessionReceipt $claim,
    ): TelegramInteractionSessionReceipt {
        try {
            $completed = $this->sessions->transition(
                $action->sessionPublicId,
                $claim->version,
                self::STATE_QUEUE,
                [],
                'tg-support-category-complete:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException $exception) {
            throw new TelegramSupportCategorySessionRace('Telegram Support category mutation lost the session completion race.', 0, $exception);
        }
        $this->assertSessionActor($action, $completed);

        return $completed;
    }

    private function recoverAdvancedMutation(TelegramInteractionAction $action, string $code): bool
    {
        $session = $this->sessions->activeForAccount($action->telegramAccountId);
        if ($session === null
            || $session->publicId !== $action->sessionPublicId
            || $session->userId !== $action->userId
            || $session->version <= $action->sessionVersion) {
            return false;
        }

        if ($session->version === $action->sessionVersion + 2 && $session->state === self::STATE_QUEUE) {
            $this->queueCategoryConfirmation(
                $action,
                $session->version,
                $this->categories->category($action->userId, $code),
            );
        }

        return true;
    }

    private function renderCategories(TelegramInteractionAction $action, int $version): void
    {
        $locale = $this->locale($action->userId);
        $items = [];
        foreach ($this->categories->categories($action->userId) as $category) {
            $items[] = $this->categoryText($category, $locale);
        }

        $rows = [];
        $this->appendBack($action, $version, $rows, $locale);
        $this->queue(
            $action,
            $this->translation('telegram_support.category_management', $locale, [
                'items' => implode("\n\n", $items),
            ]),
            'categories',
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    private function queueCategoryConfirmation(
        TelegramInteractionAction $action,
        int $version,
        SupportTicketCategoryManagementSnapshot $category,
    ): void {
        $locale = $this->locale($action->userId);
        $rows = [];
        $this->appendBack($action, $version, $rows, $locale);
        $this->queue(
            $action,
            $this->translation('telegram_support.category_updated', $locale, [
                'item' => $this->categoryText($category, $locale),
            ]),
            'category-updated',
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    private function categoryText(SupportTicketCategoryManagementSnapshot $category, string $locale): string
    {
        return $this->translation('telegram_support.category_management_item', $locale, [
            'code' => $category->code,
            'name' => $locale === 'en' ? $category->nameEn : $category->nameFa,
            'sort' => $category->sortOrder,
            'active' => $this->translation(
                $category->isActive ? 'telegram_support.category_active' : 'telegram_support.category_inactive',
                $locale,
            ),
            'route' => $category->routeRoleCode
                ?? $this->translation('telegram_support.category_unrouted', $locale),
        ]);
    }

    private function assertSessionActor(
        TelegramInteractionAction $action,
        TelegramInteractionSessionReceipt $session,
    ): void {
        if ($session->userId !== $action->userId) {
            throw new RuntimeException('Telegram Support category session actor binding is invalid.');
        }
    }

    /** @param list<list<TelegramInlineCallbackButton>> $rows */
    private function appendBack(TelegramInteractionAction $action, int $version, array &$rows, string $locale): void
    {
        $back = $this->callbacks->issue(
            $action->sessionPublicId,
            $version,
            self::ACTION_BACK,
            [],
            'tg-support-category-back:'.hash('sha256', $action->requestKey.':'.$version),
        );
        $rows[] = [new TelegramInlineCallbackButton(
            $this->translation('telegram_support.buttons.back', $locale),
            $back->publicId,
        )];
    }

    private function queue(
        TelegramInteractionAction $action,
        string $text,
        string $surface,
        TelegramInlineKeyboardSnapshot $keyboard,
    ): void {
        if (mb_strlen($text) > ConfidentialTelegramPresentation::MAXIMUM_TEXT_CHARACTERS) {
            $text = mb_substr($text, 0, ConfidentialTelegramPresentation::MAXIMUM_TEXT_CHARACTERS - 1).'…';
        }

        $source = new readonly class($text) implements ConfidentialTelegramPresentationSource
        {
            public function __construct(private string $text) {}

            public function confidentialTelegramText(): string
            {
                return $this->text;
            }
        };
        $presentation = $this->presentations->fromSource($source);
        $this->delivery->send(
            $action->telegramUserId,
            $presentation,
            'tg-support-category-delivery:'.hash('sha256', $action->requestKey.':'.$surface),
            'tg-support:'.substr(hash('sha256', $action->botId.':'.$action->updateId.':'.$surface), 0, 48),
            $keyboard,
        );
    }

    private function locale(int $userId): string
    {
        $locale = $this->database->connection()->table('users')->where('id', $userId)->value('locale');

        return $locale === 'en' ? 'en' : 'fa';
    }

    /** @param array<string,bool|float|int|string> $replace */
    private function translation(string $key, string $locale, array $replace = []): string
    {
        $value = $this->localization->resolve($key, $replace, $locale);
        if ($value === '' || $value === '['.$key.']') {
            throw new RuntimeException('Telegram Support category translation is unavailable.');
        }
        if (preg_match('/:[A-Za-z_][A-Za-z0-9_]*/', $value) === 1) {
            throw new RuntimeException('Telegram Support category translation has an unresolved placeholder.');
        }

        return $value;
    }
}

final class TelegramSupportCategorySessionRace extends RuntimeException {}
