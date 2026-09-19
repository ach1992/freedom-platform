<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Domain\TelegramBroadcastMessageMode;
use DomainException;
use JsonException;
use SensitiveParameter;

final readonly class TelegramBroadcastMessageDefinition
{
    private const MAX_TEXT_CHARACTERS = 4096;

    private const MAX_TEXT_BYTES = 16_384;

    private function __construct(
        public TelegramBroadcastMessageMode $mode,
        public ?string $text,
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
                || $sourceChatId !== null
                || $sourceMessageId !== null
            ) {
                throw new DomainException('Broadcast text content is invalid.');
            }
        } elseif ($text !== null
            || $sourceChatId === null
            || $sourceChatId < 1
            || $sourceMessageId === null
            || $sourceMessageId < 1
        ) {
            throw new DomainException('Broadcast source-message content is invalid.');
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
            $text,
            null,
            null,
            $inlineKeyboard,
        );
    }

    public static function copy(
        int $sourceChatId,
        int $sourceMessageId,
        ?TelegramInlineKeyboardSnapshot $inlineKeyboard = null,
    ): self {
        return new self(
            TelegramBroadcastMessageMode::Copy,
            null,
            $sourceChatId,
            $sourceMessageId,
            $inlineKeyboard,
        );
    }

    public static function forward(int $sourceChatId, int $sourceMessageId): self
    {
        return new self(
            TelegramBroadcastMessageMode::Forward,
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
                'text' => $this->text,
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
