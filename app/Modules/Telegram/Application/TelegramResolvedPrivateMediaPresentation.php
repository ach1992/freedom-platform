<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;
use LogicException;
use SensitiveParameter;
use Stringable;
use WeakMap;

final class TelegramResolvedPrivateMediaPresentation implements Stringable
{
    /** @var WeakMap<self,string>|null */
    private static ?WeakMap $bytesByInstance = null;

    public function __construct(
        public readonly string $contentType,
        #[SensitiveParameter] string $bytes,
        public readonly string $filename,
        public readonly string $caption,
        public readonly string $detectedMime,
        public readonly int $byteSize,
        public readonly string $contentSha256,
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

        self::bytesByInstance()[$this] = $bytes;
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
        return [
            'redacted' => true,
            'type' => $this->contentType,
            'mime' => $this->detectedMime,
            'size' => $this->byteSize,
        ];
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

    /** @return WeakMap<self,string> */
    private static function bytesByInstance(): WeakMap
    {
        return self::$bytesByInstance ??= new WeakMap;
    }

    private function __clone(): void {}
}
