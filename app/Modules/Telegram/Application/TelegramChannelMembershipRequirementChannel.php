<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramChannelMembershipRequirementChannel
{
    public function __construct(
        public int $requiredChannelId,
        public string $channelKey,
        public int $telegramChatId,
        public string $chatType,
        public string $visibility,
        public string $displayTitle,
        public string $state,
        public int $version,
    ) {
        if ($requiredChannelId < 1 || $telegramChatId >= 0 || $version < 1) {
            throw new InvalidArgumentException('Telegram membership requirement channel identity is invalid.');
        }
        if (preg_match('/\A[a-z][a-z0-9_.-]{2,63}\z/', $channelKey) !== 1) {
            throw new InvalidArgumentException('Telegram membership requirement channel key is invalid.');
        }
        if (! in_array($chatType, ['group', 'supergroup', 'channel'], true)
            || ! in_array($visibility, ['public', 'private'], true)
            || ! in_array($state, ['draft', 'active', 'disabled'], true)
            || trim($displayTitle) === '') {
            throw new InvalidArgumentException('Telegram membership requirement channel configuration is invalid.');
        }
    }

    /** @return array{id:int,key:string,chat_id:int,chat_type:string,visibility:string,title:string,state:string,version:int} */
    public function hashPayload(): array
    {
        return [
            'id' => $this->requiredChannelId,
            'key' => $this->channelKey,
            'chat_id' => $this->telegramChatId,
            'chat_type' => $this->chatType,
            'visibility' => $this->visibility,
            'title' => $this->displayTitle,
            'state' => $this->state,
            'version' => $this->version,
        ];
    }
}
