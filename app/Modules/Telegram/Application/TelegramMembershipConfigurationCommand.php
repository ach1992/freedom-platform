<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramMembershipConfigurationCommand
{
    public function __construct(
        public string $operation,
        public ?int $targetId,
        public ?int $expectedVersion,
        public ?TelegramRequiredChannelDefinition $channel,
        public ?TelegramChannelMembershipRuleDefinition $rule,
        public string $reason,
    ) {
        if (! in_array($operation, [
            'channel.create',
            'channel.update',
            'channel.activate',
            'channel.disable',
            'rule.create',
            'rule.update',
            'rule.activate',
            'rule.disable',
        ], true)) {
            throw new InvalidArgumentException('Telegram membership configuration operation is invalid.');
        }
        if (trim($reason) === '' || mb_strlen($reason) > 1000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason) === 1) {
            throw new InvalidArgumentException('Telegram membership configuration reason is invalid.');
        }

        $create = str_ends_with($operation, '.create');
        if ($create) {
            if ($targetId !== null || $expectedVersion !== null) {
                throw new InvalidArgumentException('Telegram membership create command cannot carry target identity.');
            }
        } elseif ($targetId === null || $targetId < 1 || $expectedVersion === null || $expectedVersion < 1) {
            throw new InvalidArgumentException('Telegram membership mutation target identity is invalid.');
        }

        $channelOperation = str_starts_with($operation, 'channel.');
        $definitionRequired = str_ends_with($operation, '.create') || str_ends_with($operation, '.update');
        if ($channelOperation) {
            if (($definitionRequired && $channel === null) || (! $definitionRequired && $channel !== null) || $rule !== null) {
                throw new InvalidArgumentException('Telegram membership channel command definition is invalid.');
            }
        } elseif (($definitionRequired && $rule === null) || (! $definitionRequired && $rule !== null) || $channel !== null) {
            throw new InvalidArgumentException('Telegram membership rule command definition is invalid.');
        }
    }
}
