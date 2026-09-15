<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Shared\Application\SafeOutboxPayload;
use InvalidArgumentException;

final readonly class TelegramInteractionPayload
{
    private const MAXIMUM_BYTES = 4096;

    /** @var array<string, mixed> */
    private array $values;

    private string $json;

    private string $hash;

    /** @param array<string, mixed> $values */
    public function __construct(array $values)
    {
        if (array_is_list($values) && $values !== []) {
            throw new InvalidArgumentException('Telegram interaction payload must be a JSON object.');
        }

        $safePayload = new SafeOutboxPayload($values);
        $json = $values === [] ? '{}' : $safePayload->json();
        if (strlen($json) > self::MAXIMUM_BYTES) {
            throw new InvalidArgumentException('Telegram interaction payload exceeds the 4 KiB safety limit.');
        }

        $this->values = $values;
        $this->json = $json;
        $this->hash = hash('sha256', $json);
    }

    /** @return array<string, mixed> */
    public function values(): array
    {
        return $this->values;
    }

    public function json(): string
    {
        return $this->json;
    }

    public function hash(): string
    {
        return $this->hash;
    }
}
