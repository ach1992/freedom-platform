<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class TelegramMenuItemDefinition
{
    public const KIND_SYSTEM = 'system';

    public const KIND_CUSTOM = 'custom';

    public const ACTION_REGISTERED = 'registered';

    public const ACTION_HTTPS_URL = 'https_url';

    public const ACTION_COPY_TEXT = 'copy_text';

    public const ACTION_SUBMENU = 'submenu';

    public function __construct(
        public string $key,
        public string $kind,
        public string $actionType,
        public ?string $actionKey,
        public ?string $labelFa,
        public ?string $labelEn,
        public ?string $normalEmoji,
        public ?string $premiumEmojiId,
        public ?TelegramInlineButtonStyle $style,
        public int $row,
        public int $order,
        public bool $enabled = true,
        public string $language = 'any',
        public string $audience = 'all',
        public ?DateTimeImmutable $activeFrom = null,
        public ?DateTimeImmutable $activeUntil = null,
        public ?string $httpsUrl = null,
        public ?TelegramInlineHttpsUrlPurpose $httpsUrlPurpose = null,
        public ?string $copyText = null,
        public ?string $submenuKey = null,
    ) {
        $this->assertToken($key, 'Telegram menu item key', 64);
        if (! in_array($kind, [self::KIND_SYSTEM, self::KIND_CUSTOM], true)) {
            throw new InvalidArgumentException('Telegram menu item kind is invalid.');
        }
        if (! in_array($actionType, [
            self::ACTION_REGISTERED,
            self::ACTION_HTTPS_URL,
            self::ACTION_COPY_TEXT,
            self::ACTION_SUBMENU,
        ], true)) {
            throw new InvalidArgumentException('Telegram menu item action type is invalid.');
        }
        if ($row < 0 || $row > 9 || $order < 0 || $order > 7) {
            throw new InvalidArgumentException('Telegram menu item row/order is invalid.');
        }
        if (! in_array($language, ['any', 'fa', 'en'], true)) {
            throw new InvalidArgumentException('Telegram menu item language is invalid.');
        }
        if (! in_array($audience, ['all', 'customers', 'agents', 'administrators'], true)) {
            throw new InvalidArgumentException('Telegram menu item audience is invalid.');
        }

        $this->assertText($labelFa, 'Telegram menu Persian label', 64);
        $this->assertText($labelEn, 'Telegram menu English label', 64);
        if ($kind === self::KIND_CUSTOM && $labelFa === null) {
            throw new InvalidArgumentException('Custom Telegram menu item requires a Persian label.');
        }
        $this->assertText($normalEmoji, 'Telegram menu normal emoji', 8);
        if ($premiumEmojiId !== null && preg_match('/\A[0-9]{1,64}\z/', $premiumEmojiId) !== 1) {
            throw new InvalidArgumentException('Telegram menu premium emoji ID is invalid.');
        }
        if ($premiumEmojiId !== null && $normalEmoji === null) {
            throw new InvalidArgumentException('Telegram menu premium emoji requires a normal emoji fallback.');
        }

        if ($activeFrom !== null && $activeUntil !== null && $activeUntil <= $activeFrom) {
            throw new InvalidArgumentException('Telegram menu active range is invalid.');
        }

        $this->assertActionShape();
    }

    /** @return array<string, bool|int|string|null> */
    public function payload(): array
    {
        return [
            'action_key' => $this->actionKey,
            'action_type' => $this->actionType,
            'active_from' => $this->utc($this->activeFrom),
            'active_until' => $this->utc($this->activeUntil),
            'audience' => $this->audience,
            'copy_text' => $this->copyText,
            'enabled' => $this->enabled,
            'https_url' => $this->httpsUrl,
            'https_url_purpose' => $this->httpsUrlPurpose?->value,
            'key' => $this->key,
            'kind' => $this->kind,
            'label_en' => $this->labelEn,
            'label_fa' => $this->labelFa,
            'language' => $this->language,
            'normal_emoji' => $this->normalEmoji,
            'order' => $this->order,
            'premium_emoji_id' => $this->premiumEmojiId,
            'row' => $this->row,
            'style' => $this->style?->value,
            'submenu_key' => $this->submenuKey,
        ];
    }

    /** @param array<string,mixed> $payload */
    public static function restore(array $payload): self
    {
        $expected = [
            'action_key', 'action_type', 'active_from', 'active_until', 'audience',
            'copy_text', 'enabled', 'https_url', 'https_url_purpose', 'key', 'kind',
            'label_en', 'label_fa', 'language', 'normal_emoji', 'order',
            'premium_emoji_id', 'row', 'style', 'submenu_key',
        ];
        if (array_keys($payload) !== $expected) {
            throw new InvalidArgumentException('Stored Telegram menu item shape is invalid.');
        }

        foreach ([
            'action_key', 'active_from', 'active_until', 'copy_text', 'https_url',
            'https_url_purpose', 'label_en', 'label_fa', 'normal_emoji',
            'premium_emoji_id', 'style', 'submenu_key',
        ] as $nullableString) {
            if ($payload[$nullableString] !== null && ! is_string($payload[$nullableString])) {
                throw new InvalidArgumentException('Stored Telegram menu item value is invalid.');
            }
        }
        foreach (['action_type', 'audience', 'key', 'kind', 'language'] as $requiredString) {
            if (! is_string($payload[$requiredString])) {
                throw new InvalidArgumentException('Stored Telegram menu item value is invalid.');
            }
        }
        if (! is_bool($payload['enabled']) || ! is_int($payload['row']) || ! is_int($payload['order'])) {
            throw new InvalidArgumentException('Stored Telegram menu item value is invalid.');
        }

        $style = $payload['style'] === null ? null : TelegramInlineButtonStyle::tryFrom($payload['style']);
        $purpose = $payload['https_url_purpose'] === null
            ? null
            : TelegramInlineHttpsUrlPurpose::tryFrom($payload['https_url_purpose']);
        if (($payload['style'] !== null && $style === null)
            || ($payload['https_url_purpose'] !== null && $purpose === null)) {
            throw new InvalidArgumentException('Stored Telegram menu item enum is invalid.');
        }

        return new self(
            $payload['key'],
            $payload['kind'],
            $payload['action_type'],
            $payload['action_key'],
            $payload['label_fa'],
            $payload['label_en'],
            $payload['normal_emoji'],
            $payload['premium_emoji_id'],
            $style,
            $payload['row'],
            $payload['order'],
            $payload['enabled'],
            $payload['language'],
            $payload['audience'],
            self::parseUtc($payload['active_from']),
            self::parseUtc($payload['active_until']),
            $payload['https_url'],
            $purpose,
            $payload['copy_text'],
            $payload['submenu_key'],
        );
    }

    private function assertActionShape(): void
    {
        $populated = array_filter([
            'action_key' => $this->actionKey,
            'https_url' => $this->httpsUrl,
            'https_url_purpose' => $this->httpsUrlPurpose?->value,
            'copy_text' => $this->copyText,
            'submenu_key' => $this->submenuKey,
        ], static fn (?string $value): bool => $value !== null);

        if ($this->actionType === self::ACTION_REGISTERED) {
            if ($this->actionKey === null || array_keys($populated) !== ['action_key']) {
                throw new InvalidArgumentException('Registered Telegram menu action shape is invalid.');
            }
            $this->assertToken($this->actionKey, 'Telegram menu registered action', 64);
            if (TelegramMenuRegisteredAction::tryFrom($this->actionKey) === null) {
                throw new InvalidArgumentException('Telegram menu registered action is not allowlisted.');
            }

            return;
        }

        if ($this->actionType === self::ACTION_HTTPS_URL) {
            if ($this->httpsUrl === null
                || $this->httpsUrlPurpose === null
                || array_keys($populated) !== ['https_url', 'https_url_purpose']) {
                throw new InvalidArgumentException('Telegram menu HTTPS action shape is invalid.');
            }
            if (! in_array($this->httpsUrlPurpose, [
                TelegramInlineHttpsUrlPurpose::SupportContact,
                TelegramInlineHttpsUrlPurpose::ClientGuideResource,
            ], true)) {
                throw new InvalidArgumentException('Telegram menu HTTPS purpose is not allowed.');
            }
            TelegramInlineHttpsUrlPolicy::assertAllowed($this->httpsUrl, $this->httpsUrlPurpose);

            return;
        }

        if ($this->actionType === self::ACTION_COPY_TEXT) {
            if ($this->copyText === null || array_keys($populated) !== ['copy_text']) {
                throw new InvalidArgumentException('Telegram menu copy-text action shape is invalid.');
            }
            new TelegramInlineCopyTextButton(
                $this->labelFa ?? 'Copy',
                $this->copyText,
                $this->style,
                $this->premiumEmojiId,
            );

            return;
        }

        if ($this->submenuKey === null || array_keys($populated) !== ['submenu_key']) {
            throw new InvalidArgumentException('Telegram menu submenu action shape is invalid.');
        }
        self::assertMenuKey($this->submenuKey);
    }

    public static function assertMenuKey(string $menuKey): void
    {
        if ($menuKey !== 'home' && preg_match('/\Asubmenu\.[a-z0-9][a-z0-9_.-]{0,54}\z/', $menuKey) !== 1) {
            throw new InvalidArgumentException('Telegram menu key is invalid.');
        }
    }

    private function assertToken(string $value, string $label, int $maximum): void
    {
        if (strlen($value) > $maximum || preg_match('/\A[a-z0-9][a-z0-9_.-]*\z/', $value) !== 1) {
            throw new InvalidArgumentException($label.' is invalid.');
        }
    }

    private function assertText(?string $value, string $label, int $maximum): void
    {
        if ($value === null) {
            return;
        }
        if (trim($value) === ''
            || mb_strlen($value) > $maximum
            || ! mb_check_encoding($value, 'UTF-8')
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException($label.' is invalid.');
        }
    }

    private function utc(?DateTimeImmutable $value): ?string
    {
        return $value?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private static function parseUtc(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        if (! $parsed instanceof DateTimeImmutable || $parsed->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new InvalidArgumentException('Stored Telegram menu active timestamp is invalid.');
        }

        return $parsed;
    }
}
