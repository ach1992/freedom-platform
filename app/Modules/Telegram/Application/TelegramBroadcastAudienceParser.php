<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use DomainException;

final readonly class TelegramBroadcastAudienceParser
{
    private const ALLOWED_KEYS = [
        'accounts',
        'tiers',
        'tags',
        'purchase',
        'offerings',
        'categories',
        'servers',
        'service',
        'wallet_min',
        'wallet_max',
        'channels',
        'channel_mode',
        'channel_state',
        'manual',
    ];

    /** @requirement COM-002 QUA-001 QUA-004 */
    public function parse(string $input): TelegramBroadcastAudienceDefinition
    {
        $input = trim($input);
        if ($input === '') {
            throw new DomainException('Broadcast audience input is empty.');
        }
        if (strcasecmp($input, 'all') === 0) {
            return new TelegramBroadcastAudienceDefinition;
        }
        if (strlen($input) > 16_384 || ! mb_check_encoding($input, 'UTF-8') || str_contains($input, "\0")) {
            throw new DomainException('Broadcast audience input is invalid.');
        }

        $values = [];
        foreach (preg_split('/\R/u', $input) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                throw new DomainException('Broadcast audience lines must use key=value.');
            }

            $key = strtolower(trim($parts[0]));
            $value = trim($parts[1]);
            if (! in_array($key, self::ALLOWED_KEYS, true)) {
                throw new DomainException('Broadcast audience key is not supported: '.$key);
            }
            if (array_key_exists($key, $values)) {
                throw new DomainException('Broadcast audience key is duplicated: '.$key);
            }
            if ($value === '') {
                throw new DomainException('Broadcast audience value cannot be empty: '.$key);
            }

            $values[$key] = $value;
        }

        if ($values === []) {
            throw new DomainException('Broadcast audience input contains no filters.');
        }

        return new TelegramBroadcastAudienceDefinition(
            accountTypes: $this->strings($values['accounts'] ?? null),
            tierCodes: $this->strings($values['tiers'] ?? null),
            tagCodes: $this->strings($values['tags'] ?? null),
            purchaseState: $values['purchase'] ?? 'any',
            offeringCodes: $this->strings($values['offerings'] ?? null),
            categoryCodes: $this->strings($values['categories'] ?? null),
            serverCodes: $this->strings($values['servers'] ?? null),
            serviceState: $values['service'] ?? 'any',
            walletMinimumIrr: $this->nullableInteger($values['wallet_min'] ?? null, 'wallet_min'),
            walletMaximumIrr: $this->nullableInteger($values['wallet_max'] ?? null, 'wallet_max'),
            channelChatIds: $this->integers($values['channels'] ?? null),
            channelMembershipMode: $values['channel_mode'] ?? 'all',
            channelMembershipState: $values['channel_state'] ?? 'member',
            manualUserPublicIds: $this->strings($values['manual'] ?? null),
        );
    }

    /** @return list<string> */
    private function strings(?string $value): array
    {
        if ($value === null) {
            return [];
        }

        $parts = array_map('trim', explode(',', $value));
        if (in_array('', $parts, true)) {
            throw new DomainException('Broadcast audience comma-separated values cannot be empty.');
        }

        return array_values($parts);
    }

    /** @return list<int> */
    private function integers(?string $value): array
    {
        if ($value === null) {
            return [];
        }

        $items = [];
        foreach ($this->strings($value) as $item) {
            $validated = filter_var($item, FILTER_VALIDATE_INT);
            if ($validated === false || $validated === 0) {
                throw new DomainException('Broadcast channel IDs must be non-zero integers.');
            }
            $items[] = $validated;
        }

        return $items;
    }

    private function nullableInteger(?string $value, string $key): ?int
    {
        if ($value === null) {
            return null;
        }

        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($validated === false) {
            throw new DomainException('Broadcast audience '.$key.' must be a non-negative integer.');
        }

        return $validated;
    }
}
