<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Telegram;

use App\Modules\Telegram\Application\TelegramBroadcastAudienceParser;
use DomainException;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

final class TelegramBroadcastAudienceParserTest extends TestCase
{
    public function test_parser_accepts_all_as_unbounded_audience(): void
    {
        $audience = (new TelegramBroadcastAudienceParser())->parse('all');

        self::assertTrue($audience->isUnbounded());
    }

    public function test_parser_maps_all_supported_filter_dimensions(): void
    {
        $audience = (new TelegramBroadcastAudienceParser())->parse(<<<'FILTERS'
accounts=customer,agent
tiers=gold,silver
tags=vip,beta
purchase=with_successful
offerings=prime.monthly,plus.monthly
categories=vpn
servers=de-1,nl-1
service=active
wallet_min=100000
wallet_max=5000000
channels=-100123,-100456
channel_mode=all
channel_state=member
manual=01ARZ3NDEKTSV4RRFFQ69G5FAV
FILTERS);

        self::assertSame(['agent', 'customer'], $audience->snapshot()['account_types']);
        self::assertSame(['gold', 'silver'], $audience->snapshot()['tier_codes']);
        self::assertSame(['beta', 'vip'], $audience->snapshot()['tag_codes']);
        self::assertSame('with_successful', $audience->purchaseState);
        self::assertSame(100000, $audience->walletMinimumIrr);
        self::assertSame(5000000, $audience->walletMaximumIrr);
        self::assertSame([-100456, -100123], $audience->snapshot()['channel_chat_ids']);
        self::assertSame(['01ARZ3NDEKTSV4RRFFQ69G5FAV'], $audience->manualUserPublicIds);
    }

    public function test_parser_rejects_unknown_and_duplicate_keys(): void
    {
        $parser = new TelegramBroadcastAudienceParser();

        try {
            $parser->parse("unknown=value");
            self::fail('Unknown broadcast audience key should fail.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('not supported', $exception->getMessage());
        }

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('duplicated');
        $parser->parse("accounts=customer\naccounts=agent");
    }

    public function test_broadcast_localization_has_persian_default_and_english_copy(): void
    {
        self::assertSame('مدیریت ارسال همگانی', Lang::get('telegram.broadcast.title', [], 'fa'));
        self::assertSame('Broadcast management', Lang::get('telegram.broadcast.title', [], 'en'));
        self::assertSame('ارسال همگانی', Lang::get('telegram.broadcast.menu', [], 'fa'));
        self::assertSame('Broadcasts', Lang::get('telegram.broadcast.menu', [], 'en'));
    }
}
