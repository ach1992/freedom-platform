<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use SensitiveParameter;
use Stringable;
use WeakMap;

final class TelegramResolvedPrivateMediaPresentation implements JsonSerializable, Stringable
{
    /** @var WeakMap<self,string>|null */
    private static ?WeakMap $bytesByInstance = null;

    private readonly string $contentType;

    private readonly string $filename;

    private readonly string $caption;

    private readonly string $detectedMime;

    private readonly int $byteSize;

    private readonly string $contentSha256;

    public function __construct(
        string $contentType,
        #[SensitiveParameter] string $bytes,
        string $filename,
        #[SensitiveParameter] string $caption,
        string $detectedMime,
        int $byteSize,
        string $contentSha256,
    ) {
        if (! in_array($contentType, ['photo', 'video', 'document'], true)
            || $bytes === ''
            || strlen($bytes) !== $byteSize
            || $byteSize > 20_000_000
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,126}\z/', $filename) !== 1
            || str_contains($filename, '..')
            || ! TelegramPrivateMediaContentValidator::isApprovedMime($detectedMime)
            || preg_match('/\A[0-9a-f]{64}\z/', $contentSha256) !== 1
            || ! hash_equals($contentSha256, hash('sha256', $bytes))
            || ! mb_check_encoding($caption, 'UTF-8')
            || str_contains($caption, "\0")
            || mb_strlen($caption) > 1024
            || ($contentType === 'photo' && ! TelegramPrivateMediaContentValidator::isImageMime($detectedMime))
            || ($contentType === 'video' && $detectedMime !== 'video/mp4')) {
            throw new InvalidArgumentException('Resolved private Telegram media presentation is invalid.');
        }

        $this->contentType = $contentType;
        $this->filename = $filename;
        $this->caption = $caption;
        $this->detectedMime = $detectedMime;
        $this->byteSize = $byteSize;
        $this->contentSha256 = $contentSha256;
        self::bytesByInstance()[$this] = $bytes;
    }

    public function contentType(): string
    {
        return $this->contentType;
    }

    public function filename(): string
    {
        return $this->filename;
    }

    public function captionForProvider(): string
    {
        return $this->caption;
    }

    public function detectedMime(): string
    {
        return $this->detectedMime;
    }

    public function byteSize(): int
    {
        return $this->byteSize;
    }

    public function contentSha256(): string
    {
        return $this->contentSha256;
    }

    public function revealBytesForProvider(): string
    {
        $map = self::$bytesByInstance;
        if ($map === null || ! isset($map[$this])) {
            throw new LogicException('Resolved private Telegram media bytes are unavailable.');
        }

        return $map[$this];
    }

    public function __toString(): string
    {
        return '[RESOLVED_PRIVATE_TELEGRAM_MEDIA_PRESENTATION]';
    }

    /** @return array{redacted:true,type:string,mime:string,size:int} */
    public function __debugInfo(): array
    {
        return $this->redactedMetadata();
    }

    /** @return array{redacted:true,type:string,mime:string,size:int} */
    public function jsonSerialize(): array
    {
        return $this->redactedMetadata();
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Resolved private Telegram media presentations cannot be serialized.');
    }

    /** @param array<array-key,mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Resolved private Telegram media presentations cannot be unserialized.');
    }

    /** @return array{redacted:true,type:string,mime:string,size:int} */
    private function redactedMetadata(): array
    {
        return [
            'redacted' => true,
            'type' => $this->contentType,
            'mime' => $this->detectedMime,
            'size' => $this->byteSize,
        ];
    }

    /** @return WeakMap<self,string> */
    private static function bytesByInstance(): WeakMap
    {
        return self::$bytesByInstance ??= new WeakMap;
    }

    private function __clone(): void {}
}
