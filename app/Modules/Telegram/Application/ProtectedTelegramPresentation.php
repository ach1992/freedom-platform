<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Shared\Application\RestrictedValue;
use InvalidArgumentException;
use LogicException;
use Stringable;

/**
 * Restricted payload held only until the one provider-boundary attempt.
 * Its string/debug representations cannot expose link or QR material.
 */
final readonly class ProtectedTelegramPresentation implements Stringable
{
    private function __construct(
        private ?string $text,
        private ?string $documentContents,
        private ?string $documentFilename,
        private ?string $caption,
        private ?string $copyButtonText,
        private ?RestrictedValue $copyText,
    ) {}

    public static function plainText(string $text): self
    {
        if ($text === '') {
            throw new InvalidArgumentException('Protected Telegram text is required.');
        }

        return new self($text, null, null, null, null, null);
    }

    public static function plainTextWithCopyText(
        string $text,
        string $buttonText,
        RestrictedValue $copyText,
    ): self {
        if ($text === '') {
            throw new InvalidArgumentException('Protected Telegram text is required.');
        }
        if (trim($buttonText) === '' || mb_strlen($buttonText) > 64 || ! mb_check_encoding($buttonText, 'UTF-8')) {
            throw new InvalidArgumentException('Protected Telegram copy button text must contain 1-64 safe characters.');
        }
        $copied = $copyText->reveal();
        if ($copied === '' || mb_strlen($copied) > 256 || ! mb_check_encoding($copied, 'UTF-8')) {
            throw new InvalidArgumentException('Protected Telegram copy text must contain 1-256 safe characters.');
        }

        return new self($text, null, null, null, $buttonText, $copyText);
    }

    public static function svgDocument(string $contents, string $caption): self
    {
        if ($contents === '' || strlen($contents) > 1_048_576 || mb_strlen($caption) > 1024) {
            throw new InvalidArgumentException('Protected Telegram document presentation is invalid.');
        }

        return new self($caption, $contents, 'service-details.svg', $caption, null, null);
    }

    public function hasCopyText(): bool
    {
        return $this->copyText !== null;
    }

    public function copyButtonText(): string
    {
        return $this->copyButtonText ?? throw new LogicException('Protected Telegram copy button is unavailable.');
    }

    public function copyText(): RestrictedValue
    {
        return $this->copyText ?? throw new LogicException('Protected Telegram copy text is unavailable.');
    }

    public function isText(): bool
    {
        return $this->documentContents === null;
    }

    public function text(): string
    {
        return $this->text ?? throw new LogicException('Protected Telegram text is unavailable.');
    }

    public function documentContents(): string
    {
        return $this->documentContents ?? throw new LogicException('Protected Telegram document is unavailable.');
    }

    public function documentFilename(): string
    {
        return $this->documentFilename ?? throw new LogicException('Protected Telegram document is unavailable.');
    }

    public function caption(): string
    {
        return $this->caption ?? throw new LogicException('Protected Telegram caption is unavailable.');
    }

    /** @return array{redacted:true,type:string} */
    public function __debugInfo(): array
    {
        return ['redacted' => true, 'type' => $this->isText() ? 'text' : 'document'];
    }

    public function __toString(): string
    {
        return '[PROTECTED_TELEGRAM_PRESENTATION]';
    }
}
