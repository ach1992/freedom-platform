<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application;

use App\Modules\Telegram\Application\Contracts\TelegramSupportOwnedOrderProjection;
use App\Modules\Telegram\Application\TelegramSupportBusinessReferenceListItem;
use App\Modules\Telegram\Application\TelegramSupportBusinessReferencePage;
use App\Modules\Telegram\Application\TelegramSupportBusinessReferenceResolution;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use RuntimeException;

final readonly class TelegramSupportOwnedOrderProjectionService implements TelegramSupportOwnedOrderProjection
{
    private const MAXIMUM_PAGE_SIZE = 6;

    public function __construct(private DatabaseManager $database) {}

    public function pageForSelf(int $actorUserId, int $subjectUserId, int $page, int $pageSize): TelegramSupportBusinessReferencePage
    {
        $this->assertSelf($actorUserId, $subjectUserId);
        $this->assertPage($page, $pageSize);

        $connection = $this->database->connection();
        $totalItems = (int) $connection->table('orders')->where('user_id', $subjectUserId)->count();
        $totalPages = max(1, (int) ceil($totalItems / $pageSize));
        $effectivePage = min($page, $totalPages);

        $rows = $connection->table('orders')
            ->where('user_id', $subjectUserId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->offset(($effectivePage - 1) * $pageSize)
            ->limit($pageSize)
            ->get(['public_id', 'total_amount_irr', 'currency']);

        $items = [];
        foreach ($rows as $row) {
            $publicId = $this->databaseString($row->public_id ?? null, 'Order public ID');
            $items[] = new TelegramSupportBusinessReferenceListItem(
                $this->selectionToken($subjectUserId, $publicId),
                $publicId,
                $this->nonNegativeDatabaseInt($row->total_amount_irr ?? null, 'Order amount'),
                $this->databaseString($row->currency ?? null, 'Order currency'),
            );
        }

        return new TelegramSupportBusinessReferencePage($items, $effectivePage, $totalPages, $totalItems);
    }

    public function resolveForSelf(int $actorUserId, int $subjectUserId, string $selectionToken): TelegramSupportBusinessReferenceResolution
    {
        $this->assertSelf($actorUserId, $subjectUserId);
        $this->assertSelectionToken($selectionToken);

        $row = $this->database->connection()->table('orders as order_row')
            ->where('order_row.user_id', $subjectUserId)
            ->whereRaw("LEFT(SHA2(CONCAT('telegram-support-owned-order-v1:', CAST(order_row.user_id AS CHAR), ':', order_row.public_id), 256), 40) = ?", [$selectionToken])
            ->first(['order_row.id', 'order_row.public_id']);

        if ($row === null) {
            throw new AuthorizationException('Telegram Support Order reference is unavailable for this actor.');
        }

        return new TelegramSupportBusinessReferenceResolution(
            $this->positiveDatabaseInt($row->id ?? null, 'Order ID'),
            $this->databaseString($row->public_id ?? null, 'Order public ID'),
        );
    }

    private function assertSelf(int $actorUserId, int $subjectUserId): void
    {
        if ($actorUserId < 1 || $subjectUserId < 1 || $actorUserId !== $subjectUserId) {
            throw new AuthorizationException('Telegram Support Order projection is self-only.');
        }
    }

    private function assertPage(int $page, int $pageSize): void
    {
        if ($page < 1 || $pageSize < 1 || $pageSize > self::MAXIMUM_PAGE_SIZE) {
            throw new InvalidArgumentException('Telegram Support Order page request is invalid.');
        }
    }

    private function assertSelectionToken(string $selectionToken): void
    {
        if (preg_match('/\A[0-9a-f]{40}\z/', $selectionToken) !== 1) {
            throw new AuthorizationException('Telegram Support Order reference is unavailable for this actor.');
        }
    }

    private function selectionToken(int $userId, string $publicId): string
    {
        return substr(hash('sha256', "telegram-support-owned-order-v1:{$userId}:{$publicId}"), 0, 40);
    }

    private function databaseString(mixed $value, string $label): string
    {
        if (! is_string($value) || $value === '' || ! mb_check_encoding($value, 'UTF-8')) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $value;
    }

    private function positiveDatabaseInt(mixed $value, string $label): int
    {
        $integer = $this->databaseInt($value, $label);
        if ($integer < 1) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $integer;
    }

    private function nonNegativeDatabaseInt(mixed $value, string $label): int
    {
        $integer = $this->databaseInt($value, $label);
        if ($integer < 0) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $integer;
    }

    private function databaseInt(mixed $value, string $label): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (! is_string($value) || preg_match('/\A\d+\z/', $value) !== 1 || strlen($value) > 19) {
            throw new RuntimeException($label.' is invalid.');
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($integer === false) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $integer;
    }
}
