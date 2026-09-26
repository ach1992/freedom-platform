<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;
use JsonException;

final readonly class TelegramContactRequestKeyboardSnapshot
{
    private const MAXIMUM_BYTES = 1024;

    private string $json;

    private string $hash;

    public function __construct(public string $buttonText)
    {
        $text = trim($buttonText);
        if ($text === ''
            || mb_strlen($text) > 64
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $text) === 1) {
            throw new InvalidArgumentException('Telegram contact-request button text is invalid.');
        }

        $values = ['contact_request' => ['button_text' => $text]];
        try {
            $json = json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Telegram contact-request keyboard could not be encoded.', 0, $exception);
        }
        if (strlen($json) > self::MAXIMUM_BYTES) {
            throw new InvalidArgumentException('Telegram contact-request keyboard exceeds the safety limit.');
        }

        $this->buttonText = $text;
        $this->json = $json;
        $this->hash = hash('sha256', $json);
    }

    public static function restore(string $json): self
    {
        if ($json === '' || strlen($json) > self::MAXIMUM_BYTES) {
            throw new InvalidArgumentException('Stored Telegram contact-request keyboard is invalid.');
        }

        try {
            $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Stored Telegram contact-request keyboard is invalid.', 0, $exception);
        }
        if (! is_array($decoded)
            || array_is_list($decoded)
            || array_keys($decoded) !== ['contact_request']
            || ! is_array($decoded['contact_request'])
            || array_is_list($decoded['contact_request'])
            || array_keys($decoded['contact_request']) !== ['button_text']
            || ! is_string($decoded['contact_request']['button_text'])) {
            throw new InvalidArgumentException('Stored Telegram contact-request keyboard has an invalid shape.');
        }

        $snapshot = new self($decoded['contact_request']['button_text']);
        if (! hash_equals($snapshot->json(), $json)) {
            throw new InvalidArgumentException('Stored Telegram contact-request keyboard is not canonical.');
        }

        return $snapshot;
    }

    /** @return list<string> */
    public function callbackPublicIds(): array
    {
        return [];
    }

    public function json(): string
    {
        return $this->json;
    }

    public function hash(): string
    {
        return $this->hash;
    }

    /** @return array{redacted:true,type:string} */
    public function __debugInfo(): array
    {
        return ['redacted' => true, 'type' => 'contact_request_keyboard_snapshot'];
    }
}
