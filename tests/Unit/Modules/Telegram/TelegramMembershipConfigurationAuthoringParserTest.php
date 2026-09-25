<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Telegram;

use App\Modules\Telegram\Application\TelegramMembershipConfigurationAuthoringParser;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** @requirement CHN-001 ACL-002 SEC-001 QUA-004 */
final class TelegramMembershipConfigurationAuthoringParserTest extends TestCase
{
    public function test_channel_create_and_update_parse_into_canonical_secret_bearing_definition(): void
    {
        $parser = new TelegramMembershipConfigurationAuthoringParser;
        $create = $parser->parse(implode("\n", [
            'operation=channel.create',
            'channel_key=Main_Channel',
            'chat_id=-1001234567890',
            'chat_type=channel',
            'visibility=private',
            'title=Main Private Channel',
            'join_url=https://t.me/+SecretInviteToken01',
            'sort_order=20',
            'reason=Initial required channel',
        ]));

        self::assertSame('channel.create', $create->operation);
        self::assertNull($create->targetId);
        self::assertNull($create->expectedVersion);
        self::assertNotNull($create->channel);
        self::assertSame('main_channel', $create->channel->channelKey);
        self::assertSame(-1001234567890, $create->channel->telegramChatId);
        self::assertSame('https://t.me/+SecretInviteToken01', $create->channel->joinUrl);
        self::assertNull($create->rule);

        $update = $parser->parse(implode("\n", [
            'operation=channel.update',
            'id=9',
            'version=3',
            'channel_key=main_channel',
            'chat_id=-1001234567890',
            'chat_type=channel',
            'visibility=public',
            'title=Main Public Channel',
            'join_url=https://t.me/MainPublicChannel',
            'sort_order=30',
            'reason=Rotate the membership destination',
        ]));

        self::assertSame(9, $update->targetId);
        self::assertSame(3, $update->expectedVersion);
        self::assertSame('public', $update->channel?->visibility);
        self::assertSame('https://t.me/MainPublicChannel', $update->channel?->joinUrl);
    }

    public function test_rule_create_parses_nullable_selectors_explicit_timezone_and_ordered_channels(): void
    {
        $command = (new TelegramMembershipConfigurationAuthoringParser)->parse(implode("\n", [
            'operation=rule.create',
            'rule_key=customer_service_view',
            'action=service_view',
            'audience=customers',
            'tier_code=normal',
            'customer_tag_id=-',
            'plan_offering_id=-',
            'match_mode=any',
            'failure_policy=fail_open',
            'priority=120',
            'effective_from=2026-09-25T08:00:00+03:30',
            'effective_until=2026-10-25T08:00:00+03:30',
            'channel_ids=7,4',
            'reason=Protect paid-service views',
        ]));

        self::assertSame('rule.create', $command->operation);
        self::assertNull($command->channel);
        self::assertNotNull($command->rule);
        self::assertSame('service_view', $command->rule->action);
        self::assertSame('normal', $command->rule->tierCode);
        self::assertNull($command->rule->customerTagId);
        self::assertSame([7, 4], $command->rule->requiredChannelIds);
        self::assertSame(
            (new DateTimeImmutable('2026-09-25T08:00:00+03:30'))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
            $command->rule->effectiveFromUtc,
        );
    }

    public function test_identity_mutations_require_positive_id_version_and_no_extra_fields(): void
    {
        $parser = new TelegramMembershipConfigurationAuthoringParser;
        $command = $parser->parse(implode("\n", [
            'operation=rule.disable',
            'id=12',
            'version=4',
            'reason=Retire obsolete policy',
        ]));

        self::assertSame('rule.disable', $command->operation);
        self::assertSame(12, $command->targetId);
        self::assertSame(4, $command->expectedVersion);

        $this->expectException(InvalidArgumentException::class);
        $parser->parse(implode("\n", [
            'operation=rule.disable',
            'id=12',
            'version=4',
            'channel_ids=1',
            'reason=Unexpected field must fail',
        ]));
    }

    #[DataProvider('invalidInputProvider')]
    public function test_invalid_or_ambiguous_authoring_input_fails_closed(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new TelegramMembershipConfigurationAuthoringParser)->parse($input);
    }

    /** @return iterable<string,array{string}> */
    public static function invalidInputProvider(): iterable
    {
        yield 'duplicate key' => ["operation=rule.activate\nid=1\nid=2\nversion=1\nreason=duplicate"];
        yield 'implicit timezone' => [implode("\n", [
            'operation=rule.create',
            'rule_key=time_rule',
            'action=gift_code_use',
            'audience=customers',
            'tier_code=-',
            'customer_tag_id=-',
            'plan_offering_id=-',
            'match_mode=all',
            'failure_policy=fail_closed',
            'priority=10',
            'effective_from=2026-09-25T08:00:00',
            'effective_until=-',
            'channel_ids=1',
            'reason=timezone required',
        ])];
        yield 'duplicate channels' => [implode("\n", [
            'operation=rule.create',
            'rule_key=duplicate_channels',
            'action=service_view',
            'audience=customers',
            'tier_code=-',
            'customer_tag_id=-',
            'plan_offering_id=-',
            'match_mode=all',
            'failure_policy=fail_open',
            'priority=10',
            'effective_from=-',
            'effective_until=-',
            'channel_ids=1,1',
            'reason=duplicates rejected',
        ])];
        yield 'non-negative telegram chat id' => [implode("\n", [
            'operation=channel.create',
            'channel_key=invalid_chat',
            'chat_id=100',
            'chat_type=channel',
            'visibility=public',
            'title=Invalid Channel',
            'join_url=https://t.me/InvalidChannel',
            'sort_order=0',
            'reason=invalid id',
        ])];
    }
}
