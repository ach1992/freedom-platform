<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Telegram;

use App\Modules\Telegram\Application\TelegramBroadcastAudienceDefinition;
use App\Modules\Telegram\Application\TelegramBroadcastMessageDefinition;
use App\Modules\Telegram\Application\TelegramInlineCallbackButton;
use App\Modules\Telegram\Application\TelegramInlineHttpsUrlButton;
use App\Modules\Telegram\Application\TelegramInlineHttpsUrlPurpose;
use App\Modules\Telegram\Application\TelegramInlineKeyboardSnapshot;
use App\Modules\Telegram\Domain\TelegramBroadcastCampaignState;
use App\Modules\Telegram\Domain\TelegramBroadcastSourceKind;
use DomainException;
use PHPUnit\Framework\TestCase;

final class TelegramBroadcastDomainTest extends TestCase
{
    public function test_campaign_state_machine_keeps_pause_after_start_and_terminal_states_closed(): void
    {
        self::assertTrue(TelegramBroadcastCampaignState::Draft->canTransitionTo(
            TelegramBroadcastCampaignState::Scheduled,
        ));
        self::assertTrue(TelegramBroadcastCampaignState::Draft->canTransitionTo(
            TelegramBroadcastCampaignState::Active,
        ));
        self::assertFalse(TelegramBroadcastCampaignState::Scheduled->canTransitionTo(
            TelegramBroadcastCampaignState::Paused,
        ));
        self::assertTrue(TelegramBroadcastCampaignState::Active->canTransitionTo(
            TelegramBroadcastCampaignState::Paused,
        ));
        self::assertTrue(TelegramBroadcastCampaignState::Paused->canTransitionTo(
            TelegramBroadcastCampaignState::Active,
        ));
        self::assertSame([], TelegramBroadcastCampaignState::Completed->allowedNextStates());
        self::assertSame([], TelegramBroadcastCampaignState::Cancelled->allowedNextStates());
    }

    public function test_audience_snapshot_is_canonical_and_order_independent(): void
    {
        $left = new TelegramBroadcastAudienceDefinition(
            accountTypes: ['agent', 'customer'],
            tierCodes: ['gold', 'silver'],
            tagCodes: ['vip', 'beta'],
            purchaseState: 'with_successful',
            offeringCodes: ['plus.monthly', 'prime.monthly'],
            categoryCodes: ['vpn', 'proxy'],
            serverCodes: ['de-1', 'nl-1'],
            serviceState: 'active',
            walletMinimumIrr: 100_000,
            walletMaximumIrr: 5_000_000,
            channelChatIds: [-100200, -100100],
            channelMembershipMode: 'all',
            channelMembershipState: 'member',
            manualUserPublicIds: [
                '01ARZ3NDEKTSV4RRFFQ69G5FAV',
                '01BX5ZZKBKACTAV9WEVGEMMVRZ',
            ],
        );
        $right = new TelegramBroadcastAudienceDefinition(
            accountTypes: ['customer', 'agent'],
            tierCodes: ['silver', 'gold'],
            tagCodes: ['beta', 'vip'],
            purchaseState: 'with_successful',
            offeringCodes: ['prime.monthly', 'plus.monthly'],
            categoryCodes: ['proxy', 'vpn'],
            serverCodes: ['nl-1', 'de-1'],
            serviceState: 'active',
            walletMinimumIrr: 100_000,
            walletMaximumIrr: 5_000_000,
            channelChatIds: [-100100, -100200],
            channelMembershipMode: 'all',
            channelMembershipState: 'member',
            manualUserPublicIds: [
                '01BX5ZZKBKACTAV9WEVGEMMVRZ',
                '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            ],
        );

        self::assertSame($left->json(), $right->json());
        self::assertSame($left->hash(), $right->hash());
        self::assertSame($left->json(), TelegramBroadcastAudienceDefinition::restore($left->json())->json());
    }

    public function test_audience_rejects_inverted_wallet_range(): void
    {
        $this->expectException(DomainException::class);

        new TelegramBroadcastAudienceDefinition(
            walletMinimumIrr: 2_000,
            walletMaximumIrr: 1_000,
        );
    }

    public function test_new_text_accepts_recipient_independent_safe_https_buttons(): void
    {
        $keyboard = new TelegramInlineKeyboardSnapshot([[
            new TelegramInlineHttpsUrlButton(
                'Support',
                'https://t.me/example_support',
                TelegramInlineHttpsUrlPurpose::SupportContact,
            ),
        ]]);

        $message = TelegramBroadcastMessageDefinition::newText('سلام', $keyboard);

        self::assertNotNull($message->inlineKeyboard);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $message->contentHash());
    }

    public function test_broadcast_rejects_recipient_bound_callback_buttons(): void
    {
        $keyboard = new TelegramInlineKeyboardSnapshot([[
            new TelegramInlineCallbackButton(
                'Continue',
                '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            ),
        ]]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('recipient-independent HTTPS URL buttons');

        TelegramBroadcastMessageDefinition::newText('سلام', $keyboard);
    }

    public function test_copy_media_allows_bounded_caption_override_but_text_copy_does_not(): void
    {
        $photo = TelegramBroadcastMessageDefinition::copy(
            123456789,
            77,
            TelegramBroadcastSourceKind::Photo,
            'کپشن جدید',
        );

        self::assertSame('کپشن جدید', $photo->captionOverride);

        $this->expectException(DomainException::class);
        TelegramBroadcastMessageDefinition::copy(
            123456789,
            78,
            TelegramBroadcastSourceKind::Text,
            'invalid',
        );
    }

    public function test_forward_preserves_source_kind_without_authored_keyboard_or_caption(): void
    {
        $message = TelegramBroadcastMessageDefinition::forward(
            123456789,
            91,
            TelegramBroadcastSourceKind::Document,
        );

        self::assertSame(TelegramBroadcastSourceKind::Document, $message->sourceKind);
        self::assertNull($message->captionOverride);
        self::assertNull($message->inlineKeyboard);
    }
}
