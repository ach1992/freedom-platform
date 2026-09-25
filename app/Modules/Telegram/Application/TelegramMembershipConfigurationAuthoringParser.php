<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use SensitiveParameter;

final class TelegramMembershipConfigurationAuthoringParser
{
    private const COMMON_MUTATION_KEYS = ['operation', 'id', 'version', 'reason'];

    /** @requirement CHN-001 QUA-001 QUA-004 SEC-001 */
    public function parse(#[SensitiveParameter] string $input): TelegramMembershipConfigurationCommand
    {
        if ($input === '' || strlen($input) > 16_384 || ! mb_check_encoding($input, 'UTF-8') || str_contains($input, "\0")) {
            throw new InvalidArgumentException('Telegram membership configuration input is invalid.');
        }

        $values = [];
        foreach (preg_split('/\R/u', $input) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                throw new InvalidArgumentException('Telegram membership configuration lines must use key=value.');
            }
            $key = strtolower(trim($parts[0]));
            $value = trim($parts[1]);
            if ($key === '' || $value === '' || array_key_exists($key, $values)) {
                throw new InvalidArgumentException('Telegram membership configuration key/value is invalid.');
            }
            $values[$key] = $value;
        }

        $operation = $values['operation'] ?? null;
        if (! is_string($operation)) {
            throw new InvalidArgumentException('Telegram membership configuration operation is required.');
        }

        return match ($operation) {
            'channel.create' => $this->channelDefinitionCommand($operation, $values, false),
            'channel.update' => $this->channelDefinitionCommand($operation, $values, true),
            'channel.activate', 'channel.disable' => $this->identityCommand($operation, $values),
            'rule.create' => $this->ruleDefinitionCommand($operation, $values, false),
            'rule.update' => $this->ruleDefinitionCommand($operation, $values, true),
            'rule.activate', 'rule.disable' => $this->identityCommand($operation, $values),
            default => throw new InvalidArgumentException('Telegram membership configuration operation is unsupported.'),
        };
    }

    /** @param array<string,string> $values */
    private function channelDefinitionCommand(string $operation, array $values, bool $update): TelegramMembershipConfigurationCommand
    {
        $keys = [
            'operation', 'channel_key', 'chat_id', 'chat_type', 'visibility',
            'title', 'join_url', 'sort_order', 'reason',
        ];
        if ($update) {
            $keys[] = 'id';
            $keys[] = 'version';
        }
        $this->assertKeys($values, $keys);

        $definition = new TelegramRequiredChannelDefinition(
            $values['channel_key'],
            $this->integer($values['chat_id'], null, -1),
            $values['chat_type'],
            $values['visibility'],
            $values['title'],
            $values['join_url'],
            $this->integer($values['sort_order'], 0, 1_000_000),
        );

        return new TelegramMembershipConfigurationCommand(
            $operation,
            $update ? $this->positiveInteger($values['id']) : null,
            $update ? $this->positiveInteger($values['version']) : null,
            $definition,
            null,
            $values['reason'],
        );
    }

    /** @param array<string,string> $values */
    private function ruleDefinitionCommand(string $operation, array $values, bool $update): TelegramMembershipConfigurationCommand
    {
        $keys = [
            'operation', 'rule_key', 'action', 'audience', 'tier_code', 'customer_tag_id',
            'plan_offering_id', 'match_mode', 'failure_policy', 'priority',
            'effective_from', 'effective_until', 'channel_ids', 'reason',
        ];
        if ($update) {
            $keys[] = 'id';
            $keys[] = 'version';
        }
        $this->assertKeys($values, $keys);

        $definition = new TelegramChannelMembershipRuleDefinition(
            $values['rule_key'],
            $this->nullableString($values['action']),
            $values['audience'],
            $this->nullableString($values['tier_code']),
            $this->nullablePositiveInteger($values['customer_tag_id']),
            $this->nullablePositiveInteger($values['plan_offering_id']),
            $values['match_mode'],
            $values['failure_policy'],
            $this->integer($values['priority'], 0, 65_535),
            $this->nullableDateTime($values['effective_from']),
            $this->nullableDateTime($values['effective_until']),
            $this->channelIds($values['channel_ids']),
        );

        return new TelegramMembershipConfigurationCommand(
            $operation,
            $update ? $this->positiveInteger($values['id']) : null,
            $update ? $this->positiveInteger($values['version']) : null,
            null,
            $definition,
            $values['reason'],
        );
    }

    /** @param array<string,string> $values */
    private function identityCommand(string $operation, array $values): TelegramMembershipConfigurationCommand
    {
        $this->assertKeys($values, self::COMMON_MUTATION_KEYS);

        return new TelegramMembershipConfigurationCommand(
            $operation,
            $this->positiveInteger($values['id']),
            $this->positiveInteger($values['version']),
            null,
            null,
            $values['reason'],
        );
    }

    /** @param array<string,string> $values
     * @param  list<string>  $expected
     */
    private function assertKeys(array $values, array $expected): void
    {
        $actual = array_keys($values);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new InvalidArgumentException('Telegram membership configuration fields do not match the selected operation.');
        }
    }

    private function positiveInteger(string $value): int
    {
        return $this->integer($value, 1, PHP_INT_MAX);
    }

    private function nullablePositiveInteger(string $value): ?int
    {
        return $value === '-' ? null : $this->positiveInteger($value);
    }

    private function integer(string $value, ?int $minimum, ?int $maximum): int
    {
        if (preg_match('/\A-?[0-9]+\z/', $value) !== 1) {
            throw new InvalidArgumentException('Telegram membership configuration integer is invalid.');
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false
            || ($minimum !== null && $integer < $minimum)
            || ($maximum !== null && $integer > $maximum)) {
            throw new InvalidArgumentException('Telegram membership configuration integer is out of range.');
        }

        return $integer;
    }

    private function nullableString(string $value): ?string
    {
        return $value === '-' ? null : $value;
    }

    private function nullableDateTime(string $value): ?DateTimeImmutable
    {
        if ($value === '-') {
            return null;
        }
        if (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})\z/', $value) !== 1) {
            throw new InvalidArgumentException('Telegram membership configuration datetime must include an explicit timezone.');
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception $exception) {
            throw new InvalidArgumentException('Telegram membership configuration datetime is invalid.', previous: $exception);
        }
    }

    /** @return list<int> */
    private function channelIds(string $value): array
    {
        if ($value === '-') {
            return [];
        }

        $ids = [];
        foreach (explode(',', $value) as $part) {
            $part = trim($part);
            if ($part === '') {
                throw new InvalidArgumentException('Telegram membership configuration channel list is invalid.');
            }
            $id = $this->positiveInteger($part);
            if (in_array($id, $ids, true)) {
                throw new InvalidArgumentException('Telegram membership configuration channel IDs must be unique.');
            }
            $ids[] = $id;
        }

        return $ids;
    }
}
