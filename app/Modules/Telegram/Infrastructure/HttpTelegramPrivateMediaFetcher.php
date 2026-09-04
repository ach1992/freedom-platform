<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Infrastructure;

use App\Modules\Telegram\Application\Contracts\TelegramPrivateMediaFetcher;
use App\Modules\Telegram\Application\TelegramPrivateMediaDownload;
use App\Modules\Telegram\Application\TelegramPrivateMediaRejected;
use App\Shared\Application\RestrictedValue;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

final readonly class HttpTelegramPrivateMediaFetcher implements TelegramPrivateMediaFetcher
{
    private const MAX_PROVIDER_DOWNLOAD_BYTES = 20_000_000;

    /** @requirement C2C-002 DAT-002 DAT-003 SEC-002 SEC-003 SEC-009 INT-001 INT-002 QUA-001 QUA-004 */
    public function __construct(
        private Factory $http,
        private TelegramRuntimeConfiguration $configuration,
    ) {}

    public function fetch(
        RestrictedValue $fileId,
        RestrictedValue $expectedFileUniqueId,
        int $maximumBytes,
    ): TelegramPrivateMediaDownload {
        if ($maximumBytes < 1 || $maximumBytes > self::MAX_PROVIDER_DOWNLOAD_BYTES) {
            throw new RuntimeException('Telegram private-media download limit is invalid.');
        }

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            [$encodedPath, $providerSize] = $this->resolveFile(
                $fileId,
                $expectedFileUniqueId,
                $maximumBytes,
            );

            try {
                $response = $this->http
                    ->accept('*/*')
                    ->withoutRedirecting()
                    ->timeout($this->configuration->apiTimeoutSeconds)
                    ->connectTimeout(min(5, $this->configuration->apiTimeoutSeconds))
                    ->get(
                        $this->configuration->apiBaseUrl
                        .'/file/bot'.$this->configuration->botToken.'/'.$encodedPath,
                    );
            } catch (Throwable) {
                if ($attempt < 2) {
                    continue;
                }

                throw new RuntimeException('Telegram private-media download transport failed.');
            }

            if ($response->status() >= 300 && $response->status() <= 399) {
                if ($attempt < 2) {
                    continue;
                }

                throw new RuntimeException('Telegram private-media download redirected unexpectedly.');
            }
            if ($response->status() === 429 || $response->serverError()) {
                if ($attempt < 2) {
                    continue;
                }

                throw new RuntimeException('Telegram private-media download is temporarily unavailable.');
            }
            if (! $response->successful()) {
                throw new TelegramPrivateMediaRejected('provider_file_unavailable');
            }

            $content = $response->body();
            $length = strlen($content);
            if ($length < 1) {
                throw new TelegramPrivateMediaRejected('empty_file');
            }
            if ($length > $maximumBytes) {
                throw new TelegramPrivateMediaRejected('file_too_large');
            }
            if ($providerSize !== null && $providerSize !== $length) {
                throw new RuntimeException('Telegram private-media provider size changed during download.');
            }

            return TelegramPrivateMediaDownload::fromBytes($content, $providerSize);
        }

        throw new RuntimeException('Telegram private-media download retry boundary was exhausted.');
    }

    /** @return array{0:string,1:?int} */
    private function resolveFile(
        RestrictedValue $fileId,
        RestrictedValue $expectedFileUniqueId,
        int $maximumBytes,
    ): array {
        $response = $this->readOnlyGetFile($fileId);
        $decoded = $response->json();
        if (! is_array($decoded) || ($decoded['ok'] ?? null) !== true || ! $response->successful()) {
            if ($response->status() === 429 || $response->serverError()) {
                throw new RuntimeException('Telegram getFile is temporarily unavailable.');
            }

            throw new TelegramPrivateMediaRejected('provider_file_unavailable');
        }

        $result = $decoded['result'] ?? null;
        if (! is_array($result) || array_is_list($result)) {
            throw new RuntimeException('Telegram getFile response is malformed.');
        }
        $returnedFileId = $result['file_id'] ?? null;
        $returnedUniqueId = $result['file_unique_id'] ?? null;
        if (! is_string($returnedFileId)
            || ! is_string($returnedUniqueId)
            || ! hash_equals($fileId->reveal(), $returnedFileId)
            || ! hash_equals($expectedFileUniqueId->reveal(), $returnedUniqueId)) {
            throw new RuntimeException('Telegram getFile identity does not match the accepted update.');
        }

        $providerSize = $this->optionalPositiveInteger($result['file_size'] ?? null);
        if ($providerSize !== null && $providerSize > $maximumBytes) {
            throw new TelegramPrivateMediaRejected('file_too_large');
        }

        $filePath = $result['file_path'] ?? null;
        if (! is_string($filePath)) {
            throw new TelegramPrivateMediaRejected('provider_file_unavailable');
        }

        return [$this->encodedProviderPath($filePath), $providerSize];
    }

    private function readOnlyGetFile(RestrictedValue $fileId): Response
    {
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $response = $this->http
                    ->asJson()
                    ->acceptJson()
                    ->withoutRedirecting()
                    ->timeout($this->configuration->apiTimeoutSeconds)
                    ->connectTimeout(min(5, $this->configuration->apiTimeoutSeconds))
                    ->post(
                        $this->configuration->apiBaseUrl.'/bot'.$this->configuration->botToken.'/getFile',
                        ['file_id' => $fileId->reveal()],
                    );
            } catch (Throwable) {
                if ($attempt < 2) {
                    continue;
                }

                throw new RuntimeException('Telegram getFile transport failed.');
            }

            if ($response->status() >= 300 && $response->status() <= 399) {
                if ($attempt < 2) {
                    continue;
                }

                throw new RuntimeException('Telegram getFile redirected unexpectedly.');
            }
            if (($response->status() === 429 || $response->serverError()) && $attempt < 2) {
                continue;
            }

            return $response;
        }

        throw new RuntimeException('Telegram getFile retry boundary was exhausted.');
    }

    private function encodedProviderPath(string $filePath): string
    {
        if ($filePath === ''
            || strlen($filePath) > 1024
            || str_starts_with($filePath, '/')
            || str_contains($filePath, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $filePath) === 1) {
            throw new RuntimeException('Telegram file path is invalid.');
        }

        $segments = explode('/', $filePath);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || strlen($segment) > 255) {
                throw new RuntimeException('Telegram file path is invalid.');
            }
        }

        return implode('/', array_map('rawurlencode', $segments));
    }

    private function optionalPositiveInteger(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (! is_int($value) || $value < 1) {
            throw new RuntimeException('Telegram provider file size is invalid.');
        }

        return $value;
    }
}
