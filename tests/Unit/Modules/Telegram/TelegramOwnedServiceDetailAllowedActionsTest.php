<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Telegram;

use App\Modules\Telegram\Application\TelegramOwnedServiceAction;
use App\Modules\Telegram\Application\TelegramOwnedServiceDetail;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TelegramOwnedServiceDetailAllowedActionsTest extends TestCase
{
    public function test_detail_accepts_only_typed_deterministic_action_order(): void
    {
        $detail = $this->detail([
            TelegramOwnedServiceAction::Renew,
            TelegramOwnedServiceAction::AddData,
            TelegramOwnedServiceAction::ResetUsage,
        ]);

        self::assertSame([
            TelegramOwnedServiceAction::Renew,
            TelegramOwnedServiceAction::AddData,
            TelegramOwnedServiceAction::ResetUsage,
        ], $detail->allowedActions);

        foreach ([
            ['renew'],
            [TelegramOwnedServiceAction::AddData, TelegramOwnedServiceAction::Renew],
            [TelegramOwnedServiceAction::Renew, TelegramOwnedServiceAction::Renew],
        ] as $invalidActions) {
            try {
                $this->detail($invalidActions);
                self::fail('Invalid allowed Service actions must fail closed.');
            } catch (InvalidArgumentException) {
                // Expected.
            }
        }
    }

    #[DataProvider('unavailableServiceProvider')]
    public function test_unavailable_service_cannot_expose_allowed_actions(string $lifecycle, ?string $provisionedAt): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->detail([TelegramOwnedServiceAction::Renew], $lifecycle, $provisionedAt);
    }

    /** @return iterable<string,array{string,?string}> */
    public static function unavailableServiceProvider(): iterable
    {
        yield 'retired' => ['retired', '2026-09-01 00:00:00.000000'];
        yield 'not provisioned' => ['active', null];
    }

    public function test_fa_and_en_localizations_cover_every_supported_action_and_detail_placeholder(): void
    {
        /** @var array<string,mixed> $en */
        $en = require dirname(__DIR__, 4).'/resources/lang/en/telegram.php';
        /** @var array<string,mixed> $fa */
        $fa = require dirname(__DIR__, 4).'/resources/lang/fa/telegram.php';

        foreach ([$en, $fa] as $catalog) {
            $services = $catalog['navigation']['services'] ?? null;
            self::assertIsArray($services);
            self::assertIsString($services['detail'] ?? null);
            self::assertStringContainsString(':allowed_actions', $services['detail']);
            self::assertIsString($services['allowed_actions_none'] ?? null);
            self::assertNotSame('', $services['allowed_actions_none']);
            self::assertIsString($services['allowed_actions_separator'] ?? null);

            $actions = $services['values']['action'] ?? null;
            self::assertIsArray($actions);
            foreach (TelegramOwnedServiceAction::ordered() as $action) {
                self::assertArrayHasKey($action->value, $actions);
                self::assertIsString($actions[$action->value]);
                self::assertNotSame('', $actions[$action->value]);
            }
        }
    }

    /** @param array<int,mixed> $actions */
    private function detail(
        array $actions,
        string $lifecycle = 'active',
        ?string $provisionedAt = '2026-09-01 00:00:00.000000',
    ): TelegramOwnedServiceDetail {
        return new TelegramOwnedServiceDetail(
            '01K40H3ZR9Q4XG9S3P2A7M8N5B',
            $lifecycle,
            'پلن تست',
            'Test plan',
            'سرور تست',
            'Test server',
            $provisionedAt,
            'none',
            null,
            null,
            null,
            null,
            null,
            null,
            $actions,
        );
    }
}
