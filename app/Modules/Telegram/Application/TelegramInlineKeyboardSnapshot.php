<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;
use JsonException;

final readonly class TelegramInlineKeyboardSnapshot
{
    private const MAXIMUM_ROWS = 10;

    private const MAXIMUM_BUTTONS_PER_ROW = 8;

    private const MAXIMUM_BUTTONS = 64;

    private const MAXIMUM_BYTES = 16_384;

    /** @var list<list<TelegramInlineCallbackButton>> */
    private array $rows;

    private string $json;

    private string $hash;

    /** @param list<list<TelegramInlineCallbackButton>> $rows */
    public function __construct(array $rows)
    {
        if ($rows === [] || count($rows) > self::MAXIMUM_ROWS) {
            throw new InvalidArgumentException('Telegram inline keyboard must contain 1-10 rows.');
        }

        $seen = [];
        $buttonCount = 0;
        foreach ($rows as $row) {
            if (! is_array($row) || ! array_is_list($row) || $row === [] || count($row) > self::MAXIMUM_BUTTONS_PER_ROW) {
                throw new InvalidArgumentException('Telegram inline keyboard rows must contain 1-8 buttons.');
            }
            foreach ($row as $button) {
                if (! $button instanceof TelegramInlineCallbackButton) {
                    throw new InvalidArgumentException('Telegram inline keyboard contains an invalid button.');
                }
                if (isset($seen[$button->callbackPublicId])) {
                    throw new InvalidArgumentException('Telegram inline keyboard callback identities must be unique.');
                }
                $seen[$button->callbackPublicId] = true;
                $buttonCount++;
            }
        }
        if ($buttonCount > self::MAXIMUM_BUTTONS) {
            throw new InvalidArgumentException('Telegram inline keyboard exceeds the 64-button safety limit.');
        }

        $values = [
            'rows' => array_map(
                static fn (array $row): array => array_map(
                    static fn (TelegramInlineCallbackButton $button): array => $button->snapshot(),
                    $row,
                ),
                $rows,
            ),
        ];
        try {
            $json = json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Telegram inline keyboard could not be encoded.', 0, $exception);
        }
        if (strlen($json) > self::MAXIMUM_BYTES) {
            throw new InvalidArgumentException('Telegram inline keyboard exceeds the 16 KiB safety limit.');
        }

        $this->rows = $rows;
        $this->json = $json;
        $this->hash = hash('sha256', $json);
    }

    public static function restore(string $json): self
    {
        if ($json === '' || strlen($json) > self::MAXIMUM_BYTES) {
            throw new InvalidArgumentException('Stored Telegram inline keyboard snapshot is invalid.');
        }

        try {
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Stored Telegram inline keyboard snapshot is invalid.', 0, $exception);
        }
        if (! is_array($decoded) || array_is_list($decoded) || array_keys($decoded) !== ['rows']) {
            throw new InvalidArgumentException('Stored Telegram inline keyboard snapshot has an invalid shape.');
        }
        $decodedRows = $decoded['rows'];
        if (! is_array($decodedRows) || ! array_is_list($decodedRows)) {
            throw new InvalidArgumentException('Stored Telegram inline keyboard rows are invalid.');
        }

        $rows = [];
        foreach ($decodedRows as $decodedRow) {
            if (! is_array($decodedRow) || ! array_is_list($decodedRow)) {
                throw new InvalidArgumentException('Stored Telegram inline keyboard row is invalid.');
            }
            $row = [];
            foreach ($decodedRow as $decodedButton) {
                if (! is_array($decodedButton)
                    || array_is_list($decodedButton)
                    || array_keys($decodedButton) !== ['text', 'callback_public_id', 'style']
                    || ! is_string($decodedButton['text'] ?? null)
                    || ! is_string($decodedButton['callback_public_id'] ?? null)
                    || (! is_string($decodedButton['style'] ?? null) && ($decodedButton['style'] ?? null) !== null)) {
                    throw new InvalidArgumentException('Stored Telegram inline keyboard button is invalid.');
                }
                $style = $decodedButton['style'] === null
                    ? null
                    : TelegramInlineButtonStyle::tryFrom($decodedButton['style']);
                if ($decodedButton['style'] !== null && $style === null) {
                    throw new InvalidArgumentException('Stored Telegram inline keyboard button style is invalid.');
                }
                $row[] = new TelegramInlineCallbackButton(
                    $decodedButton['text'],
                    $decodedButton['callback_public_id'],
                    $style,
                );
            }
            $rows[] = $row;
        }

        $snapshot = new self($rows);
        if (! hash_equals($snapshot->json(), $json)) {
            throw new InvalidArgumentException('Stored Telegram inline keyboard snapshot is not canonical.');
        }

        return $snapshot;
    }

    /** @return list<list<TelegramInlineCallbackButton>> */
    public function rows(): array
    {
        return $this->rows;
    }

    /** @return list<string> */
    public function callbackPublicIds(): array
    {
        $ids = [];
        foreach ($this->rows as $row) {
            foreach ($row as $button) {
                $ids[] = $button->callbackPublicId;
            }
        }

        return $ids;
    }

    public function json(): string
    {
        return $this->json;
    }

    public function hash(): string
    {
        return $this->hash;
    }

    /** @return array{redacted:true,type:string,buttons:int} */
    public function __debugInfo(): array
    {
        return [
            'redacted' => true,
            'type' => 'inline_keyboard_snapshot',
            'buttons' => count($this->callbackPublicIds()),
        ];
    }
}
