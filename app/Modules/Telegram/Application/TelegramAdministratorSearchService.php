<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Application\Contracts\TelegramAdministratorSearchSource;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

final readonly class TelegramAdministratorSearchService
{
    private const MAXIMUM_QUERY_BYTES = 191;

    private const MAXIMUM_RESULTS = 12;

    /** @var list<TelegramAdministratorSearchSource> */
    private array $sources;

    /**
     * @param iterable<TelegramAdministratorSearchSource> $sources
     */
    public function __construct(iterable $sources)
    {
        $normalized = [];
        foreach ($sources as $source) {
            if (! $source instanceof TelegramAdministratorSearchSource) {
                throw new InvalidArgumentException('Telegram administrator search source is invalid.');
            }
            $normalized[] = $source;
        }
        if ($normalized === []) {
            throw new InvalidArgumentException('Telegram administrator search requires at least one source.');
        }

        $this->sources = $normalized;
    }

    public function availableFor(int $actorUserId): bool
    {
        if ($actorUserId < 1) {
            return false;
        }

        foreach ($this->sources as $source) {
            if ($source->availableFor($actorUserId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<TelegramAdministratorSearchItem>
     *
     * @requirement ADM-001 ACL-001 ACL-002 SEC-002 SEC-003 DAT-003 QUA-001
     */
    public function search(
        int $actorUserId,
        string $botId,
        string $query,
    ): array {
        if ($actorUserId < 1) {
            throw new AuthorizationException('Telegram administrator search access denied.');
        }
        if (preg_match('/\A[1-9][0-9]{0,19}\z/', $botId) !== 1) {
            throw new InvalidArgumentException('Telegram administrator search bot ID is invalid.');
        }

        $normalizedQuery = trim($query);
        if ($normalizedQuery === ''
            || strlen($normalizedQuery) > self::MAXIMUM_QUERY_BYTES
            || ! mb_check_encoding($normalizedQuery, 'UTF-8')
            || str_contains($normalizedQuery, "\0")
        ) {
            return [];
        }

        /** @var array<string,TelegramAdministratorSearchItem> $items */
        $items = [];
        foreach ($this->sources as $source) {
            if (! $source->availableFor($actorUserId)) {
                continue;
            }

            try {
                $sourceItems = $source->search($actorUserId, $botId, $normalizedQuery);
            } catch (AuthorizationException) {
                // Authorization is re-checked inside each source. If it changed
                // between menu rendering and execution, that source fails closed.
                continue;
            }

            foreach ($sourceItems as $item) {
                $items[$item->identityKey()] = $item;
            }
        }

        $kindOrder = array_flip([
            'user',
            'order',
            'payment_intent',
            'purchase_settlement',
            'provider_transaction',
            'card_receipt',
            'gift_submission',
            'service',
        ]);
        $result = array_values($items);
        usort(
            $result,
            static function (TelegramAdministratorSearchItem $left, TelegramAdministratorSearchItem $right) use ($kindOrder): int {
                $kind = ($kindOrder[$left->kind] ?? PHP_INT_MAX) <=> ($kindOrder[$right->kind] ?? PHP_INT_MAX);

                return $kind !== 0
                    ? $kind
                    : [$left->publicId, $left->reference ?? ''] <=> [$right->publicId, $right->reference ?? ''];
            },
        );

        return array_slice($result, 0, self::MAXIMUM_RESULTS);
    }
}
