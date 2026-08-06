<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\CustomPlanUsernameMode;
use DomainException;

final class CustomPlanUsernameNormalizer
{
    /**
     * @param list<string> $allowedSeparators
     * @param list<string> $reservedWords
     */
    public function normalize(
        CustomPlanUsernameMode $mode,
        ?int $telegramUserId,
        ?string $requestedUsername,
        string $commandKey,
        int $minimumLength,
        int $maximumLength,
        array $allowedSeparators,
        array $reservedWords,
    ): string {
        if ($mode !== CustomPlanUsernameMode::CustomerSelected && $telegramUserId === null) {
            throw new DomainException('Generated custom-plan username requires a Telegram identity.');
        }

        $username = match ($mode) {
            CustomPlanUsernameMode::TelegramUserId => 'u'.$telegramUserId,
            CustomPlanUsernameMode::TelegramUserIdSuffix => 'u'.$telegramUserId.'_'.substr(hash('sha256', $commandKey), 0, 8),
            CustomPlanUsernameMode::CustomerSelected => $requestedUsername
                ?? throw new DomainException('Customer-selected username is required.'),
        };
        $username = strtolower(trim($username));
        if (strlen($username) < $minimumLength || strlen($username) > $maximumLength) {
            throw new DomainException('Custom-plan username length is invalid.');
        }

        $escaped = implode('', array_map(static fn (string $separator): string => preg_quote($separator, '/'), $allowedSeparators));
        $pattern = '/\A[a-z0-9'.($escaped === '' ? '' : $escaped).']+\z/';
        if (preg_match($pattern, $username) !== 1) {
            throw new DomainException('Custom-plan username contains unsupported characters.');
        }
        if (in_array($username, $reservedWords, true)) {
            throw new DomainException('Custom-plan username is reserved.');
        }

        return $username;
    }
}
