<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;
use JsonException;

final readonly class TelegramMenuConfigurationDefinition
{
    private const MAXIMUM_ITEMS = 64;

    private const MAXIMUM_BYTES = 32_768;

    /** @var list<TelegramMenuItemDefinition> */
    public array $items;

    private string $json;

    private string $hash;

    /** @param list<TelegramMenuItemDefinition> $items */
    public function __construct(array $items)
    {
        if ($items === [] || count($items) > self::MAXIMUM_ITEMS) {
            throw new InvalidArgumentException('Telegram menu configuration must contain 1-64 items.');
        }

        $byKey = [];
        $byPosition = [];
        foreach ($items as $item) {
            if (! $item instanceof TelegramMenuItemDefinition) {
                throw new InvalidArgumentException('Telegram menu configuration contains an invalid item.');
            }
            if (isset($byKey[$item->key])) {
                throw new InvalidArgumentException('Telegram menu item keys must be unique.');
            }
            $position = $item->row.':'.$item->order;
            if (isset($byPosition[$position])) {
                throw new InvalidArgumentException('Telegram menu row/order positions must be unique.');
            }
            $byKey[$item->key] = true;
            $byPosition[$position] = true;
        }

        usort($items, static fn (TelegramMenuItemDefinition $left, TelegramMenuItemDefinition $right): int => [$left->row, $left->order, $left->key] <=> [$right->row, $right->order, $right->key]);

        try {
            $json = json_encode(
                ['items' => array_map(
                    static fn (TelegramMenuItemDefinition $item): array => $item->payload(),
                    $items,
                )],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Telegram menu configuration could not be encoded.', 0, $exception);
        }
        if (strlen($json) > self::MAXIMUM_BYTES) {
            throw new InvalidArgumentException('Telegram menu configuration exceeds 32 KiB.');
        }

        $this->items = $items;
        $this->json = $json;
        $this->hash = hash('sha256', $json);
    }

    public static function restore(string $json): self
    {
        if ($json === '' || strlen($json) > self::MAXIMUM_BYTES) {
            throw new InvalidArgumentException('Stored Telegram menu configuration is invalid.');
        }
        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Stored Telegram menu configuration is invalid.', 0, $exception);
        }
        if (! is_array($decoded) || array_is_list($decoded) || array_keys($decoded) !== ['items']) {
            throw new InvalidArgumentException('Stored Telegram menu configuration shape is invalid.');
        }
        $items = $decoded['items'];
        if (! is_array($items) || ! array_is_list($items)) {
            throw new InvalidArgumentException('Stored Telegram menu configuration items are invalid.');
        }

        $definition = new self(array_map(
            static function (mixed $item): TelegramMenuItemDefinition {
                if (! is_array($item) || array_is_list($item)) {
                    throw new InvalidArgumentException('Stored Telegram menu configuration item is invalid.');
                }

                return TelegramMenuItemDefinition::restore($item);
            },
            $items,
        ));
        if (! hash_equals($definition->json(), $json)) {
            throw new InvalidArgumentException('Stored Telegram menu configuration is not canonical.');
        }

        return $definition;
    }

    public function json(): string
    {
        return $this->json;
    }

    public function hash(): string
    {
        return $this->hash;
    }
}
