<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;
use LogicException;
use Stringable;

final class TelegramResolvedInlineKeyboardMarkup implements Stringable
{
    /** @param array<string,string> $callbackDataByPublicId */
    private function __construct(
        private readonly TelegramInlineKeyboardSnapshot $snapshot,
        private readonly array $callbackDataByPublicId,
    ) {}

    /** @param array<string,string> $callbackDataByPublicId */
    public static function resolve(
        TelegramInlineKeyboardSnapshot $snapshot,
        array $callbackDataByPublicId,
    ): self {
        $expected = $snapshot->callbackPublicIds();
        if (count($callbackDataByPublicId) !== count($expected)) {
            throw new InvalidArgumentException('Telegram inline keyboard callback resolution is incomplete.');
        }

        foreach ($expected as $publicId) {
            $callbackData = $callbackDataByPublicId[$publicId] ?? null;
            if (! is_string($callbackData)
                || preg_match('/\Ai_[A-Za-z0-9_-]{32}\z/', $callbackData) !== 1
                || strlen($callbackData) > 64) {
                throw new InvalidArgumentException('Telegram inline keyboard callback data is invalid.');
            }
        }

        return new self($snapshot, $callbackDataByPublicId);
    }

    /** @return array{inline_keyboard:list<list<array<string,string>>>} */
    public function providerPayload(): array
    {
        $rows = [];
        foreach ($this->snapshot->rows() as $row) {
            $providerRow = [];
            foreach ($row as $button) {
                $providerButton = [
                    'text' => $button->text,
                    'callback_data' => $this->callbackDataByPublicId[$button->callbackPublicId],
                ];
                if ($button->style !== null) {
                    $providerButton['style'] = $button->style->value;
                }
                $providerRow[] = $providerButton;
            }
            $rows[] = $providerRow;
        }

        return ['inline_keyboard' => $rows];
    }

    public function __toString(): string
    {
        return '[PROTECTED_TELEGRAM_INLINE_KEYBOARD]';
    }

    /** @return array{redacted:true,type:string} */
    public function __debugInfo(): array
    {
        return ['redacted' => true, 'type' => 'inline_keyboard'];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Resolved Telegram inline keyboards cannot be serialized.');
    }

    /** @param array<array-key,mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Resolved Telegram inline keyboards cannot be unserialized.');
    }
}
