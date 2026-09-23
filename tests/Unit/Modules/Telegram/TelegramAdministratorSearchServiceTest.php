<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Telegram;

use App\Modules\Telegram\Application\Contracts\TelegramAdministratorSearchSource;
use App\Modules\Telegram\Application\TelegramAdministratorSearchItem;
use App\Modules\Telegram\Application\TelegramAdministratorSearchService;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TelegramAdministratorSearchServiceTest extends TestCase
{
    public function test_search_is_permission_filtered_deduplicated_sorted_and_bounded(): void
    {
        $allowed = new class implements TelegramAdministratorSearchSource
        {
            public int $calls = 0;

            public function availableFor(int $actorUserId): bool
            {
                return $actorUserId === 7;
            }

            public function search(int $actorUserId, string $botId, string $query): array
            {
                $this->calls++;

                return [
                    new TelegramAdministratorSearchItem('service', '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'active'),
                    new TelegramAdministratorSearchItem('user', '01ARZ3NDEKTSV4RRFFQ69G5FAW', 'customer:active'),
                    new TelegramAdministratorSearchItem('user', '01ARZ3NDEKTSV4RRFFQ69G5FAW', 'customer:active'),
                ];
            }
        };
        $denied = new class implements TelegramAdministratorSearchSource
        {
            public int $calls = 0;

            public function availableFor(int $actorUserId): bool
            {
                return false;
            }

            public function search(int $actorUserId, string $botId, string $query): array
            {
                $this->calls++;

                return [new TelegramAdministratorSearchItem(
                    'payment_intent',
                    '01ARZ3NDEKTSV4RRFFQ69G5FAX',
                    'captured',
                )];
            }
        };

        $service = new TelegramAdministratorSearchService([$allowed, $denied]);
        $items = $service->search(7, '123456789', '01ARZ3NDEKTSV4RRFFQ69G5FAW');

        self::assertSame(1, $allowed->calls);
        self::assertSame(0, $denied->calls);
        self::assertCount(2, $items);
        self::assertSame('user', $items[0]->kind);
        self::assertSame('service', $items[1]->kind);
    }

    public function test_authorization_loss_in_one_source_fails_closed_without_exposing_its_results(): void
    {
        $lost = new class implements TelegramAdministratorSearchSource
        {
            public function availableFor(int $actorUserId): bool
            {
                return true;
            }

            public function search(int $actorUserId, string $botId, string $query): array
            {
                throw new AuthorizationException('changed');
            }
        };
        $allowed = new class implements TelegramAdministratorSearchSource
        {
            public function availableFor(int $actorUserId): bool
            {
                return true;
            }

            public function search(int $actorUserId, string $botId, string $query): array
            {
                return [new TelegramAdministratorSearchItem(
                    'order',
                    '01ARZ3NDEKTSV4RRFFQ69G5FAY',
                    'awaiting_payment',
                )];
            }
        };

        $items = (new TelegramAdministratorSearchService([$lost, $allowed]))
            ->search(9, '123456789', '01ARZ3NDEKTSV4RRFFQ69G5FAY');

        self::assertCount(1, $items);
        self::assertSame('order', $items[0]->kind);
    }

    public function test_invalid_actor_bot_or_query_fail_closed(): void
    {
        $source = new class implements TelegramAdministratorSearchSource
        {
            public function availableFor(int $actorUserId): bool
            {
                return true;
            }

            public function search(int $actorUserId, string $botId, string $query): array
            {
                return [];
            }
        };
        $service = new TelegramAdministratorSearchService([$source]);

        self::assertFalse($service->availableFor(0));
        self::assertSame([], $service->search(1, '123456789', str_repeat('x', 192)));

        $this->expectException(InvalidArgumentException::class);
        $service->search(1, 'not-a-bot', 'value');
    }

    public function test_constructor_requires_at_least_one_valid_source(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TelegramAdministratorSearchService([]);
    }
}
