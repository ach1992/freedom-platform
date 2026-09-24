<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;

final readonly class TelegramMenuConfigurationAuthoringParser
{
    private const MAXIMUM_BYTES = 32_768;

    /** @var list<string> */
    private const ITEM_KEYS = [
        'action_key',
        'action_type',
        'active_from',
        'active_until',
        'audience',
        'copy_text',
        'enabled',
        'https_url',
        'https_url_purpose',
        'key',
        'kind',
        'label_en',
        'label_fa',
        'language',
        'normal_emoji',
        'order',
        'premium_emoji_id',
        'row',
        'style',
        'submenu_key',
    ];

    public function parse(string $json): TelegramMenuConfigurationAuthoringDraft
    {
        if ($json === '' || strlen($json) > self::MAXIMUM_BYTES) {
            throw new InvalidArgumentException('Telegram menu authoring payload is invalid.');
        }

        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Telegram menu authoring payload is invalid JSON.', 0, $exception);
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidArgumentException('Telegram menu authoring payload must be an object.');
        }
        $keys = array_keys($decoded);
        sort($keys, SORT_STRING);
        if ($keys !== ['items', 'menu_key', 'reason']) {
            throw new InvalidArgumentException('Telegram menu authoring payload has unsupported fields.');
        }

        $menuKey = $this->requiredString($decoded, 'menu_key');
        TelegramMenuItemDefinition::assertMenuKey($menuKey);

        $reason = $this->requiredString($decoded, 'reason');
        if (mb_strlen($reason) > 500
            || ! mb_check_encoding($reason, 'UTF-8')
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason) === 1) {
            throw new InvalidArgumentException('Telegram menu authoring reason is invalid.');
        }

        $items = $decoded['items'] ?? null;
        if (! is_array($items) || ! array_is_list($items)) {
            throw new InvalidArgumentException('Telegram menu authoring items must be a list.');
        }

        return new TelegramMenuConfigurationAuthoringDraft(
            $menuKey,
            $reason,
            new TelegramMenuConfigurationDefinition(array_map(
                fn (mixed $item): TelegramMenuItemDefinition => $this->item($item),
                $items,
            )),
        );
    }

    private function item(mixed $item): TelegramMenuItemDefinition
    {
        if (! is_array($item) || array_is_list($item)) {
            throw new InvalidArgumentException('Telegram menu authoring item must be an object.');
        }

        $keys = array_keys($item);
        foreach ($keys as $key) {
            if (! is_string($key) || ! in_array($key, self::ITEM_KEYS, true)) {
                throw new InvalidArgumentException('Telegram menu authoring item has unsupported fields.');
            }
        }

        $styleValue = $this->nullableString($item, 'style');
        $style = $styleValue === null ? null : TelegramInlineButtonStyle::tryFrom($styleValue);
        if ($styleValue !== null && $style === null) {
            throw new InvalidArgumentException('Telegram menu authoring style is invalid.');
        }

        $purposeValue = $this->nullableString($item, 'https_url_purpose');
        $purpose = $purposeValue === null ? null : TelegramInlineHttpsUrlPurpose::tryFrom($purposeValue);
        if ($purposeValue !== null && $purpose === null) {
            throw new InvalidArgumentException('Telegram menu authoring HTTPS purpose is invalid.');
        }

        return new TelegramMenuItemDefinition(
            key: $this->requiredString($item, 'key'),
            kind: $this->requiredString($item, 'kind'),
            actionType: $this->requiredString($item, 'action_type'),
            actionKey: $this->nullableString($item, 'action_key'),
            labelFa: $this->nullableString($item, 'label_fa'),
            labelEn: $this->nullableString($item, 'label_en'),
            normalEmoji: $this->nullableString($item, 'normal_emoji'),
            premiumEmojiId: $this->nullableString($item, 'premium_emoji_id'),
            style: $style,
            row: $this->requiredInt($item, 'row'),
            order: $this->requiredInt($item, 'order'),
            enabled: $this->optionalBool($item, 'enabled', true),
            language: $this->optionalString($item, 'language', 'any'),
            audience: $this->optionalString($item, 'audience', 'all'),
            activeFrom: $this->optionalTimestamp($item, 'active_from'),
            activeUntil: $this->optionalTimestamp($item, 'active_until'),
            httpsUrl: $this->nullableString($item, 'https_url'),
            httpsUrlPurpose: $purpose,
            copyText: $this->nullableString($item, 'copy_text'),
            submenuKey: $this->nullableString($item, 'submenu_key'),
        );
    }

    /** @param array<string,mixed> $values */
    private function requiredString(array $values, string $key): string
    {
        $value = $values[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('Telegram menu authoring string field is invalid.');
        }

        return $value;
    }

    /** @param array<string,mixed> $values */
    private function optionalString(array $values, string $key, string $default): string
    {
        if (! array_key_exists($key, $values)) {
            return $default;
        }

        return $this->requiredString($values, $key);
    }

    /** @param array<string,mixed> $values */
    private function nullableString(array $values, string $key): ?string
    {
        if (! array_key_exists($key, $values) || $values[$key] === null) {
            return null;
        }
        if (! is_string($values[$key])) {
            throw new InvalidArgumentException('Telegram menu authoring nullable string field is invalid.');
        }

        return $values[$key];
    }

    /** @param array<string,mixed> $values */
    private function requiredInt(array $values, string $key): int
    {
        $value = $values[$key] ?? null;
        if (! is_int($value)) {
            throw new InvalidArgumentException('Telegram menu authoring integer field is invalid.');
        }

        return $value;
    }

    /** @param array<string,mixed> $values */
    private function optionalBool(array $values, string $key, bool $default): bool
    {
        if (! array_key_exists($key, $values)) {
            return $default;
        }
        if (! is_bool($values[$key])) {
            throw new InvalidArgumentException('Telegram menu authoring boolean field is invalid.');
        }

        return $values[$key];
    }

    /** @param array<string,mixed> $values */
    private function optionalTimestamp(array $values, string $key): ?DateTimeImmutable
    {
        $value = $this->nullableString($values, $key);
        if ($value === null) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        if (! $parsed instanceof DateTimeImmutable || $parsed->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new InvalidArgumentException('Telegram menu authoring timestamp is invalid.');
        }

        return $parsed;
    }
}
