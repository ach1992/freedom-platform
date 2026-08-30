<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Application\Contracts\TelegramInteractionHandler;
use App\Modules\Telegram\Domain\TelegramDeliveryAction;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final readonly class TelegramNavigationHandler implements TelegramInteractionHandler
{
    private const TRANSLATION_KEY = 'telegram.navigation.home';

    public function __construct(
        private DatabaseManager $database,
        private Translator $translator,
        private NonRestrictedTelegramPresentationFactory $presentations,
        private TelegramDeliveryQueueService $delivery,
    ) {}

    public function flow(): string
    {
        return TelegramNavigationEntryGateway::FLOW;
    }

    public function handle(TelegramInteractionAction $action): void
    {
        if ($action->sessionState !== TelegramNavigationEntryGateway::STATE) {
            throw new RuntimeException('Telegram navigation session state is unsupported.');
        }

        $locale = $this->locale($action->userId);
        $text = $this->translation($locale);
        $source = new readonly class($text) implements NonRestrictedTelegramPresentationSource
        {
            public function __construct(private string $text) {}

            public function nonRestrictedTelegramText(): string
            {
                return $this->text;
            }
        };
        $presentation = $this->presentations->fromSource($source);

        $this->delivery->queue(
            TelegramDeliveryAction::Send,
            $action->telegramUserId,
            null,
            $presentation,
            'navigation-home:'.$action->requestKey,
            "telegram-nav:{$action->botId}:{$action->updateId}",
        );
    }

    private function locale(int $userId): string
    {
        $locale = $this->database->connection()->table('users')->where('id', $userId)->value('locale');

        return $locale === 'en' ? 'en' : 'fa';
    }

    private function translation(string $locale): string
    {
        $text = $this->translator->get(self::TRANSLATION_KEY, [], $locale);
        if (! is_string($text) || $text === '' || $text === self::TRANSLATION_KEY) {
            $text = $this->translator->get(self::TRANSLATION_KEY, [], 'en');
        }
        if (! is_string($text) || $text === '' || $text === self::TRANSLATION_KEY) {
            throw new RuntimeException('Telegram navigation translation is unavailable.');
        }

        return $text;
    }
}
