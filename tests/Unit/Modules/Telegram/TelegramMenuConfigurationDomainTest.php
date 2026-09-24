<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Telegram;

use App\Modules\Telegram\Application\TelegramInlineButtonStyle;
use App\Modules\Telegram\Application\TelegramInlineCopyTextButton;
use App\Modules\Telegram\Application\TelegramInlineHttpsUrlPurpose;
use App\Modules\Telegram\Application\TelegramInlineKeyboardSnapshot;
use App\Modules\Telegram\Application\TelegramMenuConfigurationDefinition;
use App\Modules\Telegram\Application\TelegramMenuItemDefinition;
use App\Modules\Telegram\Application\TelegramResolvedInlineKeyboardMarkup;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TelegramMenuConfigurationDomainTest extends TestCase
{
    public function test_definition_is_canonical_and_preserves_normal_fallback_with_premium_emoji(): void
    {
        $definition = new TelegramMenuConfigurationDefinition([
            $this->system('my_services', 1),
            new TelegramMenuItemDefinition(
                key: 'copy_support_code',
                kind: TelegramMenuItemDefinition::KIND_CUSTOM,
                actionType: TelegramMenuItemDefinition::ACTION_COPY_TEXT,
                actionKey: null,
                labelFa: 'کپی کد پشتیبانی',
                labelEn: 'Copy support code',
                normalEmoji: '📋',
                premiumEmojiId: '5368324170671202286',
                style: TelegramInlineButtonStyle::Success,
                row: 2,
                order: 0,
                copyText: 'SUPPORT-HELP',
            ),
            $this->system('my_account', 0),
        ]);

        $restored = TelegramMenuConfigurationDefinition::restore($definition->json());

        self::assertSame($definition->json(), $restored->json());
        self::assertSame($definition->hash(), $restored->hash());
        self::assertSame(['my_account', 'my_services', 'copy_support_code'], array_map(
            static fn (TelegramMenuItemDefinition $item): string => $item->key,
            $restored->items,
        ));

        $keyboard = new TelegramInlineKeyboardSnapshot([[
            new TelegramInlineCopyTextButton(
                '📋 Copy support code',
                'SUPPORT-HELP',
                TelegramInlineButtonStyle::Success,
                '5368324170671202286',
            ),
        ]]);
        self::assertSame($keyboard->json(), TelegramInlineKeyboardSnapshot::restore($keyboard->json())->json());
        self::assertSame([
            'inline_keyboard' => [[[
                'text' => '📋 Copy support code',
                'copy_text' => ['text' => 'SUPPORT-HELP'],
                'style' => 'success',
                'icon_custom_emoji_id' => '5368324170671202286',
            ]]],
        ], TelegramResolvedInlineKeyboardMarkup::resolve($keyboard, [])->providerPayload());
    }

    public function test_legacy_callback_snapshot_remains_byte_compatible(): void
    {
        $legacyJson = '{"rows":[[{"text":"Choose","callback_public_id":"01ARZ3NDEKTSV4RRFFQ69G5FAV","style":"primary"}]]}';

        self::assertSame($legacyJson, TelegramInlineKeyboardSnapshot::restore($legacyJson)->json());
    }

    public function test_custom_action_validation_fails_closed(): void
    {
        foreach ([
            fn () => new TelegramMenuItemDefinition(
                key: 'bad_callback',
                kind: TelegramMenuItemDefinition::KIND_CUSTOM,
                actionType: TelegramMenuItemDefinition::ACTION_REGISTERED,
                actionKey: 'arbitrary.callback.name',
                labelFa: 'نامعتبر',
                labelEn: null,
                normalEmoji: null,
                premiumEmojiId: null,
                style: null,
                row: 0,
                order: 0,
            ),
            fn () => new TelegramMenuItemDefinition(
                key: 'premium_without_fallback',
                kind: TelegramMenuItemDefinition::KIND_CUSTOM,
                actionType: TelegramMenuItemDefinition::ACTION_COPY_TEXT,
                actionKey: null,
                labelFa: 'کپی',
                labelEn: null,
                normalEmoji: null,
                premiumEmojiId: '123456789',
                style: null,
                row: 0,
                order: 0,
                copyText: 'safe',
            ),
            fn () => new TelegramMenuItemDefinition(
                key: 'unsafe_url',
                kind: TelegramMenuItemDefinition::KIND_CUSTOM,
                actionType: TelegramMenuItemDefinition::ACTION_HTTPS_URL,
                actionKey: null,
                labelFa: 'لینک',
                labelEn: null,
                normalEmoji: null,
                premiumEmojiId: null,
                style: null,
                row: 0,
                order: 0,
                httpsUrl: 'http://example.com',
                httpsUrlPurpose: TelegramInlineHttpsUrlPurpose::ClientGuideResource,
            ),
        ] as $factory) {
            try {
                $factory();
                self::fail('Unsafe menu definition must fail closed.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_active_range_must_be_forward_only(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TelegramMenuItemDefinition(
            key: 'copy',
            kind: TelegramMenuItemDefinition::KIND_CUSTOM,
            actionType: TelegramMenuItemDefinition::ACTION_COPY_TEXT,
            actionKey: null,
            labelFa: 'کپی',
            labelEn: null,
            normalEmoji: null,
            premiumEmojiId: null,
            style: null,
            row: 0,
            order: 0,
            activeFrom: new DateTimeImmutable('2026-09-25T00:00:00Z'),
            activeUntil: new DateTimeImmutable('2026-09-24T00:00:00Z'),
            copyText: 'safe',
        );
    }

    private function system(string $key, int $row): TelegramMenuItemDefinition
    {
        return new TelegramMenuItemDefinition(
            key: $key,
            kind: TelegramMenuItemDefinition::KIND_SYSTEM,
            actionType: TelegramMenuItemDefinition::ACTION_REGISTERED,
            actionKey: $key,
            labelFa: null,
            labelEn: null,
            normalEmoji: null,
            premiumEmojiId: null,
            style: TelegramInlineButtonStyle::Primary,
            row: $row,
            order: 0,
        );
    }
}
