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
