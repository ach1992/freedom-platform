<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Shared\Application\RestrictedValue;
use InvalidArgumentException;
use SensitiveParameter;

final readonly class TelegramPrivateMediaDownload
{
    private function __construct(
        private RestrictedValue $content,
        public ?int $providerFileSize,
    ) {}

    public static function fromBytes(
        #[SensitiveParameter] string $content,
        ?int $providerFileSize,
    ): self {
        if ($content === '' || ($providerFileSize !== null && $providerFileSize < 1)) {
            throw new InvalidArgumentException('Telegram private-media download is invalid.');
        }

        return new self(RestrictedValue::fromString($content), $providerFileSize);
    }

    public function bytes(): string
    {
        return $this->content->reveal();
    }
}
