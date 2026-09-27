<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use LogicException;
use Stringable;

final readonly class TelegramResolvedContactRequestMarkup implements Stringable
{
    public function __construct(private TelegramContactRequestKeyboardSnapshot $snapshot) {}

    /** @return array{keyboard:list<list<array{text:string,request_contact:true}>>,resize_keyboard:true,one_time_keyboard:true} */
    public function providerPayload(): array
    {
        return [
            'keyboard' => [[[
                'text' => $this->snapshot->buttonText,
                'request_contact' => true,
            ]]],
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ];
    }

    public function __toString(): string
    {
        return '[PROTECTED_TELEGRAM_CONTACT_REQUEST_KEYBOARD]';
    }

    /** @return array{redacted:true,type:string} */
    public function __debugInfo(): array
    {
        return ['redacted' => true, 'type' => 'contact_request_keyboard'];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Resolved Telegram contact-request keyboards cannot be serialized.');
    }

    /** @param array<array-key,mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Resolved Telegram contact-request keyboards cannot be unserialized.');
    }
}
