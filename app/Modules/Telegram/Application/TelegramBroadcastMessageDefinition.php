<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Domain\TelegramBroadcastMessageMode;
use App\Modules\Telegram\Domain\TelegramBroadcastSourceKind;
use DomainException;
use JsonException;
use SensitiveParameter;

final readonly class TelegramBroadcastMessageDefinition
{
    private const MAX_TEXT_CHARACTERS = 4096;

    private const MAX_TEXT_BYTES = 16_384;

    private function __construct(
        public TelegramBroadcastMessageMode $mode,
        public ?TelegramBroadcastSourceKind $sourceKind,
        public ?string $text,
        public ?string $captionOverride,
        public ?int $sourceChatId,
        public ?int $sourceMessageId,
        public ?TelegramInlineKeyboardSnapshot $inlineKeyboard,
    ) {
        if ($mode === TelegramBroadcastMessageMode::NewText) {
            if ($text === null
                || $text === ''
                || mb_strlen($text) > self::MAX_TEXT_CHARACTERS
                || strlen($text) > self::MAX_TEXT_BYTES
                || ! mb_check_encoding($text, 'UTF-8')
                || str_contains($text, "\0")
                || $sourceKind !== null
                || $captionOverride !== null
                || $sourceChatId !== null
                || $sourceMessageId !== null
            ) {
                throw new DomainException('Broadcast text content is invalid.');
            }
        } elseif ($sourceKind === null
            || $text !== null
            || $sourceChatId === null
            || $sourceChatId < 1
            || $sourceMessageId === null
            || $sourceMessageId < 1
        ) {
            throw new DomainException('Broadcast source-message content is invalid.');
        }

        if ($captionOverride !== null) {
            if ($mode !== TelegramBroadcastMessageMode::Copy
                || $sourceKind === null
                || ! $sourceKind->supportsCaption()
                || mb_strlen($captionOverride) > 1024
                || strlen($captionOverride) > 4096
                || ! mb_check_encoding($captionOverride, 'UTF-8')
                || str_contains($captionOverride, "\0")
            ) {
                throw new DomainException('Broadcast caption override is invalid.');
            }
        }

        if (! $mode->supportsAuthoredInlineKeyboard() && $inlineKeyboard !== null) {
            throw new DomainException('Forwarded broadcast messages cannot add an authored inline keyboard.');
        }

        if ($inlineKeyboard !== null && $inlineKeyboard->callbackPublicIds() !== []) {
            throw new DomainException('Broadcast inline keyboards require recipient-independent HTTPS URL buttons.');
        }
    }

    public static function newText(
        #[SensitiveParameter] string $text,
        ?TelegramInlineKeyboardSnapshot $inlineKeyboard = null,
    ): self {
        return new self(
            TelegramBroadcastMessageMode::NewText,
            null,
            $text,
            null,
            null,
            null,
            $inlineKeyboard,
        );
    }

    public static function copy(
        int $sourceChatId,
        int $sourceMessageId,
        TelegramBroadcastSourceKind $sourceKind,
        ?string $captionOverride = null,
        ?TelegramInlineKeyboardSnapshot $inlineKeyboard = null,
    ): self {
        return new self(
            TelegramBroadcastMessageMode::Copy,
            $sourceKind,
            null,
            $captionOverride,
            $sourceChatId,
            $sourceMessageId,
            $inlineKeyboard,
        );
    }

    public static function forward(
        int $sourceChatId,
        int $sourceMessageId,
        TelegramBroadcastSourceKind $sourceKind,
    ): self {
        return new self(
            TelegramBroadcastMessageMode::Forward,
            $sourceKind,
            null,
            null,
            $sourceChatId,
            $sourceMessageId,
            null,
        );
    }

    public static function restoreStored(
        string $mode,
        ?string $sourceKind,
        ?string $text,
        ?string $captionOverride,
        int|string|null $sourceChatId,
        int|string|null $sourceMessageId,
        ?string $inlineKeyboardSnapshot,
    ): self {
        $resolvedMode = TelegramBroadcastMessageMode::tryFrom($mode)
            ?? throw new DomainException('Stored broadcast message mode is invalid.');
        $resolvedKind = $sourceKind === null
            ? null
            : TelegramBroadcastSourceKind::tryFrom($sourceKind);
        if ($sourceKind !== null && $resolvedKind === null) {
            throw new DomainException('Stored broadcast source kind is invalid.');
        }
        $chatId = self::nullablePositiveInt($sourceChatId, 'Stored broadcast source chat ID');
        $messageId = self::nullablePositiveInt($sourceMessageId, 'Stored broadcast source message ID');
        $keyboard = $inlineKeyboardSnapshot === null
            ? null
            : TelegramInlineKeyboardSnapshot::restore($inlineKeyboardSnapshot);

        return new self(
            $resolvedMode,
            $resolvedKind,
            $text,
            $captionOverride,
            $chatId,
            $messageId,
            $keyboard,
        );
    }

    public function withInlineKeyboard(?TelegramInlineKeyboardSnapshot $inlineKeyboard): self
    {
        return new self(
            $this->mode,
            $this->sourceKind,
            $this->text,
            $this->captionOverride,
            $this->sourceChatId,
            $this->sourceMessageId,
            $inlineKeyboard,
        );
    }

    public function withText(string $text): self
    {
        if ($this->mode !== TelegramBroadcastMessageMode::NewText) {
            throw new DomainException('Only a text broadcast can replace its text body.');
        }

        return self::newText($text, $this->inlineKeyboard);
    }

    public function withCaptionOverride(?string $captionOverride): self
    {
        if ($this->mode !== TelegramBroadcastMessageMode::Copy
            || $this->sourceKind?->supportsCaption() !== true
        ) {
            throw new DomainException('Only copied media broadcasts can replace their caption.');
        }

        return self::copy(
            $this->sourceChatId ?? throw new DomainException('Broadcast copy source chat is unavailable.'),
            $this->sourceMessageId ?? throw new DomainException('Broadcast copy source message is unavailable.'),
            $this->sourceKind,
            $captionOverride,
            $this->inlineKeyboard,
        );
    }

    public function contentHash(): string
    {
        try {
            $json = json_encode([
                'mode' => $this->mode->value,
                'source_kind' => $this->sourceKind?->value,
                'text' => $this->text,
                'caption_override' => $this->captionOverride,
                'source_chat_id' => $this->sourceChatId,
                'source_message_id' => $this->sourceMessageId,
                'inline_keyboard_hash' => $this->inlineKeyboard?->hash(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new DomainException('Broadcast content could not be encoded.', 0, $exception);
        }

        return hash('sha256', $json);
    }

    public function inlineKeyboardJson(): ?string
    {
        return $this->inlineKeyboard?->json();
    }

    private static function nullablePositiveInt(int|string|null $value, string $label): ?int
    {
        if ($value === null) {
            return null;
        }

        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($validated === false) {
            throw new DomainException($label.' is invalid.');
        }

        return $validated;
    }

    /** @return array{redacted:true,type:string,mode:string,has_inline_keyboard:bool} */
    public function __debugInfo(): array
    {
        return [
            'redacted' => true,
            'type' => 'telegram_broadcast_message_definition',
            'mode' => $this->mode->value,
            'has_inline_keyboard' => $this->inlineKeyboard !== null,
        ];
    }
}
